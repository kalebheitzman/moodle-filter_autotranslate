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
 * UI Manager for the Autotranslate Plugin
 *
 * Purpose:
 * This file defines the UI manager for the filter_autotranslate plugin, coordinating user interface
 * operations such as displaying paginated translation lists and triggering actions like marking
 * translations stale. It acts as a bridge between UI components (e.g., management pages) and the
 * underlying data and service layers, ensuring a seamless user experience without direct database
 * manipulation.
 *
 * Structure:
 * Contains the `ui_manager` class with properties for `$translationsource` (data retrieval) and
 * `$contentservice` (service operations). Key methods include `get_paginated_translations` (UI data
 * fetch) and `mark_course_stale` (staleness trigger).
 *
 * Usage:
 * Called by UI components (e.g., manage.php) to retrieve translation data for display or to initiate
 * actions like marking a course’s translations stale for lazy rebuilding. It delegates data retrieval
 * to `translation_source` and updates to `content_service`.
 *
 * Design Decisions:
 * - Focuses on UI coordination, avoiding direct database access to maintain separation of concerns,
 *   with reads via `translation_source` and writes via `content_service`.
 * - Implements the Option 3 (Mark Stale and Lazy Rebuild) strategy by providing a method to flag
 *   translations as stale, complementing the lazy refresh in `content_service`.
 * - Keeps logic lightweight, acting as a pass-through to underlying services, making it extensible
 *   for future UI features (e.g., translation editing).
 * - Uses global $DB for simplicity, as it’s a high-level coordinator instantiated in UI scripts.
 *
 * Dependencies:
 * - `translation_source.php`: Provides read-only access to translation data for display.
 * - `content_service.php`: Handles database operations, including marking translations stale.
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_autotranslate;

use filter_autotranslate\translation_source;
use filter_autotranslate\content_service;

/**
 * Class to coordinate UI operations for the filter_autotranslate plugin.
 */
class ui_manager {
    /**
     * @var translation_source The source instance for retrieving translation data.
     */
    private $translationsource;

    /**
     * @var content_service The service instance for managing content operations.
     */
    private $contentservice;

    /**
     * Constructor for the UI manager.
     *
     * Initializes the manager with instances of the translation source and content service using
     * the global $DB object for database access.
     */
    public function __construct() {
        global $DB;
        $this->translationsource = new translation_source($DB);
        $this->contentservice = new content_service($DB);
    }

    /**
     * Fetches paginated translations for UI display.
     *
     * Retrieves a paginated list of translations from `translation_source`, ensuring source text is
     * always available (defaulting to 'N/A' if missing), used for management interfaces.
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
        $result = $this->translationsource->get_paginated_translations(
            $page,
            $perpage,
            $filterlang,
            $filterhuman,
            $sort,
            $dir,
            $courseid,
            $filterneedsreview
        );

        $translations = $result['translations'];
        $total = $result['total'];

        // Ensure source text defaults to 'N/A' if missing for UI consistency.
        foreach ($translations as $translation) {
            $translation->source_text = $translation->source_text ?: 'N/A';
        }

        return ['translations' => $translations, 'total' => $total];
    }

    /**
     * Fetches paginated source and target translations for a specific language.
     *
     * Retrieves source translations (`lang = 'other'`) and their target counterparts for a given
     * language, supporting pagination and filtering for UI display in target language view.
     *
     * @param int $page The current page number (0-based).
     * @param int $perpage The number of records per page.
     * @param string $targetlang The target language to fetch (e.g., 'es').
     * @param string $filterhuman The human status to filter by ('' for all, '1' for human, '0' for auto).
     * @param string $sort The column to sort by (e.g., 'hash', 'lang').
     * @param string $dir The sort direction ('ASC' or 'DESC').
     * @param int $courseid The course ID to filter by (0 for all).
     * @param string $filterneedsreview Filter by review status ('' for all, '1' for needs review, '0' for reviewed).
     * @return array An array with 'translations' (records) and 'total' (count of matching records).
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
        $result = $this->translationsource->get_paginated_target_translations(
            $page,
            $perpage,
            $targetlang,
            $filterhuman,
            $sort,
            $dir,
            $courseid,
            $filterneedsreview
        );

        $translations = $result['translations'];
        $total = $result['total'];

        // Ensure source text defaults to 'N/A' if missing (unlikely with INNER JOIN, but for consistency).
        foreach ($translations as $translation) {
            $translation->source_text = $translation->source_text ?: 'N/A';
        }

        return ['translations' => $translations, 'total' => $total];
    }

    /**
     * Marks all translations for a course as stale.
     *
     * Triggers `content_service` to mark translations associated with a course as stale, enabling
     * lazy rebuilding when next processed by `text_filter`.
     *
     * @param int $courseid The course ID to mark stale.
     */
    public function mark_course_stale($courseid) {
        $this->contentservice->mark_course_stale($courseid);
    }
}
