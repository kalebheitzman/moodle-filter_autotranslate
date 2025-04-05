# Moodle Autotranslate Filter

![Latest Release](https://img.shields.io/github/v/release/kalebheitzman/moodle-filter_autotranslate) ![Moodle Plugin CI](https://github.com/kalebheitzman/moodle-filter_autotranslate/actions/workflows/moodle-ci.yml/badge.svg)

## Introduction

The **Moodle Autotranslate Filter** enhances your Moodle site by automatically translating content into multiple languages using any OpenAI-compatible translation service (e.g., Google Generative AI). Managing translations in Moodle has traditionally been a fragmented, manual challenge for administrators and translators. This plugin offers a translator-centered approach, combining automation with human review to simplify multilingual support for a global audience.

## Key Features

- **Automatic Translation**: Fetches translations via API for untranslated content, triggered manually by admins.
- **Scheduled Tagging**: Tags content with `{t:hash}` every 5 minutes via a scheduled task, covering core and third-party modules.
- **Human Translation Support**: Includes a management interface to edit or add translations manually.
- **Global Reuse**: Shares translations site-wide for identical text, ensuring consistency and efficiency.
- **Context Awareness**: Stores translations with Moodle context levels (e.g., Course, Module) and course mappings.
- **Multilang Processing**: Extracts `<span>` and `{mlang}` tags, storing translations and replacing them with `{t:hash}`.

## Installation

To install the plugin:

1. Download the latest release from the [GitHub repository](https://github.com/kalebheitzman/moodle-filter_autotranslate/releases).
2. Unzip it into your Moodle `filter` directory (e.g., `/path/to/moodle/filter/`).
3. Log in as an administrator and go to **Site Administration** > **Notifications** to install.
4. Navigate to **Site Administration** > **Plugins** > **Filters** > **Manage filters**.
5. Enable "Autotranslate" and set it to **content and headings**, ideally at the top of the filter list.

## Configuration

Configure the plugin with your translation service:

1. Sign up for an OpenAI-compatible service (e.g., Google Generative AI).
2. Gather:
   - **Endpoint**: API URL (e.g., `https://generativelanguage.googleapis.com/v1beta/openai`).
   - **API Key**: Your authentication key.
   - **Model**: The translation model (e.g., `gemini-1.5-pro-latest`).
3. Go to **Site Administration** > **Plugins** > **Filters** > **Autotranslate settings**.
4. Enter the **Endpoint**, **API Key**, and **Model**.

### Example Configuration

- **Endpoint**: `https://generativelanguage.googleapis.com/v1beta/openai`
- **API Key**: `your-google-api-key`
- **Model**: `gemini-1.5-pro-latest`

Refer to your service’s documentation for specifics.

## Usage

Here’s how to use the plugin:

1. Install and configure as above.
2. Content is tagged with `{t:hash}` every 5 minutes via a scheduled task; new content may take up to 5 minutes to show translations.
3. Switch languages via Moodle’s language selector (top-right) to view translations for tagged content.
4. Manage translations at `/filter/autotranslate/manage.php` (requires `filter/autotranslate:manage` capability):
   - View translations with hash, language, source text (for target languages), translated text, human status, context level, and actions.
   - Filter by language, human status, review status, and course ID.
   - Edit via "Edit" links or add new translations with the "Add" button.
   - Use the "Autotranslate" button to fetch API translations for untranslated content.
5. Teachers with `filter/autotranslate:edit` can edit translations within their courses.

## Permissions and Capabilities

The plugin defines two capabilities:

- **`filter/autotranslate:manage`**:
  - Purpose: Manage translations site-wide and configure settings.
  - Default Roles: Manager, Editing Teacher (System context).
- **`filter/autotranslate:edit`**:
  - Purpose: Edit translations within specific courses.
  - Default Roles: Teacher, Editing Teacher, Manager (Course context).

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
- Runs every 5 minutes via `tagcontent_scheduled_task.php`, tagging content with `{t:hash}` in configured tables.
- Example: `Submit {t:aBcDeFgHiJ}` → Spanish: `Enviar`.

### Multilang Processing
- Scheduled task extracts `<span>` and `{mlang}` tags, stores translations, and replaces them with `{t:hash}` (destructive).

### Translation Display
- `text_filter.php` detects `{t:hash}`, fetches the user’s language translation, or falls back to source text if unavailable.

## Scheduled Tasks

- **`tagcontent_scheduled_task.php`**:
  - Tags content every 5 minutes.
- **`autotranslate_adhoc_task.php`**:
  - Trigger: "Autotranslate" button on `manage.php`.
  - Fetches translations via API for untranslated content.

Adjust via **Site Administration** > **Server** > **Scheduled tasks**.

## Management Interface

At `/filter/autotranslate/manage.php`:
- **View**: Table with hash, language, source text (target languages), translated text, human status, context level, actions.
- **Filter**: Language, human status, review status, course ID.
- **Edit/Add**: Edit via "Edit" or add via "Add" button.
- **Autotranslate**: Triggers API translation fetch.

## Important Considerations
- **Alpha Stage**: Backup your database before use.
- **Database Size**: Translations increase storage needs.
- **Performance**: Tagging runs every 5 minutes; new content may delay up to 5 minutes.
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