<?php
require_once __DIR__ . '/../config.php';
require_login();

$pdo = db();
$uid = current_user_id();
$method = $_SERVER['REQUEST_METHOD'];

function has_column_rooms(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function can_manage_room_rooms(PDO $pdo, int $roomId, int $userId): bool {
    $stmt = $pdo->prepare('SELECT created_by FROM rooms WHERE id = ?');
    $stmt->execute([$roomId]);
    $createdBy = (int)($stmt->fetchColumn() ?: 0);
    if ($createdBy === $userId) {
        return true;
    }
    if (has_column_rooms($pdo, 'room_roles', 'role')) {
        $stmt = $pdo->prepare("SELECT role FROM room_roles WHERE room_id = ? AND user_id = ? AND role IN ('owner','moderator') LIMIT 1");
        $stmt->execute([$roomId, $userId]);
        if ($stmt->fetchColumn()) {
            return true;
        }
    }
    return function_exists('current_user_is_admin') && current_user_is_admin();
}

if ($method === 'GET') {
    $hasInviteToken = has_column_rooms($pdo, 'rooms', 'invite_token');
    $hasSlowMode = has_column_rooms($pdo, 'rooms', 'slow_mode_seconds');

    $sel = 'r.id, r.name, r.password_hash, r.created_by, r.vip_only, rm.user_id AS joined, rm.last_read_id,
            CASE WHEN rm.user_id IS NULL THEN 0 ELSE (
                SELECT COUNT(*) FROM messages m
                WHERE m.room_id = r.id AND m.sender_id <> ? AND m.id > COALESCE(rm.last_read_id, 0) AND m.deleted_at IS NULL
            ) END AS unread_count,
            (SELECT m.body FROM messages m WHERE m.room_id = r.id AND m.deleted_at IS NULL ORDER BY m.id DESC LIMIT 1) AS last_body,
            (SELECT m.file_name FROM messages m WHERE m.room_id = r.id AND m.deleted_at IS NULL ORDER BY m.id DESC LIMIT 1) AS last_file_name';
    if ($hasInviteToken) {
        $sel .= ', r.invite_token';
    }
    if ($hasSlowMode) {
        $sel .= ', r.slow_mode_seconds';
    }

    $stmt = $pdo->prepare(
        "SELECT $sel
         FROM rooms r
         LEFT JOIN room_members rm ON rm.room_id = r.id AND rm.user_id = ?
         ORDER BY r.name"
    );
    $stmt->execute([$uid, $uid]);

    $rooms = [];
    foreach ($stmt->fetchAll() as $row) {
        $room = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'joined' => !empty($row['joined']),
            'has_password' => !empty($row['password_hash']),
            'can_manage' => can_manage_room_rooms($pdo, (int)$row['id'], $uid),
            'unread_count' => (int)$row['unread_count'],
            'last_body' => $row['last_body'] ?? '',
            'last_file_name' => $row['last_file_name'] ?? '',
            'vip_only' => !empty($row['vip_only']),
            'slow_mode_seconds' => (int)($row['slow_mode_seconds'] ?? 0),
        ];
        if ($hasInviteToken) {
            $room['invite_token'] = $row['invite_token'] ?? null;
        }
        $rooms[] = $room;
    }
    json_response(['ok' => true, 'rooms' => $rooms]);
}

