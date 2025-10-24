# Moodle Autotranslate Filter – Agent Guide
## Architecture
- `classes/text_filter.php` is the runtime entry; it swaps `{t:hash}` tags using `translation_source` and caches results in `taggedcontent`.
- `classes/content_service.php` owns all writes (tagging, persisting, pluginfile rewrites) and depends on `text_utils`, `modschemas`, and `selectedfields` caches—alter it carefully and keep transactions intact.
- `classes/translation_source.php` is read-only data access; `classes/ui_manager.php` wraps it for UI defaults—`ui_manager` currently self-injects `$DB`, so only pass dependencies if you also change its constructor.
- `classes/task/tagcontent.php` is wired via `db/tasks.php` but the implementation is commented out; resurrecting tagging requires re-enabling that logic and staying consistent with settings matrices.
## Workflow
- Field selections in `settings.php` use `admin_setting_configfieldmatrix`; chosen tables drive tagging scope and course/context mapping via `content_service`.
- `manage.php` + `templates/manage.mustache` render translation tables, using `classes/form/manage_form.php`, `css/manage.css`, and `amd/src/autotranslate.js` for AJAX-triggered tasks.
- `externallib.php` exposes `filter_autotranslate_autotranslate` and `filter_autotranslate_task_status`, enforcing `filter/autotranslate:edit` and queueing `\filter_autotranslate\task\autotranslate` with hash batches.
- `classes/task/autotranslate.php` hits an OpenAI-compatible `/chat/completions` endpoint, processes batches of five, respects rate limits, and records progress in `mdl_filter_autotranslate_task_progress` (requeueing itself when runtime exceeds ~3 minutes).
## Data & Caching
- Core tables: `mdl_filter_autotranslate_translations` (source stored as `lang='other'`), `mdl_filter_autotranslate_hid_cids` (hash ↔ course), and `mdl_filter_autotranslate_task_progress` (UI polling).
- Cache definitions in `db/caches.php` back `taggedcontent`, `modschemas`, and `selectedfields`; bust the right cache if you touch schema discovery or tagging output.
- Hash utilities live in `classes/text_utils.php` (`generate_unique_hash`, `extract_hash`, `process_mlang_tags`) and ensure identical source text reuses existing hashes.
- Render pipeline expects stored content shaped as `source text {t:hash}`—the filter strips tags when translation is missing, so never return raw `{t:hash}` to users.
## Conventions & Gotchas
- Entry scripts (`manage.php`, `create.php`, `edit.php`, `externallib.php`, `settings.php`, `db/tasks.php`) execute before the autoloader finishes; keep explicit `require_once` calls for any new classes.
- Coding standard is Moodle (`phpcs.xml.dist`); follow lowercase variable names, snake_case functions, and verbose PHPDoc like in `classes/text_filter.php`.
- Rebuild AMD assets with `npx grunt amd --component filter_autotranslate` from the Moodle root whenever you change `amd/src`, and commit the generated `amd/build` file.
- Webservice flows rely on `filter_autotranslate/targetlangs` existing—validate setting updates before queueing tasks to avoid `invalid_parameter_exception` errors.
## Testing & Ops
- Manual regression steps, including manage/edit flows and task runs, live in `docs/testing-plan.md`; align new work with that checklist.
- Use Moodle CLI to simulate schedulers: `php admin/cli/scheduled_task.php --execute='\filter_autotranslate\task\tagcontent'` and `php admin/cli/scheduled_task.php --execute='\filter_autotranslate\task\autotranslate'`, plus `php admin/cli/purge_caches.php` after schema or settings changes.
- `README.md` documents privacy risks and destructive multilang replacement; surface similar warnings in new UX or docs.
## Pointers
- `docs/ai-instructions.md` and `docs/overview.md` already capture the broader design—update them alongside architectural changes to keep guidance consistent.
