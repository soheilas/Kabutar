<?php
require_once __DIR__ . '/../config.php';
require_login();

$pdo = db();
$uid = current_user_id();
$method = $_SERVER['REQUEST_METHOD'];
ensure_private_chat_permissions_table($pdo);

function has_column_private_requests(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

if ($method === 'GET') {
    $hasDisplayName = has_column_private_requests($pdo, 'users', 'display_name');
    $select = 'p.id AS request_id, p.requester_id AS user_id, p.created_at, u.username';
    if ($hasDisplayName) {
        $select .= ', u.display_name';
    }
    $stmt = $pdo->prepare(
        "SELECT $select
         FROM private_chat_permissions p
         JOIN users u ON u.id = p.requester_id
         WHERE p.recipient_id = ? AND p.status = 'pending'
         ORDER BY p.updated_at DESC"
    );
    $stmt->execute([$uid]);
    $incoming = [];
    foreach ($stmt->fetchAll() as $row) {
        $displayName = $row['username'];
        if ($hasDisplayName && !empty($row['display_name'])) {
            $displayName = (string)$row['display_name'];
        }
        $incoming[] = [
            'request_id' => (int)$row['request_id'],
            'user_id' => (int)$row['user_id'],
            'username' => (string)$row['username'],
            'display_name' => $displayName,
            'created_at' => $row['created_at'],
        ];
    }
    json_response(['ok' => true, 'incoming' => $incoming]);
}

if ($method === 'POST') {
    require_csrf_json();
    $action = $_POST['action'] ?? '';

    if ($action === 'send') {
        $targetId = (int)($_POST['user_id'] ?? 0);
        if ($targetId <= 0 || $targetId === $uid) {
            json_response(['ok' => false, 'error' => 'کاربر نامعتبر است.'], 400);
        }
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ?');
        $stmt->execute([$targetId]);
        if (!$stmt->fetchColumn()) {
            json_response(['ok' => false, 'error' => 'کاربر پیدا نشد.'], 404);
        }

        $relation = private_chat_relation($pdo, $uid, $targetId);
        if (!empty($relation['can_chat']) || private_chat_has_history($pdo, $uid, $targetId)) {
            json_response(['ok' => true, 'status' => 'accepted']);
        }
        if (!empty($relation['incoming_pending_id'])) {
            json_response([
                'ok' => false,
                'error' => 'از این کاربر درخواست دارید. ابتدا آن را قبول یا رد کنید.',
                'status' => 'incoming',
                'request_id' => (int)$relation['incoming_pending_id'],
            ], 409);
        }

        $stmt = $pdo->prepare(
            "INSERT INTO private_chat_permissions (requester_id, recipient_id, status, responded_by, responded_at)
             VALUES (?, ?, 'pending', NULL, NULL)
             ON DUPLICATE KEY UPDATE
               status = 'pending',
               responded_by = NULL,
               responded_at = NULL,
               updated_at = CURRENT_TIMESTAMP"
        );
        $stmt->execute([$uid, $targetId]);

        $requestId = (int)$pdo->lastInsertId();
        if ($requestId <= 0) {
            $stmt = $pdo->prepare('SELECT id FROM private_chat_permissions WHERE requester_id = ? AND recipient_id = ? LIMIT 1');
            $stmt->execute([$uid, $targetId]);
            $requestId = (int)($stmt->fetchColumn() ?: 0);
        }

        json_response(['ok' => true, 'status' => 'outgoing', 'request_id' => $requestId]);
    }

    if ($action === 'respond') {
        $requestId = (int)($_POST['request_id'] ?? 0);
        $decision = strtolower(trim((string)($_POST['decision'] ?? '')));
        if ($requestId <= 0 || !in_array($decision, ['accept', 'reject'], true)) {
            json_response(['ok' => false, 'error' => 'درخواست نامعتبر است.'], 400);
        }

        $stmt = $pdo->prepare(
            "SELECT id, requester_id, recipient_id, status
             FROM private_chat_permissions
             WHERE id = ? AND recipient_id = ?"
        );
        $stmt->execute([$requestId, $uid]);
        $row = $stmt->fetch();
        if (!$row) {
            json_response(['ok' => false, 'error' => 'درخواست پیدا نشد.'], 404);
        }
        if (($row['status'] ?? '') !== 'pending') {
            json_response(['ok' => false, 'error' => 'این درخواست دیگر فعال نیست.'], 409);
        }

        $requesterId = (int)$row['requester_id'];
        if ($decision === 'accept') {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare(
                    "UPDATE private_chat_permissions
                     SET status = 'accepted', responded_by = ?, responded_at = NOW(), updated_at = NOW()
                     WHERE id = ?"
                );
                $stmt->execute([$uid, $requestId]);

                $stmt = $pdo->prepare(
                    "UPDATE private_chat_permissions
                     SET status = 'accepted', responded_by = ?, responded_at = NOW(), updated_at = NOW()
                     WHERE requester_id = ? AND recipient_id = ? AND status = 'pending'"
                );
                $stmt->execute([$uid, $uid, $requesterId]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                json_response(['ok' => false, 'error' => 'Unable to accept request right now.'], 500);
            }
            json_response(['ok' => true, 'status' => 'accepted', 'user_id' => $requesterId]);
        }

        $stmt = $pdo->prepare(
            "UPDATE private_chat_permissions
             SET status = 'rejected', responded_by = ?, responded_at = NOW(), updated_at = NOW()
             WHERE id = ?"
        );
        $stmt->execute([$uid, $requestId]);
        json_response(['ok' => true, 'status' => 'rejected', 'user_id' => $requesterId]);
    }

    if ($action === 'cancel') {
        $requestId = (int)($_POST['request_id'] ?? 0);
        $targetId = (int)($_POST['user_id'] ?? 0);
        if ($requestId <= 0 && $targetId <= 0) {
            json_response(['ok' => false, 'error' => 'درخواست نامعتبر است.'], 400);
        }
        if ($requestId > 0) {
            $stmt = $pdo->prepare(
                "DELETE FROM private_chat_permissions
                 WHERE id = ? AND requester_id = ? AND status = 'pending'"
            );
            $stmt->execute([$requestId, $uid]);
        } else {
            if ($targetId === $uid) {
                json_response(['ok' => false, 'error' => 'کاربر نامعتبر است.'], 400);
            }
            $stmt = $pdo->prepare(
                "DELETE FROM private_chat_permissions
                 WHERE requester_id = ? AND recipient_id = ? AND status = 'pending'"
            );
            $stmt->execute([$uid, $targetId]);
        }
        json_response(['ok' => true, 'deleted' => $stmt->rowCount() > 0]);
    }

    json_response(['ok' => false, 'error' => 'درخواست نامعتبر است.'], 400);
}

json_response(['ok' => false, 'error' => 'متد مجاز نیست.'], 405);
