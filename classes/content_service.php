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
 * Content service for the Autotranslate filter plugin.
 *
 * This class handles content processing, tagging, translation storage, and persistence for the
 * Autotranslate filter plugin. It processes text with MLang tags, tags content with unique hashes,
 * persists changes into Moodle database tables (e.g., course sections, module tables), and stores
 * translations in `filter_autotranslate_translations`. It supports both primary tables (e.g., `book`)
 * and secondary tables (e.g., `book_chapters`) for modules.
 *
 * Key Features:
 * - Processes MLang tags and extracts source text and translations.
 * - Tags content with unique hashes (e.g., `{t:hash}`) for tracking.
 * - Persists tagged content into Moodle tables based on context (course, module, etc.).
 * - Stores translations with course mappings in `filter_autotranslate_hid_cids`.
 * - Rewrites pluginfile URLs for consistent content rendering.
 *
 * Usage:
 * - Called by `text_filter.php` to process and persist content during page rendering.
 * - Configured via plugin settings to enable fields for persistence (e.g., `book.name`, `book_chapters.title`).
 *
 * Design Notes:
 * - Uses unprefixed table names internally (e.g., `book_chapters`), relying on Moodle’s database layer to add prefixes.
 * - Handles transactions to ensure atomic updates of translations and mappings.
 * - Avoids direct database modifications outside of Moodle’s DML API for portability.
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_autotranslate;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/weblib.php');

use core_component;

/**
 * Handles content-related database operations for the Autotranslate filter.
 */
class content_service {

    /** @var \moodle_database Moodle database instance for DML operations. */
    private $db;

    /** @var text_utils Utility for text processing and hash generation. */
    private $textutils;

    /**
     * Constructs the service with database and utility dependencies.
     *
     * @param \moodle_database $db Moodle database instance.
     */
    public function __construct(\moodle_database $db) {
        $this->db = $db;
        $this->textutils = new text_utils();
    }

    /**
     * Tags content, persists it, and stores translations.
     *
     * Trims content, parses MLang tags, tags with a hash, persists in Moodle tables,
     * and stores translations in `filter_autotranslate_translations`.
     *
     * @param string $content Content to process (e.g., text with MLang tags).
     * @param \context $context Moodle context (e.g., course, module).
     * @param int $courseid Course ID for mapping translations.
     * @return string Tagged content or original if persistence fails.
     */
    public function process_content($content, $context, $courseid) {
        $trimmed = trim($content);
        if (empty($trimmed) || is_numeric($trimmed)) {
            return $content; // Skip empty or numeric content.
        }

        // Parse MLang tags to extract source text and translations.
        $mlangresult = $this->textutils->process_mlang_tags($trimmed, $context);
        $sourcetext = $this->textutils->extract_hashed_text($mlangresult['source_text'] ?: $trimmed);
        $displaytext = $mlangresult['display_text'] ?: $trimmed;
        $translations = $mlangresult['translations'];

        // Extract or generate a hash for the content.
        $hash = $this->textutils->extract_hash($content);
        if ($hash) {
            $translation = $this->db->get_record('filter_autotranslate_translations', ['hash' => $hash, 'lang' => 'other']);
            if ($translation) {
                if ($translation->translated_text !== $sourcetext) {
                    $translation->translated_text = $sourcetext;
                    $translation->timemodified = time();
                    $this->db->update_record('filter_autotranslate_translations', $translation);
                }
                $this->update_hash_course_mapping($hash, $courseid);
            } else {
                $this->upsert_translation($hash, 'other', $sourcetext, $context->contextlevel, $courseid, $context);
                $this->update_hash_course_mapping($hash, $courseid);
            }
        } else {
            $existing = $this->db->get_record_sql(
                "SELECT hash FROM {filter_autotranslate_translations} WHERE lang = 'other' AND " .
                $this->db->sql_compare_text('translated_text') . " = " . $this->db->sql_compare_text(':text'),
                ['text' => $sourcetext],
                IGNORE_MULTIPLE
            );
            $hash = $existing ? $existing->hash : $this->textutils->generate_unique_hash();
        }

        $taggedcontent = "$sourcetext {t:$hash}";
        $persisted = $this->persist_tagged_content($taggedcontent, $sourcetext, $trimmed, $context, $courseid);

        if ($persisted) {
            // Rewrite pluginfile URLs for consistency.
            $sourcetext = $this->rewrite_pluginfile_urls($sourcetext, $context, $hash);
            foreach ($translations as $lang => $text) {
                $translations[$lang] = $this->rewrite_pluginfile_urls($text, $context, $hash);
            }

            // Store translations in a transaction.
            $transaction = $this->db->start_delegated_transaction();
            try {
                $this->upsert_translation($hash, 'other', $sourcetext, $context->contextlevel, $courseid, $context);
                foreach ($translations as $lang => $text) {
                    $this->upsert_translation($hash, $lang, $text, $context->contextlevel, $courseid, $context);
                }
                $this->update_hash_course_mapping($hash, $courseid);
                $transaction->allow_commit();
            } catch (\Exception $e) {
                $transaction->rollback($e);
                debugging("Failed to process content: " . $e->getMessage(), DEBUG_DEVELOPER);
                return $content;
            }
        }

        return $taggedcontent;
    }

