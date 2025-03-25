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
// along with Moodle.  If not, see <http://www.gnu.org/licenses>.
/**
 * Autotranslate Event Observers
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Defines the events observed by the filter_autotranslate plugin.
 *
 * Purpose:
 * This file specifies the Moodle events that the filter_autotranslate plugin listens to, mapping
 * each event to a callback function in observer.php. The observer handles delete events to clean up
 * hash-course mappings in the autotranslate_hid_cids table, ensuring the database remains consistent.
 *
 * Design Decisions:
 * - Observes only delete events (course_module_deleted, course_deleted, course_section_deleted) to
 *   remove obsolete hash-course mappings, keeping the database clean.
 * - Create and update events are not observed, as tagging is handled by the tagcontent_task.php
 *   scheduled task, which processes content in batches for better performance and scalability.
 * - The catch-all event (*) is commented out but can be enabled for debugging purposes with proper
 *   logging configuration.
 *
 * Dependencies:
 * - observer.php: Contains the callback functions for handling the observed events.
 */
$observers = [
    [
        'eventname' => '\core\event\course_module_deleted',
        'callback' => 'filter_autotranslate\observer::course_module_deleted',
        'priority' => 0,
        'internal' => false,
    ],
    [
        'eventname' => '\core\event\course_deleted',
        'callback' => 'filter_autotranslate\observer::course_deleted',
        'priority' => 0,
        'internal' => false,
    ],
    [
        'eventname' => '\core\event\course_section_deleted',
        'callback' => 'filter_autotranslate\observer::course_section_deleted',
        'priority' => 0,
        'internal' => false,
    ],
    // [
    //     'eventname' => '*',
    //     'callback' => 'filter_autotranslate\observer::catch_all',
    //     'priority' => 0,
    //     'internal' => false,
    // ]
];