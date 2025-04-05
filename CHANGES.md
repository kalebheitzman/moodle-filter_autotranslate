# Changelog

## 2025040401

- **Total Rewrite of Plugin Architecture**: Overhauled the `filter_autotranslate` plugin with a new structure for improved maintainability, performance, and flexibility. Replaced `translation_repository.php`, `translation_service.php`, and `tagging_service.php` with `content_service.php` (database writes), `translation_source.php` (read-only access), `ui_manager.php` (UI coordination), and `text_utils.php` (utilities), while keeping `text_filter.php` as the lean entry point. Retained `tagging_config.php` for settings configuration in `settings.php`.
- **Dynamic Tagging in Text Filter**: Enhanced `text_filter.php` to dynamically tag untagged content with `{t:hash}` during page rendering, supporting all content (including third-party modules) without reliance on `tagging_config.php` for runtime tagging. Configuration moved to context-based tagging via `selectctx` settings.
- **Management Interface Updates**:
  - Updated `/filter/autotranslate/manage.php` to use `ui_manager.php` and `translation_source.php` for viewing, filtering, and editing translations.
  - Retained filters for language, human status, review status, and course ID (via `mdl_filter_autotranslate_hid_cids`).
  - Kept editing via `edit.php` (WYSIWYG or textarea) and adding via `create.php`, updating `translated_text`, and `human`.
  - Removed "Rebuild Translations" button, with optional "Mark Stale" trigger (not yet in UI).
- **Multilang Tag Processing**: Improved handling of `<span>` and `{mlang}` tags in `text_utils.php`, extracting translations into `mdl_filter_autotranslate_translations` and replacing them with `{t:hash}` tags (destructive, non-reversible).
- **Scheduled Tasks**:
  - Updated `autotranslate_adhoc_task.php` to fetch translations via API, triggered by the "Autotranslate" button in `manage.php`, using `content_service.php` for storage.
  - Removed `fetchtranslation_task` as autotranslation is now adhoc; dynamic tagging handles initial content.
- **Database Schema**:
  - Kept `mdl_filter_autotranslate_translations` with `contextlevel`, `human`, `timecreated`, `timemodified`, for context and review tracking.
  - Retained `mdl_filter_autotranslate_hid_cids` for course-hash mappings.
  - Added `update_translation()` to `content_service.php` for editing translations via `edit.php`.
- **URL Rewriting**: Ensured `@@PLUGINFILE@@` URLs are rewritten in `content_service.php` during storage, with documentation in `text_filter.php` and `content_service.php`.
- **Course Mapping**: Fixed `text_filter.php` to update `mdl_filter_autotranslate_hid_cids` for all `{t:hash}` tags dynamically, ensuring accurate course filtering.
- **Duplicate Hash Fix**: Improved `text_utils.php` to reuse existing hashes for identical text, preventing duplicates (e.g., in wiki content).
- **Permissions**:
  - Kept `filter/autotranslate:manage` for system-level management (default: Manager, Editing Teacher).
  - Kept `filter/autotranslate:edit` for course-level editing (default: Teacher, Editing Teacher, Manager).
- **Performance**:
  - Leveraged dynamic tagging in `text_filter.php` for immediate processing, with optional caching via `cache.php`.
  - Removed batch processing for rebuilds, relying on lazy rebuilds for efficiency.
- **Settings Configuration**: Retained `tagging_config.php` for use in `settings.php`, providing admins with a multicheckbox to configure tables and fields, though no longer used by the core tagging logic.
- **Documentation**: Updated `CHANGES.md`, `README.md`, and added `ai-instructions.md` to reflect new architecture, dynamic tagging, lazy rebuild, and retention of `tagging_config.php` for settings.
- **Removed Features**: Eliminated `rebuild_translations_adhoc_task.php` and related CLI tools, simplifying the plugin with Option 3, while keeping `tagging_config.php` for settings.

## 2025032600

