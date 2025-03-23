# Testing Plan for `filter_autotranslate` Plugin

## Testing Plan

The testing plan for the `filter_autotranslate` plugin is documented in [docs/testing-plan.md](docs/testing-plan.md). Testing progress is tracked in the following issues:
- [Manage Interface Testing](https://github.com/kalebheitzman/moodle-filter_autotranslate/issues/8)
- [Edit Interface Testing](https://github.com/kalebheitzman/moodle-filter_autotranslate/issues/9)
- [Event Observers Testing](https://github.com/kalebheitzman/moodle-filter_autotranslate/issues/10)
- [Tasks Testing](https://github.com/kalebheitzman/moodle-filter_autotranslate/issues/11)
- [Translation Workflow Testing](https://github.com/kalebheitzman/moodle-filter_autotranslate/issues/12)
- [Edge Cases Testing](https://github.com/kalebheitzman/moodle-filter_autotranslate/issues/13)

## Table of Contents
- [Overview](#overview)
- [Prerequisites](#prerequisites)
- [1. Manage Interface (`manage.php`)](#1-manage-interface-managephp)
  - [Test Case 1.1: Display Translations](#test-case-11-display-translations)
  - [Test Case 1.2: Filter by Language](#test-case-12-filter-by-language)
  - [Test Case 1.3: Filter by Human Reviewed](#test-case-13-filter-by-human-reviewed)
  - [Test Case 1.4: Filter by Needs Review](#test-case-14-filter-by-needs-review)
  - [Test Case 1.5: Pagination](#test-case-15-pagination)
  - [Test Case 1.6: Navigation to Edit Page](#test-case-16-navigation-to-edit-page)
- [2. Edit Interface (`edit.php`)](#2-edit-interface-editphp)
  - [Test Case 2.1: Page Load and Layout](#test-case-21-page-load-and-layout)
  - [Test Case 2.2: Prevent Editing `other` Language](#test-case-22-prevent-editing-other-language)
  - [Test Case 2.3: Edit a Translation](#test-case-23-edit-a-translation)
  - [Test Case 2.4: Cancel Editing](#test-case-24-cancel-editing)
  - [Test Case 2.5: Switch Language](#test-case-25-switch-language)
  - [Test Case 2.6: WYSIWYG Editor vs. Textarea](#test-case-26-wysiwyg-editor-vs-textarea)
- [3. Event Observers (`observer.php`)](#3-event-observers-observerphp)
  - [Test Case 3.1: Course Update Triggers Observer](#test-case-31-course-update-triggers-observer)
  - [Test Case 3.2: Module Update Triggers Observer](#test-case-32-module-update-triggers-observer)
  - [Test Case 3.3: Course Section Update Triggers Observer](#test-case-33-course-section-update-triggers-observer)
- [4. Tasks (`tagcontent_task.php` and `fetchtranslation_task.php`)](#4-tasks-tagcontent_taskphp-and-fetchtranslation_taskphp)
  - [Test Case 4.1: Run `tagcontent_task` on Course Content](#test-case-41-run-tagcontent_task-on-course-content)
  - [Test Case 4.2: Run `tagcontent_task` on Module Content](#test-case-42-run-tagcontent_task-on-module-content)
  - [Test Case 4.3: Run `tagcontent_task` with Existing Tags](#test-case-43-run-tagcontent_task-with-existing-tags)
  - [Test Case 4.4: Run `fetchtranslation_task` to Fetch Translations](#test-case-44-run-fetchtranslation_task-to-fetch-translations)
  - [Test Case 4.5: Run `fetchtranslation_task` with Rate Limiting](#test-case-45-run-fetchtranslation_task-with-rate-limiting)
  - [Test Case 4.6: Run `fetchtranslation_task` with Invalid API Key](#test-case-46-run-fetchtranslation_task-with-invalid-api-key)
  - [Test Case 4.7: Run `fetchtranslation_task` with No Target Languages](#test-case-47-run-fetchtranslation_task-with-no-target-languages)
- [5. Translation Workflow](#5-translation-workflow)
  - [Test Case 5.1: End-to-End Workflow (Source Update to Translation Fetch and Review)](#test-case-51-end-to-end-workflow-source-update-to-translation-fetch-and-review)
  - [Test Case 5.2: No Translation Found](#test-case-52-no-translation-found)
- [6. Edge Cases](#6-edge-cases)
  - [Test Case 6.1: Source Text with Special Characters](#test-case-61-source-text-with-special-characters)
  - [Test Case 6.2: Empty Source Text](#test-case-62-empty-source-text)
  - [Test Case 6.3: Invalid Language](#test-case-63-invalid-language)
  - [Test Case 6.4: Missing Permissions](#test-case-64-missing-permissions)
- [Testing Notes](#testing-notes)
- [Post-Testing Actions](#post-testing-actions)
- [Test Results](#test-results)

## Overview
This testing plan covers the manual testing of the `filter_autotranslate` plugin in a Moodle environment. The plugin allows for automatic translation of content, management of translations, and editing of translations with a `timereviewed` field to track when translations are reviewed. The testing will focus on the following areas:
1. **Manage Interface (`manage.php`)**: Test the display, filtering, and navigation of translations.
2. **Edit Interface (`edit.php`)**: Test the editing of translations, including `timereviewed` updates and UI behavior.
3. **Event Observers (`observer.php`)**: Test the behavior when source content is updated (e.g., course updates, module updates).
4. **Tasks (`tagcontent_task.php` and `fetchtranslation_task.php`)**: Test the tagging of translatable content and fetching of automatic translations.
5. **Translation Workflow**: Test the overall workflow from source content updates to translation management and editing.
6. **Edge Cases**: Test scenarios like missing translations, invalid languages, and UI edge cases.

## Prerequisites
- **Moodle Environment**: Ensure you have a Moodle instance set up with the `filter_autotranslate` plugin installed and enabled.
- **Languages**: Enable multiple language packs in Moodle (e.g., `en`, `cs`, `hu`, `bg`, `pl`, `ro`, `ru`, `tr`) and configure the plugin’s `targetlangs` setting to include these languages (e.g., `cs,hu,bg,pl,ro,ru,tr`).
- **API Configuration**: Configure the plugin’s API settings for `fetchtranslation_task`:
  - Set `apiendpoint` to a valid Google Generative AI API endpoint (e.g., `https://generativelanguage.googleapis.com/v1beta/openai`).
  - Set `apikey` to a valid API key.
  - Set `apimodel` to a valid model (e.g., `gemini-1.5-pro-latest`).
  - Set `systeminstructions` (e.g., "Translate with a formal tone.").
  - Set `batchsize` (e.g., `10`), `fetchlimit` (e.g., `200`), `maxattempts` (e.g., `3`), and `ratelimitthreshold` (e.g., `50`).
- **Sample Content**: Create a course with translatable content (e.g., course summary, section summary, module intro, page content) to test the plugin’s functionality.
- **User Role**: Log in as a user with the `filter/autotranslate:manage` capability (e.g., an admin or manager role).
- **Clear Caches**: After making any changes to the plugin, clear the Moodle caches:
  <code>
  sudo -u www-data php admin/cli/purge_caches.php
  </code>

## 1. Manage Interface (`manage.php`)

### Test Case 1.1: Display Translations
**Objective**: Verify that the manage interface displays translations correctly.
- **Steps**:
  1. Navigate to the manage page (`/filter/autotranslate/manage.php`).
  2. Observe the table of translations.
- **Expected Outcome**:
  - The table displays columns: "Hash", "Language", "Translated Text" (or "Source Text" and "Translated Text" if a specific language is selected), "Human Reviewed", "Context Level", "Review Status", and "Actions".
  - Each row shows a translation with the correct hash, language, translated text, human reviewed status (Yes/No), context level, review status (warning icon if `timereviewed < timemodified`), and an "Edit" link.
- **Notes**:
  - Check that the "Review Status" column shows a warning icon for translations where `timereviewed < timemodified`.
  - Verify that the "Human Reviewed" column shows "Yes" or "No" correctly based on the `human` field.

### Test Case 1.2: Filter by Language
**Objective**: Verify that the language filter works correctly.
- **Steps**:
  1. On the manage page, locate the "Filter by Language" section.
  2. Click the "All" button, then click a specific language (e.g., "CS").
  3. Observe the table.
- **Expected Outcome**:
  - When "All" is selected, the table shows translations for all languages.
  - When "CS" is selected, the table shows only translations for the `cs` language, with columns "Source Text" and "Translated Text".
- **Notes**:
  - Ensure the "Source Text" column shows the `other` language text (e.g., `en` if that’s the site language).
  - Verify that the filter buttons update correctly (active button is highlighted).

### Test Case 1.3: Filter by Human Reviewed
**Objective**: Verify that the human reviewed filter works correctly.
- **Steps**:
  1. On the manage page, locate the "Filter by Human Reviewed" section.
  2. Click the "Yes" button, then click "No", then click "All".
  3. Observe the table after each filter change.
- **Expected Outcome**:
  - "Yes" filter shows only translations where `human = 1`.
  - "No" filter shows only translations where `human = 0`.
  - "All" filter shows all translations regardless of `human` status.
- **Notes**:
  - Verify that the filter buttons update correctly (active button is highlighted).
  - Check that the "Human Reviewed" column values match the filter applied.

### Test Case 1.4: Filter by Needs Review
**Objective**: Verify that the needs review filter works correctly.
- **Steps**:
  1. On the manage page, locate the "Filter by Needs Review" section.
  2. Click the "Yes" button, then click "No", then click "All".
  3. Observe the table after each filter change.
- **Expected Outcome**:
  - "Yes" filter shows only translations where `timereviewed < timemodified` (translations needing review).
  - "No" filter shows only translations where `timereviewed >= timemodified` (translations up to date).
  - "All" filter shows all translations regardless of review status.
- **Notes**:
  - Verify that the "Review Status" column shows a warning icon for translations in the "Yes" filter.
  - Check that the filter buttons update correctly (active button is highlighted).

### Test Case 1.5: Pagination
**Objective**: Verify that pagination works correctly.
- **Steps**:
  1. On the manage page, ensure there are enough translations to require pagination (e.g., more than 20 if `perpage` is set to 20).
  2. Use the "Translations per page" filter to set different values (e.g., 10, 20, 50).
  3. Navigate through the pages using the pagination links.
- **Expected Outcome**:
  - The table displays the correct number of translations per page based on the selected `perpage` value.
  - Pagination links work correctly, showing the appropriate page of translations.
- **Notes**:
  - Verify that the "Translations per page" buttons update correctly (active button is highlighted).
  - Check that filters (language, human reviewed, needs review) persist across page changes.

### Test Case 1.6: Navigation to Edit Page
**Objective**: Verify that clicking the "Edit" link navigates to the edit page.
- **Steps**:
  1. On the manage page, locate a translation row.
  2. Click the "Edit" link in the "Actions" column.
- **Expected Outcome**:
  - The browser navigates to the edit page (`/filter/autotranslate/edit.php`) with the correct `hash` and `tlang` parameters.
  - The edit page loads correctly (to be tested in the next section).
- **Notes**:
  - Verify that the URL parameters match the translation’s `hash` and `lang`.

## 2. Edit Interface (`edit.php`)

### Test Case 2.1: Page Load and Layout
**Objective**: Verify that the edit page loads correctly with the expected layout.
- **Steps**:
  1. From the manage page, click the "Edit" link for a translation (e.g., language `cs`).
  2. Observe the edit page layout.
- **Expected Outcome**:
  - The page displays a header with "Edit Translation".
  - Below the header, there are two buttons: "Manage Translations" (left) and a "Switch Language" section (right) with language buttons (e.g., "BG", "CS", "HU", etc.).
  - The main content is a row with two columns:
    - Left column (7/12 width): A card titled "Translation" containing the form with fields "Hash" (static), "Language" (static), "Translated Text" (textarea or editor), and "Human Translated" (checkbox), followed by "Save changes" and "Cancel" buttons.
    - Right column (5/12 width): A card titled "Source Text" containing the source text (e.g., in English if that’s the site language).
- **Notes**:
  - Verify that the "Switch Language" section does not include the `other` language.
  - Check that the form fields are populated with the correct values (e.g., `translated_text` matches the current translation).

### Test Case 2.2: Prevent Editing `other` Language
**Objective**: Verify that the `other` language cannot be edited through the edit page.
- **Steps**:
  1. Manually navigate to the edit page with `tlang=other` (e.g., `/filter/autotranslate/edit.php?hash=<hash>&tlang=other`).
  2. Observe the result.
- **Expected Outcome**:
  - An error message is displayed: "The site language (other) cannot be edited through this interface. Please update the source content directly."
- **Notes**:
  - Verify that the error message is clear and prevents further action.

### Test Case 2.3: Edit a Translation
**Objective**: Verify that editing a translation updates the record correctly, including `timereviewed`.
- **Steps**:
  1. From the manage page, select a translation needing review (i.e., with a warning icon in the "Review Status" column, indicating `timereviewed < timemodified`).
  2. Click the "Edit" link to go to the edit page.
  3. Modify the "Translated Text" field (e.g., add some text).
  4. Check the "Human Translated" checkbox.
  5. Click "Save changes".
  6. Observe the result.
- **Expected Outcome**:
  - The browser redirects to the manage page with a success message: "Translation saved successfully."
  - The translation record in the database is updated:
    - `translated_text` reflects the new text.
    - `human` is set to `1`.
    - `timereviewed` and `timemodified` are set to the current timestamp.
  - On the manage page, the warning icon in the "Review Status" column for this translation is no longer present (since `timereviewed` now equals `timemodified`).
  - The "Human Reviewed" column shows "Yes".
- **Notes**:
  - Check the database directly (e.g., using phpMyAdmin) to confirm the `timereviewed` and `timemodified` values.
  - Verify that the success message is displayed correctly.

### Test Case 2.4: Cancel Editing
**Objective**: Verify that canceling the edit form returns to the manage page without changes.
- **Steps**:
  1. From the manage page, click the "Edit" link for a translation.
  2. Modify the "Translated Text" field (e.g., add some text).
  3. Click "Cancel".
  4. Observe the result.
- **Expected Outcome**:
  - The browser redirects to the manage page.
  - No changes are made to the translation record in the database (e.g., `translated_text`, `human`, `timereviewed`, and `timemodified` remain unchanged).
- **Notes**:
  - Check the database to confirm no changes were made.

### Test Case 2.5: Switch Language
**Objective**: Verify that the language switcher works correctly.
- **Steps**:
  1. On the edit page for a translation (e.g., language `cs`), locate the "Switch Language" section.
  2. Click a different language button (e.g., "HU").
  3. Observe the result.
- **Expected Outcome**:
  - The edit page reloads with the new language (`tlang=hu`).
  - The form displays the translation for the `hu` language, with the correct `translated_text` and `human` values.
  - The source text remains the same (since it’s always the `other` language text).
- **Notes**:
  - Verify that the "Switch Language" buttons update correctly (active button is highlighted).
  - Check that the form fields are populated with the correct values for the new language.

### Test Case 2.6: WYSIWYG Editor vs. Textarea
**Objective**: Verify that the editor type (WYSIWYG or textarea) is correctly chosen based on the source text.
- **Steps**:
  1. Ensure a translation exists where the source text (`other` language) contains HTML (e.g., `<p>Sample paper of final reflection SBL</p>`).
  2. From the manage page, click the "Edit" link for this translation.
  3. Observe the "Translated Text" field.
  4. Repeat the test with a translation where the source text is plain text (e.g., "Sample paper of final reflection SBL").
- **Expected Outcome**:
  - For HTML source text, the "Translated Text" field is a WYSIWYG editor.
  - For plain text source text, the "Translated Text" field is a textarea.
- **Notes**:
  - Verify that the editor/textarea renders correctly and allows editing.
  - Check that saving the form preserves the HTML/plain text format as expected.

## 3. Event Observers (`observer.php`)

### Test Case 3.1: Course Update Triggers Observer
**Objective**: Verify that updating a course summary updates the `timemodified` of related translations.
- **Steps**:
  1. Create a course with a summary (e.g., "Course Summary Test").
  2. Wait for the autotranslate task to run (or manually trigger it if enabled) to generate translations for the summary.
  3. On the manage page, note the `timemodified` and `timereviewed` values for the translations of this summary (e.g., for `cs`, `hu`).
  4. Edit the course summary (e.g., change it to "Updated Course Summary Test").
  5. Save the changes.
  6. Return to the manage page and refresh.
- **Expected Outcome**:
  - The `timemodified` of the translations for the course summary is updated to the current timestamp.
  - The `timereviewed` of the translations remains unchanged.
  - The "Review Status" column shows a warning icon for these translations (since `timereviewed < timemodified`).
- **Notes**:
  - Check the database to confirm the `timemodified` and `timereviewed` values.
  - Verify that the observer logs (if enabled) show the update.

### Test Case 3.2: Module Update Triggers Observer
**Objective**: Verify that updating a module intro updates the `timemodified` of related translations.
- **Steps**:
  1. In a course, create a module (e.g., a Page) with an intro (e.g., "Module Intro Test").
  2. Wait for the autotranslate task to run (or manually trigger it) to generate translations for the intro.
  3. On the manage page, note the `timemodified` and `timereviewed` values for the translations of this intro.
  4. Edit the module intro (e.g., change it to "Updated Module Intro Test").
  5. Save the changes.
  6. Return to the manage page and refresh.
- **Expected Outcome**:
  - The `timemodified` of the translations for the module intro is updated to the current timestamp.
  - The `timereviewed` of the translations remains unchanged.
  - The "Review Status" column shows a warning icon for these translations.
- **Notes**:
  - Check the database to confirm the `timemodified` and `timereviewed` values.
  - Verify that the observer logs (if enabled) show the update.

### Test Case 3.3: Course Section Update Triggers Observer
**Objective**: Verify that updating a course section summary updates the `timemodified` of related translations.
- **Steps**:
  1. In a course, edit a section summary (e.g., "Section Summary Test").
  2. Wait for the autotranslate task to run (or manually trigger it) to generate translations for the summary.
  3. On the manage page, note the `timemodified` and `timereviewed` values for the translations of this summary.
  4. Edit the section summary (e.g., change it to "Updated Section Summary Test").
  5. Save the changes.
  6. Return to the manage page and refresh.
- **Expected Outcome**:
  - The `timemodified` of the translations for the section summary is updated to the current timestamp.
  - The `timereviewed` of the translations remains unchanged.
  - The "Review Status" column shows a warning icon for these translations.
- **Notes**:
  - Check the database to confirm the `timemodified` and `timereviewed` values.
  - Verify that the observer logs (if enabled) show the update.

## 4. Tasks (`tagcontent_task.php` and `fetchtranslation_task.php`)

### Test Case 4.1: Run `tagcontent_task` on Course Content
**Objective**: Verify that `tagcontent_task` correctly tags translatable content in a course (e.g., course summary, section summary).
- **Steps**:
  1. Create a course with a summary (e.g., "Course Summary Test") and a section summary (e.g., "Section Summary Test").
  2. Ensure the plugin’s `selectctx` setting includes `CONTEXT_COURSE` (50) and `CONTEXT_COURSECAT` (40).
  3. Manually run the `tagcontent_task`:
     <code>
     sudo -u www-data php admin/cli/scheduled_task.php --execute='\filter_autotranslate\task\tagcontent_task'
     </code>
  4. Check the task output in the terminal for logs (e.g., "Tagged and stored: field=summary, instanceid=<id>, old_hash=none, new_hash=<hash>, courseid=<courseid>").
  5. Check the database tables:
     - `course` table: Verify that the `summary` field now includes a translation tag (e.g., `{t:<hash>}`).
     - `course_sections` table: Verify that the `summary` field now includes a translation tag.
     - `autotranslate_hid_cids` table: Verify that the hash is mapped to the course ID.
     - `autotranslate_translations` table: Verify that a record exists for the `other` language with the hash, `translated_text`, and `contextlevel`.
- **Expected Outcome**:
  - The task completes without errors, with logs indicating that content was tagged.
  - The `course.summary` and `course_sections.summary` fields are updated with translation tags.
  - The `autotranslate_hid_cids` table contains entries mapping the hashes to the course ID.
  - The `autotranslate_translations` table contains entries for the `other` language with the correct `hash`, `translated_text`, and `contextlevel` (e.g., 50 for `CONTEXT_COURSE`).
- **Notes**:
  - Verify that the task logs show the correct number of records processed.
  - Check that the tagged content in the database includes a valid hash (e.g., `{t:abcdefghij}`).

### Test Case 4.2: Run `tagcontent_task` on Module Content
**Objective**: Verify that `tagcontent_task` correctly tags translatable content in a module (e.g., page content).
- **Steps**:
  1. In a course, create a Page module with a name (e.g., "Page Name Test") and content (e.g., "Page Content Test").
  2. Ensure the plugin’s `selectctx` setting includes `CONTEXT_MODULE` (70).
  3. Manually run the `tagcontent_task`:
     <code>
     sudo -u www-data php admin/cli/scheduled_task.php --execute='\filter_autotranslate\task\tagcontent_task'
     </code>
  4. Check the task output in the terminal for logs (e.g., "Tagged and stored: field=content, instanceid=<id>, old_hash=none, new_hash=<hash>, courseid=<courseid>").
  5. Check the database tables:
     - `page` table: Verify that the `name` and `content` fields now include translation tags.
     - `autotranslate_hid_cids` table: Verify that the hashes are mapped to the course ID.
     - `autotranslate_translations` table: Verify that records exist for the `other` language with the hashes, `translated_text`, and `contextlevel`.
- **Expected Outcome**:
  - The task completes without errors, with logs indicating that content was tagged.
  - The `page.name` and `page.content` fields are updated with translation tags.
  - The `autotranslate_hid_cids` table contains entries mapping the hashes to the course ID.
  - The `autotranslate_translations` table contains entries for the `other` language with the correct `hash`, `translated_text`, and `contextlevel` (e.g., 70 for `CONTEXT_MODULE`).
- **Notes**:
  - Verify that the task logs show the correct number of records processed.
  - Check that the tagged content in the database includes valid hashes.

### Test Case 4.3: Run `tagcontent_task` with Existing Tags
**Objective**: Verify that `tagcontent_task` handles content that is already tagged.
- **Steps**:
  1. Create a course with a summary (e.g., "Course Summary Test").
  2. Run the `tagcontent_task` to tag the content (as in Test Case 4.1).
  3. Verify that the `course.summary` field now includes a translation tag (e.g., `{t:abcdefghij}`).
  4. Run the `tagcontent_task` again.
  5. Check the task output for logs (e.g., "Updated hash mapping for existing content: instanceid=<id>, hash=<hash>, courseid=<courseid>").
  6. Check the database tables:
     - `course` table: Verify that the `summary` field still has the same translation tag.
     - `autotranslate_hid_cids` table: Verify that the hash mapping remains unchanged.
- **Expected Outcome**:
  - The task completes without errors, with logs indicating that existing tagged content was processed but not re-tagged.
  - The `course.summary` field retains its original translation tag.
  - The `autotranslate_hid_cids` table retains the existing hash mapping.
- **Notes**:
  - Verify that the task does not create duplicate entries in `autotranslate_translations` or `autotranslate_hid_cids`.

### Test Case 4.4: Run `fetchtranslation_task` to Fetch Translations
**Objective**: Verify that `fetchtranslation_task` fetches translations for untagged content.
- **Steps**:
  1. Ensure the plugin’s API settings are correctly configured (valid `apiendpoint`, `apikey`, `apimodel`, etc.).
  2. Create a course with a summary (e.g., "Course Summary Test").
  3. Run the `tagcontent_task` to tag the content (as in Test Case 4.1).
  4. Verify that the `autotranslate_translations` table contains a record for the `other` language with the hash.
  5. Manually run the `fetchtranslation_task`:
     <code>
     sudo -u www-data php admin/cli/scheduled_task.php --execute='\filter_autotranslate\task\fetchtranslation_task'
     </code>
  6. Check the task output for logs (e.g., "Fetched translation: hash=<hash>, lang=cs, text='<translated text>'").
  7. Check the `autotranslate_translations` table for new records.
- **Expected Outcome**:
  - The task completes without errors, with logs indicating that translations were fetched for each target language (e.g., `cs`, `hu`, `bg`, etc.).
  - The `autotranslate_translations` table contains new records for each target language with the same `hash` as the `other` record, with `translated_text` populated, `human` set to `0`, and `contextlevel` matching the source record.
- **Notes**:
  - Verify that the task logs show the correct number of records processed.
  - Check that the translations are reasonable (e.g., "Course Summary Test" translated to Czech for `cs`).
  - Confirm that the `human` field is `0` for machine-generated translations.

### Test Case 4.5: Run `fetchtranslation_task` with Rate Limiting
**Objective**: Verify that `fetchtranslation_task` handles rate limiting correctly.
- **Steps**:
  1. Set the plugin’s `ratelimitthreshold` to a low value (e.g., `2`) to simulate rate limiting.
  2. Create multiple courses with summaries (e.g., 5 courses with summaries "Course 1", "Course 2", etc.) to ensure multiple batches.
  3. Run the `tagcontent_task` to tag the content.
  4. Manually run the `fetchtranslation_task`:
     <code>
     sudo -u www-data php admin/cli/scheduled_task.php --execute='\filter_autotranslate\task\fetchtranslation_task'
     </code>
  5. Check the task output for logs (e.g., "Approaching rate limit (2 requests). Sleeping for 60 seconds.").
  6. Check the `autotranslate_translations` table for new records.
- **Expected Outcome**:
  - The task pauses after reaching the rate limit threshold (e.g., after 2 requests), with a log indicating a 60-second sleep.
  - After the sleep, the task continues processing the remaining batches.
  - The `autotranslate_translations` table contains new records for each target language for all courses.
- **Notes**:
  - Verify that the task completes without errors.
  - Check that the sleep duration is logged and respected.

### Test Case 4.6: Run `fetchtranslation_task` with Invalid API Key
**Objective**: Verify that `fetchtranslation_task` handles API errors (e.g., invalid API key) gracefully.
- **Steps**:
  1. Temporarily set the plugin’s `apikey` to an invalid value (e.g., "invalid_key").
  2. Create a course with a summary (e.g., "Course Summary Test").
  3. Run the `tagcontent_task` to tag the content.
  4. Manually run the `fetchtranslation_task`:
     <code>
     sudo -u www-data php admin/cli/scheduled_task.php --execute='\filter_autotranslate\task\fetchtranslation_task'
     </code>
  5. Check the task output for error messages.
  6. Check the `autotranslate_translations` table for new records.
- **Expected Outcome**:
  - The task fails with an error message in the logs (e.g., "API error: HTTP 401, Response: ...").
  - No new translations are added to the `autotranslate_translations` table.
- **Notes**:
  - Verify that the error message is clear and indicates the API key issue.
  - Restore the correct API key after this test.

### Test Case 4.7: Run `fetchtranslation_task` with No Target Languages
**Objective**: Verify that `fetchtranslation_task` handles the case where no target languages are configured.
- **Steps**:
  1. Temporarily set the plugin’s `targetlangs` setting to an empty value.
  2. Create a course with a summary (e.g., "Course Summary Test").
  3. Run the `tagcontent_task` to tag the content.
  4. Manually run the `fetchtranslation_task`:
     <code>
     sudo -u www-data php admin/cli/scheduled_task.php --execute='\filter_autotranslate\task\fetchtranslation_task'
     </code>
  5. Check the task output for messages.
  6. Check the `autotranslate_translations` table for new records.
- **Expected Outcome**:
  - The task exits early with a log message: "No target languages configured. Skipping fetch."
  - No new translations are added to the `autotranslate_translations` table.
- **Notes**:
  - Verify that the task does not attempt to fetch translations.
  - Restore the `targetlangs` setting after this test.

## 5. Translation Workflow

### Test Case 5.1: End-to-End Workflow (Source Update to Translation Fetch and Review)
**Objective**: Verify the complete workflow from source content update to translation fetch and review.
- **Steps**:
  1. Create a course with a summary (e.g., "Initial Course Summary").
  2. Run the `tagcontent_task` to tag the content.
  3. Run the `fetchtranslation_task` to fetch translations for the target languages.
  4. On the manage page, note the `timereviewed` and `timemodified` values for the translations (they should be equal initially).
  5. Update the course summary (e.g., "Updated Course Summary").
  6. Save the changes.
  7. Run the `tagcontent_task` again to update the tagged content.
  8. Return to the manage page and refresh.
  9. Verify that the translations for the course summary show a warning icon in the "Review Status" column.
  10. Click the "Edit" link for one of the translations (e.g., `cs`).
  11. Modify the "Translated Text" (e.g., translate "Updated Course Summary" to Czech).
  12. Check the "Human Translated" checkbox.
  13. Click "Save changes".
  14. Return to the manage page and refresh.
- **Expected Outcome**:
  - After running `tagcontent_task` and `fetchtranslation_task`, the `autotranslate_translations` table contains records for the `other` language and each target language.
  - After updating the course summary and running `tagcontent_task` again, the translations’ `timemodified` is updated, and the "Review Status" column shows a warning icon.
  - After editing the translation:
    - The translation’s `translated_text` is updated.
    - The `human` field is set to `1`.
    - The `timereviewed` and `timemodified` fields are updated to the current timestamp.
    - The "Review Status" column no longer shows a warning icon for this translation.
    - The "Human Reviewed" column shows "Yes".
- **Notes**:
  - Check the database to confirm the field values.
  - Verify that the success message is displayed after saving.

### Test Case 5.2: No Translation Found
**Objective**: Verify that the plugin handles cases where no translation exists for a given hash and language after tasks run.
- **Steps**:
  1. Manually navigate to the edit page with a non-existent hash (e.g., `/filter/autotranslate/edit.php?hash=nonexistent&tlang=cs`).
  2. Observe the result.
- **Expected Outcome**:
  - The browser redirects to the manage page with an error message: "No translation found for the specified hash."
- **Notes**:
  - Verify that the error message is clear and the redirect works correctly.
  - This test confirms that the tasks do not create invalid records.

## 6. Edge Cases

### Test Case 6.1: Source Text with Special Characters
**Objective**: Verify that the plugin handles source text with special characters correctly through the tasks.
- **Steps**:
  1. Create a course with a summary containing special characters (e.g., "Test & <b>Special</b> ČŠŽ").
  2. Run the `tagcontent_task` to tag the content.
  3. Run the `fetchtranslation_task` to fetch translations.
  4. On the manage page, locate the translations for this summary.
  5. Click the "Edit" link for one of the translations.
  6. Observe the source text and form.
- **Expected Outcome**:
  - The `tagcontent_task` tags the content correctly, preserving special characters and HTML.
  - The `fetchtranslation_task` fetches translations, and the translated text in the `autotranslate_translations` table handles special characters appropriately.
  - The source text displays correctly on the edit page, with special characters and HTML preserved (e.g., "Test & <b>Special</b> ČŠŽ").
  - The "Translated Text" field is a WYSIWYG editor (since the source text contains HTML).
  - Saving the translation preserves the special characters in the translated text.
- **Notes**:
  - Verify that the special characters are not corrupted or escaped incorrectly in the database or UI.

### Test Case 6.2: Empty Source Text
**Objective**: Verify that the plugin handles empty source text correctly through the tasks.
- **Steps**:
  1. Create a course with an empty summary.
  2. Run the `tagcontent_task` to tag the content.
  3. Run the `fetchtranslation_task` to fetch translations.
  4. On the manage page, locate the translations for this summary (if any).
  5. Click the "Edit" link for one of the translations (if available).
  6. Observe the source text and form.
- **Expected Outcome**:
  - The `tagcontent_task` skips empty content (no translation tag is added).
  - The `fetchtranslation_task` does not fetch translations for empty content (no records in `autotranslate_translations`).
  - If no translations exist, the edit page redirects with an error message: "No translation found for the specified hash."
  - If a translation exists (e.g., from a previous non-empty state), the source text displays as "N/A" on the edit page.
- **Notes**:
  - Verify that the task logs indicate skipping empty content.
  - Check that no unnecessary records are created in the database.

### Test Case 6.3: Invalid Language
**Objective**: Verify that the plugin handles invalid languages correctly through the tasks.
- **Steps**:
  1. Temporarily add an invalid language to the `targetlangs` setting (e.g., `invalid`).
  2. Create a course with a summary (e.g., "Course Summary Test").
  3. Run the `tagcontent_task` to tag the content.
  4. Run the `fetchtranslation_task` to fetch translations.
  5. Check the task output for error messages.
  6. Check the `autotranslate_translations` table for new records.
- **Expected Outcome**:
  - The `tagcontent_task` completes successfully, tagging the content.
  - The `fetchtranslation_task` fails to fetch translations for the invalid language, with an error message in the logs (e.g., "Invalid translation for language invalid in record...").
  - The `autotranslate_translations` table contains records only for valid languages (e.g., `cs`, `hu`), not for the invalid language.
- **Notes**:
  - Verify that the task logs indicate the error for the invalid language.
  - Restore the `targetlangs` setting after this test.

### Test Case 6.4: Missing Permissions
**Objective**: Verify that users without the `filter/autotranslate:manage` capability cannot access the manage or edit pages.
- **Steps**:
  1. Log in as a user without the `filter/autotranslate:manage` capability (e.g., a student).
  2. Navigate to the manage page (`/filter/autotranslate/manage.php`).
  3. Navigate to the edit page (e.g., `/filter/autotranslate/edit.php?hash=<hash>&tlang=cs`).
- **Expected Outcome**:
  - Both pages display an error message: "You do not have the required permissions to access this page" (or similar, depending on Moodle’s default permission error message).
- **Notes**:
  - Verify that the user is redirected to an appropriate page (e.g., the Moodle dashboard) after the error.

## Testing Notes
- **Database Verification**: For tests involving `timereviewed`, `timemodified`, and `human`, use a database tool (e.g., phpMyAdmin) to check the `autotranslate_translations` table and confirm the field values. For task-related tests, check the `autotranslate_translations` and `autotranslate_hid_cids` tables to confirm that records are created or updated correctly.
- **Task Logs**: Enable Moodle’s cron logging (`$CFG->showcronloglevel = 1;`) to capture detailed task output in the terminal or logs.
- **API Monitoring**: If possible, monitor API requests to the Google Generative AI API to verify that the `fetchtranslation_task` is making correct requests and handling responses.
- **Debugging**: Enable debugging in Moodle (`$CFG->debug = E_ALL | E_STRICT; $CFG->debugdisplay = 1;`) to catch any warnings or notices during testing.
- **Logging**: If you have observer logging enabled (e.g., in `observer.php`), check the logs to verify that events are being triggered correctly.
- **UI Consistency**: Ensure that the UI elements (e.g., buttons, cards, tables) are styled consistently with Moodle’s Bootstrap 4 theme.
- **Edge Cases**: Pay attention to edge cases like empty translations, special characters, and invalid inputs to ensure the plugin handles them gracefully.

## Post-Testing Actions
- **Document Issues**: If you encounter any issues during testing, document them with details (e.g., steps to reproduce, expected vs. actual outcome, screenshots, task logs) in the corresponding GitHub issue for that section.
- **Iterate**: Based on the test results, address any issues or add new features as needed (e.g., improving error handling in tasks, adding more logging).
- **User Feedback**: If possible, get feedback from other users (e.g., translators) to ensure the plugin meets their needs, especially for the translation fetch and tagging processes.
