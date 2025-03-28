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
 * Content Service for the Autotranslate Plugin
 *
 * Purpose:
 * This file defines the content service for the filter_autotranslate plugin, providing a unified
 * layer for all database operations related to tagging, storing, and managing translations. It
 * processes untagged or stale content from `text_filter`, handles MLang parsing, stores translations
 * in the database, manages course mappings, and supports marking translations stale for lazy
 * rebuilding, ensuring efficient content management without static configurations. It also includes
 * methods for discovering module schemas and building field selection options for settings.
 *
 * Structure:
 * Contains the `content_service` class with properties for `$db` (database access) and `$textutils`
 * (utility functions). Key methods include `process_content` (tagging and storage),
 * `mark_course_stale` (staleness flagging), `store_translation` (database insertion),
 * `update_hash_course_mapping` (course mapping), `rewrite_pluginfile_urls` (URL resolution),
 * `get_all_module_schemas` and `get_field_selection_options` for settings support, and
 * `get_selected_fields` to fetch enabled fields for a specific context or module.
 *
 * Usage:
 * Called by `text_filter` to process untagged or stale text during rendering, by `ui_manager`
 * to mark translations stale for a course, by `settings.php` to generate dynamic field options,
 * and by other components to fetch selected fields for translation.
 *
 * Design Decisions:
 * - Consolidates tagging and translation storage from previous `tagging_service` and
 *   `translation_service` into a single service, reducing overlap and simplifying maintenance.
 * - Implements a lazy rebuild strategy (Option 3) by checking `timereviewed` vs. `timemodified`
 *   to detect stale translations, refreshing them on demand during filtering.
 * - Uses dynamic context and settings (e.g., `selectctx`) instead of static configurations,
 *   eliminating reliance on `tagging_config`.
 * - Wraps database operations in transactions for consistency, with robust error handling to
 *   ensure data integrity.
 * - New schema discovery methods leverage existing `get_module_schema` to avoid duplication.
 * - Caches selected fields to improve performance when fetching field selections.
 *
 * Dependencies:
 * - `text_utils.php`: Provides utility functions for MLang parsing, hash generation, and text checks.
 * - `db/xmldb_filter_autotranslate.xml`: Defines the database schema (`translations`, `hid_cids`).
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_autotranslate;

use filter_autotranslate\text_utils;
use core_component;

/**
 * Service class for handling content-related database operations in the filter_autotranslate plugin.
 */
class content_service {
    /**
     * @var \moodle_database The Moodle database instance for all operations.
     */
    private $db;

    /**
     * @var text_utils The utility instance for text processing and hash generation.
     */
    private $textutils;

    /**
     * Constructor for the content service.
     *
     * Initializes the service with database access and text utilities for content processing.
     *
     * @param \moodle_database $db The Moodle database instance.
     */
    public function __construct(\moodle_database $db) {
        $this->db = $db;
        $this->textutils = new text_utils();
    }

    /**
     * Processes content by tagging and persisting it in the source table.
     *
     * Tags untagged content with a `{t:hash}` tag, persists it in the original Moodle table,
     * reuses existing hashes for identical content, processes MLang tags, and updates course mappings.
     *
     * @param string $content The content to process.
     * @param \context $context The context in which the content appears.
     * @param int $courseid The course ID for mapping.
     * @return string The tagged content (e.g., "Hello {t:abc1234567}").
     */
    public function process_content($content, $context, $courseid) {
        $trimmed = trim($content);
        if (empty($trimmed) || is_numeric($trimmed)) {
            return $content;
        }

        // Check if already tagged.
        $hash = $this->textutils->extract_hash($content);
        if ($hash) {
            $translation = $this->db->get_record('filter_autotranslate_translations', ['hash' => $hash, 'lang' => 'other']);
            if ($translation) {
                $this->update_hash_course_mapping($hash, $courseid);
                return $content;
            }
        }

        // Process MLang tags and prepare content.
        $mlangresult = $this->textutils->process_mlang_tags($trimmed, $context);
        $sourcetext = $mlangresult['source_text'] ?: $trimmed;
        $displaytext = $mlangresult['display_text'] ?: $trimmed;
        $translations = $mlangresult['translations'];

        // Check for existing untagged content to reuse hash.
        $existing = $this->db->get_record_sql(
            "SELECT hash FROM {filter_autotranslate_translations}
             WHERE lang = 'other' AND " . $this->db->sql_compare_text('translated_text') . " = " .
             $this->db->sql_compare_text(':text'),
            ['text' => $sourcetext],
            IGNORE_MULTIPLE
        );
        $hash = $existing ? $existing->hash : $this->textutils->generate_unique_hash();
        $taggedcontent = "$displaytext {t:$hash}";

        // Persist the tagged content dynamically.
        $this->persist_tagged_content($taggedcontent, $sourcetext, $context, $courseid);

        // Rewrite URLs in source text and translations.
        $sourcetext = $this->rewrite_pluginfile_urls($sourcetext, $context, $hash);
        foreach ($translations as $lang => $text) {
            $translations[$lang] = $this->rewrite_pluginfile_urls($text, $context, $hash);
        }

        // Store content in a transaction.
        $transaction = $this->db->start_delegated_transaction();
        try {
            $this->store_translation($hash, 'other', $sourcetext, $context->contextlevel, $courseid, $context);
            foreach ($translations as $lang => $text) {
                $this->store_translation($hash, $lang, $text, $context->contextlevel, $courseid, $context);
            }
            $this->update_hash_course_mapping($hash, $courseid);
            $transaction->allow_commit();
        } catch (\Exception $e) {
            $transaction->rollback($e);
            debugging("Failed to process content: " . $e->getMessage(), DEBUG_DEVELOPER);
            return $content;
        }

        return $taggedcontent;
    }

