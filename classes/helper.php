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
 * Autotranslate Helpers
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace filter_autotranslate;

// Import global functions.
use function get_config;
use function get_string_manager;
use function random_int;

/**
 * Utility class providing helper functions for the filter_autotranslate plugin.
 *
 * Purpose:
 * This class contains pure utility functions for string manipulation, hash generation,
 * tag detection, MLang tag processing, and language mapping. It is designed to handle common
 * operations that do not involve database interactions, ensuring a clear separation of concerns.
 * Database operations (e.g., storing translations, updating hash-course mappings) are
 * handled by dedicated service classes (tagging_service.php, translation_service.php).
 *
 * Design Decisions:
 * - Focuses on utility functions only, with no database operations, to adhere to the
 *   principle of separation of concerns.
 * - Function names use snake_case (e.g., generate_unique_hash) to follow Moodle's coding style.
 * - The process_mlang_tags function no longer performs database writes; it returns processed
 *   data for the caller to handle (e.g., tagging_service.php inserts translations).
 * - Functions are designed to be reusable across the plugin (e.g., by tagcontent_task.php,
 *   text_filter.php, and service classes).
 *
 * Dependencies:
 * - None (pure utility functions).
 */
class helper {

    /**
     * Generates a unique 10-character alphanumeric hash.
     *
     * This function creates a random hash to be used in {t:hash} tags for tagging content.
     * The hash is checked against the autotranslate_translations table to ensure uniqueness.
     *
     * @return string A unique 10-character hash.
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
        } while ($DB->record_exists('autotranslate_translations', ['hash' => $hash]));

        return $hash;
    }

    /**
     * Processes {mlang} and <span lang="xx" class="multilang"> tags in content.
     *
     * This function extracts translations from MLang tags ({mlang xx}...{mlang}) and
     * <span lang="xx" class="multilang"> tags, returning the source text, display text,
     * and translations. It no longer performs database operations; the caller (e.g., tagging_service.php)
     * is responsible for storing translations.
     *
     * @param string $text The content to process.
     * @param \context $context The context object (used for contextlevel in translations).
     * @return array An array containing:
     *               - 'source_text': The extracted source text (site language or 'other').
     *               - 'display_text': The text to display (preserving HTML structure).
     *               - 'translations': An array of translations (lang => text).
     */
    public static function process_mlang_tags($text, $context) {
        $sitelang = get_config('core', 'lang') ?: 'en';
        // Get the list of installed language packs.
        $installedlangs = array_keys(get_string_manager()->get_list_of_translations());
        $validlangs = array_merge($installedlangs, ['other']); // Include 'other' as a valid language code.

        // Normalize valid language codes to lowercase.
        $validlangs = array_map('strtolower', $validlangs);

        $translations = [];
        $sourcetext = '';
        $displaytext = ''; // To preserve HTML structure.
        $firstcontent = null; // Store the first language content as a fallback.

        // Process old-style <span lang="xx" class="multilang"> tags.
        $text = self::process_span_multilang_tags(
            $text,
            $context,
            $translations,
            $displaytext,
            $validlangs,
            $firstcontent,
            $sourcetext
        );

        // Process new-style {mlang xx}...{mlang} tags.
        if (preg_match_all('/{mlang\s+([\w]+)}(.+?)(?:{mlang}|$)/s', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $lang = trim($match[1]);
                $content = trim($match[2]);

                // Normalize language code to lowercase.
                $lang = strtolower($lang);

                // Validate the language code.
                if (!in_array($lang, $validlangs)) {
                    continue;
                }

                if ($firstcontent === null) {
                    $firstcontent = $content; // Store the first content as a fallback.
                }

                if ($lang === 'other' || $lang === $sitelang) {
                    $sourcetext .= $content . ' ';
                    $displaytext .= $content . ' ';
                } else {
                    $translations[$lang] = isset($translations[$lang]) ? $translations[$lang] . ' ' . $content : $content;
                }
            }
        } else {
            // If no {mlang} tags, use the text as-is (possibly processed by process_span_multilang_tags).
            $sourcetext = $text;
            $displaytext = $text;
        }

        if (!empty($sourcetext)) {
            $sourcetext = trim($sourcetext);
        } else if ($firstcontent !== null) {
            // Fallback to the first language content if site language or 'other' is not found.
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
     * Processes old-style <span lang="xx" class="multilang"> tags in content.
     *
     * This function extracts translations from <span lang="xx" class="multilang"> tags,
     * returning the display text while populating the translations array. It is a helper
     * function for process_mlang_tags and does not interact with the database.
     *
     * @param string $text The content to process containing multilang span tags.
     * @param \context $context The context object used to determine contextlevel in translations.
     * @param array &$translations Reference to an array where translations for other languages are stored.
     * @param string &$outsidetext Reference to a string where text outside of multilang tags is accumulated.
     * @param array $validlangs List of valid language codes to filter translations.
     * @param string|null &$firstcontent Reference to the first language content as a fallback, or null if not set.
     * @param string &$sourcetext Reference to the source text for translation in the site language.
     * @return string The processed content, either site language content or original content if no tags are found.
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

        // Pattern to match <span lang="xx" class="multilang">...</span>.
        $pattern = '/<span lang="([a-zA-Z-]+)" class="multilang">(.*?)<\/span>/s';
        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $lang = $match[1];
                $content = trim($match[2]);
                $startpos = strpos($text, $match[0], $lastpos);
                $outside = substr($text, $lastpos, $startpos - $lastpos);
                if (!empty($outside)) {
                    $outsidetext .= $outside;
                    $displaytext .= $outside;
                }
                $lastpos = $startpos + strlen($match[0]);

                // Normalize language code to lowercase.
                $lang = strtolower($lang);

                // Validate the language code.
                if (!in_array($lang, $validlangs)) {
                    continue;
                }

                if ($firstcontent === null) {
                    $firstcontent = $content; // Store the first content as a fallback.
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

        return $text; // Return original content if no span multilang tags found.
    }

    /**
     * Tags content with a hash if untagged.
     *
     * This function checks if the content already has a hash in autotranslate_translations,
     * reusing the existing hash for identical strings (after trimming). If no hash exists,
     * it generates a new one using generate_unique_hash. It no longer performs database
     * operations; the caller (e.g., tagging_service.php) is responsible for storing the hash.
     *
     * @param string $text The content to tag.
     * @param \context $context The context object (used for contextlevel in translations).
     * @return string The tagged content with a {t:hash} tag.
     */
    public static function tag_content($text, $context) {
        global $DB;

        $content = trim($text);
        $params = ['lang' => 'other', 'text' => $content];
        $sql = "SELECT hash FROM {autotranslate_translations}
                WHERE lang = :lang AND " . $DB->sql_compare_text('translated_text') . " = " . $DB->sql_compare_text(':text');
        $existing = $DB->get_record_sql($sql, $params, IGNORE_MULTIPLE);

        $hash = $existing ? $existing->hash : static::generate_unique_hash();

        return $content . " {t:$hash}";
    }

    /**
     * Checks if content is already tagged with a {t:hash} tag.
     *
     * This function uses a regular expression to detect the presence of a {t:hash} tag,
     * allowing for optional trailing HTML tags like </p>.
     *
     * @param string $content The content to check.
     * @return bool True if the content is tagged, false otherwise.
     */
    public static function is_tagged($content) {
        return preg_match('/\{t:[a-zA-Z0-9]{10}\}(?:<\/p>)?/s', $content) === 1;
    }

    /**
     * Extracts the hash from tagged content.
     *
     * This function uses a regular expression to extract the 10-character hash from a {t:hash} tag,
     * handling cases where the tag may be followed by whitespace or HTML tags like </p>.
     *
     * @param string $content The content to parse.
     * @return string|null The extracted hash, or null if not found.
     */
    public static function extract_hash($content) {
        if (preg_match('/\{t:([a-zA-Z0-9]{10})\}(?:\s*(?:<\/p>)?)?$/s', $content, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Checks if a language is right-to-left (RTL).
     *
     * This function determines if a given language code corresponds to a right-to-left language,
     * such as Arabic or Hebrew, to apply appropriate text direction and alignment in the UI.
     *
     * @param string $lang The language code (e.g., 'en', 'ar', 'he').
     * @return bool True if the language is RTL, false otherwise.
     */
    public static function is_rtl_language($lang) {
        // List of known RTL languages in Moodle.
        $rtllanguages = ['ar', 'he', 'fa', 'ur']; // Arabic, Hebrew, Persian, Urdu.

        // Check if the language is in the RTL list.
        if (in_array($lang, $rtllanguages)) {
            return true;
        }

        // Optionally, check the language pack's configuration (if available)
        // This requires access to the language pack's langconfig.php, which is not directly accessible
        // For now, rely on the predefined list above.
        return false;
    }

    /**
     * Maps a language code to 'other' if it matches the site language.
     *
     * This function ensures consistent language handling across the plugin by mapping the site
     * language to 'other', as stored in autotranslate_translations.
     *
     * @param string $lang The language code to map (e.g., 'en', 'es').
     * @return string The mapped language code ('other' if it matches the site language, otherwise the original code).
     */
    public static function map_language_to_other($lang) {
        $sitelang = get_config('core', 'lang') ?: 'en';
        return ($lang === $sitelang) ? 'other' : $lang;
    }
}
