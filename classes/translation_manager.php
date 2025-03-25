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

require_once($CFG->dirroot . '/filter/autotranslate/classes/translation_service.php');

/**
 * Class to coordinate translation management for the filter_autotranslate plugin.
 *
 * Purpose:
 * This class acts as a middle layer between the UI (e.g., manage.php) and the translation
 * repository (translation_repository.php), coordinating the retrieval and management of
 * translations. It does not perform database updates directly, delegating such operations
 * to translation_service.php to ensure a clear separation of concerns.
 *
 * Design Decisions:
 * - Focuses on coordination logic only, with no direct database operations, to adhere to the
 *   principle of separation of concerns.
 * - Function names use snake_case (e.g., get_paginated_translations) to follow Moodle's coding style.
 * - The update_human_status function delegates database updates to translation_service.php,
 *   ensuring this class remains focused on coordination.
 *
 * Dependencies:
 * - translation_repository.php: Provides read-only access to translation data.
 * - translation_service.php: Handles database operations for translations (e.g., updating records).
 */
class translation_manager {
    private $repository;
    private $service;

    /**
     * Constructor for the translation manager.
     *
     * @param translation_repository $repository The translation repository instance.
     * @param translation_service $service The translation service instance (optional, defaults to new instance).
     */
    public function __construct(translation_repository $repository, translation_service $service = null) {
        $this->repository = $repository;
        $this->service = $service ?? new translation_service();
    }

    /**
     * Fetches paginated translations for the manage page.
     *
     * This function coordinates the retrieval of translations with pagination and filtering,
     * using the translation_repository to fetch data. It post-processes the results to ensure
     * the source text is always available for display.
     *
     * @param int $page The current page number.
     * @param int $perpage The number of records per page.
     * @param string $filter_lang The language to filter by (or 'all').
     * @param string $filter_human The human status to filter by ('' for all, '1' for human, '0' for machine).
     * @param string $sort The column to sort by (e.g., 'hash', 'lang').
     * @param string $dir The sort direction ('ASC' or 'DESC').
     * @param int $courseid The course ID to filter by (0 for all).
     * @param string $filter_needsreview Filter by needs review status ('' for all, '1' for needs review, '0' for reviewed).
     * @return array An array containing:
     *               - 'translations': The paginated translation records.
     *               - 'total': The total number of matching records.
     */
    public function get_paginated_translations($page, $perpage, $filter_lang, $filter_human, $sort, $dir, $courseid = 0, $filter_needsreview = '') {
        $sitelang = get_config('core', 'lang') ?: 'en';
        $result = $this->repository->get_paginated_translations($page, $perpage, $filter_lang, $filter_human, $sort, $dir, $sitelang, $courseid, $filter_needsreview);
        $translations = $result['translations'];
        $total = $result['total'];

        // Post-process translations to set source_text default
        foreach ($translations as $translation) {
            $translation->source_text = $translation->source_text ?: 'N/A';
        }

        return ['translations' => $translations, 'total' => $total];
    }

    /**
     * Updates the human status of a translation.
     *
     * This function coordinates the update of a translation's human status (e.g., marking it as
     * human-edited or machine-generated), delegating the database update to translation_service.php.
     *
     * @param int $translationid The ID of the translation record.
     * @param int $human The human status (1 for human-edited, 0 for machine-generated).
     */
    public function update_human_status($translationid, $human) {
        $translation = $this->repository->get_translation($translationid, null);
        if ($translation) {
            $translation->human = $human;
            $this->service->update_human_status($translation);
        }
    }
}