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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Adhoc task to fetch translations for the Autotranslate plugin.
 *
 * Fetches translations for untranslated hashes in a target language via an external API,
 * storing them in `filter_autotranslate_translations` using `content_service`. Triggered
 * by the "Autotranslate" button on `manage.php` via `external.php`.
 *
 * Features:
 * - Batches API requests for untranslated hashes with retry logic.
 * - Updates progress in `filter_autotranslate_task_progress` for UI feedback.
 * - Re-queues if runtime exceeds limits, ensuring completion.
 *
 * Usage:
 * - Queued via `external.php` with target language, course ID, and hashes.
 * - Polls progress until completed or failed, updating UI via `autotranslate.js`.
 *
 * Design:
 * - Uses configurable API settings (endpoint, key, model) from plugin config.
 * - Processes batches (default 5) with rate limiting and max attempts.
 * - Logs failures with reasons for debugging.
 *
 * Dependencies:
 * - `content_service.php`: Stores fetched translations.
 * - `filter_autotranslate_task_progress`: Tracks task progress.
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_autotranslate\task;

use core\task\adhoc_task;
use curl; // For making HTTP requests to the translation API.

/**
 * Adhoc task to fetch and store translations.
 */
class autotranslate_adhoc_task extends adhoc_task {
    /**
     * Returns the task name.
     *
     * @return string Task name for display in admin interface.
     */
    public function get_name() {
        return get_string('autotranslateadhoc', 'filter_autotranslate');
    }

    /**
     * Updates task progress in the database.
     *
     * Saves status, processed count, and total in `filter_autotranslate_task_progress`,
     * with optional failure reason.
     *
     * @param string $status Task status ('queued', 'running', 'completed', 'failed').
     * @param int $processed Number of entries processed.
     * @param int $total Total entries to process.
     * @param string|null $failurereason Reason for failure, if applicable.
     */
    private function update_progress($status, $processed, $total, $failurereason = null) {
        global $DB;

        $taskid = $this->get_id();
        $progress = $DB->get_record('filter_autotranslate_task_progress', ['taskid' => $taskid]);
        if ($progress) {
            $progress->status = $status;
            $progress->processed_entries = $processed;
            $progress->total_entries = $total;
            $progress->timemodified = time();
            if ($status === 'failed' && $failurereason) {
                $progress->failure_reason = $failurereason;
            }
            $DB->update_record('filter_autotranslate_task_progress', $progress);
        }

        if ($failurereason) {
            debugging("Autotranslate Task $taskid: $failurereason", DEBUG_DEVELOPER);
        }
    }

    /**
     * Extracts error message from API response.
     *
     * Parses JSON response for a user-friendly error message.
     *
     * @param string $response API response body.
     * @return string Error message or 'Unknown error occurred.'
     */
    private function extract_error_message($response) {
        $data = json_decode($response, true);
        if ($data && isset($data['error']['message'])) {
            return $data['error']['message'];
        }
        return "Unknown error occurred.";
    }

    /**
     * Extracts HTTP status code from exception message.
     *
     * Parses exception message for the HTTP code.
     *
     * @param string $message Exception message.
     * @return string HTTP code or 'Unknown' if not found.
     */
    private function extract_http_code($message) {
        if (str_contains($message, 'HTTP')) {
            $parts = explode('HTTP', $message);
            return trim(explode(' ', $parts[1])[0]);
        }
        return 'Unknown';
    }

