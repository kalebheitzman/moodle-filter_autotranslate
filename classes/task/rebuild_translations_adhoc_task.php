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
 * Rebuild Translations Adhoc Task
 *
 * This task rebuilds translations for a specific course.
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rebuild_translations_adhoc_task extends adhoc_task {
    /**
     * Returns the name of the task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('rebuildtranslationsadhoc', 'filter_autotranslate');
    }

    /**
     * Updates the task progress in the database.
     *
     * @param string $status The status of the task (queued, running, completed, failed).
     * @param int $processed The number of entries processed.
     * @param int $total The total number of entries to process.
     * @param string|null $message Optional message for logging.
     */
    private function update_progress($status, $processed, $total, $message = null) {
        global $DB;

        $taskid = $this->get_id();
        $progress = $DB->get_record('filter_autotranslate_task_progress', ['taskid' => $taskid]);
        if ($progress) {
            $progress->status = $status;
            $progress->processed_entries = $processed;
            $progress->total_entries = $total;
            $progress->timemodified = time();
            $DB->update_record('filter_autotranslate_task_progress', $progress);
        }

        if ($message) {
            debugging("Rebuild Translations Task $taskid: $message", DEBUG_DEVELOPER);
        }
    }

    /**
     * Executes the task to rebuild translations for a specific course.
     */
    public function execute() {
        global $DB;

        $data = $this->get_custom_data();
        if (!$data) {
            $this->update_progress('failed', 0, 0, 'No custom data provided.');
            return;
        }

        $courseid = $data->courseid;
        $totalentries = $data->total_entries;

        if (!$DB->record_exists('course', ['id' => $courseid])) {
            $this->update_progress('failed', 0, 0, "Course ID $courseid does not exist.");
            return;
        }

        $this->update_progress('running', 0, $totalentries);

        // Delete existing hid_cids entries for the course.
        $DB->delete_records('filter_autotranslate_hid_cids', ['courseid' => $courseid]);

        $taggingservice = new \filter_autotranslate\tagging_service($DB);
        $tables = \filter_autotranslate\tagging_config::get_default_tables();
        $totalentrieschecked = 0;
        $batchsize = (int)(get_config('filter_autotranslate', 'managelimit') ?: 20);

        // Limit to course-related contexts.
        $relevantcontexts = [CONTEXT_COURSE, CONTEXT_MODULE];
        foreach ($tables as $ctx => $tablelist) {
            if (!in_array($ctx, $relevantcontexts)) {
                continue;
            }

            foreach ($tablelist as $table => $config) {
                $fields = \filter_autotranslate\tagging_manager::get_fields_to_tag($table, $ctx);
                if (empty($fields)) {
                    continue;
                }

                foreach ($fields as $field) {
                    $offset = 0;

                    do {
                        try {
                            if ($ctx == CONTEXT_COURSE) {
                                if ($table === 'course') {
                                    $sql = "SELECT c.id AS instanceid, c.$field AS content, c.id AS course
                                            FROM {course} c
                                            WHERE c.id = :courseid AND c.$field IS NOT NULL AND c.$field != ''";
                                    $params = ['courseid' => $courseid];
                                } else if ($table === 'course_sections') {
                                    $sql = "SELECT cs.id AS instanceid, cs.$field AS content, cs.course
                                            FROM {course_sections} cs
                                            WHERE cs.course = :courseid AND cs.$field IS NOT NULL AND cs.$field != ''";
                                    $params = ['courseid' => $courseid];
                                } else {
                                    continue;
                                }
                            } else if ($ctx == CONTEXT_MODULE) {
                                $sql = "SELECT m.id AS instanceid, m.$field AS content, cm.course, cm.id AS cmid
                                        FROM {{$table}} m
                                        JOIN {course_modules} cm ON cm.instance = m.id
                                        AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                        WHERE cm.course = :courseid AND m.$field IS NOT NULL AND m.$field != ''";
                                $params = ['modulename' => $table, 'courseid' => $courseid];
                            } else {
                                continue;
                            }

                            $records = $DB->get_records_sql($sql, $params, $offset, $batchsize);
                            $count = count($records);
                            $totalentrieschecked += $count;

                            if ($count > 0) {
                                $firstrecord = reset($records);
                                $context = isset($firstrecord->cmid)
                                    ? \context_module::instance($firstrecord->cmid)
                                    : \context_course::instance($courseid);
                            } else {
                                $context = \context_course::instance($courseid);
                            }

                            foreach ($records as $record) {
                                if (!isset($record->instanceid)) {
                                    continue;
                                }
                                $record->id = $record->instanceid;
                                unset($record->instanceid);
                                $record->$field = $record->content;
                                unset($record->content);
                                if (!isset($record->id)) {
                                    continue;
                                }
                                $updated = $taggingservice->tag_content($table, $record, [$field], $context, $record->course);
                                if ($updated) {
                                    $taggingservice->mark_translations_for_revision($table, $record->id, [$field], $context);
                                }
                                $this->update_progress('running', $totalentrieschecked, $totalentries);
                            }

                            $offset += $batchsize;
                        } catch (\dml_exception $e) {
                            $this->update_progress('failed', $totalentrieschecked, $totalentries, 'Error: ' . $e->getMessage());
                            return;
                        }
                    } while ($count >= $batchsize);
                }

                // Process secondary tables for module contexts.
                if ($ctx == CONTEXT_MODULE) {
                    $secondarytables = \filter_autotranslate\tagging_manager::get_secondary_tables($table, $ctx);
                    foreach ($secondarytables as $secondarytable => $secondaryfields) {
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
                                            WHERE cm.course = :courseid
                                            AND qr.usingcontextid = ctx.id
                                            AND qr.component = 'mod_quiz'
                                            AND qr.questionarea = 'slot'";
                                    $params = ['modulename' => $table, 'contextlevel' => CONTEXT_MODULE, 'courseid' => $courseid];
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
                                            WHERE cm.course = :courseid
                                            AND qr.usingcontextid = ctx.id
                                            AND qr.component = 'mod_quiz'
                                            AND qr.questionarea = 'slot'";
                                    $params = ['modulename' => $table, 'contextlevel' => CONTEXT_MODULE, 'courseid' => $courseid];
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
                                            WHERE cm.course = :courseid
                                            AND qr.usingcontextid = ctx.id
                                            AND qr.component = 'mod_quiz'
                                            AND qr.questionarea = 'slot'";
                                    $params = ['modulename' => $table, 'contextlevel' => CONTEXT_MODULE, 'courseid' => $courseid];
                                } else {
                                    if ($grandparenttable) {
                                        $sql = "SELECT DISTINCT s.id AS instanceid, cm.course, p.id
                                                AS primary_instanceid, cm.id AS cmid
                                                FROM {{$secondarytable}} s
                                                JOIN {{$parenttable}} pt ON s.$fk = pt.id
                                                JOIN {{$grandparenttable}} gpt ON pt.$parentfk = gpt.id
                                                JOIN {{$table}} p ON p.id = gpt.$grandparentfk
                                                JOIN {course_modules} cm ON cm.instance = p.id
                                                AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                                WHERE cm.course = :courseid";
                                    } else if ($parenttable) {
                                        $sql = "SELECT DISTINCT s.id AS instanceid, cm.course, p.id
                                                AS primary_instanceid, cm.id AS cmid
                                                FROM {{$secondarytable}} s
                                                JOIN {{$parenttable}} pt ON s.$fk = pt.id
                                                JOIN {{$table}} p ON p.id = pt.$parentfk
                                                JOIN {course_modules} cm ON cm.instance = p.id
                                                AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                                WHERE cm.course = :courseid";
                                    } else {
                                        $sql = "SELECT DISTINCT s.id AS instanceid, cm.course, p.id
                                                AS primary_instanceid, cm.id AS cmid
                                                FROM {{$secondarytable}} s
                                                JOIN {{$table}} p ON p.id = s.$fk
                                                JOIN {course_modules} cm ON cm.instance = p.id
                                                AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                                WHERE cm.course = :courseid";
                                    }
                                    $params = ['modulename' => $table, 'courseid' => $courseid];
                                }

                                $records = $DB->get_records_sql($sql, $params, $offset, $batchsize);
                                $count = count($records);
                                $totalentrieschecked += $count;

                                if ($count > 0) {
                                    $firstrecord = reset($records);
                                    $context = isset($firstrecord->cmid)
                                        ? \context_module::instance($firstrecord->cmid)
                                        : \context_course::instance($courseid);
                                } else {
                                    $context = \context_course::instance($courseid);
                                }

                                foreach ($records as $record) {
                                    if (!isset($record->instanceid)) {
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
                                                    WHERE s.id = :instanceid AND cm.course = :courseid
                                                    AND s.$field IS NOT NULL AND s.$field != ''
                                                    AND qr.usingcontextid = ctx.id
                                                    AND qr.component = 'mod_quiz'
                                                    AND qr.questionarea = 'slot'";
                                            $params = [
                                                'modulename' => $table,
                                                'contextlevel' => CONTEXT_MODULE,
                                                'instanceid' => $instanceid,
                                                'courseid' => $courseid,
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
                                                    WHERE s.id = :instanceid AND cm.course = :courseid
                                                    AND s.$field IS NOT NULL AND s.$field != ''
                                                    AND qr.usingcontextid = ctx.id
                                                    AND qr.component = 'mod_quiz'
                                                    AND qr.questionarea = 'slot'";
                                            $params = [
                                                'modulename' => $table,
                                                'contextlevel' => CONTEXT_MODULE,
                                                'instanceid' => $instanceid,
                                                'courseid' => $courseid,
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
                                                    WHERE s.id = :instanceid AND cm.course = :courseid
                                                    AND s.$field IS NOT NULL AND s.$field != ''
                                                    AND qr.usingcontextid = ctx.id
                                                    AND qr.component = 'mod_quiz'
                                                    AND qr.questionarea = 'slot'";
                                            $params = [
                                                'modulename' => $table,
                                                'contextlevel' => CONTEXT_MODULE,
                                                'instanceid' => $instanceid,
                                                'courseid' => $courseid,
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
                                                        WHERE s.id = :instanceid AND cm.course = :courseid
                                                        AND s.$field IS NOT NULL AND s.$field != ''";
                                            } else if ($parenttable) {
                                                $sql = "SELECT s.id AS instanceid, s.$field AS content, cm.course, p.id
                                                        AS primary_instanceid, cm.id AS cmid
                                                        FROM {{$secondarytable}} s
                                                        JOIN {{$parenttable}} pt ON s.$fk = pt.id
                                                        JOIN {{$table}} p ON p.id = pt.$parentfk
                                                        JOIN {course_modules} cm ON cm.instance = p.id
                                                        AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                                        WHERE s.id = :instanceid AND cm.course = :courseid
                                                        AND s.$field IS NOT NULL AND s.$field != ''";
                                            } else {
                                                $sql = "SELECT s.id AS instanceid, s.$field AS content, cm.course, p.id
                                                        AS primary_instanceid, cm.id AS cmid
                                                        FROM {{$secondarytable}} s
                                                        JOIN {{$table}} p ON p.id = s.$fk
                                                        JOIN {course_modules} cm ON cm.instance = p.id
                                                        AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                                        WHERE s.id = :instanceid AND cm.course = :courseid
                                                        AND s.$field IS NOT NULL AND s.$field != ''";
                                            }
                                            $params = [
                                                'modulename' => $table,
                                                'instanceid' => $instanceid,
                                                'courseid' => $courseid,
                                            ];
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
                                            $taggingservice->mark_translations_for_revision(
                                                $secondarytable,
                                                $fieldrecord->id,
                                                [$field],
                                                $context
                                            );
                                        }
                                        $this->update_progress('running', $totalentrieschecked, $totalentries);
                                    }
                                }

                                $offset += $batchsize;
                            } catch (\dml_exception $e) {
                                $this->update_progress('failed', $totalentrieschecked, $totalentries, 'Error: ' . $e->getMessage());
                                return;
                            }
                        } while ($count >= $batchsize);
                    }
                }
            }
        }

        $this->update_progress('completed', $totalentrieschecked, $totalentries, 'Rebuild completed successfully.');
    }
}
