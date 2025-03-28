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
 * Autotranslate Tagging Service
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace filter_autotranslate;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/filter/autotranslate/classes/helper.php');
require_once($CFG->dirroot . '/filter/autotranslate/classes/translation_service.php');

/**
 * Service class for handling tagging-related database operations in the filter_autotranslate plugin.
 */
class tagging_service {
    /**
     * @var \moodle_database
     */
    private $db;

    /**
     * @var translation_service
     */
    private $translationservice;

    /**
     * Constructor for the tagging_service class.
     *
     * @param \moodle_database $db The database object.
     */
    public function __construct(\moodle_database $db) {
        $this->db = $db;
        $this->translationservice = new translation_service($db);
    }

    /**
     * Tags content and stores it in the database, reusing existing hashes and rewriting URLs.
     *
     * This method handles tagging, hash reuse, URL rewriting, and source text storage in a single
     * reusable function for both text_filter.php and tagcontent_task.php.
     *
     * @param string $content The content to tag.
     * @param \context $context The context object for tagging.
     * @param int $courseid The course ID for hash-course mapping.
     * @return string The tagged content with {t:hash}.
     */
    public function tag_and_store_content($content, $context, $courseid) {
        $trimmedcontent = trim($content);
        if (empty($trimmedcontent) || is_numeric($trimmedcontent)) {
            return $content;
        }

        // Process MLang tags if present.
        $mlangresult = helper::process_mlang_tags($trimmedcontent, $context);
        $sourcetext = $mlangresult['source_text'];
        $displaytext = $mlangresult['display_text'];
        $translations = $mlangresult['translations'];

        if (empty($sourcetext)) {
            $sourcetext = $trimmedcontent;
            $displaytext = $trimmedcontent;
        }

        // Check if content is already tagged and extract hash.
        $hash = helper::extract_hash($content);
        if ($hash) {
            // If tagged, ensure source text exists but do not update if it already does.
            $this->restore_missing_source_text($hash, $sourcetext, $context, $courseid);
            $taggedcontent = $displaytext . " {t:$hash}";
            $this->update_hash_course_mapping($taggedcontent, $courseid);
            return $taggedcontent;
        }

        // Check for existing hash based on source text.
        $existing = $this->db->get_record_sql(
            "SELECT hash FROM {filter_autotranslate_translations} WHERE lang = :lang AND " .
            $this->db->sql_compare_text('translated_text') . " = " . $this->db->sql_compare_text(':text'),
            ['lang' => 'other', 'text' => $sourcetext],
            IGNORE_MULTIPLE
        );
        $hash = $existing ? $existing->hash : helper::generate_unique_hash();

        // Rewrite URLs in source text and translations.
        $sourcetext = $this->translationservice->rewrite_pluginfile_urls($sourcetext, $context, $hash);
        foreach ($translations as $lang => $text) {
            $translations[$lang] = $this->translationservice->rewrite_pluginfile_urls($text, $context, $hash);
        }

        // Store or update source text and translations in the database.
        $transaction = $this->db->start_delegated_transaction();
        try {
            // Only store source text if it doesn't exist.
            if (!$this->db->record_exists('filter_autotranslate_translations', ['hash' => $hash, 'lang' => 'other'])) {
                $this->translationservice->store_translation($hash, 'other', $sourcetext, $context->contextlevel, $courseid, $context);
            }
            // Store MLang translations if present, only if they don't exist.
            foreach ($translations as $lang => $translatedtext) {
                if (!$this->db->record_exists('filter_autotranslate_translations', ['hash' => $hash, 'lang' => $lang])) {
                    $this->translationservice->store_translation($hash, $lang, $translatedtext, $context->contextlevel, $courseid, $context);
                }
            }
            $taggedcontent = $displaytext . " {t:$hash}";
            $this->update_hash_course_mapping($taggedcontent, $courseid);
            $transaction->allow_commit();
        } catch (\Exception $e) {
            $transaction->rollback($e);
            debugging("Failed to tag content for hash '$hash': " . $e->getMessage(), DEBUG_DEVELOPER);
            return $content;
        }

        return $taggedcontent;
    }

    /**
     * Tags content in a record and updates the database.
     *
     * @param string $table The table name (e.g., 'course', 'book_chapters').
     * @param object $record The record object containing the fields to tag.
     * @param array $fields The fields to tag.
     * @param \context $context The context object for tagging.
     * @param int $courseid The course ID for hash-course mapping.
     * @return bool Whether the record was updated.
     */
    public function tag_content($table, $record, $fields, $context, $courseid) {
        $updated = false;
        foreach ($fields as $field) {
            if (empty($record->$field)) {
                continue;
            }

            $content = $record->$field;
            $taggedcontent = $this->tag_and_store_content($content, $context, $courseid);
            if ($taggedcontent !== $content) {
                $record->$field = $taggedcontent;
                $updated = true;
            }
        }

        if ($updated) {
            $record->timemodified = time();
            $this->db->update_record($table, $record);
            $context->mark_dirty();
            $this->mark_translations_for_revision($table, $record->id, $fields, $context);
        }

        return $updated;
    }

