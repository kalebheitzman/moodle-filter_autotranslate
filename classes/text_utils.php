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
 * and tag handling. Supports content tagging and translation workflows without database writes.
 *
 * Features:
 * - Generates unique 10-character hashes for {t:hash} tags.
 * - Parses {mlang} and <span> tags to extract source text and translations.
 * - Detects and extracts {t:hash} tags from content.
 *
 * Usage:
 * - Used by `content_service` for tagging and MLang processing.
 * - Supports `text_filter` for tag detection (unused internally).
 * - Called by UI scripts (e.g., `manage.php`) for language mapping and RTL checks.
 *
 * Design:
 * - Static methods for simplicity and stateless operation.
 * - Regex-based parsing for flexibility with HTML content.
 * - No direct database writes, relies on callers for persistence.
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_autotranslate;

/**
 * Utility class for text processing helpers.
 */
class text_utils {
    /**
     * Generates a unique 10-character hash.
     *
     * Creates a random alphanumeric hash for {t:hash} tags, verified unique against
     * `filter_autotranslate_translations`.
     *
     * @return string Unique hash (e.g., 'abc1234567').
     * @throws \Exception If unique hash not generated after 100 attempts.
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
     * Processes {mlang} tags to extract source and translations.
     *
     * Parses content for {mlang} tags, extracting source text ('other' or site language)
     * and translations for other languages. Handles <span> tags via helper method.
     *
     * @param string $text Text to process.
     * @param \context $context Context of the text.
     * @return array ['source_text' => string, 'display_text' => string, 'translations' => array]
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
     * Processes <span lang="xx" class="multilang"> tags.
     *
     * Extracts translations from <span> tags, updating source text and translations array.
     *
     * @param string $text Content to process.
     * @param \context $context Context (unused here).
     * @param array &$translations Reference to store translations.
     * @param string &$outsidetext Reference for text outside tags.
     * @param array $validlangs Valid language codes.
     * @param string|null &$firstcontent Reference to first content found.
     * @param string &$sourcetext Reference to source text.
     * @return string Processed content.
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
     * Tags content with a {t:hash} marker.
     *
     * Adds a {t:hash} tag, reusing existing hashes or generating new ones.
     *
     * @param string $text Content to tag.
     * @param \context $context Context (unused here).
     * @return string Tagged content (e.g., "Hello {t:abc1234567}").
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
     * Checks if content has a {t:hash} tag.
     *
     * @param string $content Content to check.
     * @return bool True if tagged, false otherwise.
     */
    public static function is_tagged($content) {
        return preg_match('/{t:[a-zA-Z0-9]{10}\}/', $content) === 1;
    }

    /**
     * Extracts the hash from tagged content.
     *
     * @param string $content Tagged content.
     * @return string|null Hash (e.g., 'abc1234567') or null if not found.
     */
    public static function extract_hash($content) {
        if (preg_match('/{t:([a-zA-Z0-9]{10})\}/', $content, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Extracts untagged text from content with a {t:hash} tag.
     *
     * @param string $content Content with a {t:hash} tag.
     * @return string Untagged text, trimmed.
     */
    public static function extract_hashed_text($content) {
        return trim(preg_replace('/\{t:[a-zA-Z0-9]{10}\}/', '', $content));
    }

    /**
     * Maps a language code to 'other' if it matches site language.
     *
     * @param string $lang Language code (e.g., 'en').
     * @return string Mapped code ('other' or original).
     */
    public static function map_language_to_other($lang) {
        $sitelang = get_config('core', 'lang') ?: 'en';
        return ($lang === $sitelang) ? 'other' : $lang;
    }

    /**
     * Checks if a language is right-to-left (RTL).
     *
     * @param string $lang Language code (e.g., 'ar').
     * @return bool True if RTL, false otherwise.
     */
    public static function is_rtl_language($lang) {
        // List of common RTL language codes (expand as needed).
        $rtllangs = ['ar', 'he', 'fa', 'ur']; // Arabic, Hebrew, Persian, Urdu.
        return in_array(strtolower($lang), $rtllangs);
    }
}
