<?php
require_once __DIR__ . '/../config.php';
require_login();

$pdo = db();
$uid = current_user_id();
$q = trim((string)($_GET['q'] ?? ''));
ensure_private_chat_permissions_table($pdo);

$hasDisplayName = false;
try {
    $chk = $pdo->query("SHOW COLUMNS FROM users LIKE 'display_name'");
    $hasDisplayName = $chk->rowCount() > 0;
} catch (Throwable $e) {}

$select = 'id, username, last_active, is_admin, is_vip, vip_label, is_invisible, avatar_token, is_banned_permanently, banned_until';
if ($hasDisplayName) {
    $select .= ', display_name';
}
$sql = "SELECT $select,
        CASE
            WHEN is_invisible = 1 THEN 0
            WHEN last_active >= (NOW() - INTERVAL 45 SECOND) THEN 1
            ELSE 0
        END AS is_online,
        (SELECT COUNT(*) FROM messages m
            WHERE m.sender_id = users.id AND m.recipient_id = ? AND m.read_at IS NULL AND m.deleted_at IS NULL
        ) AS unread_count,
        (SELECT m.body FROM messages m
            WHERE ((m.sender_id = users.id AND m.recipient_id = ?) OR (m.sender_id = ? AND m.recipient_id = users.id))
              AND m.deleted_at IS NULL
            ORDER BY m.id DESC LIMIT 1
        ) AS last_body,
        (SELECT m.file_name FROM messages m
            WHERE ((m.sender_id = users.id AND m.recipient_id = ?) OR (m.sender_id = ? AND m.recipient_id = users.id))
              AND m.deleted_at IS NULL
            ORDER BY m.id DESC LIMIT 1
        ) AS last_file_name,
        (SELECT 1 FROM messages m
            WHERE (m.sender_id = ? AND m.recipient_id = users.id)
               OR (m.sender_id = users.id AND m.recipient_id = ?)
            LIMIT 1
        ) AS has_private_history,
        (SELECT p.status FROM private_chat_permissions p
            WHERE p.requester_id = ? AND p.recipient_id = users.id
            ORDER BY p.updated_at DESC LIMIT 1
        ) AS dm_out_status,
        (SELECT p.id FROM private_chat_permissions p
            WHERE p.requester_id = ? AND p.recipient_id = users.id
            ORDER BY p.updated_at DESC LIMIT 1
        ) AS dm_out_id,
        (SELECT p.status FROM private_chat_permissions p
            WHERE p.requester_id = users.id AND p.recipient_id = ?
            ORDER BY p.updated_at DESC LIMIT 1
        ) AS dm_in_status,
        (SELECT p.id FROM private_chat_permissions p
            WHERE p.requester_id = users.id AND p.recipient_id = ?
            ORDER BY p.updated_at DESC LIMIT 1
        ) AS dm_in_id
     FROM users
     WHERE id <> ?";
$params = [$uid, $uid, $uid, $uid, $uid, $uid, $uid, $uid, $uid, $uid, $uid, $uid];

if ($q !== '') {
    $term = '%' . $q . '%';
    if ($hasDisplayName) {
        $sql .= ' AND (username LIKE ? OR COALESCE(display_name, \'\') LIKE ?)';
        $params[] = $term;
        $params[] = $term;
    } else {
        $sql .= ' AND username LIKE ?';
        $params[] = $term;
    }
}
$sql .= ' ORDER BY username';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$users = [];
foreach ($rows as $row) {
    $lastActive = $row['last_active'] ?? null;
    if (!empty($row['is_admin']) && !empty($row['is_invisible'])) {
        $now = new DateTime();
        $now->modify('-12 hours');
        $lastActive = $now->format('Y-m-d H:i:s');
    }
    $dmOutStatus = strtolower((string)($row['dm_out_status'] ?? ''));
    $dmInStatus = strtolower((string)($row['dm_in_status'] ?? ''));
    $dmOutId = (int)($row['dm_out_id'] ?? 0);
    $dmInId = (int)($row['dm_in_id'] ?? 0);
    $hasPrivateHistory = !empty($row['has_private_history']);
    $dmStatus = 'none';
    $dmRequestId = 0;
    if ($hasPrivateHistory) {
        $dmStatus = 'accepted';
        $dmRequestId = ($dmOutStatus === 'accepted') ? $dmOutId : (($dmInStatus === 'accepted') ? $dmInId : 0);
    } elseif ($dmOutStatus === 'accepted') {
        $dmStatus = 'accepted';
        $dmRequestId = $dmOutId;
    } elseif ($dmInStatus === 'accepted') {
        $dmStatus = 'accepted';
        $dmRequestId = $dmInId;
    } elseif ($dmInStatus === 'pending') {
        $dmStatus = 'incoming';
        $dmRequestId = $dmInId;
    } elseif ($dmOutStatus === 'pending') {
        $dmStatus = 'outgoing';
        $dmRequestId = $dmOutId;
    }

    $u = [
        'id' => (int)$row['id'],
        'username' => $row['username'],
        'is_online' => (bool)$row['is_online'],
        'last_active' => $lastActive,
        'unread_count' => (int)$row['unread_count'],
        'last_body' => $row['last_body'] ?? '',
        'last_file_name' => $row['last_file_name'] ?? '',
        'is_admin' => !empty($row['is_admin']),
        'is_vip' => !empty($row['is_vip']),
        'vip_label' => $row['vip_label'] ?? '',
        'is_banned' => !empty($row['is_banned_permanently']) || (!empty($row['banned_until']) && strtotime((string)$row['banned_until']) > time()),
        'dm_status' => $dmStatus,
        'dm_request_id' => $dmRequestId,
        'can_private_chat' => ($dmStatus === 'accepted') || $hasPrivateHistory || current_user_is_admin() || !empty($row['is_admin']),
    ];
    if ($hasDisplayName && array_key_exists('display_name', $row)) {
        $u['display_name'] = $row['display_name'] ?? null;
    }
    if (!empty($row['avatar_token'])) {
        $u['avatar_url'] = 'avatar.php?u=' . (int)$row['id'] . '&t=' . rawurlencode($row['avatar_token']);
    } else {
        $u['avatar_url'] = null;
    }
    $users[] = $u;
}

json_response(['ok' => true, 'users' => $users]);
