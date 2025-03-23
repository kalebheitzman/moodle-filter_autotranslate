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
     * Default tables, fields, and relationships to be tagged, organized by context level.
     *
     * Each table entry is an array with:
     * - 'fields': The fields to be tagged.
     * - 'primary_table': (Optional) The primary table for secondary tables.
     * - 'fk': (Optional) The foreign key linking to the primary or parent table.
     * - 'parent_table': (Optional) The parent table for nested secondary tables.
     * - 'parent_fk': (Optional) The foreign key linking the parent to the primary or grandparent table.
     * - 'grandparent_table': (Optional) The grandparent table for deeply nested secondary tables.
     * - 'grandparent_fk': (Optional) The foreign key linking the grandparent to the primary table.
     *
     * @var array
     */
    private static $default_tables = [
        10 => [
            'message' => ['fields' => ['fullmessage']],
            'block_instances' => ['fields' => ['configdata']],
        ],
        30 => [
            'user_info_data' => ['fields' => ['data']],
        ],
        40 => [
            'course_categories' => ['fields' => ['description']],
        ],
        50 => [
            'course' => ['fields' => ['fullname', 'shortname', 'summary']],
            'course_sections' => ['fields' => ['name', 'summary']],
        ],
        70 => [
            'assign' => ['fields' => ['name', 'intro', 'activity']],
            'book' => [
                'fields' => ['name', 'intro'],
                'secondary' => [
                    'book_chapters' => [
                        'fk' => 'bookid',
                        'fields' => ['title', 'content'],
                    ],
                ],
            ],
            'choice' => [
                'fields' => ['name', 'intro'],
                'secondary' => [
                    'choice_options' => [
                        'fk' => 'choiceid',
                        'fields' => ['text'],
                    ],
                ],
            ],
            'data' => [
                'fields' => ['name', 'intro'],
                'secondary' => [
                    'data_content' => [
                        'fk' => 'recordid',
                        'fields' => ['content', 'content1', 'content2', 'content3', 'content4'],
                    ],
                    'data_fields' => [
                        'fk' => 'dataid',
                        'fields' => ['name', 'description'],
                    ],
                ],
            ],
            'feedback' => [
                'fields' => ['name', 'intro', 'page_after_submit'],
                'secondary' => [
                    'feedback_item' => [
                        'fk' => 'feedback',
                        'fields' => ['name', 'label'],
                    ],
                ],
            ],
            'folder' => ['fields' => ['name', 'intro']],
            'forum' => [
                'fields' => ['name', 'intro'],
                'secondary' => [
                    'forum_discussions' => [
                        'fk' => 'forum',
                        'fields' => ['name'],
                    ],
                    'forum_posts' => [
                        'fk' => 'discussion',
                        'parent_table' => 'forum_discussions',
                        'parent_fk' => 'forum',
                        'fields' => ['subject', 'message'],
                    ],
                ],
            ],
            'glossary' => [
                'fields' => ['name', 'intro'],
                'secondary' => [
                    'glossary_entries' => [
                        'fk' => 'glossaryid',
                        'fields' => ['concept', 'definition'],
                    ],
                ],
            ],
            'label' => ['fields' => ['intro', 'name']],
            'lesson' => [
                'fields' => ['name', 'intro'],
                'secondary' => [
                    'lesson_pages' => [
                        'fk' => 'lessonid',
                        'fields' => ['title', 'contents'],
                    ],
                    'lesson_answers' => [
                        'fk' => 'pageid',
                        'parent_table' => 'lesson_pages',
                        'parent_fk' => 'lessonid',
                        'fields' => ['answer'],
                    ],
                ],
            ],
            'lti' => ['fields' => ['name', 'intro']],
            'page' => ['fields' => ['name', 'intro', 'content']],
            'quiz' => [
                'fields' => ['name', 'intro'],
                'secondary' => [
                    'question' => [
                        'fk' => 'questionid',
                        'parent_table' => 'quiz_slots',
                        'parent_fk' => 'quizid',
                        'fields' => ['name', 'questiontext', 'generalfeedback'],
                    ],
                    'question_answers' => [
                        'fk' => 'question',
                        'parent_table' => 'question',
                        'parent_fk' => 'questionid',
                        'grandparent_table' => 'quiz_slots',
                        'grandparent_fk' => 'quizid',
                        'fields' => ['answer', 'feedback'],
                    ],
                    'question_categories' => [
                        'fk' => 'category',
                        'parent_table' => 'question',
                        'parent_fk' => 'questionid',
                        'grandparent_table' => 'quiz_slots',
                        'grandparent_fk' => 'quizid',
                        'fields' => ['name', 'info'],
                    ],
                ],
            ],
            'resource' => ['fields' => ['name', 'intro']],
            'url' => ['fields' => ['name', 'intro']],
            'wiki' => [
                'fields' => ['name', 'intro', 'firstpagetitle'],
                'secondary' => [
                    'wiki_pages' => [
                        'fk' => 'subwikiid',
                        'parent_table' => 'wiki_subwikis',
                        'parent_fk' => 'wikiid',
                        'fields' => ['title'],
                    ],
                    'wiki_versions' => [
                        'fk' => 'pageid',
                        'parent_table' => 'wiki_pages',
                        'parent_fk' => 'subwikiid',
                        'grandparent_table' => 'wiki_subwikis',
                        'grandparent_fk' => 'wikiid',
                        'fields' => ['content'],
                    ],
                ],
            ],
            'workshop' => [
                'fields' => ['name', 'intro'],
                'secondary' => [
                    'workshop_submissions' => [
                        'fk' => 'workshopid',
                        'fields' => ['title', 'content'],
                    ],
                    'workshop_assessments' => [
                        'fk' => 'submissionid',
                        'parent_table' => 'workshop_submissions',
                        'parent_fk' => 'workshopid',
                        'fields' => ['feedbackauthor', 'feedbackreviewer'],
                    ],
                ],
            ],
        ],
        80 => [
            'block_instances' => ['fields' => ['configdata']],
        ],
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
     * Get the default tables, fields, and relationships for use in settings and tasks.
     *
     * @return array The default tables, fields, and relationships.
     */
    public static function get_default_tables() {
        return self::$default_tables;
    }

    /**
     * Get the secondary mappings for a specific primary table.
     *
     * @param string $primary_table The primary table name.
     * @return array The secondary mappings for the primary table, or an empty array if none exist.
     */
    public static function get_secondary_mappings($primary_table) {
        foreach (self::$default_tables as $contextlevel => $tables) {
            if (isset($tables[$primary_table]['secondary'])) {
                return $tables[$primary_table]['secondary'];
            }
        }
        return [];
    }

    /**
     * Check if a table is a secondary table and return its relationship details.
     *
     * @param string $table The table to check.
     * @return array|null The relationship details if the table is secondary, or null if not.
     */
    public static function get_relationship_details($table) {
        foreach (self::$default_tables as $contextlevel => $tables) {
            foreach ($tables as $primary_table => $config) {
                if (isset($config['secondary'])) {
                    if (isset($config['secondary'][$table])) {
                        return [
                            'primary_table' => $primary_table,
                            'fk' => $config['secondary'][$table]['fk'] ?? null,
                            'parent_table' => $config['secondary'][$table]['parent_table'] ?? null,
                            'parent_fk' => $config['secondary'][$table]['parent_fk'] ?? null,
                            'grandparent_table' => $config['secondary'][$table]['grandparent_table'] ?? null,
                            'grandparent_fk' => $config['secondary'][$table]['grandparent_fk'] ?? null,
                        ];
                    }
                }
            }
        }
        return null;
    }
}