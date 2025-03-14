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

    if ($oldversion < 2025031412) {
        // Define table autotranslate_translations
        $table = new xmldb_table('autotranslate_translations');

        // Define the index mdl_autotran_conins_ix (contextlevel, instanceid)
        $index = new xmldb_index('mdl_autotran_conins_ix', XMLDB_INDEX_NOTUNIQUE, ['contextlevel', 'instanceid']);

        // Conditionally drop the index if it exists
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }

        // Define the field instanceid to be dropped
        $field = new xmldb_field('instanceid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);

        // Conditionally drop the field instanceid
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        // Optionally recreate an index on contextlevel alone if needed
        $newindex = new xmldb_index('mdl_autotran_con_ix', XMLDB_INDEX_NOTUNIQUE, ['contextlevel']);
        if (!$dbman->index_exists($table, $newindex)) {
            $dbman->add_index($table, $newindex);
        }

        // Save point reached
        upgrade_plugin_savepoint(true, 2025031412, 'filter', 'autotranslate');
    }

    return true;
}