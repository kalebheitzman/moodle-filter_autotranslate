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
 * Text utilities for the Autotranslate plugin.
 *
 * Provides stateless helper functions for text processing, MLang parsing, hash generation,
 * and tag detection in the filter_autotranslate plugin.
 *
 * Features:
 * - Hash generation and tagging.
 * - MLang and span tag parsing.
 * - Text extraction and language checks.
 *
 * Usage:
 * - Used by `content_service` for tagging and MLang processing.
 * - Supports `text_filter` for tag detection.
 *
 * Design:
 * - Static methods for simplicity and stateless use.
 * - No database writes, pure utility functions.
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_autotranslate;

/**
 * Utility class for text-related helper functions.
 */
class text_utils {

    /**
     * Generates a unique 10-character alphanumeric hash.
     *
     * Creates a random hash for `{t:hash}` tags, ensuring uniqueness by checking against the
     * `filter_autotranslate_translations` table.
     *
     * @return string A unique 10-character hash (e.g., 'abc1234567').
     * @throws \Exception If a unique hash cannot be generated after 100 attempts.
     */
    public static function generate_unique_hash() {
        global $DB;

        $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $length = 10;
        $max = strlen($characters) - 1;
        $attempts = 0;
        $maxattempts = 100;

        do {
            $hash = '';
            for ($i = 0; $i < $length; $i++) {
                $hash .= $characters[random_int(0, $max)];
            }
            $attempts++;
            if ($attempts >= $maxattempts) {
                throw new \Exception("Unable to generate a unique hash after $maxattempts attempts.");
            }
        } while ($DB->record_exists('filter_autotranslate_translations', ['hash' => $hash]));

        return $hash;
    }

    /**
     * Processes MLang tags in content to extract source text and translations.
     *
     * Parses content for {mlang} tags, extracting the source text for 'other' or the site language,
     * and building a translations array for other languages. Handles both {mlang} and <span> tags.
     *
     * @param string $text The text to process.
     * @param \context $context The context in which the text appears.
     * @return array An array with 'source_text', 'display_text', and 'translations'.
     */
    public function process_mlang_tags($text, $context) {
        $sitelang = get_config('core', 'lang') ?: 'en';
        $validlangs = array_merge(array_keys(get_string_manager()->get_list_of_translations()), ['other']);
        $validlangs = array_map('strtolower', $validlangs);

        $translations = [];
        $sourcetext = '';
        $displaytext = '';
        $firstcontent = null;

        // Process <span> multilang tags first.
        $text = $this->process_span_multilang_tags(
            $text,
            $context,
            $translations,
            $displaytext,
            $validlangs,
            $firstcontent,
            $sourcetext
        );

        // Updated regex to handle HTML and enforce {mlang} pairs.
        if (preg_match_all('/{mlang\s+([\w]+)}(.+?){mlang}/is', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $lang = strtolower(trim($match[1]));
                $content = trim($match[2]);
                if (!in_array($lang, $validlangs)) {
                    continue;
                }
                if ($firstcontent === null) {
                    $firstcontent = $content;
                }
                if ($lang === 'other' || $lang === $sitelang) {
                    $sourcetext .= $content . ' ';
                    $displaytext .= $content . ' ';
                } else {
                    $translations[$lang] = isset($translations[$lang]) ? $translations[$lang] . ' ' . $content : $content;
                }
            }
        } else {
            $sourcetext = $text;
            $displaytext = $text;
        }

        if (!empty($sourcetext)) {
            $sourcetext = trim($sourcetext);
        } else if ($firstcontent !== null) {
            $sourcetext = trim($firstcontent);
            $displaytext = $firstcontent;
        }

        return [
            'source_text' => $sourcetext,
            'display_text' => $displaytext,
            'translations' => $translations,
        ];
    }

