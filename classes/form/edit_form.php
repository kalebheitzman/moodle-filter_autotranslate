<?php
namespace filter_autotranslate\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class edit_form extends \moodleform {
    public function definition() {
        $mform = $this->_form;
        $translation = $this->_customdata['translation'];
        $tlang = $this->_customdata['tlang'];
        $use_wysiwyg = $this->_customdata['use_wysiwyg'] ?? false;

        $mform->addElement('hidden', 'hash', $translation->hash);
        $mform->setType('hash', PARAM_ALPHANUMEXT);

        $mform->addElement('hidden', 'tlang', $tlang);
        $mform->setType('tlang', PARAM_LANG);

        // Conditionally add editor or textarea
        if ($use_wysiwyg) {
            $mform->addElement('editor', 'translated_text', 'Translation', null, ['context' => \context_system::instance(), 'subdirs' => 0, 'maxfiles' => 0, 'enable_filemanagement' => false]);
            $mform->setDefault('translated_text', ['text' => $translation->translated_text, 'format' => FORMAT_HTML]);
        } else {
            $mform->addElement('textarea', 'translated_text', 'Translation', ['rows' => 15, 'cols' => 60]);
            $mform->setDefault('translated_text', $translation->translated_text);
        }

        $mform->addElement('checkbox', 'human', 'Human Translated');
        $mform->setDefault('human', $translation->human);

        $this->add_action_buttons();
    }

    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);
        if ($data['translated_text'] === null || (is_array($data['translated_text']) && empty($data['translated_text']['text']))) {
            $errors['translated_text'] = 'Translation cannot be empty';
        }
        // Allow updating the same language or adding a new one
        return $errors;
    }
}