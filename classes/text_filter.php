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
 * Auto Translate Filter
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace filter_autotranslate;

use filter_autotranslate\translation_repository;
use filter_autotranslate\translation_service;
use filter_autotranslate\tagging_service;
use filter_autotranslate\helper;

/**
 * Text filter class for the filter_autotranslate plugin.
 */
class text_filter extends \core_filters\text_filter {
    /**
     * @var translation_repository The translation repository instance for fetching translations.
     */
    private $translationrepository;

    /**
     * @var translation_service The translation service instance for updating translations.
     */
    private $translationservice;

    /**
     * @var tagging_service The tagging service instance for tagging content.
     */
    private $taggingservice;

    /**
     * @var array Cache of hashes processed in the current request to avoid redundant updates.
     */
    private $processedhashes = [];

    /**
     * @var \cache The cache instance for storing tagged content across requests.
     */
    private $cache;

    /**
     * Constructor for the text filter.
     *
     * @param \context $context The context in which the filter is applied.
     * @param array $options Filter options.
     */
    public function __construct($context, array $options = []) {
        parent::__construct($context, $options);
        global $DB;
        $this->translationrepository = new translation_repository($DB);
        $this->translationservice = new translation_service($DB);
        $this->taggingservice = new tagging_service($DB);
        $this->cache = \cache::make('filter_autotranslate', 'taggedcontent');
    }

