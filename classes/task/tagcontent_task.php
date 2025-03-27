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
            $rebuilder = new \filter_autotranslate\task\rebuild_course_translations();
            $rebuilder->execute($courseid);
            return;
        }

        $selectedctx = get_config('filter_autotranslate', 'selectctx') ?: '40,50,70,80';
        $selectedctx = array_filter(array_map('trim', explode(',', (string)$selectedctx)));
        $managelimit = (int)(get_config('filter_autotranslate', 'managelimit') ?: 20);

        $batchsize = $managelimit;
        $totalentrieschecked = 0;

        $taggingservice = new tagging_service($DB);
        $tables = \filter_autotranslate\tagging_config::get_default_tables();

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

                            foreach ($records as $record) {
                                if (!isset($record->instanceid)) {
                                    if (defined('CLI_SCRIPT')) {
                                        mtrace("Error: Record missing instanceid for table $table, field $field");
                                    }
                                    continue;
                                }
                                $context = isset($record->cmid) ? \context_module::instance($record->cmid) : \context_system::instance();
                                $record->id = $record->instanceid;
                                unset($record->instanceid);
                                $record->$field = $record->content;
                                unset($record->content);
                                $updated = $taggingservice->tag_content($table, $record, [$field], $context, $record->course);
                                if ($updated && defined('CLI_SCRIPT')) {
                                    mtrace("Tagged content in table $table, field $field, id {$record->id}");
                                }
                            }

                            $offset += $batchsize;
                        } catch (\dml_exception $e) {
                            if (defined('CLI_SCRIPT')) {
                                mtrace("Error for table $table, field $field: " . $e->getMessage());
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
                            mtrace("Processing secondary table: $secondarytable, Fields: " . implode(', ', $secondaryfields));
                        }
                        $offset = 0;

                        do {
                            try {
                                $relationship = \filter_autotranslate\tagging_config::get_relationship_details($secondarytable);
                                $fk = $relationship['fk'];
                                $parenttable = $relationship['parent_table'];
                                $grandparenttable = $relationship['grandparent_table'];

                                if ($secondarytable === 'question') {
                                    $sql = "SELECT DISTINCT s.id AS instanceid, cm.course, cm.id AS cmid
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
                                    $sql = "SELECT DISTINCT s.id AS instanceid, cm.course, cm.id AS cmid
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
                                    $sql = "SELECT DISTINCT s.id AS instanceid, cm.course, cm.id AS cmid
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
                                        $sql = "SELECT DISTINCT s.id AS instanceid, cm.course, cm.id AS cmid
                                                FROM {{$secondarytable}} s
                                                JOIN {{$parenttable}} pt ON s.$fk = pt.id
                                                JOIN {{$grandparenttable}} gpt ON pt.$fk = gpt.id
                                                JOIN {{$table}} p ON p.id = gpt.$fk
                                                JOIN {course_modules} cm ON cm.instance = p.id
                                                AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)";
                                    } else if ($parenttable) {
                                        $sql = "SELECT DISTINCT s.id AS instanceid, cm.course, cm.id AS cmid
                                                FROM {{$secondarytable}} s
                                                JOIN {{$parenttable}} pt ON s.$fk = pt.id
                                                JOIN {{$table}} p ON p.id = pt.$fk
                                                JOIN {course_modules} cm ON cm.instance = p.id
                                                AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)";
                                    } else {
                                        $sql = "SELECT DISTINCT s.id AS instanceid, cm.course, cm.id AS cmid
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

                                foreach ($records as $record) {
                                    if (!isset($record->instanceid)) {
                                        if (defined('CLI_SCRIPT')) {
                                            mtrace("Error: Secondary record missing instanceid for table $secondarytable");
                                        }
                                        continue;
                                    }
                                    $context = \context_module::instance($record->cmid);
                                    foreach ($secondaryfields as $field) {
                                        $sql = str_replace("DISTINCT s.id AS instanceid", "s.id AS instanceid, s.$field AS content", $sql) .
                                            " WHERE s.id = :instanceid AND s.$field IS NOT NULL AND s.$field != ''";
                                        $params['instanceid'] = $record->instanceid;
                                        $fieldrecord = $DB->get_record_sql($sql, $params);
                                        if (!$fieldrecord) {
                                            continue;
                                        }

                                        $fieldrecord->id = $fieldrecord->instanceid;
                                        unset($fieldrecord->instanceid);
                                        $fieldrecord->$field = $fieldrecord->content;
                                        unset($fieldrecord->content);
                                        $updated = $taggingservice->tag_content(
                                            $secondarytable,
                                            $fieldrecord,
                                            [$field],
                                            $context,
                                            $fieldrecord->course
                                        );
                                        if ($updated && defined('CLI_SCRIPT')) {
                                            mtrace("Tagged content in table $secondarytable, field $field, id {$fieldrecord->id}");
                                        }
                                    }
                                }

                                $offset += $batchsize;
                            } catch (\dml_exception $e) {
                                if (defined('CLI_SCRIPT')) {
                                    mtrace("Error for table $secondarytable: " . $e->getMessage());
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
