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
 * Autotranslate Edit Page
 *
 * Purpose:
 * This script renders the edit page for the filter_autotranslate plugin, allowing administrators
 * to modify an existing translation in the `filter_autotranslate_translations` table. It updates
 * the translated_text, human status, and timereviewed fields, preventing edits to the 'other'
 * language record, and includes a language switcher for navigating translations of the same hash.
 *
 * Usage:
 * Accessed via '/filter/autotranslate/edit.php' with required parameters `hash` and `tlang`,
 * typically from the manage page’s "Edit" link. Displays a form alongside the source text and
 * processes updates to the translation.
 *
 * Design Decisions:
 * - Uses `translation_source` for read-only data access to fetch translation and source text.
 * - Uses `content_service` to update translations, centralizing database writes.
 * - Prevents editing of 'other' language records, redirecting to manage.php with an error.
 * - Dynamically selects WYSIWYG editor or textarea based on HTML in the translated text.
 * - Includes a language switcher for quick navigation between translations of the same hash.
 * - Relies on `content_service` for @@PLUGINFILE@@ URL rewriting, so no local rewriting is needed.
 *
 * Dependencies:
 * - `text_utils.php`: For utility functions (e.g., map_language_to_other).
 * - `translation_source.php`: For fetching translation data.
 * - `content_service.php`: For updating translations.
 * - `form/edit_form.php`: For the edit form.
 * - `templates/edit.mustache`: For rendering the page.
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_autotranslate;

use filter_autotranslate\form\edit_form;
use filter_autotranslate\text_utils;
use filter_autotranslate\translation_source;
use filter_autotranslate\content_service;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/weblib.php');

defined('MOODLE_INTERNAL') || die();

// Require login and check capability.
require_login();
require_capability('filter/autotranslate:manage', \context_system::instance());

// Get parameters.
$hash = required_param('hash', PARAM_ALPHANUMEXT);
$tlang = required_param('tlang', PARAM_RAW);
$contextid = optional_param('contextid', SYSCONTEXTID, PARAM_INT);

// Validate and normalize tlang.
if (empty($tlang)) {
    redirect(
        new \moodle_url('/filter/autotranslate/manage.php'),
        get_string('invalidtlang', 'filter_autotranslate'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

$tlang = clean_param($tlang, PARAM_TEXT);
$tlang = strtolower(trim($tlang));

if (empty($tlang)) {
    redirect(
        new \moodle_url('/filter/autotranslate/manage.php'),
        get_string('invalidtlang', 'filter_autotranslate'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

// Map the language to 'other' if it matches the site language.
$queriedtlang = text_utils::map_language_to_other($tlang);

// Prevent editing the 'other' language record.
if ($tlang === 'other' || $queriedtlang === 'other') {
    redirect(
        new \moodle_url('/filter/autotranslate/manage.php'),
        get_string('cannoteditother', 'filter_autotranslate'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

// Set up the page.
$PAGE->set_url('/filter/autotranslate/edit.php', ['hash' => $hash, 'tlang' => $tlang, 'contextid' => $contextid]);
$PAGE->set_context(\context_system::instance());
$PAGE->set_title(get_string('edittranslation', 'filter_autotranslate'));
$PAGE->set_heading(get_string('edittranslation', 'filter_autotranslate'));

global $DB, $OUTPUT;

// Initialize services.
$translationsource = new translation_source($DB);
$contentservice = new content_service($DB);

// Fetch the translation record.
$translation = $translationsource->get_translation($hash, $queriedtlang);
if (!$translation) {
    redirect(
        new \moodle_url('/filter/autotranslate/manage.php'),
        get_string('notranslationfound', 'filter_autotranslate'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

// Fetch source text.
$sourcetext = $translationsource->get_source_text($hash);
if (!$sourcetext || $sourcetext === 'N/A') {
    $sourcetext = 'N/A';
}
$sourcetext = format_text($sourcetext, FORMAT_HTML);

// Determine if translated text contains HTML for editor type.
$usewysiwyg = $translation->translated_text && preg_match('/<[^>]+>/', $translation->translated_text);

// Initialize the form.
$mform = new edit_form(
    new \moodle_url('/filter/autotranslate/edit.php', ['hash' => $hash, 'tlang' => $tlang, 'contextid' => $contextid]),
    ['translation' => $translation, 'tlang' => $queriedtlang, 'use_wysiwyg' => $usewysiwyg]
);

// Handle form submission.
if ($mform->is_cancelled()) {
    redirect(new \moodle_url('/filter/autotranslate/manage.php'));
} else if ($data = $mform->get_data()) {
    try {
        require_sesskey();
    } catch (\moodle_exception $e) {
        redirect(
            new \moodle_url('/filter/autotranslate/manage.php'),
            get_string('invalidsesskey', 'error'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    // Re-fetch to ensure it still exists.
    $translation = $translationsource->get_translation($hash, $queriedtlang);
    if (!$translation) {
        redirect(
            new \moodle_url('/filter/autotranslate/manage.php'),
            get_string('notranslationfound', 'filter_autotranslate'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    // Derive context from courseid if available, otherwise null.
    $context = ($data->courseid > 0) ? \context_course::instance($data->courseid) : null;

    // Define translatedtext from form data.
    $translatedtext = is_array($data->translated_text) ? $data->translated_text['text'] : $data->translated_text;

    // Update the translation.
    $contentservice->upsert_translation(
        $translation->hash,                 // Use hash from the translation record.
        $translation->lang,                 // Use lang from the translation record.
        $translatedtext,                    // New text from the form.
        $translation->contextlevel,         // Context level from the existing record.
        $context,                           // Context instance (if applicable).
        !empty($data->human) ? 1 : 0        // Human status from the form.
    );

    redirect(
        new \moodle_url('/filter/autotranslate/manage.php'),
        get_string('translationsaved', 'filter_autotranslate')
    );
}

// Output the header.
echo $OUTPUT->header();

// Render the "Manage Translations" link and language switcher.
echo '<div class="row mb-3">';
echo '<div class="col-md-6">';
$manageurl = new \moodle_url('/filter/autotranslate/manage.php');
echo \html_writer::link(
    $manageurl,
    get_string('managetranslations', 'filter_autotranslate'),
    ['class' => 'btn btn-secondary mb-3']
);
echo '</div>';

// Language switcher.
$alllangs = $translationsource->get_all_languages($hash);
$sitelang = get_config('core', 'lang') ?: 'en';
$langbuttons = [];
foreach ($alllangs as $lang) {
    if ($lang === 'other') {
        continue; // Skip 'other' in the switcher.
    }
    $displaylang = ($lang === 'other') ? $sitelang : $lang;
    $url = new \moodle_url('/filter/autotranslate/edit.php', ['hash' => $hash, 'tlang' => $lang, 'contextid' => $contextid]);
    $class = ($lang === $queriedtlang) ? 'btn btn-primary' : 'btn btn-secondary';
    $langbuttons[] = \html_writer::link($url, strtoupper($displaylang), ['class' => "$class mr-1"]);
}
echo \html_writer::tag(
    'div',
    get_string('switchlanguage', 'filter_autotranslate') . ': ' . implode(' ', $langbuttons),
    ['class' => 'mb-3 col-md-6 text-end']
);
echo '</div>';

// Render the template.
$data = [
    'form' => $mform->render(),
    'sourcetext' => $sourcetext,
    'sourcetextlabel' => get_string('sourcetext', 'filter_autotranslate'),
];
echo $OUTPUT->render_from_template('filter_autotranslate/edit', $data);

echo $OUTPUT->footer();
