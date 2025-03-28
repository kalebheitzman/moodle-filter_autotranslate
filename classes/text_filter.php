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
 * Text Filter for the Autotranslate Plugin
 *
 * Purpose:
 * This file defines the text filter for the filter_autotranslate plugin, acting as a lean entry
 * point to process Moodle text by replacing `{t:hash}` tags with translations or delegating
 * untagged or stale content for tagging and storage. It integrates with Moodle’s filtering system
 * to dynamically enhance text during page rendering, leveraging caching for performance and
 * ensuring minimal logic by offloading complex operations to other components.
 *
 * Structure:
 * Contains the `text_filter` class, extending Moodle’s core text filter, with properties for
 * `$contentservice` (tagging/storage), `$translationsource` (data retrieval), and `$cache`
 * (performance). Key methods include `filter` (main processing), `has_tags` (tag detection),
 * `replace_tags` (translation replacement), `get_courseid_from_context` (course ID resolution),
 * and `get_indicator` (UI markup).
 *
 * Usage:
 * Called by Moodle’s filtering system when text needs processing, typically during page rendering.
 * It checks context settings, replaces existing `{t:hash}` tags with translations from
 * `translationsource`, or delegates untagged/stale text to `contentservice` for processing.
 *
 * Design Decisions:
 * - Keeps logic minimal to ensure maintainability, delegating tagging and staleness handling to
 *   `contentservice` and translation retrieval to `translationsource`.
 * - Uses application-level caching (via `cache.php`) to store tagged content, reducing redundant
 *   database operations across requests.
 * - Retains context validation (`selectctx`) to control where filtering applies, aligning with
 *   dynamic settings from the plugin’s configuration.
 * - Preserves the auto-translation indicator as a simple UI enhancement, with potential to move
 *   to `ui_manager` in future iterations.
 *
 * Dependencies:
 * - `content_service.php`: Handles tagging, storage, and staleness logic for untagged or stale content.
 * - `translation_source.php`: Provides read-only access to translation data for tag replacement.
 * - `text_utils.php`: Supplies utility functions (e.g., tag detection, used indirectly via `contentservice`).
 * - `cache.php`: Defines the `taggedcontent` cache for performance optimization.
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
     * @param \context $context The context in which the filter is applied.
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
     * Processes untagged content by tagging it and persisting it in the source table via
     * `content_service`, then replaces all {t:hash} tags with the appropriate translation for the
     * current language using `translation_source`. Ensures tags are processed only once to prevent
     * duplication.
     *
     * @param string $text The text to filter.
     * @param array $options Filter options, including 'context'.
     * @return string The filtered text with translations applied.
     */
    public function filter($text, array $options = []) {
        if (empty($text) || is_numeric($text)) {
            return $text;
        }

        $context = $options['context'] ?? \context_system::instance();
        $courseid = $this->get_courseid_from_context($context);

        // Step 1: Tag untagged content only if no {t:hash} tags exist.
        $pattern = '/{t:([a-zA-Z0-9]{10})}/';
        if (!preg_match($pattern, $text)) {
            $text = $this->contentservice->process_content($text, $context, $courseid);
        }

        // Step 2: Replace {t:hash} tags with translations, avoiding reprocessing.
        $currentlang = current_language();
        $filteredtext = preg_replace_callback($pattern, function($matches) use ($currentlang, $context) {
            $hash = $matches[1];
            $translation = $this->translationsource->get_translation($hash, $currentlang);

            if ($translation && $translation->translated_text) {
                return $translation->translated_text; // Use translated text if available.
            }

            // Fallback to source text (lang = 'other') if no translation exists.
            $sourcetext = $this->translationsource->get_source_text($hash);
            return $sourcetext ?: $matches[0]; // Return original tag if no source text.
        }, $text);

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
        $context = $this->context;

        if ($context->contextlevel == CONTEXT_COURSE) {
            return $context->instanceid;
        } else if ($context->contextlevel == CONTEXT_MODULE) {
            $cm = get_coursemodule_from_id('', $context->instanceid);
            return $cm ? $cm->course : 0;
        } else if ($context->contextlevel == CONTEXT_BLOCK) {
            $parentcontext = $context->get_parent_context();
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
