<?php
require_once __DIR__ . '/config.php';
require_login();

$csrf = csrf_token();
$username = current_username();
$isAdmin = function_exists('current_user_is_admin') && current_user_is_admin();
$stickerFiles = [];
foreach (glob(__DIR__ . '/assets/sticker/*') ?: [] as $stickerPath) {
    if (!is_file($stickerPath)) {
        continue;
    }
    $fileName = basename($stickerPath);
    if (preg_match('/\.(svg|png|jpe?g|gif|webp)$/i', $fileName)) {
        $stickerFiles[] = 'assets/sticker/' . $fileName;
    }
}
natsort($stickerFiles);
$stickerFiles = array_values($stickerFiles);
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8">
  <title><?= h(SITE_NAME) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#0c1520">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="پیام‌رسان">
  <link rel="manifest" href="manifest.php">
  <link rel="icon" type="image/png" href="favicon.png">
  <link rel="apple-touch-icon" href="assets/icons/icon-192.png">
  <meta name="csrf-token" content="<?= h($csrf) ?>">
  <link rel="stylesheet" href="assets/fonts/fonts.css?v=<?= h(asset_version('assets/fonts/fonts.css')) ?>">
  <link rel="stylesheet" href="assets/chat.css?v=<?= h(asset_version('assets/chat.css')) ?>">
</head>
<body
  data-avatar="<?= h(AVATAR_URL) ?>?v=<?= h(asset_version('assets/avatar.svg')) ?>"
  data-stickers='<?= h(json_encode($stickerFiles, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>'
