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
 * Autotranslate Migration Steps
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the autotranslate filter.
 *
 * @param int $oldversion The current version of the plugin.
 * @return bool True if the upgrade was successful, false otherwise.
 */
function xmldb_filter_autotranslate_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2025031401) {
        // Define field human.
        $table = new xmldb_table('filter_autotranslate_translations');
        $field = new xmldb_field('human', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'timemodified');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Save point reached.
        upgrade_plugin_savepoint(true, 2025031401, 'filter', 'autotranslate');
    }

    if ($oldversion < 2025031508) { // Adjust the version number as needed.
        global $DB;
        $dbman = $DB->get_manager();

        // Define the table.
        $table = new xmldb_table('filter_autotranslate_hid_cids');

        // Add fields.
        $table->add_field('hash', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        // Add keys.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['hash', 'courseid']);
        $table->add_key('hashfk', XMLDB_KEY_FOREIGN, ['hash'], 'filter_autotranslate_translations', ['hash']);
        $table->add_key('courseidfk', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);

        // No need to add explicit indexes on 'hash' or 'courseid'—foreign keys handle it.

        // Create the table if it doesn’t exist.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Save the upgrade point.
        upgrade_plugin_savepoint(true, 2025031508, 'filter', 'autotranslate');
    }

    // Add the 'timereviewed' field and index.
    if ($oldversion < 2025031830) {
        $table = new xmldb_table('filter_autotranslate_translations');

        // Add timereviewed field.
        $field = new xmldb_field('timereviewed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timemodified');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add index on timereviewed.
        $index = new xmldb_index('mdl_autotran_timereviewed_ix', XMLDB_INDEX_NOTUNIQUE, ['timereviewed']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Populate timereviewed for existing records (set to timecreated).
        $DB->execute("UPDATE {filter_autotranslate_translations} SET timereviewed = timecreated WHERE timereviewed = 0");

        upgrade_plugin_savepoint(true, 2025031830, 'filter', 'autotranslate');
    }

    return true;
}
