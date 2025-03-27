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
 * Autotranslate Create Page
 *
 * Allows administrators to create a new translation for a specific hash and language in the
 * filter_autotranslate plugin. The page saves the translation to filter_autotranslate_translations
 * and redirects back to the manage page with preserved filters.
 *
 * Design Decisions:
 * - Uses translation_repository.php to fetch source text, ensuring read-only database access.
 * - Uses translation_service.php to insert new translations, centralizing database writes.
 * - Prevents creation of 'other' language records, as source text should be updated via Moodle workflows.
 * - Dynamically uses a WYSIWYG editor or textarea based on source text HTML presence.
 * - Preserves all manage.php filters (courseid, filter_lang, etc.) in the redirect URL.
 * - @@PLUGINFILE@@ URLs are rewritten when stored (in translation_service.php), so no rewriting is needed here.
 *
 * Dependencies:
 * - helper.php: For utility functions (e.g., map_language_to_other).
 * - translation_repository.php: For fetching source text.
 * - translation_service.php: For saving translations.
 * - create_form.php: For the creation form.
 * - create.mustache: For rendering the page.
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_autotranslate;

use filter_autotranslate\form\create_form;
use filter_autotranslate\helper;
use filter_autotranslate\translation_repository;
use filter_autotranslate\translation_service;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/weblib.php');

// Require login and check capability.
require_login();
require_capability('filter/autotranslate:manage', \context_system::instance());

// Get parameters.
$hash = required_param('hash', PARAM_ALPHANUMEXT);
$tlang = required_param('tlang', PARAM_RAW);
$courseid = optional_param('courseid', 0, PARAM_INT);
$filterhuman = optional_param('filter_human', '', PARAM_RAW);
$filterneedsreview = optional_param('filter_needsreview', '', PARAM_RAW);
$perpage = optional_param('perpage', 20, PARAM_INT);
$page = optional_param('page', 0, PARAM_INT);
$sort = optional_param('sort', 'hash', PARAM_ALPHA);
$dir = optional_param('dir', 'ASC', PARAM_ALPHA);

