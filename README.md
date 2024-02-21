# Moodle Autostranslate Filter

[![Latest Release](https://img.shields.io/github/v/release/jamfire/moodle-filter_autotranslate)](https://github.com/jamfire/moodle-filter_autotranslate/releases)
[![Moodle Plugin CI](https://github.com/jamfire/moodle-filter_autotranslate/actions/workflows/moodle-ci.yml/badge.svg)](https://github.com/jamfire/moodle-filter_autotranslate/actions/workflows/moodle-ci.yml)

## Installation

-   Unzip the plugin in the moodle .../filter/ directory.

## DeepL Integration and Plugin Settings

This plugin uses DeepL to autotranslate content on your Moodle site into any of the languages that DeepL supports. The source language is always your Moodle default site language. The target language are any of the languages that are not your default site language.

You can signup for a free or pro version key of DeepL's [API.](https://www.deepl.com/pro-api). This plugin utilizes the official [DeepL PHP client library](https://github.com/DeepLcom/deepl-php) for connecting to the API. **You will need to enter your DeepL API key under the Autotranslate filter settings before this filter will work.**

## Enable the filter

-   Go to "Site Administration &gt;&gt; Plugins &gt;&gt; Filters &gt;&gt; Manage filters" and enable the plugin there.
-   It is recommended that you position the Autotranslate Filter at the top of your filter list and enable it on headings and content.
-   There is built in support to parse existing {mlang} content and create the necessary translations in the `filter_autotranslate` table instead of losing them to autotranslation.

## Autotranslation Scheduled Task

There are two scheduled tasks that run every minute that can be configured in the Autotranslate Filter settings. The first task is an autotranslate job that translates any content loaded that is not your Moodle default site language. For example, if someone selects Spanish in the language switcher, and the default language of Moodle is in English, each line of text of the page visited will be queued for autotranslation. In the first minute after content on a page has been queued, the default site language will be served but after the autotranslate job has finished, the autotranslated version of the page will be served and become available for editing on the Autotranslation Management screen. By default, 200 strings are translated per minute using this scheduled task.

## Autotranslation Management

This filter provides a string management interface for translators to manually adjust autotranslations at `/filter/autotranslate/manage.php`. If the identical string shows up in multiple places on your Moodle site, you only need to translate the string once. This is useful for items like blocks, additional navigation, etc. You can select the different contexts you want to translate under the Autotranslate filter settings.

![Manage Page](docs/manage.jpg)

## Glossary Sync Task

The second scheduled task syncs a local copy of your Autotranslation glossary to DeepL for any [language combinations supported by DeepL.](https://www.deepl.com/docs-api/glossaries). You can manage your glossaries at `/filter/autotranslate/glossary.php`. To create a sync job, you will need to click "Sync Glossary" on the Glossary Management page for the selected source and target language pair.

![Glossary Page](docs/glossary.jpg)
