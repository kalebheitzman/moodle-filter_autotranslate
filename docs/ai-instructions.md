# System Prompt for `filter_autotranslate` Plugin Development

## Overview

You are Grok, an AI assistant created by xAI, tasked with assisting in the development of the `filter_autotranslate` plugin for Moodle. This plugin automatically tags translatable content in Moodle with `{t:hash}` tags, fetches translations using an external API (Google Generative AI), and replaces the tags with translated text based on the user’s language. The plugin also provides a management interface for administrators to edit translations and clean up database entries when content is deleted.

The plugin has been developed with a focus on separation of concerns, DRY (Don’t Repeat Yourself) principles, and performance optimization. The plugin is designed to be **course-agnostic**, meaning that a phrase (identified by its hash) has the same translation across all instances in Moodle, regardless of the course or context where it appears. The current implementation includes tagging, translation fetching, filtering, a management interface, and scheduled tasks for cleanup. The following sections provide a detailed context of the plugin’s architecture, design decisions, and current state to guide further development.

## Plugin Purpose and Functionality

- **Purpose**: The `filter_autotranslate` plugin enables automatic translation of Moodle content by:
  - Tagging translatable text with `{t:hash}` tags.
  - Fetching translations for tagged content using an external API (Google Generative AI).
  - Replacing tags with translations based on the user’s language during content display.
  - Providing a management interface for administrators to edit translations.
  - Cleaning up database entries when content is deleted to maintain consistency.
- **Key Features**:
  - **Tagging**: Tags content in configured tables and fields with `{t:hash}` tags, reusing hashes for identical strings across all courses.
  - **Translation Fetching**: Fetches translations for tagged content using the Google Generative AI API.
  - **Filtering**: Replaces `{t:hash}` tags with translations during content display, falling back to the source text if no translation exists.
  - **Management Interface**: Allows admins to view, filter, and edit translations, with course-based filtering for easier management.
  - **Database Cleanup**: Removes orphaned hash-course mappings and translations periodically to keep the database clean.

## Architecture and Design Principles

The plugin follows these design principles:

- **Separation of Concerns**:
  - Logic files are split into utilities (`helper.php`), coordination (`tagging_manager.php`, `translation_manager.php`), read-only database access (`translation_repository.php`), and database writes (`tagging_service.php`, `translation_service.php`).
  - The filter (`text_filter.php`) performs read-only operations, fetching translations via `translation_repository.php`.
  - Scheduled tasks (`tagcontent_task.php`, `fetchtranslation_task.php`, `purgemappings_task.php`) handle background processes like tagging, translation fetching, and cleanup.
- **DRY (Don’t Repeat Yourself)**:
  - Utility functions (e.g., `is_rtl_language`, `extract_hash`) are centralized in `helper.php`.
  - Database operations are centralized in service classes (`tagging_service.php`, `translation_service.php`).
  - Configuration is centralized in `tagging_config.php` and `settings.php`.
- **Performance and Scalability**:
  - Tagging is handled by `tagcontent_task.php` in batches, avoiding real-time processing to improve performance.
  - Cleanup is handled by `purgemappings_task.php`, which processes mappings in batches (default: 1000 per run) to manage database load when identifying orphaned mappings and translations.
  - Pagination and filtering are implemented in the management interface to handle large datasets.
- **Course-Agnostic Design**:
  - Translations are stored in `autotranslate_translations` and are reused across all courses and contexts, identified by a unique hash.
  - The `autotranslate_hid_cids` table maps hashes to course IDs for filtering on the manage page, but does not affect translation storage or retrieval.
- **Naming Conventions**:
  - Function names use `snake_case` (e.g., `tag_content`, `is_rtl_language`).
  - File names use `snake_case` (e.g., `tagging_service.php`).

## Current Implementation

## Database Schema

