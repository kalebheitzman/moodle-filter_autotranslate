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
 * Purge Mappings Task for Autotranslate Filter
 *
 * Purpose:
 * This scheduled task periodically removes orphaned hash-course mappings from the
 * autotranslate_hid_cids table, ensuring the database remains clean. It checks for mappings
 * associated with non-existent courses, course modules, and course sections, and deletes them.
 *
 * Design Decisions:
 * - Runs periodically (default: daily) to clean up orphaned mappings, avoiding the timing issues
 *   of event-driven observers.
 * - Uses straightforward database queries to identify orphaned mappings, ensuring reliability.
 * - Logs actions using Moodle's debugging API for transparency and debugging.
 * - Function names use snake_case (e.g., get_name) to follow Moodle's coding style.
 * - Does not delete translations from autotranslate_translations, as they might be reused across
 *   contexts; only removes mappings from autotranslate_hid_cids.
 *
 * Dependencies:
 * - None (interacts directly with the Moodle database).
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace filter_autotranslate\task;

defined('MOODLE_INTERNAL') || die();

class purgemappings_task extends \core\task\scheduled_task {
    /**
     * Returns the name of the task.
     *
     * @return string The name of the task.
     */
    public function get_name() {
        return get_string('purgemappingstask', 'filter_autotranslate');
    }

    /**
     * Executes the task to purge orphaned hash-course mappings.
     *
     * This method checks for mappings in autotranslate_hid_cids that reference non-existent
     * courses, course modules, or course sections, and removes them.
     */
    public function execute() {
        global $DB;

        try {
            // Step 1: Remove mappings for non-existent courses
            $sql = "SELECT DISTINCT hc.courseid
                    FROM {autotranslate_hid_cids} hc
                    LEFT JOIN {course} c ON c.id = hc.courseid
                    WHERE c.id IS NULL";
            $orphaned_courseids = $DB->get_fieldset_sql($sql);
            if (!empty($orphaned_courseids)) {
                list($insql, $inparams) = $DB->get_in_or_equal($orphaned_courseids, SQL_PARAMS_NAMED);
                $DB->delete_records_select('autotranslate_hid_cids', "courseid $insql", $inparams);
                mtrace("Removed " . count($orphaned_courseids) . " orphaned course mappings from autotranslate_hid_cids.");
            }

            // Step 2: Remove mappings for non-existent course modules (contextlevel = CONTEXT_MODULE)
            $sql = "SELECT DISTINCT hc.hash, hc.courseid
                    FROM {autotranslate_hid_cids} hc
                    JOIN {autotranslate_translations} t ON t.hash = hc.hash
                    LEFT JOIN {course_modules} cm ON cm.id = t.contextid
                    WHERE t.contextlevel = :contextlevel
                    AND cm.id IS NULL";
            $params = ['contextlevel' => CONTEXT_MODULE];
            $orphaned_modules = $DB->get_records_sql($sql, $params);
            foreach ($orphaned_modules as $record) {
                $DB->delete_records('autotranslate_hid_cids', ['hash' => $record->hash, 'courseid' => $record->courseid]);
            }
            if (!empty($orphaned_modules)) {
                mtrace("Removed " . count($orphaned_modules) . " orphaned course module mappings from autotranslate_hid_cids.");
            }

            // Step 3: Remove mappings for non-existent course sections (contextlevel = CONTEXT_COURSE)
            $sql = "SELECT DISTINCT hc.hash, hc.courseid
                    FROM {autotranslate_hid_cids} hc
                    JOIN {autotranslate_translations} t ON t.hash = hc.hash
                    LEFT JOIN {course_sections} cs ON cs.id = t.contextid AND cs.course = hc.courseid
                    WHERE t.contextlevel = :contextlevel
                    AND cs.id IS NULL";
            $params = ['contextlevel' => CONTEXT_COURSE];
            $orphaned_sections = $DB->get_records_sql($sql, $params);
            foreach ($orphaned_sections as $record) {
                $DB->delete_records('autotranslate_hid_cids', ['hash' => $record->hash, 'courseid' => $record->courseid]);
            }
            if (!empty($orphaned_sections)) {
                mtrace("Removed " . count($orphaned_sections) . " orphaned course section mappings from autotranslate_hid_cids.");
            }
        } catch (\Exception $e) {
            debugging("Error in purgemappings_task: " . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}