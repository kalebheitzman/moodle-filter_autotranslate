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

/**
 * Autotranslate filter extended libs
 *
 * @package      filter_autotranslate
 * @copyright    2022 Kaleb Heitzman <kaleb@jamfire.io>
 * @license      http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Add Translate Course to course settings menu.
 *
 * @package      filter_autotranslate
 * @param object $navigation
 * @param object $course
 * @return void
 */
function filter_autotranslate_extend_navigation_course($navigation, $course) {

    // Get langs.
    $sitelang = get_config('core', 'lang');
    $currentlang = current_language();

    // Build a moodle url.
    $manageurl = new moodle_url(
        "/filter/autotranslate/manage.php?targetlang=$currentlang&limit=500&instanceid=$course->id"
    );

    // Get title of translate page for navigation menu.
    $managetitle = get_string('manage_title', 'filter_autotranslate');

    // Navigation node.
    $managecontent = navigation_node::create(
        $managetitle,
        $manageurl,
        navigation_node::TYPE_CUSTOM,
        $managetitle,
        'autotranslate',
    );
    $navigation->add_node($managecontent);

    // Build a moodle url.
    $glossaryurl = new moodle_url(
        "/filter/autotranslate/glossary.php"
    );

    // Get title of glossary page for navigation menu.
    $glossarytitle = get_string('glossary_title', 'filter_autotranslate');

    // Navigation node.
    $glossarycontent = navigation_node::create(
        $glossarytitle,
        $glossaryurl,
        navigation_node::TYPE_CUSTOM,
        $glossarytitle,
        'autotranslate',
    );
    $navigation->add_node($glossarycontent);
}

/**
 * Observer class for autotranslate events
 */
class filter_autotranslate_observer {
    private static $fieldmap = [
        'url' => ['name', 'intro'],
        'page' => ['name', 'intro', 'content'],
        'forum' => ['name', 'intro'],
        'wiki' => ['name', 'intro'],
        'label' => ['summary'],
    ];

    public static function handle_course_module_updated(\core\event\course_module_updated $event) {
        global $DB;

        error_log("Observer: handle_course_module_updated triggered for cmid={$event->contextinstanceid}");

        $cmid = $event->contextinstanceid;
        $cm = get_coursemodule_from_id('', $cmid);
        if (!$cm) {
            error_log("Observer: No course module found for cmid=$cmid");
            return;
        }
        if (!isset(self::$fieldmap[$cm->modname])) {
            error_log("Observer: No fieldmap for module {$cm->modname}");
            return;
        }

        $table = $cm->modname;
        $fields = self::$fieldmap[$cm->modname];
        $id = $cm->instance;
        $context = context_module::instance($cm->id);

        self::process_update($table, $fields, $id, $event, $context);
    }

    public static function handle_course_section_updated(\core\event\course_section_updated $event) {
        global $DB;

        error_log("Observer: handle_course_section_updated triggered for sectionid={$event->objectid}");

        $table = 'course_sections';
        $fields = ['name', 'summary'];
        $id = $event->objectid;
        $context = $event->get_context();

        self::process_update($table, $fields, $id, $event, $context);
    }

    private static function process_update($table, $fields, $id, $event, $context) {
        global $DB;

        $oldrecord = $event->get_record_snapshot($table, $id);
        if (!$oldrecord) {
            error_log("Observer: No snapshot for $table id=$id");
            return;
        }

        $newrecord = $DB->get_record($table, ['id' => $id], '*', IGNORE_MISSING);
        if (!$newrecord) {
            error_log("Observer: No updated record for $table id=$id");
            return;
        }

        foreach ($fields as $field) {
            $oldtext = $oldrecord->$field ?? '';
            $newtext = $newrecord->$field ?? '';
            if ($oldtext === $newtext) {
                continue;
            }

            $oldhash = $oldtext ? md5($oldtext) : null;
            $newhash = $newtext ? md5($newtext) : null;

            if ($oldhash && $oldhash !== $newhash) {
                $translations = $DB->get_records('filter_autotranslate', ['hash' => $oldhash]);
                $contexts = $DB->get_records('filter_autotranslate_ctx', ['hash' => $oldhash]);

                if ($translations) {
                    $DB->execute("UPDATE {filter_autotranslate} SET hash = ?, modified_at = ? WHERE hash = ?",
                        [$newhash, time(), $oldhash]);
                    error_log("Observer: Updated filter_autotranslate hash from $oldhash to $newhash for $table.$field id=$id");

                    $sitelang = get_config('core', 'lang');
                    foreach ($translations as $trans) {
                        if ($trans->lang === $sitelang) {
                            $DB->set_field('filter_autotranslate', 'text', $newtext, ['hash' => $oldhash, 'lang' => $sitelang]);
                            error_log("Observer: Updated filter_autotranslate text to '$newtext' for hash=$oldhash, lang=$sitelang");
                        }
                    }

                    $DB->execute("UPDATE {filter_autotranslate_ctx} SET hash = ? WHERE hash = ?",
                        [$newhash, $oldhash]);
                    error_log("Observer: Updated filter_autotranslate_ctx hash from $oldhash to $newhash for contextid={$context->id}");

                    $jobs = $DB->get_records('filter_autotranslate_jobs', ['hash' => $oldhash]);
                    if ($jobs) {
                        $DB->execute("UPDATE {filter_autotranslate_jobs} SET hash = ? WHERE hash = ?",
                            [$newhash, $oldhash]);
                        error_log("Observer: Updated filter_autotranslate_jobs hash from $oldhash to $newhash");
                    }
                }
            }
        }
    }
}

// Debug file loading
error_log("Observer: lib.php loaded for filter_autotranslate");