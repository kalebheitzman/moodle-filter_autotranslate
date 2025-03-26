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
 * Task Schedule Configuration for filter_autotranslate
 *
 * Purpose:
 * This file defines the scheduled tasks for the filter_autotranslate plugin. These tasks handle
 * background processes such as tagging content for translation, fetching translations from external
 * services, and purging orphaned hash-course mappings to keep the database clean.
 *
 * Structure:
 * The $tasks array contains definitions for each scheduled task, with the following properties:
 * - 'classname': The fully qualified class name of the task (e.g., 'filter_autotranslate\task\tagcontent_task').
 * - 'blocking': Whether the task is blocking (0 for non-blocking, 1 for blocking). All tasks are non-blocking.
 * - 'minute', 'hour', 'day', 'month', 'dayofweek': The cron schedule for the task, using standard cron syntax.
 *   - 'minute': Minute of the hour (e.g., '*\/15' for every 15 minutes, '0' for the start of the hour).
 *   - 'hour': Hour of the day (e.g., '*' for every hour, '0' for midnight).
 *   - 'day': Day of the month (e.g., '*' for every day).
 *   - 'month': Month of the year (e.g., '*' for every month).
 *   - 'dayofweek': Day of the week (e.g., '*' for every day, '0' for Sunday).
 *
 * Design Decisions:
 * - Tasks are scheduled to run at regular intervals to balance performance and timeliness:
 *   - tagcontent_task runs every 15 minutes to tag new or updated content for translation.
 *   - fetchtranslation_task runs every 30 minutes to fetch translations from external services.
 *   - purgemappings_task runs daily at midnight to clean up orphaned mappings, as this is a less frequent operation.
 * - All tasks are non-blocking to ensure they do not interfere with other Moodle cron processes.
 * - Schedules can be adjusted in Moodle's admin interface (Site administration > Server > Scheduled tasks).
 *
 * Dependencies:
 * - tagcontent_task.php: Handles tagging of content for translation.
 * - fetchtranslation_task.php: Fetches translations from external services.
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        // Task to tag content for translation by adding {t:hash} tags.
        // Runs every 15 minutes to process new or updated content.
        'classname' => 'filter_autotranslate\task\tagcontent_task',
        'blocking' => 0,
        'minute' => '*/15',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
        // Task to fetch translations from external services for tagged content.
        // Runs every 30 minutes to balance load and ensure timely translation updates.
        'classname' => 'filter_autotranslate\task\fetchtranslation_task',
        'blocking' => 0,
        'minute' => '*/30',
        'hour' => '*',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*',
    ],
];
