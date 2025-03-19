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
 * Autotranslate Manage Form
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_autotranslate\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class manage_form extends \moodleform {
    public function definition() {
        $mform = $this->_form;
        $filter_lang = $this->_customdata['filter_lang'] ?? '';
        $filter_human = $this->_customdata['filter_human'] ?? '';
        $filter_needsreview = $this->_customdata['filter_needsreview'] ?? '';
        $perpage = $this->_customdata['perpage'] ?? 20; // Default to 20 if not set
        $baseurl = $this->_customdata['baseurl'] ?? new \moodle_url('/filter/autotranslate/manage.php');

        // Ensure $filter_human is a string and trim any whitespace
        $filter_human = trim((string)$filter_human);
        // debugging("Processed filter_human: '$filter_human' (from customdata: '{$this->_customdata['filter_human']}'), Type: " . gettype($filter_human), DEBUG_DEVELOPER);

        // Get site language and target languages
        $sitelang = get_config('core', 'lang') ?: 'en';
        $targetlangs = get_config('filter_autotranslate', 'targetlangs') ?: '';
        $targetlangs = !empty($targetlangs) ? array_filter(array_map('trim', explode(',', $targetlangs))) : [];

        // Build filter options: "All" plus site language (mapped to 'other') and target languages
        $lang_options = ['all' => get_string('all', 'filter_autotranslate')];
        $lang_options['other'] = strtoupper($sitelang); // Map 'other' to site language for display
        foreach ($targetlangs as $lang) {
            $lang_options[$lang] = strtoupper($lang); // Add target languages
        }

        // Language filter buttons
        $mform->addElement('hidden', 'filter_lang', $filter_lang);
        $mform->setType('filter_lang', PARAM_RAW);
        $filter_buttons = [];
        foreach ($lang_options as $value => $label) {
            $url = new \moodle_url($baseurl, ['filter_lang' => $value, 'page' => 0, 'filter_human' => $filter_human, 'filter_needsreview' => $filter_needsreview, 'perpage' => $perpage]);
            // Highlight "All" button if filter_lang is empty
            $class = ($filter_lang === $value || ($value === 'all' && empty($filter_lang))) ? 'btn btn-primary' : 'btn btn-secondary';
            // debugging("Filter lang: '$filter_lang', Value: '$value', Class: $class", DEBUG_DEVELOPER);
            $filter_buttons[] = \html_writer::link($url, $label, ['class' => $class . ' mr-1']);
        }
        $mform->addElement('static', 'lang_filter', get_string('filterbylanguage', 'filter_autotranslate'), implode(' ', $filter_buttons));

        // Human reviewed filter buttons
        $human_options = [
            '' => get_string('all', 'filter_autotranslate'),
            '1' => get_string('yes', 'filter_autotranslate'),
            '0' => get_string('no', 'filter_autotranslate')
        ];
        $mform->addElement('hidden', 'filter_human', $filter_human);
        $mform->setType('filter_human', PARAM_RAW);
        $human_buttons = [];
        foreach ($human_options as $value => $label) {
            $url = new \moodle_url($baseurl, ['filter_human' => $value, 'page' => 0, 'filter_lang' => $filter_lang, 'filter_needsreview' => $filter_needsreview, 'perpage' => $perpage]);
            $class = ($filter_human == $value) ? 'btn btn-primary' : 'btn btn-secondary';
            // debugging("Filter human: '$filter_human' (Type: " . gettype($filter_human) . "), Value: '$value' (Type: " . gettype($value) . "), Class: $class", DEBUG_DEVELOPER);
            $human_buttons[] = \html_writer::link($url, $label, ['class' => $class . ' mr-1']);
        }
        $mform->addElement('static', 'human_filter', get_string('filterbyhumanreviewed', 'filter_autotranslate'), implode(' ', $human_buttons));

        // Needs review filter buttons
        $needsreview_options = [
            '' => get_string('all', 'filter_autotranslate'),
            '1' => get_string('yes', 'filter_autotranslate'),
            '0' => get_string('no', 'filter_autotranslate')
        ];
        $mform->addElement('hidden', 'filter_needsreview', $filter_needsreview);
        $mform->setType('filter_needsreview', PARAM_RAW);
        $needsreview_buttons = [];
        foreach ($needsreview_options as $value => $label) {
            $url = new \moodle_url($baseurl, ['filter_needsreview' => $value, 'page' => 0, 'filter_lang' => $filter_lang, 'filter_human' => $filter_human, 'perpage' => $perpage]);
            $class = ($filter_needsreview == $value) ? 'btn btn-primary' : 'btn btn-secondary';
            // debugging("Filter needsreview: '$filter_needsreview' (Type: " . gettype($filter_needsreview) . "), Value: '$value' (Type: " . gettype($value) . "), Class: $class", DEBUG_DEVELOPER);
            $needsreview_buttons[] = \html_writer::link($url, $label, ['class' => $class . ' mr-1']);
        }
        $mform->addElement('static', 'needsreview_filter', get_string('filterbyneedsreview', 'filter_autotranslate'), implode(' ', $needsreview_buttons));

        // Limit (perpage) filter buttons
        $limit_options = [
            10 => '10',
            20 => '20',
            50 => '50',
            100 => '100',
            250 => '250',
        ];
        $mform->addElement('hidden', 'perpage', $perpage);
        $mform->setType('perpage', PARAM_INT);
        $limit_buttons = [];
        foreach ($limit_options as $value => $label) {
            $url = new \moodle_url($baseurl, ['perpage' => $value, 'page' => 0, 'filter_lang' => $filter_lang, 'filter_human' => $filter_human, 'filter_needsreview' => $filter_needsreview]);
            $class = ($perpage == $value) ? 'btn btn-primary' : 'btn btn-secondary';
            // debugging("Limit: '$perpage', Value: '$value', Class: $class", DEBUG_DEVELOPER);
            $limit_buttons[] = \html_writer::link($url, $label, ['class' => $class . ' mr-1']);
        }
        $mform->addElement('static', 'limit_filter', get_string('perpage', 'filter_autotranslate'), implode(' ', $limit_buttons));
    }
}