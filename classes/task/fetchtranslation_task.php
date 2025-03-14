<?php
namespace filter_autotranslate\task;

defined('MOODLE_INTERNAL') || die();

use core\task\scheduled_task;

class fetchtranslation_task extends scheduled_task {

    /**
     * Returns the name of the scheduled task.
     *
     * @return string
     */
    public function get_name() {
        return 'Fetch Automatic Translations';
    }

    /**
     * Executes the scheduled task to fetch translations using Google Generative AI API (OpenAI-compatible endpoint).
     */
    public function execute() {
        global $DB;

        try {
            // Get settings
            $apiendpoint = get_config('filter_autotranslate', 'apiendpoint') ?: 'https://generativelanguage.googleapis.com/v1beta/openai';
            $apikey = get_config('filter_autotranslate', 'apikey');
            $apimodel = get_config('filter_autotranslate', 'apimodel') ?: 'gemini-1.5-pro-latest';
            $fetchlimit = get_config('filter_autotranslate', 'fetchlimit') ?: '';
            $batchsize = get_config('filter_autotranslate', 'batchsize') ?: 10;
            $targetlangs = get_config('filter_autotranslate', 'targetlangs') ?: '';
            $systeminstructions = get_config('filter_autotranslate', 'systeminstructions') ?: 'Translate with a formal tone.';
            $maxattempts = get_config('filter_autotranslate', 'maxattempts') ?: 3;
            $ratelimitthreshold = get_config('filter_autotranslate', 'ratelimitthreshold') ?: 50;

            if (empty($apiendpoint) || empty($apimodel)) {
                mtrace("API endpoint or model not configured. Skipping translation fetch.");
                return;
            }

            if (empty($apikey)) {
                mtrace("API key not configured. Google Generative AI API (OpenAI-compatible) requires an API key. Please set it in the plugin settings.");
                return;
            }

            // Parse target languages (stored as a CSV list in Moodle 4.5)
            $targetlangs = !empty($targetlangs) ? array_filter(array_map('trim', explode(',', $targetlangs))) : [];
            if (empty($targetlangs)) {
                mtrace("No target languages configured. Skipping fetch.");
                return;
            }

            // Cast fetchlimit to integer for use with $limitnum
            $fetchlimit = (int)$fetchlimit ?: 200; // Default to 200 if empty or invalid

            // Fetch untagged translations where at least one target language is missing
            $params = $targetlangs; // Only targetlangs for IN clause
            $sql = "SELECT t.id, t.hash, t.translated_text AS source_text, t.contextlevel, t.instanceid
                    FROM {autotranslate_translations} t
                    WHERE t.lang = 'other' 
                    AND EXISTS (
                        SELECT 1
                        FROM {autotranslate_translations} t2 
                        WHERE t2.hash = t.hash 
                        AND t2.lang = 'other'
                        AND NOT EXISTS (
                            SELECT 1 
                            FROM {autotranslate_translations} t3 
                            WHERE t3.hash = t.hash 
                            AND t3.lang IN (" . implode(',', array_fill(0, count($targetlangs), '?')) . ")
                        )
                    )
                    LIMIT " . $fetchlimit;
            $records = $DB->get_records_sql($sql, $params);

            mtrace("Starting Fetch Translation task. Processing " . count($records) . " records for languages: " . implode(', ', $targetlangs));

            // Process records in batches
            $batches = array_chunk($records, $batchsize);
            $requests = 0;

            foreach ($batches as $batch) {
                try {
                    // Rate limiting
                    if ($requests >= $ratelimitthreshold) {
                        mtrace("Approaching rate limit ($ratelimitthreshold requests). Sleeping for 60 seconds.");
                        sleep(60); // Sleep for 1 minute
                        $requests = 0;
                    }

                    // Build batch prompt
                    $texts = [];
                    $hashes = [];
                    $record_ids = []; // Map numeric indices to record IDs
                    $text_count = 0;
                    foreach ($batch as $record) {
                        $text_count++;
                        $texts[$text_count] = $record->source_text;
                        $hashes[$text_count] = $record->hash;
                        $record_ids[$text_count] = $record->id;
                    }

                    mtrace("Sent texts: " . json_encode($texts));

                    $translations = $this->fetch_translations($texts, $hashes, $record_ids, $apiendpoint, $apikey, $apimodel, $targetlangs, $systeminstructions, $maxattempts);
                    foreach ($translations as $recordid => $lang_translations) {
                        $record_id = $record_ids[$recordid];
                        $record = $batch[array_search($record_id, array_column($batch, 'id'))];
                        foreach ($lang_translations as $lang => $translated_text) {
                            $translated_text = is_array($translated_text) ? implode(' ', $translated_text) : $translated_text;
                            $this->store_translation($record->hash, $lang, $translated_text, $record->contextlevel, $record->instanceid);
                            mtrace("Fetched translation: hash={$record->hash}, lang=$lang, text='$translated_text'");
                        }
                    }

                    $requests++;
                } catch (\Exception $e) {
                    mtrace("Error fetching translations for batch: " . $e->getMessage());
                    continue; // Move to next batch on error
                }
            }

            mtrace("Fetch Translation task completed.");
        } catch (\Exception $e) {
            mtrace("Task failed due to: " . $e->getMessage());
            throw $e; // Re-throw to ensure proper failure handling
        }
    }

