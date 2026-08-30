<?php
require_once __DIR__ . '/../config.php';
require_login();

$pdo = db();
$uid = current_user_id();
$method = $_SERVER['REQUEST_METHOD'];
ensure_private_chat_permissions_table($pdo);

function is_room_member(PDO $pdo, int $roomId, int $userId): bool {
    $stmt = $pdo->prepare('SELECT 1 FROM room_members WHERE room_id = ? AND user_id = ?');
    $stmt->execute([$roomId, $userId]);
    return (bool)$stmt->fetchColumn();
}

function has_column(PDO $pdo, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT 1
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1'
        );
        $stmt->execute([$table, $column]);
        $cache[$key] = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

function can_manage_room(PDO $pdo, int $roomId, int $userId): bool {
    $stmt = $pdo->prepare('SELECT created_by FROM rooms WHERE id = ?');
    $stmt->execute([$roomId]);
    $createdBy = (int)($stmt->fetchColumn() ?: 0);
    if ($createdBy === $userId) {
        return true;
    }
    $hasRoomRoles = has_column($pdo, 'room_roles', 'role');
    if ($hasRoomRoles) {
        $stmt = $pdo->prepare("SELECT role FROM room_roles WHERE room_id = ? AND user_id = ? AND role IN ('owner','moderator') LIMIT 1");
        $stmt->execute([$roomId, $userId]);
        if ($stmt->fetchColumn()) {
            return true;
        }
    }
    return function_exists('current_user_is_admin') && current_user_is_admin();
}

function require_private_permission_or_fail(PDO $pdo, int $userId, int $otherId): void {
    if ($otherId <= 0) {
        json_response(['ok' => false, 'error' => 'کاربر نامعتبر است.'], 400);
    }
    // Saved Messages — پیام به خودت همیشه مجاز
    if ($otherId === $userId) return;
    // اگه مخاطب ادمینه، نیاز به درخواست نیست
    try {
        $stmt = $pdo->prepare('SELECT is_admin FROM users WHERE id = ?');
        $stmt->execute([$otherId]);
        $recipientIsAdmin = (bool)$stmt->fetchColumn();
        if ($recipientIsAdmin) return;
    } catch (Throwable $e) {}
    if (!private_chat_is_allowed($pdo, $userId, $otherId)) {
        json_response(['ok' => false, 'error' => 'برای پیام خصوصی باید درخواست شما توسط مخاطب تایید شود.'], 403);
    }
}

function is_allowed_upload_extension_vip(string $ext, bool $isVip): bool {
    $allowed = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp',
        'mp3', 'm4a', 'ogg', 'wav', 'webm',
        'pdf', 'txt',
        'zip', 'rar', '7z'
    ];
    if ($isVip) {
        $allowed = array_merge($allowed, [
            'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'mp4'
        ]);
    }
    return in_array($ext, $allowed, true);
}

