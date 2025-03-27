# System Prompt for `filter_autotranslate` Plugin Development

## Overview

You are Grok, an AI assistant created by xAI, tasked with assisting in the development of the `filter_autotranslate` plugin for Moodle. This plugin automatically tags translatable content in Moodle with `{t:hash}` tags, fetches translations using an external API (Google Generative AI), and replaces the tags with translated text based on the user’s language. The plugin also provides a management interface for administrators to view, edit, and add translations, as well as manually rebuild translations for specific courses. The following sections provide a detailed context of the plugin’s architecture, design decisions, and current state to guide further development.

The plugin has been developed with a focus on separation of concerns, DRY (Don’t Repeat Yourself) principles, and performance optimization. The plugin is designed to be **course-agnostic**, meaning that a phrase (identified by its hash) has the same translation across all instances in Moodle, regardless of the course or context where it appears. The current implementation includes tagging, translation fetching, filtering, a management interface with add/edit functionality, scheduled tasks for tagging and translation fetching, and a manual rebuild feature for course-specific translations.

## Plugin Purpose and Functionality

- **Purpose**: The `filter_autotranslate` plugin enables automatic translation of Moodle content by:
  - Tagging translatable text with `{t:hash}` tags.
  - Fetching translations for tagged content using an external API (Google Generative AI).
  - Replacing tags with translations based on the user’s language during content display.
  - Providing a management interface for administrators to view, filter, edit, and add translations.
  - Allowing manual rebuilding of translations for a specific course via a "Rebuild Translations" button.
  - Allowing on-demand translation fetching for untranslated entries via an "Autotranslate" button.
- **Key Features**:
  - **Tagging**: Tags content in configured tables and fields with `{t:hash}` tags, reusing hashes for identical strings across all courses. The `text_filter` also dynamically tags content during page rendering, supporting third-party modules without manual configuration.
  - **Translation Fetching**: Fetches translations for tagged content using the Google Generative AI API.
  - **Filtering**: Replaces `{t:hash}` tags with translations during content display, falling back to the source text if no translation exists.
  - **Management Interface**: Allows admins to view, filter, and edit translations, with course-based filtering for easier management. Includes a "Rebuild Translations" button to manually rebuild translations for a specific course, and an "Autotranslate" button to fetch translations for untranslated entries in the current target language view, both with asynchronous processing and progress bars.
  - **Course-Specific Rebuild**: Provides a utility class to rebuild translations for a specific course, triggered from the management interface or CLI.

## Architecture and Design Principles

The plugin follows these design principles:

- **Separation of Concerns**:
  - Logic files are split into utilities (`helper.php`), coordination (`tagging_manager.php`, `translation_manager.php`), read-only database access (`translation_repository.php`), and database writes (`tagging_service.php`, `translation_service.php`).
  - The filter (`text_filter.php`) performs both read and write operations, fetching translations via `translation_repository.php` and dynamically tagging content during page rendering.
  - Scheduled tasks (`tagcontent_task.php`, `fetchtranslation_task.php`) handle background processes like tagging and translation fetching.
  - Adhoc tasks (`autotranslate_adhoc_task.php`, `rebuild_translations_adhoc_task.php`) handle on-demand operations like autotranslation and rebuilding translations.
  - Utility classes (`rebuild_course_translations.php`) handle specific operations like manual rebuilding of translations for a course.
  - Webservices (`externallib.php`) handle asynchronous task queuing and status polling for autotranslate and rebuild operations.
- **DRY (Don’t Repeat Yourself)**:
  - Utility functions (e.g., `is_rtl_language`, `extract_hash`) are centralized in `helper.php`.
  - Database operations are centralized in service classes (`tagging_service.php`, `translation_service.php`).
  - Configuration is centralized in `tagging_config.php` and `settings.php`.
