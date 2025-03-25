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
 * Autotranslate Edit Page
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_autotranslate;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/weblib.php'); // For file_rewrite_pluginfile_urls()
require_once($CFG->dirroot . '/filter/autotranslate/classes/helper.php');
require_once($CFG->dirroot . '/filter/autotranslate/classes/translation_repository.php');
require_once($CFG->dirroot . '/filter/autotranslate/classes/translation_service.php');

/**
 * Allows administrators to edit a specific translation in the filter_autotranslate plugin.
 *
 * Purpose:
 * This page enables administrators to edit a translation for a given hash and language in the
 * autotranslate_translations table. It updates the translated_text, human status, and timereviewed
 * fields, ensuring that the 'other' language (site language) cannot be edited through this interface.
 * The page also provides a language switcher to navigate between translations for the same hash.
 *
 * Design Decisions:
 * - Uses translation_repository.php to fetch translation data, ensuring read-only database access.
 * - Uses translation_service.php to update translations, centralizing database write operations.
 * - Prevents editing of the 'other' language record, redirecting users to update the source content
 *   directly through normal Moodle workflows.
 * - Dynamically determines whether to use a WYSIWYG editor based on the presence of HTML in the
 *   translated text (not the source text), ensuring the editor matches the content's needs.
 * - Includes a language switcher to allow quick navigation between translations for the same hash.
 * - @@PLUGINFILE@@ URLs are rewritten when translations are stored (in tagging_service.php and
 *   translation_service.php), so no rewriting is needed here at display time.
 *
 * Dependencies:
 * - helper.php: For utility functions (e.g., map_language_to_other).
 * - translation_repository.php: For fetching translation data.
 * - translation_service.php: For updating translations.
 * - edit_form.php: For the edit form.
 * - edit.mustache: For rendering the page.
 */

// Require login and check capability
require_login();
require_capability('filter/autotranslate:manage', context_system::instance());

// Get parameters
$hash = required_param('hash', PARAM_ALPHANUMEXT);
$tlang = required_param('tlang', PARAM_RAW); // Use PARAM_RAW to get the exact value
$contextid = optional_param('contextid', SYSCONTEXTID, PARAM_INT);

// Validate and normalize tlang
if (empty($tlang)) {
    redirect(new moodle_url('/filter/autotranslate/manage.php'), get_string('invalidtlang', 'filter_autotranslate'), null, \core\output\notification::NOTIFY_ERROR);
    exit();
}

$tlang = clean_param($tlang, PARAM_TEXT);
$tlang = strtolower(trim($tlang));

// Ensure tlang is not empty after cleaning
if (empty($tlang)) {
    redirect(new moodle_url('/filter/autotranslate/manage.php'), get_string('invalidtlang', 'filter_autotranslate'), null, \core\output\notification::NOTIFY_ERROR);
    exit();
}

// Map the language to 'other' if it matches the site language
$queried_tlang = helper::map_language_to_other($tlang);

// Prevent editing the 'other' language record through this interface
if ($tlang === 'other' || $queried_tlang === 'other') {
    redirect(new moodle_url('/filter/autotranslate/manage.php'), get_string('cannoteditother', 'filter_autotranslate'), null, \core\output\notification::NOTIFY_ERROR);
    exit();
}

// Set up the page
$PAGE->set_url('/filter/autotranslate/edit.php', ['hash' => $hash, 'tlang' => $tlang, 'contextid' => $contextid]);
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('edittranslation', 'filter_autotranslate'));
$PAGE->set_heading(get_string('edittranslation', 'filter_autotranslate'));

global $DB, $OUTPUT;

// Initialize the translation repository and service
$repository = new translation_repository($DB);
$service = new translation_service($DB);

// Fetch the translation record
$translation = $repository->get_translation($hash, $queried_tlang);
if (!$translation) {
    redirect(new moodle_url('/filter/autotranslate/manage.php'), get_string('notranslationfound', 'filter_autotranslate'), null, \core\output\notification::NOTIFY_ERROR);
}

