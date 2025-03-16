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

        // Translation textarea/editor with label above
        $mform->addElement('html', '<div class="form-group">');
        $mform->addElement('html', '<label class="col-form-label">Translation</label>');
        if ($use_wysiwyg) {
            $mform->addElement('editor', 'translated_text', '', null, [
                'context' => \context_system::instance(),
                'subdirs' => 0,
                'maxfiles' => 0,
                'enable_filemanagement' => false,
            ]);
            $mform->setDefault('translated_text', ['text' => $translation->translated_text, 'format' => FORMAT_HTML]);
        } else {
            $mform->addElement('textarea', 'translated_text', '', ['rows' => 15, 'cols' => 60, 'class' => 'form-control']);
            $mform->setDefault('translated_text', $translation->translated_text);
        }
        $mform->addElement('html', '</div>');

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
        return $errors;
    }
}