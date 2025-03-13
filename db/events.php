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
 * Recalculate hashes via observers for filter_autotranslate.
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kaleb@jamfire.io>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Explicitly include lib.php to ensure the class is loaded.
require_once(__DIR__ . '/../lib.php');

$observers = [
    [
        'eventname' => '\core\event\course_module_updated',
        'callback'  => 'filter_autotranslate_observer::handle_course_module_updated',
        'priority'  => 0,
    ],
    [
        'eventname' => '\core\event\course_section_updated',
        'callback'  => 'filter_autotranslate_observer::handle_course_section_updated',
        'priority'  => 0,
    ],
];

// Debug to confirm registration
error_log("Events: Observer registration loaded for filter_autotranslate");