    /**
     * Fetches translations using Google Generative AI API (OpenAI-compatible endpoint).
     *
     * @param array $texts Array of texts to translate, keyed by numeric index (1, 2, etc.)
     * @param array $hashes Array of hashes corresponding to numeric indices
     * @param array $record_ids Array mapping numeric indices to database record IDs
     * @param string $apiendpoint The API endpoint URL
     * @param string $apikey The API key (required for Google API)
     * @param string $apimodel The model name
     * @param array $targetlangs Array of target language codes
     * @param string $systeminstructions System instructions for the model
     * @param int $maxattempts Maximum retry attempts for API calls
     * @return array Array of translations, keyed by numeric index and language
     */
    private function fetch_translations($texts, $hashes, $record_ids, $apiendpoint, $apikey, $apimodel, $targetlangs, $systeminstructions, $maxattempts) {
        global $DB;

        // Build the prompt with dynamic example based on target languages
        $text_count = count($texts);
        $text_list = '';
        foreach ($texts as $index => $text) {
            $text_list .= "$index. " . addslashes($text) . "\n";
        }

        // Create a dynamic example using the current target languages
        $example = '[';
        $example_parts = [];
        foreach ($targetlangs as $lang) {
            $example_parts[] = "\"$lang\": \"Example translation in $lang\"";
        }
        $example .= '{' . implode(', ', $example_parts) . '}, {' . implode(', ', $example_parts) . '}]';

        $prompt = "Translate the following $text_count texts into the following languages: " . implode(', ', $targetlangs) . ". Return the result as a JSON array where each element is a dictionary mapping language codes to the translated text as a string. The array must contain exactly $text_count elements, one for each text, in the same order as the input list. For each text, you MUST provide translations for ALL languages listed in " . json_encode($targetlangs) . ". If any language is missing for any text, the response will be considered invalid. Do not nest the translations under additional keys like 'translation'. Here is an example for 2 texts translated into " . implode(' and ', $targetlangs) . ":\n" .
                  $example . "\n" .
                  "Now translate the following texts:\n" . $text_list;

        // Define dynamic JSON schema for an array of translation objects
        $schema = [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => array_fill_keys($targetlangs, ['type' => 'string']),
                'required' => $targetlangs,
                'additionalProperties' => ['type' => 'string']
            ],
            'minItems' => $text_count,
            'maxItems' => $text_count
        ];

