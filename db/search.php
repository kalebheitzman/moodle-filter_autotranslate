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
 * Search areas for the Autotranslate plugin.
 *
 * Defines search areas to integrate translations into Moodle’s global search system.
 *
 * Features:
 * - `filter_autotranslate-translation`: Indexes translation data for search.
 *
 * Usage:
 * - Registers `translation` search area for future use with Moodle search.
 * - Links to `filter_autotranslate\search\translation` class (not yet fully implemented).
 *
 * Design:
 * - Prepares plugin for searchable translations via `mdl_filter_autotranslate_translations`.
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @see        https://docs.moodle.org/dev/Search_API
 */

defined('MOODLE_INTERNAL') || die();

$searchareas = [
    'filter_autotranslate-translation' => [
        'class' => 'filter_autotranslate\\search\\translation',
        'file' => '/filter/autotranslate/classes/search/translation.php',
    ],
];
