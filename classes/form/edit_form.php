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
 * Autotranslate Edit Form
 *
 * Purpose:
 * This class defines the form used on the edit page (edit.php) in the filter_autotranslate plugin,
 * allowing administrators to modify an existing translation. It includes fields for translated text
 * and human status, with read-only displays for hash and language.
 *
 * Usage:
 * Instantiated in edit.php to provide a form for updating a translation, with options for WYSIWYG
 * or textarea based on the translated text’s HTML content.
 *
 * Design Decisions:
 * - Extends Moodle’s `moodleform` class for consistency with Moodle’s form handling.
 * - Uses hidden fields to preserve hash and lang during submission.
 * - Displays hash and lang as static elements to inform users without allowing edits.
 * - Switches between WYSIWYG editor and textarea based on the `use_wysiwyg` flag.
 * - Uses all lowercase variable names per plugin convention.
 *
 * Dependencies:
 * - None (uses Moodle’s core `formslib`).
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_autotranslate\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form class for editing a translation.
 */
class edit_form extends \moodleform {
    /**
     * Defines the form elements for editing a translation.
     *
     * Sets up the form with fields for translated text and human status, along with read-only
     * displays for hash and language.
     */
    protected function definition() {
        $mform = $this->_form;

        // Hidden fields for hash and lang.
        $mform->addElement('hidden', 'hash', $this->_customdata['translation']->hash);
        $mform->setType('hash', PARAM_ALPHANUMEXT);

        $mform->addElement('hidden', 'lang', $this->_customdata['tlang']);
        $mform->setType('lang', PARAM_ALPHANUM);

        // Display hash and language as static elements (read-only).
        $mform->addElement(
            'static',
            'hashdisplay',
            get_string('hash', 'filter_autotranslate'),
            $this->_customdata['translation']->hash
        );
        $mform->addElement(
            'static',
            'langdisplay',
            get_string('language', 'filter_autotranslate'),
            $this->_customdata['tlang']
        );

        // Translated text field.
        if ($this->_customdata['use_wysiwyg']) {
            $mform->addElement(
                'editor',
                'translated_text',
                get_string('translatedtext', 'filter_autotranslate'),
                ['rows' => 10]
            );
            $mform->setType('translated_text', PARAM_RAW);
            $mform->setDefault('translated_text', [
                'text' => $this->_customdata['translation']->translated_text,
                'format' => FORMAT_HTML,
            ]);
        } else {
            $mform->addElement(
                'textarea',
                'translated_text',
                get_string('translatedtext', 'filter_autotranslate'),
                'wrap="virtual" rows="10" cols="50"'
            );
            $mform->setType('translated_text', PARAM_RAW);
            $mform->setDefault('translated_text', $this->_customdata['translation']->translated_text);
        }
        $mform->addRule('translated_text', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('translated_text', 'translation', 'filter_autotranslate');

        // Human translated checkbox.
        $mform->addElement(
            'checkbox',
            'human',
            get_string('humantranslated', 'filter_autotranslate')
        );
        $mform->setDefault('human', $this->_customdata['translation']->human);

        // Action buttons.
        $this->add_action_buttons(true, get_string('savechanges', 'filter_autotranslate'));
    }

    /**
     * Validates the form data.
     *
     * Performs server-side validation, relying on client-side checks for required fields, with
     * room for additional validation if needed (e.g., HTML content).
     *
     * @param array $data The submitted form data.
     * @param array $files The submitted files (not used).
     * @return array An array of errors, or empty if validation passes.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        // Additional validation can be added here if needed.
        return $errors;
    }
}
