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

global $CFG;
require_once($CFG->libdir . '/formslib.php');

// Load the files we're going to need.
defined('MOODLE_INTERNAL') || die();
// require_once("$CFG->libdir/form/editor.php");
// require_once("$CFG->dirroot/local/coursetranslator/classes/editor/MoodleQuickForm_cteditor.php");

/**
 * Autotranslate Manage Form Output
 *
 * Provides output class for /filter/autotranslate/manage.php
 *
 * @package    filter_autotranslate
 * @copyright  2024 Kaleb Heitzman <kaleb@jamfire.io>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manage_form extends \moodleform {

    /**
     * Define Moodle Form
     *
     * @return void
     */
    public function definition() {
        global $CFG;

        // Start moodle form.
        $mform = $this->_form;
        // $this->_form->disable_form_change_checker();
            
        // get customdata
        $source_records = $this->_customdata['source_records'];
        $target_records = $this->_customdata['target_records'];
        $source_lang = $this->_customdata['source_lang'];
        $target_lang = $this->_customdata['target_lang'];
        $lang_dir = $this->_customdata['lang_dir'];
        $pages = $this->_customdata['pages'];
        $page = $this->_customdata['page'];
        $limit = $this->_customdata['limit'];

        // $this->_form->_attributes['action'] = new \moodle_url('/filter/autotranslate/manage.php', array(
        //     'source_lang' => $this->source_lang, 
        //     'target_lang' => $this->target_lang,
        //     'page' => $page,
        //     'limit' => $limit,
        // ));

        $merged_records = [];
        foreach($source_records as $record) {
            $merged_record = $record;
            $target_record = $this->findObjectByKeyValue($target_records, "hash", $record->hash);
            $merged_record->target_text = $target_record->text;
            $merged_record->target_lang = $target_record->lang;
            $merged_record->target_lang_dir = $lang_dir;
            array_push($merged_records, $merged_record);
        }

        // Open Form.
        $mform->addElement('html', '<div class="container-fluid filter-autotranslate_form">');

        // Loop through merged records to build form.
        $formData = [];
        foreach ($merged_records as $record) {
            $is_html = $this->contains_html($record->text);
            $hash = $record->hash;
            if ($is_html) {
                $formData[$hash] = array(
                        "text" => $record->text,
                        "format" => "1"
                );
            } else {
                $formData[$hash] = $record->text;
            }
            $this->get_formrow($mform, $record);
        }

        $this->set_data($formData);

        $mform->addElement('html', '<div class="row pt-5">');

        // pagination
        $mform->addElement('html', '<div class="col-7 filter-autotranslate__pagination">');
        $mform->addElement('html', '<ul>');
        
        
        // Number of pages to display around the current page
        $pagesToShow = 5;

        // Calculate the range of pages to display
        $startPage = max(1, $page - floor($pagesToShow / 2));
        $endPage = min($startPage + $pagesToShow - 1, end($pages));

        // Display "First" link
        if ($startPage > 1) {
            $firstUrl = new \moodle_url('/filter/autotranslate/manage.php', array(
                'source_lang' => $source_lang,
                'target_lang' => $target_lang,
                'page' => 1,
                'limit' => $limit
            ));
            $mform->addElement('html', '<li class="mr-1 mb-1"><a href="' . $firstUrl->out() . '" class="btn btn-light">' . get_string('pag_first', 'filter_autotranslate') . '</a></li>');
        }

        // Display "Previous" link if applicable
        if ($startPage > 1) {
            $prevPage = max(1, $page - 1);
            $prevUrl = new \moodle_url('/filter/autotranslate/manage.php', array(
                'source_lang' => $source_lang,
                'target_lang' => $target_lang,
                'page' => $prevPage,
                'limit' => $limit
            ));
            $mform->addElement('html', '<li class="mr-1 mb-1"><a href="' . $prevUrl->out() . '" class="btn btn-light">' . get_string('pag_previous', 'filter_autotranslate') . '</a></li>');
        }

        // Display the range of pages
        for ($pagenum = $startPage; $pagenum <= $endPage; $pagenum++) {
            $url = new \moodle_url('/filter/autotranslate/manage.php', array(
                'source_lang' => $source_lang,
                'target_lang' => $target_lang,
                'page' => $pagenum,
                'limit' => $limit
            ));
            $mform->addElement('html', '<li class="mr-1 mb-1">');
            $btn_class = intval($page) === intval($pagenum) ? 'btn-primary' : 'btn-light';
            $mform->addElement('html', '<a href="' . $url->out() . '" class="btn ' . $btn_class . '">' . $pagenum . '</a>');
            $mform->addElement('html', '</li>');
        }

        // Display "Next" link if applicable
        if ($endPage < end($pages)) {
            $nextPage = min(end($pages), $page + 1);
            $nextUrl = new \moodle_url('/filter/autotranslate/manage.php', array(
                'source_lang' => $source_lang,
                'target_lang' => $target_lang,
                'page' => $nextPage,
                'limit' => $limit
            ));
            $mform->addElement('html', '<li class="mr-1 mb-1"><a href="' . $nextUrl->out() . '" class="btn btn-light">' . get_string('pag_next', 'filter_autotranslate') . '</a></li>');
        }

        // Display "Last" link
        if ($endPage < end($pages)) {
            $lastUrl = new \moodle_url('/filter/autotranslate/manage.php', array(
                'source_lang' => $source_lang,
                'target_lang' => $target_lang,
                'page' => end($pages),
                'limit' => $limit
            ));
            $mform->addElement('html', '<li class="mr-1 mb-1"><a href="' . $lastUrl->out() . '" class="btn btn-light">' . get_string('pag_last', 'filter_autotranslate') . '</a></li>');
        }

        
        $mform->addElement('html', '</ul>');
        $mform->addElement('html', '</div>');

        // action buttons
        $mform->addElement('html', '<div class="col-5">');
        $buttonarray=array();
        $buttonarray[] = $mform->createElement('submit', 'submitbutton', get_string('savechanges'));
        // $buttonarray[] = $mform->createElement('reset', 'resetbutton', get_string('revert'));
        // $buttonarray[] = $mform->createElement('cancel');
        $mform->addGroup($buttonarray, 'buttonar', '', ' ', false);
        $mform->addElement('html', '</div>');

        $mform->addElement('html', '</div>');


        $mform->addElement('hidden', 'source_lang', $source_lang);
        $mform->setType('source_lang', PARAM_NOTAGS);

        $mform->addElement('hidden', 'target_lang', $target_lang);
        $mform->setType('target_lang', PARAM_NOTAGS);

        $mform->addElement('hidden', 'page', $page);
        $mform->setType('page', PARAM_INT);

        $mform->addElement('hidden', 'limit', $limit);
        $mform->setType('limit', PARAM_INT);

        // Close form.
        $mform->addElement('html', '</div>');
    }

    /**
     * Generate Form Row
     *
     * @param \MoodleQuickForm $mform
     * @param \stdClass $item
     * @return void
     */
    private function get_formrow(\MoodleQuickForm $mform, \stdClass $record) {
        global $PAGE;

        // Open translation item.
        $mform->addElement(
            'html',
            '<div class="row align-items-start border-bottom py-3">'
        );

        // first column
        $mform->addElement('html', '<div class="col-2">');
        $mform->addElement('html', $record->id . ": " . substr($record->hash, 0, 11));
        $mform->addElement('html', '</div>');

        // second column
        $mform->addElement('html', '<div
            class="col-5 filter-autotranslate__source-text"
        >');
        $mform->addElement('html', $record->text);
        $mform->addElement('html', '</div>');

        // third column
        $mform->addElement('html', '<div
            class="col-5 filter-autotranslate__target-text ' . $record->target_lang_dir . '"
        >');
        // if ($record->target_lang !== "en") {
            if ($PAGE->user_is_editing() ) {
                // edit mode is on
                $field_name = 'translation[' . $record->hash . ']';
                $is_html = $this->contains_html($record->text);
                if ($is_html) {
                    $mform->addElement(
                        'editor', 
                        $field_name, 
                        null, 
                        array(
                            'autosave' => false,
                            'removeorphaneddrafts' => true
                        )
                    )->setValue(array('text' => $record->target_text));
                    $mform->setType($field_name, PARAM_RAW);
                    // $mform->setDefault($field_name, $record->target_text);
                } else {
                    $mform->addElement('textarea', $field_name, null, 
                        array(
                            'oninput' => 'this.style.height = "";this.style.height = this.scrollHeight + "px"',
                            'onfocus' => 'this.style.height = "";this.style.height = this.scrollHeight + "px"'
                        )
                    );
                    $mform->setDefault($field_name, $record->target_text);
                }
            } else {
                // edit mode is off
                $mform->addElement('html', $record->target_text);
            }
        // }
        $mform->addElement('html', '</div>');

        // Close translation item.
        $mform->addElement('html', '</div>');
    }

    /**
     * Specificy Autotranslation Access
     *
     * @return void
     */
    // public function require_access() {
    //     require_capability('local/multilingual:edittranslations', \context_system::instance()->id);
    // }

    public function validation($data, $files) {
        // mtrace("Form data before validation: " . print_r($data, true));
        $errors = parent::validation($data, $files);
        // mtrace("Form data after validation: " . print_r($data, true));
        return $errors;
    }

    public function get_data() {
        $data = parent::get_data();
        // mtrace("Form data after submission: " . print_r($data, true));
        return $data;
    }


    /**
     * Find Object by Key Value
     * 
     * @param array Array
     * @param key Array Key
     * @param value Value to search for
     * @return Object
     */
    private function findObjectByKeyValue($array, $key, $value) {
        $filteredArray = array_filter($array, function ($object) use ($key, $value) {
            return $object->{$key} === $value;
        });

        // If array_filter finds a match, return the first element; otherwise, return null
        return reset($filteredArray) ?: null;
    }

    /**
     * Detect if string has html
     * 
     * @param $string String to check
     * @return boolean
     */
    private function contains_html($string) {
        // Strip HTML and PHP tags from the input string
        $stripped_string = strip_tags($string);

        // Compare the original and stripped strings
        if ($string !== $stripped_string) {
            return true; // String contains HTML or PHP
        } else {
            return false; // String does not contain HTML or PHP
        }
    }
}
