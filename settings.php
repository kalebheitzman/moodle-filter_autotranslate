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
 * Autotranslate Settings
 *
 * Purpose:
 * This file sets up the configuration options for the filter_autotranslate plugin, allowing
 * administrators to customize API settings, translation settings, task settings, and field selection
 * for translation. It is displayed under Site administration > Plugins > Filters > Autotranslate.
 *
 * Structure:
 * The settings are organized into sections using admin_setting_heading:
 * - API Configuration: Settings for the translation API (e.g., endpoint, key, model).
 * - Translation Settings: Settings for translation behavior (e.g., target languages, batch size).
 * - Task Configuration: Settings for scheduled tasks (e.g., fetch limits, task frequency).
 * - Core Fields: Dynamic matrices for selecting fields from core Moodle components and modules,
 *   split by context level (course, course_sections, course_categories) and per module (e.g., forum).
 * - Third-Party Fields: Dynamic matrices for selecting fields from third-party modules, split per
 *   module (e.g., bigbluebuttonbn, chat).
 *
 * Design Decisions:
 * - Uses Moodle's admin settings API (admin_setting_configcheckbox, admin_setting_configtext, etc.)
 *   to define form elements, ensuring consistency with Moodle's admin interface.
 * - The apiendpoint and apimodel defaults are set to match the Google Generative AI API used in
 *   fetchtranslation_task.php, ensuring compatibility out of the box.
 * - Leverages content_service::get_field_selection_options to fetch dynamic field options for the
 *   field selection sections, keeping this file focused on presentation.
 * - Uses a custom admin_setting_configfieldmatrix to render field selection as a true matrix
 *   (rows = tables, columns = fields), split by context level and module for better usability.
 * - Includes validation for fields (e.g., PARAM_URL for apiendpoint, PARAM_INT for batchsize) to
 *   ensure valid input.
 *
 * Dependencies:
 * - content_service.php: Provides field discovery and selection options for field matrices.
 * - admin_setting_configfieldmatrix.php: Custom setting class for matrix UI.
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Import required classes at the top level.
use filter_autotranslate\content_service;
use filter_autotranslate\admin_setting_configfieldmatrix;

