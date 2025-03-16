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

global $DB, $OUTPUT;
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

// Parse source text to determine if it contains HTML
$use_wysiwyg = false;
if ($source_text && preg_match('/<[^>]+>/', $source_text)) {
    $use_wysiwyg = true;
    debugging("Source text contains HTML, enabling WYSIWYG editor", DEBUG_DEVELOPER);
} else {
    debugging("Source text is plain text, using regular textarea", DEBUG_DEVELOPER);
}

// Initialize the form
$mform = new \filter_autotranslate\form\edit_form(null, ['translation' => $translation, 'tlang' => $queried_tlang, 'use_wysiwyg' => $use_wysiwyg]);

if ($data = $mform->get_data()) {
    require_sesskey();

    $translation->translated_text = is_array($data->translated_text) ? $data->translated_text['text'] : $data->translated_text;
    $translation->human = !empty($data->human) ? 1 : 0; // Handle checkbox submission
    $translation->timemodified = time();

    $DB->update_record('autotranslate_translations', $translation);
    redirect(new moodle_url('/filter/autotranslate/manage.php'), get_string('translationsaved', 'filter_autotranslate'));
}

// Start a Bootstrap row for the layout
echo '<div class="row mb-3">';

echo '<div class="col-md-6">';
// Add a "Manage Translations" link below the heading
$manageurl = new \moodle_url('/filter/autotranslate/manage.php');
echo \html_writer::link($manageurl, get_string('managetranslations', 'filter_autotranslate'), ['class' => 'btn btn-secondary mb-3']);
echo '</div>';

// Get all languages for this hash to populate the language switcher
$all_langs = $DB->get_fieldset_select('autotranslate_translations', 'DISTINCT lang', 'hash = ?', [$hash]);

// Add language switcher as buttons, mapping 'other' to site language for display
$lang_buttons = [];
foreach ($all_langs as $lang) {
    $display_lang = ($lang === 'other') ? $sitelang : $lang;
    $url = new moodle_url('/filter/autotranslate/edit.php', ['hash' => $hash, 'tlang' => $lang, 'contextid' => $contextid]);
    $class = ($lang === $queried_tlang) ? 'btn btn-primary' : 'btn btn-secondary';
    $lang_buttons[] = \html_writer::link($url, strtoupper($display_lang), ['class' => $class . ' mr-1']);
}
echo \html_writer::tag('div', 'Switch Language: ' . implode(' ', $lang_buttons), ['class' => 'mb-3 col-md-6 text-right']);

echo '</div>';

// Render the template
$data = [
    'form' => $mform->render(),
    'source_text' => $source_text,
];
echo $OUTPUT->render_from_template('filter_autotranslate/edit', $data);

echo $OUTPUT->footer();