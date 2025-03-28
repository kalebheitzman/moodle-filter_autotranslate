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
 * Tagging Manager for filter_autotranslate
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace filter_autotranslate;

use filter_autotranslate\tagging_config;

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
     * @param string $tablename The name of the table (e.g., 'course', 'course_sections', 'assign').
     * @param int $contextlevel The context level (e.g., 50 for courses, 70 for course modules).
     * @return array The list of fields to tag.
     */
    public static function get_fields_to_tag($tablename, $contextlevel) {
        $selectedctx = get_config('filter_autotranslate', 'selectctx') ?: '40,50,70,80';
        $selectedctx = array_map('trim', explode(',', (string)$selectedctx));
        if (!in_array((string)$contextlevel, $selectedctx)) {
            return [];
        }

        // Get the configured tables and fields.
        $tables = \filter_autotranslate\tagging_config::get_default_tables();
        $taggingoptionsraw = get_config('filter_autotranslate', 'tagging_config') ?: '';
        $taggingoptions = $taggingoptionsraw ? array_map('trim', explode(',', (string)$taggingoptionsraw)) : [];
        $taggingoptionsmap = array_fill_keys($taggingoptions, true);

        // Check if the context level and table exist in the default tables.
        if (!isset($tables[$contextlevel]) || !isset($tables[$contextlevel][$tablename])) {
            return [];
        }

        // Filter the fields based on the tagging configuration.
        $fields = $tables[$contextlevel][$tablename]['fields'] ?? [];
        $filteredfields = [];
        foreach ($fields as $field) {
            $key = "ctx{$contextlevel}_{$tablename}_{$field}";
            if (empty($taggingoptions) || isset($taggingoptionsmap[$key])) {
                $filteredfields[] = $field;
            }
        }

        return $filteredfields;
    }

    /**
     * Fetches secondary tables and their fields for a given primary table and context level.
     *
     * This function retrieves the secondary tables (e.g., book_chapters for book) defined in
     * tagging_config.php, along with their fields to tag, applying any admin-configured filters.
     *
     * @param string $primarytable The name of the primary table (e.g., 'quiz').
     * @param int $contextlevel The context level (e.g., 70 for course modules).
     * @return array Array of secondary tables and their fields.
     */
    public static function get_secondary_tables($primarytable, $contextlevel) {
        $selectedctx = get_config('filter_autotranslate', 'selectctx') ?: '40,50,70,80';
        $selectedctx = array_map('trim', explode(',', (string)$selectedctx));
        if (!in_array((string)$contextlevel, $selectedctx)) {
            return [];
        }

        // Get the configured tables and fields.
        $tables = \filter_autotranslate\tagging_config::get_default_tables();
        $taggingoptionsraw = get_config('filter_autotranslate', 'tagging_config') ?: '';
        $taggingoptions = $taggingoptionsraw ? array_map('trim', explode(',', (string)$taggingoptionsraw)) : [];
        $taggingoptionsmap = array_fill_keys($taggingoptions, true);

        // Check if the context level and primary table exist in the default tables.
        if (!isset($tables[$contextlevel]) || !isset($tables[$contextlevel][$primarytable])) {
            return [];
        }

        $secondarytables = [];
        $secondaryconfig = $tables[$contextlevel][$primarytable]['secondary'] ?? [];
        foreach ($secondaryconfig as $secondarytable => $config) {
            $secondaryfields = $config['fields'] ?? [];
            $filteredfields = [];
            foreach ($secondaryfields as $field) {
                $key = "ctx{$contextlevel}_{$secondarytable}_{$field}";
                if (empty($taggingoptions) || isset($taggingoptionsmap[$key])) {
                    $filteredfields[] = $field;
                }
            }
            if (!empty($filteredfields)) {
                $secondarytables[$secondarytable] = $filteredfields;
            }
        }

        return $secondarytables;
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
                continue; // Skip already tagged content (database operations handled by caller).
            }

            // Process MLang tags if present.
            $mlangresult = helper::process_mlang_tags($content, $context);
            $sourcetext = $mlangresult['source_text'];
            $displaytext = $mlangresult['display_text'];
            $translations = $mlangresult['translations'];

            if (!empty($sourcetext)) {
                // Tag the content (hash will be generated or reused by tag_content).
                $taggedcontent = helper::tag_content($sourcetext, $context);
                $taggedcontent = str_replace($sourcetext, $displaytext, $taggedcontent);
            } else {
                // No MLang tags, tag the content directly.
                $taggedcontent = helper::tag_content($content, $context);
            }

            // Update the record field with the tagged content.
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
     * @param string $secondarytable The secondary table name (e.g., 'book_chapters').
     * @param array $fields The fields to fetch from the secondary table.
     * @param string $primarytable The primary table name (e.g., 'book').
     * @param int $primaryinstanceid The primary instance ID (e.g., book ID), 0 to fetch all.
     * @param array $relationship Relationship details from tagging_config.php.
     * @param bool $includecoursemodules Whether to include JOIN with course_modules (for create/update events).
     * @return array [SQL query, parameters].
     */
    public static function build_secondary_table_query(
        $secondarytable,
        $fields,
        $primarytable,
        $primaryinstanceid,
        $relationship,
        $includecoursemodules = true
    ) {
        $fk = $relationship['fk'];
        $parenttable = $relationship['parent_table'];
        $parentfk = $relationship['parent_fk'];
        $grandparenttable = $relationship['grandparent_table'];
        $grandparentfk = $relationship['grandparent_fk'];

        if ($secondarytable === 'question') {
            // Special case: question requires multiple joins due to Moodle's question bank structure.
            // Questions are part of a shared question bank and may be used in multiple quizzes.
            // We join through question_versions, question_bank_entries, question_references, and quiz_slots
            // to determine the associated course ID.
            $sql = "SELECT s.id AS instanceid, " . implode(', ', array_map(function ($f) {
                return "s.$f";
            }, $fields)) . ", cm.course, p.id AS primary_instanceid, cm.id AS cmid
                    FROM {{$secondarytable}} s
                    JOIN {question_versions} qv ON qv.questionid = s.id
                    JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                    JOIN {question_references} qr ON qr.questionbankentryid = qbe.id
                    JOIN {{$parenttable}} pt ON pt.id = qr.itemid
                    JOIN {{$primarytable}} p ON p.id = pt.quizid";
            if ($includecoursemodules) {
                $sql .= " JOIN {course_modules} cm ON cm.instance = p.id
                          AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                          JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel";
            }
            $sql .= " WHERE " . implode(' OR ', array_map(function ($f) {
                return "(s.$f IS NOT NULL AND s.$f != '')";
            }, $fields));
            if ($includecoursemodules) {
                $sql .= " AND qr.usingcontextid = ctx.id
                          AND qr.component = 'mod_quiz'
                          AND qr.questionarea = 'slot'";
            }
            if ($primaryinstanceid > 0) {
                $sql .= " AND p.id = :instanceid";
            }
            $params = $includecoursemodules ? ['modulename' => $primarytable, 'contextlevel' => CONTEXT_MODULE] : [];
            if ($primaryinstanceid > 0) {
                $params['instanceid'] = $primaryinstanceid;
            }
        } else if ($secondarytable === 'question_answers') {
            // Special case: question_answers requires multiple joins due to Moodle's question bank structure.
            // We join through question, question_versions, question_bank_entries, question_references, and quiz_slots
            // to determine the associated course ID.
            $sql = "SELECT s.id AS instanceid, " . implode(', ', array_map(function ($f) {
                return "s.$f";
            }, $fields)) . ", cm.course, p.id AS primary_instanceid, cm.id AS cmid
                    FROM {{$secondarytable}} s
                    JOIN {{$parenttable}} pt ON s.question = pt.id
                    JOIN {question_versions} qv ON qv.questionid = pt.id
                    JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                    JOIN {question_references} qr ON qr.questionbankentryid = qbe.id
                    JOIN {{$grandparenttable}} gpt ON gpt.id = qr.itemid
                    JOIN {{$primarytable}} p ON p.id = gpt.quizid";
            if ($includecoursemodules) {
                $sql .= " JOIN {course_modules} cm ON cm.instance = p.id
                        AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                          JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel";
            }
            $sql .= " WHERE " . implode(' OR ', array_map(function ($f) {
                return "(s.$f IS NOT NULL AND s.$f != '')";
            }, $fields));
            if ($includecoursemodules) {
                $sql .= " AND qr.usingcontextid = ctx.id
                          AND qr.component = 'mod_quiz'
                          AND qr.questionarea = 'slot'";
            }
            if ($primaryinstanceid > 0) {
                $sql .= " AND p.id = :instanceid";
            }
            $params = $includecoursemodules ? ['modulename' => $primarytable, 'contextlevel' => CONTEXT_MODULE] : [];
            if ($primaryinstanceid > 0) {
                $params['instanceid'] = $primaryinstanceid;
            }
        } else if ($secondarytable === 'question_categories') {
            // Special case: question_categories requires multiple joins due to Moodle's question bank structure.
            // We join through question_bank_entries, question_references, and quiz_slots
            // to determine the associated course ID.
            $sql = "SELECT s.id AS instanceid, " . implode(', ', array_map(function ($f) {
                return "s.$f";
            }, $fields)) . ", cm.course, p.id AS primary_instanceid, cm.id AS cmid
                    FROM {{$secondarytable}} s
                    JOIN {question_bank_entries} qbe ON qbe.questioncategoryid = s.id
                    JOIN {question_references} qr ON qr.questionbankentryid = qbe.id
                    JOIN {{$grandparenttable}} gpt ON gpt.id = qr.itemid
                    JOIN {{$primarytable}} p ON p.id = gpt.quizid";
            if ($includecoursemodules) {
                $sql .= " JOIN {course_modules} cm ON cm.instance = p.id
                          AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                          JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel";
            }
            $sql .= " WHERE " . implode(' OR ', array_map(function ($f) {
                return "(s.$f IS NOT NULL AND s.$f != '')";
            }, $fields));
            if ($includecoursemodules) {
                $sql .= " AND qr.usingcontextid = ctx.id
                          AND qr.component = 'mod_quiz'
                          AND qr.questionarea = 'slot'";
            }
            if ($primaryinstanceid > 0) {
                $sql .= " AND p.id = :instanceid";
            }
            $params = $includecoursemodules ? ['modulename' => $primarytable, 'contextlevel' => CONTEXT_MODULE] : [];
            if ($primaryinstanceid > 0) {
                $params['instanceid'] = $primaryinstanceid;
            }
        } else {
            // General case for simpler secondary tables.
            $sql = "SELECT s.id AS instanceid, " . implode(', ', array_map(function ($f) {
                return "s.$f";
            }, $fields)) . ", cm.course, p.id AS primary_instanceid, cm.id AS cmid
                    FROM {{$secondarytable}} s";
            if ($grandparenttable) {
                $sql .= " JOIN {{$parenttable}} pt ON s.$fk = pt.id
                          JOIN {{$grandparenttable}} gpt ON pt.$parentfk = gpt.id
                          JOIN {{$primarytable}} p ON p.id = gpt.$grandparentfk";
            } else if ($parenttable) {
                $sql .= " JOIN {{$parenttable}} pt ON s.$fk = pt.id
                          JOIN {{$primarytable}} p ON p.id = pt.$parentfk";
            } else {
                $sql .= " JOIN {{$primarytable}} p ON p.id = s.$fk";
            }
            if ($includecoursemodules) {
                $sql .= " JOIN {course_modules} cm ON cm.instance = p.id
                          AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)";
            }
            $sql .= " WHERE " . implode(' OR ', array_map(function ($f) {
                return "(s.$f IS NOT NULL AND s.$f != '')";
            }, $fields));
            if ($primaryinstanceid > 0) {
                $sql .= " AND p.id = :instanceid";
            }
            $params = $includecoursemodules ? ['modulename' => $primarytable] : [];
            if ($primaryinstanceid > 0) {
                $params['instanceid'] = $primaryinstanceid;
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
     * @param string $primarytable The primary table name (e.g., 'quiz').
     * @param int $primaryinstanceid The primary instance ID (e.g., quiz ID).
     * @param array $secondarytables Array of secondary tables and their fields.
     * @param \context $context The context object for tagging.
     * @param int $courseid The course ID for hash-course mapping.
     * @param bool $includecoursemodules Whether to include JOIN with course_modules (for create/update events).
     */
    public static function process_secondary_tables(
        $primarytable,
        $primaryinstanceid,
        $secondarytables,
        $context,
        $courseid,
        $includecoursemodules = true
    ) {
        global $DB;

        foreach ($secondarytables as $secondarytable => $fields) {
            $relationship = \filter_autotranslate\tagging_config::get_relationship_details($secondarytable);

            [$sql, $params] = self::build_secondary_table_query(
                $secondarytable,
                $fields,
                $primarytable,
                $primaryinstanceid,
                $relationship,
                $includecoursemodules
            );
            $records = $DB->get_records_sql($sql, $params);
            foreach ($records as $record) {
                $record->course = $courseid; // Ensure courseid is set for hash mapping.
                $result = self::tag_fields($secondarytable, $record, $fields, $context, $courseid);
                // Caller (e.g., observer.php) is responsible for handling database updates.
            }
        }
    }
}
