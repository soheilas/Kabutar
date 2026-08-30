<?php
require_once __DIR__ . '/../config.php';
require_login();

$pdo = db();
$uid = current_user_id();
$method = $_SERVER['REQUEST_METHOD'];

$hasProfileColumns = function (PDO $pdo): bool {
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'display_name'");
        $ok = $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
};

function avatar_fit_dimensions(int $width, int $height, int $maxDimension): array {
    if ($width <= 0 || $height <= 0 || $maxDimension <= 0) {
        return [0, 0];
    }
    $scale = min(1, $maxDimension / max($width, $height));
    $targetW = max(1, (int)round($width * $scale));
    $targetH = max(1, (int)round($height * $scale));
    return [$targetW, $targetH];
}

function optimize_avatar_image(string $tmpPath, string $destPath, string $sourceMime): bool {
    if (!function_exists('imagecreatefromstring') || !function_exists('imagecreatetruecolor') || !function_exists('imagecopyresampled')) {
        return false;
    }

    $binary = @file_get_contents($tmpPath);
    if ($binary === false) {
        return false;
    }

    $source = @imagecreatefromstring($binary);
    if (!$source) {
        return false;
    }

    $sourceW = imagesx($source);
    $sourceH = imagesy($source);
    [$targetW, $targetH] = avatar_fit_dimensions($sourceW, $sourceH, 512);
    if ($targetW <= 0 || $targetH <= 0) {
        imagedestroy($source);
        return false;
    }

    $target = $source;
    if ($targetW !== $sourceW || $targetH !== $sourceH) {
        $target = imagecreatetruecolor($targetW, $targetH);
        if (!$target) {
            imagedestroy($source);
            return false;
        }
        $keepAlpha = in_array($sourceMime, ['image/png', 'image/webp', 'image/gif'], true);
        if ($keepAlpha) {
            imagealphablending($target, false);
            imagesavealpha($target, true);
            $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
            imagefilledrectangle($target, 0, 0, $targetW, $targetH, $transparent);
        } else {
            $white = imagecolorallocate($target, 255, 255, 255);
            imagefilledrectangle($target, 0, 0, $targetW, $targetH, $white);
        }
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetW, $targetH, $sourceW, $sourceH);
    }

    $written = false;
    if (function_exists('imagewebp')) {
        $written = @imagewebp($target, $destPath, 82);
    }
    if (!$written && in_array($sourceMime, ['image/png', 'image/gif'], true) && function_exists('imagepng')) {
        $written = @imagepng($target, $destPath, 7);
    }
    if (!$written && function_exists('imagejpeg')) {
        $written = @imagejpeg($target, $destPath, 82);
    }

    if ($target !== $source) {
        imagedestroy($target);
    }
    imagedestroy($source);

    return $written && is_file($destPath);
}

if ($method === 'GET') {
    $targetId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : $uid;
    if ($targetId <= 0) {
        $targetId = $uid;
    }
    $stmt = $pdo->prepare('SELECT id, username FROM users WHERE id = ?');
    $stmt->execute([$targetId]);
    $row = $stmt->fetch();
    if (!$row) {
        json_response(['ok' => false, 'error' => 'کاربر پیدا نشد.'], 404);
    }
    $out = [
        'ok'           => true,
        'id'           => (int)$row['id'],
        'username'     => $row['username'],
        'display_name' => null,
        'bio'          => null,
        'avatar_url'   => null,
    ];
    if ($hasProfileColumns($pdo)) {
        $stmt = $pdo->prepare('SELECT display_name, bio, avatar_token FROM users WHERE id = ?');
        $stmt->execute([$targetId]);
        $r = $stmt->fetch();
        if ($r) {
            $out['display_name'] = $r['display_name'] ?? null;
            $out['bio'] = $r['bio'] ?? null;
            $out['avatar_url'] = !empty($r['avatar_token'])
                ? ('avatar.php?u=' . $targetId . '&t=' . rawurlencode($r['avatar_token']))
                : null;
        }
    }
    json_response($out);
}

if ($method === 'POST') {
    require_csrf_json();
    $action = $_POST['action'] ?? '';

    if (!$hasProfileColumns($pdo)) {
        json_response(['ok' => false, 'error' => 'قابلیت پروفایل فعال نیست. ابتدا migration دیتابیس را اجرا کنید.'], 400);
    }

    if ($action === 'update') {
        $displayName = trim((string)($_POST['display_name'] ?? ''));
        $bio = trim((string)($_POST['bio'] ?? ''));
        if (mb_strlen($displayName) > 100) {
            json_response(['ok' => false, 'error' => 'نام نمایشی حداکثر ۱۰۰ کاراکتر.'], 400);
        }
        $stmt = $pdo->prepare('UPDATE users SET display_name = ?, bio = ? WHERE id = ?');
        $stmt->execute([$displayName ?: null, $bio ?: null, $uid]);
        json_response(['ok' => true]);
    }

    if ($action === 'upload_avatar') {
        if (empty($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
            json_response(['ok' => false, 'error' => 'فایل انتخاب نشده یا آپلود ناموفق.'], 400);
        }
        $file = $_FILES['avatar'];
        $ext = strtolower(pathinfo($file['name'] ?? 'x', PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array($ext, $allowed, true)) {
            json_response(['ok' => false, 'error' => 'فقط تصویر (jpg, png, gif, webp) مجاز است.'], 400);
        }
        if ($file['size'] > 2 * 1024 * 1024) {
            json_response(['ok' => false, 'error' => 'حجم عکس حداکثر ۲ مگابایت.'], 400);
        }
        $imageInfo = @getimagesize($file['tmp_name']);
        $mime = strtolower((string)($imageInfo['mime'] ?? ''));
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!$imageInfo || !in_array($mime, $allowedMimes, true)) {
            json_response(['ok' => false, 'error' => 'فایل تصویر معتبر نیست.'], 400);
        }
        ensure_upload_dir();
        $token = bin2hex(random_bytes(16));
        $path = UPLOAD_DIR . '/' . $token;
        $optimized = optimize_avatar_image($file['tmp_name'], $path, $mime);
        if (!$optimized && is_file($path)) {
            @unlink($path);
        }
        if (!$optimized) {
            if (!move_uploaded_file($file['tmp_name'], $path)) {
                json_response(['ok' => false, 'error' => 'ذخیره فایل انجام نشد.'], 500);
            }
        }
        $stmt = $pdo->prepare('SELECT avatar_token FROM users WHERE id = ?');
        $stmt->execute([$uid]);
        $old = $stmt->fetchColumn();
        if ($old && is_file(UPLOAD_DIR . '/' . $old)) {
            @unlink(UPLOAD_DIR . '/' . $old);
        }
        $stmt = $pdo->prepare('UPDATE users SET avatar_token = ? WHERE id = ?');
        $stmt->execute([$token, $uid]);
        json_response([
            'ok' => true,
            'avatar_url' => 'avatar.php?u=' . $uid . '&t=' . rawurlencode($token),
        ]);
    }

    json_response(['ok' => false, 'error' => 'درخواست نامعتبر است.'], 400);
}

json_response(['ok' => false, 'error' => 'متد مجاز نیست.'], 405);
