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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Text filter for the Autotranslate plugin.
 *
 * Replaces {t:hash} tags with translations based on the user’s language, operating in course,
 * module, and course category contexts only. This filter no longer tags untagged content,
 * relying instead on the `tagcontent_scheduled_task` (running every 5 minutes) to handle tagging.
 * Results are cached only when {t:hash} tags are present and replaced, improving performance
 * by avoiding unnecessary caching of untagged text.
 *
 * Features:
 * - Lightweight replacement of {t:hash} tags with translations or source text fallback.
 * - Caching of processed text containing {t:hash} tags for efficiency.
 * - Operates exclusively in course-related contexts (CONTEXT_COURSE, CONTEXT_MODULE, CONTEXT_COURSECAT).
 *
 * Usage:
 * - Applied during text rendering in supported contexts to replace existing {t:hash} tags.
 * - Untagged content remains unchanged until tagged by the scheduled task.
 *
 * Design:
 * - Focuses on minimal processing, delegating tagging to `tagcontent_scheduled_task` for lazy
 *   content preparation.
 * - Uses cache API to store only tagged and replaced text, reducing overhead.
 * - Maintains separation of concerns by relying on `translation_source` for data retrieval.
 *
 * Dependencies:
 * - `translation_source.php`: Fetches translations and source text for replacement.
 * - `cache.php`: Cache definition for storing filtered text.
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_autotranslate;

use filter_autotranslate\translation_source;

/**
 * Text filter class for the Autotranslate plugin.
 *
 * Handles replacement of {t:hash} tags with translations in supported contexts, caching results
 * when tags are processed.
 */
class text_filter extends \core_filters\text_filter {

    /** @var translation_source Source for fetching translations and source text. */
    private $translationsource;

    /** @var \cache Cache instance for storing filtered text with {t:hash} replacements. */
    private $cache;

    /**
     * Constructs the filter with necessary dependencies.
     *
     * Initializes the filter with a translation source and cache instance, using the global
     * database object for data access.
     *
     * @param \context $context The context in which the filter is applied (e.g., course, module).
     * @param array $options Optional filter configuration options.
     */
    public function __construct($context, array $options = []) {
        parent::__construct($context, $options);
        global $DB;

        $this->translationsource = new translation_source($DB);
        $this->cache = \cache::make('filter_autotranslate', 'taggedcontent');
    }

    /**
     * Filters text by replacing {t:hash} tags with translations.
     *
     * Processes the input text to replace existing {t:hash} tags with translations for the current
     * user’s language, falling back to source text or original content if no translation exists.
     * Caches the result only if {t:hash} tags are present and replaced, leaving untagged text
     * unchanged for the `tagcontent_scheduled_task` to handle.
     *
     * @param string $text The text to filter, potentially containing {t:hash} tags.
     * @param array $options Filter options (e.g., 'noclean' for HTML handling).
     * @return string The filtered text with {t:hash} tags replaced, or unchanged if no tags.
     */
    public function filter($text, array $options = []) {
        // Skip processing for empty or numeric text to avoid unnecessary overhead.
        if (empty($text) || is_numeric($text)) {
            return $text;
        }

        $currentlang = current_language();
        $cachekey = md5($text . $this->context->id . $currentlang);

        // Return cached result if available, regardless of whether it was previously translated.
        $cached = $this->cache->get($cachekey);
        if ($cached !== false) {
            return $cached;
        }

        // Pattern to match {t:hash} tags at the end of text, capturing content before the tag.
        $pattern = '/(.*?)(\{t:([a-zA-Z0-9]{10})\})(?:\s*|\s*<[^>]*>)?$/s';
        $filteredtext = $text;

        // Only process and cache if {t:hash} tags are present.
        if (preg_match($pattern, $text)) {
            $filteredtext = preg_replace_callback($pattern, function($matches) use ($currentlang) {
                $content = $matches[1]; // Text before the {t:hash} tag.
                $hash = $matches[3];    // The hash value (e.g., 'abc1234567').

                // Fetch translation for the current language and source text as fallback.
                $translation = $this->translationsource->get_translation($hash, $currentlang);
                $sourcetext = $this->translationsource->get_source_text($hash);

                // Return translated text if available, otherwise source text or original content.
                if ($translation && $translation->translated_text) {
                    return $translation->translated_text;
                }
                return $sourcetext && $sourcetext !== 'N/A' ? $sourcetext : $content;
            }, $text);

            // Cache the result since {t:hash} was processed, whether translated or not.
            $this->cache->set($cachekey, $filteredtext);
        }

        return $filteredtext;
    }

