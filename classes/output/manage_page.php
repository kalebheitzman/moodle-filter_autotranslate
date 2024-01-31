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
     * @param string $site_lang Default Moodle Language
     */
    private string $site_lang;

    /**
     * @param array $langs Languages supported on the site
     */
    private array $langs;


    /**
     * @param string $source_lange Source language of the text
     */
    private string $source_lang;

    /**
     * @param string $target_lang Target language of the text
     */
    private string $target_lang;

    /**
     * @param int $instanceid Context instanceid associated with the text
     */
    private null | int $instanceid;

    /**
     * @param int $contextlevel Context  associated with the text
     */
    private null | int $contextlevel;

    /**
     * @param int Current page number
     */
    private int $page;

    /**
     * @param int Limit for query
     */
    private int $limit;

    /**
     * @param int Offset for query
     */
    private int $offset;

    /**
     * @param array $pages Array map of pages for pagination
     */
    private array $pages;

    /**
     * @param string Target text rtl or ltr
     */
    private string $target_lang_dir;

    /**
     * @param manage_form $mform Autotranslation management form
     */
    private manage_form $mform;

    /**
     * Constructor
     *
     */
    public function __construct() {
        global $DB;

        // Pagination params
        $managelimit = get_config('filter_autotranslate', 'managelimit');
        if (!$managelimit) {
            $managelimit = 20;
        }

        // get params
        $this->site_lang = get_config('core', 'lang', PARAM_NOTAGS);
        $this->langs = get_string_manager()->get_list_of_translations();
        $this->source_lang = optional_param('source_lang', $this->site_lang, PARAM_NOTAGS);
        $this->source_lang = clean_param($this->source_lang, PARAM_NOTAGS);
        $this->target_lang = optional_param('target_lang', $this->site_lang, PARAM_NOTAGS);
        $this->target_lang = clean_param($this->target_lang, PARAM_NOTAGS);
        $this->instanceid = optional_param('instanceid', null, PARAM_INT);
        if ($this->instanceid !== null) {
            $this->instanceid = clean_param($this->instanceid, PARAM_INT);
        }
        $this->contextlevel = optional_param('contextlevel', null, PARAM_INT);
        if ($this->contextlevel !== null) {
            $this->contextlevel = clean_param($this->contextlevel, PARAM_INT);
        }
        $this->page = optional_param('page', 1, PARAM_INT);
        $this->page = clean_param($this->page, PARAM_INT);
        $this->limit = optional_param('limit', $managelimit, PARAM_INT);
        $this->limit = clean_param($this->limit, PARAM_INT);
        $this->page = max(1, (int)$this->page); // Ensure a valid positive integer for page
        $this->offset = ($this->page - 1) * $this->limit;

        // build the target params
        $target_records = [];
        if ($this->instanceid || $this->contextlevel) {
            // Construct the SQL query for filter_autotranslate_ids table
            $in_sql_ids = "SELECT hash FROM {filter_autotranslate_ids} WHERE";
            $conditions = array();
            $values = array();

            if ($this->contextlevel !== null) {
                $conditions[] = 'contextlevel = ?';
                $values[] = $this->contextlevel;
            }

            if ($this->instanceid !== null) {
                $conditions[] = 'instanceid = ?';
                $values[] = $this->instanceid;
            }

            $conditions[] = 'lang = ?';
            $values[] = $this->target_lang;

            $in_sql_ids .= " " . implode(' AND ', $conditions);
            $hashes = $DB->get_fieldset_sql($in_sql_ids, $values);

            // Construct the placeholders for the IN clause
            $in_placeholders = implode(',', array_fill(0, count($hashes), '?'));

            // Construct the conditions for the IN clause
            $in_conditions = array('hash IN (' . $in_placeholders . ')', 'lang = ?');

            // Add values for the IN clause
            $in_values = array_merge($hashes, array($this->target_lang));

            // Construct the SQL query for filter_autotranslate table
            $in_sql = "SELECT * FROM {filter_autotranslate} WHERE " . implode(' AND ', $in_conditions);

            // Add placeholders for the LIMIT clause
            $limit_conditions = array('lang = ?');
            $limit_values = array($this->target_lang);

            // Construct the SQL query for the LIMIT clause
            $limit_sql = "SELECT * FROM {filter_autotranslate} WHERE " . implode(' AND ', $limit_conditions);
            $limit_sql = "SELECT * FROM {filter_autotranslate} WHERE " . implode(' AND ', $limit_conditions) . " LIMIT ?, ?";

            // Combine the values for both queries
            $values = array_merge($in_values, $in_values);

            // Get target records using pagination
            $total_pages = ceil(count($hashes) / $this->limit);
            $this->pages = range(1, $total_pages);

            $target_records = $DB->get_records_sql($in_sql . ' ORDER BY id ASC', $values);
        } else {
            // Get the total number of target records
            $target_records_count = $DB->count_records("filter_autotranslate", array("lang" => $this->target_lang));
            $total_pages = ceil($target_records_count / $this->limit);
            $this->pages = range(1, $total_pages);

            $target_parms = [];
            $target_params['lang'] = $this->target_lang;
            $target_records = $DB->get_records("filter_autotranslate", $target_params, '', '*', $this->offset, $this->limit);
        }

        // Construct the array of target record hashes
        $target_hashes = array_column($target_records, 'hash');
        if (!$target_hashes) {
            $source_records = [];
        } else {

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
            $values = array_merge($in_values, array($this->offset, $this->limit));

            // Get source records using the IN clause
            $source_records = $DB->get_records_sql($in_sql . ' ORDER BY id ASC', $values);
        }

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
            'page' => $this->page,
            'limit' => $this->limit
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
                    'page' => $this->page,
                    'limit' => $this->limit
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
     * @return string Return 'left-to-right' or 'right-to-left'
     */
    private static function getCharacterOrder($locale = '')
    {
        $data = Data::get('layout', $locale);
        return $data['characterOrder'];
    }
}