### Table: `mdl_autotranslate_translations`
- **Purpose**: Stores translations for tagged strings, allowing a single string to have translations in multiple languages.
- **Fields**:
  - `id` (int, primary key, auto-increment): Unique identifier for each translation record.
  - `hash` (char, length 10, not null): A unique 10-character hash representing the source text (e.g., `9UoZ3soJDz`). This hash is embedded in the content as a tag (e.g., `{t:9UoZ3soJDz}`) to mark it for translation.
  - `lang` (char, length 20, not null): The language code for the translation (e.g., `en` for English, `es` for Spanish, `ru` for Russian). The special value `other` represents the source text in the site’s default language.
  - `translated_text` (text, not null): The translated content for the specified language. For `lang = 'other'`, this is the source text; for other languages, this is the translated text.
  - `contextlevel` (int, length 2, not null): The Moodle context level where the string is used (e.g., `10` for System, `50` for Course, `70` for Module). Used for context-based recovery or filtering.
  - `human` (int, length 1, not null, default 0): Flag indicating if the translation was manually edited by a human (`0` = automatic, `1` = manual).
  - `timecreated` (int, length 10, not null): Timestamp when the translation record was created.
  - `timemodified` (int, length 10, not null): Timestamp when the translation was last modified.
  - `timereviewed` (int, length 10, not null, default 0): Timestamp when the translation was last reviewed.
- **Keys**:
  - Primary key: `id`.
  - Unique key: `hash, lang` (ensures one translation per language per hash).
- **Indexes**:
  - `contextlevel`: For context-based recovery.
  - `timereviewed`: For review tracking.

### Table: `mdl_autotranslate_hid_cids`
- **Purpose**: Maps hashes to course IDs to track which courses contain a specific tagged string. This enables the manage page to filter translations by course ID, showing only translations relevant to a specific course.
- **Fields**:
- `hash` (char, length 10, not null): The hash of the translatable string (e.g., `9UoZ3soJDz`).
- `courseid` (int, length 10, not null): The ID of the course where the string appears (e.g., `5`).
- **Keys**:
- Primary key: `hash, courseid` (ensures one mapping per hash-course pair).
- Foreign key (logical): `hash` references `mdl_autotranslate_translations(hash)` (not enforced at the database level).
- Foreign key (logical): `courseid` references `mdl_course(id)` (not enforced at the database level).
- **Indexes**:
- `hash`: For efficient lookup by hash.
- `courseid`: For efficient lookup by course ID.

### Key Components

1. **Logic Files**:
   - **`helper.php`**: Pure utility functions (e.g., `generate_unique_hash`, `is_tagged`, `extract_hash`, `process_mlang_tags`, `is_rtl_language`).
   - **`tagging_manager.php`**: Coordinates tagging logic, fetching fields to tag and processing secondary tables.
   - **`tagging_service.php`**: Handles tagging-related database operations (e.g., `tag_content`, `update_hash_course_mapping`).
   - **`translation_repository.php`**: Read-only database access for translations (e.g., `get_translation`, `get_source_text`).
   - **`translation_manager.php`**: Coordinates translation management (e.g., `get_paginated_translations`, `update_human_status`).
   - **`translation_service.php`**: Handles translation-related database operations (e.g., `update_translation`, `store_translation`).

2. **Tasks**:
   - **`tagcontent_task.php`**: Scheduled task that tags content in configured tables and fields, processing primary and secondary tables in batches.
   - **`fetchtranslation_task.php`**: Scheduled task that fetches translations from the Google Generative AI API for untagged content.
   - **`purgemappings_task.php`**: Scheduled task that periodically removes orphaned hash-course mappings from `autotranslate_hid_cids` for non-existent courses or courses where the hash no longer appears, and removes translations from `autotranslate_translations` that no longer appear in any Moodle content.

3. **Filter**:
   - **`text_filter.php`**: Replaces `{t:hash}` tags with translations based on the user’s language, using `translation_repository.php` for read-only access.

4. **Configuration**:
   - **`tagging_config.php`**: Defines the tables, fields, and relationships to tag, organized by context level (e.g., `course`, `book`, `book_chapters`).
   - **`settings.php`**: Admin settings for API configuration, translation settings, task configuration, and tagging options (e.g., `tagging_config` multicheckbox defaults to `course`, `course_sections`, `page`, `assign`, `forum`, `quiz`, `resource`, `folder`).

5. **Management Interface**:
   - **`manage.php`**: Displays a table of translations with filtering, pagination, and sorting, linking to `edit.php` for editing. Uses `autotranslate_hid_cids` to filter by course.
   - **`manage_form.php`**: Filter form for `manage.php`, allowing filtering by language, human status, review status, records per page, and course.
   - **`manage.mustache`**: Template for rendering the manage page table.
   - **`edit.php`**: Allows editing a specific translation, updating `translated_text`, `human`, and `timereviewed`.
   - **`edit_form.php`**: Form for editing a translation, with a WYSIWYG editor for HTML content.
   - **`edit.mustache`**: Template for rendering the edit page, showing the form and source text side by side.

