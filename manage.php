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
 * Purpose:
 * This script renders the manage page for the filter_autotranslate plugin, displaying a table of
 * translations stored in the autotranslate_translations table. It allows administrators to filter
 * translations by language, human status, review status, and records per page, with support for
 * pagination, sorting, and editing individual translations. The page also handles right-to-left (RTL)
 * languages for proper text alignment.
 *
 * Design Decisions:
 * - Uses Moodle's standard page setup (require_login, context_system, set_url) to ensure proper
 *   integration with Moodle's framework.
 * - Leverages translation_manager and translation_repository classes for database operations,
 *   maintaining separation of concerns.
 * - Implements filtering via manage_form.php, which renders a form with filter buttons for language,
 *   human status, review status, and records per page.
 * - Supports RTL languages by applying dir="rtl" and right-aligned text for translated text in the
 *   Mustache template, using helper::is_rtl_language().
 * - Uses Moodle's paging_bar for pagination, ensuring a consistent user experience.
 * - URLs are rewritten at storage time (in tagging_service.php and translation_service.php), so
 *   no additional URL rewriting is performed here; text is formatted using format_text().
 * - Error handling is implemented with a try-catch block to catch and log unexpected errors.
 *
 * Dependencies:
 * - translation_manager.php: For fetching paginated translations.
 * - translation_repository.php: For database operations related to translations.
 * - helper.php: For utility functions like map_language_to_other() and is_rtl_language().
 * - manage_form.php: For rendering the filter form.
 * - manage.mustache: For rendering the table of translations.
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_autotranslate;

require_once(__DIR__ . '/../../config.php');
use filter_autotranslate\translation_manager;
use filter_autotranslate\translation_repository;
use filter_autotranslate\helper;

try {
    require_login();

    $context = \context_system::instance();
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

    global $DB, $OUTPUT;
    $repository = new translation_repository($DB);
    $manager = new translation_manager($repository);

    echo $OUTPUT->header();

    // Map the filter language to 'other' if it matches the site language
    $internal_filter_lang = helper::map_language_to_other($filter_lang);

    // Fetch translations with courseid filter
    $result = $manager->get_paginated_translations($page, $perpage, $internal_filter_lang, $filter_human, $sort, $dir, $courseid, $filter_needsreview);
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
        // URLs are already rewritten when stored, so just format the text for display
        $translated_text = format_text($translation->translated_text, FORMAT_HTML);
        $source_text = $translation->source_text !== 'N/A' ? format_text($translation->source_text, FORMAT_HTML) : 'N/A';

        // Determine if the translation needs review based on the target language record
        $needs_review = $translation->timereviewed < $translation->timemodified;
        $review_status = $needs_review ? $OUTPUT->pix_icon('i/warning', get_string('needsreview', 'filter_autotranslate'), 'moodle', ['class' => 'text-danger align-middle']) : '';

        // Format the dates as small text to append below the translated text
        $dates_html = '<small class="text-muted" dir="ltr">' .
                      get_string('last_modified', 'filter_autotranslate') . ': ' . userdate($translation->timemodified) . '<br>' .
                      get_string('last_reviewed', 'filter_autotranslate') . ': ' . userdate($translation->timereviewed) .
                      '</small>';

        // Append the dates below the translated text
        $translated_text_with_dates = $translated_text . '<br>' . $dates_html;

        // Determine if the "Edit" link should be shown
        $show_edit_link = ($translation->lang !== 'other' && $internal_filter_lang !== 'other');

        // Determine if the language is RTL
        $is_rtl = helper::is_rtl_language($translation->lang);

        $row = [
            'hash' => $translation->hash,
            'lang' => $translation->lang,
            'translated_text' => $translated_text_with_dates,
            'human' => $translation->human ? get_string('yes') : get_string('no'),
            'contextlevel' => $translation->contextlevel,
            'review_status' => $review_status,
            'show_edit_link' => $show_edit_link,
            'edit_url' => (new \moodle_url('/filter/autotranslate/edit.php', ['hash' => $translation->hash, 'tlang' => $translation->lang]))->out(false),
            'edit_label' => get_string('edit'),
            'is_rtl' => $is_rtl,
        ];
        if (!empty($internal_filter_lang) && $internal_filter_lang !== 'all' && $internal_filter_lang !== 'other') {
            $row['source_text'] = $source_text;
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
    $baseurl = new \moodle_url($PAGE->url, [
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
} catch (\Exception $e) {
    error_log("Fatal error in manage.php: " . $e->getMessage());
    echo "An error occurred: " . $e->getMessage();
}