        // Prepare the API request for Google Generative AI API (OpenAI-compatible)
        $data = [
            'model' => $apimodel,
            'messages' => [
                ['role' => 'system', 'content' => $systeminstructions],
                ['role' => 'user', 'content' => $prompt]
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'translation-schema',
                    'schema' => $schema
                ]
            ]
        ];

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            "Authorization: Bearer $apikey"
        ];

        $ch = curl_init();
        $url = rtrim($apiendpoint, '/') . '/chat/completions';
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $attempts = 0;
        while ($attempts < $maxattempts) {
            $response = curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($response === false) {
                mtrace("cURL error (attempt " . ($attempts + 1) . "): " . curl_error($ch));
                $attempts++;
                sleep(2); // Wait before retrying
                continue;
            }

            mtrace("API Response HTTP Code: $httpcode");
            if ($httpcode == 404) {
                mtrace("API endpoint not found: " . $url . ". Please verify the API endpoint and configuration.");
                $attempts++;
                sleep(2); // Wait before retrying
                continue;
            }

            if ($httpcode == 429) {
                mtrace("Rate limit reached (attempt " . ($attempts + 1) . "). Sleeping for 60 seconds.");
                sleep(60); // Wait for rate limit reset
                $attempts++;
                continue;
            }

            $data = json_decode($response, true);
            if ($httpcode == 200 && isset($data['choices'][0]['message']['content'])) {
                mtrace("Full API response: " . $data['choices'][0]['message']['content']);

                $translations = json_decode($data['choices'][0]['message']['content'], true);
                if (!is_array($translations)) {
                    mtrace("Invalid JSON content in response: " . $data['choices'][0]['message']['content']);
                    throw new \Exception("Invalid JSON response from API: " . $data['choices'][0]['message']['content']);
                }

                // Validate the response structure (array of translation objects)
                $processed_translations = [];
                foreach ($translations as $index => $lang_translations) {
                    $recordid = (string)($index + 1); // Map array index (0-based) to recordid (1-based)
                    if (!is_array($lang_translations)) {
                        mtrace("Invalid translation structure for record $recordid: " . json_encode($lang_translations));
                        continue;
                    }

                    $hash = $hashes[$recordid];
                    $existing_translations = $DB->get_records('autotranslate_translations', ['hash' => $hash], '', 'lang, translated_text');
                    $existing_langs = array_keys($existing_translations);
                    $missing_langs = array_diff($targetlangs, $existing_langs);

                    mtrace("Record $recordid (hash: $hash) - Existing languages: " . implode(', ', $existing_langs) . "; Missing languages: " . implode(', ', $missing_langs));

                    $processed_translations[$recordid] = [];
                    $provided_langs = [];
                    foreach ($missing_langs as $lang) {
                        if (isset($lang_translations[$lang])) {
                            $translation = $lang_translations[$lang];
                            if (!is_string($translation)) {
                                mtrace("Invalid translation for language $lang in record $recordid (hash: $hash): " . json_encode($translation));
                                continue;
                            }
                            $processed_translations[$recordid][$lang] = $translation;
                            $provided_langs[] = $lang;
                        } else {
                            mtrace("No translation provided for missing language $lang in record $recordid (hash: $hash): " . json_encode($lang_translations));
                        }
                    }

                    if (!empty($provided_langs)) {
                        mtrace("Record $recordid (hash: $hash) - Provided languages: " . implode(', ', $provided_langs));
                    } else {
                        mtrace("No translations provided for any missing languages in record $recordid (hash: $hash)");
                    }
                }

                if (empty($processed_translations)) {
                    throw new \Exception("No valid translations found in response");
                }

                curl_close($ch);
                return $processed_translations;
            } else {
                mtrace("API response data: " . json_encode($data));
                throw new \Exception("API error: HTTP $httpcode, Response: " . json_encode($data));
            }
        }

        curl_close($ch);
        throw new \Exception("Failed to fetch translations after $maxattempts attempts");
    }

    /**
     * Stores a translation in the translations table, updating if it already exists.
     *
     * @param string $hash The unique hash
     * @param string $lang The language code
     * @param string|array $translated_text The translated text (string or array to handle potential API responses)
     * @param int $contextlevel The context level
     * @param int $instanceid The instance ID
     */
    private function store_translation($hash, $lang, $translated_text, $contextlevel, $instanceid) {
        global $DB;

        // Ensure translated_text is a string
        $translated_text = is_array($translated_text) ? implode(' ', $translated_text) : (string)$translated_text;

        // Check if a translation already exists for this hash and lang
        $existing = $DB->get_record('autotranslate_translations', ['hash' => $hash, 'lang' => $lang]);
        if ($existing) {
            // Update the existing record
            $record = new \stdClass();
            $record->id = $existing->id;
            $record->translated_text = $translated_text;
            $record->contextlevel = $contextlevel;
            $record->instanceid = $instanceid;
            $record->timemodified = time();
            $record->human = 0; // Machine-generated translation

            $DB->update_record('autotranslate_translations', $record);
        } else {
            // Insert a new record
            $record = new \stdClass();
            $record->hash = $hash;
            $record->lang = $lang;
            $record->translated_text = $translated_text;
            $record->contextlevel = $contextlevel;
            $record->instanceid = $instanceid;
            $record->timecreated = time();
            $record->timemodified = time();
            $record->human = 0; // Machine-generated translation

            $DB->insert_record('autotranslate_translations', $record);
        }
    }
}