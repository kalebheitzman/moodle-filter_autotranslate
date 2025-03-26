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
 * Autotranslate Translation Service
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace filter_autotranslate;

/**
 * Service class for handling translation-related database operations in the filter_autotranslate plugin.
 *
 * Purpose:
 * This class encapsulates all database operations related to translations, ensuring a clear
 * separation of concerns. It is used by translation_manager.php to update translations and by
 * fetchtranslation_task.php to store translations fetched from an external API.
 *
 * Design Decisions:
 * - Focuses on database operations only, adhering to the principle of separation of concerns.
 *   Coordination logic is handled by translation_manager.php, and read-only access is provided
 *   by translation_repository.php.
 * - Function names use snake_case (e.g., update_translation) to follow Moodle's coding style.
 * - The store_translation function ensures translations are either inserted or updated, handling
 *   both new translations and updates to existing ones.
 * - Rewrites @@PLUGINFILE@@ URLs in the translated text before storing it in autotranslate_translations,
 *   ensuring the stored text has fully resolved URLs and eliminating the need for rewriting at display time.
 *
 * Dependencies:
 * - None (interacts directly with the Moodle database).
 */
class translation_service {
    /**
     * @var \moodle_database|null $db The Moodle database instance, or null to use the global $DB.
     */
    private $db;

    /**
     * Constructor for the translation service.
     *
     * @param \moodle_database|null $db The Moodle database instance, or null to use the global $DB.
     */
    public function __construct(?\moodle_database $db = null) {
        global $DB;
        $this->db = $db ?? $DB;
    }

    /**
     * Updates a translation record in autotranslate_translations.
     *
     * This function updates an existing translation record with the provided data, typically
     * used to update fields like translated_text, human, timemodified, and timereviewed.
     *
     * @param object $translation The translation record to update.
     */
    public function update_translation($translation) {
        $this->db->update_record('autotranslate_translations', $translation);
    }

    /**
     * Updates the human status of a translation.
     *
     * This function updates the human status (e.g., marking a translation as human-edited or
     * machine-generated) and related timestamps (timemodified, timereviewed) for a translation record.
     *
     * @param object $translation The translation record to update.
     */
    public function update_human_status($translation) {
        $translation->timemodified = time();
        $translation->timereviewed = time();
        $this->db->update_record('autotranslate_translations', $translation);
    }

    /**
     * Stores a translation in autotranslate_translations, updating if it already exists.
     *
     * This function inserts a new translation record or updates an existing one if a record
     * with the same hash and language already exists. It is used by fetchtranslation_task.php
     * to store translations fetched from an external API.
     *
     * @param string $hash The unique hash of the translation.
     * @param string $lang The language code (e.g., 'es', 'fr').
     * @param string|array $translatedtext The translated text (string or array to handle potential API responses).
     * @param int $contextlevel The context level for the translation.
     */
    public function store_translation($hash, $lang, $translatedtext, $contextlevel) {
        // Ensure translated_text is a string.
        $translatedtext = is_array($translatedtext) ? implode(' ', $translatedtext) : (string)$translatedtext;

        // Fetch the context for the translation (based on contextlevel and hash).
        $context = $this->get_context_for_hash($hash, $contextlevel);
        if ($context) {
            // Rewrite @@PLUGINFILE@@ URLs in the translated text.
            $translatedtext = $this->rewrite_pluginfile_urls($translatedtext, $context, $hash);
        }

        // Check if a translation already exists for this hash and lang.
        $existing = $this->db->get_record('autotranslate_translations', ['hash' => $hash, 'lang' => $lang]);
        if ($existing) {
            // Update the existing record.
            $record = new \stdClass();
            $record->id = $existing->id;
            $record->translated_text = $translatedtext;
            $record->contextlevel = $contextlevel;
            $record->timemodified = time();
            $record->timereviewed = time();
            $record->human = 0; // Machine-generated translation.

            $this->db->update_record('autotranslate_translations', $record);
        } else {
            // Insert a new record.
            $record = new \stdClass();
            $record->hash = $hash;
            $record->lang = $lang;
            $record->translated_text = $translatedtext;
            $record->contextlevel = $contextlevel;
            $record->timecreated = time();
            $record->timemodified = time();
            $record->timereviewed = time();
            $record->human = 0; // Machine-generated translation.

            $this->db->insert_record('autotranslate_translations', $record);
        }
    }

