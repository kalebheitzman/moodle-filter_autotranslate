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

class text_filter extends \core_filters\text_filter {
    public function filter($text, array $options = []) {
        global $DB, $USER;

        if (empty($text) || is_numeric($text)) {
            return $text;
        }

        // Get configured contexts
        $selectedctx = get_config('filter_autotranslate', 'selectctx');
        $selectedctx = $selectedctx ? array_map('trim', explode(',', $selectedctx)) : ['40', '50', '70', '80'];
        $currentcontext = $this->context->contextlevel;
        debugging("Filtering context: $currentcontext, Allowed contexts: " . implode(', ', $selectedctx), DEBUG_DEVELOPER);

        if (!in_array((string)$currentcontext, $selectedctx)) {
            return $text;
        }

        $pattern = '/{translation hash=([a-zA-Z0-9]{10})}(.*?){\/translation}/s';
        $matches = [];
        if (!preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            return $text; // No tags to process
        }

        $replacements = [];
        foreach ($matches as $match) {
            $fulltag = $match[0];
            $hash = $match[1];
            $source_text = trim($match[2]);

            $userlang = current_language();
            $translation = $DB->get_record('autotranslate_translations', ['hash' => $hash, 'lang' => $userlang], 'translated_text');
            $display_text = $translation ? $translation->translated_text : $this->get_fallback_text($hash, $source_text);

            $allowhtml = !empty($options['noclean']) || ($this->context->contextlevel !== CONTEXT_COURSE && $this->context->contextlevel !== CONTEXT_MODULE);
            $editindicator = $this->get_edit_indicator($hash, $allowhtml);

            $replacements[$fulltag] = $display_text . $editindicator;
        }

        foreach ($replacements as $tag => $replacement) {
            $text = str_replace($tag, $replacement, $text);
        }

        return $text;
    }

    private function get_fallback_text($hash, $source_text) {
        global $DB;
        $fallback = $DB->get_record('autotranslate_translations', ['hash' => $hash, 'lang' => 'other'], 'translated_text');
        return $fallback ? $fallback->translated_text : $source_text;
    }

    private function get_edit_indicator($hash, $allowhtml) {
        global $USER;
        $editindicator = '';
        if (has_capability('filter/autotranslate:edit', $this->context, $USER)) {
            if ($allowhtml) {
                $editurl = new \moodle_url('/filter/autotranslate/edit.php', [
                    'hash' => $hash,
                    'lang' => current_language(),
                ]);
                $editindicator = \html_writer::link($editurl, ' [Translate]', ['class' => 'autotranslate-edit-link']);
            }
        }
        return $editindicator;
    }
}