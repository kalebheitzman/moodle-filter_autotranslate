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
 * Tagging Manager for filter_autotranslate
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace filter_autotranslate;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/filter/autotranslate/classes/tagging_config.php');

/**
 * Class to manage the tagging process for the filter_autotranslate plugin.
 *
 * Purpose:
 * This class coordinates the tagging process, determining which fields to tag,
 * managing secondary tables, and preparing content for tagging. It acts as a middle
 * layer between tasks (e.g., tagcontent_task.php) and the observer (observer.php),
 * delegating database operations to the tagging_service.php service class.
 *
 * Design Decisions:
 * - Focuses on coordination logic only, with no database operations, to adhere to the
 *   principle of separation of concerns. Database writes (e.g., updating records,
 *   storing hash-course mappings) are handled by tagging_service.php.
 * - Simplifies secondary table handling by aligning with the single-query approach
 *   used in tagcontent_task.php, reducing complexity compared to the previous two-step
 *   process (fetch instance IDs, then fetch fields).
 * - Handles complex secondary tables (e.g., question_answers) as special cases with
 *   custom queries, due to Moodle's question bank structure requiring multiple joins.
 * - Function names use snake_case (e.g., get_fields_to_tag) to follow Moodle's coding style.
 *
 * Dependencies:
 * - tagging_config.php: Defines the tables, fields, and relationships to tag.
 * - helper.php: Provides utility functions for tag detection and MLang tag processing.
 * - tagging_service.php: Handles database operations for tagging (e.g., updating records).
 */
class tagging_manager {

    /**
     * Fetches the fields to tag for a given table and context level from the tagging configuration.
     *
     * This function retrieves the fields defined in tagging_config.php for a specific table
     * and context level, applying any admin-configured filters (e.g., tagging_config setting).
     *
     * @param string $table_name The name of the table (e.g., 'course', 'course_sections', 'assign').
     * @param int $context_level The context level (e.g., 50 for courses, 70 for course modules).
     * @return array The list of fields to tag.
     */
    public static function get_fields_to_tag($table_name, $context_level) {
        $selectedctx = get_config('filter_autotranslate', 'selectctx');
        $selectedctx = $selectedctx ? array_map('trim', explode(',', $selectedctx)) : ['40', '50', '70', '80'];
        if (!in_array((string)$context_level, $selectedctx)) {
            return [];
        }

        // Get the configured tables and fields
        $tables = \filter_autotranslate\tagging_config::get_default_tables();
        $tagging_options_raw = get_config('filter_autotranslate', 'tagging_config');

        // Convert the tagging options to an array of selected options
        $tagging_options = [];
        if ($tagging_options_raw !== false && $tagging_options_raw !== null && $tagging_options_raw !== '') {
            if (is_string($tagging_options_raw)) {
                $tagging_options = array_map('trim', explode(',', $tagging_options_raw));
            } elseif (is_array($tagging_options_raw)) {
                $tagging_options = $tagging_options_raw;
            }
        }

        // Convert the array of selected options into a key-value map for easier lookup
        $tagging_options_map = [];
        foreach ($tagging_options as $option) {
            $tagging_options_map[$option] = true;
        }

        // Check if the context level and table exist in the default tables
        if (!isset($tables[$context_level]) || !isset($tables[$context_level][$table_name])) {
            return [];
        }

        // Filter the fields based on the tagging configuration
        $fields = $tables[$context_level][$table_name]['fields'] ?? [];
        $filtered_fields = [];
        foreach ($fields as $field) {
            $key = "ctx{$context_level}_{$table_name}_{$field}";
            if (empty($tagging_options_map) || isset($tagging_options_map[$key])) {
                $filtered_fields[] = $field;
            }
        }

        return $filtered_fields;
    }

