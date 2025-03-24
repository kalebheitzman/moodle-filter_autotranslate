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

class tagging_manager {

    /**
     * Get the fields to tag for a given table and context level from the tagging configuration.
     *
     * @param string $tablename The name of the table (e.g., 'course', 'course_sections', 'assign')
     * @param int $contextlevel The context level (e.g., 50 for courses, 70 for course modules)
     * @return array The list of fields to tag
     */
    public static function get_fields_to_tag($tablename, $contextlevel) {
        $selectedctx = get_config('filter_autotranslate', 'selectctx');
        $selectedctx = $selectedctx ? array_map('trim', explode(',', $selectedctx)) : ['40', '50', '70', '80'];
        if (!in_array((string)$contextlevel, $selectedctx)) {
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
        if (!isset($tables[$contextlevel]) || !isset($tables[$contextlevel][$tablename])) {
            return [];
        }

        // Filter the fields based on the tagging configuration
        $fields = $tables[$contextlevel][$tablename]['fields'] ?? [];
        $filtered_fields = [];
        foreach ($fields as $field) {
            $key = "ctx{$contextlevel}_{$tablename}_{$field}";
            if (empty($tagging_options_map) || isset($tagging_options_map[$key])) {
                $filtered_fields[] = $field;
            }
        }

        return $filtered_fields;
    }

    /**
     * Get secondary tables and their fields for a given primary table and context level.
     *
     * @param string $primary_table The name of the primary table (e.g., 'quiz')
     * @param int $contextlevel The context level (e.g., 70 for course modules)
     * @return array Array of secondary tables and their fields
     */
    public static function get_secondary_tables($primary_table, $contextlevel) {
        $selectedctx = get_config('filter_autotranslate', 'selectctx');
        $selectedctx = $selectedctx ? array_map('trim', explode(',', $selectedctx)) : ['40', '50', '70', '80'];
        if (!in_array((string)$contextlevel, $selectedctx)) {
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
        if (!isset($tables[$contextlevel]) || !isset($tables[$contextlevel][$primary_table])) {
            return [];
        }

        $secondary_tables = [];
        $secondary_config = $tables[$contextlevel][$primary_table]['secondary'] ?? [];
        foreach ($secondary_config as $secondary_table => $config) {
            $secondary_fields = $config['fields'] ?? [];
            $filtered_fields = [];
            foreach ($secondary_fields as $field) {
                $key = "ctx{$contextlevel}_{$secondary_table}_{$field}";
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
     * Tag fields in a record and update the database.
     *
     * @param string $table Table name
     * @param object $record Record object
     * @param array $fields Fields to tag
     * @param \context $context Context object
     * @param int $courseid Course ID for hash mapping
     * @return bool Whether the record was updated
     */
    public static function tag_fields($table, $record, $fields, $context, $courseid) {
        global $DB;

        $updated = false;
        foreach ($fields as $field) {
            if (empty($record->$field)) {
                continue;
            }

            $content = $record->$field;
            if (helper::is_tagged($content)) {
                helper::update_hash_course_mapping($content, $courseid);
                helper::create_or_update_source_translation($content, $context->contextlevel);
                continue;
            }

            $taggedcontent = helper::process_mlang_tags($content, $context);
            if ($taggedcontent === $content) {
                $taggedcontent = helper::tag_content($content, $context);
            }

            if ($taggedcontent !== $content) {
                $record->$field = $taggedcontent;
                helper::update_hash_course_mapping($taggedcontent, $courseid);
                helper::create_or_update_source_translation($taggedcontent, $context->contextlevel);
                $updated = true;
            }
        }

        if ($updated) {
            $record->timemodified = time(); // Update timemodified when content is tagged
            $DB->update_record($table, $record);

            // Mark the context as dirty to refresh caches on the next request
            $context->mark_dirty();
        }

        return $updated;
    }

    /**
     * Build an SQL query to fetch secondary table records for a primary record.
     *
     * @param string $secondary_table Secondary table name (e.g., 'book_chapters')
     * @param string $field Field to fetch from the secondary table
     * @param string $primary_table Primary table name (e.g., 'book')
     * @param int $primary_instanceid Primary instance ID (e.g., book ID)
     * @param array $relationship Relationship details from tagging_config
     * @param bool $include_course_modules Whether to include JOIN with course_modules (for create/update events)
     * @return array [SQL query, parameters]
     */
    private static function build_secondary_table_query($secondary_table, $field, $primary_table, $primary_instanceid, $relationship, $include_course_modules = true) {
        $fk = $relationship['fk'];
        $parent_table = $relationship['parent_table'];
        $parent_fk = $relationship['parent_fk'];
        $grandparent_table = $relationship['grandparent_table'];
        $grandparent_fk = $relationship['grandparent_fk'];

        if ($secondary_table === 'question') {
            $sql = "SELECT s.id AS instanceid, s.$field AS content" . ($include_course_modules ? ", cm.course, p.id AS primary_instanceid, cm.id AS cmid" : "") . "
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
            $sql .= " WHERE p.id = :instanceid
                      AND s.$field IS NOT NULL OR s.$field != ''";
            if ($include_course_modules) {
                $sql .= " AND qr.usingcontextid = ctx.id
                          AND qr.component = 'mod_quiz'
                          AND qr.questionarea = 'slot'";
            }
            $params = $include_course_modules ? ['modulename' => $primary_table, 'contextlevel' => CONTEXT_MODULE, 'instanceid' => $primary_instanceid] : ['instanceid' => $primary_instanceid];
        } elseif ($secondary_table === 'question_answers') {
            $sql = "SELECT s.id AS instanceid, s.$field AS content" . ($include_course_modules ? ", cm.course, p.id AS primary_instanceid, cm.id AS cmid" : "") . "
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
            $sql .= " WHERE p.id = :instanceid
                      AND s.$field IS NOT NULL OR s.$field != ''";
            if ($include_course_modules) {
                $sql .= " AND qr.usingcontextid = ctx.id
                          AND qr.component = 'mod_quiz'
                          AND qr.questionarea = 'slot'";
            }
            $params = $include_course_modules ? ['modulename' => $primary_table, 'contextlevel' => CONTEXT_MODULE, 'instanceid' => $primary_instanceid] : ['instanceid' => $primary_instanceid];
        } elseif ($secondary_table === 'question_categories') {
            $sql = "SELECT s.id AS instanceid, s.$field AS content" . ($include_course_modules ? ", cm.course, p.id AS primary_instanceid, cm.id AS cmid" : "") . "
                    FROM {{$secondary_table}} s
                    JOIN {question_bank_entries} qbe ON qbe.questioncategoryid = s.id
                    JOIN {question_references} qr ON qr.questionbankentryid = qbe.id
                    JOIN {{$grandparent_table}} gpt ON gpt.id = qr.itemid
                    JOIN {{$primary_table}} p ON p.id = gpt.quizid";
            if ($include_course_modules) {
                $sql .= " JOIN {course_modules} cm ON cm.instance = p.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                          JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel";
            }
            $sql .= " WHERE p.id = :instanceid
                      AND s.$field IS NOT NULL OR s.$field != ''";
            if ($include_course_modules) {
                $sql .= " AND qr.usingcontextid = ctx.id
                          AND qr.component = 'mod_quiz'
                          AND qr.questionarea = 'slot'";
            }
            $params = $include_course_modules ? ['modulename' => $primary_table, 'contextlevel' => CONTEXT_MODULE, 'instanceid' => $primary_instanceid] : ['instanceid' => $primary_instanceid];
        } else {
            $sql = "SELECT s.id AS instanceid, s.$field AS content" . ($include_course_modules ? ", cm.course, p.id AS primary_instanceid, cm.id AS cmid" : "") . "
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
            $sql .= " WHERE p.id = :instanceid
                      AND s.$field IS NOT NULL OR s.$field != ''";
            $params = $include_course_modules ? ['modulename' => $primary_table, 'instanceid' => $primary_instanceid] : ['instanceid' => $primary_instanceid];
        }

        return [$sql, $params];
    }

    /**
     * Process secondary tables for a primary record.
     *
     * @param string $primary_table Primary table name (e.g., 'quiz')
     * @param int $primary_instanceid Primary instance ID (e.g., quiz ID)
     * @param array $secondary_tables Array of secondary tables and their fields
     * @param \context $context Context object
     * @param int $courseid Course ID for hash mapping
     * @param bool $include_course_modules Whether to include JOIN with course_modules (for create/update events)
     */
    public static function process_secondary_tables($primary_table, $primary_instanceid, $secondary_tables, $context, $courseid, $include_course_modules = true) {
        global $DB;

        foreach ($secondary_tables as $secondary_table => $fields) {
            $relationship = \filter_autotranslate\tagging_config::get_relationship_details($secondary_table);

            foreach ($fields as $field) {
                list($sql, $params) = self::build_secondary_table_query($secondary_table, $field, $primary_table, $primary_instanceid, $relationship, $include_course_modules);
                $records = $DB->get_records_sql($sql, $params);
                foreach ($records as $record) {
                    $record->course = $courseid; // Ensure courseid is set for hash mapping
                    self::tag_fields($secondary_table, $record, [$field], $context, $courseid);
                }
            }
        }
    }
}