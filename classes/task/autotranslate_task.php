<?php
namespace filter_autotranslate\task;

defined('MOODLE_INTERNAL') || die();

use core\task\scheduled_task;
use filter_autotranslate\helper;

class autotranslate_task extends scheduled_task {

    /**
     * Returns the name of the scheduled task.
     *
     * @return string
     */
    public function get_name() {
        return 'Auto Translate Text Tagging';
    }

    /**
     * Executes the scheduled task to tag untagged text and process mlang tags.
     */
    public function execute() {
        global $DB;

        $selectedctx = get_config('filter_autotranslate', 'selectctx') ?: '40,50,70,80';
        $selectedctx = !empty($selectedctx) ? array_filter(array_map('trim', explode(',', $selectedctx))) : ['40', '50', '70', '80'];
        $managelimit = get_config('filter_autotranslate', 'managelimit') ?: 20;
        $sitelang = get_config('core', 'lang') ?: 'en';

        $batchsize = $managelimit;

        $tables = [
            10 => ['message' => ['fullmessage'], 'block_instances' => ['configdata']],
            30 => ['user_info_data' => ['data']],
            40 => ['course_categories' => ['description']],
            50 => ['course_sections' => ['name', 'summary']],
            70 => [
                'assign' => ['name', 'intro'],
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

        mtrace("Starting Auto Translate task with contexts: " . implode(', ', $selectedctx));

        foreach ($selectedctx as $ctx) {
            if (!isset($tables[$ctx])) {
                mtrace("Skipping invalid context level: $ctx");
                continue;
            }

            foreach ($tables[$ctx] as $table => $fields) {
                foreach ($fields as $field) {
                    $offset = 0;

                    do {
                        try {
                            // Special handling for course_sections to fetch course ID
                            if ($table === 'course_sections') {
                                $sql = "SELECT cs.id AS instanceid, cs.$field AS content, cs.course
                                        FROM {course_sections} cs
                                        JOIN {course} c ON c.id = cs.course
                                        WHERE cs.$field IS NOT NULL
                                        AND cs.$field NOT LIKE '%{translation hash=[a-zA-Z0-9]{10}}%{\/translation}%'";
                            } else {
                                $sql = "SELECT id AS instanceid, $field AS content
                                        FROM {" . $table . "}
                                        WHERE $field IS NOT NULL
                                        AND $field NOT LIKE '%{translation hash=[a-zA-Z0-9]{10}}%{\/translation}%'";
                            }
                            $params = [];
                            $records = $DB->get_records_sql($sql, $params, $offset, $batchsize);
                            $count = count($records);
                            mtrace("Retrieved $count records for $table.$field, context $ctx, offset $offset");

                            if ($count > 0) {
                                // Use the first record's instanceid to create a context instance
                                $firstrecord = reset($records);
                                $context = static::get_context_for_level($ctx, $firstrecord->instanceid, $table, $firstrecord);
                                if (!$context) {
                                    mtrace("Could not determine context for $table at level $ctx");
                                    continue 2; // Skip to the next field
                                }
                            } else {
                                $context = \context_system::instance(); // Fallback for no records
                            }

                            foreach ($records as $record) {
                                $raw_content = $record->content;
                                $content = trim($raw_content);
                                if (!helper::is_tagged($raw_content) && !empty($raw_content)) {
                                    mtrace("Processing content for instanceid={$record->instanceid}");

                                    $taggedcontent = helper::process_mlang_tags($content, $context);
                                    if ($taggedcontent === $content) {
                                        $taggedcontent = helper::tag_content($content, $context);
                                    }

                                    if ($taggedcontent !== $content) {
                                        $DB->set_field($table, $field, $taggedcontent, ['id' => $record->instanceid]);
                                        mtrace("Tagged and stored: field=$field, instanceid={$record->instanceid}");
                                    }
                                } else {
                                    mtrace("Skipping already tagged or empty content for instanceid={$record->instanceid}");
                                }
                            }

                            $offset += $batchsize;
                        } catch (\dml_exception $e) {
                            mtrace("Error processing $table for context $ctx: " . $e->getMessage());
                            break;
                        }
                    } while ($count >= $batchsize);
                }
            }
        }

        mtrace("Auto Translate task completed");
    }

    /**
     * Get the context instance based on context level and instance ID.
     *
     * @param int $contextlevel The context level
     * @param int $instanceid The instance ID
     * @param string $table The table name
     * @param \stdClass $record The record being processed
     * @return \context|null The context instance or null if not found
     */
    private static function get_context_for_level($contextlevel, $instanceid, $table, $record) {
        switch ($contextlevel) {
            case CONTEXT_SYSTEM:
                return \context_system::instance();
            case CONTEXT_USER:
                return \context_user::instance($instanceid);
            case CONTEXT_COURSECAT:
                return \context_coursecat::instance($instanceid);
            case CONTEXT_COURSE:
                if ($table === 'course_sections' && isset($record->course)) {
                    return \context_course::instance($record->course);
                }
                return \context_course::instance($instanceid);
            case CONTEXT_MODULE:
                // For modules, we need the cmid; instanceid is the module instance ID
                $cm = \get_coursemodule_from_instance($table, $instanceid);
                return $cm ? \context_module::instance($cm->id) : null;
            case CONTEXT_BLOCK:
                // Block context requires block instance ID
                return \context_block::instance($instanceid);
            default:
                return null;
        }
    }
}