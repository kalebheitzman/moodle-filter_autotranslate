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
 * Autotranslate Jobs
 *
 * Checks against the job database for unfetched translations
 *
 * @package    filter_autotranslate
 * @copyright  2024 Kaleb Heitzman <kaleb@jamfire.io>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class autotranslate_task extends \core\task\scheduled_task {
    /**
     * Get name of the Autotranslate Task
     */
    public function get_name() {
        return get_string('fetchtask', 'filter_autotranslate');
    }

    /**
     * Execute the Autotranslate Task
     */
    public function execute() {
        global $DB;

        // Get the site language.
        $sitelang = get_config('core', 'lang');

        // Get the fetch limit.
        $fetchlimit = get_config('filter_autotranslate', 'fetchlimit');
        if (!$fetchlimit) {
            $fetchlimit = 200;
        }

        // Your task logic goes here.
        mtrace("Executing autotranslation fetch jobs...");

        // Get the api key from settings.
        $authkey = get_config('filter_autotranslate', 'deeplapikey');
        if (!$authkey) {
            throw new \moodle_exception('missingapikey', 'filter_autotranslate');
        }

        // Load deepl translator.
        $translator = new \DeepL\Translator($authkey);

        // Get 100 existing jobs.
        $jobs = $DB->get_records('filter_autotranslate_jobs', [
            'fetched' => '0',
            'source' => '1',
        ], null, '*', 0, $fetchlimit);
        $jobscount = count($jobs);
        mtrace("$jobscount jobs found...");

        // Iterate through the jos and add translations.
        foreach ($jobs as $job) {
            mtrace("fetching $job->lang translation for $job->hash key...");

            // Get the source text.
            $sourcerecord = $DB->get_record('filter_autotranslate', ['hash' => $job->hash, 'lang' => $sitelang]);
            $targetrecord = $DB->get_record('filter_autotranslate', ['hash' => $job->hash, 'lang' => $job->lang]);

            if ($job->lang === 'en') {
                $job->lang = 'en-US';
            }

            // Get glossary if it exists.
            $glossary = $DB->get_record(
                'filter_autotranslate_gids',
                ['source_lang' => $sitelang, 'target_lang' => $job->lang],
                'glossary_id,name'
            );

            // Set glossaryid if glossary exists.
            $glossaryid = null;
            if ($glossary && $glossary->glossary_id) {
                $glossaryid = $glossary->glossary_id;
                mtrace("retrieved $glossary->name version $glossary->glossary_id");
            }

            // Only translate if text exists.
            if ($sourcerecord) {
                // Get the translation.
                $options = [];
                $options['formality'] = 'prefer_more';
                $options['tag_handling'] = 'html';
                if ($glossaryid) {
                    $options['glossary'] = $glossaryid;
                }
                $translation = $translator->translateText(
                    $sourcerecord->text,
                    $sitelang,
                    $job->lang,
                    $options
                );

                // Insert translation to the db.
                if (!$targetrecord) {
                    $tid = $DB->insert_record(
                        'filter_autotranslate',
                        [
                            'hash' => $job->hash,
                            'lang' => $job->lang,
                            'status' => 0,
                            'text' => $translation->text,
                            'created_at' => time(),
                            'modified_at' => time(),
                        ]
                    );

                    mtrace("added translation to filter_autotranslate with ID $tid");
                }

                // Update the job to fetched.
                $DB->set_field(
                    'filter_autotranslate_jobs',
                    'modified_at',
                    time(),
                    [
                        'id' => $job->id,
                    ]
                );
                $jid = $DB->set_field(
                    'filter_autotranslate_jobs',
                    'fetched',
                    '1',
                    [
                        'id' => $job->id,
                    ]
                );

                mtrace("completed job $job->id...");
            }
        }
    }
}
