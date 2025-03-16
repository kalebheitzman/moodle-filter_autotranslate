<?php
namespace filter_autotranslate;

defined('MOODLE_INTERNAL') || die();

class translation_repository {
    private $db;

    public function __construct(\moodle_database $db) {
        $this->db = $db;
    }

    public function get_translation(string $hash, string $lang = null) {
        if ($lang) {
            return $this->db->get_record('autotranslate_translations', ['hash' => $hash, 'lang' => $lang]);
        }
        return $this->db->get_record('autotranslate_translations', ['id' => $hash]);
    }

    public function get_source_text(string $hash) {
        $source_text = $this->db->get_field('autotranslate_translations', 'translated_text', ['hash' => $hash, 'lang' => 'other']);
        return $source_text ?: 'N/A';
    }

    public function get_all_languages(string $hash) {
        return $this->db->get_fieldset_select('autotranslate_translations', 'DISTINCT lang', 'hash = ?', [$hash]);
    }

    public function update_translation(object $translation) {
        $this->db->update_record('autotranslate_translations', $translation);
    }

    public function get_paginated_translations($page, $perpage, $filter_lang, $filter_human, $sort, $dir, $sitelang) {
        $internal_filter_lang = ($filter_lang === $sitelang) ? 'other' : $filter_lang;
        debugging("Filter lang: $filter_lang, Internal filter lang: $internal_filter_lang", DEBUG_DEVELOPER);

        $sql = "SELECT t.*, t2.translated_text AS source_text
                FROM {autotranslate_translations} t
                LEFT JOIN {autotranslate_translations} t2 ON t.hash = t2.hash AND t2.lang = 'other'";
        $params = [];

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

        $valid_sorts = ['hash', 'lang', 'translated_text', 'human', 'contextlevel'];
        $sort = in_array($sort, $valid_sorts) ? $sort : 'hash';
        $dir = strtoupper($dir) === 'DESC' ? 'DESC' : 'ASC';
        $sql .= " ORDER BY t.$sort $dir";

        $count_sql = "SELECT COUNT(*) FROM {autotranslate_translations} t" . (empty($params) ? "" : " WHERE " . implode(" AND ", $where));
        debugging("Count SQL: $count_sql with params: " . json_encode($params), DEBUG_DEVELOPER);
        $total = $this->db->count_records_sql($count_sql, $params);
        debugging("Main SQL: $sql with params: " . json_encode($params), DEBUG_DEVELOPER);
        $translations = $this->db->get_records_sql($sql, $params, $page * $perpage, $perpage);

        return ['translations' => $translations, 'total' => $total];
    }
}