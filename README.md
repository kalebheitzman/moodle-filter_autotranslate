# Moodle Autotranslate Filter

![Latest Release](https://img.shields.io/github/v/release/kalebheitzman/moodle-filter_autotranslate) ![Moodle Plugin CI](https://github.com/kalebheitzman/moodle-filter_autotranslate/actions/workflows/moodle-ci.yml/badge.svg)

## Introduction

The **Moodle Autotranslate Filter** plugin automatically translates content across your Moodle site into multiple languages using any OpenAI-compatible translation service (e.g., Google Generative AI). It’s designed to make your courses, resources, and pages accessible to a global audience, while also supporting human-reviewed translations. Managing translations in Moodle has traditionally been a fragmented, manual challenge for administrators and translators. This plugin provides a translator-centered approach, blending automation with human oversight to create a seamless multilingual experience.

## Key Features

- **Automatic Translation**: Fetches translations via API for untranslated content, triggered manually by admins.
- **Scheduled Tagging**: Tags content with `{t:hash}` every 5 minutes via a scheduled task, supporting core and third-party modules.
- **Human Translation Support**: Allows manual review or correction of translations via a management interface.
- **Global Reuse**: Identical text shares translations site-wide for efficiency.
- **Context Awareness**: Organizes translations by Moodle context (e.g., course, module) and course mappings.
- **Multilang Processing**: Extracts and stores translations from `<span>` and `{mlang}` tags, replacing them with `{t:hash}`.

## Installation

To install the plugin:

1. Download the plugin from the [GitHub repository](https://github.com/kalebheitzman/moodle-filter_autotranslate/releases).
2. Unzip the file into your Moodle `filter` directory (e.g., `/path/to/moodle/filter/`).
3. Log in as an administrator and navigate to **Site Administration** > **Notifications** to complete the installation.
4. Go to **Site Administration** > **Plugins** > **Filters** > **Manage filters**.
5. Enable "Autotranslate" and set it to apply to **content and headings**, ideally at the top of the filter list.

## Configuration

Configure the plugin with your translation service:

1. Sign up for an OpenAI-compatible translation service (e.g., Google Generative AI).
2. Obtain the following details:
   - **Endpoint**: The API URL (e.g., `https://generativelanguage.googleapis.com/v1beta/openai`).
   - **API Key**: Your authentication key.
   - **Model**: The translation model (e.g., `gemini-1.5-pro-latest`).
3. Go to **Site Administration** > **Plugins** > **Filters** > **Autotranslate settings**.
4. Enter the **Endpoint**, **API Key**, and **Model**.

### Example Configuration

- **Endpoint**: `https://generativelanguage.googleapis.com/v1beta/openai`
- **API Key**: `your-google-api-key`
- **Model**: `gemini-1.5-pro-latest`

Check your service’s documentation for specific details.

## Usage

Here’s how to use the plugin:

1. Install and configure as above.
2. Content is tagged with `{t:hash}` every 5 minutes via `tagcontent_scheduled_task.php`. New content may take up to 5 minutes to appear tagged and translated.
3. Switch languages using Moodle’s language selector (usually top-right) to view translations for tagged content.
4. Manually manage translations at `/filter/autotranslate/manage.php` (requires `filter/autotranslate:manage` capability):
   - View translations with hash, language, source text (for target languages), translated text, human status, context level, and actions.
   - Filter by language, human status, review status, and course ID.
   - Edit via "Edit" links or add new translations with the "Add" button.
   - Use the "Autotranslate" button to fetch API translations for untranslated content.
5. Teachers with `filter/autotranslate:edit` can edit translations within their courses, though this is not yet fully enforced.

## Permissions and Capabilities

In Moodle, capabilities define what actions users can perform based on their roles. The Autotranslate Filter plugin introduces two capabilities:

- **`filter/autotranslate:manage`**:
  - **Purpose**: Allows users to manage translations site-wide, configure settings, and use the management interface.
  - **Default Roles**: Manager, Editing Teacher (System context).
- **`filter/autotranslate:edit`**:
  - **Purpose**: Permits editing translations within specific courses (currently unused in code).
  - **Default Roles**: Teacher, Editing Teacher, Manager (Course context).

Customize these via Moodle’s role management. See [Moodle’s capabilities docs](https://docs.moodle.org/en/Capabilities) for details.

## How It Works

The plugin manages translations through scheduled tagging and a filter mechanism.

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
- The `tagcontent_scheduled_task.php` tags content with `{t:hash}` every 5 minutes across configured tables (e.g., course summaries, activity intros).
- **Example**: `Submit {t:aBcDeFgHiJ}` → Spanish (`es`): `Enviar`.

### Multilang Processing
- The scheduled task processes `<span>` and `{mlang}` tags, extracts translations, stores them in `mdl_filter_autotranslate_translations`, and replaces them with `{t:hash}` (destructive).

### Translation Display
- `text_filter.php` processes `{t:hash}` tags on page load, fetching the user’s language translation or falling back to source text.

## Scheduled Tasks

- **`tagcontent_scheduled_task.php`** (runs **every 5 minutes**):
  - Tags content in configured tables with `{t:hash}` and stores source text.
- **`autotranslate_adhoc_task.php`** (runs **on demand**):
  - Triggered by the "Autotranslate" button on `manage.php`, fetches translations for untranslated content.

Adjust these in **Site Administration** > **Server** > **Scheduled tasks**.

## Management Interface

At `/filter/autotranslate/manage.php`:
- **View**: Table with hash, language, source text (target languages), translated text, human status, context level, actions.
- **Filter**: Language, human status, review status, course ID.
- **Edit/Add**: Edit via "Edit" links or add via "Add" button.
- **Autotranslate**: Triggers API fetches for untranslated entries.

## Important Considerations and Risks

- **Alpha Stage**: ⚠️ This plugin is in alpha. **Backup your database** before use to avoid data loss.
- **Database Growth**: Storing translations increases database size significantly over time.
- **Performance**: Tagging runs every 5 minutes; new content may take up to 5 minutes to appear translated. Heavy tagging or large datasets may impact site performance—adjust task frequency in `settings.php` if needed.
- **Content Alteration**: ⚠️ Tags like `{t:aBcDeFgHiJ}` persist in content fields. Disabling the plugin without cleanup leaves raw tags visible, affecting readability.
- **Multilang Tag Removal**: ⚠️ `<span>` and `{mlang}` tags are removed and replaced with `{t:hash}` tags irreversibly. Original tags cannot be restored without a custom script (not implemented).
- **Hash Sensitivity**: Editing tagged text or hashes manually can break translation links, requiring re-tagging.
- **Translation Quality**: Auto-translations vary by service/model; human review may be needed for accuracy.
- **API Dependency**: ⚠️ Requires a reliable, active translation service. Downtime or API issues will halt new translations.

## Uninstallation

To remove the plugin:

1. Disable it in **Site Administration** > **Plugins** > **Filters** > **Manage filters**.
2. Delete the plugin files from the `filter` directory.
3. Optionally, remove data from `mdl_filter_autotranslate_*` tables and clean `{t:hash}` tags from content.

**⚠️ Warning**: Without cleanup, raw `{t:hash}` tags remain in content, and original `<span>` or `{mlang}` tags cannot be restored. Always back up your database first.

## Support and Issues

For help or to report bugs, visit the [GitHub repository](https://github.com/kalebheitzman/moodle-filter_autotranslate).