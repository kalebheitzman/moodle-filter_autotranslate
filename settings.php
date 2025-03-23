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
// along with Moodle.  If not, see <http://www.gnu.org/licenses>.
/**
 * Autotranslate Settings
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    if ($ADMIN->fulltree) {
        require_once($CFG->dirroot . '/filter/autotranslate/classes/tagging_config.php');

        // Section: API Configuration
        $settings->add(
            new admin_setting_heading(
                'filter_autotranslate_apiconfig',
                get_string('apiconfig', 'filter_autotranslate'),
                get_string('apiconfig_desc', 'filter_autotranslate')
            )
        );

        // Enable Automatic Fetching of Translations
        $settings->add(
            new admin_setting_configcheckbox(
                'filter_autotranslate/enableautofetch',
                get_string('enableautofetch', 'filter_autotranslate'),
                get_string('enableautofetch_desc', 'filter_autotranslate'),
                0 // Default to off
            )
        );

        // API Endpoint (Base URL)
        $settings->add(
            new admin_setting_configtext(
                'filter_autotranslate/apiendpoint',
                get_string('apiendpoint', 'filter_autotranslate'),
                get_string('apiendpoint_desc', 'filter_autotranslate'),
                'http://localhost:11434/v1', // Default to local Ollama instance
                PARAM_URL
            )
        );

        // API Key
        $settings->add(
            new admin_setting_configtext(
                'filter_autotranslate/apikey',
                get_string('apikey', 'filter_autotranslate'),
                get_string('apikey_desc', 'filter_autotranslate'),
                null,
                PARAM_RAW_TRIMMED,
                40
            )
        );

        // API Model
        $settings->add(
            new admin_setting_configtext(
                'filter_autotranslate/apimodel',
                get_string('apimodel', 'filter_autotranslate'),
                get_string('apimodel_desc', 'filter_autotranslate'),
                'mistral',
                PARAM_TEXT
            )
        );

        // Section: Translation Settings
        $settings->add(
            new admin_setting_heading(
                'filter_autotranslate_translationsettings',
                get_string('translationsettings', 'filter_autotranslate'),
                get_string('translationsettings_desc', 'filter_autotranslate')
            )
        );

        // Target languages for translation (checkboxes of enabled languages, excluding site language)
        $enabledlangs = get_string_manager()->get_list_of_translations();
        $sitelang = get_config('core', 'lang') ?: 'en'; // Get site language, default to 'en'
        unset($enabledlangs[$sitelang]); // Remove site language from the list

        if (empty($enabledlangs)) {
            $settings->add(
                new admin_setting_description(
                    'filter_autotranslate_targetlangs_warning',
                    '',
                    get_string('targetlangs_warning', 'filter_autotranslate')
                )
            );
        } else {
            // Define default languages
            $defaultlangs = ['es' => 1, 'fr' => 1, 'de' => 1]; // Default to Spanish, French, German
            // Ensure defaults only include enabled languages
            $defaultlangs = array_intersect_key($defaultlangs, $enabledlangs);
            // If no defaults match enabled languages, select none by default
            if (empty($defaultlangs)) {
                $defaultlangs = [];
            }
            $settings->add(
                new admin_setting_configmulticheckbox(
                    'filter_autotranslate/targetlangs',
                    get_string('targetlangs', 'filter_autotranslate'),
                    get_string('targetlangs_desc', 'filter_autotranslate'),
                    $defaultlangs,
                    $enabledlangs
                )
            );
        }

        // System Instructions (Glossary and Translation Instructions)
        $settings->add(
            new admin_setting_configtextarea(
                'filter_autotranslate/systeminstructions',
                get_string('systeminstructions', 'filter_autotranslate'),
                get_string('systeminstructions_desc', 'filter_autotranslate'),
                'Translate with a formal tone.',
                PARAM_TEXT
            )
        );

        // Batch Size for Translation
        $settings->add(
            new admin_setting_configtext(
                'filter_autotranslate/batchsize',
                get_string('batchsize', 'filter_autotranslate'),
                get_string('batchsize_desc', 'filter_autotranslate'),
                10,
                PARAM_INT
            )
        );

        // Section: Task Configuration
        $settings->add(
            new admin_setting_heading(
                'filter_autotranslate_taskconfig',
                get_string('taskconfig', 'filter_autotranslate'),
                get_string('taskconfig_desc', 'filter_autotranslate')
            )
        );

        // Fetch translation task limit
        $settings->add(
            new admin_setting_configtext(
                'filter_autotranslate/fetchlimit',
                get_string('fetchlimit', 'filter_autotranslate'),
                get_string('fetchlimit_desc', 'filter_autotranslate'),
                200,
                PARAM_INT
            )
        );

        // Maximum retry attempts for API calls
        $settings->add(
            new admin_setting_configtext(
                'filter_autotranslate/maxattempts',
                get_string('maxattempts', 'filter_autotranslate'),
                get_string('maxattempts_desc', 'filter_autotranslate'),
                3,
                PARAM_INT
            )
        );

        // Rate limit threshold for API calls
        $settings->add(
            new admin_setting_configtext(
                'filter_autotranslate/ratelimitthreshold',
                get_string('ratelimitthreshold', 'filter_autotranslate'),
                get_string('ratelimitthreshold_desc', 'filter_autotranslate'),
                50,
                PARAM_INT
            )
        );

        // Records per run for tagcontent_task
        $settings->add(
            new admin_setting_configtext(
                'filter_autotranslate/recordsperrun',
                get_string('recordsperrun', 'filter_autotranslate'),
                get_string('recordsperrun_desc', 'filter_autotranslate'),
                1000, // Default to 1000 records per table per run
                PARAM_INT
            )
        );

        // Select context levels for autotranslation
        $settings->add(
            new admin_setting_configmulticheckbox(
                'filter_autotranslate/selectctx',
                get_string('selectctx', 'filter_autotranslate'),
                get_string('selectctx_desc', 'filter_autotranslate'),
                ['40', '50', '70', '80'], // Default to Course Category, Course, Module, Block
                [
                    '10' => get_string('ctx_system', 'filter_autotranslate'),
                    '30' => get_string('ctx_user', 'filter_autotranslate'),
                    '40' => get_string('ctx_coursecat', 'filter_autotranslate'),
                    '50' => get_string('ctx_course', 'filter_autotranslate'),
                    '70' => get_string('ctx_module', 'filter_autotranslate'),
                    '80' => get_string('ctx_block', 'filter_autotranslate'),
                ]
            )
        );

        // Task frequency (in minutes)
        $settings->add(
            new admin_setting_configtext(
                'filter_autotranslate/taskfrequency',
                get_string('taskfrequency', 'filter_autotranslate'),
                get_string('taskfrequency_desc', 'filter_autotranslate'),
                60, // Default to hourly
                PARAM_INT
            )
        );

        // Manual trigger option
        $settings->add(
            new admin_setting_configcheckbox(
                'filter_autotranslate/enablemanualtrigger',
                get_string('enablemanualtrigger', 'filter_autotranslate'),
                get_string('enablemanualtrigger_desc', 'filter_autotranslate'),
                0
            )
        );

        // Section: Tagging Configuration
        $settings->add(
            new admin_setting_heading(
                'filter_autotranslate_taggingconfig',
                get_string('taggingconfig', 'filter_autotranslate'),
                get_string('taggingconfig_desc', 'filter_autotranslate')
            )
        );

        // Build the form elements for selecting tables and fields
        $default_tables = \filter_autotranslate\tagging_config::get_default_tables();
        $tagging_options = [];
        foreach ($default_tables as $contextlevel => $tables) {
            $context_name = get_string("ctx_{$contextlevel}", 'filter_autotranslate');
            foreach ($tables as $table => $config) {
                // Handle primary table fields
                $fields = $config['fields'] ?? [];
                foreach ($fields as $field) {
                    $key = "ctx{$contextlevel}_{$table}_{$field}";
                    $label = "$context_name: $table.$field";
                    $tagging_options[$key] = $label;
                }

                // Handle secondary table fields
                if (isset($config['secondary'])) {
                    foreach ($config['secondary'] as $secondary_table => $secondary_config) {
                        $secondary_fields = $secondary_config['fields'] ?? [];
                        foreach ($secondary_fields as $field) {
                            $key = "ctx{$contextlevel}_{$secondary_table}_{$field}";
                            $label = "$context_name: $secondary_table.$field";
                            $tagging_options[$key] = $label;
                        }
                    }
                }
            }
        }

        $settings->add(
            new admin_setting_configmulticheckbox(
                'filter_autotranslate/tagging_config',
                get_string('taggingconfig_options', 'filter_autotranslate'),
                get_string('taggingconfig_options_desc', 'filter_autotranslate'),
                array_fill_keys(array_keys($tagging_options), 1), // Default to all checked
                $tagging_options
            )
        );
    }
}