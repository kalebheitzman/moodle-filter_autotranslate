<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_filter_autotranslate_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2025031401) {
        // Define field human
        $table = new xmldb_table('autotranslate_translations');
        $field = new xmldb_field('human', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'timemodified');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Save point reached
        upgrade_plugin_savepoint(true, 2025031401, 'filter', 'autotranslate');
    }

    if ($oldversion < 2025031508) { // Adjust the version number as needed
        global $DB;
        $dbman = $DB->get_manager();

        // Define the table
        $table = new xmldb_table('autotranslate_hid_cids');

        // Add fields
        $table->add_field('hash', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        // Add keys
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['hash', 'courseid']);
        $table->add_key('hashfk', XMLDB_KEY_FOREIGN, ['hash'], 'autotranslate_translations', ['hash']);
        $table->add_key('courseidfk', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);

        // No need to add explicit indexes on 'hash' or 'courseid'—foreign keys handle it

        // Create the table if it doesn’t exist
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Save the upgrade point
        upgrade_plugin_savepoint(true, 2025031508, 'filter', 'autotranslate');
    }

    return true;
}