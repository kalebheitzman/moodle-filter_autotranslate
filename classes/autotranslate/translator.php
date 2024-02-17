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

namespace filter_autotranslate\autotranslate;

// Load the files we're going to need.
defined('MOODLE_INTERNAL') || die();

// Load the files we're going to need.
require_once(dirname(__DIR__, 2) . "/vendor/autoload.php");

class translator {
    /**
     * @param \DeepL\Translator $this->translator DeepL Translator
     */
    private \DeepL\Translator $translator;

    /**
     * @param array $this->sourcelangs Supported Source Languages
     */
    public array $sourcelangs;

    /**
     * @param array $this->targetlangs Supported Target Languages
     */
    public array $targetlangs;

    /**
     * @param array $this->glossarylangs Glossary Languages
     */
    public array $glossarylangs;

    /**
     * @param array $this->langs Supported Moodle Langs
     */
    public array $langs;

    /**
     * Class Construct.
     */
    public function __construct() {

        // Get the api key from settings.
        $authkey = get_config('filter_autotranslate', 'deeplapikey');
        if (!$authkey) {
            throw new \moodle_exception('missingapikey', 'filter_autotranslate');
        }

        // Load deepl translator.
        $this->translator = new \DeepL\Translator($authkey);
        $this->langs = get_string_manager()->get_list_of_translations();
        $this->sourcelangs = $this->getsupportedsourcelangs();
        $this->targetlangs = $this->getsupportedtargetlangs();
        $this->glossarylangs = $this->getsupportedglossarylangs();
    }

    /**
     * Get Supported Source Langs
     */
    private function getsupportedsourcelangs() {
        $sourcelangs = $this->translator->getSourceLanguages();

        $supportedsourcelangs = [];
        foreach ($sourcelangs as $lang) {
            if (!empty($this->langs[strtolower($lang->code)])) {
                array_push($supportedsourcelangs, $this->langs[strtolower($lang->code)]);
            }
        }

        return $supportedsourcelangs;
    }

    /**
     * Get Supported Target Langs
     */
    private function getsupportedtargetlangs() {
        $targetlangs = $this->translator->getTargetLanguages();

        $supportedtargetlangs = [];
        foreach ($targetlangs as $lang) {
            if (!empty($this->langs[strtolower($lang->code)])) {
                array_push($supportedtargetlangs, $this->langs[strtolower($lang->code)]);
            }
        }

        return $supportedtargetlangs;
    }

    /**
     * Get Supported Glossary Languages
     *
     * This function relies on the Moodle
     * site language be set as the sourceLang
     */
    private function getsupportedglossarylangs() {
        $sitelang = get_config('core', 'lang', PARAM_NOTAGS);
        $glossarylangs = $this->translator->getGlossaryLanguages();

        $supportedglossarylangs = [];

        // Set the site language.
        $supportedglossarylangs[$sitelang] = $this->langs[$sitelang];

        // Get the supported languages.
        foreach ($glossarylangs as $glossarylang) {
            if ($glossarylang->sourceLang === $sitelang && array_key_exists($glossarylang->targetLang, $this->langs)) {
                $supportedglossarylangs[$glossarylang->targetLang] = $this->langs[$glossarylang->targetLang];
            }
        }
        return $supportedglossarylangs;
    }
}
