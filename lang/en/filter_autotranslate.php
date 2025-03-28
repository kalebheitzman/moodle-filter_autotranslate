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
 * Language strings for filter_autotranslate
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['actions'] = 'Actions';
$string['all'] = 'All';
$string['apiconfig'] = 'API Configuration';
$string['apiconfig_desc'] = 'Configure the API settings for the translation service used to fetch automatic translations.';
$string['apiendpoint'] = 'API Endpoint (Base URL)';
$string['apiendpoint_desc'] = 'The base URL of the OpenAI-compatible API (e.g., https://generativelanguage.googleapis.com/v1beta/openai for Google Generative AI).';
$string['apikey'] = 'API Key';
$string['apikey_desc'] = 'The API key for authenticating with the translation service. Required for most services, including Google Generative AI. Leave blank if not required (e.g., for local setups).';
$string['apimodel'] = 'API Model';
$string['apimodel_desc'] = 'The name of the model to use for translations (e.g., gemini-1.5-pro-latest for Google Generative AI). Refer to your API provider\'s documentation for available models.';
$string['autotranslated'] = 'Autotranslated';
$string['batchsize'] = 'Batch Size for Translation';
$string['batchsize_desc'] = 'The maximum number of texts to translate in a single API call (e.g., 10). Adjust based on the model\'s token limits to optimize performance.';
$string['cannoteditother'] = 'The site language (other) cannot be edited through this interface. Please update the source content directly.';
$string['contextlevel'] = 'Context Level';
$string['course'] = 'Course';
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
$string['enableautofetch'] = 'Enable Automatic Fetching of Translations';
$string['enableautofetch_desc'] = 'If enabled, the fetchtranslation_task will automatically fetch translations from the API for untagged content. If disabled, the task will skip fetching translations, allowing manual translation or other processes to handle translation.';
$string['enablemanualtrigger'] = 'Enable Manual Trigger';
$string['enablemanualtrigger_desc'] = 'If enabled, administrators can manually trigger the autotranslate tasks from the admin interface, useful for immediate processing outside the scheduled frequency.';
$string['fetchlimit'] = 'Fetch Translation Task Limit';
$string['fetchlimit_desc'] = 'The maximum number of translations to fetch in a single run of the fetchtranslation_task (e.g., 200). Higher values process more records but may increase runtime and API usage.';
$string['filterbyhumanreviewed'] = 'Filter by Human Reviewed';
$string['filterbylanguage'] = 'Filter by Language';
$string['filterbyneedsreview'] = 'Filter by Needs Review';
$string['filtername'] = 'Autotranslate Filter';
$string['hash'] = 'Hash';
$string['humanreviewed'] = 'Human Reviewed';
$string['humantranslated'] = 'Human Translated';
$string['language'] = 'Language';
$string['last_modified'] = 'Last Modified';
$string['last_reviewed'] = 'Last Reviewed';
$string['manage'] = 'Manage Translations';
$string['manageautotranslations'] = 'Manage Autotranslations';
$string['managelimit'] = 'Manage Limit';
$string['managelimit_desc'] = 'The maximum number of records to process in a single batch for the tagcontent_task (e.g., 20). This controls the batch size for tagging operations, balancing performance and memory usage.';
$string['managetranslations'] = 'Manage Translations';
$string['maxattempts'] = 'Maximum Retry Attempts';
$string['maxattempts_desc'] = 'The maximum number of retry attempts for API calls in case of temporary failures (e.g., network issues or rate limiting). For example, 3 attempts allow the task to retry twice after an initial failure.';
$string['needsreview'] = 'Needs Review';
$string['no'] = 'No';
$string['no_translations_to_review'] = 'No translations need review at this time.';
$string['notranslationfound'] = 'No translation found for the specified hash.';
$string['perpage'] = 'Translations per page';
$string['pluginname'] = 'Autotranslate Filter';
$string['purgemappingstask'] = 'Purge hash-course mappings';
$string['ratelimitthreshold'] = 'Rate Limit Threshold';
$string['ratelimitthreshold_desc'] = 'The number of API requests allowed per minute before the fetchtranslation_task pauses to avoid rate limiting (e.g., 50 requests/minute). Adjust based on your API provider\'s rate limits.';
$string['rebuildtranslations'] = 'Rebuild Translations';
$string['recordsperrun'] = 'Records Per Run for Tagging Task';
$string['recordsperrun_desc'] = 'The maximum number of records to process per table in a single run of the tagcontent_task. Lower values reduce memory usage and runtime but may take more runs to process all records (e.g., 1000).';
$string['reviewstatus'] = 'Review Status';
$string['savechanges'] = 'Save Changes';
$string['search:translation'] = 'Translations';
$string['selectctx'] = 'Select Contexts for Autotranslation';
$string['selectctx_desc'] = 'Choose the context levels where text should be automatically tagged and translated (e.g., courses, modules).';
$string['sourcetext'] = 'Source Text';
$string['switchlanguage'] = 'Switch Language';
$string['systeminstructions'] = 'System Instructions';
$string['systeminstructions_desc'] = 'Instructions for the translation model, including glossary and style, applied to all target languages (e.g., "Use the following glossary: \'Submit\' => \'Enviar\' (Spanish), \'Soumettre\' (French), \'Einreichen\' (German). Translate with a formal tone.").';
$string['taggingconfig'] = 'Tagging Configuration';
$string['taggingconfig_desc'] = 'Configure which tables and fields should be tagged with {t:hash} tags for translation by the Autotranslate filter.';
$string['taggingconfig_options'] = 'Tables and Fields to Tag';
$string['taggingconfig_options_desc'] = 'Select the tables and fields to be tagged with {t:hash} tags for translation. By default, commonly used tables (e.g., course, course_sections, page, assign, forum, quiz, resource, folder) are selected. Uncheck any fields you do not want to be processed.';
$string['targetlangs'] = 'Target Languages for Translation';
$string['targetlangs_desc'] = 'Select the languages to translate content into. These languages are sourced from the enabled language packs on this Moodle site (excluding the site language) and will be applied globally to all contexts.';
$string['targetlangs_warning'] = 'No additional language packs are installed. Please install language packs to enable translation.';
$string['taskconfig'] = 'Task Configuration';
$string['taskconfig_desc'] = 'Configure how the scheduled tasks (tagcontent_task and fetchtranslation_task) are executed.';
$string['taskfrequency'] = 'Task Frequency';
$string['taskfrequency_desc'] = 'How often (in minutes) the autotranslate tasks (tagcontent_task and fetchtranslation_task) should run (e.g., 60 for hourly execution).';
$string['translatedtext'] = 'Translated Text';
$string['translation'] = 'Translation';
$string['translation_help'] = 'Enter the translated text for the selected language. Ensure the translation accurately reflects the source text while adapting it for cultural and linguistic nuances.';
$string['translations_needing_review'] = 'Translations Needing Review';
$string['translationsaved'] = 'Translation saved successfully';
$string['translationsettings'] = 'Translation Settings';
$string['translationsettings_desc'] = 'Configure how translations are performed, including target languages and API behavior.';
$string['translationsrebuilt'] = 'Translations have been rebuilt successfully.';
$string['translationsupdated'] = 'Translations updated successfully';
$string['unknown_course'] = 'Unknown Course';
$string['updatetranslations'] = 'Update Translations';
$string['yes'] = 'Yes';
