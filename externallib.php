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
 * External API for the filter_autotranslate plugin.
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_autotranslate;

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * External API class for the filter_autotranslate plugin.
 */
class external extends external_api {
    /**
     * Parameters for the autotranslate function.
     *
     * @return external_function_parameters
     */
    public static function autotranslate_parameters() {
        return new external_function_parameters([
            'targetlang' => new external_value(PARAM_TEXT, 'Target language code (e.g., es)', VALUE_REQUIRED),
            'courseid' => new external_value(PARAM_INT, 'Course ID to filter by', VALUE_DEFAULT, 0),
            'filter_human' => new external_value(PARAM_TEXT, 'Filter by human status', VALUE_DEFAULT, ''),
            'filter_needsreview' => new external_value(PARAM_TEXT, 'Filter by review status', VALUE_DEFAULT, ''),
            'perpage' => new external_value(PARAM_INT, 'Records per page', VALUE_DEFAULT, 20),
            'page' => new external_value(PARAM_INT, 'Current page number', VALUE_DEFAULT, 0),
            'sort' => new external_value(PARAM_ALPHA, 'Sort column', VALUE_DEFAULT, 'hash'),
            'dir' => new external_value(PARAM_ALPHA, 'Sort direction', VALUE_DEFAULT, 'ASC'),
        ]);
    }

    /**
     * Queues an adhoc task to fetch translations for untranslated entries.
     *
     * @param string $targetlang Target language code.
     * @param int $courseid Course ID to filter by.
     * @param string $filterhuman Filter by human status.
     * @param string $filterneedsreview Filter by review status.
     * @param int $perpage Records per page.
     * @param int $page Current page number.
     * @param string $sort Sort column.
     * @param string $dir Sort direction.
     * @return array Task ID and success status.
     */
    public static function autotranslate($targetlang, $courseid, $filterhuman, $filterneedsreview, $perpage, $page, $sort, $dir) {
        global $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::autotranslate_parameters(), [
            'targetlang' => $targetlang,
            'courseid' => $courseid,
            'filter_human' => $filterhuman,
            'filter_needsreview' => $filterneedsreview,
            'perpage' => $perpage,
            'page' => $page,
            'sort' => $sort,
            'dir' => $dir,
        ]);

        // Validate context and capability.
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('filter/autotranslate:manage', $context);

        // Validate target language.
        if (empty($params['targetlang']) || $params['targetlang'] === 'other' || $params['targetlang'] === 'all') {
            throw new \invalid_parameter_exception('Target language must be a valid language code (not "other" or "all").');
        }

        // Fetch all untranslated entries matching the filter criteria (not limited by page).
        $sql = "SELECT t_other.hash, t_other.translated_text AS source_text, t_other.contextlevel
                FROM {filter_autotranslate_translations} t_other
                LEFT JOIN {filter_autotranslate_translations} t_target
                    ON t_other.hash = t_target.hash AND t_target.lang = :targetlang
                WHERE t_other.lang = 'other' AND t_target.id IS NULL";
        $sqlparams = ['targetlang' => $params['targetlang']];

