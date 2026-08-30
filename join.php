<?php
require_once __DIR__ . '/config.php';

$invite = trim((string)($_GET['invite'] ?? ''));

if (empty($_SESSION['user_id'])) {
    if ($invite !== '') {
        $_SESSION['redirect_after_login'] = 'join.php?invite=' . urlencode($invite);
    }
    header('Location: index.php' . ($invite !== '' ? '?invite=' . urlencode($invite) : ''));
    exit;
}

if ($invite === '') {
    header('Location: chat.php');
    exit;
}

$uid = current_user_id();
$pdo = db();

$hasInviteToken = false;
try {
    $chk = $pdo->query("SHOW COLUMNS FROM rooms LIKE 'invite_token'");
    $hasInviteToken = $chk->rowCount() > 0;
} catch (Throwable $e) {}

if (!$hasInviteToken) {
    header('Location: chat.php');
    exit;
}

$stmt = $pdo->prepare('SELECT id, name, vip_only FROM rooms WHERE invite_token = ?');
$stmt->execute([$invite]);
$room = $stmt->fetch();

if (!$room) {
    $_SESSION['join_error'] = 'لینک دعوت منقضی یا نامعتبر است.';
    header('Location: chat.php');
    exit;
}

$roomId = (int)$room['id'];
if (!empty($room['vip_only'])) {
    $stmt = $pdo->prepare('SELECT is_vip FROM users WHERE id = ?');
    $stmt->execute([$uid]);
    if (!$stmt->fetchColumn()) {
        $_SESSION['join_error'] = 'این اتاق فقط برای VIP است.';
        header('Location: chat.php');
        exit;
    }
}

$stmt = $pdo->prepare('INSERT IGNORE INTO room_members (room_id, user_id) VALUES (?, ?)');
$stmt->execute([$roomId, $uid]);
$stmt = $pdo->prepare('SELECT MAX(id) FROM messages WHERE room_id = ? AND deleted_at IS NULL');
$stmt->execute([$roomId]);
$maxId = (int)($stmt->fetchColumn() ?: 0);
$stmt = $pdo->prepare('UPDATE room_members SET last_read_id = GREATEST(COALESCE(last_read_id, 0), ?) WHERE room_id = ? AND user_id = ?');
$stmt->execute([$maxId, $roomId, $uid]);

header('Location: chat.php?room=' . $roomId);
exit;
