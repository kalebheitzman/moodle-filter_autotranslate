<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/filter/autotranslate/classes/translation_manager.php');
require_once($CFG->dirroot . '/filter/autotranslate/classes/translation_repository.php');

require_login();
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/filter/autotranslate/manage.php', [
    'courseid' => optional_param('courseid', 0, PARAM_INT),
    'filter_lang' => optional_param('filter_lang', '', PARAM_RAW),
    'filter_human' => optional_param('filter_human', '', PARAM_RAW),
    'perpage' => optional_param('perpage', 20, PARAM_INT),
    'page' => optional_param('page', 0, PARAM_INT),
    'sort' => optional_param('sort', 'hash', PARAM_ALPHA),
    'dir' => optional_param('dir', 'ASC', PARAM_ALPHA),
]);
$PAGE->set_title(get_string('managetranslations', 'filter_autotranslate'));
$PAGE->set_heading(get_string('managetranslations', 'filter_autotranslate'));

// Check capability
require_capability('filter/autotranslate:manage', $context);

// Get parameters
$courseid = optional_param('courseid', 0, PARAM_INT); // Optional courseid parameter
$filter_lang = optional_param('filter_lang', '', PARAM_RAW);
$filter_human = optional_param('filter_human', '', PARAM_RAW);
$page = optional_param('page', 0, PARAM_INT);
$perpage = optional_param('perpage', 20, PARAM_INT);
$sort = optional_param('sort', 'hash', PARAM_ALPHA);
$dir = optional_param('dir', 'ASC', PARAM_ALPHA);

global $DB, $OUTPUT;
$repository = new \filter_autotranslate\translation_repository($DB);
$manager = new \filter_autotranslate\translation_manager($repository);

// Handle form submission for human status updates
if ($data = data_submitted()) {
    require_sesskey();

    foreach ($data as $key => $value) {
        if (preg_match('/^human_(\d+)$/', $key, $matches)) {
            $translationid = $matches[1];
            $manager->update_human_status($translationid, $value ? 1 : 0);
        }
    }
    redirect(new moodle_url('/filter/autotranslate/manage.php', [
        'page' => $page,
        'perpage' => $perpage,
        'sort' => $sort,
        'dir' => $dir,
        'filter_lang' => $filter_lang,
        'filter_human' => $filter_human,
        'courseid' => $courseid,
    ]), get_string('translationsupdated', 'filter_autotranslate'));
}

echo $OUTPUT->header();

$sitelang = get_config('core', 'lang') ?: 'en';
$internal_filter_lang = ($filter_lang === $sitelang) ? 'other' : $filter_lang;

// Fetch translations with courseid filter
$result = $manager->get_paginated_translations($page, $perpage, $filter_lang, $filter_human, $sort, $dir, $courseid);
$translations = $result['translations'];
$total = $result['total'];

// Filter form
$mform = new \filter_autotranslate\form\manage_form(null, [
    'filter_lang' => $filter_lang,
    'filter_human' => $filter_human,
    'baseurl' => new \moodle_url('/filter/autotranslate/manage.php', [
        'perpage' => $perpage,
        'sort' => $sort,
        'dir' => $dir,
        'courseid' => $courseid,
    ]),
]);
$filter_form_html = $mform->render();

// Prepare table data for Mustache
$table_rows = [];
foreach ($translations as $translation) {
    // Rewrite @@PLUGINFILE@@ placeholders in translated_text and source_text
    $translated_text = file_rewrite_pluginfile_urls(
        $translation->translated_text,
        'pluginfile.php',
        context_system::instance()->id,
        'filter_autotranslate',
        'translations',
        $translation->id
    );
    $source_text = $translation->source_text !== 'N/A' ? file_rewrite_pluginfile_urls(
        $translation->source_text,
        'pluginfile.php',
        context_system::instance()->id,
        'filter_autotranslate',
        'translations',
        $translation->id
    ) : 'N/A';

    $row = [
        'hash' => $translation->hash,
        'lang' => $translation->lang,
        'translated_text' => format_text($translated_text, FORMAT_HTML),
        'human' => html_writer::checkbox('human_' . $translation->id, 1, $translation->human, '', ['class' => 'human-checkbox']),
        'contextlevel' => $translation->contextlevel,
        'actions' => html_writer::link(
            new moodle_url('/filter/autotranslate/edit.php', ['hash' => $translation->hash, 'tlang' => $translation->lang]),
            get_string('edit')
        ),
    ];
    if (!empty($internal_filter_lang) && $internal_filter_lang !== 'all' && $internal_filter_lang !== 'other') {
        $row['source_text'] = format_text($source_text, FORMAT_HTML);
    }
    $table_rows[] = $row;
}

// Prepare table headers
$table_headers = [
    get_string('hash', 'filter_autotranslate'),
    get_string('language', 'filter_autotranslate')
];
if (!empty($internal_filter_lang) && $internal_filter_lang !== 'all' && $internal_filter_lang !== 'other') {
    $table_headers[] = 'Source Text';
    $table_headers[] = 'Translated Text';
} else {
    $table_headers[] = get_string('translatedtext', 'filter_autotranslate');
}
$table_headers[] = get_string('humanreviewed', 'filter_autotranslate');
$table_headers[] = get_string('contextlevel', 'filter_autotranslate');
$table_headers[] = get_string('actions', 'filter_autotranslate');

// Prepare pagination
$baseurl = new moodle_url($PAGE->url, [
    'perpage' => $perpage,
    'sort' => $sort,
    'dir' => $dir,
    'filter_lang' => $filter_lang,
    'filter_human' => $filter_human,
    'courseid' => $courseid,
]);
$pagination_html = $OUTPUT->paging_bar($total, $page, $perpage, $baseurl);

// Render the template
$data = [
    'filter_form' => $filter_form_html,
    'table_headers' => $table_headers,
    'table_rows' => $table_rows,
    'update_button' => html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('updatetranslations', 'filter_autotranslate'), 'class' => 'btn btn-primary']),
    'pagination' => $pagination_html,
    'form_action' => new \moodle_url('/filter/autotranslate/manage.php'),
    'hidden_params' => html_writer::input_hidden_params(new \moodle_url('/filter/autotranslate/manage.php')),
];
echo $OUTPUT->render_from_template('filter_autotranslate/manage', $data);

echo $OUTPUT->footer();