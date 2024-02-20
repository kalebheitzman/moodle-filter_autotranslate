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

/**
 * Translator API
 *
 * Extend the DeepL PHP Api with functionality
 * used by this plugin.
 *
 * @package    filter_autotranslate
 * @copyright  2024 Kaleb Heitzman <kaleb@jamfire.io>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class translator {
    /**
     * @var \DeepL\Translator $translator DeepL Translator
     */
    public \DeepL\Translator $translator;

    /**
     * @var array $sourcelangs Supported Source Languages
     */
    public array $sourcelangs;

    /**
     * @var array $targetlangs Supported Target Languages
     */
    public array $targetlangs;

    /**
     * @var array $glossarylangs Glossary Languages
     */
    public array $glossarylangs;

    /**
     * @var array $langs Supported Moodle Langs
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
    }

    /**
     * Get Supported Source Langs
     */
    public function getsupportedsourcelangs() {
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
    public function getsupportedtargetlangs() {
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
    public function getsupportedglossarylangs() {
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

    /**
     * Get DeepL Usage
     */
    public function getusage() {
        return $this->translator->getUsage();
    }

    /**
     * Get Glossaries.
     */
    public function listglossaries() {
        return $this->translator->listGlossaries();
    }
}