    /**
     * Persists tagged content into a Moodle table based on context.
     *
     * Locates the matching table and field, updates with tagged content. Uses `$originaltext`
     * first for matching, falls back to `$sourcetext`. Supports course, module, and category contexts.
     *
     * @param string $taggedcontent Tagged content to persist (e.g., "Text {t:hash}").
     * @param string $sourcetext Untagged source text for validation and fallback.
     * @param string $originaltext Original raw text for primary matching.
     * @param \context $context Moodle context (e.g., course, module).
     * @param int $courseid Course ID (unused here, passed for consistency).
     * @return bool True if persisted, false if no match or unsupported context.
     */
    private function persist_tagged_content($taggedcontent, $sourcetext, $originaltext, $context, $courseid) {
        if ($this->textutils->is_tagged($sourcetext)) {
            return false; // Skip already tagged source text.
        }

        $contextlevel = $context->contextlevel;
        $instanceid = $context->instanceid;
        $persisted = false;

        switch ($contextlevel) {
            case CONTEXT_SYSTEM:
            case CONTEXT_USER:
                break;

            case CONTEXT_COURSE:
                $course = get_course($instanceid);
                $coursefields = $this->get_selected_fields('course');
                if (!empty($coursefields)) {
                    $fields = [];
                    foreach ($coursefields as $field => $enabled) {
                        if ($enabled && property_exists($course, $field)) {
                            $fields[$field] = $course->$field;
                        }
                    }
                    if (!empty($fields)) {
                        $persisted = $this->update_field_if_match('course', 'id', $instanceid, $fields, $originaltext,
                            $taggedcontent);
                        if (!$persisted) {
                            $persisted = $this->update_field_if_match('course', 'id', $instanceid, $fields, $sourcetext,
                                $taggedcontent);
                        }
                    }
                }

                if (!$persisted) {
                    $sectionfields = $this->get_selected_fields('course_sections');
                    if (!empty($sectionfields)) {
                        $sections = $this->db->get_records('course_sections', ['course' => $instanceid]);
                        foreach ($sections as $section) {
                            $fields = [];
                            foreach ($sectionfields as $field => $enabled) {
                                $fieldname = str_replace('course_sections.', '', $field);
                                if ($enabled && property_exists($section, $fieldname)) {
                                    $fields[$fieldname] = $section->$fieldname;
                                }
                            }
                            if (!empty($fields)) {
                                $persisted = $this->update_field_if_match('course_sections', 'id', $section->id, $fields,
                                    $originaltext, $taggedcontent);
                                if (!$persisted) {
                                    $persisted = $this->update_field_if_match('course_sections', 'id', $section->id,
                                        $fields, $sourcetext, $taggedcontent);
                                }
                                if ($persisted) {
                                    break;
                                }
                            }
                        }
                    }
                }
                break;

            case CONTEXT_MODULE:
                $cm = get_coursemodule_from_id('', $instanceid);
                if ($cm) {
                    $modname = $cm->modname;
                    $modulefields = $this->get_selected_fields($modname);
                    if (empty($modulefields)) {
                        debugging("No enabled fields for module '$modname'", DEBUG_DEVELOPER);
                        break;
                    }

                    $prefix = $this->db->get_prefix();
                    $primarytable = $prefix . $modname;
                    $primaryfields = [];
                    $secondaryfields = [];

                    // Split fields into primary and secondary tables, ensuring unprefixed names.
                    foreach ($modulefields as $key => $enabled) {
                        if (!$enabled) {
                            continue;
                        }
                        list($table, $field) = explode('.', $key, 2);
                        if ($table === $primarytable) {
                            $primaryfields[$field] = 1;
                        } else {
                            $unprefixedtable = str_replace($prefix, '', $table);
                            $secondaryfields[$unprefixedtable][$field] = 1;
                        }
                    }

                    $found = false;

                    if (!empty($primaryfields)) {
                        $instance = $this->db->get_record($modname, ['id' => $cm->instance]);
                        if ($instance) {
                            $fields = array_intersect_key((array)$instance, $primaryfields);
                            foreach ($fields as $field => $value) {
                                $trimmedvalue = $value === null ? '' : trim($value);
                                if ($trimmedvalue === $originaltext || $trimmedvalue === $sourcetext) {
                                    $found = true;
                                    $persisted = $this->update_field_if_match($modname, 'id', $cm->instance, $fields,
                                        $trimmedvalue, $taggedcontent);
                                    break;
                                }
                            }
                        }
                    }

                    if (!$found && !empty($secondaryfields)) {
                        foreach ($secondaryfields as $table => $fields) {
                            if (!in_array('id', $this->db->get_columns($table))) {
                                continue; // Skip tables without an 'id' column.
                            }
                            $fk = $modname . 'id';
                            try {
                                $records = $this->db->get_records($table, [$fk => $cm->instance]);
                            } catch (\dml_exception $e) {
                                debugging("Failed to query secondary table '$table': " . $e->getMessage(), DEBUG_DEVELOPER);
                                continue;
                            }
                            foreach ($records as $record) {
                                $recordfields = array_intersect_key((array)$record, $fields);
                                foreach ($recordfields as $field => $value) {
                                    $trimmedvalue = $value === null ? '' : trim($value);
                                    if ($trimmedvalue === $originaltext || $trimmedvalue === $sourcetext) {
                                        $found = true;
                                        $persisted = $this->update_field_if_match($table, 'id', $record->id,
                                            $recordfields, $trimmedvalue, $taggedcontent);
                                        break 2;
                                    }
                                }
                            }
                        }
                    }
                }
                break;

            case CONTEXT_COURSECAT:
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
                            $persisted = $this->update_field_if_match('course_categories', 'id', $instanceid, $fields,
                                $originaltext, $taggedcontent);
                            if (!$persisted) {
                                $persisted = $this->update_field_if_match('course_categories', 'id', $instanceid, $fields,
                                    $sourcetext, $taggedcontent);
                            }
                        }
                    }
                }
                break;

            case CONTEXT_BLOCK:
                break;

            default:
                debugging("Unsupported context level $contextlevel for persistence", DEBUG_DEVELOPER);
        }

        return $persisted;
    }

    /**
     * Updates a field if its value matches the source text.
     *
     * @param string $table Table to update (unprefixed, e.g., 'course_sections').
     * @param string $idfield ID field name (typically 'id').
     * @param int $id ID value of the record to update.
     * @param array $fields Field name-value pairs to check.
     * @param string $sourcetext Text to match against field values.
     * @param string $taggedcontent Tagged content to set if matched.
     * @return bool True if updated, false if no match.
     */
    private function update_field_if_match($table, $idfield, $id, $fields, $sourcetext, $taggedcontent) {
        foreach ($fields as $field => $value) {
            $trimmedvalue = $value === null ? '' : trim($value);
            if ($trimmedvalue === $sourcetext) {
                $this->db->set_field($table, $field, $taggedcontent, [$idfield => $id]);
                return true;
            }
        }
        return false;
    }

    /**
     * Gets schemas for specified modules.
     *
     * @param array $modulenames Array of module names (e.g., ['book', 'assign']).
     * @return array Table-field mappings for the modules.
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
     * Generates field options for settings.
     *
     * Builds a structured array of core and third-party module fields for the plugin’s settings UI.
     *
     * @return array Array with 'core' and 'thirdparty' keys, containing table-field options.
     */
    public function get_field_selection_options() {
        $corenonmodules = [
            'course' => ['course' => ['fullname', 'shortname', 'summary']],
            'course_sections' => ['course_sections' => ['name', 'summary']],
            'course_categories' => ['course_categories' => ['description']],
        ];

        $coremodules = [
            'assign', 'book', 'choice', 'data', 'feedback', 'folder', 'forum', 'glossary', 'label',
            'lesson', 'lti', 'page', 'quiz', 'resource', 'url', 'wiki', 'workshop',
        ];

        $installedmodules = array_keys(core_component::get_plugin_list('mod'));
        $coremodulelist = array_intersect($installedmodules, $coremodules);
        $thirdpartymodules = array_diff($installedmodules, $coremodules);

        $coremoduletables = $this->get_all_module_schemas($coremodulelist);
        $coremodulesgrouped = [];
        $prefix = $this->db->get_prefix();
        foreach ($coremodulelist as $modname) {
            $coremodulesgrouped[$modname] = [];
            foreach ($coremoduletables as $table => $fields) {
                if (strpos($table, $prefix . $modname) === 0) {
                    $coremodulesgrouped[$modname][$table] = $fields;
                }
            }
        }

        $thirdpartymoduletables = $this->get_all_module_schemas($thirdpartymodules);
        $thirdpartygrouped = [];
        foreach ($thirdpartymodules as $modname) {
            $thirdpartygrouped[$modname] = [];
            foreach ($thirdpartymoduletables as $table => $fields) {
                if (strpos($table, $prefix . $modname) === 0) {
                    $thirdpartygrouped[$modname][$table] = $fields;
                }
            }
        }

        $coreoptions = $corenonmodules;
        $coreoptions['modules'] = $coremodulesgrouped;

        ksort($coreoptions);
        ksort($coremodulesgrouped);
        ksort($thirdpartygrouped);

        return ['core' => $coreoptions, 'thirdparty' => $thirdpartygrouped];
    }

    /**
     * Fetches enabled fields from settings for a context.
     *
     * @param string $contextid Context identifier (e.g., 'book', 'course').
     * @return array Enabled fields (e.g., ['mdl_book.name' => 1, 'mdl_book_chapters.title' => 1]).
     */
    public function get_selected_fields($contextid) {
        $config = get_config('filter_autotranslate', "{$contextid}_fields");
        $fields = [];

        if ($config !== false && $config !== null) {
            $fields = unserialize($config);
            if (!is_array($fields)) {
                $fields = [];
            }
        }

        return $fields;
    }

    /**
     * Gets schema for a module, identifying translatable fields.
     *
     * @param string $modname Module name (e.g., 'book').
     * @return array Table-field mappings (e.g., ['mdl_book' => ['name', 'intro']]).
     */
    private function get_module_schema($modname) {
        global $CFG;

        $schema = [];
        $prefix = $this->db->get_prefix();
        $pattern = "{$prefix}{$modname}%";

        // Fields to include (text-based) and exclude (non-translatable).
        $includefields = [
            'name', 'intro', 'summary', 'description', 'content', 'message', 'text', 'body', 'title',
            'feedback', 'instructions', 'question', 'answer', 'response', 'comment', 'label', 'value',
            'presentation',
        ];
        $excludefields = [
            'id', 'timemodified', 'timecreated', 'dependvalue', 'configdata', 'path', 'colour',
            'activity', 'attemptreopenmethod', 'pathnew', 'onlinetext', 'commenttext', 'type',
            'format', 'version', 'status', 'grade', 'score', 'url', 'email', 'phone', 'ip', 'token',
            'key', 'secret', 'password', 'hash', 'signature', 'settings', 'options', 'metadata',
            'attributes', 'params', 'data', 'json',
        ];

        $dbtype = strtolower($CFG->dbtype);

        if (in_array($dbtype, ['mysql', 'mariadb'])) {
            $sql = "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = :dbname AND TABLE_NAME LIKE :pattern";
            $tables = $this->db->get_fieldset_sql($sql, ['dbname' => $CFG->dbname, 'pattern' => $pattern]);
        } else if ($dbtype === 'pgsql') {
            $sql = "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_name LIKE :pattern";
            $tables = $this->db->get_fieldset_sql($sql, ['pattern' => $pattern]);
        } else {
            debugging("Using fallback query for database type '$dbtype'", DEBUG_DEVELOPER);
            $sql = "SHOW TABLES LIKE :pattern";
            $tables = $this->db->get_fieldset_sql($sql, ['pattern' => $pattern]);
        }

        foreach ($tables as $table) {
            try {
                if (in_array($dbtype, ['mysql', 'mariadb'])) {
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
                    $columns = $this->db->get_columns($table);
                    if (!$columns) {
                        $lowertable = strtolower($table);
                        $columns = $this->db->get_columns($lowertable);
                    }
                    if (!$columns) {
                        debugging("No columns for table '$table' after case adjustment", DEBUG_DEVELOPER);
                        continue;
                    }
                }

                $fields = [];
                foreach ($columns as $column) {
                    $type = strtolower($column->type);
                    $name = $column->name;
                    $istext = in_array($type, ['text', 'varchar'])
                        || strpos($type, 'text') !== false
                        || strpos($type, 'varchar') !== false;
                    if ($istext && in_array($name, $includefields) && !in_array($name, $excludefields)) {
                        $fields[] = $name;
                    }
                }

                if ($fields) {
                    $schema[$table] = array_values($fields);
                }
            } catch (\moodle_exception $e) {
                debugging("Failed to get columns for '$table': " . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        return $schema ?: [];
    }

    /**
     * Marks course translations as stale by updating their modification time.
     *
     * @param int $courseid Course ID to mark as stale.
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
     * Updates a translation record with new text and review status.
     *
     * @param int $id Translation record ID.
     * @param string $text Updated translated text.
     * @param int $human Human review status (0 = auto, 1 = human).
     * @param int $timereviewed Timestamp of last review.
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
     * Inserts or updates a translation in `filter_autotranslate_translations`.
     *
     * @param string $hash Unique hash for the content.
     * @param string $lang Language code (e.g., 'other', 'es').
     * @param string $text Translated text to store.
     * @param int $contextlevel Context level (e.g., CONTEXT_MODULE).
     * @param int $courseid Course ID (passed through for mapping).
     * @param \context $context Context (passed through for URL rewriting).
     */
    private function upsert_translation($hash, $lang, $text, $contextlevel, $courseid, $context) {
        $existing = $this->db->get_record('filter_autotranslate_translations', ['hash' => $hash, 'lang' => $lang]);
        if ($existing) {
            if ($existing->translated_text !== $text) {
                $existing->translated_text = $text;
                $existing->timemodified = time();
                $this->db->update_record('filter_autotranslate_translations', $existing);
            }
        } else {
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
     * Updates hash-to-course mapping in `filter_autotranslate_hid_cids`.
     *
     * @param string $hash Content hash to map.
     * @param int $courseid Course ID to associate with the hash.
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
     * Rewrites @@PLUGINFILE@@ URLs to full URLs based on context.
     *
     * @param string $content Content with pluginfile URLs.
     * @param \context $context Moodle context for URL rewriting.
     * @param string $hash Content hash (unused here, included for consistency).
     * @return string Content with rewritten URLs.
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
            return \file_rewrite_pluginfile_urls($content, 'pluginfile.php', $context->id, $component, $filearea, $itemid);
        }

        return $content;
    }
}
