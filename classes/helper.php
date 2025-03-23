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
 * Autotranslate Helpers
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace filter_autotranslate;

defined('MOODLE_INTERNAL') || die();

class helper {
    /**
     * Generate a unique 10-character alphanumeric hash.
     *
     * @return string
     * @throws \Exception
     */
    public static function generate_unique_hash() {
        global $DB;

        $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $length = 10;
        $max = strlen($characters) - 1;
        $attempts = 0;
        $max_attempts = 100;

        do {
            $hash = '';
            for ($i = 0; $i < $length; $i++) {
                $hash .= $characters[random_int(0, $max)];
            }
            $attempts++;
            if ($attempts >= $max_attempts) {
                throw new \Exception("Unable to generate a unique hash after $max_attempts attempts.");
            }
        } while ($DB->record_exists('autotranslate_translations', ['hash' => $hash]));

        return $hash;
    }

    /**
     * Process both {mlang} and <span lang="xx" class="multilang"> tags in content and store translations.
     *
     * @param string $text Content to process
     * @param \context $context Context object
     * @return string Tagged content
     */
    public static function process_mlang_tags($text, $context) {
        global $DB;

        $sitelang = get_config('core', 'lang') ?: 'en';
        // Get the list of installed language packs
        $installed_langs = array_keys(get_string_manager()->get_list_of_translations());
        $valid_langs = array_merge($installed_langs, ['other']); // Include 'other' as a valid language code

        // Normalize valid language codes to lowercase
        $valid_langs = array_map('strtolower', $valid_langs);

        $translations = [];
        $source_text = '';
        $display_text = ''; // To preserve HTML structure
        $first_content = null; // Store the first language content as a fallback

        // First, process old-style <span lang="xx" class="multilang"> tags
        $text = self::process_span_multilang_tags($text, $context, $translations, $display_text, $valid_langs, $first_content, $source_text);

        // Then, process new-style {mlang xx}...{mlang} tags
        if (preg_match_all('/{mlang\s+([\w]+)}(.+?)(?:{mlang}|$)/s', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $lang = trim($match[1]);
                $content = trim($match[2]);

                // Normalize language code to lowercase
                $lang = strtolower($lang);

                // Validate the language code
                if (!in_array($lang, $valid_langs)) {
                    continue;
                }

                if ($first_content === null) {
                    $first_content = $content; // Store the first content as a fallback
                }

                if ($lang === 'other' || $lang === $sitelang) {
                    $source_text .= $content . ' ';
                    $display_text .= $content . ' ';
                } else {
                    $translations[$lang] = isset($translations[$lang]) ? $translations[$lang] . ' ' . $content : $content;
                }
            }
        } else {
            // If no {mlang} tags, use the text as-is (possibly processed by process_span_multilang_tags)
            $source_text = $text;
            $display_text = $text;
        }

        if (!empty($source_text)) {
            $source_text = trim($source_text);
        } elseif ($first_content !== null) {
            // Fallback to the first language content if site language or 'other' is not found
            $source_text = trim($first_content);
            $display_text = $first_content;
        }

        if (!empty($source_text)) {
            $params = ['lang' => 'other', 'text' => $source_text];
            $sql = "SELECT hash FROM {autotranslate_translations} WHERE lang = :lang AND " . $DB->sql_compare_text('translated_text') . " = " . $DB->sql_compare_text(':text');
            $existing = $DB->get_record_sql($sql, $params, IGNORE_MULTIPLE);

            $hash = $existing ? $existing->hash : static::generate_unique_hash();

            if (!$existing) {
                $record = new \stdClass();
                $record->hash = $hash;
                $record->lang = 'other';
                $record->translated_text = $source_text;
                $record->contextlevel = $context->contextlevel;
                $record->timecreated = time();
                $record->timemodified = time();
                $record->timereviewed = time();
                $record->human = 1;

                $DB->insert_record('autotranslate_translations', $record);

                foreach ($translations as $lang => $translated_text) {
                    $trans_record = new \stdClass();
                    $trans_record->hash = $hash;
                    $trans_record->lang = $lang;
                    $trans_record->translated_text = $translated_text;
                    $trans_record->contextlevel = $context->contextlevel;
                    $trans_record->timecreated = time();
                    $trans_record->timemodified = time();
                    $trans_record->timereviewed = time();
                    $trans_record->human = 1;
                    $DB->insert_record('autotranslate_translations', $trans_record);
                }
            }

            return $display_text . " {t:$hash}";
        }

        return $text;
    }

