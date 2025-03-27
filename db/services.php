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
 * External services definition for the filter_autotranslate plugin.
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'filter_autotranslate_autotranslate' => [
        'classname'   => 'filter_autotranslate\external',
        'methodname'  => 'autotranslate',
        'description' => 'Queues an adhoc task to fetch translations for untranslated entries in the current view.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'filter/autotranslate:manage',
    ],
    'filter_autotranslate_rebuild_translations' => [
        'classname'   => 'filter_autotranslate\external',
        'methodname'  => 'rebuild_translations',
        'description' => 'Queues an adhoc task to rebuild translations for a specific course.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'filter/autotranslate:manage',
    ],
    'filter_autotranslate_task_status' => [
        'classname'   => 'filter_autotranslate\external',
        'methodname'  => 'task_status',
        'description' => 'Retrieves the status and progress of an autotranslate or rebuild task.',
        'type'        => 'read',
        'ajax'        => true,
        'capabilities' => 'filter/autotranslate:manage',
    ],
];

$services = [
    'filter_autotranslate_services' => [
        'functions' => [
            'filter_autotranslate_autotranslate',
            'filter_autotranslate_rebuild_translations',
            'filter_autotranslate_task_status',
        ],
        'restrictedusers' => 0,
        'enabled' => 1,
        'shortname' => 'filter_autotranslate_services',
    ],
];
