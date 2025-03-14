<?php
require_once(__DIR__ . '/../../config.php');
// require_once($CFG->dirroot . '/filter/autotranslate/lib.php');

require_login();

$PAGE->set_url('/filter/autotranslate/manage.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('managetranslations', 'filter_autotranslate'));
$PAGE->set_heading(get_string('managetranslations', 'filter_autotranslate'));

// Fetch all translations
global $DB;
$translations = $DB->get_records('autotranslate_translations', [], 'hash, lang');

if ($data = data_submitted()) {
    require_sesskey();

    foreach ($data as $key => $value) {
        if (preg_match('/^human_(\d+)$/', $key, $matches)) {
            $translationid = $matches[1];
            $translation = $DB->get_record('autotranslate_translations', ['id' => $translationid], '*', MUST_EXIST);
            $translation->human = $value ? 1 : 0; // Update based on checkbox state
            $translation->timemodified = time();
            $DB->update_record('autotranslate_translations', $translation);
        }
    }
    redirect(new moodle_url('/filter/autotranslate/manage.php'), get_string('translationsupdated', 'filter_autotranslate'));
}

echo $OUTPUT->header();

echo html_writer::start_tag('form', ['method' => 'post', 'action' => new moodle_url('/filter/autotranslate/manage.php')]);
echo html_writer::input_hidden_params(new moodle_url('/filter/autotranslate/manage.php'));

$table = new html_table();
$table->head = [
    get_string('hash', 'filter_autotranslate'),
    get_string('language', 'filter_autotranslate'),
    get_string('translatedtext', 'filter_autotranslate'),
    get_string('humanreviewed', 'filter_autotranslate'),
    get_string('actions', 'filter_autotranslate')
];
$table->data = [];

foreach ($translations as $translation) {
    $row = new html_table_row();
    $row->cells = [
        $translation->hash,
        $translation->lang,
        format_text($translation->translated_text, FORMAT_PLAIN),
        html_writer::checkbox('human_' . $translation->id, 1, $translation->human, '', ['class' => 'human-checkbox']),
        html_writer::link(new moodle_url('/filter/autotranslate/edit.php', ['hash' => $translation->hash, 'lang' => $translation->lang]), get_string('edit'))
    ];
    $table->data[] = $row;
}

echo html_writer::table($table);
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('updatetranslations', 'filter_autotranslate')]);
echo html_writer::end_tag('form');

echo $OUTPUT->footer();