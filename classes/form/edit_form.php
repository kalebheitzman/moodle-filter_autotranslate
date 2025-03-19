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
 * Edit Translation Form
 *
 * Form for editing a translation in the Autotranslate filter.
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_autotranslate\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class edit_form extends \moodleform {
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
        } else {
            $mform->addElement('textarea', 'translated_text', get_string('translatedtext', 'filter_autotranslate'), 'wrap="virtual" rows="10" cols="50"');
            $mform->setType('translated_text', PARAM_RAW);
        }
        $mform->addRule('translated_text', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('translated_text', 'translation', 'filter_autotranslate');
        $mform->setDefault('translated_text', $this->_customdata['translation']->translated_text);

        // Human translated checkbox
        $mform->addElement('checkbox', 'human', get_string('humantranslated', 'filter_autotranslate'));
        $mform->setDefault('human', $this->_customdata['translation']->human);

        // Action buttons
        $this->add_action_buttons(true, get_string('savechanges', 'filter_autotranslate'));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        return $errors;
    }
}