    /**
     * Fetches secondary tables and their fields for a given primary table and context level.
     *
     * This function retrieves the secondary tables (e.g., book_chapters for book) defined in
     * tagging_config.php, along with their fields to tag, applying any admin-configured filters.
     *
     * @param string $primary_table The name of the primary table (e.g., 'quiz').
     * @param int $context_level The context level (e.g., 70 for course modules).
     * @return array Array of secondary tables and their fields.
     */
    public static function get_secondary_tables($primary_table, $context_level) {
        $selectedctx = get_config('filter_autotranslate', 'selectctx');
        $selectedctx = $selectedctx ? array_map('trim', explode(',', $selectedctx)) : ['40', '50', '70', '80'];
        if (!in_array((string)$context_level, $selectedctx)) {
            return [];
        }

        // Get the configured tables and fields
        $tables = \filter_autotranslate\tagging_config::get_default_tables();
        $tagging_options_raw = get_config('filter_autotranslate', 'tagging_config');

        // Convert the tagging options to an array of selected options
        $tagging_options = [];
        if ($tagging_options_raw !== false && $tagging_options_raw !== null && $tagging_options_raw !== '') {
            if (is_string($tagging_options_raw)) {
                $tagging_options = array_map('trim', explode(',', $tagging_options_raw));
            } elseif (is_array($tagging_options_raw)) {
                $tagging_options = $tagging_options_raw;
            }
        }

        // Convert the array of selected options into a key-value map for easier lookup
        $tagging_options_map = [];
        foreach ($tagging_options as $option) {
            $tagging_options_map[$option] = true;
        }

        // Check if the context level and primary table exist in the default tables
        if (!isset($tables[$context_level]) || !isset($tables[$context_level][$primary_table])) {
            return [];
        }

        $secondary_tables = [];
        $secondary_config = $tables[$context_level][$primary_table]['secondary'] ?? [];
        foreach ($secondary_config as $secondary_table => $config) {
            $secondary_fields = $config['fields'] ?? [];
            $filtered_fields = [];
            foreach ($secondary_fields as $field) {
                $key = "ctx{$context_level}_{$secondary_table}_{$field}";
                if (empty($tagging_options_map) || isset($tagging_options_map[$key])) {
                    $filtered_fields[] = $field;
                }
            }
            if (!empty($filtered_fields)) {
                $secondary_tables[$secondary_table] = $filtered_fields;
            }
        }

        return $secondary_tables;
    }

    /**
     * Coordinates the tagging of fields in a record.
     *
     * This function processes each field in the record, tagging untagged content with {t:hash} tags
     * using helper::tag_content. It no longer performs database updates; instead, it delegates
     * database operations (e.g., updating records, storing hash-course mappings) to tagging_service.php.
     *
     * @param string $table The table name (e.g., 'course', 'book_chapters').
     * @param object $record The record object containing the fields to tag.
     * @param array $fields The fields to tag.
     * @param \context $context The context object for tagging.
     * @param int $courseid The course ID for hash-course mapping.
     * @return array An array containing:
     *               - 'updated': bool Whether the record was updated.
     *               - 'record': object The updated record object.
     */
    public static function tag_fields($table, $record, $fields, $context, $courseid) {
        $updated = false;
        foreach ($fields as $field) {
            if (empty($record->$field)) {
                continue;
            }

            $content = $record->$field;
            if (helper::is_tagged($content)) {
                continue; // Skip already tagged content (database operations handled by caller)
            }

            // Process MLang tags if present
            $mlang_result = helper::process_mlang_tags($content, $context);
            $source_text = $mlang_result['source_text'];
            $display_text = $mlang_result['display_text'];
            $translations = $mlang_result['translations'];

            if (!empty($source_text)) {
                // Tag the content (hash will be generated or reused by tag_content)
                $taggedcontent = helper::tag_content($source_text, $context);
                $taggedcontent = str_replace($source_text, $display_text, $taggedcontent);
            } else {
                // No MLang tags, tag the content directly
                $taggedcontent = helper::tag_content($content, $context);
            }

            // Update the record field with the tagged content
            $record->$field = $taggedcontent;
            $updated = true;
        }

        return [
            'updated' => $updated,
            'record' => $record,
        ];
    }

