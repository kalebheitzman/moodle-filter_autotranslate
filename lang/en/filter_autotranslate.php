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
 * Language strings for filter_autotranslate
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Autotranslate Filter';
$string['filtername'] = 'Autotranslate Filter';
$string['autotranslated'] = 'Autotranslated';
$string['apiconfig'] = 'API Configuration';
$string['apiconfig_desc'] = 'Configure the API settings for the translation service.';
$string['apiendpoint'] = 'API Endpoint (Base URL)';
$string['apiendpoint_desc'] = 'The base URL of the OpenAI-spec\'d API (e.g., http://localhost:11434/v1 for a local Ollama instance).';
$string['apikey'] = 'API Key';
$string['apikey_desc'] = 'The API key for authenticating with the translation service. Leave blank if not required (e.g., for local setups).';
$string['apimodel'] = 'API Model';
$string['apimodel_desc'] = 'The name of the model to use for translations (e.g., mistral, llama3).';
$string['translationsettings'] = 'Translation Settings';
$string['translationsettings_desc'] = 'Configure how translations are performed.';
$string['targetlangs'] = 'Target Languages for Translation';
$string['targetlangs_desc'] = 'Select the languages to translate content into. These languages are sourced from the enabled language packs on this Moodle site (excluding the site language) and will be applied globally to all contexts.';
$string['systeminstructions'] = 'System Instructions';
$string['systeminstructions_desc'] = 'Instructions for the translation model, including glossary and style, applied to all target languages (e.g., "Use the following glossary: \'Submit\' => \'Enviar\' (Spanish), \'Soumettre\' (French), \'Einreichen\' (German). Translate with a formal tone.").';
$string['batchsize'] = 'Batch Size for Translation';
$string['batchsize_desc'] = 'The number of texts to translate in a single API call (e.g., 10). Adjust based on model token limits.';
$string['taskconfig'] = 'Task Configuration';
$string['taskconfig_desc'] = 'Configure how translation tasks are executed.';
$string['fetchlimit'] = 'Fetch Translation Task Limit';
$string['fetchlimit_desc'] = 'The maximum number of translations to fetch in a single task run.';
$string['maxattempts'] = 'Maximum Retry Attempts';
$string['maxattempts_desc'] = 'The maximum number of retry attempts for API calls in case of temporary failures (e.g., network issues or rate limiting).';
$string['ratelimitthreshold'] = 'Rate Limit Threshold';
$string['ratelimitthreshold_desc'] = 'The number of API requests allowed per minute before pausing to avoid rate limiting (e.g., 50 requests/minute).';
$string['selectctx'] = 'Select Contexts for Autotranslation';
$string['selectctx_desc'] = 'Choose the context levels where text should be automatically translated.';
$string['ctx_system'] = 'System';
$string['ctx_user'] = 'User';
$string['ctx_coursecat'] = 'Course Category';
$string['ctx_course'] = 'Course';
$string['ctx_module'] = 'Module';
$string['ctx_block'] = 'Block';
$string['taskfrequency'] = 'Task Frequency';
$string['taskfrequency_desc'] = 'How often (in minutes) the autotranslate task should run.';
$string['enablemanualtrigger'] = 'Enable Manual Trigger';
$string['enablemanualtrigger_desc'] = 'Allow administrators to manually trigger the autotranslate task.';
$string['search:translation'] = 'Translations';
$string['edittranslation'] = 'Edit Translation';
$string['translatedtext'] = 'Translated Text';
$string['translationsaved'] = 'Translation saved successfully';
$string['translation'] = 'Translation';
$string['translation_help'] = 'Enter the translated text for the selected language. Ensure the translation accurately reflects the source text while adapting it for cultural and linguistic nuances.';
$string['savechanges'] = 'Save Changes';
$string['managetranslations'] = 'Manage Translations';
$string['hash'] = 'Hash';
$string['language'] = 'Language';
$string['humanreviewed'] = 'Human Reviewed';
$string['actions'] = 'Actions';
$string['edit'] = 'Edit';
$string['yes'] = 'Yes';
$string['no'] = 'No';
$string['translationsupdated'] = 'Translations updated successfully';
$string['updatetranslations'] = 'Update Translations';
$string['contextlevel'] = 'Context Level';
$string['manageautotranslations'] = 'Manage Autotranslations';
$string['perpage'] = 'Translations per page';