<?php
require_once __DIR__ . '/config.php';
require_login();

$userId = (int)($_GET['u'] ?? 0);
$token = (string)($_GET['t'] ?? '');

if ($userId <= 0 || $token === '' || !preg_match('/^[a-f0-9]{32}$/', $token)) {
    http_response_code(404);
    exit;
}

$pdo = db();
$stmt = $pdo->prepare('SELECT avatar_token FROM users WHERE id = ?');
$stmt->execute([$userId]);
$row = $stmt->fetchColumn();
if ($row !== $token) {
    http_response_code(404);
    exit;
}

$path = UPLOAD_DIR . '/' . $token;
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

$mime = 'image/jpeg';
$finfo = @new finfo(FILEINFO_MIME_TYPE);
if ($finfo) {
    $detected = @$finfo->file($path);
    if ($detected && strpos($detected, 'image/') === 0) {
        $mime = $detected;
    }
}
$size = filesize($path);

header('Content-Type: ' . $mime);
header('Content-Length: ' . $size);
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');

readfile($path);
exit;
