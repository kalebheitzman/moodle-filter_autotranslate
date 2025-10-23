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
 * Privacy Subsystem implementation for filter_autotranslate.
 *
 * This plugin processes user-generated content (such as forum posts, wiki pages, and glossary entries)
 * by sending text to an external translation API for automatic translation. While the plugin stores
 * translations in its database, it does not link them to specific user IDs. Translations are stored
 * by content hash and are shared/reused across the site when identical content appears.
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_autotranslate\privacy;

use core_privacy\local\metadata\collection;

/**
 * Privacy Subsystem for filter_autotranslate implementing metadata_provider.
 *
 * Declares that user-generated content is sent to external translation services but
 * translations are stored anonymously (not linked to user IDs) in the local database.
 *
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements 
    \core_privacy\local\metadata\provider {

    /**
     * Returns metadata about data processed by this plugin.
     *
     * Declares that user-generated content (forum posts, wiki pages, glossary entries, etc.)
     * is sent to an external translation API service for automatic translation purposes.
     * Translations are stored locally but not linked to specific user IDs.
     *
     * @param collection $collection The collection to add metadata to.
     * @return collection The updated collection.
     */
    public static function get_metadata(collection $collection): collection {
        // User content sent to external translation API.
        $collection->add_external_location_link('translation_api', [
            'text' => 'privacy:metadata:translation_api:text',
            'sourcelanguage' => 'privacy:metadata:translation_api:sourcelanguage',
            'targetlanguage' => 'privacy:metadata:translation_api:targetlanguage',
        ], 'privacy:metadata:translation_api');

        // Local storage (anonymized - no user IDs stored).
        $collection->add_database_table('filter_autotranslate_translations', [
            'translated_text' => 'privacy:metadata:translations:translated_text',
            'lang' => 'privacy:metadata:translations:lang',
            'timecreated' => 'privacy:metadata:translations:timecreated',
            'timemodified' => 'privacy:metadata:translations:timemodified',
        ], 'privacy:metadata:translations');

        return $collection;
    }
}
