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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/filter/autotranslate/classes/translation_repository.php');
require_once($CFG->dirroot . '/filter/autotranslate/classes/translation_service.php');
require_once($CFG->dirroot . '/filter/autotranslate/classes/tagging_service.php');
require_once($CFG->dirroot . '/filter/autotranslate/classes/helper.php');

/**
 * Text filter class for the filter_autotranslate plugin.
 *
 * Purpose:
 * This class implements the text filter for the filter_autotranslate plugin, replacing {t:hash}
 * tags in content with the appropriate translation based on the user's language. It also dynamically
 * tags untagged translatable content during the filtering process, storing the tagged content in
 * the database and caching it for performance.
 *
 * Design Decisions:
 * - Originally designed for read-only operations, fetching translations via translation_repository.php.
 * - Now includes write operations to dynamically tag content, process MLang tags, and store translations
 *   in the database, as a necessary compromise to handle third-party modules and eliminate the need for
 *   manual configuration in tagging_config.php.
 * - Uses Moodle's cache API to cache tagged content across requests, reducing database operations.
 * - Reuses existing services (translation_service.php, tagging_service.php) and helpers (helper.php)
 *   to keep the code DRY and consistent with the task-based tagging process.
 * - Function names use snake_case (e.g., get_fallback_text) to follow Moodle's coding style.
 * - Includes error handling for invalid hashes, logging a message to aid debugging.
 *
 * Dependencies:
 * - translation_repository.php: Provides read-only access to translation data for fetching translations.
 * - translation_service.php: Handles database writes for updating translations.
 * - tagging_service.php: Handles database operations for tagging content and updating hash-course mappings.
 * - helper.php: Provides utility functions for tag detection, hash generation, and MLang tag processing.
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
     * Initializes the translation repository, translation service, tagging service, and cache
     * for fetching and updating translations, tagging content, and caching tagged content.
     *
     * @param \context $context The context in which the filter is applied.
     * @param array $options Filter options.
     */
    public function __construct($context, array $options = []) {
        parent::__construct($context, $options);
        global $DB;
        $this->translation_repository = new translation_repository($DB);
        $this->translation_service = new translation_service($DB);
        $this->tagging_service = new tagging_service($DB);
        $this->cache = \cache::make('filter_autotranslate', 'taggedcontent');
    }

    /**
     * Filters the given text, replacing {t:hash} tags with translations and dynamically tagging untagged content.
     *
     * This function processes the input text, identifying {t:hash} tags and replacing them with
     * the appropriate translation based on the user's language. If no translation is available,
     * it falls back to the source text (lang = 'other'). It also dynamically tags untagged content,
     * processes MLang tags, stores the tagged content in the cache, and updates the database with
     * the source text and hash-course mappings.
     *
     * @param string $text The text to filter.
     * @param array $options Filter options (e.g., 'noclean' to allow HTML).
     * @return string The filtered text with {t:hash} tags replaced by translations.
     */
    public function filter($text, array $options = []) {
        global $USER, $DB;

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
                $hash = $match[2]; // Group 2 is the hash.
                $sourcetext = trim($match[1]); // Group 1 is the source text.

                // Update the hash-course mapping for all {t:hash} tags to ensure hid_cids is populated.
                $this->tagging_service->update_hash_course_mapping($fulltag, $courseid);

                // Skip if we've already processed this hash in the current request.
                if (isset($this->processed_hashes[$hash])) {
                    $displaytext = $this->processed_hashes[$hash]['display_text'];
                    $isautotranslated = $this->processed_hashes[$hash]['is_autotranslated'];
                } else {
                    // Fetch the current source text from the database (lang = 'other').
                    $dbsource = $this->translation_repository->get_translation($hash, 'other');
                    $dbsourcetext = $dbsource ? $dbsource->translated_text : 'N/A';

                    // Compare the source text from the content with the database (preserve HTML, only trim whitespace).
                    $trimmedcontenttext = trim($sourcetext);
                    $trimmeddbtext = ($dbsourcetext !== 'N/A') ? trim($dbsourcetext) : '';

                    // Update the source text if it has changed and is not empty.
                    if ($trimmedcontenttext !== '' && $trimmedcontenttext !== $trimmeddbtext) {
                        if ($dbsourcetext === 'N/A') {
                            // Insert a new record for lang = 'other'.
                            $this->translation_service->store_translation(
                                $hash,
                                'other',
                                $sourcetext,
                                $this->context->contextlevel
                            );
                        } else {
                            // Update the existing record.
                            $translation = new \stdClass();
                            $translation->id = $dbsource->id; // Include the ID.
                            $translation->hash = $hash;
                            $translation->lang = 'other';
                            $translation->translated_text = $sourcetext;
                            $translation->contextlevel = $dbsource->contextlevel;
                            $translation->human = 1; // Source text is considered human-edited.
                            $translation->timecreated = $dbsource->timecreated;
                            $translation->timemodified = time();
                            $translation->timereviewed = $dbsource->timereviewed;
                            $this->translation_service->update_translation($translation);
                        }
                    }

                    // Fetch the translation for the user's language, mapping site language to 'other'.
                    $userlang = current_language();
                    $sitelang = get_config('core', 'lang') ?: 'en';
                    $effectivelang = ($userlang === $sitelang) ? 'other' : $userlang;
                    $translation = $this->translation_repository->get_translation($hash, $effectivelang);
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

                    // Check if the translation is auto-translated (human = 0).
                    $isautotranslated = $translation ? $translation->human === 0 : false; // Default to auto if no record.

                    // Cache the result for this hash.
                    $this->processed_hashes[$hash] = [
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

        // If no {t:hash} tags are found, dynamically tag the content.
        $trimmedtext = trim($text);
        if ($trimmedtext === '' || is_numeric($trimmedtext)) {
            return $text; // Skip empty or numeric content.
        }

        // Process MLang tags if present.
        $mlangresult = helper::process_mlang_tags($trimmedtext, $this->context);
        $sourcetext = $mlangresult['source_text'];
        $displaytext = $mlangresult['display_text'];
        $translations = $mlangresult['translations'];

        if (empty($sourcetext)) {
            $sourcetext = $trimmedtext;
            $displaytext = $trimmedtext;
        }

        // Check if the content is already cached.
        $cachekey = md5($sourcetext) . '_' . $this->context->id;
        $cacheddata = $this->cache->get($cachekey);

        if ($cacheddata !== false) {
            // Content is already cached; use the stored tagged content and hash.
            $taggedcontent = $cacheddata['tagged_content'];
            $hash = $cacheddata['hash'];
        } else {
            // Check if the source text already has a hash in the database.
            $existing = $DB->get_record_sql(
                "SELECT hash FROM {autotranslate_translations} WHERE lang = :lang AND " .
                $DB->sql_compare_text('translated_text') . " = " . $DB->sql_compare_text(':text'),
                ['lang' => 'other', 'text' => $sourcetext],
                IGNORE_MULTIPLE
            );

            $hash = $existing ? $existing->hash : helper::generate_unique_hash();

            // Tag the content.
            $taggedcontent = $displaytext . " {t:$hash}";

            // Store the tagged content in the cache.
            $this->cache->set($cachekey, [
                'tagged_content' => $taggedcontent,
                'hash' => $hash,
            ]);

            // Store the source text and translations in the database.
            $courseid = $this->get_courseid_from_context();
            $transaction = $DB->start_delegated_transaction();
            try {
                // Store the source text if it doesn't exist.
                if (!$existing) {
                    $this->translation_service->store_translation($hash, 'other', $sourcetext, $this->context->contextlevel);

                    // Store translations from MLang tags.
                    foreach ($translations as $lang => $translatedtext) {
                        $this->translation_service->store_translation($hash, $lang, $translatedtext, $this->context->contextlevel);
                    }
                }

                // Update the hash-course mapping.
                $this->tagging_service->update_hash_course_mapping($taggedcontent, $courseid);

                $transaction->allow_commit();
            } catch (\Exception $e) {
                $transaction->rollback($e);
                debugging(
                    "Failed to tag content for hash '$hash' in context {$this->context->id}: " . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
                return $text; // Fallback to original text.
            }
        }

        // Process the tagged content (which now has a {t:hash} tag).
        $matches = [];
        if (preg_match_all($pattern, $taggedcontent, $matches, PREG_SET_ORDER)) {
            $replacements = [];
            foreach ($matches as $match) {
                $fulltag = $match[0];
                $hash = $match[2];
                $sourcetext = trim($match[1]);

                // Skip if we've already processed this hash in the current request.
                if (isset($this->processed_hashes[$hash])) {
                    $displaytext = $this->processed_hashes[$hash]['display_text'];
                    $isautotranslated = $this->processed_hashes[$hash]['is_autotranslated'];
                } else {
                    // Fetch the translation for the user's language.
                    $userlang = current_language();
                    $sitelang = get_config('core', 'lang') ?: 'en';
                    $effectivelang = ($userlang === $sitelang) ? 'other' : $userlang;
                    $translation = $this->translation_repository->get_translation($hash, $effectivelang);
                    if (!$translation) {
                        $debugenabled = get_config('filter_autotranslate', 'debugtranslations');
                        if ($debugenabled) {
                            debugging(
                                "Translation not found for hash '$hash' and language '$effectivelang' " .
                                "in context {$this->context->id}. Source text: " . substr($sourcetext, 0, 50) .
                                "...", DEBUG_DEVELOPER
                            );
                        }
                        $displaytext = $this->get_fallback_text($hash, $sourcetext);
                    } else {
                        $displaytext = $translation->translated_text;
                    }

                    // Check if the translation is auto-translated (human = 0).
                    $isautotranslated = $translation ? $translation->human === 0 : false;

                    // Cache the result for this hash.
                    $this->processed_hashes[$hash] = [
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
        return 0; // Default to 0 if course ID cannot be determined.
    }

    /**
     * Fetches the fallback text for a hash if a translation is not available.
     *
     * This function retrieves the source text (lang = 'other') for a given hash as a fallback
     * when a translation in the user's language is not found. If no source text exists, it returns
     * the original source text from the content.
     *
     * @param string $hash The hash of the translation.
     * @param string $sourcetext The original source text before the {t:hash} tag.
     * @return string The fallback text (source text or original text).
     */
    private function get_fallback_text($hash, $sourcetext) {
        $fallback = $this->translation_repository->get_source_text($hash);
        return $fallback !== 'N/A' ? $fallback : $sourcetext;
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
        // Use Font Awesome globe icon with solid style.
        $label = '<span class="text-secondary font-italic small mr-1">' .
            get_string('autotranslated', 'filter_autotranslate') .
            '</span>';
        $icon = '<span class="text-secondary">' . $OUTPUT->pix_icon('i/siteevent', 'Autotranslated', 'moodle') . '</span>';
        return '<div class="text-right">' . $label . $icon . '</div>';
    }
}
