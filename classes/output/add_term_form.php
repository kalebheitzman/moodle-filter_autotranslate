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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace filter_autotranslate\output;

// Load the files we're going to need.
defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Autotranslate Add Term Form Output
 *
 * Provides output class for /filter/autotranslate/glossary.php
 *
 * @package    filter_autotranslate
 * @copyright  2024 Kaleb Heitzman <kaleb@jamfire.io>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class add_term_form extends \moodleform {
    /**
     * @param string $urlparms URL Params from Glossary Page
     */
    private array $urlparams;

    /**
     * Define Moodle Form
     *
     * @return void
     */
    public function definition() {
        global $CFG;

        // Start moodle form.
        $mform = $this->_form;

        // Get customdata.
        $sourcelang = $this->_customdata['source_lang'];
        $targetlang = $this->_customdata['target_lang'];
        $langdir = $this->_customdata['lang_dir'];
        $page = $this->_customdata['page'];
        $limit = $this->_customdata['limit'];

        // URL params for pagination.
        $this->urlparams = [];
        $this->urlparams['source_lang'] = $sourcelang;
        $this->urlparams['target_lang'] = $targetlang;
        $this->urlparams['limit'] = $limit;


        // Open Form.
        $mform->addElement('html', '<div class="row pt-5 filter-autotranslate__form">');

        // Source Term.
        $mform->addElement('html', '<div class="col-6">');
        $mform->addElement(
            'text',
            'term',
            get_string("glossary_term", "filter_autotranslate"),
            [
                "class" => "w-100",
                "placeholder" => get_string("glossary_term", "filter_autotranslate"),
            ]
        );
        $mform->setType('term', PARAM_TEXT);
        $mform->addElement('html', '</div>');

        // Action Buttons.
        $mform->addElement('html', '<div class="col-6">');

        // Hidden Fields.
        $mform->addElement('hidden', 'source_lang', $sourcelang);
        $mform->setType('source_lang', PARAM_NOTAGS);

        $mform->addElement('hidden', 'target_lang', $targetlang);
        $mform->setType('target_lang', PARAM_NOTAGS);

        $mform->addElement('hidden', 'page', $page);
        $mform->setType('page', PARAM_INT);

        $mform->addElement('hidden', 'limit', $limit);
        $mform->setType('limit', PARAM_INT);

        // Buttons.
        $buttonarray = [];
        $buttonarray[] = $mform->createElement('submit', 'submitbutton', get_string('submit'));
        $mform->addGroup($buttonarray, 'buttonar', '', '', false);
        $mform->addElement('html', '</div>');

        // Close Form.
        $mform->addElement('html', '</div>');
    }

    /**
     * Specify Autotranslation Access
     *
     * @return void
     */
    public function require_access() {
        require_capability('filter/autotranslate:translate', \context_system::instance()->id);
    }

    /**
     * Validation
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        return $errors;
    }

    /**
     * Get Data
     */
    public function get_data() {
        $data = parent::get_data();
        return $data;
    }
}
