<?php
namespace filter_autotranslate\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class edit_form extends \moodleform {
    public function definition() {
        $mform = $this->_form;
        $translation = $this->_customdata['translation'];
        $lang = $this->_customdata['lang'] ?? 'other'; // Default to 'other' if not set

        // Display the language being edited
        $langlabel = ($lang === 'other') ? 'Source Text (other)' : get_string('language', 'filter_autotranslate') . ': ' . $lang;
        $mform->addElement('static', 'langdisplay', get_string('edittranslation', 'filter_autotranslate'), $langlabel);

        $mform->addElement('hidden', 'hash', $translation->hash);
        $mform->setType('hash', PARAM_ALPHANUMEXT);
        $mform->addElement('hidden', 'lang', $lang);
        $mform->setType('lang', PARAM_LANG);
        // Removed contextid as it’s no longer relevant without instanceid
        // $mform->addElement('hidden', 'contextid', $translation->contextlevel);
        // $mform->setType('contextid', PARAM_INT);

        $mform->addElement('textarea', 'translated_text', get_string('translatedtext', 'filter_autotranslate'), 'rows="20" cols="50"');
        $mform->setDefault('translated_text', $translation->translated_text);
        $mform->addRule('translated_text', get_string('required'), 'required', null, 'client');

        $this->add_action_buttons(true, get_string('savechanges', 'filter_autotranslate'));
    }
}