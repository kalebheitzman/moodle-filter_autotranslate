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
 * Purpose:
 * Defines external API functions for the filter_autotranslate plugin, enabling AJAX interactions
 * from manage.php to queue autotranslate tasks and retrieve task status.
 *
 * Design Decisions:
 * - Uses `content_service` and `translation_source` for database operations, aligning with the
 *   plugin’s core structure.
 * - Removes `rebuild_translations` function as it’s no longer used in manage.php with Option 3
 *   (Mark Stale and Lazy Rebuild).
 * - Employs all lowercase variable names per plugin convention.
 *
 * Dependencies:
 * - `content_service.php`: For managing translations (not directly used here but implied).
 * - `translation_source.php`: For fetching translation data.
 * - `task/autotranslate_adhoc_task.php`: For queuing the autotranslate task.
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
     * Identifies untranslated entries in the target language based on the provided filters and
     * queues an autotranslate task with their hashes.
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

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('filter/autotranslate:manage', $context);

        if (empty($params['targetlang']) || $params['targetlang'] === 'other' || $params['targetlang'] === 'all') {
            throw new \invalid_parameter_exception('Target language must be a valid language code (not "other" or "all").');
        }

        $translationsource = new \filter_autotranslate\translation_source($DB);
        $records = $translationsource->get_paginated_translations(
            0, // Fetch all, not paginated here.
            0, // No limit.
            $params['targetlang'],
            $params['filter_human'],
            $params['sort'],
            $params['dir'],
            $params['courseid'],
            $params['filter_needsreview']
        );

        $hashes = [];
        foreach ($records['translations'] as $record) {
            if (!$record->target_id) { // Untranslated entries lack a target_id.
                $hashes[] = $record->hash;
            }
        }

        $total = count($hashes);
        if (empty($hashes)) {
            return ['taskid' => 0, 'success' => true, 'message' => 'No untranslated entries found.'];
        }

        $task = new \filter_autotranslate\task\autotranslate_adhoc_task();
        $task->set_custom_data([
            'targetlang' => $params['targetlang'],
            'courseid' => $courseid,
            'hashes' => $hashes,
            'total_entries' => $total,
        ]);
        $taskid = \core\task\manager::queue_adhoc_task($task);

        $progress = new \stdClass();
        $progress->taskid = $taskid;
        $progress->tasktype = 'autotranslate';
        $progress->total_entries = $total;
        $progress->processed_entries = 0;
        $progress->status = 'queued';
        $progress->failure_reason = '';
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
     * Fetches progress data from `filter_autotranslate_task_progress` for a given task ID.
     *
     * @param int $taskid ID of the task to check.
     * @return array Task status and progress.
     */
    public static function task_status($taskid) {
        global $DB;

        $params = self::validate_parameters(self::task_status_parameters(), [
            'taskid' => $taskid,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('filter/autotranslate:manage', $context);

        $progress = $DB->get_record('filter_autotranslate_task_progress', ['taskid' => $params['taskid']]);
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
            'failure_reason' => $progress->failure_reason ?? '',
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
            'tasktype' => new external_value(PARAM_TEXT, 'Type of task (autotranslate)'),
            'total_entries' => new external_value(PARAM_INT, 'Total number of entries to process'),
            'processed_entries' => new external_value(PARAM_INT, 'Number of entries processed'),
            'percentage' => new external_value(PARAM_FLOAT, 'Progress percentage'),
            'status' => new external_value(PARAM_TEXT, 'Status of the task (queued, running, completed, failed)'),
            'failure_reason' => new external_value(PARAM_TEXT, 'Reason for task failure, if applicable', VALUE_DEFAULT, ''),
        ]);
    }
}
