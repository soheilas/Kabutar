<?php
require_once __DIR__ . '/../config.php';
require_login();

$pdo    = db();
$uid    = current_user_id();
$method = $_SERVER['REQUEST_METHOD'];

// جدول subscriptions
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS push_subscriptions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NOT NULL,
        endpoint TEXT NOT NULL,
        p256dh VARCHAR(512),
        auth VARCHAR(255),
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(Throwable $e) {}

if ($method === 'POST') {
    require_csrf_json();
    $action = $_POST['action'] ?? '';

    if ($action === 'subscribe') {
        $endpoint = trim($_POST['endpoint'] ?? '');
        $p256dh   = trim($_POST['p256dh'] ?? '');
        $auth     = trim($_POST['auth'] ?? '');
        if (!$endpoint) json_response(['ok'=>false,'error'=>'endpoint خالی.'],400);
        // جایگزین کردن subscription قدیمی
        $pdo->prepare('DELETE FROM push_subscriptions WHERE user_id=? AND endpoint=?')->execute([$uid, $endpoint]);
        $pdo->prepare('INSERT INTO push_subscriptions (user_id,endpoint,p256dh,auth) VALUES (?,?,?,?)')->execute([$uid,$endpoint,$p256dh,$auth]);
        json_response(['ok'=>true,'message'=>'اشتراک ذخیره شد.']);
    }

    if ($action === 'unsubscribe') {
        $endpoint = trim($_POST['endpoint'] ?? '');
        $pdo->prepare('DELETE FROM push_subscriptions WHERE user_id=? AND endpoint=?')->execute([$uid,$endpoint]);
        json_response(['ok'=>true]);
    }

    json_response(['ok'=>false,'error'=>'عملیات نامعتبر.'],400);
}

// GET: برگردوندن public key
if ($method === 'GET') {
    json_response(['ok'=>true,'public_key'=>VAPID_PUBLIC_KEY]);
}

json_response(['ok'=>false,'error'=>'متد نامعتبر.'],405);