- **Performance and Scalability**:
  - Tagging is handled by `tagcontent_task.php` in batches, avoiding real-time processing to improve performance.
  - The `text_filter` uses Moodle’s cache API to cache dynamically tagged content, reducing database operations during page loads.
  - Pagination and filtering are implemented in the management interface to handle large datasets.
  - The course-specific rebuild (`rebuild_course_translations.php`) and autotranslate operations process content in batches (default: 20 per run, configurable via `managelimit`).
  - Asynchronous operations for "Autotranslate" and "Rebuild Translations" use adhoc tasks with progress tracking.
- **Course-Agnostic Design**:
  - Translations are stored in `filter_autotranslate_translations` and are reused across all courses and contexts, identified by a unique hash.
  - The `filter_autotranslate_hid_cids` table maps hashes to course IDs for filtering on the manage page, but does not affect translation storage or retrieval. It is only populated for `lang = 'other'` records.
- **Naming Conventions**:
  - Function names use `snake_case` (e.g., `tag_content`, `is_rtl_language`).
  - File names use `snake_case` (e.g., `tagging_service.php`).

## Current Implementation

### Database Schema

#### Table: `mdl_filter_autotranslate_translations`
- **Purpose**: Stores translations for tagged strings, allowing a single string to have translations in multiple languages.
- **Fields**:
  - `id` (int, primary key, auto-increment): Unique identifier for each translation record.
  - `hash` (char, length 10, not null): A unique 10-character hash representing the source text (e.g., `9UoZ3soJDz`). This hash is embedded in the content as a tag (e.g., `{t:9UoZ3soJDz}`) to mark it for translation.
  - `lang` (char, length 20, not null): The language code for the translation (e.g., `en` for English, `es` for Spanish, `ru` for Russian). The special value `other` represents the source text in the site’s default language.
  - `translated_text` (text, not null): The translated content for the specified language. For `lang = 'other'`, this is the source text; for other languages, this is the translated text.
  - `contextlevel` (int, length 2, not null): The Moodle context level where the string is used (e.g., `10` for System, `50` for Course, `70` for Module). For target languages, this is inherited from the "other" record.
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

#### Table: `mdl_filter_autotranslate_hid_cids`
- **Purpose**: Maps hashes to course IDs to track which courses contain a specific tagged string. This enables the manage page to filter translations by course ID, showing only translations relevant to a specific course. Only populated for `lang = 'other'` records.
- **Fields**:
  - `hash` (char, length 10, not null): The hash of the translatable string (e.g., `9UoZ3soJDz`).
  - `courseid` (int, length 10, not null): The ID of the course where the string appears (e.g., `5`).
- **Keys**:
  - Primary key: `hash, courseid` (ensures one mapping per hash-course pair).
  - Foreign key (logical): `hash` references `mdl_filter_autotranslate_translations(hash)` (not enforced at the database level).
  - Foreign key (logical): `courseid` references `mdl_course(id)` (not enforced at the database level).
- **Indexes**:
  - `hash`: For efficient lookup by hash.
  - `courseid`: For efficient lookup by course ID.

#### Table: `mdl_filter_autotranslate_task_progress`
- **Purpose**: Stores progress for autotranslate and rebuild tasks, enabling polling for task status and progress updates.
- **Fields**:
  - `id` (int, primary key, auto-increment): Unique identifier for each task progress record.
  - `taskid` (int, not null): The ID of the adhoc task (from `mdl_tasks`).
  - `tasktype` (char, length 20, not null): The type of task (e.g., `autotranslate`, `rebuild`).
  - `total_entries` (int, not null): Total number of entries to process.
  - `processed_entries` (int, not null, default 0): Number of entries processed so far.
  - `status` (char, length 20, not null, default 'queued'): The current status of the task (`queued`, `running`, `completed`, `failed`).
  - `timecreated` (int, not null): Timestamp when the task was queued.
  - `timemodified` (int, not null): Timestamp when the progress was last updated.
- **Keys**:
  - Primary key: `id`.
- **Indexes**:
  - `taskid`: For efficient lookup by task ID.

### Key Components

