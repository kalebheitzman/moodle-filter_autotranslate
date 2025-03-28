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

/**
 * Custom admin setting for a field selection matrix.
 *
 * Purpose:
 * This file defines a custom admin setting class for the filter_autotranslate plugin, rendering a
 * matrix (table) where rows are tables, columns are fields, and cells contain checkboxes only where
 * a field exists in the table. It extends Moodle's admin_setting class to provide a tabular UI for
 * selecting translatable fields.
 *
 * Structure:
 * Contains the `admin_setting_configfieldmatrix` class, extending `admin_setting`. Key methods
 * include `write_setting` (save data), `get_setting` (load data), and `output_html` (render UI).
 *
 * Usage:
 * Used in settings.php to replace admin_setting_configmulticheckbox, providing a matrix UI for
 * selecting fields to translate.
 *
 * Design Decisions:
 * - Extends admin_setting to integrate with Moodle's settings API.
 * - Stores selections as a serialized array of table.field keys, matching the format of
 *   admin_setting_configmulticheckbox for compatibility.
 * - Renders a table with dynamic columns based on unique fields, showing checkboxes only where
 *   applicable.
 * - Includes default selections for common fields (name, intro, summary) to match prior behavior.
 * - Adds horizontal scrolling and borders for better usability with many columns.
 * - Left-aligns the "Table" column and minimizes its width for better readability.
 *
 * Dependencies:
 * - None beyond Moodle core.
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_autotranslate;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/adminlib.php');

/**
 * Custom admin setting to render a field selection matrix.
 */
class admin_setting_configfieldmatrix extends \admin_setting {
    /**
     * @var array Associative array of table => fields.
     */
    private $tables;

    /**
     * Constructor for the field matrix setting.
     *
     * @param string $name Unique name of the setting.
     * @param string $visiblename Display name of the setting.
     * @param string $description Description of the setting.
     * @param array $tables Associative array of table => fields.
     * @param array $defaultsetting Default selections (table.field => 1).
     */
    public function __construct($name, $visiblename, $description, $tables, $defaultsetting) {
        $this->tables = $tables;
        parent::__construct($name, $visiblename, $description, $defaultsetting);
    }

    /**
     * Retrieves the current setting value from the database.
     *
     * @return array|null Array of table.field => 1, or null if not set.
     */
    public function get_setting() {
        $value = $this->config_read($this->name);
        if ($value === false || $value === null) {
            return null;
        }
        return unserialize($value);
    }

    /**
     * Saves the submitted setting value to the database.
     *
     * @param array $data The submitted data (table.field => 1 for checked).
     * @return string Empty string on success, error message on failure.
     */
    public function write_setting($data) {
        // Ensure data is an array; if not, treat as empty.
        if (!is_array($data)) {
            $data = [];
        }

        // Build the value to save: only include checked fields.
        $value = [];
        foreach ($this->tables as $table => $fields) {
            foreach ($fields as $field) {
                $key = "$table.$field";
                if (isset($data[$key]) && $data[$key] == 1) {
                    $value[$key] = 1;
                }
            }
        }

        // Serialize the array before saving.
        $serializedvalue = serialize($value);
        return $this->config_write($this->name, $serializedvalue) ? '' : get_string('errorsetting', 'admin');
    }

    /**
     * Renders the matrix as an HTML table with horizontal scrolling.
     *
     * @param array $data Current form data.
     * @param string $query Search query (not used).
     * @return string HTML output for the matrix.
     */
    public function output_html($data, $query = '') {
        // Get default and current values.
        $default = $this->get_defaultsetting();
        $current = $this->get_setting();
        if (!is_array($current)) {
            $current = [];
        }
        if (!is_array($data)) {
            $data = [];
        }

        // Collect all unique fields for columns.
        $allfields = [];
        foreach ($this->tables as $table => $fields) {
            foreach ($fields as $field) {
                $allfields[$field] = $field;
            }
        }
        ksort($allfields);

        // Start building the HTML with a scrollable wrapper.
        $html = '<div style="overflow-x: auto; max-width: 100%;">';
        $html .= '<table class="generaltable autotranslate-matrix">';
        $html .= '<thead><tr>';
        $html .= '<th class="table-column">' . get_string('table', 'filter_autotranslate') . '</th>';
        foreach ($allfields as $field) {
            $html .= '<th>' . htmlspecialchars($field) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        // Build rows for each table.
        foreach ($this->tables as $table => $fields) {
            $html .= '<tr>';
            $html .= '<td class="table-column">' . htmlspecialchars($table) . '</td>';
            foreach ($allfields as $field) {
                $html .= '<td>';
                if (in_array($field, $fields)) {
                    $key = "$table.$field";
                    $checked = (isset($data[$key]) && $data[$key] == 1) ||
                               (!isset($data[$key]) && isset($current[$key])) ||
                               (!isset($data[$key]) && !isset($current[$key]) && isset($default[$key]));
                    $html .= '<input type="checkbox" name="' . $this->get_full_name() . '[' . $key . ']" value="1"';
                    if ($checked) {
                        $html .= ' checked';
                    }
                    $html .= ' />';
                }
                $html .= '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        $html .= '</div>';

        return format_admin_setting($this, $this->visiblename, $html, $this->description);
    }
}
