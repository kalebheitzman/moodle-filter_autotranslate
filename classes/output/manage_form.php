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

use moodleform;

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
class manage_form extends moodleform {

    /**
     * Define Moodle Form
     *
     * @return void
     */
    public function definition() {
        global $CFG;
        
        $source_records = $this->_customdata['source_records'];
        $target_records = $this->_customdata['target_records'];
        $lang_dir = $this->_customdata['lang_dir'];

        $merged_records = [];
        foreach($source_records as $record) {
            $merged_record = $record;
            $target_record = $this->findObjectByKeyValue($target_records, "hash", $record->hash);
            $merged_record->target_text = $target_record->text;
            $merged_record->target_lang = $target_record->lang;
            $merged_record->target_lang_dir = $lang_dir;
            array_push($merged_records, $merged_record);
        }

        // Start moodle form.
        $mform = $this->_form;
        $mform->disable_form_change_checker();
        // \MoodleQuickForm::registerElementType(
        //     'cteditor',
        //     "$CFG->libdir/form/editor.php",
        //     '\filter_autotranslate\editor\MoodleQuickForm_cteditor'
        // );

        // Open Form.
        $mform->addElement('html', '<div class="container-fluid filter-autotranslate_form">');

        // Loop through merged records to build form.
        foreach ($merged_records as $record) {
            $this->get_formrow($mform, $record);
        }

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

        // Get mlangfilter to filter text.
        // $mlangfilter = $this->_customdata['mlangfilter'];

        // // Build a key for js interaction.
        // $key = "$item->table[$item->id][$item->field]";
        // $keyid = "{$item->table}-{$item->id}-{$item->field}";

        // // Data status.
        // $status = $item->tneeded ? 'needsupdate' : 'updated';

        // Open translation item.
        $mform->addElement(
            'html',
            '<div class="row align-items-start border-bottom py-3">'
        );

        // first column
        $mform->addElement('html', '<div class="col-2">');
        $mform->addElement('html', substr($record->hash, 0, 11));
        $mform->addElement('html', '</div>');

        // second column
        $mform->addElement('html', '<div
            class="col-5 px-0 pr-5 filter-autotranslate__source-text"
        >');
        $mform->addElement('html', $record->text);
        $mform->addElement('html', '</div>');

        // third column
        $mform->addElement('html', '<div
            class="col-5 px-0 pr-5 filter-autotranslate__target-text ' . $record->target_lang_dir . '"
        >');
        // if ($record->target_lang !== "en") {
            if ($PAGE->user_is_editing() ) {
                // edit mode is on
                $field_name = 'text[' . $record->hash . ']';
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
                    // $mform->setType($field_name, PARAM_RAW);
                    // $mform->setDefault($field_name, $record->target_text);
                } else {
                    $mform->addElement('textarea', $field_name, null);
                    $mform->setDefault($field_name, $record->target_text);
                }
            } else {
                // edit mode is off
                $mform->addElement('html', $record->target_text);
            }
        // }
        $mform->addElement('html', '</div>');

        // // First column.
        // $mform->addElement('html', '<div class="col-2">');
        // $mform->addElement('html', '<div class="form-check">');
        // $mform->addElement('html', '<input
        //     class="form-check-input local-coursetranslator__checkbox"
        //     type="checkbox"
        //     data-key="' . $key . '"
        //     disabled
        // />');
        // $label = '<label class="form-check-label">';
        // if ($item->tneeded) {
        //     $label .= ' <span class="badge badge-pill badge-danger rounded py-1" data-status-key="' . $key . '">'
        //             . get_string('t_needsupdate', 'filter_autotranslate')
        //             . '</span>';
        // } else {
        //     $label .= ' <span class="badge badge-pill badge-success rounded py-1" data-status-key="' . $key . '">'
        //             . get_string('t_uptodate', 'filter_autotranslate')
        //             . '</span>';
        // }
        // $label .= '</label>';
        // $label .= '<a href="' . $item->link . '" target="_blank" title="' . get_string('t_edit', 'filter_autotranslate') . '">';
        // $label .= '<i class="fa fa-pencil-square-o mr-1" aria-hidden="true"></i>';
        // $label .= '</a>';
        // $label .= '<a data-toggle="collapse" title="' . get_string('t_viewsource', 'filter_autotranslate') . '" href="#'
        //     . $keyid . '" role="button" aria-expanded="false" aria-controls="'
        //     . $keyid . '"><i class="fa fa-code" aria-hidden="true"></i></a>';
        // $mform->addElement('html', $label);
        // $mform->addElement('html', '</div>');
        // $mform->addElement('html', '</div>');

        // // Source Text.
        // $mform->addElement('html', '<div
        //     class="col-5 px-0 pr-5 local-coursetranslator__source-text"
        //     data-key="' . $key . '"
        // >');
        // $mform->addElement('html', '<div data-sourcetext-key="' . $key . '">' . $mlangfilter->filter($item->text) . '</div>');
        // $mform->addElement('html', '<div>');

        // $mform->addElement('html', '<div class="collapse" id="' . $keyid . '">');
        // $mform->addElement(
        //     'html',
        //     '<div data-key="' . $key
        //     . '" class="mt-3 card card-body local-coursetranslator__textarea">'
        //     . trim($item->text) . '</div>'
        // );
        // $mform->addElement('html', '</div>');
        // $mform->addElement('html', '</div>');
        // $mform->addElement('html', '</div>');

        // // Translation Input.
        // $mform->addElement('html', '<div
        //     class="col-5 px-0 local-coursetranslator__translation local-coursetranslator__editor"
        //     data-key="' . $key . '"
        //     data-table="' . $item->table . '"
        //     data-id="' . $item->id . '"
        //     data-field="' . $item->field . '"
        //     data-tid="' . $item->tid . '"
        // >');
        // // Plain text input.
        // if ($item->format === 0) {
        //     $mform->addElement('html', '<div
        //         class="format-' . $item->format . ' border py-2 px-3"
        //         contenteditable="true"
        //         data-format="' . $item->format . '"
        //     ></div>');
        // }
        // // HTML input.
        // if ($item->format === 1) {
        //     $mform->addElement('cteditor', $key, $key);
        //     $mform->setType($key, PARAM_RAW);
        // }

        // $mform->addElement('html', '</div>');

        // Close translation item.
        $mform->addElement('html', '</div>');

    }

    /**
     * Process data
     *
     * @param \stdClass $data
     * @return void
     */
    public function process(\stdClass $data) {

    }

    /**
     * Specificy Autotranslation Access
     *
     * @return void
     */
    // public function require_access() {
    //     require_capability('local/multilingual:edittranslations', \context_system::instance()->id);
    // }


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
