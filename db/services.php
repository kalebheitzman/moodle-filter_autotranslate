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
 * External services for the Autotranslate plugin.
 *
 * Defines AJAX API functions for queuing and monitoring autotranslate tasks from the
 * management interface, linking `manage.php` to `external.php` via `autotranslate.js`.
 *
 * Features:
 * - `filter_autotranslate_autotranslate`: Queues translation tasks for untranslated content.
 * - `filter_autotranslate_task_status`: Tracks task progress and status.
 *
 * Usage:
 * - Called by `autotranslate.js` from `manage.php` "Autotranslate" button and polling.
 * - Requires `filter/autotranslate:manage` capability for both functions.
 *
 * Design:
 * - Lightweight AJAX endpoints for task management, tied to `external.php`.
 * - Service groups functions for consistent access control.
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @see        https://docs.moodle.org/dev/Web_services_API
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'filter_autotranslate_autotranslate' => [
        'classname'   => 'filter_autotranslate\external',
        'methodname'  => 'autotranslate',
        'description' => 'Queues an adhoc task to fetch translations for untranslated entries.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'filter/autotranslate:manage',
    ],
    'filter_autotranslate_task_status' => [
        'classname'   => 'filter_autotranslate\external',
        'methodname'  => 'task_status',
        'description' => 'Retrieves the status and progress of an autotranslate task.',
        'type'        => 'read',
        'ajax'        => true,
        'capabilities' => 'filter/autotranslate:manage',
    ],
];

$services = [
    'filter_autotranslate_services' => [
        'functions' => [
            'filter_autotranslate_autotranslate',
            'filter_autotranslate_task_status',
        ],
        'restrictedusers' => 0,
        'enabled' => 1,
        'shortname' => 'filter_autotranslate_services',
    ],
];
