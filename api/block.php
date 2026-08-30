<?php
require_once __DIR__ . '/../config.php';
require_login();

$pdo    = db();
$uid    = current_user_id();
$method = $_SERVER['REQUEST_METHOD'];

// ساخت جدول اگر وجود نداشت
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_blocks (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        blocker_id INT UNSIGNED NOT NULL,
        blocked_id INT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_block (blocker_id, blocked_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(Throwable $e) {}

if ($method === 'GET') {
    $rows = $pdo->prepare('SELECT blocked_id FROM user_blocks WHERE blocker_id=?');
    $rows->execute([$uid]);
    $blocked = array_column($rows->fetchAll(), 'blocked_id');
    json_response(['ok' => true, 'blocked' => $blocked]);
}

if ($method === 'POST') {
    require_csrf_json();
    $action    = $_POST['action'] ?? '';
    $targetId  = (int)($_POST['user_id'] ?? 0);

    if ($targetId <= 0 || $targetId === $uid) {
        json_response(['ok' => false, 'error' => 'کاربر نامعتبر.'], 400);
    }

    if ($action === 'block') {
        $pdo->prepare('INSERT IGNORE INTO user_blocks (blocker_id, blocked_id) VALUES (?,?)')->execute([$uid, $targetId]);
        // حذف private_chat_permissions — جداگانه try/catch تا اگه جدول نبود block هنوز کار کنه
        try {
            $pdo->prepare('DELETE FROM private_chat_permissions WHERE (requester_id=? AND recipient_id=?) OR (requester_id=? AND recipient_id=?)')->execute([$uid,$targetId,$targetId,$uid]);
        } catch(Throwable $e) {}
        json_response(['ok' => true, 'message' => 'کاربر بلاک شد.']);
    }

    if ($action === 'unblock') {
        $pdo->prepare('DELETE FROM user_blocks WHERE blocker_id=? AND blocked_id=?')->execute([$uid, $targetId]);
        json_response(['ok' => true, 'message' => 'بلاک برداشته شد.']);
    }

    if ($action === 'delete_chat') {
        // حذف پیام‌های DM دو طرفه برای هر دو نفر
        $pdo->prepare('UPDATE messages SET deleted_at=NOW(),deleted_by=? 
            WHERE ((sender_id=? AND recipient_id=?) OR (sender_id=? AND recipient_id=?))
            AND deleted_at IS NULL')
            ->execute([$uid, $uid, $targetId, $targetId, $uid]);
        json_response(['ok' => true, 'message' => 'گفتگو پاک شد.']);
    }

    json_response(['ok' => false, 'error' => 'عملیات نامعتبر.'], 400);
}

json_response(['ok' => false, 'error' => 'متد نامعتبر.'], 405);
