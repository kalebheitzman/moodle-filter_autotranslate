# Moodle Autotranslate Filter

![Latest Release](https://img.shields.io/github/v/release/kalebheitzman/moodle-filter_autotranslate) ![Moodle Plugin CI](https://github.com/kalebheitzman/moodle-filter_autotranslate/actions/workflows/moodle-ci.yml/badge.svg)

## Introduction

The **Moodle Autotranslate Filter** plugin automatically translates content across your Moodle site into multiple languages using any OpenAI-compatible translation service. It’s designed to make your courses, resources, and pages accessible to a global audience, while also supporting human-reviewed translations and integrating with Moodle’s global search. Whether you’re an administrator, teacher, or developer, this plugin provides the tools you need to create a seamless multilingual experience.

## Key Features

- **Automatic Translation**: Translates content using your chosen OpenAI-compatible service.
- **Human Translation Support**: Allows manual review or correction of translations.
- **Global Reuse**: Identical text shares translations site-wide for efficiency.
- [ ] **Search Integration**: Indexes translations for multilingual search results (work in progress).
- **Customizable**: Works with any translation service following the OpenAI API specification.
- **Context Awareness**: Organizes translations by Moodle context (e.g., course, module).

## Installation

To install the plugin:

1. Download the plugin from the [GitHub repository](https://github.com/kalebheitzman/moodle-filter_autotranslate/releases).
2. Unzip the file into your Moodle’s `filter` directory (e.g., `/path/to/moodle/filter/`).
3. Log in as an administrator and navigate to **Site Administration** > **Notifications** to complete the installation.
4. Go to **Site Administration** > **Plugins** > **Filters** > **Manage filters**.
5. Enable "Autotranslate" and set it to apply to **content and headings**, ideally at the top of the filter list.

## Configuration

Configure the plugin with your translation service:

1. Sign up for an OpenAI-compatible translation service (e.g., OpenAI, Google Translate, or a custom model).
2. Obtain the following details:
   - **Endpoint**: The API URL (e.g., `https://api.openai.com/v1`).
   - **API Key**: Your authentication key.
   - **Model**: The translation model (e.g., `gpt-3.5-turbo`).
3. Go to **Site Administration** > **Plugins** > **Filters** > **Autotranslate settings**.
4. Enter the **Endpoint**, **API Key**, and **Model**.

### Example Configuration

- **Endpoint**: `https://api.openai.com/v1`
- **API Key**: `your-openai-api-key`
- **Model**: `gpt-3.5-turbo`

Check your service’s documentation for specific details.

## Usage

Here’s how to use the plugin:

1. Install and configure as above.
2. Visit a page (e.g., a course or resource).
3. Switch languages using Moodle’s language selector (usually top-right).
4. Wait up to 30 minutes for translations to appear (see Scheduled Tasks below).
5. Manually manage translations via the plugin's interface at `/filter/autotranslate/manage.php` (requires appropriate capabilities; see [Permissions and Capabilities](#permissions-and-capabilities) below).

Teachers can also edit translations within their course contexts for greater control, provided they have the necessary permissions.

## Permissions and Capabilities

In Moodle, capabilities define what actions users can perform based on their roles. The Autotranslate Filter plugin introduces two capabilities to control access to its features:

- **filter/autotranslate:manage**
  - **Purpose**: Allows users to manage translations at the system level. This includes configuring plugin settings and overseeing the translation process across the entire Moodle site.
  - **Default roles**: Manager, Editing Teacher
  - **Context**: System-wide

- **filter/autotranslate:edit**
  - **Purpose**: Allows users to edit translations within specific courses. This enables teachers to fine-tune translations to better suit their course content.
  - **Default roles**: Teacher, Editing Teacher, Manager
  - **Context**: Course level

These capabilities ensure that only authorized users can manage or edit translations, helping maintain the quality and consistency of your site's content. Administrators can customize these permissions through Moodle's role management system to fit their specific needs.

For more information on how capabilities work in Moodle, see the [Moodle documentation on capabilities](https://docs.moodle.org/en/Capabilities).

## How It Works

The plugin manages translations through a database, text tagging, and a filter mechanism.

### Database Schema

Translations are stored in the `mdl_autotranslate_translations` table for site-wide reuse. The table includes the following columns:

- **`id`**:
  - Type: `BIGINT(10)`
  - Auto-incrementing primary key.

- **`hash`**:
  - Type: `VARCHAR(10)`
  - A unique 10-character hash for the source text (e.g., `aBcDeFgHiJ`).
  - Used to link source text to translations via tags like `Source Text {t:aBcDeFgHiJ}`.

- **`lang`**:
  - Type: `VARCHAR(20)`
  - Language code for the translation (e.g., `es` for Spanish).

- **`human`**:
  - Type: `TINYINT(1)`
  - Default: `0`
  - Indicates if the translation is human-reviewed (`1`) or auto-translated (`0`).

- **`translated_text`**:
  - Type: `TEXT`
  - Stores the translated content for the specified language.

- **`contextlevel`**:
  - Type: `INT(2)`
  - Represents the Moodle context level (e.g., `10` for System, `50` for Course, `70` for Module).

- **`timecreated`**:
  - Type: `INT(10)`
  - Timestamp when the record was created.

- **`timemodified`**:
  - Type: `INT(10)`
  - Timestamp when the record was last modified.

- **Unique Key**:
  - A combination of `hash` and `lang` ensures each hash-language pair is unique.

**Purpose**:  
This table centralizes translations with a persistent `hash` key, enabling reuse across Moodle while maintaining context awareness and tracking human intervention.

### Text Tagging

The plugin tags content with `CONTENT {t:abcd1234}`:

- Example: `Submit {t:aBcDeFgHiJ}`
- Spanish (`es`): “Enviar”
- The hash ensures translations are reusable across identical text.

### Translation Display

The filter processes text on page load:

- Detects `{t:hashid}` tags.
- Fetches the translation for the user’s language from the database.
- Falls back to the source text (e.g., “Submit”) if no translation exists.

## Scheduled Tasks

Two tasks handle translation management:

1. **`autotranslate_task`** (runs **every 15 minutes**):
   - Scans fields (e.g., course summaries, activity intros) for untagged text.
   - Assigns a unique hash and tags the content with `CONTENT {t:aBcDeFgHiJ}`.
   - Stores the source text in the database with `human = 1`.

2. **`fetchtranslation_task`** (runs **every 30 minutes**):
   - Generates automatic translations for tagged content using your translation service.
   - Stores translations with `human = 0`.

Adjust these schedules in the plugin settings if needed.

## Integration with Global Search

The plugin enhances Moodle’s global search:

- **Indexing**: Source text and translations are indexed for multilingual searches.
- **Display**: Results appear in the user’s language, falling back to source text if needed.
- **Setup**: Ensure your search engine (e.g., Solr) supports custom fields and reindex after installation.

- [ ] **Note**: This feature is still a work in progress.

## Important Considerations and Risks

- **Alpha Stage**: This plugin is under development. **Backup your database** before use.
- **Database Growth**: Storing translations increases database size.
- **Performance**: Scheduled tasks may impact busy sites.
- **Content Alteration**: Tags like `{t:aBcDeFgHiJ}` modify text fields. Disabling or uninstalling without cleanup may expose raw tags, affecting readability.
- **Hash Sensitivity**: Editing tagged text or hashes manually can break translation links.
- **Translation Quality**: Auto-translations vary by service/model; human review may be required.

## Uninstallation

To remove the plugin:

1. Disable it in **Site Administration** > **Plugins** > **Filters** > **Manage filters**.
2. Delete the plugin files from the `filter` directory.
3. Optionally, remove translation data from `mdl_autotranslate_translations` and clean tagged content.

**⚠️ Warning**: Without cleanup, raw tags may remain in content. Always back up your database first.

## Support and Issues

For help or to report bugs, visit the [GitHub repository](https://github.com/kalebheitzman/moodle-filter_autotranslate).