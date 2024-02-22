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
use filter_autotranslate\output\glossary_form;
use filter_autotranslate\output\add_term_form;
use filter_autotranslate\autotranslate\translator;

/**
 * Autotranslate Glossary Page Output
 *
 * Provides output class for /filter/autotranslate/glossary.php
 *
 * @package    filter_autotranslate
 * @copyright  2024 Kaleb Heitzman <kaleb@jamfire.io>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class glossary_page implements renderable, templatable {
    /**
     * @var string $sitelang Default Moodle Language
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
     * @var string $target_lang_dir Target text rtl or ltr
     */
    private string $targetlangdir;

    /**
     * @var string $urlquery Url query
     */
    private string $urlquery;

    /**
     * @var glossary_form $mform Autotranslation glossary form
     */
    private glossary_form $mform;

    /**
     * @var add_term_form $tform Autotranslation add term form
     */
    private add_term_form $tform;

    /**
     * Constructor
     *
     */
    public function __construct() {
        global $DB;

        // Pagination params.
        $glossarylimit = get_config('filter_autotranslate', 'glossarylimit');
        if (!$glossarylimit) {
            $glossarylimit = 20;
        }

        // Translation API.
        $translator = new translator();

        // Qury params.
        $this->sitelang = get_config('core', 'lang', PARAM_NOTAGS);
        $this->langs = $translator->getsupportedglossarylangs();
        $this->sourcelang = optional_param('source_lang', $this->sitelang, PARAM_NOTAGS);
        $this->sourcelang = clean_param($this->sourcelang, PARAM_NOTAGS);
        $this->targetlang = optional_param('target_lang', $this->sitelang, PARAM_NOTAGS);
        $this->targetlang = clean_param($this->targetlang, PARAM_NOTAGS);
        $this->page = optional_param('page', 1, PARAM_INT);
        $this->page = clean_param($this->page, PARAM_INT);
        $this->limit = optional_param('limit', $glossarylimit, PARAM_INT);
        $this->limit = clean_param($this->limit, PARAM_INT);
        if ($this->limit === 0) {
            $this->limit = $glossarylimit;
        }
        $this->page = max(1, (int)$this->page); // Ensure a valid positive integer for page.
        $this->offset = ($this->page - 1) * $this->limit;

        // Target language direction.
        $this->targetlangdir = $this->getCharacterOrder($this->targetlang);

        $this->pages = [1];

        // Url params.
        $urlparams = [];
        $urlparams['source_lang'] = $this->sourcelang;
        $urlparams['target_lang'] = $this->targetlang;
        $urlparams['limit'] = $this->limit;
        $urlparams['page'] = $this->page;
        $this->urlquery = http_build_query($urlparams);

        // Source records.
        $sourcerecords = $DB->get_records(
            'filter_autotranslate_gterms',
            [ "lang" => $this->sitelang ],
            'id ASC',
            "*",
            $this->offset,
            $this->limit
        );

        // Get the hashes.
        $hashes = array_column($sourcerecords, 'hash');

        if (!$hashes) {
            $targetrecords = [];
        } else {
            // Prepare the array of hashes for the SQL query.
            $placeholders = implode(',', array_fill(0, count($hashes), '?'));

            // Construct the SQL query with a prepared statement.
            $sql = "SELECT * FROM {filter_autotranslate_gterms} WHERE hash IN ($placeholders) AND lang = ?";

            // Append the locale code to the array of hashes.
            $hashes[] = $this->targetlang;

            // Execute the prepared statement.
            $targetrecords = $DB->get_records_sql($sql, $hashes);
        }

        // Moodle Form.
        $mform = new glossary_form(null, [
            'source_records' => $sourcerecords,
            'target_records' => $targetrecords,
            'source_lang' => $this->sourcelang,
            'target_lang' => $this->targetlang,
            'lang_dir' => $this->targetlangdir,
            'pages' => $this->pages,
            'page' => $this->page,
            'limit' => $this->limit,
        ]);
        $this->mform = $mform;

        // Term form.
        $tform = new add_term_form(null, [
            'source_lang' => $this->sourcelang,
            'target_lang' => $this->targetlang,
            'lang_dir' => $this->targetlangdir,
            'page' => $this->page,
            'limit' => $this->limit,
        ]);
        $this->tform = $tform;
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
        $data->sitelang = $this->langs[$this->sitelang];
        $data->sourcelang = $this->langs[$this->sourcelang];
        $data->targetlang = $this->langs[$this->targetlang];
        $data->sitelangcode = $this->sitelang;
        $data->sourcelangcode = $this->sourcelang;
        $data->targetlangcode = $this->targetlang;
        $data->targetlangdir = $this->targetlangdir;
        $data->limit = $this->limit;

        // Redirect url.
        $url = new \moodle_url('/filter/autotranslate/glossary.php?' . $this->urlquery);

        // Can this be fiexed? Hacky fix but the only way to adjust html...
        // This could be overridden in css and I might look at that fix for the future.
        $renderedform = $this->mform->render();
        $renderedform = str_replace('col-md-3 col-form-label d-flex pb-0 pr-md-0', 'd-none', $renderedform);
        $renderedform = str_replace('class="col-md-9 form-inline align-items-start felement"', 'class="w-100"', $renderedform);
        $data->mform = $renderedform;

        if ($this->mform) {
            if ($this->mform->is_cancelled()) {
                // If there is a cancel element on the form, and it was pressed,
                // then the `is_cancelled()` function will return true.
                // You can handle the cancel operation here.
                return;
            } else if ($fromform = $this->mform->get_data()) {
                // When the form is submitted, and the data is successfully validated,
                // the `get_data()` function will return the data posted in the form.
                foreach ($fromform->translation as $hash => $translation) {
                    // If the target language is the same as the site language
                    // we are simply updating the record and need to update all
                    // of the existing hashes if the term has changed.
                    $hashcheck = md5($translation);
                    if ($hashcheck !== $hash && $this->sitelang === $fromform->target_lang) {
                        // Set the new text.
                        $DB->set_field(
                            'filter_autotranslate_gterms',
                            'text',
                            $translation,
                            ['hash' => $hash, 'lang' => $fromform->target_lang]
                        );

                        // Update all existing hashes.
                        $DB->set_field(
                            'filter_autotranslate_gterms',
                            'hash',
                            $hashcheck,
                            ['hash' => $hash]
                        );

                        // Update modified times.
                        $DB->set_field(
                            'filter_autotranslate_gterms',
                            'modified_at',
                            time(),
                            ['hash' => $hashcheck]
                        );
                    } else if ($this->sitelang !== $fromform->target_lang && !empty($translation)) {
                        // Get the glossary term.
                        $glossaryrecord = $DB->get_record(
                            'filter_autotranslate_gterms',
                            ['hash' => $hash, 'lang' => $fromform->target_lang]
                        );

                        // If it does not exist, create it.
                        if (!$glossaryrecord) {
                            $DB->insert_record(
                                'filter_autotranslate_gterms',
                                [
                                    'hash' => $hash,
                                    'lang' => $fromform->target_lang,
                                    'text' => $translation,
                                    'created_at' => time(),
                                    'modified_at' => time(),
                                ]
                            );
                        } else if (!empty($translation) && $glossaryrecord->text !== $translation) {
                            // Submitted translation does not equal the saved translation.
                            $DB->update_record(
                                'filter_autotranslate_gterms',
                                [
                                    'id' => $glossaryrecord->id,
                                    'hash' => $hash,
                                    'text' => $translation,
                                    'created_at' => $glossaryrecord->created_at,
                                    'modified_at' => time(),
                                ]
                            );
                        }
                    }
                }

                // Fix this: I don't like the way this works
                // I need to figure out how to update records before the point for
                // the source column.
                redirect($url);
            }
        } else {
            $data->mform = null;
        }

        // Can this be fixed? Hacky fix but the only way to adjust html...
        // This could be overridden in css and I might look at that fix for the future.
        // Add the term form.
        $tformrendered = $this->tform->render();
        $tformrendered = str_replace('col-md-3 col-form-label d-flex pb-0 pr-md-0', 'd-none', $tformrendered);
        $tformrendered = str_replace(
            'class="col-md-9 form-inline align-items-start felement"',
            'class="w-100"',
            $tformrendered
        );
        $data->tform = $tformrendered;

        if ($this->tform) {
            if ($this->tform->is_cancelled()) {
                // If there is a cancel element on the form, and it was pressed,
                // then the `is_cancelled()` function will return true.
                // You can handle the cancel operation here.
                return;
            } else if ($this->tform->no_submit_button_pressed()) {
                // If you have a no-submit button on your form, then you can handle that action here.
                // Create a sync job for the current glossary.
                $name = "glossary-{$this->sourcelang}_{$this->targetlang}";

                // Get existing glossary record.
                $glossary = $DB->get_record(
                    'filter_autotranslate_gids',
                    ['name' => $name]
                );

                // No glossary found, add new glossary record.
                if (!$glossary) {
                    $glossarydata = [
                        'name' => $name,
                        'ready' => 0,
                        'source_lang' => $this->sourcelang,
                        'target_lang' => $this->targetlang,
                        'created_at' => time(),
                        'modified_at' => time(),
                        'last_sync' => 0,
                        'entry_count' => 0,
                    ];

                    $DB->insert_record('filter_autotranslate_gids', $glossarydata);
                } else {
                    // Glossary record found, but it needs rebuilt.
                    $DB->set_field(
                        'filter_autotranslate_gids',
                        'modified_at',
                        time(),
                        ['name' => $name]
                    );
                }

                // Check to see if the glossary glossary exists.

                // Create the glossary glossary if it doesn't exist.
            } else if ($fromform = $this->tform->get_data()) {
                // When the form is submitted, and the data is successfully validated,
                // the `get_data()` function will return the data posted in the form.
                if ($fromform->term) {
                    // Calculate the new hash.
                    $md5hash = md5($fromform->term);

                    $recordexists = $DB->get_record(
                        'filter_autotranslate_gterms',
                        ['hash' => $md5hash, 'lang' => $this->sitelang]
                    );

                    if (!$recordexists) {
                        $DB->insert_record(
                            'filter_autotranslate_gterms',
                            [
                                'text' => $fromform->term,
                                'hash' => $md5hash,
                                'lang' => $this->sitelang,
                                'created_at' => time(),
                                'modified_at' => time(),
                            ]
                        );
                    }
                }

                // Fix this: I don't like the way this works
                // I need to figure out how to update records before the point for
                // the source column.
                redirect($url);
            }
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
