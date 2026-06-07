<?php
require_once __DIR__ . '/config.php';
$homepage = 'index';
$target = '/pages/' . $homepage . '.php';
header('Location: ' . $target);
exit;