    /**
     * Updates the filter_autotranslate_hid_cids table with the hash and courseid mapping.
     *
     * @param string $content The tagged content (containing the hash).
     * @param int $courseid The course ID to map.
     */
    public function update_hash_course_mapping($content, $courseid) {
        $hash = helper::extract_hash($content);
        if (!$hash || !$courseid) {
            return;
        }

        $exists = $this->db->record_exists('filter_autotranslate_hid_cids', ['hash' => $hash, 'courseid' => $courseid]);
        if (!$exists) {
            try {
                $this->db->execute(
                    "INSERT INTO {filter_autotranslate_hid_cids} (hash, courseid) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE hash = hash",
                    [$hash, $courseid]
                );
            } catch (\dml_exception $e) {
                debugging($e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    /**
     * Restores missing source text for an existing hash only if it doesn't exist.
     *
     * @param string $hash The hash from the content.
     * @param string $sourcetext The source text to restore.
     * @param \context $context The context object for tagging.
     * @param int $courseid The course ID for hash-course mapping.
     */
    public function restore_missing_source_text($hash, $sourcetext, $context, $courseid) {
        $existing = $this->db->get_record('filter_autotranslate_translations', ['hash' => $hash, 'lang' => 'other']);
        if (!$existing) {
            // Only store if missing, no update if it exists.
            $sourcetext = $this->translationservice->rewrite_pluginfile_urls($sourcetext, $context, $hash);
            $this->translationservice->store_translation($hash, 'other', $sourcetext, $context->contextlevel, $courseid, $context);
            $this->update_hash_course_mapping("{t:$hash}", $courseid);
        }
        // If it exists, do not update (preserve original contextlevel and text).
    }

    /**
     * Creates or updates the source translation record in filter_autotranslate_translations.
     *
     * This function stores the source text (lang = 'other') for a given hash in filter_autotranslate_translations,
     * creating a new record if it doesn't exist or updating the existing record if it does.
     *
     * @param string $content The tagged content (containing the hash).
     * @param int $contextlevel The context level for the translation.
     */
    public function create_or_update_source_translation($content, $contextlevel) {
        $hash = helper::extract_hash($content);
        if (!$hash) {
            return;
        }

        // Extract the source text by removing the {t:hash} tag.
        $sourcetext = preg_replace('/\{t:[a-zA-Z0-9]{10}\}(?:\s*(?:<\/p>)?)?$/s', '', $content);
        $this->restore_missing_source_text($hash, $sourcetext, \context::instance_by_id($this->db->get_field('context', 'id', ['contextlevel' => $contextlevel])), 0);
    }

    /**
     * Marks translations as needing revision by updating timemodified and timereviewed.
     *
     * This function updates the timemodified and timereviewed fields for translations associated
     * with the given record, indicating that they need review due to changes in the source content.
     *
     * @param string $table The table name (e.g., 'course', 'course_sections', 'mod_*').
     * @param int $instanceid The instance ID (e.g., course ID, section ID, module instance ID).
     * @param array $fields The fields that were updated (e.g., ['name', 'intro']).
     * @param \context $context The context object for tagging.
     */
    public function mark_translations_for_revision($table, $instanceid, $fields, $context) {
        $hashes = [];
        $record = $this->db->get_record($table, ['id' => $instanceid], implode(',', $fields));
        foreach ($fields as $field) {
            if (empty($record->$field)) {
                continue;
            }

            $content = $record->$field;
            $hash = helper::extract_hash($content);
            if ($hash) {
                $hashes[$hash] = true; // Use array to avoid duplicates.
            }
        }

        if (empty($hashes)) {
            return;
        }

        // Update timemodified and timereviewed for all translations with these hashes.
        $placeholders = implode(',', array_fill(0, count($hashes), '?'));
        $sql = "UPDATE {filter_autotranslate_translations}
                SET timemodified = ?,
                    timereviewed = CASE WHEN timereviewed = 0 THEN ? ELSE timereviewed END
                WHERE hash IN ($placeholders)
                AND contextlevel = ?
                AND lang != 'other'";
        $params = array_merge([time(), time()], array_keys($hashes), [$context->contextlevel]);
        $this->db->execute($sql, $params);
    }
}
