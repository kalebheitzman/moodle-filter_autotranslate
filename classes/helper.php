<?php
namespace filter_autotranslate;

defined('MOODLE_INTERNAL') || die();

class helper {
    /**
     * Generate a unique 10-character alphanumeric hash.
     *
     * @return string
     * @throws \Exception
     */
    public static function generate_unique_hash() {
        global $DB;

        $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $length = 10;
        $max = strlen($characters) - 1;
        $attempts = 0;
        $max_attempts = 100;

        do {
            $hash = '';
            for ($i = 0; $i < $length; $i++) {
                $hash .= $characters[random_int(0, $max)];
            }
            $attempts++;
            if ($attempts >= $max_attempts) {
                throw new \Exception("Unable to generate a unique hash after $max_attempts attempts.");
            }
        } while ($DB->record_exists('autotranslate_translations', ['hash' => $hash]));

        return $hash;
    }

    /**
     * Process mlang tags in content and store translations.
     *
     * @param string $text Content to process
     * @param \context $context Context object
     * @return string Tagged content
     */
    public static function process_mlang_tags($text, $context) {
        global $DB;

        if (!preg_match_all('/{mlang\s+([\w]+)}(.+?)(?:{mlang}|$)/s', $text, $matches, PREG_SET_ORDER)) {
            return $text;
        }

        $sitelang = get_config('core', 'lang') ?: 'en';
        $source_text = '';
        $translations = [];
        $outside_text = '';

        $last_pos = 0;
        foreach ($matches as $match) {
            $lang = $match[1];
            $content = trim($match[2]);
            $start_pos = strpos($text, $match[0], $last_pos);
            $outside = trim(substr($text, $last_pos, $start_pos - $last_pos));
            if (!empty($outside)) {
                $outside_text .= $outside;
            }
            $last_pos = $start_pos + strlen($match[0]);

            if ($lang === 'other' || $lang === $sitelang) {
                $source_text .= $content . ' ';
            } else {
                $translations[$lang] = isset($translations[$lang]) ? $translations[$lang] . ' ' . $content : $content;
            }
        }

        $outside_text .= trim(substr($text, $last_pos));

        if (!empty($outside_text) && empty($source_text)) {
            $source_text = $outside_text;
        } elseif (!empty($outside_text)) {
            $outside_text .= $outside_text;
        }

        if (!empty($source_text)) {
            $source_text = trim($source_text);
            $params = ['lang' => 'other', 'text' => $source_text];
            $sql = "SELECT hash FROM {autotranslate_translations} WHERE lang = :lang AND " . $DB->sql_compare_text('translated_text') . " = " . $DB->sql_compare_text(':text');
            $existing = $DB->get_record_sql($sql, $params, IGNORE_MULTIPLE);

            $hash = $existing ? $existing->hash : static::generate_unique_hash();

            if (!$existing) {
                $record = new \stdClass();
                $record->hash = $hash;
                $record->lang = 'other';
                $record->translated_text = $source_text;
                $record->contextlevel = $context->contextlevel;
                $record->timecreated = time();
                $record->timemodified = time();
                $record->human = 1;

                $DB->insert_record('autotranslate_translations', $record);

                foreach ($translations as $lang => $translated_text) {
                    $trans_record = new \stdClass();
                    $trans_record->hash = $hash;
                    $trans_record->lang = $lang;
                    $trans_record->translated_text = $translated_text;
                    $trans_record->contextlevel = $context->contextlevel;
                    $trans_record->timecreated = time();
                    $trans_record->timemodified = time();
                    $trans_record->human = 1;
                    $DB->insert_record('autotranslate_translations', $trans_record);
                }
            }

            return "{translation hash=$hash}" . $source_text . "{/translation}";
        }

        return $text;
    }

    /**
     * Tag content with a hash if untagged.
     *
     * @param string $text Content to tag
     * @param \context $context Context object
     * @return string Tagged content
     */
    public static function tag_content($text, $context) {
        global $DB;

        $content = trim($text);
        $params = ['lang' => 'other', 'text' => $content];
        $sql = "SELECT hash FROM {autotranslate_translations} WHERE lang = :lang AND " . $DB->sql_compare_text('translated_text') . " = " . $DB->sql_compare_text(':text');
        $existing = $DB->get_record_sql($sql, $params, IGNORE_MULTIPLE);

        $hash = $existing ? $existing->hash : static::generate_unique_hash();

        if (!$existing) {
            $record = new \stdClass();
            $record->hash = $hash;
            $record->lang = 'other';
            $record->translated_text = $content;
            $record->contextlevel = $context->contextlevel;
            $record->timecreated = time();
            $record->timemodified = time();
            $record->human = 1;

            $DB->insert_record('autotranslate_translations', $record);
        }

        return "{translation hash=$hash}" . $content . "{/translation}";
    }

    /**
     * Check if content is already tagged.
     *
     * @param string $content Content to check
     * @return bool
     */
    public static function is_tagged($content) {
        return preg_match('/{translation hash=[a-zA-Z0-9]{10}}.*{\/translation}/s', $content) === 1;
    }
}