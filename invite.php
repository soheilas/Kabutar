<?php
require_once __DIR__ . '/config.php';
require_login();

$pdo = db();
$uid = current_user_id();
$csrf = csrf_token();

// آیا ساخت لینک دعوت مجاز است؟
$inviteOnly = site_setting('invite_only', '0') === '1';
$canInvite  = current_user_is_admin() || $inviteOnly; // اگه invite_only فعاله، همه می‌تونن لینک بدن؟
// در واقع: همه کاربران می‌تونن لینک گروه‌هایی که عضوشن رو بگیرن
// ادمین‌ها می‌تونن لینک دعوت به سایت هم بگیرن

$error = '';
$success = '';

// ──────── POST: ساخت لینک دعوت برای گروه ────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_is_valid($_POST['csrf'] ?? '')) {
    $roomId = (int)($_POST['room_id'] ?? 0);
    if ($roomId > 0) {
        // چک عضویت
        $stmt = $pdo->prepare('SELECT 1 FROM room_members WHERE room_id=? AND user_id=?');
        $stmt->execute([$roomId, $uid]);
        if ($stmt->fetchColumn()) {
            $token = bin2hex(random_bytes(24));
            try {
                $pdo->prepare('UPDATE rooms SET invite_token=? WHERE id=?')->execute([$token, $roomId]);
                $success = 'لینک دعوت ساخته شد.';
            } catch (\Throwable $e) {
                $error = 'خطا در ساخت لینک.';
            }
        } else {
            $error = 'شما عضو این گروه نیستید.';
        }
    }
}

// گروه‌هایی که کاربر عضوشه
$myRooms = [];
try {
    $stmt = $pdo->prepare(
        "SELECT r.id, r.name, r.invite_token
         FROM rooms r
         JOIN room_members rm ON rm.room_id=r.id AND rm.user_id=?
         ORDER BY r.name"
    );
    $stmt->execute([$uid]);
    $myRooms = $stmt->fetchAll();
} catch (\Throwable $e) {}

$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off' ? 'https' : 'http')
         . '://' . $_SERVER['HTTP_HOST']
         . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

