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
 * Scheduled task to tag content for the Autotranslate plugin.
 *
 * Tags content in Moodle tables with {t:hash} markers, processes MLang tags, and stores
 * translations via `content_service`. Runs every 5 minutes, processing batches based on
 * settings-defined field matrices for core and third-party modules.
 *
 * Features:
 * - Tags content in course, module, and category tables with {t:hash}.
 * - Processes MLang tags and persists tagged content.
 * - Tracks progress across runs using `mdl_config_plugins`.
 *
 * Usage:
 * - Executes every 5 minutes to prepare content for `text_filter`.
 * - Processes up to `recordsperrun` records in `managelimit` batches.
 *
 * Design:
 * - Batches records to manage load, resuming via stored table/ID.
 * - Handles primary and secondary module tables with context resolution.
 * - Logs progress with `mtrace` for debugging.
 *
 * Dependencies:
 * - `content_service.php`: Tags, processes, and stores content.
 * - `mdl_config_plugins`: Stores current table and last processed ID.
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_autotranslate\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/filter/autotranslate/classes/content_service.php');

/**
 * Scheduled task to tag content in Moodle tables.
 */
class tagcontent_scheduled_task extends \core\task\scheduled_task {
    /**
     * Returns the task name.
     *
     * @return string Task name for display in admin interface.
     */
    public function get_name() {
        return get_string('tagcontenttask', 'filter_autotranslate');
    }

