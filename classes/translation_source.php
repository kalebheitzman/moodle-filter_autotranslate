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
 * Translation source for the Autotranslate plugin.
 *
 * Provides read-only access to translation data in `filter_autotranslate_translations`.
 * Serves data to `text_filter` for rendering, `ui_manager` for UI display, and `external`
 * for autotranslate tasks. Focuses on efficient retrieval with pagination and filtering.
 *
 * Features:
 * - Fetches translations by hash and language for rendering.
 * - Retrieves source text (lang = 'other') with 'N/A' fallback.
 * - Supports paginated lists for UI and untranslated hashes for tasks.
 *
 * Usage:
 * - Called by `text_filter` to replace {t:hash} tags during rendering.
 * - Used by `ui_manager` to display translations in manage.php.
 * - Queried by `external.php` to identify untranslated entries.
 *
 * Design:
 * - Read-only, with writes handled by `content_service`.
 * - Optimized queries with joins for source text and pagination.
 * - Returns null or 'N/A' for missing data, ensuring graceful fallbacks.
 *
 * Dependencies:
 * - `db/xmldb_filter_autotranslate.xml`: Defines the translation table structure.
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_autotranslate;

/**
 * Data access layer for translations in the Autotranslate plugin.
 */
class translation_source {
    /**
     * @var \moodle_database Moodle database instance for read operations.
     */
    private $db;

    /**
     * Constructs the translation source with a database instance.
     *
     * @param \moodle_database $db Moodle database for data retrieval.
     */
    public function __construct(\moodle_database $db) {
        $this->db = $db;
    }

    /**
     * Fetches a translation by hash and language.
     *
     * Retrieves a record from `filter_autotranslate_translations` for a hash and language,
     * used by `text_filter` to replace {t:hash} tags. Returns null if not found.
     *
     * @param string $hash The hash of the translation (e.g., 'abc1234567').
     * @param string|null $lang Language code (e.g., 'es'), or null to fetch by ID.
     * @return object|null Translation record or null if not found.
     */
    public function get_translation(string $hash, ?string $lang = null) {
        if ($lang) {
            return $this->db->get_record('filter_autotranslate_translations', ['hash' => $hash, 'lang' => $lang]);
        }
        return $this->db->get_record('filter_autotranslate_translations', ['id' => $hash]);
    }

