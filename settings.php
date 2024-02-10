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
    if ($ADMIN->fulltree) {
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

        // Schedule jobs limit.
        $settings->add(
            new admin_setting_configtext(
                'filter_autotranslate/managelimit',
                get_string('managelimit', 'filter_autotranslate'),
                get_string('managelimit_desc', 'filter_autotranslate'),
                20,
                PARAM_INT
            )
        );

        // Schedule jobs limit.
        $settings->add(
            new admin_setting_configtext(
                'filter_autotranslate/fetchlimit',
                get_string('fetchlimit', 'filter_autotranslate'),
                get_string('fetchlimit_desc', 'filter_autotranslate'),
                200,
                PARAM_INT
            )
        );

        // Context level.
        $settings->add(
            new admin_setting_configmulticheckbox(
                'filter_autotranslate/selectctx',
                get_string('selectctx', 'filter_autotranslate'),
                get_string('selectctx_desc', 'filter_autotranslate'),
                ['40', '50', '70', '80'], // Corrected to use string values.
                [
                    '10' => get_string('ctx_system', 'filter_autotranslate'),
                    '30' => get_string('ctx_user', 'filter_autotranslate'),
                    '40' => get_string('ctx_coursecat', 'filter_autotranslate'),
                    '50' => get_string('ctx_course', 'filter_autotranslate'),
                    '70' => get_string('ctx_module', 'filter_autotranslate'),
                    '80' => get_string('ctx_block', 'filter_autotranslate'),
                ]
            )
        );
    }
}
