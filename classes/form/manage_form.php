<?php
namespace filter_autotranslate\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class manage_form extends \moodleform {
    public function definition() {
        $mform = $this->_form;
        $filter_lang = $this->_customdata['filter_lang'] ?? '';
        $filter_human = $this->_customdata['filter_human'] ?? ''; // Use passed value, default to '' if not set
        $baseurl = $this->_customdata['baseurl'] ?? new \moodle_url('/filter/autotranslate/manage.php');

        // Get site language and target languages
        $sitelang = get_config('core', 'lang') ?: 'en';
        $targetlangs = get_config('filter_autotranslate', 'targetlangs') ?: '';
        $targetlangs = !empty($targetlangs) ? array_filter(array_map('trim', explode(',', $targetlangs))) : [];

        // Build filter options: "All" plus site language (mapped to 'other') and target languages
        $lang_options = ['all' => 'All'];
        $lang_options['other'] = $sitelang; // Map 'other' to site language for display
        foreach ($targetlangs as $lang) {
            $lang_options[$lang] = $lang; // Add target languages
        }

        // Add language filter as hidden input with buttons for display
        $mform->addElement('hidden', 'filter_lang', $filter_lang);
        $mform->setType('filter_lang', PARAM_RAW);
        $filter_buttons = [];
        foreach ($lang_options as $value => $label) {
            $url = new \moodle_url($baseurl, ['filter_lang' => $value, 'page' => 0, 'filter_human' => $filter_human]);
            $class = ($filter_lang === $value) ? 'btn btn-primary' : 'btn btn-secondary';
            $filter_buttons[] = \html_writer::link($url, $label, ['class' => $class . ' mr-1']);
        }
        $mform->addElement('static', 'lang_filter', 'Filter by Language', implode(' ', $filter_buttons));

        // Filter by Human Reviewed, honor URL parameter if present
        $options = ['' => 'All', 0 => 'No', 1 => 'Yes'];
        $mform->addElement('select', 'filter_human', 'Filter by Human Reviewed', $options);
        $mform->setDefault('filter_human', $filter_human); // Use the passed value from URL

        $this->add_action_buttons(false, 'Apply Filters');
    }
}