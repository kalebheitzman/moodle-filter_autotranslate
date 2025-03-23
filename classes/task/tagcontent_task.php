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
            foreach ($table_list as $table => $config) {
                // Handle primary table fields
                $fields = $config['fields'] ?? [];
                $configured_fields = [];
                foreach ($fields as $field) {
                    $key = "ctx{$contextlevel}_{$table}_{$field}";
                    // A field is enabled if it's present in the tagging options (checked) or if no options are set
                    if (empty($tagging_options_map) || isset($tagging_options_map[$key])) {
                        $configured_fields[] = $field;
                    }
                }
                if (!empty($configured_fields)) {
                    $configured_tables[$contextlevel][$table] = $configured_fields;
                    mtrace("Table: $table, Configured fields: " . implode(', ', $configured_fields));
                }

                // Handle secondary tables
                if (isset($config['secondary'])) {
                    foreach ($config['secondary'] as $secondary_table => $secondary_config) {
                        $secondary_fields = $secondary_config['fields'] ?? [];
                        $configured_secondary_fields = [];
                        foreach ($secondary_fields as $field) {
                            $key = "ctx{$contextlevel}_{$secondary_table}_{$field}";
                            if (empty($tagging_options_map) || isset($tagging_options_map[$key])) {
                                $configured_secondary_fields[] = $field;
                            }
                        }
                        if (!empty($configured_secondary_fields)) {
                            $configured_tables[$contextlevel][$secondary_table] = $configured_secondary_fields;
                            mtrace("Table: $secondary_table, Configured fields: " . implode(', ', $configured_secondary_fields));
                        }
                    }
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
                            // Determine if the table is a primary or secondary table
                            $relationship = \filter_autotranslate\tagging_config::get_relationship_details($table);
                            $is_secondary = !empty($relationship);
                            $primary_table = $is_secondary ? $relationship['primary_table'] : $table;
                            $fk = $is_secondary ? $relationship['fk'] : null;
                            $parent_table = $is_secondary ? $relationship['parent_table'] : null;
                            $parent_fk = $is_secondary ? $relationship['parent_fk'] : null;
                            $grandparent_table = $is_secondary ? $relationship['grandparent_table'] : null;
                            $grandparent_fk = $is_secondary ? $relationship['grandparent_fk'] : null;

                            // Build the SQL query based on the context level and whether it's a primary or secondary table
                            if ($ctx == CONTEXT_SYSTEM) { // Context level 10
                                if ($table === 'message') {
                                    $sql = "SELECT m.id AS instanceid, m.$field AS content, 0 AS course
                                            FROM {{$table}} m
                                            WHERE m.$field IS NOT NULL OR m.$field != ''";
                                    $params = [];
                                } elseif ($table === 'block_instances') {
                                    $sql = "SELECT bi.id AS instanceid, bi.$field AS content, COALESCE(c.id, 0) AS course
                                            FROM {{$table}} bi
                                            LEFT JOIN {context} ctx ON ctx.instanceid = bi.id AND ctx.contextlevel = :contextlevel
                                            LEFT JOIN {course} c ON c.id = ctx.instanceid AND ctx.contextlevel = :coursecontextlevel
                                            WHERE bi.$field IS NOT NULL OR bi.$field != ''";
                                    $params = ['contextlevel' => CONTEXT_BLOCK, 'coursecontextlevel' => CONTEXT_COURSE];
                                } else {
                                    $sql = "SELECT m.id AS instanceid, m.$field AS content, 0 AS course
                                            FROM {{$table}} m
                                            WHERE m.$field IS NOT NULL OR m.$field != ''";
                                    $params = [];
                                }
                            } elseif ($ctx == CONTEXT_USER) { // Context level 30
                                $sql = "SELECT uid.id AS instanceid, uid.$field AS content, 0 AS course
                                        FROM {{$table}} uid
                                        JOIN {user} u ON u.id = uid.userid
                                        WHERE uid.$field IS NOT NULL OR uid.$field != ''";
                                $params = [];
                            } elseif ($ctx == CONTEXT_COURSECAT) { // Context level 40
                                $sql = "SELECT cc.id AS instanceid, cc.$field AS content, 0 AS course
                                        FROM {{$table}} cc
                                        WHERE cc.$field IS NOT NULL OR cc.$field != ''";
                                $params = [];
                            } elseif ($ctx == CONTEXT_COURSE) { // Context level 50
                                if ($table === 'course') {
                                    $sql = "SELECT c.id AS instanceid, c.$field AS content, c.id AS course
                                            FROM {course} c
                                            WHERE c.$field IS NOT NULL OR c.$field != ''";
                                    $params = [];
                                } elseif ($table === 'course_sections') {
                                    $sql = "SELECT cs.id AS instanceid, cs.$field AS content, cs.course
                                            FROM {course_sections} cs
                                            JOIN {course} c ON c.id = cs.course
                                            WHERE cs.$field IS NOT NULL OR cs.$field != ''";
                                    $params = [];
                                } else {
                                    $sql = "SELECT m.id AS instanceid, m.$field AS content, cm.course, cm.id AS cmid
                                            FROM {" . $table . "} m
                                            JOIN {course_modules} cm ON cm.instance = m.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                            WHERE m.$field IS NOT NULL OR m.$field != ''";
                                    $params = ['modulename' => $table];
                                }
                            } elseif ($ctx == CONTEXT_MODULE) { // Context level 70
                                if ($is_secondary) {
                                    if ($parent_table && $grandparent_table) {
                                        // Handle nested secondary tables with a grandparent (e.g., wiki_versions, question_answers, question_categories)
                                        if ($table === 'question_answers') {
                                            $sql = "SELECT s.id AS instanceid, s.$field AS content, cm.course, p.id AS primary_instanceid, cm.id AS cmid
                                                    FROM {{$table}} s
                                                    JOIN {{$parent_table}} pt ON s.question = pt.id
                                                    JOIN {question_versions} qv ON qv.questionid = pt.id
                                                    JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                                                    JOIN {question_references} qr ON qr.questionbankentryid = qbe.id
                                                    JOIN {{$grandparent_table}} gpt ON gpt.id = qr.itemid
                                                    JOIN {{$primary_table}} p ON p.id = gpt.quizid
                                                    JOIN {course_modules} cm ON cm.instance = p.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                                    JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                                                    WHERE s.$field IS NOT NULL OR s.$field != ''
                                                    AND qr.usingcontextid = ctx.id
                                                    AND qr.component = 'mod_quiz'
                                                    AND qr.questionarea = 'slot'";
                                            $params = ['modulename' => $primary_table, 'contextlevel' => CONTEXT_MODULE];
                                        } elseif ($table === 'question_categories') {
                                            $sql = "SELECT s.id AS instanceid, s.$field AS content, cm.course, p.id AS primary_instanceid, cm.id AS cmid
                                                    FROM {{$table}} s
                                                    JOIN {question_bank_entries} qbe ON qbe.questioncategoryid = s.id
                                                    JOIN {question_references} qr ON qr.questionbankentryid = qbe.id
                                                    JOIN {{$grandparent_table}} gpt ON gpt.id = qr.itemid
                                                    JOIN {{$primary_table}} p ON p.id = gpt.quizid
                                                    JOIN {course_modules} cm ON cm.instance = p.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                                    JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                                                    WHERE s.$field IS NOT NULL OR s.$field != ''
                                                    AND qr.usingcontextid = ctx.id
                                                    AND qr.component = 'mod_quiz'
                                                    AND qr.questionarea = 'slot'";
                                            $params = ['modulename' => $primary_table, 'contextlevel' => CONTEXT_MODULE];
                                        } else {
                                            $sql = "SELECT s.id AS instanceid, s.$field AS content, cm.course, p.id AS primary_instanceid, cm.id AS cmid
                                                    FROM {{$table}} s
                                                    JOIN {{$parent_table}} pt ON s.$fk = pt.id
                                                    JOIN {{$grandparent_table}} gpt ON pt.$parent_fk = gpt.id
                                                    JOIN {{$primary_table}} p ON p.id = gpt.$grandparent_fk
                                                    JOIN {course_modules} cm ON cm.instance = p.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                                    WHERE s.$field IS NOT NULL OR s.$field != ''";
                                            $params = ['modulename' => $primary_table];
                                        }
                                    } elseif ($parent_table) {
                                        // Handle nested secondary tables (e.g., forum_posts, lesson_answers, question)
                                        if ($table === 'question') {
                                            $sql = "SELECT s.id AS instanceid, s.$field AS content, cm.course, p.id AS primary_instanceid, cm.id AS cmid
                                                    FROM {{$table}} s
                                                    JOIN {question_versions} qv ON qv.questionid = s.id
                                                    JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                                                    JOIN {question_references} qr ON qr.questionbankentryid = qbe.id
                                                    JOIN {{$parent_table}} pt ON pt.id = qr.itemid
                                                    JOIN {{$primary_table}} p ON p.id = pt.quizid
                                                    JOIN {course_modules} cm ON cm.instance = p.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                                    JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                                                    WHERE s.$field IS NOT NULL OR s.$field != ''
                                                    AND qr.usingcontextid = ctx.id
                                                    AND qr.component = 'mod_quiz'
                                                    AND qr.questionarea = 'slot'";
                                            $params = ['modulename' => $primary_table, 'contextlevel' => CONTEXT_MODULE];
                                        } else {
                                            $sql = "SELECT s.id AS instanceid, s.$field AS content, cm.course, p.id AS primary_instanceid, cm.id AS cmid
                                                    FROM {{$table}} s
                                                    JOIN {{$parent_table}} pt ON s.$fk = pt.id
                                                    JOIN {{$primary_table}} p ON p.id = pt.$parent_fk
                                                    JOIN {course_modules} cm ON cm.instance = p.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                                    WHERE s.$field IS NOT NULL OR s.$field != ''";
                                            $params = ['modulename' => $primary_table];
                                        }
                                    } else {
                                        // Handle direct secondary tables (e.g., book_chapters, choice_options)
                                        $sql = "SELECT s.id AS instanceid, s.$field AS content, cm.course, p.id AS primary_instanceid, cm.id AS cmid
                                                FROM {{$table}} s
                                                JOIN {{$primary_table}} p ON p.id = s.$fk
                                                JOIN {course_modules} cm ON cm.instance = p.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                                WHERE s.$field IS NOT NULL OR s.$field != ''";
                                        $params = ['modulename' => $primary_table];
                                    }
                                } else {
                                    // Primary table (e.g., book, choice)
                                    $sql = "SELECT m.id AS instanceid, m.$field AS content, cm.course, cm.id AS cmid
                                            FROM {" . $table . "} m
                                            JOIN {course_modules} cm ON cm.instance = m.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                            WHERE m.$field IS NOT NULL OR m.$field != ''";
                                    $params = ['modulename' => $table];
                                }
                            } elseif ($ctx == CONTEXT_BLOCK) { // Context level 80
                                $sql = "SELECT bi.id AS instanceid, bi.$field AS content, COALESCE(c.id, 0) AS course
                                        FROM {{$table}} bi
                                        LEFT JOIN {context} ctx ON ctx.instanceid = bi.id AND ctx.contextlevel = :contextlevel
                                        LEFT JOIN {course} c ON c.id = ctx.instanceid AND ctx.contextlevel = :coursecontextlevel
                                        WHERE bi.$field IS NOT NULL OR bi.$field != ''";
                                $params = ['contextlevel' => CONTEXT_BLOCK, 'coursecontextlevel' => CONTEXT_COURSE];
                            } else {
                                $sql = "SELECT m.id AS instanceid, m.$field AS content, 0 AS course
                                        FROM {{$table}} m
                                        WHERE m.$field IS NOT NULL OR m.$field != ''";
                                $params = [];
                            }

                            mtrace("Executing SQL for table $table, field $field: $sql");
                            mtrace("Parameters: " . json_encode($params));

                            $records = $DB->get_records_sql($sql, $params, $offset, $batchsize);
                            $count = count($records);
                            $total_entries_checked += $count; // Increment the counter
                            mtrace("Records fetched for table $table, field $field: $count");

                            if ($count > 0) {
                                $firstrecord = reset($records);
                                // Use cmid directly for context derivation
                                $context = null;
                                if (isset($firstrecord->cmid)) {
                                    $context = \context_module::instance($firstrecord->cmid);
                                } else {
                                    mtrace("No cmid found in record for table $table, field $field");
                                    $context = \context_system::instance(); // Fallback
                                }
                                mtrace("Context for table $table, cmid " . ($firstrecord->cmid ?? 'null') . ": " . ($context ? $context->id : 'null'));
                                if (!$context) {
                                    mtrace("Context is null, using system context as fallback");
                                    $context = \context_system::instance();
                                }
                            } else {
                                $context = \context_system::instance(); // Fallback for no records
                                mtrace("No records found, using system context");
                            }

                            foreach ($records as $record) {
                                $raw_content = $record->content;
                                $content = trim($raw_content);
                                $old_hash = $this->extract_hash($raw_content);
                                mtrace("Processing record for table $table, field $field, instanceid {$record->instanceid}, content: " . substr($raw_content, 0, 50) . "...");

                                if (helper::is_tagged($raw_content)) {
                                    mtrace("Content is already tagged, skipping");
                                    continue;
                                }
                                if (empty($content)) {
                                    mtrace("Content is empty after trimming, skipping");
                                    continue;
                                }

                                // Process both old-style <span> and new-style {mlang} tags
                                $taggedcontent = helper::process_mlang_tags($content, $context);

                                if ($taggedcontent === $content) {
                                    $taggedcontent = helper::tag_content($content, $context);
                                }

                                if ($taggedcontent !== $content) {
                                    $new_hash = $this->extract_hash($taggedcontent);
                                    if (!$new_hash) {
                                        mtrace("No new hash found in tagged content, skipping");
                                        continue;
                                    }

                                    // Use cmid for courseid derivation
                                    $courseid = $record->course;
                                    if ($courseid) {
                                        if ($old_hash && $old_hash !== $new_hash) {
                                            $this->remove_old_hash_mapping($old_hash, $courseid);
                                        }
                                        $this->update_hash_course_mapping($new_hash, $courseid);
                                        try {
                                            $DB->set_field($table, $field, $taggedcontent, ['id' => $record->instanceid]);
                                            mtrace("Tagged content for table $table, field $field, instanceid {$record->instanceid}: " . substr($taggedcontent, 0, 50) . "...");
                                        } catch (\dml_exception $e) {
                                            mtrace("Error updating record: " . $e->getMessage());
                                        }
                                    } else {
                                        mtrace("Course ID not found for table $table, instanceid {$record->instanceid}");
                                    }
                                }
                            }

                            $offset += $batchsize;
                        } catch (\dml_exception $e) {
                            mtrace("Error executing query for table $table, field $field: " . $e->getMessage());
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