function is_allowed_upload_mime_vip(string $ext, string $mime, bool $isVip): bool {
    $map = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'bmp' => ['image/bmp', 'image/x-ms-bmp'],
        'mp3' => ['audio/mpeg', 'audio/mp3'],
        'm4a' => ['audio/mp4', 'audio/x-m4a', 'video/mp4'],
        'ogg' => ['audio/ogg', 'audio/oga'],
        'wav' => ['audio/wav', 'audio/x-wav'],
        'webm' => ['audio/webm', 'video/webm'],
        'pdf' => ['application/pdf'],
        'txt' => ['text/plain'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
        'rar' => ['application/x-rar', 'application/vnd.rar'],
        '7z' => ['application/x-7z-compressed'],
    ];
    if ($isVip) {
        $map += [
            'doc' => ['application/msword', 'application/vnd.ms-word'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'xls' => ['application/vnd.ms-excel'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'ppt' => ['application/vnd.ms-powerpoint'],
            'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
            'csv' => ['text/csv', 'application/vnd.ms-excel'],
            'mp4' => ['video/mp4']
        ];
    }
    if (!isset($map[$ext])) {
        return false;
    }
    return in_array($mime, $map[$ext], true);
}


function is_allowed_upload_extension(string $ext): bool {
    $allowed = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp',
        'mp3', 'm4a', 'ogg', 'wav', 'webm',
        'pdf', 'txt',
        'zip', 'rar', '7z'
    ];
    return in_array($ext, $allowed, true);
}

function is_allowed_upload_mime(string $ext, string $mime): bool {
    $map = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'bmp' => ['image/bmp', 'image/x-ms-bmp'],
        'mp3' => ['audio/mpeg', 'audio/mp3'],
        'm4a' => ['audio/mp4', 'audio/x-m4a', 'video/mp4'],
        'ogg' => ['audio/ogg', 'audio/oga'],
        'wav' => ['audio/wav', 'audio/x-wav'],
        'pdf' => ['application/pdf'],
        'txt' => ['text/plain'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
        'rar' => ['application/x-rar', 'application/vnd.rar'],
        '7z' => ['application/x-7z-compressed'],
    ];
    if (!isset($map[$ext])) {
        return false;
    }
    if ($mime === 'application/octet-stream') {
        $audioFallback = ['mp3', 'm4a', 'ogg', 'wav', 'webm'];
        if (in_array($ext, $audioFallback, true)) {
            return true;
        }
    }
    return in_array($mime, $map[$ext], true);
}

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
    $mode = $_GET['mode'] ?? '';
    if ($action === 'search') {
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q === '') {
            json_response(['ok' => true, 'results' => []]);
        }
        $queryLen = function_exists('mb_strlen') ? mb_strlen($q) : strlen($q);
        if ($queryLen < 2) {
            json_response(['ok' => false, 'error' => 'عبارت جستجو باید حداقل ۲ کاراکتر باشد.'], 400);
        }
        if ($queryLen > 80) {
            json_response(['ok' => false, 'error' => 'عبارت جستجو خیلی طولانی است.'], 400);
        }
        $term = '%' . $q . '%';
        if ($mode === 'group') {
            $roomId = (int)($_GET['room_id'] ?? 0);
            if ($roomId <= 0) {
                json_response(['ok' => false, 'error' => 'اتاق نامعتبر است.'], 400);
            }
            if (!is_room_member($pdo, $roomId, $uid)) {
                json_response(['ok' => false, 'error' => 'عضو اتاق نیستید.'], 403);
            }
            $stmt = $pdo->prepare(
                'SELECT m.id, m.sender_id, m.body, m.file_name, m.created_at, u.username
                 FROM messages m
                 JOIN users u ON u.id = m.sender_id
                 WHERE m.room_id = ? AND m.deleted_at IS NULL
                   AND (m.body LIKE ? OR m.file_name LIKE ?)
                 ORDER BY m.id DESC
                 LIMIT 40'
            );
            $stmt->execute([$roomId, $term, $term]);
        } elseif ($mode === 'private') {
            $otherId = (int)($_GET['user_id'] ?? 0);
            if ($otherId <= 0) {
                json_response(['ok' => false, 'error' => 'کاربر نامعتبر است.'], 400);
            }
            require_private_permission_or_fail($pdo, $uid, $otherId);
            $stmt = $pdo->prepare(
                'SELECT m.id, m.sender_id, m.body, m.file_name, m.created_at, u.username
                 FROM messages m
                 JOIN users u ON u.id = m.sender_id
                 WHERE m.deleted_at IS NULL
                   AND ((m.sender_id = ? AND m.recipient_id = ?) OR (m.sender_id = ? AND m.recipient_id = ?))
                   AND (m.body LIKE ? OR m.file_name LIKE ?)
                 ORDER BY m.id DESC
                 LIMIT 40'
            );
            $stmt->execute([$uid, $otherId, $otherId, $uid, $term, $term]);
        } else {
            json_response(['ok' => false, 'error' => 'حالت نامعتبر است.'], 400);
        }
        $results = [];
        foreach ($stmt->fetchAll() as $row) {
            $bodyRaw = $row['body'] ?? '';
            $bodyOut = ($mode === 'private') ? msg_decrypt($bodyRaw) : $bodyRaw;
            $results[] = [
                'id' => (int)$row['id'],
                'sender' => $row['username'],
                'body' => $bodyOut,
                'file_name' => $row['file_name'] ?? '',
                'created_at' => $row['created_at'],
                'is_me' => ((int)$row['sender_id'] === $uid),
            ];
        }
        json_response(['ok' => true, 'results' => $results]);
    }

    $sinceId = max(0, (int)($_GET['since_id'] ?? 0));
    $beforeId = max(0, (int)($_GET['before_id'] ?? 0));
    $initial = (int)($_GET['initial'] ?? 0) === 1;
    $readSinceId = max(0, (int)($_GET['read_since_id'] ?? 0));
    $deletedSince = max(0, (int)($_GET['deleted_since'] ?? 0));
    $editedSince = trim((string)($_GET['edited_since'] ?? ''));
    if ($editedSince !== '' && !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $editedSince)) {
        $editedSince = '';
    }
    $readUpdates = [];
    $messagesRows = [];
    $editedRows = [];
    $seenBy = [];
    $deletedIds = [];
    $pinned = null;
    $deletedLast = 0;
    $editedLast = '';
    $serverNow = time();
    $serverNowText = date('Y-m-d H:i:s');
    try {
        $serverNowText = (string)($pdo->query("SELECT DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s')")->fetchColumn() ?: $serverNowText);
    } catch (Throwable $e) {
    }
    $hasOlder = null;

    if ($mode === 'group') {
        $roomId = (int)($_GET['room_id'] ?? 0);
        if ($roomId <= 0) {
            json_response(['ok' => false, 'error' => 'اتاق نامعتبر است.'], 400);
        }
        if (!is_room_member($pdo, $roomId, $uid)) {
            json_response(['ok' => false, 'error' => 'عضو اتاق نیستید.'], 403);
        }
        $stmt = $pdo->prepare('SELECT vip_only FROM rooms WHERE id = ?');
        $stmt->execute([$roomId]);
        $roomVipOnly = $stmt->fetchColumn();
        if (!empty($roomVipOnly) && !current_user_is_vip()) {
            json_response(['ok' => false, 'error' => 'این اتاق فقط برای VIP است.'], 403);
        }

        if ($beforeId > 0) {
            $stmt = $pdo->prepare(
            'SELECT m.id, m.sender_id, m.body, m.file_token, m.file_name, m.file_size, m.file_type, m.read_at, m.created_at, m.edited_at, m.forwarded_from_id,
                    m.reply_to_id, u.username, u.is_vip AS sender_is_vip,
                    m2.body AS reply_body, m2.file_name AS reply_file_name, u2.username AS reply_sender
             FROM messages m
             JOIN users u ON u.id = m.sender_id
                 LEFT JOIN messages m2 ON m2.id = m.reply_to_id AND m2.deleted_at IS NULL
                 LEFT JOIN users u2 ON u2.id = m2.sender_id
                 WHERE m.room_id = ? AND m.id < ? AND m.deleted_at IS NULL
                 ORDER BY m.id DESC
                 LIMIT 21'
            );
            $stmt->execute([$roomId, $beforeId]);
            $messagesRows = $stmt->fetchAll();
            if (count($messagesRows) > 20) {
                $hasOlder = true;
                $messagesRows = array_slice($messagesRows, 0, 20);
            } else {
                $hasOlder = false;
            }
        } elseif ($initial && $sinceId === 0) {
            $stmt = $pdo->prepare(
            'SELECT m.id, m.sender_id, m.body, m.file_token, m.file_name, m.file_size, m.file_type, m.read_at, m.created_at, m.edited_at, m.forwarded_from_id,
                    m.reply_to_id, u.username, u.is_vip AS sender_is_vip,
                    m2.body AS reply_body, m2.file_name AS reply_file_name, u2.username AS reply_sender
             FROM messages m
             JOIN users u ON u.id = m.sender_id
                 LEFT JOIN messages m2 ON m2.id = m.reply_to_id AND m2.deleted_at IS NULL
                 LEFT JOIN users u2 ON u2.id = m2.sender_id
                 WHERE m.room_id = ? AND m.deleted_at IS NULL
                 ORDER BY m.id DESC
                 LIMIT 20'
            );
            $stmt->execute([$roomId]);
            $messagesRows = $stmt->fetchAll();
        } else {
            $stmt = $pdo->prepare(
            'SELECT m.id, m.sender_id, m.body, m.file_token, m.file_name, m.file_size, m.file_type, m.read_at, m.created_at, m.edited_at, m.forwarded_from_id,
                    m.reply_to_id, u.username, u.is_vip AS sender_is_vip,
                    m2.body AS reply_body, m2.file_name AS reply_file_name, u2.username AS reply_sender
             FROM messages m
             JOIN users u ON u.id = m.sender_id
                 LEFT JOIN messages m2 ON m2.id = m.reply_to_id AND m2.deleted_at IS NULL
                 LEFT JOIN users u2 ON u2.id = m2.sender_id
                 WHERE m.room_id = ? AND m.id > ? AND m.deleted_at IS NULL
             ORDER BY m.id ASC
             LIMIT 20'
            );
            $stmt->execute([$roomId, $sinceId]);
            $messagesRows = $stmt->fetchAll();
        }

        $stmt = $pdo->prepare(
            'SELECT id, UNIX_TIMESTAMP(deleted_at) AS deleted_ts
             FROM messages
             WHERE room_id = ? AND deleted_at IS NOT NULL AND UNIX_TIMESTAMP(deleted_at) > ?
             ORDER BY deleted_at ASC
             LIMIT 20'
        );
        $stmt->execute([$roomId, $deletedSince]);
        foreach ($stmt->fetchAll() as $row) {
            $deletedIds[] = (int)$row['id'];
            $deletedLast = max($deletedLast, (int)$row['deleted_ts']);
        }

        $stmt = $pdo->prepare('SELECT MAX(id) FROM messages WHERE room_id = ? AND deleted_at IS NULL');
        $stmt->execute([$roomId]);
        $maxId = (int)($stmt->fetchColumn() ?: 0);
        $stmt = $pdo->prepare(
            'UPDATE room_members SET last_read_id = GREATEST(COALESCE(last_read_id, 0), ?)
             WHERE room_id = ? AND user_id = ?'
        );
        $stmt->execute([$maxId, $roomId, $uid]);

        $stmt = $pdo->prepare(
            'SELECT m.id, m.body, m.file_name, m.created_at, u.username
             FROM messages m
             JOIN users u ON u.id = m.sender_id
             WHERE m.room_id = ? AND m.pinned_at IS NOT NULL AND m.deleted_at IS NULL
             ORDER BY m.pinned_at DESC
             LIMIT 1'
        );
        $stmt->execute([$roomId]);
        $pinnedRow = $stmt->fetch();
        if ($pinnedRow) {
            $pinned = [
                'id' => (int)$pinnedRow['id'],
                'sender' => $pinnedRow['username'],
                'body' => $pinnedRow['body'] ?? '',
                'file_name' => $pinnedRow['file_name'] ?? '',
                'created_at' => $pinnedRow['created_at'],
            ];
        }

        if ($editedSince !== '') {
            $stmt = $pdo->prepare(
                'SELECT id, body, edited_at
                 FROM messages
                 WHERE room_id = ? AND deleted_at IS NULL AND edited_at IS NOT NULL AND edited_at >= ?
                 ORDER BY edited_at ASC, id ASC
                 LIMIT 50'
            );
            $stmt->execute([$roomId, $editedSince]);
            $editedRows = $stmt->fetchAll();
        }
    } elseif ($mode === 'private') {
        $otherId = (int)($_GET['user_id'] ?? 0);
        if ($otherId <= 0) {
            json_response(['ok' => false, 'error' => 'کاربر نامعتبر است.'], 400);
        }

        require_private_permission_or_fail($pdo, $uid, $otherId);
        $stmt = $pdo->prepare(
            'UPDATE messages SET read_at = NOW()
             WHERE sender_id = ? AND recipient_id = ? AND read_at IS NULL AND deleted_at IS NULL'
        );
        $stmt->execute([$otherId, $uid]);

        if ($beforeId > 0) {
            $stmt = $pdo->prepare(
            'SELECT m.id, m.sender_id, m.body, m.file_token, m.file_name, m.file_size, m.file_type, m.read_at, m.created_at, m.edited_at, m.forwarded_from_id,
                    m.reply_to_id, u.username, u.is_vip AS sender_is_vip,
                    m2.body AS reply_body, m2.file_name AS reply_file_name, u2.username AS reply_sender
             FROM messages m
             JOIN users u ON u.id = m.sender_id
                 LEFT JOIN messages m2 ON m2.id = m.reply_to_id AND m2.deleted_at IS NULL
                 LEFT JOIN users u2 ON u2.id = m2.sender_id
                 WHERE ((m.sender_id = ? AND m.recipient_id = ?) OR (m.sender_id = ? AND m.recipient_id = ?))
                   AND m.id < ? AND m.deleted_at IS NULL
                 ORDER BY m.id DESC
                 LIMIT 21'
            );
            $stmt->execute([$uid, $otherId, $otherId, $uid, $beforeId]);
            $messagesRows = $stmt->fetchAll();
            if (count($messagesRows) > 20) {
                $hasOlder = true;
                $messagesRows = array_slice($messagesRows, 0, 20);
            } else {
                $hasOlder = false;
            }
        } elseif ($initial && $sinceId === 0) {
            $stmt = $pdo->prepare(
            'SELECT m.id, m.sender_id, m.body, m.file_token, m.file_name, m.file_size, m.file_type, m.read_at, m.created_at, m.edited_at, m.forwarded_from_id,
                    m.reply_to_id, u.username, u.is_vip AS sender_is_vip,
                    m2.body AS reply_body, m2.file_name AS reply_file_name, u2.username AS reply_sender
             FROM messages m
             JOIN users u ON u.id = m.sender_id
                 LEFT JOIN messages m2 ON m2.id = m.reply_to_id AND m2.deleted_at IS NULL
                 LEFT JOIN users u2 ON u2.id = m2.sender_id
                 WHERE ((m.sender_id = ? AND m.recipient_id = ?) OR (m.sender_id = ? AND m.recipient_id = ?))
                   AND m.deleted_at IS NULL
                 ORDER BY m.id DESC
                 LIMIT 20'
            );
            $stmt->execute([$uid, $otherId, $otherId, $uid]);
            $messagesRows = $stmt->fetchAll();
        } else {
            $stmt = $pdo->prepare(
            'SELECT m.id, m.sender_id, m.body, m.file_token, m.file_name, m.file_size, m.file_type, m.read_at, m.created_at, m.edited_at, m.forwarded_from_id,
                    m.reply_to_id, u.username, u.is_vip AS sender_is_vip,
                    m2.body AS reply_body, m2.file_name AS reply_file_name, u2.username AS reply_sender
             FROM messages m
             JOIN users u ON u.id = m.sender_id
                 LEFT JOIN messages m2 ON m2.id = m.reply_to_id AND m2.deleted_at IS NULL
                 LEFT JOIN users u2 ON u2.id = m2.sender_id
                 WHERE ((m.sender_id = ? AND m.recipient_id = ?) OR (m.sender_id = ? AND m.recipient_id = ?))
                   AND m.id > ? AND m.deleted_at IS NULL
             ORDER BY m.id ASC
             LIMIT 20'
            );
            $stmt->execute([$uid, $otherId, $otherId, $uid, $sinceId]);
            $messagesRows = $stmt->fetchAll();
        }

        $stmt = $pdo->prepare(
            'SELECT id, UNIX_TIMESTAMP(deleted_at) AS deleted_ts
             FROM messages
             WHERE deleted_at IS NOT NULL
               AND ((sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?))
               AND UNIX_TIMESTAMP(deleted_at) > ?
             ORDER BY deleted_at ASC
             LIMIT 20'
        );
        $stmt->execute([$uid, $otherId, $otherId, $uid, $deletedSince]);
        foreach ($stmt->fetchAll() as $row) {
            $deletedIds[] = (int)$row['id'];
            $deletedLast = max($deletedLast, (int)$row['deleted_ts']);
        }

        $stmt = $pdo->prepare(
            'SELECT id FROM messages
             WHERE sender_id = ? AND recipient_id = ? AND read_at IS NOT NULL AND id > ? AND deleted_at IS NULL
             ORDER BY id ASC'
        );
        $stmt->execute([$uid, $otherId, $readSinceId]);
        $readUpdates = array_map('intval', array_column($stmt->fetchAll(), 'id'));

        if ($editedSince !== '') {
            $stmt = $pdo->prepare(
                'SELECT id, body, edited_at
                 FROM messages
                 WHERE deleted_at IS NULL AND edited_at IS NOT NULL AND edited_at >= ?
                   AND ((sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?))
                 ORDER BY edited_at ASC, id ASC
                 LIMIT 50'
            );
            $stmt->execute([$editedSince, $uid, $otherId, $otherId, $uid]);
            $editedRows = $stmt->fetchAll();
        }
    } else {
        json_response(['ok' => false, 'error' => 'حالت نامعتبر است.'], 400);
    }

    $messages = [];
    if (($initial && $sinceId === 0) || $beforeId > 0) {
        $messagesRows = array_reverse($messagesRows);
    }

    $senderIds = [];
    foreach ($messagesRows as $row) {
        $senderIds[(int)$row['sender_id']] = true;
    }
    $senderMeta = [];
    $hasAvatarToken = has_column($pdo, 'users', 'avatar_token');
    if ($senderIds) {
        $ids = array_keys($senderIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sel = "SELECT id, is_admin, is_vip, vip_label";
        if ($hasAvatarToken) {
            $sel .= ", avatar_token";
        }
        $stmt = $pdo->prepare("$sel FROM users WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $metaRow) {
            $sid = (int)$metaRow['id'];
            $senderMeta[$sid] = [
                'is_admin' => !empty($metaRow['is_admin']),
                'is_vip' => !empty($metaRow['is_vip']),
                'vip_label' => $metaRow['vip_label'] ?? '',
            ];
            if ($hasAvatarToken && !empty($metaRow['avatar_token'])) {
                $senderMeta[$sid]['avatar_url'] = 'avatar.php?u=' . $sid . '&t=' . rawurlencode($metaRow['avatar_token']);
            } else {
                $senderMeta[$sid]['avatar_url'] = null;
            }
        }
    }

    foreach ($messagesRows as $row) {
        $sid = (int)$row['sender_id'];
        $meta = $senderMeta[$sid] ?? [];
        // رمزگشایی پیام‌های خصوصی
        $bodyRaw = $row['body'] ?? '';
        $bodyDecoded = ($mode === 'private') ? msg_decrypt($bodyRaw) : $bodyRaw;
        $messages[] = [
            'id' => (int)$row['id'],
            'sender_id' => $sid,
            'sender' => $row['username'],
            'sender_is_vip' => !empty($row['sender_is_vip']),
            'body' => $bodyDecoded,
            'file_name' => $row['file_name'],
            'file_size' => (int)($row['file_size'] ?? 0),
            'file_type' => $row['file_type'],
            'has_file' => !empty($row['file_token']),
            'read_at' => $row['read_at'],
            'reply_to_id' => $row['reply_to_id'] ? (int)$row['reply_to_id'] : null,
            'reply_sender' => $row['reply_sender'],
            'reply_body' => ($mode === 'private') ? msg_decrypt($row['reply_body'] ?? '') : ($row['reply_body'] ?? ''),
            'reply_file_name' => $row['reply_file_name'],
            'created_at' => $row['created_at'],
            'edited_at' => $row['edited_at'] ?? null,
            'forwarded_from_id' => !empty($row['forwarded_from_id']) ? (int)$row['forwarded_from_id'] : null,
            'is_me' => ($sid === $uid),
            'sender_is_admin' => !empty($meta['is_admin']),
            'sender_is_vip' => !empty($meta['is_vip']),
            'sender_vip_label' => (string)($meta['vip_label'] ?? ''),
            'sender_avatar_url' => $meta['avatar_url'] ?? null,
        ];
    }

    $viewerVip = current_user_is_vip();
    if ($mode === 'group' && $messages && $viewerVip) {
        $ids = array_map('intval', array_column($messages, 'id'));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $seenStmt = $pdo->prepare(
            "SELECT m.id,
                    COUNT(rm.user_id) AS seen_count,
                    SUBSTRING_INDEX(GROUP_CONCAT(u.username ORDER BY u.username SEPARATOR ', '), ', ', 3) AS seen_preview
             FROM messages m
             JOIN room_members rm ON rm.room_id = m.room_id
               AND rm.last_read_id >= m.id
               AND rm.user_id <> m.sender_id
             JOIN users u ON u.id = rm.user_id
             WHERE m.id IN ($placeholders) AND m.deleted_at IS NULL
             GROUP BY m.id"
        );
        $seenStmt->execute($ids);
        $seenMap = [];
        foreach ($seenStmt->fetchAll() as $seenRow) {
            $seenMap[(int)$seenRow['id']] = [
                'count' => (int)$seenRow['seen_count'],
                'preview' => $seenRow['seen_preview'] ?? ''
            ];
        }
        foreach ($messages as &$msg) {
            $seen = $seenMap[(int)$msg['id']] ?? ['count' => 0, 'preview' => ''];
            $msg['seen_count'] = $seen['count'];
            $msg['seen_preview'] = $seen['preview'];
        }
        unset($msg);
    }

    if ($messages) {
        $hasReactionsTable = false;
        try {
            $pdo->query('SELECT 1 FROM message_reactions LIMIT 1');
            $hasReactionsTable = true;
        } catch (Throwable $e) {}
        if ($hasReactionsTable) {
            $ids = array_map('intval', array_column($messages, 'id'));
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $rxStmt = $pdo->prepare(
                "SELECT message_id, emoji, COUNT(*) AS c,
                        SUM(CASE WHEN user_id = ? THEN 1 ELSE 0 END) AS me
                 FROM message_reactions
                 WHERE message_id IN ($placeholders)
                 GROUP BY message_id, emoji
                 ORDER BY message_id ASC, c DESC, emoji ASC"
            );
            $params = array_merge([$uid], $ids);
            $rxStmt->execute($params);
            $map = [];
            foreach ($rxStmt->fetchAll() as $r) {
                $mid = (int)$r['message_id'];
                if (!isset($map[$mid])) {
                    $map[$mid] = [];
                }
                $map[$mid][] = [
                    'emoji' => (string)$r['emoji'],
                    'count' => (int)$r['c'],
                    'mine' => ((int)$r['me'] > 0),
                ];
            }
            foreach ($messages as &$m) {
                $m['reactions'] = $map[(int)$m['id']] ?? [];
            }
            unset($m);
        } else {
            foreach ($messages as &$m) {
                $m['reactions'] = [];
            }
            unset($m);
        }
    }

    $editedMessages = [];
    foreach ($editedRows as $row) {
        $editedAt = (string)($row['edited_at'] ?? '');
        if ($editedAt === '') {
            continue;
        }
        if ($editedAt > $editedLast) {
            $editedLast = $editedAt;
        }
        $editedMessages[] = [
            'id' => (int)$row['id'],
            'body' => $row['body'] ?? '',
            'edited_at' => $editedAt,
        ];
    }

    json_response([
        'ok' => true,
        'messages' => $messages,
        'edited_messages' => $editedMessages,
        'edited_last' => $editedLast,
        'has_older' => $hasOlder,
        'read_updates' => $readUpdates,
        'pinned' => $pinned,
        'deleted_ids' => $deletedIds,
        'deleted_last' => $deletedLast,
        'server_now' => $serverNow,
        'server_now_text' => $serverNowText
    ]);
}

