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

defined('MOODLE_INTERNAL') || die();

global $CFG;

// Get libs.
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
    public function filter($text, array $options = []): string {
        // Access constants.
        global $DB;
        global $PAGE;
        global $CFG;

        // Trim the text.
        $text = trim($text);

        // Text is empty.
        if (empty($text)) {
            return $text;
        };

        // Define URLs of pages to exempt.
        $exemptedpages = [
            $CFG->wwwroot . '/filter/autotranslate/manage.php',
            $CFG->wwwroot . '/filter/autotranslate/glossary.php',
            // Add more exempted page URLs as needed.
        ];

        // Check if the current page URL is in the exempted list.
        if (in_array($PAGE->url->out(), $exemptedpages)) {
            // Apply your filter logic here for non-exempted pages.
            return $text;
        }

        // Only translate contexts that are in filter settings.
        $selectctx = explode(",", get_config('filter_autotranslate', 'selectctx'));
        if (!in_array(strval($this->context->contextlevel), $selectctx)) {
            return $text;
        }

        // Language settings.
        $sitelang = get_config('core', 'lang');
        $currentlang = current_language();

        // Clean the mlang text.
        if (str_contains($text, "mlang other")) {
            $text = str_replace("mlang other", "mlang $sitelang", $text);
        }

        // Parsed text
        // if there is parsed text for the current language
        // set the text to the parsed text so mlang doesn't get
        // stored in the db.
        $langsresult = $this->mlangparser($text);
        if (!$langsresult) {
            // Look for multilang2 text.
            $langsresult = $this->mlangparser2($text);
        }

        // Check for translated string from core.
        // This is to catch translated text that is not coming from multilang. Its possible
        // that other components will need to be called and merged in.
        $component = $this->get_component_name();
        $strings = get_string_manager()->load_component_strings($component, $currentlang);
        $textistranslation = in_array($text, array_values($strings));

        // Text is from stringidentifier so we
        // return it without further processing.
        if ($textistranslation) {
            return $text;
        }

        // Clean the langresults.
        foreach ($langsresult as $lang => $value) {
            // Catch bad multilang usage and clean it up.
            // Note: there might be value in printing this to the page
            // so it can be cleaned up in the editor.
            if (str_contains($langsresult[$lang], "{mlang")) {
                unset($langsresult[$lang]);
            }

            // Empty lang or value detected, unset the array item.
            if (empty($lang) || empty($value)) {
                unset($langsresult[$lang]);
            }

            // Lang codes longer than 2 characters are unsupported.
            if (strlen($lang) > 2) {
                unset($langsresult[$lang]);
            }
        }

        // Current language for string not detected in multilang.
        if (!array_key_exists($currentlang, $langsresult) && $currentlang !== $sitelang) {
            $langsresult[$currentlang] = null;
        }

        // Default language for site not detected in multilang.
        // Note: we don't want to enter text based on string identifiers in another language.
        if (!array_key_exists($sitelang, $langsresult) && $currentlang === $sitelang) {
            $langsresult[$sitelang] = $text;
        }

        // Generate the md5 hash of the current text.
        $hash = md5($text);

        // Iterate through each lang results.
        // Remember: this includes the default site language.
        foreach ($langsresult as $lang => $content) {
            // Null content has been found,
            // kick off job processing.
            if (!$content && $currentlang === $lang && $currentlang !== $sitelang) {
                // Check if job exists.
                $job = $DB->get_record('filter_autotranslate_jobs', ['hash' => $hash, 'lang' => $lang], 'id');

                // Insert job.
                if (!$job) {
                    $DB->insert_record(
                        'filter_autotranslate_jobs',
                        [
                            'hash' => $hash,
                            'lang' => $lang,
                            'fetched' => 0,
                            'source' => 0,
                        ]
                    );
                }
            }

            // Get the contextid record.
            $ctxrecord = $DB->get_record(
                'filter_autotranslate_ctx',
                [
                    'hash' => $hash,
                    'lang' => $lang,
                    'contextid' => $this->context->id,
                    'contextlevel' => $this->context->contextlevel,
                    'instanceid' => $this->context->instanceid,
                ]
            );

            // Insert the context id record if it does not exist
            // still create the record even if content is null because
            // an autotranslation job has been added for the content.
            if (!$ctxrecord && $lang) {
                $DB->insert_record(
                    'filter_autotranslate_ctx',
                    [
                        'hash' => $hash,
                        'lang' => $lang,
                        'contextid' => $this->context->id,
                        'contextlevel' => $this->context->contextlevel,
                        'instanceid' => $this->context->instanceid,
                    ]
                );
            }

            // See if the record exists.
            $record = $DB->get_record('filter_autotranslate', ['hash' => $hash, 'lang' => $lang], 'text');

            // Create a record for the text
            // do not add null content here.
            // Note: We don't want to insert stringidentifier content here.
            if (!$record && $content && $lang) {
                $DB->insert_record(
                    'filter_autotranslate',
                    [
                        'hash' => $hash,
                        'lang' => $lang,
                        'status' => $lang === $sitelang ? 2 : 1,
                        'text' => $content,
                        'created_at' => time(),
                        'modified_at' => time(),
                    ]
                );
            } else if ($record && $currentlang === $lang) {
                // Translation found, return the text.
                return $record->text;
            }
        }

        // Never gonna give you up.
        if (!empty($langsresult[$currentlang])) {
            return $langsresult[$currentlang];
        }

        // Never gonna let you down.
        if (!empty($langsresult[$sitelang])) {
            return $langsresult[$sitelang];
        }

        // Never gonna run around and desert you.
        return $text;
    }

    /**
     * Get Component Name from the Context
     *
     * This is used to check against string tranlsations
     * in Moodle so that we dont store unnecessary db info.
     *
     * @return string $component Component Name
     */
    private function get_component_name() {
        global $DB;

        // Initialize an array to store components.
        $components = [];

        // Get the context.
        $context = $this->context;

        // Check the type of context and infer associated components.
        if ($context instanceof context_system) {
            // For the system context, add the system component.
            $components[] = 'core_system';
        } else if ($context instanceof context_course) {
            // For a course context, add sub-components related to course management.
            $components[] = 'core_course';
        } else if ($context instanceof context_module) {
            // For a module context, add sub-components related to the specific module.
            $module = $DB->get_record('course_modules', ['id' => $context->instanceid]);
            if ($module && !empty($module->module)) {
                $components[] = 'mod_' . $module->module;
            }
        } else if ($context instanceof context_block) {
            // For a block context, add sub-components related to the specific block.
            $blockconfig = get_config('block', $context->instanceid);
            if ($blockconfig && !empty($blockconfig->name)) {
                $components[] = 'block_' . $blockconfig->name;
            } else {
                $components[] = 'block_system'; // Fallback block component.
            }
        } else if ($context instanceof context_coursecat) {
            // For a course category context, add sub-components related to course categories.
            $components[] = 'core_coursecat';
        } else if ($context instanceof context_user) {
            // For a user context, add the user component.
            $components[] = 'core_user';
        }

        // Convert the array of components into a string.
        $componentstring = implode(',', $components);

        return !empty($componentstring) ? $componentstring : null;
    }

    /**
     * Parse {mlang} tags in a given text.
     *
     * This function extracts language codes and corresponding content from {mlang} tags.
     *
     * @param string $text Text with {mlang} tags
     * @return array Associative array with language codes as keys and content as values
     */
    private function mlangparser2($text) {
        $result = [];
        $pattern = '/{\s*mlang\s+([^}]+)\s*}(.*?){\s*mlang\s*(?:[^}]+)?}/is';
        $sitelang = get_config('core', 'lang');

        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $lang = $match[1];
                $lang = $lang === 'other' ? $sitelang : $lang;
                $content = $match[2];
                $result[strtolower($lang)] = trim($content);
            }
        }

        return $result;
    }

    /**
     * Parse text and create an array based on the filter criteria
     *
     * @param string $text Text to be filtered
     * @return array Associative array with language codes as keys and corresponding texts as values
     */
    private function mlangparser($text) {
        $result = [];

        if (empty($text) || is_numeric($text)) {
            return $result;
        }

        // Adjust the regular expression pattern accordingly.
        $search = '/<(?:lang|span)[^>]+lang="([a-zA-Z0-9_-]+)"[^>]*>(.*?)<\/(?:lang|span)>/is';

        preg_replace_callback($search, function ($langblock) use (&$result) {
            $langlist = [];
            if (preg_match_all('/[a-zA-Z0-9_-]+/', $langblock[1], $langs)) {
                foreach ($langs[0] as $lang) {
                    $lang = str_replace('-', '_', strtolower($lang)); // Normalize languages.
                    $langlist[strtolower($lang)] = trim($langblock[2]);
                }
            }
            $result += $langlist;
        }, $text);

        return $result;
    }
}