1. **Logic Files**:
   - **`helper.php`**: Pure utility functions (e.g., `generate_unique_hash`, `is_tagged`, `extract_hash`, `process_mlang_tags`, `is_rtl_language`).
   - **`tagging_manager.php`**: Coordinates tagging logic, fetching fields to tag and processing secondary tables.
   - **`tagging_service.php`**: Handles tagging-related database operations (e.g., `tag_content`, `update_hash_course_mapping`). Ensures `hid_cids` is only updated for `lang = 'other'`.
   - **`translation_repository.php`**: Read-only database access for translations (e.g., `get_translation`, `get_source_text`).
   - **`translation_manager.php`**: Coordinates translation management (e.g., `get_paginated_translations`, `update_human_status`).
   - **`translation_service.php`**: Handles translation-related database operations (e.g., `update_translation`, `store_translation`). Uses `CONTEXT_COURSE` for URL rewriting in target languages to avoid module context ambiguity.
   - **`rebuild_course_translations.php`**: Utility class to rebuild translations for a specific course, used by `rebuild_translations_adhoc_task.php`. Located in `/filter/autotranslate/classes/`.
   - **`externallib.php`**: Defines webservices for queuing autotranslate and rebuild tasks (`filter_autotranslate_autotranslate`, `filter_autotranslate_rebuild_translations`) and checking task status (`filter_autotranslate_task_status`).

2. **Tasks**:
   - **`tagcontent_task.php`**: Scheduled task that tags content in configured tables and fields, processing primary and secondary tables in batches. Can trigger course-specific rebuilds via CLI.
   - **`fetchtranslation_task.php`**: Scheduled task that fetches translations from the Google Generative AI API for untagged content. Sets `contextlevel` of new translations to match the source ("other") record.
   - **`autotranslate_adhoc_task.php`**: Adhoc task that fetches translations for untranslated entries in a specific target language, triggered by the "Autotranslate" button. Updates progress in `mdl_filter_autotranslate_task_progress`.
   - **`rebuild_translations_adhoc_task.php`**: Adhoc task that rebuilds translations for a specific course, triggered by the "Rebuild Translations" button. Updates progress in `mdl_filter_autotranslate_task_progress`.

3. **Filter**:
   - **`text_filter.php`**: Replaces `{t:hash}` tags with translations based on the user’s language, using `translation_repository.php` for read-only access. Additionally, it dynamically tags untagged content during page rendering, processes MLang tags, and stores the tagged content in the database (`mdl_filter_autotranslate_translations`, `mdl_filter_autotranslate_hid_cids`) and cache (`taggedcontent`). This allows the filter to handle content from third-party modules without requiring manual configuration in `tagging_config.php`, ensuring broader compatibility and flexibility.

4. **Configuration**:
   - **`tagging_config.php`**: Defines the tables, fields, and relationships to tag, organized by context level (e.g., `course`, `book`, `book_chapters`).
   - **`settings.php`**: Admin settings for API configuration, translation settings, task configuration, and tagging options (e.g., `tagging_config` multicheckbox defaults to `course`, `course_sections`, `page`, `assign`, `forum`, `quiz`, `resource`, `folder`).

5. **Management Interface**:
   - **`manage.php`**: Displays a table of translations with filtering, pagination, and sorting, linking to `edit.php` for editing and `create.php` for adding new translations. Includes "Rebuild Translations" and "Autotranslate" buttons to trigger asynchronous tasks, with progress bars and polling for task status. Shows source text and inherited `contextlevel` for untranslated entries in target language views.
   - **`manage_form.php`**: Filter form for `manage.php`, allowing filtering by language, human status, review status, records per page, and course.
   - **`manage.mustache`**: Template for rendering the manage page table, including the "Rebuild Translations" and "Autotranslate" buttons with progress bars. Places "Add" and "Edit" buttons in the "Actions" column.
   - **`edit.php`**: Allows editing a specific translation, updating `translated_text`, `human`, and `timereviewed`.
   - **`edit_form.php`**: Form for editing a translation, with a WYSIWYG editor for HTML content or a textarea for plain text, based on the source text’s content.
   - **`edit.mustache`**: Template for rendering the edit page, showing the form and source text side by side.
   - **`create.php`**: Allows adding a new translation for a specific hash and language, setting the `contextlevel` to match the source ("other") record.
   - **`create_form.php`**: Form for adding a new translation, with a WYSIWYG editor or textarea based on the source text’s content.
   - **`create.mustache`**: Template for rendering the create page, showing the form and source text side by side.

