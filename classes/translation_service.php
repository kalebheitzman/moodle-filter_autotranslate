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
 * Autotranslate Translation Service
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace filter_autotranslate;

defined('MOODLE_INTERNAL') || die();

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
 *
 * Dependencies:
 * - None (interacts directly with the Moodle database).
 */
class translation_service {
    private $db;

    /**
     * Constructor for the translation service.
     *
     * @param \moodle_database $db The Moodle database instance.
     */
    public function __construct(\moodle_database $db = null) {
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
     * @param string|array $translated_text The translated text (string or array to handle potential API responses).
     * @param int $contextlevel The context level for the translation.
     */
    public function store_translation($hash, $lang, $translated_text, $contextlevel) {
        // Ensure translated_text is a string
        $translated_text = is_array($translated_text) ? implode(' ', $translated_text) : (string)$translated_text;

        // Check if a translation already exists for this hash and lang
        $existing = $this->db->get_record('autotranslate_translations', ['hash' => $hash, 'lang' => $lang]);
        if ($existing) {
            // Update the existing record
            $record = new \stdClass();
            $record->id = $existing->id;
            $record->translated_text = $translated_text;
            $record->contextlevel = $contextlevel;
            $record->timemodified = time();
            $record->timereviewed = time();
            $record->human = 0; // Machine-generated translation

            $this->db->update_record('autotranslate_translations', $record);
        } else {
            // Insert a new record
            $record = new \stdClass();
            $record->hash = $hash;
            $record->lang = $lang;
            $record->translated_text = $translated_text;
            $record->contextlevel = $contextlevel;
            $record->timecreated = time();
            $record->timemodified = time();
            $record->timereviewed = time();
            $record->human = 0; // Machine-generated translation

            $this->db->insert_record('autotranslate_translations', $record);
        }
    }
}