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

/**
 * Autotranslate Jobs
 *
 * Checks against the job database for unfetched translations
 *
 * @package    filter_autotranslate
 * @copyright  2024 Kaleb Heitzman <kaleb@jamfire.io>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class check_source_task extends \core\task\scheduled_task {
    /**
     * Get name of the Check Source Task
     */
    public function get_name() {
        return get_string('checksourcetask', 'filter_autotranslate');
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
        mtrace("Executing autotranslation check source tasks...");

        // Get 100 existing jobs.
        $jobs = $DB->get_records('filter_autotranslate_jobs', [
            'fetched' => '0',
            'source' => '0',
        ], null, '*');
        $jobscount = count($jobs);
        mtrace("$jobscount jobs found...");

        // Iterate through the jos and add translations.
        foreach ($jobs as $job) {
            mtrace("checking $job->lang source for $job->hash key...");

            // Get the source text.
            $sourcerecord = $DB->get_record('filter_autotranslate', ['hash' => $job->hash, 'lang' => $sitelang], 'id');
            if ($sourcerecord) {
                // Update the job to fetched.
                mtrace("source for $job->id found...");

                // Set the source to 1.
                $DB->set_field(
                    'filter_autotranslate_jobs',
                    'source',
                    '1',
                    [
                        'id' => $job->id,
                    ]
                );

                // Set the modified time.
                $DB->set_field(
                    'filter_autotranslate_jobs',
                    'modified_at',
                    time(),
                    [
                        'id' => $job->id,
                    ]
                );
            } else {
                // No source record found and we should
                // see if the record should be deleted.
                $currenttime = time();
                $timediff = $currenttime - $job->modified_at;
                $timelimit = 60 * 60 * 24 * 7; // 7 days

                if ($timediff > $timelimit) {
                    mtrace("check source job $job->id for $job->lang has expired, cleaning up...");
                    $DB->delete_records(
                        'filter_autotranslate_jobs',
                        [
                            'id' => $job->id,
                        ]
                    );
                }
            }
        }
    }
}
