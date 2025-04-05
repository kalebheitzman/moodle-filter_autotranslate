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
 * Management page for the Autotranslate plugin.
 *
 * Displays a filterable, paginated table of translations from `filter_autotranslate_translations`,
 * with edit/add actions and an "Autotranslate" button. Supports RTL languages and target language views.
 *
 * Features:
 * - Filters by language, human status, course, and review needs.
 * - Paginates translations with sorting options.
 * - Links to `edit.php`/`create.php` for translation management.
 * - Triggers `autotranslate_adhoc_task` via `external.php`.
 *
 * Usage:
 * - Accessed at '/filter/autotranslate/manage.php' by admins with manage capability.
 * - Renders translation data from `ui_manager` and `translation_source`.
 *
 * Design:
 * - Uses `ui_manager` for data and Mustache for responsive UI.
 * - Supports target language view with source-target pairing.
 * - Integrates `autotranslate.js` for AJAX task queuing.
 *
 * Dependencies:
 * - `ui_manager.php`: Fetches paginated translation data.
 * - `translation_source.php`: Provides raw translation data.
 * - `text_utils.php`: Handles RTL and language mapping.
 * - `form/manage_form.php`: Renders filter form.
 * - `templates/manage.mustache`: UI template.
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_autotranslate;

require_once(__DIR__ . '/../../config.php');

