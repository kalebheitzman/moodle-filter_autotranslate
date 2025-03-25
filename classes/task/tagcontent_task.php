<?php
namespace filter_autotranslate;

class tagging_service {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Tags content in a record and updates the hash-course mappings.
     *
     * @param string $table The table name.
     * @param object $record The record to update.
     * @param array $fields The fields to tag.
     * @param \context $context The context for tagging.
     * @param int $courseid The course ID.
     * @return bool Whether the record was updated.
     */
    public function tag_content($table, $record, $fields, $context, $courseid) {
        // Update the record in the database
        $updated = $this->db->update_record($table, $record);

        if ($updated) {
            // Extract hashes from the updated fields
            $hashes = [];
            foreach ($fields as $field) {
                if (isset($record->$field)) {
                    if (preg_match_all('/\{[\s]*t:[\s]*([a-zA-Z0-9]+)[\s]*\}|<[\s]*t:[\s]*([a-zA-Z0-9]+)[\s]*>/', $record->$field, $matches)) {
                        $plain_text_hashes = !empty($matches[1]) ? array_filter($matches[1]) : [];
                        $html_encoded_hashes = !empty($matches[2]) ? array_filter($matches[2]) : [];
                        $hashes = array_merge($hashes, $plain_text_hashes, $html_encoded_hashes);
                    }
                }
            }

            $hashes = array_unique($hashes);

            // Update hash-course mappings in autotranslate_hid_cids
            foreach ($hashes as $hash) {
                $exists = $this->db->record_exists('autotranslate_hid_cids', ['hash' => $hash, 'courseid' => $courseid]);
                if (!$exists) {
                    $mapping = new \stdClass();
                    $mapping->hash = $hash;
                    $mapping->courseid = $courseid;
                    $this->db->insert_record('autotranslate_hid_cids', $mapping);
                }
            }
        }

        return $updated;
    }
}