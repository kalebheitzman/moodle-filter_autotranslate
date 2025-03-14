# Moodle Autostranslate Filter

## Step 1: Database Schema and Translation Management for `filter_autotranslate`

The `filter_autotranslate` plugin provides a robust system for managing translations in Moodle, enabling automatic and manual translation of text across the platform. This step outlines the database schema, how translations are stored, tagged, and displayed, and warns users about the potentially destructive nature of these changes.

### Database Schema

Translations are stored in a global database table named `mdl_autotranslate_translations`. This table is not tied to specific courses or contexts, allowing translations to be reused site-wide where appropriate.

#### Table Structure

```sql
CREATE TABLE mdl_autotranslate_translations (
    id BIGINT(10) AUTO_INCREMENT PRIMARY KEY,
    hash VARCHAR(10) NOT NULL COMMENT 'Unique 10-character hash for the source text',
    lang VARCHAR(20) NOT NULL COMMENT 'Language code (e.g., es, ru)',
    translated_text TEXT NOT NULL COMMENT 'Translated content for the specified language',
    contextlevel INT(2) NOT NULL COMMENT 'Moodle context level (e.g., 10 for System, 50 for Course, 70 for Module)',
    instanceid INT(10) NOT NULL COMMENT 'Instance ID within the context (e.g., page ID, section ID)',
    timecreated INT(10) NOT NULL COMMENT 'Timestamp of creation',
    timemodified INT(10) NOT NULL COMMENT 'Timestamp of last modification',
    UNIQUE KEY hash_lang (hash, lang),
    INDEX context_instance (contextlevel, instanceid)
);
```

- **hash**: A unique 10-character alphanumeric identifier (e.g., `aBcDeFgHiJ`) embedded in the source text using a tag like `{translation hash=aBcDeFgHiJ}Source Text{translation}`. This links the source text to its translations.
- **lang**: The language code for the translation (e.g., `es` for Spanish, `fr` for French).
- **translated_text**: The translated content for the specified language.

**Purpose**:  
This table centralizes translations, making them reusable across Moodle. The `hash` acts as a persistent key, ensuring translations remain connected to their source text, even if the text is edited or moved.

### Text Tagging and Storage (Scheduled Task)

A **scheduled task** runs periodically (e.g., hourly) to manage text tagging and storage. It performs the following actions:

- Scans Moodle’s database for untagged text fields (e.g., `mdl_page.content`, `mdl_course_sections.summary`).
- Generates a unique 10-character hash for each untagged piece of text.
- Wraps the text with a tag, e.g., `{translation hash=aBcDeFgHiJ}Source Text{translation}`, and updates the database field.
- Stores the source text and its hash in the `mdl_autotranslate_translations` table for later translation.

**Why Use a Scheduled Task?**

- **Performance**: Tagging and storing translations in the background keeps page loads fast, as the filter only retrieves and displays translations.
- **Consistency**: The task ensures all text is systematically tagged, minimizing errors or omissions.
- **Integration with Global Search**: The task triggers reindexing of affected content in Moodle’s global search engine, ensuring both source text and translations are searchable.

**⚠️ Potentially Destructive Nature**:  
The scheduled task modifies Moodle’s database by adding `{translation hash=...}` tags to text fields. This is essential for the plugin to work but alters the original content. If the plugin is disabled or uninstalled without removing these tags, users will see raw tags (e.g., `{translation hash=aBcDeFgHiJ}`) in the text, which could break the site’s readability. Additionally, if the hash is manually edited or removed, the link to translations may be lost, requiring manual fixes. Always back up your database before enabling this plugin and test changes in a non-production environment.

### Displaying Translations (Filter)

The `filter_autotranslate` filter processes text on every page load to display translations. It:

- Detects `{translation hash=...}` tags in the text.
- Queries the `mdl_autotranslate_translations` table using the hash and the user’s current language.
- Replaces the tagged text with the matching translation, if available.
- Falls back to the source text if no translation exists or if it’s empty.

**Example**:

- Tagged text: `{translation hash=aBcDeFgHiJ}Submit{translation}`
- Spanish (`es`) translation: “Enviar”
- If no translation exists: “Submit”

**Why This Matters**:  
Users always see content, even if translations are incomplete, ensuring a seamless experience.

### Handling Missing or Empty Translations

The system is designed to handle missing or empty translations gracefully:

- If a translation for the user’s language isn’t found or is empty, the filter displays the source text within the tag.
- This fallback mechanism ensures that content is always visible, mirroring Moodle’s existing `mlang` filter behavior where `{mlang other}...{mlang}` acts as the default.

**⚠️ Key Risk**:  
If the hash is changed or removed (e.g., by a user editing the text), the filter cannot retrieve the translation. The source text will still display, but the connection to existing translations will break. The scheduled task may re-tag untagged text over time, but manual intervention might be needed if hashes are altered.

### Integration with Global Search

The `filter_autotranslate` plugin integrates with Moodle’s global search to enhance search capabilities across languages:

- **Indexing**: The scheduled task triggers reindexing of content, including both the source text (within `{translation hash=...}` tags) and all translations from `mdl_autotranslate_translations`. This ensures users can search in their preferred language and find results regardless of the original language.
- **Context Awareness**: Metadata such as `contextlevel`, `instanceid`, and `courseid` are included in the index, allowing search results to be filtered by context and linked back to their original locations.
- **Display**: Search results are presented in the user’s language, falling back to the source text if no translation matches, providing a seamless multilingual search experience.
- **Considerations**: Ensure the search engine (e.g., Solr or the database engine) is configured to handle custom fields from `mdl_autotranslate_translations`. Manual reindexing may be required if hashes are altered or the plugin is disabled.

### Key Considerations for Users

- **Global Reuse**: Translations are stored globally and linked by hash, allowing reuse across contexts (e.g., courses or system-wide content). Be cautious, as identical text with different meanings in different contexts could lead to confusion.
- **Contextual Permissions**: The plugin leverages Moodle’s context system, letting teachers edit translations within their courses, while keeping management intuitive.
- **Risk of Disruption**: Adding tags to text fields makes this system powerful but potentially destructive—mishandling hashes or disabling the plugin without cleanup can disrupt content. Always back up your database and test changes in a safe environment.
- **Instance ID Limitation**: Currently, `instanceid` for context level 70 (Module) may use invalid IDs from module tables (e.g., `mdl_url.id`), causing context mapping issues in search. A future improvement will refine this to use `course_modules.id` correctly or decouple translations from specific instances, enhancing the context-agnostic design.

### Summary

- **Database**: The `mdl_autotranslate_translations` table stores translations globally, linked by a unique hash.
- **Scheduled Task**: Tags text with hashes and stores it in the database, running in the background for efficiency, and integrates with global search.
- **Filter**: Displays translations based on the user’s language, falling back to source text if needed.
- **Risks**: Modifying text fields makes this system powerful but potentially destructive—mishandling hashes or disabling the plugin without cleanup can break content.

This approach balances flexibility and performance, making translations easy to manage and searchable. However, users must understand the risks of altering tagged text or improperly uninstalling the plugin to avoid disrupting their Moodle site.
