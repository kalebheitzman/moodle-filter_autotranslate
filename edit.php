<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$hash = required_param('hash', PARAM_ALPHANUMEXT);
$tlang = optional_param('tlang', '', PARAM_LANG); // Translation language parameter
$contextid = optional_param('contextid', SYSCONTEXTID, PARAM_INT);

// Get the site language
$sitelang = get_config('core', 'lang') ?: 'en'; // Default to 'en' if not set

// Map site language to 'other' if they match
$queried_tlang = ($tlang === $sitelang) ? 'other' : $tlang;
debugging("Mapping tlang - Provided: $tlang, Site Language: $sitelang, Queried tLang: $queried_tlang", DEBUG_DEVELOPER);

$PAGE->set_url('/filter/autotranslate/edit.php', ['hash' => $hash, 'tlang' => $tlang, 'contextid' => $contextid]);
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('edittranslation', 'filter_autotranslate'));
$PAGE->set_heading(get_string('edittranslation', 'filter_autotranslate'));

echo $OUTPUT->header();

// Add a "Manage Translations" link below the heading
$manageurl = new \moodle_url('/filter/autotranslate/manage.php');
echo \html_writer::link($manageurl, get_string('managetranslations', 'filter_autotranslate'), ['class' => 'btn btn-secondary mb-3']);

global $DB;
debugging("Attempting to fetch translation - Hash: $hash, Queried tLang: $queried_tlang, ContextID: $contextid", DEBUG_DEVELOPER);
$translation = $DB->get_record('autotranslate_translations', ['hash' => $hash, 'lang' => $queried_tlang]);
if (!$translation) {
    // Fallback to source text (lang = 'other') if no translation exists
    $translation = $DB->get_record('autotranslate_translations', ['hash' => $hash, 'lang' => 'other']);
    if (!$translation) {
        debugging("No record found for hash $hash in any language, redirecting to manage.php", DEBUG_DEVELOPER);
        redirect(new moodle_url('/filter/autotranslate/manage.php'), 'No translation found for the specified hash.', null, \core\output\notification::NOTIFY_ERROR);
    }
    $queried_tlang = 'other'; // Update to reflect the fallback
    debugging("Falling back to source text - Hash: $hash, Queried tLang: $queried_tlang", DEBUG_DEVELOPER);
}

if ($data = data_submitted()) {
    require_sesskey();

    $translation->translated_text = $data->translated_text;
    $translation->human = 1; // Mark as human-reviewed
    $translation->timemodified = time();

    $DB->update_record('autotranslate_translations', $translation);
    redirect(new moodle_url('/filter/autotranslate/manage.php'), get_string('translationsaved', 'filter_autotranslate'));
}

// Get all languages for this hash to populate the language switcher
$all_langs = $DB->get_fieldset_select('autotranslate_translations', 'DISTINCT lang', 'hash = ?', [$hash]);

// Add language switcher as buttons, mapping 'other' to site language for display
$lang_buttons = [];
foreach ($all_langs as $lang) {
    $display_lang = ($lang === 'other') ? $sitelang : $lang; // Display site language for 'other'
    $url = new moodle_url('/filter/autotranslate/edit.php', ['hash' => $hash, 'tlang' => $lang, 'contextid' => $contextid]);
    $class = ($lang === $queried_tlang) ? 'btn btn-primary' : 'btn btn-secondary';
    $lang_buttons[] = \html_writer::link($url, $display_lang, ['class' => $class . ' mr-1']);
}
echo \html_writer::tag('div', 'Switch Language: ' . implode(' ', $lang_buttons), ['class' => 'mb-3']);

// Pass the queried language to the form for display
$mform = new \filter_autotranslate\form\edit_form(null, ['translation' => $translation, 'tlang' => $queried_tlang]);
$mform->display();

echo $OUTPUT->footer();