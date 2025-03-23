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

class observer {
    /**
     * Handle course module created event.
     *
     * @param \core\event\course_module_created $event
     */
    public static function course_module_created(\core\event\course_module_created $event) {
        global $DB;

        try {
            $data = $event->get_data();
            $cmid = $data['contextinstanceid'];
            $modulename = $data['other']['modulename'];

            // Fetch the course module to get the instanceid
            $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
            $instanceid = $cm->instance;
            if (!$cm->course) {
                return;
            }

            // Fetch the module record using the instanceid
            $module = $DB->get_record($modulename, ['id' => $instanceid]);
            if (!$module) {
                return;
            }

            // Get the fields to tag from the tagging configuration
            $context = \context_module::instance($cmid);
            $fields = \filter_autotranslate\tagging_manager::get_fields_to_tag($modulename, $context->contextlevel);

            // Tag primary table fields
            $updated = \filter_autotranslate\tagging_manager::tag_fields($modulename, $module, $fields, $context, $cm->course);

            // Process secondary tables
            $secondary_tables = \filter_autotranslate\tagging_manager::get_secondary_tables($modulename, $context->contextlevel);
            \filter_autotranslate\tagging_manager::process_secondary_tables($modulename, $instanceid, $secondary_tables, $context, $cm->course);

            if ($updated) {
                \filter_autotranslate\helper::mark_translations_for_revision($modulename, $instanceid, $fields, $context);
            }
        } catch (\Exception $e) {
            // Silently handle the error for now
        }
    }

    /**
     * Handle course module updated event.
     *
     * @param \core\event\course_module_updated $event
     */
    public static function course_module_updated(\core\event\course_module_updated $event) {
        global $DB;

        try {
            $data = $event->get_data();
            $cmid = $data['contextinstanceid'];
            $modulename = $data['other']['modulename'];

            // Fetch the course module to get the instanceid
            $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
            $instanceid = $cm->instance;
            if (!$cm->course) {
                return;
            }

            // Fetch the module record using the instanceid
            $module = $DB->get_record($modulename, ['id' => $instanceid]);
            if (!$module) {
                return;
            }

            // Get the fields to tag from the tagging configuration
            $context = \context_module::instance($cmid);
            $fields = \filter_autotranslate\tagging_manager::get_fields_to_tag($modulename, $context->contextlevel);

            // Tag primary table fields
            $updated = \filter_autotranslate\tagging_manager::tag_fields($modulename, $module, $fields, $context, $cm->course);

            // Process secondary tables
            $secondary_tables = \filter_autotranslate\tagging_manager::get_secondary_tables($modulename, $context->contextlevel);
            \filter_autotranslate\tagging_manager::process_secondary_tables($modulename, $instanceid, $secondary_tables, $context, $cm->course);

            if ($updated) {
                \filter_autotranslate\helper::mark_translations_for_revision($modulename, $instanceid, $fields, $context);
            }
        } catch (\Exception $e) {
            // Silently handle the error for now
        }
    }

    /**
     * Handle course created event.
     *
     * @param \core\event\course_created $event
     */
    public static function course_created(\core\event\course_created $event) {
        global $DB;

        try {
            $data = $event->get_data();
            $courseid = $data['courseid'];
            if (!$courseid) {
                return;
            }

            $course = $DB->get_record('course', ['id' => $courseid]);
            if (!$course) {
                return;
            }

            // Get the fields to tag from the tagging configuration
            $context = \context_course::instance($courseid);
            $fields = \filter_autotranslate\tagging_manager::get_fields_to_tag('course', $context->contextlevel);

            // Tag fields
            \filter_autotranslate\tagging_manager::tag_fields('course', $course, $fields, $context, $courseid);
        } catch (\Exception $e) {
            // Silently handle the error for now
        }
    }

    /**
     * Handle course updated event.
     *
     * @param \core\event\course_updated $event
     */
    public static function course_updated(\core\event\course_updated $event) {
        global $DB;

        try {
            $data = $event->get_data();
            $courseid = $data['courseid'];
            if (!$courseid) {
                return;
            }

            $course = $DB->get_record('course', ['id' => $courseid]);
            if (!$course) {
                return;
            }

            // Get the fields to tag from the tagging configuration
            $context = \context_course::instance($courseid);
            $fields = \filter_autotranslate\tagging_manager::get_fields_to_tag('course', $context->contextlevel);

            // Tag fields
            $updated = \filter_autotranslate\tagging_manager::tag_fields('course', $course, $fields, $context, $courseid);

            if ($updated) {
                \filter_autotranslate\helper::mark_translations_for_revision('course', $courseid, $fields, $context);
            }
        } catch (\Exception $e) {
            // Silently handle the error for now
        }
    }

    /**
     * Handle course section created event.
     *
     * @param \core\event\course_section_created $event
     */
    public static function course_section_created(\core\event\course_section_created $event) {
        global $DB;

        try {
            $sectionid = $event->objectid;
            if ($sectionid === null) {
                return;
            }

            $section = $DB->get_record('course_sections', ['id' => $sectionid]);
            if (!$section || !$section->course) {
                return;
            }

            // Get the fields to tag from the tagging configuration
            $context = \context_course::instance($section->course);
            $fields = \filter_autotranslate\tagging_manager::get_fields_to_tag('course_sections', $context->contextlevel);

            // Tag fields
            \filter_autotranslate\tagging_manager::tag_fields('course_sections', $section, $fields, $context, $section->course);
        } catch (\Exception $e) {
            // Silently handle the error for now
        }
    }

    /**
     * Handle course section updated event.
     *
     * @param \core\event\course_section_updated $event
     */
    public static function course_section_updated(\core\event\course_section_updated $event) {
        global $DB;

        try {
            $sectionid = $event->objectid;
            if ($sectionid === null) {
                return;
            }

            $section = $DB->get_record('course_sections', ['id' => $sectionid]);
            if (!$section || !$section->course) {
                return;
            }

            // Get the fields to tag from the tagging configuration
            $context = \context_course::instance($section->course);
            $fields = \filter_autotranslate\tagging_manager::get_fields_to_tag('course_sections', $context->contextlevel);

            // Tag fields
            $updated = \filter_autotranslate\tagging_manager::tag_fields('course_sections', $section, $fields, $context, $section->course);

            if ($updated) {
                \filter_autotranslate\helper::mark_translations_for_revision('course_sections', $sectionid, $fields, $context);
            }
        } catch (\Exception $e) {
            // Silently handle the error for now
        }
    }

    /**
     * Catch-all method for debugging.
     *
     * @param \core\event\base $event
     */
    public static function catch_all(\core\event\base $event) {
        // Silently handle for now
    }
}