    /**
     * Fetches source text for a hash.
     *
     * Retrieves the source text (lang = 'other') for a hash, returning 'N/A' if not found,
     * used as a fallback by `text_filter`.
     *
     * @param string $hash The hash of the translation.
     * @return string Source text or 'N/A' if not found.
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
     * Fetches all language codes for a hash.
     *
     * Returns distinct language codes for a hash, used for UI display or validation.
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
     * Retrieves translations with filters (language, human status, course ID, review status),
     * joining source text (lang = 'other') for display in `manage.php` via `ui_manager`.
     *
     * @param int $page Page number (0-based).
     * @param int $perpage Records per page.
     * @param string $filterlang Language filter ('all', 'es', etc.).
     * @param string $filterhuman Human status filter ('' for all, '1' human, '0' auto).
     * @param string $sort Column to sort (e.g., 'hash', 'lang').
     * @param string $dir Sort direction ('ASC' or 'DESC').
     * @param int $courseid Course ID filter (0 for all).
     * @param string $filterneedsreview Review status filter ('' all, '1' needs, '0' reviewed).
     * @return array ['translations' => records, 'total' => count].
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

        // Base query with join to source text.
        $sql = "SELECT t.*, t2.translated_text AS source_text, t2.timemodified AS source_timemodified
                FROM {filter_autotranslate_translations} t
                LEFT JOIN {filter_autotranslate_translations} t2 ON t.hash = t2.hash AND t2.lang = 'other'";
        $joins[] = "LEFT JOIN {filter_autotranslate_translations} t2 ON t.hash = t2.hash AND t2.lang = 'other'";

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

        // Filter by needs review using source_timemodified vs timemodified.
        if ($filterneedsreview !== '') {
            if ($filterneedsreview == '1') {
                $where[] = "t2.timemodified > t.timemodified";
            } else if ($filterneedsreview == '0') {
                $where[] = "t2.timemodified <= t.timemodified";
            }
        }

        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        // Include joins in count query to support t2.timemodified.
        $countsql = "SELECT COUNT(*) FROM {filter_autotranslate_translations} t " .
                    implode(' ', $joins) .
                    (empty($where) ? '' : ' WHERE ' . implode(' AND ', $where));
        $total = $this->db->count_records_sql($countsql, $params);
        $translations = $this->db->get_records_sql($sql, $params, $page * $perpage, $perpage);

        return ['translations' => $translations, 'total' => $total];
    }

    /**
     * Fetches paginated source and target translations for a language.
     *
     * Retrieves source (lang = 'other') and target translations for a language, with filters,
     * for target language view in `manage.php` via `ui_manager`.
     *
     * @param int $page Page number (0-based).
     * @param int $perpage Records per page.
     * @param string $targetlang Target language (e.g., 'es').
     * @param string $filterhuman Human status filter ('' for all, '1' human, '0' auto).
     * @param string $sort Column to sort (e.g., 'hash', 'lang').
     * @param string $dir Sort direction ('ASC' or 'DESC').
     * @param int $courseid Course ID filter (0 for all).
     * @param string $filterneedsreview Review status filter ('' all, '1' needs, '0' reviewed).
     * @return array ['translations' => records, 'total' => count].
     */
    public function get_paginated_target_translations(
        $page,
        $perpage,
        $targetlang,
        $filterhuman,
        $sort,
        $dir,
        $courseid = 0,
        $filterneedsreview = ''
    ) {
        $params = ['targetlang' => $targetlang];
        $where = [];
        $joins = [];

        $sql = "SELECT t_other.hash, t_other.translated_text AS source_text, t_other.timemodified AS source_timemodified,
                    t_target.id AS target_id, t_target.lang AS target_lang,
                    t_target.translated_text AS target_text, t_target.human,
                    t_other.contextlevel AS source_contextlevel, t_target.contextlevel AS target_contextlevel,
                    t_target.timecreated, t_target.timemodified
                FROM {filter_autotranslate_translations} t_other
                LEFT JOIN {filter_autotranslate_translations} t_target
                    ON t_other.hash = t_target.hash AND t_target.lang = :targetlang
                WHERE t_other.lang = 'other'";
        $joins[] = "LEFT JOIN {filter_autotranslate_translations} t_target
                    ON t_other.hash = t_target.hash AND t_target.lang = :targetlang_count";

        if ($courseid > 0) {
            $hashes = $this->db->get_fieldset_select(
                'filter_autotranslate_hid_cids',
                'hash',
                'courseid = :courseid',
                ['courseid' => $courseid]
            );
            if (!empty($hashes)) {
                [$insql, $inparams] = $this->db->get_in_or_equal($hashes, SQL_PARAMS_NAMED);
                $where[] = "t_other.hash $insql";
                $params = array_merge($params, $inparams);
            } else {
                $where[] = '1=0';
            }
        }

        if ($filterhuman !== '') {
            $where[] = "t_target.human = :human";
            $params['human'] = (int)$filterhuman;
        }

        if ($filterneedsreview !== '') {
            if ($filterneedsreview == '1') {
                $where[] = "(t_target.timemodified IS NULL OR t_other.timemodified > t_target.timemodified)";
            } else if ($filterneedsreview == '0') {
                $where[] = "t_target.timemodified IS NOT NULL AND t_other.timemodified <= t_target.timemodified";
            }
        }

        if (!empty($where)) {
            $sql .= ' AND ' . implode(' AND ', $where);
        }

        $validsorts = ['hash', 'translated_text', 'human', 'contextlevel', 'timemodified'];
        $sort = in_array($sort, $validsorts) ? $sort : 'hash';
        $dir = strtoupper($dir) === 'DESC' ? 'DESC' : 'ASC';
        $sql .= " ORDER BY t_other.$sort $dir";

        $countsql = "SELECT COUNT(*) FROM {filter_autotranslate_translations} t_other " .
                    implode(' ', $joins) .
                    (empty($where)
                        ? " WHERE t_other.lang = 'other'"
                        : " WHERE t_other.lang = 'other' AND " . implode(' AND ', $where));
        $total = $this->db->count_records_sql($countsql, array_merge($params, ['targetlang_count' => $targetlang]));
        $translations = $this->db->get_records_sql($sql, $params, $page * $perpage, $perpage);

        return ['translations' => $translations, 'total' => $total];
    }

    /**
     * Fetches untranslated hashes for a target language with pagination.
     *
     * Retrieves hashes of source translations (lang = 'other') without target translations,
     * for autotranslate tasks via `external.php`, with filters and pagination.
     *
     * @param int $page Page number (0-based).
     * @param int $perpage Records per page (0 for no limit).
     * @param string $targetlang Target language (e.g., 'es').
     * @param string $filterhuman Human status filter ('' for all, '1' human, '0' auto).
     * @param string $sort Column to sort (e.g., 'hash', 'translated_text').
     * @param string $dir Sort direction ('ASC' or 'DESC').
     * @param int $courseid Course ID filter (0 for all).
     * @param string $filterneedsreview Review status filter ('' all, '1' needs, '0' reviewed).
     * @return array List of untranslated hashes.
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
                $where[] = "t2.timemodified > t.timemodified";
            } else if ($filterneedsreview == '0') {
                $where[] = "t2.timemodified <= t.timemodified";
            }
        }

        if (!empty($joins)) {
            $sql .= ' ' . implode(' ', $joins);
        }
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        // Apply sorting.
        $validsorts = ['hash', 'translated_text', 'human', 'contextlevel', 'timemodified'];
        $sort = in_array($sort, $validsorts) ? $sort : 'hash';
        $dir = strtoupper($dir) === 'DESC' ? 'DESC' : 'ASC';
        $sql .= " ORDER BY t.$sort $dir";

        // Apply pagination.
        return $this->db->get_fieldset_sql($sql, $params, $page * $perpage, $perpage);
    }
}
