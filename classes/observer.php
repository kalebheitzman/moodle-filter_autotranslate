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
            \filter_autotranslate\tagging_manager::process_secondary_tables($modulename, $instanceid, $secondary_tables, $context, $cm->course, true);

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
            \filter_autotranslate\tagging_manager::process_secondary_tables($modulename, $instanceid, $secondary_tables, $context, $cm->course, true);

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
     * Handle course deleted event.
     *
     * @param \core\event\course_deleted $event
     */
    public static function course_deleted(\core\event\course_deleted $event) {
        global $DB;

        try {
            $courseid = $event->courseid;
            if (!$courseid) {
                return;
            }

            // Remove all entries in autotranslate_hid_cids for this course
            $DB->delete_records('autotranslate_hid_cids', ['courseid' => $courseid]);
        } catch (\Exception $e) {
            // Silently handle the error for now
        }
    }

    /**
     * Handle course module deleted event.
     *
     * @param \core\event\course_module_deleted $event
     */
    public static function course_module_deleted(\core\event\course_module_deleted $event) {
        global $DB;

        // Log to a file for debugging
        $logfile = '/tmp/autotranslate_debug.log';
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($logfile, "[$timestamp] Course module deleted event triggered\n", FILE_APPEND);

        try {
            $data = $event->get_data();
            $cmid = $data['contextinstanceid'];
            $modulename = $data['other']['modulename'];
            $instanceid = $data['other']['instanceid'];
            $courseid = $event->courseid;

            $msg = "Course module deleted: cmid=$cmid, modulename=$modulename, instanceid=$instanceid, courseid=$courseid";
            mtrace($msg);
            file_put_contents($logfile, "[$timestamp] $msg\n", FILE_APPEND);

            if (!$courseid || !$instanceid) {
                $msg = "Missing courseid or instanceid, skipping.";
                mtrace($msg);
                file_put_contents($logfile, "[$timestamp] $msg\n", FILE_APPEND);
                return;
            }

            // Use the course context instead of the module context, since the module is already deleted
            $context = \context_course::instance($courseid);
            $fields = \filter_autotranslate\tagging_manager::get_fields_to_tag($modulename, CONTEXT_MODULE);
            $msg = "Fields to process for $modulename: " . implode(', ', $fields);
            mtrace($msg);
            file_put_contents($logfile, "[$timestamp] $msg\n", FILE_APPEND);

            // Fetch the module record (snapshot since it’s already deleted)
            $module = $event->get_record_snapshot($modulename, $instanceid);
            $hashes = [];
            if ($module) {
                // Collect hashes from the primary table fields if the snapshot is available
                foreach ($fields as $field) {
                    if (empty($module->$field)) {
                        $msg = "Field $field is empty in $modulename, skipping.";
                        mtrace($msg);
                        file_put_contents($logfile, "[$timestamp] $msg\n", FILE_APPEND);
                        continue;
                    }
                    $hash = \filter_autotranslate\helper::extract_hash($module->$field);
                    if ($hash) {
                        $msg = "Found hash in $modulename, field $field: $hash";
                        mtrace($msg);
                        file_put_contents($logfile, "[$timestamp] $msg\n", FILE_APPEND);
                        $hashes[$hash] = true;
                    } else {
                        $msg = "No hash found in $modulename, field $field: " . substr($module->$field, 0, 50) . "...";
                        mtrace($msg);
                        file_put_contents($logfile, "[$timestamp] $msg\n", FILE_APPEND);
                    }
                }

                // Process secondary tables to collect hashes
                $secondary_tables = \filter_autotranslate\tagging_manager::get_secondary_tables($modulename, CONTEXT_MODULE);
                $msg = "Secondary tables to process: " . implode(', ', array_keys($secondary_tables));
                mtrace($msg);
                file_put_contents($logfile, "[$timestamp] $msg\n", FILE_APPEND);

                foreach ($secondary_tables as $secondary_table => $secondary_fields) {
                    $msg = "Processing secondary table: $secondary_table, fields: " . implode(', ', $secondary_fields);
                    mtrace($msg);
                    file_put_contents($logfile, "[$timestamp] $msg\n", FILE_APPEND);
                    $relationship = \filter_autotranslate\tagging_config::get_relationship_details($secondary_table);

                    foreach ($secondary_fields as $field) {
                        // Use the version of the query that does not include course_modules
                        list($sql, $params) = \filter_autotranslate\tagging_manager::build_secondary_table_query($secondary_table, $field, $modulename, $instanceid, $relationship, false);
                        $msg = "Executing SQL for secondary table $secondary_table, field $field: $sql";
                        mtrace($msg);
                        file_put_contents($logfile, "[$timestamp] $msg\n", FILE_APPEND);
                        $msg = "Parameters: " . json_encode($params);
                        mtrace($msg);
                        file_put_contents($logfile, "[$timestamp] $msg\n", FILE_APPEND);

                        $records = $DB->get_records_sql($sql, $params);
                        $msg = "Records fetched for secondary table $secondary_table, field $field: " . count($records);
                        mtrace($msg);
                        file_put_contents($logfile, "[$timestamp] $msg\n", FILE_APPEND);
                        foreach ($records as $record) {
                            $hash = \filter_autotranslate\helper::extract_hash($record->content);
                            if ($hash) {
                                $msg = "Found hash in $secondary_table, field $field: $hash";
                                mtrace($msg);
                                file_put_contents($logfile, "[$timestamp] $msg\n", FILE_APPEND);
                                $hashes[$hash] = true;
                            } else {
                                $msg = "No hash found in $secondary_table, field $field: " . substr($record->content, 0, 50) . "...";
                                mtrace($msg);
                                file_put_contents($logfile, "[$timestamp] $msg\n", FILE_APPEND);
                            }
                        }
                    }
                }
            } else {
                $msg = "Could not fetch module snapshot for $modulename with instanceid $instanceid, attempting to fetch hashes from autotranslate tables.";
                mtrace($msg);
                file_put_contents($logfile, "[$timestamp] $msg\n", FILE_APPEND);

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
                    $msg = "Found hash in autotranslate tables: " . $hash_record->hash;
                    mtrace($msg);
                    file_put_contents($logfile, "[$timestamp] $msg\n", FILE_APPEND);
                }
            }

            // Remove the relationships from autotranslate_hid_cids
            $msg = "Collected hashes: " . (empty($hashes) ? 'none' : implode(', ', array_keys($hashes)));
            mtrace($msg);
            file_put_contents($logfile, "[$timestamp] $msg\n", FILE_APPEND);
            self::remove_hash_course_mappings(array_keys($hashes), $courseid);
        } catch (\Exception $e) {
            $msg = "Error in course_module_deleted: " . $e->getMessage();
            mtrace($msg);
            file_put_contents($logfile, "[$timestamp] $msg\n", FILE_APPEND);
        }
    }

    /**
     * Handle course section deleted event.
     *
     * @param \core\event\course_section_deleted $event
     */
    public static function course_section_deleted(\core\event\course_section_deleted $event) {
        global $DB;

        try {
            $sectionid = $event->objectid;
            $courseid = $event->courseid;

            if (!$sectionid || !$courseid) {
                return;
            }

            // Get the section record (snapshot since it’s already deleted)
            $section = $event->get_record_snapshot('course_sections', $sectionid);
            if (!$section) {
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
                $hash = \filter_autotranslate\helper::extract_hash($section->$field);
                if ($hash) {
                    $hashes[$hash] = true;
                }
            }

            // Remove the relationships from autotranslate_hid_cids
            self::remove_hash_course_mappings(array_keys($hashes), $courseid);
        } catch (\Exception $e) {
            // Silently handle the error for now
        }
    }

    /**
     * Remove hash-course mappings from autotranslate_hid_cids.
     *
     * @param array $hashes Array of hashes to remove
     * @param int $courseid The course ID to remove mappings for
     */
    private static function remove_hash_course_mappings($hashes, $courseid) {
        global $DB;

        $logfile = '/tmp/autotranslate_debug.log';
        $timestamp = date('Y-m-d H:i:s');

        if (empty($hashes) || !$courseid) {
            $msg = "No hashes or courseid provided, skipping removal from autotranslate_hid_cids.";
            mtrace($msg);
            file_put_contents($logfile, "[$timestamp] $msg\n", FILE_APPEND);
            return;
        }

        // Prepare the SQL to delete mappings
        $placeholders = implode(',', array_fill(0, count($hashes), '?'));
        $params = array_merge($hashes, [$courseid]);
        $sql = "DELETE FROM {autotranslate_hid_cids}
                WHERE hash IN ($placeholders)
                AND courseid = ?";
        $msg = "Executing SQL to remove mappings: $sql";
        mtrace($msg);
        file_put_contents($logfile, "[$timestamp] $msg\n", FILE_APPEND);
        $msg = "Parameters: " . json_encode($params);
        mtrace($msg);
        file_put_contents($logfile, "[$timestamp] $msg\n", FILE_APPEND);
        $DB->execute($sql, $params);
    }

    /**
     * Catch-all method for debugging.
     *
     * @param \core\event\base $event
     */
    public static function catch_all(\core\event\base $event) {
        $logfile = '/tmp/autotranslate_debug.log';
        $timestamp = date('Y-m-d H:i:s');
        $msg = "Catch-all event triggered: " . $event->eventname;
        mtrace($msg);
        file_put_contents($logfile, "[$timestamp] $msg\n", FILE_APPEND);
    }
}