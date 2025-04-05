# Moodle Autotranslate Filter

![Latest Release](https://img.shields.io/github/v/release/kalebheitzman/moodle-filter_autotranslate) ![Moodle Plugin CI](https://github.com/kalebheitzman/moodle-filter_autotranslate/actions/workflows/moodle-ci.yml/badge.svg)

## Introduction

The **Moodle Autotranslate Filter** plugin enhances your Moodle site by automatically translating content into multiple languages using any OpenAI-compatible translation service (e.g., Google Generative AI). Designed for accessibility, it supports a global audience while allowing human-reviewed translations. Ideal for administrators, teachers, and developers, this plugin simplifies creating a multilingual Moodle experience.

## Key Features

- **Automatic Translation**: Fetches translations via an OpenAI-compatible API for untagged or untranslated content.
- **Dynamic Tagging**: Tags content with `{t:hash}` during page rendering, ensuring immediate translation support for all content, including third-party modules.
- **Human Translation Support**: Offers a management interface to edit or add translations manually.
- **Global Reuse**: Shares translations site-wide for identical text, maintaining consistency and efficiency.
- **Context Awareness**: Stores translations with Moodle context levels (e.g., System, Course, Module), inherited from source text.
- **Multilang Processing**: Extracts and replaces `<span>` and `{mlang}` tags with `{t:hash}` tags, storing translations in the database.
- **Lazy Rebuild**: Marks translations stale for on-demand rebuilding during page loads, avoiding scheduled rebuild tasks.

## Installation

To install the plugin:

1. Download the latest release from the [GitHub repository](https://github.com/kalebheitzman/moodle-filter_autotranslate/releases).
2. Unzip it into your Moodle’s `filter` directory (e.g., `/path/to/moodle/filter/`).
3. Log in as an administrator and go to **Site Administration** > **Notifications** to install.
4. Navigate to **Site Administration** > **Plugins** > **Filters** > **Manage filters**.
5. Enable "Autotranslate" and set it to **content and headings**, ideally at the top of the filter list.

## Configuration

Configure the plugin with your translation service:

1. Sign up for an OpenAI-compatible service (e.g., Google Generative AI, OpenAI).
2. Gather:
   - **Endpoint**: API URL (e.g., `https://generativelanguage.googleapis.com/v1beta`).
   - **API Key**: Your authentication key.
   - **Model**: The translation model (e.g., `gemini-1.5-pro-latest`).
3. Go to **Site Administration** > **Plugins** > **Filters** > **Autotranslate settings**.
4. Enter the **Endpoint**, **API Key**, and **Model**.

### Example Configuration

- **Endpoint**: `https://generativelanguage.googleapis.com/v1beta`
- **API Key**: `your-google-api-key`
- **Model**: `gemini-1.5-pro-latest`

Refer to your service’s documentation for specifics.

## Usage

Here’s how to use the plugin:

1. Install and configure as above.
2. Visit any page (e.g., course, resource). The filter dynamically tags untagged content with `{t:hash}` on page load.
3. Switch languages via Moodle’s language selector (top-right).
4. Translations appear immediately for dynamically tagged content; API-translated content may take up to 30 minutes via the autotranslate task.
5. Manage translations at `/filter/autotranslate/manage.php` (requires `filter/autotranslate:manage` capability):
   - View translations with hash, language, source text (for target languages), translated text, human status, context level, review status, and actions.
   - Filter by language, human status, review status, and course ID.
   - Edit via "Edit" links or add new translations with the "Add" button.
   - Mark a course’s translations stale using the "Mark Stale" option (if added to UI).
6. Teachers can edit translations within their courses if granted `filter/autotranslate:edit`.

## Permissions and Capabilities

The plugin defines two capabilities:

- **filter/autotranslate:manage**
  - **Purpose**: Manage translations site-wide, configure settings, and mark translations stale.
  - **Default Roles**: Manager, Editing Teacher
  - **Context**: System
- **filter/autotranslate:edit**
  - **Purpose**: Edit translations within specific courses.
  - **Default Roles**: Teacher, Editing Teacher, Manager
  - **Context**: Course

Customize these via Moodle’s role management.

## How It Works

### Database Schema

#### `mdl_filter_autotranslate_translations`
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

#### `mdl_filter_autotranslate_hid_cids`
- **Purpose**: Maps hashes to course IDs for filtering.
- **Fields**:
  - `hash` (VARCHAR(10), not null): Hash of the string.
  - `courseid` (BIGINT, not null): Course ID.
- **Keys**: PK: `hash, courseid`.
- **Indexes**: `hash`, `courseid`.

#### `mdl_filter_autotranslate_task_progress`
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

### Text Tagging
- **Dynamic**: The filter tags content with `{t:hash}` during rendering, storing source text and translations immediately.
- **Example**: `Submit {t:aBcDeFgHiJ}` → Spanish: `Enviar`.

### Multilang Processing
- **Extraction**: Processes `<span lang="xx" class="multilang">` and `{mlang xx}` tags, storing translations and replacing with `{t:hash}`.
- **Destructive**: Original multilang tags are removed irreversibly.

### Translation Display
- Detects `{t:hash}`, fetches the user’s language translation, or falls back to source text if unavailable.

### Lazy Rebuild
- Marks translations stale via `ui_manager.php` (UI trigger optional), refreshing them on next page load.

## Scheduled Tasks

- **`autotranslate_adhoc_task`**:
  - **Trigger**: "Autotranslate" button on manage.php.
  - **Purpose**: Fetches translations for untranslated entries via API.
  - **Schedule**: Adhoc, runs when queued.

Adjust via **Site Administration** > **Server** > **Scheduled tasks**.

## Management Interface

At `/filter/autotranslate/manage.php`:
- **View**: Table with hash, language, source text (target languages), translated text, human status, context level, review status, actions.
- **Filter**: Language, human status, review status, course ID.
- **Edit/Add**: Edit via "Edit" or add via "Add" button.
- **Stale Marking**: Optional "Mark Stale" button (add to UI if desired).

## Important Considerations
- **Alpha Stage**: Backup your database before use.
- **Database Size**: Translations increase storage needs.
- **Performance**: Dynamic tagging may impact load times; adjust API settings if needed.
- **Content Alteration**: `{t:hash}` tags persist; disabling leaves raw tags visible.
- **Multilang Removal**: `<span>` and `{mlang}` tags are replaced irreversibly.
- **API Dependency**: Requires a reliable translation service.

## Uninstallation

1. Disable in **Site Administration** > **Plugins** > **Filters** > **Manage filters**.
2. Delete from `filter` directory.
3. Optionally, remove data from `mdl_filter_autotranslate_*` tables and clean `{t:hash}` tags.

**Warning**: Without cleanup, tags remain in content.

## Support

Report issues or get help at the [GitHub repository](https://github.com/kalebheitzman/moodle-filter_autotranslate).