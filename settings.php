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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * @package    filter_autotranslate
 * @copyright  2024 Kaleb Heitzman <kaleb@jamfire.io>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();


if ($hassiteconfig) {

    // Create new settings page.
    $settings = new admin_settingpage('filter_autotranslate', get_string('pluginname', 'filter_autotranslate'));

    // Add to admin menu.
    $ADMIN->add('filterplugins', $settings);

    // Use deepl machine translation.
    // $settings->add(
    //     new admin_setting_configcheckbox(
    //         'filter_autotranslate/usedeepl',
    //         get_string('usedeepl', 'filter_autotranslate'),
    //         get_string('usedeepl_desc', 'filter_autotranslate'),
    //         false
    //     )
    // );

    // DeepL apikey.
    $settings->add(
        new admin_setting_configtext(
            'filter_autotranslate/deeplapikey',
            get_string('apikey', 'filter_autotranslate'),
            get_string('apikey_desc', 'filter_autotranslate'),
            null,
            PARAM_RAW_TRIMMED,
            40
        )
    );

    // DeepL Free or Pro?
    // $settings->add(
    //     new admin_setting_configcheckbox(
    //         'filter_autotranslate/deeplpro',
    //         get_string('deeplpro', 'filter_autotranslate'),
    //         get_string('deeplpro_desc', 'filter_autotranslate'),
    //         false
    //     )
    // );

    // Use translation page autotranslation.
    $settings->add(
        new admin_setting_configcheckbox(
            'filter_autotranslate/useautotranslate',
            get_string('useautotranslate', 'filter_autotranslate'),
            get_string('useautotranslate_desc', 'filter_autotranslate'),
            true
        )
    );

}

if ($ADMIN->fulltree) {
    // $listoftranslations = get_string_manager()->get_list_of_translations(true);

    // $settings->add(new admin_setting_heading('managetranslations', '',
    //     html_writer::link(new moodle_url('/filter/translations/managetranslations.php'),
    //         get_string('managetranslations', 'filter_autotranslate'), ['class' => "btn btn-primary"])));

    // $settings->add(new admin_setting_heading('managetranslationissues', '',
    //     html_writer::link(new moodle_url('/filter/translations/managetranslationissues.php'),
    //         get_string('managetranslationissues', 'filter_autotranslate'), ['class' => "btn btn-primary"])));


    // $settings->add(new admin_setting_heading('performance', get_string('performance', 'admin'), ''));

    // $settings->add(new admin_setting_configcheckbox('filter_autotranslate/showperfdata',
    //     get_string('showperfdata', 'filter_autotranslate'), '', false));

    // $options = [];
    // foreach ([cache_store::MODE_REQUEST, cache_store::MODE_SESSION, cache_store::MODE_APPLICATION] as $mode) {
    //     $options[$mode] = get_string('mode_' . $mode, 'cache');
    // }
    // $settings->add(new admin_setting_configselect('filter_autotranslate/cachingmode',
    //     get_string('cachingmode', 'filter_autotranslate'), get_string('cachingmode_desc', 'filter_autotranslate'),
    //     cache_store::MODE_REQUEST, $options));

    // $settings->add(new admin_setting_configtextarea('filter_autotranslate/untranslatedpages',
    //     new lang_string('untranslatedpages', 'filter_autotranslate'),
    //     new lang_string('untranslatedpages_desc', 'filter_autotranslate'),
    //     '/blocks/configurable_reports/viewreport.php')
    // );

    // $settings->add(new admin_setting_configmultiselect('filter_autotranslate/excludelang',
    //     get_string('excludelang', 'filter_autotranslate'),
    //     get_string('excludelang_desc', 'filter_autotranslate'), [],
    //     $listoftranslations));

    // $settings->add(new admin_setting_heading('logging', get_string('logging', 'filter_autotranslate'), ''));

    // $settings->add(new admin_setting_configmultiselect('filter_autotranslate/logexcludelang',
    //     get_string('logexcludelang', 'filter_autotranslate'),
    //     get_string('logexcludelang_desc', 'filter_autotranslate'), [],
    //     $listoftranslations));

    // $settings->add(new admin_setting_configcheckbox('filter_autotranslate/loghistory',
    //     get_string('loghistory', 'filter_autotranslate'), '', false));

    // $settings->add(new admin_setting_configcheckbox('filter_autotranslate/logmissing',
    //     get_string('logmissing', 'filter_autotranslate'), '', false));

    // $settings->add(new admin_setting_configcheckbox('filter_autotranslate/logstale',
    //     get_string('logstale', 'filter_autotranslate'), '', false));

    // $settings->add(new admin_setting_configduration('filter_autotranslate/logdebounce',
    //     get_string('logdebounce', 'filter_autotranslate'), '', DAYSECS));

    // $settings->add(new admin_setting_heading('scheduledtasks', get_string('scheduledtasksheading', 'filter_autotranslate'), ''));

    // $settings->add(new admin_setting_configtextarea('filter_autotranslate/columndefinition',
    //     new lang_string('columndefinition', 'filter_autotranslate'),
    //     new lang_string('columndefinition_desc', 'filter_autotranslate'),
    //     '')
    // );

    // $settings->add(new admin_setting_heading('languagestringreverseapi',
    //     get_string('languagestringreverse', 'filter_autotranslate'), ''));

    // $settings->add(new admin_setting_configcheckbox('filter_autotranslate/languagestringreverse_enable',
    //     get_string('languagestringreverse_enable', 'filter_autotranslate'), '', false));

    // $settings->add(new admin_setting_heading('googletranslateapi',
    //     get_string('googletranslate', 'filter_autotranslate'), ''));

    // $settings->add(new admin_setting_configcheckbox('filter_autotranslate/google_enable',
    //     get_string('google_enable', 'filter_autotranslate'), '', false));

    // $settings->add(new admin_setting_configcheckbox('filter_autotranslate/google_backoffonerror',
    //     get_string('google_backoffonerror', 'filter_autotranslate'), '', false));

    // $settings->add(new admin_setting_configtext('filter_autotranslate/google_apiendpoint',
    //     get_string('google_apiendpoint', 'filter_autotranslate'), '', 'https://translation.googleapis.com/language/translate/v2',
    //     PARAM_URL));

    // $settings->add(new admin_setting_configtext('filter_autotranslate/google_apikey',
    //     get_string('google_apikey', 'filter_autotranslate'), '', null, PARAM_RAW_TRIMMED, 40));
}
