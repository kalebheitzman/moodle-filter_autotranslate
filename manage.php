<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Autotranslation Manager
 *
 * @package    filter_autotranslate
 * @copyright  2024 Kaleb Heitzman <kaleb@jamfire.io>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @see        https://docs.moodle.org/dev/Output_API
 */

require_once(dirname(__DIR__, 2) . '/config.php');
require_once($CFG->libdir . '/pagelib.php');

// Create page context.
$PAGE = new moodle_page();
$context = context_system::instance();
$PAGE->set_context($context);

// Set access permissions.
require_login();
require_capability('filter/autotranslate:translate', $context);

// Set initial page layout.
$title = get_string('manage_title', 'filter_autotranslate');
$PAGE->set_url('/filter/autotranslate/manage.php');
$PAGE->set_title($title);
$PAGE->set_heading($title);
$PAGE->set_pagelayout('base');
$PAGE->requires->css('/filter/autotranslate/styles.css');

// Get the renderer.
$output = $PAGE->get_renderer('filter_autotranslate');

// Output header.
echo $output->header();

// Output translation grid.
$renderable = new \filter_autotranslate\output\manage_page();
echo $output->render($renderable);

// Output footer.
echo $output->footer();
