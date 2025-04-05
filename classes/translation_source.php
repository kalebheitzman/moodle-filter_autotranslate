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
 * Translation Source for the Autotranslate Plugin
 *
 * Purpose:
 * This file defines the translation source for the filter_autotranslate plugin, providing read-only
 * access to translation data stored in the `filter_autotranslate_translations` table. It serves
 * as a data retrieval layer for `text_filter` to fetch translations during rendering and for
 * `ui_manager` to display translation lists, ensuring a clear separation from database writes
 * handled by `content_service`.
 *
 * Structure:
 * Contains the `translation_source` class with a single property `$db` (database access). Key
 * methods include `get_translation` (single translation fetch), `get_source_text` (source text
 * retrieval), `get_all_languages` (language list), `get_paginated_translations` (UI data), and
 * `get_untranslated_hashes` (autotranslate tasks).
 *
 * Usage:
 * Called by `text_filter` to retrieve translations for `{t:hash}` tags during text processing, by
 * `ui_manager` to fetch paginated translation data for management interfaces, and by `external.php`
 * to fetch untranslated entries for autotranslate tasks.
 *
 * Design Decisions:
 * - Focuses solely on read operations to maintain separation of concerns, leaving writes to
 *   `content_service`.
 * - Supports pagination and filtering for UI needs, joining source text (`lang = 'other'`) with
 *   translations for comprehensive display.
 * - Uses simple, efficient queries to minimize database load, leveraging existing indexes from
 *   the schema (e.g., `contextlevel`, `timereviewed`).
 * - Returns null or defaults (e.g., 'N/A') when data is unavailable, ensuring graceful fallbacks.
 *
 * Dependencies:
 * - `db/xmldb_filter_autotranslate.xml`: Defines the `filter_autotranslate_translations` table
 *   structure used for data retrieval.
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_autotranslate;

/**
 * Data access layer for translations in the filter_autotranslate plugin.
 */
class translation_source {
    /**
     * @var \moodle_database The Moodle database instance for read operations.
     */
    private $db;

    /**
     * Constructor for the translation source.
     *
     * Initializes the source with database access for retrieving translation data.
     *
     * @param \moodle_database $db The Moodle database instance.
     */
    public function __construct(\moodle_database $db) {
        $this->db = $db;
    }

    /**
     * Fetches a translation by hash and language.
     *
     * Retrieves a specific translation record from `filter_autotranslate_translations` based on
     * the provided hash and language, used by `text_filter` for tag replacement.
     *
     * @param string $hash The hash of the translation (e.g., 'abc1234567').
     * @param string|null $lang The language code (e.g., 'es', 'other'), or null to fetch by ID.
     * @return object|null The translation record, or null if not found.
     */
    public function get_translation(string $hash, ?string $lang = null) {
        if ($lang) {
            return $this->db->get_record('filter_autotranslate_translations', ['hash' => $hash, 'lang' => $lang]);
        }
        return $this->db->get_record('filter_autotranslate_translations', ['id' => $hash]);
    }

    /**
     * Fetches the source text for a hash.
     *
     * Retrieves the source text (where `lang = 'other'`) for a given hash, returning 'N/A' if not
     * found, used as a fallback when translations are unavailable.
     *
     * @param string $hash The hash of the translation.
     * @return string The source text, or 'N/A' if not found.
     */
    public function get_source_text(string $hash) {
        $sourcetext = $this->db->get_field(
            'filter_autotranslate_translations',
            'translated_text',
            ['hash' => $hash, 'lang' => 'other']
        );
        return $sourcetext ?: 'N/A';
    }

    /**
     * Fetches all languages for a hash.
     *
     * Returns a list of distinct language codes associated with a given hash, indicating available
     * translations for UI display or validation.
     *
     * @param string $hash The hash of the translation.
     * @return array List of language codes (e.g., ['en', 'es', 'other']).
     */
    public function get_all_languages(string $hash) {
        return $this->db->get_fieldset_select(
            'filter_autotranslate_translations',
            'DISTINCT lang',
            'hash = ?',
            [$hash]
        );
    }

