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
 * Autotranslate Tag Content Task
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace filter_autotranslate\task;

defined('MOODLE_INTERNAL') || die();

use core\task\scheduled_task;
use filter_autotranslate\helper;
use filter_autotranslate\tagging_config;

class tagcontent_task extends scheduled_task {

    /**
     * Returns the name of the scheduled task.
     *
     * @return string
     */
    public function get_name() {
        return 'Auto Translate Text Tagging';
    }

    /**
     * Executes the scheduled task to tag untagged text and process mlang tags.
     */
    public function execute() {
        global $DB;

        $selectedctx = get_config('filter_autotranslate', 'selectctx') ?: '40,50,70,80';
        $selectedctx = !empty($selectedctx) ? array_filter(array_map('trim', explode(',', $selectedctx))) : ['40', '50', '70', '80'];
        $managelimit = get_config('filter_autotranslate', 'managelimit') ?: 20;
        $sitelang = get_config('core', 'lang') ?: 'en';

        $batchsize = $managelimit;
        $total_entries_checked = 0; // Counter for total entries checked

        // Get the configured tables and fields
        $tables = \filter_autotranslate\tagging_config::get_default_tables();

        // Filter the tables based on the selected contexts and admin configuration
        $configured_tables = [];
        $tagging_options_raw = get_config('filter_autotranslate', 'tagging_config');

        // Convert the tagging options to an array of selected options
        $tagging_options = [];
        if ($tagging_options_raw !== false && $tagging_options_raw !== null && $tagging_options_raw !== '') {
            if (is_string($tagging_options_raw)) {
                // If it's a comma-separated string, explode it into an array
                $tagging_options = array_map('trim', explode(',', $tagging_options_raw));
            } elseif (is_array($tagging_options_raw)) {
                // If it's already an array (e.g., Moodle decoded the JSON), use it directly
                $tagging_options = $tagging_options_raw;
            }
        }

        // Convert the array of selected options into a key-value map for easier lookup
        $tagging_options_map = [];
        foreach ($tagging_options as $option) {
            $tagging_options_map[$option] = true;
        }

        foreach ($tables as $contextlevel => $table_list) {
            if (!in_array($contextlevel, $selectedctx)) {
                continue;
            }

            $configured_tables[$contextlevel] = [];
            foreach ($table_list as $table => $fields) {
                $configured_fields = [];
                foreach ($fields as $field) {
                    $key = "ctx{$contextlevel}_{$table}_{$field}";
                    // A field is enabled if it's present in the tagging options (checked)
                    if (isset($tagging_options_map[$key])) {
                        $configured_fields[] = $field;
                    }
                }
                if (!empty($configured_fields)) {
                    $configured_tables[$contextlevel][$table] = $configured_fields;
                }
            }
        }

        mtrace("Starting Auto Translate task with contexts: " . implode(', ', $selectedctx));

        foreach ($configured_tables as $ctx => $tables) {
            foreach ($tables as $table => $fields) {
                foreach ($fields as $field) {
                    $offset = 0;

                    do {
                        try {
                            // Special handling for course and course_sections to fetch course ID
                            if ($table === 'course') {
                                $sql = "SELECT c.id AS instanceid, c.$field AS content, c.id AS course
                                        FROM {course} c
                                        WHERE c.$field IS NOT NULL";
                            } elseif ($table === 'course_sections') {
                                $sql = "SELECT cs.id AS instanceid, cs.$field AS content, cs.course
                                        FROM {course_sections} cs
                                        JOIN {course} c ON c.id = cs.course
                                        WHERE cs.$field IS NOT NULL";
                            } else {
                                $sql = "SELECT m.id AS instanceid, m.$field AS content, cm.course
                                        FROM {" . $table . "} m
                                        JOIN {course_modules} cm ON cm.instance = m.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                        WHERE m.$field IS NOT NULL";
                            }
                            $params = ($table === 'course' || $table === 'course_sections') ? [] : ['modulename' => $table];
                            $records = $DB->get_records_sql($sql, $params, $offset, $batchsize);
                            $count = count($records);
                            $total_entries_checked += $count; // Increment the counter

                            if ($count > 0) {
                                $firstrecord = reset($records);
                                $context = $this->get_context_for_level($ctx, $firstrecord->instanceid, $table, $firstrecord);
                                if (!$context) {
                                    continue 2; // Skip to the next field
                                }
                            } else {
                                $context = \context_system::instance(); // Fallback for no records
                            }

                            foreach ($records as $record) {
                                $raw_content = $record->content;
                                $content = trim($raw_content);
                                $old_hash = $this->extract_hash($raw_content);

                                if (!helper::is_tagged($raw_content) && !empty($content)) {
                                    // Process both old-style <span> and new-style {mlang} tags
                                    $taggedcontent = helper::process_mlang_tags($content, $context);

                                    if ($taggedcontent === $content) {
                                        $taggedcontent = helper::tag_content($content, $context);
                                    }

                                    if ($taggedcontent !== $content) {
                                        $new_hash = $this->extract_hash($taggedcontent);
                                        if (!$new_hash) {
                                            continue;
                                        }

                                        $courseid = $this->get_courseid($ctx, $table, $record);
                                        if ($courseid) {
                                            if ($old_hash && $old_hash !== $new_hash) {
                                                $this->remove_old_hash_mapping($old_hash, $courseid);
                                            }
                                            $this->update_hash_course_mapping($new_hash, $courseid);
                                            try {
                                                $DB->set_field($table, $field, $taggedcontent, ['id' => $record->instanceid]);
                                            } catch (\dml_exception $e) {
                                                // Error handling is silent as per your preference
                                            }
                                        }
                                    }
                                }
                            }

                            $offset += $batchsize;
                        } catch (\dml_exception $e) {
                            break;
                        }
                    } while ($count >= $batchsize);
                }
            }
        }

        mtrace("Auto Translate task completed. Total entries checked: $total_entries_checked");
    }

