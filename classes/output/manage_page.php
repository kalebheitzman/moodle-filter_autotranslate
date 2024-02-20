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

defined('MOODLE_INTERNAL') || die();

// Load the files we're going to need.
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
     * @var string $this->sitelang Default Moodle Language
     */
    private string $sitelang;

    /**
     * @var array $langs Languages supported on the site
     */
    private array $langs;


    /**
     * @var string $source_lange Source language of the text
     */
    private string $sourcelang;

    /**
     * @var string $target_lang Target language of the text
     */
    private string $targetlang;

    /**
     * @var int $instanceid Context instanceid associated with the text
     */
    private null | int $instanceid;

    /**
     * @var int $contextlevel Context  associated with the text
     */
    private null | int $contextlevel;

    /**
     * @var int Current page number
     */
    private int $page;

    /**
     * @var int Limit for query
     */
    private int $limit;

    /**
     * @var int Offset for query
     */
    private int $offset;

    /**
     * @var array $pages Array map of pages for pagination
     */
    private array $pages;

    /**
     * @var ing $status Status of translations to query
     */
    private int $status;

    /**
     * @var string $target_lang_dir Target text rtl or ltr
     */
    private string $targetlangdir;

    /**
     * @var string $urlquery Url query
     */
    private string $urlquery;


    /**
     * @var manage_form $mform Autotranslation management form
     */
    private manage_form $mform;

    /**
     * Constructor
     *
     */
    public function __construct() {
        global $DB;

        // Pagination params.
        $managelimit = get_config('filter_autotranslate', 'managelimit');
        if (!$managelimit) {
            $managelimit = 20;
        }

        // Qury params.
        $this->sitelang = get_config('core', 'lang', PARAM_NOTAGS);
        $this->langs = get_string_manager()->get_list_of_translations();
        $this->sourcelang = optional_param('source_lang', $this->sitelang, PARAM_NOTAGS);
        $this->sourcelang = clean_param($this->sourcelang, PARAM_NOTAGS);
        $this->targetlang = optional_param('target_lang', $this->sitelang, PARAM_NOTAGS);
        $this->targetlang = clean_param($this->targetlang, PARAM_NOTAGS);
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
        if ($this->limit === 0) {
            $this->limit = $managelimit;
        }
        $this->page = max(1, (int)$this->page); // Ensure a valid positive integer for page.
        $this->offset = ($this->page - 1) * $this->limit;
        $this->status = optional_param('status', -1, PARAM_INT);
        $this->status = clean_param($this->status, PARAM_INT);

        // Url params.
        $urlparams = [];
        $urlparams['source_lang'] = $this->sourcelang;
        $urlparams['target_lang'] = $this->targetlang;
        $urlparams['limit'] = $this->limit;
        $urlparams['page'] = $this->page;
        if ($this->instanceid) {
            $urlparams['instanceid'] = $this->instanceid;
        }
        if ($this->contextlevel) {
            $urlparams['contextlevel'] = $this->contextlevel;
        }
        if ($this->status && $this->status !== -1) {
            $urlparams['status'] = $this->status;
        }
        $this->urlquery = http_build_query($urlparams);

        // Build the target params.
        $targetrecords = [];
        if ($this->instanceid || $this->contextlevel) {
            // Construct the SQL query for filter_autotranslate_ctx table.
            $insqlids = "SELECT hash FROM {filter_autotranslate_ctx} WHERE";
            $conditions = [];
            $values = [];

            if ($this->contextlevel !== null && $this->contextlevel >= 10) {
                $conditions[] = 'contextlevel = ?';
                $values[] = $this->contextlevel;
            }

            if ($this->instanceid !== null && $this->instanceid > 0) {
                $conditions[] = 'instanceid = ?';
                $values[] = $this->instanceid;
            }

            $conditions[] = 'lang = ?';
            $values[] = $this->targetlang;

            $insqlids .= " " . implode(' AND ', $conditions);
            $hashes = $DB->get_fieldset_sql($insqlids, $values);

            // No hashes found.
            if (!$hashes) {
                $targetrecords = [];
                $this->pages = [];
            } else {
                // Construct the placeholders for the IN clause.
                $inplaceholders = implode(',', array_fill(0, count($hashes), '?'));

                // Construct the conditions for the IN clause.
                $inconditions = ['hash IN (' . $inplaceholders . ')', 'lang = ?'];

                // Add values for the IN clause.
                $invalues = array_merge($hashes, [$this->targetlang]);

                // Add additional conditions.
                $additionalconditions = [];
                if ($this->status > -1) {
                    $additionalconditions[] = 'status = ?';
                    $invalues[] = $this->status;
                }

                $inconditions = array_merge($inconditions, $additionalconditions);

                // Construct the SQL query for filter_autotranslate table.
                $insql = "SELECT * FROM {filter_autotranslate} WHERE " . implode(' AND ', $inconditions);

                // Combine the values for both queries.
                $values = array_merge($invalues, $invalues);

                // Get target records using pagination.
                $totalpages = ceil(count($hashes) / $this->limit);
                $this->pages = range(1, $totalpages);

                $targetrecords = $DB->get_records_sql($insql . ' ORDER BY id ASC', $values, $this->offset, $this->limit);
            }
        } else {
            // Specify the target params.
            $targetparams = [];
            $targetparams['lang'] = $this->targetlang;
            if ($this->status > -1) {
                $targetparams['status'] = $this->status;
            }

            // Get the total number of target records.
            $targetrecordscount = $DB->count_records("filter_autotranslate", $targetparams);
            $totalpages = ceil($targetrecordscount / $this->limit);
            $this->pages = range(1, $totalpages);

            $targetrecords = $DB->get_records("filter_autotranslate", $targetparams, '', '*', $this->offset, $this->limit);
        }

        // Construct the array of target record hashes.
        $targethashes = array_column($targetrecords, 'hash');
        if (!$targethashes) {
            $sourcerecords = [];
        } else {
            // Construct the placeholders for the IN clause.
            $inplaceholders = implode(',', array_fill(0, count($targethashes), '?'));

            // Construct the conditions for the IN clause.
            $inconditions = ['hash IN (' . $inplaceholders . ')', 'lang = ?'];

            // Add values for the IN clause.
            $invalues = array_merge($targethashes, [$this->sourcelang]);

            // Construct the SQL query for the IN clause.
            $insql = "SELECT * FROM {filter_autotranslate} WHERE " . implode(' AND ', $inconditions);

            // Combine the values for both queries.
            $values = array_merge($invalues, [$this->offset, $this->limit]);

            // Get source records using the IN clause.
            $sourcerecords = $DB->get_records_sql($insql . ' ORDER BY id ASC', $values);
        }

        // Target language direction.
        $this->targetlangdir = $this->getCharacterOrder($this->targetlang);

        // Moodle Form.
        $mform = new manage_form(null, [
            'source_records' => $sourcerecords,
            'target_records' => $targetrecords,
            'source_lang' => $this->sourcelang,
            'target_lang' => $this->targetlang,
            'status' => $this->status,
            'lang_dir' => $this->targetlangdir,
            'pages' => $this->pages,
            'page' => $this->page,
            'limit' => $this->limit,
            'instanceid' => $this->instanceid,
            'contextlevel' => $this->contextlevel,
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

        // Site languages available.
        $langs = [];
        foreach ($this->langs as $key => $lang) {
            array_push($langs, [
                'code' => $key,
                'lang' => $lang,
                'target-btn-class' => $this->targetlang === $key ? "btn-dark" : "btn-light",
                'source-btn-class' => $this->sourcelang === $key ? "btn-dark" : "btn-light",
            ]);
        }
        $data->langs = $langs;
        $data->sourcelang = $this->langs[$this->sourcelang];
        $data->targetlang = $this->langs[$this->targetlang];
        $data->sourcelangcode = $this->sourcelang;
        $data->targetlangcode = $this->targetlang;
        $data->targetlangdir = $this->targetlangdir;
        $data->status = $this->status;
        $data->instanceid = $this->instanceid;
        $data->contextlevel = $this->contextlevel;
        $data->limit = $this->limit;

        if ($this->mform) {
            if ($this->mform->is_cancelled()) {
                // If there is a cancel element on the form, and it was pressed,
                // then the `is_cancelled()` function will return true.
                // You can handle the cancel operation here.
                return;
            } else if ($fromform = $this->mform->get_data()) {
                // When the form is submitted, and the data is successfully validated,
                // the `get_data()` function will return the data posted in the form.
                $sourcelang = $fromform->source_lang;
                $targetlang = $fromform->target_lang;
                $url = new \moodle_url('/filter/autotranslate/manage.php?' . $this->urlquery);

                // Iterate through each translation.
                foreach ($fromform->translation as $hash => $item) {
                    // Get the text based on whether this is plaintext or html.
                    if (is_array($item)) {
                        $text = $item['text'];
                    } else {
                        $text = $item;
                    }

                    // Translation is same as original, skip to next item.
                    if ($fromform->translation[$hash] !== $fromform->original[$hash]) {
                        // Build the initial record and use it to query the db.
                        $record = [];
                        $record['hash'] = $hash;
                        $record['lang'] = $this->targetlang;

                        // Try to find existing target record to update.
                        $targetrecord = $DB->get_record('filter_autotranslate', $record, '*');

                        // Set the rest of record.
                        $record['status'] = $this->sitelang === $this->targetlang ? 2 : 1;
                        $record['text'] = $text;
                        $record['modified_at'] = time();

                        // Calculate the new hash.
                        $md5hash = md5($text);

                        // If the target record uses the same lang as the site_lang
                        // then hashes need updated in filter_autotranslate, filter_autotranslate_ctx
                        // and filter_autotranslate_jobs.
                        if ($record['lang'] === $this->sitelang && $md5hash !== $hash) {
                            // Check to see if an existing hash exists and if so
                            // delete the old hash records.
                            $existingrecord = $DB->get_record(
                                'filter_autotranslate',
                                ['hash' => $md5hash],
                                '*',
                            );

                            // There is an existing record with the same hash already
                            // this will cause duplicates if we don't catch it here.
                            if ($existingrecord) {
                                // Get the filter_autotranslate records.
                                $autotranslaterecords = $DB->get_records(
                                    'filter_autotranslate',
                                    ['hash' => $record['hash']],
                                    '',
                                    'id'
                                );
                                $autotranslaterecordsids = array_column($autotranslaterecords, 'id');

                                // Delete the hash in filter_autotranslate records.
                                if (!empty($autotranslaterecordsids)) {
                                    $DB->delete_records('filter_autotranslate', ['hash' => $record['hash']]);
                                }

                                // Get the filter_autotranslate_ctx records.
                                $autotranslaterecordscontext = $DB->get_records(
                                    'filter_autotranslate_ctx',
                                    ['hash' => $record['hash']],
                                    '',
                                    'id'
                                );
                                $autotranslaterecordscontextids = array_column($autotranslaterecordscontext, 'id');

                                // Delete the hash in filter_autotranslate_ctx.
                                if (!empty($autotranslaterecordscontextids)) {
                                    $DB->delete_records('filter_autotranslate_ctx', ['hash' => $record['hash']]);
                                }

                                // Get the filter_autotranslate_jobs records.
                                $autotranslaterecordsjobs = $DB->get_records(
                                    'filter_autotranslate_jobs',
                                    ['hash' => $record['hash']],
                                    '',
                                    'id'
                                );
                                $autotranslaterecordsjobsids = array_column($autotranslaterecordsjobs, 'id');

                                // Delete the hash in filter_autotranslate_jobs.
                                if (!empty($autotranslaterecordsjobsids)) {
                                    $DB->delete_records('filter_autotranslate_jobs', ['hash' => $record['hash']]);
                                }
                            } else { // Update the hashes after the source text has been updated.
                                $record['id'] = $targetrecord->id;
                                $DB->update_record('filter_autotranslate', $record);

                                // Get the filter_autotranslate records.
                                $autotranslaterecords = $DB->get_records(
                                    'filter_autotranslate',
                                    ['hash' => $record['hash']],
                                    '',
                                    'id'
                                );
                                $autotranslaterecordsids = array_column($autotranslaterecords, 'id');

                                // Update the hash in filter_autotranslate records.
                                if (!empty($autotranslaterecordsids)) {
                                    $idsplaceholder = implode(',', array_fill(0, count($autotranslaterecordsids), '?'));

                                    // Update the hash field with the new hash value.
                                    $sql = "UPDATE {filter_autotranslate}
                                            SET hash = ?
                                            WHERE id IN ($idsplaceholder)";

                                    $params = array_merge([$md5hash], $autotranslaterecordsids);

                                    $DB->execute($sql, $params);
                                }

                                // Get the filter_autotranslate_ctx records.
                                $autotranslaterecordscontext = $DB->get_records(
                                    'filter_autotranslate_ctx',
                                    ['hash' => $record['hash']],
                                    '',
                                    'id'
                                );
                                $autotranslaterecordscontextids = array_column($autotranslaterecordscontext, 'id');

                                // Update the hash in filter_autotranslate_ctx.
                                if (!empty($autotranslaterecordscontextids)) {
                                    $idsplaceholder = implode(',', array_fill(0, count($autotranslaterecordscontextids), '?'));

                                    // Update the hash field with the new hash value.
                                    $sql = "UPDATE {filter_autotranslate_ctx}
                                            SET hash = ?
                                            WHERE id IN ($idsplaceholder)";

                                    $params = array_merge([$md5hash], $autotranslaterecordscontextids);

                                    $DB->execute($sql, $params);
                                }

                                // Get the filter_autotranslate_jobs records.
                                $autotranslaterecordsjobs = $DB->get_records(
                                    'filter_autotranslate_jobs',
                                    ['hash' => $record['hash']],
                                    '',
                                    'id'
                                );
                                $autotranslaterecordsjobsids = array_column($autotranslaterecordsjobs, 'id');

                                // Update the hash in filter_autotranslate_jobs.
                                if (!empty($autotranslaterecordsjobsids)) {
                                    $idsplaceholder = implode(',', array_fill(0, count($autotranslaterecordsjobsids), '?'));

                                    // Update the hash field with the new hash value.
                                    $sql = "UPDATE {filter_autotranslate_jobs}
                                            SET hash = ?
                                            WHERE id IN ($idsplaceholder)";

                                    $params = array_merge([$md5hash], $autotranslaterecordsjobsids);

                                    $DB->execute($sql, $params);
                                }
                            }
                        } else {
                            $record['id'] = $targetrecord->id;
                            $record['created_at'] = $targetrecord->created_at;

                            $DB->update_record('filter_autotranslate', $record);
                        }
                    } // end translation found
                }
                // Fix this: I don't like the way this works
                // I need to figure out how to update records before the point for
                // the source column.
                redirect($url);
            }

            // Can this be fiexed? Hacky fix but the only way to adjust html...
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
    private static function getcharacterorder($locale = '') {
        $data = Data::get('layout', $locale);
        return $data['characterOrder'];
    }
}
