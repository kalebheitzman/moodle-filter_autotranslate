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
    public function get_name() {
        return get_string('synctask', 'filter_autotranslate');
    }

    public function execute() {
        global $DB;

        // Get the site language.
        $sitelang = get_config('core', 'lang');

        // Your task logic goes here.
        mtrace("Executing sync glossaries task...");
    }
}