    /**
     * Builds an SQL query to fetch records from a secondary table for tagging.
     *
     * This function constructs a query to fetch records from a secondary table (e.g., book_chapters),
     * including all fields to tag and the associated course ID. It aligns with the simplified
     * single-query approach used in tagcontent_task.php, avoiding the previous two-step process.
     *
     * @param string $secondary_table The secondary table name (e.g., 'book_chapters').
     * @param array $fields The fields to fetch from the secondary table.
     * @param string $primary_table The primary table name (e.g., 'book').
     * @param int $primary_instanceid The primary instance ID (e.g., book ID), 0 to fetch all.
     * @param array $relationship Relationship details from tagging_config.php.
     * @param bool $include_course_modules Whether to include JOIN with course_modules (for create/update events).
     * @return array [SQL query, parameters].
     */
    public static function build_secondary_table_query($secondary_table, $fields, $primary_table, $primary_instanceid, $relationship, $include_course_modules = true) {
        $fk = $relationship['fk'];
        $parent_table = $relationship['parent_table'];
        $parent_fk = $relationship['parent_fk'];
        $grandparent_table = $relationship['grandparent_table'];
        $grandparent_fk = $relationship['grandparent_fk'];

        if ($secondary_table === 'question') {
            // Special case: question requires multiple joins due to Moodle's question bank structure.
            // Questions are part of a shared question bank and may be used in multiple quizzes.
            // We join through question_versions, question_bank_entries, question_references, and quiz_slots
            // to determine the associated course ID.
            $sql = "SELECT s.id AS instanceid, " . implode(', ', array_map(function($f) { return "s.$f"; }, $fields)) . ", cm.course, p.id AS primary_instanceid, cm.id AS cmid
                    FROM {{$secondary_table}} s
                    JOIN {question_versions} qv ON qv.questionid = s.id
                    JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                    JOIN {question_references} qr ON qr.questionbankentryid = qbe.id
                    JOIN {{$parent_table}} pt ON pt.id = qr.itemid
                    JOIN {{$primary_table}} p ON p.id = pt.quizid";
            if ($include_course_modules) {
                $sql .= " JOIN {course_modules} cm ON cm.instance = p.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                          JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel";
            }
            $sql .= " WHERE " . implode(' OR ', array_map(function($f) { return "(s.$f IS NOT NULL AND s.$f != '')"; }, $fields));
            if ($include_course_modules) {
                $sql .= " AND qr.usingcontextid = ctx.id
                          AND qr.component = 'mod_quiz'
                          AND qr.questionarea = 'slot'";
            }
            if ($primary_instanceid > 0) {
                $sql .= " AND p.id = :instanceid";
            }
            $params = $include_course_modules ? ['modulename' => $primary_table, 'contextlevel' => CONTEXT_MODULE] : [];
            if ($primary_instanceid > 0) {
                $params['instanceid'] = $primary_instanceid;
            }
        } elseif ($secondary_table === 'question_answers') {
            // Special case: question_answers requires multiple joins due to Moodle's question bank structure.
            // We join through question, question_versions, question_bank_entries, question_references, and quiz_slots
            // to determine the associated course ID.
            $sql = "SELECT s.id AS instanceid, " . implode(', ', array_map(function($f) { return "s.$f"; }, $fields)) . ", cm.course, p.id AS primary_instanceid, cm.id AS cmid
                    FROM {{$secondary_table}} s
                    JOIN {{$parent_table}} pt ON s.question = pt.id
                    JOIN {question_versions} qv ON qv.questionid = pt.id
                    JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                    JOIN {question_references} qr ON qr.questionbankentryid = qbe.id
                    JOIN {{$grandparent_table}} gpt ON gpt.id = qr.itemid
                    JOIN {{$primary_table}} p ON p.id = gpt.quizid";
            if ($include_course_modules) {
                $sql .= " JOIN {course_modules} cm ON cm.instance = p.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                          JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel";
            }
            $sql .= " WHERE " . implode(' OR ', array_map(function($f) { return "(s.$f IS NOT NULL AND s.$f != '')"; }, $fields));
            if ($include_course_modules) {
                $sql .= " AND qr.usingcontextid = ctx.id
                          AND qr.component = 'mod_quiz'
                          AND qr.questionarea = 'slot'";
            }
            if ($primary_instanceid > 0) {
                $sql .= " AND p.id = :instanceid";
            }
            $params = $include_course_modules ? ['modulename' => $primary_table, 'contextlevel' => CONTEXT_MODULE] : [];
            if ($primary_instanceid > 0) {
                $params['instanceid'] = $primary_instanceid;
            }
        } elseif ($secondary_table === 'question_categories') {
            // Special case: question_categories requires multiple joins due to Moodle's question bank structure.
            // We join through question_bank_entries, question_references, and quiz_slots
            // to determine the associated course ID.
            $sql = "SELECT s.id AS instanceid, " . implode(', ', array_map(function($f) { return "s.$f"; }, $fields)) . ", cm.course, p.id AS primary_instanceid, cm.id AS cmid
                    FROM {{$secondary_table}} s
                    JOIN {question_bank_entries} qbe ON qbe.questioncategoryid = s.id
                    JOIN {question_references} qr ON qr.questionbankentryid = qbe.id
                    JOIN {{$grandparent_table}} gpt ON gpt.id = qr.itemid
                    JOIN {{$primary_table}} p ON p.id = gpt.quizid";
            if ($include_course_modules) {
                $sql .= " JOIN {course_modules} cm ON cm.instance = p.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                          JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel";
            }
            $sql .= " WHERE " . implode(' OR ', array_map(function($f) { return "(s.$f IS NOT NULL AND s.$f != '')"; }, $fields));
            if ($include_course_modules) {
                $sql .= " AND qr.usingcontextid = ctx.id
                          AND qr.component = 'mod_quiz'
                          AND qr.questionarea = 'slot'";
            }
            if ($primary_instanceid > 0) {
                $sql .= " AND p.id = :instanceid";
            }
            $params = $include_course_modules ? ['modulename' => $primary_table, 'contextlevel' => CONTEXT_MODULE] : [];
            if ($primary_instanceid > 0) {
                $params['instanceid'] = $primary_instanceid;
            }
        } else {
            // General case for simpler secondary tables
            $sql = "SELECT s.id AS instanceid, " . implode(', ', array_map(function($f) { return "s.$f"; }, $fields)) . ", cm.course, p.id AS primary_instanceid, cm.id AS cmid
                    FROM {{$secondary_table}} s";
            if ($grandparent_table) {
                $sql .= " JOIN {{$parent_table}} pt ON s.$fk = pt.id
                          JOIN {{$grandparent_table}} gpt ON pt.$parent_fk = gpt.id
                          JOIN {{$primary_table}} p ON p.id = gpt.$grandparent_fk";
            } elseif ($parent_table) {
                $sql .= " JOIN {{$parent_table}} pt ON s.$fk = pt.id
                          JOIN {{$primary_table}} p ON p.id = pt.$parent_fk";
            } else {
                $sql .= " JOIN {{$primary_table}} p ON p.id = s.$fk";
            }
            if ($include_course_modules) {
                $sql .= " JOIN {course_modules} cm ON cm.instance = p.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)";
            }
            $sql .= " WHERE " . implode(' OR ', array_map(function($f) { return "(s.$f IS NOT NULL AND s.$f != '')"; }, $fields));
            if ($primary_instanceid > 0) {
                $sql .= " AND p.id = :instanceid";
            }
            $params = $include_course_modules ? ['modulename' => $primary_table] : [];
            if ($primary_instanceid > 0) {
                $params['instanceid'] = $primary_instanceid;
            }
        }

        return [$sql, $params];
    }