if ($hassiteconfig) {
    if ($ADMIN->fulltree) {
        // Load content_service and matrix setting class.
        require_once($CFG->dirroot . '/filter/autotranslate/classes/content_service.php');
        require_once($CFG->dirroot . '/filter/autotranslate/classes/admin_setting_configfieldmatrix.php');

        // Section: API Configuration.
        $settings->add(
            new admin_setting_heading(
                'filter_autotranslate_apiconfig',
                get_string('apiconfig', 'filter_autotranslate'),
                get_string('apiconfig_desc', 'filter_autotranslate')
            )
        );

        // Enable Automatic Fetching of Translations.
        $settings->add(
            new admin_setting_configcheckbox(
                'filter_autotranslate/enableautofetch',
                get_string('enableautofetch', 'filter_autotranslate'),
                get_string('enableautofetch_desc', 'filter_autotranslate'),
                0 // Default to off.
            )
        );

        // API Endpoint (Base URL).
        $settings->add(
            new admin_setting_configtext(
                'filter_autotranslate/apiendpoint',
                get_string('apiendpoint', 'filter_autotranslate'),
                get_string('apiendpoint_desc', 'filter_autotranslate'),
                'https://generativelanguage.googleapis.com/v1beta/openai',
                PARAM_URL
            )
        );

        // API Key.
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

        // API Model.
        $settings->add(
            new admin_setting_configtext(
                'filter_autotranslate/apimodel',
                get_string('apimodel', 'filter_autotranslate'),
                get_string('apimodel_desc', 'filter_autotranslate'),
                'gemini-1.5-pro-latest',
                PARAM_TEXT
            )
        );

        // Section: Translation Settings.
        $settings->add(
            new admin_setting_heading(
                'filter_autotranslate_translationsettings',
                get_string('translationsettings', 'filter_autotranslate'),
                get_string('translationsettings_desc', 'filter_autotranslate')
            )
        );

        // Target languages for translation (checkboxes of enabled languages, excluding site language).
        $enabledlangs = get_string_manager()->get_list_of_translations();
        $sitelang = get_config('core', 'lang') ?: 'en';
        unset($enabledlangs[$sitelang]);

        if (empty($enabledlangs)) {
            $settings->add(
                new admin_setting_description(
                    'filter_autotranslate_targetlangs_warning',
                    '',
                    get_string('targetlangs_warning', 'filter_autotranslate')
                )
            );
        } else {
            $defaultlangs = ['es' => 1, 'fr' => 1, 'de' => 1];
            $defaultlangs = array_intersect_key($defaultlangs, $enabledlangs);
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

        // System Instructions (Glossary and Translation Instructions).
        $settings->add(
            new admin_setting_configtextarea(
                'filter_autotranslate/systeminstructions',
                get_string('systeminstructions', 'filter_autotranslate'),
                get_string('systeminstructions_desc', 'filter_autotranslate'),
                'Translate with a formal tone.',
                PARAM_TEXT
            )
        );

        // Batch Size for Translation.
        $settings->add(
            new admin_setting_configtext(
                'filter_autotranslate/batchsize',
                get_string('batchsize', 'filter_autotranslate'),
                get_string('batchsize_desc', 'filter_autotranslate'),
                10,
                PARAM_INT
            )
        );

        // Fetch translation task limit.
        $settings->add(
            new admin_setting_configtext(
                'filter_autotranslate/fetchlimit',
                get_string('fetchlimit', 'filter_autotranslate'),
                get_string('fetchlimit_desc', 'filter_autotranslate'),
                200,
                PARAM_INT
            )
        );

        // Maximum retry attempts for API calls.
        $settings->add(
            new admin_setting_configtext(
                'filter_autotranslate/maxattempts',
                get_string('maxattempts', 'filter_autotranslate'),
                get_string('maxattempts_desc', 'filter_autotranslate'),
                3,
                PARAM_INT
            )
        );

        // Rate limit threshold for API calls.
        $settings->add(
            new admin_setting_configtext(
                'filter_autotranslate/ratelimitthreshold',
                get_string('ratelimitthreshold', 'filter_autotranslate'),
                get_string('ratelimitthreshold_desc', 'filter_autotranslate'),
                50,
                PARAM_INT
            )
        );

        // Task frequency (in minutes).
        $settings->add(
            new admin_setting_configtext(
                'filter_autotranslate/taskfrequency',
                get_string('taskfrequency', 'filter_autotranslate'),
                get_string('taskfrequency_desc', 'filter_autotranslate'),
                60,
                PARAM_INT
            )
        );

        // Manual trigger option.
        $settings->add(
            new admin_setting_configcheckbox(
                'filter_autotranslate/enablemanualtrigger',
                get_string('enablemanualtrigger', 'filter_autotranslate'),
                get_string('enablemanualtrigger_desc', 'filter_autotranslate'),
                0
            )
        );

        // Section: Task Configuration.
        $settings->add(
            new admin_setting_heading(
                'filter_autotranslate_taskconfig',
                get_string('taskconfig', 'filter_autotranslate'),
                get_string('taskconfig_desc', 'filter_autotranslate')
            )
        );

        // Records per run for tagcontent_task.
        $settings->add(
            new admin_setting_configtext(
                'filter_autotranslate/recordsperrun',
                get_string('recordsperrun', 'filter_autotranslate'),
                get_string('recordsperrun_desc', 'filter_autotranslate'),
                1000,
                PARAM_INT
            )
        );

        // Manage limit for tagcontent_task.
        $settings->add(
            new admin_setting_configtext(
                'filter_autotranslate/managelimit',
                get_string('managelimit', 'filter_autotranslate', null, true) ?: 'Manage Limit',
                get_string('managelimit_desc', 'filter_autotranslate', null, true) ?:
                'The maximum number of records to process in a single batch for the tagcontent_task.',
                20,
                PARAM_INT
            )
        );

        // Section: Field Selection (dynamic matrices).
        // Instantiate content_service for field options.
        $contentservice = new content_service($DB);

        // Define build_field_defaults only if it doesn't exist.
        if (!function_exists('build_field_defaults')) {
            /**
             * Builds default selections for the field matrix.
             *
             * Creates a default array of table.field => 1 for common fields (name, intro, summary) to
             * pre-check them in the matrix, improving usability.
             *
             * @param array $tables Associative array of table => fields from content_service.
             * @return array Default selections (e.g., ['course.name' => 1]).
             */
            function build_field_defaults($tables) {
                $defaults = [];
                foreach ($tables as $table => $fields) {
                    foreach ($fields as $field) {
                        if (in_array(
                                $field, [
                                    'fullname', 'name', 'intro', 'summary',
                                    'content', 'title', 'text',
                                    'answer', 'response', 'activity',
                                ]
                            )) {
                            $defaults["$table.$field"] = 1;
                        }
                    }
                }
                return $defaults;
            }
        }

        // Fetch field options from content_service.
        $fieldoptions = $contentservice->get_field_selection_options();

        // Core Fields Section.
        $settings->add(new admin_setting_heading(
            'filter_autotranslate_corefields',
            get_string('corefields', 'filter_autotranslate'),
            get_string('corefields_desc', 'filter_autotranslate')
        ));

        // Add matrices for core non-module contexts (course, course_sections, course_categories).
        foreach (['course', 'course_sections', 'course_categories'] as $contextkey) {
            if (!empty($fieldoptions['core'][$contextkey])) {
                $settings->add(new admin_setting_configfieldmatrix(
                    "filter_autotranslate/{$contextkey}_fields",
                    get_string($contextkey, 'filter_autotranslate'),
                    get_string("{$contextkey}_desc", 'filter_autotranslate'),
                    $fieldoptions['core'][$contextkey],
                    build_field_defaults($fieldoptions['core'][$contextkey])
                ));
            }
        }

        // Add matrices for each core module.
        if (!empty($fieldoptions['core']['modules'])) {
            foreach ($fieldoptions['core']['modules'] as $modname => $tables) {
                if (!empty($tables)) {
                    // Use the module's own language file for the display name (e.g., "Assignment" for assign).
                    $moddisplayname = get_string('pluginname', "mod_$modname", null, false) ?: ucfirst($modname);
                    $settings->add(new admin_setting_configfieldmatrix(
                        "filter_autotranslate/{$modname}_fields",
                        $moddisplayname . ' Fields',
                        get_string('corefields_desc', 'filter_autotranslate'),
                        $tables,
                        build_field_defaults($tables)
                    ));
                }
            }
        }

        // Third-Party Fields Section.
        if (!empty($fieldoptions['thirdparty'])) {
            $settings->add(new admin_setting_heading(
                'filter_autotranslate_thirdpartyfields',
                get_string('thirdpartyfields', 'filter_autotranslate'),
                get_string('thirdpartyfields_desc', 'filter_autotranslate')
            ));

            // Add matrices for each third-party module.
            foreach ($fieldoptions['thirdparty'] as $modname => $tables) {
                if (!empty($tables)) {
                    // Use the module's own language file for the display name, if available.
                    $moddisplayname = get_string('pluginname', "mod_$modname", null, false) ?: ucfirst($modname);
                    $settings->add(new admin_setting_configfieldmatrix(
                        "filter_autotranslate/{$modname}_fields",
                        get_string('thirdpartymodulefields', 'filter_autotranslate', ['module' => $moddisplayname]),
                        get_string('thirdpartyfields_desc', 'filter_autotranslate'),
                        $tables,
                        build_field_defaults($tables)
                    ));
                }
            }
        }
    }
}
