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
 * Cache definitions for the Autotranslate plugin.
 *
 * Defines caches to optimize performance by storing tagged content, module schemas,
 * and selected fields, reducing database queries in the filter and settings.
 *
 * Features:
 * - 'taggedcontent': Stores tagged text from `text_filter` for rendering.
 * - 'modschemas': Caches module schemas for `content_service`.
 * - 'selectedfields': Caches field selections for `content_service` settings.
 *
 * Usage:
 * - Used by `text_filter` to cache filtered text with {t:hash} tags.
 * - Supports `content_service` for schema and field lookups.
 *
 * Design:
 * - Uses MODE_APPLICATION for shared, application-level caching.
 * - Employs simple keys/data and static acceleration for efficiency.
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$definitions = [
    'taggedcontent' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'staticacceleration' => true,
        'staticaccelerationsize' => 1000,
    ],
    'modschemas' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'staticacceleration' => true,
        'staticaccelerationsize' => 10,
        'canuselocalstore' => true,
        'persistent' => true,
    ],
    'selectedfields' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'staticacceleration' => true,
        'staticaccelerationsize' => 10,
    ],
];
