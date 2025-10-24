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
 * Entry point for the Autotranslate filter.
 *
 * Moodle still expects a legacy `filter_autotranslate` class living in the plugin root
 * so we provide a thin wrapper that defers to the namespaced implementation in
 * `classes/text_filter.php`. Keep this file in place or the filter will not be
 * constructed in recent Moodle 5 builds.
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/classes/text_filter.php');
require_once(__DIR__ . '/classes/translation_source.php');
require_once(__DIR__ . '/classes/task/tagcontent.php');

/**
 * Legacy wrapper class required by Moodle's filter loader.
 */
// class filter_autotranslate extends \filter_autotranslate\text_filter {
// }

// Provide a class alias for Moodle components expecting the legacy class name.
class_alias(\filter_autotranslate\text_filter::class, filter_autotranslate::class);
