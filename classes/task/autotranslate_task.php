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

require_once(dirname(__DIR__, 4) . '/config.php');
require_once(dirname(__DIR__, 2) . "/vendor/autoload.php");

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
    public function get_name() {
        return get_string('taskname', 'filter_autotranslate');
    }

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
            'source_missing' => '0',
        ], null, '*', 0, $fetchlimit);
        $jobscount = count($jobs);
        mtrace("$jobscount jobs found...");

        // Iterate through the jos and add translations.
        foreach ($jobs as $job) {
            mtrace("fetching $job->lang translation for $job->hash key...");

            // Get the source text.
            $sourcerecord = $DB->get_record('filter_autotranslate', ['hash' => $job->hash, 'lang' => $sitelang]);
            $targetrecord = $DB->get_record('filter_autotranslate', ['hash' => $job->hash, 'lang' => $job->lang]);

            // Only translate if text exists.
            if ($sourcerecord) {
                // Get the translation.
                $translation = $translator->translateText($sourcerecord->text, null, $job->lang, ['formality' => 'prefer_more']);

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
                $jid = $DB->update_record(
                    'filter_autotranslate_jobs',
                    [
                        'id' => $job->id,
                        'fetched' => "1",
                    ]
                );

                mtrace("completed job $job->id...");
            } else if (!$sourcerecord) {
                // Update the job to fetched.
                $jid = $DB->update_record(
                    'filter_autotranslate_jobs',
                    [
                        'id' => $job->id,
                        'source_missing' => "1",
                    ]
                );
            }
        }
    }
}
