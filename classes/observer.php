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
 * Autotranslate Observers
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace filter_autotranslate;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/filter/autotranslate/classes/tagging_config.php');
require_once($CFG->dirroot . '/filter/autotranslate/classes/translation_repository.php');
require_once($CFG->dirroot . '/filter/autotranslate/classes/helper.php');

/**
 * Observer class for handling delete events in the filter_autotranslate plugin.
 *
 * Purpose:
 * This class handles Moodle events related to the deletion of courses, course modules, and course
 * sections, ensuring that associated hash-course mappings in the autotranslate_hid_cids table are
 * removed to keep the database clean. Tagging of content for create and update events is handled by
 * the tagcontent_task.php scheduled task, which processes content in batches for better performance.
 *
 * Design Decisions:
 * - Focuses on delete events only (course_module_deleted, course_deleted, course_section_deleted)
 *   to remove obsolete hash-course mappings, keeping the observer lightweight and performant.
 * - Uses translation_repository.php to fetch hashes associated with a course, centralizing database
 *   read operations.
 * - Uses helper.php for utility functions (e.g., extract_hash).
 * - Removed create and update event handlers, as tagging is now handled by tagcontent_task.php,
 *   reducing complexity and improving scalability.
 * - Removed debugging logs to a file, replacing them with Moodle's debugging API for production use.
 * - Function names use snake_case (e.g., course_module_deleted) to follow Moodle's coding style.
 *
 * Dependencies:
 * - tagging_config.php: For accessing the tagging configuration (e.g., secondary tables).
 * - translation_repository.php: For fetching hashes associated with a course.
 * - helper.php: For utility functions (e.g., extract_hash).
 */
