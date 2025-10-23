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
 * Capabilities for the Autotranslate plugin.
 *
 * Defines permissions for managing translations in the Autotranslate filter.
 *
 * Features:
 * - `filter/autotranslate:manage`: Allows access to plugin settings and configuration.
 * - `filter/autotranslate:edit`: Permits editing translations in the management interface.
 *
 * Usage:
 * - `settings.php`: Protected by Moodle's $hassiteconfig (requires moodle/site:config).
 * - `manage.php`, `create.php`, `edit.php`, `external.php`: Check `edit` capability.
 * - `lib.php`: Checks `edit` at course context for navigation menu.
 *
 * Design:
 * - System-level `manage` for plugin configuration (settings page).
 * - System-level `edit` allows site-wide translation editing; can be granted at course level for course-specific access.
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @see        https://docs.moodle.org/dev/Access_API
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'filter/autotranslate:manage' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'moodle/site:config',
    ],
    'filter/autotranslate:edit' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'moodle/site:manageblocks',
    ],
];
