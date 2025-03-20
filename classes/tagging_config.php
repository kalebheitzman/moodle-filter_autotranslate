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
// along with Moodle.  If not, see <http://www.gnu.org/licenses>.
/**
 * Tagging Configuration for Autotranslate Filter
 *
 * @package    filter_autotranslate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace filter_autotranslate;

defined('MOODLE_INTERNAL') || die();

class tagging_config {
    /**
     * Default tables and fields to be tagged, organized by context level.
     *
     * @var array
     */
    private static $default_tables = [
        10 => ['message' => ['fullmessage'], 'block_instances' => ['configdata']],
        30 => ['user_info_data' => ['data']],
        40 => ['course_categories' => ['description']],
        50 => [
            'course' => ['summary'],
            'course_sections' => ['name', 'summary'],
        ],
        70 => [
            'assign' => ['name', 'intro', 'activity'],
            'book' => ['name', 'intro'],
            'chat' => ['name', 'intro'],
            'choice' => ['name', 'intro'],
            'data' => ['name', 'intro'],
            'feedback' => ['name', 'intro'],
            'folder' => ['name', 'intro'],
            'forum' => ['name', 'intro'],
            'glossary' => ['name', 'intro'],
            'h5pactivity' => ['name', 'intro'],
            'imscp' => ['name', 'intro'],
            'label' => ['intro', 'name'],
            'lesson' => ['name', 'intro'],
            'lti' => ['name', 'intro'],
            'page' => ['name', 'intro', 'content'],
            'quiz' => ['name', 'intro'],
            'resource' => ['name', 'intro'],
            'scorm' => ['name', 'intro'],
            'survey' => ['name', 'intro'],
            'url' => ['name', 'intro'],
            'wiki' => ['name', 'intro'],
            'workshop' => ['name', 'intro'],
        ],
        80 => ['block_instances' => ['configdata']],
    ];

    /**
     * Get the configured tables and fields to be tagged.
     *
     * @return array The configured tables and fields, or the default if not set.
     */
    public static function get_tagging_config() {
        $config = get_config('filter_autotranslate', 'tagging_config');
        if (!empty($config)) {
            $configured_tables = json_decode($config, true);
            if (is_array($configured_tables)) {
                return $configured_tables;
            }
        }
        return self::$default_tables;
    }

    /**
     * Get the default tables and fields for use in settings.
     *
     * @return array The default tables and fields.
     */
    public static function get_default_tables() {
        return self::$default_tables;
    }
}