    /**
     * Fetches paginated translations for UI display.
     *
     * Retrieves a paginated list of translations with filtering options (language, human status,
     * course ID, review status), joining with source text (`lang = 'other'`) for comprehensive data,
     * used by `ui_manager` for management interfaces.
     *
     * @param int $page The current page number (0-based).
     * @param int $perpage The number of records per page.
     * @param string $filterlang The language to filter by (e.g., 'es', 'all').
     * @param string $filterhuman The human status to filter by ('' for all, '1' for human, '0' for auto).
     * @param string $sort The column to sort by (e.g., 'hash', 'lang').
     * @param string $dir The sort direction ('ASC' or 'DESC').
     * @param int $courseid The course ID to filter by (0 for all).
     * @param string $filterneedsreview Filter by review status ('' for all, '1' for needs review, '0' for reviewed).
     * @return array An array with 'translations' (records) and 'total' (count of matching records).
     */
    public function get_paginated_translations(
        $page,
        $perpage,
        $filterlang,
        $filterhuman,
        $sort,
        $dir,
        $courseid = 0,
        $filterneedsreview = ''
    ) {
        $sitelang = get_config('core', 'lang') ?: 'en';
        $internalfilterlang = ($filterlang === $sitelang) ? 'other' : $filterlang;

        $params = [];
        $where = [];
        $joins = [];

        $sql = "SELECT t.*, t2.translated_text AS source_text, t2.timemodified AS source_timemodified
                FROM {filter_autotranslate_translations} t
                LEFT JOIN {filter_autotranslate_translations} t2 ON t.hash = t2.hash AND t2.lang = 'other'";

        // Only apply language filter if $internalfilterlang is non-empty and not 'all'.
        if (!empty($internalfilterlang) && $internalfilterlang !== 'all') {
            $where[] = "t.lang = :lang";
            $params['lang'] = $internalfilterlang;
        }

        if ($courseid > 0) {
            $hashes = $this->db->get_fieldset_select(
                'filter_autotranslate_hid_cids',
                'hash',
                'courseid = :courseid',
                ['courseid' => $courseid]
            );
            if (!empty($hashes)) {
                [$insql, $inparams] = $this->db->get_in_or_equal($hashes, SQL_PARAMS_NAMED);
                $where[] = "t.hash $insql";
                $params = array_merge($params, $inparams);
            } else {
                $where[] = '1=0';
            }
        }

        if ($filterhuman !== '') {
            $where[] = "t.human = :human";
            $params['human'] = (int)$filterhuman;
        }

        if ($filterneedsreview !== '') {
            if ($filterneedsreview == '1') {
                $where[] = "t.timereviewed < t.timemodified";
            } else if ($filterneedsreview == '0') {
                $where[] = "t.timereviewed >= t.timemodified";
            }
        }

        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $countsql = "SELECT COUNT(*) FROM {filter_autotranslate_translations} t " .
                    (empty($joins) ? '' : implode(' ', $joins)) .
                    (empty($where) ? '' : ' WHERE ' . implode(' AND ', $where));
        $total = $this->db->count_records_sql($countsql, $params);
        $translations = $this->db->get_records_sql($sql, $params, $page * $perpage, $perpage);

        return ['translations' => $translations, 'total' => $total];
    }

    /**
     * Fetches hashes of untranslated source translations for a target language with pagination.
     *
     * Retrieves the hashes of source translations (`lang = 'other'`) that do not have a corresponding
     * target translation for the specified language, applying filters for course ID, human status,
     * review status, and supporting pagination and sorting. Used by `external.php` for autotranslate tasks.
     *
     * @param int $page The current page number (0-based).
     * @param int $perpage The number of records per page (0 for no limit).
     * @param string $targetlang The target language to check for untranslated entries (e.g., 'es').
     * @param string $filterhuman The human status to filter by ('' for all, '1' for human, '0' for auto).
     * @param string $sort The column to sort by (e.g., 'hash', 'translated_text').
     * @param string $dir The sort direction ('ASC' or 'DESC').
     * @param int $courseid The course ID to filter by (0 for all).
     * @param string $filterneedsreview Filter by review status ('' for all, '1' for needs review, '0' for reviewed).
     * @return array List of hashes for untranslated entries.
     */
    public function get_untranslated_hashes(
        $page,
        $perpage,
        $targetlang,
        $filterhuman,
        $sort,
        $dir,
        $courseid = 0,
        $filterneedsreview = ''
    ) {
        $params = [];
        $where = [];
        $joins = [];

        // Fetch source translations that lack a target translation.
        $sql = "SELECT DISTINCT t.hash
                FROM {filter_autotranslate_translations} t
                LEFT JOIN {filter_autotranslate_translations} t2 ON t.hash = t2.hash AND t2.lang = :targetlang";
        $params['targetlang'] = $targetlang;

        // Always filter for source translations and ensure no target translation exists.
        $where[] = "t.lang = 'other'";
        $where[] = "t2.id IS NULL";

        // Filter by course ID.
        if ($courseid > 0) {
            $hashes = $this->db->get_fieldset_select(
                'filter_autotranslate_hid_cids',
                'hash',
                'courseid = :courseid',
                ['courseid' => $courseid]
            );
            if (!empty($hashes)) {
                [$insql, $inparams] = $this->db->get_in_or_equal($hashes, SQL_PARAMS_NAMED);
                $where[] = "t.hash $insql";
                $params = array_merge($params, $inparams);
            }
            // If no hashes are found for the course, we don't exclude all records.
            // Let the query proceed to find untranslated entries.
        }

        // Filter by human status.
        if ($filterhuman !== '') {
            $where[] = "t.human = :human";
            $params['human'] = (int)$filterhuman;
        }

        // Filter by needs review.
        if ($filterneedsreview !== '') {
            if ($filterneedsreview == '1') {
                $where[] = "t.timereviewed < t.timemodified";
            } else if ($filterneedsreview == '0') {
                $where[] = "t.timereviewed >= t.timemodified";
            }
        }

        if (!empty($joins)) {
            $sql .= ' ' . implode(' ', $joins);
        }
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        // Apply sorting.
        $validsorts = ['hash', 'translated_text', 'human', 'contextlevel', 'timereviewed', 'timemodified'];
        $sort = in_array($sort, $validsorts) ? $sort : 'hash';
        $dir = strtoupper($dir) === 'DESC' ? 'DESC' : 'ASC';
        $sql .= " ORDER BY t.$sort $dir";

        // Apply pagination.
        return $this->db->get_fieldset_sql($sql, $params, $page * $perpage, $perpage);
    }
}
