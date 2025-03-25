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
 * Autotranslate Edit Form
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_autotranslate\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form class for editing a translation in the filter_autotranslate plugin.
 *
 * Purpose:
 * This class defines the form used on the edit page (edit.php) to allow administrators to edit
 * a specific translation. It includes fields for the translated text and human status, with
 * read-only displays for the hash and language.
 *
 * Design Decisions:
 * - Uses Moodle's moodleform class to ensure consistency with Moodle's form handling.
 * - Includes hidden fields for hash and lang to preserve them during form submission.
 * - Displays hash and lang as static elements to inform the user without allowing edits.
 * - Uses a WYSIWYG editor for translated_text if use_wysiwyg is true (determined by edit.php),
 *   otherwise uses a textarea, to accommodate HTML content.
 * - Includes a help button for translated_text to guide users on translation best practices.
 * - Function names use snake_case (e.g., definition) to follow Moodle's coding style.
 *
 * Dependencies:
 * - None (uses Moodle's core formslib).
 */
class edit_form extends \moodleform {
    /**
     * Defines the form elements for editing a translation.
     *
     * This function sets up the form with fields for the translated text and human status,
     * along with read-only displays for the hash and language.
     */
    protected function definition() {
        $mform = $this->_form;

        // Hidden fields for hash and lang
        $mform->addElement('hidden', 'hash', $this->_customdata['translation']->hash);
        $mform->setType('hash', PARAM_ALPHANUMEXT);

        $mform->addElement('hidden', 'lang', $this->_customdata['tlang']);
        $mform->setType('lang', PARAM_ALPHANUM);

        // Display hash and language as static elements (read-only)
        $mform->addElement('static', 'hash_display', get_string('hash', 'filter_autotranslate'), $this->_customdata['translation']->hash);
        $mform->addElement('static', 'lang_display', get_string('language', 'filter_autotranslate'), $this->_customdata['tlang']);

        // Translated text field
        if ($this->_customdata['use_wysiwyg']) {
            $mform->addElement('editor', 'translated_text', get_string('translatedtext', 'filter_autotranslate'), ['rows' => 10]);
            $mform->setType('translated_text', PARAM_RAW);
            // Set default value as an array for the editor
            $mform->setDefault('translated_text', [
                'text' => $this->_customdata['translation']->translated_text,
                'format' => FORMAT_HTML
            ]);
        } else {
            $mform->addElement('textarea', 'translated_text', get_string('translatedtext', 'filter_autotranslate'), 'wrap="virtual" rows="10" cols="50"');
            $mform->setType('translated_text', PARAM_RAW);
            $mform->setDefault('translated_text', $this->_customdata['translation']->translated_text);
        }
        $mform->addRule('translated_text', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('translated_text', 'translation', 'filter_autotranslate');

        // Human translated checkbox
        $mform->addElement('checkbox', 'human', get_string('humantranslated', 'filter_autotranslate'));
        $mform->setDefault('human', $this->_customdata['translation']->human);

        // Action buttons
        $this->add_action_buttons(true, get_string('savechanges', 'filter_autotranslate'));
    }

    /**
     * Validates the form data.
     *
     * This function performs server-side validation of the form data. Currently, it relies on
     * client-side validation for required fields (e.g., translated_text), but additional
     * validation (e.g., for HTML content) could be added here if needed.
     *
     * @param array $data The submitted form data.
     * @param array $files The submitted files (not used).
     * @return array An array of errors, or an empty array if validation passes.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        // Additional validation can be added here if needed (e.g., validate HTML content)
        return $errors;
    }
}