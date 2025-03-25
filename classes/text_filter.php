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
// along with Moodle.  If not, see <http://www.gnu.org/licenses>.
/**
 * Auto Translate Filter
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace filter_autotranslate;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/filter/autotranslate/classes/translation_repository.php');

/**
 * Text filter class for the filter_autotranslate plugin.
 *
 * Purpose:
 * This class implements the text filter for the filter_autotranslate plugin, replacing {t:hash}
 * tags in content with the appropriate translation based on the user's language. It operates in a
 * read-only manner, fetching translations from the database and applying them to the text.
 *
 * Design Decisions:
 * - Focuses on read-only operations to adhere to the principle of separation of concerns.
 *   Database writes are handled by service classes (e.g., tagging_service.php, translation_service.php).
 * - Function names use snake_case (e.g., get_fallback_text) to follow Moodle's coding style.
 * - Includes error handling for invalid hashes, logging a message to aid debugging.
 * - The get_file_params function for handling @@PLUGINFILE@@ URLs is currently commented out,
 *   as it is incomplete and requires further implementation (e.g., determining the correct component
 *   and file area for all contexts).
 *
 * Dependencies:
 * - translation_repository.php: Provides read-only access to translation data for fetching translations.
 */
class text_filter extends \core_filters\text_filter {

    /**
     * @var translation_repository The translation repository instance for fetching translations.
     */
    private $translation_repository;

    /**
     * Constructor for the text filter.
     *
     * Initializes the translation repository for fetching translations.
     *
     * @param \context $context The context in which the filter is applied.
     * @param array $options Filter options.
     */
    public function __construct($context, array $options = []) {
        parent::__construct($context, $options);
        global $DB;
        $this->translation_repository = new translation_repository($DB);
    }

    /**
     * Filters the given text, replacing {t:hash} tags with translations.
     *
     * This function processes the input text, identifying {t:hash} tags and replacing them with
     * the appropriate translation based on the user's language. If no translation is available,
     * it falls back to the source text (lang = 'other'). It also adds an indicator for auto-translated
     * content if applicable.
     *
     * @param string $text The text to filter.
     * @param array $options Filter options (e.g., 'noclean' to allow HTML).
     * @return string The filtered text with {t:hash} tags replaced by translations.
     */
    public function filter($text, array $options = []) {
        global $USER;

        if (empty($text) || is_numeric($text)) {
            return $text;
        }

        // Get configured contexts
        $selectedctx = get_config('filter_autotranslate', 'selectctx');
        $selectedctx = $selectedctx ? array_map('trim', explode(',', $selectedctx)) : ['40', '50', '70', '80'];
        $currentcontext = $this->context->contextlevel;

        if (!in_array((string)$currentcontext, $selectedctx)) {
            return $text;
        }

        // Updated regex pattern to match {t:hash} tags in various positions, including when wrapped in <p> tags
        $pattern = '/((?:[^<{]*(?:<[^>]+>[^<{]*)*)?)\s*(?:<p>\s*)?\{t:([a-zA-Z0-9]{10})\}(?:\s*<\/p>)?/s';
        $matches = [];
        if (!preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            return $text; // No tags to process
        }

        $replacements = [];
        foreach ($matches as $match) {
            $fulltag = $match[0];
            $hash = $match[2]; // Group 2 is the hash
            $source_text = trim($match[1]); // Group 1 is the source text

            // Fetch the translation for the user's language
            $userlang = current_language();
            $translation = $this->translation_repository->get_translation($hash, $userlang);
            if (!$translation) {
                // Log if the hash is invalid or not found
                debugging("Translation not found for hash '$hash' and language '$userlang' in context {$this->context->id}", DEBUG_DEVELOPER);
                $display_text = $this->get_fallback_text($hash, $source_text);
            } else {
                $display_text = $translation->translated_text;
            }

            // Check if the translation is auto-translated (human = 0)
            $is_autotranslated = $translation ? $translation->human === 0 : false; // Default to auto if no record
            $autotranslated_indicator = $is_autotranslated ? $this->get_autotranslate_indicator() : '';

            $allowhtml = !empty($options['noclean']) || ($this->context->contextlevel !== CONTEXT_COURSE && $this->context->contextlevel !== CONTEXT_MODULE);

            // Rewrite @@PLUGINFILE@@ URLs in the translated text (currently commented out, to be implemented)
            /*
            $fileparams = $this->get_file_params();
            if ($fileparams) {
                list($component, $filearea, $itemid) = $fileparams;
                $display_text = \file_rewrite_pluginfile_urls(
                    $display_text,
                    $filearea,
                    $this->context->id,
                    $component,
                    $itemid,
                    null
                );
            }
            */
            
            $replacements[$fulltag] = $display_text . ($allowhtml ? $autotranslated_indicator : "");
        }

        foreach ($replacements as $tag => $replacement) {
            $text = str_replace($tag, $replacement, $text);
        }

        return $text;
    }

    /**
     * Fetches the fallback text for a hash if a translation is not available.
     *
     * This function retrieves the source text (lang = 'other') for a given hash as a fallback
     * when a translation in the user's language is not found. If no source text exists, it returns
     * the original source text from the content.
     *
     * @param string $hash The hash of the translation.
     * @param string $source_text The original source text before the {t:hash} tag.
     * @return string The fallback text (source text or original text).
     */
    private function get_fallback_text($hash, $source_text) {
        $fallback = $this->translation_repository->get_source_text($hash);
        return $fallback !== 'N/A' ? $fallback : $source_text;
    }

    /**
     * Generates an indicator for auto-translated content.
     *
     * This function creates a visual indicator (e.g., an icon and label) to show that a translation
     * was auto-generated (human = 0), helping users identify machine-translated content.
     *
     * @return string The HTML for the auto-translated indicator.
     */
    private function get_autotranslate_indicator() {
        global $OUTPUT;
        // Use Font Awesome globe icon with solid style
        $label = '<span class="text-secondary font-italic small mr-1">' . get_string('autotranslated', 'filter_autotranslate') . '</span>';
        $icon = '<span class="text-secondary">' . $OUTPUT->pix_icon('i/siteevent', 'Autotranslated', 'moodle') . '</span>';
        return '<div class="text-right">' . $label . $icon . '</div>';
    }

    /**
     * Determines the component, file area, and item ID for rewriting @@PLUGINFILE@@ URLs.
     *
     * This function identifies the appropriate component, file area, and item ID based on the
     * current context, to support rewriting @@PLUGINFILE@@ URLs in translated content. It is
     * currently commented out as the implementation is incomplete and requires further work to
     * handle all contexts correctly.
     *
     * @return array|null [component, filearea, itemid] or null if not applicable.
     */
    private function get_file_params() {
        $context = $this->context;

        if ($context->contextlevel == CONTEXT_COURSE) {
            // Course context (e.g., course summary)
            $component = 'course';
            $filearea = 'summary';
            $itemid = $context->instanceid;
        } elseif ($context->contextlevel == CONTEXT_MODULE) {
            // Module context (e.g., module intro)
            $cm = get_coursemodule_from_id('', $context->instanceid);
            if ($cm) {
                $component = 'mod_' . $cm->modname; // e.g., 'mod_forum'
                $filearea = 'intro';
                $itemid = $cm->instance; // Module instance ID, not course module ID
            } else {
                return null; // Couldn’t fetch course module
            }
        } else {
            // Other contexts (e.g., blocks, categories) not handled yet
            return null;
        }

        return [$component, $filearea, $itemid];
    }
}