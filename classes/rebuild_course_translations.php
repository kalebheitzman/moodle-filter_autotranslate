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
 * Autotranslate Course-Specific Rebuild
 *
 * This class handles the rebuilding of translations for a specific course,
 * triggered manually via CLI or the "Rebuild Translations" button in the manage page.
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace filter_autotranslate;

defined('MOODLE_INTERNAL') || die();

use filter_autotranslate\tagging_manager;
use filter_autotranslate\tagging_service;

class rebuild_course_translations {

    /**
     * Executes the tagging task for a specific course.
     *
     * This method processes only the content related to the specified course ID,
     * limiting the scope to CONTEXT_COURSE (50) and CONTEXT_MODULE (70).
     *
     * @param int $courseid The ID of the course to rebuild translations for.
     */
    public function execute($courseid) {
        global $DB;

        // Ensure the course exists
        if (!$DB->record_exists('course', ['id' => $courseid])) {
            debugging("Error: Course ID $courseid does not exist.", DEBUG_DEVELOPER);
            return;
        }

        $tagging_service = new \filter_autotranslate\tagging_service($DB);
        $tables = \filter_autotranslate\tagging_config::get_default_tables();
        $total_entries_checked = 0;
        $batchsize = (int)(get_config('filter_autotranslate', 'managelimit') ?: 20);

        debugging("Rebuilding translations for course ID: $courseid", DEBUG_DEVELOPER);

        // Limit to course-related contexts
        $relevant_contexts = [CONTEXT_COURSE, CONTEXT_MODULE];
        foreach ($tables as $ctx => $table_list) {
            if (!in_array($ctx, $relevant_contexts)) {
                continue;
            }

            foreach ($table_list as $table => $config) {
                $fields = \filter_autotranslate\tagging_manager::get_fields_to_tag($table, $ctx);
                if (empty($fields)) {
                    continue;
                }

                debugging("Processing table: $table, Fields: " . implode(', ', $fields), DEBUG_DEVELOPER);
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
                                } elseif ($table === 'course_sections') {
                                    $sql = "SELECT cs.id AS instanceid, cs.$field AS content, cs.course
                                            FROM {course_sections} cs
                                            WHERE cs.course = :courseid AND cs.$field IS NOT NULL AND cs.$field != ''";
                                    $params = ['courseid' => $courseid];
                                } else {
                                    continue; // Skip non-course tables in this context
                                }
                            } elseif ($ctx == CONTEXT_MODULE) {
                                $sql = "SELECT m.id AS instanceid, m.$field AS content, cm.course, cm.id AS cmid
                                        FROM {{$table}} m
                                        JOIN {course_modules} cm ON cm.instance = m.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                        WHERE cm.course = :courseid AND m.$field IS NOT NULL AND m.$field != ''";
                                $params = ['modulename' => $table, 'courseid' => $courseid];
                            } else {
                                continue; // Shouldn’t reach here due to context filter
                            }

                            $records = $DB->get_records_sql($sql, $params, $offset, $batchsize);
                            $count = count($records);
                            $total_entries_checked += $count;
                            debugging("Fetched $count records for table $table, field $field at offset $offset", DEBUG_DEVELOPER);

                            if ($count > 0) {
                                $firstrecord = reset($records);
                                $context = isset($firstrecord->cmid) ? \context_module::instance($firstrecord->cmid) : \context_course::instance($courseid);
                                debugging("First record: " . json_encode($firstrecord), DEBUG_DEVELOPER);
                            } else {
                                $context = \context_course::instance($courseid); // Fallback
                            }

                            foreach ($records as $record) {
                                if (!isset($record->instanceid)) {
                                    debugging("Error: Record missing instanceid for table $table, field $field: " . json_encode($record), DEBUG_DEVELOPER);
                                    continue;
                                }
                                $record->id = $record->instanceid;
                                unset($record->instanceid);
                                $record->$field = $record->content;
                                unset($record->content);
                                if (!isset($record->id)) {
                                    debugging("Error: Record missing id field after renaming for table $table, field $field", DEBUG_DEVELOPER);
                                    continue;
                                }
                                $updated = $tagging_service->tag_content($table, $record, [$field], $context, $record->course);
                                if ($updated) {
                                    debugging("Tagged content in table $table, field $field, instanceid {$record->id}: " . substr($record->$field, 0, 50) . "...", DEBUG_DEVELOPER);
                                    $tagging_service->mark_translations_for_revision($table, $record->id, [$field], $context);
                                }
                            }

                            $offset += $batchsize;
                        } catch (\dml_exception $e) {
                            debugging("Error executing query for table $table, field $field: " . $e->getMessage(), DEBUG_DEVELOPER);
                            break;
                        }
                    } while ($count >= $batchsize);
                }

                // Process secondary tables for module contexts
                if ($ctx == CONTEXT_MODULE) {
                    $secondary_tables = \filter_autotranslate\tagging_manager::get_secondary_tables($table, $ctx);
                    foreach ($secondary_tables as $secondary_table => $secondary_fields) {
                        debugging("Processing secondary table: $secondary_table, Fields: " . implode(', ', $secondary_fields), DEBUG_DEVELOPER);
                        $offset = 0;

                        do {
                            try {
                                $relationship = \filter_autotranslate\tagging_config::get_relationship_details($secondary_table);
                                $fk = $relationship['fk'];
                                $parent_table = $relationship['parent_table'];
                                $parent_fk = $relationship['parent_fk'];
                                $grandparent_table = $relationship['grandparent_table'];
                                $grandparent_fk = $relationship['grandparent_fk'];

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
                                            WHERE cm.course = :courseid
                                            AND qr.usingcontextid = ctx.id
                                            AND qr.component = 'mod_quiz'
                                            AND qr.questionarea = 'slot'";
                                    $params = ['modulename' => $table, 'contextlevel' => CONTEXT_MODULE, 'courseid' => $courseid];
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
                                            WHERE cm.course = :courseid
                                            AND qr.usingcontextid = ctx.id
                                            AND qr.component = 'mod_quiz'
                                            AND qr.questionarea = 'slot'";
                                    $params = ['modulename' => $table, 'contextlevel' => CONTEXT_MODULE, 'courseid' => $courseid];
                                } elseif ($secondary_table === 'question_categories') {
                                    $sql = "SELECT DISTINCT s.id AS instanceid, cm.course, p.id AS primary_instanceid, cm.id AS cmid
                                            FROM {{$secondary_table}} s
                                            JOIN {question_bank_entries} qbe ON qbe.questioncategoryid = s.id
                                            JOIN {question_references} qr ON qr.questionbankentryid = qbe.id
                                            JOIN {{$grandparent_table}} gpt ON gpt.id = qr.itemid
                                            JOIN {{$table}} p ON p.id = gpt.quizid
                                            JOIN {course_modules} cm ON cm.instance = p.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                            JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                                            WHERE cm.course = :courseid
                                            AND qr.usingcontextid = ctx.id
                                            AND qr.component = 'mod_quiz'
                                            AND qr.questionarea = 'slot'";
                                    $params = ['modulename' => $table, 'contextlevel' => CONTEXT_MODULE, 'courseid' => $courseid];
                                } else {
                                    if ($grandparent_table) {
                                        $sql = "SELECT DISTINCT s.id AS instanceid, cm.course, p.id AS primary_instanceid, cm.id AS cmid
                                                FROM {{$secondary_table}} s
                                                JOIN {{$parent_table}} pt ON s.$fk = pt.id
                                                JOIN {{$grandparent_table}} gpt ON pt.$parent_fk = gpt.id
                                                JOIN {{$table}} p ON p.id = gpt.$grandparent_fk
                                                JOIN {course_modules} cm ON cm.instance = p.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                                WHERE cm.course = :courseid";
                                    } elseif ($parent_table) {
                                        $sql = "SELECT DISTINCT s.id AS instanceid, cm.course, p.id AS primary_instanceid, cm.id AS cmid
                                                FROM {{$secondary_table}} s
                                                JOIN {{$parent_table}} pt ON s.$fk = pt.id
                                                JOIN {{$table}} p ON p.id = pt.$parent_fk
                                                JOIN {course_modules} cm ON cm.instance = p.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                                WHERE cm.course = :courseid";
                                    } else {
                                        $sql = "SELECT DISTINCT s.id AS instanceid, cm.course, p.id AS primary_instanceid, cm.id AS cmid
                                                FROM {{$secondary_table}} s
                                                JOIN {{$table}} p ON p.id = s.$fk
                                                JOIN {course_modules} cm ON cm.instance = p.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                                WHERE cm.course = :courseid";
                                    }
                                    $params = ['modulename' => $table, 'courseid' => $courseid];
                                }

                                $records = $DB->get_records_sql($sql, $params, $offset, $batchsize);
                                $count = count($records);
                                $total_entries_checked += $count;
                                debugging("Fetched $count records for table $secondary_table at offset $offset", DEBUG_DEVELOPER);

                                if ($count > 0) {
                                    $firstrecord = reset($records);
                                    $context = isset($firstrecord->cmid) ? \context_module::instance($firstrecord->cmid) : \context_course::instance($courseid);
                                    debugging("First record: " . json_encode($firstrecord), DEBUG_DEVELOPER);
                                } else {
                                    $context = \context_course::instance($courseid); // Fallback
                                }

                                foreach ($records as $record) {
                                    if (!isset($record->instanceid)) {
                                        debugging("Error: Secondary record missing instanceid for table $secondary_table: " . json_encode($record), DEBUG_DEVELOPER);
                                        continue;
                                    }
                                    $instanceid = $record->instanceid;

                                    foreach ($secondary_fields as $field) {
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
                                                    WHERE s.id = :instanceid AND cm.course = :courseid
                                                    AND s.$field IS NOT NULL AND s.$field != ''
                                                    AND qr.usingcontextid = ctx.id
                                                    AND qr.component = 'mod_quiz'
                                                    AND qr.questionarea = 'slot'";
                                            $params = ['modulename' => $table, 'contextlevel' => CONTEXT_MODULE, 'instanceid' => $instanceid, 'courseid' => $courseid];
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
                                                    WHERE s.id = :instanceid AND cm.course = :courseid
                                                    AND s.$field IS NOT NULL AND s.$field != ''
                                                    AND qr.usingcontextid = ctx.id
                                                    AND qr.component = 'mod_quiz'
                                                    AND qr.questionarea = 'slot'";
                                            $params = ['modulename' => $table, 'contextlevel' => CONTEXT_MODULE, 'instanceid' => $instanceid, 'courseid' => $courseid];
                                        } elseif ($secondary_table === 'question_categories') {
                                            $sql = "SELECT s.id AS instanceid, s.$field AS content, cm.course, p.id AS primary_instanceid, cm.id AS cmid
                                                    FROM {{$secondary_table}} s
                                                    JOIN {question_bank_entries} qbe ON qbe.questioncategoryid = s.id
                                                    JOIN {question_references} qr ON qr.questionbankentryid = qbe.id
                                                    JOIN {{$grandparent_table}} gpt ON gpt.id = qr.itemid
                                                    JOIN {{$table}} p ON p.id = gpt.quizid
                                                    JOIN {course_modules} cm ON cm.instance = p.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                                    JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                                                    WHERE s.id = :instanceid AND cm.course = :courseid
                                                    AND s.$field IS NOT NULL AND s.$field != ''
                                                    AND qr.usingcontextid = ctx.id
                                                    AND qr.component = 'mod_quiz'
                                                    AND qr.questionarea = 'slot'";
                                            $params = ['modulename' => $table, 'contextlevel' => CONTEXT_MODULE, 'instanceid' => $instanceid, 'courseid' => $courseid];
                                        } else {
                                            if ($grandparent_table) {
                                                $sql = "SELECT s.id AS instanceid, s.$field AS content, cm.course, p.id AS primary_instanceid, cm.id AS cmid
                                                        FROM {{$secondary_table}} s
                                                        JOIN {{$parent_table}} pt ON s.$fk = pt.id
                                                        JOIN {{$grandparent_table}} gpt ON pt.$parent_fk = gpt.id
                                                        JOIN {{$table}} p ON p.id = gpt.$grandparent_fk
                                                        JOIN {course_modules} cm ON cm.instance = p.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                                        WHERE s.id = :instanceid AND cm.course = :courseid
                                                        AND s.$field IS NOT NULL AND s.$field != ''";
                                            } elseif ($parent_table) {
                                                $sql = "SELECT s.id AS instanceid, s.$field AS content, cm.course, p.id AS primary_instanceid, cm.id AS cmid
                                                        FROM {{$secondary_table}} s
                                                        JOIN {{$parent_table}} pt ON s.$fk = pt.id
                                                        JOIN {{$table}} p ON p.id = pt.$parent_fk
                                                        JOIN {course_modules} cm ON cm.instance = p.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                                        WHERE s.id = :instanceid AND cm.course = :courseid
                                                        AND s.$field IS NOT NULL AND s.$field != ''";
                                            } else {
                                                $sql = "SELECT s.id AS instanceid, s.$field AS content, cm.course, p.id AS primary_instanceid, cm.id AS cmid
                                                        FROM {{$secondary_table}} s
                                                        JOIN {{$table}} p ON p.id = s.$fk
                                                        JOIN {course_modules} cm ON cm.instance = p.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                                        WHERE s.id = :instanceid AND cm.course = :courseid
                                                        AND s.$field IS NOT NULL AND s.$field != ''";
                                            }
                                            $params = ['modulename' => $table, 'instanceid' => $instanceid, 'courseid' => $courseid];
                                        }

                                        $field_record = $DB->get_record_sql($sql, $params);
                                        if (!$field_record) {
                                            continue;
                                        }

                                        $field_record->id = $field_record->instanceid;
                                        unset($field_record->instanceid);
                                        $field_record->$field = $field_record->content;
                                        unset($field_record->content);
                                        if (!isset($field_record->id)) {
                                            debugging("Error: Secondary record missing id field after renaming for table $secondary_table, field $field", DEBUG_DEVELOPER);
                                            continue;
                                        }
                                        $updated = $tagging_service->tag_content($secondary_table, $field_record, [$field], $context, $field_record->course);
                                        if ($updated) {
                                            debugging("Tagged content in table $secondary_table, field $field, instanceid {$field_record->id}: " . substr($field_record->$field, 0, 50) . "...", DEBUG_DEVELOPER);
                                            $tagging_service->mark_translations_for_revision($secondary_table, $field_record->id, [$field], $context);
                                        }
                                    }
                                }

                                $offset += $batchsize;
                            } catch (\dml_exception $e) {
                                debugging("Error executing query for secondary table $secondary_table: " . $e->getMessage(), DEBUG_DEVELOPER);
                                break;
                            }
                        } while ($count >= $batchsize);
                    }
                }
            }
        }

        debugging("Rebuild completed for course ID $courseid. Total entries checked: $total_entries_checked", DEBUG_DEVELOPER);
    }
}