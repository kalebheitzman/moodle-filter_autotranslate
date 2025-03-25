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

/**
 * Configuration class for defining tables and fields to be tagged in the filter_autotranslate plugin.
 *
 * Purpose:
 * This class defines the default tables, fields, and relationships to be tagged with {t:hash} tags
 * for translation, organized by Moodle context levels (e.g., 50 for courses, 70 for modules). It is
 * used by tagcontent_task.php, observer.php, and tagging_manager.php to determine which content to tag.
 *
 * Structure:
 * The $default_tables array is organized as follows:
 * - Key: Context level (e.g., 50 for CONTEXT_COURSE, 70 for CONTEXT_MODULE).
 * - Value: Array of tables, where each table entry contains:
 *   - 'fields': Array of fields to tag (e.g., ['name', 'intro']).
 *   - 'secondary': Optional array of secondary tables (e.g., book_chapters for book), where each secondary table contains:
 *     - 'fk': Foreign key linking to the primary or parent table (e.g., 'bookid').
 *     - 'fields': Array of fields to tag in the secondary table.
 *     - 'parent_table': Optional parent table for nested relationships (e.g., forum_discussions for forum_posts).
 *     - 'parent_fk': Foreign key linking the parent to the primary or grandparent table.
 *     - 'grandparent_table': Optional grandparent table for deeply nested relationships.
 *     - 'grandparent_fk': Foreign key linking the grandparent to the primary table.
 *
 * Design Decisions:
 * - Uses a nested structure to organize tables by context level, aligning with Moodle's context system
 *   for efficient filtering (e.g., via the selectctx setting).
 * - Includes secondary tables to ensure all translatable content is tagged (e.g., book_chapters for book),
 *   with relationship details to determine course IDs for hash-course mappings.
 * - Function names use snake_case (e.g., get_tagging_config) to follow Moodle's coding style.
 * - The get_tagging_config function allows for a custom configuration via the tagging_config setting,
 *   falling back to $default_tables if not set.
 *
 * Dependencies:
 * - None (pure configuration class).
 */
