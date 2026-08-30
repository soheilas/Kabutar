<?php
require_once __DIR__ . '/config.php';
require_admin();

$csrf  = csrf_token();
$pdo   = db();
$myId  = current_user_id();

// سازنده — بررسی قبلی جعلی بود و برای هر مدیری درست از آب درمی‌آمد
$isSuperAdmin = current_user_is_owner();

// ── POST handler (form-based) ──
$error = ''; $success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf'] ?? '')) {
        $error = 'توکن امنیتی نامعتبر.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            switch ($action) {
                case 'room_create':
                    $name = trim($_POST['name'] ?? '');
                    $pass = trim($_POST['password'] ?? '');
                    if (!preg_match('/^[\p{L}\p{N} _-]{3,60}$/u', $name)) throw new RuntimeException('نام اتاق نامعتبر.');
                    $chkRoom = $pdo->prepare('SELECT id FROM rooms WHERE name=?');
                    $chkRoom->execute([$name]);
                    if ($chkRoom->fetchColumn()) throw new RuntimeException('اتاق با این نام قبلاً وجود دارد.');
                    $hash = $pass !== '' ? password_hash($pass, PASSWORD_DEFAULT) : null;
                    $pdo->prepare('INSERT INTO rooms (name,created_by,password_hash) VALUES (?,?,?)')->execute([$name, $myId, $hash]);
                    $success = 'اتاق ساخته شد.'; break;

                case 'room_delete':
                    $rid = (int)($_POST['room_id'] ?? 0);
                    if ($rid <= 0) throw new RuntimeException('اتاق نامعتبر.');
                    $pdo->prepare('DELETE FROM messages WHERE room_id=?')->execute([$rid]);
                    $pdo->prepare('DELETE FROM room_members WHERE room_id=?')->execute([$rid]);
                    try { $pdo->prepare('DELETE FROM room_roles WHERE room_id=?')->execute([$rid]); } catch(\Throwable $e){}
                    try { $pdo->prepare('DELETE FROM room_bans WHERE room_id=?')->execute([$rid]); } catch(\Throwable $e){}
                    $pdo->prepare('DELETE FROM rooms WHERE id=?')->execute([$rid]);
                    $success = 'اتاق حذف شد.'; break;

                case 'set_setting':
                    if (!$isSuperAdmin) throw new RuntimeException('فقط سازنده.');
                    $key = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($_POST['setting_key'] ?? '')));
                    $val = trim($_POST['setting_value'] ?? '');
                    if ($key === '') throw new RuntimeException('کلید نامعتبر.');
                    site_setting_set($key, $val);
                    $success = 'ذخیره شد.'; break;

                default:
                    $error = 'درخواست نامعتبر.';
            }
        } catch (\Throwable $e) { $error = $e->getMessage(); }
    }
}

// ── Data ──
$users = $pdo->query(
    "SELECT id, username, display_name, is_admin, COALESCE(admin_level,0) AS admin_level, is_vip, vip_label, last_active, banned_until, is_banned_permanently,
            CASE WHEN is_invisible=1 THEN 0 WHEN last_active>=(NOW()-INTERVAL 45 SECOND) THEN 1 ELSE 0 END AS is_online
     FROM users ORDER BY is_admin DESC, last_active DESC"
)->fetchAll();

$rooms = $pdo->query(
    "SELECT r.id, r.name, r.password_hash, r.slow_mode_seconds,
            (SELECT COUNT(*) FROM room_members rm WHERE rm.room_id=r.id) AS member_count,
            (SELECT COUNT(*) FROM messages m WHERE m.room_id=r.id AND m.deleted_at IS NULL) AS msg_count,
            u.username AS creator
     FROM rooms r LEFT JOIN users u ON u.id=r.created_by ORDER BY r.name"
)->fetchAll();

