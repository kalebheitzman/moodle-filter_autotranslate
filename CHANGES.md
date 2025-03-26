# Changelog

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