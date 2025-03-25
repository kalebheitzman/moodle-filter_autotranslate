<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses>.
/**
 * Autotranslate Fetch Translations Task
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace filter_autotranslate\task;

defined('MOODLE_INTERNAL') || die();

use core\task\scheduled_task;
use filter_autotranslate\translation_service;

class fetchtranslation_task extends scheduled_task {

    /**
     * Flag to indicate if the task should exit due to a signal.
     *
     * @var bool
     */
    private $should_exit = false;

    /**
     * Returns the name of the scheduled task.
     *
     * @return string
     */
    public function get_name() {
        return 'Fetch Automatic Translations';
    }

    /**
     * Signal handler to catch termination signals and set the exit flag.
     *
     * @param int $signo The signal number
     */
    private function signal_handler($signo) {
        mtrace("Received signal $signo. Preparing to exit gracefully...");
        $this->should_exit = true;
    }

    /**
     * Extracts a user-friendly error message from the API response.
     *
     * @param string $response The API response body
     * @return string The extracted error message
     */
    private function extract_error_message($response) {
        $data = json_decode($response, true);
        if ($data && isset($data['error']['message'])) {
            return $data['error']['message'];
        }
        return "Unknown error occurred.";
    }

    /**
     * Extracts the HTTP status code from the exception message.
     *
     * @param string $message The exception message
     * @return string The HTTP status code or 'Unknown'
     */
    private function extract_http_code($message) {
        if (str_contains($message, 'HTTP')) {
            $parts = explode('HTTP', $message);
            return trim(explode(' ', $parts[1])[0]);
        }
        return 'Unknown';
    }

    /**
     * Executes the scheduled task to fetch translations using Google Generative AI API (OpenAI-compatible endpoint).
     */
    public function execute() {
        global $DB;

        // Initialize $transaction to null to avoid undefined variable warnings
        $transaction = null;

        // Initialize the translation service
        $translation_service = new \filter_autotranslate\translation_service($DB);

        // Set up signal handling if the pcntl extension is available
        if (extension_loaded('pcntl')) {
            pcntl_signal(SIGTERM, [$this, 'signal_handler']);
            pcntl_signal(SIGINT, [$this, 'signal_handler']);
        } else {
            mtrace("Warning: pcntl extension not available. Signal handling for graceful shutdown is disabled.");
        }

        try {
            // Check if automatic fetching is enabled
            $enableautofetch = get_config('filter_autotranslate', 'enableautofetch');
            if (!$enableautofetch) {
                mtrace("Automatic fetching of translations is disabled. Skipping fetchtranslation_task.");
                return;
            }

            // Get settings
            $apiendpoint = get_config('filter_autotranslate', 'apiendpoint') ?: 'https://generativelanguage.googleapis.com/v1beta/openai';
            $apikey = get_config('filter_autotranslate', 'apikey');
            $apimodel = get_config('filter_autotranslate', 'apimodel') ?: 'gemini-1.5-pro-latest';
            $fetchlimit = get_config('filter_autotranslate', 'fetchlimit') ?: '';
            $batchsize = get_config('filter_autotranslate', 'batchsize') ?: 10; // Use as maximum batch size
            $targetlangs = get_config('filter_autotranslate', 'targetlangs') ?: '';
            $systeminstructions = get_config('filter_autotranslate', 'systeminstructions') ?: 'Translate with a formal tone.';
            $maxattempts = get_config('filter_autotranslate', 'maxattempts') ?: 3;
            $ratelimitthreshold = get_config('filter_autotranslate', 'ratelimitthreshold') ?: 50;

            if (empty($apiendpoint) || empty($apimodel)) {
                mtrace("API endpoint or model not configured. Skipping translation fetch.");
                return;
            }

            if (empty($apikey)) {
                mtrace("API key not configured. Google Generative AI API (OpenAI-compatible) requires an API key. Please set it in the plugin settings under Site administration > Plugins > Filters > Autotranslate.");
                return;
            }

            // Parse target languages (stored as a CSV list in Moodle 4.5)
            $targetlangs = !empty($targetlangs) ? array_filter(array_map('trim', explode(',', $targetlangs))) : [];
            if (empty($targetlangs)) {
                mtrace("No target languages configured. Please configure target languages in the plugin settings under Site administration > Plugins > Filters > Autotranslate to enable translation fetching.");
                return;
            }

            // Fetch untagged translations where at least one target language is missing
            $params = $targetlangs; // Only targetlangs for IN clause
            $sql = "SELECT t.id, t.hash, t.translated_text AS source_text, t.contextlevel
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

            // Dynamically batch records based on string length, with batchsize as the maximum
            $batches = [];
            $current_batch = [];
            $current_batch_chars = 0;
            $max_batch_chars = 2000; // Maximum total characters per batch

            foreach ($records as $record) {
                $text = $record->source_text;
                $char_count = strlen($text);

                // Determine batch size based on character count, capped by the configured batchsize
                if ($char_count < 200) {
                    $max_batch_size = min(10, $batchsize); // Cap at configured batchsize
                    $max_batch_chars = 2000;
                } elseif ($char_count < 400) {
                    $max_batch_size = min(5, $batchsize);
                    $max_batch_chars = 1500;
                } elseif ($char_count < 600) {
                    $max_batch_size = min(3, $batchsize);
                    $max_batch_chars = 1200;
                } else {
                    $max_batch_size = 1; // Always 1 for long texts, regardless of batchsize setting
                    $max_batch_chars = 600;
                }

                // Add to current batch if it fits
                if (count($current_batch) < $max_batch_size && ($current_batch_chars + $char_count) <= $max_batch_chars) {
                    $current_batch[] = $record;
                    $current_batch_chars += $char_count;
                } else {
                    // Batch is full, add to batches and start a new batch
                    if (!empty($current_batch)) {
                        $batches[] = $current_batch;
                    }
                    $current_batch = [$record];
                    $current_batch_chars = $char_count;
                }
            }

            // Add the last batch if not empty
            if (!empty($current_batch)) {
                $batches[] = $current_batch;
            }

            $requests = 0;
            $start_time = time();
            $max_runtime = 180; // 3 minutes, slightly less than the default 4-minute limit
            $batch_count = 0;
            $total_response_time = 0;

            foreach ($batches as $batch) {
                $batch_count++;
                // Check runtime to avoid exceeding the maximum runtime
                if (time() - $start_time > $max_runtime) {
                    mtrace("Task runtime approaching limit. Exiting to allow re-run.");
                    break;
                }

                // Check if the task should exit due to a signal
                if ($this->should_exit) {
                    mtrace("Task interrupted by signal. Rolling back transaction and exiting.");
                    if ($transaction) {
                        $transaction->rollback(new \Exception("Task interrupted by signal"));
                    }
                    break;
                }

                // Start a database transaction for this batch
                $transaction = $DB->start_delegated_transaction();

                try {
                    // Rate limiting
                    if ($requests >= $ratelimitthreshold) {
                        mtrace("Approaching rate limit ($ratelimitthreshold requests). Sleeping for 60 seconds.");
                        sleep(60); // Sleep for 1 minute, keep the lock
                        $requests = 0;
                    }

                    // Process signals after sleep to catch interruptions
                    if (extension_loaded('pcntl')) {
                        pcntl_signal_dispatch();
                    }

                    // Check again after sleep
                    if ($this->should_exit) {
                        mtrace("Task interrupted by signal after rate limit sleep. Rolling back transaction and exiting.");
                        $transaction->rollback(new \Exception("Task interrupted by signal"));
                        break;
                    }

                    // Build batch prompt and log batch info
                    $texts = [];
                    $hashes = [];
                    $record_ids = []; // Map numeric indices to record IDs
                    $text_count = 0;
                    $total_chars = 0;
                    foreach ($batch as $record) {
                        $text_count++;
                        $texts[$text_count] = $record->source_text;
                        $hashes[$text_count] = $record->hash;
                        $record_ids[$text_count] = $record->id;
                        $total_chars += strlen($record->source_text);
                    }

                    mtrace("Batch $batch_count: $text_count texts, $total_chars chars total");

                    $translations = $this->fetch_translations($texts, $hashes, $record_ids, $apiendpoint, $apikey, $apimodel, $targetlangs, $systeminstructions, $maxattempts);
                    
                    // Log response time and API usage
                    $response_time = $this->last_response_time ?? 0;
                    $total_response_time += $response_time;
                    mtrace("  Response time: " . number_format($response_time, 2) . " seconds");
                    if ($this->last_api_usage) {
                        mtrace("  API Usage: prompt_tokens=" . $this->last_api_usage['prompt_tokens'] . ", completion_tokens=" . $this->last_api_usage['completion_tokens'] . ", total_tokens=" . $this->last_api_usage['total_tokens']);
                    }

                    // Store translations and log summary
                    $translation_count = 0;
                    $translation_summary = [];
                    foreach ($translations as $recordid => $lang_translations) {
                        $record_id = $record_ids[$recordid];
                        $record = $batch[array_search($record_id, array_column($batch, 'id'))];
                        $langs_translated = count($lang_translations);
                        $translation_count += $langs_translated;
                        $translation_summary[] = "hash=" . $record->hash . ": $langs_translated langs";
                        foreach ($lang_translations as $lang => $translated_text) {
                            $translated_text = is_array($translated_text) ? implode(' ', $translated_text) : $translated_text;
                            $translation_service->store_translation($record->hash, $lang, $translated_text, $record->contextlevel);
                        }
                    }

                    mtrace("  Translations stored: $text_count records, $translation_count translations (" . implode(', ', $translation_summary) . ")");

                    $requests++;
                    // Commit the transaction for this batch
                    $transaction->allow_commit();
                    $transaction = null; // Clear the transaction reference
                } catch (\Exception $e) {
                    // Roll back the transaction on error
                    $transaction->rollback($e);
                    $transaction = null;

                    // Extract a user-friendly error message and HTTP code
                    $error_message = $this->extract_error_message($e->getMessage());
                    $http_code = $this->extract_http_code($e->getMessage());
                    $user_message = "API error: HTTP $http_code - $error_message. Please verify the API key in the plugin settings under Site administration > Plugins > Filters > Autotranslate and ensure it’s valid for the Google Generative AI API.";
                    $final_message = "$error_message. Please verify the API key in the plugin settings under Site administration > Plugins > Filters > Autotranslate and ensure it’s valid for the Google Generative AI API.";
                    mtrace($user_message);
                    throw new \Exception($final_message);
                }
            }

            $total_runtime = time() - $start_time;
            $avg_response_time = $batch_count > 0 ? $total_response_time / $batch_count : 0;
            mtrace("Fetch Translation task completed.");
            mtrace("Summary: Processed " . count($records) . " records in $batch_count batches, total runtime: $total_runtime seconds (~" . round($total_runtime / 60, 1) . " minutes), average response time per batch: " . number_format($avg_response_time, 1) . " seconds");
        } catch (\Exception $e) {
            mtrace("Fetch Translation task failed: " . $e->getMessage());
            throw $e; // Re-throw to ensure proper failure handling
        } finally {
            // Ensure any open transaction is rolled back on exit
            if ($transaction) {
                try {
                    $transaction->rollback(new \Exception("Task exited with an open transaction"));
                } catch (\Exception $rollback_e) {
                    mtrace("Error rolling back transaction on exit: " . $rollback_e->getMessage());
                }
            }
        }
    }

    // Store the last response time and API usage for logging
    private $last_response_time = 0;
    private $last_api_usage = null;

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

        // Build the prompt with a simplified example (using only one language)
        $text_count = count($texts);
        $text_list = '';
        foreach ($texts as $index => $text) {
            $text_list .= "$index. " . addslashes($text) . "\n";
        }

        // Use only the first language for the example to simplify the prompt
        $example_lang = $targetlangs[0]; // e.g., "cs"
        $example = "[{\"$example_lang\": \"Example translation in $example_lang\"}]";

        $prompt = "Translate the following $text_count texts into the following languages: " . implode(', ', $targetlangs) . ". Return the result as a JSON array where each element is a dictionary mapping language codes to the translated text as a string. The array must contain exactly $text_count elements, one for each text, in the same order as the input list. For each text, you MUST provide translations for ALL languages listed in " . json_encode($targetlangs) . ". If any language is missing for any text, the response will be considered invalid. Do not nest the translations under additional keys like 'translation'. Here is an example for 1 text translated into $example_lang:\n" .
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
        try {
            $url = rtrim($apiendpoint, '/') . '/chat/completions';
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            // Add timeout options
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // 10 seconds to connect
            curl_setopt($ch, CURLOPT_TIMEOUT, 120); // 120 seconds total timeout

            $attempts = 0;
            while ($attempts < $maxattempts) {
                $response = curl_exec($ch);
                $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curl_info = curl_getinfo($ch);

                if ($response === false) {
                    $error = curl_error($ch);
                    $errno = curl_errno($ch);
                    mtrace("cURL error (attempt " . ($attempts + 1) . "): $error (errno: $errno)");
                    $attempts++;
                    sleep(2); // Wait before retrying
                    continue;
                }

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

                if ($httpcode != 200) {
                    $error_message = $this->extract_error_message($response);
                    throw new \Exception("API error: HTTP $httpcode, Response: $error_message");
                }

                $data = json_decode($response, true);
                if ($httpcode == 200 && isset($data['choices'][0]['message']['content'])) {
                    // Store response time and API usage for logging
                    $this->last_response_time = $curl_info['total_time'];
                    $this->last_api_usage = $data['usage'] ?? null;

                    $translations = json_decode($data['choices'][0]['message']['content'], true);
                    if (!is_array($translations)) {
                        throw new \Exception("Invalid JSON response from API: " . $data['choices'][0]['message']['content']);
                    }

                    // Validate the response structure (array of translation objects)
                    $processed_translations = [];
                    foreach ($translations as $index => $lang_translations) {
                        $recordid = (string)($index + 1); // Map array index (0-based) to recordid (1-based)
                        if (!is_array($lang_translations)) {
                            continue;
                        }

                        $hash = $hashes[$recordid];
                        $existing_translations = $DB->get_records('autotranslate_translations', ['hash' => $hash], '', 'lang, translated_text');
                        $existing_langs = array_keys($existing_translations);
                        $missing_langs = array_diff($targetlangs, $existing_langs);

                        $processed_translations[$recordid] = [];
                        foreach ($missing_langs as $lang) {
                            if (isset($lang_translations[$lang]) && is_string($lang_translations[$lang])) {
                                $processed_translations[$recordid][$lang] = $lang_translations[$lang];
                            }
                        }
                    }

                    if (empty($processed_translations)) {
                        throw new \Exception("No valid translations found in response");
                    }

                    return $processed_translations;
                } else {
                    throw new \Exception("Unexpected API response format: " . json_encode($data));
                }
            }

            throw new \Exception("Failed to fetch translations after $maxattempts attempts");
        } finally {
            // Ensure the cURL handle is always closed
            curl_close($ch);
        }
    }
}