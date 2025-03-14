<?php
namespace filter_autotranslate\task;

defined('MOODLE_INTERNAL') || die();

use core\task\scheduled_task;

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
     * Executes the scheduled task to tag untagged text and store source text.
     */
    public function execute() {
        global $DB;

        // Get settings
        $selectedctx = get_config('filter_autotranslate', 'selectctx');
        $selectedctx = $selectedctx ? explode(',', $selectedctx) : ['40', '50', '70', '80']; // Default to 40, 50, 70, 80
        $managelimit = get_config('filter_autotranslate', 'managelimit') ?: 20;
        $sitelang = get_config('core', 'lang') ?: 'en'; // Default to 'en' if not set

        // Batch size for processing
        $batchsize = $managelimit;

        // Define tables and fields to scan by context level
        $tables = [
            10 => ['message' => ['fullmessage'], 'block_instances' => ['configdata']], // System
            30 => ['user_info_data' => ['data']], // User
            40 => ['course_categories' => ['description']], // Course Category
            50 => ['course_sections' => ['name', 'summary']], // Course
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
                'label' => ['intro'],
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
            80 => ['block_instances' => ['configdata']], // Block
        ];

        mtrace("Starting Auto Translate task with contexts: " . implode(', ', $selectedctx));

        foreach ($selectedctx as $ctx) {
            if (!isset($tables[$ctx])) {
                mtrace("Skipping invalid context level: $ctx");
                continue;
            }

            foreach ($tables[$ctx] as $table => $fields) {
                $offset = 0;

                do {
                    try {
                        foreach ($fields as $field) {
                            $sql = "SELECT id AS instanceid, $field AS content
                                    FROM {" . $table . "}
                                    WHERE $field IS NOT NULL AND TRIM($field) != ''";
                            $params = [];
                            $records = $DB->get_records_sql($sql, $params, $offset, $batchsize);
                            $count = count($records);

                            mtrace("Processing batch for $table.$field, context $ctx, offset $offset, records: $count");

                            foreach ($records as $record) {
                                $content = trim($record->content);
                                if (empty($content)) {
                                    mtrace("Warning: Empty content skipped for instanceid={$record->instanceid}");
                                    continue;
                                }

                                // Check if content already has our tag or needs mlang conversion
                                if (strpos($content, '{translation hash=') === false) {
                                    // Check for existing mlang tags
                                    if (preg_match_all('/{mlang\s+([\w]+)}(.+?)(?:{mlang}|$)/s', $content, $matches, PREG_SET_ORDER)) {
                                        $source_text = '';
                                        $translations = [];
                                        $outside_text = '';

                                        // Extract mlang content
                                        $last_pos = 0;
                                        foreach ($matches as $match) {
                                            $lang = $match[1];
                                            $text = trim($match[2]);
                                            $start_pos = strpos($content, $match[0], $last_pos);
                                            $outside = trim(substr($content, $last_pos, $start_pos - $last_pos));
                                            if (!empty($outside)) {
                                                $outside_text .= $outside;
                                            }
                                            $last_pos = $start_pos + strlen($match[0]);

                                            if ($lang === 'other' || $lang === $sitelang) {
                                                $source_text .= $text . ' ';
                                            } else {
                                                $translations[$lang] = isset($translations[$lang]) ? $translations[$lang] . ' ' . $text : $text;
                                            }
                                        }

                                        // Capture any remaining text after the last mlang tag
                                        $outside_text .= trim(substr($content, $last_pos));

                                        // If there's outside text, treat it as source text if no source was found
                                        if (!empty($outside_text) && empty($source_text)) {
                                            $source_text = $outside_text;
                                        } elseif (!empty($outside_text)) {
                                            mtrace("Warning: Text outside mlang tags for instanceid={$record->instanceid}: '$outside_text'");
                                            $source_text .= $outside_text;
                                        }

                                        if (!empty($source_text)) {
                                            $source_text = trim($source_text);
                                            // Generate a unique 10-character hash
                                            $hash = $this->generate_unique_hash();
                                            $taggedcontent = "{translation hash=$hash}" . $source_text . "{/translation}";

                                            // Update the database with the new tag
                                            $DB->set_field($table, $field, $taggedcontent, ['id' => $record->instanceid]);

                                            // Store source text
                                            $this->store_source_text($hash, $source_text, $ctx, $record->instanceid);
                                            mtrace("Converted mlang to hash: hash=$hash, source='$source_text', context=$ctx, instanceid={$record->instanceid}");

                                            // Store existing translations
                                            foreach ($translations as $lang => $translated_text) {
                                                $this->store_translation($hash, $lang, $translated_text, $ctx, $record->instanceid);
                                                mtrace("Stored translation: hash=$hash, lang=$lang, text='$translated_text'");
                                            }
                                        } else {
                                            mtrace("No source language found in mlang tags for instanceid={$record->instanceid}, skipping");
                                            continue;
                                        }
                                    } else {
                                        // Untagged text
                                        $hash = $this->generate_unique_hash();
                                        $taggedcontent = "{translation hash=$hash}" . $content . "{/translation}";

                                        // Update the database with the tagged text
                                        $DB->set_field($table, $field, $taggedcontent, ['id' => $record->instanceid]);

                                        // Store the source text in the translations table
                                        $this->store_source_text($hash, $content, $ctx, $record->instanceid);
                                        mtrace("Tagged and stored: hash=$hash, text='$content', context=$ctx, instanceid={$record->instanceid}");
                                    }
                                } else {
                                    mtrace("Skipping already tagged content for instanceid={$record->instanceid}");
                                }
                            }

                            $offset += $batchsize;
                        }
                    } catch (\dml_exception $e) {
                        mtrace("Error processing $table for context $ctx: " . $e->getMessage() . " - Query: $sql with params " . json_encode($params));
                        break;
                    }
                } while ($count >= $batchsize);
            }
        }

        mtrace("Auto Translate task completed");
    }

    /**
     * Generates a unique 10-character alphanumeric hash.
     *
     * @return string
     */
    private function generate_unique_hash() {
        global $DB;

        $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $length = 10;
        $max = strlen($characters) - 1;

        do {
            $hash = '';
            for ($i = 0; $i < $length; $i++) {
                $hash .= $characters[random_int(0, $max)];
            }
        } while ($DB->record_exists('autotranslate_translations', ['hash' => $hash]));

        return $hash;
    }

    /**
     * Stores the source text in the translations table with fallback as the source.
     *
     * @param string $hash The unique hash
     * @param string $source_text The source text
     * @param int $contextlevel The context level
     * @param int $instanceid The instance ID
     */
    private function store_source_text($hash, $source_text, $contextlevel, $instanceid) {
        global $DB;

        $record = new \stdClass();
        $record->hash = $hash;
        $record->lang = 'other'; // Fallback language
        $record->translated_text = $source_text; // Store source as fallback
        $record->contextlevel = $contextlevel;
        $record->instanceid = $instanceid;
        $record->timecreated = time();
        $record->timemodified = time();
        $record->human = 1;

        $DB->insert_record('autotranslate_translations', $record);
    }

    /**
     * Stores a translation in the translations table.
     *
     * @param string $hash The unique hash
     * @param string $lang The language code
     * @param string $translated_text The translated text
     * @param int $contextlevel The context level
     * @param int $instanceid The instance ID
     */
    private function store_translation($hash, $lang, $translated_text, $contextlevel, $instanceid) {
        global $DB;

        $record = new \stdClass();
        $record->hash = $hash;
        $record->lang = $lang;
        $record->translated_text = $translated_text;
        $record->contextlevel = $contextlevel;
        $record->instanceid = $instanceid;
        $record->timecreated = time();
        $record->timemodified = time();
        $record->human = 1;

        $DB->insert_record('autotranslate_translations', $record);
    }
}