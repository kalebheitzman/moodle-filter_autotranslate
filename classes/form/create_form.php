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
 * Autotranslate create form.
 *
 * Defines the form for `create.php` in the Autotranslate plugin, enabling admins to add
 * a new translation for a specific hash and language, stored via `content_service.php`.
 *
 * Features:
 * - Fields for translated text and human status (default checked).
 * - Read-only displays for hash and language.
 * - Preserves `manage.php` filters via hidden fields.
 *
 * Usage:
 * - Instantiated in `create.php` to create translations, using WYSIWYG or textarea based
 *   on source text HTML, submitted to `content_service->upsert_translation()`.
 *
 * Design:
 * - Extends `moodleform` for Moodle form consistency.
 * - Static hash/lang displays prevent edits, hidden fields retain filters.
 * - WYSIWYG/textarea toggled by `use_wysiwyg` from `create.php`.
 * - Uses lowercase variable names per plugin convention.
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_autotranslate\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form class for creating a new translation.
 */
class create_form extends \moodleform {
    /**
     * Defines the form elements for creating a new translation.
     *
     * Adds fields for translated text and human status, static hash/lang displays, and hidden
     * fields to preserve `manage.php` filters.
     */
    protected function definition() {
        $mform = $this->_form;

        // Hidden fields for hash, tlang, and manage.php filters.
        $mform->addElement('hidden', 'hash', $this->_customdata['hash']);
        $mform->setType('hash', PARAM_ALPHANUMEXT);

        $mform->addElement('hidden', 'tlang', $this->_customdata['tlang']);
        $mform->setType('tlang', PARAM_ALPHANUM);

        $mform->addElement('hidden', 'courseid', $this->_customdata['courseid']);
        $mform->setType('courseid', PARAM_INT);

        $mform->addElement('hidden', 'filter_human', $this->_customdata['filter_human']);
        $mform->setType('filter_human', PARAM_INT);

        $mform->addElement('hidden', 'filter_needsreview', $this->_customdata['filter_needsreview']);
        $mform->setType('filter_needsreview', PARAM_INT);

        $mform->addElement('hidden', 'perpage', $this->_customdata['perpage']);
        $mform->setType('perpage', PARAM_INT);

        $mform->addElement('hidden', 'page', $this->_customdata['page']);
        $mform->setType('page', PARAM_INT);

        $mform->addElement('hidden', 'sort', $this->_customdata['sort']);
        $mform->setType('sort', PARAM_ALPHA);

        $mform->addElement('hidden', 'dir', $this->_customdata['dir']);
        $mform->setType('dir', PARAM_ALPHA);

        // Display hash and language as static elements (read-only).
        $mform->addElement(
            'static',
            'hashdisplay',
            get_string('hash', 'filter_autotranslate'),
            $this->_customdata['hash']
        );
        $mform->addElement(
            'static',
            'langdisplay',
            get_string('language', 'filter_autotranslate'),
            $this->_customdata['tlang']
        );

        // Translated text field: WYSIWYG editor or textarea based on source text HTML.
        if ($this->_customdata['use_wysiwyg']) {
            $mform->addElement(
                'editor',
                'translated_text',
                get_string('translatedtext', 'filter_autotranslate'),
                ['rows' => 10]
            );
            $mform->setType('translated_text', PARAM_CLEANHTML);
            $mform->setDefault('translated_text', ['text' => '', 'format' => FORMAT_HTML]);
        } else {
            $mform->addElement(
                'textarea',
                'translated_text',
                get_string('translatedtext', 'filter_autotranslate'),
                'wrap="virtual" rows="10" cols="50"'
            );
            $mform->setType('translated_text', PARAM_CLEANHTML);
            $mform->setDefault('translated_text', '');
        }
        $mform->addRule('translated_text', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('translated_text', 'translation', 'filter_autotranslate');

        // Human translated checkbox, default checked.
        $mform->addElement(
            'checkbox',
            'human',
            get_string('humantranslated', 'filter_autotranslate')
        );
        $mform->setDefault('human', 1); // Default to checked for manual entry.

        // Action buttons.
        $this->add_action_buttons(true, get_string('savechanges', 'filter_autotranslate'));
    }

    /**
     * Validates the form data.
     *
     * Ensures client-side required field validation; server-side checks can be added if needed.
     *
     * @param array $data The submitted form data.
     * @param array $files The submitted files (not used).
     * @return array An array of errors, or empty if validation passes.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        // Additional server-side validation can be added here (e.g., text length).
        return $errors;
    }
}