    /**
     * Fetches translations for untranslated hashes.
     *
     * Calls an external API to translate hashes, stores results via `content_service`,
     * and updates progress. Re-queues if runtime nears limit.
     */
    public function execute() {
        global $DB;

        $data = $this->get_custom_data();
        if (!$data) {
            $this->update_progress('failed', 0, 0, 'No custom data provided.');
            return;
        }

        $targetlang = $data->targetlang;
        $courseid = $data->courseid;
        $hashes = $data->hashes;
        $totalentries = $data->total_entries;

        if (empty($hashes)) {
            $this->update_progress('completed', 0, 0, 'No hashes to process.');
            return;
        }

        $this->update_progress('running', 0, $totalentries);

        $contentservice = new \filter_autotranslate\content_service($DB);
        $apiendpoint = get_config('filter_autotranslate', 'apiendpoint')
            ?: 'https://generativelanguage.googleapis.com/v1beta/openai';
        $apikey = get_config('filter_autotranslate', 'apikey');
        $apimodel = get_config('filter_autotranslate', 'apimodel') ?: 'gemini-1.5-pro-latest';
        $systeminstructions = get_config('filter_autotranslate', 'systeminstructions') ?: 'Translate with a formal tone.';
        $maxattempts = get_config('filter_autotranslate', 'maxattempts') ?: 3;
        $ratelimitthreshold = get_config('filter_autotranslate', 'ratelimitthreshold') ?: 50;

        if (empty($apikey)) {
            $this->update_progress('failed', 0, $totalentries, 'API key not configured.');
            return;
        }

        // Build arrays for texts, record indices, and context levels.
        $texts = [];
        $recordindices = [];
        $contextlevels = [];
        $processed = 0;
        $requests = 0;
        $starttime = time();
        $maxruntime = 180; // 3 minutes, less than the 4-minute default limit

        foreach ($hashes as $index => $hash) {
            $record = $DB->get_record('filter_autotranslate_translations', ['hash' => $hash, 'lang' => 'other']);
            if (!$record) {
                $processed++;
                $this->update_progress('running', $processed, $totalentries);
                continue;
            }
            $texts[$index] = $record->translated_text;
            $recordindices[$index] = $index;
            $contextlevels[$index] = $record->contextlevel;
        }

        $batchsize = 5; // Process in batches to manage API load.
        $textbatches = array_chunk($texts, $batchsize, true);

        foreach ($textbatches as $batch) {
            // Check runtime to avoid exceeding limit.
            if (time() - $starttime > $maxruntime) {
                $this->update_progress(
                    'running',
                    $processed,
                    $totalentries,
                    'Task runtime approaching limit. Will continue in the next run.'
                );
                $remaininghashes = array_slice($hashes, $processed);
                if (!empty($remaininghashes)) {
                    $task = new \filter_autotranslate\task\autotranslate_adhoc_task();
                    $task->set_custom_data([
                        'targetlang' => $targetlang,
                        'courseid' => $courseid,
                        'hashes' => $remaininghashes,
                        'total_entries' => $totalentries,
                    ]);
                    \core\task\manager::queue_adhoc_task($task);
                }
                return;
            }

            $textcount = count($batch);
            $textlist = '';
            $batchindices = array_keys($batch);
            foreach ($batch as $index => $text) {
                // Use 1-based index for the API prompt, but relative to the batch.
                $textindex = (array_search($index, $batchindices) + 1);
                $textlist .= "$textindex. " . addslashes($text) . "\n";
            }

            $example = "[{\"$targetlang\": \"Example translation in $targetlang\"}]";
            $prompt = "Translate the following $textcount texts into the following language: $targetlang. " .
                "Return the result as a JSON array where each element is a dictionary mapping the language " .
                "code to the translated text as a string. The array must contain exactly $textcount elements, " .
                "one for each text, in the same order as the input list. For each text, you MUST provide a translation " .
                "for $targetlang. If the language is missing for any text, the response will be considered invalid. " .
                "Do not nest the translations under additional keys like 'translation'. Here is an " .
                "example for 1 text translated into $targetlang:\n" .
                $example . "\n" .
                "Now translate the following texts:\n" . $textlist;

            $schema = [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [$targetlang => ['type' => 'string']],
                    'required' => [$targetlang],
                    'additionalProperties' => ['type' => 'string'],
                ],
                'minItems' => $textcount,
                'maxItems' => $textcount,
            ];

            $attempt = 0;
            $success = false;
            $translations = [];

            while ($attempt < $maxattempts && !$success) {
                $attempt++;
                $requests++;

                // Instantiate Moodle's curl class.
                $curl = new curl();

                try {
                    // Set the URL.
                    $url = rtrim($apiendpoint, '/') . '/chat/completions';

                    // Set headers.
                    $headers = [
                        'Content-Type: application/json',
                        'Accept: application/json',
                        "Authorization: Bearer $apikey",
                    ];

                    // Prepare POST data.
                    $postdata = json_encode([
                        'model' => $apimodel,
                        'messages' => [
                            ['role' => 'system', 'content' => $systeminstructions],
                            ['role' => 'user', 'content' => $prompt],
                        ],
                        'response_format' => [
                            'type' => 'json_schema',
                            'json_schema' => [
                                'name' => 'translation-schema',
                                'schema' => $schema,
                            ],
                        ],
                        'temperature' => 0.7,
                    ]);

                    // Set options (equivalent to curl_setopt).
                    $curl->setHeader($headers);
                    $curl->setopt([
                        'CURLOPT_RETURNTRANSFER' => 1,
                        'CURLOPT_CONNECTTIMEOUT' => 10,
                        'CURLOPT_TIMEOUT' => 120,
                    ]);

                    // Execute the POST request.
                    $result = $curl->post($url, $postdata);

                    // Get HTTP status code.
                    $httpcode = $curl->info['http_code'];

                    if ($httpcode == 429) {
                        sleep(60);
                        continue;
                    }

                    if ($httpcode != 200) {
                        $errormessage = $this->extract_error_message($result);
                        throw new \moodle_exception(
                            'apierror',
                            'filter_autotranslate',
                            '',
                            "Error communicating with the translation API: HTTP $httpcode - $errormessage"
                        );
                    }

                    $response = json_decode($result, true);
                    if (isset($response['choices'][0]['message']['content'])) {
                        $translatedtext = $response['choices'][0]['message']['content'];
                        $translationsarray = json_decode($translatedtext, true);
                        if (!is_array($translationsarray)) {
                            throw new \moodle_exception(
                                'apierror',
                                'filter_autotranslate',
                                '',
                                'Invalid JSON response from API: ' . $translatedtext
                            );
                        }

                        foreach ($translationsarray as $responseindex => $langtranslations) {
                            if (!is_array($langtranslations) || !isset($langtranslations[$targetlang])) {
                                continue;
                            }
                            // Map the response index (0-based) to the original index in the batch.
                            $batchindex = $batchindices[$responseindex];
                            $translations[$batchindex] = $langtranslations[$targetlang];
                        }
                        $success = true;
                    } else {
                        throw new \moodle_exception(
                            'apierror',
                            'filter_autotranslate',
                            '',
                            'Invalid API response: ' . json_encode($response)
                        );
                    }
                } catch (\Exception $e) {
                    if ($attempt == $maxattempts) {
                        $this->update_progress('failed', $processed, $totalentries, 'API error: ' . $e->getMessage());
                        return;
                    }
                    sleep(5);
                }

                if ($requests >= $ratelimitthreshold) {
                    sleep(60);
                    $requests = 0;
                }
            }

            if (!$success) {
                $this->update_progress('failed', $processed, $totalentries, 'Failed to fetch translations after maximum attempts.');
                return;
            }

            // Store translations.
            foreach ($translations as $index => $translatedtext) {
                $hash = $hashes[$index];
                $contextlevel = $contextlevels[$index];
                $context = $courseid ? \context_course::instance($courseid) : null;
                $contentservice->upsert_translation($hash, $targetlang, $translatedtext, $contextlevel, 0);
                $processed++;
                $this->update_progress('running', $processed, $totalentries);
            }
        }

        $this->update_progress('completed', $processed, $totalentries, 'Translations fetched successfully.');
    }
}
