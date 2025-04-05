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
 * Version metadata for the Autotranslate plugin.
 *
 * Defines version, release, and compatibility details for the Autotranslate filter.
 *
 * Features:
 * - Sets plugin version and release.
 * - Requires Moodle 4.5 (2024100702) or higher.
 * - Marks as alpha maturity for development status.
 *
 * Usage:
 * - Loaded by Moodle to register and validate plugin compatibility.
 *
 * Design:
 * - Uses YYYYMMDDXX version format for incremental updates.
 * - Includes display name from plugin language string.
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->version   = 2025040500;                // The current plugin version (Date: YYYYMMDDXX).
$plugin->requires  = 2024100702;                // Requires this Moodle version.
$plugin->component = 'filter_autotranslate';    // Full name of the plugin (used for diagnostics).
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '2025040500';
$plugin->displayname = get_string('filtername', 'filter_autotranslate');
