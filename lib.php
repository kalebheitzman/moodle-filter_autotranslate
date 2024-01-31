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
 * Add Translate Course to course settings menu.
 *
 * @package      filter_autotranslate
 * @param object $navigation
 * @param object $course
 * @return void
 */
function filter_autotranslate_extend_navigation_course($navigation, $course) {

    // Get langs.
    $site_lang = get_config('core', 'lang');
    $current_lang = current_language();

    // Build a moodle url.
    $url = new moodle_url("/filter/autotranslate/manage.php?source_lang=$site_lang&target_lang=$current_lang&limit=500&instanceid=$course->id");

    // Get title of translate page for navigation menu.
    $title = get_string('manage_title', 'filter_autotranslate');

    // Navigation node.
    $translatecontent = navigation_node::create(
        $title,
        $url,
        navigation_node::TYPE_CUSTOM,
        $title,
        'autotranslate',
    );
    $navigation->add_node($translatecontent);
}

