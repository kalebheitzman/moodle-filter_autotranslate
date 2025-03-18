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
 * Autotranslate Translation Manager
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_autotranslate;

defined('MOODLE_INTERNAL') || die();

class translation_manager {
    private $repository;

    public function __construct(translation_repository $repository) {
        $this->repository = $repository;
    }

    public function get_paginated_translations($page, $perpage, $filter_lang, $filter_human, $sort, $dir, $courseid = 0) {
        $sitelang = get_config('core', 'lang') ?: 'en';
        $result = $this->repository->get_paginated_translations($page, $perpage, $filter_lang, $filter_human, $sort, $dir, $sitelang, $courseid);
        $translations = $result['translations'];
        $total = $result['total'];

        // Post-process translations to set source_text default
        foreach ($translations as $translation) {
            $translation->source_text = $translation->source_text ?: 'N/A';
        }

        return ['translations' => $translations, 'total' => $total];
    }

    public function update_human_status($translationid, $human) {
        $translation = $this->repository->get_translation($translationid, null);
        if ($translation) {
            $translation->human = $human;
            $translation->timemodified = time();
            $this->repository->update_translation($translation);
        }
    }
}