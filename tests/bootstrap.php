<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Constants the application normally defines in its bootstrap / conf_global.php
defined('DS') || define('DS', DIRECTORY_SEPARATOR);
defined('ROOT') || define('ROOT', __DIR__ . '/fixtures');
defined('TEMPLATES_PATH') || define('TEMPLATES_PATH', __DIR__ . '/fixtures/templates');
defined('VIEWS_PATH') || define('VIEWS_PATH', __DIR__ . '/fixtures/views');
