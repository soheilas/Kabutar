<?php
require_once __DIR__ . '/../config.php';
require_login();

$pdo = db();
$uid = current_user_id();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    require_csrf_json();
    $action = $_POST['action'] ?? '';
    if ($action === 'set_invisible') {
        if (!function_exists('current_user_is_admin') || !current_user_is_admin()) {
            json_response(['ok' => false, 'error' => 'اجازه دسترسی ندارید.'], 403);
        }
        $value = (int)($_POST['value'] ?? 0);
        $stmt = $pdo->prepare('UPDATE users SET is_invisible = ? WHERE id = ?');
        $stmt->execute([$value ? 1 : 0, $uid]);
        json_response(['ok' => true, 'is_invisible' => $value ? 1 : 0]);
    } elseif ($action === 'set_notify_mode') {
        $mode = $_POST['value'] ?? 'all';
        if (!in_array($mode, ['all', 'mentions', 'none'], true)) {
            json_response(['ok' => false, 'error' => 'حالت نامعتبر است.'], 400);
        }
        // ذخیره در session (ساده‌ترین روش بدون ستون اضافه)
        $_SESSION['notify_mode'] = $mode;
        // اگه ستون notify_mode در دیتابیس وجود داشت، اونو هم آپدیت کن
        try {
            $chk = $pdo->query("SHOW COLUMNS FROM users LIKE 'notify_mode'");
            if ($chk->rowCount() > 0) {
                $pdo->prepare('UPDATE users SET notify_mode=? WHERE id=?')->execute([$mode, $uid]);
            }
        } catch (Throwable $e) {}
        json_response(['ok' => true, 'notify_mode' => $mode]);
    } elseif ($action === 'update_last_active') {
        // به‌روزرسانی فوری last_active (برای بستن صفحه یا تغییر تب)
        touch_user($uid, true);
        json_response(['ok' => true]);
    } else {
        json_response(['ok' => false, 'error' => 'درخواست نامعتبر است.'], 400);
    }
}

$stmt = $pdo->prepare('SELECT is_invisible, username, display_name FROM users WHERE id = ?');
$stmt->execute([$uid]);
$meRow       = $stmt->fetch();
$isInvisible = (bool)($meRow['is_invisible'] ?? false);
$meUsername  = (string)($meRow['username'] ?? '');
$meDisplayName = (string)($meRow['display_name'] ?? '');

// خواندن notify_mode
$notifyMode = $_SESSION['notify_mode'] ?? 'all';
try {
    $chk = $pdo->query("SHOW COLUMNS FROM users LIKE 'notify_mode'");
    if ($chk->rowCount() > 0) {
        $stmt2 = $pdo->prepare('SELECT notify_mode FROM users WHERE id=?');
        $stmt2->execute([$uid]);
        $dbMode = $stmt2->fetchColumn();
        if ($dbMode && in_array($dbMode, ['all','mentions','none'], true)) {
            $notifyMode = $dbMode;
            $_SESSION['notify_mode'] = $dbMode;
        }
    }
} catch (Throwable $e) {}

json_response([
    'ok'           => true,
    'username'     => $meUsername,
    'display_name' => $meDisplayName ?: null,
    'is_vip'       => current_user_is_vip(),
    'is_admin'     => function_exists('current_user_is_admin') && current_user_is_admin(),
    'is_invisible' => $isInvisible,
    'notify_mode'  => $notifyMode,
]);