    /**
     * Tags content in Moodle tables with {t:hash}.
     *
     * Processes batches of records from settings-defined tables, tagging content with
     * {t:hash} via `content_service`. Resumes from the last table/ID stored in config.
     */
    public function execute() {
        global $DB, $CFG;

        $contentservice = new \filter_autotranslate\content_service($DB);

        // Retrieve settings.
        $recordsperrun = (int)(get_config('filter_autotranslate', 'recordsperrun') ?: 1000);
        $managelimit = (int)(get_config('filter_autotranslate', 'managelimit') ?: 20);

        // Check for question reference tables (Moodle 4.5+).
        $questionreferencestablesexist = $DB->get_manager()->table_exists('question_references') &&
                                         $DB->get_manager()->table_exists('question_versions') &&
                                         $DB->get_manager()->table_exists('question_bank_entries');
        if (!$questionreferencestablesexist) {
            mtrace("Warning: Question reference tables missing. Skipping question tables.");
        }

        // Fetch enabled fields from settings matrices.
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
                    $tables[$unprefixedtable] = array_map(function ($key) {
                        // Extract the field name (e.g., 'fullname' from 'mdl_course.fullname').
                        return explode('.', $key)[1];
                    }, array_keys(array_filter($enabledfields, function ($value, $key) use ($table) {
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
                        $tables[$unprefixedtable] = array_map(function ($key) {
                            // Extract the field name (e.g., 'name' from 'mdl_forum.name').
                            return explode('.', $key)[1];
                        }, array_keys(array_filter($enabledfields, function ($value, $key) use ($table) {
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
                        $tables[$unprefixedtable] = array_map(function ($key) {
                            // Extract the field name (e.g., 'name' from 'mdl_bigbluebuttonbn.name').
                            return explode('.', $key)[1];
                        }, array_keys(array_filter($enabledfields, function ($value, $key) use ($table) {
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
        mtrace("Starting Tag Content Task at $starttime. recordsperrun: $recordsperrun, " .
               "managelimit: $managelimit, starting from table: " . ($currenttable ?: $tablelist[0]) . ", id: $currentid");

        // Process each table starting from the current table.
        for ($i = $startindex; $i < count($tablelist); $i++) {
            $table = $tablelist[$i];
            $fields = $tables[$table];

            if ($totalprocessed >= $recordsperrun) {
                mtrace("Reached total record limit of $recordsperrun. Stopped at table: $table, " .
                       "last id: $currentid, total processed: $totalprocessed");
                set_config('tagcontent_current_table', $table, 'filter_autotranslate');
                set_config('tagcontent_current_id', $currentid, 'filter_autotranslate');
                return;
            }

            // Skip tables without an 'id' column.
            if (!array_key_exists('id', $DB->get_columns($table))) {
                mtrace("Skipping table $table: No 'id' column found.");
                continue;
            }

            // Skip question and question_answers tables if question reference tables are not usable.
            if (($table === 'question' || $table === 'question_answers') && !$questionreferencestablesexist) {
                mtrace("Skipping table $table: Question reference tables are not usable.");
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

                        // Track tables where context/course ID has been logged to avoid repetition.
                        static $contextlogged = [];

                        if ($table === 'course') {
                            $context = \context_course::instance($record->id);
                            $courseid = $record->id;
                            if (!isset($contextlogged[$table])) {
                                mtrace("Table: $table, Course ID: $courseid");
                                $contextlogged[$table] = true;
                            }
                        } else if ($table === 'course_sections') {
                            $section = $DB->get_record('course_sections', ['id' => $record->id], 'course', MUST_EXIST);
                            $context = \context_course::instance($section->course);
                            $courseid = $section->course;
                            if (!isset($contextlogged[$table])) {
                                mtrace("Table: $table, Course ID: $courseid");
                                $contextlogged[$table] = true;
                            }
                        } else if ($table === 'course_categories') {
                            $context = \context_coursecat::instance($record->id);
                            if (!isset($contextlogged[$table])) {
                                mtrace("Table: $table, Course ID: $courseid (no course ID for categories)");
                                $contextlogged[$table] = true;
                            }
                        } else {
                            // Module table (primary or secondary, e.g., forum, forum_posts).
                            $instanceid = $record->id;
                            $modname = $table;

                            // For primary tables (e.g., forum), the instanceid is the record id.
                            // For secondary tables (e.g., forum_posts), we need to find the primary record.
                            if ($table === 'forum_posts') {
                                $modname = 'forum';
                                $post = $DB->get_record('forum_posts', ['id' => $instanceid], 'discussion', MUST_EXIST);
                                $discussion = $DB->get_record(
                                    'forum_discussions',
                                    ['id' => $post->discussion],
                                    'forum',
                                    MUST_EXIST
                                );
                                $instanceid = $discussion->forum;
                            } else if ($table === 'forum_discussions') {
                                $modname = 'forum';
                                $discussion = $DB->get_record('forum_discussions', ['id' => $instanceid], 'forum', MUST_EXIST);
                                $instanceid = $discussion->forum;
                            } else if ($table === 'glossary_entries') {
                                $modname = 'glossary';
                                $entry = $DB->get_record('glossary_entries', ['id' => $instanceid], 'glossaryid', MUST_EXIST);
                                $instanceid = $entry->glossaryid;
                            } else if ($table === 'question') {
                                // Declare the static variable once for both question and question_answers blocks.
                                static $skippedrecords = 0;
                                $modname = 'quiz';
                                // Find the question bank entry and version for this question.
                                $version = $DB->get_record(
                                    'question_versions',
                                    ['questionid' => $instanceid],
                                    'questionbankentryid',
                                    IGNORE_MISSING
                                );
                                if ($version) {
                                    // Find the question reference for this question bank entry.
                                    $reference = $DB->get_record_sql(
                                        "SELECT qr.itemid
                                        FROM {question_references} qr
                                        WHERE qr.component = 'mod_quiz'
                                        AND qr.questionarea = 'slot'
                                        AND qr.questionbankentryid = :questionbankentryid",
                                        ['questionbankentryid' => $version->questionbankentryid],
                                        IGNORE_MISSING
                                    );
                                    if ($reference) {
                                        // Get the quizid from the quiz_slots record.
                                        $slot = $DB->get_record(
                                            'quiz_slots',
                                            ['id' => $reference->itemid],
                                            'quizid',
                                            IGNORE_MISSING
                                        );
                                        if ($slot) {
                                            $instanceid = $slot->quizid;
                                        } else {
                                            $skippedrecords++;
                                            continue; // Skip if no quiz_slots record is found.
                                        }
                                    } else {
                                        $skippedrecords++;
                                        continue; // Skip if no question reference is found.
                                    }
                                } else {
                                    $skippedrecords++;
                                    continue; // Skip if no question version is found.
                                }
                            } else if ($table === 'question_answers') {
                                $modname = 'quiz';
                                $answer = $DB->get_record('question_answers', ['id' => $instanceid], 'question', MUST_EXIST);
                                // Find the question bank entry and version for this question.
                                $version = $DB->get_record(
                                    'question_versions',
                                    ['questionid' => $answer->question],
                                    'questionbankentryid',
                                    IGNORE_MISSING
                                );
                                if ($version) {
                                    // Find the question reference for this question bank entry.
                                    $reference = $DB->get_record_sql(
                                        "SELECT qr.itemid
                                        FROM {question_references} qr
                                        WHERE qr.component = 'mod_quiz'
                                        AND qr.questionarea = 'slot'
                                        AND qr.questionbankentryid = :questionbankentryid",
                                        ['questionbankentryid' => $version->questionbankentryid],
                                        IGNORE_MISSING
                                    );
                                    if ($reference) {
                                        // Get the quizid from the quiz_slots record.
                                        $slot = $DB->get_record(
                                            'quiz_slots',
                                            ['id' => $reference->itemid],
                                            'quizid',
                                            IGNORE_MISSING
                                        );
                                        if ($slot) {
                                            $instanceid = $slot->quizid;
                                        } else {
                                            $skippedrecords++;
                                            continue; // Skip if no quiz_slots record is found.
                                        }
                                    } else {
                                        $skippedrecords++;
                                        continue; // Skip if no question reference is found.
                                    }
                                } else {
                                    $skippedrecords++;
                                    continue; // Skip if no question version is found.
                                }
                            } else if ($table === 'quiz_sections') {
                                $modname = 'quiz';
                                $section = $DB->get_record('quiz_sections', ['id' => $instanceid], 'quizid', MUST_EXIST);
                                $instanceid = $section->quizid;
                            } else if ($table === 'wiki_pages') {
                                $modname = 'wiki';
                                $page = $DB->get_record('wiki_pages', ['id' => $instanceid], 'subwikiid', MUST_EXIST);
                                $subwiki = $DB->get_record('wiki_subwikis', ['id' => $page->subwikiid], 'wikiid', MUST_EXIST);
                                $instanceid = $subwiki->wikiid;
                            } else {
                                // Generic handling for other secondary tables (e.g., book_chapters, lesson_pages).
                                $parts = explode('_', $modname);
                                $modname = $parts[0]; // Example, 'book' from 'book_chapters'.

                                // Special mapping for certain secondary tables to their correct module names.
                                $tabletomodulemap = [
                                    'workshopform_accumulative' => 'workshop',
                                    // Add other mappings here if needed for future secondary tables.
                                ];
                                if (isset($tabletomodulemap[$table])) {
                                    $modname = $tabletomodulemap[$table];
                                }

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
                                } else {
                                    mtrace("Table: $table, Record ID: {$record->id}, No foreign key found.");
                                    continue; // Skip if no foreign key is found.
                                }
                            }

                            // Fetch the course_modules record with the correct modname and instanceid.
                            $cm = $DB->get_record_sql(
                                "SELECT cm.id, cm.course
                                 FROM {course_modules} cm
                                 JOIN {modules} m ON m.id = cm.module
                                 WHERE m.name = :modname AND cm.instance = :instance",
                                ['modname' => $modname, 'instance' => $instanceid]
                            );

                            // Track tables where course_modules lookup fails to avoid repetitive logging.
                            static $failedcoursemodules = [];

                            if ($cm) {
                                $context = \context_module::instance($cm->id);
                                $courseid = $cm->course;
                                if (!isset($contextlogged[$table])) {
                                    mtrace("Table: $table, Course ID: $courseid (via course_modules)");
                                    $contextlogged[$table] = true;
                                }
                                // Reset the failure flag for this table if a course module is found.
                                unset($failedcoursemodules[$table]);
                            } else {
                                if (!isset($failedcoursemodules[$table])) {
                                    mtrace("Table: $table, No course_modules record found for " .
                                           "modname: $modname, instance: $instanceid");
                                    $failedcoursemodules[$table] = true;
                                }
                                $context = \context_system::instance();
                                if (!isset($contextlogged[$table])) {
                                    mtrace("Table: $table, Using system context (no specific context found)");
                                    $contextlogged[$table] = true;
                                }
                            }
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
                                mtrace("Table: $table, Record ID: {$record->id}, Field: $field, " .
                                       "Tagged content updated, Course ID: $courseid");
                            }
                        }

                        $totalprocessed++;
                        $currentid = $record->id;

                        if ($totalprocessed >= $recordsperrun) {
                            mtrace("Reached total record limit of $recordsperrun. Stopped at table: $table, " .
                                   "last id: $currentid, total processed: $totalprocessed");
                            set_config('tagcontent_current_table', $table, 'filter_autotranslate');
                            set_config('tagcontent_current_id', $currentid, 'filter_autotranslate');
                            return;
                        }
                    } catch (\Exception $e) {
                        mtrace("Error processing record ID: {$record->id} in table: $table - " . $e->getMessage());
                        continue;
                    }
                }

                // Log the batch result and any skipped records for question or question_answers tables.
                if (isset($skippedrecords) && $skippedrecords > 0) {
                    mtrace("Skipped $skippedrecords records in table: $table due to missing question references.");
                    $skippedrecords = 0; // Reset for the next batch.
                }
                mtrace("Processed $count records in table: $table, last id: $currentid, total processed: $totalprocessed");
            }
        }

        // If all tables are processed, reset the state.
        mtrace("Tag Content Task completed. Total records processed: $totalprocessed, all tables processed, resetting state.");
        set_config('tagcontent_current_table', '', 'filter_autotranslate');
        set_config('tagcontent_current_id', 0, 'filter_autotranslate');
    }
}