    /**
     * Processes `<span lang="xx" class="multilang">` tags in content.
     *
     * Extracts translations from `<span>` tags, accumulating source text and translations for
     * `process_mlang_tags`.
     *
     * @param string $text The content to process.
     * @param \context $context The context (unused here).
     * @param array &$translations Reference to store translations.
     * @param string &$outsidetext Reference to accumulate text outside tags.
     * @param array $validlangs List of valid language codes.
     * @param string|null &$firstcontent Reference to the first content found.
     * @param string &$sourcetext Reference to the source text.
     * @return string The processed content.
     */
    public static function process_span_multilang_tags(
        $text,
        $context,
        &$translations,
        &$outsidetext,
        $validlangs,
        &$firstcontent,
        &$sourcetext
    ) {
        $sitelang = get_config('core', 'lang') ?: 'en';
        $lastpos = 0;
        $displaytext = '';

        $pattern = '/<span lang="([a-zA-Z-]+)" class="multilang">(.*?)<\/span>/s';
        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $lang = strtolower($match[1]);
                $content = trim($match[2]);
                $startpos = strpos($text, $match[0], $lastpos);
                $outside = substr($text, $lastpos, $startpos - $lastpos);

                if (!empty($outside)) {
                    $outsidetext .= $outside;
                    $displaytext .= $outside;
                }
                $lastpos = $startpos + strlen($match[0]);

                if (!in_array($lang, $validlangs)) {
                    continue;
                }

                if ($firstcontent === null) {
                    $firstcontent = $content;
                }

                if ($lang === $sitelang) {
                    $sourcetext .= $content . ' ';
                    $displaytext .= $content . ' ';
                } else {
                    $translations[$lang] = isset($translations[$lang]) ? $translations[$lang] . ' ' . $content : $content;
                }
            }

            $remaining = substr($text, $lastpos);
            if (!empty($remaining)) {
                $outsidetext .= $remaining;
                $displaytext .= $remaining;
            }
            return $displaytext;
        }

        return $text;
    }

    /**
     * Tags content with a hash if untagged.
     *
     * Adds a `{t:hash}` tag to content, reusing existing hashes if the text matches a stored record,
     * or generating a new one via `generate_unique_hash`.
     *
     * @param string $text The content to tag.
     * @param \context $context The context (unused here, included for consistency).
     * @return string The tagged content (e.g., "Hello {t:abc1234567}").
     */
    public static function tag_content($text, $context) {
        global $DB;

        $content = trim($text);
        $params = ['lang' => 'other', 'text' => $content];
        $sql = "SELECT hash FROM {filter_autotranslate_translations}
                WHERE lang = :lang AND " . $DB->sql_compare_text('translated_text') . " = " . $DB->sql_compare_text(':text');
        $existing = $DB->get_record_sql($sql, $params, IGNORE_MULTIPLE);

        $hash = $existing ? $existing->hash : static::generate_unique_hash();
        return "$content {t:$hash}";
    }

    /**
     * Checks if content is tagged with `{t:hash}`.
     *
     * Detects the presence of a `{t:hash}` tag in content using a regular expression.
     *
     * @param string $content The content to check.
     * @return bool True if tagged, false otherwise.
     */
    public static function is_tagged($content) {
        return preg_match('/{t:[a-zA-Z0-9]{10}\}/', $content) === 1;
    }

    /**
     * Extracts the hash from tagged content.
     *
     * Retrieves the 10-character hash from a `{t:hash}` tag, handling optional trailing HTML.
     *
     * @param string $content The tagged content.
     * @return string|null The hash (e.g., 'abc1234567'), or null if not found.
     */
    public static function extract_hash($content) {
        if (preg_match('/{t:([a-zA-Z0-9]{10})\}/', $content, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Extracts untagged text from content containing a {t:hash} tag.
     *
     * Removes the {t:hash} tag from the content and returns the trimmed untagged text.
     *
     * @param string $content The content with a potential {t:hash} tag.
     * @return string The untagged text.
     */
    public static function extract_hashed_text($content) {
        return trim(preg_replace('/\{t:[a-zA-Z0-9]{10}\}/', '', $content));
    }

    /**
     * Maps a language code to 'other' if it matches the site language.
     *
     * Compares the provided language code to the site’s configured language and returns 'other'
     * if they match, indicating it’s the source text language. Otherwise, returns the original code.
     *
     * @param string $lang The language code to map (e.g., 'en', 'es').
     * @return string The mapped language code ('other' or the original $lang).
     */
    public static function map_language_to_other($lang) {
        $sitelang = get_config('core', 'lang') ?: 'en';
        return ($lang === $sitelang) ? 'other' : $lang;
    }

    /**
     * Determines if a language is right-to-left (RTL).
     *
     * Checks if the given language code corresponds to an RTL language (e.g., Arabic, Hebrew),
     * useful for adjusting UI rendering in management interfaces.
     *
     * @param string $lang The language code to check (e.g., 'ar', 'en').
     * @return bool True if the language is RTL, false otherwise.
     */
    public static function is_rtl_language($lang) {
        // List of common RTL language codes (expand as needed).
        $rtllangs = ['ar', 'he', 'fa', 'ur']; // Arabic, Hebrew, Persian, Urdu.
        return in_array(strtolower($lang), $rtllangs);
    }
}