class observer {
    /**
     * Handles the course module deleted event.
     *
     * This method removes hash-course mappings from autotranslate_hid_cids when a course module
     * is deleted, ensuring the database remains clean.
     *
     * @param \core\event\course_module_deleted $event The course module deleted event.
     */
    public static function course_module_deleted(\core\event\course_module_deleted $event) {
        global $DB;

        try {
            $data = $event->get_data();
            $cmid = $data['contextinstanceid'];
            $modulename = $data['other']['modulename'];
            $instanceid = $data['other']['instanceid'];
            $courseid = $event->courseid;

            if (!$courseid || !$instanceid) {
                debugging("Missing courseid or instanceid in course_module_deleted event: " . json_encode($data), DEBUG_DEVELOPER);
                return;
            }

            // Initialize the translation repository
            $repository = new translation_repository($DB);

            // Collect hashes from the module snapshot (primary table)
            $hashes = [];
            $module = $event->get_record_snapshot($modulename, $instanceid);
            if ($module) {
                // Get the fields to process from the tagging configuration
                $fields = \filter_autotranslate\tagging_manager::get_fields_to_tag($modulename, CONTEXT_MODULE);
                foreach ($fields as $field) {
                    if (empty($module->$field)) {
                        continue;
                    }
                    $hash = helper::extract_hash($module->$field);
                    if ($hash) {
                        $hashes[$hash] = true;
                    }
                }

                // Process secondary tables to collect hashes
                $secondary_tables = \filter_autotranslate\tagging_manager::get_secondary_tables($modulename, CONTEXT_MODULE);
                foreach ($secondary_tables as $secondary_table => $secondary_fields) {
                    $relationship = \filter_autotranslate\tagging_config::get_relationship_details($secondary_table);
                    foreach ($secondary_fields as $field) {
                        // Use the version of the query that does not include course_modules
                        list($sql, $params) = \filter_autotranslate\tagging_manager::build_secondary_table_query($secondary_table, $field, $modulename, $instanceid, $relationship, false);
                        $records = $DB->get_records_sql($sql, $params);
                        foreach ($records as $record) {
                            $hash = helper::extract_hash($record->content);
                            if ($hash) {
                                $hashes[$hash] = true;
                            }
                        }
                    }
                }
            } else {
                // Fallback: Fetch hashes from autotranslate_hid_cids and autotranslate_translations
                $sql = "SELECT DISTINCT hc.hash
                        FROM {autotranslate_hid_cids} hc
                        JOIN {autotranslate_translations} t ON t.hash = hc.hash
                        WHERE hc.courseid = :courseid
                        AND t.contextlevel = :contextlevel";
                $params = ['courseid' => $courseid, 'contextlevel' => CONTEXT_MODULE];
                $hash_records = $DB->get_records_sql($sql, $params);
                foreach ($hash_records as $hash_record) {
                    $hashes[$hash_record->hash] = true;
                }
            }

            // Remove the relationships from autotranslate_hid_cids
            self::remove_hash_course_mappings(array_keys($hashes), $courseid);
        } catch (\Exception $e) {
            debugging("Error in course_module_deleted: " . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Handles the course deleted event.
     *
     * This method removes all hash-course mappings from autotranslate_hid_cids for the deleted course,
     * ensuring the database remains clean.
     *
     * @param \core\event\course_deleted $event The course deleted event.
     */
    public static function course_deleted(\core\event\course_deleted $event) {
        global $DB;

        try {
            $courseid = $event->courseid;
            if (!$courseid) {
                debugging("Missing courseid in course_deleted event: " . json_encode($event->get_data()), DEBUG_DEVELOPER);
                return;
            }

            // Remove all entries in autotranslate_hid_cids for this course
            $DB->delete_records('autotranslate_hid_cids', ['courseid' => $courseid]);
        } catch (\Exception $e) {
            debugging("Error in course_deleted: " . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Handles the course section deleted event.
     *
     * This method removes hash-course mappings from autotranslate_hid_cids when a course section
     * is deleted, ensuring the database remains clean.
     *
     * @param \core\event\course_section_deleted $event The course section deleted event.
     */
    public static function course_section_deleted(\core\event\course_section_deleted $event) {
        global $DB;

        try {
            $sectionid = $event->objectid;
            $courseid = $event->courseid;

            if (!$sectionid || !$courseid) {
                debugging("Missing sectionid or courseid in course_section_deleted event: " . json_encode($event->get_data()), DEBUG_DEVELOPER);
                return;
            }

            // Get the section record (snapshot since it’s already deleted)
            $section = $event->get_record_snapshot('course_sections', $sectionid);
            if (!$section) {
                debugging("Could not fetch section snapshot for sectionid $sectionid in course_section_deleted event", DEBUG_DEVELOPER);
                return;
            }

            // Get the fields to process from the tagging configuration
            $context = \context_course::instance($courseid);
            $fields = \filter_autotranslate\tagging_manager::get_fields_to_tag('course_sections', $context->contextlevel);

            // Collect hashes from the section fields
            $hashes = [];
            foreach ($fields as $field) {
                if (empty($section->$field)) {
                    continue;
                }
                $hash = helper::extract_hash($section->$field);
                if ($hash) {
                    $hashes[$hash] = true;
                }
            }

            // Remove the relationships from autotranslate_hid_cids
            self::remove_hash_course_mappings(array_keys($hashes), $courseid);
        } catch (\Exception $e) {
            debugging("Error in course_section_deleted: " . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Removes hash-course mappings from autotranslate_hid_cids.
     *
     * This method deletes records from autotranslate_hid_cids for the given hashes and course ID,
     * ensuring the database remains clean after a deletion event.
     *
     * @param array $hashes Array of hashes to remove.
     * @param int $courseid The course ID to remove mappings for.
     */
    private static function remove_hash_course_mappings($hashes, $courseid) {
        global $DB;

        if (empty($hashes) || !$courseid) {
            debugging("No hashes or courseid provided, skipping removal from autotranslate_hid_cids.", DEBUG_DEVELOPER);
            return;
        }

        // Prepare the SQL to delete mappings
        $placeholders = implode(',', array_fill(0, count($hashes), '?'));
        $params = array_merge($hashes, [$courseid]);
        $sql = "DELETE FROM {autotranslate_hid_cids}
                WHERE hash IN ($placeholders)
                AND courseid = ?";
        $DB->execute($sql, $params);
    }
}