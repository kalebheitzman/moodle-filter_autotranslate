<?php
namespace filter_autotranslate;

defined('MOODLE_INTERNAL') || die();

class translation_manager {
    private $repository;

    public function __construct(translation_repository $repository) {
        $this->repository = $repository;
    }

    public function get_paginated_translations($page, $perpage, $filter_lang, $filter_human, $sort, $dir, $courseid = 0) {
        $sitelang = get_config('core', 'lang') ?: 'en';
        $result = $this->repository->get_paginated_translations($page, $perpage, $filter_lang, $filter_human, $sort, $dir, $sitelang, $courseid);
        $translations = $result['translations'];
        $total = $result['total'];

        // Post-process translations to set source_text default
        foreach ($translations as $translation) {
            $translation->source_text = $translation->source_text ?: 'N/A';
        }

        return ['translations' => $translations, 'total' => $total];
    }

    public function update_human_status($translationid, $human) {
        $translation = $this->repository->get_translation($translationid, null);
        if ($translation) {
            $translation->human = $human;
            $translation->timemodified = time();
            $this->repository->update_translation($translation);
        }
    }
}