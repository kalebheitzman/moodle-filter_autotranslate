# Moodle Autotranslate Filter

![Latest Release](https://img.shields.io/github/v/release/kalebheitzman/moodle-filter_autotranslate) ![Moodle Plugin CI](https://github.com/kalebheitzman/moodle-filter_autotranslate/actions/workflows/moodle-ci.yml/badge.svg)

## Introduction

The **Moodle Autotranslate Filter** plugin automatically translates content across your Moodle site into multiple languages using any OpenAI-compatible translation service (e.g., Google Generative AI). It’s designed to make your courses, resources, and pages accessible to a global audience, while also supporting human-reviewed translations. Whether you’re an administrator, teacher, or developer, this plugin provides the tools you need to create a seamless multilingual experience.

## Key Features

- **Automatic Translation**: Translates content using your chosen OpenAI-compatible service.
- **Dynamic Tagging**: Tags content on-the-fly during page rendering, supporting third-party modules without manual configuration, in addition to scheduled task-based tagging.
- **Human Translation Support**: Allows manual review or correction of translations.
- **Global Reuse**: Identical text shares translations site-wide for efficiency.
- **Course-Specific Rebuild**: Manually rebuild translations for a specific course using the "Rebuild Translations" button.
- **Customizable**: Works with any translation service following the OpenAI API specification.
- **Context Awareness**: Organizes translations by Moodle context (e.g., course, module).
- **Multilang Processing**: Extracts and stores translations from `<span>` and `{mlang}` multilang tags, replacing them with `{t:hash}` tags.
- [ ] **Search Integration**: Indexes translations for multilingual search results (work in progress).

## Installation

To install the plugin:

1. Download the plugin from the [GitHub repository](https://github.com/kalebheitzman/moodle-filter_autotranslate/releases).
2. Unzip the file into your Moodle’s `filter` directory (e.g., `/path/to/moodle/filter/`).
3. Log in as an administrator and navigate to **Site Administration** > **Notifications** to complete the installation.
4. Go to **Site Administration** > **Plugins** > **Filters** > **Manage filters**.
5. Enable "Autotranslate" and set it to apply to **content and headings**, ideally at the top of the filter list.

## Configuration

Configure the plugin with your translation service:

1. Sign up for an OpenAI-compatible translation service (e.g., Google Generative AI, OpenAI, or a custom model).
2. Obtain the following details:
   - **Endpoint**: The API URL (e.g., `https://api.openai.com/v1`).
   - **API Key**: Your authentication key.
   - **Model**: The translation model (e.g., `gemini-1.5-pro` for Google Generative AI).
3. Go to **Site Administration** > **Plugins** > **Filters** > **Autotranslate settings**.
4. Enter the **Endpoint**, **API Key**, and **Model**.

### Example Configuration

- **Endpoint**: `https://generativelanguage.googleapis.com/v1beta`
- **API Key**: `your-google-api-key`
- **Model**: `gemini-1.5-pro`

Check your service’s documentation for specific details.

## Usage

Here’s how to use the plugin:

1. Install and configure as above.
2. Visit a page (e.g., a course or resource). The plugin will dynamically tag untagged content on-the-fly during page rendering, ensuring immediate tagging for viewed content, including from third-party modules.
3. Switch languages using Moodle’s language selector (usually top-right).
4. Wait up to 30 minutes for translations to appear for content tagged by scheduled tasks (see Scheduled Tasks below). Content tagged dynamically during page loads will be available immediately.
5. Manually manage translations via the plugin's interface at `/filter/autotranslate/manage.php` (requires appropriate capabilities; see [Permissions and Capabilities](#permissions-and-capabilities) below).
6. Use the "Rebuild Translations" button on the manage page to manually rebuild translations for a specific course (requires `filter/autotranslate:manage` capability). Note that this may affect dynamically tagged content (see Important Considerations and Risks below).

Teachers can also edit translations within their course contexts for greater control, provided they have the necessary permissions.

## Permissions and Capabilities

In Moodle, capabilities define what actions users can perform based on their roles. The Autotranslate Filter plugin introduces two capabilities to control access to its features:

- **filter/autotranslate:manage**
  - **Purpose**: Allows users to manage translations at the system level. This includes configuring plugin settings, overseeing the translation process across the entire Moodle site, and using the "Rebuild Translations" button to manually rebuild translations for a specific course.
  - **Default roles**: Manager, Editing Teacher
  - **Context**: System-wide

- **filter/autotranslate:edit**
  - **Purpose**: Allows users to edit translations within specific courses. This enables teachers to fine-tune translations to better suit their course content.
  - **Default roles**: Teacher, Editing Teacher, Manager
  - **Context**: Course level

These capabilities ensure that only authorized users can manage or edit translations, helping maintain the quality and consistency of your site's content. Administrators can customize these permissions through Moodle's role management system to fit their specific needs.

For more information on how capabilities work in Moodle, see the [Moodle documentation on capabilities](https://docs.moodle.org/en/Capabilities).

## How It Works

The plugin manages translations through a combination of dynamic tagging, scheduled task-based tagging, and a filter mechanism.

### Database Schema

#### Table: `mdl_filter_autotranslate_translations`
- **Purpose**: Stores translations for tagged strings, allowing a single string to have translations in multiple languages.
- **Fields**:
  - `id` (BIGINT(10), auto-increment, primary key): Unique identifier for each translation record.
  - `hash` (VARCHAR(10), not null): A unique 10-character hash representing the source text (e.g., `9UoZ3soJDz`). This hash is embedded in the content as a tag (e.g., `{t:9UoZ3soJDz}`) to mark it for translation.
  - `lang` (VARCHAR(20), not null): The language code for the translation (e.g., `en` for English, `es` for Spanish, `ru` for Russian). The special value `other` represents the source text in the site’s default language.
  - `translated_text` (TEXT, not null): The translated content for the specified language. For `lang = 'other'`, this is the source text; for other languages, this is the translated text.
  - `contextlevel` (INT(2), not null): The Moodle context level where the string is used (e.g., `10` for System, `50` for Course, `70` for Module). Used for context-based recovery or filtering.
  - `human` (TINYINT(1), not null, default 0): Flag indicating if the translation was manually edited by a human (`0` = automatic, `1` = manual).
  - `timecreated` (INT(10), not null): Timestamp when the translation record was created.
  - `timemodified` (INT(10), not null): Timestamp when the translation was last modified.
  - `timereviewed` (INT(10), not null, default 0): Timestamp when the translation was last reviewed.
- **Keys**:
  - Primary key: `id`.
  - Unique key: `hash, lang` (ensures one translation per language per hash).
- **Indexes**:
  - `contextlevel`: For context-based recovery.
  - `timereviewed`: For review tracking.

#### Table: `mdl_filter_autotranslate_hid_cids`
- **Purpose**: Maps hashes to course IDs to track which courses contain a specific tagged string. This enables the manage page to filter translations by course ID, showing only translations relevant to a specific course.
- **Fields**:
  - `hash` (VARCHAR(10), not null): The hash of the translatable string (e.g., `9UoZ3soJDz`).
  - `courseid` (BIGINT(10), not null): The ID of the course where the string appears (e.g., `5`).
- **Keys**:
  - Primary key: `hash, courseid` (ensures one mapping per hash-course pair).
  - Foreign key (logical): `hash` references `mdl_filter_autotranslate_translations(hash)` (not enforced at the database level).
  - Foreign key (logical): `courseid` references `mdl_course(id)` (not enforced at the database level).
- **Indexes**:
  - `hash`: For efficient lookup by hash.
  - `courseid`: For efficient lookup by course ID.

### Text Tagging

The plugin tags content with `CONTENT {t:hash}` in two ways:

- **Dynamic Tagging**: During page rendering, the filter automatically tags untagged content with `{t:hash}` tags, including content from third-party modules not explicitly configured. This ensures immediate tagging for viewed content.
- **Scheduled Tagging**: The `autotranslate_task` tags content in configured tables (e.g., course summaries, activity intros) with `{t:hash}` tags, ensuring consistent tagging across the site.
- **Example**: `Submit {t:aBcDeFgHiJ}`
  - Spanish (`es`): “Enviar”
  - The hash ensures translations are reusable across identical text site-wide.

### Multilang Processing

The plugin processes existing multilang content in both `<span>` and `{mlang}` formats:

- **Extraction**: Extracts translations from `<span>` tags (e.g., `<span lang="es" class="multilang">Hola</span>`) and `{mlang}` tags (e.g., `{mlang es}Hola{mlang}`), storing them in the `mdl_autotranslate_translations` table.
- **Replacement**: Removes the multilang tags and replaces them with a single `{t:hash}` tag, linking to the stored translations.
- **Destructive Action**: This process is destructive and non-reversible, as the original multilang tags are removed. A future script could potentially restore them, but this is not currently implemented.

### Translation Display

The filter processes text on page load:

- Detects `{t:hashid}` tags.
- Fetches the translation for the user’s language from the database.
- Falls back to the source text (e.g., “Submit”) if no translation exists.

## Scheduled Tasks

Two tasks handle translation management:

1. **`autotranslate_task`** (runs **every 15 minutes**):
   - Scans fields in configured tables (e.g., course summaries, activity intros) for untagged text.
   - Processes existing multilang tags (`<span>` and `{mlang}`), extracts translations, and replaces them with `{t:hash}` tags.
   - Assigns a unique hash and tags the content with `CONTENT {t:aBcDeFgHiJ}`.
   - Stores the source text in the database with `lang = 'other'`.
   - Note: This task only tags content in configured tables. Content from unconfigured tables (e.g., third-party modules) is tagged dynamically during page loads.

2. **`fetchtranslation_task`** (runs **every 30 minutes**):
   - Generates automatic translations for tagged content using your translation service.
   - Stores translations with `human = 0`.

Adjust these schedules in the plugin settings if needed. You can also run these tasks manually via **Site Administration** > **Server** > **Scheduled tasks**.

## Management Interface

The plugin provides a management interface at `/filter/autotranslate/manage.php` for administrators to oversee translations:

- **View Translations**: Displays a table of translations with columns for hash, language, translated text, human status, context level, review status, and actions.
- **Filter Translations**: Filter by language, human status, review status, and course ID (using `mdl_filter_autotranslate_hid_cids` for course-based filtering).
- **Edit Translations**: Edit individual translations via a WYSIWYG editor, updating `translated_text`, `human`, and `timereviewed`.
- **Rebuild Translations**: Manually rebuild translations for a specific course using the "Rebuild Translations" button (requires `filter/autotranslate:manage` capability). This operation is synchronous and redirects with a success message upon completion. Note that this may affect dynamically tagged content (see Important Considerations and Risks below).

## Integration with Global Search

The plugin is designed to enhance Moodle’s global search, but this feature is still a work in progress:

- **Indexing**: Source text and translations will be indexed for multilingual searches.
- **Display**: Results will appear in the user’s language, falling back to source text if needed.
- **Setup**: Ensure your search engine (e.g., Solr) supports custom fields and reindex after installation.

- [ ] **Note**: This feature is not yet fully implemented.

## Important Considerations and Risks

- **Alpha Stage**: This plugin is under development. **Backup your database** before use.
- **Database Growth**: Storing translations increases database size.
- **Performance**: Scheduled tasks may impact busy sites. Adjust task frequency or batch sizes (`managelimit`) as needed.
- **Content Alteration**: Tags like `{t:aBcDeFgHiJ}` modify text fields. Disabling or uninstalling without cleanup may expose raw tags, affecting readability.
- **Multilang Tag Removal**: The plugin removes `<span>` and `{mlang}` multilang tags, replacing them with `{t:hash}` tags. This is a destructive, non-reversible action, and the original multilang tags cannot be restored without a custom script (not currently implemented).
- **Tagging Delay for Configured Content**: Content in configured tables (e.g., course summaries, activity intros) is tagged by the `autotranslate_task` on a schedule (default: every 15 minutes). This may delay translations becoming available for such content. However, the filter dynamically tags content during page rendering, ensuring that untagged content (including from third-party modules) is tagged immediately upon viewing.
- **Dynamic Content Handling During Rebuild**: The filter dynamically tags content during page loads, but this tagged content is not persisted in the source tables (e.g., third-party module tables). When rebuilding translations via the "Rebuild Translations" button, dynamic content not in configured tables is not reprocessed, requiring a page visit to re-tag it. This can lead to temporary loss of dynamic content tagging until the content is viewed again.
- **Hash Sensitivity**: Editing tagged text or hashes manually can break translation links.
- **Translation Quality**: Auto-translations vary by service/model; human review may be required.
- **Rebuild Performance**: The "Rebuild Translations" operation is synchronous and may take time for large courses. Consider adjusting the batch size (`managelimit`) for better performance.

## Uninstallation

To remove the plugin:

1. Disable it in **Site Administration** > **Plugins** > **Filters** > **Manage filters**.
2. Delete the plugin files from the `filter` directory.
3. Optionally, remove translation data from `mdl_filter_autotranslate_translations` and `mdl_filter_autotranslate_hid_cids`, and clean tagged content by removing `{t:hash}` tags.

**⚠️ Warning**: Without cleanup, raw tags may remain in content, and original multilang tags (`<span>` or `{mlang}`) cannot be restored. Always back up your database first.

## Support and Issues

For help or to report bugs, visit the [GitHub repository](https://github.com/kalebheitzman/moodle-filter_autotranslate).