// Validate and normalize tlang.
if (empty($tlang)) {
    redirect(
        new \moodle_url('/filter/autotranslate/manage.php'),
        get_string('invalidtlang', 'filter_autotranslate'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

$tlang = clean_param($tlang, PARAM_TEXT);
$tlang = strtolower(trim($tlang));

if (empty($tlang)) {
    redirect(
        new \moodle_url('/filter/autotranslate/manage.php'),
        get_string('invalidtlang', 'filter_autotranslate'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

// Map the language to 'other' if it matches the site language.
$queriedtlang = helper::map_language_to_other($tlang);

// Prevent creating 'other' language records.
if ($tlang === 'other' || $queriedtlang === 'other') {
    redirect(
        new \moodle_url('/filter/autotranslate/manage.php'),
        get_string('cannoteditother', 'filter_autotranslate'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

// Check if a translation already exists.
global $DB;
$repository = new translation_repository($DB);
if ($repository->get_translation($hash, $queriedtlang)) {
    redirect(
        new \moodle_url('/filter/autotranslate/edit.php', ['hash' => $hash, 'tlang' => $tlang]),
        get_string('translationexists', 'filter_autotranslate'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

// Set up the page.
$PAGE->set_url('/filter/autotranslate/create.php', [
    'hash' => $hash,
    'tlang' => $tlang,
    'courseid' => $courseid,
    'filter_human' => $filterhuman,
    'filter_needsreview' => $filterneedsreview,
    'perpage' => $perpage,
    'page' => $page,
    'sort' => $sort,
    'dir' => $dir,
]);
$PAGE->set_context(\context_system::instance());
$PAGE->set_title(get_string('createtranslation', 'filter_autotranslate'));
$PAGE->set_heading(get_string('createtranslation', 'filter_autotranslate'));

global $OUTPUT;

// Initialize the translation service.
$service = new translation_service($DB);

// Fetch the source ("other") record to get its contextlevel.
$sourcerecord = $repository->get_translation($hash, 'other');
if (!$sourcerecord) {
    redirect(
        new \moodle_url('/filter/autotranslate/manage.php'),
        get_string('notranslationfound', 'filter_autotranslate'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

// Fetch source text.
$sourcetext = $repository->get_source_text($hash);
if (!$sourcetext || $sourcetext === 'N/A') {
    $sourcetext = 'N/A';
}
$sourcetext = format_text($sourcetext, FORMAT_HTML);

// Determine if source text contains HTML for editor type.
$usewysiwyg = $sourcetext !== 'N/A' && preg_match('/<[^>]+>/', $sourcetext);

// Initialize the form.
$mform = new create_form(
    null,
    [
        'hash' => $hash,
        'tlang' => $queriedtlang,
        'courseid' => $courseid,
        'filter_human' => $filterhuman,
        'filter_needsreview' => $filterneedsreview,
        'perpage' => $perpage,
        'page' => $page,
        'sort' => $sort,
        'dir' => $dir,
        'use_wysiwyg' => $usewysiwyg,
    ]
);

// Handle form submission.
if ($mform->is_cancelled()) {
    redirect(new \moodle_url('/filter/autotranslate/manage.php', [
        'courseid' => $courseid,
        'filter_lang' => $tlang,
        'filter_human' => $filterhuman,
        'filter_needsreview' => $filterneedsreview,
        'perpage' => $perpage,
        'page' => $page,
        'sort' => $sort,
        'dir' => $dir,
    ]));
} else if ($data = $mform->get_data()) {
    try {
        require_sesskey();
    } catch (\moodle_exception $e) {
        redirect(
            new \moodle_url('/filter/autotranslate/manage.php'),
            get_string('invalidsesskey', 'error'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    // Create the new translation record.
    $translation = new \stdClass();
    $translation->hash = $data->hash;
    $translation->lang = $data->tlang;
    $translation->translated_text = is_array($data->translated_text) ? $data->translated_text['text'] : $data->translated_text;
    $translation->human = !empty($data->human) ? 1 : 0;
    // Use the contextlevel from the source ("other") record.
    $translation->contextlevel = $sourcerecord->contextlevel ?? CONTEXT_COURSE; // Fallback to course if not found.
    $translation->timecreated = time();
    $translation->timemodified = time();
    $translation->timereviewed = time();

    $service->store_translation(
        $translation->hash,
        $translation->lang,
        $translation->translated_text,
        $translation->contextlevel,
        $data->courseid,
        $translation->lang
    );

    redirect(
        new \moodle_url('/filter/autotranslate/manage.php', [
            'courseid' => $data->courseid,
            'filter_lang' => $tlang,
            'filter_human' => $data->filter_human,
            'filter_needsreview' => $data->filter_needsreview,
            'perpage' => $data->perpage,
            'page' => $data->page,
            'sort' => $data->sort,
            'dir' => $data->dir,
        ]),
        get_string('translationsaved', 'filter_autotranslate')
    );
}

// Output the header.
echo $OUTPUT->header();

// Render the "Manage Translations" link.
echo '<div class="row mb-3">';
echo '<div class="col-md-6">';
$manageurl = new \moodle_url('/filter/autotranslate/manage.php', [
    'courseid' => $courseid,
    'filter_lang' => $tlang,
    'filter_human' => $filterhuman,
    'filter_needsreview' => $filterneedsreview,
    'perpage' => $perpage,
    'page' => $page,
    'sort' => $sort,
    'dir' => $dir,
]);
echo \html_writer::link(
    $manageurl,
    get_string('managetranslations', 'filter_autotranslate'),
    ['class' => 'btn btn-secondary mb-3']
);
echo '</div>';
echo '</div>';

// Render the template.
$data = [
    'form' => $mform->render(),
    'source_text' => $sourcetext,
    'source_text_label' => get_string('sourcetext', 'filter_autotranslate'),
];
echo $OUTPUT->render_from_template('filter_autotranslate/create', $data);

echo $OUTPUT->footer();