// Fetch source text
$source_text = $repository->get_source_text($hash);
if (!$source_text || $source_text === 'N/A') {
    $source_text = 'N/A'; // Fallback if no source text is found
}

// URLs are already rewritten when stored, so just format the text for display
$source_text = format_text($source_text, FORMAT_HTML);

// Determine if the translated text contains HTML to decide on editor type
$use_wysiwyg = false;
if ($translation->translated_text && preg_match('/<[^>]+>/', $translation->translated_text)) {
    $use_wysiwyg = true;
}

// Initialize the form with tlang explicitly passed and set the form action to preserve tlang
$mform = new form\edit_form(
    new moodle_url('/filter/autotranslate/edit.php', ['hash' => $hash, 'tlang' => $tlang, 'contextid' => $contextid]),
    ['translation' => $translation, 'tlang' => $queried_tlang, 'use_wysiwyg' => $use_wysiwyg]
);

// Handle form submission
if ($mform->is_cancelled()) {
    redirect(new moodle_url('/filter/autotranslate/manage.php'));
} elseif ($data = $mform->get_data()) {
    try {
        require_sesskey();
    } catch (\moodle_exception $e) {
        redirect(new moodle_url('/filter/autotranslate/manage.php'), get_string('invalidsesskey', 'error'), null, \core\output\notification::NOTIFY_ERROR);
    }

    // Re-fetch the translation record to ensure it still exists
    $translation = $repository->get_translation($hash, $queried_tlang);
    if (!$translation) {
        redirect(new moodle_url('/filter/autotranslate/manage.php'), get_string('notranslationfound', 'filter_autotranslate'), null, \core\output\notification::NOTIFY_ERROR);
    }

    // Update the translation record
    $translation->translated_text = is_array($data->translated_text) ? $data->translated_text['text'] : $data->translated_text;
    $translation->human = !empty($data->human) ? 1 : 0; // Handle checkbox submission
    $translation->timereviewed = time(); // Update timereviewed to indicate the translation has been reviewed
    $translation->timemodified = time();

    // Use the translation service to update the record
    $service->update_translation($translation);

    redirect(new moodle_url('/filter/autotranslate/manage.php'), get_string('translationsaved', 'filter_autotranslate'));
}

// Output the header
echo $OUTPUT->header();

// Render the "Manage Translations" link and language switcher
echo '<div class="row mb-3">';
echo '<div class="col-md-6">';
$manageurl = new moodle_url('/filter/autotranslate/manage.php');
echo \html_writer::link($manageurl, get_string('managetranslations', 'filter_autotranslate'), ['class' => 'btn btn-secondary mb-3']);
echo '</div>';

// Get all languages for this hash to populate the language switcher
$all_langs = $repository->get_all_languages($hash);

// Add language switcher as buttons, mapping 'other' to site language for display
$sitelang = get_config('core', 'lang') ?: 'en';
$lang_buttons = [];
foreach ($all_langs as $lang) {
    if ($lang === 'other') {
        continue; // Skip the 'other' language in the switcher
    }
    $display_lang = ($lang === 'other') ? $sitelang : $lang;
    $url = new moodle_url('/filter/autotranslate/edit.php', ['hash' => $hash, 'tlang' => $lang, 'contextid' => $contextid]);
    $class = ($lang === $queried_tlang) ? 'btn btn-primary' : 'btn btn-secondary';
    $lang_buttons[] = \html_writer::link($url, strtoupper($display_lang), ['class' => $class . ' mr-1']);
}
echo \html_writer::tag('div', get_string('switchlanguage', 'filter_autotranslate') . ': ' . implode(' ', $lang_buttons), ['class' => 'mb-3 col-md-6 text-end']);
echo '</div>';

// Render the template
$data = [
    'form' => $mform->render(),
    'source_text' => $source_text,
    'source_text_label' => get_string('sourcetext', 'filter_autotranslate'),
];
echo $OUTPUT->render_from_template('filter_autotranslate/edit', $data);

echo $OUTPUT->footer();