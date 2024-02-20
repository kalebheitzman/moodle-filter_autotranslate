# Moodle Autostranslate Filter

[![Latest Release](https://img.shields.io/github/v/release/jamfire/moodle-filter_autotranslate)](https://github.com/jamfire/moodle-filter_autotranslate/releases)
[![Moodle Plugin CI](https://github.com/jamfire/moodle-filter_autotranslate/actions/workflows/moodle-ci.yml/badge.svg)](https://github.com/jamfire/moodle-filter_autotranslate/actions/workflows/moodle-ci.yml)

## Installation

-   Unzip the plugin in the moodle .../filter/ directory.

## Enable the filter

-   Go to "Site Administration &gt;&gt; Plugins &gt;&gt; Filters &gt;&gt; Manage filters" and enable the plugin there.
-   It is recommended that you position the Autotranslate Filter at the top of your filter list and enable it on headings and content.

## DeepL Integration

This plugin uses DeepL to autotranslate content on your Moodle site into any of the languages that DeepL supports. The source language is always your Moodle default site language. The target language are any of the languages that are not your default site language.

You can signup for a free or pro version API key on DeepL's [Api page.](https://www.deepl.com/pro-api)

## Autotranslation Scheduled Task

There are two scheduled tasks that run every minute that can be configured in the Autotranslate Filter settings. The first task is an autotranslate job that translates any content viewed outside of your Moodle default language. For example, if someone selects Spanish as thier site language, and the default language of Moodle is in English, each line of text of the page visited will be queued for autotranslation. In the first minute after content on a page has been queued, the default language will be served but after the autotranslate job has finished, the autotranslated version of the page will be served.

## Autotranslation Management

This filter provides a string management interface for translators to manually adjust autotranslations at `/filter/autotranslate/manage.php`.
