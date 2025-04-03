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
 * Replaces {t:hash} tags with translations based on user language, tags untagged content,
 * and caches results. Operates in course, module, and course category contexts only.
 *
 * Features:
 * - Dynamic tagging via content_service.
 * - Translation retrieval with fallback to source text.
 * - Caching for performance.
 *
 * Usage:
 * - Applied during text rendering in supported contexts.
 *
 * Design:
 * - Focuses on course-related content (CONTEXT_COURSE, CONTEXT_MODULE, CONTEXT_COURSECAT).
 * - Delegates tagging and storage to content_service.
 * - Uses cache API for efficiency.
 *
 * Dependencies:
 * - content_service.php: Tags and stores content.
 * - translation_source.php: Fetches translations.
 * - text_utils.php: Utility functions.
 * - cache.php: Cache definition.
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_autotranslate;

use filter_autotranslate\content_service;
use filter_autotranslate\translation_source;

/**
 * Text filter class for the Autotranslate plugin.
 */
class text_filter extends \core_filters\text_filter {

    /** @var content_service Service for tagging and managing content. */
    private $contentservice;

    /** @var translation_source Source for fetching translations. */
    private $translationsource;

    /** @var \cache Cache for storing filtered text. */
    private $cache;

    /**
     * Constructs the filter with dependencies.
     *
     * @param \context $context Filter context.
     * @param array $options Filter options.
     */
    public function __construct($context, array $options = []) {
        parent::__construct($context, $options);
        global $DB;

        $this->contentservice = new content_service($DB);
        $this->translationsource = new translation_source($DB);
        $this->cache = \cache::make('filter_autotranslate', 'taggedcontent');
    }

    /**
     * Filters text, replacing {t:hash} tags with translations.
     *
     * @param string $text Text to filter.
     * @param array $options Filter options.
     * @return string Filtered text.
     */
    public function filter($text, array $options = []) {
        if (empty($text) || is_numeric($text)) {
            return $text;
        }

        $courseid = $this->get_courseid_from_context($this->context);
        $currentlang = current_language();
        $cachekey = md5($text . $this->context->id . $currentlang);

        $cached = $this->cache->get($cachekey);
        if ($cached !== false) {
            return $cached;
        }

        $pattern = '/(.*?)(\{t:([a-zA-Z0-9]{10})\})(?:\s*|\s*<[^>]*>)?$/s';
        $text = $this->contentservice->process_content($text, $this->context, $courseid);

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
     * Checks for {t:hash} tags in text.
     *
     * @param string $text Text to check.
     * @return bool True if tags present, false otherwise.
     */
    private function has_tags($text) {
        return preg_match('/\{t:[a-zA-Z0-9]{10}\}/s', $text) === 1;
    }

    /**
     * Replaces {t:hash} tags with translations.
     *
     * @param string $text Text with tags.
     * @param array $options Filter options.
     * @return string Text with translations.
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
     * Gets course ID from context.
     *
     * @return int Course ID or 0 if not found.
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
     * Creates indicator for auto-translated content.
     *
     * @return string HTML indicator.
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
