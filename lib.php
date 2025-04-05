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
 * Library functions for the Autotranslate plugin.
 *
 * Provides utility functions to enhance Moodle navigation for the Autotranslate plugin.
 *
 * Features:
 * - Adds "Manage Autotranslations" link to course settings menu.
 *
 * Usage:
 * - Called by Moodle to extend course navigation for admins.
 *
 * Design:
 * - Checks manage capability before adding navigation link.
 * - Uses current or site language for filter consistency.
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Extends course navigation with a manage link.
 *
 * Adds "Manage Autotranslations" to the course settings menu for users with manage
 * capability, linking to `manage.php` with course-specific filters.
 *
 * @param navigation_node $navigation Navigation node to extend.
 * @param stdClass $course Course object.
 * @param context $context Course context.
 * @return void
 */
function filter_autotranslate_extend_navigation_course($navigation, $course, $context) {
    global $CFG;

    // Check capability.
    if (!has_capability('filter/autotranslate:manage', $context)) {
        return;
    }

    // Get site and current language.
    $sitelang = get_config('core', 'lang') ?: 'en';
    $currentlang = current_language();
    $filterlang = ($currentlang === $sitelang) ? 'other' : $currentlang;

    // Build URL for Manage Autotranslations.
    $manageurl = new \moodle_url('/filter/autotranslate/manage.php', [
        'courseid' => $course->id,
        'filter_lang' => $filterlang,
        'perpage' => 20,
    ]);

    // Navigation node.
    $managetitle = get_string('manageautotranslations', 'filter_autotranslate');
    $managecontent = \navigation_node::create(
        $managetitle,
        $manageurl,
        \navigation_node::TYPE_SETTING,
        $managetitle,
        'autotranslatemanage',
        new \pix_icon('i/settings', '')
    );
    $navigation->add_node($managecontent);
    $managecontent->showinsecondarynavigation = true; // Force into More menu.
}