    /**
     * Process old-style <span lang="xx" class="multilang"> tags in content and extract translations.
     *
     * @param string $text Content to process
     * @param \context $context Context object
     * @param array &$translations Array to store translations for other languages
     * @param string &$outside_text String to store text outside of multilang tags
     * @param array $valid_langs List of valid language codes
     * @param string|null &$first_content First language content as a fallback
     * @param string &$source_text Source text for translation
     * @return string The processed content (site language content or original content if no tags)
     */
    public static function process_span_multilang_tags($text, $context, &$translations, &$outside_text, $valid_langs, &$first_content, &$source_text) {
        $sitelang = get_config('core', 'lang') ?: 'en';
        $last_pos = 0;
        $display_text = '';

        // Pattern to match <span lang="xx" class="multilang">...</span>
        $pattern = '/<span lang="([a-zA-Z-]+)" class="multilang">(.*?)<\/span>/s';
        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $lang = $match[1];
                $content = trim($match[2]);
                $start_pos = strpos($text, $match[0], $last_pos);
                $outside = substr($text, $last_pos, $start_pos - $last_pos);
                if (!empty($outside)) {
                    $outside_text .= $outside;
                    $display_text .= $outside;
                }
                $last_pos = $start_pos + strlen($match[0]);

                // Normalize language code to lowercase
                $lang = strtolower($lang);

                // Validate the language code
                if (!in_array($lang, $valid_langs)) {
                    continue;
                }

                if ($first_content === null) {
                    $first_content = $content; // Store the first content as a fallback
                }

                if ($lang === $sitelang) {
                    $source_text .= $content . ' ';
                    $display_text .= $content . ' ';
                } else {
                    $translations[$lang] = isset($translations[$lang]) ? $translations[$lang] . ' ' . $content : $content;
                }
            }