    /**
     * Persists tagged content back to the source Moodle table based on context.
     *
     * Dynamically traverses Moodle data structures to find the table and field containing the
     * source text, then updates it with the tagged content. Supports core and non-core modules.
     * Uses enabled fields from settings to determine which fields to process.
     *
     * @param string $taggedcontent The content with {t:hash} tag to persist.
     * @param string $sourcetext The original untagged text to match.
     * @param \context $context The context determining the table and record to update.
     * @param int $courseid The course ID for mapping.
     */
    private function persist_tagged_content($taggedcontent, $sourcetext, $context, $courseid) {
        if ($this->textutils->is_tagged($sourcetext)) {
            return; // Skip if source text is already tagged.
        }

        $contextlevel = $context->contextlevel;
        $instanceid = $context->instanceid;

        switch ($contextlevel) {
            case CONTEXT_SYSTEM:
                break;

            case CONTEXT_USER:
                break;

            case CONTEXT_COURSE:
                $course = get_course($instanceid);
                // Get enabled fields for the course table.
                $coursefields = $this->get_selected_fields('course');
                if (!empty($coursefields)) {
                    $fields = [];
                    foreach ($coursefields as $field => $enabled) {
                        if ($enabled && isset($course->$field)) {
                            $fields[$field] = $course->$field;
                        }
                    }
                    if (!empty($fields)) {
                        $this->update_field_if_match('course', 'id', $instanceid, $fields, $sourcetext, $taggedcontent);
                    }
                }

                // Get enabled fields for the course_sections table.
                $sectionfields = $this->get_selected_fields('course_sections');
                if (!empty($sectionfields)) {
                    $sections = $this->db->get_records('course_sections', ['course' => $instanceid]);
                    foreach ($sections as $section) {
                        $fields = [];
                        foreach ($sectionfields as $field => $enabled) {
                            if ($enabled && isset($section->$field)) {
                                $fields[$field] = $section->$field;
                            }
                        }
                        if (!empty($fields)) {
                            $this->update_field_if_match(
                                'course_sections',
                                'id',
                                $section->id,
                                $fields,
                                $sourcetext,
                                $taggedcontent
                            );
                        }
                    }
                }
                break;

            case CONTEXT_MODULE:
                $cm = get_coursemodule_from_id('', $instanceid);
                if ($cm) {
                    $modname = $cm->modname;
                    // Get enabled fields for the module's tables.
                    $modulefields = $this->get_selected_fields($modname);
                    if (empty($modulefields)) {
                        break; // Skip if no fields are enabled.
                    }

                    // Split fields into primary and secondary tables.
                    $prefix = $this->db->get_prefix();
                    $primarytable = $prefix . $modname;
                    $primaryfields = [];
                    $secondaryfields = [];

                    foreach ($modulefields as $key => $enabled) {
                        if (!$enabled) {
                            continue;
                        }
                        list($table, $field) = explode('.', $key, 2);
                        if ($table === $primarytable) {
                            $primaryfields[$field] = 1;
                        } else {
                            $secondaryfields[$table][$field] = 1;
                        }
                    }

                    // Primary table (e.g., mdl_forum).
                    if (!empty($primaryfields)) {
                        $instance = $this->db->get_record($modname, ['id' => $cm->instance]);
                        if ($instance) {
                            $fields = array_intersect_key((array)$instance, $primaryfields);
                            if (!empty($fields)) {
                                $this->update_field_if_match($modname, 'id', $cm->instance, $fields, $sourcetext, $taggedcontent);
                            }
                        }
                    }

                    // Secondary tables (e.g., mdl_forum_posts).
                    foreach ($secondaryfields as $table => $fields) {
                        if (!in_array('id', $this->db->get_columns($table))) {
                            continue; // Skip invalid tables.
                        }
                        $fk = $modname . 'id'; // Guess FK; refine later.
                        $records = $this->db->get_records($table, [$fk => $cm->instance]);
                        foreach ($records as $record) {
                            $recordfields = array_intersect_key((array)$record, $fields);
                            if (!empty($recordfields)) {
                                $this->update_field_if_match($table, 'id', $record->id, $recordfields, $sourcetext, $taggedcontent);
                            }
                        }
                    }
                }
                break;

            case CONTEXT_COURSECAT:
                // Get enabled fields for the course_categories table.
                $categoryfields = $this->get_selected_fields('course_categories');
                if (!empty($categoryfields)) {
                    $category = $this->db->get_record('course_categories', ['id' => $instanceid]);
                    if ($category) {
                        $fields = [];
                        foreach ($categoryfields as $field => $enabled) {
                            if ($enabled && isset($category->$field)) {
                                $fields[$field] = $category->$field;
                            }
                        }
                        if (!empty($fields)) {
                            $this->update_field_if_match(
                                'course_categories',
                                'id',
                                $instanceid,
                                $fields,
                                $sourcetext,
                                $taggedcontent
                            );
                        }
                    }
                }
                break;

            case CONTEXT_BLOCK:
                break;

            default:
                debugging("Unsupported context level $contextlevel for persistence", DEBUG_DEVELOPER);
        }
    }

