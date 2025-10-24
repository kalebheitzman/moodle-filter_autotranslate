<?php
defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/text_filter.php');
require_once(__DIR__ . '/translation_source.php');
require_once(__DIR__ . '/task/tagcontent.php');
require_once(__DIR__ . '/task/autotranslate.php');

return [
    'filter_autotranslate\\translation_source' => 'classes/translation_source.php',
    'filter_autotranslate\\task\\tagcontent'   => 'classes/task/tagcontent.php',
    'filter_autotranslate\\task\\autotranslate'=> 'classes/task/autotranslate.php',
];