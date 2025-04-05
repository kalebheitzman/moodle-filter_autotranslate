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
 * Scheduled tasks for the Autotranslate plugin.
 *
 * Defines the scheduled task to tag content with `{t:hash}` for the Autotranslate filter.
 *
 * Features:
 * - `tagcontent_scheduled_task`: Tags content every 5 minutes across configured tables.
 *
 * Usage:
 * - Runs `tagcontent_scheduled_task.php` to prepare content for `text_filter.php`.
 * - Configurable via `settings.php` for table/field selection.
 *
 * Design:
 * - Non-blocking, runs every 5 minutes to balance load and tagging frequency.
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @see        https://docs.moodle.org/dev/Task_API
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => 'filter_autotranslate\task\tagcontent_scheduled_task',
        'blocking' => 0,
        'minute' => '*/5', // Run every 5 minutes.
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
];
