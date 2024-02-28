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
     * @var string $urlparms URL Params from Manage Page
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
        $sourcerecords = $this->_customdata['source_records'];
        $targetrecords = $this->_customdata['target_records'];
        $sourcelang = $this->_customdata['source_lang'];
        $targetlang = $this->_customdata['target_lang'];
        $status = $this->_customdata['status'];
        $langdir = $this->_customdata['lang_dir'];
        $pages = $this->_customdata['pages'];
        $page = $this->_customdata['page'];
        $limit = $this->_customdata['limit'];
        $instanceid = $this->_customdata['instanceid'];
        $contextlevel = $this->_customdata['contextlevel'];

        // URL params for pagination.
        $this->urlparams = [];
        $this->urlparams['target_lang'] = $targetlang;
        $this->urlparams['limit'] = $limit;
        if ($instanceid) {
            $this->urlparams['instanceid'] = $instanceid;
        }
        if ($contextlevel) {
            $this->urlparams['contextlevel'] = $contextlevel;
        }
        if ($status > -1) {
            $this->urlparams['status'] = $status;
        }

        $mergedrecords = [];
        foreach ($sourcerecords as $record) {
            $mergedrecord = $record;
            $targetrecord = $this->findObjectByKeyValue($targetrecords, "hash", $record->hash);
            $mergedrecord->target_text = $targetrecord->text;
            $mergedrecord->target_lang = $targetrecord->lang;
            $mergedrecord->target_lang_dir = $langdir;
            array_push($mergedrecords, $mergedrecord);
        }

        // Loop through merged records to build form.
        $formdata = [];
        foreach ($mergedrecords as $record) {
            $ishtml = $this->contains_html($record->text);
            $hash = $record->hash;
            if ($ishtml) {
                $formdata[$hash] = [
                        "text" => $record->text,
                        "format" => "1",
                ];
            } else {
                $formdata[$hash] = $record->text;
            }
            $this->get_formrow($mform, $record);
        }

        $this->set_data($formdata);

        $mform->addElement('html', '<div class="row pt-5">');

        // Pagination.
        $mform->addElement('html', '<div class="col-7 filter-autotranslate__pagination">');
        $mform->addElement('html', '<ul>');

        // Number of pages to display around the current page.
        $pagestoshow = 5;

        // Calculate the range of pages to display.
        $startpage = max(1, $page - floor($pagestoshow / 2));
        $endpage = min($startpage + $pagestoshow - 1, end($pages));

        // Display "First" link.
        if ($startpage > 1) {
            $firsturl = new \moodle_url('/filter/autotranslate/manage.php', [
                ...$this->urlparams,
                'page' => 1,
            ]);
            $mform->addElement('html', '<li class="mr-1 mb-1"><a href="' .
                $firsturl->out() . '" class="btn btn-light">' .
                get_string('pag_first', 'filter_autotranslate') . '</a></li>');
        }

        // Display "Previous" link if applicable.
        if ($startpage > 1) {
            $prevpage = max(1, $page - 1);
            $prevurl = new \moodle_url('/filter/autotranslate/manage.php', [
                ...$this->urlparams,
                'page' => $prevpage,
            ]);
            $mform->addElement('html', '<li class="mr-1 mb-1"><a href="' . $prevurl->out() .
                '" class="btn btn-light">' . get_string('pag_previous', 'filter_autotranslate') . '</a></li>');
        }

        // Display the range of pages.
        for ($pagenum = $startpage; $pagenum <= $endpage && $endpage > 1; $pagenum++) {
            $url = new \moodle_url('/filter/autotranslate/manage.php', [
                ...$this->urlparams,
                'page' => $pagenum,
            ]);
            $mform->addElement('html', '<li class="mr-1 mb-1">');
            $btnclass = intval($page) === intval($pagenum) ? 'btn-primary' : 'btn-light';
            $mform->addElement('html', '<a href="' . $url->out() . '" class="btn ' . $btnclass . '">' . $pagenum . '</a>');
            $mform->addElement('html', '</li>');
        }

        // Display "Next" link if applicable.
        if ($endpage < end($pages)) {
            $nextpage = min(end($pages), $page + 1);
            $nexturl = new \moodle_url('/filter/autotranslate/manage.php', [
                ...$this->urlparams,
                'page' => $nextpage,
            ]);
            $mform->addElement('html', '<li class="mr-1 mb-1"><a href="' . $nexturl->out() .
            '" class="btn btn-light">' . get_string('pag_next', 'filter_autotranslate') . '</a></li>');
        }

        // Display "Last" link.
        if ($endpage < end($pages)) {
            $lasturl = new \moodle_url('/filter/autotranslate/manage.php', [
                ...$this->urlparams,
                'page' => end($pages),
            ]);
            $mform->addElement('html', '<li class="mr-1 mb-1"><a href="' . $lasturl->out() .
            '" class="btn btn-light">' . get_string('pag_last', 'filter_autotranslate') . '</a></li>');
        }

        $mform->addElement('html', '</ul>');
        $mform->addElement('html', '</div>');

        // Action buttons.
        $mform->addElement('html', '<div class="col-5">');
        $buttonarray = [];
        $buttonarray[] = $mform->createElement('submit', 'submitbutton', get_string('savechanges'));
        $mform->addGroup($buttonarray, 'buttonar', '', ' ', false);
        $mform->addElement('html', '</div>');

        $mform->addElement('html', '</div>');

        $mform->addElement('hidden', 'source_lang', $sourcelang);
        $mform->setType('source_lang', PARAM_NOTAGS);

        $mform->addElement('hidden', 'target_lang', $targetlang);
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
     * @param \MoodleQuickForm $mform Moodle Form
     * @param \stdClass $record Row Item
     * @return void
     */
    private function get_formrow(\MoodleQuickForm $mform, \stdClass $record) {
        global $PAGE;
        global $DB;

        // Open translation item.
        $mform->addElement(
            'html',
            '<div class="row align-items-start border-bottom py-3">'
        );

        // First column.
        $translations = $DB->get_records(
            'filter_autotranslate',
            [
                'hash' => $record->hash,
            ],
            'lang ASC',
            'lang,status'
        );
        $mform->addElement('html', '<div class="col-2">');
        $mform->addElement('html', '<div>');
        $mform->addElement('html', $record->id . ": " . substr($record->hash, 0, 11));
        $mform->addElement('html', '</div>');
        $mform->addElement('html', '<ul class="filter-autotranslate__lang-list mt-2">');
        foreach ($translations as $translation) {
            $btnstatusclass = "btn-light";
            if ($translation->status === "1") {
                $btnstatusclass = "btn-success";
            }
            if ($translation->status === "2") {
                $btnstatusclass = "btn-info";
            }
            $localurlparams = $this->urlparams;
            $localurlparams['target_lang'] = $translation->lang;
            $localurl = new \moodle_url('/filter/autotranslate/manage.php', $localurlparams);
            $mform->addElement('html', '<li class="mr-1 mb-1">');
            $mform->addElement('html', '<a href="' . $localurl->out() .
                '" class="btn btn-sm ' . $btnstatusclass . '">' .
                strtoupper($translation->lang) . '</a>');
            $mform->addElement('html', '</li>');
        }
        $mform->addElement('html', '</ul>');
        $mform->addElement('html', '</div>');

        // Second column.
        $mform->addElement('html', '<div
            class="col-5 filter-autotranslate__source-text"
        >');
        $mform->addElement('html', $record->text);
        $mform->addElement('html', '</div>');

        // Third column.
        $mform->addElement('html', '<div
            class="col-5 filter-autotranslate__target-text ' . $record->target_lang_dir . '"
        >');
        if ($PAGE->user_is_editing()) {
            // Edit mode is on.
            $fieldname = 'translation[' . $record->hash . ']';
            $fieldname2 = 'original[' . $record->hash . ']';
            $ishtml = $this->contains_html($record->text);
            if ($ishtml) {
                $mform->addElement(
                    'editor',
                    $fieldname,
                    null,
                    [
                        'autosave' => false,
                        'removeorphaneddrafts' => true,
                    ]
                )->setValue(['text' => $record->target_text]);
                $mform->setType($fieldname, PARAM_RAW);
            } else {
                $mform->addElement(
                    'textarea',
                    $fieldname,
                    null,
                    [
                        'oninput' => 'this.style.height = "";this.style.height = this.scrollHeight + "px"',
                        'onfocus' => 'this.style.height = "";this.style.height = this.scrollHeight + "px"',
                    ]
                );
                $mform->setDefault($fieldname, $record->target_text);
            }
            $mform->addElement('textarea', $fieldname2, null, ["class" => "d-none"]);
            $mform->setDefault($fieldname2, $record->target_text);
        } else {
            // Edit mode is off.
            $mform->addElement('html', $record->target_text);
        }
        $mform->addElement('html', '</div>');

        // Close translation item.
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
     *
     * @param array $data Form Data
     * @param array $files Uploaded Files
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

    /**
     * Find Object by Key Value
     *
     * @param array $array Array of objects
     * @param string $key Key to reference
     * @param string $value to search for
     * @return Object that matches
     */
    private function findobjectbykeyvalue($array, $key, $value) {
        $filteredarray = array_filter($array, function ($object) use ($key, $value) {
            return $object->{$key} === $value;
        });

        // If array_filter finds a match, return the first element; otherwise, return null.
        return reset($filteredarray) ?: null;
    }

    /**
     * Detect if string has html
     *
     * @param string $string String to check
     * @return boolean If html was detected
     */
    private function contains_html($string) {
        // Strip HTML and PHP tags from the input string.
        $strippedstring = strip_tags($string);

        // Compare the original and stripped strings.
        if ($string !== $strippedstring) {
            return true; // String contains HTML or PHP.
        } else {
            return false; // String does not contain HTML or PHP.
        }
    }
}