    /**
     * Filters the given text, replacing {t:hash} tags with translations and dynamically tagging untagged content.
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

        // Get configured contexts.
        $selectedctx = get_config('filter_autotranslate', 'selectctx');
        $selectedctx = $selectedctx ? array_map('trim', explode(',', $selectedctx)) : ['40', '50', '70', '80'];
        $currentcontext = $this->context->contextlevel;

        if (!in_array((string)$currentcontext, $selectedctx)) {
            return $text;
        }

        // First, check if the text already contains {t:hash} tags.
        $pattern = '/((?:[^<{]*(?:<[^>]+>[^<{]*)*)?)\s*(?:<p>\s*)?\{t:([a-zA-Z0-9]{10})\}(?:\s*<\/p>)?/s';
        $matches = [];
        $hastags = preg_match_all($pattern, $text, $matches, PREG_SET_ORDER);

        if ($hastags) {
            // Process existing {t:hash} tags.
            $replacements = [];
            $courseid = $this->get_courseid_from_context();
            foreach ($matches as $match) {
                $fulltag = $match[0];
                $hash = $match[2];
                $sourcetext = trim($match[1]);

                // Ensure source text exists or restore it.
                $this->taggingservice->restore_missing_source_text($hash, $sourcetext, $this->context, $courseid);
                $this->taggingservice->update_hash_course_mapping($fulltag, $courseid);

                // Skip if we've already processed this hash in the current request.
                if (isset($this->processedhashes[$hash])) {
                    $displaytext = $this->processedhashes[$hash]['display_text'];
                    $isautotranslated = $this->processedhashes[$hash]['is_autotranslated'];
                } else {
                    // Fetch the translation for the user's language.
                    $userlang = current_language();
                    $sitelang = get_config('core', 'lang') ?: 'en';
                    $effectivelang = ($userlang === $sitelang) ? 'other' : $userlang;
                    $translation = $this->translationrepository->get_translation($hash, $effectivelang);
                    if (!$translation) {
                        $debugenabled = get_config('filter_autotranslate', 'debugtranslations');
                        if ($debugenabled) {
                            debugging(
                                "Translation not found for hash '$hash' and language '$effectivelang' " .
                                "in context {$this->context->id}. Source text: " . substr($sourcetext, 0, 50) . "...",
                                DEBUG_DEVELOPER
                            );
                        }
                        $displaytext = $this->get_fallback_text($hash, $sourcetext);
                    } else {
                        $displaytext = $translation->translated_text;
                    }

                    // Check if the translation is auto-translated.
                    $isautotranslated = $translation ? $translation->human === 0 : false;

                    // Cache the result for this hash.
                    $this->processedhashes[$hash] = [
                        'display_text' => $displaytext,
                        'is_autotranslated' => $isautotranslated,
                    ];
                }

                $autotranslatedindicator = $isautotranslated ? $this->get_autotranslate_indicator() : '';
                $allowhtml = !empty($options['noclean']) ||
                    ($this->context->contextlevel !== CONTEXT_COURSE && $this->context->contextlevel !== CONTEXT_MODULE);
                $replacements[$fulltag] = $displaytext . ($allowhtml ? $autotranslatedindicator : "");
            }

            foreach ($replacements as $tag => $replacement) {
                $text = str_replace($tag, $replacement, $text);
            }

            return $text;
        }

        // If no {t:hash} tags, dynamically tag the content.
        $courseid = $this->get_courseid_from_context();
        $taggedcontent = $this->taggingservice->tag_and_store_content($text, $this->context, $courseid);

        // Process the tagged content.
        if (preg_match_all($pattern, $taggedcontent, $matches, PREG_SET_ORDER)) {
            $replacements = [];
            foreach ($matches as $match) {
                $fulltag = $match[0];
                $hash = $match[2];
                $sourcetext = trim($match[1]);

                if (isset($this->processedhashes[$hash])) {
                    $displaytext = $this->processedhashes[$hash]['display_text'];
                    $isautotranslated = $this->processedhashes[$hash]['is_autotranslated'];
                } else {
                    $userlang = current_language();
                    $sitelang = get_config('core', 'lang') ?: 'en';
                    $effectivelang = ($userlang === $sitelang) ? 'other' : $userlang;
                    $translation = $this->translationrepository->get_translation($hash, $effectivelang);
                    if (!$translation) {
                        $debugenabled = get_config('filter_autotranslate', 'debugtranslations');
                        if ($debugenabled) {
                            debugging(
                                "Translation not found for hash '$hash' and language '$effectivelang' " .
                                "in context {$this->context->id}. Source text: " . substr($sourcetext, 0, 50) . "...",
                                DEBUG_DEVELOPER
                            );
                        }
                        $displaytext = $this->get_fallback_text($hash, $sourcetext);
                    } else {
                        $displaytext = $translation->translated_text;
                    }

                    $isautotranslated = $translation ? $translation->human === 0 : false;
                    $this->processedhashes[$hash] = [
                        'display_text' => $displaytext,
                        'is_autotranslated' => $isautotranslated,
                    ];
                }

                $autotranslatedindicator = $isautotranslated ? $this->get_autotranslate_indicator() : '';
                $allowhtml = !empty($options['noclean']) ||
                    ($this->context->contextlevel !== CONTEXT_COURSE && $this->context->contextlevel !== CONTEXT_MODULE);
                $replacements[$fulltag] = $displaytext . ($allowhtml ? $autotranslatedindicator : "");
            }

            foreach ($replacements as $tag => $replacement) {
                $taggedcontent = str_replace($tag, $replacement, $taggedcontent);
            }
        }

        return $taggedcontent;
    }

    /**
     * Fetches the course ID from the current context.
     *
     * @return int The course ID, or 0 if not found.
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
     * Fetches the fallback text for a hash if a translation is not available.
     *
     * @param string $hash The hash of the translation.
     * @param string $sourcetext The original source text before the {t:hash} tag.
     * @return string The fallback text (source text or original text).
     */
    private function get_fallback_text($hash, $sourcetext) {
        $fallback = $this->translationrepository->get_source_text($hash);
        return $fallback !== 'N/A' ? $fallback : $sourcetext;
    }

    /**
     * Generates an indicator for auto-translated content.
     *
     * @return string The HTML for the auto-translated indicator.
     */
    private function get_autotranslate_indicator() {
        global $OUTPUT;
        $label = '<span class="text-secondary font-italic small mr-1">' .
            get_string('autotranslated', 'filter_autotranslate') .
            '</span>';
        $icon = '<span class="text-secondary">' . $OUTPUT->pix_icon('i/siteevent', 'Autotranslated', 'moodle') . '</span>';
        return '<div class="text-right">' . $label . $icon . '</div>';
    }
}
