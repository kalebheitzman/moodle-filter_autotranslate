<?php
require_once(__DIR__ . '/../../config.php');

require_login();

$hash = required_param('hash', PARAM_ALPHANUMEXT);
$lang = required_param('lang', PARAM_LANG);
$contextid = optional_param('contextid', SYSCONTEXTID, PARAM_INT);

// Get the site language
$sitelang = get_config('core', 'lang') ?: 'en'; // Default to 'en' if not set

// Map site language to 'other' if they match
$queried_lang = ($lang === $sitelang) ? 'other' : $lang;
debugging("Mapping lang - Provided: $lang, Site Language: $sitelang, Queried Lang: $queried_lang", DEBUG_DEVELOPER); // Log to debug system

$PAGE->set_url('/filter/autotranslate/edit.php', ['hash' => $hash, 'lang' => $lang, 'contextid' => $contextid]);
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('edittranslation', 'filter_autotranslate'));
$PAGE->set_heading(get_string('edittranslation', 'filter_autotranslate'));

// Load the existing translation or fallback to source text
global $DB;
debugging("Attempting to fetch translation - Hash: $hash, Queried Lang: $queried_lang, ContextID: $contextid", DEBUG_DEVELOPER); // Log to debug system
$translation = $DB->get_record('autotranslate_translations', ['hash' => $hash, 'lang' => $queried_lang]);
if (!$translation) {
    // Fallback to source text (lang = 'other') if no translation exists
    $translation = $DB->get_record('autotranslate_translations', ['hash' => $hash, 'lang' => 'other']);
    if (!$translation) {
        debugging("No record found for hash $hash in any language, redirecting to manage.php", DEBUG_DEVELOPER);
        redirect(new moodle_url('/filter/autotranslate/manage.php'), 'No translation found for the specified hash.', null, \core\output\notification::NOTIFY_ERROR);
    }
    $queried_lang = 'other'; // Update to reflect the fallback
    debugging("Falling back to source text - Hash: $hash, Queried Lang: $queried_lang", DEBUG_DEVELOPER);
}

if ($data = data_submitted()) {
    require_sesskey();

    $translation->translated_text = $data->translated_text;
    $translation->human = 1; // Mark as human-reviewed
    $translation->timemodified = time();

    $DB->update_record('autotranslate_translations', $translation);
    redirect(new moodle_url('/filter/autotranslate/manage.php'), get_string('translationsaved', 'filter_autotranslate'));
}

echo $OUTPUT->header();

// Add a "Manage Translations" link below the heading
$manageurl = new \moodle_url('/filter/autotranslate/manage.php');
echo \html_writer::link($manageurl, get_string('managetranslations', 'filter_autotranslate'), ['class' => 'btn btn-secondary mb-3']);

// Pass the queried language to the form for display
$mform = new \filter_autotranslate\form\edit_form(null, ['translation' => $translation, 'lang' => $queried_lang]);
$mform->display();

echo $OUTPUT->footer();