            $remaining = substr($text, $last_pos);
            if (!empty($remaining)) {
                $outside_text .= $remaining;
                $display_text .= $remaining;
            }
            return $display_text;
        }

        return $text; // Return original content if no span multilang tags found
    }

    /**
     * Tag content with a hash if untagged.
     *
     * @param string $text Content to tag
     * @param \context $context Context object
     * @return string Tagged content
     */
    public static function tag_content($text, $context) {
        global $DB;

        $content = trim($text);
        $params = ['lang' => 'other', 'text' => $content];
        $sql = "SELECT hash FROM {autotranslate_translations} WHERE lang = :lang AND " . $DB->sql_compare_text('translated_text') . " = " . $DB->sql_compare_text(':text');
        $existing = $DB->get_record_sql($sql, $params, IGNORE_MULTIPLE);

        $hash = $existing ? $existing->hash : static::generate_unique_hash();

        if (!$existing) {
            $record = new \stdClass();
            $record->hash = $hash;
            $record->lang = 'other';
            $record->translated_text = $content;
            $record->contextlevel = $context->contextlevel;
            $record->timecreated = time();
            $record->timemodified = time();
            $record->timereviewed = time();
            $record->human = 1;

            $DB->insert_record('autotranslate_translations', $record);
        }

        return $content . " {t:$hash}";
    }

    /**
     * Check if content is already tagged.
     *
     * @param string $content Content to check
     * @return bool
     */
    public static function is_tagged($content) {
        return preg_match('/\{t:[a-zA-Z0-9]{10}\}(?:<\/p>)?/s', $content) === 1;
    }

    /**
     * Extract the hash from tagged content.
     *
     * @param string $content The content to parse
     * @return string|null The extracted hash, or null if not found
     */
    public static function extract_hash($content) {
        if (preg_match('/\{t:([a-zA-Z0-9]{10})\}$/', $content, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Updates the autotranslate_hid_cids table with the hash and courseid mapping.
     *
     * @param string $content The tagged content (containing the hash)
     * @param int $courseid The courseid to map
     */
    public static function update_hash_course_mapping($content, $courseid) {
        global $DB;

        $hash = self::extract_hash($content);
        if (!$hash || !$courseid) {
            return;
        }

        $exists = $DB->record_exists('autotranslate_hid_cids', ['hash' => $hash, 'courseid' => $courseid]);
        if (!$exists) {
            try {
                $DB->execute("INSERT INTO {autotranslate_hid_cids} (hash, courseid) VALUES (?, ?) 
                            ON DUPLICATE KEY UPDATE hash = hash", [$hash, $courseid]);
            } catch (\dml_exception $e) {
                // Silent error handling
            }
        }
    }

    /**
     * Create or update the source translation record in mdl_autotranslate_translations.
     *
     * @param string $content The tagged content (containing the hash)
     * @param int $contextlevel The context level
     */
    public static function create_or_update_source_translation($content, $contextlevel) {
        global $DB;

        $hash = self::extract_hash($content);
        if (!$hash) {
            return;
        }

        // Extract the source text by removing the {t:hash} tag
        $source_text = preg_replace('/\{t:[a-zA-Z0-9]{10}\}$/', '', $content);

        // Check if a source translation record already exists
        $existing = $DB->get_record('autotranslate_translations', ['hash' => $hash, 'lang' => 'other']);
        $current_time = time();

        if ($existing) {
            // Update the existing record
            $existing->translated_text = $source_text;
            $existing->contextlevel = $contextlevel;
            $existing->timemodified = $current_time;
            $existing->timereviewed = $existing->timereviewed == 0 ? $current_time : $existing->timereviewed;
            $existing->human = 1; // Human-edited, as per observer requirement
            $DB->update_record('autotranslate_translations', $existing);
        } else {
            // Create a new record
            $record = new \stdClass();
            $record->hash = $hash;
            $record->lang = 'other';
            $record->translated_text = $source_text;
            $record->contextlevel = $contextlevel;
            $record->timecreated = $current_time;
            $record->timemodified = $current_time;
            $record->timereviewed = $current_time;
            $record->human = 1; // Human-edited, as per observer requirement
            $DB->insert_record('autotranslate_translations', $record);
        }
    }

    /**
     * Mark translations as needing revision by updating timemodified and timereviewed.
     *
     * @param string $table Table name (e.g., 'course', 'course_sections', 'mod_*')
     * @param int $instanceid Instance ID (e.g., course ID, section ID, module instance ID)
     * @param array $fields Fields that were updated (e.g., ['name', 'intro'])
     * @param \context $context Context object
     */
    public static function mark_translations_for_revision($table, $instanceid, $fields, $context) {
        global $DB;

        // Find all hashes associated with the updated fields
        $hashes = [];
        $record = $DB->get_record($table, ['id' => $instanceid], implode(',', $fields));
        foreach ($fields as $field) {
            if (empty($record->$field)) {
                continue;
            }

            $content = $record->$field;
            $hash = self::extract_hash($content);
            if ($hash) {
                $hashes[$hash] = true; // Use array to avoid duplicates
            }
        }

        if (empty($hashes)) {
            return;
        }

        // Update timemodified and timereviewed for all translations with these hashes
        $placeholders = implode(',', array_fill(0, count($hashes), '?'));
        $sql = "UPDATE {autotranslate_translations}
                SET timemodified = ?,
                    timereviewed = CASE WHEN timereviewed = 0 THEN ? ELSE timereviewed END
                WHERE hash IN ($placeholders)
                AND contextlevel = ?
                AND lang != 'other'";
        $params = array_merge([time(), time()], array_keys($hashes), [$context->contextlevel]);
        $DB->execute($sql, $params);
    }
}