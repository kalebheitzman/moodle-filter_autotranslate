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
 * Translation creation page for the Autotranslate plugin.
 *
 * Renders a form to add a new translation for a hash and target language, saving it to
 * `filter_autotranslate_translations` via `content_service`. Redirects to `manage.php`.
 *
 * Features:
 * - Displays source text alongside a form (WYSIWYG or textarea based on HTML).
 * - Prevents creation of 'other' language records.
 * - Preserves `manage.php` filters in redirects.
 *
 * Usage:
 * - Accessed via '/filter/autotranslate/create.php' from `manage.php` "Add" link.
 * - Requires `hash` and `tlang` parameters with manage capability.
 *
 * Design:
 * - Uses `translation_source` for source text, `content_service` for saving.
 * - Switches editor type based on source text HTML presence.
 * - Validates inputs and session key for security.
 *
 * Dependencies:
 * - `text_utils.php`: Maps languages and validates input.
 * - `translation_source.php`: Fetches source text.
 * - `content_service.php`: Stores new translations.
 * - `form/create_form.php`: Renders the form.
 * - `templates/create.mustache`: UI template.
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_autotranslate;

use filter_autotranslate\form\create_form;
use filter_autotranslate\text_utils;
use filter_autotranslate\translation_source;
use filter_autotranslate\content_service;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/weblib.php');

// Require login and check capability.
require_login();
require_capability('filter/autotranslate:manage', \context_system::instance());

// Get parameters.
$hash = required_param('hash', PARAM_ALPHANUMEXT);
$tlang = required_param('tlang', PARAM_TEXT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$filterhuman = optional_param('filter_human', '', PARAM_INT);
$filterneedsreview = optional_param('filter_needsreview', '', PARAM_INT);
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
$queriedtlang = text_utils::map_language_to_other($tlang);

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
$translationsource = new translation_source($DB);
if ($translationsource->get_translation($hash, $queriedtlang)) {
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

// Initialize the content service.
$contentservice = new content_service($DB);

// Fetch the source ("other") record to get its contextlevel.
$sourcerecord = $translationsource->get_translation($hash, 'other');
if (!$sourcerecord) {
    redirect(
        new \moodle_url('/filter/autotranslate/manage.php'),
        get_string('notranslationfound', 'filter_autotranslate'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

// Fetch source text.
$sourcetext = $translationsource->get_source_text($hash);
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

    // Store the new translation.
    $translatedtext = is_array($data->translated_text) ? $data->translated_text['text'] : $data->translated_text;
    $contentservice->upsert_translation(
        $data->hash,
        $data->tlang,
        $translatedtext,
        $sourcerecord->contextlevel ?? CONTEXT_COURSE, // Fallback to course if not found.
        !empty($data->human) ? 1 : 0 // Human status from the form.
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
    'sourcetext' => $sourcetext,
    'sourcetextlabel' => get_string('sourcetext', 'filter_autotranslate'),
];
echo $OUTPUT->render_from_template('filter_autotranslate/create', $data);

echo $OUTPUT->footer();