    /**
     * Fetches the context for a hash based on its context level and course mapping.
     *
     * This function retrieves the context associated with a hash by looking up the course ID
     * in autotranslate_hid_cids and determining the context level from autotranslate_translations.
     *
     * @param string $hash The hash to look up.
     * @param int $contextlevel The context level from the translation record.
     * @return \context|null The context object, or null if not found.
     */
    private function get_context_for_hash($hash, $contextlevel) {
        // Fetch the course ID from autotranslate_hid_cids.
        $courseid = $this->db->get_field('autotranslate_hid_cids', 'courseid', ['hash' => $hash]);
        if (!$courseid) {
            return null;
        }

        try {
            if ($contextlevel == CONTEXT_COURSE) {
                return \context_course::instance($courseid);
            } else if ($contextlevel == CONTEXT_MODULE) {
                // Fetch the course module ID (requires joining with course_modules).
                $sql = "SELECT cm.id
                        FROM {course_modules} cm
                        JOIN {autotranslate_hid_cids} hc ON hc.courseid = cm.course
                        WHERE hc.hash = :hash";
                $cmid = $this->db->get_field_sql($sql, ['hash' => $hash]);
                if ($cmid) {
                    return \context_module::instance($cmid);
                }
            } else if ($contextlevel == CONTEXT_BLOCK) {
                $sql = "SELECT bi.id
                        FROM {block_instances} bi
                        JOIN {context} ctx ON ctx.instanceid = bi.id AND ctx.contextlevel = :contextlevel
                        JOIN {autotranslate_hid_cids} hc ON hc.courseid = ctx.instanceid
                        WHERE hc.hash = :hash";
                $blockid = $this->db->get_field_sql($sql, ['hash' => $hash, 'contextlevel' => CONTEXT_BLOCK]);
                if ($blockid) {
                    return \context_block::instance($blockid);
                }
            } else if ($contextlevel == CONTEXT_SYSTEM) {
                return \context_system::instance();
            } else if ($contextlevel == CONTEXT_USER) {
                $sql = "SELECT u.id
                        FROM {user} u
                        JOIN {autotranslate_hid_cids} hc ON hc.courseid = 0
                        WHERE hc.hash = :hash";
                $userid = $this->db->get_field_sql($sql, ['hash' => $hash]);
                if ($userid) {
                    return \context_user::instance($userid);
                }
            } else if ($contextlevel == CONTEXT_COURSECAT) {
                $sql = "SELECT cc.id
                        FROM {course_categories} cc
                        JOIN {autotranslate_hid_cids} hc ON hc.courseid = 0
                        WHERE hc.hash = :hash";
                $catid = $this->db->get_field_sql($sql, ['hash' => $hash]);
                if ($catid) {
                    return \context_coursecat::instance($catid);
                }
            }
        } catch (\dml_exception $e) {
            debugging("Failed to fetch context for hash '$hash': " . $e->getMessage(), DEBUG_DEVELOPER);
        }

        return null;
    }

    /**
     * Rewrites @@PLUGINFILE@@ URLs in content based on the context.
     *
     * This function rewrites @@PLUGINFILE@@ URLs in the translated text before storing it,
     * using the context to determine the correct component, filearea, and itemid.
     *
     * @param string $content The content to rewrite.
     * @param \context $context The context object for the content.
     * @param string $hash The hash of the translation (used to fetch itemid if needed).
     * @return string The content with rewritten URLs.
     */
    private function rewrite_pluginfile_urls($content, $context, $hash) {
        $component = '';
        $filearea = '';
        $itemid = 0;

        // Determine the component, filearea, and itemid based on the context.
        if ($context->contextlevel == CONTEXT_COURSE) {
            $component = 'course';
            $filearea = 'summary'; // Default for course context.
            $itemid = $context->instanceid;
        } else if ($context->contextlevel == CONTEXT_MODULE) {
            $cm = get_coursemodule_from_id('', $context->instanceid);
            if ($cm) {
                $component = 'mod_' . $cm->modname;
                $filearea = 'intro'; // Default for module context.
                $itemid = $cm->instance;
            }
        } else if ($context->contextlevel == CONTEXT_BLOCK) {
            $component = 'block_instances';
            $filearea = 'content';
            $itemid = $context->instanceid;
        } else if ($context->contextlevel == CONTEXT_SYSTEM) {
            $component = 'core';
            $filearea = 'content';
            $itemid = 0;
        } else if ($context->contextlevel == CONTEXT_USER) {
            $component = 'user';
            $filearea = 'profile';
            $itemid = $context->instanceid;
        } else if ($context->contextlevel == CONTEXT_COURSECAT) {
            $component = 'coursecat';
            $filearea = 'description';
            $itemid = $context->instanceid;
        }

        if ($component && $filearea) {
            $content = \file_rewrite_pluginfile_urls(
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