if ($method === 'POST') {
    require_csrf_json();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $vipOnly = !empty($_POST['vip_only']);
        $isVip = current_user_is_vip();
        $isAdminUser = function_exists('current_user_is_admin') && current_user_is_admin();

        // چک تنظیم allow_room_creation
        if (!$isAdminUser) {
            $allowCreate = true;
            try {
                $chk = $pdo->query("SELECT `value` FROM site_settings WHERE `key`='allow_room_creation' LIMIT 1");
                if ($chk) {
                    $row = $chk->fetch();
                    if ($row && $row['value'] === '0') $allowCreate = false;
                }
            } catch (Throwable $e) {}
            if (!$allowCreate) {
                json_response(['ok' => false, 'error' => 'ساخت گروه توسط مدیر غیرفعال شده است.'], 403);
            }
        }
        if ($vipOnly && !$isVip) {
            json_response(['ok' => false, 'error' => 'اتاق فقط برای VIP است.'], 403);
        }
        if (!preg_match('/^[\p{L}\p{N} _-]{3,60}$/u', $name)) {
            json_response(['ok' => false, 'error' => 'نام اتاق نامعتبر است.'], 400);
        }
        $passLen = function_exists('mb_strlen') ? mb_strlen($password) : strlen($password);
        if ($password !== '' && $passLen < 4) {
            json_response(['ok' => false, 'error' => 'رمز اتاق باید حداقل ۴ کاراکتر باشد.'], 400);
        }

        $stmt = $pdo->prepare('SELECT id FROM rooms WHERE name = ?');
        $stmt->execute([$name]);
        if ($stmt->fetchColumn()) {
            json_response(['ok' => false, 'error' => 'اتاق قبلا وجود دارد.'], 400);
        }

        $passwordHash = $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null;
        $slowMode = has_column_rooms($pdo, 'rooms', 'slow_mode_seconds') ? 0 : null;
        if ($slowMode !== null) {
            $stmt = $pdo->prepare('INSERT INTO rooms (name, created_by, password_hash, vip_only, slow_mode_seconds) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$name, $uid, $passwordHash, $vipOnly ? 1 : 0, 0]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO rooms (name, created_by, password_hash, vip_only) VALUES (?, ?, ?, ?)');
            $stmt->execute([$name, $uid, $passwordHash, $vipOnly ? 1 : 0]);
        }
        $roomId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare('INSERT INTO room_members (room_id, user_id) VALUES (?, ?)');
        $stmt->execute([$roomId, $uid]);

        $stmt = $pdo->prepare('SELECT MAX(id) FROM messages WHERE room_id = ? AND deleted_at IS NULL');
        $stmt->execute([$roomId]);
        $maxId = (int)($stmt->fetchColumn() ?: 0);
        $stmt = $pdo->prepare('UPDATE room_members SET last_read_id = ? WHERE room_id = ? AND user_id = ?');
        $stmt->execute([$maxId, $roomId, $uid]);

        json_response(['ok' => true, 'room_id' => $roomId]);
    }

    if ($action === 'set_password') {
        $roomId = (int)($_POST['room_id'] ?? 0);
        $password = trim($_POST['password'] ?? '');
        if ($roomId <= 0) {
            json_response(['ok' => false, 'error' => 'شناسه اتاق نامعتبر است.'], 400);
        }
        $passLen = function_exists('mb_strlen') ? mb_strlen($password) : strlen($password);
        if ($password !== '' && $passLen < 4) {
            json_response(['ok' => false, 'error' => 'رمز اتاق باید حداقل ۴ کاراکتر باشد.'], 400);
        }

        if (!can_manage_room_rooms($pdo, $roomId, $uid)) {
            json_response(['ok' => false, 'error' => 'اجازه تغییر رمز این اتاق را ندارید.'], 403);
        }

        $passwordHash = $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null;
        $stmt = $pdo->prepare('UPDATE rooms SET password_hash = ?, created_by = COALESCE(created_by, ?) WHERE id = ?');
        $stmt->execute([$passwordHash, $uid, $roomId]);

        if ($password !== '') {
            $stmt = $pdo->prepare('DELETE FROM room_members WHERE room_id = ? AND user_id <> ?');
            $stmt->execute([$roomId, $uid]);
        }

        json_response(['ok' => true]);
    }

    if ($action === 'join') {
        $roomId = (int)($_POST['room_id'] ?? 0);
        $password = trim($_POST['password'] ?? '');
        if ($roomId <= 0) {
            json_response(['ok' => false, 'error' => 'شناسه اتاق نامعتبر است.'], 400);
        }

        $stmt = $pdo->prepare('SELECT id, password_hash, vip_only FROM rooms WHERE id = ?');
        $stmt->execute([$roomId]);
        $room = $stmt->fetch();
        if (!$room) {
            json_response(['ok' => false, 'error' => 'اتاق پیدا نشد.'], 404);
        }
        if (!empty($room['vip_only']) && !current_user_is_vip()) {
            json_response(['ok' => false, 'error' => 'این اتاق فقط برای VIP است.'], 403);
        }
        if (has_column_rooms($pdo, 'room_bans', 'user_id')) {
            $stmt = $pdo->prepare('SELECT 1 FROM room_bans WHERE room_id = ? AND user_id = ?');
            $stmt->execute([$roomId, $uid]);
            if ($stmt->fetchColumn()) {
                json_response(['ok' => false, 'error' => 'شما از این اتاق بن شده‌اید.'], 403);
            }
        }

        $stmt = $pdo->prepare('SELECT 1 FROM room_members WHERE room_id = ? AND user_id = ?');
        $stmt->execute([$roomId, $uid]);
        if ($stmt->fetchColumn()) {
            json_response(['ok' => true]);
        }

        if (!empty($room['password_hash'])) {
            if ($password === '' || !password_verify($password, $room['password_hash'])) {
                json_response(['ok' => false, 'error' => 'رمز اتاق اشتباه است.'], 403);
            }
        }

        $stmt = $pdo->prepare('INSERT IGNORE INTO room_members (room_id, user_id) VALUES (?, ?)');
        $stmt->execute([$roomId, $uid]);

        $stmt = $pdo->prepare('SELECT MAX(id) FROM messages WHERE room_id = ? AND deleted_at IS NULL');
        $stmt->execute([$roomId]);
        $maxId = (int)($stmt->fetchColumn() ?: 0);
        $stmt = $pdo->prepare('UPDATE room_members SET last_read_id = GREATEST(COALESCE(last_read_id, 0), ?) WHERE room_id = ? AND user_id = ?');
        $stmt->execute([$maxId, $roomId, $uid]);

        json_response(['ok' => true]);
    }

    if ($action === 'set_slow_mode') {
        $roomId = (int)($_POST['room_id'] ?? 0);
        $seconds = max(0, min(600, (int)($_POST['seconds'] ?? 0)));
        if ($roomId <= 0) {
            json_response(['ok' => false, 'error' => 'شناسه اتاق نامعتبر است.'], 400);
        }
        if (!has_column_rooms($pdo, 'rooms', 'slow_mode_seconds')) {
            json_response(['ok' => false, 'error' => 'مایگریشن فاز ۳ اجرا نشده است.'], 400);
        }
        if (!can_manage_room_rooms($pdo, $roomId, $uid)) {
            json_response(['ok' => false, 'error' => 'اجازه مدیریت اتاق ندارید.'], 403);
        }
        $stmt = $pdo->prepare('UPDATE rooms SET slow_mode_seconds = ? WHERE id = ?');
        $stmt->execute([$seconds, $roomId]);
        json_response(['ok' => true, 'slow_mode_seconds' => $seconds]);
    }

    if ($action === 'set_role') {
        $roomId = (int)($_POST['room_id'] ?? 0);
        $userId = (int)($_POST['user_id'] ?? 0);
        $role = trim((string)($_POST['role'] ?? ''));
        if ($roomId <= 0 || $userId <= 0 || !in_array($role, ['owner', 'moderator', 'none'], true)) {
            json_response(['ok' => false, 'error' => 'درخواست نامعتبر است.'], 400);
        }
        if (!has_column_rooms($pdo, 'room_roles', 'role')) {
            json_response(['ok' => false, 'error' => 'مایگریشن فاز ۳ اجرا نشده است.'], 400);
        }
        if (!can_manage_room_rooms($pdo, $roomId, $uid)) {
            json_response(['ok' => false, 'error' => 'اجازه مدیریت اتاق ندارید.'], 403);
        }
        if ($role === 'none') {
            $stmt = $pdo->prepare('DELETE FROM room_roles WHERE room_id = ? AND user_id = ?');
            $stmt->execute([$roomId, $userId]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO room_roles (room_id, user_id, role, assigned_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE role = VALUES(role), assigned_by = VALUES(assigned_by), assigned_at = CURRENT_TIMESTAMP');
            $stmt->execute([$roomId, $userId, $role, $uid]);
        }
        json_response(['ok' => true]);
    }

    if ($action === 'ban_user' || $action === 'unban_user') {
        $roomId = (int)($_POST['room_id'] ?? 0);
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($roomId <= 0 || $userId <= 0) {
            json_response(['ok' => false, 'error' => 'درخواست نامعتبر است.'], 400);
        }
        if (!has_column_rooms($pdo, 'room_bans', 'user_id')) {
            json_response(['ok' => false, 'error' => 'مایگریشن فاز ۳ اجرا نشده است.'], 400);
        }
        if (!can_manage_room_rooms($pdo, $roomId, $uid)) {
            json_response(['ok' => false, 'error' => 'اجازه مدیریت اتاق ندارید.'], 403);
        }
        if ($action === 'ban_user') {
            $reason = trim((string)($_POST['reason'] ?? ''));
            $stmt = $pdo->prepare('INSERT INTO room_bans (room_id, user_id, banned_by, reason) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE banned_by = VALUES(banned_by), reason = VALUES(reason), banned_at = CURRENT_TIMESTAMP');
            $stmt->execute([$roomId, $userId, $uid, $reason !== '' ? $reason : null]);
            $stmt = $pdo->prepare('DELETE FROM room_members WHERE room_id = ? AND user_id = ?');
            $stmt->execute([$roomId, $userId]);
        } else {
            $stmt = $pdo->prepare('DELETE FROM room_bans WHERE room_id = ? AND user_id = ?');
            $stmt->execute([$roomId, $userId]);
        }
        json_response(['ok' => true]);
    }

    if ($action === 'mute_user') {
        $roomId = (int)($_POST['room_id'] ?? 0);
        $userId = (int)($_POST['user_id'] ?? 0);
        $minutes = max(0, min(1440, (int)($_POST['minutes'] ?? 0)));
        if ($roomId <= 0 || $userId <= 0) {
            json_response(['ok' => false, 'error' => 'درخواست نامعتبر است.'], 400);
        }
        if (!has_column_rooms($pdo, 'room_members', 'muted_until')) {
            json_response(['ok' => false, 'error' => 'مایگریشن فاز ۳ اجرا نشده است.'], 400);
        }
        if (!can_manage_room_rooms($pdo, $roomId, $uid)) {
            json_response(['ok' => false, 'error' => 'اجازه مدیریت اتاق ندارید.'], 403);
        }
        $stmt = $pdo->prepare('UPDATE room_members SET muted_until = CASE WHEN ? <= 0 THEN NULL ELSE DATE_ADD(NOW(), INTERVAL ? MINUTE) END WHERE room_id = ? AND user_id = ?');
        $stmt->execute([$minutes, $minutes, $roomId, $userId]);
        json_response(['ok' => true]);
    }

    $hasInviteToken = has_column_rooms($pdo, 'rooms', 'invite_token');

    if ($hasInviteToken && $action === 'generate_invite') {
        $roomId = (int)($_POST['room_id'] ?? 0);
        if ($roomId <= 0) {
            json_response(['ok' => false, 'error' => 'شناسه اتاق نامعتبر است.'], 400);
        }
        $isRoomAdmin = function_exists('current_user_is_admin') && current_user_is_admin();
        if (!can_manage_room_rooms($pdo, $roomId, $uid) && !$isRoomAdmin) {
            json_response(['ok' => false, 'error' => 'اجازه مدیریت اتاق ندارید.'], 403);
        }
        $token = bin2hex(random_bytes(24));
        $stmt = $pdo->prepare('UPDATE rooms SET invite_token = ? WHERE id = ?');
        $stmt->execute([$token, $roomId]);
        json_response(['ok' => true, 'invite_token' => $token]);
    }

    if ($hasInviteToken && $action === 'join_by_invite') {
        $inviteToken = trim((string)($_POST['invite_token'] ?? ''));
        if ($inviteToken === '') {
            json_response(['ok' => false, 'error' => 'لینک دعوت نامعتبر است.'], 400);
        }
        $stmt = $pdo->prepare('SELECT id, name, vip_only FROM rooms WHERE invite_token = ?');
        $stmt->execute([$inviteToken]);
        $room = $stmt->fetch();
        if (!$room) {
            json_response(['ok' => false, 'error' => 'لینک دعوت منقضی یا نامعتبر است.'], 404);
        }
        $roomId = (int)$room['id'];
        if (!empty($room['vip_only']) && !current_user_is_vip()) {
            json_response(['ok' => false, 'error' => 'این اتاق فقط برای VIP است.'], 403);
        }
        if (has_column_rooms($pdo, 'room_bans', 'user_id')) {
            $stmt = $pdo->prepare('SELECT 1 FROM room_bans WHERE room_id = ? AND user_id = ?');
            $stmt->execute([$roomId, $uid]);
            if ($stmt->fetchColumn()) {
                json_response(['ok' => false, 'error' => 'شما از این اتاق بن شده‌اید.'], 403);
            }
        }
        $stmt = $pdo->prepare('INSERT IGNORE INTO room_members (room_id, user_id) VALUES (?, ?)');
        $stmt->execute([$roomId, $uid]);
        $stmt = $pdo->prepare('SELECT MAX(id) FROM messages WHERE room_id = ? AND deleted_at IS NULL');
        $stmt->execute([$roomId]);
        $maxId = (int)($stmt->fetchColumn() ?: 0);
        $stmt = $pdo->prepare('UPDATE room_members SET last_read_id = GREATEST(COALESCE(last_read_id, 0), ?) WHERE room_id = ? AND user_id = ?');
        $stmt->execute([$maxId, $roomId, $uid]);
        json_response(['ok' => true, 'room_id' => $roomId, 'room_name' => $room['name']]);
    }

    // ── ترک گروه ──
    if ($action === 'leave') {
        $roomId = (int)($_POST['room_id'] ?? 0);
        if ($roomId <= 0) json_response(['ok' => false, 'error' => 'اتاق نامعتبر.'], 400);
        // سازنده نمیتونه ترک کنه
        $stmt = $pdo->prepare('SELECT created_by FROM rooms WHERE id=?');
        $stmt->execute([$roomId]);
        $row = $stmt->fetch();
        if ($row && (int)$row['created_by'] === $uid) {
            json_response(['ok' => false, 'error' => 'سازنده اتاق نمیتواند ترک کند. ابتدا اتاق را حذف کنید.'], 400);
        }
        $pdo->prepare('DELETE FROM room_members WHERE room_id=? AND user_id=?')->execute([$roomId, $uid]);
        json_response(['ok' => true]);
    }

    // ── اخراج عضو از گروه (ادمین) ──
    if ($action === 'kick_member') {
        $roomId    = (int)($_POST['room_id'] ?? 0);
        $targetUid = (int)($_POST['target_user_id'] ?? 0);
        if ($roomId <= 0 || $targetUid <= 0) json_response(['ok' => false, 'error' => 'ورودی نامعتبر.'], 400);
        if (!can_manage_room_rooms($pdo, $roomId, $uid)) json_response(['ok' => false, 'error' => 'دسترسی ندارید.'], 403);
        $pdo->prepare('DELETE FROM room_members WHERE room_id=? AND user_id=?')->execute([$roomId, $targetUid]);
        json_response(['ok' => true]);
    }

    // ── حذف همه پیام‌های گروه (ادمین) ──
    if ($action === 'clear_messages') {
        $roomId = (int)($_POST['room_id'] ?? 0);
        if ($roomId <= 0) json_response(['ok' => false, 'error' => 'اتاق نامعتبر.'], 400);
        if (!can_manage_room_rooms($pdo, $roomId, $uid)) json_response(['ok' => false, 'error' => 'دسترسی ندارید.'], 403);
        try {
            $pdo->prepare('UPDATE messages SET deleted_at=NOW(), deleted_by=? WHERE room_id=? AND deleted_at IS NULL')
                ->execute([$uid, $roomId]);
        } catch (Throwable $e) {
            $pdo->prepare('UPDATE messages SET deleted_at=NOW() WHERE room_id=? AND deleted_at IS NULL')->execute([$roomId]);
        }
        json_response(['ok' => true]);
    }

    // ── حذف پیام‌های یک کاربر در گروه (ادمین) ──
    if ($action === 'delete_user_messages') {
        $roomId    = (int)($_POST['room_id'] ?? 0);
        $targetUid = (int)($_POST['target_user_id'] ?? 0);
        if ($roomId <= 0 || $targetUid <= 0) json_response(['ok' => false, 'error' => 'ورودی نامعتبر.'], 400);
        if (!can_manage_room_rooms($pdo, $roomId, $uid)) json_response(['ok' => false, 'error' => 'دسترسی ندارید.'], 403);
        try {
            $pdo->prepare('UPDATE messages SET deleted_at=NOW(), deleted_by=? WHERE room_id=? AND sender_id=? AND deleted_at IS NULL')
                ->execute([$uid, $roomId, $targetUid]);
        } catch (Throwable $e) {
            $pdo->prepare('UPDATE messages SET deleted_at=NOW() WHERE room_id=? AND sender_id=? AND deleted_at IS NULL')->execute([$roomId, $targetUid]);
        }
        json_response(['ok' => true]);
    }

    json_response(['ok' => false, 'error' => 'درخواست نامعتبر است.'], 400);
}

json_response(['ok' => false, 'error' => 'متد مجاز نیست.'], 405);
