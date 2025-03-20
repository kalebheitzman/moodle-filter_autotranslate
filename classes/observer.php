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

            // Fetch the module record using the instanceid
            $module = $DB->get_record($modulename, ['id' => $instanceid]);
            if (!$module) {
                return;
            }

            $fields = ['name', 'intro'];
            if ($modulename === 'page') {
                $fields[] = 'content';
            }

            $context = \context_module::instance($cmid);
            $updated = static::tag_fields($modulename, $module, $fields, $context);

            // If the fields were updated, store the hash-to-course ID mapping and create/update translation records
            if ($updated) {
                foreach ($fields as $field) {
                    if (empty($module->$field)) {
                        continue;
                    }
                    $content = $module->$field;
                    if (preg_match('/{translation hash=([a-zA-Z0-9]{10})}/', $content, $match)) {
                        $hash = $match[1];
                        static::update_hash_course_mapping($hash, $cm->course);
                        static::create_or_update_source_translation($hash, $module->$field, $context->contextlevel);
                    }
                }
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

            // Fetch the module record using the instanceid
            $module = $DB->get_record($modulename, ['id' => $instanceid]);
            if (!$module) {
                return;
            }

            $fields = ['name', 'intro'];
            if ($modulename === 'page') {
                $fields[] = 'content';
            }

            $context = \context_module::instance($cmid);
            $updated = static::tag_fields($modulename, $module, $fields, $context);

            // If the fields were updated, store the hash-to-course ID mapping and create/update translation records
            if ($updated) {
                foreach ($fields as $field) {
                    if (empty($module->$field)) {
                        continue;
                    }
                    $content = $module->$field;
                    if (preg_match('/{translation hash=([a-zA-Z0-9]{10})}/', $content, $match)) {
                        $hash = $match[1];
                        static::update_hash_course_mapping($hash, $cm->course);
                        static::create_or_update_source_translation($hash, $module->$field, $context->contextlevel);
                    }
                }
                static::mark_translations_for_revision('course_module', $instanceid, $fields, $context);
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

            $course = $DB->get_record('course', ['id' => $courseid]);
            if (!$course) {
                return;
            }

            $fields = ['summary'];
            $context = \context_course::instance($courseid);
            $updated = static::tag_fields('course', $course, $fields, $context);

            // If the fields were updated, store the hash-to-course ID mapping and create/update translation records
            if ($updated) {
                foreach ($fields as $field) {
                    if (empty($course->$field)) {
                        continue;
                    }
                    $content = $course->$field;
                    if (preg_match('/{translation hash=([a-zA-Z0-9]{10})}/', $content, $match)) {
                        $hash = $match[1];
                        static::update_hash_course_mapping($hash, $courseid);
                        static::create_or_update_source_translation($hash, $course->$field, $context->contextlevel);
                    }
                }
            }
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

            $course = $DB->get_record('course', ['id' => $courseid]);
            if (!$course) {
                return;
            }

            $fields = ['summary'];
            $context = \context_course::instance($courseid);
            $updated = static::tag_fields('course', $course, $fields, $context);

            // If the fields were updated, store the hash-to-course ID mapping and create/update translation records
            if ($updated) {
                foreach ($fields as $field) {
                    if (empty($course->$field)) {
                        continue;
                    }
                    $content = $course->$field;
                    if (preg_match('/{translation hash=([a-zA-Z0-9]{10})}/', $content, $match)) {
                        $hash = $match[1];
                        static::update_hash_course_mapping($hash, $courseid);
                        static::create_or_update_source_translation($hash, $course->$field, $context->contextlevel);
                    }
                }
                static::mark_translations_for_revision('course', $courseid, $fields, $context);
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
            if (!$section) {
                return;
            }

            $fields = ['name', 'summary'];
            $context = \context_course::instance($section->course);
            $updated = static::tag_fields('course_sections', $section, $fields, $context);

            // If the fields were updated, store the hash-to-course ID mapping and create/update translation records
            if ($updated) {
                foreach ($fields as $field) {
                    if (empty($section->$field)) {
                        continue;
                    }
                    $content = $section->$field;
                    if (preg_match('/{translation hash=([a-zA-Z0-9]{10})}/', $content, $match)) {
                        $hash = $match[1];
                        static::update_hash_course_mapping($hash, $section->course);
                        static::create_or_update_source_translation($hash, $section->$field, $context->contextlevel);
                    }
                }
            }
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
            if (!$section) {
                return;
            }

            $fields = ['name', 'summary'];
            $context = \context_course::instance($section->course);
            $updated = static::tag_fields('course_sections', $section, $fields, $context);

            // If the fields were updated, store the hash-to-course ID mapping and create/update translation records
            if ($updated) {
                foreach ($fields as $field) {
                    if (empty($section->$field)) {
                        continue;
                    }
                    $content = $section->$field;
                    if (preg_match('/{translation hash=([a-zA-Z0-9]{10})}/', $content, $match)) {
                        $hash = $match[1];
                        static::update_hash_course_mapping($hash, $section->course);
                        static::create_or_update_source_translation($hash, $section->$field, $context->contextlevel);
                    }
                }
                static::mark_translations_for_revision('course_sections', $sectionid, $fields, $context);
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
            }
        }

        if (empty($filtered_fields)) {
            return false;
        }

        $updated = false;
        foreach ($filtered_fields as $field) {
            if (empty($record->$field)) {
                continue;
            }

            $content = $record->$field;
            if (helper::is_tagged($content)) {
                continue;
            }

            $taggedcontent = helper::process_mlang_tags($content, $context);
            if ($taggedcontent === $content) {
                $taggedcontent = helper::tag_content($content, $context);
            }

            if ($taggedcontent !== $content) {
                $record->$field = $taggedcontent;
                $updated = true;
            }
        }

        if ($updated) {
            $record->timemodified = time(); // Update timemodified when content is tagged
            $DB->update_record($table, $record);

            // Mark the context as dirty to refresh caches on the next request
            $context->mark_dirty();
        }

        return $updated;
    }

    /**
     * Create or update the source translation record in mdl_autotranslate_translations.
     *
     * @param string $hash The translation hash
     * @param string $content The tagged content
     * @param int $contextlevel The context level
     */
    private static function create_or_update_source_translation($hash, $content, $contextlevel) {
        global $DB;

        // Extract the source text by removing the {translation} tags
        $source_text = preg_replace('/{translation[^}]*}/', '', $content);
        $source_text = preg_replace('/{\/translation}/', '', $source_text);

        // Check if a source translation record already exists
        $existing = $DB->get_record('autotranslate_translations', ['hash' => $hash, 'lang' => 'other']);
        $current_time = time();

        if ($existing) {
            // Update the existing record
            $existing->translated_text = $source_text;
            $existing->contextlevel = $contextlevel;
            $existing->timemodified = $current_time;
            $existing->timereviewed = $existing->timereviewed == 0 ? $current_time : $existing->timereviewed;
            $existing->human = 1; // Human Reviewed
            $DB->update_record('autotranslate_translations', $existing);
        } else {
            // Create a new record
            $record = new \stdClass();
            $record->hash = $hash;
            $record->lang = 'other';
            $record->translated_text = $source_text;
            $record->contextlevel = $contextlevel;
            $record->timecreated = $current_time;
            $record->timemodified = $current_time;
            $record->timereviewed = $current_time;
            $record->human = 1; // Human Reviewed
            $DB->insert_record('autotranslate_translations', $record);
        }
    }

    /**
     * Mark translations as needing revision by updating timemodified and timereviewed.
     *
     * @param string $table Table name (e.g., 'course', 'course_sections', 'mod_*')
     * @param int $instanceid Instance ID (e.g., course ID, section ID, module instance ID)
     * @param array $fields Fields that were updated (e.g., ['name', 'intro'])
     * @param \context $context Context object
     */
    private static function mark_translations_for_revision($table, $instanceid, $fields, $context) {
        global $DB;

        // Find all hashes associated with the updated fields
        $hashes = [];
        foreach ($fields as $field) {
            $record = $DB->get_record($table, ['id' => $instanceid], $field);
            if (!$record || empty($record->$field)) {
                continue;
            }

            $content = $record->$field;
            if (preg_match('/{translation hash=([a-zA-Z0-9]{10})}/', $content, $match)) {
                $hash = $match[1];
                $hashes[$hash] = true; // Use array to avoid duplicates
            }
        }

        if (empty($hashes)) {
            return;
        }

        // Update timemodified and timereviewed for all translations with these hashes
        $placeholders = implode(',', array_fill(0, count($hashes), '?'));
        $sql = "UPDATE {autotranslate_translations}
                SET timemodified = ?,
                    timereviewed = CASE WHEN timereviewed = 0 THEN ? ELSE timereviewed END
                WHERE hash IN ($placeholders)
                AND contextlevel = ?
                AND lang != 'other'";
        $params = array_merge([time(), time()], array_keys($hashes), [$context->contextlevel]);
        $DB->execute($sql, $params);
    }

    /**
     * Updates the autotranslate_hid_cids table with the hash and courseid mapping.
     *
     * @param string $hash The hash to map
     * @param int $courseid The courseid to map
     */
    private static function update_hash_course_mapping($hash, $courseid) {
        global $DB;

        if (!$hash || !$courseid) {
            return;
        }

        $exists = $DB->record_exists('autotranslate_hid_cids', ['hash' => $hash, 'courseid' => $courseid]);
        if (!$exists) {
            try {
                $DB->execute("INSERT INTO {autotranslate_hid_cids} (hash, courseid) VALUES (?, ?) 
                            ON DUPLICATE KEY UPDATE hash = hash", [$hash, $courseid]);
            } catch (\dml_exception $e) {
                // Silently handle the error for now
            }
        }
    }
}