- **Total Rewrite of the Plugin**: Completely overhauled the `filter_autotranslate` plugin to improve functionality, performance, and usability, introducing a new architecture and feature set.
- **Dynamic Tagging in Text Filter**: Enhanced the `text_filter` to dynamically tag untagged content during page rendering, including content from third-party modules not explicitly configured in `tagging_config.php`. This ensures immediate tagging for viewed content, complementing the scheduled task-based tagging.
- **Course-Specific Rebuild Feature**: Added the ability to manually rebuild translations for a specific course using the "Rebuild Translations" button in the management interface (`manage.php`) or via CLI through `tagcontent_task.php`. Implemented in `rebuild_course_translations.php` with batch processing (default: 20 records per run, configurable via `managelimit`).
- **Management Interface Enhancements**:
  - Introduced a comprehensive management interface at `/filter/autotranslate/manage.php` for viewing, filtering, and editing translations.
  - Added filtering by language, human status, review status, and course ID (using `mdl_filter_autotranslate_hid_cids` for course-based filtering).
  - Enabled editing of translations via a WYSIWYG editor, updating `translated_text`, `human`, and `timereviewed`.
  - Included pagination and sorting for efficient handling of large datasets.
- **Multilang Tag Processing**: Improved support for `<span>` and `{mlang}` multilang tags by extracting translations, storing them in `mdl_filter_autotranslate_translations`, and replacing them with `{t:hash}` tags. Note that this is a destructive, non-reversible action.
- **Scheduled Tasks**:
  - Renamed the tagging task to `autotranslate_task` (runs every 15 minutes) to tag content in configured tables and process multilang tags.
  - Added `fetchtranslation_task` (runs every 30 minutes) to fetch translations from the configured OpenAI-compatible service and store them with `human = 0`.
- **Database Schema Updates**:
  - Enhanced `mdl_filter_autotranslate_translations` with fields for `contextlevel`, `human`, `timecreated`, `timemodified`, and `timereviewed` to support context awareness, human editing, and review tracking.
  - Introduced `mdl_filter_autotranslate_hid_cids` to map hashes to course IDs, enabling course-based filtering in the management interface.
- **Fixed @@PLUGINFILE@@ URL Rewriting**: Resolved issues with `@@PLUGINFILE@@` URL rewriting by handling it in `translation_service.php` during translation storage, ensuring stored translations have fully resolved URLs. Updated documentation in `text_filter.php`, `tagging_service.php`, and `translation_service.php` to reflect this fix.
- **Fixed Missing hid_cids Entries**: Ensured the `text_filter` updates `mdl_filter_autotranslate_hid_cids` for all `{t:hash}` tags, aligning with the behavior of `tagcontent_task` and preventing missing course mappings on the manage page.
- **Fixed Duplicate Wiki Page Content**: Addressed an issue where wiki page content (`mdl_wiki_pages.cachedcontent`) was being tagged with duplicate hashes by reusing existing hashes in the `text_filter` and improving caching logic.
- **Permissions and Capabilities**:
  - Added `filter/autotranslate:manage` capability to allow system-level management of translations, including the "Rebuild Translations" feature (default roles: Manager, Editing Teacher).
  - Added `filter/autotranslate:edit` capability to allow editing translations within specific courses (default roles: Teacher, Editing Teacher, Manager).
- **Performance Optimizations**:
  - Implemented batch processing in `tagcontent_task.php` and `rebuild_course_translations.php` (default: 20 records per run, configurable via `managelimit`).
  - Added caching for dynamically tagged content in the `text_filter` using Moodle’s cache API (`taggedcontent` cache).
- **Updated Documentation**: Updated `ai-instructions.md` and `README.md` to reflect the new architecture, dynamic tagging functionality, course-specific rebuild feature, and implications for tagging delay and dynamic content handling.

## 2024030400

- Disabled having to be in edit mode for the manage page

## 2024022202

- Major Refactor
- Better mlang support
- Fixed various bugs after testing with production data

## 2024021700

- Initial Release