    /**
     * Checks if the text contains {t:hash} tags.
     *
     * Determines whether the input text has at least one {t:hash} tag, used internally to optimize
     * processing decisions. Currently unused but retained for potential future optimizations.
     *
     * @param string $text The text to check for {t:hash} tags.
     * @return bool True if {t:hash} tags are present, false otherwise.
     */
    private function has_tags($text) {
        return preg_match('/\{t:[a-zA-Z0-9]{10}\}/s', $text) === 1;
    }

    /**
     * Replaces {t:hash} tags with translations (alternative method, unused).
     *
     * An alternative implementation for replacing {t:hash} tags with translations or source text,
     * including an indicator for auto-translated content. Currently unused in favor of the simpler
     * `filter()` regex approach, but retained for potential future use or reference.
     *
     * @param string $text The text containing {t:hash} tags to process.
     * @param array $options Filter options (e.g., 'noclean' to allow HTML).
     * @return string The text with {t:hash} tags replaced.
     */
    private function replace_tags($text, array $options) {
        $pattern = '/((?:[^<{]*(?:<[^>]+>[^<{]*)*)?)\s*(?:<p>\s*)?\{t:([a-zA-Z0-9]{10})\}(?:\s*<\/p>)?/s';
        return preg_replace_callback($pattern, function ($match) use ($options) {
            $sourcetext = trim($match[1]);
            $hash = $match[2];

            // Determine effective language: site language maps to 'other', else user language.
            $userlang = current_language();
            $sitelang = get_config('core', 'lang') ?: 'en';
            $effectivelang = ($userlang === $sitelang) ? 'other' : $userlang;

            // Fetch translation and fall back to source text if unavailable.
            $translation = $this->translationsource->get_translation($hash, $effectivelang);
            $displaytext = $translation ? $translation->translated_text : $sourcetext;

            // Add indicator for auto-translated content if not human-edited.
            $indicator = $translation && !$translation->human ? $this->get_indicator() : '';
            $allowhtml = !empty($options['noclean']) ||
                         ($this->context->contextlevel !== CONTEXT_COURSE &&
                          $this->context->contextlevel !== CONTEXT_MODULE);

            return $displaytext . ($allowhtml ? $indicator : '');
        }, $text);
    }

    /**
     * Retrieves the course ID from the filter’s context.
     *
     * Extracts the course ID based on the context level (course, module, or block), returning 0 if
     * not applicable. Used internally to provide course context, though not currently utilized
     * after tagging removal.
     *
     * @return int The course ID, or 0 if not found or not in a course-related context.
     */
    private function get_courseid_from_context() {
        $this->context = $this->context;

        if ($this->context->contextlevel == CONTEXT_COURSE) {
            return $this->context->instanceid;
        } else if ($this->context->contextlevel == CONTEXT_MODULE) {
            $cm = get_coursemodule_from_id('', $this->context->instanceid);
            return $cm ? $cm->course : 0;
        } else if ($this->context->contextlevel == CONTEXT_BLOCK) {
            $parentcontext = $this->context->get_parent_context();
            if ($parentcontext && $parentcontext->contextlevel == CONTEXT_COURSE) {
                return $parentcontext->instanceid;
            }
        }

        return 0;
    }

    /**
     * Generates an HTML indicator for auto-translated content.
     *
     * Creates a visual marker (label and icon) to indicate when text is auto-translated rather than
     * human-edited, used in the alternative `replace_tags` method (currently unused).
     *
     * @return string HTML string containing the auto-translated indicator.
     */
    private function get_indicator() {
        global $OUTPUT;

        $label = '<span class="text-secondary font-italic small mr-1">' .
                 get_string('autotranslated', 'filter_autotranslate') .
                 '</span>';
        $icon = '<span class="text-secondary">' .
                $OUTPUT->pix_icon('i/siteevent', 'Autotranslated', 'moodle') .
                '</span>';

        return '<div class="text-right">' . $label . $icon . '</div>';
    }
}
