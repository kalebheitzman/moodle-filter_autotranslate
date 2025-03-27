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
 * Autotranslate Create Form
 *
 * Form class for creating a new translation in the filter_autotranslate plugin.
 *
 * Purpose:
 * This class defines the form used on the create page (create.php) to allow administrators to add
 * a new translation for a specific hash and language. It includes fields for translated text and
 * human status, with read-only displays for hash and language, and preserves manage.php filters.
 *
 * Design Decisions:
 * - Uses Moodle's moodleform class for consistency with Moodle's form handling.
 * - Includes hidden fields for hash, tlang, and manage.php filters to preserve them during submission.
 * - Displays hash and lang as static elements to inform the user without allowing edits.
 * - Uses a WYSIWYG editor if source text contains HTML, otherwise a textarea, based on use_wysiwyg flag.
 * - Defaults 'human' checkbox to checked, as this is a manual translation entry.
 * - Includes a help button for translated_text to guide users.
 * - Function names use snake_case (e.g., definition) per Moodle coding style.
 *
 * Dependencies:
 * - None (uses Moodle's core formslib).
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
     * Sets up the form with fields for translated text and human status, along with read-only
     * displays for hash and language, and hidden fields for filter preservation.
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
        $mform->setType('filter_human', PARAM_RAW);

        $mform->addElement('hidden', 'filter_needsreview', $this->_customdata['filter_needsreview']);
        $mform->setType('filter_needsreview', PARAM_RAW);

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
            'hash_display',
            get_string('hash', 'filter_autotranslate'),
            $this->_customdata['hash']
        );
        $mform->addElement(
            'static',
            'lang_display',
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
            $mform->setType('translated_text', PARAM_RAW);
            $mform->setDefault('translated_text', ['text' => '', 'format' => FORMAT_HTML]);
        } else {
            $mform->addElement(
                'textarea',
                'translated_text',
                get_string('translatedtext', 'filter_autotranslate'),
                'wrap="virtual" rows="10" cols="50"'
            );
            $mform->setType('translated_text', PARAM_RAW);
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
     * Performs server-side validation. Currently relies on client-side required field check,
     * but can be extended for additional validation (e.g., HTML content checks).
     *
     * @param array $data The submitted form data.
     * @param array $files The submitted files (not used).
     * @return array An array of errors, or empty if validation passes.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        // Additional validation can be added here if needed (e.g., HTML content validation).
        return $errors;
    }
}
