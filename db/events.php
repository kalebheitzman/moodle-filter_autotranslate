<?php
defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\course_module_created',
        'callback' => 'filter_autotranslate\observer::course_module_created',
    ],
    [
        'eventname' => '\core\event\course_module_updated',
        'callback' => 'filter_autotranslate\observer::course_module_updated',
    ],
    [
        'eventname' => '\core\event\course_updated',
        'callback' => 'filter_autotranslate\observer::course_updated',
    ],
    [
        'eventname' => '\core\event\course_section_updated',
        'callback' => 'filter_autotranslate\observer::course_section_updated',
    ]
];