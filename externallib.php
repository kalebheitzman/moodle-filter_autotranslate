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
 * External API for the Autotranslate plugin.
 *
 * Defines AJAX endpoints for `manage.php` to queue autotranslate tasks and check status.
 * Integrates with `translation_source` to identify untranslated entries and triggers
 * `autotranslate_adhoc_task` for translation fetching.
 *
 * Features:
 * - Queues autotranslate tasks with filters (language, course, etc.).
 * - Retrieves task progress and status for UI updates.
 *
 * Usage:
 * - `autotranslate`: Called by `autotranslate.js` from "Autotranslate" button.
 * - `task_status`: Polled by `autotranslate.js` for progress feedback.
 *
 * Design:
 * - Validates inputs (e.g., target language, capability) before queuing.
 * - Stores progress in `filter_autotranslate_task_progress` for status checks.
 * - Follows Moodle external API conventions with parameter validation.
 *
 * Dependencies:
 * - `translation_source.php`: Fetches untranslated hashes.
 * - `task/autotranslate_adhoc_task.php`: Executes queued tasks.
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
 * External API class for Autotranslate plugin operations.
 */
class external extends external_api {
    /**
     * Defines parameters for the autotranslate function.
     *
     * @return external_function_parameters Parameter structure for validation.
     */
    public static function autotranslate_parameters() {
        return new external_function_parameters([
            'targetlang' => new external_value(PARAM_TEXT, 'Target language code (e.g., "es")', VALUE_REQUIRED),
            'courseid' => new external_value(PARAM_INT, 'Course ID filter', VALUE_DEFAULT, 0),
            'filter_human' => new external_value(PARAM_TEXT, 'Human status filter', VALUE_DEFAULT, ''),
            'filter_needsreview' => new external_value(PARAM_TEXT, 'Review status filter', VALUE_DEFAULT, ''),
            'perpage' => new external_value(PARAM_INT, 'Records per page', VALUE_DEFAULT, 20),
            'page' => new external_value(PARAM_INT, 'Page number (0-based)', VALUE_DEFAULT, 0),
            'sort' => new external_value(PARAM_ALPHA, 'Sort column (e.g., "hash")', VALUE_DEFAULT, 'hash'),
            'dir' => new external_value(PARAM_ALPHA, 'Sort direction ("ASC" or "DESC")', VALUE_DEFAULT, 'ASC'),
        ]);
    }

    /**
     * Queues an autotranslate task for untranslated entries.
     *
     * Identifies untranslated hashes using `translation_source` and queues an
     * `autotranslate_adhoc_task` with provided filters.
     *
     * @param string $targetlang Target language code.
     * @param int $courseid Course ID filter (0 for all).
     * @param string $filterhuman Human status filter ('' all, '1' human, '0' auto).
     * @param string $filterneedsreview Review status filter ('' all, '1' needs, '0' reviewed).
     * @param int $perpage Records per page.
     * @param int $page Page number (0-based).
     * @param string $sort Sort column.
     * @param string $dir Sort direction.
     * @return array Task ID, success status, and message.
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

        // Validate target language against enabled languages in settings.
        if (empty($params['targetlang']) || $params['targetlang'] === 'other' || $params['targetlang'] === 'all') {
            throw new \invalid_parameter_exception('Target language must be a valid language code (not "other" or "all").');
        }

        $enabledlangs = get_config('filter_autotranslate', 'targetlangs');
        if ($enabledlangs === false || $enabledlangs === null || $enabledlangs === '') {
            throw new \invalid_parameter_exception('No target languages are enabled in the settings.');
        }

        // Parse the CSV list of enabled languages.
        $enabledlangs = array_map('trim', explode(',', $enabledlangs));
        if (empty($enabledlangs) || !in_array($params['targetlang'], $enabledlangs)) {
            throw new \invalid_parameter_exception("Target language '{$params['targetlang']}' is not enabled in the settings.");
        }

        $translationsource = new \filter_autotranslate\translation_source($DB);
        $hashes = $translationsource->get_untranslated_hashes(
            $params['page'],
            $params['perpage'],
            $params['targetlang'],
            $params['filter_human'],
            $params['sort'],
            $params['dir'],
            $params['courseid'],
            $params['filter_needsreview']
        );

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
     * Defines return values for the autotranslate function.
     *
     * @return external_single_structure Return structure for API response.
     */
    public static function autotranslate_returns() {
        return new external_single_structure([
            'taskid' => new external_value(PARAM_INT, 'ID of the queued task'),
            'success' => new external_value(PARAM_BOOL, 'Whether the task was queued successfully'),
            'message' => new external_value(PARAM_TEXT, 'Success or error message'),
        ]);
    }

    /**
     * Defines parameters for the task_status function.
     *
     * @return external_function_parameters Parameter structure for validation.
     */
    public static function task_status_parameters() {
        return new external_function_parameters([
            'taskid' => new external_value(PARAM_INT, 'ID of the task to check', VALUE_REQUIRED),
        ]);
    }

    /**
     * Retrieves status and progress of a task.
     *
     * Fetches progress from `filter_autotranslate_task_progress` for a task ID.
     *
     * @param int $taskid ID of the task to check.
     * @return array Task status, progress percentage, and details.
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
     * Defines return values for the task_status function.
     *
     * @return external_single_structure Return structure for API response.
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
