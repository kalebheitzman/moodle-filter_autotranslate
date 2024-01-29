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
 * @package    filter_autotranslate
 * @copyright  2024 Kaleb Heitzman <kaleb@jamfire.io>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();


if ($hassiteconfig) {

    // Create new settings page.
    $settings = new admin_settingpage('filter_autotranslate', get_string('autotranslate_settings', 'filter_autotranslate'));

    // Add to admin menu.
    $ADMIN->add('filterplugins', $settings);

    // DeepL apikey.
    $settings->add(
        new admin_setting_configtext(
            'filter_autotranslate/deeplapikey',
            get_string('apikey', 'filter_autotranslate'),
            get_string('apikey_desc', 'filter_autotranslate'),
            null,
            PARAM_RAW_TRIMMED,
            40
        )
    );

    // Schedule jobs limit
    $settings->add(
        new admin_setting_configtext(
            'filter_autotranslate/fetchlimit',
            get_string('fetchlimit', 'filter_autotranslate'),
            get_string('fetchlimit_desc', 'filter_autotranslate'),
            200,
            PARAM_INT
        )
    );
}
