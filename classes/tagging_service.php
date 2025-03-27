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

/**
 * Service class for handling tagging-related database operations in the filter_autotranslate plugin.
 */
class tagging_service {
    /**
     * @var \moodle_database
     */
    private $db;

    /**
     * Constructor for the tagging_service class.
     *
     * @param \moodle_database $db The database object.
     */
    public function __construct(\moodle_database $db) {
        $this->db = $db;
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
            if (helper::is_tagged($content)) {
                // Update hash-course mapping and source translation for already tagged content.
                $this->update_hash_course_mapping($content, $courseid, 'other');
                $this->create_or_update_source_translation($content, $context->contextlevel);
                continue;
            }

            $mlangresult = helper::process_mlang_tags($content, $context);
            $sourcetext = $mlangresult['source_text'];
            $displaytext = $mlangresult['display_text'];
            $translations = $mlangresult['translations'];

            if (!empty($sourcetext)) {
                $params = ['lang' => 'other', 'text' => $sourcetext];
                $sql = "SELECT hash FROM {filter_autotranslate_translations} WHERE lang = :lang
                        AND " . $this->db->sql_compare_text('translated_text') . " = " . $this->db->sql_compare_text(':text');
                $existing = $this->db->get_record_sql($sql, $params, IGNORE_MULTIPLE);

                $hash = $existing ? $existing->hash : helper::generate_unique_hash();

                if (!$existing) {
                    $sourcetext = $this->rewrite_pluginfile_urls($sourcetext, $context, $table, $field, $record->id);
                    $recorddata = new \stdClass();
                    $recorddata->hash = $hash;
                    $recorddata->lang = 'other';
                    $recorddata->translated_text = $sourcetext;
                    $recorddata->contextlevel = $context->contextlevel;
                    $recorddata->timecreated = time();
                    $recorddata->timemodified = time();
                    $recorddata->timereviewed = time();
                    $recorddata->human = 1;
                    $this->db->insert_record('filter_autotranslate_translations', $recorddata);

                    foreach ($translations as $lang => $translatedtext) {
                        $translatedtext = $this->rewrite_pluginfile_urls($translatedtext, $context, $table, $field, $record->id);
                        $transrecord = new \stdClass();
                        $transrecord->hash = $hash;
                        $transrecord->lang = $lang;
                        $transrecord->translated_text = $translatedtext;
                        $transrecord->contextlevel = $context->contextlevel;
                        $transrecord->timecreated = time();
                        $transrecord->timemodified = time();
                        $transrecord->timereviewed = time();
                        $transrecord->human = 1;
                        $this->db->insert_record('filter_autotranslate_translations', $transrecord);
                    }
                }

                $taggedcontent = $displaytext . " {t:$hash}";
            } else {
                $taggedcontent = helper::tag_content($content, $context);
                $hash = helper::extract_hash($taggedcontent);
                $content = $this->rewrite_pluginfile_urls($content, $context, $table, $field, $record->id);
                $this->create_or_update_source_translation($taggedcontent, $context->contextlevel);
            }

            // Only update hid_cids for 'other' language.
            $this->update_hash_course_mapping($taggedcontent, $courseid, 'other');

            $record->$field = $taggedcontent;
            $updated = true;
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
     * @param string $lang The language of the translation (only 'other' updates hid_cids).
     */
    public function update_hash_course_mapping($content, $courseid, $lang = 'other') {
        $hash = helper::extract_hash($content);
        if (!$hash || !$courseid || $lang !== 'other') {
            return; // Only update hid_cids for 'other' language.
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

        // Check if a source translation record already exists.
        $existing = $this->db->get_record('filter_autotranslate_translations', ['hash' => $hash, 'lang' => 'other']);
        $currenttime = time();

        if ($existing) {
            // Update the existing record.
            $existing->translated_text = $sourcetext;
            $existing->contextlevel = $contextlevel;
            $existing->timemodified = $currenttime;
            $existing->timereviewed = $existing->timereviewed == 0 ? $currenttime : $existing->timereviewed;
            $existing->human = 1; // Human-edited, as per observer requirement.
            $this->db->update_record('filter_autotranslate_translations', $existing);
        } else {
            // Create a new record.
            $record = new \stdClass();
            $record->hash = $hash;
            $record->lang = 'other';
            $record->translated_text = $sourcetext;
            $record->contextlevel = $contextlevel;
            $record->timecreated = $currenttime;
            $record->timemodified = $currenttime;
            $record->timereviewed = $currenttime;
            $record->human = 1; // Human-edited, as per observer requirement.
            $this->db->insert_record('filter_autotranslate_translations', $record);
        }
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
        // Find all hashes associated with the updated fields.
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

    /**
     * Rewrites @@PLUGINFILE@@ URLs in content based on the context and table.
     *
     * This function determines the correct component, filearea, and itemid for the content
     * and rewrites @@PLUGINFILE@@ URLs to their proper form before storing the translation.
     *
     * TODO: Breaks from manage rebuild translations button
     *
     * @param string $content The content to rewrite.
     * @param \context $context The context object for the content.
     * @param string $table The table name (e.g., 'course', 'book_chapters').
     * @param string $field The field name (e.g., 'summary', 'content').
     * @param int $itemid The item ID (e.g., record ID).
     * @return string The content with rewritten URLs.
     */
    private function rewrite_pluginfile_urls($content, $context, $table, $field, $itemid) {
        return $content;
    }
}
