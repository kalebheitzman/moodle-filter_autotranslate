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
 * Autotranslate filter library functions.
 *
 * Purpose:
 * Provides utility functions for the filter_autotranslate plugin, including extending the course
 * navigation menu with a "Manage Autotranslations" link for administrators.
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Adds "Manage Autotranslations" to the course settings menu.
 *
 * Extends the course navigation with a link to manage.php for users with the manage capability,
 * using the current language or 'other' based on the site language.
 *
 * @param navigation_node $navigation The navigation node to extend.
 * @param stdClass $course The course object.
 * @param context $context The course context.
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
        'perpage' => 250,
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