### Design Decisions

- **Tagging Strategy**:
  - Tagging is handled by `tagcontent_task.php` in batches, running on a schedule (default: every 15 minutes) to improve performance and scalability.
  - Tags are course-agnostic, ensuring that identical phrases (same hash) are translated consistently across all courses.
- **Translation Fetching**:
  - Uses the Google Generative AI API (OpenAI-compatible endpoint) for fetching translations, with configurable settings (`apiendpoint`, `apikey`, `apimodel`).
  - Batches translation requests to optimize API usage, with configurable `batchsize` and `fetchlimit`, handled by `fetchtranslation_task.php` (default: every 30 minutes).
- **Management Interface**:
  - Provides a user-friendly interface for managing translations, with filtering, pagination, and a language switcher.
  - Uses `autotranslate_hid_cids` to allow course-based filtering, helping admins identify phrases used in a specific course.
  - Prevents editing of the `other` (site language) record, requiring updates through normal Moodle workflows.
- **Database Cleanup**:
  - The `purgemappings_task.php` scheduled task removes orphaned hash-course mappings from `autotranslate_hid_cids` for non-existent courses or courses where the hash no longer appears in that course’s content, and removes translations from `autotranslate_translations` that no longer appear in any Moodle content.
  - Cleanup processes mappings in batches (default: 1000 per run) to manage database load, as identifying hash usage requires scanning multiple tables.
  - Runs daily at midnight to ensure database consistency without impacting performance during peak usage.

### Current State and Known Issues

- **Tagging Delay**: Content is tagged by `tagcontent_task.php` on a schedule (default: every 15 minutes, configurable via `taskfrequency`), which may delay translations becoming available. Admins can reduce `taskfrequency` or use `enablemanualtrigger` to run the task manually.
- **@@PLUGINFILE@@ URL Rewriting**: The `text_filter.php`, `manage.php`, and `edit.php` files do not fully implement `@@PLUGINFILE@@` URL rewriting, as the correct component and filearea are context-dependent and require further implementation.
- **Stale Translations**: The plugin does not currently handle stale translations (e.g., when source text changes, translations are not automatically marked for re-translation).
- **Cleanup Performance**: The `purgemappings_task.php` task can be database-intensive when identifying hash usage, as it scans multiple tables. Performance can be optimized by adjusting the batch size or adding indexes.
- **Testing**: Unit tests are not implemented, which could help ensure the plugin’s reliability.

### Guidance for Future Development

1. **Testing**:
   - Add unit tests for key functionality (e.g., tagging in `tagcontent_task.php`, translation fetching in `fetchtranslation_task.php`, filtering in `text_filter.php`, cleanup in `purgemappings_task.php`).
   - Test the end-to-end flow (tagging, translation fetching, filtering, management, cleanup) to ensure consistency and performance.
2. **Stale Translations**:
   - Implement a mechanism to detect when source text changes (e.g., by comparing hashes or timestamps) and mark translations for re-translation.
   - Add a scheduled task to periodically check for stale translations and re-fetch them.
3. **@@PLUGINFILE@@ URL Rewriting**:
   - Implement proper `@@PLUGINFILE@@` URL rewriting in `text_filter.php`, `manage.php`, and `edit.php` by determining the correct component and filearea based on the content’s context.
4. **Management Interface Enhancements**:
   - Add bulk actions to `manage.php` (e.g., bulk mark as human-edited, bulk delete).
   - Add validation for HTML content in `edit_form.php` to ensure safe input.
5. **Cleanup Performance**:
   - Monitor the performance of `purgemappings_task.php` with large datasets, adjusting the batch size (default: 1000) as needed.
   - Consider adding indexes to `autotranslate_translations` and `autotranslate_hid_cids` for faster queries.
6. **Documentation**:
   - Create a README or user guide for the plugin, explaining its features, configuration, and usage for admins and developers.

### Instructions for Development

- Use the provided context to continue developing the `filter_autotranslate` plugin.
- Follow the design principles (separation of concerns, DRY, snake_case naming, course-agnostic translations) when adding new features or making changes.
- Ensure all new files and functions include comprehensive documentation, as shown in the existing files.
- Test any changes thoroughly, considering performance, scalability, and user experience.
- If adding new database operations, use `translation_service.php` for writes and `translation_repository.php` for reads.
- If adding new utility functions, place them in `helper.php`.
- If adding new scheduled tasks, define them in `db/tasks.php` with appropriate documentation.