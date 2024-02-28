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

// Settings page.
$string['autotranslate_settings'] = 'Autotranslate filter settings';
$string['usage'] = 'Current Usage';
$string['usagedesc'] = 'You have used {$a->count} of {$a->limit} characters.';

// Glossary page strings.
$string['glossary_title'] = 'Autotranslation Glossary';
$string['site_language'] = 'Default Site Language';
$string['supported_glossary_langs'] = 'Supported Glossary Languages';
$string['glossary_term'] = 'Add a new glossary term';
$string['sync_glossary'] = 'Sync Glossary';

// Manage page strings.
$string['manage_title'] = 'Manage Autotranslations';
$string['hash_text'] = 'ID: Hash Key';
$string['available_translations'] = 'Languages Translated';
$string['source_text'] = 'Source Text';
$string['translation_text'] = 'Translation Text';
$string['source_language'] = 'Source Language';
$string['select_target_language'] = 'Target Language';
$string['pag_first'] = '&laquo; First';
$string['pag_previous'] = '&lsaquo; Previous';
$string['pag_next'] = 'Next &rsaquo;';
$string['pag_last'] = 'Last &raquo;';
$string['selectctx'] = 'Context Levels';
$string['selectctx_desc'] = 'Context levels in Moodle that should be autotranslated';
$string['ctx_system'] = 'System';
$string['ctx_user'] = 'User';
$string['ctx_coursecat'] = 'Course Category';
$string['ctx_course'] = 'Course';
$string['ctx_module'] = 'Module';
$string['ctx_block'] = 'Block';
$string['resetstatus'] = 'Reset';
$string['allstatuses'] = 'All Statuses';
$string['autotranslated'] = 'Auto';
$string['verified'] = 'Verified';
$string['source'] = 'Source';

// Tasks.
$string['fetchtask'] = 'Autotranslation Fetch';
$string['missingapikey'] = 'You have not entered a DeepL API key';
$string['synctask'] = 'Sync DeepL Glossaries';
$string['checksourcetask'] = "Check source records";

// Capabilities.
$string['translate'] = 'Manage autotranslations';
