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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Content service for the Autotranslate filter plugin.
 *
 * Manages tagging, translation storage, and persistence for the filter_autotranslate plugin.
 * Processes text, handles MLang tags, persists tagged content, and stores translations.
 *
 * Key features:
 * - Tags and persists content from `text_filter`.
 * - Parses MLang tags, storing results in `filter_autotranslate_translations`.
 * - Maps hashes to courses in `filter_autotranslate_hid_cids`.
 * - Supports stale translation marking and schema discovery.
 *
 * Usage:
 * - `text_filter` for rendering.
 * - `ui_manager` for stale marking.
 * - `settings.php` for field options.
 *
 * Design notes:
 * - Combines tagging and translation logic.
 * - Uses lazy rebuild with `timereviewed` vs. `timemodified`.
 * - Dynamic context, no static configs.
 * - Transaction-based for consistency.
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

    /** @var \moodle_database Moodle database instance. */
    private $db;

    /** @var text_utils Text processing and hash utility. */
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
     * @param string $content Content to process.
     * @param \context $context Moodle context.
     * @param int $courseid Course ID.
     * @return string Tagged content or original if persistence fails.
     */
    public function process_content($content, $context, $courseid) {
        $trimmed = trim($content);

        if (empty($trimmed) || is_numeric($trimmed)) {
            return $content;
        }

        $mlangresult = $this->textutils->process_mlang_tags($trimmed, $context);
        $sourcetext = $this->textutils->extract_hashed_text($mlangresult['source_text'] ?: $trimmed);
        $displaytext = $mlangresult['display_text'] ?: $trimmed;
        $translations = $mlangresult['translations'];

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
            $sourcetext = $this->rewrite_pluginfile_urls($sourcetext, $context, $hash);
            foreach ($translations as $lang => $text) {
                $translations[$lang] = $this->rewrite_pluginfile_urls($text, $context, $hash);
            }

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
     * first, falls back to `$sourcetext` for flexibility.
     *
     * @param string $taggedcontent Tagged content to persist.
     * @param string $sourcetext Untagged source text for validation and fallback.
     * @param string $originaltext Original raw text for primary matching.
     * @param \context $context Moodle context.
     * @param int $courseid Course ID (unused here).
     * @return bool True if persisted, false if no match.
     */
    private function persist_tagged_content($taggedcontent, $sourcetext, $originaltext, $context, $courseid) {
        if ($this->textutils->is_tagged($sourcetext)) {
            return false;
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
                                continue;
                            }
                            $fk = $modname . 'id';
                            $records = $this->db->get_records($table, [$fk => $cm->instance]);
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
     * @param string $table Table to update.
     * @param string $idfield ID field name.
     * @param int $id ID value.
     * @param array $fields Field name-value pairs.
     * @param string $sourcetext Text to match.
     * @param string $taggedcontent Tagged content to set.
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
     * @param array $modulenames Module names.
     * @return array Table-field mappings.
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
     * @return array Core and third-party field options.
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
     * Fetches enabled fields from settings.
     *
     * @param string $contextid Context identifier.
     * @return array Enabled fields.
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
     * Gets schema for a module.
     *
     * @param string $modname Module name.
     * @return array Table-field mappings.
     */
    private function get_module_schema($modname) {
        global $CFG;

        $schema = [];
        $prefix = $this->db->get_prefix();
        $pattern = "{$prefix}{$modname}%";

        $includefields = [
            'name', 'intro', 'summary', 'description', 'content', 'message', 'text', 'body', 'title',
            'feedback', 'instructions', 'question', 'answer', 'response', 'comment', 'label', 'value',
            'presentation', 'activity',
        ];
        $excludefields = [
            'id', 'timemodified', 'timecreated', 'dependvalue', 'configdata', 'path', 'colour',
            'attemptreopenmethod', 'pathnew', 'onlinetext', 'commenttext', 'type',
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
     * Marks course translations as stale.
     *
     * @param int $courseid Course ID.
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
     * Updates a translation record.
     *
     * @param int $id Translation ID.
     * @param string $text Updated text.
     * @param int $human Human review status (0 = auto, 1 = human).
     * @param int $timereviewed Last review timestamp.
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
     * @param string $hash Content hash.
     * @param string $lang Language code.
     * @param string $text Text to store.
     * @param int $contextlevel Context level.
     * @param int $courseid Course ID (passed through).
     * @param \context $context Context (passed through).
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
     * @param string $hash Content hash.
     * @param int $courseid Course ID.
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
     * Rewrites @@PLUGINFILE@@ URLs to full URLs.
     *
     * @param string $content Content with plugin file URLs.
     * @param \context $context Moodle context.
     * @param string $hash Content hash (unused here).
     * @return string Rewritten content.
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