class tagging_config {
    /**
     * Default tables, fields, and relationships to be tagged, organized by context level.
     *
     * @var array
     */
    private static $default_tables = [
        // Context level 10: System context
        10 => [
            'message' => [
                'fields' => ['fullmessage'], // Messages sent between users
            ],
            'block_instances' => [
                'fields' => ['configdata'], // Block configuration data
            ],
        ],
        // Context level 30: User context
        30 => [
            'user_info_data' => [
                'fields' => ['data'], // Custom user profile fields
            ],
        ],
        // Context level 40: Course category context
        40 => [
            'course_categories' => [
                'fields' => ['description'], // Course category descriptions
            ],
        ],
        // Context level 50: Course context
        50 => [
            'course' => [
                'fields' => ['fullname', 'shortname', 'summary'], // Course details
            ],
            'course_sections' => [
                'fields' => ['name', 'summary'], // Course section details
            ],
        ],
        // Context level 70: Module context
        70 => [
            'assign' => [
                'fields' => ['name', 'intro', 'activity'], // Assignment details
            ],
            'book' => [
                'fields' => ['name', 'intro'], // Book module details
                'secondary' => [
                    'book_chapters' => [
                        'fk' => 'bookid', // Links to book.id
                        'fields' => ['title', 'content'], // Book chapter content
                    ],
                ],
            ],
            'choice' => [
                'fields' => ['name', 'intro'], // Choice module details
                'secondary' => [
                    'choice_options' => [
                        'fk' => 'choiceid', // Links to choice.id
                        'fields' => ['text'], // Choice options
                    ],
                ],
            ],
            'data' => [
                'fields' => ['name', 'intro'], // Database module details
                'secondary' => [
                    'data_content' => [
                        'fk' => 'recordid', // Links to data_records.id
                        'fields' => ['content', 'content1', 'content2', 'content3', 'content4'], // Database content
                    ],
                    'data_fields' => [
                        'fk' => 'dataid', // Links to data.id
                        'fields' => ['name', 'description'], // Database field definitions
                    ],
                ],
            ],
            'feedback' => [
                'fields' => ['name', 'intro', 'page_after_submit'], // Feedback module details
                'secondary' => [
                    'feedback_item' => [
                        'fk' => 'feedback', // Links to feedback.id
                        'fields' => ['name', 'label'], // Feedback items
                    ],
                ],
            ],
            'folder' => [
                'fields' => ['name', 'intro'], // Folder module details
            ],
            'forum' => [
                'fields' => ['name', 'intro'], // Forum module details
                'secondary' => [
                    'forum_discussions' => [
                        'fk' => 'forum', // Links to forum.id
                        'fields' => ['name'], // Forum discussion titles
                    ],
                    'forum_posts' => [
                        'fk' => 'discussion', // Links to forum_discussions.id
                        'parent_table' => 'forum_discussions', // Parent table for relationship
                        'parent_fk' => 'forum', // Links forum_discussions to forum.id
                        'fields' => ['subject', 'message'], // Forum post content
                    ],
                ],
            ],
            'glossary' => [
                'fields' => ['name', 'intro'], // Glossary module details
                'secondary' => [
                    'glossary_entries' => [
                        'fk' => 'glossaryid', // Links to glossary.id
                        'fields' => ['concept', 'definition'], // Glossary entries
                    ],
                ],
            ],
            'label' => [
                'fields' => ['intro', 'name'], // Label module details
            ],
            'lesson' => [
                'fields' => ['name', 'intro'], // Lesson module details
                'secondary' => [
                    'lesson_pages' => [
                        'fk' => 'lessonid', // Links to lesson.id
                        'fields' => ['title', 'contents'], // Lesson pages
                    ],
                    'lesson_answers' => [
                        'fk' => 'pageid', // Links to lesson_pages.id
                        'parent_table' => 'lesson_pages', // Parent table for relationship
                        'parent_fk' => 'lessonid', // Links lesson_pages to lesson.id
                        'fields' => ['answer'], // Lesson answers
                    ],
                ],
            ],
            'lti' => [
                'fields' => ['name', 'intro'], // LTI module details
            ],
            'page' => [
                'fields' => ['name', 'intro', 'content'], // Page module details
            ],
            'quiz' => [
                'fields' => ['name', 'intro'], // Quiz module details
                'secondary' => [
                    'question' => [
                        'fk' => 'questionid', // Links to quiz_slots.questionid
                        'parent_table' => 'quiz_slots', // Parent table for relationship
                        'parent_fk' => 'quizid', // Links quiz_slots to quiz.id
                        'fields' => ['name', 'questiontext', 'generalfeedback'], // Quiz questions
                    ],
                    'question_answers' => [
                        'fk' => 'question najbli', // Links to question.id
                        'parent_table' => 'question', // Parent table for relationship
                        'parent_fk' => 'questionid', // Links question to quiz_slots.questionid
                        'grandparent_table' => 'quiz_slots', // Grandparent table for relationship
                        'grandparent_fk' => 'quizid', // Links quiz_slots to quiz.id
                        'fields' => ['answer', 'feedback'], // Quiz question answers
                    ],
                    'question_categories' => [
                        'fk' => 'category', // Links to question.category
                        'parent_table' => 'question', // Parent table for relationship
                        'parent_fk' => 'questionid', // Links question to quiz_slots.questionid
                        'grandparent_table' => 'quiz_slots', // Grandparent table for relationship
                        'grandparent_fk' => 'quizid', // Links quiz_slots to quiz.id
                        'fields' => ['name', 'info'], // Quiz question categories
                    ],
                ],
            ],
            'resource' => [
                'fields' => ['name', 'intro'], // Resource module details
            ],
            'url' => [
                'fields' => ['name', 'intro'], // URL module details
            ],
            'wiki' => [
                'fields' => ['name', 'intro', 'firstpagetitle'], // Wiki module details
                'secondary' => [
                    'wiki_pages' => [
                        'fk' => 'subwikiid', // Links to wiki_subwikis.id
                        'parent_table' => 'wiki_subwikis', // Parent table for relationship
                        'parent_fk' => 'wikiid', // Links wiki_subwikis to wiki.id
                        'fields' => ['title'], // Wiki pages
                    ],
                    'wiki_versions' => [
                        'fk' => 'pageid', // Links to wiki_pages.id
                        'parent_table' => 'wiki_pages', // Parent table for relationship
                        'parent_fk' => 'subwikiid', // Links wiki_pages to wiki_subwikis.id
                        'grandparent_table' => 'wiki_subwikis', // Grandparent table for relationship
                        'grandparent_fk' => 'wikiid', // Links wiki_subwikis to wiki.id
                        'fields' => ['content'], // Wiki page versions
                    ],
                ],
            ],
            'workshop' => [
                'fields' => ['name', 'intro'], // Workshop module details
                'secondary' => [
                    'workshop_submissions' => [
                        'fk' => 'workshopid', // Links to workshop.id
                        'fields' => ['title', 'content'], // Workshop submissions
                    ],
                    'workshop_assessments' => [
                        'fk' => 'submissionid', // Links to workshop_submissions.id
                        'parent_table' => 'workshop_submissions', // Parent table for relationship
                        'parent_fk' => 'workshopid', // Links workshop_submissions to workshop.id
                        'fields' => ['feedbackauthor', 'feedbackreviewer'], // Workshop assessments
                    ],
                ],
            ],
        ],
        // Context level 80: Block context
        80 => [
            'block_instances' => [
                'fields' => ['configdata'], // Block configuration data (repeated from context 10)
            ],
        ],
    ];

