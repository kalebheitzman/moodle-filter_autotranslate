# Moodle Autotranslate Filter

[![Latest Release](https://img.shields.io/github/v/release/jamfire/moodle-filter_autotranslate)](https://github.com/jamfire/moodle-filter_autotranslate/releases) [![Moodle Plugin CI](https://github.com/jamfire/moodle-filter_autotranslate/actions/workflows/moodle-ci.yml/badge.svg)](https://github.com/jamfire/moodle-filter_autotranslate/actions/workflows/moodle-ci.yml)

The `filter_autotranslate` plugin enhances Moodle by enabling automatic and manual translation of text across the platform, making content accessible in multiple languages. This document outlines the database schema, text tagging and display mechanisms, and key considerations for users.

## Step 1: Database Schema and Translation Management

### Database Schema

Translations are stored in a global table, `mdl_autotranslate_translations`, allowing reuse site-wide where appropriate.

#### Table Structure

```sql
CREATE TABLE mdl_autotranslate_translations (
    id BIGINT(10) AUTO_INCREMENT PRIMARY KEY,
    hash VARCHAR(10) NOT NULL COMMENT 'Unique 10-character hash for the source text',
    lang VARCHAR(20) NOT NULL COMMENT 'Language code (e.g., es, ru)',
    translated_text TEXT NOT NULL COMMENT 'Translated content for the specified language',
    contextlevel INT(2) NOT NULL COMMENT 'Moodle context level (e.g., 10 for System, 50 for Course, 70 for Module)',
    timecreated INT(10) NOT NULL COMMENT 'Timestamp of creation',
    timemodified INT(10) NOT NULL COMMENT 'Timestamp of last modification',
    human TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Flag indicating if the translation is human-reviewed (1) or auto-translated (0)',
    UNIQUE KEY hash_lang (hash, lang)
);
```

- **hash**: A unique 10-character alphanumeric identifier (e.g., `aBcDeFgHiJ`) embedded in tags like `{translation hash=aBcDeFgHiJ}Source Text{/translation}` to link source text to translations.
- **lang**: The language code for the translation (e.g., `es` for Spanish).
- **translated_text**: The translated content for the specified language.
- **contextlevel**: Indicates the Moodle context (e.g., 50 for Course, 70 for Module) for organizational purposes.
- **timecreated/timemodified**: Track when records are created or updated.
- **human**: A flag (`0` or `1`) where `1` indicates the translation was reviewed or authored by a human, and `0` indicates it was auto-translated (default).

**Purpose**:
Centralizes translations with a persistent `hash` key, enabling reuse across Moodle while maintaining context awareness and tracking human intervention.

### Text Tagging and Storage (Scheduled Task)

The `autotranslate_task` scheduled task runs periodically to manage text tagging:

- Scans database fields (e.g., `mdl_label.name`, `mdl_url.intro`, `mdl_course_sections.summary`) for untagged text.
- Checks `mdl_autotranslate_translations` for existing hashes associated with the text (with `lang = 'other'`). If found, applies the existing hash to tag the field; otherwise, generates a new 10-character hash.
- Tags text with `{translation hash=...}...{/translation}` and updates the database.
- Stores source text with `human = 1` in `mdl_autotranslate_translations`.

**Why Use a Scheduled Task?**

- Ensures background processing for performance.
- Maintains consistency by tagging all eligible content.
- Triggers reindexing for global search integration.

**⚠️ Warning**:
The task modifies text fields by adding `{translation hash=...}` tags. Disabling or uninstalling the plugin without removing these tags may leave raw tags visible, potentially disrupting readability. Always back up your database and test in a non-production environment.

### Displaying Translations (Filter)

The `filter_autotranslate` filter processes text on page load:

- Detects `{translation hash=...}` tags.
- Retrieves translations from `mdl_autotranslate_translations` based on the user’s language.
- Displays the translation or falls back to the source text if unavailable.

**Example**:

- Tagged text: `{translation hash=aBcDeFgHiJ}Submit{/translation}`
- Spanish (`es`) translation: “Enviar”
- Fallback: “Submit”

### Handling Missing or Empty Translations

- If no translation exists for the user’s language or it’s empty, the filter displays the source text.
- Ensures content visibility, similar to Moodle’s `mlang` filter behavior.

**⚠️ Risk**:
Altering or removing the `hash` (e.g., manual edits) breaks the link to translations. The source text remains, but manual fixes may be needed if hashes are changed.

### Integration with Global Search

The plugin enhances Moodle’s global search:

- **Indexing**: The task triggers reindexing of source text and translations, making them searchable in the user’s language.
- **Context**: Metadata (`contextlevel`) is included for filtering.
- **Display**: Results appear in the user’s language with fallback to source text.
- **Consideration**: Configure the search engine (e.g., Solr) to handle custom fields and reindex if needed.

### Key Considerations for Users

- **Global Reuse**: Identical text shares the same `hash`, enabling reuse but risking confusion if meanings differ by context.
- **Contextual Permissions**: Teachers can edit translations within their courses.
- **Risk of Disruption**: Tagging alters content; improper plugin management (e.g., uninstall without cleanup) can break display. Back up your database and test changes.

### Summary

- **Database**: `mdl_autotranslate_translations` stores translations globally with unique hashes and a `human` flag.
- **Scheduled Task**: Tags text and reuses existing hashes, running in the background.
- **Filter**: Displays translations with fallback to source text.
- **Risks**: Modifying text fields requires careful management to avoid disruption.

This design balances performance, reusability, and searchability while emphasizing the need for backups and testing.
