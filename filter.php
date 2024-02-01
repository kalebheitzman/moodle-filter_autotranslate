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

/**
 * Multi-language content filter, with simplified syntax.
 *
 * @package    filter_autotranslate
 * @copyright  2024 Kaleb Heitzman <kaleb@jamfire.io>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

global $CFG;

// get libs
require_once(dirname(__DIR__, 2) . '/config.php');
require_once($CFG->libdir . '/filterlib.php');
require_once(__DIR__ . "/vendor/autoload.php");

/**
 * Autotranslate current language if not system default
 *
 * The way the filter works is as follows:
 *
 * @package    filter_autotranslate
 * @copyright  2024 Kaleb Heitzman <kaleb@jamfire.io>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class filter_autotranslate extends moodle_text_filter {

    /**
     * Setup page with filter requirements and other prepare stuff.
     *
     * Override this method if the filter needs to setup page
     * requirements or needs other stuff to be executed.
     *
     * Note this method is invoked from {@see setup_page_for_filters()}
     * for each piece of text being filtered, so it is responsible
     * for controlling its own execution cardinality.
     *
     * @param moodle_page $page the page we are going to add requirements to.
     * @param context $context the context which contents are going to be filtered.
     * @since Moodle 2.3
     */
    public function setup($page, $context) {
        // Override me, if needed.
    }

    /**
     * Filter text before changing format to HTML.
     *
     * @param string $text
     * @param array $options
     * @return string
     */
    public function filter_stage_pre_format(string $text, array $options): string {
        // NOTE: override if necessary.
        return $text;
    }

    /**
     * Filter HTML text before sanitising text.
     *
     * NOTE: this is called even if $options['noclean'] is true and text is not cleaned.
     *
     * @param string $text
     * @param array $options
     * @return string
     */
    public function filter_stage_pre_clean(string $text, array $options): string {
        // NOTE: override if necessary.
        return $text;
    }

    /**
     * Filter HTML text at the very end after text is sanitised.
     *
     * NOTE: this is called even if $options['noclean'] is true and text is not cleaned.
     *
     * @param string $text
     * @param array $options
     * @return string
     */
    public function filter_stage_post_clean(string $text, array $options): string {
        // NOTE: override if necessary.
        return $this->filter($text, $options);
    }

    /**
     * Filter simple text coming from format_string().
     *
     * Note that unless $CFG->formatstringstriptags is disabled
     * HTML tags are not expected in returned value.
     *
     * @param string $text
     * @param array $options
     * @return string
     */
    public function filter_stage_string(string $text, array $options): string {
        // NOTE: override if necessary.
        return $this->filter($text, $options);
    }

    /**
     * This function filters the received text based on the language
     * tags embedded in the text, and the current user language or
     * 'other', if present.
     *
     * @param string $text The text to filter.
     * @param array $options The filter options.
     * @return string The filtered text for this multilang block.
     */
    public function filter($text, array $options = array()): string {
        // access constants
        global $DB;
        global $PAGE;
        global $CFG;

        echo "<pre>";
        var_dump($this->mlangparser($text));
        echo "</pre>";

        // var_dump($manager);
        // $enabled_filters = \filter_manager::get_text_filters();
        // var_dump($enabled_filters);

        // Define URLs of pages to exempt
        $exempted_pages = array(
            $CFG->wwwroot . '/filter/autotranslate/manage.php',
            $CFG->wwwroot . '/filter/autotranslate/glossary.php',
            // Add more exempted page URLs as needed
        );

        // Check if the current page URL is in the exempted list
        if (in_array($PAGE->url, $exempted_pages)) {
            // Apply your filter logic here for non-exempted pages
            return $text;
        }

        // only translate context that are equal or greater than 40
        // @see https://docs.moodle.org/403/en/Context
        $selectctx = explode(",", get_config('filter_autotranslate', 'selectctx'));
        if (!in_array(strval($this->context->contextlevel), $selectctx)) {
            return $text;
        }

        // check editing mode
        $editing = $PAGE->user_is_editing();

        // language settings
        $site_lang = get_config('core', 'lang');
        $current_lang = current_language();

        // generate the md5 hash of the current text
        $hash = md5($text);

        // get the contextid record
        $context_record = $DB->get_record(
            'filter_autotranslate_ids', 
            array(
                'hash' => $hash, 
                'lang' => $current_lang, 
                'contextid' => $this->context->id,
                'contextlevel' => $this->context->contextlevel,
                'instanceid' => $this->context->instanceid
            )
        );

        // insert the context id record if it does not exist
        if (!$context_record) {
            $DB->insert_record(
                'filter_autotranslate_ids', 
                array(
                    'hash' => $hash,
                    'lang' => $current_lang,
                    'contextid' => $this->context->id,
                    'contextlevel' => $this->context->contextlevel,
                    'instanceid' => $this->context->instanceid
                )
            );
        }

        // see if the record exists
        $record = $DB->get_record('filter_autotranslate', array('hash' => $hash, 'lang' => $current_lang), 'text');

        // create a record for the default text
        // @todo: this may not be needed and may just be causing extra database storage
        // @note: this could also be used for replacing mlang text in the main entry
        // and saving other detected translations into the right translation entries
        if (!$record && $site_lang === $current_lang) {
            $DB->insert_record(
                'filter_autotranslate',
                array(
                    'hash' => $hash,
                    'lang' => $current_lang,
                    'status' => $current_lang === $site_lang ? 2 : 0,
                    'text' => $text,
                    'created_at' => time(),
                    'modified_at' => time()
                )
            );
        } 
        // translation needs to happen
        else if (!$record && $site_lang !== $current_lang) {

            // check if job exists
            $job = $DB->get_record('filter_autotranslate_jobs', array('hash' => $hash, 'lang' => $current_lang), 'fetched');

            // insert job
            if (!$job) {
                $DB->insert_record(
                    'filter_autotranslate_jobs',
                    array(
                        'hash' => $hash,
                        'lang' => $current_lang,
                        'fetched' => 0,
                        'source_missing' => 0
                    )
                );
            }
        } 
        // translation found, return the text
        else if ($record && $site_lang !== $current_lang) {
            $text = $record->text;
        }

        // if ($editing) {

        //      $url = new \moodle_url('/filter/autotranslate/manage.php', array(
        //         'source_lang' => $site_lang,
        //         'target_lang' => $current_lang,
        //         'hash' => $hash,
        //     ));

        //     $output = "<div>";
        //     $output .= $text;
        //     $output .= '<a href="' . $url->out() . '"><i class="fas fa-language"></i> Translate</a>';
        //     $output .= "</div>";

        //     $text = $output;
        // }

        // echo "<pre>";
        // var_dump($this->context->id);
        // var_dump($this->context->contextlevel);
        // var_dump($this->context->instanceid);
        // echo "</pre>";
        
        return $text;
    }

    /**
     * Parse multilang text and create an array based on the filter criteria
     *
     * @param string $text Text to be filtered
     * @return array Associative array with language codes as keys and corresponding texts as values
     */
    private function mlangparser($text) {
        $result = [];

        if (empty($text) or is_numeric($text)) {
            return $result;
        }

        // Pattern for the {mlang} tags
        $mlangPattern = '/{\s*mlang\s+([a-z0-9_-]+)\s*}(.*?){\s*mlang\s*}/is';

        // Pattern for <lang> or <span> tags
        $langPattern = '/<(?:lang|span)[^>]+lang="([a-zA-Z0-9_-]+)"[^>]*>(.*?)<\/(?:lang|span)>/is';

        // Search for {mlang} tags
        if (preg_match_all($mlangPattern, $text, $mlangMatches, PREG_SET_ORDER)) {
            foreach ($mlangMatches as $mlangMatch) {
                $lang = $mlangMatch[1];
                $content = $mlangMatch[2];

                // Organize content into the result array with language codes as keys
                $result[$lang] = $content;
            }
        }

        // Search for <lang> or <span> tags
        preg_replace_callback($langPattern, function ($langblock) use (&$result) {
            $langlist = [];
            if (preg_match_all('/[a-zA-Z0-9_-]+/', $langblock[1], $langs)) {
                foreach ($langs[0] as $lang) {
                    $lang = str_replace('-', '_', strtolower($lang)); // Normalize languages.
                    $langlist[$lang] = $langblock[2];
                }
            }
            $result += $langlist;
        }, $text);

        return $result;
    }

    // /**
    //  * Search for mlang tags
    //  *
    //  * The code for this PHP parser is adapted from filter/multilang2
    //  *
    //  * @param string $text Text with {mlang}
    //  * @return string
    //  */
    // private function mlangparser2($text) {
    //     // Search for {mlang} not found.
    //     if (!preg_match_all('/{\s*mlang\s+([a-z0-9_-]+)\s*}(.*?){\s*mlang\s*}/is', $text, $matches, PREG_SET_ORDER)) {
    //         return [];
    //     }

    //     // Iterate through matches and build the result array
    //     $result = [];
    //     foreach ($matches as $match) {
    //         $lang = $match[1];
    //         $content = $match[2];

    //         // Organize content into the result array with language codes as keys
    //         $result[$lang] = $content;
    //     }

    //     return $result;
    // }

    // /**
    //  * Parse text and create an array based on the filter criteria
    //  *
    //  * @param string $text Text to be filtered
    //  * @return array Associative array with language codes as keys and corresponding texts as values
    //  */
    // private function mlangparser($text) {
    //     $result = [];

    //     if (empty($text) or is_numeric($text)) {
    //         return $result;
    //     }

    //     // Adjust the regular expression pattern accordingly
    //     $search = '/<(?:lang|span)[^>]+lang="([a-zA-Z0-9_-]+)"[^>]*>(.*?)<\/(?:lang|span)>/is';

    //     preg_replace_callback($search, function ($langblock) use (&$result) {
    //         $langlist = [];
    //         if (preg_match_all('/[a-zA-Z0-9_-]+/', $langblock[1], $langs)) {
    //             foreach ($langs[0] as $lang) {
    //                 $lang = str_replace('-', '_', strtolower($lang)); // Normalize languages.
    //                 $langlist[$lang] = $langblock[2];
    //             }
    //         }
    //         $result += $langlist;
    //     }, $text);

    //     return $result;
    // }

}