>
  <div id="toast-container" class="toast-container" aria-live="polite"></div>
  <div id="sidebar-overlay" class="sidebar-overlay" aria-hidden="true"></div>
  <div class="app">
    <aside class="sidebar">

      <!-- ── گفتگوهای اخیر ── -->
      <div class="sb-section" id="sb-recent-section">
        <div class="sb-section-header">
          <span>گفتگوهای اخیر</span>
        </div>
        <ul id="recent-chats-list" class="sb-list"></ul>
      </div>

      <!-- ── درخواست‌های خصوصی ── -->
      <div class="sb-section" id="sb-requests-section" style="display:none">
        <div class="sb-section-header">
          <span>درخواست‌های خصوصی</span>
          <span id="requests-count" class="sb-badge"></span>
        </div>
        <ul id="private-request-list" class="sb-list request-list"></ul>
      </div>

      <!-- ── گروه‌ها ── -->
      <div class="sb-section">
        <div class="sb-section-header">
          <span>گروه‌ها</span>
          <button id="create-room" class="sb-new-btn">+ جدید</button>
        </div>
        <ul id="room-list" class="sb-list"></ul>
      </div>

      <!-- ── تاریخچه تماس ── -->
      <div class="sb-section" id="sb-calls-section" style="display:none">
        <div class="sb-section-header">
          <span>📞 آخرین تماس‌ها</span>
          <button class="sb-new-btn" onclick="clearCallHistory()" title="پاک کردن">🗑️</button>
        </div>
        <ul id="call-history-list" class="sb-list"></ul>
      </div>

      <!-- ── کاربران ── -->
      <div class="sb-section">
        <div class="sb-section-header">
          <span>کاربران</span>
        </div>
        <div class="sb-search-wrap">
          <input type="text" id="user-search" class="sb-search"
                 placeholder="🔍 جستجوی کاربر..." autocomplete="off" aria-label="جستجوی کاربر">
        </div>
        <ul id="user-list" class="sb-list"></ul>
      </div>

    </aside>

    <main class="chat" id="chat-main">
      <div id="drop-overlay" class="drop-overlay" aria-hidden="true">فایل را اینجا رها کنید</div>
            <div class="chat-header">

        <!-- ردیف اول: عنوان گفتگو + دکمه‌های اتاق + موبایل -->
        <div class="chat-title-wrap">

          <!-- راست: آواتار + نام + وضعیت (کلیک = پروفایل) -->
          <button id="contact-profile-btn" class="chat-contact-info-btn" type="button" style="display:none">
            <div class="chat-hdr-avatar">
              <img id="chat-contact-avatar" class="chat-contact-avatar" src="" alt="">
              <span id="chat-contact-status-dot" class="chat-hdr-dot"></span>
            </div>
            <div class="chat-hdr-text">
              <span id="chat-title" class="chat-hdr-name"></span>
              <span id="chat-status" class="chat-status"></span>
            </div>
          </button>

          <!-- نام گروه -->
          <div id="chat-title-group" class="chat-hdr-group-name" style="display:none"></div>

          <!-- خالی -->
          <div id="chat-title-empty" class="chat-hdr-empty"></div>

          <!-- فاصله کشدار -->
          <div class="chat-hdr-spacer"></div>

          <!-- دکمه‌های تماس (فقط private) -->
          <div id="chat-call-btns" class="chat-call-btns" style="display:none">
            <button id="call-video-btn" class="call-hdr-btn" title="تماس تصویری">
              <svg width="17" height="17" viewBox="0 0 24 24" fill="currentColor"><path d="M17 10.5V7c0-.55-.45-1-1-1H4c-.55 0-1 .45-1 1v10c0 .55.45 1 1 1h12c.55 0 1-.45 1-1v-3.5l4 4v-11l-4 4z"/></svg>
            </button>
            <button id="call-audio-btn" class="call-hdr-btn" title="تماس صوتی">
              <svg width="17" height="17" viewBox="0 0 24 24" fill="currentColor"><path d="M6.6 10.8c1.4 2.8 3.8 5.1 6.6 6.6l2.2-2.2c.3-.3.7-.4 1-.2 1.1.4 2.3.6 3.6.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1C10.6 21 3 13.4 3 4c0-.6.4-1 1-1h3.5c.6 0 1 .4 1 1 0 1.3.2 2.5.6 3.6.1.3 0 .7-.2 1l-2.3 2.2z"/></svg>
            </button>
          </div>

          <!-- منوی اتاق -->
          <button id="room-password-btn" class="hdr-action-btn" type="button" style="display:none">🔒 رمز</button>
          <button id="room-invite-btn"   class="hdr-action-btn" type="button" style="display:none">🔗 دعوت</button>
          <button id="room-clear-btn"    class="hdr-action-btn danger" type="button" style="display:none" title="همه‌ی پیام‌های این گروه را پاک کن">🗑️ پاک کردن</button>
          <button id="room-leave-btn"    class="hdr-action-btn danger" type="button" style="display:none" title="از این گروه خارج شو">🚪 ترک گروه</button>

          <!-- موبایل -->
          <button id="settings-toggle" class="hdr-icon-btn settings-toggle mobile-only" type="button" title="تنظیمات">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
          </button>
          <button id="sidebar-toggle" class="hdr-icon-btn sidebar-toggle mobile-only" type="button" title="منو">☰</button>

        </div>

        <!-- ردیف دوم: اطلاعات کاربر + ابزارها -->
        <div class="user-meta">
          <button id="settings-close" class="hdr-icon-btn settings-close" type="button">×</button>

          <div class="chat-search-wrap">
            <input type="search" id="message-search" class="chat-search-input" placeholder="🔍 جستجو در پیام‌ها..." autocomplete="off" aria-label="جستجو در پیام‌ها">
            <div id="search-results" class="search-results" aria-live="polite" role="listbox"></div>
          </div>

          <select id="notify-mode" class="notify-select" aria-label="حالت اعلان">
            <option value="all">🔔 همه</option>
            <option value="mentions">🔕 منشن</option>
            <option value="none">🔇 بی‌صدا</option>
          </select>

          <div class="user-meta-info">
            <button id="profile-btn" class="user-avatar-btn" type="button" title="پروفایل من">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
            </button>
            <span class="user-name"><?= h($username) ?></span>
            <?php if ($isAdmin): ?>
              <span class="admin-badge">سازنده</span>
              <button id="invisible-toggle" class="hdr-action-btn" type="button">👁 نامرئی</button>
            <?php endif; ?>
          </div>

          <?php if (current_user_is_admin()): ?>
          <a class="hdr-icon-btn admin-panel-btn" href="admin.php" title="پنل مدیریت">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
          </a>
          <?php endif; ?>

          <a class="logout-btn" href="logout.php">خروج</a>
        </div>

      </div>

      <div id="pinned-banner" class="pinned-banner"></div>
      <div id="older-loading" class="older-loading" aria-hidden="true">در حال بارگذاری پیام‌های قدیمی...</div>
      <div id="messages" class="messages"></div>
      <div id="typing-indicator" class="typing-indicator"></div>

      <form id="send-form" class="send-form" enctype="multipart/form-data">
        <div id="reply-bar" class="reply-bar">
          <div id="reply-text" class="reply-text"></div>
          <button type="button" id="reply-cancel" class="reply-cancel" aria-label="لغو پاسخ">×</button>
        </div>
        <textarea id="message-input" name="message" placeholder="پیام خود را بنویسید..." maxlength="<?= MAX_MESSAGE_LEN ?>" rows="1"></textarea>
        <div class="file-picker">
          <label class="file-btn" aria-label="انتخاب فایل">
            <img src="assets/icon/folder.svg" alt="">
            <input type="file" id="file-input" name="file">
          </label>
          <span id="file-name-text" class="file-name-text">فایلی انتخاب نشده</span>
        </div>
        <button type="button" id="paste-btn" class="paste-btn" title="چسباندن (Ctrl+V)" aria-label="چسباندن">
          <img src="assets/icon/paste.svg" alt="">
        </button>
        <button type="button" id="voice-btn" class="voice-btn" aria-label="نگه دار برای ضبط">
          <span class="voice-idle-icon">
            <img src="assets/icon/voice.svg" alt="">
          </span>
          <span class="voice-rec-icon" style="display:none">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
              <circle cx="12" cy="12" r="9" fill="rgba(255,60,60,0.2)" stroke="#ff4444" stroke-width="1.5"/>
              <circle cx="12" cy="12" r="5" fill="#ff4444"/>
            </svg>
          </span>
        </button>
        <div id="voice-swipe-hint-bar" class="voice-swipe-bar" style="display:none">
          <span class="swipe-cancel-zone">← بکش برای لغو</span>
          <span class="swipe-mid">در حال ضبط...</span>
          <span class="swipe-send-zone">رها کن = ارسال ✓</span>
        </div>
        <button type="button" id="emoji-btn" class="emoji-btn" aria-label="انتخاب اموجی">
          <img src="assets/icon/emoji.svg" alt="">
        </button>
        <button type="submit" id="send-btn" aria-label="ارسال">
          <img src="assets/icon/send.svg" alt="">
        </button>
        <div id="emoji-picker" class="emoji-picker" aria-hidden="true"></div>
      </form>

      <div id="upload-progress" class="upload-progress" aria-hidden="true">
        <div class="upload-progress-track">
          <div id="upload-progress-bar" class="upload-progress-bar"></div>
        </div>
        <div id="upload-progress-text" class="upload-progress-text"></div>
      </div>

      <div id="status" class="status"></div>
    </main>
  </div>

  <div id="profile-modal" class="modal-overlay" aria-hidden="true">
    <div class="modal profile-modal">
      <div class="modal-header">
        <h2>پروفایل من</h2>
        <button type="button" class="modal-close" id="profile-modal-close" aria-label="بستن">×</button>
      </div>
      <div class="modal-body">
        <div class="profile-avatar-wrap">
          <!-- عکس فعلی یا placeholder -->
          <div class="profile-avatar-container" id="profile-avatar-container">
            <img id="profile-avatar-img" class="profile-avatar" src="" alt="عکس پروفایل"
                 onerror="this.style.display='none';document.getElementById('profile-avatar-placeholder').style.display='flex'"
                 onload="this.style.display='block';document.getElementById('profile-avatar-placeholder').style.display='none'">
            <div id="profile-avatar-placeholder" class="profile-avatar-placeholder">
              <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#6b8a9e" stroke-width="1.5">
                <circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/>
              </svg>
              <span>عکس پروفایل<br>ندارید</span>
            </div>
          </div>
          <label class="avatar-change-label" for="profile-avatar-input">
            📷 تغییر عکس
          </label>
          <input type="file" id="profile-avatar-input" class="avatar-file-input"
                 accept="image/jpeg,image/png,image/gif,image/webp">
        </div>
        <div class="profile-fields">
          <label for="profile-display-name">نام نمایشی</label>
          <input type="text" id="profile-display-name" maxlength="100" placeholder="نامی که دیگران می‌بینند">
          <label for="profile-bio">بیو</label>
          <textarea id="profile-bio" rows="3" maxlength="500" placeholder="درباره خود بنویسید..."></textarea>
          <button type="button" id="profile-save" class="btn-primary">ذخیره</button>
        </div>
      </div>
    </div>
  </div>

  <div id="invite-modal" class="modal-overlay" aria-hidden="true">
    <div class="modal invite-modal">
      <div class="modal-header">
        <h2>لینک دعوت گروه</h2>
        <button type="button" class="modal-close" id="invite-modal-close" aria-label="بستن">×</button>
      </div>
      <div class="modal-body">
        <p class="invite-desc">این لینک را برای دعوت به گروه به اشتراک بگذارید:</p>
        <div class="invite-link-wrap">
          <input type="text" id="invite-link-input" class="invite-link-input" readonly>
          <button type="button" id="invite-copy-btn" class="btn-primary">کپی لینک</button>
        </div>
        <button type="button" id="invite-generate-btn" class="ghost">ایجاد لینک جدید</button>
      </div>
    </div>
  </div>

  <div id="contact-profile-modal" class="modal-overlay" aria-hidden="true">
    <div class="modal profile-modal contact-profile-modal">
      <div class="modal-header">
        <h2>پروفایل مخاطب</h2>
        <button type="button" class="modal-close" id="contact-profile-modal-close" aria-label="بستن">×</button>
      </div>
      <div class="modal-body">
        <div class="profile-avatar-wrap">
          <img id="contact-profile-avatar" class="profile-avatar" src="" alt="عکس پروفایل">
        </div>
        <div class="profile-fields contact-profile-fields">
          <p id="contact-profile-name" class="contact-profile-name"></p>
          <p id="contact-profile-username" class="contact-profile-username"></p>
          <p id="contact-profile-bio" class="contact-profile-bio"></p>
        </div>
        <!-- دکمه‌های عمومی — برای همه -->
        <div id="contact-user-actions" class="contact-user-actions">
          <button class="user-action-btn block-btn" id="contact-block-btn" onclick="toggleBlockUser()">🚫 بلاک</button>
          <button class="user-action-btn delete-chat-btn" onclick="deleteChatWithUser()">🗑️ حذف گفتگو</button>
        </div>
        <!-- دکمه‌های ادمین — فقط برای ادمین نمایش داده میشه -->
        <div id="contact-admin-actions" class="contact-admin-actions" style="display:none">
          <button class="admin-action-btn ban-btn" onclick="adminActionFromProfile('ban')">🚫 مسدود</button>
          <button class="admin-action-btn kick-btn" onclick="adminActionFromProfile('kick')">⛔ حذف از اتاق</button>
          <button class="admin-action-btn vip-btn" onclick="adminActionFromProfile('vip')">⭐ VIP</button>
          <button class="admin-action-btn pw-btn" onclick="adminActionFromProfile('password')">🔑 تغییر رمز</button>
          <button class="admin-action-btn del-btn" onclick="adminActionFromProfile('delete')">🗑️ حذف پیام‌ها</button>
        </div>
      </div>
    </div>
  </div>


