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
 * UI manager for the Autotranslate plugin.
 *
 * Coordinates UI operations, fetching paginated translation data for `manage.php`
 * from `translation_source`. Acts as a bridge between UI and data layer.
 *
 * Features:
 * - Provides paginated translations with source text fallbacks.
 * - Supports target language views with source-target pairing.
 *
 * Usage:
 * - Called by `manage.php` to display translation tables.
 *
 * Design:
 * - Delegates reads to `translation_source` for data consistency.
 * - Lightweight, avoids direct database manipulation.
 *
 * Dependencies:
 * - `translation_source.php`: Fetches translation data.
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_autotranslate;

use filter_autotranslate\translation_source;

/**
 * Coordinates UI operations for the Autotranslate plugin.
 */
class ui_manager {
    /**
     * @var translation_source Fetches translation data.
     */
    private $translationsource;

    /**
     * Constructs the UI manager with a data instance.
     *
     * Initializes with global $DB for `translation_source`.
     */
    public function __construct() {
        global $DB;
        $this->translationsource = new translation_source($DB);
    }

    /**
     * Fetches paginated translations for UI display.
     *
     * Retrieves translations from `translation_source` with filters, ensuring source text
     * defaults to 'N/A' if missing, for `manage.php` display.
     *
     * @param int $page Page number (0-based).
     * @param int $perpage Records per page.
     * @param string $filterlang Language filter ('all', 'es', etc.).
     * @param string $filterhuman Human status ('' all, '1' human, '0' auto).
     * @param string $sort Sort column (e.g., 'hash').
     * @param string $dir Sort direction ('ASC' or 'DESC').
     * @param int $courseid Course ID filter (0 for all).
     * @param string $filterneedsreview Review status ('' all, '1' needs, '0' reviewed).
     * @return array ['translations' => records, 'total' => count]
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
     * Fetches paginated source and target translations.
     *
     * Retrieves source (lang = 'other') and target translations for a language, for
     * target view in `manage.php`, with filters and 'N/A' source fallback.
     *
     * @param int $page Page number (0-based).
     * @param int $perpage Records per page.
     * @param string $targetlang Target language (e.g., 'es').
     * @param string $filterhuman Human status ('' all, '1' human, '0' auto).
     * @param string $sort Sort column (e.g., 'hash').
     * @param string $dir Sort direction ('ASC' or 'DESC').
     * @param int $courseid Course ID filter (0 for all).
     * @param string $filterneedsreview Review status ('' all, '1' needs, '0' reviewed).
     * @return array ['translations' => records, 'total' => count]
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
}
