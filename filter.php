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

// get libs
require_once(dirname(__DIR__, 2) . '/config.php');
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
     * Filter text before changing format to HTML.
     *
     * @param string $text
     * @param array $options
     * @return string
     */
    public function filter_stage_pre_format(string $text, array $options): string {
        // Ideally we want to get rid of all other languages before any text formatting.
        return $this->filter($text, $options);
    }

    /**
     * Filter HTML text before sanitising text.
     *
     * Text sanitisation might not be performed if $options['noclean'] true.
     *
     * @param string $text
     * @param array $options
     * @return string
     */
    public function filter_stage_pre_clean(string $text, array $options): string {
        return $text;
    }

    /**
     * Filter HTML text after sanitisation.
     *
     * @param string $text
     * @param array $options
     * @return string
     */
    public function filter_stage_post_clean(string $text, array $options): string {
        return $text;
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
        // access the db constant
        global $DB;
        // to get context via $PAGE
        global $PAGE;

        // get the api key from settings
        $authKey = get_config('filter_autotranslate', 'deeplapikey');
        if (!$authKey) {
            return $text;
        }

        // language settings
        $default_lang = get_config('core', 'lang');;
        $current_lang = current_language();

        // load deepl translator
        $translator = new \DeepL\Translator($authKey);

        // generate the md5 hash of the current text
        $hash = md5($text);

        // get the contextid record
        $context_record = $DB->get_record('filter_autotranslate_ids', array('hash' => $hash, 'lang' => $current_lang, 'context_id' => $PAGE->context->id));

        // insert the context id record if it does not exist
        if (!$context_record) {
            $DB->insert_record(
                'filter_autotranslate_ids', 
                array(
                    'hash' => $hash,
                    'lang' => $current_lang,
                    'context_id' => $PAGE->context->id
                )
            );
        }

        // see if the record exists
        $record = $DB->get_record('filter_autotranslate', array('hash' => $hash, 'lang' => $current_lang), 'text');

        // create a record for the default text
        // @todo: this may not be needed and may just be causing extra database storage
        // @note: this could also be used for replacing mlang text in the main entry
        // and saving other detected translations into the right translation entries
        if (!$record && $default_lang === $current_lang) {
            $DB->insert_record(
                'filter_autotranslate',
                array(
                    'hash' => $hash,
                    'lang' => $current_lang,
                    'text' => $text,
                    'created_at' => time(),
                    'modified_at' => time()
                )
            );

        } 
        // translation needs to happen
        else if (!$record && $default_lang !== $current_lang) {

            // check if job exists
            $job = $DB->get_record('filter_autotranslate_jobs', array('hash' => $hash, 'lang' => $current_lang), 'fetched');

            // insert job
            if (!$job) {
                $DB->insert_record(
                    'filter_autotranslate_jobs',
                    array(
                        'hash' => $hash,
                        'lang' => $current_lang,
                        'fetched' => 0
                    )
                );
            }

            // $translation = $translator->translateText($text, null, $current_lang);

            // $id = $DB->insert_record(
            //     'filter_autotranslate',
            //     array(
            //         'hash' => $hash,
            //         'lang' => $current_lang,
            //         'text' => $translation->text,
            //         'created_at' => time(),
            //         'modified_at' => time()
            //     )
            // );



            // return $translation->text;
            return $text;
        } 
        // translation found, return the text
        else if ($record && $default_lang !== $current_lang) {
            return $record->text;
        }
        
        return $text;
    }

}