    /**
     * Gets schemas for all specified modules using get_module_schema.
     *
     * Iterates over a list of module names, retrieves their schemas, and returns a combined array of
     * tables and fields. Used to discover translatable fields for settings matrices.
     *
     * @param array $modulenames List of module names (e.g., ['forum', 'quiz']).
     * @return array Associative array of table => fields (e.g., ['forum' => ['name', 'intro']]).
     */
    public function get_all_module_schemas($modulenames) {
        $schemas = [];

        foreach ($modulenames as $modname) {
            $schema = $this->get_module_schema($modname);
            foreach ($schema as $table => $fields) {
                $schemas[$table] = $fields;
            }
        }

        return $schemas;
    }

    /**
     * Gets field selection options for core and third-party matrices.
     *
     * Discovers all installed modules, separates them into core and third-party, groups tables by
     * context level and module, and returns structured options for settings.php to build matrices.
     *
     * @return array Structured array with 'core' and 'thirdparty' keys, each containing grouped tables.
     */
    public function get_field_selection_options() {
        // Define core non-module tables by context level, with only translatable fields.
        $corenonmodules = [
            'course' => [
                'course' => ['fullname', 'summary'], // Excluded shortname as it's not typically translated.
            ],
            'course_sections' => [
                'course_sections' => ['name', 'summary'],
            ],
            'course_categories' => [
                'course_categories' => ['description'],
            ],
        ];

        // Define core Moodle modules (standard set shipped with Moodle).
        $coremodules = [
            'assign', 'book', 'choice', 'data', 'feedback', 'folder', 'forum', 'glossary', 'label',
            'lesson', 'lti', 'page', 'quiz', 'resource', 'url', 'wiki', 'workshop',
        ];

        // Clear the schema cache to ensure fresh data.
        $cache = \cache::make('filter_autotranslate', 'modschemas');
        $cache->purge();

        // Get all installed modules using the non-deprecated method.
        $installedmodules = array_keys(core_component::get_plugin_list('mod'));

        // Separate into core and third-party modules.
        $coremodulelist = array_intersect($installedmodules, $coremodules);
        $thirdpartymodules = array_diff($installedmodules, $coremodules);

        // Build schemas for core modules.
        $coremoduletables = $this->get_all_module_schemas($coremodulelist);
        $coremodulesgrouped = [];
        $prefix = $this->db->get_prefix();
        foreach ($coremodulelist as $modname) {
            $coremodulesgrouped[$modname] = [];
            foreach ($coremoduletables as $table => $fields) {
                // Check if the table belongs to this module (e.g., mdl_forum, mdl_forum_posts).
                if (strpos($table, $prefix . $modname) === 0) {
                    $coremodulesgrouped[$modname][$table] = $fields;
                }
            }
        }

        // Build schemas for third-party modules.
        $thirdpartymoduletables = $this->get_all_module_schemas($thirdpartymodules);
        $thirdpartygrouped = [];
        foreach ($thirdpartymodules as $modname) {
            $thirdpartygrouped[$modname] = [];
            foreach ($thirdpartymoduletables as $table => $fields) {
                // Check if the table belongs to this module (e.g., mdl_bigbluebuttonbn).
                if (strpos($table, $prefix . $modname) === 0) {
                    $thirdpartygrouped[$modname][$table] = $fields;
                }
            }
        }

        // Combine core non-modules and modules.
        $coreoptions = $corenonmodules;
        $coreoptions['modules'] = $coremodulesgrouped;

        // Sort the grouped tables for consistent display.
        ksort($coreoptions);
        ksort($coremodulesgrouped);
        ksort($thirdpartygrouped);

        return [
            'core' => $coreoptions,
            'thirdparty' => $thirdpartygrouped,
        ];
    }

