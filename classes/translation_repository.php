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
 * Autotranslate Translation Repository
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_autotranslate;

defined('MOODLE_INTERNAL') || die();

class translation_repository {
    private $db;

    public function __construct(\moodle_database $db) {
        $this->db = $db;
    }

    public function get_db(): \moodle_database {
        return $this->db;
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

    public function get_paginated_translations($page, $perpage, $filter_lang, $filter_human, $sort, $dir, $sitelang, $courseid = 0, $filter_needsreview = '') {
        $internal_filter_lang = ($filter_lang === $sitelang) ? 'other' : $filter_lang;
        // debugging("Filter lang: $filter_lang, Internal filter lang: $internal_filter_lang, Courseid: $courseid, Filter needsreview: $filter_needsreview", DEBUG_DEVELOPER);

        $sql = "SELECT t.*, t2.translated_text AS source_text
                FROM {autotranslate_translations} t
                LEFT JOIN {autotranslate_translations} t2 ON t.hash = t2.hash AND t2.lang = 'other'";
        $params = [];

        $where = [];
        if (!empty($internal_filter_lang) && $internal_filter_lang !== 'all') {
            $where[] = "t.lang = :lang";
            $params['lang'] = $internal_filter_lang;
            // debugging("Applied filter: lang = $internal_filter_lang", DEBUG_DEVELOPER);
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
                // debugging("Applied courseid filter: hash IN (" . implode(',', $hashes) . ")", DEBUG_DEVELOPER);
            } else {
                $where[] = '1=0'; // No matches if no hashes for courseid
                // debugging("No hashes found for courseid=$courseid", DEBUG_DEVELOPER);
            }
        }
        if ($filter_needsreview !== '') {
            if ($filter_needsreview == '1') {
                $where[] = "t.timereviewed < t.timemodified";
                // debugging("Applied filter: timereviewed < timemodified", DEBUG_DEVELOPER);
            } elseif ($filter_needsreview == '0') {
                $where[] = "t.timereviewed >= t.timemodified";
                // debugging("Applied filter: timereviewed >= timemodified", DEBUG_DEVELOPER);
            }
        }

        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
            // debugging("SQL WHERE clause: " . implode(" AND ", $where), DEBUG_DEVELOPER);
        }

        $valid_sorts = ['hash', 'lang', 'translated_text', 'human', 'contextlevel', 'timereviewed', 'timemodified'];
        $sort = in_array($sort, $valid_sorts) ? $sort : 'hash';
        $dir = strtoupper($dir) === 'DESC' ? 'DESC' : 'ASC';
        $sql .= " ORDER BY t.$sort $dir";

        $count_sql = "SELECT COUNT(*) FROM {autotranslate_translations} t" . (empty($where) ? "" : " WHERE " . implode(" AND ", $where));
        // debugging("Count SQL: $count_sql with params: " . json_encode($params), DEBUG_DEVELOPER);
        $total = $this->db->count_records_sql($count_sql, $params);
        // debugging("Main SQL: $sql with params: " . json_encode($params), DEBUG_DEVELOPER);
        $translations = $this->db->get_records_sql($sql, $params, $page * $perpage, $perpage);

        return ['translations' => $translations, 'total' => $total];
    }
}