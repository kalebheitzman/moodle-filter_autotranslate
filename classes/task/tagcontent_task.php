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
use filter_autotranslate\tagging_manager;
use filter_autotranslate\tagging_service;

/**
 * Scheduled task to tag untagged text with {t:hash} tags for translation.
 *
 * Purpose:
 * This task scans a predefined list of Moodle tables and fields, tags untagged content with
 * {t:hash} tags, and associates each hash with a course ID for filtering on the manage page.
 * It processes both primary tables (e.g., course, book) and secondary tables (e.g., book_chapters)
 * defined in tagging_config.php.
 *
 * Design Decisions:
 * - Uses the nested structure in tagging_config.php to organize tables by context level (e.g., 50 for courses, 70 for modules)
 *   for consistency with Moodle's context system and efficient course ID determination.
 * - Simplifies secondary table handling by fetching all fields in a single query with straightforward joins,
 *   avoiding the previous two-step process (fetch instance IDs, then fetch fields).
 * - Handles complex secondary tables (e.g., question_answers) as special cases with custom queries,
 *   due to Moodle's question bank structure requiring multiple joins.
 * - Reuses hashes for identical strings (after trimming) to ensure consistency and limit database growth.
 * - Associates hashes with course IDs in autotranslate_hid_cids, using 0 for non-course contexts.
 *
 * Dependencies:
 * - tagging_config.php: Defines the tables, fields, and relationships to tag.
 * - tagging_manager.php: Provides methods to fetch fields to tag and build queries for secondary tables.
 * - tagging_service.php: Handles database operations for tagging (e.g., updating records, storing hash-course mappings).
 */
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

        // Fetch configuration settings
        $selectedctx = get_config('filter_autotranslate', 'selectctx') ?: '40,50,70,80';
        $selectedctx = !empty($selectedctx) ? array_filter(array_map('trim', explode(',', $selectedctx))) : ['40', '50', '70', '80'];
        $managelimit = get_config('filter_autotranslate', 'managelimit') ?: 20;

        $batchsize = $managelimit;
        $total_entries_checked = 0;

        // Get the configured tables and fields from tagging_config.php
        $tables = \filter_autotranslate\tagging_config::get_default_tables();

        // Initialize the tagging service
        $tagging_service = new \filter_autotranslate\tagging_service($DB);

        mtrace("Starting Auto Translate task with contexts: " . implode(', ', $selectedctx));

        // Loop through each context level and table
        foreach ($tables as $ctx => $table_list) {
            // Skip if the context level is not selected
            if (!in_array((string)$ctx, $selectedctx)) {
                continue;
            }

            // Process primary tables
            foreach ($table_list as $table => $config) {
                $fields = \filter_autotranslate\tagging_manager::get_fields_to_tag($table, $ctx);
                if (empty($fields)) {
                    continue;
                }

                mtrace("Processing primary table: $table, Fields: " . implode(', ', $fields));

                $offset = 0;
                do {
                    try {
                        // Build the query to fetch records for the primary table
                        // The query includes all fields to tag and the course ID
                        if ($ctx == CONTEXT_SYSTEM) { // Context level 10
                            if ($table === 'message') {
                                $sql = "SELECT m.id AS instanceid, " . implode(', ', array_map(function($f) { return "m.$f"; }, $fields)) . ", 0 AS course
                                        FROM {{$table}} m
                                        WHERE " . implode(' OR ', array_map(function($f) { return "(m.$f IS NOT NULL AND m.$f != '')"; }, $fields));
                                $params = [];
                            } elseif ($table === 'block_instances') {
                                $sql = "SELECT bi.id AS instanceid, " . implode(', ', array_map(function($f) { return "bi.$f"; }, $fields)) . ", COALESCE(c.id, 0) AS course
                                        FROM {{$table}} bi
                                        LEFT JOIN {context} ctx ON ctx.instanceid = bi.id AND ctx.contextlevel = :contextlevel
                                        LEFT JOIN {course} c ON c.id = ctx.instanceid AND ctx.contextlevel = :coursecontextlevel
                                        WHERE " . implode(' OR ', array_map(function($f) { return "(bi.$f IS NOT NULL AND bi.$f != '')"; }, $fields));
                                $params = ['contextlevel' => CONTEXT_BLOCK, 'coursecontextlevel' => CONTEXT_COURSE];
                            } else {
                                $sql = "SELECT m.id AS instanceid, " . implode(', ', array_map(function($f) { return "m.$f"; }, $fields)) . ", 0 AS course
                                        FROM {{$table}} m
                                        WHERE " . implode(' OR ', array_map(function($f) { return "(m.$f IS NOT NULL AND m.$f != '')"; }, $fields));
                                $params = [];
                            }
                        } elseif ($ctx == CONTEXT_USER) { // Context level 30
                            $sql = "SELECT uid.id AS instanceid, " . implode(', ', array_map(function($f) { return "uid.$f"; }, $fields)) . ", 0 AS course
                                    FROM {{$table}} uid
                                    JOIN {user} u ON u.id = uid.userid
                                    WHERE " . implode(' OR ', array_map(function($f) { return "(uid.$f IS NOT NULL AND uid.$f != '')"; }, $fields));
                            $params = [];
                        } elseif ($ctx == CONTEXT_COURSECAT) { // Context level 40
                            $sql = "SELECT cc.id AS instanceid, " . implode(', ', array_map(function($f) { return "cc.$f"; }, $fields)) . ", 0 AS course
                                    FROM {{$table}} cc
                                    WHERE " . implode(' OR ', array_map(function($f) { return "(cc.$f IS NOT NULL AND cc.$f != '')"; }, $fields));
                            $params = [];
                        } elseif ($ctx == CONTEXT_COURSE) { // Context level 50
                            if ($table === 'course') {
                                $sql = "SELECT c.id AS instanceid, " . implode(', ', array_map(function($f) { return "c.$f"; }, $fields)) . ", c.id AS course
                                        FROM {course} c
                                        WHERE " . implode(' OR ', array_map(function($f) { return "(c.$f IS NOT NULL AND c.$f != '')"; }, $fields));
                                $params = [];
                            } elseif ($table === 'course_sections') {
                                $sql = "SELECT cs.id AS instanceid, " . implode(', ', array_map(function($f) { return "cs.$f"; }, $fields)) . ", cs.course
                                        FROM {course_sections} cs
                                        JOIN {course} c ON c.id = cs.course
                                        WHERE " . implode(' OR ', array_map(function($f) { return "(cs.$f IS NOT NULL AND cs.$f != '')"; }, $fields));
                                $params = [];
                            } else {
                                $sql = "SELECT m.id AS instanceid, " . implode(', ', array_map(function($f) { return "m.$f"; }, $fields)) . ", cm.course, cm.id AS cmid
                                        FROM {" . $table . "} m
                                        JOIN {course_modules} cm ON cm.instance = m.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                        WHERE " . implode(' OR ', array_map(function($f) { return "(m.$f IS NOT NULL AND m.$f != '')"; }, $fields));
                                $params = ['modulename' => $table];
                            }
                        } elseif ($ctx == CONTEXT_MODULE) { // Context level 70
                            $sql = "SELECT m.id AS instanceid, " . implode(', ', array_map(function($f) { return "m.$f"; }, $fields)) . ", cm.course, cm.id AS cmid
                                    FROM {" . $table . "} m
                                    JOIN {course_modules} cm ON cm.instance = m.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                    WHERE " . implode(' OR ', array_map(function($f) { return "(m.$f IS NOT NULL AND m.$f != '')"; }, $fields));
                            $params = ['modulename' => $table];
                        } elseif ($ctx == CONTEXT_BLOCK) { // Context level 80
                            $sql = "SELECT bi.id AS instanceid, " . implode(', ', array_map(function($f) { return "bi.$f"; }, $fields)) . ", COALESCE(c.id, 0) AS course
                                    FROM {{$table}} bi
                                    LEFT JOIN {context} ctx ON ctx.instanceid = bi.id AND ctx.contextlevel = :contextlevel
                                    LEFT JOIN {course} c ON c.id = ctx.instanceid AND ctx.contextlevel = :coursecontextlevel
                                    WHERE " . implode(' OR ', array_map(function($f) { return "(bi.$f IS NOT NULL AND bi.$f != '')"; }, $fields));
                            $params = ['contextlevel' => CONTEXT_BLOCK, 'coursecontextlevel' => CONTEXT_COURSE];
                        } else {
                            $sql = "SELECT m.id AS instanceid, " . implode(', ', array_map(function($f) { return "m.$f"; }, $fields)) . ", 0 AS course
                                    FROM {{$table}} m
                                    WHERE " . implode(' OR ', array_map(function($f) { return "(m.$f IS NOT NULL AND m.$f != '')"; }, $fields));
                            $params = [];
                        }

                        // Fetch records in batches
                        $records = $DB->get_records_sql($sql, $params, $offset, $batchsize);
                        $count = count($records);
                        $total_entries_checked += $count;

                        // Determine the context for tagging
                        if ($count > 0) {
                            $firstrecord = reset($records);
                            $context = null;
                            if (isset($firstrecord->cmid)) {
                                $context = \context_module::instance($firstrecord->cmid);
                            } else {
                                $context = \context_system::instance();
                            }
                        } else {
                            $context = \context_system::instance();
                        }

                        // Process each record
                        foreach ($records as $record) {
                            if (!isset($record->instanceid)) {
                                mtrace("Error: Record missing instanceid for table $table: " . json_encode($record));
                                continue;
                            }
                            $record->id = $record->instanceid;
                            unset($record->instanceid);
                            $courseid = $record->course;
                            unset($record->course);
                            if (isset($record->cmid)) {
                                unset($record->cmid);
                            }
                            if (!isset($record->id)) {
                                mtrace("Error: Record missing id field after renaming for table $table");
                                continue;
                            }

                            // Tag the record using the tagging service
                            $result = \filter_autotranslate\tagging_manager::tag_fields($table, $record, $fields, $context, $courseid);
                            if ($result['updated']) {
                                $updated = $tagging_service->tag_content($table, $result['record'], $fields, $context, $courseid);
                                if ($updated) {
                                    mtrace("Tagged content in table $table, instanceid {$record->id}");
                                }
                            }
                        }

                        $offset += $batchsize;
                    } catch (\dml_exception $e) {
                        mtrace("Error executing query for table $table: " . $e->getMessage());
                        break;
                    }
                } while ($count >= $batchsize);

                // Process secondary tables for module contexts
                if ($ctx == CONTEXT_MODULE) {
                    $secondary_tables = \filter_autotranslate\tagging_manager::get_secondary_tables($table, $ctx);
                    foreach ($secondary_tables as $secondary_table => $secondary_fields) {
                        mtrace("Processing secondary table: $secondary_table, Fields: " . implode(', ', $secondary_fields));
                        $offset = 0;
                        do {
                            try {
                                $relationship = \filter_autotranslate\tagging_config::get_relationship_details($secondary_table);
                                $fk = $relationship['fk'];
                                $parent_table = $relationship['parent_table'];
                                $parent_fk = $relationship['parent_fk'];
                                $grandparent_table = $relationship['grandparent_table'];
                                $grandparent_fk = $relationship['grandparent_fk'];

                                // Build the query to fetch records for the secondary table
                                // Special case handling for complex tables
                                if ($secondary_table === 'question') {
                                    // Special case: question requires multiple joins due to Moodle's question bank structure.
                                    // Questions are part of a shared question bank and may be used in multiple quizzes.
                                    // We join through question_versions, question_bank_entries, question_references, and quiz_slots
                                    // to determine the associated course ID.
                                    $sql = "SELECT s.id AS instanceid, " . implode(', ', array_map(function($f) { return "s.$f"; }, $secondary_fields)) . ", cm.course, p.id AS primary_instanceid, cm.id AS cmid
                                            FROM {{$secondary_table}} s
                                            JOIN {question_versions} qv ON qv.questionid = s.id
                                            JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                                            JOIN {question_references} qr ON qr.questionbankentryid = qbe.id
                                            JOIN {{$parent_table}} pt ON pt.id = qr.itemid
                                            JOIN {{$table}} p ON p.id = pt.quizid
                                            JOIN {course_modules} cm ON cm.instance = p.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                            JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                                            WHERE " . implode(' OR ', array_map(function($f) { return "(s.$f IS NOT NULL AND s.$f != '')"; }, $secondary_fields)) . "
                                            AND qr.usingcontextid = ctx.id
                                            AND qr.component = 'mod_quiz'
                                            AND qr.questionarea = 'slot'";
                                    $params = ['modulename' => $table, 'contextlevel' => CONTEXT_MODULE];
                                } elseif ($secondary_table === 'question_answers') {
                                    // Special case: question_answers requires multiple joins due to Moodle's question bank structure.
                                    // We join through question, question_versions, question_bank_entries, question_references, and quiz_slots
                                    // to determine the associated course ID.
                                    $sql = "SELECT s.id AS instanceid, " . implode(', ', array_map(function($f) { return "s.$f"; }, $secondary_fields)) . ", cm.course, p.id AS primary_instanceid, cm.id AS cmid
                                            FROM {{$secondary_table}} s
                                            JOIN {{$parent_table}} pt ON s.question = pt.id
                                            JOIN {question_versions} qv ON qv.questionid = pt.id
                                            JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                                            JOIN {question_references} qr ON qr.questionbankentryid = qbe.id
                                            JOIN {{$grandparent_table}} gpt ON gpt.id = qr.itemid
                                            JOIN {{$table}} p ON p.id = gpt.quizid
                                            JOIN {course_modules} cm ON cm.instance = p.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                            JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                                            WHERE " . implode(' OR ', array_map(function($f) { return "(s.$f IS NOT NULL AND s.$f != '')"; }, $secondary_fields)) . "
                                            AND qr.usingcontextid = ctx.id
                                            AND qr.component = 'mod_quiz'
                                            AND qr.questionarea = 'slot'";
                                    $params = ['modulename' => $table, 'contextlevel' => CONTEXT_MODULE];
                                } elseif ($secondary_table === 'question_categories') {
                                    // Special case: question_categories requires multiple joins due to Moodle's question bank structure.
                                    // We join through question_bank_entries, question_references, and quiz_slots
                                    // to determine the associated course ID.
                                    $sql = "SELECT s.id AS instanceid, " . implode(', ', array_map(function($f) { return "s.$f"; }, $secondary_fields)) . ", cm.course, p.id AS primary_instanceid, cm.id AS cmid
                                            FROM {{$secondary_table}} s
                                            JOIN {question_bank_entries} qbe ON qbe.questioncategoryid = s.id
                                            JOIN {question_references} qr ON qr.questionbankentryid = qbe.id
                                            JOIN {{$grandparent_table}} gpt ON gpt.id = qr.itemid
                                            JOIN {{$table}} p ON p.id = gpt.quizid
                                            JOIN {course_modules} cm ON cm.instance = p.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                            JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                                            WHERE " . implode(' OR ', array_map(function($f) { return "(s.$f IS NOT NULL AND s.$f != '')"; }, $secondary_fields)) . "
                                            AND qr.usingcontextid = ctx.id
                                            AND qr.component = 'mod_quiz'
                                            AND qr.questionarea = 'slot'";
                                    $params = ['modulename' => $table, 'contextlevel' => CONTEXT_MODULE];
                                } else {
                                    // General case for simpler secondary tables
                                    if ($grandparent_table) {
                                        $sql = "SELECT s.id AS instanceid, " . implode(', ', array_map(function($f) { return "s.$f"; }, $secondary_fields)) . ", cm.course, p.id AS primary_instanceid, cm.id AS cmid
                                                FROM {{$secondary_table}} s
                                                JOIN {{$parent_table}} pt ON s.$fk = pt.id
                                                JOIN {{$grandparent_table}} gpt ON pt.$parent_fk = gpt.id
                                                JOIN {{$table}} p ON p.id = gpt.$grandparent_fk
                                                JOIN {course_modules} cm ON cm.instance = p.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                                WHERE " . implode(' OR ', array_map(function($f) { return "(s.$f IS NOT NULL AND s.$f != '')"; }, $secondary_fields));
                                    } elseif ($parent_table) {
                                        $sql = "SELECT s.id AS instanceid, " . implode(', ', array_map(function($f) { return "s.$f"; }, $secondary_fields)) . ", cm.course, p.id AS primary_instanceid, cm.id AS cmid
                                                FROM {{$secondary_table}} s
                                                JOIN {{$parent_table}} pt ON s.$fk = pt.id
                                                JOIN {{$table}} p ON p.id = pt.$parent_fk
                                                JOIN {course_modules} cm ON cm.instance = p.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                                WHERE " . implode(' OR ', array_map(function($f) { return "(s.$f IS NOT NULL AND s.$f != '')"; }, $secondary_fields));
                                    } else {
                                        $sql = "SELECT s.id AS instanceid, " . implode(', ', array_map(function($f) { return "s.$f"; }, $secondary_fields)) . ", cm.course, p.id AS primary_instanceid, cm.id AS cmid
                                                FROM {{$secondary_table}} s
                                                JOIN {{$table}} p ON p.id = s.$fk
                                                JOIN {course_modules} cm ON cm.instance = p.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                                WHERE " . implode(' OR ', array_map(function($f) { return "(s.$f IS NOT NULL AND s.$f != '')"; }, $secondary_fields));
                                    }
                                    $params = ['modulename' => $table];
                                }

                                // Fetch records in batches
                                $records = $DB->get_records_sql($sql, $params, $offset, $batchsize);
                                $count = count($records);
                                $total_entries_checked += $count;

                                // Determine the context for tagging
                                if ($count > 0) {
                                    $firstrecord = reset($records);
                                    $context = null;
                                    if (isset($firstrecord->cmid)) {
                                        $context = \context_module::instance($firstrecord->cmid);
                                    } else {
                                        $context = \context_system::instance();
                                    }
                                } else {
                                    $context = \context_system::instance();
                                }

                                // Process each record
                                foreach ($records as $record) {
                                    if (!isset($record->instanceid)) {
                                        mtrace("Error: Secondary record missing instanceid for table $secondary_table: " . json_encode($record));
                                        continue;
                                    }
                                    $record->id = $record->instanceid;
                                    unset($record->instanceid);
                                    $courseid = $record->course;
                                    unset($record->course);
                                    if (isset($record->cmid)) {
                                        unset($record->cmid);
                                    }
                                    if (isset($record->primary_instanceid)) {
                                        unset($record->primary_instanceid);
                                    }
                                    if (!isset($record->id)) {
                                        mtrace("Error: Secondary record missing id field after renaming for table $secondary_table");
                                        continue;
                                    }

                                    // Tag the record using the tagging service
                                    $result = \filter_autotranslate\tagging_manager::tag_fields($secondary_table, $record, $secondary_fields, $context, $courseid);
                                    if ($result['updated']) {
                                        $updated = $tagging_service->tag_content($secondary_table, $result['record'], $secondary_fields, $context, $courseid);
                                        if ($updated) {
                                            mtrace("Tagged content in table $secondary_table, instanceid {$record->id}");
                                        }
                                    }
                                }

                                $offset += $batchsize;
                            } catch (\dml_exception $e) {
                                mtrace("Error executing query for table $secondary_table: " . $e->getMessage());
                                break;
                            }
                        } while ($count >= $batchsize);
                    }
                }
            }
        }

        mtrace("Auto Translate task completed. Total entries checked: $total_entries_checked");
    }
}