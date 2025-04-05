# AI Instructions for Moodle Autotranslate Filter Development

## Overview

This document summarizes development details for the Moodle Autotranslate Filter plugin, updated to version `2025040401`. It includes a system prompt for AI-assisted work, a logic map of components, the current database schema, and Moodle Extra coding conventions. Use this to guide further development in a new chat.

## System Prompt

Here’s a system prompt for future AI assistance:

> You are Grok 3, built by xAI, aiding development of the Moodle Autotranslate Filter plugin (version `2025040401`). Adhere to Moodle Extra PHPCS: lowercase variables (e.g., `$contentservice`), no underscores, snake_case functions (e.g., `upsert_translation`), and detailed PHPDoc comments like in `text_filter.php`. The plugin uses `text_filter.php` (entry point), `content_service.php` (writes), `translation_source.php` (reads), `ui_manager.php` (UI), and `text_utils.php` (utilities). Tagging runs every 5 minutes via `tagcontent_scheduled_task.php`, with manual API fetches via `autotranslate_adhoc_task.php`. Refer to the logic map and schema below for interactions and structure. Offer step-by-step guidance, avoiding code unless asked, using single backticks for inline code in Markdown.

## Logic Map

Component interactions in the plugin:

- **Entry Point**: `text_filter.php`
  - **Role**: Replaces `{t:hash}` tags during rendering.
  - **Flow**: Fetches translations from `translation_source.php` for tagged content, caching via `cache.php` (`taggedcontent`).
  - **Calls**: `translationsource->get_translation()`, `translationsource->get_source_text()`.

- **Database Writes**: `content_service.php`
  - **Role**: Handles tagging, storage, and updates.
  - **Methods**:
    - `process_content($content, $context, $courseid)`: Tags content, processes multilang tags via `text_utils.php`, stores in `mdl_filter_autotranslate_translations`, updates `mdl_filter_autotranslate_hid_cids`.
    - `upsert_translation($hash, $lang, $text, $contextlevel, $human)`: Inserts/updates translations.
    - `get_field_selection_options()`: Provides settings field options.
  - **Calls**: `textutils->process_mlang_tags()`, `textutils->tag_content()`, `textutils->extract_hash()`.

- **Read-Only Access**: `translation_source.php`
  - **Role**: Supplies translation data.
  - **Methods**:
    - `get_translation($hash, $lang)`: Fetches a translation.
    - `get_source_text($hash)`: Gets source text (`lang = 'other'`).
    - `get_paginated_translations(...)`: Provides paginated data for UI.
    - `get_paginated_target_translations(...)`: Pairs source-target translations.
    - `get_untranslated_hashes(...)`: Lists untranslated hashes for tasks.
  - **Used By**: `text_filter.php`, `ui_manager.php`, `external.php`.

- **UI Coordination**: `ui_manager.php`
  - **Role**: Manages UI data for `manage.php`, `create.php`, `edit.php`.
  - **Methods**:
    - `get_paginated_translations(...)`: Wraps `translationsource->get_paginated_translations()`.
    - `get_paginated_target_translations(...)`: Wraps `translationsource->get_paginated_target_translations()`.
  - **Calls**: `translationsource` methods.

- **Utilities**: `text_utils.php`
  - **Role**: Stateless text helpers.
  - **Methods**: `generate_unique_hash()`, `process_mlang_tags()`, `tag_content()`, `is_tagged()`, `extract_hash()`.
  - **Used By**: `content_service.php`.

- **Settings**: `settings.php`
  - **Role**: Configures API, tasks, and field selections for `tagcontent_scheduled_task.php`.

- **Management UI**:
  - `manage.php`: Displays/filters translations, triggers `autotranslate_adhoc_task.php`.
  - `create.php`: Adds translations.
  - `edit.php`: Edits translations.

- **Tasks**:
  - `tagcontent_scheduled_task.php`: Tags content every 5 minutes.
  - `autotranslate_adhoc_task.php`: Fetches API translations when triggered.

- **External API**: `external.php`
  - **Role**: Queues tasks and reports status via `autotranslate()` and `task_status()`.

- **Caching**: `cache.php`
  - **Role**: Defines `taggedcontent`, `modschemas`, `selectedfields` caches.

## Database Schema

Current tables in the Moodle database:

- **`mdl_filter_autotranslate_translations`**
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
  - **Keys**: PK: `id`, Unique: `hash, lang`.
  - **Indexes**: `contextlevel`.
  - **Usage**: Written by `content_service.php`, read by `translation_source.php`.

- **`mdl_filter_autotranslate_hid_cids`**
  - **Purpose**: Maps hashes to course IDs for filtering.
  - **Fields**:
    - `hash` (VARCHAR(10), not null): Hash of the string.
    - `courseid` (BIGINT, not null): Course ID.
  - **Keys**: PK: `hash, courseid`.
  - **Indexes**: `hash`, `courseid`.
  - **Usage**: Updated by `content_service.php`, queried by `translation_source.php`.

- **`mdl_filter_autotranslate_task_progress`**
  - **Purpose**: Tracks autotranslate task progress.
  - **Fields**:
    - `id` (BIGINT, PK, auto-increment): Unique ID.
    - `taskid` (INT, not null): Adhoc task ID.
    - `tasktype` (VARCHAR(20), not null): Type (`autotranslate`).
    - `total_entries` (INT, not null): Total items to process.
    - `processed_entries` (INT, default 0): Items processed.
    - `status` (VARCHAR(20), default 'queued'): Task status.
    - `timecreated` (INT(10), not null): Creation timestamp.
    - `timemodified` (INT(10), not null): Last modified timestamp.
  - **Keys**: PK: `id`.
  - **Indexes**: `taskid`.
  - **Usage**: Updated by `autotranslate_adhoc_task.php`, read by `external.php`.

## Moodle Extra Coding Style
- **Variables**: Lowercase, no underscores (e.g., `$contentservice`, `$targetlang`).
- **Functions**: Snake_case (e.g., `upsert_translation`, `process_content`).
- **Comments**: Detailed PHPDoc with Purpose, Usage, Design, Dependencies (see `text_filter.php`).
- **Files**: One class per file, lowercase filenames matching class names (e.g., `content_service.php`).

## Key Development Notes
- **Tagging**: Moved from dynamic in `text_filter.php` to scheduled via `tagcontent_scheduled_task.php` (5-minute intervals).
- **Tasks**: `autotranslate_adhoc_task.php` replaces proactive fetches, triggered manually from `manage.php`.
- **Settings**: `settings.php` configures tables/fields for tagging, not runtime logic.
- **Removed**: Dropped `mark_course_stale`, lazy rebuilds, and old tagging configs from core logic.
- **Caching**: Added `cache.php` for `taggedcontent`, `modschemas`, `selectedfields` to boost performance.

## Recommendations
- **Testing**: Validate scheduled tagging and adhoc translation with version `2025040401`.
- **Enhancements**: Consider adding bulk tagging options in `manage.php` or integrating with Moodle’s search.
- **Future**: Explore optional use of `settings.php` fields for dynamic tagging if performance allows.