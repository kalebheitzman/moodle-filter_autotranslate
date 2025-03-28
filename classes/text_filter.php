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
 * Text filter class for the filter_autotranslate plugin.
 *
 * Purpose:
 * This class implements the text filter for the filter_autotranslate plugin, replacing {t:hash} tags
 * in content with translations based on the user's current language. It dynamically tags untagged
 * translatable content during filtering, delegates storage to content_service, and caches results
 * for performance. The filter operates only in CONTEXT_COURSE, CONTEXT_MODULE, and CONTEXT_COURSECAT
 * to focus on educational content, leaving other contexts unchanged.
 *
 * Design Decisions:
 * - Originally focused on read-only translation retrieval, now includes dynamic tagging and write
 *   operations via content_service to handle untagged content and third-party modules without manual
 *   configuration in tagging_config.php.
 * - Restricts filtering to CONTEXT_COURSE (50), CONTEXT_MODULE (70), and CONTEXT_COURSECAT (40) using
 *   $this->context, ensuring translations apply only to course-related content.
 * - Uses Moodle's cache API (taggedcontent cache) to store filtered text, reducing redundant database
 *   queries across multiple filter invocations.
 * - Delegates MLang tag processing, content tagging, and database operations to content_service and
 *   text_utils, keeping the filter lean and focused on replacement.
 * - Employs a robust fallback chain: translation -> source text -> original content, avoiding 'N/A'
 *   outputs when source text is unavailable.
 * - Maintains Moodle-extra coding style with lowercase variables and no underscores, though internal
 *   method names (e.g., getcourseidfromcontext) follow this convention implicitly.
 * - Includes error handling via content_service transactions, with debugging support for tracing issues.
 *
 * Dependencies:
 * - content_service.php: Manages tagging, MLang parsing, and database writes for translations and
 *   hash-course mappings.
 * - translation_source.php: Provides read-only access to translation data from filter_autotranslate_translations.
 * - text_utils.php: Supplies utility functions for tag detection, hash generation, and MLang processing.
 * - cache.php: Defines the taggedcontent cache for performance optimization.
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_autotranslate;

use filter_autotranslate\content_service;
use filter_autotranslate\translation_source;

/**
 * Text filter class for the filter_autotranslate plugin.
 */
class text_filter extends \core_filters\text_filter {
    /**
     * @var content_service The service instance for tagging, storing, and managing content.
     */
    private $contentservice;

    /**
     * @var translation_source The source instance for fetching translation data.
     */
    private $translationsource;

    /**
     * @var \cache The cache instance for storing tagged content across requests.
     */
    private $cache;

    /**
     * Constructor for the text filter.
     *
     * Initializes the filter with its dependencies, setting up the content service, translation
     * source, and cache for efficient text processing.
     *
     * @param \context $this->context The context in which the filter is applied.
     * @param array $options Filter options (e.g., 'noclean' for HTML handling).
     */
    public function __construct($context, array $options = []) {
        parent::__construct($context, $options);
        global $DB;

        $this->contentservice = new content_service($DB);
        $this->translationsource = new translation_source($DB);
        $this->cache = \cache::make('filter_autotranslate', 'taggedcontent');
    }

    /**
     * Filters the given text, replacing {t:hash} tags with translated content.
     *
     * @param string $text The text to filter.
     * @param array $options Filter options, including 'context'.
     * @return string The filtered text with translations applied.
     */
    public function filter($text, array $options = []) {
        if (empty($text) || is_numeric($text)) {
            return $text;
        }

        $allowedcontexts = [CONTEXT_COURSE, CONTEXT_MODULE, CONTEXT_COURSECAT];
        if (!in_array($this->context->contextlevel, $allowedcontexts)) {
            return $text;
        }

        $courseid = $this->get_courseid_from_context($this->context);
        $currentlang = current_language();
        $cachekey = md5($text . $this->context->id . $currentlang);

        $cached = $this->cache->get($cachekey);
        if ($cached !== false) {
            return $cached;
        }

        $pattern = '/^(.*)\s*\{t:([a-zA-Z0-9]{10})\}$/s';
        $hastags = preg_match($pattern, trim($text), $matches);

        if (!$hastags) {
            $text = $this->contentservice->process_content($text, $this->context, $courseid);
        }

        $filteredtext = preg_replace_callback($pattern, function($matches) use ($currentlang) {
            $content = $matches[1];
            $hash = $matches[2];
            $translation = $this->translationsource->get_translation($hash, $currentlang);
            $sourcetext = $this->translationsource->get_source_text($hash);
            if ($translation && $translation->translated_text) {
                return $translation->translated_text;
            }
            return $sourcetext && $sourcetext !== 'N/A' ? $sourcetext : $content;
        }, $text);

        $this->cache->set($cachekey, $filteredtext);
        return $filteredtext;
    }

    /**
     * Checks if the text contains `{t:hash}` tags.
     *
     * Uses a regular expression to detect the presence of `{t:hash}` tags, indicating the text
     * has already been processed for translation.
     *
     * @param string $text The text to check.
     * @return bool True if tags are present, false otherwise.
     */
    private function has_tags($text) {
        return preg_match('/\{t:[a-zA-Z0-9]{10}\}/s', $text) === 1;
    }

    /**
     * Replaces `{t:hash}` tags with translations.
     *
     * Processes text containing `{t:hash}` tags by fetching translations from `translationsource`
     * and appending an indicator for auto-translated content if applicable.
     *
     * @param string $text The text with tags to replace.
     * @param array $options Filter options (e.g., 'noclean' for HTML).
     * @return string The text with tags replaced by translations.
     */
    private function replace_tags($text, array $options) {
        $pattern = '/((?:[^<{]*(?:<[^>]+>[^<{]*)*)?)\s*(?:<p>\s*)?\{t:([a-zA-Z0-9]{10})\}(?:\s*<\/p>)?/s';
        return preg_replace_callback($pattern, function ($match) use ($options) {
            $sourcetext = trim($match[1]);
            $hash = $match[2];

            // Determine the effective language for translation.
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
     * Resolves the course ID from the current context.
     *
     * Determines the course ID based on the context level (course, module, or block) for use in
     * tagging and mapping by `contentservice`.
     *
     * @return int The course ID, or 0 if not applicable.
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
     * Generates an indicator for auto-translated content.
     *
     * Creates HTML markup to visually indicate that content was auto-translated, used in contexts
     * where HTML is allowed.
     *
     * @return string The HTML indicator markup.
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
