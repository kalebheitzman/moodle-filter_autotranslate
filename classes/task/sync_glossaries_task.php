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

namespace filter_autotranslate\task;

defined('MOODLE_INTERNAL') || die();

require_once(dirname(__DIR__, 2) . "/vendor/autoload.php");

use filter_autotranslate\autotranslate\translator;

/**
 * Sync Glossaries Task
 *
 * Checks last modified time against last sync time to
 * update glossaries on DeepL.
 *
 * @package    filter_autotranslate
 * @copyright  2024 Kaleb Heitzman <kaleb@jamfire.io>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_glossaries_task extends \core\task\scheduled_task {
    /**
     * Get the name of Sync Glossaries Task
     */
    public function get_name() {
        return get_string('synctask', 'filter_autotranslate');
    }

    /**
     * Execute the Sync Glossaries Task
     */
    public function execute() {
        global $DB;

        // Instantiate the translator.
        $translator = new translator();

        // Get the site language.
        $sitelang = get_config('core', 'lang');

        // Your task logic goes here.
        mtrace("Executing sync glossaries task...");

        // Get all glossaries.
        $glossaries = $DB->get_records('filter_autotranslate_gids');
        $deeplglossaries = $translator->listglossaries();

        // Go through each glossary to compare
        // the last_sync time with the last_modified time
        // to see if an update is needed.
        foreach ($glossaries as $glossary) {
            // Glossary needs updated.
            if ($glossary->last_sync <= $glossary->modified_at && intval($glossary->last_sync) !== 0) {
                mtrace("Checking $glossary->name...");

                // Get the source terms.
                $sourceterms = $DB->get_records(
                    'filter_autotranslate_gterms',
                    [
                        'lang' => $glossary->source_lang,
                    ],
                    'id ASC',
                    'hash,text',
                    0,
                    5000
                );

                // Get the target terms.
                $targetterms = $DB->get_records(
                    'filter_autotranslate_gterms',
                    [
                        'lang' => $glossary->target_lang,
                    ],
                    'id ASC',
                    'hash,text',
                    0,
                    5000
                );

                // No target terms found.
                if (count($targetterms) === 0) {
                    mtrace("No $glossary->name terms found...");
                    continue;
                }

                // Build the glossary.
                $terms = [];
                foreach ($targetterms as $targetterm) {
                    $sourceterm = $this->findobjectbykeyvalue($sourceterms, 'hash', $targetterm->hash);
                    if ($sourceterm) {
                        $terms[$sourceterm->text] = $targetterm->text;
                    }
                }

                // Build the DeepL Glossary of terms.
                $glossaryterms = \DeepL\GlossaryEntries::fromEntries($terms);

                mtrace("Syncing $glossary->name to DeepL...");

                // Delete the existing glossary.
                // DeepL Glossaries are immutable so you have to
                // delete them and then reupload them each time
                // a change is made.
                if ($glossary->glossary_id) {
                    mtrace("Deleting stale DeepL glossary: $glossary->glossary_id");
                    $translator->translator->deleteGlossary($glossary->glossary_id);
                    $DB->set_field(
                        'filter_autotranslate_gids',
                        'glossary_id',
                        null,
                        ['name' => $glossary->name]
                    );
                }

                // Create the glossary on DeepL.
                $results = $translator->translator->createGlossary(
                    $glossary->name,
                    $glossary->source_lang,
                    $glossary->target_lang,
                    $glossaryterms
                );

                $glossary->glossary_id = $results->glossaryId;
                $glossary->ready = $results->ready;
                $glossary->last_sync = time();
                $glossary->entry_count = $results->entryCount;

                // Update the local glossary record.
                mtrace("Syncing $glossary->name to $results->glossaryId...");
                $DB->update_record(
                    'filter_autotranslate_gids',
                    $glossary
                );
            }
        }
    }

    /**
     * Find Object by Key Value
     *
     * @param array $array Array of objects
     * @param string $key Array Key to reference
     * @param string $value Value to search for
     * @return Object Object found by key value
     */
    private function findobjectbykeyvalue($array, $key, $value) {
        $filteredarray = array_filter($array, function ($object) use ($key, $value) {
            return $object->{$key} === $value;
        });

        // If array_filter finds a match, return the first element; otherwise, return null.
        return reset($filteredarray) ?: null;
    }
}
