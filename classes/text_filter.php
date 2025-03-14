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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Auto Translate Filter
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_autotranslate;

defined('MOODLE_INTERNAL') || die();

class text_filter extends \core_filters\text_filter {

    /**
     * Apply the filter to the text.
     *
     * @param string $text The text to filter
     * @param array $options Filter options
     * @return string The filtered text
     */
    public function filter($text, array $options = []) {
        global $DB, $USER;

        if (empty($text) || is_numeric($text)) {
            return $text; // Skip empty or numeric text
        }

        // Use regex to find all {translation hash=...}{/translation} tags
        $pattern = '/{translation hash=([a-zA-Z0-9]{10})}(.*?){\/translation}/s';
        if (!preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            return $text; // No tags found, return original text
        }

        $replacements = [];
        foreach ($matches as $match) {
            $fulltag = $match[0];
            $hash = $match[1];
            $source_text = trim($match[2]);

            // Get the user's current language
            $userlang = current_language();

            // Fetch the translation for the user's language
            $translation = $DB->get_record('autotranslate_translations', ['hash' => $hash, 'lang' => $userlang], 'translated_text');
            if ($translation && !empty($translation->translated_text)) {
                $display_text = $translation->translated_text;
            } else {
                // Fall back to the 'other' language (source text)
                $fallback = $DB->get_record('autotranslate_translations', ['hash' => $hash, 'lang' => 'other'], 'translated_text');
                $display_text = $fallback ? $fallback->translated_text : $source_text;
            }

            // Determine if HTML is allowed (e.g., avoid headings or plain text areas)
            $allowhtml = !empty($options['noclean']) || ($this->context->contextlevel !== CONTEXT_COURSE && $this->context->contextlevel !== CONTEXT_MODULE);

            // Add edit indicator (HTML link where safe, plain text otherwise)
            $editindicator = '';
            if (has_capability('filter/autotranslate:edit', $this->context, $USER)) {
                if ($allowhtml) {
                    $editurl = new \moodle_url('/filter/autotranslate/edit.php', ['hash' => $hash, 'contextid' => $this->context->id]);
                    $editindicator = \html_writer::link($editurl, ' [Translate]', ['class' => 'autotranslate-edit-link']);
                } else {
                    $editindicator = ''; // Plain text for sanitized areas
                }
            }

            // Replace the tag with the display text and edit indicator
            $replacements[$fulltag] = $display_text . $editindicator;
        }

        // Replace all matches in the text
        foreach ($replacements as $tag => $replacement) {
            $text = str_replace($tag, $replacement, $text);
        }

        return $text;
    }
}