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

        // Treat selectctx as CSV string
        $selectedctx = get_config('filter_autotranslate', 'selectctx') ?: '40,50,70,80';
        $selectedctx = array_filter(array_map('trim', explode(',', (string)$selectedctx)));
        $managelimit = (int)(get_config('filter_autotranslate', 'managelimit') ?: 20);

        $batchsize = $managelimit;
        $total_entries_checked = 0;

        // Instantiate tagging_service with the database connection
        $tagging_service = new tagging_service($DB);

        // Get the configured tables and fields
        $tables = \filter_autotranslate\tagging_config::get_default_tables();

        mtrace("Starting Auto Translate task with contexts: " . implode(', ', $selectedctx));

        foreach ($tables as $ctx => $table_list) {
            if (!in_array((string)$ctx, $selectedctx)) {
                continue;
            }

            foreach ($table_list as $table => $config) {
                $fields = \filter_autotranslate\tagging_manager::get_fields_to_tag($table, $ctx);
                if (empty($fields)) {
                    continue;
                }

                mtrace("Processing table: $table, Fields: " . implode(', ', $fields));
                foreach ($fields as $field) {
                    $offset = 0;

                    do {
                        try {
                            if ($ctx == CONTEXT_SYSTEM) { // Context level 10
                                if ($table === 'message') {
                                    $sql = "SELECT m.id AS instanceid, m.$field AS content, 0 AS course
                                            FROM {{$table}} m
                                            WHERE m.$field IS NOT NULL AND m.$field != ''";
                                    $params = [];
                                } elseif ($table === 'block_instances') {
                                    $sql = "SELECT bi.id AS instanceid, bi.$field AS content, COALESCE(c.id, 0) AS course
                                            FROM {{$table}} bi
                                            LEFT JOIN {context} ctx ON ctx.instanceid = bi.id AND ctx.contextlevel = :contextlevel
                                            LEFT JOIN {course} c ON c.id = ctx.instanceid AND ctx.contextlevel = :coursecontextlevel
                                            WHERE bi.$field IS NOT NULL AND bi.$field != ''";
                                    $params = ['contextlevel' => CONTEXT_BLOCK, 'coursecontextlevel' => CONTEXT_COURSE];
                                } else {
                                    $sql = "SELECT m.id AS instanceid, m.$field AS content, 0 AS course
                                            FROM {{$table}} m
                                            WHERE m.$field IS NOT NULL AND m.$field != ''";
                                    $params = [];
                                }
                            } elseif ($ctx == CONTEXT_USER) { // Context level 30
                                $sql = "SELECT uid.id AS instanceid, uid.$field AS content, 0 AS course
                                        FROM {{$table}} uid
                                        JOIN {user} u ON u.id = uid.userid
                                        WHERE uid.$field IS NOT NULL AND uid.$field != ''";
                                $params = [];
                            } elseif ($ctx == CONTEXT_COURSECAT) { // Context level 40
                                $sql = "SELECT cc.id AS instanceid, cc.$field AS content, 0 AS course
                                        FROM {{$table}} cc
                                        WHERE cc.$field IS NOT NULL AND cc.$field != ''";
                                $params = [];
                            } elseif ($ctx == CONTEXT_COURSE) { // Context level 50
                                if ($table === 'course') {
                                    $sql = "SELECT c.id AS instanceid, c.$field AS content, c.id AS course
                                            FROM {course} c
                                            WHERE c.$field IS NOT NULL AND c.$field != ''";
                                    $params = [];
                                } elseif ($table === 'course_sections') {
                                    $sql = "SELECT cs.id AS instanceid, cs.$field AS content, cs.course
                                            FROM {course_sections} cs
                                            JOIN {course} c ON c.id = cs.course
                                            WHERE cs.$field IS NOT NULL AND cs.$field != ''";
                                    $params = [];
                                } else {
                                    $sql = "SELECT m.id AS instanceid, m.$field AS content, cm.course, cm.id AS cmid
                                            FROM {" . $table . "} m
                                            JOIN {course_modules} cm ON cm.instance = m.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                            WHERE m.$field IS NOT NULL AND m.$field != ''";
                                    $params = ['modulename' => $table];
                                }
                            } elseif ($ctx == CONTEXT_MODULE) { // Context level 70
                                $sql = "SELECT m.id AS instanceid, m.$field AS content, cm.course, cm.id AS cmid
                                        FROM {" . $table . "} m
                                        JOIN {course_modules} cm ON cm.instance = m.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                        WHERE m.$field IS NOT NULL AND m.$field != ''";
                                $params = ['modulename' => $table];
                            } elseif ($ctx == CONTEXT_BLOCK) { // Context level 80
                                $sql = "SELECT bi.id AS instanceid, bi.$field AS content, COALESCE(c.id, 0) AS course
                                        FROM {{$table}} bi
                                        LEFT JOIN {context} ctx ON ctx.instanceid = bi.id AND ctx.contextlevel = :contextlevel
                                        LEFT JOIN {course} c ON c.id = ctx.instanceid AND ctx.contextlevel = :coursecontextlevel
                                        WHERE bi.$field IS NOT NULL AND bi.$field != ''";
                                    $params = ['contextlevel' => CONTEXT_BLOCK, 'coursecontextlevel' => CONTEXT_COURSE];
                            } else {
                                $sql = "SELECT m.id AS instanceid, m.$field AS content, 0 AS course
                                        FROM {{$table}} m
                                        WHERE m.$field IS NOT NULL AND m.$field != ''";
                                $params = [];
                            }

                            $records = $DB->get_records_sql($sql, $params, $offset, $batchsize);
                            $count = count($records);
                            $total_entries_checked += $count;
                            mtrace("Fetched $count records for table $table, field $field at offset $offset");

                            if ($count > 0) {
                                $firstrecord = reset($records);
                                $context = null;
                                if (isset($firstrecord->cmid)) {
                                    $context = \context_module::instance($firstrecord->cmid);
                                } else {
                                    $context = \context_system::instance(); // Fallback
                                }
                                mtrace("First record: " . json_encode($firstrecord));
                            } else {
                                $context = \context_system::instance(); // Fallback for no records
                            }

                            foreach ($records as $record) {
                                if (!isset($record->instanceid)) {
                                    mtrace("Error: Record missing instanceid for table $table, field $field: " . json_encode($record));
                                    continue;
                                }
                                $record->id = $record->instanceid;
                                unset($record->instanceid);
                                $record->$field = $record->content;
                                unset($record->content);
                                if (!isset($record->id)) {
                                    mtrace("Error: Record missing id field after renaming for table $table, field $field");
                                    continue;
                                }
                                // Use instantiated tagging_service
                                $updated = $tagging_service->tag_content($table, $record, [$field], $context, $record->course);
                                if ($updated) {
                                    mtrace("Tagged content in table $table, field $field, instanceid {$record->id}: " . substr($record->$field, 0, 50) . "...");
                                    // Corrected to use tagging_service method
                                    $tagging_service->mark_translations_for_revision($table, $record->id, [$field], $context);
                                }
                            }

                            $offset += $batchsize;
                        } catch (\dml_exception $e) {
                            mtrace("Error executing query for table $table, field $field: " . $e->getMessage());
                            break;
                        }
                    } while ($count >= $batchsize);
                }

                // Process secondary tables for module contexts
                if ($ctx == CONTEXT_MODULE) {
                    $secondary_tables = \filter_autotranslate\tagging_manager::get_secondary_tables($table, $ctx);
                    foreach ($secondary_tables as $secondary_table => $secondary_fields) {
                        mtrace("Processing table: $secondary_table, Fields: " . implode(', ', $secondary_fields));
                        $offset = 0;
                        do {
                            try {
                                $relationship = \filter_autotranslate\tagging_config::get_relationship_details($secondary_table);
                                $fk = $relationship['fk'];
                                $parent_table = $relationship['parent_table'];
                                $parent_fk = $relationship['parent_fk'];
                                $grandparent_table = $relationship['grandparent_table'];
                                $grandparent_fk = $relationship['grandparent_fk'];

                                // Fetch instance IDs to process
                                if ($secondary_table === 'question') {
                                    $sql = "SELECT DISTINCT s.id AS instanceid, cm.course, p.id AS primary_instanceid, cm.id AS cmid
                                            FROM {{$secondary_table}} s
                                            JOIN {question_versions} qv ON qv.questionid = s.id
                                            JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                                            JOIN {question_references} qr ON qr.questionbankentryid = qbe.id
                                            JOIN {{$parent_table}} pt ON pt.id = qr.itemid
                                            JOIN {{$table}} p ON p.id = pt.quizid
                                            JOIN {course_modules} cm ON cm.instance = p.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                            JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                                            WHERE qr.usingcontextid = ctx.id
                                            AND qr.component = 'mod_quiz'
                                            AND qr.questionarea = 'slot'";
                                    $params = ['modulename' => $table, 'contextlevel' => CONTEXT_MODULE];
                                } elseif ($secondary_table === 'question_answers') {
                                    $sql = "SELECT DISTINCT s.id AS instanceid, cm.course, p.id AS primary_instanceid, cm.id AS cmid
                                            FROM {{$secondary_table}} s
                                            JOIN {{$parent_table}} pt ON s.question = pt.id
                                            JOIN {question_versions} qv ON qv.questionid = pt.id
                                            JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                                            JOIN {question_references} qr ON qr.questionbankentryid = qbe.id
                                            JOIN {{$grandparent_table}} gpt ON gpt.id = qr.itemid
                                            JOIN {{$table}} p ON p.id = gpt.quizid
                                            JOIN {course_modules} cm ON cm.instance = p.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                            JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                                            WHERE qr.usingcontextid = ctx.id
                                            AND qr.component = 'mod_quiz'
                                            AND qr.questionarea = 'slot'";
                                    $params = ['modulename' => $table, 'contextlevel' => CONTEXT_MODULE];
                                } elseif ($secondary_table === 'question_categories') {
                                    $sql = "SELECT DISTINCT s.id AS instanceid, cm.course, p.id AS primary_instanceid, cm.id AS cmid
                                            FROM {{$secondary_table}} s
                                            JOIN {question_bank_entries} qbe ON qbe.questioncategoryid = s.id
                                            JOIN {question_references} qr ON qr.questionbankentryid = qbe.id
                                            JOIN {{$grandparent_table}} gpt ON gpt.id = qr.itemid
                                            JOIN {{$table}} p ON p.id = gpt.quizid
                                            JOIN {course_modules} cm ON cm.instance = p.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                            JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                                            WHERE qr.usingcontextid = ctx.id
                                            AND qr.component = 'mod_quiz'
                                            AND qr.questionarea = 'slot'";
                                    $params = ['modulename' => $table, 'contextlevel' => CONTEXT_MODULE];
                                } else {
                                    if ($grandparent_table) {
                                        $sql = "SELECT DISTINCT s.id AS instanceid, cm.course, p.id AS primary_instanceid, cm.id AS cmid
                                                FROM {{$secondary_table}} s
                                                JOIN {{$parent_table}} pt ON s.$fk = pt.id
                                                JOIN {{$grandparent_table}} gpt ON pt.$parent_fk = gpt.id
                                                JOIN {{$table}} p ON p.id = gpt.$grandparent_fk
                                                JOIN {course_modules} cm ON cm.instance = p.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)";
                                    } elseif ($parent_table) {
                                        $sql = "SELECT DISTINCT s.id AS instanceid, cm.course, p.id AS primary_instanceid, cm.id AS cmid
                                                FROM {{$secondary_table}} s
                                                JOIN {{$parent_table}} pt ON s.$fk = pt.id
                                                JOIN {{$table}} p ON p.id = pt.$parent_fk
                                                JOIN {course_modules} cm ON cm.instance = p.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)";
                                    } else {
                                        $sql = "SELECT DISTINCT s.id AS instanceid, cm.course, p.id AS primary_instanceid, cm.id AS cmid
                                                FROM {{$secondary_table}} s
                                                JOIN {{$table}} p ON p.id = s.$fk
                                                JOIN {course_modules} cm ON cm.instance = p.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)";
                                    }
                                    $params = ['modulename' => $table];
                                }

                                $records = $DB->get_records_sql($sql, $params, $offset, $batchsize);
                                $count = count($records);
                                $total_entries_checked += $count;

                                if ($count > 0) {
                                    $firstrecord = reset($records);
                                    $context = null;
                                    if (isset($firstrecord->cmid)) {
                                        $context = \context_module::instance($firstrecord->cmid);
                                    } else {
                                        $context = \context_system::instance(); // Fallback
                                    }
                                } else {
                                    $context = \context_system::instance(); // Fallback for no records
                                }

                                foreach ($records as $record) {
                                    if (!isset($record->instanceid)) {
                                        mtrace("Error: Secondary record missing instanceid for table $secondary_table: " . json_encode($record));
                                        continue;
                                    }
                                    $instanceid = $record->instanceid;
                                    $course = $record->course;
                                    $cmid = isset($record->cmid) ? $record->cmid : null;
                                    $primary_instanceid = $record->primary_instanceid;

                                    // Process each field for this instance
                                    foreach ($secondary_fields as $field) {
                                        // Fetch the record for this specific field
                                        if ($secondary_table === 'question') {
                                            $sql = "SELECT s.id AS instanceid, s.$field AS content, cm.course, p.id AS primary_instanceid, cm.id AS cmid
                                                    FROM {{$secondary_table}} s
                                                    JOIN {question_versions} qv ON qv.questionid = s.id
                                                    JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                                                    JOIN {question_references} qr ON qr.questionbankentryid = qbe.id
                                                    JOIN {{$parent_table}} pt ON pt.id = qr.itemid
                                                    JOIN {{$table}} p ON p.id = pt.quizid
                                                    JOIN {course_modules} cm ON cm.instance = p.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                                    JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                                                    WHERE s.id = :instanceid
                                                    AND s.$field IS NOT NULL AND s.$field != ''
                                                    AND qr.usingcontextid = ctx.id
                                                    AND qr.component = 'mod_quiz'
                                                    AND qr.questionarea = 'slot'";
                                            $params = ['modulename' => $table, 'contextlevel' => CONTEXT_MODULE, 'instanceid' => $instanceid];
                                        } elseif ($secondary_table === 'question_answers') {
                                            $sql = "SELECT s.id AS instanceid, s.$field AS content, cm.course, p.id AS primary_instanceid, cm.id AS cmid
                                                    FROM {{$secondary_table}} s
                                                    JOIN {{$parent_table}} pt ON s.question = pt.id
                                                    JOIN {question_versions} qv ON qv.questionid = pt.id
                                                    JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                                                    JOIN {question_references} qr ON qr.questionbankentryid = qbe.id
                                                    JOIN {{$grandparent_table}} gpt ON gpt.id = qr.itemid
                                                    JOIN {{$table}} p ON p.id = gpt.quizid
                                                    JOIN {course_modules} cm ON cm.instance = p.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                                    JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                                                    WHERE s.id = :instanceid
                                                    AND s.$field IS NOT NULL AND s.$field != ''
                                                    AND qr.usingcontextid = ctx.id
                                                    AND qr.component = 'mod_quiz'
                                                    AND qr.questionarea = 'slot'";
                                            $params = ['modulename' => $table, 'contextlevel' => CONTEXT_MODULE, 'instanceid' => $instanceid];
                                        } elseif ($secondary_table === 'question_categories') {
                                            $sql = "SELECT s.id AS instanceid, s.$field AS content, cm.course, p.id AS primary_instanceid, cm.id AS cmid
                                                    FROM {{$secondary_table}} s
                                                    JOIN {question_bank_entries} qbe ON qbe.questioncategoryid = s.id
                                                    JOIN {question_references} qr ON qr.questionbankentryid = qbe.id
                                                    JOIN {{$grandparent_table}} gpt ON gpt.id = qr.itemid
                                                    JOIN {{$table}} p ON p.id = gpt.quizid
                                                    JOIN {course_modules} cm ON cm.instance = p.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                                    JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                                                    WHERE s.id = :instanceid
                                                    AND s.$field IS NOT NULL AND s.$field != ''
                                                    AND qr.usingcontextid = ctx.id
                                                    AND qr.component = 'mod_quiz'
                                                    AND qr.questionarea = 'slot'";
                                            $params = ['modulename' => $table, 'contextlevel' => CONTEXT_MODULE, 'instanceid' => $instanceid];
                                        } else {
                                            if ($grandparent_table) {
                                                $sql = "SELECT s.id AS instanceid, s.$field AS content, cm.course, p.id AS primary_instanceid, cm.id AS cmid
                                                        FROM {{$secondary_table}} s
                                                        JOIN {{$parent_table}} pt ON s.$fk = pt.id
                                                        JOIN {{$grandparent_table}} gpt ON pt.$parent_fk = gpt.id
                                                        JOIN {{$table}} p ON p.id = gpt.$grandparent_fk
                                                        JOIN {course_modules} cm ON cm.instance = p.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                                        WHERE s.id = :instanceid
                                                        AND s.$field IS NOT NULL AND s.$field != ''";
                                            } elseif ($parent_table) {
                                                $sql = "SELECT s.id AS instanceid, s.$field AS content, cm.course, p.id AS primary_instanceid, cm.id AS cmid
                                                        FROM {{$secondary_table}} s
                                                        JOIN {{$parent_table}} pt ON s.$fk = pt.id
                                                        JOIN {{$table}} p ON p.id = pt.$parent_fk
                                                        JOIN {course_modules} cm ON cm.instance = p.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                                        WHERE s.id = :instanceid
                                                        AND s.$field IS NOT NULL AND s.$field != ''";
                                            } else {
                                                $sql = "SELECT s.id AS instanceid, s.$field AS content, cm.course, p.id AS primary_instanceid, cm.id AS cmid
                                                        FROM {{$secondary_table}} s
                                                        JOIN {{$table}} p ON p.id = s.$fk
                                                        JOIN {course_modules} cm ON cm.instance = p.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                                        WHERE s.id = :instanceid
                                                        AND s.$field IS NOT NULL AND s.$field != ''";
                                            }
                                            $params = ['modulename' => $table, 'instanceid' => $instanceid];
                                        }

                                        $field_record = $DB->get_record_sql($sql, $params);
                                        if (!$field_record) {
                                            continue; // Skip if no record found for this field
                                        }

                                        $field_record->id = $field_record->instanceid;
                                        unset($field_record->instanceid);
                                        $field_record->$field = $field_record->content;
                                        unset($field_record->content);
                                        if (!isset($field_record->id)) {
                                            mtrace("Error: Secondary record missing id field after renaming for table $secondary_table, field $field");
                                            continue;
                                        }
                                        $updated = $tagging_service->tag_content($secondary_table, $field_record, [$field], $context, $field_record->course);
                                        if ($updated) {
                                            mtrace("Tagged content in table $secondary_table, field $field, instanceid {$field_record->id}: " . substr($field_record->$field, 0, 50) . "...");
                                            // Corrected to use tagging_service method
                                            $tagging_service->mark_translations_for_revision($secondary_table, $field_record->id, [$field], $context);
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