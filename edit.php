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
 * Allows translators to edit a specific translation for a given hash and language.
 * Updates the timereviewed field of the target language record to indicate that the
 * translation has been reviewed. The 'other' language (site language) cannot be edited
 * through this interface and must be updated via normal Moodle workflows.
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
require_capability('filter/autotranslate:manage', context_system::instance());

$hash = required_param('hash', PARAM_ALPHANUMEXT);
$tlang = optional_param('tlang', '', PARAM_LANG); // Translation language parameter
$contextid = optional_param('contextid', SYSCONTEXTID, PARAM_INT);

// Get the site language
$sitelang = get_config('core', 'lang') ?: 'en'; // Default to 'en' if not set

// Map site language to 'other' if they match
$queried_tlang = ($tlang === $sitelang) ? 'other' : $tlang;
debugging("Mapping tlang - Provided: $tlang, Site Language: $sitelang, Queried tLang: $queried_tlang", DEBUG_DEVELOPER);

// Prevent editing the 'other' language record through this interface
if ($queried_tlang === 'other') {
    throw new moodle_exception('cannoteditother', 'filter_autotranslate');
}

// Set up the page
$PAGE->set_url('/filter/autotranslate/edit.php', ['hash' => $hash, 'tlang' => $tlang, 'contextid' => $contextid]);
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('edittranslation', 'filter_autotranslate'));
$PAGE->set_heading(get_string('edittranslation', 'filter_autotranslate'));

global $DB, $OUTPUT;

// Fetch the translation record
debugging("Attempting to fetch translation - Hash: $hash, Queried tLang: $queried_tlang, ContextID: $contextid", DEBUG_DEVELOPER);
$translation = $DB->get_record('autotranslate_translations', ['hash' => $hash, 'lang' => $queried_tlang]);
if (!$translation) {
    debugging("No record found for hash $hash in language $queried_tlang, redirecting to manage.php", DEBUG_DEVELOPER);
    redirect(new moodle_url('/filter/autotranslate/manage.php'), get_string('notranslationfound', 'filter_autotranslate'), null, \core\output\notification::NOTIFY_ERROR);
}

// Fetch source text
$source_text = $DB->get_field('autotranslate_translations', 'translated_text', ['hash' => $hash, 'lang' => 'other']);
if (!$source_text) {
    $source_text = 'N/A'; // Fallback if no source text is found
}

// Rewrite @@PLUGINFILE@@ placeholders in the source text
$source_text = file_rewrite_pluginfile_urls(
    $source_text,
    'pluginfile.php',
    context_system::instance()->id,
    'filter_autotranslate',
    'translations',
    $translation->id
);
$source_text = format_text($source_text, FORMAT_HTML);

// Determine if the source text contains HTML to decide on editor type
$use_wysiwyg = false;
if ($source_text && preg_match('/<[^>]+>/', $source_text)) {
    $use_wysiwyg = true;
    debugging("Source text contains HTML, enabling WYSIWYG editor", DEBUG_DEVELOPER);
} else {
    debugging("Source text is plain text, using regular textarea", DEBUG_DEVELOPER);
}

// Initialize the form
$mform = new \filter_autotranslate\form\edit_form(null, ['translation' => $translation, 'tlang' => $queried_tlang, 'use_wysiwyg' => $use_wysiwyg]);

// Handle form submission
if ($mform->is_cancelled()) {
    redirect(new moodle_url('/filter/autotranslate/manage.php'));
} elseif ($data = $mform->get_data()) {
    require_sesskey();

    $translation->translated_text = is_array($data->translated_text) ? $data->translated_text['text'] : $data->translated_text;
    $translation->human = !empty($data->human) ? 1 : 0; // Handle checkbox submission
    $translation->timereviewed = time(); // Update timereviewed to indicate the translation has been reviewed
    $translation->timemodified = time();
    $DB->update_record('autotranslate_translations', $translation);
    redirect(new moodle_url('/filter/autotranslate/manage.php'), get_string('translationsaved', 'filter_autotranslate'));
}

// Output the header
echo $OUTPUT->header();

// Render the "Manage Translations" link and language switcher
echo '<div class="row mb-3">';
echo '<div class="col-md-6">';
$manageurl = new \moodle_url('/filter/autotranslate/manage.php');
echo \html_writer::link($manageurl, get_string('managetranslations', 'filter_autotranslate'), ['class' => 'btn btn-secondary mb-3']);
echo '</div>';

// Get all languages for this hash to populate the language switcher
$all_langs = $DB->get_fieldset_select('autotranslate_translations', 'DISTINCT lang', 'hash = ?', [$hash]);

// Add language switcher as buttons, mapping 'other' to site language for display
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