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
 * Strings for component 'filter_autotranslate', language 'en'
 *
 * @package    filter_autotranslate
 * @copyright  2024 Kaleb Heitzman <kaleb@jamfire.io>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['filtername'] = 'Autotranslate';
$string['pluginname'] = 'Autotranslate Filter';
$string['privacy:metadata'] = 'The Autotranslate Filter plugin does not store any personal data.';

// DeepL strings.
$string['apikey'] = 'API Key for DeepL Translations';
$string['apikey_desc'] = 'Your API key from DeepL.';
$string['managelimit'] = 'Manage Page Record Limit';
$string['managelimit_desc'] = 'Max number of records visible on the Manage page.';
$string['fetchlimit'] = 'Scheduled Task Fetch Limit';
$string['fetchlimit_desc'] = 'Max number of fetches to the DeepL API per autotranslate scheduled task run (task runs every 60 seconds).';
$string['supported_languages'] = 'bg,cs,da,de,el,en,es,et,fi,fr,hu,it,ja,lt,lv,nl,pl,pt,ro,ru,sk,sl,sv,zh'; // Do not change between translations.

// Settings page
$string['autotranslate_settings'] = 'Autotranslate filter settings';

// Manage page strings
$string['manage_title'] = "Manage Autotranslations";
$string['hash_text'] = "ID: Hash Key";
$string['source_text'] = "Source Text";
$string['translation_text'] = "Translation Text";
$string['select_source_language'] = "Select Source Language";
$string['select_target_language'] = "Select Target Language";
$string['pag_first'] = "&laquo; First";
$string['pag_previous'] = "&lsaquo; Previous";
$string['pag_next'] = "Next &rsaquo;";
$string['pag_last'] = "Last &raquo;";

// Tasks
$string['taskname'] = "Autotranslation Fetch";
$string['missingapikey'] = "You have not entered a DeepL API key";