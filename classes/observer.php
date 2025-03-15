<?php
namespace filter_autotranslate;

defined('MOODLE_INTERNAL') || die();

class observer {
    private static function log($message) {
        // global $CFG;
        // $logfile = $CFG->dataroot . '/temp/filter_autotranslate_observer.log';
        // $message = "Observer: " . $message . " at " . date('Y-m-d H:i:s') . "\n";
        // file_put_contents($logfile, $message, FILE_APPEND);
    }

    /**
     * Handle course module created event.
     *
     * @param \core\event\course_module_created $event
     */
    public static function course_module_created(\core\event\course_module_created $event) {
        global $DB;

        try {
            static::log("course_module_created triggered for cmid={$event->contextinstanceid}");

            $data = $event->get_data();
            $cmid = $data['contextinstanceid'];
            $modulename = $data['other']['modulename'];

            static::log("Processing module type: $modulename, cmid: $cmid");

            // Fetch the course module to get the instanceid
            $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
            $instanceid = $cm->instance;

            // Fetch the module record using the instanceid
            $module = $DB->get_record($modulename, ['id' => $instanceid]);
            if (!$module) {
                static::log("Module not found for instanceid: $instanceid in table: $modulename");
                return;
            }

            $fields = ['name', 'intro'];
            if ($modulename === 'page') {
                $fields[] = 'content';
            }

            $context = \context_module::instance($cmid);
            static::log("Context level: {$context->contextlevel}");
            static::tag_fields($modulename, $module, $fields, $context);
        } catch (\Exception $e) {
            static::log("Error in course_module_created: " . $e->getMessage());
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
            static::log("course_module_updated triggered for cmid={$event->contextinstanceid}");

            $data = $event->get_data();
            $cmid = $data['contextinstanceid'];
            $modulename = $data['other']['modulename'];

            static::log("Processing module type: $modulename, cmid: $cmid");

            // Fetch the course module to get the instanceid
            $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
            $instanceid = $cm->instance;

            // Fetch the module record using the instanceid
            $module = $DB->get_record($modulename, ['id' => $instanceid]);
            if (!$module) {
                static::log("Module not found for instanceid: $instanceid in table: $modulename");
                return;
            }

            $fields = ['name', 'intro'];
            if ($modulename === 'page') {
                $fields[] = 'content';
            }

            $context = \context_module::instance($cmid);
            static::log("Context level: {$context->contextlevel}");
            static::tag_fields($modulename, $module, $fields, $context);
        } catch (\Exception $e) {
            static::log("Error in course_module_updated: " . $e->getMessage());
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
            static::log("course_updated triggered for courseid={$event->courseid}");

            $data = $event->get_data();
            $courseid = $data['courseid'];

            $course = $DB->get_record('course', ['id' => $courseid]);
            if (!$course) {
                static::log("Course not found for courseid: $courseid");
                return;
            }

            $fields = ['summary'];
            $context = \context_course::instance($courseid);
            static::log("Context level: {$context->contextlevel}");
            static::tag_fields('course', $course, $fields, $context);
        } catch (\Exception $e) {
            static::log("Error in course_updated: " . $e->getMessage());
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
            static::log("course_section_updated triggered for sectionid={$event->objectid}");

            $sectionid = $event->objectid;
            if ($sectionid === null) {
                static::log("No sectionid found in event data");
                return;
            }

            $section = $DB->get_record('course_sections', ['id' => $sectionid]);
            if (!$section) {
                static::log("Section not found for sectionid: $sectionid");
                return;
            }

            $fields = ['name', 'summary'];
            $context = \context_course::instance($section->course);
            static::log("Context level: {$context->contextlevel}");
            static::tag_fields('course_sections', $section, $fields, $context);
        } catch (\Exception $e) {
            static::log("Error in course_section_updated: " . $e->getMessage());
        }
    }

    /**
     * Catch-all method for debugging.
     *
     * @param \core\event\base $event
     */
    public static function catch_all(\core\event\base $event) {
        static::log("Catch-all triggered in observer.php for event: " . $event->eventname);
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
            static::log("Skipping due to context level not in selected contexts: {$context->contextlevel}");
            return false;
        }

        $updated = false;
        foreach ($fields as $field) {
            if (empty($record->$field)) {
                static::log("Field $field is empty for $table record");
                continue;
            }

            $content = $record->$field;
            if (helper::is_tagged($content)) {
                static::log("Field $field already tagged in $table record");
                continue;
            }

            $taggedcontent = helper::process_mlang_tags($content, $context);
            if ($taggedcontent === $content) {
                $taggedcontent = helper::tag_content($content, $context);
            }

            if ($taggedcontent !== $content) {
                $record->$field = $taggedcontent;
                $updated = true;
                static::log("Tagged field $field in $table record");
            }
        }

        if ($updated) {
            $DB->update_record($table, $record);
            static::log("Updated $table record with tagged content");

            // Purge all caches to reflect changes immediately
            \cache_helper::purge_all();
            static::log("Purged all caches after tagging");
        } else {
            static::log("No updates needed for $table record");
        }

        return $updated;
    }
}