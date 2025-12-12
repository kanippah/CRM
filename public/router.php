<?php
$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);

if ($path === '/twilio.min.js') {
    return false;
}
if ($path === '/logo.png') {
    return false;
}
if ($path === '/favicon.png') {
    return false;
}
if ($path === '/background.jpg') {
    return false;
}

if (preg_match('/\.(?:css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$/', $path)) {
    return false;
}

require __DIR__ . '/index.php';
