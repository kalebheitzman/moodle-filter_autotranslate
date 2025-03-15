<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/filter/autotranslate/classes/translation_manager.php');

require_login();
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/filter/autotranslate/manage.php');
$PAGE->set_title(get_string('managetranslations', 'filter_autotranslate'));
$PAGE->set_heading(get_string('managetranslations', 'filter_autotranslate'));

// Check capability
require_capability('filter/autotranslate:manage', $context);

// Get URL parameters
$perpage = optional_param('perpage', 20, PARAM_INT);
$page = optional_param('page', 0, PARAM_INT);
$filter_lang = optional_param('filter_lang', '', PARAM_RAW);
$filter_human = optional_param('filter_human', '', PARAM_RAW);
$sort = optional_param('sort', 'hash', PARAM_ALPHA);
$dir = optional_param('dir', 'ASC', PARAM_ALPHA);

$manager = new \filter_autotranslate\translation_manager();

// Handle form submission for human status updates
if ($data = data_submitted()) {
    require_sesskey();

    foreach ($data as $key => $value) {
        if (preg_match('/^human_(\d+)$/', $key, $matches)) {
            $translationid = $matches[1];
            $manager->update_human_status($translationid, $value ? 1 : 0);
        }
    }
    redirect(new moodle_url('/filter/autotranslate/manage.php', ['page' => $page, 'perpage' => $perpage, 'sort' => $sort, 'dir' => $dir, 'filter_lang' => $filter_lang, 'filter_human' => $filter_human]), get_string('translationsupdated', 'filter_autotranslate'));
}

echo $OUTPUT->header();

$sitelang = get_config('core', 'lang') ?: 'en';
$internal_filter_lang = ($filter_lang === $sitelang) ? 'other' : $filter_lang;

$result = $manager->get_paginated_translations($page, $perpage, $filter_lang, $filter_human, $sort, $dir);
$translations = $result['translations'];
$total = $result['total'];

// Filter form
$mform = new \filter_autotranslate\form\manage_form(null, [
    'filter_lang' => $filter_lang,
    'filter_human' => $filter_human,
    'baseurl' => new \moodle_url('/filter/autotranslate/manage.php', ['perpage' => $perpage, 'sort' => $sort, 'dir' => $dir])
]);
$mform->display();

// Form for human status updates
echo html_writer::start_tag('form', ['method' => 'post', 'action' => new moodle_url('/filter/autotranslate/manage.php')]);
echo html_writer::input_hidden_params(new moodle_url('/filter/autotranslate/manage.php'));

// Display translations table
$table = new html_table();
$table->head = [
    get_string('hash', 'filter_autotranslate'),
    get_string('language', 'filter_autotranslate')
];

// Dynamically add Source Text and Translated Text columns if a specific language is filtered
if (!empty($internal_filter_lang) && $internal_filter_lang !== 'all' && $internal_filter_lang !== 'other') {
    $table->head[] = 'Source Text';
    $table->head[] = 'Translated Text';
} else {
    $table->head[] = get_string('translatedtext', 'filter_autotranslate');
}

$table->head = array_merge($table->head, [
    get_string('humanreviewed', 'filter_autotranslate'),
    get_string('contextlevel', 'filter_autotranslate'),
    get_string('actions', 'filter_autotranslate')
]);
$table->data = [];

foreach ($translations as $translation) {
    $row = new html_table_row();
    $cells = [
        $translation->hash,
        $translation->lang
    ];

    // Show Source Text and Translated Text if a specific language is filtered
    if (!empty($internal_filter_lang) && $internal_filter_lang !== 'all' && $internal_filter_lang !== 'other') {
        $source_text = file_rewrite_pluginfile_urls($translation->source_text, 'pluginfile.php', $context->id, 'filter_autotranslate', 'translations', $translation->id);
        $translated_text = file_rewrite_pluginfile_urls($translation->translated_text, 'pluginfile.php', $context->id, 'filter_autotranslate', 'translations', $translation->id);
        $cells[] = format_text($source_text, FORMAT_PLAIN);
        $cells[] = format_text($translated_text, FORMAT_PLAIN);
    } else {
        $translated_text = file_rewrite_pluginfile_urls($translation->translated_text, 'pluginfile.php', $context->id, 'filter_autotranslate', 'translations', $translation->id);
        $cells[] = format_text($translated_text, FORMAT_PLAIN);
    }

    $cells = array_merge($cells, [
        html_writer::checkbox('human_' . $translation->id, 1, $translation->human, '', ['class' => 'human-checkbox']),
        $translation->contextlevel,
        html_writer::link(new moodle_url('/filter/autotranslate/edit.php', ['hash' => $translation->hash, 'tlang' => $translation->lang]), get_string('edit'))
    ]);

    $row->cells = $cells;
    $table->data[] = $row;
}

echo html_writer::table($table);
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('updatetranslations', 'filter_autotranslate'), 'class' => 'btn btn-primary']);
echo html_writer::end_tag('form');

// Pagination controls
$baseurl = new moodle_url($PAGE->url, ['perpage' => $perpage, 'sort' => $sort, 'dir' => $dir, 'filter_lang' => $filter_lang, 'filter_human' => $filter_human]);
echo $OUTPUT->paging_bar($total, $page, $perpage, $baseurl);

echo $OUTPUT->footer();