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
 * Database upgrades for the Autotranslate plugin.
 *
 * Manages schema migrations for the Autotranslate filter, updating tables to support
 * translation storage, course mappings, and task progress tracking.
 *
 * Features:
 * - Adds `human` field (`2025031401`) to track manual edits.
 * - Creates `hid_cids` table (`2025031508`) for hash-course mappings.
 * - Adds `task_progress` table (`2025032602`) for autotranslate task tracking.
 * - Removes `timereviewed` field (`2025040401`) as stale marking was dropped.
 *
 * Usage:
 * - Called during plugin upgrades to align database with version `2025040401`.
 *
 * Design:
 * - Incremental upgrades ensure compatibility from early versions to current state.
 * - Final schema matches `install.xml` post-`timereviewed` removal.
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @see        https://docs.moodle.org/dev/Database_schema_updates
 */

/**
 * Upgrades the Autotranslate filter database schema.
 *
 * Applies incremental changes to tables based on the old version, up to `2025040401`.
 *
 * @param int $oldversion The current installed version of the plugin.
 * @return bool True if the upgrade succeeds, false otherwise.
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

    if ($oldversion < 2025031508) {
        // Define the table.
        $table = new xmldb_table('filter_autotranslate_hid_cids');

        // Add fields.
        $table->add_field('hash', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        // Add keys.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['hash', 'courseid']);
        $table->add_key('hashfk', XMLDB_KEY_FOREIGN, ['hash'], 'filter_autotranslate_translations', ['hash']);
        $table->add_key('courseidfk', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);

        // Create the table if it doesn’t exist.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Save the upgrade point.
        upgrade_plugin_savepoint(true, 2025031508, 'filter', 'autotranslate');
    }

    // Add the 'timereviewed' field and index (later removed).
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

        // Populate timereviewed for existing records.
        $DB->execute("UPDATE {filter_autotranslate_translations} SET timereviewed = timecreated WHERE timereviewed = 0");

        upgrade_plugin_savepoint(true, 2025031830, 'filter', 'autotranslate');
    }

    if ($oldversion < 2025032602) {
        // Define the new table filter_autotranslate_task_progress.
        $table = new xmldb_table('filter_autotranslate_task_progress');

        // Define fields.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('taskid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('tasktype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('total_entries', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('processed_entries', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'queued');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        // Define keys.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        // Define indexes.
        $table->add_index('taskid', XMLDB_INDEX_NOTUNIQUE, ['taskid']);

        // Create the table if it doesn't exist.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Save the upgrade point.
        upgrade_plugin_savepoint(true, 2025032602, 'filter', 'autotranslate');
    }

    if ($oldversion < 2025040401) {
        $table = new xmldb_table('filter_autotranslate_translations');

        // Remove the timereviewed index.
        $index = new xmldb_index('mdl_autotran_timereviewed_ix', XMLDB_INDEX_NOTUNIQUE, ['timereviewed']);
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }

        // Remove the timereviewed field.
        $field = new xmldb_field('timereviewed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2025040401, 'filter', 'autotranslate');
    }

    return true;
}
