# AI Instructions for Moodle Autotranslate Filter Development

## Overview

This document summarizes a comprehensive development conversation for the Moodle Autotranslate Filter plugin, updated to version `2025032800`. It provides a system prompt for future AI-assisted work, outlines the plugin’s logic map, details the database schema, and specifies key conventions like Moodle Extra coding style. Use this as a starting point for continued development in a new chat.

## System Prompt

Below is a recommended system prompt for future chats:

> You are Grok 3, built by xAI, assisting with the development of the Moodle Autotranslate Filter plugin (version `2025032800`). Follow the Moodle Extra PHPCS coding style: all lowercase variable names (e.g., `$contentservice`), no underscores in variables, snake_case for function names (e.g., `update_translation`), and detailed PHPDoc comments mirroring the style in `text_filter.php`. The plugin uses a structure with `text_filter.php` (entry point), `content_service.php` (database writes), `translation_source.php` (read-only access), `ui_manager.php` (UI coordination), and `text_utils.php` (utilities), with `tagging_config.php` retained for settings configuration in `settings.php`. It implements Option 3 (Mark Stale and Lazy Rebuild), dynamically tagging content with `{t:hash}` during page loads and refreshing stale translations on demand. Refer to the logic map and database schema below for component interactions and data structure. Provide conversational, step-by-step guidance, avoiding code unless requested, and use `<code></code>` for inline code in Markdown.

## Logic Map

The plugin’s components interact as follows:

- **Entry Point**: `<code>text_filter.php</code>`
  - **Role**: Processes text during page rendering.
  - **Flow**: Checks for `{t:hash}` tags; if present, fetches translations from `translation_source.php`; if absent or stale (`timereviewed` < `timemodified`), delegates to `content_service.php` to tag and store.
  - **Calls**: `translationsource->get_translation()`, `contentservice->process_content()`.

- **Database Writes**: `<code>content_service.php</code>`
  - **Role**: Manages all database operations (tagging, storing, updating).
  - **Methods**:
    - `process_content($text, $context, $courseid)`: Tags untagged/stale text, processes multilang tags via `text_utils.php`, stores in `mdl_filter_autotranslate_translations`, updates `mdl_filter_autotranslate_hid_cids`.
    - `store_translation($hash, $lang, $text, $contextlevel, $courseid, $context)`: Inserts new translations.
    - `update_translation($id, $text, $human, $timereviewed)`: Updates existing translations.
    - `mark_course_stale($courseid)`: Flags translations stale by updating `timemodified`.
  - **Calls**: `textutils->process_mlang_tags()`, `textutils->tag_content()`, `textutils->extract_hash()`.

- **Read-Only Access**: `<code>translation_source.php</code>`
  - **Role**: Fetches translation data for display or filtering.
  - **Methods**:
    - `get_translation($hash, $lang)`: Retrieves a specific translation.
    - `get_source_text($hash)`: Gets source text (`lang = 'other'`).
    - `get_all_languages($hash)`: Lists languages for a hash.
    - `get_paginated_translations($page, $perpage, $filterlang, $filterhuman, $sort, $dir, $courseid, $filterneedsreview)`: Fetches paginated data for UI.
  - **Used By**: `text_filter.php`, `ui_manager.php`.

- **UI Coordination**: `<code>ui_manager.php</code>`
  - **Role**: Manages UI tasks for `manage.php`, `create.php`, `edit.php`.
  - **Methods**:
    - `get_paginated_translations(...)`: Wraps `translationsource->get_paginated_translations()` with defaults.
    - `mark_course_stale($courseid)`: Triggers staleness via `contentservice`.
  - **Calls**: `translationsource->get_paginated_translations()`, `contentservice->mark_course_stale()`.

- **Utilities**: `<code>text_utils.php</code>`
  - **Role**: Provides stateless text processing functions.
  - **Methods**: `generate_unique_hash()`, `process_mlang_tags()`, `tag_content()`, `is_tagged()`, `extract_hash()`.
  - **Used By**: `content_service.php`.

- **Settings Configuration**: `<code>tagging_config.php</code>`
  - **Role**: Defines tables and fields for admin configuration in `settings.php`.
  - **Methods**: `get_tagging_config()`, `get_default_tables()`, `get_secondary_mappings()`, `get_relationship_details()`.
  - **Used By**: `settings.php` (not used in core tagging logic).

- **Management UI**:
  - `<code>manage.php</code>`: Displays translations, filters, triggers autotranslate task, optional "Mark Stale".
  - `<code>create.php</code>`: Adds new translations.
  - `<code>edit.php</code>`: Edits existing translations.

