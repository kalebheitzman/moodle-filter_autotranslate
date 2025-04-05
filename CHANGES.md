# Changelog

## 2025040401

- **Architecture Refinement**: Refined `filter_autotranslate` structure for performance and clarity:
  - `text_filter.php` now replaces `{t:hash}` tags only, caching via `cache.php` (`taggedcontent`).
  - `content_service.php` handles all writes (tagging, storing), using `upsert_translation()` for edits.
  - `translation_source.php` provides read-only data access.
  - `ui_manager.php` coordinates UI for `manage.php`.
  - `text_utils.php` offers utility functions (e.g., `generate_unique_hash()`).
- **Scheduled Tagging**: Replaced dynamic tagging in `text_filter.php` with `tagcontent_scheduled_task.php`, running every 5 minutes to tag content with `{t:hash}` across configured tables.
- **Management Interface**:
  - Updated `manage.php` to use `ui_manager.php` and `translation_source.php` for viewing/filtering translations.
  - Filters: language, human status, review status, course ID (via `mdl_filter_autotranslate_hid_cids`).
  - Edit via `edit.php`, add via `create.php` (WYSIWYG or textarea), updating `translated_text` and `human`.
  - "Autotranslate" button triggers `autotranslate_adhoc_task.php` for API translations.
- **Multilang Processing**: Enhanced `text_utils.php` to process `<span>` and `{mlang}` tags in `tagcontent_scheduled_task.php`, storing translations and replacing with `{t:hash}` (destructive).
- **Scheduled Tasks**:
  - Added `tagcontent_scheduled_task.php` (every 5 minutes) for content tagging.
  - Updated `autotranslate_adhoc_task.php` for manual API translation fetches from `manage.php`.
- **Database Schema**: Kept `mdl_filter_autotranslate_translations` with `contextlevel`, `human`, `timecreated`, `timemodified`; retained `mdl_filter_autotranslate_hid_cids` for course mappings.
- **Caching**: Added `cache.php` with `taggedcontent`, `modschemas`, `selectedfields` caches for `text_filter.php` and `content_service.php`.
- **URL Rewriting**: Fixed `@@PLUGINFILE@@` URLs in `content_service.php` during storage.
- **Permissions**: Retained `filter/autotranslate:manage` (system) and `filter/autotranslate:edit` (course).
- **Settings**: Updated `settings.php` to configure tables/fields, used by `tagcontent_scheduled_task.php`.
- **Removed**: Dropped `mark_course_stale` and rebuild features, simplifying to scheduled tagging and adhoc translation.

## 2025032600

- **Major Rewrite**: Overhauled plugin with dynamic tagging in `text_filter.php` and course-specific rebuilds (later removed).
- **Management Enhancements**: Added `manage.php` with filtering, editing via WYSIWYG, and rebuild button (deprecated).
- **Multilang Support**: Processed `<span>` and `{mlang}` tags dynamically, storing in `mdl_filter_autotranslate_translations`.
- **Tasks**: Introduced `autotranslate_task` (15-minute tagging) and `fetchtranslation_task` (30-minute API fetch), later replaced.
- **Schema Updates**: Added `contextlevel`, `human`, `timecreated`, `timemodified`, `timereviewed` (later dropped) to `mdl_filter_autotranslate_translations`.

## 2024030400

- **UI Tweak**: Removed edit mode requirement for `manage.php`, improving accessibility.

## 2024022202

- **Refactor**: Major code overhaul for stability and better `<span>`/ `{mlang}` support.
- **Bug Fixes**: Addressed issues found in production testing.

## 2024021700

- **Initial Release**: First version with basic tagging and translation functionality.