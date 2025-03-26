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
 * Autotranslate Translation Repository
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace filter_autotranslate;

defined('MOODLE_INTERNAL') || die();

/**
 * Database access layer for translations in the filter_autotranslate plugin.
 *
 * Purpose:
 * This class provides read-only access to translation data in the autotranslate_translations
 * table, serving as the data access layer for translation-related operations. It is used by
 * translation_manager.php to fetch translations for the manage page and by other components
 * (e.g., text_filter.php) to retrieve translations for display.
 *
 * Design Decisions:
 * - Focuses on read-only operations to adhere to the principle of separation of concerns.
 *   Database writes (e.g., updating translations) are handled by translation_service.php.
 * - Function names use snake_case (e.g., get_translation) to follow Moodle's coding style.
 * - The get_paginated_translations function includes complex filtering logic to support the
 *   manage page, which could be simplified in the future if needed.
 *
 * Dependencies:
 * - None (interacts directly with the Moodle database).
 */
class translation_repository {
    private $db;

    /**
     * Constructor for the translation repository.
     *
     * @param \moodle_database $db The Moodle database instance.
     */
    public function __construct(\moodle_database $db) {
        $this->db = $db;
    }

    /**
     * Returns the Moodle database instance.
     *
     * This function provides access to the database instance for internal use within the class.
     *
     * @return \moodle_database The Moodle database instance.
     */
    public function get_db(): \moodle_database {
        return $this->db;
    }

    /**
     * Fetches a translation by hash and language.
     *
     * This function retrieves a specific translation record from autotranslate_translations
     * based on the provided hash and language. If no language is specified, it fetches the
     * record by ID.
     *
     * @param string $hash The hash of the translation.
     * @param string|null $lang The language code (e.g., 'es', 'fr'), or null to fetch by ID.
     * @return object|null The translation record, or null if not found.
     */
    public function get_translation(string $hash, string $lang = null) {
        if ($lang) {
            return $this->db->get_record('autotranslate_translations', ['hash' => $hash, 'lang' => $lang]);
        }
        return $this->db->get_record('autotranslate_translations', ['id' => $hash]);
    }

    /**
     * Fetches the source text for a hash.
     *
     * This function retrieves the source text (lang = 'other') for a given hash from
     * autotranslate_translations, returning 'N/A' if not found.
     *
     * @param string $hash The hash of the translation.
     * @return string The source text, or 'N/A' if not found.
     */
    public function get_source_text(string $hash) {
        $source_text = $this->db->get_field('autotranslate_translations', 'translated_text', ['hash' => $hash, 'lang' => 'other']);
        return $source_text ?: 'N/A';
    }

    /**
     * Fetches all languages for a hash.
     *
     * This function retrieves a list of distinct language codes for a given hash from
     * autotranslate_translations, indicating which languages have translations.
     *
     * @param string $hash The hash of the translation.
     * @return array List of language codes.
     */
    public function get_all_languages(string $hash) {
        return $this->db->get_fieldset_select('autotranslate_translations', 'DISTINCT lang', 'hash = ?', [$hash]);
    }

    /**
     * Fetches paginated translations with filtering for the manage page.
     *
     * This function retrieves translations from autotranslate_translations with pagination
     * and filtering options (e.g., by language, human status, course ID, needs review).
     * It joins with the source text (lang = 'other') for display purposes.
     *
     * @param int $page The current page number.
     * @param int $perpage The number of records per page.
     * @param string $filter_lang The language to filter by (or 'all').
     * @param string $filter_human The human status to filter by ('' for all, '1' for human, '0' for machine).
     * @param string $sort The column to sort by (e.g., 'hash', 'lang').
     * @param string $dir The sort direction ('ASC' or 'DESC').
     * @param string $sitelang The site language (e.g., 'en').
     * @param int $courseid The course ID to filter by (0 for all).
     * @param string $filter_needsreview Filter by needs review status ('' for all, '1' for needs review, '0' for reviewed).
     * @return array An array containing:
     *               - 'translations': The paginated translation records.
     *               - 'total': The total number of matching records.
     */
    public function get_paginated_translations($page, $perpage, $filter_lang, $filter_human, $sort, $dir, $sitelang, $courseid = 0, $filter_needsreview = '') {
        $internal_filter_lang = ($filter_lang === $sitelang) ? 'other' : $filter_lang;

        $sql = "SELECT t.*, t2.translated_text AS source_text
                FROM {autotranslate_translations} t
                LEFT JOIN {autotranslate_translations} t2 ON t.hash = t2.hash AND t2.lang = 'other'";
        $params = [];

        $where = [];
        if (!empty($internal_filter_lang) && $internal_filter_lang !== 'all') {
            $where[] = "t.lang = :lang";
            $params['lang'] = $internal_filter_lang;
        }
        if ($filter_human !== '') {
            $where[] = "t.human = :human";
            $params['human'] = (int)$filter_human;
        }
        if ($courseid > 0) {
            $hashes = $this->db->get_fieldset_select('autotranslate_hid_cids', 'hash', 'courseid = :courseid', ['courseid' => $courseid]);
            if (!empty($hashes)) {
                list($insql, $inparams) = $this->db->get_in_or_equal($hashes, SQL_PARAMS_NAMED);
                $where[] = "t.hash $insql";
                $params = array_merge($params, $inparams);
            } else {
                $where[] = '1=0'; // No matches if no hashes for courseid
            }
        }
        if ($filter_needsreview !== '') {
            if ($filter_needsreview == '1') {
                $where[] = "t.timereviewed < t.timemodified";
            } elseif ($filter_needsreview == '0') {
                $where[] = "t.timereviewed >= t.timemodified";
            }
        }

        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        $valid_sorts = ['hash', 'lang', 'translated_text', 'human', 'contextlevel', 'timereviewed', 'timemodified'];
        $sort = in_array($sort, $valid_sorts) ? $sort : 'hash';
        $dir = strtoupper($dir) === 'DESC' ? 'DESC' : 'ASC';
        $sql .= " ORDER BY t.$sort $dir";

        $count_sql = "SELECT COUNT(*) FROM {autotranslate_translations} t" . (empty($where) ? "" : " WHERE " . implode(" AND ", $where));
        $total = $this->db->count_records_sql($count_sql, $params);
        $translations = $this->db->get_records_sql($sql, $params, $page * $perpage, $perpage);

        return ['translations' => $translations, 'total' => $total];
    }
}