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

use renderable;
use renderer_base;
use templatable;
use stdClass;
use Punic\Data;
use filter_autotranslate\output\manage_form;

require_once(dirname(__DIR__, 2) . "/vendor/autoload.php");

/**
 * Autotranslate Manage Page Output
 *
 * Provides output class for /filter/autotranslate/manage.php
 *
 * @package    filter_autotranslate
 * @copyright  2024 Kaleb Heitzman <kaleb@jamfire.io>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manage_page implements renderable, templatable {

    /**
     * Constructor
     *
     */
    public function __construct() {
        global $DB;

        $this->site_lang = get_config('core', 'lang');
        $this->langs = get_string_manager()->get_list_of_translations();
        $this->source_lang = optional_param('source_lang', $this->site_lang, PARAM_NOTAGS);
        $this->target_lang = optional_param('target_lang', $this->site_lang, PARAM_NOTAGS);

        $offset = 0;
        $rowcount = 10;
        $source_records = $DB->get_records("filter_autotranslate", array("lang" => $this->source_lang), '', '*', $offset, $rowcount);

        if (!$source_records) {
            $this->mform = null;
            return;
        }

        $target_hashes = [];
        foreach ($source_records as $source_record) {
            array_push($target_hashes, $source_record->hash);
        }

        // Assuming your table is named 'filter_autotranslate'
        $table = 'filter_autotranslate';
        $column = 'hash';

        // Construct the conditions
        $conditions = array(
            $column . ' IN (' . join(',', array_fill(0, count($target_hashes), '?')) . ')',
            'lang = ?', // Additional conditions can be added here
        );

        // Add target_lang value to conditions
        $values = array_merge($target_hashes, array($this->target_lang));

        // Construct the SQL query
        $sql = "SELECT * FROM {" . $table . "} WHERE " . join(' AND ', $conditions);

        // Get the target records
        $target_records = $DB->get_records_sql($sql, $values, $offset, $rowcount);

        // target language direction
        $this->target_lang_dir = $this->getCharacterOrder($this->target_lang);

        // Moodle Form.
        $mform = new manage_form(null, [
            'source_records' => $source_records,
            'target_records' => $target_records,
            'source_lang' => $this->source_lang,
            'target_lang' => $this->target_lang,
            'lang_dir' => $this->target_lang_dir
        ]);
        $this->mform = $mform;
    }

    /**
     * Export Data to Template
     *
     * @param renderer_base $output
     * @return object
     */
    public function export_for_template(renderer_base $output) {
        global $DB;
        $data = new stdClass();

        // site languages available
        $langs = [];
        foreach ($this->langs as $key => $lang) {
            array_push($langs, array(
                'code' => $key,
                'lang' => $lang,
                'target-btn-class' => $this->target_lang === $key ? "btn-dark" : "btn-light",
                'source-btn-class' => $this->source_lang === $key ? "btn-dark" : "btn-light"
            ));
        }
        $data->langs = $langs;
        $data->source_lang = $this->langs[$this->source_lang];
        $data->target_lang = $this->langs[$this->target_lang];
        $data->source_lang_code = $this->source_lang;
        $data->target_lang_code = $this->target_lang;
        $data->target_lang_dir = $this->target_lang_dir;

        // manage page url
        // $url = new \moodle_url('/filter/autotranslate/manage.php');
        // $data->url = $url->out();

        if ($this->mform) {
            if ($this->mform->is_cancelled()) {
                // If there is a cancel element on the form, and it was pressed,
                // then the `is_cancelled()` function will return true.
                // You can handle the cancel operation here.
            } else if ($fromform = $this->mform->get_data()) {
                // When the form is submitted, and the data is successfully validated,
                // the `get_data()` function will return the data posted in the form.
                $source_lang = $fromform->source_lang;
                $target_lang = $fromform->target_lang;
                $url = new \moodle_url('/filter/autotranslate/manage.php', array(
                    'source_lang' => $fromform->source_lang,
                    'target_lang' => $fromform->target_lang
                ));

                foreach ($fromform->translation as $hash => $item) {
                    if (is_array($item)) {
                        $text = $item['text'];
                    } else {
                        $text = $item;
                    }

                    $record = [];
                    $record['hash'] = $hash;
                    $record['lang'] = $this->target_lang;
                    
                    // try to find existing record to update
                    $translation_record = $DB->get_record('filter_autotranslate', $record, 'id,created_at');

                    // set the rest of record
                    $record['text'] = $text;
                    $record['created_at'] = time();
                    $record['modified_at'] = time();

                    if ($translation_record) {
                        $record['created_at'] = $translation_record->created_at;
                        $record['id'] = $translation_record->id;

                        $DB->update_record('filter_autotranslate', $record);
                    } else if (!$translation_record and !empty($text)) {
                        $source_record = $DB->get_record('filter_autotranslate_ids', array('hash' => $hash, 'lang' => $source_lang));
                        $target_record = $DB->get_record('filter_autotranslate_ids', array('hash' => $hash, 'lang' => $target_lang));
                        $context_record = $DB->get_record('filter_autotranslate_ids', array('hash' => $hash, 'lang' => $target_lang));
                        if ($source_record && !$target_record && !$context_record) {
                            $DB->insert_record(
                                'filter_autotranslate',
                                $record
                            );
                            $DB->insert_record(
                                'filter_autotranslate_ids',
                                array(
                                    'hash' => $hash,
                                    'lang' => $target_lang,
                                    'context_id' => $source_record->context_id
                                )
                            );
                        }
                    }
                }

                // redirect($url);            
            } else {   
            
            }

            // Hacky fix but the only way to adjust html...
            // This could be overridden in css and I might look at that fix for the future.
            $renderedform = $this->mform->render();
            $renderedform = str_replace('col-md-3 col-form-label d-flex pb-0 pr-md-0', 'd-none', $renderedform);
            $renderedform = str_replace('class="col-md-9 form-inline align-items-start felement"', 'class="w-100"', $renderedform);
            $data->mform = $renderedform;
        } else {
            $data->mform = null;
        }

        return $data;
    }

    /**
     * Retrieve the character order (right-to-left or left-to-right).
     *
     * @param string $locale The locale to use. If empty we'll use the default locale set in \Punic\Data
     *
     * @return string Return 'left-to-right' or 'right-to-left'
     */
    private static function getCharacterOrder($locale = '')
    {
        $data = Data::get('layout', $locale);
        return $data['characterOrder'];
    }
}
