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

    return true;
}