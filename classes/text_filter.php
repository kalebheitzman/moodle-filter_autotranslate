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
        // debugging("Filtering context: $currentcontext, Allowed contexts: " . implode(', ', $selectedctx), DEBUG_DEVELOPER);

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
            $translation = $DB->get_record('autotranslate_translations', ['hash' => $hash, 'lang' => $userlang], 'translated_text, human');
            $display_text = $translation ? $translation->translated_text : $this->get_fallback_text($hash, $source_text);

            // Check if the translation is auto-translated (human = 0)
            $is_autotranslated = $translation ? $translation->human === 0 : false; // Default to auto if no record
            $autotranslated_indicator = $is_autotranslated ? $this->get_autotranslate_indicator() : '';

            $allowhtml = !empty($options['noclean']) || ($this->context->contextlevel !== CONTEXT_COURSE && $this->context->contextlevel !== CONTEXT_MODULE);

            // Rewrite @@PLUGINFILE@@ URLs in the translated text
            $fileparams = $this->get_file_params();
            if ($fileparams) {
                list($component, $filearea, $itemid) = $fileparams;
                $display_text = file_rewrite_pluginfile_urls(
                    $display_text,
                    $filearea,
                    $this->context->id,
                    $component,
                    $itemid,
                    null
                );
            }
            
            $replacements[$fulltag] = $display_text . ($allowhtml ? $autotranslated_indicator : "");
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

    private function get_autotranslate_indicator() {
        global $OUTPUT;
        // Use Font Awesome globe icon with solid style
        $label = '<span class="text-secondary font-italic small mr-1">' . get_string('autotranslated', 'filter_autotranslate') . '</span>';
        $icon = '<span class="text-secondary">' . $OUTPUT->pix_icon('i/siteevent', 'Autotranslated', 'moodle') . '</span>';
        return  '<div class="text-right">' . $label . $icon . '</div>';
    }

    /**
 * Determine the component, file area, and item ID based on the current context.
 * @return array|null [component, filearea, itemid] or null if not applicable
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