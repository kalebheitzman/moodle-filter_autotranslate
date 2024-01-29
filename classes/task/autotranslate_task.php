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

require_once(dirname(__DIR__, 4) . '/config.php');
require_once(dirname(__DIR__, 2) . "/vendor/autoload.php");

// Load the files we're going to need.
defined('MOODLE_INTERNAL') || die();

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
        $site_lang = get_config('core', 'lang');

        // Your task logic goes here
        mtrace('Executing the autotranslation fetch...');

        // get the api key from settings
        $authKey = get_config('filter_autotranslate', 'deeplapikey');
        if (!$authKey) {
            throw new \moodle_exception('missingapikey', 'filter_autotranslate');
        }

        // load deepl translator
        $translator = new \DeepL\Translator($authKey);

        // get 100 existing jobs
        $jobs = $DB->get_records('filter_autotranslate_jobs', array('fetched' => '0'), null, '*', 0, 100);

        // iterate through the jos and add translations
        foreach($jobs as $job) {
            mtrace("fetching $job->lang translation for $job->hash key...");

            // get the source text
            $source_record = $DB->get_record('filter_autotranslate', array('hash' => $job->hash, 'lang' => $site_lang));
            $target_record = $DB->get_record('filter_autotranslate', array('hash' => $job->hash, 'lang' => $job_lang));

            // only translate if text exists
            if ($source_record->text) {
                // get the translation
                $translation = $translator->translateText($source_record->text, $site_lang, $job->lang, ['formality' => 'prefer_more']);

                // insert translation to the db
                if (!$target_record) {
                    $tid = $DB->insert_record(
                        'filter_autotranslate',
                        array(
                            'hash' => $job->hash,
                            'lang' => $job->lang,
                            'text' => $translation->text,
                            'created_at' => time(),
                            'modified_at' => time()
                        )
                    );

                    mtrace("added translation to filter_autotranslate with ID $tid");
                }

                // update the job to fetched
                $jid = $DB->update_record(
                    'filter_autotranslate_jobs',
                    array(
                        'id' => $job->id,
                        'fetched' => "1"
                    )
                );

                mtrace("completed job $job->id...");


            } else if (!$source_record->text) {
                mtrace('source text empty...');
            }
        }

    }
}