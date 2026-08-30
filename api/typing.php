<?php
require_once __DIR__ . '/../config.php';
require_login();

$pdo = db();
$uid = current_user_id();
$method = $_SERVER['REQUEST_METHOD'];

function is_room_member(PDO $pdo, int $roomId, int $userId): bool {
    $stmt = $pdo->prepare('SELECT 1 FROM room_members WHERE room_id = ? AND user_id = ?');
    $stmt->execute([$roomId, $userId]);
    return (bool)$stmt->fetchColumn();
}

function is_user_invisible(PDO $pdo, int $userId): bool {
    $stmt = $pdo->prepare('SELECT is_invisible FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    return (bool)$stmt->fetchColumn();
}

if ($method === 'POST') {
    require_csrf_json();
    if (is_user_invisible($pdo, $uid)) {
        json_response(['ok' => true]);
    }
    $mode = $_POST['mode'] ?? '';

    if ($mode === 'group') {
        $roomId = (int)($_POST['room_id'] ?? 0);
        if ($roomId <= 0) {
            json_response(['ok' => false, 'error' => 'اتاق نامعتبر است.'], 400);
        }
        if (!is_room_member($pdo, $roomId, $uid)) {
            json_response(['ok' => false, 'error' => 'عضو اتاق نیستید.'], 403);
        }
        $stmt = $pdo->prepare(
            'INSERT INTO room_typing (room_id, user_id, updated_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE updated_at = NOW()'
        );
        $stmt->execute([$roomId, $uid]);
        json_response(['ok' => true]);
    }

    if ($mode === 'private') {
        $otherId = (int)($_POST['user_id'] ?? 0);
        if ($otherId <= 0) {
            json_response(['ok' => false, 'error' => 'کاربر نامعتبر است.'], 400);
        }
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ?');
        $stmt->execute([$otherId]);
        if (!$stmt->fetchColumn()) {
            json_response(['ok' => false, 'error' => 'کاربر پیدا نشد.'], 404);
        }
        $stmt = $pdo->prepare(
            'INSERT INTO private_typing (sender_id, recipient_id, updated_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE updated_at = NOW()'
        );
        $stmt->execute([$uid, $otherId]);
        json_response(['ok' => true]);
    }

    json_response(['ok' => false, 'error' => 'حالت نامعتبر است.'], 400);
}

if ($method === 'GET') {
    $mode = $_GET['mode'] ?? '';
    // همه کاربران typing رو می‌بینن
    $isVip = current_user_is_vip();

    if ($mode === 'group') {
        $roomId = (int)($_GET['room_id'] ?? 0);
        if ($roomId <= 0) {
            json_response(['ok' => false, 'error' => 'اتاق نامعتبر است.'], 400);
        }
        if (!is_room_member($pdo, $roomId, $uid)) {
            json_response(['ok' => false, 'error' => 'عضو اتاق نیستید.'], 403);
        }
        $stmt = $pdo->prepare(
            'SELECT u.username
             FROM room_typing rt
             JOIN users u ON u.id = rt.user_id
             WHERE rt.room_id = ? AND rt.user_id <> ? AND rt.updated_at >= (NOW() - INTERVAL 5 SECOND)
               AND u.is_invisible = 0
             ORDER BY u.username
             LIMIT 5'
        );
        $stmt->execute([$roomId, $uid]);
        $names = array_map(static function ($row) {
            return $row['username'];
        }, $stmt->fetchAll());
        json_response(['ok' => true, 'users' => $names]);
    }

    if ($mode === 'private') {
        $otherId = (int)($_GET['user_id'] ?? 0);
        if ($otherId <= 0) {
            json_response(['ok' => false, 'error' => 'کاربر نامعتبر است.'], 400);
        }
        $stmt = $pdo->prepare(
            'SELECT 1 FROM private_typing pt
             JOIN users u ON u.id = pt.sender_id
             WHERE pt.sender_id = ? AND pt.recipient_id = ? AND pt.updated_at >= (NOW() - INTERVAL 5 SECOND)
               AND u.is_invisible = 0'
        );
        $stmt->execute([$otherId, $uid]);
        $typing = (bool)$stmt->fetchColumn();
        json_response(['ok' => true, 'typing' => $typing]);
    }

    json_response(['ok' => false, 'error' => 'حالت نامعتبر است.'], 400);
}

json_response(['ok' => false, 'error' => 'متد مجاز نیست.'], 405);