// سایت‌لینک دعوت (invite_only)
$siteInviteEnabled = site_setting('invite_only', '0') === '1';
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>لینک دعوت</title>
<link rel="stylesheet" href="assets/chat.css?v=<?= h(asset_version('assets/chat.css')) ?>">
<style>
body { background: #080e17; color: #ddeaf8; font-family: "IranYekanX",system-ui,sans-serif; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
.inv-box { width: 100%; max-width: 520px; }
.inv-header { display: flex; align-items: center; gap: 12px; margin-bottom: 24px; }
.inv-title { font-size: 20px; font-weight: 800; }
.inv-back { color: #6b8a9e; text-decoration: none; font-size: 13px; }
.inv-back:hover { color: #3aa4ff; }
.inv-card { background: #0f1c2a; border: 1px solid #1e3248; border-radius: 14px; padding: 20px; margin-bottom: 16px; }
.inv-card-title { font-size: 13px; font-weight: 700; color: #6b8a9e; margin-bottom: 14px; }
.inv-room-row { display: flex; align-items: center; gap: 10px; padding: 10px 0; border-bottom: 1px solid rgba(30,50,72,0.5); }
.inv-room-row:last-child { border-bottom: none; }
.inv-room-name { flex: 1; font-weight: 600; }
.inv-link-wrap { display: flex; gap: 8px; margin-top: 8px; }
.inv-link-input { flex: 1; padding: 8px 12px; border: 1px solid #1e3248; border-radius: 8px; background: #142030; color: #ddeaf8; font-size: 12px; font-family: monospace; }
.inv-btn { padding: 7px 14px; border-radius: 8px; border: none; font-family: inherit; font-size: 12px; font-weight: 700; cursor: pointer; white-space: nowrap; transition: all 0.15s; }
.inv-btn-gen { background: rgba(58,164,255,0.15); border: 1px solid rgba(58,164,255,0.3); color: #3aa4ff; }
.inv-btn-gen:hover { background: rgba(58,164,255,0.25); }
.inv-btn-copy { background: rgba(34,204,119,0.15); border: 1px solid rgba(34,204,119,0.3); color: #22cc77; }
.inv-btn-copy:hover { background: rgba(34,204,119,0.25); }
.inv-alert { padding: 10px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; }
.inv-alert.error { background: rgba(255,79,79,0.1); border: 1px solid rgba(255,79,79,0.3); color: #ff9b9b; }
.inv-alert.success { background: rgba(34,204,119,0.1); border: 1px solid rgba(34,204,119,0.3); color: #5dffa0; }
.inv-site-link { display: flex; align-items: center; gap: 10px; padding: 12px; background: rgba(124,92,252,0.1); border: 1px solid rgba(124,92,252,0.25); border-radius: 10px; }
.inv-site-icon { font-size: 28px; }
.inv-site-info { flex: 1; }
.inv-site-title { font-weight: 700; margin-bottom: 4px; }
.inv-site-desc { font-size: 12px; color: #6b8a9e; }
</style>
</head>
<body>
<div class="inv-box">
  <div class="inv-header">
    <a href="chat.php" class="inv-back">← بازگشت</a>
    <div class="inv-title">🔗 لینک‌های دعوت</div>
  </div>

  <?php if ($error): ?><div class="inv-alert error">⚠️ <?= h($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="inv-alert success">✅ <?= h($success) ?></div><?php endif; ?>

  <?php if ($siteInviteEnabled): ?>
  <!-- لینک ثبت‌نام سایت -->
  <div class="inv-card">
    <div class="inv-card-title">🌐 لینک دعوت به سایت</div>
    <div class="inv-site-link">
      <div class="inv-site-icon">🔑</div>
      <div class="inv-site-info">
        <div class="inv-site-title">ثبت‌نام فقط با لینک دعوت فعال است</div>
        <div class="inv-site-desc">این لینک را با افرادی که می‌خواهید عضو شوند به اشتراک بگذارید</div>
      </div>
    </div>
    <div class="inv-link-wrap" style="margin-top:12px">
      <input class="inv-link-input" id="site-invite-link" readonly value="<?= h($baseUrl . '/index.php') ?>">
      <button class="inv-btn inv-btn-copy" onclick="copyLink('site-invite-link')">📋 کپی</button>
    </div>
    <div style="font-size:11px;color:#6b8a9e;margin-top:8px">کاربران می‌توانند با این لینک مستقیم ثبت‌نام کنند.</div>
  </div>
  <?php endif; ?>

  <!-- لینک دعوت گروه‌ها -->
  <div class="inv-card">
    <div class="inv-card-title">🏠 لینک دعوت گروه‌ها</div>
    <?php if (!$myRooms): ?>
      <div style="color:#6b8a9e;font-size:13px;text-align:center;padding:20px">عضو هیچ گروهی نیستید.</div>
    <?php else: ?>
      <?php foreach ($myRooms as $room): ?>
        <div class="inv-room-row">
          <div>
            <div class="inv-room-name">🏠 <?= h($room['name']) ?></div>
            <?php if ($room['invite_token']): ?>
              <div class="inv-link-wrap">
                <input class="inv-link-input" id="link-<?= $room['id'] ?>" readonly
                       value="<?= h($baseUrl . '/join.php?invite=' . $room['invite_token']) ?>">
                <button class="inv-btn inv-btn-copy" onclick="copyLink('link-<?= $room['id'] ?>')">📋</button>
              </div>
            <?php else: ?>
              <div style="font-size:12px;color:#6b8a9e;margin-top:4px">لینک دعوت ندارد</div>
            <?php endif; ?>
          </div>
          <form method="post" style="margin-right:auto;flex-shrink:0">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="room_id" value="<?= $room['id'] ?>">
            <button type="submit" class="inv-btn inv-btn-gen">🔄 <?= $room['invite_token'] ? 'تجدید' : 'ساخت لینک' ?></button>
          </form>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

</div>

<script>
function copyLink(id) {
  const inp = document.getElementById(id);
  if (!inp) return;
  inp.select();
  navigator.clipboard?.writeText(inp.value).catch(() => {
    document.execCommand('copy');
  });
  const btn = inp.nextElementSibling;
  if (btn) { btn.textContent = '✅'; setTimeout(() => btn.textContent = '📋', 1500); }
}
</script>
</body>
</html>
