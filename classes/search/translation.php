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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace filter_autotranslate\search;

/**
 * Auto Translate Search Area
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class translation extends \core_search\base {
    /**
     * Returns the recordset of translations to index.
     *
     * @param int $modifiedfrom Timestamp to filter records modified after this time
     * @param \context|null $context Context to limit the recordset (optional)
     * @return \moodle_recordset
     */
    public function get_document_recordset($modifiedfrom = 0, ?\context $context = null) {
        global $DB;

        $sql = "SELECT id, hash, lang, translated_text, contextlevel, instanceid, timemodified
                FROM {filter_autotranslate_translations}
                WHERE lang != 'other'";
        $params = [];

        // If context is provided, filter by contextlevel and instanceid.
        if ($context) {
            $contextlevel = $context->contextlevel;
            $instanceid = $context->instanceid;
            $sql .= " AND contextlevel = ? AND instanceid = ?";
            $params[] = $contextlevel;
            $params[] = $instanceid;
        }

        $sql .= " ORDER BY timemodified ASC";
        return $DB->get_recordset_sql($sql, $params);
    }

    /**
     * Returns the document associated with this translation record.
     *
     * @param \stdClass $record The translation record
     * @param array $options Additional options
     * @return \core_search\document|false Returns false if context is invalid
     */
    public function get_document($record, $options = []) {
        global $DB;

        // Get the context ID, skip if invalid.
        $contextid = $this->get_context_id($record->contextlevel, $record->instanceid);
        if ($contextid === \context_system::instance()->id && $record->contextlevel != 10) {
            // Skip indexing if context mapping failed (unless it's a System context).
            debugging(
                "Skipping document for contextlevel {$record->contextlevel}, " .
                "instanceid {$record->instanceid} due to invalid context mapping.",
                DEBUG_DEVELOPER
            );
            return false;
        }

        // Create a document for indexing.
        $doc = \core_search\document_factory::instance($record->id, $this->componentname, $this->areaname);

        // Set the document fields.
        $doc->set('title', $record->translated_text);
        $doc->set('content', $record->translated_text);
        $doc->set('contextid', $contextid);
        $doc->set('owneruserid', 0); // System-owned content.
        $doc->set('modified', $record->timemodified);
        $doc->set('description1', 'Translation for hash: ' . $record->hash . ', language: ' . $record->lang);

        // Determine course ID with debugging for invalid cases.
        $courseid = 1; // Default to site course ID.
        if ($record->contextlevel == 50) { // Course context.
            $course = $DB->get_record('course', ['id' => $record->instanceid], 'id', IGNORE_MISSING);
            $courseid = $course ? $record->instanceid : 1;
            if (!$course) {
                debugging(
                    "Course not found for contextlevel 50, instanceid {$record->instanceid}. Using site course ID.",
                    DEBUG_DEVELOPER
                );
            }
        } else if ($record->contextlevel == 70) { // Module context.
            $cm = $DB->get_record('course_modules', ['id' => $record->instanceid], 'course', IGNORE_MISSING);
            if ($cm) {
                $course = $DB->get_record('course', ['id' => $cm->course], 'id', IGNORE_MISSING);
                $courseid = $course ? $cm->course : 1;
                if (!$course) {
                    debugging(
                        "Course not found for contextlevel 70, module instanceid {$record->instanceid}. Using site course ID.",
                        DEBUG_DEVELOPER
                    );
                }
            } else {
                debugging(
                    "Course module not found for contextlevel 70, instanceid {$record->instanceid}. Using site course ID.",
                    DEBUG_DEVELOPER
                );
                return false; // Skip if module context is invalid.
            }
        } else {
            debugging(
                "Non-course context (level {$record->contextlevel}). Using site course ID for courseid.",
                DEBUG_DEVELOPER
            );
        }
        $doc->set('courseid', $courseid);

        return $doc;
    }

    /**
     * Returns the context ID for the given context level and instance ID.
     *
     * @param int $contextlevel The context level
     * @param int $instanceid The instance ID
     * @return int The context ID
     */
    private function get_context_id($contextlevel, $instanceid) {
        try {
            if ($contextlevel == 10) {
                return \context_system::instance()->id;
            } else if ($contextlevel == 30) {
                return \context_user::instance($instanceid)->id;
            } else if ($contextlevel == 40) {
                return \context_coursecat::instance($instanceid)->id;
            } else if ($contextlevel == 50) {
                return \context_course::instance($instanceid)->id;
            } else if ($contextlevel == 70) {
                return \context_module::instance($instanceid)->id;
            } else if ($contextlevel == 80) {
                return \context_block::instance($instanceid)->id;
            }
        } catch (\moodle_exception $e) {
            // Log the error but return a fallback context if instanceid is invalid.
            debugging(
                "Invalid context mapping for contextlevel $contextlevel, instanceid $instanceid: " . $e->getMessage(),
                DEBUG_DEVELOPER
            );
        }
        return \context_system::instance()->id; // Fallback to system context.
    }

    /**
     * Whether the user can access the specified item.
     *
     * @param int $id The item ID
     * @return bool True if accessible
     */
    public function check_access($id) {
        global $DB;

        $record = $DB->get_record('filter_autotranslate_translations', ['id' => $id], '*', IGNORE_MISSING);
        if (!$record) {
            return \core_search\manager::ACCESS_DENIED;
        }

        // Use a valid capability based on context.
        $context = \context::instance_by_id($this->get_context_id($record->contextlevel, $record->instanceid));
        $capability = ($record->contextlevel == 50 || $record->contextlevel == 70)
            ? 'moodle/course:view'
            : 'moodle/site:accessallgroups';
        return has_capability($capability, $context) ? \core_search\manager::ACCESS_GRANTED : \core_search\manager::ACCESS_DENIED;
    }

    /**
     * Returns a URL to the item.
     *
     * @param \core_search\document $doc The document
     * @return \moodle_url
     */
    public function get_doc_url(\core_search\document $doc) {
        $contextid = $doc->get('contextid');
        $context = \context::instance_by_id($contextid);
        if ($context->contextlevel == 50) { // Course.
            return new \moodle_url('/course/view.php', ['id' => $doc->get('courseid')]);
        } else if ($context->contextlevel == 70) { // Module (URL activity).
            return new \moodle_url('/mod/url/view.php', ['id' => $context->instanceid]);
        }
        return new \moodle_url('/'); // Fallback to homepage.
    }

    /**
     * Returns a URL to display the item (e.g., in search results).
     *
     * @param \core_search\document $doc The document
     * @return \moodle_url
     */
    public function get_context_url(\core_search\document $doc) {
        return $this->get_doc_url($doc);
    }
}