if ($method === 'POST') {
    require_csrf_json();
    $isVip = current_user_is_vip();

    $action = $_POST['action'] ?? '';
    if ($action === 'edit') {
        $messageId = (int)($_POST['message_id'] ?? 0);
        $newBody = trim((string)($_POST['body'] ?? ''));
        if ($messageId <= 0) {
            json_response(['ok' => false, 'error' => 'شناسه پیام نامعتبر است.'], 400);
        }
        if ($newBody === '' || strlen($newBody) > MAX_MESSAGE_LEN) {
            json_response(['ok' => false, 'error' => 'متن ویرایش نامعتبر است.'], 400);
        }
        $hasEditHistory = has_column($pdo, 'messages', 'edit_history_json');
        $stmt = $pdo->prepare('SELECT id, sender_id, body, created_at, deleted_at FROM messages WHERE id = ?');
        $stmt->execute([$messageId]);
        $msg = $stmt->fetch();
        if (!$msg || !empty($msg['deleted_at'])) {
            json_response(['ok' => false, 'error' => 'پیام پیدا نشد.'], 404);
        }
        if ((int)$msg['sender_id'] !== $uid) {
            json_response(['ok' => false, 'error' => 'فقط پیام خودتان را می‌توانید ویرایش کنید.'], 403);
        }
        $createdTs = strtotime((string)$msg['created_at']);
        if ($createdTs && (time() - $createdTs) > 300) {
            json_response(['ok' => false, 'error' => 'مهلت ویرایش پیام تمام شده است.'], 403);
        }
        $old = (string)($msg['body'] ?? '');
        $historyEntry = json_encode([
            'at' => date('Y-m-d H:i:s'),
            'old' => $old
        ], JSON_UNESCAPED_UNICODE);
        $attempts = [];
        if ($hasEditHistory && $historyEntry !== false) {
            $attempts[] = [
                'sql' => 'UPDATE messages SET body = ?, edited_at = NOW(), edit_history_json = JSON_ARRAY_APPEND(COALESCE(edit_history_json, JSON_ARRAY()), "$", CAST(? AS JSON)) WHERE id = ?',
                'params' => [$newBody, $historyEntry, $messageId],
            ];
        }
        $attempts[] = [
            'sql' => 'UPDATE messages SET body = ?, edited_at = NOW() WHERE id = ?',
            'params' => [$newBody, $messageId],
        ];
        $attempts[] = [
            'sql' => 'UPDATE messages SET body = ? WHERE id = ?',
            'params' => [$newBody, $messageId],
        ];

        $updated = false;
        foreach ($attempts as $attempt) {
            try {
                $stmt = $pdo->prepare($attempt['sql']);
                $stmt->execute($attempt['params']);
                $updated = true;
                break;
            } catch (Throwable $e) {
                // Older schemas or MariaDB JSON differences should not block plain text edits.
            }
        }
        if (!$updated) {
            json_response(['ok' => false, 'error' => 'ویرایش پیام انجام نشد.'], 500);
        }
        $editedAt = null;
        if (has_column($pdo, 'messages', 'edited_at')) {
            try {
                $stmt = $pdo->prepare('SELECT edited_at FROM messages WHERE id = ?');
                $stmt->execute([$messageId]);
                $value = $stmt->fetchColumn();
                $editedAt = $value ? (string)$value : null;
            } catch (Throwable $e) {
                $editedAt = null;
            }
        }
        json_response(['ok' => true, 'edited_at' => $editedAt]);
    }

    if ($action === 'react' || $action === 'unreact') {
        $messageId = (int)($_POST['message_id'] ?? 0);
        $emoji = trim((string)($_POST['emoji'] ?? ''));
        if ($messageId <= 0 || $emoji === '') {
            json_response(['ok' => false, 'error' => 'داده واکنش نامعتبر است.'], 400);
        }
        try {
            $pdo->query('SELECT 1 FROM message_reactions LIMIT 1');
        } catch (Throwable $e) {
            json_response(['ok' => false, 'error' => 'مایگریشن فاز ۳ اجرا نشده است.'], 400);
        }
        $stmt = $pdo->prepare('SELECT id, room_id, sender_id, recipient_id, deleted_at FROM messages WHERE id = ?');
        $stmt->execute([$messageId]);
        $msg = $stmt->fetch();
        if (!$msg || !empty($msg['deleted_at'])) {
            json_response(['ok' => false, 'error' => 'پیام پیدا نشد.'], 404);
        }
        if (!empty($msg['room_id'])) {
            if (!is_room_member($pdo, (int)$msg['room_id'], $uid)) {
                json_response(['ok' => false, 'error' => 'عضو اتاق نیستید.'], 403);
            }
        } else {
            $a = (int)$msg['sender_id'];
            $b = (int)$msg['recipient_id'];
            if (!($uid === $a || $uid === $b)) {
                json_response(['ok' => false, 'error' => 'اجازه دسترسی ندارید.'], 403);
            }
        }
        if ($action === 'react') {
            $stmt = $pdo->prepare('INSERT IGNORE INTO message_reactions (message_id, user_id, emoji) VALUES (?, ?, ?)');
            $stmt->execute([$messageId, $uid, $emoji]);
        } else {
            $stmt = $pdo->prepare('DELETE FROM message_reactions WHERE message_id = ? AND user_id = ? AND emoji = ?');
            $stmt->execute([$messageId, $uid, $emoji]);
        }
        json_response(['ok' => true]);
    }

    if ($action === 'forward') {
        $sourceId = (int)($_POST['message_id'] ?? 0);
        $targetMode = $_POST['target_mode'] ?? '';
        $targetId = (int)($_POST['target_id'] ?? 0);
        if ($sourceId <= 0 || $targetId <= 0 || !in_array($targetMode, ['group', 'private'], true)) {
            json_response(['ok' => false, 'error' => 'درخواست فوروارد نامعتبر است.'], 400);
        }
        $hasForwarded = has_column($pdo, 'messages', 'forwarded_from_id');
        if (!$hasForwarded) {
            json_response(['ok' => false, 'error' => 'مایگریشن فاز ۳ اجرا نشده است.'], 400);
        }
        $stmt = $pdo->prepare('SELECT id, room_id, sender_id, recipient_id, body, file_token, file_name, file_size, file_type, deleted_at FROM messages WHERE id = ?');
        $stmt->execute([$sourceId]);
        $src = $stmt->fetch();
        if (!$src || !empty($src['deleted_at'])) {
            json_response(['ok' => false, 'error' => 'پیام مبدا پیدا نشد.'], 404);
        }
        if (!empty($src['room_id'])) {
            if (!is_room_member($pdo, (int)$src['room_id'], $uid)) {
                json_response(['ok' => false, 'error' => 'اجازه دسترسی به پیام مبدا ندارید.'], 403);
            }
        } else {
            $a = (int)$src['sender_id'];
            $b = (int)$src['recipient_id'];
            if (!($uid === $a || $uid === $b)) {
                json_response(['ok' => false, 'error' => 'اجازه دسترسی به پیام مبدا ندارید.'], 403);
            }
        }
        $roomId = null;
        $recipientId = null;
        if ($targetMode === 'group') {
            $roomId = $targetId;
            if (!is_room_member($pdo, $roomId, $uid)) {
                json_response(['ok' => false, 'error' => 'عضو اتاق مقصد نیستید.'], 403);
            }
        } else {
            $recipientId = $targetId;
            if ($recipientId === $uid) {
                json_response(['ok' => false, 'error' => 'فوروارد به خودتان مجاز نیست.'], 400);
            }
            $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ?');
            $stmt->execute([$recipientId]);
            if (!$stmt->fetchColumn()) {
                json_response(['ok' => false, 'error' => 'کاربر مقصد پیدا نشد.'], 404);
            }
            require_private_permission_or_fail($pdo, $uid, $recipientId);
        }
        // decrypt مبدا اگه PV بود، بعد encrypt مقصد اگه PV هست
        $fwdBodyRaw = $src['body'];
        $fwdBodyPlain = (!empty($src['room_id']) === false && !empty($src['recipient_id']))
            ? msg_decrypt($fwdBodyRaw)
            : $fwdBodyRaw;
        $fwdBodyStore = ($targetMode === 'private')
            ? msg_encrypt($fwdBodyPlain)
            : $fwdBodyPlain;

        $stmt = $pdo->prepare(
            'INSERT INTO messages (sender_id, room_id, recipient_id, body, file_token, file_name, file_size, file_type, forwarded_from_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $uid,
            $roomId,
            $recipientId,
            $fwdBodyStore,
            $src['file_token'],
            $src['file_name'],
            $src['file_size'],
            $src['file_type'],
            $sourceId
        ]);
        json_response(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
    }

    if ($action === 'delete') {
        $messageId = (int)($_POST['message_id'] ?? 0);
        if ($messageId <= 0) {
            json_response(['ok' => false, 'error' => 'شناسه پیام نامعتبر است.'], 400);
        }
        $stmt = $pdo->prepare('SELECT id, sender_id, room_id FROM messages WHERE id = ?');
        $stmt->execute([$messageId]);
        $msg = $stmt->fetch();
        if (!$msg) {
            json_response(['ok' => false, 'error' => 'پیام پیدا نشد.'], 404);
        }
        $isOwner = (int)$msg['sender_id'] === $uid;
        $isAdmin = function_exists('current_user_is_admin') && current_user_is_admin();
        // ادمین می‌تونه هر پیامی رو حذف کنه
        // مدیر اتاق هم می‌تونه پیام‌های اتاقش رو حذف کنه
        $canDelete = $isOwner || $isAdmin;
        if (!$canDelete && !empty($msg['room_id'])) {
            $canDelete = can_manage_room($pdo, (int)$msg['room_id'], $uid);
        }
        if (!$canDelete) {
            json_response(['ok' => false, 'error' => 'شما اجازه حذف این پیام را ندارید.'], 403);
        }
        // deleted_by ممکنه وجود نداشته باشه — safe update
        try {
            $stmt = $pdo->prepare('UPDATE messages SET deleted_at = NOW(), deleted_by = ? WHERE id = ?');
            $stmt->execute([$uid, $messageId]);
        } catch (Throwable $e) {
            // اگه deleted_by نبود، بدون اون update کن
            $pdo->prepare('UPDATE messages SET deleted_at = NOW() WHERE id = ?')->execute([$messageId]);
        }
        json_response(['ok' => true, 'deleted_at' => time()]);
    }

    if ($action === 'pin' || $action === 'unpin') {
        if (!$isVip) {
            json_response(['ok' => false, 'error' => 'این قابلیت فقط برای VIP است.'], 403);
        }
        $messageId = (int)($_POST['message_id'] ?? 0);
        if ($messageId <= 0) {
            json_response(['ok' => false, 'error' => 'شناسه پیام نامعتبر است.'], 400);
        }
        $stmt = $pdo->prepare('SELECT id, room_id FROM messages WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$messageId]);
        $msg = $stmt->fetch();
        if (!$msg || empty($msg['room_id'])) {
            json_response(['ok' => false, 'error' => 'پیام برای پین نامعتبر است.'], 404);
        }
        $roomId = (int)$msg['room_id'];
        if (!is_room_member($pdo, $roomId, $uid)) {
            json_response(['ok' => false, 'error' => 'عضو اتاق نیستید.'], 403);
        }
        if ($action === 'pin') {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('UPDATE messages SET pinned_at = NULL, pinned_by = NULL WHERE room_id = ?');
            $stmt->execute([$roomId]);
            $stmt = $pdo->prepare('UPDATE messages SET pinned_at = NOW(), pinned_by = ? WHERE id = ?');
            $stmt->execute([$uid, $messageId]);
            $pdo->commit();
            json_response(['ok' => true]);
        }
        $stmt = $pdo->prepare('UPDATE messages SET pinned_at = NULL, pinned_by = NULL WHERE id = ?');
        $stmt->execute([$messageId]);
        json_response(['ok' => true]);
    }

    $mode = $_POST['mode'] ?? '';
    $message = trim($_POST['message'] ?? '');
    if (strlen($message) > MAX_MESSAGE_LEN) {
        json_response(['ok' => false, 'error' => 'متن پیام خیلی طولانی است.'], 400);
    }

    $hasFile = isset($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE;

    if ($message === '' && !$hasFile) {
        json_response(['ok' => false, 'error' => 'پیام یا فایل الزامی است.'], 400);
    }

    $roomId = null;
    $recipientId = null;
    $replyToId = (int)($_POST['reply_to_id'] ?? 0);
    if ($mode === 'group') {
        $roomId = (int)($_POST['room_id'] ?? 0);
        if ($roomId <= 0) {
            json_response(['ok' => false, 'error' => 'اتاق نامعتبر است.'], 400);
        }
        if (!is_room_member($pdo, $roomId, $uid)) {
            json_response(['ok' => false, 'error' => 'عضو اتاق نیستید.'], 403);
        }
        if (has_column($pdo, 'rooms', 'slow_mode_seconds')) {
            $stmt = $pdo->prepare('SELECT slow_mode_seconds FROM rooms WHERE id = ?');
            $stmt->execute([$roomId]);
            $slowMode = (int)($stmt->fetchColumn() ?: 0);
            if ($slowMode > 0 && !can_manage_room($pdo, $roomId, $uid)) {
                $stmt = $pdo->prepare('SELECT created_at FROM messages WHERE room_id = ? AND sender_id = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1');
                $stmt->execute([$roomId, $uid]);
                $lastAt = $stmt->fetchColumn();
                if ($lastAt) {
                    $diff = time() - strtotime((string)$lastAt);
                    if ($diff < $slowMode) {
                        json_response(['ok' => false, 'error' => 'حالت Slow Mode فعال است.'], 429);
                    }
                }
            }
        }
    } elseif ($mode === 'private') {
        $recipientId = (int)($_POST['user_id'] ?? 0);
        if ($recipientId <= 0) {
            json_response(['ok' => false, 'error' => 'کاربر نامعتبر است.'], 400);
        }
        // Saved Messages — پیام به خودت مجاز
        if ($recipientId === $uid) {
            // اجازه میدیم — skip permission check
        } else {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ?');
            $stmt->execute([$recipientId]);
            if (!$stmt->fetchColumn()) {
                json_response(['ok' => false, 'error' => 'کاربر پیدا نشد.'], 404);
            }
            require_private_permission_or_fail($pdo, $uid, $recipientId);

            // چک بلاک — اگه recipient بلاک کرده، ارسال مجاز نیست
            try {
                $blkStmt = $pdo->prepare(
                    "SELECT id FROM user_blocks WHERE blocker_id=? AND blocked_id=? LIMIT 1"
                );
                $blkStmt->execute([$recipientId, $uid]);
                if ($blkStmt->fetchColumn()) {
                    json_response(['ok' => false, 'error' => 'block', 'blocked' => true], 403);
                }
                $blkStmt2 = $pdo->prepare(
                    "SELECT id FROM user_blocks WHERE blocker_id=? AND blocked_id=? LIMIT 1"
                );
                $blkStmt2->execute([$uid, $recipientId]);
                if ($blkStmt2->fetchColumn()) {
                    json_response(['ok' => false, 'error' => 'block', 'blocked' => true], 403);
                }
            } catch (Throwable $e) {}
        }
    } else {
        json_response(['ok' => false, 'error' => 'حالت نامعتبر است.'], 400);
    }

    if ($replyToId > 0) {
        $stmt = $pdo->prepare('SELECT id, room_id, sender_id, recipient_id FROM messages WHERE id = ?');
        $stmt->execute([$replyToId]);
        $reply = $stmt->fetch();
        if (!$reply) {
            json_response(['ok' => false, 'error' => 'پیام برای پاسخ پیدا نشد.'], 404);
        }
        if ($mode === 'group') {
            if ((int)$reply['room_id'] !== $roomId) {
                json_response(['ok' => false, 'error' => 'پاسخ به پیام نامعتبر است.'], 400);
            }
        } else {
            if (!empty($reply['room_id'])) {
                json_response(['ok' => false, 'error' => 'پاسخ به پیام نامعتبر است.'], 400);
            }
            $senderId = (int)$reply['sender_id'];
            $rcptId = (int)$reply['recipient_id'];
            $valid = ($senderId === $uid && $rcptId === $recipientId) || ($senderId === $recipientId && $rcptId === $uid);
            if (!$valid) {
                json_response(['ok' => false, 'error' => 'پاسخ به پیام نامعتبر است.'], 400);
            }
        }
    } else {
        $replyToId = null;
    }

    $fileToken = null;
    $fileName = null;
    $fileSize = null;
    $fileType = null;

    if ($hasFile) {
        $file = $_FILES['file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            json_response(['ok' => false, 'error' => 'آپلود ناموفق بود.'], 400);
        }
        $maxSize = $isVip ? MAX_FILE_SIZE_VIP : MAX_FILE_SIZE;
        if ($file['size'] > $maxSize) {
            json_response(['ok' => false, 'error' => 'حجم فایل زیاد است.'], 400);
        }

        $safeName = sanitize_filename($file['name'] ?? 'file');
        if ($safeName === '') {
            $safeName = 'file';
        }

        $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
        if ($ext === '' || !is_allowed_upload_extension_vip($ext, $isVip)) {
            json_response(['ok' => false, 'error' => 'نوع فایل مجاز نیست.'], 400);
        }

        $mime = 'application/octet-stream';
        $hasFinfo = class_exists('finfo');
        if ($hasFinfo) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $detected = $finfo->file($file['tmp_name']);
            if ($detected) {
                $mime = $detected;
            }
        }
        if ($hasFinfo && !is_allowed_upload_mime_vip($ext, $mime, $isVip)) {
            json_response(['ok' => false, 'error' => 'نوع فایل مجاز نیست.'], 400);
        }

        $fileToken = bin2hex(random_bytes(16));
        ensure_upload_dir();
        $dest = UPLOAD_DIR . '/' . $fileToken;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            json_response(['ok' => false, 'error' => 'ذخیره فایل انجام نشد.'], 500);
        }

        $fileName = $safeName;
        $fileSize = (int)$file['size'];
        $fileType = $mime;
    }

    $body = $message !== '' ? $message : null;

    // رمزنگاری پیام‌های خصوصی (PV)
    $bodyToStore = ($mode === 'private' && $body !== null) ? msg_encrypt($body) : $body;

    $stmt = $pdo->prepare(
        'INSERT INTO messages (sender_id, room_id, recipient_id, reply_to_id, body, file_token, file_name, file_size, file_type)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $uid,
        $roomId,
        $recipientId,
        $replyToId,
        $bodyToStore,
        $fileToken,
        $fileName,
        $fileSize,
        $fileType
    ]);

    json_response(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
}

json_response(['ok' => false, 'error' => 'متد مجاز نیست.'], 405);

