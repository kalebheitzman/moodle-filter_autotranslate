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

$observers = [
    [
        'eventname' => '\core\event\course_module_created',
        'callback' => 'filter_autotranslate\observer::course_module_created',
        'priority' => 0,
        'internal' => false,
    ],
    [
        'eventname' => '\core\event\course_module_updated',
        'callback' => 'filter_autotranslate\observer::course_module_updated',
        'priority' => 0,
        'internal' => false,
    ],
    [
        'eventname' => '\core\event\course_module_deleted',
        'callback' => 'filter_autotranslate\observer::course_module_deleted',
        'priority' => 0,
        'internal' => false,
    ],
    [
        'eventname' => '\core\event\course_created',
        'callback' => 'filter_autotranslate\observer::course_created',
        'priority' => 0,
        'internal' => false,
    ],
    [
        'eventname' => '\core\event\course_updated',
        'callback' => 'filter_autotranslate\observer::course_updated',
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
        'eventname' => '\core\event\course_section_created',
        'callback' => 'filter_autotranslate\observer::course_section_created',
        'priority' => 0,
        'internal' => false,
    ],
    [
        'eventname' => '\core\event\course_section_updated',
        'callback' => 'filter_autotranslate\observer::course_section_updated',
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