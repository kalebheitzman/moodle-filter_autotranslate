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
 * Tag Content Scheduled Task for the Autotranslate Plugin
 *
 * Purpose:
 * This task processes content in Moodle tables, tags it with `{t:hash}` tags, handles MLang
 * processing, stores source text and existing translations, and updates the database with the
 * tagged content. It uses the dynamic matrices from the settings page to determine which tables
 * and fields to process, batching the content to manage load efficiently.
 *
 * Usage:
 * Runs as a scheduled task every 5 minutes by default, processing a subset of records in each run
 * based on the `recordsperrun` and `managelimit` settings, tagging content in the specified tables
 * and fields.
 *
 * Design Decisions:
 * - Uses `content_service` for MLang processing, tagging, and storing translations, ensuring
 *   consistency with the plugin’s core functionality.
 * - Processes records in batches using `managelimit` to manage database load, with a total limit
 *   of `recordsperrun` per run across all tables.
 * - Stores the current table and last processed record ID in `mdl_config_plugins` to resume
 *   processing in the next run.
 * - Provides detailed logging output using `mtrace` to track progress and debug issues.
 * - Employs all lowercase variable names per plugin convention.
 *
 * Dependencies:
 * - `content_service.php`: For processing content, tagging, and storing translations.
 * - `mdl_config_plugins` table: For tracking the current table and last processed record ID.
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_autotranslate\task;

use core\task\scheduled_task;

/**
 * Scheduled task to tag content in Moodle tables for the Autotranslate plugin.
 */
class tagcontent_scheduled_task extends scheduled_task {
    /**
     * Returns the name of the task.
     *
     * @return string The task name for display.
     */
    public function get_name() {
        return get_string('tagcontenttask', 'filter_autotranslate');
    }