    /**
     * Gets the enabled fields for a specific context or module from the settings.
     *
     * Retrieves the enabled (checked) fields for a given context identifier (e.g., 'course',
     * 'course_sections', 'forum') from the settings. For modules, returns fields for all related
     * tables (primary and secondary). Results are cached for performance.
     *
     * @param string $contextid The context identifier (e.g., 'course', 'course_sections', 'forum').
     * @return array Array of enabled fields (e.g., ['fullname' => 1, 'summary' => 1] for 'course',
     *               ['mdl_forum.name' => 1, 'mdl_forum_posts.message' => 1] for 'forum').
     */
    public function get_selected_fields($contextid) {
        // Initialize the cache.
        $cache = \cache::make('filter_autotranslate', 'selectedfields');
        $cachekey = "selectedfields_$contextid";

        // Check if the result is cached.
        $cachedfields = $cache->get($cachekey);
        if ($cachedfields !== false) {
            return $cachedfields;
        }

        // Fetch the config for the given context identifier.
        $config = get_config('filter_autotranslate', "{$contextid}_fields");
        $fields = [];

        if ($config !== false && $config !== null) {
            $fields = unserialize($config);
            if (!is_array($fields)) {
                $fields = [];
            }
        }

        // Cache the result, even if empty.
        $cache->set($cachekey, $fields);
        return $fields;
    }

