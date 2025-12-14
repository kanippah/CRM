<?php
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$static_extensions = ['png', 'jpg', 'jpeg', 'gif', 'ico', 'svg', 'css', 'js', 'woff', 'woff2', 'ttf', 'eot'];
$extension = strtolower(pathinfo($uri, PATHINFO_EXTENSION));

// Serve static files
if (in_array($extension, $static_extensions)) {
    $file = __DIR__ . $uri;
    if (file_exists($file) && is_file($file)) {
        $mime_types = [
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'ico' => 'image/x-icon',
            'svg' => 'image/svg+xml',
            'css' => 'text/css',
            'js' => 'application/javascript',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'eot' => 'application/vnd.ms-fontobject'
        ];
        header('Content-Type: ' . ($mime_types[$extension] ?? 'application/octet-stream'));
        header('Cache-Control: public, max-age=3600');
        readfile($file);
        exit;
    }
}

// Route everything else to index.php
require __DIR__ . '/index.php';