    /**
     * Executes the task to tag content in Moodle tables.
     *
     * Processes records in batches, tagging content in the specified tables and fields based on
     * the dynamic matrices from the settings page.
     */
    public function execute() {
        global $DB, $CFG;

        $contentservice = new \filter_autotranslate\content_service($DB);

        // Retrieve settings.
        $recordsperrun = (int)(get_config('filter_autotranslate', 'recordsperrun') ?: 1000);
        $managelimit = (int)(get_config('filter_autotranslate', 'managelimit') ?: 20);

        // Fetch all enabled fields from the settings matrices.
        $fieldoptions = $contentservice->get_field_selection_options();
        $tables = [];

        // Get the database prefix.
        $prefix = $DB->get_prefix();

        // Core non-module contexts (course, course_sections, course_categories).
        foreach (['course', 'course_sections', 'course_categories'] as $contextkey) {
            if (!empty($fieldoptions['core'][$contextkey])) {
                $enabledfields = $contentservice->get_selected_fields($contextkey);
                foreach ($fieldoptions['core'][$contextkey] as $table => $fields) {
                    // Remove the prefix from the table name.
                    $unprefixedtable = str_replace($prefix, '', $table);
                    $tables[$unprefixedtable] = array_map(function($key) {
                        // Extract the field name (e.g., 'fullname' from 'mdl_course.fullname').
                        return explode('.', $key)[1];
                    }, array_keys(array_filter($enabledfields, function($value, $key) use ($table) {
                        return $value && strpos($key, "$table.") === 0;
                    }, ARRAY_FILTER_USE_BOTH)));
                }
            }
        }

        // Core modules.
        if (!empty($fieldoptions['core']['modules'])) {
            foreach ($fieldoptions['core']['modules'] as $modname => $modtables) {
                if (!empty($modtables)) {
                    $enabledfields = $contentservice->get_selected_fields($modname);
                    foreach ($modtables as $table => $fields) {
                        // Remove the prefix from the table name.
                        $unprefixedtable = str_replace($prefix, '', $table);
                        $tables[$unprefixedtable] = array_map(function($key) {
                            // Extract the field name (e.g., 'name' from 'mdl_forum.name').
                            return explode('.', $key)[1];
                        }, array_keys(array_filter($enabledfields, function($value, $key) use ($table) {
                            return $value && strpos($key, "$table.") === 0;
                        }, ARRAY_FILTER_USE_BOTH)));
                    }
                }
            }
        }

        // Third-party modules.
        if (!empty($fieldoptions['thirdparty'])) {
            foreach ($fieldoptions['thirdparty'] as $modname => $modtables) {
                if (!empty($modtables)) {
                    $enabledfields = $contentservice->get_selected_fields($modname);
                    foreach ($modtables as $table => $fields) {
                        // Remove the prefix from the table name.
                        $unprefixedtable = str_replace($prefix, '', $table);
                        $tables[$unprefixedtable] = array_map(function($key) {
                            // Extract the field name (e.g., 'name' from 'mdl_bigbluebuttonbn.name').
                            return explode('.', $key)[1];
                        }, array_keys(array_filter($enabledfields, function($value, $key) use ($table) {
                            return $value && strpos($key, "$table.") === 0;
                        }, ARRAY_FILTER_USE_BOTH)));
                    }
                }
            }
        }

        // Remove tables with no enabled fields.
        $tables = array_filter($tables);

        if (empty($tables)) {
            mtrace("No tables with enabled fields to process.");
            return;
        }

        // Sort tables alphabetically for consistent processing order.
        ksort($tables);
        $tablelist = array_keys($tables);

        // Load the current table and last processed record ID.
        $currenttable = get_config('filter_autotranslate', 'tagcontent_current_table') ?: '';
        $currentid = (int)(get_config('filter_autotranslate', 'tagcontent_current_id') ?: 0);

        // Adjust the current table to remove the prefix if necessary (for backward compatibility).
        $currenttable = str_replace($prefix, '', $currenttable);

        // Determine the starting table.
        $startindex = 0;
        if ($currenttable && in_array($currenttable, $tablelist)) {
            $startindex = array_search($currenttable, $tablelist);
        }

        $totalprocessed = 0;
        $starttime = date('Y-m-d H:i:s');

        // Log the start of the task.
        mtrace("Starting Tag Content Task at $starttime. recordsperrun: $recordsperrun, managelimit: $managelimit, " .
               "starting from table: " . ($currenttable ?: $tablelist[0]) . ", id: $currentid");

        // Process each table starting from the current table.
        for ($i = $startindex; $i < count($tablelist); $i++) {
            $table = $tablelist[$i];
            $fields = $tables[$table];

            if ($totalprocessed >= $recordsperrun) {
                mtrace("Reached total record limit of $recordsperrun. " .
                       "Stopped at table: $table, last id: $currentid, total processed: $totalprocessed");
                set_config('tagcontent_current_table', $table, 'filter_autotranslate');
                set_config('tagcontent_current_id', $currentid, 'filter_autotranslate');
                return;
            }

            // Skip tables without an 'id' column.
            if (!array_key_exists('id', $DB->get_columns($table))) {
                mtrace("Skipping table $table: No 'id' column found.");
                continue;
            }

            // Log the start of processing for this table.
            mtrace("Processing table: $table, starting from id: $currentid");

            // Fetch the next batch of records.
            while ($totalprocessed < $recordsperrun) {
                $records = $DB->get_records_select(
                    $table,
                    "id > :lastid",
                    ['lastid' => $currentid],
                    'id ASC',
                    'id, ' . implode(', ', $fields),
                    0,
                    $managelimit
                );

                $count = count($records);
                if ($count == 0) {
                    // End of table reached.
                    $currentid = 0;
                    $nextindex = $i + 1;
                    $nexttable = $nextindex < count($tablelist) ? $tablelist[$nextindex] : '';
                    mtrace("Finished table: $table, moving to next table: " . ($nexttable ?: 'none'));
                    set_config('tagcontent_current_table', $nexttable, 'filter_autotranslate');
                    set_config('tagcontent_current_id', 0, 'filter_autotranslate');
                    break;
                }

                // Process each record in the batch.
                foreach ($records as $record) {
                    try {
                        // Determine the context and course ID based on the table.
                        $context = null;
                        $courseid = 0;

                        if ($table === 'course') {
                            $context = \context_course::instance($record->id);
                            $courseid = $record->id;
                        } else if ($table === 'course_sections') {
                            $section = $DB->get_record('course_sections', ['id' => $record->id], 'course', MUST_EXIST);
                            $context = \context_course::instance($section->course);
                            $courseid = $section->course;
                        } else if ($table === 'course_categories') {
                            $context = \context_coursecat::instance($record->id);
                        } else {
                            // Module table (primary or secondary, e.g., forum, forum_posts).
                            $instanceid = $record->id;
                            $modname = $table;

                            // For primary tables (e.g., forum), the instanceid is the record id.
                            // For secondary tables (e.g., forum_posts), we need to find the primary record.
                            if ($table === $modname) {
                                // Primary table.
                                $cm = $DB->get_record_sql(
                                    "SELECT cm.id, cm.course
                                     FROM {course_modules} cm
                                     JOIN {modules} m ON m.id = cm.module
                                     WHERE m.name = :modname AND cm.instance = :instance",
                                    ['modname' => $modname, 'instance' => $instanceid]
                                );
                            } else {
                                // Secondary table (e.g., forum_posts, forum_discussions).
                                // Infer the module name and relationship.
                                $parts = explode('_', $modname);
                                $modname = $parts[0]; // e.g., 'forum' from 'forum_posts'.
                                if ($table === 'forum_posts') {
                                    $post = $DB->get_record('forum_posts', ['id' => $instanceid], 'discussion', MUST_EXIST);
                                    $discussion = $DB->get_record('forum_discussions', ['id' => $post->discussion], 'forum', MUST_EXIST);
                                    $instanceid = $discussion->forum;
                                } else if ($table === 'forum_discussions') {
                                    $discussion = $DB->get_record('forum_discussions', ['id' => $instanceid], 'forum', MUST_EXIST);
                                    $instanceid = $discussion->forum;
                                } else {
                                    // Generic fallback: assume the table name follows the pattern [modname]_[suffix].
                                    // Fetch the foreign key field (guess based on common patterns).
                                    $columns = $DB->get_columns($table);
                                    $fk = null;
                                    foreach ([$modname . 'id', 'id'] as $possiblefk) {
                                        if (isset($columns[$possiblefk])) {
                                            $fk = $possiblefk;
                                            break;
                                        }
                                    }
                                    if ($fk) {
                                        $parentid = $DB->get_field($table, $fk, ['id' => $instanceid]);
                                        $instanceid = $parentid;
                                    }
                                }

                                $cm = $DB->get_record_sql(
                                    "SELECT cm.id, cm.course
                                     FROM {course_modules} cm
                                     JOIN {modules} m ON m.id = cm.module
                                     WHERE m.name = :modname AND cm.instance = :instance",
                                    ['modname' => $modname, 'instance' => $instanceid]
                                );
                            }

                            if ($cm) {
                                $context = \context_module::instance($cm->id);
                                $courseid = $cm->course;
                            }
                        }

                        if (!$context) {
                            $context = \context_system::instance();
                        }

                        // Process each enabled field.
                        foreach ($fields as $field) {
                            if (!isset($record->$field) || trim($record->$field) === '') {
                                continue;
                            }
                            $originalcontent = $record->$field;
                            $taggedcontent = $contentservice->process_content($originalcontent, $context, $courseid);
                            if ($taggedcontent !== $originalcontent) {
                                $DB->set_field($table, $field, $taggedcontent, ['id' => $record->id]);
                            }
                        }

                        $totalprocessed++;
                        $currentid = $record->id;

                        if ($totalprocessed >= $recordsperrun) {
                            mtrace("Reached total record limit of $recordsperrun. " .
                                   "Stopped at table: $table, last id: $currentid, total processed: $totalprocessed");
                            set_config('tagcontent_current_table', $table, 'filter_autotranslate');
                            set_config('tagcontent_current_id', $currentid, 'filter_autotranslate');
                            return;
                        }
                    } catch (\Exception $e) {
                        mtrace("Error processing record id {$record->id} in table $table: " . $e->getMessage());
                        continue;
                    }
                }

                // Log the batch result.
                mtrace("Processed $count records in table: $table, last id: $currentid, total processed: $totalprocessed");
            }
        }

        // If all tables are processed, reset the state.
        mtrace("Tag Content Task completed. Total records processed: $totalprocessed, " .
               "all tables processed, resetting state.");
        set_config('tagcontent_current_table', '', 'filter_autotranslate');
        set_config('tagcontent_current_id', 0, 'filter_autotranslate');
    }
}
