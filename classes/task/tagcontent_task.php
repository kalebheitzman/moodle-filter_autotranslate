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


use core\task\scheduled_task;
use filter_autotranslate\helper;
use filter_autotranslate\tagging_manager;
use filter_autotranslate\tagging_service;

/**
 * Autotranslate Tag Content Task
 *
 * This scheduled task tags untagged text and processes mlang tags across configured contexts.
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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

        // Check if called from CLI with a courseid argument.
        if (defined('CLI_SCRIPT') && !empty($argv) && count($argv) > 1 && is_numeric($argv[1])) {
            $courseid = (int)$argv[1];
            // Delegate to the course-specific rebuild class.
            $rebuilder = new \filter_autotranslate\task\rebuild_course_translations();
            $rebuilder->execute($courseid);
            return;
        }

        // Treat selectctx as CSV string.
        $selectedctx = get_config('filter_autotranslate', 'selectctx') ?: '40,50,70,80';
        $selectedctx = array_filter(array_map('trim', explode(',', (string)$selectedctx)));
        $managelimit = (int)(get_config('filter_autotranslate', 'managelimit') ?: 20);

        $batchsize = $managelimit;
        $totalentrieschecked = 0;

        // Instantiate tagging_service with the database connection.
        $taggingservice = new tagging_service($DB);

        // Get the configured tables and fields.
        $tables = \filter_autotranslate\tagging_config::get_default_tables();

        // Only output mtrace in CLI context to avoid browser output in web context.
        if (defined('CLI_SCRIPT')) {
            mtrace("Starting Auto Translate task with contexts: " . implode(', ', $selectedctx));
        }

        foreach ($tables as $ctx => $tablelist) {
            if (!in_array((string)$ctx, $selectedctx)) {
                continue;
            }

            foreach ($tablelist as $table => $config) {
                $fields = \filter_autotranslate\tagging_manager::get_fields_to_tag($table, $ctx);
                if (empty($fields)) {
                    continue;
                }

                if (defined('CLI_SCRIPT')) {
                    mtrace("Processing table: $table, Fields: " . implode(', ', $fields));
                }
                foreach ($fields as $field) {
                    $offset = 0;

                    do {
                        try {
                            if ($ctx == CONTEXT_SYSTEM) {
                                if ($table === 'message') {
                                    $sql = "SELECT m.id AS instanceid, m.$field AS content, 0 AS course
                                            FROM {{$table}} m
                                            WHERE m.$field IS NOT NULL AND m.$field != ''";
                                    $params = [];
                                } else if ($table === 'block_instances') {
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
                            } else if ($ctx == CONTEXT_USER) {
                                $sql = "SELECT uid.id AS instanceid, uid.$field AS content, 0 AS course
                                        FROM {{$table}} uid
                                        JOIN {user} u ON u.id = uid.userid
                                        WHERE uid.$field IS NOT NULL AND uid.$field != ''";
                                $params = [];
                            } else if ($ctx == CONTEXT_COURSECAT) {
                                $sql = "SELECT cc.id AS instanceid, cc.$field AS content, 0 AS course
                                        FROM {{$table}} cc
                                        WHERE cc.$field IS NOT NULL AND cc.$field != ''";
                                $params = [];
                            } else if ($ctx == CONTEXT_COURSE) {
                                if ($table === 'course') {
                                    $sql = "SELECT c.id AS instanceid, c.$field AS content, c.id AS course
                                            FROM {course} c
                                            WHERE c.$field IS NOT NULL AND c.$field != ''";
                                    $params = [];
                                } else if ($table === 'course_sections') {
                                    $sql = "SELECT cs.id AS instanceid, cs.$field AS content, cs.course
                                            FROM {course_sections} cs
                                            JOIN {course} c ON c.id = cs.course
                                            WHERE cs.$field IS NOT NULL AND cs.$field != ''";
                                    $params = [];
                                } else {
                                    $sql = "SELECT m.id AS instanceid, m.$field AS content, cm.course, cm.id AS cmid
                                            FROM {" . $table . "} m
                                            JOIN {course_modules} cm ON cm.instance = m.id
                                            AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                            WHERE m.$field IS NOT NULL AND m.$field != ''";
                                    $params = ['modulename' => $table];
                                }
                            } else if ($ctx == CONTEXT_MODULE) {
                                $sql = "SELECT m.id AS instanceid, m.$field AS content, cm.course, cm.id AS cmid
                                        FROM {" . $table . "} m
                                        JOIN {course_modules} cm ON cm.instance = m.id
                                        AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                        WHERE m.$field IS NOT NULL AND m.$field != ''";
                                $params = ['modulename' => $table];
                            } else if ($ctx == CONTEXT_BLOCK) {
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
                            $totalentrieschecked += $count;
                            if (defined('CLI_SCRIPT')) {
                                mtrace("Fetched $count records for table $table, field $field at offset $offset");
                            }

                            if ($count > 0) {
                                $firstrecord = reset($records);
                                $context = null;
                                if (isset($firstrecord->cmid)) {
                                    $context = \context_module::instance($firstrecord->cmid);
                                } else {
                                    $context = \context_system::instance(); // Fallback.
                                }
                                if (defined('CLI_SCRIPT')) {
                                    mtrace("First record: " . json_encode($firstrecord));
                                }
                            } else {
                                $context = \context_system::instance(); // Fallback for no records.
                            }

                            foreach ($records as $record) {
                                if (!isset($record->instanceid)) {
                                    if (defined('CLI_SCRIPT')) {
                                        mtrace(
                                            "Error: Record missing instanceid for table $table, field $field: " .
                                            json_encode($record)
                                        );
                                    }
                                    continue;
                                }
                                $record->id = $record->instanceid;
                                unset($record->instanceid);
                                $record->$field = $record->content;
                                unset($record->content);
                                if (!isset($record->id)) {
                                    if (defined('CLI_SCRIPT')) {
                                        mtrace("Error: Record missing id field after renaming for table $table, field $field");
                                    }
                                    continue;
                                }
                                $updated = $taggingservice->tag_content($table, $record, [$field], $context, $record->course);
                                if ($updated) {
                                    if (defined('CLI_SCRIPT')) {
                                        mtrace(
                                            "Tagged content in table $table, field $field, instanceid {$record->id}: " .
                                            substr($record->$field, 0, 50) . "..."
                                        );
                                    }
                                    $taggingservice->mark_translations_for_revision($table, $record->id, [$field], $context);
                                }
                            }

                            $offset += $batchsize;
                        } catch (\dml_exception $e) {
                            if (defined('CLI_SCRIPT')) {
                                mtrace("Error executing query for table $table, field $field: " . $e->getMessage());
                            }
                            break;
                        }
                    } while ($count >= $batchsize);
                }

                // Process secondary tables for module contexts.
                if ($ctx == CONTEXT_MODULE) {
                    $secondarytables = \filter_autotranslate\tagging_manager::get_secondary_tables($table, $ctx);
                    foreach ($secondarytables as $secondarytable => $secondaryfields) {
                        if (defined('CLI_SCRIPT')) {
                            mtrace("Processing table: $secondarytable, Fields: " . implode(', ', $secondaryfields));
                        }
                        $offset = 0;

                        do {
                            try {
                                $relationship = \filter_autotranslate\tagging_config::get_relationship_details($secondarytable);
                                $fk = $relationship['fk'];
                                $parenttable = $relationship['parent_table'];
                                $parentfk = $relationship['parent_fk'];
                                $grandparenttable = $relationship['grandparent_table'];
                                $grandparentfk = $relationship['grandparent_fk'];

                                if ($secondarytable === 'question') {
                                    $sql = "SELECT DISTINCT s.id AS instanceid, cm.course, p.id AS primary_instanceid, cm.id AS cmid
                                            FROM {{$secondarytable}} s
                                            JOIN {question_versions} qv ON qv.questionid = s.id
                                            JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                                            JOIN {question_references} qr ON qr.questionbankentryid = qbe.id
                                            JOIN {{$parenttable}} pt ON pt.id = qr.itemid
                                            JOIN {{$table}} p ON p.id = pt.quizid
                                            JOIN {course_modules} cm ON cm.instance = p.id
                                            AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                            JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                                            WHERE qr.usingcontextid = ctx.id
                                            AND qr.component = 'mod_quiz'
                                            AND qr.questionarea = 'slot'";
                                    $params = ['modulename' => $table, 'contextlevel' => CONTEXT_MODULE];
                                } else if ($secondarytable === 'question_answers') {
                                    $sql = "SELECT DISTINCT s.id AS instanceid, cm.course, p.id AS primary_instanceid, cm.id AS cmid
                                            FROM {{$secondarytable}} s
                                            JOIN {{$parenttable}} pt ON s.question = pt.id
                                            JOIN {question_versions} qv ON qv.questionid = pt.id
                                            JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                                            JOIN {question_references} qr ON qr.questionbankentryid = qbe.id
                                            JOIN {{$grandparenttable}} gpt ON gpt.id = qr.itemid
                                            JOIN {{$table}} p ON p.id = gpt.quizid
                                            JOIN {course_modules} cm ON cm.instance = p.id
                                            AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                            JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                                            WHERE qr.usingcontextid = ctx.id
                                            AND qr.component = 'mod_quiz'
                                            AND qr.questionarea = 'slot'";
                                    $params = ['modulename' => $table, 'contextlevel' => CONTEXT_MODULE];
                                } else if ($secondarytable === 'question_categories') {
                                    $sql = "SELECT DISTINCT s.id AS instanceid, cm.course, p.id AS primary_instanceid, cm.id AS cmid
                                            FROM {{$secondarytable}} s
                                            JOIN {question_bank_entries} qbe ON qbe.questioncategoryid = s.id
                                            JOIN {question_references} qr ON qr.questionbankentryid = qbe.id
                                            JOIN {{$grandparenttable}} gpt ON gpt.id = qr.itemid
                                            JOIN {{$table}} p ON p.id = gpt.quizid
                                            JOIN {course_modules} cm ON cm.instance = p.id
                                            AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                            JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                                            WHERE qr.usingcontextid = ctx.id
                                            AND qr.component = 'mod_quiz'
                                            AND qr.questionarea = 'slot'";
                                    $params = ['modulename' => $table, 'contextlevel' => CONTEXT_MODULE];
                                } else {
                                    if ($grandparenttable) {
                                        $sql = "SELECT DISTINCT s.id AS instanceid, cm.course, p.id
                                                AS primary_instanceid, cm.id AS cmid
                                                FROM {{$secondarytable}} s
                                                JOIN {{$parenttable}} pt ON s.$fk = pt.id
                                                JOIN {{$grandparenttable}} gpt ON pt.$parentfk = gpt.id
                                                JOIN {{$table}} p ON p.id = gpt.$grandparentfk
                                                JOIN {course_modules} cm ON cm.instance = p.id
                                                AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)";
                                    } else if ($parenttable) {
                                        $sql = "SELECT DISTINCT s.id AS instanceid, cm.course, p.id
                                                AS primary_instanceid, cm.id AS cmid
                                                FROM {{$secondarytable}} s
                                                JOIN {{$parenttable}} pt ON s.$fk = pt.id
                                                JOIN {{$table}} p ON p.id = pt.$parentfk
                                                JOIN {course_modules} cm ON cm.instance = p.id
                                                AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)";
                                    } else {
                                        $sql = "SELECT DISTINCT s.id AS instanceid, cm.course, p.id
                                                AS primary_instanceid, cm.id AS cmid
                                                FROM {{$secondarytable}} s
                                                JOIN {{$table}} p ON p.id = s.$fk
                                                JOIN {course_modules} cm ON cm.instance = p.id
                                                AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)";
                                    }
                                    $params = ['modulename' => $table];
                                }

                                $records = $DB->get_records_sql($sql, $params, $offset, $batchsize);
                                $count = count($records);
                                $totalentrieschecked += $count;
                                if (defined('CLI_SCRIPT')) {
                                    mtrace("Fetched $count records for table $secondarytable at offset $offset");
                                }

                                if ($count > 0) {
                                    $firstrecord = reset($records);
                                    $context = null;
                                    if (isset($firstrecord->cmid)) {
                                        $context = \context_module::instance($firstrecord->cmid);
                                    } else {
                                        $context = \context_system::instance(); // Fallback.
                                    }
                                } else {
                                    $context = \context_system::instance(); // Fallback for no records.
                                }

                                foreach ($records as $record) {
                                    if (!isset($record->instanceid)) {
                                        if (defined('CLI_SCRIPT')) {
                                            mtrace(
                                                "Error: Secondary record missing instanceid for table $secondarytable: " .
                                                json_encode($record)
                                            );
                                        }
                                        continue;
                                    }
                                    $instanceid = $record->instanceid;

                                    foreach ($secondaryfields as $field) {
                                        if ($secondarytable === 'question') {
                                            $sql = "SELECT s.id AS instanceid, s.$field AS content, cm.course, p.id
                                                    AS primary_instanceid, cm.id AS cmid
                                                    FROM {{$secondarytable}} s
                                                    JOIN {question_versions} qv ON qv.questionid = s.id
                                                    JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                                                    JOIN {question_references} qr ON qr.questionbankentryid = qbe.id
                                                    JOIN {{$parenttable}} pt ON pt.id = qr.itemid
                                                    JOIN {{$table}} p ON p.id = pt.quizid
                                                    JOIN {course_modules} cm ON cm.instance = p.id
                                                    AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                                    JOIN {context} ctx ON ctx.instanceid = cm.id
                                                    AND ctx.contextlevel = :contextlevel
                                                    WHERE s.id = :instanceid
                                                    AND s.$field IS NOT NULL AND s.$field != ''
                                                    AND qr.usingcontextid = ctx.id
                                                    AND qr.component = 'mod_quiz'
                                                    AND qr.questionarea = 'slot'";
                                            $params = [
                                                'modulename' => $table,
                                                'contextlevel' => CONTEXT_MODULE,
                                                'instanceid' => $instanceid,
                                            ];
                                        } else if ($secondarytable === 'question_answers') {
                                            $sql = "SELECT s.id AS instanceid, s.$field AS content, cm.course, p.id
                                                    AS primary_instanceid, cm.id AS cmid
                                                    FROM {{$secondarytable}} s
                                                    JOIN {{$parenttable}} pt ON s.question = pt.id
                                                    JOIN {question_versions} qv ON qv.questionid = pt.id
                                                    JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                                                    JOIN {question_references} qr ON qr.questionbankentryid = qbe.id
                                                    JOIN {{$grandparenttable}} gpt ON gpt.id = qr.itemid
                                                    JOIN {{$table}} p ON p.id = gpt.quizid
                                                    JOIN {course_modules} cm ON cm.instance = p.id
                                                    AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                                    JOIN {context} ctx ON ctx.instanceid = cm.id
                                                    AND ctx.contextlevel = :contextlevel
                                                    WHERE s.id = :instanceid
                                                    AND s.$field IS NOT NULL AND s.$field != ''
                                                    AND qr.usingcontextid = ctx.id
                                                    AND qr.component = 'mod_quiz'
                                                    AND qr.questionarea = 'slot'";
                                            $params = [
                                                'modulename' => $table,
                                                'contextlevel' => CONTEXT_MODULE,
                                                'instanceid' => $instanceid,
                                            ];
                                        } else if ($secondarytable === 'question_categories') {
                                            $sql = "SELECT s.id AS instanceid, s.$field AS content, cm.course, p.id
                                                    AS primary_instanceid, cm.id AS cmid
                                                    FROM {{$secondarytable}} s
                                                    JOIN {question_bank_entries} qbe ON qbe.questioncategoryid = s.id
                                                    JOIN {question_references} qr ON qr.questionbankentryid = qbe.id
                                                    JOIN {{$grandparenttable}} gpt ON gpt.id = qr.itemid
                                                    JOIN {{$table}} p ON p.id = gpt.quizid
                                                    JOIN {course_modules} cm ON cm.instance = p.id
                                                    AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                                    JOIN {context} ctx ON ctx.instanceid = cm.id
                                                    AND ctx.contextlevel = :contextlevel
                                                    WHERE s.id = :instanceid
                                                    AND s.$field IS NOT NULL AND s.$field != ''
                                                    AND qr.usingcontextid = ctx.id
                                                    AND qr.component = 'mod_quiz'
                                                    AND qr.questionarea = 'slot'";
                                            $params = [
                                                'modulename' => $table,
                                                'contextlevel' => CONTEXT_MODULE,
                                                'instanceid' => $instanceid,
                                            ];
                                        } else {
                                            if ($grandparenttable) {
                                                $sql = "SELECT s.id AS instanceid, s.$field AS content, cm.course, p.id
                                                        AS primary_instanceid, cm.id AS cmid
                                                        FROM {{$secondarytable}} s
                                                        JOIN {{$parenttable}} pt ON s.$fk = pt.id
                                                        JOIN {{$grandparenttable}} gpt ON pt.$parentfk = gpt.id
                                                        JOIN {{$table}} p ON p.id = gpt.$grandparentfk
                                                        JOIN {course_modules} cm ON cm.instance = p.id
                                                        AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                                        WHERE s.id = :instanceid
                                                        AND s.$field IS NOT NULL AND s.$field != ''";
                                            } else if ($parenttable) {
                                                $sql = "SELECT s.id AS instanceid, s.$field AS content, cm.course, p.id
                                                        AS primary_instanceid, cm.id AS cmid
                                                        FROM {{$secondarytable}} s
                                                        JOIN {{$parenttable}} pt ON s.$fk = pt.id
                                                        JOIN {{$table}} p ON p.id = pt.$parentfk
                                                        JOIN {course_modules} cm ON cm.instance = p.id
                                                        AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                                        WHERE s.id = :instanceid
                                                        AND s.$field IS NOT NULL AND s.$field != ''";
                                            } else {
                                                $sql = "SELECT s.id AS instanceid, s.$field AS content, cm.course, p.id
                                                        AS primary_instanceid, cm.id AS cmid
                                                        FROM {{$secondarytable}} s
                                                        JOIN {{$table}} p ON p.id = s.$fk
                                                        JOIN {course_modules} cm ON cm.instance = p.id
                                                        AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                                        WHERE s.id = :instanceid
                                                        AND s.$field IS NOT NULL AND s.$field != ''";
                                            }
                                            $params = ['modulename' => $table, 'instanceid' => $instanceid];
                                        }

                                        $fieldrecord = $DB->get_record_sql($sql, $params);
                                        if (!$fieldrecord) {
                                            continue;
                                        }

                                        $fieldrecord->id = $fieldrecord->instanceid;
                                        unset($fieldrecord->instanceid);
                                        $fieldrecord->$field = $fieldrecord->content;
                                        unset($fieldrecord->content);
                                        if (!isset($fieldrecord->id)) {
                                            if (defined('CLI_SCRIPT')) {
                                                mtrace(
                                                    "Error: Secondary record missing id field after " .
                                                    "renaming for table $secondarytable, field $field"
                                                );
                                            }
                                            continue;
                                        }
                                        $updated = $taggingservice->tag_content(
                                            $secondarytable,
                                            $fieldrecord,
                                            [$field],
                                            $context,
                                            $fieldrecord->course
                                        );
                                        if ($updated) {
                                            if (defined('CLI_SCRIPT')) {
                                                mtrace(
                                                    "Tagged content in table $secondarytable, " .
                                                    "field $field, instanceid {$fieldrecord->id}: " .
                                                    substr($fieldrecord->$field, 0, 50) . "..."
                                                );
                                            }
                                            $taggingservice->mark_translations_for_revision(
                                                $secondarytable,
                                                $fieldrecord->id,
                                                [$field],
                                                $context
                                            );
                                        }
                                    }
                                }

                                $offset += $batchsize;
                            } catch (\dml_exception $e) {
                                if (defined('CLI_SCRIPT')) {
                                    mtrace("Error executing query for table $secondarytable: " . $e->getMessage());
                                }
                                break;
                            }
                        } while ($count >= $batchsize);
                    }
                }
            }
        }

        if (defined('CLI_SCRIPT')) {
            mtrace("Auto Translate task completed. Total entries checked: $totalentrieschecked");
        }
    }
}
