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
            mtrace("course_module_created triggered for cmid={$event->contextinstanceid}");

            $data = $event->get_data();
            $cmid = $data['contextinstanceid'];
            $modulename = $data['other']['modulename'];

            mtrace("Processing module type: $modulename, cmid: $cmid");

            // Fetch the course module to get the instanceid
            $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
            $instanceid = $cm->instance;

            // Fetch the module record using the instanceid
            $module = $DB->get_record($modulename, ['id' => $instanceid]);
            if (!$module) {
                mtrace("Module not found for instanceid: $instanceid in table: $modulename");
                return;
            }

            $fields = ['name', 'intro'];
            if ($modulename === 'page') {
                $fields[] = 'content';
            }

            $context = \context_module::instance($cmid);
            mtrace("Context level: {$context->contextlevel}");
            static::tag_fields('mod_' . $modulename, $module, $fields, $context);
        } catch (\Exception $e) {
            mtrace("Error in course_module_created: " . $e->getMessage());
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
            mtrace("course_module_updated triggered for cmid={$event->contextinstanceid}");

            $data = $event->get_data();
            $cmid = $data['contextinstanceid'];
            $modulename = $data['other']['modulename'];

            mtrace("Processing module type: $modulename, cmid: $cmid");

            // Fetch the course module to get the instanceid
            $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
            $instanceid = $cm->instance;

            // Fetch the module record using the instanceid
            $module = $DB->get_record($modulename, ['id' => $instanceid]);
            if (!$module) {
                mtrace("Module not found for instanceid: $instanceid in table: $modulename");
                return;
            }

            $fields = ['name', 'intro'];
            if ($modulename === 'page') {
                $fields[] = 'content';
            }

            $context = \context_module::instance($cmid);
            mtrace("Context level: {$context->contextlevel}");
            $updated = static::tag_fields('mod_' . $modulename, $module, $fields, $context);

            // If the fields were updated, mark existing translations as needing revision
            if ($updated) {
                static::mark_translations_for_revision($modulename, $instanceid, $fields, $context);
            }
        } catch (\Exception $e) {
            mtrace("Error in course_module_updated: " . $e->getMessage());
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
            mtrace("course_updated triggered for courseid={$event->courseid}");

            $data = $event->get_data();
            $courseid = $data['courseid'];

            $course = $DB->get_record('course', ['id' => $courseid]);
            if (!$course) {
                mtrace("Course not found for courseid: $courseid");
                return;
            }

            $fields = ['summary'];
            $context = \context_course::instance($courseid);
            mtrace("Context level: {$context->contextlevel}");
            $updated = static::tag_fields('course', $course, $fields, $context);

            // If the fields were updated, mark existing translations as needing revision
            if ($updated) {
                static::mark_translations_for_revision('course', $courseid, $fields, $context);
            }
        } catch (\Exception $e) {
            mtrace("Error in course_updated: " . $e->getMessage());
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
            mtrace("course_section_updated triggered for sectionid={$event->objectid}");

            $sectionid = $event->objectid;
            if ($sectionid === null) {
                mtrace("No sectionid found in event data");
                return;
            }

            $section = $DB->get_record('course_sections', ['id' => $sectionid]);
            if (!$section) {
                mtrace("Section not found for sectionid: $sectionid");
                return;
            }

            $fields = ['name', 'summary'];
            $context = \context_course::instance($section->course);
            mtrace("Context level: {$context->contextlevel}");
            $updated = static::tag_fields('course_sections', $section, $fields, $context);

            // If the fields were updated, mark existing translations as needing revision
            if ($updated) {
                static::mark_translations_for_revision('course_sections', $sectionid, $fields, $context);
            }
        } catch (\Exception $e) {
            mtrace("Error in course_section_updated: " . $e->getMessage());
        }
    }

    /**
     * Catch-all method for debugging.
     *
     * @param \core\event\base $event
     */
    public static function catch_all(\core\event\base $event) {
        mtrace("Catch-all triggered in observer.php for event: " . $event->eventname);
    }

    /**
     * Tag fields in a record and update the database.
     *
     * @param string $table Table name
     * @param object $record Record object
     * @param array $fields Fields to tag
     * @param \context $context Context object
     * @return bool Whether the record was updated
     */
    private static function tag_fields($table, $record, $fields, $context) {
        global $DB;

        $selectedctx = get_config('filter_autotranslate', 'selectctx');
        $selectedctx = $selectedctx ? array_map('trim', explode(',', $selectedctx)) : ['40', '50', '70', '80'];
        if (!in_array((string)$context->contextlevel, $selectedctx)) {
            mtrace("Skipping due to context level not in selected contexts: {$context->contextlevel}");
            return false;
        }

        // Get the configured tables and fields
        $tables = \filter_autotranslate\tagging_config::get_default_tables();
        $tagging_options_raw = get_config('filter_autotranslate', 'tagging_config');

        // Convert the tagging options to an array of selected options
        $tagging_options = [];
        if ($tagging_options_raw !== false && $tagging_options_raw !== null && $tagging_options_raw !== '') {
            if (is_string($tagging_options_raw)) {
                // If it's a comma-separated string, explode it into an array
                $tagging_options = array_map('trim', explode(',', $tagging_options_raw));
            } elseif (is_array($tagging_options_raw)) {
                // If it's already an array (e.g., Moodle decoded the JSON), use it directly
                $tagging_options = $tagging_options_raw;
            }
        }

        // Convert the array of selected options into a key-value map for easier lookup
        $tagging_options_map = [];
        foreach ($tagging_options as $option) {
            $tagging_options_map[$option] = true;
        }

        // Filter the fields based on the tagging configuration
        $filtered_fields = [];
        foreach ($fields as $field) {
            $key = "ctx{$context->contextlevel}_{$table}_{$field}";
            if (isset($tagging_options_map[$key])) {
                $filtered_fields[] = $field;
            } else {
                mtrace("Field $field in table $table (context level {$context->contextlevel}) is disabled in tagging configuration, skipping.");
            }
        }

        if (empty($filtered_fields)) {
            mtrace("No fields to tag for table $table after applying tagging configuration.");
            return false;
        }

        $updated = false;
        foreach ($filtered_fields as $field) {
            if (empty($record->$field)) {
                mtrace("Field $field is empty for $table record");
                continue;
            }

            $content = $record->$field;
            if (helper::is_tagged($content)) {
                mtrace("Field $field already tagged in $table record");
                continue;
            }

            $taggedcontent = helper::process_mlang_tags($content, $context);
            if ($taggedcontent === $content) {
                $taggedcontent = helper::tag_content($content, $context);
            }

            if ($taggedcontent !== $content) {
                $record->$field = $taggedcontent;
                $updated = true;
                mtrace("Tagged field $field in $table record");
            }
        }

        if ($updated) {
            $record->timemodified = time(); // Update timemodified when content is tagged
            $DB->update_record($table, $record);
            mtrace("Updated $table record with tagged content");

            // Purge caches for the specific context
            $context->purge();
            mtrace("Purged caches for context ID {$context->id} (level {$context->contextlevel})");
        } else {
            mtrace("No updates needed for $table record");
        }

        return $updated;
    }

    /**
     * Mark translations as needing revision by updating timemodified.
     *
     * @param string $table Table name (e.g., 'course', 'course_sections', 'mod_*')
     * @param int $instanceid Instance ID (e.g., course ID, section ID, module instance ID)
     * @param array $fields Fields that were updated (e.g., ['name', 'intro'])
     * @param \context $context Context object
     */
    private static function mark_translations_for_revision($table, $instanceid, $fields, $context) {
        global $DB;

        mtrace("Marking translations for revision: table=$table, instanceid=$instanceid, fields=" . implode(',', $fields) . ", contextlevel={$context->contextlevel}");

        // Find all hashes associated with the updated fields
        $hashes = [];
        foreach ($fields as $field) {
            $record = $DB->get_record($table, ['id' => $instanceid], $field);
            if (!$record || empty($record->$field)) {
                mtrace("No content found for field $field in $table with instanceid $instanceid");
                continue;
            }

            $content = $record->$field;
            if (preg_match('/{translation hash=([a-zA-Z0-9]{10})}/', $content, $match)) {
                $hash = $match[1];
                $hashes[$hash] = true; // Use array to avoid duplicates
                mtrace("Found hash $hash for field $field in $table with instanceid $instanceid");
            } else {
                mtrace("No hash found in field $field content for $table with instanceid $instanceid");
            }
        }

        if (empty($hashes)) {
            mtrace("No hashes found to mark for revision");
            return;
        }

        // Update timemodified for all translations with these hashes
        $placeholders = implode(',', array_fill(0, count($hashes), '?'));
        $sql = "UPDATE {autotranslate_translations}
                SET timemodified = ?
                WHERE hash IN ($placeholders)
                AND contextlevel = ?";
        $params = array_merge([time()], array_keys($hashes), [$context->contextlevel]);
        $affected = $DB->execute($sql, $params);

        mtrace("Updated timemodified for $affected translations with hashes: " . implode(',', array_keys($hashes)) . " in context {$context->contextlevel}");
    }
}