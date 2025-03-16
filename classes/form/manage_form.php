<?php
namespace filter_autotranslate\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class manage_form extends \moodleform {
    public function definition() {
        $mform = $this->_form;
        $filter_lang = $this->_customdata['filter_lang'] ?? '';
        $filter_human = $this->_customdata['filter_human'] ?? '';
        $baseurl = $this->_customdata['baseurl'] ?? new \moodle_url('/filter/autotranslate/manage.php');

        // Ensure $filter_human is a string and trim any whitespace
        $filter_human = trim((string)$filter_human);
        debugging("Processed filter_human: '$filter_human' (from customdata: '{$this->_customdata['filter_human']}'), Type: " . gettype($filter_human), DEBUG_DEVELOPER);

        // Get site language and target languages
        $sitelang = get_config('core', 'lang') ?: 'en';
        $targetlangs = get_config('filter_autotranslate', 'targetlangs') ?: '';
        $targetlangs = !empty($targetlangs) ? array_filter(array_map('trim', explode(',', $targetlangs))) : [];

        // Build filter options: "All" plus site language (mapped to 'other') and target languages
        $lang_options = ['all' => 'All'];
        $lang_options['other'] = strtoupper($sitelang); // Map 'other' to site language for display
        foreach ($targetlangs as $lang) {
            $lang_options[$lang] = strtoupper($lang); // Add target languages
        }

        // Language filter buttons
        $mform->addElement('hidden', 'filter_lang', $filter_lang);
        $mform->setType('filter_lang', PARAM_RAW);
        $filter_buttons = [];
        foreach ($lang_options as $value => $label) {
            $url = new \moodle_url($baseurl, ['filter_lang' => $value, 'page' => 0, 'filter_human' => $filter_human]);
            $class = ($filter_lang == $value) ? 'btn btn-primary' : 'btn btn-secondary';
            debugging("Filter lang: '$filter_lang', Value: '$value', Class: $class", DEBUG_DEVELOPER);
            $filter_buttons[] = \html_writer::link($url, $label, ['class' => $class . ' mr-1']);
        }
        $mform->addElement('static', 'lang_filter', 'Filter by Language', implode(' ', $filter_buttons));

        // Human reviewed filter buttons
        $human_options = [
            '' => 'All',
            '1' => 'Yes',
            '0' => 'No'
        ];
        $mform->addElement('hidden', 'filter_human', $filter_human);
        $mform->setType('filter_human', PARAM_RAW);
        $human_buttons = [];
        foreach ($human_options as $value => $label) {
            $url = new \moodle_url($baseurl, ['filter_human' => $value, 'page' => 0, 'filter_lang' => $filter_lang]);
            $class = ($filter_human == $value) ? 'btn btn-primary' : 'btn btn-secondary';
            debugging("Filter human: '$filter_human' (Type: " . gettype($filter_human) . "), Value: '$value' (Type: " . gettype($value) . "), Class: $class", DEBUG_DEVELOPER);
            $human_buttons[] = \html_writer::link($url, $label, ['class' => $class . ' mr-1']);
        }
        $mform->addElement('static', 'human_filter', 'Filter by Human Reviewed', implode(' ', $human_buttons));

        // Remove the Apply Filters button
        // $this->add_action_buttons(false, 'Apply Filters'); // Commented out
    }
}