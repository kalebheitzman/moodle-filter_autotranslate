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

        $this->langs = get_string_manager()->get_list_of_translations();
        $this->current_lang = optional_param('current_lang', 'en', PARAM_NOTAGS);
        $this->target_lang = optional_param('target_lang', 'en', PARAM_NOTAGS);

        $offset = 0;
        $rowcount = 10;
        $source_records = $DB->get_records("filter_autotranslate", array("lang" => $this->current_lang), '', '*', $offset, $rowcount);
        $target_records = $DB->get_records("filter_autotranslate", array("lang" => $this->target_lang), '', '*', $offset, $rowcount);

        // $this->course = $course;
        // $this->coursedata = $coursedata;
        // $this->langs = get_string_manager()->get_list_of_translations();
        // $this->langs['other'] = get_string('t_other', 'filter_autotranslate');
        // $this->current_lang = optional_param('course_lang', 'other', PARAM_NOTAGS);
        // $this->mlangfilter = $mlangfilter;

        // target language direction
        $this->target_lang_dir = $this->getCharacterOrder($this->target_lang);

        // Moodle Form.
        $mform = new manage_form(null, [
            'source_records' => $source_records,
            'target_records' => $target_records,
            'lang_dir' => $this->target_lang_dir
        ]);
        // $renderedform = str_replace('col-md-3 col-form-label d-flex pb-0 pr-md-0', 'd-none', $renderedform);
        // $renderedform = str_replace('class="col-md-9 form-inline align-items-start felement"', '', $renderedform);
        $this->mform = $mform;
    }

    /**
     * Export Data to Template
     *
     * @param renderer_base $output
     * @return object
     */
    public function export_for_template(renderer_base $output) {
        $data = new stdClass();

        
        // var_dump($data);

        // site languages available
        $langs = [];
        foreach ($this->langs as $key => $lang) {
            array_push($langs, array(
                'code' => $key,
                'lang' => $lang,
                'btn-class' => $this->target_lang === $key ? "btn-dark" : "btn-light"
            ));
        }
        $data->langs = $langs;
        $data->source_lang = $this->langs[$this->current_lang];
        $data->target_lang = $this->langs[$this->target_lang];
        $data->target_lang_dir = $this->target_lang_dir;

        // manage page url
        $url = new \moodle_url('/filter/autotranslate/manage.php');
        $data->url = $url->out();

        // var_dump($data);

        // Hacky fix but the only way to adjust html...
        // This could be overridden in css and I might look at that fix for the future.
        $renderedform = $this->mform->render();
        $renderedform = str_replace('col-md-3 col-form-label d-flex pb-0 pr-md-0', 'd-none', $renderedform);
        $renderedform = str_replace('class="col-md-9 form-inline align-items-start felement"', 'class="w-100"', $renderedform);
        $data->mform = $renderedform;

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
