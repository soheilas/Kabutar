<?php
require_once __DIR__ . '/../config.php';
require_login();

$pdo    = db();
$uid    = current_user_id();
$method = $_SERVER['REQUEST_METHOD'];

// ساخت جدول اگه نبود
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS call_signals (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        caller_id INT UNSIGNED NOT NULL,
        receiver_id INT UNSIGNED NOT NULL,
        type VARCHAR(20) NOT NULL,
        data TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_receiver (receiver_id, created_at),
        KEY idx_caller (caller_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(Throwable $e) {}

// پاک کردن سیگنال‌های قدیمی (بیشتر از ۳۰ ثانیه)
try {
    $pdo->exec("DELETE FROM call_signals WHERE created_at < (NOW() - INTERVAL 30 SECOND)");
} catch(Throwable $e) {}

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';

    // تاریخچه تماس
    if ($action === 'history') {
        try {
            // چک وجود ستون display_name
            $hasDisplayName = false;
            try {
                $chk = $pdo->query("SHOW COLUMNS FROM users LIKE 'display_name'");
                $hasDisplayName = $chk->rowCount() > 0;
            } catch (Throwable $e) {}
            $dnSelect = $hasDisplayName ? ', u.display_name' : '';
            $stmt = $pdo->prepare(
                "SELECT cs.id, cs.caller_id, cs.receiver_id, cs.data,
                        UNIX_TIMESTAMP(cs.created_at) AS ts,
                        u.username{$dnSelect}
                 FROM call_signals cs
                 LEFT JOIN users u ON u.id = IF(cs.caller_id=?, cs.receiver_id, cs.caller_id)
                 WHERE (cs.caller_id=? OR cs.receiver_id=?)
                   AND cs.type='offer'
                 ORDER BY cs.id DESC LIMIT 50"
            );
            $stmt->execute([$uid, $uid, $uid]);
            $rows = $stmt->fetchAll();
            $history = array_map(function($r) use ($uid, $hasDisplayName) {
                $data = json_decode($r['data'] ?? '{}', true);
                $peerName = $hasDisplayName && !empty($r['display_name'])
                    ? $r['display_name']
                    : ($r['username'] ?? 'کاربر');
                return [
                    'id'           => (int)$r['id'],
                    'caller_id'    => (int)$r['caller_id'],
                    'receiver_id'  => (int)$r['receiver_id'],
                    'is_outgoing'  => ((int)$r['caller_id'] === $uid),
                    'peer_name'    => $peerName,
                    'peer_username'=> $r['username'] ?? '',
                    'call_type'    => !empty($data['video']) ? 'video' : 'audio',
                    'ts'           => (int)$r['ts'],
                ];
            }, $rows);
            json_response(['ok' => true, 'history' => $history]);
        } catch(Throwable $e) {
            json_response(['ok' => true, 'history' => []]);
        }
    }

    // دریافت سیگنال‌های جدید
    $since = (int)($_GET['since'] ?? 0);
    $stmt  = $pdo->prepare(
        "SELECT id, caller_id, receiver_id, type, data, 
                UNIX_TIMESTAMP(created_at) AS ts
         FROM call_signals
         WHERE (receiver_id=? OR caller_id=?)
           AND id > ?
         ORDER BY id ASC LIMIT 20"
    );
    $stmt->execute([$uid, $uid, $since]);
    $rows = $stmt->fetchAll();
    json_response(['ok' => true, 'signals' => $rows]);
}

if ($method === 'POST') {
    require_csrf_json();
    $type       = trim($_POST['type'] ?? '');
    $targetId   = (int)($_POST['target_id'] ?? 0);
    $data       = trim($_POST['data'] ?? '{}');

    $allowed = ['offer','answer','ice','reject','hangup','busy','missed'];
    if (!in_array($type, $allowed, true) || $targetId <= 0) {
        json_response(['ok' => false, 'error' => 'ورودی نامعتبر.'], 400);
    }

    // برای offer چک کن آیا تماس فعالی هست
    if ($type === 'offer') {
        $stmt = $pdo->prepare(
            "SELECT id FROM call_signals 
             WHERE receiver_id=? AND type='offer' 
               AND created_at >= (NOW() - INTERVAL 30 SECOND)
             LIMIT 1"
        );
        $stmt->execute([$targetId]);
        if ($stmt->fetchColumn()) {
            json_response(['ok' => false, 'error' => 'busy', 'busy' => true]);
        }
    }

    $stmt = $pdo->prepare(
        "INSERT INTO call_signals (caller_id, receiver_id, type, data) VALUES (?,?,?,?)"
    );
    $stmt->execute([$uid, $targetId, $type, $data]);
    json_response(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
}

json_response(['ok' => false, 'error' => 'متد نامعتبر.'], 405);
