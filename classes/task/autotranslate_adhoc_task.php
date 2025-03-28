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

namespace filter_autotranslate\task;

use core\task\adhoc_task;

/**
 * Autotranslate Adhoc Task
 *
 * Purpose:
 * This task fetches translations for untranslated entries in a specific target language within
 * the filter_autotranslate plugin, storing them in the `filter_autotranslate_translations` table.
 * It processes a list of hashes, retrieves source text, calls an external API for translations,
 * and updates progress in `filter_autotranslate_task_progress`.
 *
 * Usage:
 * Queued via the 'Autotranslate' button on manage.php, passing target language, course ID, and
 * hashes to translate. Runs as an adhoc task, polling progress until completion or failure.
 *
 * Design Decisions:
 * - Uses `content_service` for storing translations, aligning with the plugin’s core structure.
 * - Processes in batches to manage API load, with configurable retry attempts and rate limiting.
 * - Updates progress in the database for UI feedback, re-queuing if runtime exceeds limits.
 * - Employs all lowercase variable names per plugin convention.
 *
 * Dependencies:
 * - `content_service.php`: For storing translated content.
 * - `filter_autotranslate_task_progress` table: For tracking task progress.
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class autotranslate_adhoc_task extends adhoc_task {
    /**
     * Returns the name of the task.
     *
     * @return string The task name for display.
     */
    public function get_name() {
        return get_string('autotranslateadhoc', 'filter_autotranslate');
    }

    /**
     * Updates the task progress in the database.
     *
     * Updates or logs the status, processed entries, and total entries in the
     * `filter_autotranslate_task_progress` table, with an optional failure reason.
     *
     * @param string $status The task status (queued, running, completed, failed).
     * @param int $processed The number of entries processed.
     * @param int $total The total number of entries to process.
     * @param string|null $failurereason Optional reason for failure if the task failed.
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
     * Extracts a user-friendly error message from the API response.
     *
     * Parses the API response to retrieve a meaningful error message for logging or display.
     *
     * @param string $response The API response body.
     * @return string The extracted error message or a default if none found.
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
     * Parses the exception message to retrieve the HTTP status code for error handling.
     *
     * @param string $message The exception message.
     * @return string The HTTP status code or 'Unknown' if not found.
     */
    private function extract_http_code($message) {
        if (str_contains($message, 'HTTP')) {
            $parts = explode('HTTP', $message);
            return trim(explode(' ', $parts[1])[0]);
        }
        return 'Unknown';
    }

    /**
     * Executes the task to fetch translations.
     *
     * Fetches translations for a list of hashes in a target language using an external API,
     * storing them via `content_service` and updating progress.
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

        // Build prompt for translation.
        $texts = [];
        $recordids = [];
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
            $textindex = $index + 1;
            $texts[$textindex] = $record->translated_text;
            $recordids[$textindex] = $index;
            $contextlevels[$textindex] = $record->contextlevel;
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
            foreach ($batch as $index => $text) {
                $textlist .= "$index. " . addslashes($text) . "\n";
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
                $ch = curl_init();
                try {
                    curl_setopt($ch, CURLOPT_URL, rtrim($apiendpoint, '/') . '/chat/completions');
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Content-Type: application/json',
                        'Accept: application/json',
                        "Authorization: Bearer $apikey",
                    ]);
                    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 120);

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

                    curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
                    $result = curl_exec($ch);
                    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

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

                        foreach ($translationsarray as $index => $langtranslations) {
                            $recordid = (string)($index + 1);
                            if (!is_array($langtranslations) || !isset($langtranslations[$targetlang])) {
                                continue;
                            }
                            $translations[$recordid] = $langtranslations[$targetlang];
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
                    curl_close($ch);
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
                $recordindex = $recordids[$index];
                $hash = $hashes[$recordindex];
                $contextlevel = $contextlevels[$index];
                $context = $courseid ? \context_course::instance($courseid) : null;
                $contentservice->store_translation($hash, $targetlang, $translatedtext, $contextlevel, $courseid, $context);
                $processed++;
                $this->update_progress('running', $processed, $totalentries);
            }
        }

        $this->update_progress('completed', $processed, $totalentries, 'Translations fetched successfully.');
    }
}