    /**
     * Gets the schema (tables and fields) for a module, caching the result.
     *
     * Dynamically retrieves tables and text fields related to a module (e.g., 'forum', 'label'),
     * ensuring compatibility with MySQL, MariaDB, PostgreSQL, and other databases. Caches the result
     * for performance.
     *
     * @param string $modname The module name (e.g., 'forum', 'label').
     * @return array Associative array of table names to text field names.
     */
    private function get_module_schema($modname) {
        $cache = \cache::make('filter_autotranslate', 'modschemas');
        $key = "schema_$modname";
        $schema = $cache->get($key);

        if ($schema === false) {
            global $CFG;
            $schema = [];
            $prefix = $this->db->get_prefix();
            $pattern = "{$prefix}{$modname}%";

            // Define inclusion and exclusion lists for translatable fields.
            $includefields = [
                'name', 'intro', 'summary', 'description', 'content', 'message',
                'text', 'body', 'title', 'feedback', 'instructions', 'question',
                'answer', 'response', 'comment', 'label', 'value', 'presentation',
            ];
            $excludefields = [
                'id', 'timemodified', 'timecreated',
                'dependvalue', 'configdata', 'path', 'colour', 'activity',
                'attemptreopenmethod', 'pathnew', 'onlinetext', 'commenttext',
                'type', 'format', 'version', 'status', 'grade', 'score',
                'url', 'email', 'phone', 'ip', 'token', 'key', 'secret',
                'password', 'hash', 'signature', 'settings', 'options',
                'metadata', 'attributes', 'params', 'data', 'json',
            ];

            // Get database type (mysql, mariadb, pgsql, etc.).
            $dbtype = strtolower($CFG->dbtype);

            // Fetch tables matching the module pattern.
            if (in_array($dbtype, ['mysql', 'mariadb'])) {
                $sql = "SELECT TABLE_NAME
                        FROM INFORMATION_SCHEMA.TABLES
                        WHERE TABLE_SCHEMA = :dbname
                        AND TABLE_NAME LIKE :pattern";
                $tables = $this->db->get_fieldset_sql($sql, [
                    'dbname' => $CFG->dbname,
                    'pattern' => $pattern,
                ]);
            } else if ($dbtype === 'pgsql') {
                $sql = "SELECT table_name
                        FROM information_schema.tables
                        WHERE table_schema = 'public'
                        AND table_name LIKE :pattern";
                $tables = $this->db->get_fieldset_sql($sql, [
                    'pattern' => $pattern,
                ]);
            } else {
                // Fallback for other databases: Use a simpler query to list tables.
                debugging("Using fallback query for database type '$dbtype'", DEBUG_DEVELOPER);
                $sql = "SHOW TABLES LIKE :pattern";
                $tables = $this->db->get_fieldset_sql($sql, [
                    'pattern' => $pattern,
                ]);
            }

            // Process each table to get text fields.
            foreach ($tables as $table) {
                try {
                    // For MySQL/MariaDB, use DESCRIBE directly to fetch columns, bypassing get_columns.
                    if (in_array($dbtype, ['mysql', 'mariadb'])) {
                        // Do not prepend prefix again; $table already includes it (e.g., mdl_forum).
                        $sql = "DESCRIBE $table";
                        $rawcolumns = $this->db->get_records_sql($sql);
                        $columns = [];
                        foreach ($rawcolumns as $rawcolumn) {
                            $column = new \stdClass();
                            $column->name = $rawcolumn->field;
                            $column->type = strtolower($rawcolumn->type);
                            $columns[$column->name] = $column;
                        }
                    } else {
                        // For other databases, try get_columns with case sensitivity handling.
                        $columns = $this->db->get_columns($table);
                        if (!$columns) {
                            // Try lowercase version to handle case sensitivity.
                            $lowertable = strtolower($table);
                            $columns = $this->db->get_columns($lowertable);
                        }

                        if (!$columns) {
                            debugging("No columns found for table '$table' even after case adjustment", DEBUG_DEVELOPER);
                            continue;
                        }
                    }

                    $fields = [];
                    foreach ($columns as $column) {
                        $type = strtolower($column->type);
                        $name = $column->name;

                        // Check if the field type is TEXT or VARCHAR.
                        if (in_array($type, ['text', 'varchar']) ||
                            (strpos($type, 'text') !== false) ||
                            (strpos($type, 'varchar') !== false)) {
                            // Apply hybrid filtering: must be in includefields and not in excludefields.
                            if (in_array($name, $includefields) && !in_array($name, $excludefields)) {
                                $fields[] = $name;
                            }
                        }
                    }

                    // Store if any translatable fields remain.
                    if ($fields) {
                        $schema[$table] = array_values($fields);
                    }
                } catch (\moodle_exception $e) {
                    debugging("Failed to retrieve columns for table '$table': " . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }

            // Cache the result, even if empty.
            $cache->set($key, $schema);
        }

        return $schema ?: [];
    }

    /**
     * Updates a field in the specified table if the content matches the source text.
     *
     * @param string $table The table to update.
     * @param string $idfield The ID field name (e.g., 'id').
     * @param int $id The ID value to match.
     * @param array $fields Associative array of field names to their values.
     * @param string $sourcetext The original text to match against.
     * @param string $taggedcontent The tagged content to persist.
     */
    private function update_field_if_match($table, $idfield, $id, $fields, $sourcetext, $taggedcontent) {
        foreach ($fields as $field => $value) {
            // Handle null values gracefully.
            $trimmedvalue = $value === null ? '' : trim($value);
            if ($trimmedvalue === $sourcetext) {
                $this->db->set_field($table, $field, $taggedcontent, [$idfield => $id]);
                return;
            }
        }
    }

    /**
     * Marks all translations for a course as stale.
     *
     * Updates the `timemodified` timestamp for translations tied to a course, flagging them as stale
     * for lazy rebuilding when next processed by `text_filter`.
     *
     * @param int $courseid The course ID to mark stale.
     */
    public function mark_course_stale($courseid) {
        $hashes = $this->db->get_fieldset_select('filter_autotranslate_hid_cids', 'hash', 'courseid = ?', [$courseid]);
        if (empty($hashes)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($hashes), '?'));
        $params = array_merge([time()], $hashes);
        $this->db->execute(
            "UPDATE {filter_autotranslate_translations} SET timemodified = ? WHERE hash IN ($placeholders)",
            $params
        );
    }

    /**
     * Updates an existing translation in the database.
     *
     * Modifies the translated text, human status, and timereviewed fields for a specific translation
     * identified by its ID.
     *
     * @param int $id The ID of the translation record to update.
     * @param string $text The updated translated text.
     * @param int $human The human status (0 = auto, 1 = human).
     * @param int $timereviewed The timestamp of the last review.
     */
    public function update_translation($id, $text, $human, $timereviewed) {
        $record = $this->db->get_record('filter_autotranslate_translations', ['id' => $id], '*', MUST_EXIST);
        $record->translated_text = $text;
        $record->human = $human;
        $record->timemodified = time();
        $record->timereviewed = $timereviewed;
        $this->db->update_record('filter_autotranslate_translations', $record);
    }

    /**
     * Stores a translation in the database if it doesn’t exist.
     *
     * Inserts a new translation record into `filter_autotranslate_translations` if no matching
     * hash/language pair exists, ensuring data consistency.
     *
     * @param string $hash The unique hash for the content.
     * @param string $lang The language code (e.g., 'other', 'es').
     * @param string $text The translated or source text.
     * @param int $contextlevel The Moodle context level.
     * @param int $courseid The course ID for mapping.
     * @param \context $context The context for URL rewriting.
     */
    public function store_translation($hash, $lang, $text, $contextlevel, $courseid, $context) {
        if (!$this->db->record_exists('filter_autotranslate_translations', ['hash' => $hash, 'lang' => $lang])) {
            $record = (object)[
                'hash' => $hash,
                'lang' => $lang,
                'translated_text' => is_array($text) ? implode(' ', $text) : $text,
                'contextlevel' => $contextlevel,
                'timecreated' => time(),
                'timemodified' => time(),
                'timereviewed' => time(),
                'human' => 0,
            ];
            $this->db->insert_record('filter_autotranslate_translations', $record);
        }
    }

    /**
     * Updates the hash-to-course mapping in the database.
     *
     * Ensures the `filter_autotranslate_hid_cids` table reflects which courses contain a given hash,
     * avoiding duplicates.
     *
     * @param string $hash The hash to map.
     * @param int $courseid The course ID to associate.
     */
    private function update_hash_course_mapping($hash, $courseid) {
        if (!$hash || !$courseid) {
            return;
        }

        if (!$this->db->record_exists('filter_autotranslate_hid_cids', ['hash' => $hash, 'courseid' => $courseid])) {
            try {
                $this->db->execute(
                    "INSERT INTO {filter_autotranslate_hid_cids} (hash, courseid) VALUES (?, ?)",
                    [$hash, $courseid]
                );
            } catch (\dml_exception $e) {
                debugging("Failed to update hash-course mapping: " . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    /**
     * Rewrites @@PLUGINFILE@@ URLs in content based on context.
     *
     * Converts plugin file placeholders into full URLs using the context, ensuring stored content
     * is display-ready.
     *
     * @param string $content The content to rewrite.
     * @param \context $context The context for URL resolution.
     * @param string $hash The hash for debugging context.
     * @return string The content with rewritten URLs.
     */
    private function rewrite_pluginfile_urls($content, $context, $hash) {
        $component = '';
        $filearea = '';
        $itemid = 0;

        if ($context->contextlevel == CONTEXT_COURSE) {
            $component = 'course';
            $filearea = 'summary';
            $itemid = $context->instanceid;
        } else if ($context->contextlevel == CONTEXT_MODULE) {
            $cm = get_coursemodule_from_id('', $context->instanceid);
            if ($cm) {
                $component = 'mod_' . $cm->modname;
                $filearea = 'intro';
                $itemid = $cm->instance;
            }
        } else if ($context->contextlevel == CONTEXT_BLOCK) {
            $component = 'block_instances';
            $filearea = 'content';
            $itemid = $context->instanceid;
        }

        if ($component && $filearea) {
            return \file_rewrite_pluginfile_urls(
                $content,
                'pluginfile.php',
                $context->id,
                $component,
                $filearea,
                $itemid
            );
        }

        return $content;
    }
}
