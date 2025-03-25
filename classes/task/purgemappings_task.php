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
 * associated with non-existent courses and removes mappings for courses where the hash no longer
 * appears in that course's content. It does NOT remove translations from autotranslate_translations,
 * as translations should be preserved for use in other courses.
 *
 * Design Decisions:
 * - Runs periodically (default: daily) to clean up orphaned mappings.
 * - Processes mappings in batches (default: 1000 per run) to manage database load.
 * - Validates mappings against mdl_autotranslate_translations to ensure hashes exist before searching content.
 * - Uses SQL LIKE queries to find {t:hash} or <t:hash> tags directly in the database, minimizing queries.
 * - Combines field searches into a single query per table to reduce database load.
 * - Groups mappings by course to process each course once, improving efficiency.
 * - Logs high-level actions (e.g., number of mappings removed) using Moodle's mtrace for transparency.
 * - Detailed debugging output (e.g., SQL queries, reasons for removal) is only shown if Moodle's debug level is set to DEBUG_DEVELOPER.
 *   To enable detailed output, set in config.php:
 *     $CFG->debug = (E_ALL | E_STRICT);
 *     $CFG->debugdisplay = 1;
 * - Function names use snake_case (e.g., get_name) to follow Moodle's coding style.
 *
 * Dependencies:
 * - tagging_config.php: For accessing the tables, fields, and relationships to check for tagged content.
 * - helper.php: For utility functions (e.g., extract_hash).
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace filter_autotranslate\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/filter/autotranslate/classes/tagging_config.php');
require_once($CFG->dirroot . '/filter/autotranslate/classes/helper.php');

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
     * Extracts hashes from a piece of content using a regular expression.
     *
     * @param string $content The content to process.
     * @param array $hashes_to_check List of hashes to search for.
     * @return array List of hashes found in the content.
     */
    private function extract_hashes_from_content($content, $hashes_to_check) {
        $hashes = [];
        $debug = debugging(null, DEBUG_DEVELOPER);

        // Use a more flexible regular expression to find {t:hash} or <t:hash> tags
        // Match variations with extra spaces or different encodings
        if (preg_match_all('/\{[\s]*t:[\s]*([a-zA-Z0-9]+)[\s]*\}|<[\s]*t:[\s]*([a-zA-Z0-9]+)[\s]*>/', $content, $matches)) {
            // Combine matches from both plain text and HTML-encoded patterns
            $plain_text_hashes = !empty($matches[1]) ? array_filter($matches[1]) : [];
            $html_encoded_hashes = !empty($matches[2]) ? array_filter($matches[2]) : [];
            $hashes = array_unique(array_merge($plain_text_hashes, $html_encoded_hashes));
        }

        // If no hashes were found, log the content for debugging
        if (empty($hashes) && $debug) {
            foreach ($hashes_to_check as $hash) {
                if (stripos($content, $hash) !== false) {
                    mtrace("Hash $hash found in raw content, but not in expected {t:hash} or <t:hash> format:");
                    mtrace("Content: " . substr($content, 0, 100) . "...");
                }
            }
        }

        return $hashes;
    }

    /**
     * Collects all hashes present in a course's content using SQL LIKE queries.
     *
     * @param int $courseid The ID of the course to check.
     * @param array $hashes_to_check List of hashes to search for in the content.
     * @return array List of hashes found in the course's content.
     */
    private function get_hashes_in_course($courseid, $hashes_to_check) {
        global $DB;

        $found_hashes = [];
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $tagging_config = \filter_autotranslate\tagging_config::get_tagging_config();
        $debug = debugging(null, DEBUG_DEVELOPER);

        // If there are no hashes to check, return an empty array
        if (empty($hashes_to_check)) {
            if ($debug) {
                mtrace("No hashes to check for course $courseid.");
            }
            return $found_hashes;
        }

        // Log the tables being processed
        if ($debug) {
            mtrace("Processing tables for course $courseid:");
            mtrace("Tagging config: " . print_r($tagging_config, true));
        }

        // Check each table and field defined in the tagging configuration
        foreach ($tagging_config as $contextlevel => $tables) {
            if ($debug) {
                mtrace("Processing context level: $contextlevel");
            }

            foreach ($tables as $table_name => $config) {
                if ($debug) {
                    mtrace("Processing table: $table_name");
                }

                $params = [];
                $where_conditions = [];

                // Build the base query to fetch records for this table
                $sql = "SELECT * FROM {{$table_name}} t";
                if ($table_name === 'course') {
                    $where_conditions[] = "t.id = :courseid";
                    $params['courseid'] = $courseid;
                } elseif ($table_name === 'course_sections') {
                    $where_conditions[] = "t.course = :courseid";
                    $params['courseid'] = $courseid;
                } elseif ($contextlevel == CONTEXT_MODULE) {
                    // For module-level tables, join with course_modules to filter by course
                    $sql .= " LEFT JOIN {course_modules} cm ON cm.instance = t.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)";
                    $sql .= " LEFT JOIN {course} c ON c.id = cm.course";
                    $where_conditions[] = "(c.id = :courseid OR c.id IS NULL)";
                    $params['courseid'] = $courseid;
                    $params['modulename'] = $table_name;
                } elseif ($contextlevel == CONTEXT_COURSE) {
                    // For course-level tables, we've already handled course and course_sections
                    // No additional joins needed
                } else {
                    // For system-level tables, no course filter
                    if ($debug) {
                        mtrace("No course filter applied for system-level table $table_name.");
                    }
                }

                // Add LIKE conditions to find records containing any of the hashes directly
                $like_conditions = [];
                foreach ($config['fields'] as $field) {
                    foreach ($hashes_to_check as $index => $hash) {
                        $like_conditions[] = $DB->sql_like('t.' . $field, ":pattern_{$field}_{$index}", false, false);
                        $params["pattern_{$field}_{$index}"] = "%$hash%";
                    }
                }

                if (!empty($like_conditions)) {
                    $where_conditions[] = "(" . implode(" OR ", $like_conditions) . ")";
                }

                if (!empty($where_conditions)) {
                    $sql .= " WHERE " . implode(" AND ", $where_conditions);
                }

                if ($debug) {
                    mtrace("Executing query for table $table_name in course $courseid:");
                    mtrace("SQL: $sql");
                    mtrace("Params: " . print_r($params, true));
                }

                // Fetch records containing hashes
                $records = $DB->get_records_sql($sql, $params);

                // Extract hashes from the matching records
                foreach ($records as $record) {
                    foreach ($config['fields'] as $field) {
                        if (isset($record->$field)) {
                            $field_hashes = $this->extract_hashes_from_content($record->$field, $hashes_to_check);
                            if (!empty($field_hashes) && $debug) {
                                mtrace("Found hashes in $table_name.$field for course $courseid: " . implode(', ', $field_hashes));
                                mtrace("Content: " . substr($record->$field, 0, 100) . "...");
                            }
                            $found_hashes = array_merge($found_hashes, $field_hashes);
                        }
                    }
                }

                // Check secondary tables (if any)
                if (isset($config['secondary'])) {
                    foreach ($config['secondary'] as $secondary_table => $secondary_config) {
                        $params = [];
                        $where_conditions = [];

                        // Build the query to fetch records for the secondary table
                        $relationship = \filter_autotranslate\tagging_config::get_relationship_details($secondary_table);
                        $primary_table = $relationship['primary_table'];
                        $fk = $relationship['fk'];
                        $parent_table = $relationship['parent_table'] ?? null;
                        $parent_fk = $relationship['parent_fk'] ?? null;
                        $grandparent_table = $relationship['grandparent_table'] ?? null;
                        $grandparent_fk = $relationship['grandparent_fk'] ?? null;

                        $sql = "SELECT st.* FROM {{$secondary_table}} st";
                        if ($secondary_table === 'question') {
                            $sql .= " JOIN {question_versions} qv ON qv.questionid = st.id";
                            $sql .= " JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid";
                            $sql .= " JOIN {question_references} qr ON qr.questionbankentryid = qbe.id";
                            $sql .= " JOIN {quiz_slots} qs ON qs.id = qr.itemid";
                            $sql .= " JOIN {{$primary_table}} pt ON pt.id = qs.quizid";
                            $where_conditions[] = "qr.component = :component";
                            $where_conditions[] = "qr.questionarea = :questionarea";
                            $params['component'] = 'mod_quiz';
                            $params['questionarea'] = 'slot';
                        } else if ($secondary_table === 'question_answers') {
                            $sql .= " JOIN {question} q ON q.id = st.question";
                            $sql .= " JOIN {question_versions} qv ON qv.questionid = q.id";
                            $sql .= " JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid";
                            $sql .= " JOIN {question_references} qr ON qr.questionbankentryid = qbe.id";
                            $sql .= " JOIN {quiz_slots} qs ON qs.id = qr.itemid";
                            $sql .= " JOIN {{$primary_table}} pt ON pt.id = qs.quizid";
                            $where_conditions[] = "qr.component = :component";
                            $where_conditions[] = "qr.questionarea = :questionarea";
                            $params['component'] = 'mod_quiz';
                            $params['questionarea'] = 'slot';
                        } else if ($secondary_table === 'question_categories') {
                            $sql .= " JOIN {question_bank_entries} qbe ON qbe.questioncategoryid = st.id";
                            $sql .= " JOIN {question_references} qr ON qr.questionbankentryid = qbe.id";
                            $sql .= " JOIN {quiz_slots} qs ON qs.id = qr.itemid";
                            $sql .= " JOIN {{$primary_table}} pt ON pt.id = qs.quizid";
                            $where_conditions[] = "qr.component = :component";
                            $where_conditions[] = "qr.questionarea = :questionarea";
                            $params['component'] = 'mod_quiz';
                            $params['questionarea'] = 'slot';
                        } else {
                            if ($parent_table && $grandparent_table) {
                                $sql .= " JOIN {{$parent_table}} parent ON parent.id = st.{$fk}";
                                $sql .= " JOIN {{$grandparent_table}} gp ON gp.id = parent.{$parent_fk}";
                                $sql .= " JOIN {{$primary_table}} pt ON pt.id = gp.{$grandparent_fk}";
                            } elseif ($parent_table) {
                                $sql .= " JOIN {{$parent_table}} parent ON parent.id = st.{$fk}";
                                $sql .= " JOIN {{$primary_table}} pt ON pt.id = parent.{$parent_fk}";
                            } else {
                                $sql .= " JOIN {{$primary_table}} pt ON pt.id = st.{$fk}";
                            }
                        }

                        if ($primary_table === 'course') {
                            $where_conditions[] = "pt.id = :courseid";
                            $params['courseid'] = $courseid;
                        } elseif ($primary_table === 'course_sections') {
                            $where_conditions[] = "pt.course = :courseid";
                            $params['courseid'] = $courseid;
                        } elseif ($contextlevel == CONTEXT_MODULE) {
                            $sql .= " LEFT JOIN {course_modules} cm ON cm.instance = pt.id AND cm.module = (SELECT id FROM {modules} WHERE name = :modulename)";
                            $sql .= " LEFT JOIN {course} c ON c.id = cm.course";
                            $where_conditions[] = "(c.id = :courseid OR c.id IS NULL)";
                            $params['courseid'] = $courseid;
                            $params['modulename'] = $primary_table;
                        }

                        // Add LIKE conditions to find records containing any of the hashes directly
                        $like_conditions = [];
                        foreach ($secondary_config['fields'] as $field) {
                            foreach ($hashes_to_check as $index => $hash) {
                                $like_conditions[] = $DB->sql_like('st.' . $field, ":pattern_{$field}_{$index}", false, false);
                                $params["pattern_{$field}_{$index}"] = "%$hash%";
                            }
                        }

                        if (!empty($like_conditions)) {
                            $where_conditions[] = "(" . implode(" OR ", $like_conditions) . ")";
                        }

                        if (!empty($where_conditions)) {
                            $sql .= " WHERE " . implode(" AND ", $where_conditions);
                        }

                        if ($debug) {
                            mtrace("Executing query for secondary table $secondary_table in course $courseid:");
                            mtrace("SQL: $sql");
                            mtrace("Params: " . print_r($params, true));
                        }

                        // Fetch records containing hashes
                        $records = $DB->get_records_sql($sql, $params);

                        // Extract hashes from the matching records
                        foreach ($records as $record) {
                            foreach ($secondary_config['fields'] as $field) {
                                if (isset($record->$field)) {
                                    $field_hashes = $this->extract_hashes_from_content($record->$field, $hashes_to_check);
                                    if (!empty($field_hashes) && $debug) {
                                        mtrace("Found hashes in $secondary_table.$field for course $courseid: " . implode(', ', $field_hashes));
                                        mtrace("Content: " . substr($record->$field, 0, 100) . "...");
                                    }
                                    $found_hashes = array_merge($found_hashes, $field_hashes);
                                }
                            }
                        }
                    }
                }
            }
        }

        return array_unique($found_hashes);
    }

    /**
     * Executes the task to purge orphaned hash-course mappings.
     *
     * This method performs three main steps:
     * 1. Removes mappings from autotranslate_hid_cids for non-existent courses.
     * 2. Removes mappings from autotranslate_hid_cids for hashes that do not exist in autotranslate_translations.
     * 3. Removes mappings from autotranslate_hid_cids for courses where the hash no longer appears
     *    in that course's content, preserving translations in autotranslate_translations.
     */
    public function execute() {
        global $DB;

        // Check if debugging is enabled at the developer level
        $debug = debugging(null, DEBUG_DEVELOPER);

        try {
            // Step 1: Remove mappings for non-existent courses
            $sql = "SELECT DISTINCT hc.courseid
                    FROM {autotranslate_hid_cids} hc
                    LEFT JOIN {course} c ON c.id = hc.courseid
                    WHERE c.id IS NULL";
            if ($debug) {
                mtrace("Step 1: Executing query to find non-existent courses:");
                mtrace("SQL: $sql");
            }
            $orphaned_courseids = $DB->get_fieldset_sql($sql);
            if (!empty($orphaned_courseids)) {
                list($insql, $inparams) = $DB->get_in_or_equal($orphaned_courseids, SQL_PARAMS_NAMED);
                $DB->delete_records_select('autotranslate_hid_cids', "courseid $insql", $inparams);
                mtrace("Removed " . count($orphaned_courseids) . " orphaned course mappings from autotranslate_hid_cids.");
            } else {
                mtrace("No orphaned course mappings found in Step 1.");
            }

            // Step 2: Remove mappings for hashes that do not exist in autotranslate_translations
            $sql = "SELECT DISTINCT hc.hash, hc.courseid
                    FROM {autotranslate_hid_cids} hc
                    LEFT JOIN {autotranslate_translations} at ON at.hash = hc.hash
                    WHERE at.hash IS NULL";
            if ($debug) {
                mtrace("Step 2: Executing query to find mappings for non-existent hashes:");
                mtrace("SQL: $sql");
            }
            $orphaned_mappings = $DB->get_records_sql($sql);
            if (!empty($orphaned_mappings)) {
                foreach ($orphaned_mappings as $mapping) {
                    $DB->delete_records('autotranslate_hid_cids', ['hash' => $mapping->hash, 'courseid' => $mapping->courseid]);
                    if ($debug) {
                        mtrace("Removed mapping for hash {$mapping->hash} in course {$mapping->courseid}: hash not found in autotranslate_translations.");
                    }
                }
                mtrace("Removed " . count($orphaned_mappings) . " mappings for hashes not found in autotranslate_translations.");
            } else {
                mtrace("No mappings found for non-existent hashes in Step 2.");
            }

            // Step 3: Remove mappings for courses where the hash no longer appears
            // Get a batch of mappings to process (limit to 1000 to manage load)
            $batch_size = 1000;
            if ($debug) {
                mtrace("Step 3: Fetching batch of mappings (limit: $batch_size)");
            }
            $mappings = $DB->get_records('autotranslate_hid_cids', null, '', 'hash, courseid', 0, $batch_size);
            if (empty($mappings)) {
                mtrace("No mappings to process in this run.");
                return;
            }

            mtrace("Processing " . count($mappings) . " mappings...");
            $orphaned_mappings = [];

            // Group mappings by courseid for efficiency
            $mappings_by_course = [];
            foreach ($mappings as $mapping) {
                $courseid = $mapping->courseid;
                $hash = $mapping->hash;
                if (!isset($mappings_by_course[$courseid])) {
                    $mappings_by_course[$courseid] = [];
                }
                $mappings_by_course[$courseid][$hash] = $mapping;
            }

            // Process each course
            foreach ($mappings_by_course as $courseid => $course_mappings) {
                try {
                    // Get the list of hashes to check for this course
                    $hashes_to_check = array_keys($course_mappings);

                    // Get all hashes present in the course's content
                    $hashes_in_course = $this->get_hashes_in_course($courseid, $hashes_to_check);
                    $hashes_in_course = array_flip($hashes_in_course); // For faster lookup

                    // Check each mapping for this course
                    foreach ($course_mappings as $hash => $mapping) {
                        if (!isset($hashes_in_course[$hash])) {
                            // Hash is not found in the course's content
                            $orphaned_mappings[] = $mapping;
                            if ($debug) {
                                mtrace("Hash $hash not found in course $courseid content, marking for removal.");
                            }
                        } else {
                            if ($debug) {
                                mtrace("Hash $hash found in course $courseid content, preserving mapping.");
                            }
                        }
                    }
                } catch (\Exception $e) {
                    mtrace("Error processing course $courseid: " . $e->getMessage());
                    continue;
                }
            }

            // Remove orphaned mappings
            if (!empty($orphaned_mappings)) {
                foreach ($orphaned_mappings as $mapping) {
                    $DB->delete_records('autotranslate_hid_cids', ['hash' => $mapping->hash, 'courseid' => $mapping->courseid]);
                }
                mtrace("Removed " . count($orphaned_mappings) . " course-specific orphaned mappings from autotranslate_hid_cids.");
            } else {
                mtrace("No course-specific orphaned mappings found in this run.");
            }
        } catch (\Exception $e) {
            mtrace("Error in purgemappings_task: " . $e->getMessage());
        }
    }
}