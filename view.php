<?php
require_once __DIR__ . '/config.php';
require_login();

$uid = current_user_id();
$isAdmin = function_exists('current_user_is_admin') && current_user_is_admin();
$messageId = (int)($_GET['m'] ?? 0);

if ($messageId <= 0) {
    http_response_code(404);
    exit;
}

$pdo = db();
$stmt = $pdo->prepare('SELECT id, sender_id, room_id, recipient_id, file_token, file_name, file_type FROM messages WHERE id = ?');
$stmt->execute([$messageId]);
$msg = $stmt->fetch();

if (!$msg || empty($msg['file_token'])) {
    http_response_code(404);
    exit;
}

if (!$isAdmin && !empty($msg['room_id'])) {
    $stmt = $pdo->prepare('SELECT 1 FROM room_members WHERE room_id = ? AND user_id = ?');
    $stmt->execute([(int)$msg['room_id'], $uid]);
    if (!$stmt->fetchColumn()) {
        http_response_code(403);
        exit;
    }
} elseif (!$isAdmin) {
    $senderId = (int)$msg['sender_id'];
    $recipientId = (int)$msg['recipient_id'];
    if ($uid !== $senderId && $uid !== $recipientId) {
        http_response_code(403);
        exit;
    }
}

$path = UPLOAD_DIR . '/' . $msg['file_token'];
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

$filename = $msg['file_name'] ?: 'file';
$filename = str_replace(['"', "\\", "\r", "\n"], '', $filename);
$mime = $msg['file_type'] ?: 'application/octet-stream';
$size = filesize($path);

header('Content-Type: ' . $mime);
header('Content-Length: ' . $size);
header('Content-Disposition: inline; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: sandbox');

readfile($path);
exit;
