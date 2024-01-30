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

require_once(dirname(__DIR__, 2) . "/vendor/autoload.php");

use renderable;
use renderer_base;
use templatable;
use stdClass;
use Punic\Data;
use filter_autotranslate\output\manage_form;

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
        $this->page = optional_param('page', 1, PARAM_NOTAGS);

        // Pagination params
        $limit = 10;
        $this->page = max(1, (int)$this->page); // Ensure a valid positive integer for page
        $offset = ($this->page - 1) * $limit;

        // Get the total number of target records
        $target_records_count = $DB->count_records("filter_autotranslate", array("lang" => $this->target_lang));
        $total_pages = ceil($target_records_count / $limit);
        $this->pages = range(1, $total_pages);

        // Get target records using pagination
        $target_records = $DB->get_records("filter_autotranslate", array("lang" => $this->target_lang), '', '*', $offset, $limit);

        // Check if there are no target records
        if (!$target_records) {
            $this->mform = null;
            return;
        }

        // Construct the array of target record hashes
        $target_hashes = array_column($target_records, 'hash');

        // Construct the placeholders for the IN clause
        $in_placeholders = implode(',', array_fill(0, count($target_hashes), '?'));

        // Construct the conditions for the IN clause
        $in_conditions = array('hash IN (' . $in_placeholders . ')', 'lang = ?');

        // Add values for the IN clause
        $in_values = array_merge($target_hashes, array($this->source_lang));

        // Construct the SQL query for the IN clause
        $in_sql = "SELECT * FROM {filter_autotranslate} WHERE " . implode(' AND ', $in_conditions);

        // Add placeholders for the LIMIT clause
        $limit_conditions = array('lang = ?');
        $limit_values = array($this->source_lang);

        // Construct the SQL query for the LIMIT clause
        $limit_sql = "SELECT * FROM {filter_autotranslate} WHERE " . implode(' AND ', $limit_conditions) . " LIMIT ?, ?";

        // Combine the values for both queries
        $values = array_merge($in_values, array($offset, $limit));

        // Get source records using the IN clause
        $source_records = $DB->get_records_sql($in_sql . ' ORDER BY id ASC', $values);

        // target language direction
        $this->target_lang_dir = $this->getCharacterOrder($this->target_lang);

        // Moodle Form.
        $mform = new manage_form(null, [
            'source_records' => $source_records,
            'target_records' => $target_records,
            'source_lang' => $this->source_lang,
            'target_lang' => $this->target_lang,
            'lang_dir' => $this->target_lang_dir,
            'pages' => $this->pages,
            'page' => $this->page
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
                    'target_lang' => $fromform->target_lang,
                    'page' => $this->page
                ));

                // iterate through each translation
                foreach ($fromform->translation as $hash => $item) {
                    // get the text based on whether this is plaintext or html
                    if (is_array($item)) {
                        $text = $item['text'];
                    } else {
                        $text = $item;
                    }

                    // build the initial record and use it to query the db
                    $record = [];
                    $record['hash'] = $hash;
                    $record['lang'] = $this->target_lang;
                    
                    // try to find existing target record to update
                    $target_record = $DB->get_record('filter_autotranslate', $record, 'id,created_at');

                    // set the rest of record
                    $record['text'] = $text;
                    $record['created_at'] = time();
                    $record['modified_at'] = time();

                    // update the target record if it exists
                    if ($target_record) {
                        $record['created_at'] = $target_record->created_at;
                        $record['id'] = $target_record->id;

                        $DB->update_record('filter_autotranslate', $record);
                    } 


                    // calculate the new hash
                    $md5hash = md5($record['text']);

                    // if the target record uses the same lang as the site_lang
                    // then hashes need updated in filter_autotranslate, filter_autotranslate_ids
                    // and filter_autotranslate_jobs
                    if ($record['lang'] === $this->site_lang && $md5hash !== $record['hash']) {

                        // get the filter_autotranslate records
                        $autotranslate_records = $DB->get_records(
                            'filter_autotranslate', 
                            array('hash' => $record['hash']),
                            '',
                            'id'
                        );
                        $autotranslate_records_ids = array_column($autotranslate_records, 'id');

                        // update the hash in filter_autotranslate records
                        if (!empty($autotranslate_records_ids)) {
                            $ids_placeholder = implode(',', array_fill(0, count($autotranslate_records_ids), '?'));

                            // Update the hash field with the new hash value
                            $sql = "UPDATE {filter_autotranslate}
                                    SET hash = ?
                                    WHERE id IN ($ids_placeholder)";
                            
                            $params = array_merge([$md5hash], $autotranslate_records_ids);
                            
                            $DB->execute($sql, $params);
                        }

                        // get the filter_autotranslate_ids records
                        $autotranslate_records_context = $DB->get_records(
                            'filter_autotranslate_ids', 
                            array('hash' => $record['hash']),
                            '',
                            'id'
                        );
                        $autotranslate_records_context_ids = array_column($autotranslate_records_context, 'id');

                        // update the hash in filter_autotranslate_ids
                        if (!empty($autotranslate_records_context_ids)) {
                            $ids_placeholder = implode(',', array_fill(0, count($autotranslate_records_context_ids), '?'));

                            // Update the hash field with the new hash value
                            $sql = "UPDATE {filter_autotranslate_ids}
                                    SET hash = ?
                                    WHERE id IN ($ids_placeholder)";
                            
                            $params = array_merge([$md5hash], $autotranslate_records_context_ids);
                            
                            $DB->execute($sql, $params);
                        }

                        // get the filter_autotranslate_jobs records
                        $autotranslate_records_jobs = $DB->get_records(
                            'filter_autotranslate_jobs', 
                            array('hash' => $record['hash']),
                            '',
                            'id'
                        );
                        $autotranslate_records_jobs_ids = array_column($autotranslate_records_jobs, 'id');

                        // update the hash in filter_autotranslate_jobs
                        if (!empty($autotranslate_records_jobs_ids)) {
                            $ids_placeholder = implode(',', array_fill(0, count($autotranslate_records_jobs_ids), '?'));

                            // Update the hash field with the new hash value
                            $sql = "UPDATE {filter_autotranslate_jobs}
                                    SET hash = ?
                                    WHERE id IN ($ids_placeholder)";
                            
                            $params = array_merge([$md5hash], $autotranslate_records_jobs_ids);
                            
                            $DB->execute($sql, $params);
                        }                        
                    }
                }

                // @todo: I don't like the way this works
                // I need to figure out how to update records before the point for 
                // the source column
                redirect($url);            
            } else {   
            
            }

            // @todo: Hacky fix but the only way to adjust html...
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
