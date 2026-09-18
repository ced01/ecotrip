<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_string($path) && is_file($_SERVER['DOCUMENT_ROOT'].$path)) {
    return false;
}

$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $_SERVER['DOCUMENT_ROOT'].'/index.php';

require $_SERVER['SCRIPT_FILENAME'];