$stats = [
    'total_users'    => (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'online_users'   => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_invisible=0 AND last_active>=(NOW()-INTERVAL 45 SECOND)")->fetchColumn(),
    'total_rooms'    => (int)$pdo->query('SELECT COUNT(*) FROM rooms')->fetchColumn(),
    'messages_today' => (int)$pdo->query("SELECT COUNT(*) FROM messages WHERE DATE(created_at)=CURDATE() AND deleted_at IS NULL")->fetchColumn(),
    'banned_users'   => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_banned_permanently=1 OR banned_until > NOW()")->fetchColumn(),
    'new_today'      => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE DATE(created_at)=CURDATE()")->fetchColumn(),
];

// نمودار ۷ روز
$dayRows = $pdo->query("SELECT DATE(created_at) AS d, COUNT(*) AS c FROM messages WHERE created_at>=(CURDATE()-INTERVAL 6 DAY) AND deleted_at IS NULL GROUP BY DATE(created_at)")->fetchAll();
$dayMap  = [];
foreach ($dayRows as $r) $dayMap[$r['d']] = (int)$r['c'];
$daySeries = [];
for ($i=6;$i>=0;$i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $daySeries[] = ['d'=>$d,'c'=>$dayMap[$d]??0,'l'=>idate('d',$i===0?time():strtotime("-$i day"))];
}
$maxDay = max(1, ...array_column($daySeries,'c'));

// آخرین ثبت‌نام
$recentReg = $pdo->query("SELECT username, display_name, created_at FROM users ORDER BY created_at DESC LIMIT 10")->fetchAll();

// ثبت‌نام‌های اخیر همراه نشانی — برای دیدن ساخت انبوه حساب
$signups = [];
try {
    $signups = $pdo->query(
        "SELECT s.ip, s.username, s.created_at, s.user_id,
                (SELECT COUNT(*) FROM signup_log s2 WHERE s2.ip = s.ip) AS ip_total
         FROM signup_log s ORDER BY s.id DESC LIMIT 100"
    )->fetchAll();
} catch (\Throwable $e) {}

// نشانی‌هایی که بیش از یک حساب ساخته‌اند
$busyIps = [];
try {
    $busyIps = $pdo->query(
        "SELECT ip, COUNT(*) AS c, MAX(created_at) AS last_at
         FROM signup_log GROUP BY ip HAVING c > 1 ORDER BY c DESC, last_at DESC LIMIT 20"
    )->fetchAll();
} catch (\Throwable $e) {}

// دفتر کارهای مدیران
$adminLog = [];
try {
    $adminLog = $pdo->query(
        "SELECT admin_name, action, target_user_id, detail, ip, created_at,
                (SELECT username FROM users WHERE id = admin_log.target_user_id) AS target_name
         FROM admin_log ORDER BY id DESC LIMIT 100"
    )->fetchAll();
} catch (\Throwable $e) {}

$actionLabels = [
    'ban_temp' => 'مسدودی موقت', 'ban_permanent' => 'مسدودی دائم', 'unban' => 'رفع مسدودی',
    'change_password' => 'تغییر رمز', 'make_admin' => 'مدیر کردن', 'revoke_admin' => 'حذف مدیر',
    'vip_grant' => 'دادن نشان', 'vip_revoke' => 'حذف نشان', 'delete_user' => 'حذف کاربر',
    'delete_all_messages' => 'حذف پیام‌ها', 'kick_all_rooms' => 'اخراج از گروه‌ها',
    'set_setting' => 'تغییر تنظیمات', 'clear_call_logs' => 'پاک کردن لاگ تماس',
    'bulk_ban' => 'مسدودی گروهی', 'bulk_delete' => 'حذف گروهی',
];

// تاریخچه‌ی تماس — از call_log می‌خوانیم، نه call_signals.
// جدول سیگنال عمداً گذراست و هر چند ثانیه خالی می‌شود، برای همین
// لاگ تماس پیش از این همیشه خالی بود.
$callLogs = [];
try {
    $callLogs = $pdo->query(
        "SELECT c.id, c.caller_id, c.receiver_id, c.is_video, c.status,
                c.started_at, c.answered_at, c.ended_at, c.duration_seconds,
                u1.username AS caller_name, u2.username AS receiver_name
         FROM call_log c
         LEFT JOIN users u1 ON u1.id = c.caller_id
         LEFT JOIN users u2 ON u2.id = c.receiver_id
         ORDER BY c.id DESC LIMIT 100"
    )->fetchAll();
} catch (\Throwable $e) {}

$callStats = ['total' => 0, 'answered' => 0, 'talk_secs' => 0];
try {
    $row = $pdo->query(
        "SELECT COUNT(*) AS total,
                SUM(status IN ('answered','ended')) AS answered,
                COALESCE(SUM(duration_seconds), 0) AS talk_secs
         FROM call_log WHERE started_at > (NOW() - INTERVAL 30 DAY)"
    )->fetch();
    // ادغام، نه جایگزینی — تا اگر ردیف ناقص بود کلیدها سر جایشان بمانند
    if (is_array($row)) $callStats = array_merge($callStats, array_filter($row, 'is_scalar'));
} catch (\Throwable $e) {}

$callStatus = [
    'ringing'  => ['در حال زنگ', 'vip-b'],
    'answered' => ['در حال گفتگو', 'on-b'],
    'ended'    => ['پایان‌یافته', 'on-b'],
    'rejected' => ['رد شده', 'ban-b'],
    'missed'   => ['بی‌پاسخ', 'ban-b'],
    'busy'     => ['مشغول', 'ban-b'],
];

/** ثانیه را به شکل خوانا درمی‌آورد */
$fmtDuration = static function (?int $secs): string {
    // خروجی با رقم فارسی برمی‌گردد
    $secs = (int)$secs;
    if ($secs <= 0) return '—';
    if ($secs < 60)   return fa($secs) . ' ثانیه';
    $m = intdiv($secs, 60);
    $r = $secs % 60;
    if ($m < 60) return $r ? fa($m) . ' دقیقه و ' . fa($r) . ' ثانیه' : fa($m) . ' دقیقه';
    $h = intdiv($m, 60);
    $m = $m % 60;
    return $m ? fa($h) . ' ساعت و ' . fa($m) . ' دقیقه' : fa($h) . ' ساعت';
};

$csrf_token = csrf_token();
function adm_s(string $k, string $d=''): string { return site_setting($k,$d); }
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="<?= h($csrf_token) ?>">
<meta name="min-password" content="<?= (int)ADMIN_SET_MIN_PASSWORD ?>">
<title>پنل مدیریت</title>
<link rel="stylesheet" href="assets/fonts/fonts.css?v=<?= h(asset_version('assets/fonts/fonts.css')) ?>">
<link rel="stylesheet" href="assets/admin.css?v=<?= h(asset_version('assets/admin.css')) ?>">
<link rel="stylesheet" href="assets/admin-controls.css?v=<?= h(asset_version('assets/admin-controls.css')) ?>">
</head>
<body>

<div class="adm-layout">

  <!-- ── Sidebar ── -->
  <aside class="adm-sidebar">
    <div class="adm-logo">
      <svg width="28" height="28" viewBox="0 0 36 36"><circle cx="18" cy="18" r="18" fill="url(#lg)"/><path d="M9 13h18M9 18h12M9 23h15" stroke="#fff" stroke-width="2" stroke-linecap="round"/><defs><linearGradient id="lg" x1="0" y1="0" x2="36" y2="36" gradientUnits="userSpaceOnUse"><stop stop-color="#3aa4ff"/><stop offset="1" stop-color="#7c5cfc"/></linearGradient></defs></svg>
      <span>مدیریت</span>
    </div>

    <nav class="adm-nav">
      <a href="#s-dash"     class="adm-link active" data-sec="s-dash">    <span class="adm-link-icon">📊</span>داشبورد</a>
      <a href="#s-users"    class="adm-link" data-sec="s-users">   <span class="adm-link-icon">👥</span>کاربران<span class="adm-badge-count"><?= fa(count($users)) ?></span></a>
      <a href="#s-rooms"    class="adm-link" data-sec="s-rooms">   <span class="adm-link-icon">🏠</span>گروه‌ها<span class="adm-badge-count"><?= fa(count($rooms)) ?></span></a>
      <a href="#s-signups"  class="adm-link" data-sec="s-signups"><span class="adm-link-icon">🆕</span>ثبت‌نام‌ها<?php if ($busyIps): ?><span class="adm-badge-count warn"><?= fa(count($busyIps)) ?></span><?php endif; ?></a>
      <a href="#s-calls"    class="adm-link" data-sec="s-calls">   <span class="adm-link-icon">📞</span>لاگ تماس</a>
      <a href="#s-audit"    class="adm-link" data-sec="s-audit">   <span class="adm-link-icon">📜</span>دفتر مدیران</a>
      <a href="#s-settings" class="adm-link" data-sec="s-settings"><span class="adm-link-icon">⚙️</span>تنظیمات</a>
    </nav>

    <div class="adm-sidebar-footer">
      <?php if (adm_s('maintenance_mode')==='1'): ?>
        <div class="adm-maint-badge">🔧 حالت بروزرسانی</div>
      <?php endif; ?>
      <a href="chat.php" class="adm-footer-btn">💬 بازگشت به چت</a>
      <a href="logout.php" class="adm-footer-btn danger">خروج</a>
    </div>
  </aside>

  <!-- ── Main ── -->
  <main class="adm-main">

    <?php if ($error): ?>
      <div class="adm-alert error">⚠️ <?= h($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="adm-alert success">✅ <?= h($success) ?></div>
    <?php endif; ?>

    <!-- ════════ داشبورد ════════ -->
    <section id="s-dash" class="adm-sec active">
      <div class="adm-sec-title">📊 داشبورد</div>

      <!-- کارت‌های آمار -->
      <div class="stat-row">
        <div class="stat-card blue">
          <div class="stat-icon">👥</div>
          <div class="stat-val"><?= fa($stats['total_users']) ?></div>
          <div class="stat-lbl">کاربر کل</div>
        </div>
        <div class="stat-card green">
          <div class="stat-icon">🟢</div>
          <div class="stat-val"><?= fa($stats['online_users']) ?></div>
          <div class="stat-lbl">آنلاین الان</div>
        </div>
        <div class="stat-card purple">
          <div class="stat-icon">💬</div>
          <div class="stat-val"><?= fa($stats['messages_today']) ?></div>
          <div class="stat-lbl">پیام امروز</div>
        </div>
        <div class="stat-card teal">
          <div class="stat-icon">🏠</div>
          <div class="stat-val"><?= fa($stats['total_rooms']) ?></div>
          <div class="stat-lbl">گروه</div>
        </div>
        <div class="stat-card red">
          <div class="stat-icon">🚫</div>
          <div class="stat-val"><?= fa($stats['banned_users']) ?></div>
          <div class="stat-lbl">مسدود</div>
        </div>
        <div class="stat-card orange">
          <div class="stat-icon">🆕</div>
          <div class="stat-val"><?= fa($stats['new_today']) ?></div>
          <div class="stat-lbl">ثبت‌نام امروز</div>
        </div>
      </div>

      <!-- نمودار ۷ روز -->
      <div class="adm-card">
        <div class="adm-card-title">📈 پیام‌های ۷ روز اخیر</div>
        <div class="bar-chart">
          <?php foreach ($daySeries as $day): ?>
          <div class="bar-col">
            <div class="bar-wrap">
              <div class="bar-fill" style="height:<?= $day['c']>0 ? max(6,round($day['c']/$maxDay*100)) : 0 ?>%">
                <?php if($day['c']>0): ?><span class="bar-val"><?= fa($day['c']) ?></span><?php endif; ?>
              </div>
            </div>
            <div class="bar-lbl"><?= fa($day['l']) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- آخرین ثبت‌نام‌ها -->
      <div class="adm-card">
        <div class="adm-card-title">🆕 آخرین ثبت‌نام‌ها</div>
        <table class="adm-tbl">
          <thead><tr><th>#</th><th>نام کاربری</th><th>نام</th><th>زمان</th></tr></thead>
          <tbody>
          <?php foreach ($recentReg as $i=>$u): ?>
            <tr>
              <td class="adm-muted"><?= fa($i+1) ?></td>
              <td><code class="adm-code">@<?= h($u['username']) ?></code></td>
              <td><?= h($u['display_name'] ?? '—') ?></td>
              <td class="adm-muted"><?= fa(date('Y/m/d H:i', strtotime((string)$u['created_at']))) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- ════════ کاربران ════════ -->
    <section id="s-users" class="adm-sec">
      <div class="adm-sec-title">👥 مدیریت کاربران</div>

      <div class="adm-toolbar">
        <input type="search" id="user-search" class="adm-search" placeholder="🔍 جستجو...">
        <div class="adm-filter-tabs">
          <button class="adm-filter active" data-filter="all">همه (<?= fa(count($users)) ?>)</button>
          <button class="adm-filter" data-filter="online">آنلاین</button>
          <button class="adm-filter" data-filter="admin">ادمین</button>
          <button class="adm-filter" data-filter="vip">VIP</button>
          <button class="adm-filter" data-filter="banned">مسدود</button>
        </div>
      </div>

      <!-- نوار انتخاب گروهی -->
      <div class="adm-bulkbar" id="bulk-bar" hidden>
        <label class="bulk-all"><input type="checkbox" id="bulk-all"> انتخاب همه‌ی نتایج</label>
        <span class="bulk-count"><b id="bulk-n">0</b> کاربر انتخاب شده</span>
        <div class="bulk-actions">
          <button class="adm-btn-sm warn"   onclick="bulkRun('bulk_ban')">🚫 مسدودی گروهی</button>
          <button class="adm-btn-sm danger" onclick="bulkRun('bulk_delete')">❌ حذف گروهی</button>
          <button class="adm-btn-sm"        onclick="bulkClear()">لغو انتخاب</button>
        </div>
      </div>

      <!-- pagination info -->
      <div class="adm-page-info" id="page-info"></div>

      <div class="user-grid" id="user-grid">
        <?php foreach ($users as $u):
          $isBanned = !empty($u['is_banned_permanently']) || (!empty($u['banned_until']) && strtotime($u['banned_until']) > time());
          $classes = 'user-card';
          if ($isBanned) $classes .= ' banned';
          $uName = h($u['display_name'] ?: $u['username']);
        ?>
        <div class="<?= $classes ?>"
             data-username="<?= h(strtolower($u['username'])) ?>"
             data-name="<?= h(strtolower($u['display_name'] ?? '')) ?>"
             data-filter="<?= ($u['is_online']?'online ':'').($u['is_admin']?'admin ':'').($u['is_vip']?'vip ':'').($isBanned?'banned ':'').'all' ?>">

          <div class="uc-head">
            <input type="checkbox" class="uc-check" data-uid="<?= $u['id'] ?>"
                   <?= ($u['id'] === $myId) ? 'disabled title="حساب خودتان"' : '' ?>>
            <div class="uc-av <?= $u['is_online'] ? 'online' : '' ?>">
              <?= h(mb_strtoupper(mb_substr($u['display_name'] ?: $u['username'], 0, 1))) ?>
            </div>
            <div class="uc-info">
              <div class="uc-name <?= $u['is_admin'] ? 'is-admin' : ($u['is_vip'] ? 'is-vip' : '') ?>">
                <?= $uName ?>
              </div>
              <div class="uc-sub">@<?= h($u['username']) ?> · #<?= fa($u['id']) ?></div>
              <div class="uc-badges">
                <?php $lvl = (int)($u['admin_level'] ?? 0); if ($lvl < 1 && !empty($u['is_admin'])) $lvl = 1; ?>
                <?php if ($lvl >= 2): ?><span class="ub owner-b">👑 سازنده</span>
                <?php elseif ($lvl === 1): ?><span class="ub admin-b">مدیر</span><?php endif; ?>
                <?php if ($u['is_vip'] && !$u['is_admin']): ?><span class="ub vip-b"><?= h($u['vip_label'] ?: 'VIP') ?></span><?php endif; ?>
                <?php if ($isBanned): ?><span class="ub ban-b">مسدود</span><?php endif; ?>
                <?php if ($u['is_online']): ?><span class="ub on-b">● آنلاین</span><?php endif; ?>
              </div>
            </div>
            <div class="uc-last"><?= $u['last_active'] ? fa(date('H:i', strtotime($u['last_active']))) : '—' ?></div>
          </div>

          <div class="uc-actions">
            <?php if (!$isBanned): ?>
              <button class="adm-btn-sm warn" onclick="adminAct('ban_user',<?= $u['id'] ?>,<?= h(json_encode($u['username'])) ?>)">⏱ بن موقت</button>
              <button class="adm-btn-sm danger" onclick="adminAct('ban_permanent',<?= $u['id'] ?>,<?= h(json_encode($u['username'])) ?>)">🚫 بن دائم</button>
            <?php else: ?>
              <button class="adm-btn-sm success" onclick="doAdminAction('unban_user',<?= $u['id'] ?>,{},'رفع مسدودی')">✅ رفع مسدودی</button>
            <?php endif; ?>
            <button class="adm-btn-sm" onclick="adminAct('change_password',<?= $u['id'] ?>,<?= h(json_encode($u['username'])) ?>)">🔑 رمز</button>
            <button class="adm-btn-sm" onclick="adminAct('vip',<?= $u['id'] ?>,<?= h(json_encode($u['username'])) ?>,<?= $u['is_vip']?1:0 ?>)">⭐ VIP</button>
            <?php if ($isSuperAdmin && $u['id'] !== $myId): ?>
              <button class="adm-btn-sm <?= $u['is_admin']?'warn':'' ?>" onclick="doAdminAction('make_admin',<?= $u['id'] ?>,{value:'<?= $u['is_admin']?0:1 ?>'},'<?= $u['is_admin']?'حذف ادمین':'ادمین کردن' ?>')">
                <?= $u['is_admin'] ? '🔻 حذف ادمین' : '👑 ادمین' ?>
              </button>
            <?php endif; ?>
            <button class="adm-btn-sm danger" onclick="adminAct('delete_messages',<?= $u['id'] ?>,<?= h(json_encode($u['username'])) ?>)">🗑 پیام‌ها</button>
            <button class="adm-btn-sm danger" onclick="adminAct('kick_all',<?= $u['id'] ?>,<?= h(json_encode($u['username'])) ?>)">🚪 اخراج گروه</button>
            <?php if ($u['id'] !== $myId): ?>
              <button class="adm-btn-sm danger" onclick="adminAct('delete_user',<?= $u['id'] ?>,<?= h(json_encode($u['username'])) ?>)">❌ حذف کامل</button>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="adm-pagination" id="adm-pagination"></div>
    </section>

    <!-- ════════ گروه‌ها ════════ -->
    <section id="s-rooms" class="adm-sec">
      <div class="adm-sec-title">🏠 مدیریت گروه‌ها</div>

      <!-- ساخت گروه جدید -->
      <div class="adm-card" style="margin-bottom:16px">
        <div class="adm-card-title">➕ ساخت گروه جدید</div>
        <form method="post" class="adm-inline">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="room_create">
          <input type="text" name="name" class="adm-input" placeholder="نام گروه" required>
          <input type="text" name="password" class="adm-input" placeholder="رمز (اختیاری)">
          <button type="submit" class="adm-btn-primary">ساخت</button>
        </form>
      </div>

      <div class="adm-bulkbar" id="room-bulk-bar" hidden>
        <span class="bulk-count"><b id="room-bulk-n">0</b> گروه انتخاب شده</span>
        <div class="bulk-actions">
          <button class="adm-btn-sm danger" onclick="bulkDeleteRooms()">❌ حذف گروه‌های انتخاب‌شده</button>
          <button class="adm-btn-sm" onclick="roomBulkClear()">لغو انتخاب</button>
        </div>
      </div>

      <div class="room-list">
        <?php foreach ($rooms as $r): ?>
        <div class="room-row">
          <input type="checkbox" class="room-check" data-rid="<?= (int)$r['id'] ?>">
          <div class="room-icon">🏠</div>
          <div class="room-info">
            <div class="room-name"><?= h($r['name']) ?></div>
            <div class="room-meta">
              <span>👥 <?= fa($r['member_count']) ?> عضو</span>
              <span>💬 <?= fa($r['msg_count']) ?> پیام</span>
              <?php if ($r['password_hash']): ?><span>🔒 رمزدار</span><?php endif; ?>
              <?php if ($r['creator']): ?><span>👤 <?= h($r['creator']) ?></span><?php endif; ?>
            </div>
          </div>
          <div class="room-actions">
            <form method="post" style="display:inline" onsubmit="return confirm('حذف گروه «<?= h(addslashes($r['name'])) ?>» و همه پیام‌هایش؟')">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="room_delete">
              <input type="hidden" name="room_id" value="<?= $r['id'] ?>">
              <button type="submit" class="adm-btn-sm danger">🗑 حذف</button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if (!$rooms): ?>
          <div class="adm-empty">هیچ گروهی وجود ندارد.</div>
        <?php endif; ?>
      </div>
    </section>

    <!-- ════════ لاگ تماس ════════ -->
    <!-- ════════ ثبت‌نام‌ها ════════ -->
    <section id="s-signups" class="adm-sec">
      <div class="adm-sec-title">🆕 ثبت‌نام‌های اخیر</div>

      <?php if ($busyIps): ?>
        <div class="adm-card" style="margin-bottom:16px">
          <div class="adm-card-title">⚠️ نشانی‌هایی که بیش از یک حساب ساخته‌اند</div>
          <table class="adm-table">
            <thead><tr><th>نشانی</th><th>تعداد حساب</th><th>آخرین ساخت</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($busyIps as $b): ?>
              <tr>
                <td><code><?= h((string)$b['ip']) ?></code></td>
                <td><span class="ub <?= (int)$b['c'] >= 5 ? 'ban-b' : 'vip-b' ?>"><?= fa((int)$b['c']) ?></span></td>
                <td><?= fa(date('Y/m/d H:i', strtotime((string)$b['last_at']))) ?></td>
                <td><button class="adm-btn-sm" onclick="selectFromIp(<?= h(json_encode($b['ip'])) ?>)">انتخاب همه در فهرست کاربران</button></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <p class="adm-hint">با «انتخاب همه» به بخش کاربران می‌روی و همه‌شان تیک می‌خورند؛ بعد یکجا مسدود یا حذفشان کن.</p>
        </div>
      <?php endif; ?>

      <div class="adm-card">
        <div class="adm-card-title">۱۰۰ ثبت‌نام آخر</div>
        <?php if (!$signups): ?>
          <p class="adm-hint">هنوز ثبت‌نامی بعد از فعال شدن این دفتر انجام نشده.</p>
        <?php else: ?>
          <table class="adm-table">
            <thead><tr><th>نام کاربری</th><th>نشانی</th><th>از این نشانی</th><th>زمان</th></tr></thead>
            <tbody>
            <?php foreach ($signups as $sg): ?>
              <tr>
                <td><?= h((string)$sg['username']) ?><?= $sg['user_id'] ? '' : ' <span class="adm-hint">(حذف‌شده)</span>' ?></td>
                <td><code><?= h((string)$sg['ip']) ?></code></td>
                <td><?= fa((int)$sg['ip_total']) ?></td>
                <td><?= fa(date('Y/m/d H:i', strtotime((string)$sg['created_at']))) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </section>

    <!-- ════════ دفتر مدیران ════════ -->
    <section id="s-audit" class="adm-sec">
      <div class="adm-sec-title">📜 دفتر کارهای مدیران</div>
      <div class="adm-card">
        <?php if (!$adminLog): ?>
          <p class="adm-hint">هنوز کاری ثبت نشده. از این پس هر کار مدیریتی این‌جا می‌ماند.</p>
        <?php else: ?>
          <table class="adm-table">
            <thead><tr><th>زمان</th><th>مدیر</th><th>کار</th><th>روی چه کسی</th><th>توضیح</th><th>نشانی</th></tr></thead>
            <tbody>
            <?php foreach ($adminLog as $al): ?>
              <tr>
                <td><?= fa(date('m/d H:i', strtotime((string)$al['created_at']))) ?></td>
                <td><b><?= h((string)($al['admin_name'] ?: '—')) ?></b></td>
                <td><?= h($actionLabels[$al['action']] ?? (string)$al['action']) ?></td>
                <td><?= h((string)($al['target_name'] ?: ($al['target_user_id'] ? '#'.$al['target_user_id'] : '—'))) ?></td>
                <td class="adm-hint"><?= fa(h((string)($al['detail'] ?? ''))) ?></td>
                <td><code><?= h((string)($al['ip'] ?? '')) ?></code></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </section>

    <section id="s-calls" class="adm-sec">
      <div class="adm-sec-title" style="display:flex;align-items:center;justify-content:space-between">
        <span>📞 لاگ تماس‌ها</span>
        <button class="adm-btn-sm danger" onclick="clearCallLogs()">🗑 پاک کردن لاگ</button>
      </div>

      <div class="stat-row" style="margin-bottom:16px">
        <div class="stat-card blue">
          <div class="stat-icon">📞</div>
          <div class="stat-val"><?= fa((int)$callStats['total']) ?></div>
          <div class="stat-lbl">تماس در ۳۰ روز</div>
        </div>
        <div class="stat-card green">
          <div class="stat-icon">✅</div>
          <div class="stat-val"><?= fa((int)$callStats['answered']) ?></div>
          <div class="stat-lbl">پاسخ داده‌شده</div>
        </div>
        <div class="stat-card purple">
          <div class="stat-icon">⏱</div>
          <div class="stat-val"><?= fa((int)round(((int)$callStats['talk_secs']) / 60)) ?></div>
          <div class="stat-lbl">دقیقه گفتگو</div>
        </div>
      </div>

      <?php if ($callLogs): ?>
      <div class="adm-card">
        <table class="adm-table">
          <thead>
            <tr><th>تماس‌گیرنده</th><th>دریافت‌کننده</th><th>وضعیت</th><th>مدت</th><th>زمان</th></tr>
          </thead>
          <tbody>
          <?php foreach ($callLogs as $cl):
            [$label, $cls] = $callStatus[$cl['status']] ?? [(string)$cl['status'], ''];
          ?>
            <tr>
              <td><?= h((string)($cl['caller_name'] ?? '—')) ?></td>
              <td><?= h((string)($cl['receiver_name'] ?? '—')) ?></td>
              <td>
                <span class="ub <?= $cls ?>"><?= h($label) ?></span>
                <?php if (!empty($cl['is_video'])): ?><span class="ub vip-b">تصویری</span><?php endif; ?>
              </td>
              <td><?= h($fmtDuration($cl['duration_seconds'] === null ? null : (int)$cl['duration_seconds'])) ?></td>
              <td class="adm-hint"><?= fa(date('Y/m/d H:i', strtotime((string)$cl['started_at']))) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
        <div class="adm-empty">
          هنوز تماسی ثبت نشده.
          <div class="adm-hint" style="margin-top:6px">
            تاریخچه از این پس ثبت می‌شود. پیش از این، جدول سیگنال هر چند ثانیه
            خالی می‌شد و به همین دلیل این بخش همیشه خالی بود.
          </div>
        </div>
      <?php endif; ?>
    </section>

    <!-- ════════ تنظیمات ════════ -->
    <section id="s-settings" class="adm-sec">
      <div class="adm-sec-title">⚙️ تنظیمات سایت</div>

      <div class="adm-card" style="margin-bottom:16px">
        <div class="adm-card-title">📄 تنظیمات فایل — فقط خواندنی</div>
        <p class="adm-hint" style="margin-top:0;margin-bottom:10px">
          این‌ها از فایل تنظیمات خوانده می‌شوند و از این‌جا قابل تغییر نیستند.
          برای عوض کردنشان همان یک فایل را ویرایش کن. کلیدها و رمزها عمداً نشان داده نمی‌شوند.
        </p>
        <table class="adm-table">
          <tbody>
            <tr><td>نام سایت</td>            <td><?= h(SITE_NAME) ?></td></tr>
            <tr><td>نشانی سایت</td>          <td><code><?= h(SITE_URL ?: '—') ?></code></td></tr>
            <tr><td>گروه پیش‌فرض</td>        <td><?= h(DEFAULT_ROOM_NAME) ?></td></tr>
            <tr><td>سقف تلاش ورود</td>       <td><?= fa(RATE_LIMIT_MAX_ATTEMPTS) ?> بار در <?= fa((int)round(RATE_LIMIT_WINDOW_SEC / 60)) ?> دقیقه</td></tr>
            <tr><td>مدت قفل شدن</td>         <td><?= fa((int)round(RATE_LIMIT_LOCKOUT_SEC / 60)) ?> دقیقه</td></tr>
            <tr><td>سقف ساخت حساب</td>       <td><?= REGISTER_MAX_PER_IP_DAY > 0 ? fa(REGISTER_MAX_PER_IP_DAY) . ' حساب از هر نشانی در شبانه‌روز' : 'بدون سقف' ?></td></tr>
            <tr><td>حداقل طول رمز</td>       <td><?= fa(REGISTER_MIN_PASSWORD) ?> کاراکتر (ثبت‌نام) · <?= fa(ADMIN_SET_MIN_PASSWORD) ?> کاراکتر (توسط مدیر)</td></tr>
            <tr><td>حجم فایل</td>            <td><?= fa((int)(MAX_FILE_SIZE / 1048576)) ?> مگابایت · <?= fa((int)(MAX_FILE_SIZE_VIP / 1048576)) ?> مگابایت برای VIP</td></tr>
            <tr><td>طول پیام</td>            <td><?= fa(MAX_MESSAGE_LEN) ?> کاراکتر</td></tr>
            <tr><td>ماندگاری ورود</td>       <td><?= fa((int)(SESSION_LIFETIME / 86400)) ?> روز</td></tr>
            <tr><td>رمزنگاری پیام خصوصی</td> <td><?= MSG_ENCRYPT_KEY !== '' ? '✅ روشن' : '⚠️ خاموش' ?></td></tr>
            <tr><td>توکن امنیتی درخواست</td> <td><?= CSRF_PROTECTION_ENABLED ? '✅ روشن' : '⚠️ خاموش' ?></td></tr>
            <tr><td>راه واسط</td>            <td><?= PROXY_SECRET !== '' ? 'باز (کلید تنظیم شده)' : '✅ بسته' ?></td></tr>
            <tr><td>سرآیند نشانی کاربر</td>  <td><?= CLIENT_IP_HEADER !== '' ? h(CLIENT_IP_HEADER) : 'نشانی مستقیم' ?></td></tr>
            <tr><td>تماس</td>                <td>
              <?php if (!FEATURE_CALLS): ?>خاموش
              <?php else:
                $turn = (string)cfg('calls.turn_url', '');
                $sec  = (string)cfg('calls.turn_secret', '');
              ?>
                روشن —
                <?php if ($turn === ''): ?>
                  <span style="color:#ff9b2e">فقط استان، بدون سرور ترن</span>
                <?php else: ?>
                  <code><?= h($turn) ?></code>
                  <?= $sec !== '' ? '· رمز موقت' : '· رمز ثابت' ?>
                <?php endif; ?>
              <?php endif; ?>
            </td></tr>
          </tbody>
        </table>
      </div>

      <div class="settings-grid">

        <!-- ثبت‌نام -->
        <div class="setting-card">
          <div class="setting-info">
            <div class="setting-title">🔐 ثبت‌نام جدید</div>
            <div class="setting-desc">اجازه ثبت‌نام کاربران جدید</div>
          </div>
          <label class="toggle-sw">
            <input type="checkbox" <?= adm_s('allow_registration','1')==='1' ? 'checked' : '' ?>
                   onchange="saveSetting('allow_registration', this.checked?'1':'0')">
            <span class="toggle-track"><span class="toggle-thumb"></span></span>
          </label>
        </div>

        <!-- فقط با دعوت -->
        <div class="setting-card">
          <div class="setting-info">
            <div class="setting-title">🔗 فقط با لینک دعوت</div>
            <div class="setting-desc">ثبت‌نام بدون لینک دعوت غیرممکن</div>
          </div>
          <label class="toggle-sw">
            <input type="checkbox" <?= adm_s('invite_only','0')==='1' ? 'checked' : '' ?>
                   onchange="saveSetting('invite_only', this.checked?'1':'0')">
            <span class="toggle-track"><span class="toggle-thumb"></span></span>
          </label>
        </div>

        <!-- حالت بروزرسانی -->
        <div class="setting-card <?= adm_s('maintenance_mode')==='1' ? 'setting-active' : '' ?>">
          <div class="setting-info">
            <div class="setting-title">🔧 حالت بروزرسانی</div>
            <div class="setting-desc">همه کاربران اخراج و صفحه بروزرسانی نشون داده میشه</div>
          </div>
          <label class="toggle-sw">
            <input type="checkbox" <?= adm_s('maintenance_mode','0')==='1' ? 'checked' : '' ?>
                   onchange="saveSetting('maintenance_mode', this.checked?'1':'0', true)">
            <span class="toggle-track"><span class="toggle-thumb"></span></span>
          </label>
        </div>

        <!-- ساخت گروه -->
        <div class="setting-card">
          <div class="setting-info">
            <div class="setting-title">🏠 ساخت گروه توسط کاربران</div>
            <div class="setting-desc">اگه غیرفعال باشه فقط ادمین می‌تونه گروه بسازه</div>
          </div>
          <label class="toggle-sw">
            <input type="checkbox" <?= adm_s('allow_room_creation','1')==='1' ? 'checked' : '' ?>
                   onchange="saveSetting('allow_room_creation', this.checked?'1':'0')">
            <span class="toggle-track"><span class="toggle-thumb"></span></span>
          </label>
        </div>

        <!-- پیام بروزرسانی -->
        <div class="setting-card setting-full">
          <div class="setting-info">
            <div class="setting-title">📝 متن صفحه بروزرسانی</div>
          </div>
          <div style="display:flex;gap:8px;flex:1">
            <input type="text" id="maint-msg" class="adm-input" style="flex:1"
                   value="<?= h(adm_s('maintenance_message','سیستم در حال بروزرسانی است.')) ?>">
            <button class="adm-btn-primary" onclick="saveSetting('maintenance_message', document.getElementById('maint-msg').value)">ذخیره</button>
          </div>
        </div>

      </div>
    </section>

  </main>
</div>

<!-- ── Modal ── -->
<div id="adm-modal" class="adm-modal-overlay" style="display:none">
  <div class="adm-modal">
    <div class="adm-modal-title" id="modal-title"></div>
    <div class="adm-modal-body" id="modal-body"></div>
    <div class="adm-modal-footer">
      <button class="adm-btn-primary" id="modal-ok">تأیید</button>
      <button class="adm-btn-sm" onclick="closeModal()">انصراف</button>
    </div>
  </div>
</div>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;

// ── Navigation ──
document.querySelectorAll('.adm-link').forEach(a => {
  a.addEventListener('click', e => {
    e.preventDefault();
    document.querySelectorAll('.adm-link').forEach(x => x.classList.remove('active'));
    document.querySelectorAll('.adm-sec').forEach(x => x.classList.remove('active'));
    a.classList.add('active');
    const sec = a.dataset.sec;
    document.getElementById(sec)?.classList.add('active');
  });
});

// ── User search + filter ──
const CARDS_PER_PAGE = 20;
let currentPage = 1;
let activeFilter = 'all';
let searchTerm   = '';

function getVisibleCards() {
  return Array.from(document.querySelectorAll('.user-card')).filter(c => {
    const filters = (c.dataset.filter || '').split(' ');
    const matchFilter = activeFilter === 'all' || filters.includes(activeFilter);
    const matchSearch = !searchTerm ||
      (c.dataset.username||'').includes(searchTerm) ||
      (c.dataset.name||'').includes(searchTerm);
    return matchFilter && matchSearch;
  });
}

function renderPage() {
  const all = getVisibleCards();
  const total = all.length;
  const pages = Math.ceil(total / CARDS_PER_PAGE);
  if (currentPage > pages) currentPage = Math.max(1, pages);
  all.forEach((c, i) => {
    const inPage = i >= (currentPage-1)*CARDS_PER_PAGE && i < currentPage*CARDS_PER_PAGE;
    c.style.display = inPage ? '' : 'none';
  });
  document.getElementById('page-info').textContent =
    total > 0 ? `نمایش ${Math.min((currentPage-1)*CARDS_PER_PAGE+1,total)}–${Math.min(currentPage*CARDS_PER_PAGE,total)} از ${total} کاربر` : 'نتیجه‌ای یافت نشد';
  // pagination
  const pg = document.getElementById('adm-pagination');
  pg.innerHTML = '';
  if (pages <= 1) return;
  for (let i=1; i<=pages; i++) {
    const b = document.createElement('button');
    b.className = 'pg-btn' + (i===currentPage ? ' active' : '');
    b.textContent = i;
    b.addEventListener('click', () => { currentPage = i; renderPage(); });
    pg.appendChild(b);
  }
}

document.getElementById('user-search').addEventListener('input', function() {
  searchTerm = this.value.trim().toLowerCase();
  currentPage = 1; renderPage();
});

document.querySelectorAll('.adm-filter').forEach(b => {
  b.addEventListener('click', () => {
    document.querySelectorAll('.adm-filter').forEach(x => x.classList.remove('active'));
    b.classList.add('active');
    activeFilter = b.dataset.filter;
    currentPage = 1; renderPage();
  });
});

renderPage();

// ── API call ──
async function doAdminAction(action, uid, extra={}, confirmMsg='') {
  if (confirmMsg && !confirm(confirmMsg + '؟')) return false;
  const fd = new FormData();
  fd.append('csrf', CSRF);
  fd.append('action', action);
  if (uid) fd.append('user_id', uid);
  Object.entries(extra).forEach(([k,v]) => fd.append(k, v));
  try {
    const r = await fetch('api/admin_action.php', {method:'POST',body:fd,credentials:'same-origin'});
    const d = await r.json();
    if (d.ok) { showToast(d.message || 'انجام شد', 'success'); setTimeout(()=>location.reload(),800); }
    else showToast(d.error || 'خطا', 'error');
    return d.ok;
  } catch(e) { showToast('خطای شبکه', 'error'); return false; }
}

// ── Toast ──
function showToast(msg, type='success') {
  const t = document.createElement('div');
  t.className = 'adm-toast ' + type;
  t.textContent = msg;
  document.body.appendChild(t);
  setTimeout(()=>t.classList.add('show'),10);
  setTimeout(()=>{ t.classList.remove('show'); setTimeout(()=>t.remove(),300); }, 3000);
}

// ── Modal ──
let modalCallback = null;
function openModal(title, bodyHtml, okCallback) {
  document.getElementById('modal-title').textContent = title;
  document.getElementById('modal-body').innerHTML = bodyHtml;
  modalCallback = okCallback;
  document.getElementById('adm-modal').style.display = 'flex';
}
function closeModal() { document.getElementById('adm-modal').style.display = 'none'; }
document.getElementById('modal-ok').addEventListener('click', () => { if(modalCallback) modalCallback(); closeModal(); });
document.getElementById('adm-modal').addEventListener('click', e => { if(e.target===e.currentTarget) closeModal(); });

// ── Admin Actions ──
// حداقل طول رمز از تنظیمات سرور می‌آید، نه نوشته‌شده این‌جا
const MIN_PW = parseInt(document.querySelector('meta[name="min-password"]')?.content || '8', 10);
function faDigits(v) {
  return String(v).replace(/[0-9]/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
}

function adminAct(action, uid, uname, extra) {
  if (action === 'ban_user') {
    openModal(`بن موقت: @${uname}`,
      `<label>مدت (دقیقه):<br><input id="m-val" class="adm-input" type="number" value="60" min="1" style="margin-top:6px"></label>
       <label style="margin-top:8px;display:block">دلیل (اختیاری):<br><input id="m-reason" class="adm-input" type="text" style="margin-top:6px"></label>`,
      () => doAdminAction('ban_user', uid, {ban_minutes: document.getElementById('m-val').value, reason: document.getElementById('m-reason').value})
    );
  } else if (action === 'ban_permanent') {
    openModal(`بن دائم: @${uname}`,
      `<div style="color:#ff7070;margin-bottom:12px">⚠️ این کاربر به صورت دائم مسدود می‌شود.</div>
       <label>دلیل (اختیاری):<br><input id="m-reason" class="adm-input" type="text" style="margin-top:6px"></label>`,
      () => doAdminAction('ban_permanent', uid, {reason: document.getElementById('m-reason').value})
    );
  } else if (action === 'change_password') {
    openModal(`تغییر رمز: @${uname}`,
      `<input id="m-val" class="adm-input" type="password" minlength="${MIN_PW}"
              placeholder="رمز جدید (حداقل ${faDigits(MIN_PW)} کاراکتر)">`,
      () => {
        const v = document.getElementById('m-val').value;
        if (v.length < MIN_PW) { alert('رمز باید حداقل ' + faDigits(MIN_PW) + ' کاراکتر باشد.'); return false; }
        return doAdminAction('change_password', uid, {new_password: v});
      }
    );
  } else if (action === 'vip') {
    const isVip = extra === 1;
    openModal(isVip ? `حذف VIP: @${uname}` : `VIP کردن: @${uname}`,
      isVip ? '<div>وضعیت VIP حذف شود؟</div>' :
      `<input id="m-label" class="adm-input" type="text" placeholder="عنوان VIP (مثلاً [gold]طلایی)" style="margin-bottom:8px">`,
      () => doAdminAction('vip', uid, isVip ? {value:'0'} : {value:'1', vip_label: document.getElementById('m-label')?.value||''})
    );
  } else if (action === 'delete_messages') {
    openModal(`حذف پیام‌ها: @${uname}`,
      `<div style="color:#ff7070">همه پیام‌های @${uname} از دیتابیس حذف می‌شود.</div>`,
      () => doAdminAction('delete_all_messages', uid, {})
    );
  } else if (action === 'kick_all') {
    openModal(`اخراج از گروه‌ها: @${uname}`,
      `<div>@${uname} از همه گروه‌ها اخراج و پیام‌هایش در گروه‌ها حذف می‌شود.</div>`,
      () => doAdminAction('kick_all_rooms', uid, {})
    );
  } else if (action === 'delete_user') {
    openModal(`حذف کامل: @${uname}`,
      `<div style="color:#ff7070">⚠️ کاربر @${uname} و تمام داده‌هایش برای همیشه حذف می‌شود.</div>`,
      () => doAdminAction('delete_user', uid, {})
    );
  }
}

// ── Save Setting ──
async function saveSetting(key, value, reload=false) {
  const fd = new FormData();
  fd.append('csrf', CSRF);
  fd.append('action', 'set_setting');
  fd.append('setting_key', key);
  fd.append('setting_value', value);
  try {
    const r = await fetch('api/admin_action.php', {method:'POST',body:fd,credentials:'same-origin'});
    const d = await r.json();
    if (d.ok) { showToast('ذخیره شد', 'success'); if(reload) setTimeout(()=>location.reload(),800); }
    else showToast(d.error||'خطا', 'error');
  } catch(e) { showToast('خطای شبکه', 'error'); }
}

// ── Clear Call Logs ──
async function clearCallLogs() {
  if (!confirm('همه لاگ‌های تماس پاک شوند؟')) return;
  await doAdminAction('clear_call_logs', null, {});
}

// ═══════════════════════════════════════════════════════════════
//  انتخاب گروهی کاربران
// ═══════════════════════════════════════════════════════════════
const bulkSel = new Set();

function bulkRefresh() {
  const bar = document.getElementById('bulk-bar');
  const n   = document.getElementById('bulk-n');
  if (n) n.textContent = faDigits(bulkSel.size);
  if (bar) bar.hidden = bulkSel.size === 0;
  document.querySelectorAll('.uc-check').forEach(c => {
    const on = bulkSel.has(parseInt(c.dataset.uid, 10));
    c.checked = on;
    c.closest('.user-card')?.classList.toggle('selected', on);
  });
}

function bulkClear() { bulkSel.clear(); bulkRefresh(); }

function bulkToggle(uid, on) {
  on ? bulkSel.add(uid) : bulkSel.delete(uid);
  bulkRefresh();
}

/** همه‌ی کارت‌هایی که با جستجو/فیلتر فعلی دیده می‌شوند */
function visibleUserIds() {
  return [...document.querySelectorAll('.user-card')]
    .filter(c => c.style.display !== 'none' && !c.querySelector('.uc-check')?.disabled)
    .map(c => parseInt(c.querySelector('.uc-check')?.dataset.uid, 10))
    .filter(Number.isFinite);
}

document.addEventListener('change', e => {
  if (e.target.classList?.contains('uc-check')) {
    bulkToggle(parseInt(e.target.dataset.uid, 10), e.target.checked);
  }
  if (e.target.id === 'bulk-all') {
    if (e.target.checked) visibleUserIds().forEach(id => bulkSel.add(id));
    else bulkClear();
    bulkRefresh();
  }
});

async function bulkRun(action) {
  if (!bulkSel.size) return;
  const ids   = [...bulkSel];
  const verb  = action === 'bulk_ban' ? 'مسدود' : 'برای همیشه حذف';
  const warn  = action === 'bulk_delete'
    ? '\n\nاین کار برگشت‌پذیر نیست — پیام‌ها و فایل‌هایشان هم پاک می‌شود.' : '';
  if (!confirm(`${ids.length} کاربر ${verb} شوند؟${warn}`)) return;

  const fd = new FormData();
  fd.append('action', action);
  fd.append('user_ids', ids.join(','));
  fd.append('csrf', document.querySelector('meta[name="csrf-token"]').content);

  try {
    const r = await fetch('api/admin_action.php', { method: 'POST', body: fd });
    const j = await r.json();
    if (j.ok) { alert(j.message); location.reload(); }
    else alert(j.error || 'خطا');
  } catch (err) { alert('خطای شبکه'); }
}

/** از بخش ثبت‌نام‌ها: همه‌ی حساب‌های یک نشانی را در فهرست کاربران تیک می‌زند */
async function selectFromIp(ip) {
  const fd = new FormData();
  fd.append('action', 'ids_from_ip');
  fd.append('ip', ip);
  fd.append('csrf', document.querySelector('meta[name="csrf-token"]').content);
  try {
    const r = await fetch('api/admin_action.php', { method: 'POST', body: fd });
    const j = await r.json();
    if (!j.ok) { alert(j.error || 'خطا'); return; }
    if (!j.ids.length) { alert('حساب فعالی از این نشانی نمانده.'); return; }

    bulkSel.clear();
    j.ids.forEach(id => bulkSel.add(id));
    document.querySelector('.adm-link[data-sec="s-users"]')?.click();
    bulkRefresh();
    document.getElementById('bulk-bar')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
  } catch (err) { alert('خطای شبکه'); }
}


// ═══ انتخاب گروهی گروه‌ها ═══
const roomSel = new Set();

function roomBulkRefresh() {
  const bar = document.getElementById('room-bulk-bar');
  const n   = document.getElementById('room-bulk-n');
  if (n) n.textContent = faDigits(roomSel.size);
  if (bar) bar.hidden = roomSel.size === 0;
  document.querySelectorAll('.room-check').forEach(c => {
    const on = roomSel.has(parseInt(c.dataset.rid, 10));
    c.checked = on;
    c.closest('.room-row')?.classList.toggle('selected', on);
  });
}

function roomBulkClear() { roomSel.clear(); roomBulkRefresh(); }

document.addEventListener('change', e => {
  if (e.target.classList?.contains('room-check')) {
    const id = parseInt(e.target.dataset.rid, 10);
    e.target.checked ? roomSel.add(id) : roomSel.delete(id);
    roomBulkRefresh();
  }
});

async function bulkDeleteRooms() {
  if (!roomSel.size) return;
  const ids = [...roomSel];
  if (!confirm(`${ids.length} گروه حذف شود؟\n\nپیام‌ها و فایل‌های داخلشان هم پاک می‌شود و برگشت‌پذیر نیست.`)) return;

  const fd = new FormData();
  fd.append('action', 'bulk_delete_rooms');
  fd.append('room_ids', ids.join(','));
  fd.append('csrf', document.querySelector('meta[name="csrf-token"]').content);
  try {
    const r = await fetch('api/admin_action.php', { method: 'POST', body: fd });
    const j = await r.json();
    if (j.ok) { alert(j.message); location.reload(); }
    else alert(j.error || 'خطا');
  } catch (err) { alert('خطای شبکه'); }
}

</script>
</body>
</html>
