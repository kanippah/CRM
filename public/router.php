<?php
$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);

$static_files = [
    '/logo.png',
    '/favicon.png',
    '/background.jpg',
    '/twilio.min.js'
];

if (in_array($path, $static_files)) {
    $file = __DIR__ . $path;
    if (file_exists($file)) {
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        $mime_types = [
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'ico' => 'image/x-icon',
            'svg' => 'image/svg+xml',
            'js' => 'application/javascript',
            'css' => 'text/css',
        ];
        $mime = $mime_types[$ext] ?? 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }
}

if (preg_match('/\.(?:css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$/', $path)) {
    $file = __DIR__ . $path;
    if (file_exists($file)) {
        return false;
    }
}

require __DIR__ . '/index.php';
