<?php
require_once __DIR__ . '/config.php';
$homepage = 'index';
$target = rtrim(PAGES_URL, '/') . '/' . $homepage . '.php';
header('Location: ' . $target);
exit;