    /**
     * Fetches the configured tables and fields to be tagged.
     *
     * This function retrieves the tagging configuration from the tagging_config setting,
     * falling back to the default $default_tables array if not set. It allows admins to
     * override the default configuration via the plugin settings.
     *
     * @return array The configured tables and fields, or the default if not set.
     */
    public static function get_tagging_config() {
        $config = get_config('filter_autotranslate', 'tagging_config') ?: '';
        if (!empty($config)) {
            return array_map('trim', explode(',', (string)$config));
        }
        return array_keys(self::flatten_default_tables());
    }

    /**
     * Returns the default tables, fields, and relationships for use in settings and tasks.
     *
     * This function provides access to the $default_tables array, which defines the default
     * configuration for tagging. It is used by tagcontent_task.php, observer.php, and settings.php.
     *
     * @return array The default tables, fields, and relationships.
     */
    public static function get_default_tables() {
        return self::$default_tables;
    }

    /**
     * Fetches the secondary mappings for a specific primary table.
     *
     * This function retrieves the secondary tables (e.g., book_chapters for book) defined for
     * a given primary table, used by tagging_manager.php to process secondary tables.
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
     * Fetches relationship details for a secondary table.
     *
     * This function retrieves the relationship details (e.g., foreign keys, parent tables) for
     * a secondary table, used by tagging_manager.php to build queries for fetching records.
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

    /**
     * Helper function to flatten default tables for settings default.
     *
     * @return array Flattened array of configuration keys (e.g., 'ctx50_course_fullname').
     */
    private static function flatten_default_tables() {
        $flat = [];
        foreach (self::$default_tables as $ctx => $tables) {
            foreach ($tables as $table => $config) {
                foreach ($config['fields'] as $field) {
                    $flat["ctx{$ctx}_{$table}_{$field}"] = true;
                }
                if (isset($config['secondary'])) {
                    foreach ($config['secondary'] as $sec_table => $sec_config) {
                        foreach ($sec_config['fields'] as $field) {
                            $flat["ctx{$ctx}_{$sec_table}_{$field}"] = true;
                        }
                    }
                }
            }
        }
        return $flat;
    }
}