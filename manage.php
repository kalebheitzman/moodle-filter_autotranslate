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
 * Autotranslate Manage Page
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

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
    'filter_needsreview' => optional_param('filter_needsreview', '', PARAM_RAW),
    'perpage' => optional_param('perpage', 20, PARAM_INT),
    'page' => optional_param('page', 0, PARAM_INT),
    'sort' => optional_param('sort', 'hash', PARAM_ALPHA),
    'dir' => optional_param('dir', 'ASC', PARAM_ALPHA),
]);
$PAGE->set_title(get_string('managetranslations', 'filter_autotranslate'));
$PAGE->set_heading(get_string('managetranslations', 'filter_autotranslate'));

// Include page-specific CSS
$PAGE->requires->css('/filter/autotranslate/css/manage.css');

// Check capability
require_capability('filter/autotranslate:manage', $context);

// Get parameters
$courseid = optional_param('courseid', 0, PARAM_INT);
$filter_lang = optional_param('filter_lang', '', PARAM_RAW);
$filter_human = optional_param('filter_human', '', PARAM_RAW);
$filter_needsreview = optional_param('filter_needsreview', '', PARAM_RAW);
$page = optional_param('page', 0, PARAM_INT);
$perpage = optional_param('perpage', 20, PARAM_INT);
$sort = optional_param('sort', 'hash', PARAM_ALPHA);
$dir = optional_param('dir', 'ASC', PARAM_ALPHA);

/**
 * Check if a language is right-to-left (RTL).
 *
 * @param string $lang The language code (e.g., 'en', 'ar', 'he').
 * @return bool True if the language is RTL, false otherwise.
 */
function is_rtl_language($lang) {
    // List of known RTL languages in Moodle
    $rtl_languages = ['ar', 'he', 'fa', 'ur']; // Arabic, Hebrew, Persian, Urdu

    // Check if the language is in the RTL list
    if (in_array($lang, $rtl_languages)) {
        return true;
    }

    // Optionally, check the language pack's configuration (if available)
    // This requires access to the language pack's langconfig.php, which is not directly accessible
    // For now, rely on the predefined list above
    return false;
}

global $DB, $OUTPUT;
$repository = new \filter_autotranslate\translation_repository($DB);
$manager = new \filter_autotranslate\translation_manager($repository);

echo $OUTPUT->header();

$sitelang = get_config('core', 'lang') ?: 'en';
$internal_filter_lang = ($filter_lang === $sitelang) ? 'other' : $filter_lang;

// Fetch translations with courseid filter
$result = $manager->get_paginated_translations($page, $perpage, $filter_lang, $filter_human, $sort, $dir, $courseid, $filter_needsreview);
$translations = $result['translations'];
$total = $result['total'];

// Filter form
$mform = new \filter_autotranslate\form\manage_form(null, [
    'filter_lang' => $filter_lang,
    'filter_human' => $filter_human,
    'filter_needsreview' => $filter_needsreview,
    'perpage' => $perpage,
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

    // Determine if the translation needs review based on the target language record
    $needs_review = $translation->timereviewed < $translation->timemodified;
    $review_status = $needs_review ? $OUTPUT->pix_icon('i/warning', get_string('needsreview', 'filter_autotranslate'), 'moodle', ['class' => 'text-danger align-middle']) : '';

    // Format the dates as small text to append below the translated text
    $dates_html = '<small class="text-muted" dir="ltr">' .
                  get_string('last_modified', 'filter_autotranslate') . ': ' . userdate($translation->timemodified) . '<br>' .
                  get_string('last_reviewed', 'filter_autotranslate') . ': ' . userdate($translation->timereviewed) .
                  '</small>';

    // Append the dates below the translated text
    $translated_text_with_dates = format_text($translated_text, FORMAT_HTML) . '<br>' . $dates_html;

    // Determine if the "Edit" link should be shown
    $show_edit_link = ($translation->lang !== 'other' && $internal_filter_lang !== 'other');

    // Determine if the language is RTL
    $is_rtl = is_rtl_language($translation->lang);

    $row = [
        'hash' => $translation->hash,
        'lang' => $translation->lang,
        'translated_text' => $translated_text_with_dates,
        'human' => $translation->human ? get_string('yes') : get_string('no'),
        'contextlevel' => $translation->contextlevel,
        'review_status' => $review_status,
        'show_edit_link' => $show_edit_link,
        'edit_url' => (new moodle_url('/filter/autotranslate/edit.php', ['hash' => $translation->hash, 'tlang' => $translation->lang]))->out(false),
        'edit_label' => get_string('edit'),
        'is_rtl' => $is_rtl,
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
$table_headers[] = get_string('reviewstatus', 'filter_autotranslate');
$table_headers[] = get_string('actions', 'filter_autotranslate');

// Prepare pagination
$baseurl = new moodle_url($PAGE->url, [
    'perpage' => $perpage,
    'sort' => $sort,
    'dir' => $dir,
    'filter_lang' => $filter_lang,
    'filter_human' => $filter_human,
    'filter_needsreview' => $filter_needsreview,
    'courseid' => $courseid,
]);
$pagination_html = $OUTPUT->paging_bar($total, $page, $perpage, $baseurl);

// Render the template
$data = [
    'filter_form' => $filter_form_html,
    'table_headers' => $table_headers,
    'table_rows' => $table_rows,
    'pagination' => $pagination_html,
];
echo $OUTPUT->render_from_template('filter_autotranslate/manage', $data);

echo $OUTPUT->footer();