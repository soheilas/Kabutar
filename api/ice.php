<?php
/**
 * تنظیمات سرور تماس را به مرورگر می‌دهد.
 *
 * چرا جدا؟ چون پیش از این نشانی و رمز سرور ترن داخل app.js نوشته شده بود
 * و app.js را هر کسی بدون ورود می‌تواند بخواند — یعنی پهنای باند سرور
 * تماس در دسترس همه بود. حالا این‌جا ورود لازم است.
 *
 * اگر turn_secret تنظیم شده باشد، برای هر کاربر یک رمز یک‌بارمصرف
 * ساخته می‌شود (روش رمز موقت coturn) و رمز اصلی هرگز بیرون نمی‌رود.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_login();

if (!FEATURE_CALLS) {
    json_response(['ok' => true, 'enabled' => false, 'iceServers' => []]);
}

$servers = [];

$stun = trim((string)cfg('calls.stun', ''));
if ($stun !== '') {
    $servers[] = ['urls' => $stun];
}

$turnUrl = trim((string)cfg('calls.turn_url', ''));
if ($turnUrl !== '') {
    $secret = trim((string)cfg('calls.turn_secret', ''));

    if ($secret !== '') {
        // رمز موقت: نام کاربری «زمان انقضا:شناسه» و رمز، امضای آن با کلید مشترک
        $ttl      = max(60, (int)cfg('calls.turn_ttl_secs', 3600));
        $expiry   = time() + $ttl;
        $username = $expiry . ':u' . current_user_id();
        $password = base64_encode(hash_hmac('sha1', $username, $secret, true));

        $servers[] = [
            'urls'       => $turnUrl,
            'username'   => $username,
            'credential' => $password,
        ];
    } else {
        // رمز ثابت
        $user = (string)cfg('calls.turn_user', '');
        $pass = (string)cfg('calls.turn_pass', '');
        if ($user !== '' && $pass !== '') {
            $servers[] = [
                'urls'       => $turnUrl,
                'username'   => $user,
                'credential' => $pass,
            ];
        } else {
            $servers[] = ['urls' => $turnUrl];
        }
    }
}

// مرورگر نباید این را ذخیره کند — رمز موقت است
header('Cache-Control: no-store, private');

json_response([
    'ok'         => true,
    'enabled'    => true,
    'iceServers' => $servers,
]);