- **Task**: `<code>autotranslate_adhoc_task.php</code>`
  - **Role**: Fetches API translations for untranslated entries, triggered by `manage.php`.

- **External API**: `<code>external.php</code>`
  - **Role**: Queues autotranslate tasks, reports status via `autotranslate()` and `task_status()`.

## Database Schema

The plugin relies on three tables in the Moodle database:

- **<code>mdl_filter_autotranslate_translations</code>**
  - **Purpose**: Stores translations with unique hashes.
  - **Fields**:
    - `id` (BIGINT, PK, auto-increment): Unique ID.
    - `hash` (VARCHAR(10), not null): 10-character hash (e.g., `9UoZ3soJDz`) for `{t:hash}` tags.
    - `lang` (VARCHAR(20), not null): Language code (e.g., `en`, `es`, `other` for source).
    - `translated_text` (TEXT, not null): Source or translated content.
    - `contextlevel` (INT(2), not null): Moodle context (e.g., 50 for Course).
    - `human` (TINYINT(1), default 0): 0 = auto, 1 = human-edited.
    - `timecreated` (INT(10), not null): Creation timestamp.
    - `timemodified` (INT(10), not null): Last modified timestamp.
    - `timereviewed` (INT(10), default 0): Last reviewed timestamp.
  - **Keys**: PK: `id`, Unique: `hash, lang`.
  - **Indexes**: `contextlevel`, `timereviewed`.
  - **Usage**: Written by `content_service.php`, read by `translation_source.php`.

- **<code>mdl_filter_autotranslate_hid_cids</code>**
  - **Purpose**: Maps hashes to course IDs for filtering.
  - **Fields**:
    - `hash` (VARCHAR(10), not null): Hash of the string.
    - `courseid` (BIGINT, not null): Course ID.
  - **Keys**: PK: `hash, courseid`.
  - **Indexes**: `hash`, `courseid`.
  - **Usage**: Updated by `content_service.php`, queried by `translation_source.php` for course-specific views.

- **<code>mdl_filter_autotranslate_task_progress</code>**
  - **Purpose**: Tracks autotranslate task progress.
  - **Fields**:
    - `id` (BIGINT, PK, auto-increment): Unique ID.
    - `taskid` (INT, not null): Adhoc task ID.
    - `tasktype` (VARCHAR(20), not null): Type (`autotranslate`).
    - `total_entries` (INT, not null): Total items to process.
    - `processed_entries` (INT, default 0): Items processed.
    - `status` (VARCHAR(20), default 'queued'): Task status.
    - `timecreated` (INT, not null): Creation timestamp.
    - `timemodified` (INT, not null): Last modified timestamp.
  - **Keys**: PK: `id`.
  - **Indexes**: `taskid`.
  - **Usage**: Updated by `autotranslate_adhoc_task.php`, read by `external.php`.

## Moodle Extra Coding Style
- **Variables**: All lowercase, no underscores (e.g., `$contentservice`, `$targetlang`).
- **Functions**: Snake_case (e.g., `update_translation`, `process_content`).
- **Comments**: Detailed PHPDoc blocks with Purpose, Usage, Design Decisions, Dependencies (see `text_filter.php`).
- **Files**: One class per file, lowercase filenames matching class names (e.g., `content_service.php`).

## Key Development Notes
- **Option 3**: Replaced proactive rebuilds with lazy rebuilds; `content_service.php` checks `timereviewed` vs. `timemodified` and refreshes stale translations on page load.
- **Dynamic Tagging**: Primary mechanism in `text_filter.php`, no longer reliant on `tagging_config.php` for runtime tagging.
- **Settings Retention**: Kept `tagging_config.php` for `settings.php` to configure tables/fields in the admin UI, though not used by the core filter.
- **Rebuild Removal**: Dropped `rebuild_translations_adhoc_task.php` and related UI/CLI features.
- **Dependencies**: Unified database ops in `content_service.php`, reads in `translation_source.php`.
- **UI**: `manage.php` uses `ui_manager.php` for data and actions, with `create.php` and `edit.php` for editing.

## Recommendations
- **Testing**: Verify dynamic tagging, lazy rebuild, and autotranslate task with version `2025032800`.
- **UI**: Consider adding "Mark Stale" to `manage.php` via `ui_manager->mark_course_stale()`.
- **Future**: Add multilingual search integration or leverage `tagging_config.php` for optional scheduled tagging if desired.

This summary captures our work from March 27, 2025, onward. Use it to guide further enhancements!