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

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/filelib.php');

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
 * - The store_translation function only inserts new translations, while updates are handled by
 *   update_translation() to preserve existing contextlevel unless explicitly changed.
 * - Rewrites @@PLUGINFILE@@ URLs in the translated text before storing it in filter_autotranslate_translations,
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
     * Updates a translation record in filter_autotranslate_translations.
     *
     * This function updates an existing translation record with the provided data, typically
     * used to update fields like translated_text, human, timemodified, and timereviewed.
     *
     * @param object $translation The translation record to update.
     */
    public function update_translation($translation) {
        $this->db->update_record('filter_autotranslate_translations', $translation);
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
        $this->db->update_record('filter_autotranslate_translations', $translation);
    }

    /**
     * Stores a new translation in filter_autotranslate_translations if it doesn't exist.
     *
     * This function inserts a new translation record if no entry exists for the given hash and language.
     * It does not update existing records; use update_translation() for updates.
     *
     * @param string $hash The unique hash of the translation.
     * @param string $lang The language code (e.g., 'es', 'fr', 'other').
     * @param string|array $translatedtext The translated text (string or array to handle potential API responses).
     * @param int $contextlevel The context level for the translation.
     * @param int|null $courseid Optional course ID to determine the context for URL rewriting.
     * @param \context|null $context Optional context object from the caller for precise URL rewriting.
     */
    public function store_translation($hash, $lang, $translatedtext, $contextlevel, $courseid = null, $context = null) {
        $translatedtext = is_array($translatedtext) ? implode(' ', $translatedtext) : (string)$translatedtext;

        // Use provided context if available; otherwise, fall back to hash-based resolution.
        $effectivecontext = $context ?? $this->get_context_for_hash($hash, $contextlevel, $courseid, $lang);
        if ($effectivecontext) {
            $translatedtext = $this->rewrite_pluginfile_urls($translatedtext, $effectivecontext, $hash);
        }

        // Only insert if the record doesn't exist.
        if (!$this->db->record_exists('filter_autotranslate_translations', ['hash' => $hash, 'lang' => $lang])) {
            $record = new \stdClass();
            $record->hash = $hash;
            $record->lang = $lang;
            $record->translated_text = $translatedtext;
            $record->contextlevel = $contextlevel;
            $record->timecreated = time();
            $record->timemodified = time();
            $record->timereviewed = time();
            $record->human = 0;

            $this->db->insert_record('filter_autotranslate_translations', $record);
        }
        // No update logic here; existing records are preserved.
    }

    /**
     * Fetches the context for a hash based on its context level and course mapping.
     *
     * @param string $hash The hash to look up.
     * @param int $contextlevel The context level from the translation record.
     * @param int|null $courseid Optional course ID to use instead of querying hid_cids.
     * @param string $lang The language of the translation (affects context fetching logic).
     * @return \context|null The context object, or null if not found.
     */
    private function get_context_for_hash($hash, $contextlevel, $courseid = null, $lang = 'other') {
        // Use provided courseid if available.
        if ($courseid !== null && $courseid > 0) {
            $effectivecourseid = $courseid;
        } else {
            $courseids = $this->db->get_fieldset_select(
                'filter_autotranslate_hid_cids',
                'courseid',
                'hash = :hash',
                ['hash' => $hash]
            );
            if (empty($courseids)) {
                return null;
            }
            $effectivecourseid = reset($courseids);
            if (count($courseids) > 1) {
                debugging(
                    "Multiple course IDs found for hash '$hash' (" . implode(', ', $courseids) . "). " .
                    "Using first course ID ($effectivecourseid) as fallback.",
                    DEBUG_DEVELOPER
                );
            }
        }

        try {
            // For target languages, use course context for URL rewriting to avoid module ambiguity.
            if ($lang !== 'other' || $contextlevel == CONTEXT_COURSE) {
                return \context_course::instance($effectivecourseid);
            }

            // For 'other', respect the original contextlevel.
            if ($contextlevel == CONTEXT_MODULE) {
                $sql = "SELECT cm.id
                        FROM {course_modules} cm
                        JOIN {filter_autotranslate_hid_cids} hc ON hc.courseid = cm.course
                        WHERE hc.hash = :hash AND cm.course = :courseid
                        LIMIT 1";
                $cmid = $this->db->get_field_sql($sql, ['hash' => $hash, 'courseid' => $effectivecourseid]);
                if ($cmid) {
                    $cmidcount = $this->db->count_records_sql(
                        "SELECT COUNT(cm.id)
                         FROM {course_modules} cm
                         JOIN {filter_autotranslate_hid_cids} hc ON hc.courseid = cm.course
                         WHERE hc.hash = :hash AND cm.course = :courseid",
                        ['hash' => $hash, 'courseid' => $effectivecourseid]
                    );
                    if ($cmidcount > 1) {
                        debugging(
                            "Multiple course module IDs found for hash '$hash' in " .
                            "course '$effectivecourseid' ($cmidcount found). Falling back to course context.",
                            DEBUG_DEVELOPER
                        );
                        return \context_course::instance($effectivecourseid);
                    }
                    return \context_module::instance($cmid);
                }
                return \context_course::instance($effectivecourseid); // Fallback if no cmid found.
            } else if ($contextlevel == CONTEXT_BLOCK) {
                $sql = "SELECT bi.id
                        FROM {block_instances} bi
                        JOIN {context} ctx ON ctx.instanceid = bi.id AND ctx.contextlevel = :contextlevel
                        JOIN {filter_autotranslate_hid_cids} hc ON hc.courseid = ctx.instanceid
                        WHERE hc.hash = :hash AND hc.courseid = :courseid
                        LIMIT 1";
                $blockid = $this->db->get_field_sql($sql, [
                    'hash' => $hash,
                    'courseid' => $effectivecourseid,
                    'contextlevel' => CONTEXT_BLOCK,
                ]);
                if ($blockid) {
                    $blockidcount = $this->db->count_records_sql(
                        "SELECT COUNT(bi.id)
                         FROM {block_instances} bi
                         JOIN {context} ctx ON ctx.instanceid = bi.id AND ctx.contextlevel = :contextlevel
                         JOIN {filter_autotranslate_hid_cids} hc ON hc.courseid = ctx.instanceid
                         WHERE hc.hash = :hash AND hc.courseid = :courseid",
                        ['hash' => $hash, 'courseid' => $effectivecourseid, 'contextlevel' => CONTEXT_BLOCK]
                    );
                    if ($blockidcount > 1) {
                        debugging(
                            "Multiple block IDs found for hash '$hash' in course '$effectivecourseid' ($blockidcount found). " .
                            "Using first blockid ($blockid) for context.",
                            DEBUG_DEVELOPER
                        );
                    }
                    return \context_block::instance($blockid);
                }
                return null;
            } else if ($contextlevel == CONTEXT_SYSTEM) {
                return \context_system::instance();
            } else if ($contextlevel == CONTEXT_USER) {
                $sql = "SELECT u.id
                        FROM {user} u
                        JOIN {filter_autotranslate_hid_cids} hc ON hc.courseid = 0
                        WHERE hc.hash = :hash
                        LIMIT 1";
                $userid = $this->db->get_field_sql($sql, ['hash' => $hash]);
                if ($userid) {
                    return \context_user::instance($userid);
                }
            } else if ($contextlevel == CONTEXT_COURSECAT) {
                $sql = "SELECT cc.id
                        FROM {course_categories} cc
                        JOIN {filter_autotranslate_hid_cids} hc ON hc.courseid = 0
                        WHERE hc.hash = :hash
                        LIMIT 1";
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
    public function rewrite_pluginfile_urls($content, $context, $hash) {
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