use filter_autotranslate\ui_manager;
use filter_autotranslate\translation_source;
use filter_autotranslate\text_utils;

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

    // Map filter language to 'other' if it matches the site language.
    $internalfilterlang = text_utils::map_language_to_other($filterlang);

    // Determine if we're in a target language view.
    $istargetlang = !empty($internalfilterlang) && $internalfilterlang !== 'all' && $internalfilterlang !== 'other';

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

    // Initialize ui_manager.
    $translationsource = new translation_source($DB);
    $uimanager = new ui_manager($translationsource);

    // Fetch translations.
    if ($istargetlang) {
        $result = $uimanager->get_paginated_target_translations(
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
    } else {
        // Options: 'All' or 'other' view: Use ui_manager.
        $result = $uimanager->get_paginated_translations(
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
    }

    // Prepare table data for Mustache.
    $tablerows = [];
    foreach ($translations as $translation) {
        $row = new \stdClass();
        $row->hash = $translation->hash;

        if ($istargetlang) {
            $row->lang = $internalfilterlang;

            // Source text with timemodified.
            $sourcetext = format_text($translation->source_text, FORMAT_HTML);
            $sourcedateshtml = '<small class="text-muted" dir="ltr">' .
                            get_string('last_modified', 'filter_autotranslate') . ': ' .
                            userdate($translation->source_timemodified) .
                            '</small>';
            $row->source_text = $sourcetext . '<br>' . $sourcedateshtml;

            if ($translation->target_id) {
                $translatedtext = format_text($translation->target_text, FORMAT_HTML);
                $dateshtml = '<small class="text-muted" dir="ltr">' .
                            get_string('last_modified', 'filter_autotranslate') . ': ' .
                            userdate($translation->timemodified) .
                            '</small>';
                $row->translated_text = $translatedtext . '<br>' . $dateshtml;
                $row->human = $translation->human ? get_string('yes') : get_string('no');
                $row->contextlevel = $translation->target_contextlevel ?? $translation->source_contextlevel;
                $needsreview = $translation->source_timemodified > $translation->timemodified;
                $row->review_status = $needsreview ? $OUTPUT->pix_icon(
                    'i/warning',
                    get_string('needsreview', 'filter_autotranslate'),
                    'moodle',
                    ['class' => 'text-danger align-middle']
                ) : '';
                $row->showEditLink = true;
                $row->editUrl = (new \moodle_url('/filter/autotranslate/edit.php', [
                    'hash' => $translation->hash,
                    'tlang' => $internalfilterlang,
                    'courseid' => $courseid,
                    'filter_human' => $filterhuman,
                    'filter_needsreview' => $filterneedsreview,
                    'perpage' => $perpage,
                    'page' => $page,
                    'sort' => $sort,
                    'dir' => $dir,
                ]))->out(false);
            } else {
                $row->translated_text = '';
                $row->showAddLink = true;
                $row->addUrl = (new \moodle_url('/filter/autotranslate/create.php', [
                    'hash' => $translation->hash,
                    'tlang' => $internalfilterlang,
                    'courseid' => $courseid,
                    'filter_human' => $filterhuman,
                    'filter_needsreview' => $filterneedsreview,
                    'perpage' => $perpage,
                    'page' => $page,
                    'sort' => $sort,
                    'dir' => $dir,
                ]))->out(false);
                $row->human = '';
                $row->contextlevel = $translation->source_contextlevel;
                $row->review_status = '';
                $row->showEditLink = false;
            }
            $row->editLabel = get_string('edit');
            $row->addLabel = get_string('add', 'core');
            $row->isRtl = text_utils::is_rtl_language($internalfilterlang);
        } else {
            $translatedtext = format_text($translation->translated_text, FORMAT_HTML);
            $sourcetext = $translation->source_text !== 'N/A' ? format_text($translation->source_text, FORMAT_HTML) : 'N/A';
            $needsreview = $translation->source_timemodified > $translation->timemodified;
            $reviewstatus = $needsreview ? $OUTPUT->pix_icon(
                'i/warning',
                get_string('needsreview', 'filter_autotranslate'),
                'moodle',
                ['class' => 'text-danger align-middle']
            ) : '';
            $dateshtml = '<small class="text-muted" dir="ltr">' .
                        get_string('last_modified', 'filter_autotranslate') . ': ' . userdate($translation->timemodified) .
                        '</small>';
            $translatedtextwithdates = $translatedtext . '<br>' . $dateshtml;
            $showeditlink = ($translation->lang !== 'other' && $internalfilterlang !== 'other');
            $isrtl = text_utils::is_rtl_language($translation->lang);

            $row->lang = $translation->lang;
            $row->translated_text = $translatedtextwithdates;
            $row->human = $translation->human ? get_string('yes') : get_string('no');
            $row->contextlevel = $translation->contextlevel;
            $row->review_status = $reviewstatus;
            $row->showEditLink = $showeditlink;
            $row->editUrl = (new \moodle_url('/filter/autotranslate/edit.php', [
                'hash' => $translation->hash,
                'tlang' => $translation->lang,
                'courseid' => $courseid,
                'filter_human' => $filterhuman,
                'filter_needsreview' => $filterneedsreview,
                'perpage' => $perpage,
                'page' => $page,
                'sort' => $sort,
                'dir' => $dir,
            ]))->out(false);
            $row->editLabel = get_string('edit');
            $row->isRtl = $isrtl;
            if (!empty($internalfilterlang) && $internalfilterlang !== 'all' && $internalfilterlang !== 'other') {
                $sourcedateshtml = '<small class="text-muted" dir="ltr">' .
                                get_string('last_modified', 'filter_autotranslate') . ': ' .
                                userdate($translation->source_timemodified) .
                                '</small>';
                $row->source_text = $sourcetext . '<br>' . $sourcedateshtml;
            }
        }
        $tablerows[] = $row;
    }

    // Prepare table headers.
    $tableheaders = [
        get_string('hash', 'filter_autotranslate'),
        get_string('language', 'filter_autotranslate'),
    ];
    if ($istargetlang) {
        $tableheaders[] = get_string('sourcetext', 'filter_autotranslate');
        $tableheaders[] = get_string('translatedtext', 'filter_autotranslate');
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

    // Prepare filter parameters for JavaScript (no rebuild).
    $filterparams = [
        'targetlang' => $internalfilterlang,
        'courseid' => $courseid,
        'filter_human' => $filterhuman,
        'filter_needsreview' => $filterneedsreview,
        'perpage' => $perpage,
        'page' => $page,
        'sort' => $sort,
        'dir' => $dir,
    ];

    // Prepare data for the template.
    $data = [
        'filter_form' => $filterformhtml,
        'table_headers' => $tableheaders,
        'table_rows' => $tablerows,
        'pagination' => $paginationhtml,
        'has_courseid' => $courseid > 0,
        'istargetlang' => $istargetlang,
        'autotranslate_label' => get_string('autotranslate', 'filter_autotranslate'),
        'filter_params' => json_encode($filterparams),
    ];

    // Load JavaScript for asynchronous actions (e.g., autotranslate).
    $PAGE->requires->js_call_amd('filter_autotranslate/autotranslate', 'init');

    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('filter_autotranslate/manage', $data);
    echo $OUTPUT->footer();
} catch (\Exception $e) {
    debugging("Fatal error in manage.php: " . $e->getMessage(), DEBUG_DEVELOPER);
    echo "An error occurred: " . $e->getMessage();
}