<!-- ━━━ Call Bar (تلگرام‌استایل) ━━━ -->
<div id="call-bar" class="call-bar" style="display:none">
  <div class="call-bar-inner" id="call-bar-inner">
    <span class="call-bar-icon" id="call-bar-icon">📞</span>
    <div class="call-bar-info">
      <span class="call-bar-name" id="call-bar-name">تماس</span>
      <span class="call-bar-timer" id="call-bar-timer">در انتظار...</span>
    </div>
    <div class="call-bar-actions">
      <button class="call-bar-btn return" onclick="returnToCall()" title="بازگشت به تماس"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 12h18M3 12l6-6M3 12l6 6"/></svg></button>
      <button class="call-bar-btn end" onclick="hangupCall()" title="قطع تماس">✕</button>
    </div>
  </div>
</div>

<!-- ━━━ Call Overlay ━━━ -->
<div id="call-overlay" class="call-overlay" style="display:none">
  <div class="call-box">
    <!-- Video elements -->
    <div class="call-videos" id="call-videos" style="display:none">
      <video id="remote-video" class="remote-video" autoplay playsinline></video>
      <video id="local-video"  class="local-video"  autoplay playsinline muted></video>
      <button class="call-minimize-btn" id="btn-minimize" onclick="toggleMinimize()" title="کوچک کردن">⤡</button>
    </div>
    <!-- Avatar (صوتی) -->
    <div class="call-avatar-wrap" id="call-avatar-wrap">
      <div class="call-avatar" id="call-avatar-letter">?</div>
      <div class="call-ripple"></div>
    </div>
    <div class="call-name" id="call-name">...</div>
    <div class="call-status" id="call-status">در حال برقراری...</div>
    <!-- دکمه‌های incoming -->
    <div class="call-actions" id="call-actions-incoming" style="display:none">
      <button class="call-action-btn reject" onclick="rejectCall()" title="رد کردن">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M12 9.19c-2.59-.06-5.15.71-7.23 2.17L2.7 9.29C5.42 7.22 8.66 6 12 6s6.58 1.22 9.3 3.29l-2.07 2.07C17.15 9.9 14.59 9.13 12 9.19zM3.41 4.41L2 5.83l2.05 2.05C1.91 9.67.6 12.1.6 12.1l2.12 2.12c0 0 1.04-1.96 2.93-3.56l1.54 1.54c-1.26 1.06-2.15 2.49-2.43 4.13h3c.32-1.25 1.08-2.34 2.12-3.06l1.65 1.65C10.97 15.55 10.5 16.25 10.5 17h3c0-1.27.6-2.4 1.54-3.13l5.54 5.54 1.42-1.42L3.41 4.41z"/></svg>
      </button>
      <button class="call-action-btn accept" onclick="acceptCall()" title="پاسخ دادن">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/></svg>
      </button>
    </div>
    <!-- دکمه‌های in-call -->
    <div class="call-actions" id="call-actions-active" style="display:none">
      <button class="call-action-btn mute-btn" id="btn-mute" onclick="toggleMute()" title="بی‌صدا">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm5.91-3c-.49 0-.9.36-.98.85C16.52 14.2 14.47 16 12 16s-4.52-1.8-4.93-4.15c-.08-.49-.49-.85-.98-.85-.61 0-1.09.54-1 1.14.49 3 2.89 5.35 5.91 5.78V20c0 .55.45 1 1 1s1-.45 1-1v-2.08c3.02-.43 5.42-2.78 5.91-5.78.1-.6-.39-1.14-1-1.14z"/></svg>
      </button>
      <button class="call-action-btn cam-btn" id="btn-cam" onclick="toggleCam()" title="دوربین" style="display:none">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M17 10.5V7c0-.55-.45-1-1-1H4c-.55 0-1 .45-1 1v10c0 .55.45 1 1 1h12c.55 0 1-.45 1-1v-3.5l4 4v-11l-4 4z"/></svg>
      </button>
      <button class="call-action-btn flip-btn" id="btn-flip" onclick="flipCamera()" title="برگردان دوربین" style="display:none">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M20 5h-3.17L15 3H9L7.17 5H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm-8 13c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5z"/><path d="M12 10v2.79l2.12 2.12 1.06-1.06L13.5 12V10H12z"/></svg>
      </button>
      <button class="call-action-btn hangup" onclick="hangupCall()" title="قطع تماس">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M12 9.19c-2.59-.06-5.15.71-7.23 2.17L2.7 9.29C5.42 7.22 8.66 6 12 6s6.58 1.22 9.3 3.29l-2.07 2.07C17.15 9.9 14.59 9.13 12 9.19zM3.41 4.41L2 5.83l2.05 2.05C1.91 9.67.6 12.1.6 12.1l2.12 2.12c0 0 1.04-1.96 2.93-3.56l1.54 1.54c-1.26 1.06-2.15 2.49-2.43 4.13h3c.32-1.25 1.08-2.34 2.12-3.06l1.65 1.65C10.97 15.55 10.5 16.25 10.5 17h3c0-1.27.6-2.4 1.54-3.13l5.54 5.54 1.42-1.42L3.41 4.41z"/></svg>
      </button>
    </div>
    <!-- دکمه outgoing -->
    <div class="call-actions" id="call-actions-outgoing" style="display:none">
      <button class="call-action-btn hangup" onclick="hangupCall()" title="لغو تماس">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M12 9.19c-2.59-.06-5.15.71-7.23 2.17L2.7 9.29C5.42 7.22 8.66 6 12 6s6.58 1.22 9.3 3.29l-2.07 2.07C17.15 9.9 14.59 9.13 12 9.19zM3.41 4.41L2 5.83l2.05 2.05C1.91 9.67.6 12.1.6 12.1l2.12 2.12c0 0 1.04-1.96 2.93-3.56l1.54 1.54c-1.26 1.06-2.15 2.49-2.43 4.13h3c.32-1.25 1.08-2.34 2.12-3.06l1.65 1.65C10.97 15.55 10.5 16.25 10.5 17h3c0-1.27.6-2.4 1.54-3.13l5.54 5.54 1.42-1.42L3.41 4.41z"/></svg>
      </button>
    </div>
  </div>
</div>
<audio id="call-ringtone" loop>
  <source src="assets/audio/notfication-message.mp3">
</audio>
<audio id="remote-audio" autoplay></audio>

  <script src="assets/app.js?v=<?= h(asset_version('assets/app.js')) ?>"></script>
<script>
window.__isAdmin = <?= current_user_is_admin() ? 'true' : 'false' ?>;
window.__myUserId = <?= (int)current_user_id() ?>;
</script>
<script>
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch(() => {});
  });
}
</script>
</body>
</html>





