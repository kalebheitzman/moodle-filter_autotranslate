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
 * translations stored in the filter_autotranslate_translations table. It allows administrators to filter
 * translations by language, human status, review status, and records per page, with support for
 * pagination, sorting, and editing individual translations. The page also handles right-to-left (RTL)
 * languages for proper text alignment. When a target language is selected, it shows all 'other' translations
 * with an option to add missing translations.
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
        require_once($CFG->dirroot . '/filter/autotranslate/classes/rebuild_course_translations.php');
        $rebuilder = new \filter_autotranslate\rebuild_course_translations();
        $DB->delete_records('filter_autotranslate_hid_cids', ['courseid' => $courseid]);
        $rebuilder->execute($courseid);
        redirect(
            new \moodle_url('/filter/autotranslate/manage.php', ['courseid' => $courseid]),
            get_string('translationsrebuilt', 'filter_autotranslate')
        );
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

    // Fetch translations differently based on filterlang.
    if (!empty($internalfilterlang) && $internalfilterlang !== 'all' && $internalfilterlang !== 'other') {
        // For target languages, fetch all 'other' translations and join with target language if exists.
        $sql = "SELECT t_other.hash, t_other.translated_text AS source_text,
                       t_target.id AS target_id, t_target.lang AS target_lang,
                       t_target.translated_text AS target_text, t_target.human,
                       t_other.contextlevel AS source_contextlevel, t_target.contextlevel AS target_contextlevel,
                       t_target.timecreated, t_target.timemodified, t_target.timereviewed
                FROM {filter_autotranslate_translations} t_other
                LEFT JOIN {filter_autotranslate_translations} t_target
                    ON t_other.hash = t_target.hash AND t_target.lang = :targetlang
                WHERE t_other.lang = 'other'";
        $params = ['targetlang' => $internalfilterlang];

        if ($courseid > 0) {
            $sql .= " AND t_other.hash IN (
                        SELECT hash FROM {filter_autotranslate_hid_cids} WHERE courseid = :courseid
                      )";
            $params['courseid'] = $courseid;
        }

        $sql .= " ORDER BY t_other.$sort $dir";
        $total = $DB->count_records_sql(
            "SELECT COUNT(*) FROM {filter_autotranslate_translations} t_other
             WHERE t_other.lang = 'other'" . ($courseid > 0 ? " AND t_other.hash IN (
                SELECT hash FROM {filter_autotranslate_hid_cids} WHERE courseid = :countcourseid
             )" : ""),
            $courseid > 0 ? ['countcourseid' => $courseid] : []
        );
        $translations = $DB->get_records_sql($sql, $params, $page * $perpage, $perpage);
    } else {
        // For 'all' or 'other', use the existing manager method.
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
    }

    // Prepare table data for Mustache.
    $tablerows = [];
    foreach ($translations as $translation) {
        $row = new \stdClass();
        $row->hash = $translation->hash;

        if (!empty($internalfilterlang) && $internalfilterlang !== 'all' && $internalfilterlang !== 'other') {
            // Target language view.
            $row->lang = $internalfilterlang;
            $row->source_text = format_text($translation->source_text, FORMAT_HTML);

            if ($translation->target_id) {
                // Existing target translation.
                $translatedtext = format_text($translation->target_text, FORMAT_HTML);
                $dateshtml = '<small class="text-muted" dir="ltr">' .
                            get_string('last_modified', 'filter_autotranslate') . ': ' .
                            userdate($translation->timemodified) . '<br>' .
                            get_string('last_reviewed', 'filter_autotranslate') . ': ' .
                            userdate($translation->timereviewed) .
                            '</small>';
                $row->translated_text = $translatedtext . '<br>' . $dateshtml;
                $row->human = $translation->human ? get_string('yes') : get_string('no');
                $row->contextlevel = $translation->target_contextlevel; // Use target contextlevel if available.
                $needsreview = $translation->timereviewed < $translation->timemodified;
                $row->review_status = $needsreview ? $OUTPUT->pix_icon(
                    'i/warning',
                    get_string('needsreview', 'filter_autotranslate'),
                    'moodle',
                    ['class' => 'text-danger align-middle']
                ) : '';
                $row->show_edit_link = true;
                $row->edit_url = (new \moodle_url('/filter/autotranslate/edit.php', [
                    'hash' => $translation->hash,
                    'tlang' => $internalfilterlang,
                ]))->out(false);
            } else {
                // No target translation, show Add button.
                $row->translated_text = ''; // Will be replaced by Add button in template.
                $row->show_add_link = true;
                $row->add_url = (new \moodle_url('/filter/autotranslate/create.php', [
                    'hash' => $translation->hash,
                    'tlang' => $internalfilterlang,
                    'courseid' => $courseid,
                ]))->out(false);
                $row->human = '';
                $row->contextlevel = $translation->source_contextlevel; // Use source contextlevel for untranslated entries.
                $row->review_status = '';
                $row->show_edit_link = false;
            }
            $row->edit_label = get_string('edit');
            $row->add_label = get_string('add', 'core');
            $row->is_rtl = helper::is_rtl_language($internalfilterlang);
        } else {
            // Filter 'all' or 'other' view (unchanged).
            $translatedtext = format_text($translation->translated_text, FORMAT_HTML);
            $sourcetext = $translation->source_text !== 'N/A' ? format_text($translation->source_text, FORMAT_HTML) : 'N/A';
            $needsreview = $translation->timereviewed < $translation->timemodified;
            $reviewstatus = $needsreview ? $OUTPUT->pix_icon(
                'i/warning',
                get_string('needsreview', 'filter_autotranslate'),
                'moodle',
                ['class' => 'text-danger align-middle']
            ) : '';
            $dateshtml = '<small class="text-muted" dir="ltr">' .
                        get_string('last_modified', 'filter_autotranslate') . ': ' . userdate($translation->timemodified) . '<br>' .
                        get_string('last_reviewed', 'filter_autotranslate') . ': ' . userdate($translation->timereviewed) .
                        '</small>';
            $translatedtextwithdates = $translatedtext . '<br>' . $dateshtml;
            $showeditlink = ($translation->lang !== 'other' && $internalfilterlang !== 'other');
            $isrtl = helper::is_rtl_language($translation->lang);

            $row->lang = $translation->lang;
            $row->translated_text = $translatedtextwithdates;
            $row->human = $translation->human ? get_string('yes') : get_string('no');
            $row->contextlevel = $translation->contextlevel;
            $row->review_status = $reviewstatus;
            $row->show_edit_link = $showeditlink;
            $row->edit_url = (new \moodle_url('/filter/autotranslate/edit.php', [
                'hash' => $translation->hash,
                'tlang' => $translation->lang,
            ]))->out(false);
            $row->edit_label = get_string('edit');
            $row->is_rtl = $isrtl;
            if (!empty($internalfilterlang) && $internalfilterlang !== 'all' && $internalfilterlang !== 'other') {
                $row->source_text = $sourcetext;
            }
        }
        $tablerows[] = $row;
    }

    // Prepare table headers.
    $tableheaders = [
        get_string('hash', 'filter_autotranslate'),
        get_string('language', 'filter_autotranslate'),
    ];
    if (!empty($internalfilterlang) && $internalfilterlang !== 'all' && $internalfilterlang !== 'other') {
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

    // Prepare data for the template.
    $data = [
        'filter_form' => $filterformhtml,
        'table_headers' => $tableheaders,
        'table_rows' => $tablerows,
        'pagination' => $paginationhtml,
        'has_courseid' => $courseid > 0,
        'rebuild_url' => $courseid > 0 ? (new \moodle_url('/filter/autotranslate/manage.php', [
            'courseid' => $courseid,
            'action' => 'rebuild',
        ]))->out(false) : '',
        'rebuild_label' => get_string('rebuildtranslations', 'filter_autotranslate'),
    ];

    // Render the template.
    echo $OUTPUT->render_from_template('filter_autotranslate/manage', $data);

    echo $OUTPUT->footer();
} catch (\Exception $e) {
    debugging("Fatal error in manage.php: " . $e->getMessage());
    echo "An error occurred: " . $e->getMessage();
}