    /**
     * Extracts the hash from tagged content.
     *
     * @param string $content The content to parse
     * @return string|null The extracted hash, or null if not found
     */
    private function extract_hash($content) {
        if (preg_match('/\{t:([a-zA-Z0-9]{10})\}$/', $content, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Determines the courseid for a given record based on context and table.
     *
     * @param int $contextlevel The context level
     * @param string $table The table name
     * @param \stdClass $record The record being processed
     * @return int|null The courseid, or null if not found
     */
    private function get_courseid($contextlevel, $table, $record) {
        global $DB;

        if ($table === 'course' || $table === 'course_sections') {
            return isset($record->course) ? (int)$record->course : null;
        } elseif ($contextlevel == CONTEXT_COURSE) {
            return (int)$record->instanceid; // instanceid is the courseid in CONTEXT_COURSE
        } elseif ($contextlevel == CONTEXT_MODULE) {
            // For modules, find the courseid via course_modules
            $params = ['instanceid' => $record->instanceid, 'modulename' => $table];
            $cm = $DB->get_record_sql("SELECT cm.course FROM {course_modules} cm
                                       JOIN {modules} m ON m.id = cm.module
                                       WHERE m.name = :modulename AND cm.instance = :instanceid", $params);
            return $cm ? (int)$cm->course : null;
        }

        return null; // Default case: no courseid if context isn’t course-related
    }

    /**
     * Updates the autotranslate_hid_cids table with the hash and courseid mapping.
     *
     * @param string $hash The hash to map
     * @param int $courseid The courseid to map
     */
    private function update_hash_course_mapping($hash, $courseid) {
        global $DB;

        if (!$hash || !$courseid) {
            return;
        }

        $exists = $DB->record_exists('autotranslate_hid_cids', ['hash' => $hash, 'courseid' => $courseid]);
        if (!$exists) {
            try {
                $DB->execute("INSERT INTO {autotranslate_hid_cids} (hash, courseid) VALUES (?, ?) 
                            ON DUPLICATE KEY UPDATE hash = hash", [$hash, $courseid]);
            } catch (\dml_exception $e) {
                // Error handling is silent as per your preference
            }
        }
    }

    /**
     * Removes an old hash mapping from autotranslate_hid_cids if it exists.
     *
     * @param string $hash The old hash to remove
     * @param int $courseid The associated courseid
     */
    private function remove_old_hash_mapping($hash, $courseid) {
        global $DB;

        if ($hash && $courseid) {
            $DB->delete_records('autotranslate_hid_cids', ['hash' => $hash, 'courseid' => $courseid]);
        }
    }

    /**
     * Get the context instance based on context level and instance ID.
     *
     * @param int $contextlevel The context level
     * @param int $instanceid The instance ID
     * @param string $table The table name
     * @param \stdClass $record The record being processed
     * @return \context|null The context instance or null if not found
     */
    private function get_context_for_level($contextlevel, $instanceid, $table, $record) {
        $courseid = $this->get_courseid($contextlevel, $table, $record);

        switch ($contextlevel) {
            case CONTEXT_SYSTEM:
                return \context_system::instance();
            case CONTEXT_USER:
                return \context_user::instance($instanceid);
            case CONTEXT_COURSECAT:
                return \context_coursecat::instance($instanceid);
            case CONTEXT_COURSE:
                if ($table === 'course' || $table === 'course_sections') {
                    return \context_course::instance($record->course);
                }
                return \context_course::instance($instanceid);
            case CONTEXT_MODULE:
                if ($courseid) {
                    $cm = \get_coursemodule_from_instance($table, $record->instanceid, $courseid);
                    return $cm && $cm->id ? \context_module::instance($cm->id) : null;
                }
                return null;
            case CONTEXT_BLOCK:
                return \context_block::instance($instanceid);
            default:
                return null;
        }
    }
}