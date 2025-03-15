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
 * Moodle Autotranslate Filter Permissions
 *
 * Adds filter/autotranslate:translate permissions for checking against
 * the webservice.
 *
 * @package    filter_autotranslate
 * @copyright  20245Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @see        https://docs.moodle.org/dev/Access_API
 */

defined('MOODLE_INTERNAL') || die();

// Translator Capabilities.
$capabilities = [
    // 'filter/autotranslate:translate' => [
    //     'captype' => 'write',
    //     'riskbitmaskt' => 'RISK_CONFIG',
    //     'contextlevel' => CONTEXT_SYSTEM,
    //     'archetypes' => [
    //         'manager' => CAP_ALLOW,
    //     ],
    // ],
    'filter/autotranslate:manage' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'moodle/site:manageblocks',
    ],
    'filter/autotranslate:edit' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],
];
