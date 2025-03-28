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
 * Cache definitions for the filter_autotranslate plugin.
 *
 * Purpose:
 * This file defines the caches used by the filter_autotranslate plugin to improve performance
 * by reducing database operations. The plugin uses caching to store tagged content generated
 * during the dynamic tagging process in text_filter.php, ensuring that content is not re-tagged
 * unnecessarily across page requests.
 *
 * Structure:
 * The $definitions array contains cache definitions, where each key is the cache identifier
 * (e.g., 'taggedcontent') and the value is an array of configuration options for that cache.
 * Each cache definition specifies the cache mode, key structure, data type, and performance
 * optimizations.
 *
 * Usage:
 * - The 'taggedcontent' cache is used by text_filter.php to store content that has been dynamically
 *   tagged with {t:hash} tags during the filtering process.
 * - The cache is accessed using a composite key in the format 'hash_contextid' (e.g., 'EcNqkI1V1h_123'),
 *   where 'hash' is the unique hash of the translatable content and 'contextid' is the Moodle context ID.
 * - The cache stores the tagged content (e.g., 'Welcome {t:EcNqkI1V1h}') to avoid re-tagging the same
 *   content in subsequent requests.
 *
 * Design Decisions:
 * - The cache uses MODE_APPLICATION to store data at the application level, ensuring it is shared across
 *   all users and sessions for maximum efficiency.
 * - 'simplekeys' and 'simpledata' are enabled to optimize performance by using simple string keys and
 *   data, reducing serialization overhead.
 * - 'staticacceleration' and 'staticaccelerationsize' are used to keep a small number of frequently
 *   accessed cache entries in memory, improving performance for common content.
 * - The cache does not use 'invalidationevents', as tagged content is relatively stable and does not
 *   require frequent invalidation.
 *
 * Dependencies:
 * - None (pure cache definition file).
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$definitions = [
    'taggedcontent' => [
        // Cache mode: Application-level caching, shared across all users and sessions.
        'mode' => cache_store::MODE_APPLICATION,

        // Use simple keys: The cache keys are simple strings (e.g., 'EcNqkI1V1h_123'), which reduces
        // serialization overhead and improves performance.
        'simplekeys' => true,

        // Use simple data: The cache stores simple string data (tagged content), which further reduces
        // serialization overhead.
        'simpledata' => true,

        // Enable static acceleration: Keeps a small number of frequently accessed cache entries in memory
        // to improve performance for common content.
        'staticacceleration' => true,

        // Static acceleration size: Limits the number of cache entries kept in memory to 1000, balancing
        // memory usage and performance. Adjust this value based on your site's usage patterns if needed.
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
    // Cache for selected fields (used in get_selected_fields).
    'selectedfields' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'staticacceleration' => true,
        'staticaccelerationsize' => 10,
    ],
];