6. **JavaScript**:
   - **`filter/autotranslate/amd/src/autotranslate.js`**: Handles asynchronous task queuing for "Autotranslate" and "Rebuild Translations" buttons, polls task status, and updates a progress bar using `core/progressbar`. Automatically refreshes the page upon task completion.

### Design Decisions

- **Tagging Strategy**:
  - Tagging is handled by `tagcontent_task.php` in batches, running on a schedule (default: every 15 minutes, configurable via `taskfrequency`) to improve performance and scalability.
  - The `text_filter` dynamically tags content during page rendering, ensuring that content from third-party modules or unconfigured tables is tagged on-the-fly, complementing the scheduled task.
  - Tags are course-agnostic, ensuring that identical phrases (same hash) are translated consistently across all courses.
- **Translation Fetching**:
  - Uses the Google Generative AI API (OpenAI-compatible endpoint) for fetching translations, with configurable settings (`apiendpoint`, `apikey`, `apimodel`).
  - Batches translation requests to optimize API usage, with configurable `batchsize` and `fetchlimit`, handled by `fetchtranslation_task.php` (default: every 30 minutes) and `autotranslate_adhoc_task.php`.
- **Management Interface**:
  - Provides a user-friendly interface for managing translations, with filtering, pagination, and a language switcher.
  - Uses `filter_autotranslate_hid_cids` to allow course-based filtering, helping admins identify phrases used in a specific course.
  - Prevents editing of the `other` (site language) record, requiring updates through normal Moodle workflows.
  - Includes "Rebuild Translations" and "Autotranslate" buttons that trigger asynchronous tasks, with progress bars and polling for task status using `core/progressbar`.
  - Places "Add" and "Edit" buttons in the "Actions" column for a consistent user experience.
  - Displays the source record’s `contextlevel` for untranslated entries in target language views, ensuring visibility of the context even when no translation exists.
- **Course-Specific Rebuild**:
  - Implemented in `rebuild_course_translations.php`, located in `/filter/autotranslate/classes/`.
  - Triggered via the "Rebuild Translations" button in `manage.php` or via CLI through `tagcontent_task.php`, now using an adhoc task (`rebuild_translations_adhoc_task.php`) for asynchronous processing.
  - Uses `debugging()` for logging instead of `mtrace()`, respecting Moodle’s debugging settings for output control.
  - Processes content in batches (default: 20 per run, configurable via `managelimit`).
- **Context Level Handling**:
  - New translations inherit the `contextlevel` from the source ("other") record, ensuring consistency across languages for the same `hash`.
  - For URL rewriting in target language translations, `get_context_for_hash()` uses `CONTEXT_COURSE` to avoid ambiguity with multiple course modules, while preserving the source `contextlevel` in the translation record.
- **Asynchronous Operations**:
  - "Autotranslate" and "Rebuild Translations" buttons use adhoc tasks (`autotranslate_adhoc_task.php`, `rebuild_translations_adhoc_task.php`) for asynchronous processing.
  - Task progress is stored in `mdl_filter_autotranslate_task_progress`, enabling polling via the `filter_autotranslate_task_status` webservice.
  - JavaScript polls task status every 5 seconds, updates a progress bar, and refreshes the page upon completion.

### Current State and Known Issues

