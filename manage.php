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

    // Include page-specific CSS.
    $PAGE->requires->css('/filter/autotranslate/css/manage.css');

    // Check capability.
    require_capability('filter/autotranslate:manage', $context);

    // Get parameters.
    $courseid = optional_param('courseid', 0, PARAM_INT);
    $action = optional_param('action', '', PARAM_ALPHA);
    $filterlang = optional_param('filter_lang', '', PARAM_RAW);
    $filterhuman = optional_param('filter_human', '', PARAM_RAW);
    $filterneedsreview = optional_param('filter_needsreview', '', PARAM_RAW);
    $page = optional_param('page', 0, PARAM_INT);
    $perpage = optional_param('perpage', 20, PARAM_INT);
    $sort = optional_param('sort', 'hash', PARAM_ALPHA);
    $dir = optional_param('dir', 'ASC', PARAM_ALPHA);

    global $DB, $OUTPUT;

    // Handle rebuild translations action.
    if ($action === 'rebuild' && $courseid > 0) {
        require_once($CFG->dirroot . '/filter/autotranslate/classes/rebuild_course_translations.php'); // Include the new class.
        $rebuilder = new \filter_autotranslate\rebuild_course_translations();
        // Clear existing mappings for this course to ensure a fresh rebuild.
        $DB->delete_records('autotranslate_hid_cids', ['courseid' => $courseid]);
        // Execute the rebuild process for the specified course.
        $rebuilder->execute($courseid);
        // Redirect back to the manage page with a success notification.
        redirect(new \moodle_url('/filter/autotranslate/manage.php', ['courseid' => $courseid]),
            get_string('translationsrebuilt', 'filter_autotranslate'));
    }

    $repository = new translation_repository($DB);
    $manager = new translation_manager($repository);

    echo $OUTPUT->header();

    // Filter form.
    $mform = new \filter_autotranslate\form\manage_form(null, [
        'filter_lang' => $filterlang,
        'filter_human' => $filterhuman,
        'filter_needsreview' => $filterneedsreview,
        'perpage' => $perpage,
        'baseurl' => new \moodle_url('/filter/autotranslate/manage.php', [
            'perpage' => $perpage,
            'sort' => $sort,
            'dir' => $dir,
            'courseid' => $courseid,
        ]),
    ]);
    $filterformhtml = $mform->render();

    // Map the filter language to 'other' if it matches the site language.
    $internalfilterlang = helper::map_language_to_other($filterlang);

    // Fetch translations with courseid filter.
    $result = $manager->get_paginated_translations(
        $page,
        $perpage,
        $internalfilterlang,
        $filterhuman,
        $sort,
        $dir,
        $courseid,
        $filterneedsreview
    );
    $translations = $result['translations'];
    $total = $result['total'];

    // Prepare table data for Mustache.
    $tablerows = [];
    foreach ($translations as $translation) {
        // URLs are already rewritten when stored, so just format the text for display.
        $translatedtext = format_text($translation->translated_text, FORMAT_HTML);
        $sourcetext = $translation->source_text !== 'N/A' ? format_text($translation->source_text, FORMAT_HTML) : 'N/A';

        // Determine if the translation needs review based on the target language record.
        $needsreview = $translation->timereviewed < $translation->timemodified;
        $reviewstatus = $needsreview ? $OUTPUT->pix_icon(
            'i/warning',
            get_string('needsreview', 'filter_autotranslate'),
            'moodle',
            ['class' => 'text-danger align-middle']
        ) : '';

        // Format the dates as small text to append below the translated text.
        $dateshtml = '<small class="text-muted" dir="ltr">' .
                      get_string('last_modified', 'filter_autotranslate') . ': ' . userdate($translation->timemodified) . '<br>' .
                      get_string('last_reviewed', 'filter_autotranslate') . ': ' . userdate($translation->timereviewed) .
                      '</small>';

        // Append the dates below the translated text.
        $translatedtextwithdates = $translatedtext . '<br>' . $dateshtml;

        // Determine if the "Edit" link should be shown.
        $showeditlink = ($translation->lang !== 'other' && $internalfilterlang !== 'other');

        // Determine if the language is RTL.
        $isrtl = helper::is_rtl_language($translation->lang);

        $row = [
            'hash' => $translation->hash,
            'lang' => $translation->lang,
            'translated_text' => $translatedtextwithdates,
            'human' => $translation->human ? get_string('yes') : get_string('no'),
            'contextlevel' => $translation->contextlevel,
            'review_status' => $reviewstatus,
            'show_edit_link' => $showeditlink,
            'edit_url' => (new \moodle_url(
                '/filter/autotranslate/edit.php',
                ['hash' => $translation->hash,
                'tlang' => $translation->lang]
            ))->out(false),
            'edit_label' => get_string('edit'),
            'is_rtl' => $isrtl,
        ];
        if (!empty($internalfilterlang) && $internalfilterlang !== 'all' && $internalfilterlang !== 'other') {
            $row['source_text'] = $sourcetext;
        }
        $tablerows[] = $row;
    }

    // Prepare table headers.
    $tableheaders = [
        get_string('hash', 'filter_autotranslate'),
        get_string('language', 'filter_autotranslate'),
    ];
    if (!empty($internalfilterlang) && $internalfilterlang !== 'all' && $internalfilterlang !== 'other') {
        $tableheaders[] = 'Source Text';
        $tableheaders[] = 'Translated Text';
    } else {
        $tableheaders[] = get_string('translatedtext', 'filter_autotranslate');
    }
    $tableheaders[] = get_string('humanreviewed', 'filter_autotranslate');
    $tableheaders[] = get_string('contextlevel', 'filter_autotranslate');
    $tableheaders[] = get_string('reviewstatus', 'filter_autotranslate');
    $tableheaders[] = get_string('actions', 'filter_autotranslate');

    // Prepare pagination.
    $baseurl = new \moodle_url($PAGE->url, [
        'perpage' => $perpage,
        'sort' => $sort,
        'dir' => $dir,
        'filter_lang' => $filterlang,
        'filter_human' => $filterhuman,
        'filter_needsreview' => $filterneedsreview,
        'courseid' => $courseid,
    ]);
    $paginationhtml = $OUTPUT->paging_bar($total, $page, $perpage, $baseurl);

    // Prepare data for the template, including the filter form and rebuild button.
    $data = [
        'filter_form' => $filterformhtml,
        'table_headers' => $tableheaders,
        'table_rows' => $tablerows,
        'pagination' => $paginationhtml,
        'has_courseid' => $courseid > 0, // Flag to show the rebuild button.
        'rebuild_url' => $courseid > 0 ? (new \moodle_url(
            '/filter/autotranslate/manage.php',
            ['courseid' => $courseid,
            'action' => 'rebuild']
        ))->out(false) : '',
        'rebuild_label' => get_string('rebuildtranslations', 'filter_autotranslate'),
    ];

    // Render the template.
    echo $OUTPUT->render_from_template('filter_autotranslate/manage', $data);

    echo $OUTPUT->footer();
} catch (\Exception $e) {
    debugging("Fatal error in manage.php: " . $e->getMessage());
    echo "An error occurred: " . $e->getMessage();
}
