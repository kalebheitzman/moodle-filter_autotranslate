<?php
namespace filter_autotranslate;

defined('MOODLE_INTERNAL') || die();

class translation_manager {
    private $db;

    public function __construct() {
        global $DB;
        $this->db = $DB;
    }

    /**
     * Get paginated translations with filters and sorting, including source text if filtered by a specific language.
     *
     * @param int $page Current page
     * @param int $perpage Records per page
     * @param string $filter_lang Filter by language
     * @param string $filter_human Filter by human status ('', '0', '1')
     * @param string $sort Sort column
     * @param string $dir Sort direction
     * @return array Paginated translations with source text if applicable
     */
    public function get_paginated_translations($page, $perpage, $filter_lang, $filter_human, $sort, $dir) {
        $sitelang = get_config('core', 'lang') ?: 'en';
        $internal_filter_lang = ($filter_lang === $sitelang) ? 'other' : $filter_lang;
        debugging("Filter lang: $filter_lang, Internal filter lang: $internal_filter_lang", DEBUG_DEVELOPER);

        // Base query for translations
        $sql = "SELECT t.*, t2.translated_text AS source_text
                FROM {autotranslate_translations} t
                LEFT JOIN {autotranslate_translations} t2 ON t.hash = t2.hash AND t2.lang = 'other'";
        $params = [];

        // Apply filters
        $where = [];
        if (!empty($internal_filter_lang) && $internal_filter_lang !== 'all') {
            $where[] = "t.lang = :lang";
            $params['lang'] = $internal_filter_lang;
            debugging("Applied filter: lang = $internal_filter_lang", DEBUG_DEVELOPER);
        }
        if ($filter_human !== '') {
            $where[] = "t.human = :human";
            $params['human'] = (int)$filter_human;
        }
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
            debugging("SQL WHERE clause: " . implode(" AND ", $where), DEBUG_DEVELOPER);
        }

        // Apply sorting
        $valid_sorts = ['hash', 'lang', 'translated_text', 'human', 'contextlevel'];
        $sort = in_array($sort, $valid_sorts) ? $sort : 'hash';
        $dir = strtoupper($dir) === 'DESC' ? 'DESC' : 'ASC';
        $sql .= " ORDER BY t.$sort $dir";

        // Pagination
        $count_sql = "SELECT COUNT(*) FROM {autotranslate_translations} t" . (empty($params) ? "" : " WHERE " . implode(" AND ", $where));
        debugging("Count SQL: $count_sql with params: " . json_encode($params), DEBUG_DEVELOPER);
        $total = $this->db->count_records_sql($count_sql, $params);
        debugging("Main SQL: $sql with params: " . json_encode($params), DEBUG_DEVELOPER);
        $translations = $this->db->get_records_sql($sql, $params, $page * $perpage, $perpage);

        // Set source_text to N/A if not found (already handled by LEFT JOIN returning NULL)
        foreach ($translations as $translation) {
            $translation->source_text = $translation->source_text ?: 'N/A';
        }

        return ['translations' => $translations, 'total' => $total];
    }

    /**
     * Update human status for a translation.
     *
     * @param int $translationid Translation ID
     * @param int $human New human status
     */
    public function update_human_status($translationid, $human) {
        $translation = $this->db->get_record('autotranslate_translations', ['id' => $translationid], '*', MUST_EXIST);
        $translation->human = $human;
        $translation->timemodified = time();
        $this->db->update_record('autotranslate_translations', $translation);
    }
}