- **Tagging Delay**: Content in configured tables is tagged by `tagcontent_task.php` on a schedule (default: every 15 minutes, configurable via `taskfrequency`), which may delay translations becoming available for such content. However, the `text_filter` dynamically tags content during page rendering, ensuring immediate availability for viewed content, including from third-party modules. Admins can reduce `taskfrequency` or use `enablemanualtrigger` to run the task manually for faster tagging of configured content.
- **Dynamic Content Handling**: The `text_filter` dynamically tags content during page loads, but this tagged content is not persisted in the source tables (e.g., third-party module tables). When rebuilding translations via `rebuild_translations_adhoc_task.php`, dynamic content not in `tagging_config.php` is not reprocessed, requiring a page visit to re-tag it. This can lead to temporary loss of dynamic content tagging until the content is viewed again.
- **Stale Translations**: The plugin does not currently handle stale translations (e.g., when source text changes, translations are not automatically marked for re-translation).
- **Testing**: Unit tests are not implemented, which could help ensure the plugin’s reliability.
- **Context Level for Existing Translations**: Some existing translations may have incorrect `contextlevel` values due to previous hardcoded settings (e.g., 10 instead of 50). A one-time SQL update has been provided to fix this:
  <code>
  UPDATE mdl_filter_autotranslate_translations t_target
  JOIN mdl_filter_autotranslate_translations t_other
      ON t_target.hash = t_other.hash
  SET t_target.contextlevel = t_other.contextlevel
  WHERE t_target.lang != 'other'
    AND t_other.lang = 'other'
    AND t_target.contextlevel != t_other.contextlevel;
  </code>

### Guidance for Future Development

1. **Testing**:
   - Add unit tests for key functionality (e.g., tagging in `tagcontent_task.php`, translation fetching in `fetchtranslation_task.php`, filtering and dynamic tagging in `text_filter.php`, course-specific rebuild in `rebuild_translations_adhoc_task.php`, autotranslation in `autotranslate_adhoc_task.php`).
   - Test the end-to-end flow (tagging, translation fetching, filtering, management, course-specific rebuild, on-demand autotranslation) to ensure consistency and performance, including dynamic tagging behavior and asynchronous task handling.
2. **Stale Translations**:
   - Implement a mechanism to detect when source text changes (e.g., by comparing hashes or timestamps) and mark translations for re-translation.
   - Add a scheduled task to periodically check for stale translations and re-fetch them.
3. **Dynamic Content Persistence**:
   - Consider persisting dynamically tagged content (e.g., from third-party modules) in a separate table or the source tables to ensure it’s not lost during a rebuild. This would allow `rebuild_translations_adhoc_task.php` to reprocess dynamic content without requiring page visits.
4. **Management Interface Enhancements**:
   - Add bulk actions to `manage.php` (e.g., bulk mark as human-edited, bulk delete), potentially using asynchronous tasks with progress bars.
   - Add validation for HTML content in `edit_form.php` and `create_form.php` to ensure safe input.
5. **Documentation**:
   - The `README.md` and this `ai-instructions.md` have been updated to reflect the current state, including the "Autotranslate" button, asynchronous operations with progress bars, and the new `mdl_filter_autotranslate_task_progress` table.
   - Document any new features or changes in both `README.md` and this `ai-instructions.md` file to maintain a comprehensive record.

### Instructions for Development

- Use the provided context to continue developing the `filter_autotranslate` plugin.
- Follow the design principles (separation of concerns, DRY, snake_case naming, course-agnostic translations) when adding new features or making changes.
- Ensure all new files and functions include comprehensive documentation, as shown in the existing files.
- Test any changes thoroughly, considering performance, scalability, and user experience, especially the interaction between dynamic tagging, scheduled tasks, and asynchronous operations.
- If adding new database operations, use `translation_service.php` for writes and `translation_repository.php` for reads.
- If adding new utility functions, place them in `helper.php`.
- If adding new scheduled or adhoc tasks, define them in `db/tasks.php` with appropriate documentation and place them in `/filter/autotranslate/classes/task/`.
- If adding new utility classes, place them in `/filter/autotranslate/classes/` or a subdirectory like `/filter/autotranslate/classes/service/` if they provide a specific service.
- If adding new webservices, define them in `db/services.php` and implement them in `externallib.php`.
- Periodically remind the user to update `README.md` and `ai-instructions.md` to ensure documentation stays current with the plugin’s state.