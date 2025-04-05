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
 * Language strings for the Autotranslate plugin.
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['actions'] = 'Actions';
$string['all'] = 'All';
$string['apiconfig'] = 'API Configuration';
$string['apiconfig_desc'] = 'Configure the settings for the translation API used by the Autotranslate filter.';
$string['apiendpoint'] = 'API Endpoint';
$string['apiendpoint_desc'] = 'The base URL of the translation API (e.g., Google Generative AI API).';
$string['apierror'] = 'Error communicating with the translation API: {$a}';
$string['apikey'] = 'API Key';
$string['apikey_desc'] = 'The API key for authenticating with the translation API.';
$string['apimodel'] = 'API Model';
$string['apimodel_desc'] = 'The model to use for translation (e.g., gemini-1.5-pro-latest).';
$string['autotranslate'] = 'Autotranslate';
$string['autotranslateadhoc'] = 'Autotranslate Adhoc Task';
$string['autotranslated'] = 'Autotranslated';
$string['batchsize'] = 'Batch Size';
$string['batchsize_desc'] = 'The number of translations to process in a single batch.';
$string['cannoteditother'] = 'The site language (other) cannot be edited through this interface. Please update the source content directly.';
$string['contextlevel'] = 'Context Level';
$string['corefields'] = 'Core Moodle Fields';
$string['corefields_desc'] = 'Select the fields from core Moodle components and modules to be tagged for translation.';
$string['course'] = 'Course Fields';
$string['course_categories'] = 'Course Categories Fields';
$string['course_categories_desc'] = 'Select the fields from the course_categories table to be tagged for translation.';
$string['course_desc'] = 'Select the fields from the course table to be tagged for translation.';
$string['course_sections'] = 'Course Sections Fields';
$string['course_sections_desc'] = 'Select the fields from the course_sections table to be tagged for translation.';
$string['createtranslation'] = 'Create Translation';
$string['ctx_10'] = 'System';
$string['ctx_30'] = 'User';
$string['ctx_40'] = 'Course Category';
$string['ctx_50'] = 'Course';
$string['ctx_70'] = 'Module';
$string['ctx_80'] = 'Block';
$string['ctx_block'] = 'Block';
$string['ctx_course'] = 'Course';
$string['ctx_coursecat'] = 'Course Category';
$string['ctx_module'] = 'Module';
$string['ctx_system'] = 'System';
$string['ctx_user'] = 'User';
$string['edit'] = 'Edit';
$string['edittranslation'] = 'Edit Translation';
$string['enableautofetch'] = 'Enable Automatic Translation';
$string['enableautofetch_desc'] = 'If enabled, translations can be automatically fetched for untagged content in a course.';
$string['errorsetting'] = 'Error saving setting';
$string['fetchlimit'] = 'Fetch Limit';
$string['fetchlimit_desc'] = 'The maximum number of translations to fetch in a single task run.';
$string['filter'] = 'Filter';
$string['filterbyhumanreviewed'] = 'Filter by Human Reviewed';
$string['filterbylanguage'] = 'Filter by Language';
$string['filterbyneedsreview'] = 'Filter by Needs Review';
$string['filtername'] = 'Autotranslate Filter';
$string['hash'] = 'Hash';
$string['humanreviewed'] = 'Human Reviewed';
$string['humantranslated'] = 'Human Translated';
$string['invalidtlang'] = 'Invalid target language.';
$string['language'] = 'Language';
$string['last_modified'] = 'Last Modified';
$string['last_reviewed'] = 'Last Reviewed';
$string['manage'] = 'Manage Translations';
$string['manageautotranslations'] = 'Manage Autotranslations';
$string['managelimit'] = 'Manage Limit';
$string['managelimit_desc'] = 'The maximum number of records to process in a single batch for the tagcontent task.';
$string['managetranslations'] = 'Manage Translations';
$string['maxattempts'] = 'Maximum Retry Attempts';
$string['maxattempts_desc'] = 'The maximum number of retry attempts for failed API calls.';
$string['needsreview'] = 'Needs Review';
$string['no'] = 'No';
$string['no_translations_to_review'] = 'No translations need review at this time.';
$string['notranslationfound'] = 'No translation found for the specified hash.';
$string['perpage'] = 'Translations per page';
$string['pluginname'] = 'Autotranslate Filter';
$string['purgemappingstask'] = 'Purge hash-course mappings';
$string['ratelimitthreshold'] = 'Rate Limit Threshold';
$string['ratelimitthreshold_desc'] = 'The threshold for rate limiting API calls (requests per minute).';
$string['rebuildtranslations'] = 'Rebuild Translations';
$string['rebuildtranslationsadhoc'] = 'Rebuild Translations Adhoc Task';
$string['recordsperrun'] = 'Records Per Run';
$string['recordsperrun_desc'] = 'The maximum number of records to process in a single run of the tagcontent task.';
$string['reviewstatus'] = 'Review Status';
$string['savechanges'] = 'Save Changes';
$string['search:translation'] = 'Translations';
$string['sourcetext'] = 'Source Text';
$string['switchlanguage'] = 'Switch Language';
$string['systeminstructions'] = 'System Instructions';
$string['systeminstructions_desc'] = 'Instructions for the translation API, such as tone or glossary terms (e.g., "Translate with a formal tone").';
$string['table'] = 'Table';
$string['tagcontenttask'] = 'Tag Content Scheduled Task';
$string['targetlangs'] = 'Target Languages';
$string['targetlangs_desc'] = 'Select the languages to translate content into. The site language is excluded.';
$string['targetlangs_warning'] = 'No additional languages are installed. Please install language packs to enable translation.';
$string['taskconfig'] = 'Task Configuration';
$string['taskconfig_desc'] = 'Configure the scheduled tasks for the Autotranslate filter.';
$string['taskfrequency'] = 'Task Frequency';
$string['taskfrequency_desc'] = 'The frequency (in minutes) at which the translation tasks should run.';
$string['tasknotfound'] = 'Task not found';
$string['thirdpartyfields'] = 'Third-Party Module Fields';
$string['thirdpartyfields_desc'] = 'Select the fields from third-party modules to be tagged for translation.';
$string['thirdpartymodulefields'] = 'Third-Party Module: {$a->module} Fields';
$string['translatedtext'] = 'Translated Text';
$string['translation'] = 'Translation';
$string['translation_help'] = 'Enter the translated text for the selected language. Ensure the translation accurately reflects the source text while adapting it for cultural and linguistic nuances.';
$string['translationexists'] = 'A translation already exists for this language. Redirecting to edit page.';
$string['translations_needing_review'] = 'Translations Needing Review';
$string['translationsaved'] = 'Translation saved successfully';
$string['translationsettings'] = 'Translation Settings';
$string['translationsettings_desc'] = 'Configure how translations are handled by the Autotranslate filter.';
$string['translationsrebuilt'] = 'Translations have been rebuilt successfully.';
$string['translationsupdated'] = 'Translations updated successfully';
$string['unknown_course'] = 'Unknown Course';
$string['updatetranslations'] = 'Update Translations';
$string['yes'] = 'Yes';
