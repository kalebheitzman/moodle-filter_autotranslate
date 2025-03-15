<?php
namespace filter_autotranslate\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class edit_form extends \moodleform {
    public function definition() {
        $mform = $this->_form;
        $translation = $this->_customdata['translation'];
        $tlang = $this->_customdata['tlang'];

        $mform->addElement('hidden', 'hash', $translation->hash);
        $mform->setType('hash', PARAM_ALPHANUMEXT);

        $mform->addElement('hidden', 'tlang', $tlang);
        $mform->setType('tlang', PARAM_LANG);

        $mform->addElement('textarea', 'translated_text', 'Translation', ['rows' => 10, 'cols' => 60]);
        $mform->setDefault('translated_text', $translation->translated_text);

        $mform->addElement('checkbox', 'human', 'Human Translated');
        $mform->setDefault('human', $translation->human);

        $this->add_action_buttons();
    }

    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);
        if (empty($data['translated_text'])) {
            $errors['translated_text'] = 'Translation cannot be empty';
        }
        // Allow updating the same language or adding a new one
        return $errors;
    }
}