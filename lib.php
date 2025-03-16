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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Autotranslate filter extended libs
 *
 * @package      filter_autotranslate
 * @copyright    2022 Kaleb Heitzman <kaleb@jamfire.io>
 * @license      http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Add Manage Autotranslations to course settings menu.
 *
 * @package      filter_autotranslate
 * @param object $navigation The navigation node to extend
 * @param object $course The course object
 * @param context $context The course context
 * @return void
 */
function filter_autotranslate_extend_navigation_course($navigation, $course, $context) {
    global $CFG;

    // Check if the user has the required capability
    if (!has_capability('filter/autotranslate:manage', $context)) {
        return;
    }

    // Debugging to confirm the function is called
    debugging('filter_autotranslate_extend_navigation_course called for course ' . $course->id, DEBUG_DEVELOPER);

    // Get the site language and current language
    $sitelang = get_config('core', 'lang') ?: 'en';
    $currentlang = current_language();
    $filter_lang = ($currentlang === $sitelang) ? 'other' : $currentlang;

    // Build a moodle URL for Manage Autotranslations
    $manageurl = new moodle_url('/filter/autotranslate/manage.php', [
        'courseid' => $course->id,
        'filter_lang' => $filter_lang,
        'perpage' => 250
    ]);

    // Get title of Manage Autotranslations page for navigation menu
    $managetitle = get_string('manageautotranslations', 'filter_autotranslate');

    // Navigation node for Manage Autotranslations
    $managecontent = navigation_node::create(
        $managetitle,
        $manageurl,
        navigation_node::TYPE_SETTING,
        $managetitle,
        'autotranslatemanage',
        new pix_icon('i/settings', '')
    );
    $navigation->add_node($managecontent);
    $managecontent->showinsecondarynavigation = true; // Force into More menu
}