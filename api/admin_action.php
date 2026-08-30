<?php
require_once __DIR__ . '/../config.php';
require_login();

// ادمین کامل یا ادمین معمولی (is_admin)
if (!current_user_is_admin()) {
    json_response(['ok' => false, 'error' => 'دسترسی ندارید.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'متد نامعتبر.'], 405);
}
require_csrf_json();

$pdo       = db();
$action    = $_POST['action'] ?? '';
$uid       = (int)($_POST['user_id'] ?? 0);
$myId      = current_user_id();

// سازنده — بررسی قبلی جعلی بود: همان is_admin را می‌خواند، پس برای
// هر مدیری درست بود و هر مدیری می‌توانست رمز سازنده را عوض کند.
$isSuperAdmin = current_user_is_owner();

/** هدف را اعتبارسنجی می‌کند: باید وجود داشته باشد و رتبه‌اش پایین‌تر باشد */
$requireTarget = function (int $target) use ($myId): int {
    if ($target <= 0)              throw new RuntimeException('کاربر نامعتبر.');
    if ($target === $myId)         throw new RuntimeException('روی حساب خودتان نمی‌توانید این کار را بکنید.');
    if (!can_act_on($target))      throw new RuntimeException('این کاربر هم‌رده یا بالاتر از شماست.');
    return $target;
};

try {
    switch ($action) {

        // ─── VIP ───
        case 'vip':
            $requireTarget($uid);
            $val   = (int)($_POST['value'] ?? 1);
            $label = trim($_POST['vip_label'] ?? '');
            if ($label !== '' && mb_strlen($label) > 60) throw new RuntimeException('عنوان VIP طولانی است.');
            if ($val) {
                $pdo->prepare('UPDATE users SET is_vip=1, vip_label=? WHERE id=?')->execute([$label ?: null, $uid]);
            } else {
                $pdo->prepare('UPDATE users SET is_vip=0, vip_label=NULL WHERE id=?')->execute([$uid]);
            }
            admin_log($val ? 'vip_grant' : 'vip_revoke', $uid, $label);
            json_response(['ok' => true, 'message' => $val ? 'VIP شد.' : 'VIP حذف شد.']);

        // ─── بن موقت ───
        case 'ban_user':
            $requireTarget($uid);
            $mins   = max(1, (int)($_POST['ban_minutes'] ?? 60));
            $reason = trim($_POST['reason'] ?? '');
            $until  = date('Y-m-d H:i:s', time() + $mins * 60);
            $pdo->prepare('UPDATE users SET banned_until=?, is_banned_permanently=0, ban_reason=? WHERE id=?')
                ->execute([$until, $reason ?: null, $uid]);
            admin_log('ban_temp', $uid, "{$mins} دقیقه — {$reason}");
            json_response(['ok' => true, 'message' => "مسدود شد تا {$mins} دقیقه دیگر."]);

        // ─── بن دائم ───
        case 'ban_permanent':
            $requireTarget($uid);
            $reason = trim($_POST['reason'] ?? '');
            $pdo->prepare('UPDATE users SET is_banned_permanently=1, ban_reason=? WHERE id=?')
                ->execute([$reason ?: null, $uid]);
            admin_log('ban_permanent', $uid, $reason);
            json_response(['ok' => true, 'message' => 'کاربر به صورت دائم مسدود شد.']);

        // ─── رفع بن ───
        case 'unban_user':
            if ($uid <= 0) throw new RuntimeException('کاربر نامعتبر.');
            if (!can_act_on($uid)) throw new RuntimeException('این کاربر هم‌رده یا بالاتر از شماست.');
            $pdo->prepare('UPDATE users SET banned_until=NULL, is_banned_permanently=0, ban_reason=NULL WHERE id=?')
                ->execute([$uid]);
            admin_log('unban', $uid);
            json_response(['ok' => true, 'message' => 'مسدودی برداشته شد.']);

        // ─── تغییر رمز ───
        case 'change_password':
            // نسخه‌ی قبلی اجازه می‌داد هر مدیری رمز هر کسی از جمله سازنده
            // را عوض کند و سایت را تحویل بگیرد.
            $requireTarget($uid);
            $pass = trim($_POST['new_password'] ?? '');
            if (strlen($pass) < ADMIN_SET_MIN_PASSWORD) {
                throw new RuntimeException('رمز حداقل ' . ADMIN_SET_MIN_PASSWORD . ' کاراکتر.');
            }
            $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')
                ->execute([password_hash($pass, PASSWORD_DEFAULT), $uid]);
            admin_log('change_password', $uid);
            json_response(['ok' => true, 'message' => 'رمز تغییر کرد.']);

        // ─── ادمین کردن ───
        case 'make_admin':
            if (!$isSuperAdmin) throw new RuntimeException('فقط سازنده می‌تواند مدیر تعیین کند.');
            if ($uid <= 0) throw new RuntimeException('کاربر نامعتبر.');
            if ($uid === $myId) throw new RuntimeException('نمیتوانید دسترسی خودتان را تغییر دهید.');
            if (admin_level_of($uid) >= ADMIN_LEVEL_OWNER) throw new RuntimeException('سازنده را نمی‌توان تغییر داد.');
            $val = (int)($_POST['value'] ?? 1);
            $pdo->prepare('UPDATE users SET is_admin=?, admin_level=? WHERE id=?')
                ->execute([$val ? 1 : 0, $val ? ADMIN_LEVEL_ADMIN : ADMIN_LEVEL_NONE, $uid]);
            admin_log($val ? 'make_admin' : 'revoke_admin', $uid);
            json_response(['ok' => true, 'message' => $val ? 'مدیر شد.' : 'دسترسی مدیر حذف شد.']);

        // ─── حذف پیام‌های کاربر ───
        case 'delete_all_messages':
            $requireTarget($uid);
            // فایل‌های پیوست را هم از دیسک پاک کن، نه فقط ردیف پایگاه داده
            $tk = $pdo->prepare('SELECT file_token FROM messages WHERE sender_id=? AND file_token IS NOT NULL');
            $tk->execute([$uid]);
            $tokens = array_column($tk->fetchAll(), 'file_token');
            $pdo->prepare('DELETE FROM messages WHERE sender_id=?')->execute([$uid]);
            foreach ($tokens as $t) delete_upload($t);
            admin_log('delete_all_messages', $uid, count($tokens) . ' فایل');
            json_response(['ok' => true, 'message' => 'همه پیام‌های کاربر حذف شد.']);

        // ─── اخراج از همه گروه‌ها ───
        case 'kick_all_rooms':
            $requireTarget($uid);
            // پیدا کن room_id هایی که کاربر عضوشه
            $roomStmt = $pdo->prepare('SELECT room_id FROM room_members WHERE user_id=?');
            $roomStmt->execute([$uid]);
            $roomIds = array_column($roomStmt->fetchAll(), 'room_id');
            // پیام‌های کاربر در اون گروه‌ها رو حذف کن
            if ($roomIds) {
                $ph = implode(',', array_fill(0, count($roomIds), '?'));
                $params = array_merge([$uid], $roomIds);
                $pdo->prepare("DELETE FROM messages WHERE sender_id=? AND room_id IN ($ph)")->execute($params);
            }
            $pdo->prepare('DELETE FROM room_members WHERE user_id=?')->execute([$uid]);
            admin_log('kick_all_rooms', $uid);
            json_response(['ok' => true, 'message' => 'از همه گروه‌ها اخراج و پیام‌هایش حذف شد.']);

        // ─── حذف کامل کاربر ───
        case 'delete_user':
            $requireTarget($uid);
            // فایل‌های کاربر را جمع کن تا بعد از حذف، از دیسک هم پاک شوند
            $tk = $pdo->prepare('SELECT file_token FROM messages WHERE (sender_id=? OR recipient_id=?) AND file_token IS NOT NULL');
            $tk->execute([$uid, $uid]);
            $tokens = array_column($tk->fetchAll(), 'file_token');
            $av = $pdo->prepare('SELECT avatar_token FROM users WHERE id=?');
            $av->execute([$uid]);
            $avatarToken = (string)($av->fetchColumn() ?: '');
            $pdo->prepare('DELETE FROM messages WHERE sender_id=?')->execute([$uid]);
            $pdo->prepare('DELETE FROM messages WHERE recipient_id=?')->execute([$uid]);
            $pdo->prepare('DELETE FROM room_members WHERE user_id=?')->execute([$uid]);
            $pdo->prepare('DELETE FROM private_chat_permissions WHERE requester_id=? OR recipient_id=?')->execute([$uid, $uid]);
            $pdo->prepare('DELETE FROM user_blocks WHERE blocker_id=? OR blocked_id=?')->execute([$uid, $uid]);
            try { $pdo->prepare('DELETE FROM message_reactions WHERE user_id=?')->execute([$uid]); } catch (\Throwable $e) {}
            try { $pdo->prepare('DELETE FROM signup_log WHERE user_id=?')->execute([$uid]); } catch (\Throwable $e) {}
            $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);
            foreach ($tokens as $t) delete_upload($t);
            if ($avatarToken !== '') delete_upload($avatarToken);
            admin_log('delete_user', $uid, count($tokens) . ' فایل');
            json_response(['ok' => true, 'message' => 'کاربر و تمام داده‌هایش حذف شد.']);

        // ─── تنظیم VIP label ───
        case 'vip_label':
            $requireTarget($uid);
            $label = trim($_POST['vip_label'] ?? '');
            $pdo->prepare('UPDATE users SET vip_label=? WHERE id=?')->execute([$label ?: null, $uid]);
            json_response(['ok' => true, 'message' => 'عنوان VIP ذخیره شد.']);

        // ─── تنظیمات سایت ───
        case 'set_setting':
            if (!$isSuperAdmin) throw new RuntimeException('فقط سازنده می‌تواند تنظیمات را تغییر دهد.');
            $key = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($_POST['setting_key'] ?? '')));
            $val = trim($_POST['setting_value'] ?? '');
            if ($key === '') throw new RuntimeException('کلید نامعتبر.');
            site_setting_set($key, $val);
            // maintenance_mode فعال: همه کاربران در request بعدی kick میشن (check_maintenance)
            // برای kick فوری‌تر — touch کردن فایل که PHP session handler رو invalidate کنه
            if ($key === 'maintenance_mode' && $val === '1') {
                // تمام session‌های فعال رو force-expire کن
                // روش: update last_active به ۱۰۰۰ ثانیه پیش — require_login چک میکنه
                try {
                    $pdo->exec("UPDATE users SET last_active = DATE_SUB(NOW(), INTERVAL 1000 SECOND) WHERE is_invisible = 0");
                } catch (\Throwable $e) {}
            }
            admin_log('set_setting', 0, $key . '=' . $val);
            json_response(['ok' => true, 'message' => 'تنظیم ذخیره شد.']);

        // ─── حذف لاگ تماس‌ها ───
        case 'clear_call_logs':
            if (!$isSuperAdmin) throw new RuntimeException('دسترسی ندارید.');
            // هم تاریخچه، هم سیگنال‌های سرگردان
            foreach (['call_log', 'call_signals'] as $tbl) {
                try { $pdo->exec("TRUNCATE TABLE `$tbl`"); }
                catch (\Throwable $e) {
                    try { $pdo->exec("DELETE FROM `$tbl`"); } catch (\Throwable $e2) {}
                }
            }
            admin_log('clear_call_logs');
            json_response(['ok' => true, 'message' => 'لاگ تماس‌ها پاک شد.']);


        // ═══ کارهای گروهی ═══════════════════════════════════════
        // برای وقتی کسی انبوه حساب می‌سازد و باید یکجا پاک شوند.

        case 'bulk_ban':
        case 'bulk_delete':
            $raw = $_POST['user_ids'] ?? '';
            $ids = array_values(array_unique(array_filter(
                array_map('intval', is_array($raw) ? $raw : explode(',', (string)$raw))
            )));
            if (!$ids) throw new RuntimeException('هیچ کاربری انتخاب نشده.');
            if (count($ids) > 500) throw new RuntimeException('حداکثر ۵۰۰ کاربر در هر بار.');

            $done = 0; $skipped = 0;
            foreach ($ids as $target) {
                // همان مرزی که برای کار تکی هست، این‌جا هم اعمال می‌شود
                if ($target === $myId || !can_act_on($target)) { $skipped++; continue; }

                if ($action === 'bulk_ban') {
                    $pdo->prepare('UPDATE users SET is_banned_permanently=1, ban_reason=? WHERE id=?')
                        ->execute(['حذف گروهی توسط مدیر', $target]);
                } else {
                    $tk = $pdo->prepare('SELECT file_token FROM messages WHERE (sender_id=? OR recipient_id=?) AND file_token IS NOT NULL');
                    $tk->execute([$target, $target]);
                    $tokens = array_column($tk->fetchAll(), 'file_token');
                    $av = $pdo->prepare('SELECT avatar_token FROM users WHERE id=?');
                    $av->execute([$target]);
                    $avatarToken = (string)($av->fetchColumn() ?: '');

                    $pdo->prepare('DELETE FROM messages WHERE sender_id=? OR recipient_id=?')->execute([$target, $target]);
                    $pdo->prepare('DELETE FROM room_members WHERE user_id=?')->execute([$target]);
                    $pdo->prepare('DELETE FROM private_chat_permissions WHERE requester_id=? OR recipient_id=?')->execute([$target, $target]);
                    $pdo->prepare('DELETE FROM user_blocks WHERE blocker_id=? OR blocked_id=?')->execute([$target, $target]);
                    try { $pdo->prepare('DELETE FROM message_reactions WHERE user_id=?')->execute([$target]); } catch (\Throwable $e) {}
                    try { $pdo->prepare('DELETE FROM signup_log WHERE user_id=?')->execute([$target]); } catch (\Throwable $e) {}
                    $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$target]);

                    foreach ($tokens as $t) delete_upload($t);
                    if ($avatarToken !== '') delete_upload($avatarToken);
                }
                $done++;
            }
            admin_log($action, 0, "{$done} کاربر، {$skipped} رد شد");
            $msg = ($action === 'bulk_ban' ? 'مسدود شد: ' : 'حذف شد: ') . $done . ' کاربر';
            if ($skipped) $msg .= " — {$skipped} مورد به دلیل نداشتن دسترسی رد شد";
            json_response(['ok' => true, 'message' => $msg, 'done' => $done, 'skipped' => $skipped]);

        // ─── انتخاب همه‌ی حساب‌های ساخته‌شده از یک نشانی ───
        case 'ids_from_ip':
            $ip = trim((string)($_POST['ip'] ?? ''));
            if ($ip === '') throw new RuntimeException('نشانی نامعتبر.');
            $stmt = $pdo->prepare(
                'SELECT s.user_id FROM signup_log s JOIN users u ON u.id = s.user_id
                 WHERE s.ip = ? AND s.user_id IS NOT NULL'
            );
            $stmt->execute([$ip]);
            $ids = array_map('intval', array_column($stmt->fetchAll(), 'user_id'));
            json_response(['ok' => true, 'ids' => $ids, 'count' => count($ids)]);


        // ─── حذف چند گروه با هم ───
        case 'bulk_delete_rooms':
            $raw = $_POST['room_ids'] ?? '';
            $ids = array_values(array_unique(array_filter(
                array_map('intval', is_array($raw) ? $raw : explode(',', (string)$raw))
            )));
            if (!$ids) throw new RuntimeException('هیچ گروهی انتخاب نشده.');
            if (count($ids) > 200) throw new RuntimeException('حداکثر ۲۰۰ گروه در هر بار.');

            $done = 0;
            foreach ($ids as $rid) {
                // فایل‌های پیوست پیام‌های گروه را هم از دیسک پاک کن
                $tk = $pdo->prepare('SELECT file_token FROM messages WHERE room_id=? AND file_token IS NOT NULL');
                $tk->execute([$rid]);
                $tokens = array_column($tk->fetchAll(), 'file_token');

                $pdo->prepare('DELETE FROM messages WHERE room_id=?')->execute([$rid]);
                $pdo->prepare('DELETE FROM room_members WHERE room_id=?')->execute([$rid]);
                foreach (['room_roles', 'room_bans', 'room_typing'] as $tbl) {
                    try { $pdo->prepare("DELETE FROM `$tbl` WHERE room_id=?")->execute([$rid]); }
                    catch (\Throwable $e) {}
                }
                $pdo->prepare('DELETE FROM rooms WHERE id=?')->execute([$rid]);
                foreach ($tokens as $t) delete_upload($t);
                $done++;
            }
            admin_log('bulk_delete_rooms', 0, "{$done} گروه");
            json_response(['ok' => true, 'message' => "حذف شد: {$done} گروه"]);

        default:
            throw new RuntimeException('عملیات نامعتبر.');
    }
} catch (\Throwable $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()]);
}