    /**
     * Coordinates the processing of secondary tables for a primary record.
     *
     * This function is used by the observer (observer.php) to tag content in secondary tables
     * when a primary record is created or updated (e.g., tagging book_chapters when a book is created).
     * It fetches records from secondary tables and coordinates tagging, delegating database updates
     * to tagging_service.php.
     *
     * @param string $primary_table The primary table name (e.g., 'quiz').
     * @param int $primary_instanceid The primary instance ID (e.g., quiz ID).
     * @param array $secondary_tables Array of secondary tables and their fields.
     * @param \context $context The context object for tagging.
     * @param int $courseid The course ID for hash-course mapping.
     * @param bool $include_course_modules Whether to include JOIN with course_modules (for create/update events).
     */
    public static function process_secondary_tables($primary_table, $primary_instanceid, $secondary_tables, $context, $courseid, $include_course_modules = true) {
        global $DB;

        foreach ($secondary_tables as $secondary_table => $fields) {
            $relationship = \filter_autotranslate\tagging_config::get_relationship_details($secondary_table);

            list($sql, $params) = self::build_secondary_table_query($secondary_table, $fields, $primary_table, $primary_instanceid, $relationship, $include_course_modules);
            $records = $DB->get_records_sql($sql, $params);
            foreach ($records as $record) {
                $record->course = $courseid; // Ensure courseid is set for hash mapping
                $result = self::tag_fields($secondary_table, $record, $fields, $context, $courseid);
                // Caller (e.g., observer.php) is responsible for handling database updates
            }
        }
    }
}