        if ($courseid > 0) {
            $sql .= " AND t_other.hash IN (
                        SELECT hash FROM {filter_autotranslate_hid_cids} WHERE courseid = :courseid
                      )";
            $sqlparams['courseid'] = $courseid;
        }

        $sql .= " ORDER BY t_other.{$params['sort']} {$params['dir']}";
        // Fetch all records, not limited by page/perpage.
        $records = $DB->get_records_sql($sql, $sqlparams);
        $total = count($records);

        if (empty($records)) {
            return ['taskid' => 0, 'success' => true, 'message' => 'No untranslated entries found.'];
        }

        // Verify the hashes exist in the database.
        $hashes = array_keys($records);
        $validhashes = $DB->get_fieldset_select('filter_autotranslate_translations', 'hash', 'hash IN (' . implode(',', array_fill(0, count($hashes), '?')) . ') AND lang = ?', array_merge($hashes, ['other']));
        if (empty($validhashes)) {
            return ['taskid' => 0, 'success' => true, 'message' => 'No valid untranslated entries found.'];
        }

        // Queue an adhoc task with all valid hashes.
        $task = new \filter_autotranslate\task\autotranslate_adhoc_task();
        $task->set_custom_data([
            'targetlang' => $params['targetlang'],
            'courseid' => $courseid,
            'hashes' => $validhashes,
            'total_entries' => count($validhashes),
        ]);
        $taskid = \core\task\manager::queue_adhoc_task($task);

        // Create a progress record.
        $progress = new \stdClass();
        $progress->taskid = $taskid;
        $progress->tasktype = 'autotranslate';
        $progress->total_entries = count($validhashes);
        $progress->processed_entries = 0;
        $progress->status = 'queued';
        $progress->failure_reason = ''; // Initialize as empty
        $progress->timecreated = time();
        $progress->timemodified = time();
        $DB->insert_record('filter_autotranslate_task_progress', $progress);

        return [
            'taskid' => $taskid,
            'success' => true,
            'message' => 'Autotranslate task queued successfully.',
        ];
    }

    /**
     * Return value for the autotranslate function.
     *
     * @return external_single_structure
     */
    public static function autotranslate_returns() {
        return new external_single_structure([
            'taskid' => new external_value(PARAM_INT, 'ID of the queued task'),
            'success' => new external_value(PARAM_BOOL, 'Whether the task was queued successfully'),
            'message' => new external_value(PARAM_TEXT, 'Success or error message'),
        ]);
    }

    /**
     * Parameters for the rebuild_translations function.
     *
     * @return external_function_parameters
     */
    public static function rebuild_translations_parameters() {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID to rebuild translations for', VALUE_REQUIRED),
        ]);
    }

    /**
     * Queues an adhoc task to rebuild translations for a specific course.
     *
     * @param int $courseid Course ID to rebuild translations for.
     * @return array Task ID and success status.
     */
    public static function rebuild_translations($courseid) {
        global $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::rebuild_translations_parameters(), [
            'courseid' => $courseid,
        ]);

        // Validate context and capability.
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('filter/autotranslate:manage', $context);

        // Validate course ID.
        if (!$DB->record_exists('course', ['id' => $courseid])) {
            throw new \invalid_parameter_exception("Course ID $courseid does not exist.");
        }

        // Estimate total entries to process (for progress tracking).
        $total = 0;
        $tables = \filter_autotranslate\tagging_config::get_default_tables();
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
                    if ($ctx == CONTEXT_COURSE) {
                        if ($table === 'course') {
                            $total += $DB->count_records_select('course', "id = :courseid AND $field IS NOT NULL AND $field != ''", ['courseid' => $courseid]);
                        } else if ($table === 'course_sections') {
                            $total += $DB->count_records_select('course_sections', "course = :courseid AND $field IS NOT NULL AND $field != ''", ['courseid' => $courseid]);
                        }
                    } else if ($ctx == CONTEXT_MODULE) {
                        $sql = "SELECT COUNT(m.id)
                                FROM {{$table}} m
                                JOIN {course_modules} cm ON cm.instance = m.id
                                AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)
                                WHERE cm.course = :courseid AND m.$field IS NOT NULL AND m.$field != ''";
                        $total += $DB->count_records_sql($sql, ['modulename' => $table, 'courseid' => $courseid]);
                    }
                }
            }
        }

        // Queue an adhoc task.
        $task = new \filter_autotranslate\task\rebuild_translations_adhoc_task();
        $task->set_custom_data([
            'courseid' => $courseid,
            'total_entries' => $total,
        ]);
        $taskid = \core\task\manager::queue_adhoc_task($task);

        // Create a progress record.
        $progress = new \stdClass();
        $progress->taskid = $taskid;
        $progress->tasktype = 'rebuild';
        $progress->total_entries = $total;
        $progress->processed_entries = 0;
        $progress->status = 'queued';
        $progress->failure_reason = ''; // Initialize as empty
        $progress->timecreated = time();
        $progress->timemodified = time();
        $DB->insert_record('filter_autotranslate_task_progress', $progress);

        return [
            'taskid' => $taskid,
            'success' => true,
            'message' => 'Rebuild Translations task queued successfully.',
        ];
    }

    /**
     * Return value for the rebuild_translations function.
     *
     * @return external_single_structure
     */
    public static function rebuild_translations_returns() {
        return new external_single_structure([
            'taskid' => new external_value(PARAM_INT, 'ID of the queued task'),
            'success' => new external_value(PARAM_BOOL, 'Whether the task was queued successfully'),
            'message' => new external_value(PARAM_TEXT, 'Success or error message'),
        ]);
    }

    /**
     * Parameters for the task_status function.
     *
     * @return external_function_parameters
     */
    public static function task_status_parameters() {
        return new external_function_parameters([
            'taskid' => new external_value(PARAM_INT, 'ID of the task to check', VALUE_REQUIRED),
        ]);
    }

    /**
     * Retrieves the status and progress of a task.
     *
     * @param int $taskid ID of the task to check.
     * @return array Task status and progress.
     */
    public static function task_status($taskid) {
        global $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::task_status_parameters(), [
            'taskid' => $taskid,
        ]);

        // Validate context and capability.
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('filter/autotranslate:manage', $context);

        // Fetch the progress record.
        $progress = $DB->get_record('filter_autotranslate_task_progress', ['taskid' => $taskid]);
        if (!$progress) {
            throw new \moodle_exception('tasknotfound', 'filter_autotranslate');
        }

        $percentage = $progress->total_entries > 0 ? ($progress->processed_entries / $progress->total_entries) * 100 : 0;

        return [
            'taskid' => $progress->taskid,
            'tasktype' => $progress->tasktype,
            'total_entries' => $progress->total_entries,
            'processed_entries' => $progress->processed_entries,
            'percentage' => round($percentage, 2),
            'status' => $progress->status,
            'failure_reason' => $progress->failure_reason ?? '', // Include the failure reason
        ];
    }

    /**
     * Return value for the task_status function.
     *
     * @return external_single_structure
     */
    public static function task_status_returns() {
        return new external_single_structure([
            'taskid' => new external_value(PARAM_INT, 'ID of the task'),
            'tasktype' => new external_value(PARAM_TEXT, 'Type of task (autotranslate or rebuild)'),
            'total_entries' => new external_value(PARAM_INT, 'Total number of entries to process'),
            'processed_entries' => new external_value(PARAM_INT, 'Number of entries processed'),
            'percentage' => new external_value(PARAM_FLOAT, 'Progress percentage'),
            'status' => new external_value(PARAM_TEXT, 'Status of the task (queued, running, completed, failed)'),
            'failure_reason' => new external_value(PARAM_TEXT, 'Reason for task failure, if applicable', VALUE_DEFAULT, ''),
        ]);
    }
}
