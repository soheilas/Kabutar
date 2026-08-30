(() => {
  const roomList = document.getElementById('room-list');
  const userList = document.getElementById('user-list');
  const chatTitle = document.getElementById('chat-title');
  const messagesEl = document.getElementById('messages');
  const form = document.getElementById('send-form');
  const messageInput = document.getElementById('message-input');
  const fileInput = document.getElementById('file-input');
  const statusEl = document.getElementById('status');
  const sendBtn = document.getElementById('send-btn');
  const createRoomBtn = document.getElementById('create-room');
  const voiceBtn = document.getElementById('voice-btn');
  const roomPasswordBtn = document.getElementById('room-password-btn');
  const sidebarToggle = document.getElementById('sidebar-toggle');
  const sidebarOverlay = document.getElementById('sidebar-overlay');
  const appRoot = document.querySelector('.app');
  const fileNameText = document.getElementById('file-name-text');
  const replyBar = document.getElementById('reply-bar');
  const replyText = document.getElementById('reply-text');
  const replyCancel = document.getElementById('reply-cancel');
  const chatStatus = document.getElementById('chat-status');
  const typingIndicator = document.getElementById('typing-indicator');
  const invisibleToggle = document.getElementById('invisible-toggle');
  const toastContainer = document.getElementById('toast-container');
  const uploadProgress = document.getElementById('upload-progress');
  const uploadProgressBar = document.getElementById('upload-progress-bar');
  const uploadProgressText = document.getElementById('upload-progress-text');
  const pasteBtn = document.getElementById('paste-btn');
  const emojiBtn = document.getElementById('emoji-btn');
  const emojiPicker = document.getElementById('emoji-picker');
  const dropOverlay = document.getElementById('drop-overlay');
  const chatMain = document.getElementById('chat-main');
  const userSearch = document.getElementById('user-search');
  const privateRequestList = document.getElementById('private-request-list');
  const sbRequestsSection  = document.getElementById('sb-requests-section');
  const requestsCount      = document.getElementById('requests-count');
  const messageSearch = document.getElementById('message-search');
  const searchResults = document.getElementById('search-results');
  const olderLoading = document.getElementById('older-loading');
  const notifyModeSelect = document.getElementById('notify-mode');
  const profileBtn = document.getElementById('profile-btn');
  const roomInviteBtn = document.getElementById('room-invite-btn');
  const roomLeaveBtn  = document.getElementById('room-leave-btn');
  const roomClearBtn  = document.getElementById('room-clear-btn');
  const profileModal = document.getElementById('profile-modal');
  const profileModalClose = document.getElementById('profile-modal-close');
  const profileDisplayName = document.getElementById('profile-display-name');
  const profileBio = document.getElementById('profile-bio');
  const profileSave = document.getElementById('profile-save');
  const profileAvatarImg = document.getElementById('profile-avatar-img');
  const profileAvatarInput = document.getElementById('profile-avatar-input');
  const inviteModal = document.getElementById('invite-modal');
  const inviteModalClose = document.getElementById('invite-modal-close');
  const inviteLinkInput = document.getElementById('invite-link-input');
  const inviteCopyBtn = document.getElementById('invite-copy-btn');
  const inviteGenerateBtn = document.getElementById('invite-generate-btn');
  const chatContactAvatar = document.getElementById('chat-contact-avatar');
  const chatContactStatusDot = document.getElementById('chat-contact-status-dot');
  const settingsToggle = document.getElementById('settings-toggle');
  const settingsClose = document.getElementById('settings-close');
  const contactProfileBtn = document.getElementById('contact-profile-btn');
  const contactProfileModal = document.getElementById('contact-profile-modal');
  const contactProfileModalClose = document.getElementById('contact-profile-modal-close');
  const contactProfileAvatar = document.getElementById('contact-profile-avatar');
  const contactProfileName = document.getElementById('contact-profile-name');
  const contactProfileUsername = document.getElementById('contact-profile-username');
  const contactProfileBio = document.getElementById('contact-profile-bio');
  let pinnedBanner = document.getElementById('pinned-banner');
  let remoteTypingText = '';
  let userSearchTimeout = null;
  const contactProfileCache = new Map();
  const CONTACT_PROFILE_TTL_MS = 60000;

  const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
  const avatarUrl = document.body.dataset.avatar;
  const stickerList = (() => {
    try {
      const raw = document.body.dataset.stickers || '[]';
      const parsed = JSON.parse(raw);
      if (!Array.isArray(parsed)) {
        return [];
      }
      return parsed
        .map((item) => String(item || '').trim())
        .filter((item) => /assets\/sticker\/[^/]+\.(svg|png|jpe?g|gif|webp)$/i.test(item));
    } catch (err) {
      return [];
    }
  })();
  const notifyIcon = 'assets/avatar.svg';
  const messageNotificationSoundSrc = 'assets/audio/notfication-message.mp3';
  const presenceNotificationSoundSrc = 'assets/audio/notfication-online-offline.mp3';
  const defaultMessageInputPlaceholder = messageInput
    ? (messageInput.getAttribute('placeholder') || 'پیام خود را بنویسید...')
    : 'پیام خود را بنویسید...';
  const voiceIconMarkup = '<img src="assets/icon/voice.svg" alt="">';
  const stopIconMarkup = '<img src="assets/icon/stop.svg" alt="">';
  const voicePlayIconSrc = 'assets/icon/play.svg';
  const voicePauseIconSrc = 'assets/icon/pause.svg';
  const maxMessageLength = 2000;

  const state = {
    mode: null,
    targetId: null,
    lastId: 0,
    oldestMessageId: 0,
    lastReadId: 0,
    lastEditedAt: '',
    replyTo: null,
    lastDeletedAt: 0,
    hasLoadedMessages: false,
    hasOlderMessages: true,
    isLoadingOlderMessages: false,
    isVip: false,
    pinnedId: 0,
    isAdmin: false,
    isInvisible: false,
    notifyMode: 'all',
    meUsername: '',
    meDisplayName: '',
    editingMessageId: 0
  };

  const roomsById = new Map();
  const usersById = new Map();
  const messagesById = new Map();
  const readById = new Set();
  const prevUnreadUsers = new Map();
  const prevUnreadRooms = new Map();
  let roomsFirstLoad = true;
  const prevOnlineUsers = new Map();
  const prevIncomingRequestIds = new Set();
  let usersLoadedOnce = false;

  let mediaRecorder = null;
  let audioChunks = [];
  let recordingStream = null;
  let isRecording = false;
  let voiceCancelled = false;
  let recordingMime = '';
  let recorderMode = '';
  let audioContext = null;
  let sourceNode = null;
  let processorNode = null;
  let silenceNode = null;
  let wavBuffers = [];
  let wavSampleRate = 0;
  let isPolling = false;
  let isSending = false;
  let notifyAsked = false;
  let lastTypingSent = 0;
  let typingPollInFlight = false;
  const notificationSounds = {
    message: null,
    presence: null
  };
  const notificationSoundLastPlayedAt = {
    message: 0,
    presence: 0
  };

  // صدای Web Audio برای آنلاین/آفلاین — بدون نیاز به فایل
  function playToneSound(type) {
    // اگه حالت اعلان none هست، صدا نده
    if (normalizeNotifyMode(state.notifyMode) === 'none') return;
    try {
      const ctx = new (window.AudioContext || window.webkitAudioContext)();
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.connect(gain);
      gain.connect(ctx.destination);

      if (type === 'online') {
        // دو نت بالارونده — جذاب و ملایم
        osc.frequency.setValueAtTime(520, ctx.currentTime);
        osc.frequency.setValueAtTime(780, ctx.currentTime + 0.12);
        gain.gain.setValueAtTime(0, ctx.currentTime);
        gain.gain.linearRampToValueAtTime(0.18, ctx.currentTime + 0.05);
        gain.gain.setValueAtTime(0.18, ctx.currentTime + 0.15);
        gain.gain.linearRampToValueAtTime(0, ctx.currentTime + 0.35);
        osc.type = 'sine';
        osc.start(ctx.currentTime);
        osc.stop(ctx.currentTime + 0.38);
      } else {
        // یه نت پایین‌رونده — آرام
        osc.frequency.setValueAtTime(480, ctx.currentTime);
        osc.frequency.setValueAtTime(320, ctx.currentTime + 0.15);
        gain.gain.setValueAtTime(0.12, ctx.currentTime);
        gain.gain.linearRampToValueAtTime(0, ctx.currentTime + 0.4);
        osc.type = 'sine';
        osc.start(ctx.currentTime);
        osc.stop(ctx.currentTime + 0.42);
      }
      osc.onended = () => ctx.close();
    } catch(e) {}
  }

  function setVoiceButtonState(st) {
    if (!voiceBtn) return;
    const idleIcon = voiceBtn.querySelector('.voice-idle-icon');
    const recIcon  = voiceBtn.querySelector('.voice-rec-icon');
    const hintBar  = document.getElementById('voice-swipe-hint-bar');
    if (st === 'stop') {
      if (idleIcon) idleIcon.style.display = 'none';
      if (recIcon)  recIcon.style.display  = 'block';
      if (hintBar)  hintBar.style.display  = 'flex';
      voiceBtn.classList.add('is-recording');
      voiceBtn.dataset.state = 'stop';
      voiceBtn.setAttribute('aria-label', 'رها کن برای ارسال');
    } else {
      if (idleIcon) idleIcon.style.display = 'block';
      if (recIcon)  recIcon.style.display  = 'none';
      if (hintBar)  hintBar.style.display  = 'none';
      voiceBtn.classList.remove('is-recording');
      voiceBtn.dataset.state = 'voice';
      voiceBtn.setAttribute('aria-label', 'نگه دار برای ضبط');
    }
  }

  setVoiceButtonState('voice');

  function stickerFileNameFromPath(path) {
    const match = String(path || '').match(/assets\/sticker\/([^/]+)$/i);
    return match ? match[1] : '';
  }

  function stickerTokenFromPath(path) {
    const fileName = stickerFileNameFromPath(path);
    return fileName ? `sticker:${encodeURIComponent(fileName)}` : '';
  }

  function parseStickerToken(text) {
    const normalized = String(text || '')
      .trim()
      .replace(/^["']|["']$/g, '');
    const legacyMatch = normalized.match(/^:?\s*sticker:(\d{3}-emoji\.svg)$/i);
    if (legacyMatch) {
      return `assets/sticker/${legacyMatch[1].toLowerCase()}`;
    }
    const match = normalized.match(/^:?\s*sticker:([^\s]+)$/i);
    if (!match) {
      return null;
    }
    let fileName = '';
    try {
      fileName = decodeURIComponent(match[1]);
    } catch (err) {
      return null;
    }
    if (!fileName || /[\\/]/.test(fileName) || !/\.(svg|png|jpe?g|gif|webp)$/i.test(fileName)) {
      return null;
    }
    return `assets/sticker/${fileName}`;
  }

  function setEmojiPickerOpen(open) {
    if (!emojiPicker) {
      return;
    }
    emojiPicker.classList.toggle('show', open);
    emojiPicker.setAttribute('aria-hidden', open ? 'false' : 'true');
  }

  function buildEmojiPicker() {
    if (!emojiPicker) {
      return;
    }
    const fragment = document.createDocumentFragment();
    stickerList.forEach((path, index) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'emoji-item';
      btn.dataset.sticker = path;
      btn.setAttribute('aria-label', `اموجی ${index + 1}`);
      const img = document.createElement('img');
      img.src = path;
      img.alt = '';
      btn.appendChild(img);
      fragment.appendChild(btn);
    });
    emojiPicker.innerHTML = '';
    emojiPicker.appendChild(fragment);
  }

  async function sendSticker(path) {
    try {
      const token = stickerTokenFromPath(path);
      if (!token) {
        setStatus('ارسال اموجی انجام نشد.');
        return;
      }
      setStatus('در حال ارسال اموجی...');
      const ok = await sendMessagePayload(token, null);
      if (!ok) {
        return;
      }
      pollMessages();
      setEmojiPickerOpen(false);
      setStatus('');
    } catch (err) {
      setStatus('ارسال اموجی انجام نشد.');
    }
  }
  let messageSearchTimeout = null;
  let mentionBox = null;
  let actionModal = null;
  let messageActionMenu = null;
  let activeMessageActionId = 0;
  let searchResultItems = [];
  let activeSearchResultIndex = -1;
  let lastRoomsPollAt = 0;
  let lastUsersPollAt = 0;
  let lastMessagesPollAt = 0;
  let lastTypingPollAt = 0;
  let lastPrivateRequestsPollAt = 0;

  function isImageFile(msg) {
    if (msg.file_type && msg.file_type.startsWith('image/')) {
      return true;
    }
    if (!msg.file_name) {
      return false;
    }
    return /\.(png|jpe?g|gif|webp|bmp|svg)$/i.test(msg.file_name);
  }

  function isAudioFile(msg) {
    if (msg.file_type && msg.file_type.startsWith('audio/')) {
      return true;
    }
    if (!msg.file_name) {
      return false;
    }
    return /\.(mp3|wav|ogg|m4a|webm)$/i.test(msg.file_name);
  }

  function isVoiceMessage(msg) {
    if (!isAudioFile(msg)) {
      return false;
    }
    return /^voice-\d+\.(mp3|wav|ogg|m4a|webm)$/i.test(String(msg.file_name || '').trim());
  }

  function formatAudioDuration(seconds) {
    const total = Math.max(0, Math.floor(Number(seconds) || 0));
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const secs = total % 60;
    if (hours > 0) {
      return `${hours}:${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
    }
    return `${minutes}:${String(secs).padStart(2, '0')}`;
  }

  function formatFileSize(bytes) {
    const size = Number(bytes || 0);
    if (!Number.isFinite(size) || size <= 0) {
      return '';
    }
    if (size < 1024) {
      return `${size} B`;
    }
    if (size < (1024 * 1024)) {
      const kb = size / 1024;
      return `${kb >= 100 ? Math.round(kb) : kb.toFixed(1)} KB`;
    }
    const mb = size / (1024 * 1024);
    return `${mb >= 100 ? Math.round(mb) : mb.toFixed(1)} MB`;
  }

  function loadImageElementFromFile(file) {
    return new Promise((resolve, reject) => {
      const objectUrl = URL.createObjectURL(file);
      const img = new Image();
      img.onload = () => {
        URL.revokeObjectURL(objectUrl);
        resolve(img);
      };
      img.onerror = () => {
        URL.revokeObjectURL(objectUrl);
        reject(new Error('failed_to_load_image'));
      };
      img.src = objectUrl;
    });
  }

  async function optimizeAvatarUpload(file) {
    if (!file || !file.type || !file.type.startsWith('image/')) {
      return file;
    }
    try {
      const img = await loadImageElementFromFile(file);
      const maxDimension = 512;
      const scale = Math.min(1, maxDimension / Math.max(img.width || 1, img.height || 1));
      const targetW = Math.max(1, Math.round((img.width || 1) * scale));
      const targetH = Math.max(1, Math.round((img.height || 1) * scale));
      const canvas = document.createElement('canvas');
      canvas.width = targetW;
      canvas.height = targetH;
      const ctx = canvas.getContext('2d');
      if (!ctx) {
        return file;
      }
      ctx.drawImage(img, 0, 0, targetW, targetH);

      let blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/webp', 0.82));
      if (!blob) {
        blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.82));
      }
      if (!blob) {
        return file;
      }

      const didResize = targetW !== img.width || targetH !== img.height;
      if (!didResize && blob.size >= file.size) {
        return file;
      }

      const ext = blob.type === 'image/webp' ? 'webp' : 'jpg';
      return new File([blob], `avatar.${ext}`, { type: blob.type || file.type, lastModified: Date.now() });
    } catch (err) {
      return file;
    }
  }

  function inferStatusKind(text) {
    const value = String(text || '').trim();
    if (!value) {
      return '';
    }
    if (/(نشد|ناموفق|نامعتبر|خطا|غیرمجاز|اجازه|نمی|نیست|ندار|failed|error|invalid|forbidden)/i.test(value)) {
      return 'error';
    }
    if (/(شد|ذخیره|کپی|ساخته|به.?روز|موفق|انجام شد|success|saved|updated|copied|done)/i.test(value)) {
      return 'success';
    }
    return 'info';
  }

  function setStatus(text, kind = 'auto') {
    if (!statusEl) {
      return;
    }
    const value = String(text || '');
    statusEl.textContent = value;
    statusEl.classList.remove('status-error', 'status-success', 'status-info');
    if (!value.trim()) {
      return;
    }
    const finalKind = kind === 'auto' ? inferStatusKind(value) : kind;
    if (finalKind === 'error') {
      statusEl.classList.add('status-error');
    } else if (finalKind === 'success') {
      statusEl.classList.add('status-success');
    } else {
      statusEl.classList.add('status-info');
    }
  }

  function updateTypingPlaceholder() {
    if (!messageInput) {
      return;
    }
    // همیشه placeholder ثابت باشد
    messageInput.setAttribute('placeholder', defaultMessageInputPlaceholder);
    messageInput.classList.remove('typing-placeholder');
    // نمایش در نوار typing indicator بالای input
    if (typingIndicator) {
      if (remoteTypingText) {
        typingIndicator.textContent = remoteTypingText;
        typingIndicator.classList.add('show');
      } else {
        typingIndicator.classList.remove('show');
        typingIndicator.textContent = '';
      }
    }
  }

  function setTypingIndicator(text) {
    const clean = String(text || '').trim();
    remoteTypingText = clean.length > 64 ? `${clean.slice(0, 61)}...` : clean;
    updateTypingPlaceholder();
  }

  function ensurePinnedBanner() {
    if (pinnedBanner) {
      return;
    }
    if (!messagesEl || !messagesEl.parentNode) {
      return;
    }
    pinnedBanner = document.createElement('div');
    pinnedBanner.id = 'pinned-banner';
    pinnedBanner.className = 'pinned-banner';
    messagesEl.parentNode.insertBefore(pinnedBanner, messagesEl);
  }

  function renderPinned(pinned) {
    ensurePinnedBanner();
    if (!pinnedBanner) {
      return;
    }
    if (!pinned || !pinned.id) {
      pinnedBanner.classList.remove('show');
      pinnedBanner.textContent = '';
      pinnedBanner.removeAttribute('data-message-id');
      state.pinnedId = 0;
      const pinButtons = messagesEl.querySelectorAll('.pin-btn');
      pinButtons.forEach((btn) => {
        btn.textContent = 'پین';
      });
      return;
    }
    state.pinnedId = pinned.id;
    const preview = pinned.body
      ? truncateText(pinned.body, 120)
      : (pinned.file_name ? `فایل: ${pinned.file_name}` : 'پیام پین شده');
    pinnedBanner.textContent = `پین: ${pinned.sender} - ${preview}`;
    pinnedBanner.setAttribute('data-message-id', String(pinned.id));
    pinnedBanner.classList.add('show');
    pinnedBanner.onclick = () => {
      highlightMessage(pinned.id);
    };
    const pinButtons = messagesEl.querySelectorAll('.pin-btn');
    pinButtons.forEach((btn) => {
      const id = Number(btn.dataset.messageId || 0);
      btn.textContent = id === state.pinnedId ? 'برداشتن پین' : 'پین';
    });
  }

  function updateInvisibleToggle() {
    if (!invisibleToggle) {
      return;
    }
    if (!state.isAdmin) {
      invisibleToggle.style.display = 'none';
      return;
    }
    invisibleToggle.style.display = 'inline-flex';
    invisibleToggle.textContent = state.isInvisible ? 'نمایش آنلاین' : 'حالت نامرئی';
  }

  async function loadMe() {
    try {
      const data = await apiGet('api/me.php');
      if (data && data.ok) {
        state.isVip = Boolean(data.is_vip);
        state.isAdmin = Boolean(data.is_admin);
        state.isInvisible = Boolean(data.is_invisible);
        state.notifyMode = normalizeNotifyMode(data.notify_mode || 'all');
        state.meUsername    = (data.username || '').trim();
        state.meDisplayName = (data.display_name || '').trim();
      }
    } catch (err) {
      state.isVip = false;
      state.isAdmin = false;
      state.isInvisible = false;
      state.notifyMode = 'all';
    }
    updateInvisibleToggle();
    applyNotifyModeToUI();
  }

  async function setNotifyMode(value) {
    const next = normalizeNotifyMode(value);
    const previous = state.notifyMode;
    state.notifyMode = next;
    applyNotifyModeToUI();
    const formData = new FormData();
    formData.append('csrf', csrfToken);
    formData.append('action', 'set_notify_mode');
    formData.append('value', next);
    const res = await apiPost('api/me.php', formData);
    if (!res.ok) {
      state.notifyMode = previous;
      applyNotifyModeToUI();
      setStatus(res.error || 'ذخیره تنظیم اعلان انجام نشد.');
      return;
    }
    state.notifyMode = normalizeNotifyMode(res.notify_mode || next);
    applyNotifyModeToUI();
    setStatus('تنظیم اعلان ذخیره شد.');
  }

  function showUploadProgress(percent) {
    if (!uploadProgress || !uploadProgressBar || !uploadProgressText) {
      return;
    }
    uploadProgress.classList.add('show');
    uploadProgress.setAttribute('aria-hidden', 'false');
    if (percent === null) {
      uploadProgress.classList.add('indeterminate');
      uploadProgressBar.style.width = '100%';
      uploadProgressText.textContent = 'در حال ارسال فایل...';
    } else {
      uploadProgress.classList.remove('indeterminate');
      uploadProgressBar.style.width = `${percent}%`;
      uploadProgressText.textContent = `در حال ارسال فایل... ${percent}%`;
    }
  }

  function hideUploadProgress() {
    if (!uploadProgress || !uploadProgressBar || !uploadProgressText) {
      return;
    }
    uploadProgress.classList.remove('show', 'indeterminate');
    uploadProgress.setAttribute('aria-hidden', 'true');
    uploadProgressBar.style.width = '0%';
    uploadProgressText.textContent = '';
  }

  async function apiPostWithProgress(url, body, onProgress) {
    return new Promise((resolve) => {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', url, true);
      xhr.withCredentials = true;
      xhr.setRequestHeader('X-CSRF-Token', csrfToken);
      if (xhr.upload && onProgress) {
        xhr.upload.onprogress = (event) => {
          if (event.lengthComputable) {
            const percent = Math.round((event.loaded / event.total) * 100);
            onProgress(percent);
          } else {
            onProgress(null);
          }
        };
      }
      xhr.onload = () => {
        let data = null;
        try {
          data = JSON.parse(xhr.responseText || '{}');
        } catch (err) {
          data = { ok: false, error: 'پاسخ نامعتبر از سرور دریافت شد.' };
        }
        resolve(data);
      };
      xhr.onerror = () => {
        resolve({ ok: false, error: 'ارتباط با سرور برقرار نشد.' });
      };
      xhr.send(body);
    });
  }

  function setupNotificationSounds() {
    try {
      notificationSounds.message = new Audio(messageNotificationSoundSrc);
      notificationSounds.message.preload = 'auto';
      notificationSounds.message.volume = 0.35;
    } catch (err) {
      notificationSounds.message = null;
    }
    try {
      notificationSounds.presence = new Audio(presenceNotificationSoundSrc);
      notificationSounds.presence.preload = 'auto';
      notificationSounds.presence.volume = 0.2;
    } catch (err) {
      notificationSounds.presence = null;
    }
  }

  function playNotificationSound(kind) {
    // اگه حالت اعلان none هست، هیچ صدایی نده
    if (normalizeNotifyMode(state.notifyMode) === 'none') return;
    const key = kind === 'presence' ? 'presence' : 'message';
    const audio = notificationSounds[key];
    if (!audio) {
      return;
    }
    const now = Date.now();
    const cooldown = key === 'presence' ? 900 : 450;
    if ((now - (notificationSoundLastPlayedAt[key] || 0)) < cooldown) {
      return;
    }
    notificationSoundLastPlayedAt[key] = now;
    try {
      audio.pause();
      audio.currentTime = 0;
      const playing = audio.play();
      if (playing && typeof playing.catch === 'function') {
        playing.catch(() => {});
      }
    } catch (err) {
    }
  }

  window.showToast = function showToast(title, body, action) {
    if (!toastContainer) {
      return;
    }
    const toast = document.createElement('div');
    toast.className = 'toast';
    const t = document.createElement('div');
    t.className = 'toast-title';
    t.textContent = title;
    const b = document.createElement('div');
    b.className = 'toast-body';
    b.textContent = body || 'پیام جدید';
    toast.appendChild(t);
    toast.appendChild(b);
    if (action && action.onClick) {
      toast.classList.add('clickable');
      toast.addEventListener('click', action.onClick);
    } else if (action && action.mode && action.id) {
      toast.classList.add('clickable');
      toast.addEventListener('click', () => {
        setConversation(action.mode, action.id, action.title || title);
      });
    }
    toastContainer.appendChild(toast);
    requestAnimationFrame(() => {
      toast.classList.add('show');
    });
    setTimeout(() => {
      toast.classList.remove('show');
      setTimeout(() => toast.remove(), 200);
    }, 4500);
  };
  function canNotify() {
    return typeof Notification !== 'undefined' && Notification.permission === 'granted';
  }

  function shouldNotifyNow() {
    if (!canNotify()) {
      return false;
    }
    if (document.visibilityState !== 'visible') {
      return true;
    }
    return !document.hasFocus();
  }

  function requestNotificationPermission() {
    if (notifyAsked || typeof Notification === 'undefined') {
      return;
    }
    notifyAsked = true;
    if (Notification.permission === 'default') {
      Notification.requestPermission().catch(() => {});
    }
  }

  function showNotification(title, body) {
    if (!shouldNotifyNow()) {
      return;
    }
    try {
      const notification = new Notification(title, {
        body,
        icon: notifyIcon,
        badge: notifyIcon,
        dir: 'rtl'
      });
      notification.onclick = () => {
        window.focus();
        notification.close();
      };
    } catch (err) {
    }
  }

  function normalizeNotifyMode(value) {
    return ['all', 'mentions', 'none'].includes(value) ? value : 'all';
  }

  function isMentioned(text) {
    if (!text || !state.meUsername) {
      return false;
    }
    const escaped = state.meUsername.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const mentionRegex = new RegExp(`(^|\\s)@${escaped}(\\b|\\s|$)`, 'i');
    return mentionRegex.test(text);
  }

  function shouldAlert(kind, previewText) {
    const mode = normalizeNotifyMode(state.notifyMode);
    if (mode === 'none') {
      return false;
    }
    if (mode === 'all') {
      return true;
    }
    if (kind === 'private') {
      return true;
    }
    return isMentioned(previewText || '');
  }

  function applyNotifyModeToUI() {
    if (!notifyModeSelect) {
      return;
    }
    notifyModeSelect.value = normalizeNotifyMode(state.notifyMode);
  }

  function formatMessagePreview(msg) {
    if (parseStickerToken(msg.body || '')) {
      return 'اموجی';
    }
    if (msg.body) {
      return truncateText(msg.body, 140);
    }
    if (msg.has_file && msg.file_name) {
      return `فایل: ${msg.file_name}`;
    }
    if (msg.has_file) {
      return 'فایل';
    }
    return 'پیام جدید';
  }

  function formatPreviewFromParts(body, fileName) {
    if (parseStickerToken(body || '')) {
      return 'اموجی';
    }
    if (body) {
      return truncateText(body, 140);
    }
    if (fileName) {
      return `فایل: ${fileName}`;
    }
    return 'پیام جدید';
  }

  function formatChatTime(value) {
    if (!value) {
      return '';
    }
    const raw = String(value).trim();
    const parsed = new Date(raw.replace(' ', 'T'));
    if (!Number.isNaN(parsed.getTime())) {
      return parsed.toLocaleTimeString('fa-IR', {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false
      });
    }
    const match = raw.match(/(\d{1,2}):(\d{2})/);
    if (match) {
      const hh = String(match[1]).padStart(2, '0');
      return `${hh}:${match[2]}`;
    }
    return raw;
  }
  function formatLastSeen(lastActive) {
    if (!lastActive) return '';
    const diffMs = Date.now() - new Date(lastActive).getTime();
    const m = Math.floor(diffMs / 60000);
    const h = Math.floor(diffMs / 3600000);
    const d = Math.floor(diffMs / 86400000);
    const p = n => String(n).replace(/[0-9]/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
    if (m < 1)   return 'همین الان';
    if (m === 1) return 'یک دقیقه پیش';
    if (m < 60)  return `${p(m)} دقیقه پیش`;
    if (h === 1) return 'یک ساعت پیش';
    if (h < 24)  return `${p(h)} ساعت پیش`;
    if (d === 1) return 'دیروز';
    if (d < 7)   return `${p(d)} روز پیش`;
    if (d < 30)  return `${p(Math.floor(d/7))} هفته پیش`;
    return 'مدت‌ها پیش';
  }

  function removeMessageById(id) {
    const numericId = Number(id || 0);
    if (numericId > 0) {
      messagesById.delete(numericId);
      if (activeMessageActionId === numericId) {
        closeMessageActionMenu();
      }
    }
    const el = messagesEl.querySelector(`[data-message-id="${id}"]`);
    if (el) {
      el.remove();
    }
  }

  function ensureMessageActionMenu() {
    if (messageActionMenu) {
      return messageActionMenu;
    }
    const menu = document.createElement('div');
    menu.className = 'message-action-menu';
    menu.id = 'message-action-menu';
    menu.setAttribute('aria-hidden', 'true');
    menu.addEventListener('click', (e) => e.stopPropagation());
    document.body.appendChild(menu);
    messageActionMenu = menu;
    return menu;
  }

  function closeMessageActionMenu() {
    if (!messageActionMenu) {
      return;
    }
    messageActionMenu.classList.remove('show');
    messageActionMenu.setAttribute('aria-hidden', 'true');
    messageActionMenu.innerHTML = '';
    messageActionMenu.style.left = '-9999px';
    messageActionMenu.style.top = '-9999px';
    messageActionMenu.style.visibility = 'hidden';
    activeMessageActionId = 0;
  }

  function openMessageFile(messageId, download = false) {
    const link = document.createElement('a');
    link.href = download ? `download.php?m=${messageId}` : `view.php?m=${messageId}`;
    if (download) {
      link.download = '';
    } else {
      link.target = '_blank';
      link.rel = 'noopener';
    }
    document.body.appendChild(link);
    link.click();
    link.remove();
  }

  function pauseOtherVoicePlayers(exceptAudio) {
    document.querySelectorAll('.voice-audio').forEach((node) => {
      if (!(node instanceof HTMLAudioElement) || node === exceptAudio) {
        return;
      }
      if (!node.paused) {
        node.pause();
      }
    });
  }

  function buildVoiceMessage(msg, isPrivateConversation) {
    const wrap = document.createElement('div');
    wrap.className = 'voice-message';

    const player = document.createElement('div');
    player.className = 'voice-player';
    player.addEventListener('click', (e) => {
      e.stopPropagation();
    });

    const playBtn = document.createElement('button');
    playBtn.type = 'button';
    playBtn.className = 'voice-play-btn';
    playBtn.setAttribute('aria-label', 'پخش صدا');

    const playIcon = document.createElement('span');
    playIcon.className = 'voice-play-icon';
    const playIconImg = document.createElement('img');
    playIconImg.src = voicePlayIconSrc;
    playIconImg.alt = '';
    playIcon.appendChild(playIconImg);
    playBtn.appendChild(playIcon);

    const track = document.createElement('div');
    track.className = 'voice-track';
    track.setAttribute('role', 'slider');
    track.setAttribute('aria-label', 'جابه‌جایی در پیام صوتی');
    track.setAttribute('aria-valuemin', '0');
    track.setAttribute('aria-valuemax', '100');
    track.setAttribute('aria-valuenow', '0');
    track.style.setProperty('--voice-progress', '0%');

    const wave = document.createElement('div');
    wave.className = 'voice-wave';
    const bars = [];
    const barCount = 46;
    for (let i = 0; i < barCount; i++) {
      const bar = document.createElement('span');
      bar.className = 'voice-bar';
      const height = 26 + Math.round(Math.abs(Math.sin((i + 1) * 0.58)) * 50) + (i % 7 === 0 ? 10 : 0);
      bar.style.setProperty('--voice-bar-height', `${Math.min(height, 92)}%`);
      wave.appendChild(bar);
      bars.push(bar);
    }
    track.appendChild(wave);

    player.appendChild(playBtn);
    player.appendChild(track);
    wrap.appendChild(player);

    const footer = document.createElement('div');
    footer.className = 'voice-footer';
    footer.addEventListener('click', (e) => {
      e.stopPropagation();
    });
    const metaLeft = document.createElement('span');
    metaLeft.className = 'voice-footer-left';
    const metaRight = document.createElement('span');
    metaRight.className = 'voice-footer-right';
    metaRight.textContent = formatChatTime(msg.created_at);

    if (isPrivateConversation && msg.is_me) {
      const isRead = Boolean(msg.read_at) || readById.has(msg.id);
      if (msg.read_at) {
        readById.add(msg.id);
      }
      const tick = document.createElement('span');
      tick.className = isRead ? 'tick read' : 'tick';
      tick.textContent = isRead ? '✓✓' : '✓';
      tick.dataset.messageId = String(msg.id);
      metaRight.appendChild(tick);
    }

    footer.appendChild(metaLeft);
    footer.appendChild(metaRight);
    wrap.appendChild(footer);

    const audio = document.createElement('audio');
    audio.className = 'voice-audio';
    audio.preload = 'metadata';
    audio.src = `view.php?m=${msg.id}`;
    wrap.appendChild(audio);

    const sizeLabel = formatFileSize(msg.file_size);
    const setPlayIconState = (paused) => {
      const nextSrc = paused ? voicePlayIconSrc : voicePauseIconSrc;
      if (playIconImg.getAttribute('src') !== nextSrc) {
        playIconImg.src = nextSrc;
      }
    };
    const setMetaLeft = () => {
      const duration = Number.isFinite(audio.duration) && audio.duration > 0 ? audio.duration : 0;
      const current = Math.max(0, Math.min(duration || 0, Number(audio.currentTime) || 0));
      const durationLabel = formatAudioDuration(duration);
      const currentLabel = formatAudioDuration(current);
      const shouldShowProgressTime = (!audio.paused && duration > 0) || current > 0;
      const parts = [shouldShowProgressTime ? `${currentLabel} / ${durationLabel}` : durationLabel];
      if (sizeLabel) {
        parts.push(sizeLabel);
      }
      metaLeft.textContent = parts.filter(Boolean).join(', ');
    };

    const syncVoiceState = () => {
      const duration = Number.isFinite(audio.duration) && audio.duration > 0 ? audio.duration : 0;
      const progress = duration > 0 ? Math.max(0, Math.min(1, audio.currentTime / duration)) : 0;
      const activeBars = Math.round(progress * bars.length);
      const currentBarIndex = bars.length ? Math.min(bars.length - 1, Math.max(0, Math.round(progress * (bars.length - 1)))) : -1;
      bars.forEach((bar, index) => {
        bar.classList.toggle('is-active', index < activeBars);
        bar.classList.toggle('is-current', duration > 0 && index === currentBarIndex);
      });
      setPlayIconState(audio.paused);
      playBtn.setAttribute('aria-label', audio.paused ? 'پخش صدا' : 'توقف پخش');
      wrap.classList.toggle('is-playing', !audio.paused);
      track.setAttribute('aria-valuenow', String(Math.round(progress * 100)));
      track.setAttribute('aria-valuetext', `${formatAudioDuration(audio.currentTime)} از ${formatAudioDuration(duration)}`);
      setMetaLeft();
    };

    playBtn.addEventListener('click', async (e) => {
      e.stopPropagation();
      try {
        if (audio.paused) {
          pauseOtherVoicePlayers(audio);
          await audio.play();
        } else {
          audio.pause();
        }
      } catch (err) {
        setStatus('پخش صدا انجام نشد.');
      }
    });

    track.addEventListener('click', (e) => {
      e.stopPropagation();
      if (!Number.isFinite(audio.duration) || audio.duration <= 0) {
        return;
      }
      const rect = track.getBoundingClientRect();
      if (!rect.width) {
        return;
      }
      const progress = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
      audio.currentTime = progress * audio.duration;
      syncVoiceState();
    });

    audio.addEventListener('loadedmetadata', syncVoiceState);
    audio.addEventListener('timeupdate', syncVoiceState);
    audio.addEventListener('play', () => {
      pauseOtherVoicePlayers(audio);
      syncVoiceState();
    });
    audio.addEventListener('pause', syncVoiceState);
    audio.addEventListener('ended', () => {
      audio.currentTime = 0;
      syncVoiceState();
    });
    audio.addEventListener('error', () => {
      setStatus('پخش صدا انجام نشد.');
    });

    syncVoiceState();
    return wrap;
  }

  function buildMessageActions(msg) {
    const actions = [];
    actions.push({
      label: 'پاسخ',
      onClick: () => setReply(msg)
    });

    actions.push({
      label: 'کپی',
      onClick: async () => {
        const txt = msg.body || (msg.file_name ? `فایل: ${msg.file_name}` : '');
        if (!txt) {
          setStatus('متنی برای کپی وجود ندارد.');
          return;
        }
        try {
          await navigator.clipboard.writeText(txt);
          setStatus('کپی شد.', 'success');
        } catch (err) {
          setStatus('کپی نشد.');
        }
      }
    });

    if (msg.has_file) {
      actions.push({
        label: 'باز کردن فایل',
        onClick: () => openMessageFile(msg.id, false)
      });
      actions.push({
        label: 'دانلود فایل',
        onClick: () => openMessageFile(msg.id, true)
      });
    }

    actions.push({
      label: 'فوروارد',
      onClick: () => forwardMessage(msg.id)
    });

    if (state.mode === 'group' && state.isVip) {
      actions.push({
        label: state.pinnedId === msg.id ? 'برداشتن پین' : 'پین',
        onClick: () => togglePin(msg.id)
      });
    }

    if (msg.is_me) {
      actions.push({
        label: 'ویرایش',
        onClick: () => editMessage(msg.id, msg.body || '')
      });
      actions.push({
        label: 'حذف',
        danger: true,
        onClick: () => deleteMessage(msg.id)
      });
    }

    // دکمه‌های ادمین گروه (برای پیام بقیه)
    if (window.__isAdmin && state.mode === 'group' && !msg.is_me) {
      actions.push({ label: '─', disabled: true });
      actions.push({
        label: '🗑️ حذف پیام',
        danger: true,
        onClick: () => deleteMessage(msg.id)
      });
      actions.push({
        label: '🚫 مسدود کاربر',
        danger: true,
        onClick: async () => {
          const mins = prompt('مدت مسدودی (دقیقه):', '60');
          if (!mins) return;
          const fd = new FormData();
          fd.append('csrf', csrfToken);
          fd.append('action', 'ban_user');
          fd.append('user_id', msg.sender_id);
          fd.append('ban_minutes', mins);
          const res = await apiPost('api/admin_action.php', fd);
          showToast(res.ok ? '🚫 مسدود شد' : '❌ ' + (res.error||'خطا'), msg.sender || '');
        }
      });
      actions.push({
        label: '⛔ اخراج از گروه',
        danger: true,
        onClick: async () => {
          if (!confirm(`${msg.sender} از گروه اخراج شود؟`)) return;
          const fd = new FormData();
          fd.append('csrf', csrfToken);
          fd.append('action', 'kick_member');
          fd.append('room_id', state.targetId);
          fd.append('target_user_id', msg.sender_id);
          const res = await apiPost('api/rooms.php', fd);
          showToast(res.ok ? '✅ اخراج شد' : '❌ ' + (res.error||'خطا'), msg.sender || '');
        }
      });
      actions.push({
        label: '🗑️ حذف همه پیام‌های این کاربر',
        danger: true,
        onClick: async () => {
          if (!confirm(`همه پیام‌های ${msg.sender} در این گروه حذف شود؟`)) return;
          const fd = new FormData();
          fd.append('csrf', csrfToken);
          fd.append('action', 'delete_user_messages');
          fd.append('room_id', state.targetId);
          fd.append('target_user_id', msg.sender_id);
          const res = await apiPost('api/rooms.php', fd);
          if (res.ok) {
            showToast('🗑️ پیام‌ها حذف شد', msg.sender || '');
            // ریست state برای reload کامل
            state.lastId = 0;
            state.hasLoadedMessages = false;
            state.oldestMessageId = null;
            if (messagesEl) messagesEl.innerHTML = '';
            pollMessages();
          } else showToast('❌ ' + (res.error||'خطا'), '');
        }
      });
    }

    return actions;
  }

  function openMessageActionMenu(messageId, anchorEl) {
    const msg = messagesById.get(messageId);
    if (!msg || !anchorEl) {
      return;
    }
    const menu = ensureMessageActionMenu();
    const actions = buildMessageActions(msg);
    if (!actions.length) {
      closeMessageActionMenu();
      return;
    }
    menu.innerHTML = '';
    actions.forEach((action) => {
      // separator
      if (action.disabled) {
        const sep = document.createElement('div');
        sep.className = 'message-action-item separator';
        sep.textContent = '';
        menu.appendChild(sep);
        return;
      }
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = action.danger ? 'message-action-item danger' : 'message-action-item';
      btn.textContent = action.label;
      btn.addEventListener('click', async () => {
        closeMessageActionMenu();
        await action.onClick();
      });
      menu.appendChild(btn);
    });

    menu.classList.add('show');
    menu.setAttribute('aria-hidden', 'false');
    menu.style.visibility = 'hidden';
    menu.style.left = '0px';
    menu.style.top = '0px';

    const anchorRect = anchorEl.getBoundingClientRect();
    const menuRect = menu.getBoundingClientRect();
    const pad = 8;
    let left = anchorRect.right - menuRect.width;
    let top = anchorRect.top - menuRect.height - 8;
    if (top < pad) {
      top = anchorRect.bottom + 8;
    }
    if (top + menuRect.height > window.innerHeight - pad) {
      top = Math.max(pad, window.innerHeight - menuRect.height - pad);
    }
    left = Math.max(pad, Math.min(left, window.innerWidth - menuRect.width - pad));

    menu.style.left = `${Math.round(left)}px`;
    menu.style.top = `${Math.round(top)}px`;
    menu.style.visibility = 'visible';
    activeMessageActionId = messageId;
  }

  function toggleMessageActionMenu(messageId, anchorEl) {
    if (activeMessageActionId === messageId && messageActionMenu && messageActionMenu.classList.contains('show')) {
      closeMessageActionMenu();
      return;
    }
    openMessageActionMenu(messageId, anchorEl);
  }

  function shouldSkipMessageMenuTarget(target) {
    if (!(target instanceof Element)) {
      return true;
    }
    return Boolean(target.closest('a, button, input, textarea, select, label, audio, video, .reaction, .reply-preview, .file-actions, .preview, [contenteditable="true"]'));
  }

  async function deleteMessage(id) {
    openActionModal({
      title: 'حذف پیام',
      submitText: 'حذف',
      render: (host) => {
        const p = document.createElement('p');
        p.className = 'action-modal-hint';
        p.textContent = 'این پیام حذف شود؟';
        host.appendChild(p);
        return {};
      },
      onSubmit: async () => {
        const formData = new FormData();
        formData.append('csrf', csrfToken);
        formData.append('action', 'delete');
        formData.append('message_id', String(id));
        const res = await apiPost('api/messages.php', formData);
        if (!res.ok) {
          setStatus(res.error || 'حذف پیام انجام نشد.');
          return false;
        }
        if (res.deleted_at && res.deleted_at > state.lastDeletedAt) {
          state.lastDeletedAt = res.deleted_at;
        }
        removeMessageById(id);
        setStatus('پیام حذف شد.', 'success');
        return true;
      }
    });
  }

  function ensureActionModal() {
    if (actionModal) {
      return actionModal;
    }
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.id = 'action-modal';
    overlay.innerHTML = `
      <div class="modal action-modal">
        <div class="modal-header">
          <h2 id="action-modal-title"></h2>
          <button type="button" class="modal-close" id="action-modal-close" aria-label="بستن">×</button>
        </div>
        <div class="modal-body">
          <div id="action-modal-content"></div>
          <div class="action-modal-actions">
            <button type="button" class="ghost" id="action-modal-cancel">انصراف</button>
            <button type="button" class="btn-primary" id="action-modal-submit">ثبت</button>
          </div>
        </div>
      </div>
    `;
    document.body.appendChild(overlay);
    const close = () => overlay.classList.remove('show');
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) close();
    });
    overlay.querySelector('#action-modal-close').addEventListener('click', close);
    overlay.querySelector('#action-modal-cancel').addEventListener('click', close);
    actionModal = {
      overlay,
      title: overlay.querySelector('#action-modal-title'),
      content: overlay.querySelector('#action-modal-content'),
      submit: overlay.querySelector('#action-modal-submit')
    };
    return actionModal;
  }

  function openActionModal({ title, submitText = 'ثبت', render, onSubmit }) {
    const modal = ensureActionModal();
    modal.title.textContent = title;
    modal.content.innerHTML = '';
    const ctx = render(modal.content) || {};
    modal.submit.textContent = submitText;
    modal.submit.disabled = false;
    modal.submit.onclick = async () => {
      const ok = await onSubmit(ctx);
      if (ok) {
        modal.overlay.classList.remove('show');
      }
    };
    modal.overlay.classList.add('show');
  }

  function buildMessageBodyNode(body) {
    const value = String(body || '');
    if (!value) {
      return null;
    }
    const stickerPath = parseStickerToken(value);
    if (stickerPath) {
      const sticker = document.createElement('img');
      sticker.className = 'preview';
      sticker.src = stickerPath;
      sticker.alt = 'emoji';
      sticker.dataset.messageBody = '1';
      return sticker;
    }
    const text = document.createElement('div');
    text.className = 'text';
    text.innerHTML = formatMentionsToHtml(value);
    text.dataset.messageBody = '1';
    return text;
  }

  function insertNodeAfter(parent, referenceNode, nextNode) {
    if (!parent || !nextNode) {
      return;
    }
    if (!referenceNode || referenceNode.parentNode !== parent) {
      parent.insertBefore(nextNode, parent.firstChild);
      return;
    }
    parent.insertBefore(nextNode, referenceNode.nextSibling);
  }

  function normalizeEditedCursor(value) {
    const normalized = String(value || '').trim();
    return /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(normalized) ? normalized : '';
  }

  function updateEditedCursor(value) {
    const normalized = normalizeEditedCursor(value);
    if (!normalized) {
      return;
    }
    if (!state.lastEditedAt || normalized > state.lastEditedAt) {
      state.lastEditedAt = normalized;
    }
  }

  function applyEditedMessages(items) {
    if (!Array.isArray(items) || !items.length) {
      return;
    }
    items.forEach((item) => {
      const messageId = Number(item && item.id ? item.id : 0);
      if (!messageId) {
        return;
      }
      updateRenderedMessage(messageId, item.body || '', item.edited_at || '');
      updateEditedCursor(item.edited_at || '');
    });
  }

  function updateRenderedMessage(messageId, newBody, editedAt = '') {
    const numericId = Number(messageId || 0);
    const msg = messagesById.get(numericId);
    if (msg) {
      msg.body = newBody;
      msg.edited_at = normalizeEditedCursor(editedAt) || msg.edited_at || new Date().toISOString();
    }

    const row = messagesEl.querySelector(`[data-message-id="${numericId}"]`);
    if (!row) {
      return false;
    }
    const bubble = row.querySelector('.bubble');
    if (!bubble) {
      return false;
    }

    const existingBody = bubble.querySelector('[data-message-body="1"]');
    let insertAfter = bubble.querySelector('.meta');
    bubble.querySelectorAll('.reply-preview').forEach((node) => {
      insertAfter = node;
    });

    const nextBody = buildMessageBodyNode(newBody);
    if (existingBody && nextBody) {
      existingBody.replaceWith(nextBody);
    } else if (existingBody) {
      existingBody.remove();
    } else if (nextBody) {
      insertNodeAfter(bubble, insertAfter, nextBody);
    }

    const bodyAnchor = nextBody || bubble.querySelector('[data-message-body="1"]') || insertAfter;
    let editedMarker = bubble.querySelector('[data-edited-marker="1"]');
    if (!editedMarker) {
      editedMarker = document.createElement('div');
      editedMarker.className = 'seen-by';
      editedMarker.dataset.editedMarker = '1';
      editedMarker.textContent = 'ویرایش شده';
      if (bodyAnchor) {
        insertNodeAfter(bubble, bodyAnchor, editedMarker);
      } else {
        bubble.appendChild(editedMarker);
      }
    } else {
      editedMarker.textContent = 'ویرایش شده';
      if (bodyAnchor && editedMarker.previousSibling !== bodyAnchor) {
        bubble.insertBefore(editedMarker, bodyAnchor.nextSibling);
      }
    }

    return true;
  }

  function refreshCurrentConversation() {
    if (!state.mode || !state.targetId) {
      return;
    }
    state.lastId = 0;
    state.oldestMessageId = 0;
    state.lastReadId = 0;
    state.lastEditedAt = '';
    state.lastDeletedAt = 0;
    state.hasLoadedMessages = false;
    state.hasOlderMessages = true;
    state.isLoadingOlderMessages = false;
    clearMessages();
    pollMessages();
  }

  async function editMessage(id, currentBody) {
    openActionModal({
      title: 'ویرایش پیام',
      submitText: 'ذخیره تغییرات',
      render: (host) => {
        const input = document.createElement('textarea');
        input.className = 'action-modal-textarea';
        input.rows = 4;
        input.maxLength = 2000;
        input.value = currentBody || '';
        input.placeholder = 'متن جدید پیام...';
        const hint = document.createElement('div');
        hint.className = 'action-modal-hint';
        hint.textContent = 'برای ذخیره سریع از Ctrl+Enter استفاده کن';
        const counter = document.createElement('div');
        counter.className = 'action-modal-hint';
        host.appendChild(input);
        host.appendChild(hint);
        host.appendChild(counter);
        const modal = ensureActionModal();
        const initialValue = String(currentBody || '').trim();
        const syncState = () => {
          const nextValue = String(input.value || '').trim();
          const disabled = !nextValue || nextValue === initialValue;
          modal.submit.disabled = disabled;
        counter.textContent = `${nextValue.length}/${maxMessageLength}`;
        };
        input.addEventListener('input', syncState);
        input.addEventListener('keydown', (e) => {
          if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            e.preventDefault();
            if (!modal.submit.disabled) {
              modal.submit.click();
            }
          }
        });
        setTimeout(() => {
          input.focus();
          input.setSelectionRange(input.value.length, input.value.length);
          syncState();
        }, 0);
        return { input };
      },
      onSubmit: async ({ input }) => {
        const body = (input.value || '').trim();
        if (!body) {
          setStatus('متن پیام نمی‌تواند خالی باشد.');
          return false;
        }
        const formData = new FormData();
        formData.append('csrf', csrfToken);
        formData.append('action', 'edit');
        formData.append('message_id', String(id));
        formData.append('body', body);
        const res = await apiPost('api/messages.php', formData);
        if (!res.ok) {
          setStatus(res.error || 'ویرایش پیام انجام نشد.');
          return false;
        }
        updateEditedCursor(res.edited_at || '');
        if (!updateRenderedMessage(id, body, res.edited_at || '')) {
          refreshCurrentConversation();
        }
        setStatus('پیام ویرایش شد.', 'success');
        return true;
      }
    });
  }

  async function forwardMessage(id) {
    openActionModal({
      title: 'فوروارد پیام',
      submitText: 'فوروارد',
      render: (host) => {
        const wrap = document.createElement('div');
        wrap.className = 'action-modal-grid';
        const mode = document.createElement('select');
        mode.className = 'action-modal-input';
        mode.innerHTML = `
          <option value="group">گروه</option>
          <option value="private">خصوصی</option>
        `;
        const target = document.createElement('select');
        target.className = 'action-modal-input';
        const hint = document.createElement('div');
        hint.className = 'action-modal-hint';
        hint.textContent = 'گفتگو مقصد را انتخاب کنید.';

        const renderTargets = () => {
          target.innerHTML = '';
          if (mode.value === 'group') {
            const groups = Array.from(roomsById.values())
              .filter((room) => room && room.joined)
              .sort((a, b) => String(a.name || '').localeCompare(String(b.name || ''), 'fa'));
            if (!groups.length) {
              const op = document.createElement('option');
              op.value = '';
              op.disabled = true;
              op.selected = true;
              op.textContent = 'گروهی برای فوروارد ندارید';
              target.appendChild(op);
              return;
            }
            groups.forEach((room) => {
              const op = document.createElement('option');
              op.value = String(room.id);
              op.textContent = `گروه: ${room.name}`;
              target.appendChild(op);
            });
            return;
          }

          const users = Array.from(usersById.values())
            .filter((user) => user && user.id && (Boolean(user.can_private_chat) || (user.dm_status || 'none') === 'accepted'))
            .sort((a, b) => userDisplayName(a).localeCompare(userDisplayName(b), 'fa'));
          if (!users.length) {
            const op = document.createElement('option');
            op.value = '';
            op.disabled = true;
            op.selected = true;
            op.textContent = 'مخاطبی برای فوروارد ندارید';
            target.appendChild(op);
            return;
          }
          users.forEach((user) => {
            const op = document.createElement('option');
            op.value = String(user.id);
            op.textContent = `خصوصی: ${userDisplayName(user)}`;
            target.appendChild(op);
          });
        };

        mode.addEventListener('change', renderTargets);
        renderTargets();

        wrap.appendChild(mode);
        wrap.appendChild(target);
        host.appendChild(wrap);
        host.appendChild(hint);
        setTimeout(() => mode.focus(), 0);
        return { mode, target };
      },
      onSubmit: async ({ mode, target }) => {
        const targetId = Number(target.value || 0);
        if (!targetId || targetId <= 0) {
          setStatus('مقصد معتبر انتخاب نشده است.');
          return false;
        }
        if (isSending) return false;
        const formData = new FormData();
        formData.append('csrf', csrfToken);
        formData.append('action', 'forward');
        formData.append('message_id', String(id));
        formData.append('target_mode', mode.value);
        formData.append('target_id', String(targetId));
        const res = await apiPost('api/messages.php', formData);
        if (!res.ok) {
          setStatus(res.error || 'فوروارد انجام نشد.');
          return false;
        }
        setStatus('فوروارد انجام شد.', 'success');
        return true;
      }
    });
  }

  async function toggleReaction(messageId, emoji, mine) {
    const formData = new FormData();
    formData.append('csrf', csrfToken);
    formData.append('action', mine ? 'unreact' : 'react');
    formData.append('message_id', String(messageId));
    formData.append('emoji', emoji);
    const res = await apiPost('api/messages.php', formData);
    if (!res.ok) {
      setStatus(res.error || 'ثبت واکنش انجام نشد.');
      return;
    }
    state.lastId = 0;
    state.hasLoadedMessages = false;
    clearMessages();
    pollMessages();
  }

  function setSending(active) {
    isSending = active;
    if (sendBtn) {
      sendBtn.disabled = active;
    }
  }

  function truncateText(text, maxLen) {
    if (!text) {
      return '';
    }
    const clean = text.replace(/\s+/g, ' ').trim();
    if (clean.length <= maxLen) {
      return clean;
    }
    return clean.slice(0, maxLen) + '...';
  }

  function escapeHtml(text) {
    return String(text || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  function formatMentionsToHtml(text) {
    const safe = escapeHtml(text);
    return safe.replace(/(^|\s)@([A-Za-z0-9_]{3,30})/g, '$1<span class="mention">@$2</span>');
  }

  function getReplyPreviewText(msg) {
    if (parseStickerToken(msg.body || '')) {
      return 'اموجی';
    }
    if (msg.body) {
      return truncateText(msg.body, 80);
    }
    if (msg.file_name) {
      return `فایل: ${msg.file_name}`;
    }
    return 'پیام';
  }

  function setReply(msg) {
    if (!replyBar || !replyText) {
      return;
    }
    state.replyTo = msg;
    replyText.textContent = `${msg.sender}: ${getReplyPreviewText(msg)}`;
    replyBar.classList.add('active');
  }

  function clearReply() {
    state.replyTo = null;
    if (!replyBar || !replyText) {
      return;
    }
    replyText.textContent = '';
    replyBar.classList.remove('active');
  }

  async function sendTyping() {
    if (!state.mode || !state.targetId) {
      return;
    }
    const formData = new FormData();
    formData.append('csrf', csrfToken);
    formData.append('mode', state.mode);
    if (state.mode === 'group') {
      formData.append('room_id', state.targetId);
    } else {
      formData.append('user_id', state.targetId);
    }
    await apiPost('api/typing.php', formData);
  }

  function scheduleTyping() {
    if (!state.mode || !state.targetId) {
      return;
    }
    const now = Date.now();
    if (now - lastTypingSent < 1200) {
      return;
    }
    lastTypingSent = now;
    sendTyping().catch(() => {});
  }

  function ensureMentionBox() {
    if (mentionBox) return mentionBox;
    if (!form) return null;
    mentionBox = document.createElement('div');
    mentionBox.className = 'mention-box';
    mentionBox.style.display = 'none';
    form.appendChild(mentionBox);
    return mentionBox;
  }

  function closeMentionBox() {
    if (!mentionBox) return;
    mentionBox.style.display = 'none';
    mentionBox.innerHTML = '';
  }

  function applyMention(username) {
    if (!messageInput) return;
    const v = messageInput.value || '';
    const m = v.match(/(^|\s)@([A-Za-z0-9_]*)$/);
    if (!m) return;
    const prefix = v.slice(0, v.length - m[2].length);
    messageInput.value = `${prefix}${username} `;
    closeMentionBox();
    messageInput.focus();
  }

  function updateMentionSuggestions() {
    if (!messageInput) return;
    const val = messageInput.value || '';
    const m = val.match(/(^|\s)@([A-Za-z0-9_]*)$/);
    if (!m) {
      closeMentionBox();
      return;
    }
    const query = (m[2] || '').toLowerCase();
    const users = Array.from(usersById.values())
      .filter((u) => !query || (u.username || '').toLowerCase().startsWith(query))
      .slice(0, 6);
    const box = ensureMentionBox();
    if (!box || !users.length) {
      closeMentionBox();
      return;
    }
    box.innerHTML = '';
    users.forEach((u) => {
      const item = document.createElement('button');
      item.type = 'button';
      item.className = 'mention-item';
      item.textContent = `@${u.username}`;
      item.addEventListener('click', () => applyMention(u.username));
      box.appendChild(item);
    });
    box.style.display = 'block';
  }

  async function pollTyping() {
    if (!state.mode || !state.targetId) {
      setTypingIndicator('');
      return;
    }
    // همه کاربران typing indicator رو می‌بینن
    if (typingPollInFlight) {
      return;
    }
    typingPollInFlight = true;
    const param = state.mode === 'group' ? 'room_id' : 'user_id';
    const url = `api/typing.php?mode=${state.mode}&${param}=${state.targetId}`;
    try {
      const data = await apiGet(url);
      if (!data || !data.ok) {
        setTypingIndicator('');
        return;
      }
      if (state.mode === 'group') {
        const users = Array.isArray(data.users) ? data.users : [];
        if (users.length) {
          const text = `در حال تایپ: ${users.join('، ')}`;
          setTypingIndicator(text);
        } else {
          setTypingIndicator('');
        }
      } else {
        if (data.typing) {
          const currentUser = usersById.get(state.targetId);
          const displayName = currentUser ? userDisplayName(currentUser) : 'کاربر';
          setTypingIndicator(`${displayName} در حال تایپ ...`);
        } else {
          setTypingIndicator('');
        }
      }
    } finally {
      typingPollInFlight = false;
    }
  }

  async function togglePin(messageId) {
    if (!state.isVip || state.mode !== 'group') {
      return;
    }
    const formData = new FormData();
    formData.append('csrf', csrfToken);
    formData.append('action', state.pinnedId === messageId ? 'unpin' : 'pin');
    formData.append('message_id', String(messageId));
    const res = await apiPost('api/messages.php', formData);
    if (!res.ok) {
      setStatus(res.error || 'عملیات پین انجام نشد.');
      return;
    }
    pollMessages();
  }

  function updateDrawerOverlay() {
    if (!sidebarOverlay || !appRoot) {
      return;
    }
    const active = appRoot.classList.contains('sidebar-open') || appRoot.classList.contains('settings-open');
    sidebarOverlay.classList.toggle('show', active);
    sidebarOverlay.setAttribute('aria-hidden', active ? 'false' : 'true');
  }

  function openSidebar() {
    if (!appRoot) {
      return;
    }
    appRoot.classList.remove('settings-open');
    appRoot.classList.add('sidebar-open');
    updateDrawerOverlay();
  }

  function closeSidebar() {
    if (!appRoot) {
      return;
    }
    appRoot.classList.remove('sidebar-open');
    updateDrawerOverlay();
  }

  function openSettings() {
    if (!appRoot) {
      return;
    }
    appRoot.classList.remove('sidebar-open');
    appRoot.classList.add('settings-open');
    updateDrawerOverlay();
  }

  function closeSettings() {
    if (!appRoot) {
      return;
    }
    appRoot.classList.remove('settings-open');
    updateDrawerOverlay();
  }

  function setHeaderElementVisible(el, visible, displayValue = 'inline-flex') {
    if (!el) {
      return;
    }
    el.style.display = visible ? displayValue : 'none';
    el.style.visibility = visible ? 'visible' : 'hidden';
    el.style.pointerEvents = visible ? 'auto' : 'none';
  }

  function updateRoomControls() {
    if (roomPasswordBtn) {
      let showRoomPassword = false;
      if (state.mode === 'group') {
        const room = roomsById.get(state.targetId);
        if (room && room.can_manage) {
          roomPasswordBtn.textContent = room.has_password ? 'تغییر رمز' : 'تنظیم رمز';
          showRoomPassword = true;
        }
      }
      setHeaderElementVisible(roomPasswordBtn, showRoomPassword);
    }
    if (roomClearBtn) {
      let showClear = window.__isAdmin && state.mode === 'group' && !!state.targetId;
      setHeaderElementVisible(roomClearBtn, showClear);
    }
    if (roomLeaveBtn) {
      let showLeave = false;
      if (state.mode === 'group' && state.targetId) {
        const room = roomsById.get(state.targetId);
        showLeave = room ? !room.can_manage : true;
      }
      setHeaderElementVisible(roomLeaveBtn, showLeave);
    }
    if (roomInviteBtn) {
      let showRoomInvite = false;
      if (state.mode === 'group') {
        const room = roomsById.get(state.targetId);
        if (room && room.can_manage) {
          showRoomInvite = true;
        }
      }
      setHeaderElementVisible(roomInviteBtn, showRoomInvite);
    }
  }

  function getInviteBaseUrl() {
    const path = window.location.pathname || '/';
    const base = path.substring(0, path.lastIndexOf('/') + 1);
    return window.location.origin + base;
  }

  async function loadProfile() {
    const data = await apiGet('api/profile.php');
    if (!data || !data.ok) {
      return;
    }
    if (profileDisplayName) {
      profileDisplayName.value = data.display_name || '';
    }
    if (profileBio) {
      profileBio.value = data.bio || '';
    }
    if (profileAvatarImg) {
      const placeholder = document.getElementById('profile-avatar-placeholder');
      if (data.avatar_url) {
        profileAvatarImg.src = data.avatar_url;
        profileAvatarImg.style.display = 'block';
        if (placeholder) placeholder.style.display = 'none';
      } else {
        profileAvatarImg.src = '';
        profileAvatarImg.style.display = 'none';
        if (placeholder) placeholder.style.display = 'flex';
      }
    }
  }

  function openProfileModal() {
    if (!profileModal) return;
    loadProfile();
    profileModal.classList.add('show');
    profileModal.setAttribute('aria-hidden', 'false');
  }

  function closeProfileModal() {
    if (!profileModal) return;
    profileModal.classList.remove('show');
    profileModal.setAttribute('aria-hidden', 'true');
  }

  function openInviteModal(room) {
    if (!inviteModal || !inviteLinkInput) return;
    const token = room && room.invite_token;
    if (token) {
      inviteLinkInput.value = getInviteBaseUrl() + 'join.php?invite=' + encodeURIComponent(token);
      inviteModal.classList.add('show');
      inviteModal.setAttribute('aria-hidden', 'false');
    } else {
      inviteModal.classList.add('show');
      inviteModal.setAttribute('aria-hidden', 'false');
      inviteLinkInput.value = '';
    }
  }

  function closeInviteModal() {
    if (!inviteModal) return;
    inviteModal.classList.remove('show');
    inviteModal.setAttribute('aria-hidden', 'true');
  }

  async function loadContactProfile(userId) {
    if (!userId) return null;
    const cached = contactProfileCache.get(userId);
    if (cached && cached.expiresAt > Date.now()) {
      return cached.data;
    }
    const data = await apiGet('api/profile.php?user_id=' + encodeURIComponent(userId));
    if (!data || !data.ok) return null;
    contactProfileCache.set(userId, {
      data,
      expiresAt: Date.now() + CONTACT_PROFILE_TTL_MS
    });
    return data;
  }

  function setContactHeader(profile) {
    const avatarWrap = document.querySelector('.chat-hdr-avatar');
    if (!chatContactAvatar) return;
    if (!profile) {
      chatContactAvatar.src = '';
      chatContactAvatar.style.display = 'none';
      if (chatContactStatusDot) chatContactStatusDot.className = 'chat-hdr-dot';
      return;
    }
    const name = profile.display_name || profile.username || '';
    const letter = name.slice(0,1).toUpperCase();
    // set letter placeholder
    if (avatarWrap) {
      avatarWrap.setAttribute('data-letter', letter);
    }
    if (profile.avatar_url) {
      chatContactAvatar.src = profile.avatar_url;
      chatContactAvatar.style.display = 'block';
      if (avatarWrap) avatarWrap.classList.add('has-avatar');
      chatContactAvatar.onerror = () => {
        chatContactAvatar.style.display = 'none';
        if (avatarWrap) avatarWrap.classList.remove('has-avatar');
      };
    } else {
      chatContactAvatar.src = '';
      chatContactAvatar.style.display = 'none';
      if (avatarWrap) avatarWrap.classList.remove('has-avatar');
    }
    chatContactAvatar.alt = name;
    // آپدیت نام
    const titleEl = document.getElementById('chat-title');
    if (titleEl && name) titleEl.textContent = name;
  }

  function openContactProfileModal() {
    if (!contactProfileModal || state.mode !== 'private' || !state.targetId) return;
    loadContactProfile(state.targetId).then((profile) => {
      if (!profile) return;
      if (contactProfileAvatar) contactProfileAvatar.src = profile.avatar_url || avatarUrl;
      if (contactProfileName) contactProfileName.textContent = (profile.display_name && profile.display_name.trim()) ? profile.display_name.trim() : profile.username;
      if (contactProfileUsername) contactProfileUsername.textContent = '@' + (profile.username || '');
      if (contactProfileBio) {
        contactProfileBio.textContent = (profile.bio && profile.bio.trim()) ? profile.bio.trim() : '—';
        contactProfileBio.style.display = (profile.bio && profile.bio.trim()) ? 'block' : 'none';
      }
      contactProfileModal.classList.add('show');
      contactProfileModal.setAttribute('aria-hidden', 'false');

      // id رو از profile یا state بگیر
      const resolvedId = profile.id || (state.mode === 'private' ? state.targetId : null);

      // دکمه‌های عمومی (بلاک / حذف چت)
      const userActionsEl = document.getElementById('contact-user-actions');
      if (userActionsEl && resolvedId && resolvedId !== window.__myUserId) {
        userActionsEl.style.display = 'flex';
        userActionsEl.dataset.userId = String(resolvedId);
        userActionsEl.dataset.username = profile.username || '';
        updateBlockBtn();
      } else if (userActionsEl) {
        userActionsEl.style.display = 'none';
      }

      // دکمه‌های ادمین
      const adminActionsEl = document.getElementById('contact-admin-actions');
      if (adminActionsEl) {
        if (window.__isAdmin && resolvedId && resolvedId !== window.__myUserId) {
          adminActionsEl.style.display = 'flex';
          adminActionsEl.dataset.userId = String(resolvedId);
          adminActionsEl.dataset.username = profile.username || '';
        } else {
          adminActionsEl.style.display = 'none';
        }
      }
    });
  }

  function closeContactProfileModal() {
    if (!contactProfileModal) return;
    contactProfileModal.classList.remove('show');
    contactProfileModal.setAttribute('aria-hidden', 'true');
  }

  function setChatStatus(text, isOnline) {
    if (!chatStatus) {
      return;
    }
    if (!text) {
      chatStatus.textContent = '';
      chatStatus.className = 'chat-status';
      return;
    }
    chatStatus.textContent = text;
    chatStatus.className = `chat-status ${isOnline ? 'online' : 'offline'}`;
    // آپدیت dot در header
    if (chatContactStatusDot) {
      chatContactStatusDot.className = `chat-hdr-dot ${isOnline ? 'online' : ''}`;
    }
  }

  async function apiGet(url) {
    let res;
    try {
      res = await fetch(url, { credentials: 'same-origin' });
    } catch (err) {
      return { ok: false, error: 'ارتباط با سرور برقرار نشد.' };
    }
    try {
      const data = await res.json();
      return data;
    } catch (err) {
      return { ok: false, error: res.ok ? 'پاسخ نامعتبر از سرور.' : 'خطای سرور.' };
    }
  }

  async function apiPost(url, body) {
    let res;
    try {
      res = await fetch(url, {
        method: 'POST',
        body,
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': csrfToken }
      });
    } catch (err) {
      return { ok: false, error: 'ارتباط با سرور برقرار نشد.' };
    }
    try {
      return await res.json();
    } catch (err) {
      return { ok: false, error: res.ok ? 'پاسخ نامعتبر از سرور.' : 'خطای سرور.' };
    }
  }

  function clearMessages() {
    closeMessageActionMenu();
    messagesById.clear();
    messagesEl.innerHTML = '';
    state.oldestMessageId = 0;
    state.hasOlderMessages = true;
    state.isLoadingOlderMessages = false;
    setOlderLoading(false);
  }

  function isMessagesNearBottom(threshold = 120) {
    return (messagesEl.scrollHeight - messagesEl.scrollTop - messagesEl.clientHeight) <= threshold;
  }

  function setOlderLoading(active) {
    if (!olderLoading) {
      return;
    }
    olderLoading.classList.toggle('show', Boolean(active));
    olderLoading.setAttribute('aria-hidden', active ? 'false' : 'true');
  }

  function highlightMessage(id) {
    const el = messagesEl.querySelector(`[data-message-id="${id}"]`);
    if (!el) {
      setStatus('این پیام در لیست فعلی لود نشده است. برای نمایش آن کمی اسکرول کنید یا بعدا دوباره تلاش کنید.');
      return;
    }
    el.classList.add('highlight');
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    setTimeout(() => {
      el.classList.remove('highlight');
    }, 1500);
  }


  // ━━━ Welcome Screen ━━━
  function showWelcomeScreen() {
    if (!messagesEl) return;
    messagesEl.innerHTML = '';
    const w = document.createElement('div');
    w.className = 'welcome-screen';
    w.innerHTML = `
      <div class="welcome-icon">💬</div>
      <div class="welcome-title">خوش آمدید</div>
      <div class="welcome-sub">یک گفتگو یا گروه را از لیست انتخاب کنید</div>
      <button class="welcome-open-sidebar" id="welcome-open-sidebar">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        نمایش گفتگوها
      </button>
    `;
    messagesEl.appendChild(w);
    const titleEl = document.getElementById('chat-title');
    if (titleEl) titleEl.textContent = '';
    // نشون دادن empty state در header
    const _profBtn = document.getElementById('contact-profile-btn');
    const _titleGrp = document.getElementById('chat-title-group');
    const _titleEmpty = document.getElementById('chat-title-empty');
    const _callBtns = document.getElementById('chat-call-btns');
    if (_profBtn) _profBtn.style.display = 'none';
    if (_titleGrp) _titleGrp.style.display = 'none';
    if (_titleEmpty) _titleEmpty.style.display = '';
    if (_callBtns) _callBtns.style.display = 'none';
    // کلیک روی دکمه = باز کردن sidebar
    const btn = document.getElementById('welcome-open-sidebar');
    if (btn) {
      btn.addEventListener('click', () => {
        if (typeof openSidebar === 'function') {
          openSidebar();
        } else {
          const tog = document.getElementById('sidebar-toggle');
          if (tog) tog.click();
        }
      });
    }
  }

  // switching animation
  function switchChat(fn) {
    if (!messagesEl) { fn(); return; }
    messagesEl.classList.add('switching');
    setTimeout(() => {
      fn();
      messagesEl.classList.remove('switching');
    }, 120);
  }

  function setConversation(mode, id, title) {
    if (mode === 'private') {
      const user = usersById.get(id);
      const canPrivate = user && (Boolean(user.can_private_chat) || (user.dm_status || 'none') === 'accepted');
      if (user && !canPrivate) {
        handlePrivateUserAction(user);
        return;
      }
    }
    state.mode = mode;
    state.targetId = id;
    state.lastId = 0;
    // expose برای call system
    window.__chatMode = mode;
    window.__chatTargetId = id || 0;
    window.__chatTargetName = title ? title.replace('خصوصی: ','').replace('گروه: ','') : '';
    // data-mode روی chat-main برای CSS
    const _cm = document.getElementById('chat-main');
    if (_cm) _cm.setAttribute('data-mode', mode || '');
    // آپدیت header
    const _profBtn = document.getElementById('contact-profile-btn');
    const _titleGrp = document.getElementById('chat-title-group');
    const _titleEmpty = document.getElementById('chat-title-empty');
    const _callBtns = document.getElementById('chat-call-btns');
    if (!mode || !id) {
      if (_profBtn) _profBtn.style.display = 'none';
      if (_titleGrp) _titleGrp.style.display = 'none';
      if (_titleEmpty) _titleEmpty.style.display = '';
      if (_callBtns) _callBtns.style.display = 'none';
    } else if (mode === 'private') {
      if (_profBtn) _profBtn.style.display = 'flex';
      if (_titleGrp) _titleGrp.style.display = 'none';
      if (_titleEmpty) _titleEmpty.style.display = 'none';
      if (_callBtns) _callBtns.style.display = 'flex';
    } else {
      if (_profBtn) _profBtn.style.display = 'none';
      if (_titleGrp) {
        _titleGrp.style.display = '';
        _titleGrp.textContent = window.__chatTargetName || title || '';
      }
      if (_titleEmpty) _titleEmpty.style.display = 'none';
      if (_callBtns) _callBtns.style.display = 'none';
    }
    document.dispatchEvent(new Event('chatModeChanged'));
    state.oldestMessageId = 0;
    state.lastReadId = 0;
    state.lastEditedAt = '';
    state.lastDeletedAt = 0;
    state.hasLoadedMessages = false;
    state.hasOlderMessages = true;
    state.isLoadingOlderMessages = false;
    readById.clear();
    messagesEl.classList.toggle('private-chat-mode', mode === 'private');
    renderPinned(null);
    setTypingIndicator('');
    clearReply();
    // نام تمیز بدون prefix
    if (chatTitle) {
      if (!mode || !id) {
        chatTitle.textContent = '';
      } else if (mode === 'private') {
        chatTitle.textContent = window.__chatTargetName || '';
      } else {
        chatTitle.textContent = window.__chatTargetName || title || '';
      }
    }
    updateRoomControls();
    if (messageSearch) {
      messageSearch.value = '';
    }
    closeSearchResults();
    closeMessageActionMenu();
    if (mode === 'private') {
      const user = usersById.get(id);
      if (user) {
        const statusText = user.is_online
          ? 'آنلاین'
          : (user.last_active ? `آخرین بازدید: ${formatLastSeen(user.last_active)}` : 'آفلاین');
        setChatStatus(statusText, user.is_online);
      } else {
        setChatStatus('', false);
      }
      loadContactProfile(id).then((profile) => {
        setContactHeader(profile);
        // اگه profile نداشت، از usersById بخون
        if (!profile && user) {
          const titleEl = document.getElementById('chat-title');
          if (titleEl) titleEl.textContent = user.display_name || user.username || '';
          if (chatContactAvatar) {
            chatContactAvatar.src = user.avatar_url || avatarUrl;
          }
        }
      });
    } else {
      setChatStatus('', false);
      setContactHeader(null);
    }
    closeSidebar();
    closeSettings();
    clearMessages();
    pollMessages();
  }

  function closeSearchResults() {
    if (!searchResults) {
      return;
    }
    searchResults.innerHTML = '';
    searchResults.classList.remove('show');
    searchResultItems = [];
    activeSearchResultIndex = -1;
  }

  function setSearchResultActive(index) {
    if (!searchResultItems.length) {
      activeSearchResultIndex = -1;
      return;
    }
    const next = Math.max(0, Math.min(index, searchResultItems.length - 1));
    activeSearchResultIndex = next;
    searchResultItems.forEach((item, idx) => {
      const isActive = idx === next;
      item.classList.toggle('active', isActive);
      item.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });
    searchResultItems[next].scrollIntoView({ block: 'nearest' });
  }

  function activateSearchResult(index) {
    if (!searchResultItems.length) {
      return;
    }
    const target = searchResultItems[index];
    if (!target) {
      return;
    }
    const messageId = Number(target.dataset.messageId || 0);
    if (!messageId) {
      return;
    }
    highlightMessage(messageId);
    closeSearchResults();
  }

  function renderSearchResults(results) {
    if (!searchResults) {
      return;
    }
    if (!results || !results.length) {
      closeSearchResults();
      return;
    }
    searchResults.innerHTML = '';
    searchResultItems = [];
    activeSearchResultIndex = -1;
    results.forEach((item) => {
      const row = document.createElement('button');
      row.type = 'button';
      row.className = 'search-item';
      row.dataset.messageId = String(item.id);
      row.setAttribute('role', 'option');
      row.setAttribute('aria-selected', 'false');
      const meta = document.createElement('div');
      meta.className = 'search-item-meta';
      meta.textContent = `${item.sender} - ${formatChatTime(item.created_at)}`;
      const txt = document.createElement('div');
      txt.className = 'search-item-text';
      txt.textContent = item.body
        ? truncateText(item.body, 110)
        : (item.file_name ? `فایل: ${item.file_name}` : 'پیام');
      row.appendChild(meta);
      row.appendChild(txt);
      row.addEventListener('click', () => {
        activateSearchResult(searchResultItems.indexOf(row));
      });
      row.addEventListener('mouseenter', () => {
        setSearchResultActive(searchResultItems.indexOf(row));
      });
      searchResults.appendChild(row);
      searchResultItems.push(row);
    });
    if (searchResultItems.length) {
      setSearchResultActive(0);
    }
    searchResults.classList.add('show');
  }

  async function searchMessages(query) {
    if (!state.mode || !state.targetId || !query || query.trim().length < 2) {
      renderSearchResults([]);
      return;
    }
    const param = state.mode === 'group' ? 'room_id' : 'user_id';
    const url = `api/messages.php?action=search&mode=${state.mode}&${param}=${state.targetId}&q=${encodeURIComponent(query.trim())}`;
    const data = await apiGet(url);
    if (!data || !data.ok) {
      renderSearchResults([]);
      return;
    }
    renderSearchResults(data.results || []);
  }

  function appendMessage(msg, options = {}) {
    const prepend = Boolean(options.prepend);
    const scrollToBottom = Boolean(options.scrollToBottom);
    const messageId = Number(msg.id || 0);
    if (messageId > 0) {
      messagesById.set(messageId, msg);
    }
    const existing = messagesEl.querySelector(`[data-message-id="${msg.id}"]`);
    if (existing) {
      return;
    }
    const row = document.createElement('div');
    row.className = msg.is_me ? 'msg me' : 'msg';
    row.dataset.messageId = String(messageId || msg.id);
    // انیمیشن فقط برای پیام‌های جدید (نه لود اولیه)
    if (state.hasLoadedMessages && !options.prepend) {
      row.classList.add('msg-new');
    }
    const isPrivateConversation = state.mode === 'private';
    const isVoiceFile = Boolean(msg.has_file) && isVoiceMessage(msg);

    const avatar = document.createElement('img');
    avatar.className = 'avatar';
    avatar.src = (msg.sender_avatar_url && msg.sender_avatar_url.trim()) ? msg.sender_avatar_url : avatarUrl;
    avatar.onerror = () => { avatar.src = avatarUrl; };

    const bubble = document.createElement('div');
    bubble.className = 'bubble';

    const meta = document.createElement('div');
    meta.className = 'meta';
    if (isPrivateConversation && isVoiceFile) {
      meta.classList.add('voice-meta-hidden');
    }
    const sender = document.createElement('span');
    if (msg.sender_is_admin) {
      sender.className = 'sender admin-name';
    } else if (msg.sender_is_vip) {
      sender.className = 'sender vip-name';
    } else {
      sender.className = 'sender';
    }
    sender.textContent = msg.sender;
    const sep = document.createElement('span');
    sep.className = 'meta-sep';
    sep.textContent = '-';
    const time = document.createElement('span');
    time.className = 'meta-time';
    time.textContent = formatChatTime(msg.created_at);
    if (!isPrivateConversation) {
      meta.appendChild(sender);
      if (msg.sender_is_admin) {
        const badge = document.createElement('span');
        badge.className = msg.sender_vip_label ? 'vip-badge' : 'admin-badge';
        if (msg.sender_vip_label && msg.sender_vip_label.trim()) {
          applyVipBadge(badge, msg.sender_vip_label);
        } else {
          badge.textContent = 'سازنده';
        }
        meta.appendChild(badge);
      } else if (msg.sender_is_vip) {
        const badge = document.createElement('span');
        badge.className = 'vip-badge';
        applyVipBadge(badge, msg.sender_vip_label);
        meta.appendChild(badge);
      }
      meta.appendChild(sep);
    }
    meta.appendChild(time);
    if (isPrivateConversation && msg.is_me && !isVoiceFile) {
      const isRead = Boolean(msg.read_at) || readById.has(msg.id);
      if (msg.read_at) {
        readById.add(msg.id);
      }
      const tick = document.createElement('span');
      tick.className = isRead ? 'tick read' : 'tick';
      tick.textContent = isRead ? '✓✓' : '✓';
      tick.dataset.messageId = String(msg.id);
      meta.appendChild(tick);
    }
    const replyBtn = document.createElement('button');
    replyBtn.type = 'button';
    replyBtn.className = isPrivateConversation
      ? 'reply-btn message-action secondary-action'
      : 'reply-btn message-action primary-action';
    replyBtn.textContent = 'پاسخ';
    replyBtn.title = 'پاسخ';
    replyBtn.setAttribute('aria-label', 'پاسخ');
    replyBtn.addEventListener('click', () => {
      setReply(msg);
    });
    meta.appendChild(replyBtn);
    if (state.mode === 'group' && state.isVip) {
      const pinBtn = document.createElement('button');
      pinBtn.type = 'button';
      pinBtn.className = 'pin-btn';
      pinBtn.textContent = state.pinnedId === msg.id ? 'برداشتن پین' : 'پین';
      pinBtn.title = 'پین پیام';
      pinBtn.setAttribute('aria-label', 'پین پیام');
      pinBtn.dataset.messageId = String(msg.id);
      pinBtn.addEventListener('click', () => {
        togglePin(msg.id);
      });
      meta.appendChild(pinBtn);
    }
    if (msg.is_me) {
      const deleteBtn = document.createElement('button');
      deleteBtn.type = 'button';
      deleteBtn.className = 'delete-btn message-action secondary-action';
      deleteBtn.textContent = 'حذف';
      deleteBtn.title = 'حذف پیام';
      deleteBtn.setAttribute('aria-label', 'حذف پیام');
      deleteBtn.addEventListener('click', () => {
        deleteMessage(msg.id);
      });
      meta.appendChild(deleteBtn);
      const editBtn = document.createElement('button');
      editBtn.type = 'button';
      editBtn.className = 'edit-btn message-action secondary-action';
      editBtn.textContent = 'ویرایش';
      editBtn.addEventListener('click', () => {
        editMessage(msg.id, msg.body || '');
      });
      meta.appendChild(editBtn);
    }
    const copyBtn = document.createElement('button');
    copyBtn.type = 'button';
    copyBtn.className = 'copy-btn message-action secondary-action';
    copyBtn.textContent = 'کپی';
    copyBtn.addEventListener('click', async () => {
      const txt = msg.body || (msg.file_name ? `فایل: ${msg.file_name}` : '');
      if (!txt) return;
      try {
        await navigator.clipboard.writeText(txt);
        setStatus('کپی شد.');
      } catch (e) {
        setStatus('کپی نشد.');
      }
    });
    meta.appendChild(copyBtn);
    const fwdBtn = document.createElement('button');
    fwdBtn.type = 'button';
    fwdBtn.className = 'fwd-btn message-action secondary-action';
    fwdBtn.textContent = 'فوروارد';
    fwdBtn.addEventListener('click', () => forwardMessage(msg.id));
    meta.appendChild(fwdBtn);

    bubble.appendChild(meta);

    if (msg.forwarded_from_id) {
      const fw = document.createElement('div');
      fw.className = 'reply-preview';
      fw.textContent = `فوروارد شده (از پیام #${msg.forwarded_from_id})`;
      fw.addEventListener('click', () => highlightMessage(msg.forwarded_from_id));
      bubble.appendChild(fw);
    }

    if (msg.reply_to_id) {
      const reply = document.createElement('div');
      reply.className = 'reply-preview';
      const replySender = document.createElement('div');
      replySender.className = 'reply-sender';
      replySender.textContent = msg.reply_sender || 'پیام';
      const replyBody = document.createElement('div');
      replyBody.className = 'reply-text';
      if (msg.reply_body) {
        if (parseStickerToken(msg.reply_body)) {
          replyBody.textContent = 'اموجی';
        } else {
          replyBody.textContent = truncateText(msg.reply_body, 80);
        }
      } else if (msg.reply_file_name) {
        replyBody.textContent = `فایل: ${msg.reply_file_name}`;
      } else {
        replyBody.textContent = 'پیام';
      }
      reply.appendChild(replySender);
      reply.appendChild(replyBody);
      reply.addEventListener('click', () => {
        highlightMessage(msg.reply_to_id);
      });
      bubble.appendChild(reply);
    }

    if (msg.body) {
      const stickerPath = parseStickerToken(msg.body);
      if (stickerPath) {
        const sticker = document.createElement('img');
        sticker.className = 'preview';
        sticker.src = stickerPath;
        sticker.alt = 'emoji';
        sticker.dataset.messageBody = '1';
        bubble.appendChild(sticker);
      } else {
        const text = document.createElement('div');
        text.className = 'text';
        text.innerHTML = formatMentionsToHtml(msg.body);
        text.dataset.messageBody = '1';
        bubble.appendChild(text);
      }
      if (msg.edited_at) {
        const edited = document.createElement('div');
        edited.className = 'seen-by';
        edited.textContent = 'ویرایش شده';
        edited.dataset.editedMarker = '1';
        bubble.appendChild(edited);
      }
    }

    if (msg.has_file) {
      const isImage = isImageFile(msg);
      const isAudio = isAudioFile(msg);
      const isVoice = isVoiceMessage(msg);
      const fileName = msg.file_name ? `فایل: ${msg.file_name}` : 'فایل';

      if (isVoice) {
        bubble.classList.add('voice-bubble');
        bubble.appendChild(buildVoiceMessage(msg, isPrivateConversation));
      } else {
        const info = document.createElement('div');
        info.className = 'file-name';
        info.textContent = fileName;
        bubble.appendChild(info);

        const actions = document.createElement('div');
        actions.className = 'file-actions';

        const view = document.createElement('a');
        view.className = 'file';
        view.href = `view.php?m=${msg.id}`;
        view.target = '_blank';
        view.rel = 'noopener';
        if (isImage) {
          view.textContent = 'مشاهده تصویر';
        } else if (isAudio) {
          view.textContent = 'گوش دادن';
        } else {
          view.textContent = 'مشاهده فایل';
        }
        actions.appendChild(view);

        const download = document.createElement('a');
        download.className = 'file';
        download.href = `download.php?m=${msg.id}`;
        download.textContent = 'دانلود';
        actions.appendChild(download);

        bubble.appendChild(actions);

        if (isImage) {
          const preview = document.createElement('img');
          preview.className = 'preview';
          preview.src = `view.php?m=${msg.id}`;
          preview.alt = msg.file_name ? `تصویر ${msg.file_name}` : 'تصویر';
          bubble.appendChild(preview);
        } else if (isAudio) {
          const audio = document.createElement('audio');
          audio.className = 'audio';
          audio.controls = true;
          audio.src = `view.php?m=${msg.id}`;
          bubble.appendChild(audio);
        }
      }
    }

    if (state.mode === 'group' && msg.seen_count && msg.seen_count > 0) {
      const seen = document.createElement('div');
      seen.className = 'seen-by';
      let text = msg.seen_preview ? `مشاهده شده توسط ${msg.seen_preview}` : 'مشاهده شده';
      let previewCount = 0;
      if (msg.seen_preview) {
        previewCount = msg.seen_preview.split(',').length;
      }
      const extra = msg.seen_count - previewCount;
      if (extra > 0) {
        text += ` و ${extra} نفر دیگر`;
      }
      seen.textContent = text;
      bubble.appendChild(seen);
    }

    const reactions = Array.isArray(msg.reactions) ? msg.reactions : [];
    if (reactions.length) {
      const rx = document.createElement('div');
      rx.className = 'reactions';
      reactions.forEach((r) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = r.mine ? 'reaction mine' : 'reaction';
        b.textContent = `${r.emoji} ${r.count}`;
        b.addEventListener('click', () => toggleReaction(msg.id, r.emoji, Boolean(r.mine)));
        rx.appendChild(b);
      });
      bubble.appendChild(rx);
    }
    const rxQuick = document.createElement('div');
    rxQuick.className = 'reactions quick';
    ['👍', '❤️', '😂'].forEach((emo) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'reaction';
      b.textContent = emo;
      const existing = reactions.find((r) => r.emoji === emo);
      b.addEventListener('click', () => toggleReaction(msg.id, emo, Boolean(existing && existing.mine)));
      rxQuick.appendChild(b);
    });
    bubble.appendChild(rxQuick);

    row.appendChild(avatar);
    row.appendChild(bubble);

    if (prepend && messagesEl.firstChild) {
      messagesEl.insertBefore(row, messagesEl.firstChild);
    } else {
      messagesEl.appendChild(row);
    }

    if (scrollToBottom) {
      messagesEl.scrollTop = messagesEl.scrollHeight;
    }
  }

  async function loadOlderMessages() {
    if (!state.mode || !state.targetId) {
      return;
    }
    if (state.isLoadingOlderMessages || !state.hasOlderMessages) {
      return;
    }
    if (!state.oldestMessageId || state.oldestMessageId <= 0) {
      return;
    }

    state.isLoadingOlderMessages = true;
    setOlderLoading(true);
    const pollKey = `${state.mode}:${state.targetId}`;
    const prevHeight = messagesEl.scrollHeight;
    const prevTop = messagesEl.scrollTop;
    const param = state.mode === 'group' ? 'room_id' : 'user_id';
    const readParam = state.mode === 'private' ? `&read_since_id=${state.lastReadId}` : '';
    const deleteParam = `&deleted_since=${state.lastDeletedAt}`;
    const editedParam = state.lastEditedAt ? `&edited_since=${encodeURIComponent(state.lastEditedAt)}` : '';
    const url = `api/messages.php?mode=${state.mode}&${param}=${state.targetId}&before_id=${state.oldestMessageId}${readParam}${deleteParam}${editedParam}`;

    try {
      const data = await apiGet(url);
      if (`${state.mode}:${state.targetId}` !== pollKey) {
        return;
      }
      if (!data.ok) {
        setStatus(data.error || 'بارگذاری پیام‌های قدیمی انجام نشد.');
        return;
      }
      applyEditedMessages(data.edited_messages || []);
      updateEditedCursor(data.edited_last || '');
      updateEditedCursor(data.server_now_text || '');

      const older = Array.isArray(data.messages) ? data.messages : [];
      if (older.length) {
        older.forEach((msg) => appendMessage(msg, { prepend: true, scrollToBottom: false }));
        const firstOlderId = Number(older[0].id || 0);
        if (firstOlderId > 0 && (!state.oldestMessageId || firstOlderId < state.oldestMessageId)) {
          state.oldestMessageId = firstOlderId;
        }
        const newHeight = messagesEl.scrollHeight;
        messagesEl.scrollTop = Math.max(0, prevTop + (newHeight - prevHeight));
      }

      if (Object.prototype.hasOwnProperty.call(data, 'has_older') && data.has_older !== null) {
        state.hasOlderMessages = Boolean(data.has_older);
      } else if (!older.length) {
        state.hasOlderMessages = false;
      }

      if (data.deleted_ids && data.deleted_ids.length) {
        data.deleted_ids.forEach((id) => removeMessageById(id));
        if (data.deleted_last && data.deleted_last > state.lastDeletedAt) {
          state.lastDeletedAt = data.deleted_last;
        }
      } else if (!state.lastDeletedAt && data.server_now) {
        state.lastDeletedAt = data.server_now;
      }

      if (data.read_updates && data.read_updates.length) {
        data.read_updates.forEach((id) => {
          readById.add(id);
          const tick = document.querySelector(`.tick[data-message-id="${id}"]`);
          if (tick) {
            tick.textContent = '✓✓';
            tick.classList.add('read');
          }
          if (id > state.lastReadId) {
            state.lastReadId = id;
          }
        });
      }
    } finally {
      state.isLoadingOlderMessages = false;
      setOlderLoading(false);
    }
  }

  async function pollMessages() {
    if (!state.mode || !state.targetId) {
      return;
    }
    if (isPolling) {
      return;
    }
    isPolling = true;
    const pollKey = `${state.mode}:${state.targetId}`;
    const param = state.mode === 'group' ? 'room_id' : 'user_id';
    const readParam = state.mode === 'private' ? `&read_since_id=${state.lastReadId}` : '';
    const deleteParam = `&deleted_since=${state.lastDeletedAt}`;
    const editedParam = state.lastEditedAt ? `&edited_since=${encodeURIComponent(state.lastEditedAt)}` : '';
    const initialParam = state.lastId === 0 ? '&initial=1' : '';
    const url = `api/messages.php?mode=${state.mode}&${param}=${state.targetId}&since_id=${state.lastId}${readParam}${deleteParam}${editedParam}${initialParam}`;
    try {
      const data = await apiGet(url);
      if (`${state.mode}:${state.targetId}` !== pollKey) {
        return;
      }
      if (!data.ok) {
        setStatus(data.error || 'بارگذاری پیام‌ها ناموفق بود.');
        return;
      }
      setStatus('');
      applyEditedMessages(data.edited_messages || []);
      updateEditedCursor(data.edited_last || '');
      updateEditedCursor(data.server_now_text || '');
      if (state.mode === 'group' && Object.prototype.hasOwnProperty.call(data, 'pinned')) {
        renderPinned(data.pinned);
      }
      if (data.messages.length) {
        const shouldStickToBottom = !state.hasLoadedMessages || isMessagesNearBottom();
        data.messages.forEach((msg) => appendMessage(msg, { scrollToBottom: false }));
        if (shouldStickToBottom) {
          messagesEl.scrollTop = messagesEl.scrollHeight;
        }
        const incoming = data.messages.filter((msg) => !msg.is_me);
        // فقط اگه قبلاً یه بار لود شده باشه و lastId > 0 باشه notification بده
        if (incoming.length && state.hasLoadedMessages && state.lastId > 0) {
          const isPrivate = state.mode === 'private';
          const senderName = incoming[0].sender || incoming[0].username || incoming[0].display_name || 'کاربر';
          const title = isPrivate
            ? `پیام جدید از ${senderName}`
            : `پیام جدید در ${chatTitle.textContent}`;
          const lastIncoming = incoming[incoming.length - 1];
          const body = formatMessagePreview(lastIncoming);
          const kind = isPrivate ? 'private' : 'group';
          if (shouldAlert(kind, body)) {
            playNotificationSound('message');
            showToast(title, body);
            showNotification(title, body);
          }
        }
        const firstId = Number(data.messages[0].id || 0);
        if (firstId > 0 && (!state.oldestMessageId || firstId < state.oldestMessageId)) {
          state.oldestMessageId = firstId;
        }
        const lastId = data.messages[data.messages.length - 1].id;
        if (lastId > state.lastId) {
          state.lastId = lastId;
        }
      }
      if (Object.prototype.hasOwnProperty.call(data, 'has_older') && data.has_older !== null) {
        state.hasOlderMessages = Boolean(data.has_older);
      }
      if (!state.hasLoadedMessages) {
        state.hasLoadedMessages = true;
      }
      if (data.deleted_ids && data.deleted_ids.length) {
        data.deleted_ids.forEach((id) => {
          removeMessageById(id);
        });
        if (data.deleted_last && data.deleted_last > state.lastDeletedAt) {
          state.lastDeletedAt = data.deleted_last;
        }
      } else if (!state.lastDeletedAt && data.server_now) {
        state.lastDeletedAt = data.server_now;
      }

      if (data.read_updates && data.read_updates.length) {
        data.read_updates.forEach((id) => {
          readById.add(id);
          const tick = document.querySelector(`.tick[data-message-id="${id}"]`);
          if (tick) {
            tick.textContent = '✓✓';
            tick.classList.add('read');
          }
          if (id > state.lastReadId) {
            state.lastReadId = id;
          }
        });
      }
    } finally {
      isPolling = false;
    }
  }

  async function loadRooms() {
    const data = await apiGet('api/rooms.php');
    if (!data.ok) {
      return;
    }
    if (!roomList) return;
    roomList.innerHTML = '';
    roomsById.clear();
    data.rooms.forEach((room) => {
      roomsById.set(room.id, room);
      const li = document.createElement('li');
      const btn = document.createElement('button');
      btn.className = room.joined ? 'item' : 'item muted';
      if (room.vip_only && !state.isVip) {
        btn.className = 'item muted';
      }
      const wrap = document.createElement('div');
      wrap.className = 'item-row';
      const name = document.createElement('span');
      name.className = 'item-name';
      name.textContent = room.name + (room.has_password ? ' 🔒' : '');
      wrap.appendChild(name);
      if (room.vip_only) {
        const vip = document.createElement('span');
        vip.className = 'vip-badge';
        vip.textContent = 'VIP';
        wrap.appendChild(vip);
      }
      if (room.unread_count && room.unread_count > 0) {
        const badge = document.createElement('span');
        badge.className = 'badge';
        badge.textContent = String(room.unread_count);
        wrap.appendChild(badge);
      }
      btn.appendChild(wrap);
      btn.addEventListener('click', async () => {
        if (room.vip_only && !state.isVip) {
          setStatus('این اتاق فقط برای VIP است.');
          return;
        }
        if (!room.joined) {
          let roomPassword = '';
          if (room.has_password) {
            roomPassword = prompt('رمز اتاق را وارد کنید:') || '';
            if (!roomPassword) {
              return;
            }
          }
          const formData = new FormData();
          formData.append('csrf', csrfToken);
          formData.append('action', 'join');
          formData.append('room_id', room.id);
          if (roomPassword) {
            formData.append('password', roomPassword);
          }
          const joinRes = await apiPost('api/rooms.php', formData);
          if (joinRes.ok) {
            await loadRooms();
            setConversation('group', room.id, `گروه: ${room.name}`);
          } else {
            setStatus(joinRes.error || 'عضویت در اتاق انجام نشد.');
          }
          return;
        }
        setConversation('group', room.id, `گروه: ${room.name}`);
      });
      li.appendChild(btn);
      roomList.appendChild(li);
    });
    updateRoomControls();

    if (roomsFirstLoad) {
      data.rooms.forEach((room) => prevUnreadRooms.set(room.id, room.unread_count));
      roomsFirstLoad = false;
    }
    data.rooms.forEach((room) => {
      const prev = prevUnreadRooms.get(room.id);
      if (prev !== undefined && room.unread_count > prev) {
        if (state.mode !== 'group' || state.targetId !== room.id) {
          const body = formatPreviewFromParts(room.last_body, room.last_file_name);
          if (shouldAlert('group', body)) {
            playNotificationSound('message');
            showToast(`پیام جدید در ${room.name}`, body, {
              mode: 'group',
              id: room.id,
              title: `گروه: ${room.name}`
            });
            showNotification(`پیام جدید در ${room.name}`, body);
          }
        }
      }
      prevUnreadRooms.set(room.id, room.unread_count);
    });
 
    buildRecentChats();
  }

  function userDisplayName(user) {
    return (user.display_name && user.display_name.trim()) ? user.display_name.trim() : user.username;
  }

  function privateStateLabel(status) {
    switch (status) {
      case 'accepted':
        return 'مجاز';
      case 'incoming':
        return 'درخواست';
      case 'outgoing':
        return 'در انتظار';
      default:
        return 'قفل';
    }
  }

  async function sendPrivateRequest(userId) {
    const formData = new FormData();
    formData.append('csrf', csrfToken);
    formData.append('action', 'send');
    formData.append('user_id', String(userId));
    const res = await apiPost('api/private_requests.php', formData);
    if (!res.ok) {
      setStatus(res.error || 'ارسال درخواست خصوصی انجام نشد.');
      return false;
    }
    setStatus('درخواست خصوصی ارسال شد.', 'success');
    await loadUsers(userSearch ? userSearch.value : undefined);
    await loadPrivateRequests();
    return true;
  }

  async function respondPrivateRequest(requestId, decision) {
    const formData = new FormData();
    formData.append('csrf', csrfToken);
    formData.append('action', 'respond');
    formData.append('request_id', String(requestId));
    formData.append('decision', decision);
    const res = await apiPost('api/private_requests.php', formData);
    if (!res.ok) {
      setStatus(res.error || 'پاسخ به درخواست انجام نشد.');
      return false;
    }
    setStatus(decision === 'accept' ? 'درخواست پذیرفته شد.' : 'درخواست رد شد.', 'success');
    await loadUsers(userSearch ? userSearch.value : undefined);
    await loadPrivateRequests();
    return true;
  }

  async function cancelPrivateRequest(userId, requestId = 0) {
    const formData = new FormData();
    formData.append('csrf', csrfToken);
    formData.append('action', 'cancel');
    if (requestId) {
      formData.append('request_id', String(requestId));
    }
    formData.append('user_id', String(userId));
    const res = await apiPost('api/private_requests.php', formData);
    if (!res.ok) {
      setStatus(res.error || 'لغو درخواست انجام نشد.');
      return false;
    }
    setStatus('درخواست خصوصی لغو شد.', 'success');
    await loadUsers(userSearch ? userSearch.value : undefined);
    await loadPrivateRequests();
    return true;
  }

  async function handlePrivateUserAction(user) {
    if (!user || !user.id) {
      return;
    }
    const displayName = userDisplayName(user);
    const dmStatus = user.dm_status || 'none';
    const canPrivate = Boolean(user.can_private_chat) || dmStatus === 'accepted';

    if (canPrivate) {
      setConversation('private', user.id, `خصوصی: ${displayName}`);
      return;
    }

    if (dmStatus === 'incoming' && user.dm_request_id) {
      openActionModal({
        title: `درخواست خصوصی: ${displayName}`,
        submitText: 'ثبت',
        render: (host) => {
          const text = document.createElement('div');
          text.className = 'action-modal-hint';
          text.textContent = `${displayName} درخواست گفتگوی خصوصی داده است.`;
          const decision = document.createElement('select');
          decision.className = 'action-modal-input';
          decision.innerHTML = `
            <option value="accept">قبول</option>
            <option value="reject">رد</option>
          `;
          host.appendChild(text);
          host.appendChild(decision);
          return { decision };
        },
        onSubmit: async ({ decision }) => {
          const chosen = (decision && decision.value) ? decision.value : 'accept';
          const ok = await respondPrivateRequest(user.dm_request_id, chosen);
          if (ok && chosen === 'accept') {
            const latest = usersById.get(user.id) || user;
            setConversation('private', user.id, `خصوصی: ${userDisplayName(latest)}`);
          }
          return ok;
        }
      });
      return;
    }

    if (dmStatus === 'outgoing') {
      if (confirm(`درخواست شما برای ${displayName} در انتظار تایید است. لغو شود؟`)) {
        await cancelPrivateRequest(user.id, Number(user.dm_request_id || 0));
      } else {
        setStatus('درخواست همچنان در انتظار تایید است.');
      }
      return;
    }

    await sendPrivateRequest(user.id);
  }

  function renderPrivateRequestList(requests) {
    if (!privateRequestList) {
      return;
    }
    privateRequestList.innerHTML = '';
    if (!requests || !requests.length) {
      return;
    }

    requests.forEach((req) => {
      const li = document.createElement('li');

      const wrap = document.createElement('div');
      wrap.className = 'dm-request-card';

      // آواتار
      const av = document.createElement('div');
      av.className = 'ui-avatar dm-req-avatar';
      av.textContent = (req.display_name || req.username || '?')[0].toUpperCase();
      wrap.appendChild(av);

      // نام + یوزرنیم
      const col = document.createElement('div');
      col.className = 'ui-col';
      const nameEl = document.createElement('div');
      nameEl.className = 'ui-name';
      nameEl.style.fontSize = '13px';
      nameEl.textContent = req.display_name || req.username || `کاربر ${req.user_id}`;
      const subEl = document.createElement('div');
      subEl.className = 'ui-sub';
      subEl.textContent = `@${req.username} · میخواهد گفتگو کند`;
      col.appendChild(nameEl);
      col.appendChild(subEl);
      wrap.appendChild(col);

      // دکمه‌های قبول/رد
      const actions = document.createElement('div');
      actions.className = 'dm-req-actions';

      const acceptBtn = document.createElement('button');
      acceptBtn.type = 'button';
      acceptBtn.className = 'dm-req-accept';
      acceptBtn.textContent = 'بله، چت کن';
      acceptBtn.addEventListener('click', async (e) => {
        e.stopPropagation();
        const ok = await respondPrivateRequest(req.request_id, 'accept');
        if (!ok) return;
        const fresh = usersById.get(req.user_id);
        const title = fresh ? userDisplayName(fresh) : (req.display_name || req.username);
        setConversation('private', req.user_id, `خصوصی: ${title}`);
      });

      const rejectBtn = document.createElement('button');
      rejectBtn.type = 'button';
      rejectBtn.className = 'dm-req-reject';
      rejectBtn.textContent = 'نه';
      rejectBtn.addEventListener('click', async (e) => {
        e.stopPropagation();
        await respondPrivateRequest(req.request_id, 'reject');
      });

      actions.appendChild(acceptBtn);
      actions.appendChild(rejectBtn);
      wrap.appendChild(actions);
      li.appendChild(wrap);
      privateRequestList.appendChild(li);
    });
  }

  async function loadPrivateRequests() {
    const data = await apiGet('api/private_requests.php');
    if (!data || !data.ok) {
      return;
    }
    const incoming = Array.isArray(data.incoming) ? data.incoming : [];
    // نمایش/مخفی بخش درخواست‌ها
    if (sbRequestsSection) {
      sbRequestsSection.style.display = incoming.length > 0 ? '' : 'none';
    }
    if (requestsCount) {
      requestsCount.textContent = incoming.length > 0 ? String(incoming.length) : '';
    }
    renderPrivateRequestList(incoming);

    const nextIds = new Set();
    incoming.forEach((req) => {
      const id = Number(req.request_id || 0);
      if (id > 0) nextIds.add(id);
    });

    // notification برای درخواست‌های جدید — همیشه نشون بده
    incoming.forEach((req) => {
      const id = Number(req.request_id || 0);
      if (id > 0 && !prevIncomingRequestIds.has(id)) {
        const fromName = req.display_name || req.username || 'کاربر';
        try { playNotificationSound('message'); } catch(_) {}
        showToast(
          `📩 درخواست گفتگو از ${fromName}`,
          'برای قبول یا رد کلیک کنید',
          {
            onClick: () => {
              openActionModal({
                title: `درخواست گفتگو از ${fromName}`,
                submitText: 'قبول',
                render: (host) => {
                  const txt = document.createElement('p');
                  txt.style.cssText = 'margin:0 0 16px;line-height:1.8;font-size:14px';
                  txt.textContent = `${fromName} می‌خواهد با شما گفتگو کند.`;
                  const btns = document.createElement('div');
                  btns.style.cssText = 'display:flex;gap:10px;justify-content:flex-end';
                  const yesBtn = document.createElement('button');
                  yesBtn.className = 'btn-primary';
                  yesBtn.style.cssText = 'padding:8px 20px;border-radius:8px;font-size:13px';
                  yesBtn.textContent = '✅ قبول';
                  const noBtn = document.createElement('button');
                  noBtn.className = 'btn-danger';
                  noBtn.style.cssText = 'padding:8px 20px;border-radius:8px;font-size:13px;border:1px solid rgba(231,76,60,0.5);background:rgba(231,76,60,0.1);color:#e74c3c;cursor:pointer';
                  noBtn.textContent = '❌ رد';
                  yesBtn.addEventListener('click', async () => {
                    const ok = await respondPrivateRequest(id, 'accept');
                    document.querySelectorAll('.action-modal').forEach(m => m.closest('.modal-overlay')?.classList.remove('show'));
                    if (ok) setConversation('private', req.user_id, `خصوصی: ${fromName}`);
                    await loadPrivateRequests();
                  });
                  noBtn.addEventListener('click', async () => {
                    await respondPrivateRequest(id, 'reject');
                    document.querySelectorAll('.action-modal').forEach(m => m.closest('.modal-overlay')?.classList.remove('show'));
                    await loadPrivateRequests();
                  });
                  btns.appendChild(yesBtn);
                  btns.appendChild(noBtn);
                  host.appendChild(txt);
                  host.appendChild(btns);
                  return {};
                },
                onSubmit: async () => true
              });
            }
          }
        );
      }
    });

    prevIncomingRequestIds.clear();
    nextIds.forEach((id) => prevIncomingRequestIds.add(id));
  }


  // ━━━ VIP label parser ━━━
  function parseVipLabel(raw) {
    if (!raw) return { label: 'VIP', color: 'gold' };
    const m = raw.match(/^\[([a-z]+)\](.+)$/);
    if (m) return { label: m[2], color: m[1] };
    return { label: raw, color: 'gold' };
  }

  function applyVipBadge(el, rawLabel) {
    const { label, color } = parseVipLabel(rawLabel);
    el.textContent = label;
    el.dataset.color = color;
  }


  // ━━━ گفتگوهای اخیر (گروه + خصوصی کنار هم) ━━━
  function buildRecentChats() {
    if (!recentChatsList) return;

    const items = [];

    // گروه‌هایی که unread دارن یا آخرین پیام دارن
    (Array.from(roomsById.values()) || []).forEach(room => {
      if (!room.joined) return;
      const lastMsg = room.last_message || room.last_body || '';
      const unread  = room.unread_count || 0;
      const ts      = room.last_message_at || null;
      items.push({
        type: 'group',
        id: room.id,
        name: room.name,
        sub: lastMsg ? truncateText(lastMsg, 35) : '',
        unread,
        ts,
        avatar: null,
        avatarLetter: (room.name || '?')[0].toUpperCase(),
        onClick: () => setConversation('group', room.id, `گروه: ${room.name}`)
      });
    });

    // کاربرانی که unread دارن
    (Array.from(usersById.values()) || []).forEach(user => {
      const unread = user.unread_count || 0;
      if (unread === 0) return;
      const canPrivate = Boolean(user.can_private_chat) || (user.dm_status || 'none') === 'accepted';
      if (!canPrivate) return;
      items.push({
        type: 'private',
        id: user.id,
        name: userDisplayName(user),
        sub: user.last_body || '',
        unread,
        ts: user.last_active,
        avatar: user.avatar_url,
        avatarLetter: userDisplayName(user).slice(0,1).toUpperCase(),
        isOnline: user.is_online,
        onClick: () => setConversation('private', user.id, `خصوصی: ${userDisplayName(user)}`)
      });
    });

    // مرتب‌سازی: unread اول، بعد زمان
    items.sort((a, b) => {
      if (b.unread !== a.unread) return b.unread - a.unread;
      if (a.ts && b.ts) return new Date(b.ts) - new Date(a.ts);
      return 0;
    });

    recentChatsList.innerHTML = '';

    const sbSection = document.getElementById('sb-recent-section');
    if (items.length === 0) {
      if (sbSection) sbSection.style.display = 'none';
      return;
    }
    if (sbSection) sbSection.style.display = '';

    items.slice(0, 12).forEach(item => {
      const li  = document.createElement('li');
      const btn = document.createElement('button');
      btn.className = 'item sb-item';

      const row = document.createElement('div');
      row.className = 'user-item';

      // آواتار
      const avWrap = document.createElement('div');
      avWrap.className = 'ui-avatar-wrap';
      const av = document.createElement('div');
      av.className = item.type === 'group' ? 'ui-avatar group-av' : 'ui-avatar';
      if (item.avatar) {
        const img = document.createElement('img');
        img.src = item.avatar;
        img.alt = '';
        img.onerror = () => { av.removeChild(img); av.textContent = item.avatarLetter; };
        av.appendChild(img);
      } else {
        av.textContent = item.avatarLetter;
      }
      avWrap.appendChild(av);
      if (item.isOnline) {
        const dot = document.createElement('span');
        dot.className = 'ui-online-dot';
        avWrap.appendChild(dot);
      }
      row.appendChild(avWrap);

      // متن
      const col = document.createElement('div');
      col.className = 'ui-col';
      const nameEl = document.createElement('div');
      nameEl.className = 'ui-name';
      nameEl.textContent = item.name;
      col.appendChild(nameEl);
      if (item.sub) {
        const subEl = document.createElement('div');
        subEl.className = 'ui-sub';
        subEl.textContent = item.sub;
        col.appendChild(subEl);
      }
      row.appendChild(col);

      // unread badge
      if (item.unread > 0) {
        const aside = document.createElement('div');
        aside.className = 'ui-aside';
        const badge = document.createElement('span');
        badge.className = 'ui-unread';
        badge.textContent = item.unread > 99 ? '99+' : String(item.unread);
        aside.appendChild(badge);
        row.appendChild(aside);
      }

      btn.appendChild(row);
      btn.addEventListener('click', () => {
        item.onClick();
        // مطمئن بشیم sidebar بسته شده
        setTimeout(() => { if (typeof closeSidebar === 'function') closeSidebar(); }, 50);
      });
      li.appendChild(btn);
      recentChatsList.appendChild(li);
    });
  }

  async function loadUsers(searchQuery) {
    const url = (typeof searchQuery === 'string' && searchQuery.trim() !== '')
      ? 'api/users.php?q=' + encodeURIComponent(searchQuery.trim())
      : 'api/users.php';
    const data = await apiGet(url);
    if (!data.ok) return;
    if (!userList) return;

    userList.innerHTML = '';
    usersById.clear();
    (data.users || []).forEach((u) => usersById.set(u.id, u));

    const myId = window.__myUserId || 0;
    const canPrivateForList = (u) => Boolean(u.can_private_chat) || (u.dm_status || 'none') === 'accepted';

    // ── ساخت آیتم یوزر ──
    function buildUserItem(user, isSelf) {
      const li  = document.createElement('li');
      const btn = document.createElement('button');
      btn.className = 'item';
      const canPrivate = isSelf || Boolean(user.can_private_chat) || (user.dm_status||'none') === 'accepted';

      const wrap = document.createElement('div');
      wrap.className = 'user-item';

      // آواتار
      const thumbWrap = document.createElement('div');
      thumbWrap.className = 'ui-avatar-wrap';
      const thumb = document.createElement('div');
      thumb.className = 'ui-avatar';
      if (user.avatar_url) {
        const img = document.createElement('img');
        img.src = user.avatar_url; img.alt = '';
        img.onerror = () => { thumb.removeChild(img); thumb.textContent = userDisplayName(user).slice(0,1).toUpperCase(); };
        thumb.appendChild(img);
      } else {
        thumb.textContent = userDisplayName(user).slice(0,1).toUpperCase();
      }
      thumbWrap.appendChild(thumb);
      if (user.is_online || isSelf) {
        const dot = document.createElement('span');
        dot.className = 'ui-online-dot';
        thumbWrap.appendChild(dot);
      }
      wrap.appendChild(thumbWrap);

      // ستون متن
      const col = document.createElement('div');
      col.className = 'ui-col';
      const nameRow = document.createElement('div');
      nameRow.className = 'ui-name-row';
      const nameEl = document.createElement('span');
      nameEl.className = 'ui-name';
      // نام کاربر: display_name اگه داشت، وگرنه username
      nameEl.textContent = user.display_name || userDisplayName(user);
      if (user.is_admin) nameEl.classList.add('admin-name');
      else if (user.is_vip) nameEl.classList.add('vip-name');
      nameRow.appendChild(nameEl);

      // badge
      if (user.is_admin) {
        const b = document.createElement('span');
        b.className = 'ui-role-badge admin-badge';
        if (user.vip_label && user.vip_label.trim()) applyVipBadge(b, user.vip_label);
        else b.textContent = 'سازنده';
        nameRow.appendChild(b);
      } else if (user.is_vip) {
        const b = document.createElement('span');
        b.className = 'ui-role-badge vip-badge';
        applyVipBadge(b, user.vip_label);
        nameRow.appendChild(b);
      }
      // badge فقط برای admin و vip
      col.appendChild(nameRow);

      const subRow = document.createElement('div');
      subRow.className = (user.is_online || isSelf) ? 'ui-sub online' : 'ui-sub';
      if (isSelf) subRow.textContent = 'پیام‌های ذخیره‌شده';
      else if (user.is_online) subRow.textContent = 'آنلاین';
      else if (user.last_active) subRow.textContent = formatLastSeen(user.last_active);
      col.appendChild(subRow);
      wrap.appendChild(col);

      // aside: unread + دکمه DM
      const aside = document.createElement('div');
      aside.className = 'ui-aside';

      if (!isSelf && user.unread_count > 0) {
        const unread = document.createElement('span');
        unread.className = 'ui-unread';
        unread.textContent = user.unread_count > 99 ? '99+' : String(user.unread_count);
        aside.appendChild(unread);
      }

      // دکمه DM — فقط برای کاربران غیر ادمین که هنوز ارتباط برقرار نشده
      if (!isSelf && !user.is_admin) {
        const rawDm = user.dm_status || 'none';
        if (!canPrivate || rawDm === 'outgoing') {
          const dmBtn = document.createElement('button');
          dmBtn.type = 'button';
          dmBtn.addEventListener('click', (e) => { e.stopPropagation(); handlePrivateUserAction(user); });
          if (rawDm === 'outgoing') {
            dmBtn.className = 'dm-req-btn outgoing';
            dmBtn.textContent = '⏳';
            dmBtn.title = 'درخواست ارسال شده — برای لغو کلیک کنید';
          } else {
            dmBtn.className = 'dm-req-btn';
            dmBtn.textContent = '💬';
            dmBtn.title = 'ارسال درخواست گفتگوی خصوصی';
          }
          aside.appendChild(dmBtn);
        }
      }

      wrap.appendChild(aside);
      btn.appendChild(wrap);
      btn.addEventListener('click', () => {
        if (isSelf) {
          // Saved Messages: چت به خودش
          setConversation('private', myId, 'پیام‌های ذخیره‌شده');
        } else {
          handlePrivateUserAction(user);
        }
      });
      li.appendChild(btn);
      return li;
    }

    function appendSep(label) {
      const sep = document.createElement('li');
      sep.className = 'list-sep';
      sep.textContent = label;
      userList.appendChild(sep);
    }

    const allUsers = data.users || [];
    const admins   = allUsers.filter(u => u.is_admin);
    const others   = allUsers.filter(u => !u.is_admin);
    const unread   = others.filter(u => canPrivateForList(u) && Number(u.unread_count||0) > 0)
                           .sort((a,b) => Number(b.unread_count)-Number(a.unread_count));
    const unreadIds = new Set(unread.map(u => u.id));
    const online   = others.filter(u => u.is_online && !unreadIds.has(u.id))
                           .sort((a,b) => userDisplayName(a).localeCompare(userDisplayName(b),'fa'));
    const offline  = others.filter(u => !u.is_online && !unreadIds.has(u.id))
                           .sort((a,b) => {
                             const ta = a.last_active ? new Date(a.last_active).getTime() : 0;
                             const tb = b.last_active ? new Date(b.last_active).getTime() : 0;
                             return tb - ta;
                           });

    // ── ۰. درخواست‌های دریافتی ──
    const incomingUsers = allUsers.filter(u =>
      (u.dm_status || 'none') === 'incoming' && u.id !== myId
    );
    if (incomingUsers.length) {
      appendSep('📩 درخواست‌های گفتگو (' + incomingUsers.length + ')');
      incomingUsers.forEach(u => {
        const li   = document.createElement('li');
        const wrap = document.createElement('div');
        wrap.className = 'dm-request-card';

        // آواتار
        const av = document.createElement('div');
        av.className = 'ui-avatar dm-req-avatar';
        if (u.avatar_url) {
          const img = document.createElement('img');
          img.src = u.avatar_url; img.alt = '';
          img.onerror = () => { av.removeChild(img); av.textContent = (u.display_name || u.username || '?')[0].toUpperCase(); };
          av.appendChild(img);
        } else {
          av.textContent = (u.display_name || u.username || '?')[0].toUpperCase();
        }
        wrap.appendChild(av);

        // ستون نام — کامل بدون truncate
        const col = document.createElement('div');
        col.className = 'dm-req-col';
        const nameEl = document.createElement('div');
        nameEl.className = 'dm-req-name';
        nameEl.textContent = u.display_name || u.username || `کاربر ${u.id}`;
        const subEl = document.createElement('div');
        subEl.className = 'dm-req-sub';
        subEl.textContent = '@' + u.username;
        col.appendChild(nameEl);
        col.appendChild(subEl);
        wrap.appendChild(col);

        // دکمه‌های ✓ و ✗
        const actions = document.createElement('div');
        actions.className = 'dm-req-actions';

        const acceptBtn = document.createElement('button');
        acceptBtn.type = 'button';
        acceptBtn.className = 'dm-req-accept';
        acceptBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
        acceptBtn.title = 'قبول';
        acceptBtn.addEventListener('click', async (e) => {
          e.stopPropagation();
          const ok = await respondPrivateRequest(Number(u.dm_request_id), 'accept');
          if (ok) setConversation('private', u.id, `خصوصی: ${u.display_name || u.username}`);
          await loadPrivateRequests();
        });

        const rejectBtn = document.createElement('button');
        rejectBtn.type = 'button';
        rejectBtn.className = 'dm-req-reject';
        rejectBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
        rejectBtn.title = 'رد';
        rejectBtn.addEventListener('click', async (e) => {
          e.stopPropagation();
          await respondPrivateRequest(Number(u.dm_request_id), 'reject');
          await loadPrivateRequests();
        });

        actions.appendChild(acceptBtn);
        actions.appendChild(rejectBtn);
        wrap.appendChild(actions);
        li.appendChild(wrap);
        userList.appendChild(li);
      });
    }

    // ── ۱. Saved Messages (خودم) ──
    if (myId > 0) {
      const meUser = allUsers.find(u => u.id === myId) || {
        id: myId,
        username: state.meUsername || 'من',
        display_name: state.meDisplayName || null,
        is_online: true, avatar_url: null,
        vip_label: '', is_admin: window.__isAdmin, is_vip: false
      };
      appendSep('پیام‌های ذخیره‌شده');
      userList.appendChild(buildUserItem(meUser, true));
    }

    // ── ۲. ادمین‌ها ──
    if (admins.length) {
      appendSep('مدیران');
      admins.sort((a,b) => userDisplayName(a).localeCompare(userDisplayName(b),'fa'))
            .forEach(u => userList.appendChild(buildUserItem(u, false)));
    }

    // ── ۳. خوانده‌نشده ──
    if (unread.length) {
      appendSep('خوانده‌نشده');
      unread.forEach(u => userList.appendChild(buildUserItem(u, false)));
    }

    // ── ۴. آنلاین ──
    if (online.length) {
      appendSep('آنلاین');
      online.forEach(u => userList.appendChild(buildUserItem(u, false)));
    }

    // ── ۵. آفلاین ──
    if (offline.length) {
      appendSep('آفلاین');
      offline.forEach(u => userList.appendChild(buildUserItem(u, false)));
    }

    // ── notifications & tracking ──
    allUsers.forEach((user) => {
      const prev = prevUnreadUsers.get(user.id);
      const canPrivate = Boolean(user.can_private_chat) || (user.dm_status||'none') === 'accepted';
      if (canPrivate && prev !== undefined && user.unread_count > prev) {
        if (state.mode !== 'private' || state.targetId !== user.id) {
          const body = formatPreviewFromParts(user.last_body, user.last_file_name);
          if (shouldAlert('private', body)) {
            playNotificationSound('message');
            showToast(`پیام جدید از ${userDisplayName(user)}`, body, { mode: 'private', id: user.id, title: `خصوصی: ${userDisplayName(user)}` });
            showNotification(`پیام جدید از ${userDisplayName(user)}`, body);
          }
        }
      }
      prevUnreadUsers.set(user.id, user.unread_count);

      if (usersLoadedOnce) {
        const wasOnline = prevOnlineUsers.get(user.id);
        if (wasOnline === false && user.is_online && shouldAlert('presence','')) {
          playToneSound('online');
          showToast(`🟢 ${userDisplayName(user)} آنلاین شد`, 'در دسترس است', { mode:'private', id:user.id, title:`خصوصی: ${userDisplayName(user)}` });
        } else if (wasOnline === true && !user.is_online && state.mode === 'private' && state.targetId === user.id && shouldAlert('presence','')) {
          playToneSound('offline');
          showToast(`⚫ ${userDisplayName(user)} آفلاین شد`, 'آخرین بازدید الان', { mode:'private', id:user.id, title:`خصوصی: ${userDisplayName(user)}` });
        }
      }
      prevOnlineUsers.set(user.id, user.is_online);
    });

    if (!usersLoadedOnce) usersLoadedOnce = true;

    if (state.mode === 'private') {
      const current = usersById.get(state.targetId);
      const canPrivate = current && (Boolean(current.can_private_chat) || (current.dm_status||'none') === 'accepted');
      if (current && canPrivate) setChatStatus(current.is_online ? 'آنلاین' : 'آفلاین', current.is_online);
      else if (current && !canPrivate) { setChatStatus('', false); setStatus('پیام خصوصی تا زمان تایید درخواست قفل است.'); }
    }

    buildRecentChats();
  }
  
  // ━━━ File Preview ━━━
  let filePreviewEl = null;

  function removeFilePreview() {
    if (filePreviewEl) { filePreviewEl.remove(); filePreviewEl = null; }
  }

  function showFilePreview(file) {
    removeFilePreview();
    const bar = document.createElement('div');
    bar.className = 'file-preview-bar';
    bar.dir = 'rtl';

    const isImg = file.type.startsWith('image/');
    const isAudio = file.type.startsWith('audio/');
    const isVideo = file.type.startsWith('video/');

    // thumbnail
    const thumb = document.createElement('div');
    thumb.className = 'fp-thumb';

    if (isImg) {
      const img = document.createElement('img');
      img.src = URL.createObjectURL(file);
      img.onload = () => URL.revokeObjectURL(img.src);
      thumb.appendChild(img);
    } else if (isAudio) {
      thumb.textContent = '🎵';
    } else if (isVideo) {
      thumb.textContent = '🎬';
    } else {
      thumb.textContent = '📎';
    }

    const info = document.createElement('div');
    info.className = 'fp-info';

    const name = document.createElement('span');
    name.className = 'fp-name';
    name.textContent = file.name;

    const size = document.createElement('span');
    size.className = 'fp-size';
    const kb = file.size / 1024;
    size.textContent = kb > 1024 ? `${(kb/1024).toFixed(1)} MB` : `${Math.round(kb)} KB`;

    info.appendChild(name);
    info.appendChild(size);

    const close = document.createElement('button');
    close.className = 'fp-close';
    close.textContent = '✕';
    close.title = 'حذف فایل';
    close.addEventListener('click', () => {
      fileInput.value = '';
      fileNameText.textContent = 'فایلی انتخاب نشده';
      removeFilePreview();
    });

    bar.appendChild(thumb);
    bar.appendChild(info);
    bar.appendChild(close);

    // درج بالای send-form
    const sendForm = document.getElementById('send-form') || form;
    if (sendForm && sendForm.parentNode) {
      sendForm.parentNode.insertBefore(bar, sendForm);
    }
    filePreviewEl = bar;
  }

async function sendMessagePayload(text, file) {
    if (!state.mode || !state.targetId) {
      setStatus('ابتدا یک گفتگو را انتخاب کنید.');
      return false;
    }
    if (state.mode === 'private') {
      const myId = window.__myUserId || 0;
      // Saved Messages: به خودت پیام دادن همیشه مجاز
      if (state.targetId !== myId) {
        const targetUser = usersById.get(state.targetId);
        const canPrivate = targetUser && (Boolean(targetUser.can_private_chat) || (targetUser.dm_status || 'none') === 'accepted');
        if (targetUser && !canPrivate) {
          setStatus('پیام خصوصی تا زمان تایید درخواست قفل است.');
          return false;
        }
      }
    }
    if (isSending) {
      return false;
    }
    setSending(true);

    const formData = new FormData();
    formData.append('csrf', csrfToken);
    formData.append('mode', state.mode);
    formData.append('message', text || '');
    if (state.mode === 'group') {
      formData.append('room_id', state.targetId);
    } else {
      formData.append('user_id', state.targetId);
    }
    if (state.replyTo && state.replyTo.id) {
      formData.append('reply_to_id', state.replyTo.id);
    }
    if (file) {
      formData.append('file', file, file.name || 'voice.webm');
    }

    try {
      if (file) {
        showUploadProgress(0);
      } else {
        hideUploadProgress();
      }
      const res = file
        ? await apiPostWithProgress('api/messages.php', formData, showUploadProgress)
        : await apiPost('api/messages.php', formData);
      if (!res.ok) {
        if (res.blocked) {
          // نشون دادن پیام بلاک در چت
          if (messagesEl) {
            const note = document.createElement('div');
            note.className = 'call-ended-note';
            const name = state.mode === 'private'
              ? (usersById.get(state.targetId)?.username || 'کاربر')
              : '';
            note.innerHTML = `<span>🚫 ارسال پیام به @${name} امکانپذیر نیست</span>`;
            messagesEl.appendChild(note);
            note.scrollIntoView({ behavior: 'smooth', block: 'end' });
          }
        } else {
          setStatus(res.error || 'ارسال انجام نشد.');
        }
        return false;
      }
      setStatus('');
      return true;
    } finally {
      hideUploadProgress();
      setSending(false);
    }
  }

  async function sendFileOnly(file) {
    if (!file) {
      return false;
    }
    const ok = await sendMessagePayload('', file);
    if (ok) {
      if (fileInput) {
        fileInput.value = '';
      }
      if (fileNameText) {
        fileNameText.textContent = 'فایلی انتخاب نشده';
      }
      clearReply();
      pollMessages();
    }
    return ok;
  }

  function audioExtensionFromMime(mime) {
    if (!mime) {
      return 'webm';
    }
    const lower = mime.toLowerCase();
    if (lower.includes('wav')) {
      return 'wav';
    }
    if (lower.includes('ogg')) {
      return 'ogg';
    }
    if (lower.includes('mpeg')) {
      return 'mp3';
    }
    if (lower.includes('mp4')) {
      return 'm4a';
    }
    if (lower.includes('webm')) {
      return 'webm';
    }
    return 'webm';
  }

  function pickRecorderMime() {
    if (!window.MediaRecorder || typeof MediaRecorder.isTypeSupported !== 'function') {
      return '';
    }
    const types = [
      'audio/mp4;codecs=mp4a.40.2',
      'audio/mp4',
      'audio/mpeg'
    ];
    for (const type of types) {
      if (MediaRecorder.isTypeSupported(type)) {
        return type;
      }
    }
    return '';
  }

  function mergeBuffers(buffers) {
    let length = 0;
    buffers.forEach((buffer) => {
      length += buffer.length;
    });
    const result = new Float32Array(length);
    let offset = 0;
    buffers.forEach((buffer) => {
      result.set(buffer, offset);
      offset += buffer.length;
    });
    return result;
  }

  function downsampleBuffer(buffer, sampleRate, outRate) {
    if (outRate >= sampleRate) {
      return buffer;
    }
    const sampleRateRatio = sampleRate / outRate;
    const newLength = Math.round(buffer.length / sampleRateRatio);
    const result = new Float32Array(newLength);
    let offsetResult = 0;
    let offsetBuffer = 0;
    while (offsetResult < result.length) {
      const nextOffsetBuffer = Math.round((offsetResult + 1) * sampleRateRatio);
      let accum = 0;
      let count = 0;
      for (let i = offsetBuffer; i < nextOffsetBuffer && i < buffer.length; i++) {
        accum += buffer[i];
        count += 1;
      }
      result[offsetResult] = count ? accum / count : 0;
      offsetResult += 1;
      offsetBuffer = nextOffsetBuffer;
    }
    return result;
  }

  function writeString(view, offset, text) {
    for (let i = 0; i < text.length; i++) {
      view.setUint8(offset + i, text.charCodeAt(i));
    }
  }

  function encodeWav(samples, sampleRate) {
    const buffer = new ArrayBuffer(44 + samples.length * 2);
    const view = new DataView(buffer);
    writeString(view, 0, 'RIFF');
    view.setUint32(4, 36 + samples.length * 2, true);
    writeString(view, 8, 'WAVE');
    writeString(view, 12, 'fmt ');
    view.setUint32(16, 16, true);
    view.setUint16(20, 1, true);
    view.setUint16(22, 1, true);
    view.setUint32(24, sampleRate, true);
    view.setUint32(28, sampleRate * 2, true);
    view.setUint16(32, 2, true);
    view.setUint16(34, 16, true);
    writeString(view, 36, 'data');
    view.setUint32(40, samples.length * 2, true);
    let offset = 44;
    for (let i = 0; i < samples.length; i++) {
      let s = Math.max(-1, Math.min(1, samples[i]));
      s = s < 0 ? s * 0x8000 : s * 0x7fff;
      view.setInt16(offset, s, true);
      offset += 2;
    }
    return new Blob([view], { type: 'audio/wav' });
  }

  function startMediaRecording(mime) {
    try {
      mediaRecorder = mime ? new MediaRecorder(recordingStream, { mimeType: mime }) : new MediaRecorder(recordingStream);
      recordingMime = mediaRecorder && mediaRecorder.mimeType ? mediaRecorder.mimeType : mime;
    } catch (err) {
      resetVoiceState();
      setStatus('امکان شروع ضبط وجود ندارد.');
      return;
    }

    recorderMode = 'media';
    audioChunks = [];
    mediaRecorder.ondataavailable = (event) => {
      if (event.data && event.data.size > 0) {
        audioChunks.push(event.data);
      }
    };
    mediaRecorder.onstop = async () => {
      if (voiceCancelled) {
        voiceCancelled = false;
        resetVoiceState();
        return;
      }
      const mimeType = recordingMime || (mediaRecorder && mediaRecorder.mimeType ? mediaRecorder.mimeType : 'audio/mp4');
      const blob = new Blob(audioChunks, { type: mimeType });
      const ext = audioExtensionFromMime(mimeType);
      const file = new File([blob], `voice-${Date.now()}.${ext}`, { type: mimeType });
      setStatus('در حال ارسال صدا...');
      await sendMessagePayload('', file);
      pollMessages();
      resetVoiceState();
    };

    mediaRecorder.start();
    isRecording = true;
    setVoiceButtonState('stop');
    // voice-cancel-btn حذف شده - swipe bar جایگزین است
    setStatus('در حال ضبط صدا...');
  }

  function startWavRecording() {
    const AudioCtx = window.AudioContext || window.webkitAudioContext;
    if (!AudioCtx) {
      resetVoiceState();
      setStatus('امکان ضبط صدا در این مرورگر وجود ندارد.');
      return;
    }
    try {
      audioContext = new AudioCtx();
      wavSampleRate = audioContext.sampleRate;
      sourceNode = audioContext.createMediaStreamSource(recordingStream);
      processorNode = audioContext.createScriptProcessor(4096, 1, 1);
      silenceNode = audioContext.createGain();
      silenceNode.gain.value = 0;
      wavBuffers = [];
      processorNode.onaudioprocess = (event) => {
        const input = event.inputBuffer.getChannelData(0);
        wavBuffers.push(new Float32Array(input));
      };
      sourceNode.connect(processorNode);
      processorNode.connect(silenceNode);
      silenceNode.connect(audioContext.destination);
    } catch (err) {
      resetVoiceState();
      setStatus('امکان شروع ضبط وجود ندارد.');
      return;
    }

    recorderMode = 'wav';
    isRecording = true;
    setVoiceButtonState('stop');
    setStatus('در حال ضبط صدا...');
  }

  async function stopWavRecording() {
    if (voiceCancelled) {
      voiceCancelled = false;
      resetVoiceState();
      return;
    }
    if (!audioContext || !processorNode) {
      resetVoiceState();
      return;
    }
    try {
      processorNode.disconnect();
      if (sourceNode) sourceNode.disconnect();
      if (silenceNode) silenceNode.disconnect();
      await audioContext.close();
    } catch (err) {}
    processorNode = null;
    sourceNode = null;
    silenceNode = null;
    audioContext = null;

    if (voiceCancelled) { voiceCancelled = false; resetVoiceState(); return; }

    const merged = mergeBuffers(wavBuffers);
    const targetRate = 16000;
    const downsampled = downsampleBuffer(merged, wavSampleRate, targetRate);
    const blob = encodeWav(downsampled, targetRate);
    const file = new File([blob], `voice-${Date.now()}.wav`, { type: 'audio/wav' });
    setStatus('در حال ارسال صدا...');
    await sendMessagePayload('', file);
    pollMessages();
    resetVoiceState();
  }

  function resetVoiceState() {
    if (recordingStream) {
      recordingStream.getTracks().forEach((track) => track.stop());
      recordingStream = null;
    }
    if (processorNode) {
      processorNode.disconnect();
      processorNode = null;
    }
    if (sourceNode) {
      sourceNode.disconnect();
      sourceNode = null;
    }
    if (silenceNode) {
      silenceNode.disconnect();
      silenceNode = null;
    }
    if (audioContext) {
      audioContext.close();
      audioContext = null;
    }
    mediaRecorder = null;
    audioChunks = [];
    wavBuffers = [];
    wavSampleRate = 0;
    recordingMime = '';
    recorderMode = '';
    isRecording = false;
    setVoiceButtonState('voice');
    const hintBar2 = document.getElementById('voice-swipe-hint-bar');
    if (hintBar2) hintBar2.style.display = 'none';
  }

  async function startRecording() {
    if (!window.isSecureContext) {
      setStatus('برای ضبط صدا باید سایت روی https باشد.');
      return;
    }
    if (!state.mode || !state.targetId) {
      setStatus('ابتدا یک گفتگو را انتخاب کنید.');
      return;
    }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      setStatus('مرورگر شما ضبط صدا را پشتیبانی نمی کند.');
      return;
    }
    try {
      recordingStream = await navigator.mediaDevices.getUserMedia({ audio: true });
    } catch (err) {
      resetVoiceState();
      setStatus('اجازه دسترسی به میکروفون داده نشد.');
      return;
    }

    const preferredMime = pickRecorderMime();
    if (preferredMime) {
      startMediaRecording(preferredMime);
      return;
    }

    startWavRecording();
  }

  function stopRecording() {
    if (!isRecording) {
      return;
    }
    if (recorderMode === 'media' && mediaRecorder) {
      mediaRecorder.stop();
      isRecording = false;
      return;
    }
    if (recorderMode === 'wav') {
      isRecording = false;
      stopWavRecording();
    }
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const text = messageInput.value.trim();
    const file = fileInput.files[0];
    if (!text && !file) {
      return;
    }

    const ok = await sendMessagePayload(text, file);
    if (!ok) {
      return;
    }

    messageInput.value = '';
    removeFilePreview();
    if (messageInput instanceof HTMLTextAreaElement) {
      const minHeight = window.matchMedia('(max-width: 900px)').matches ? 34 : 44;
      messageInput.style.height = `${minHeight}px`;
      messageInput.style.overflowY = 'hidden';
    }
    fileInput.value = '';
    if (fileNameText) {
      fileNameText.textContent = 'فایلی انتخاب نشده';
    }
    clearReply();
    updateTypingPlaceholder();
    pollMessages();
  });

  createRoomBtn.addEventListener('click', async () => {
    const name = prompt('نام اتاق (۳ تا ۶۰ کاراکتر):');
    if (!name) {
      return;
    }
    const password = (prompt('اگر می خواهید اتاق رمزدار باشد، رمز را وارد کنید (اختیاری):') || '').trim();
    let vipOnly = false;
    if (state.isVip) {
      vipOnly = confirm('اتاق فقط VIP باشد؟');
    }
    const formData = new FormData();
    formData.append('csrf', csrfToken);
    formData.append('action', 'create');
    formData.append('name', name.trim());
    if (password) {
      formData.append('password', password);
    }
    if (vipOnly) {
      formData.append('vip_only', '1');
    }

    const res = await apiPost('api/rooms.php', formData);
    if (res.ok) {
      await loadRooms();
      setConversation('group', res.room_id, `گروه: ${name.trim()}`);
    } else {
      setStatus(res.error || 'ایجاد اتاق انجام نشد.');
    }
  });

  if (roomPasswordBtn) {
    roomPasswordBtn.addEventListener('click', async () => {
      if (state.mode !== 'group') {
        return;
      }
      const room = roomsById.get(state.targetId);
      if (!room || !room.can_manage) {
        setStatus('شما اجازه تغییر رمز این اتاق را ندارید.');
        return;
      }
      const promptText = room.has_password
        ? 'رمز جدید را وارد کنید (خالی = حذف رمز):'
        : 'برای رمزدار کردن اتاق، رمز را وارد کنید (خالی = بدون رمز):';
      const password = prompt(promptText);
      if (password === null) {
        return;
      }
      const trimmed = password.trim();
      const formData = new FormData();
      formData.append('csrf', csrfToken);
      formData.append('action', 'set_password');
      formData.append('room_id', state.targetId);
      formData.append('password', trimmed);

      const res = await apiPost('api/rooms.php', formData);
      if (res.ok) {
        setStatus(trimmed ? 'رمز اتاق ذخیره شد.' : 'رمز اتاق حذف شد.');
        await loadRooms();
      } else {
        setStatus(res.error || 'تغییر رمز انجام نشد.');
      }
    });
  }

  if (roomInviteBtn) {
    roomInviteBtn.addEventListener('click', async () => {
      if (state.mode !== 'group') return;
      const room = roomsById.get(state.targetId);
      if (!room || !room.can_manage) return;
      let token = room.invite_token;
      if (!token) {
        const formData = new FormData();
        formData.append('csrf', csrfToken);
        formData.append('action', 'generate_invite');
        formData.append('room_id', state.targetId);
        const res = await apiPost('api/rooms.php', formData);
        if (!res.ok) {
          setStatus(res.error || 'ساخت لینک دعوت انجام نشد.');
          return;
        }
        token = res.invite_token;
        room.invite_token = token;
      }
      if (inviteLinkInput) {
        inviteLinkInput.value = getInviteBaseUrl() + 'join.php?invite=' + encodeURIComponent(token);
      }
      openInviteModal(room);
    });
  }

  if (roomClearBtn) {
    roomClearBtn.addEventListener('click', async () => {
      if (!state.targetId) return;
      if (!confirm('همه پیام‌های این گروه پاک شود؟')) return;
      const fd = new FormData();
      fd.append('csrf', csrfToken);
      fd.append('action', 'clear_messages');
      fd.append('room_id', state.targetId);
      const res = await apiPost('api/rooms.php', fd);
      if (res.ok) {
        showToast('🗑️ چت پاک شد', '');
        // ریست کامل state و reload پیام‌ها
        state.lastId = 0;
        state.hasLoadedMessages = false;
        state.oldestMessageId = null;
        if (messagesEl) messagesEl.innerHTML = '';
        pollMessages();
      }
      else setStatus(res.error || 'عملیات انجام نشد.');
    });
  }

  if (roomLeaveBtn) {
    roomLeaveBtn.addEventListener('click', async () => {
      if (state.mode !== 'group' || !state.targetId) return;
      const room = roomsById.get(state.targetId);
      const name = room ? room.name : 'این گروه';
      if (!confirm(`از گروه "${name}" خارج شوید؟`)) return;
      const fd = new FormData();
      fd.append('csrf', csrfToken);
      fd.append('action', 'leave');
      fd.append('room_id', state.targetId);
      const res = await apiPost('api/rooms.php', fd);
      if (res.ok) {
        setConversation(null, null, '');
        await loadRooms();
        showToast('✅ از گروه خارج شدید', name);
      } else {
        setStatus(res.error || 'خروج از گروه انجام نشد.');
      }
    });
  }

  if (profileBtn) {
    profileBtn.addEventListener('click', () => openProfileModal());
  }
  if (profileModalClose) {
    profileModalClose.addEventListener('click', () => closeProfileModal());
  }
  if (profileModal) {
    profileModal.addEventListener('click', (e) => {
      if (e.target === profileModal) closeProfileModal();
    });
  }
  if (profileSave) {
    profileSave.addEventListener('click', async () => {
      const formData = new FormData();
      formData.append('csrf', csrfToken);
      formData.append('action', 'update');
      formData.append('display_name', (profileDisplayName && profileDisplayName.value) ? profileDisplayName.value.trim() : '');
      formData.append('bio', (profileBio && profileBio.value) ? profileBio.value.trim() : '');
      const res = await apiPost('api/profile.php', formData);
      if (res.ok) {
        setStatus('پروفایل ذخیره شد.');
        closeProfileModal();
      } else {
        setStatus(res.error || 'ذخیره انجام نشد.');
      }
    });
  }
  if (profileAvatarInput) {
    profileAvatarInput.addEventListener('change', async () => {
      const file = profileAvatarInput.files[0];
      if (!file) return;
      const optimizedFile = await optimizeAvatarUpload(file);
      const fd = new FormData();
      fd.append('csrf', csrfToken);
      fd.append('action', 'upload_avatar');
      fd.append('avatar', optimizedFile, optimizedFile.name || file.name);
      const res = await apiPost('api/profile.php', fd);
      if (res.ok) {
        await loadProfile();
        // مطمئن بشیم placeholder مخفی شده
        const ph = document.getElementById('profile-avatar-placeholder');
        if (ph) ph.style.display = 'none';
        setStatus('عکس پروفایل به‌روز شد.');
      } else {
        setStatus(res.error || 'آپلود عکس انجام نشد.');
      }
      profileAvatarInput.value = '';
    });
  }
  if (inviteModalClose) {
    inviteModalClose.addEventListener('click', () => closeInviteModal());
  }
  if (inviteModal) {
    inviteModal.addEventListener('click', (e) => {
      if (e.target === inviteModal) closeInviteModal();
    });
  }
  if (inviteCopyBtn && inviteLinkInput) {
    inviteCopyBtn.addEventListener('click', async () => {
      try {
        await navigator.clipboard.writeText(inviteLinkInput.value);
        setStatus('لینک کپی شد.');
      } catch (err) {
        inviteLinkInput.select();
        setStatus('لینک انتخاب شد؛ با Ctrl+C کپی کنید.');
      }
    });
  }
  if (inviteGenerateBtn) {
    inviteGenerateBtn.addEventListener('click', async () => {
      if (state.mode !== 'group') return;
      const formData = new FormData();
      formData.append('csrf', csrfToken);
      formData.append('action', 'generate_invite');
      formData.append('room_id', state.targetId);
      const res = await apiPost('api/rooms.php', formData);
      if (!res.ok) {
        setStatus(res.error || 'ساخت لینک جدید انجام نشد.');
        return;
      }
      const room = roomsById.get(state.targetId);
      if (room) room.invite_token = res.invite_token;
      if (inviteLinkInput) {
        inviteLinkInput.value = getInviteBaseUrl() + 'join.php?invite=' + encodeURIComponent(res.invite_token);
      }
      setStatus('لینک جدید ساخته شد.');
    });
  }
  if (userSearch) {
    userSearch.addEventListener('input', () => {
      if (userSearchTimeout) clearTimeout(userSearchTimeout);
      userSearchTimeout = setTimeout(() => {
        loadUsers(userSearch.value);
      }, 300);
    });
  }

  if (messageSearch) {
    messageSearch.addEventListener('input', () => {
      if (messageSearchTimeout) clearTimeout(messageSearchTimeout);
      const query = messageSearch.value || '';
      messageSearchTimeout = setTimeout(() => {
        searchMessages(query);
      }, 280);
    });
    messageSearch.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        closeSearchResults();
        return;
      }
      if (!searchResults || !searchResults.classList.contains('show') || !searchResultItems.length) {
        return;
      }
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        setSearchResultActive(activeSearchResultIndex + 1);
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        setSearchResultActive(activeSearchResultIndex - 1);
      } else if (e.key === 'Enter') {
        e.preventDefault();
        activateSearchResult(activeSearchResultIndex >= 0 ? activeSearchResultIndex : 0);
      }
    });
    messageSearch.addEventListener('focus', () => {
      const query = (messageSearch.value || '').trim();
      if (query.length >= 2 && (!searchResults || !searchResults.classList.contains('show'))) {
        searchMessages(query);
      }
    });
  }

  if (notifyModeSelect) {
    notifyModeSelect.addEventListener('change', () => {
      setNotifyMode(notifyModeSelect.value);
    });
  }

  if (contactProfileBtn) {
    contactProfileBtn.addEventListener('click', () => openContactProfileModal());
  }
  if (contactProfileModalClose) {
    contactProfileModalClose.addEventListener('click', () => closeContactProfileModal());
  }
  if (contactProfileModal) {
    contactProfileModal.addEventListener('click', (e) => {
      if (e.target === contactProfileModal) closeContactProfileModal();
    });
  }

  if (sidebarToggle) {
    sidebarToggle.addEventListener('click', () => {
      if (appRoot && appRoot.classList.contains('sidebar-open')) {
        closeSidebar();
      } else {
        openSidebar();
      }
    });
  }

  if (settingsToggle) {
    settingsToggle.addEventListener('click', () => {
      if (appRoot && appRoot.classList.contains('settings-open')) {
        closeSettings();
      } else {
        openSettings();
      }
    });
  }

  if (settingsClose) {
    settingsClose.addEventListener('click', () => {
      closeSettings();
    });
  }

  if (sidebarOverlay) {
    sidebarOverlay.addEventListener('click', () => {
      closeSidebar();
      closeSettings();
    });
  }

  if (fileInput && fileNameText) {
    fileInput.addEventListener('change', () => {
      const file = fileInput.files[0];
      if (!file) {
        fileNameText.textContent = 'فایلی انتخاب نشده';
        removeFilePreview();
        return;
      }
      fileNameText.textContent = file.name;
      showFilePreview(file);
    });
  }

  if (chatMain && dropOverlay) {
    chatMain.addEventListener('dragover', (e) => {
      e.preventDefault();
      e.stopPropagation();
      if (e.dataTransfer.types.includes('Files')) {
        dropOverlay.classList.add('show');
        dropOverlay.setAttribute('aria-hidden', 'false');
      }
    });
    chatMain.addEventListener('dragleave', (e) => {
      if (!chatMain.contains(e.relatedTarget)) {
        dropOverlay.classList.remove('show');
        dropOverlay.setAttribute('aria-hidden', 'true');
      }
    });
    chatMain.addEventListener('drop', async (e) => {
      e.preventDefault();
      e.stopPropagation();
      dropOverlay.classList.remove('show');
      dropOverlay.setAttribute('aria-hidden', 'true');
      const files = e.dataTransfer.files;
      if (!files || files.length === 0) {
        return;
      }
      const file = files[0];
      if (!state.mode || !state.targetId) {
        setStatus('ابتدا یک گفتگو را انتخاب کنید.');
        return;
      }
      await sendFileOnly(file);
    });
  }

  document.addEventListener('paste', async (e) => {
    const items = e.clipboardData && e.clipboardData.items;
    if (!items) {
      return;
    }
    for (let i = 0; i < items.length; i++) {
      const item = items[i];
      if (item.kind === 'file' && item.type.startsWith('image/')) {
        e.preventDefault();
        const file = item.getAsFile();
        if (file && state.mode && state.targetId) {
          await sendFileOnly(file);
        } else if (!state.mode || !state.targetId) {
          setStatus('ابتدا یک گفتگو را انتخاب کنید.');
        }
        return;
      }
    }
  });

  if (pasteBtn) {
    pasteBtn.addEventListener('click', async () => {
      if (!state.mode || !state.targetId) {
        setStatus('ابتدا یک گفتگو را انتخاب کنید.');
        return;
      }
      try {
        const items = navigator.clipboard && await navigator.clipboard.read();
        if (!items || items.length === 0) {
          setStatus('چیزی برای چسباندن در کلیپبورد نیست. می‌توانید Ctrl+V را امتحان کنید.');
          return;
        }
        for (const item of items) {
          for (const type of item.types) {
            if (type.startsWith('image/')) {
              const blob = await item.getType(type);
              const file = new File([blob], 'image.png', { type: type });
              const ok = await sendFileOnly(file);
              if (ok) {
                setStatus('');
              }
              return;
            }
          }
        }
        setStatus('چیزی برای چسباندن در کلیپبورد نیست. عکسی کپی کنید (مثلاً با Snipping Tool).');
      } catch (err) {
        setStatus('دسترسی به کلیپبورد ممکن نیست. از Ctrl+V استفاده کنید.');
      }
    });
  }

  if (messageInput) {
    const resizeMessageInput = () => {
      if (!(messageInput instanceof HTMLTextAreaElement)) {
        return;
      }
      const isMobile = window.matchMedia('(max-width: 900px)').matches;
      const minHeight = isMobile ? 34 : 44;
      const maxHeight = isMobile ? 110 : 170;
      messageInput.style.height = 'auto';
      const nextHeight = Math.min(Math.max(messageInput.scrollHeight, minHeight), maxHeight);
      messageInput.style.height = `${nextHeight}px`;
      messageInput.style.overflowY = messageInput.scrollHeight > maxHeight ? 'auto' : 'hidden';
    };

    messageInput.addEventListener('input', () => {
      resizeMessageInput();
      scheduleTyping();
      updateMentionSuggestions();
      updateTypingPlaceholder();
    });
    messageInput.addEventListener('focus', () => {
      updateTypingPlaceholder();
    });
    messageInput.addEventListener('blur', () => {
      updateTypingPlaceholder();
    });
    window.addEventListener('resize', resizeMessageInput);
    messageInput.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        closeMentionBox();
        return;
      }
      if (e.key !== 'Enter') {
        return;
      }

      if (!e.shiftKey && mentionBox && mentionBox.style.display !== 'none') {
        const first = mentionBox.querySelector('.mention-item');
        if (first) {
          e.preventDefault();
          first.click();
        }
        return;
      }

      if (e.isComposing) {
        return;
      }

      if (e.shiftKey) {
        setTimeout(() => {
          resizeMessageInput();
          scheduleTyping();
          updateMentionSuggestions();
          updateTypingPlaceholder();
        }, 0);
        return;
      }

      e.preventDefault();
      if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
      } else {
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
      }
    });
    resizeMessageInput();
    updateTypingPlaceholder();
  }

  if (replyCancel) {
    replyCancel.addEventListener('click', () => {
      clearReply();
    });
  }

  if (voiceBtn) {
    // ━━━ Hold to record + swipe to cancel (مثل تلگرام) ━━━
    let voiceHoldTimer = null;
    let voiceHoldStartX = 0;
    let voiceHoldStartY = 0;
    let voiceHoldActive = false;

    function showSwipeHint(show) {
      const hintBar = document.getElementById('voice-swipe-hint-bar');
      if (hintBar) hintBar.style.display = show ? 'flex' : 'none';
    }

    async function onVoiceHoldStart(e) {
      if (isRecording) return;
      const touch = e.touches ? e.touches[0] : e;
      voiceHoldStartX = touch.clientX;
      voiceHoldStartY = touch.clientY;
      voiceHoldActive = false;
      voiceHoldTimer = setTimeout(async () => {
        voiceHoldActive = true;
        showSwipeHint(true);
        voiceBtn.classList.add('recording-pulse');
        await startRecording();
      }, 150);
    }

    function onVoiceHoldEnd(e) {
      clearTimeout(voiceHoldTimer);
      showSwipeHint(false);
      voiceBtn.classList.remove('recording-pulse');
      if (voiceHoldActive && isRecording) {
        // انگشت برداشت = ارسال
        stopRecording();
        voiceHoldActive = false;
      } else if (!voiceHoldActive && isRecording) {
        // اگه از قبل ضبط در حال انجام بود (حالت toggle)
        stopRecording();
      }
    }

    function onVoiceHoldMove(e) {
      if (!voiceHoldActive || !isRecording) return;
      const touch = e.touches ? e.touches[0] : e;
      const dx = voiceHoldStartX - touch.clientX;
      const dy = voiceHoldStartY - touch.clientY;

      // Swipe چپ یا بالا > 60px = کنسل
      if (dx > 60 || dy > 60) {
        voiceHoldActive = false;
        showSwipeHint(false);
        voiceBtn.classList.remove('recording-pulse');
        voiceCancelled = true;
        if (mediaRecorder && mediaRecorder.state !== 'inactive') {
          try { mediaRecorder.stop(); } catch(e){}
        } else {
          voiceCancelled = false;
          resetVoiceState();
        }
        if (processorNode) { try { processorNode.disconnect(); } catch(e){} processorNode = null; }
        if (sourceNode) { try { sourceNode.disconnect(); } catch(e){} sourceNode = null; }
        if (silenceNode) { try { silenceNode.disconnect(); } catch(e){} silenceNode = null; }
        if (audioContext) { try { audioContext.close(); } catch(e){} audioContext = null; }
        if (recordingStream) { recordingStream.getTracks().forEach(t=>t.stop()); recordingStream = null; }
        isRecording = false;
        setVoiceButtonState('voice');
  
        setStatus('ضبط لغو شد.');
      }
    }

    // Touch events (موبایل)
    voiceBtn.addEventListener('touchstart', onVoiceHoldStart, { passive: true });
    // icon داخل دکمه هم باید touch رو forward کنه
    const voiceIdleIcon = voiceBtn.querySelector('.voice-idle-icon');
    const voiceRecIcon  = voiceBtn.querySelector('.voice-rec-icon');
    [voiceIdleIcon, voiceRecIcon].forEach(el => {
      if (el) el.style.pointerEvents = 'none';
    });
    voiceBtn.addEventListener('touchend', (e) => { e.preventDefault(); onVoiceHoldEnd(e); });
    voiceBtn.addEventListener('touchmove', onVoiceHoldMove, { passive: true });
    voiceBtn.addEventListener('touchcancel', onVoiceHoldEnd);

    // Mouse events (دسکتاپ) — click معمولی برای toggle
    voiceBtn.addEventListener('mousedown', (e) => {
      // فقط اگه روی voiceBtn کلیک شده preventDefault بزن (نه form)
      if (e.target === voiceBtn || voiceBtn.contains(e.target)) {
        e.preventDefault();
      }
      onVoiceHoldStart(e);
    });
    voiceBtn.addEventListener('mouseup', onVoiceHoldEnd);
    voiceBtn.addEventListener('mouseleave', (e) => {
      if (voiceHoldActive && isRecording) {
        // موس از دکمه خارج شد در حال نگه داشتن = ارسال
        clearTimeout(voiceHoldTimer);
        showSwipeHint(false);
        voiceBtn.classList.remove('recording-pulse');
        if (isRecording) stopRecording();
        voiceHoldActive = false;
      }
    });
    voiceBtn.addEventListener('click', async (e) => {
      // اگه hold فعال بود، click را نادیده بگیر
      if (voiceHoldActive) return;
      // دسکتاپ: کلیک معمولی toggle
      if (!e.isTrusted || e.detail === 0) return;
      if (isRecording) {
        stopRecording();
      } else {
        await startRecording();
      }
    });
  }

  // دکمه کنسل ضبط صدا
  
  // setupVoiceCancel حذف شد - swipe رو آموزش میده


  if (emojiBtn) {
    emojiBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      const isOpen = emojiPicker && emojiPicker.classList.contains('show');
      setEmojiPickerOpen(!isOpen);
    });
  }

  if (emojiPicker) {
    emojiPicker.addEventListener('click', async (e) => {
      const target = e.target instanceof Element ? e.target.closest('.emoji-item') : null;
      if (!target) {
        return;
      }
      const path = target.dataset.sticker;
      if (!path) {
        return;
      }
      await sendSticker(path);
    });
  }

  if (invisibleToggle) {
    invisibleToggle.addEventListener('click', async () => {
      if (!state.isAdmin) {
        return;
      }
      const formData = new FormData();
      formData.append('csrf', csrfToken);
      formData.append('action', 'set_invisible');
      formData.append('value', state.isInvisible ? '0' : '1');
      const res = await apiPost('api/me.php', formData);
      if (!res.ok) {
        setStatus(res.error || 'تغییر وضعیت انجام نشد.');
        return;
      }
      state.isInvisible = Boolean(res.is_invisible);
      updateInvisibleToggle();
      loadUsers(userSearch ? userSearch.value : undefined);
    });
  }

  async function init() {
    buildEmojiPicker();
    setupNotificationSounds();
    await loadMe();
    setContactHeader(null);
    showWelcomeScreen();
    await loadRooms();
    await loadUsers(userSearch ? userSearch.value : undefined);
    loadPrivateRequests();
    buildRecentChats();
    loadCallHistory();
    pollTyping();
    // مخفی کردن دکمه‌های تماس در ابتدا
    const _ab = document.getElementById('call-audio-btn');
    const _vb = document.getElementById('call-video-btn');
    if (_ab) _ab.style.display = 'none';
    if (_vb) _vb.style.display = 'none';
  }

  init();

  document.addEventListener('click', () => {
    requestNotificationPermission();
  }, { once: true });

  document.addEventListener('click', (e) => {
    if (emojiPicker && emojiPicker.classList.contains('show')) {
      const insidePicker = emojiPicker.contains(e.target);
      const insideButton = emojiBtn && emojiBtn.contains(e.target);
      if (!insidePicker && !insideButton) {
        setEmojiPickerOpen(false);
      }
    }
    if (searchResults && searchResults.classList.contains('show')) {
      const insideResult = searchResults.contains(e.target);
      const insideSearchInput = messageSearch && messageSearch.contains(e.target);
      if (!insideResult && !insideSearchInput) {
        closeSearchResults();
      }
    }
    if (mentionBox && mentionBox.style.display !== 'none') {
      const inBox = mentionBox.contains(e.target);
      const inInput = messageInput && messageInput.contains(e.target);
      if (!inBox && !inInput) {
        closeMentionBox();
      }
    }
    if (messageActionMenu && messageActionMenu.classList.contains('show')) {
      const insideMenu = messageActionMenu.contains(e.target);
      const messageRow = e.target instanceof Element ? e.target.closest('.msg[data-message-id]') : null;
      if (!insideMenu && !messageRow) {
        closeMessageActionMenu();
      }
    }
  });

  window.addEventListener('resize', () => {
    closeMessageActionMenu();
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      setEmojiPickerOpen(false);
      closeMessageActionMenu();
    }
  });

  if (messagesEl) {
    messagesEl.addEventListener('click', (e) => {
      const target = e.target instanceof Element ? e.target : (e.target && e.target.parentElement);
      if (!target) {
        return;
      }
      if (shouldSkipMessageMenuTarget(target)) {
        return;
      }
      const row = target.closest('.msg[data-message-id]');
      if (!row) {
        closeMessageActionMenu();
        return;
      }
      const messageId = Number(row.dataset.messageId || 0);
      if (!messageId) {
        return;
      }
      const selectedText = window.getSelection ? String(window.getSelection().toString() || '').trim() : '';
      if (selectedText) {
        return;
      }
      const anchor = row.querySelector('.bubble') || row;
      toggleMessageActionMenu(messageId, anchor);
    });

    messagesEl.addEventListener('contextmenu', (e) => {
      const target = e.target instanceof Element ? e.target : (e.target && e.target.parentElement);
      if (!target) {
        return;
      }
      if (shouldSkipMessageMenuTarget(target)) {
        return;
      }
      const row = target.closest('.msg[data-message-id]');
      if (!row) {
        return;
      }
      const messageId = Number(row.dataset.messageId || 0);
      if (!messageId) {
        return;
      }
      e.preventDefault();
      const anchor = row.querySelector('.bubble') || row;
      openMessageActionMenu(messageId, anchor);
    });

    messagesEl.addEventListener('scroll', () => {
      if (activeMessageActionId > 0) {
        closeMessageActionMenu();
      }
      if (!state.hasLoadedMessages) {
        return;
      }
      if (messagesEl.scrollTop <= 120) {
        loadOlderMessages();
      }
    });
  }

  function shouldRunPoll(lastAt, visibleGap, hiddenGap) {
    const now = Date.now();
    const gap = document.visibilityState === 'visible' ? visibleGap : hiddenGap;
    return (now - lastAt) >= gap;
  }

  setInterval(() => {
    if (!shouldRunPoll(lastRoomsPollAt, 15000, 60000)) {
      return;
    }
    lastRoomsPollAt = Date.now();
    loadRooms();
  }, 2000);

  setInterval(() => {
    if (!shouldRunPoll(lastUsersPollAt, 5000, 30000)) {
      return;
    }
    lastUsersPollAt = Date.now();
    loadUsers(userSearch ? userSearch.value : undefined);
  }, 1500);

  setInterval(() => {
    if (!shouldRunPoll(lastPrivateRequestsPollAt, 5000, 30000)) {
      return;
    }
    lastPrivateRequestsPollAt = Date.now();
    loadPrivateRequests();
  }, 1500);

  setInterval(() => {
    if (!shouldRunPoll(lastMessagesPollAt, 2000, 10000)) {
      return;
    }
    lastMessagesPollAt = Date.now();
    pollMessages();
  }, 1000);

  setInterval(() => {
    if (document.visibilityState !== 'visible') {
      return;
    }
    if (!shouldRunPoll(lastTypingPollAt, 2500, 2500)) {
      return;
    }
    lastTypingPollAt = Date.now();
    pollTyping();
  }, 1000);

  async function updateLastActive() {
    try {
      const formData = new FormData();
      formData.append('action', 'update_last_active');
      await fetch('api/me.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': csrfToken },
        keepalive: true
      });
    } catch (e) {
    }
  }

  window.addEventListener('beforeunload', () => {
    updateLastActive();
  });

  document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
      updateLastActive();
      return;
    }
    lastRoomsPollAt = 0;
    lastUsersPollAt = 0;
    lastPrivateRequestsPollAt = 0;
    lastMessagesPollAt = 0;
    lastTypingPollAt = 0;
    loadRooms();
    loadUsers(userSearch ? userSearch.value : undefined);
    loadPrivateRequests();
    pollMessages();
    pollTyping();
  });




  // ━━━ Audio Unlock برای موبایل ━━━
  // موبایل‌ها audio رو بدون تعامل پخش نمیکنن
  // اولین touch/click، audio context رو unlock میکنه
  let audioUnlocked = false;
  function unlockAudio() {
    if (audioUnlocked) return;
    audioUnlocked = true;
    // یه صدای خاموش پخش کن تا unlock بشه
    try {
      if (notificationSounds.message) {
        notificationSounds.message.volume = 0;
        notificationSounds.message.play().then(() => {
          notificationSounds.message.pause();
          notificationSounds.message.currentTime = 0;
          notificationSounds.message.volume = 0.35;
        }).catch(() => {});
      }
      // AudioContext unlock
      const ctx = new (window.AudioContext || window.webkitAudioContext)();
      ctx.resume().then(() => ctx.close());
    } catch(e) {}
    document.removeEventListener('touchstart', unlockAudio);
    document.removeEventListener('click', unlockAudio);
  }
  document.addEventListener('touchstart', unlockAudio, { once: true, passive: true });
  document.addEventListener('click', unlockAudio, { once: true });

  // ━━━ Push Notification Subscription ━━━
  async function setupPushNotifications() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;
    try {
      // بگیر public key
      const kd = await apiGet('api/push_subscription.php');
      if (!kd || !kd.ok || !kd.public_key) return; // VAPID تنظیم نشده

      const reg = await navigator.serviceWorker.ready;
      let sub = await reg.pushManager.getSubscription();

      if (!sub) {
        // درخواست permission
        const perm = await Notification.requestPermission();
        if (perm !== 'granted') return;

        const appServerKey = urlBase64ToUint8Array(kd.public_key);
        sub = await reg.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: appServerKey
        });
      }

      // ذخیره subscription
      const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
      const fd = new FormData();
      fd.append('csrf', csrf);
      fd.append('action', 'subscribe');
      fd.append('endpoint', sub.endpoint);
      fd.append('p256dh', btoa(String.fromCharCode(...new Uint8Array(sub.getKey('p256dh')))));
      fd.append('auth', btoa(String.fromCharCode(...new Uint8Array(sub.getKey('auth')))));
      await fetch('api/push_subscription.php', { method:'POST', body:fd, credentials:'same-origin' });
    } catch(e) { console.log('Push setup:', e.message); }
  }

  function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = atob(base64);
    return new Uint8Array([...rawData].map(c => c.charCodeAt(0)));
  }

  // اجرا بعد از اینکه صفحه لود شد
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.ready.then(() => {
      // درخواست notification permission اگه قبلاً نپرسیده
      if (Notification.permission === 'default') {
        setTimeout(() => setupPushNotifications(), 3000);
      } else if (Notification.permission === 'granted') {
        setupPushNotifications();
      }
    });
  }

  // ━━━ Block / Unblock / Delete Chat ━━━
  let blockedUsers = new Set();

  async function loadBlockedUsers() {
    try {
      const d = await apiGet('api/block.php');
      if (d && d.ok) {
        blockedUsers = new Set((d.blocked || []).map(Number));
      }
    } catch(e) {}
  }

  window.toggleBlockUser = async function() {
    const userActionsEl = document.getElementById('contact-user-actions');
    const uid = userActionsEl?.dataset.userId;
    if (!uid) return;

    const isBlocked = blockedUsers.has(Number(uid));
    const fd = new FormData();
    fd.append('csrf', csrfToken);
    fd.append('action', isBlocked ? 'unblock' : 'block');
    fd.append('user_id', uid);
    const d = await apiPost('api/block.php', fd);
    if (d && d.ok) {
      if (isBlocked) {
        blockedUsers.delete(Number(uid));
        showToast('✅ بلاک برداشته شد', '');
      } else {
        blockedUsers.add(Number(uid));
        showToast('🚫 کاربر بلاک شد', 'دیگر پیامی از او نمی‌بینید');
      }
      updateBlockBtn();
    } else {
      showToast('❌ خطا', (d && d.error) || 'عملیات انجام نشد');
    }
  };

  function updateBlockBtn() {
    const actionsEl = document.getElementById('contact-user-actions');
    if (!actionsEl) return;
    const uid = actionsEl.dataset.userId;
    const btn = document.getElementById('contact-block-btn');
    if (btn && uid) {
      const isBlocked = blockedUsers.has(Number(uid));
      btn.textContent = isBlocked ? '✅ رفع بلاک' : '🚫 بلاک';
      btn.classList.toggle('unblock-btn', isBlocked);
    }
  }

  window.deleteChatWithUser = async function() {
    const uid = document.getElementById('contact-user-actions')?.dataset.userId;
    if (!uid) return;
    if (!confirm('گفتگو با این کاربر برای هر دو طرف پاک شود؟')) return;
    const fd = new FormData();
    fd.append('csrf', csrfToken);
    fd.append('action', 'delete_chat');
    fd.append('user_id', uid);
    const d = await apiPost('api/block.php', fd);
    if (d && d.ok) {
      showToast('🗑️ گفتگو حذف شد', '');
      if (state.mode === 'private' && String(state.targetId) === String(uid)) {
        setConversation(null, null, '');
      }
      document.getElementById('contact-profile-modal')?.classList.remove('show');
    } else {
      showToast('❌ خطا', (d && d.error) || 'عملیات انجام نشد');
    }
  };

  // loadBlockedUsers را هنگام بارگذاری صدا بزن
  loadBlockedUsers();

  // ━━━ Admin quick actions از داخل chat ━━━
  window.adminActionFromProfile = async function(action) {
    const el = document.getElementById('contact-admin-actions');
    if (!el) return;
    const uid = el.dataset.userId;
    const uname = el.dataset.username || 'کاربر';
    if (!uid) return;

    const post = async (data) => {
      const fd = new FormData();
      fd.append('csrf', csrfToken);
      Object.entries(data).forEach(([k,v]) => fd.append(k, v));
      return await apiPost('api/admin_action.php', fd);
    };

    let res;
    if (action === 'ban') {
      const mins = prompt(`مدت مسدودی ${uname} (دقیقه):`, '60');
      if (!mins) return;
      res = await post({ action:'ban_user', user_id:uid, ban_minutes:mins });
      showToast(res.ok ? '✅ ' + res.message : '❌ ' + (res.error || 'خطا'), uname);
    } else if (action === 'unban') {
      res = await post({ action:'unban_user', user_id:uid });
      showToast(res.ok ? '✅ ' + res.message : '❌ ' + (res.error || 'خطا'), uname);
    } else if (action === 'vip') {
      res = await post({ action:'vip', user_id:uid, value:'1' });
      showToast(res.ok ? '⭐ ' + res.message : '❌ ' + (res.error || 'خطا'), uname);
      if (res.ok) {
        // آپدیت label دکمه
        const vipBtn = el.querySelector('.vip-btn');
        if (vipBtn) vipBtn.textContent = '⭐ حذف VIP';
        vipBtn && vipBtn.setAttribute('onclick', "adminActionFromProfile('unvip')");
      }
    } else if (action === 'unvip') {
      res = await post({ action:'vip', user_id:uid, value:'0' });
      showToast(res.ok ? '✅ ' + res.message : '❌ ' + (res.error || 'خطا'), uname);
    } else if (action === 'password') {
      const pw = prompt(`رمز جدید برای ${uname}:`);
      if (!pw || pw.length < 4) { showToast('❌ رمز خیلی کوتاه است', ''); return; }
      res = await post({ action:'change_password', user_id:uid, new_password:pw });
      showToast(res.ok ? '🔑 ' + res.message : '❌ ' + (res.error || 'خطا'), uname);
    } else if (action === 'delete') {
      if (!confirm(`همه پیام‌های ${uname} حذف شود؟`)) return;
      res = await post({ action:'delete_all_messages', user_id:uid });
      showToast(res.ok ? '🗑️ ' + res.message : '❌ ' + (res.error || 'خطا'), uname);
    } else if (action === 'admin') {
      res = await post({ action:'make_admin', user_id:uid, value:'1' });
      showToast(res.ok ? '🛡️ ' + res.message : '❌ ' + (res.error || 'خطا'), uname);
    }
  };


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// CALL SYSTEM — WebRTC + Signaling
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
(function() {
  'use strict';
  // تنظیمات سرور تماس — از سرور گرفته می‌شود، نه نوشته‌شده این‌جا.
  // پیش از این نشانی و رمز سرور ترن همین‌جا بود و چون این فایل عمومی
  // است، هر کسی می‌توانست بخواندش.
  let TURN_CONFIG = { iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] };
  let iceFetchedAt = 0;

  async function loadIceServers(force) {
    // رمزهای موقت عمر محدود دارند، پس هر ده دقیقه دوباره می‌گیریم
    if (!force && iceFetchedAt && (Date.now() - iceFetchedAt) < 600000) return TURN_CONFIG;
    try {
      const r = await fetch('api/ice.php', { credentials: 'same-origin' });
      const j = await r.json();
      if (j && j.ok && Array.isArray(j.iceServers) && j.iceServers.length) {
        TURN_CONFIG = { iceServers: j.iceServers };
        iceFetchedAt = Date.now();
      }
    } catch (e) {
      // اگر نشد، با همان سرور استان پیش‌فرض ادامه بده
    }
    return TURN_CONFIG;
  }

  loadIceServers(true);

  // state تماس
  let callState = {
    active: false,
    pc: null,
    localStream: null,
    remoteUserId: null,
    remoteUserName: '',
    isVideo: false,
    isCaller: false,
    lastSignalId: 0,
    pollTimer: null,
    callTimer: null,
    callSeconds: 0,
    isMuted: false,
    isCamOff: false
  };

  // عناصر DOM
  const overlay     = document.getElementById('call-overlay');
  const callName    = document.getElementById('call-name');
  const callStatus  = document.getElementById('call-status');
  const callAvatar  = document.getElementById('call-avatar-letter');
  const callAvatarWrap = document.getElementById('call-avatar-wrap');
  const callVideos  = document.getElementById('call-videos');
  const remoteVideo = document.getElementById('remote-video');
  const localVideo  = document.getElementById('local-video');
  const remoteAudio = document.getElementById('remote-audio');
  const ringtone    = document.getElementById('call-ringtone');
  const actionsIn   = document.getElementById('call-actions-incoming');
  const actionsOut  = document.getElementById('call-actions-outgoing');
  const actionsActive = document.getElementById('call-actions-active');
  const callAudioBtn  = document.getElementById('call-audio-btn');
  const callVideoBtn  = document.getElementById('call-video-btn');
  const btnCam        = document.getElementById('btn-cam');

  // نمایش دکمه‌های تماس فقط در چت خصوصی
  function updateCallButtons() {
    const show = window.__chatMode === 'private' && Number(window.__chatTargetId) > 0;
    // call-hdr-btn style (new header)
    const callBtnsDiv = document.getElementById('chat-call-btns');
    if (callBtnsDiv) callBtnsDiv.style.display = show ? 'flex' : 'none';
    // backward compat
    if (callAudioBtn) callAudioBtn.style.display = show ? 'inline-flex' : 'none';
    if (callVideoBtn) callVideoBtn.style.display = show ? 'inline-flex' : 'none';
  }

  // آپدیت دکمه‌های تماس
  document.addEventListener('chatModeChanged', updateCallButtons);
  // اجرای اولیه
  updateCallButtons();


  // ━━━ Dial Tone (بوق انتظار) ━━━
  let dialToneCtx = null;
  let dialToneOsc = null;
  let dialToneInterval = null;


  // ━━━ Ring Tone برای receiver ━━━
  let ringToneInterval = null;
  let ringPlaying = false;
  let ringCtx = null;
  let ringMasterGain = null;

  function startRingTone() {
    stopRingTone();
    ringPlaying = true;
    try {
      ringCtx = new (window.AudioContext || window.webkitAudioContext)();
      ringMasterGain = ringCtx.createGain();
      ringMasterGain.gain.value = 1;
      ringMasterGain.connect(ringCtx.destination);

      function scheduleRing(startTime) {
        if (!ringPlaying || !ringCtx) return;
        [[0, 520, 0.13], [0.18, 520, 0.13], [0.36, 490, 0.13]].forEach(([delay, freq, dur]) => {
          const osc  = ringCtx.createOscillator();
          const gain = ringCtx.createGain();
          osc.connect(gain); gain.connect(ringMasterGain);
          osc.type = 'sine';
          osc.frequency.value = freq;
          gain.gain.setValueAtTime(0, startTime + delay);
          gain.gain.linearRampToValueAtTime(0.2, startTime + delay + 0.02);
          gain.gain.setValueAtTime(0.2, startTime + delay + dur - 0.02);
          gain.gain.linearRampToValueAtTime(0, startTime + delay + dur);
          osc.start(startTime + delay);
          osc.stop(startTime + delay + dur + 0.05);
        });
      }

      scheduleRing(ringCtx.currentTime + 0.1);
      ringToneInterval = setInterval(() => {
        if (!ringPlaying || !ringCtx) return;
        scheduleRing(ringCtx.currentTime + 0.1);
      }, 2200);
    } catch(e) {}
  }

  function stopRingTone() {
    ringPlaying = false;
    if (ringToneInterval) { clearInterval(ringToneInterval); ringToneInterval = null; }
    if (ringMasterGain && ringCtx) {
      try {
        ringMasterGain.gain.cancelScheduledValues(ringCtx.currentTime);
        ringMasterGain.gain.setValueAtTime(0, ringCtx.currentTime);
        setTimeout(() => {
          try { ringCtx.close(); } catch(_) {}
          ringCtx = null; ringMasterGain = null;
        }, 100);
      } catch(e) { ringCtx = null; ringMasterGain = null; }
    }
  }

  let dialTonePlaying = false;
  let dialToneCtxList = [];

  // یه AudioContext واحد با master gain برای dial tone
  let dialCtx = null;
  let dialMasterGain = null;

  function startDialTone() {
    stopDialTone();
    dialTonePlaying = true;
    try {
      dialCtx = new (window.AudioContext || window.webkitAudioContext)();
      dialToneCtxList.push(dialCtx);
      dialMasterGain = dialCtx.createGain();
      dialMasterGain.gain.value = 1;
      dialMasterGain.connect(dialCtx.destination);

      function scheduleBeep(startTime) {
        if (!dialTonePlaying || !dialCtx) return;
        [[392, 0, 0.3], [349, 0.35, 0.3]].forEach(([freq, delay, dur]) => {
          const osc  = dialCtx.createOscillator();
          const gain = dialCtx.createGain();
          osc.connect(gain);
          gain.connect(dialMasterGain);
          osc.type = 'sine';
          osc.frequency.value = freq;
          gain.gain.setValueAtTime(0, startTime + delay);
          gain.gain.linearRampToValueAtTime(0.07, startTime + delay + 0.04);
          gain.gain.setValueAtTime(0.07, startTime + delay + dur - 0.05);
          gain.gain.linearRampToValueAtTime(0, startTime + delay + dur);
          osc.start(startTime + delay);
          osc.stop(startTime + delay + dur + 0.05);
        });
      }

      // زمانبندی beep‌ها
      let t = dialCtx.currentTime + 0.1;
      scheduleBeep(t);
      dialToneInterval = setInterval(() => {
        if (!dialTonePlaying || !dialCtx) return;
        t = dialCtx.currentTime + 0.1;
        scheduleBeep(t);
      }, 3000);
    } catch(e) {}
  }

  function stopDialTone() {
    dialTonePlaying = false;
    if (dialToneInterval) { clearInterval(dialToneInterval); dialToneInterval = null; }
    // فوری gain رو صفر کن تا صدا قطع بشه
    if (dialMasterGain && dialCtx) {
      try {
        dialMasterGain.gain.cancelScheduledValues(dialCtx.currentTime);
        dialMasterGain.gain.setValueAtTime(0, dialCtx.currentTime);
        setTimeout(() => {
          dialToneCtxList.forEach(c => { try { c.close(); } catch(_){} });
          dialToneCtxList = [];
          dialCtx = null;
          dialMasterGain = null;
        }, 100);
      } catch(e) {
        dialToneCtxList.forEach(c => { try { c.close(); } catch(_){} });
        dialToneCtxList = [];
        dialCtx = null;
        dialMasterGain = null;
      }
    }
  }

  // شروع تماس
  async function startCall(withVideo) {
    await loadIceServers();
    if (callState.active) return;
    const targetId   = window.__chatTargetId;
    const targetName = window.__chatTargetName || 'کاربر';
    if (!targetId) return;

    callState.isVideo    = withVideo;
    callState.isCaller   = true;
    callState.remoteUserId   = targetId;
    callState.remoteUserName = targetName;

    // reset lastSignalId
    try {
      const r = await fetch('api/call.php?since=0', {credentials:'same-origin'});
      const d = await r.json();
      if (d.ok && d.signals.length > 0) {
        callState.lastSignalId = Math.max(...d.signals.map(s => parseInt(s.id)));
      }
    } catch(e) {}

    // بوق انتظار
    startDialTone();
    showOverlay('outgoing');

    try {
      callState.localStream = await navigator.mediaDevices.getUserMedia({
        audio: true,
        video: withVideo ? { width:640, height:480 } : false
      });
    } catch(e) {
      hideOverlay();
      alert('دسترسی به میکروفون/دوربین رد شد');
      return;
    }

    if (withVideo && localVideo) {
      localVideo.srcObject = callState.localStream;
    }

    createPeerConnection();
    const offer = await callState.pc.createOffer();
    await callState.pc.setLocalDescription(offer);

    // صبر برای ICE gathering
    await gatherICE();

    await sendSignal('offer', {
      sdp: callState.pc.localDescription,
      video: withVideo
    });

    startPolling();
    // timeout 35 ثانیه
    callState.missedTimeout = setTimeout(async () => {
      if (callState.active && callState.isCaller) {
        const nm = callState.remoteUserName;
        const tid = callState.remoteUserId;
        const ct  = callState.isVideo ? 'تصویری' : 'صوتی';
        // ارسال signal missed به receiver
        await sendSignal('missed', { call_type: ct, caller_name: window.__myUsername || 'کاربر' }, tid);
        await hangupCall();
        showToast && showToast(`📵 تماس ${ct} بی‌پاسخ`, nm);
      }
    }, 35000);
  }

  // پاسخ به تماس
  async function acceptCall() {
    await loadIceServers();
    if (!actionsIn._pendingOffer) return;
    const { sdp, video, fromId, fromName } = actionsIn._pendingOffer;

    stopRingTone();
    if (ringtone) { ringtone.pause(); ringtone.currentTime = 0; }

    callState.isVideo  = video;
    callState.isCaller = false;
    callState.remoteUserId   = fromId;
    callState.remoteUserName = fromName;

    showOverlay('connecting');

    try {
      callState.localStream = await navigator.mediaDevices.getUserMedia({
        audio: true,
        video: video ? { width:640, height:480 } : false
      });
    } catch(e) {
      hideOverlay();
      await sendSignal('reject', {}, fromId);
      return;
    }

    if (video && localVideo) {
      localVideo.srcObject = callState.localStream;
    }

    createPeerConnection();
    await callState.pc.setRemoteDescription(new RTCSessionDescription(sdp));

    const answer = await callState.pc.createAnswer();
    await callState.pc.setLocalDescription(answer);
    await gatherICE();

    await sendSignal('answer', { sdp: callState.pc.localDescription }, fromId);
  }

  // رد کردن تماس
  async function rejectCall() {
    stopRingTone();
    if (ringtone) { ringtone.pause(); ringtone.currentTime = 0; }
    const fromId = actionsIn._pendingOffer?.fromId;
    if (fromId) await sendSignal('reject', {}, fromId);
    hideOverlay();
  }

  // قطع تماس
  async function hangupCall() {
    if (ringtone) { ringtone.pause(); ringtone.currentTime = 0; }
    if (callState.remoteUserId) {
      await sendSignal('hangup', {}, callState.remoteUserId);
    }
    cleanupCall();
  }

  // toggle mute
  function toggleMute() {
    if (!callState.localStream) return;
    callState.isMuted = !callState.isMuted;
    callState.localStream.getAudioTracks().forEach(t => t.enabled = !callState.isMuted);
    const btn = document.getElementById('btn-mute');
    if (btn) btn.classList.toggle('muted', callState.isMuted);
  }

  // toggle camera
  function toggleCam() {
    if (!callState.localStream) return;
    callState.isCamOff = !callState.isCamOff;
    callState.localStream.getVideoTracks().forEach(t => t.enabled = !callState.isCamOff);
    const btn = document.getElementById('btn-cam');
    if (btn) btn.classList.toggle('off', callState.isCamOff);
  }

  // ━━━ PeerConnection ━━━
  function createPeerConnection() {
    callState.pc = new RTCPeerConnection(TURN_CONFIG);

    callState.pc.ontrack = (e) => {
      stopDialTone();
      stopRingTone();
      if (callState.missedTimeout) { clearTimeout(callState.missedTimeout); callState.missedTimeout = null; }
      const stream = e.streams[0];
      if (callState.isVideo && remoteVideo) {
        remoteVideo.srcObject = stream;
      } else if (remoteAudio) {
        remoteAudio.srcObject = stream;
      }
      showOverlay('active');
      startCallTimer();
    };

    callState.pc.onconnectionstatechange = () => {
      if (callState.pc.connectionState === 'failed') {
        if (callStatus) callStatus.textContent = 'اتصال قطع شد';
        setTimeout(hangupCall, 2000);
      }
    };

    if (callState.localStream) {
      callState.localStream.getTracks().forEach(t =>
        callState.pc.addTrack(t, callState.localStream)
      );
    }
  }

  // جمع‌آوری ICE
  function gatherICE() {
    return new Promise(resolve => {
      if (callState.pc.iceGatheringState === 'complete') { resolve(); return; }
      const timer = setTimeout(resolve, 3000);
      callState.pc.onicegatheringstatechange = () => {
        if (callState.pc.iceGatheringState === 'complete') {
          clearTimeout(timer);
          resolve();
        }
      };
    });
  }

  // ━━━ Signaling ━━━
  async function sendSignal(type, data, targetId) {
    const tid = targetId || callState.remoteUserId;
    if (!tid) return;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const fd = new FormData();
    fd.append('csrf', csrf);
    fd.append('type', type);
    fd.append('target_id', tid);
    fd.append('data', JSON.stringify(data));
    await fetch('api/call.php', { method:'POST', body:fd, credentials:'same-origin' }).catch(()=>{});
  }

  function startPolling() {
    if (callState.pollTimer) clearInterval(callState.pollTimer);
    callState.pollTimer = setInterval(pollSignals, 1500);
  }

  async function pollSignals() {
    try {
      const r = await fetch(`api/call.php?since=${callState.lastSignalId}`, { credentials:'same-origin' });
      const d = await r.json();
      if (!d.ok) return;
      for (const sig of (d.signals || [])) {
        callState.lastSignalId = Math.max(callState.lastSignalId, sig.id);
        await handleSignal(sig);
      }
    } catch(e) {}
  }

  async function handleSignal(sig) {
    const data = JSON.parse(sig.data || '{}');
    const fromId = parseInt(sig.caller_id);
    const toId   = parseInt(sig.receiver_id);
    const myId   = window.__myUserId;

    switch(sig.type) {

      case 'offer':
        if (toId !== myId) break;
        if (callState.active) {
          await sendSignal('busy', {}, fromId); break;
        }
        // گرفتن نام کاربر از usersById
        const fromUser = typeof usersById !== 'undefined' ? usersById.get(fromId) : null;
        const fromDisplayName = (fromUser && (fromUser.display_name || fromUser.username))
          || sig.caller_name || 'کاربر';
        const callTypeText = data.video ? 'تماس تصویری 📹' : 'تماس صوتی 📞';

        actionsIn._pendingOffer = {
          sdp: data.sdp, video: data.video,
          fromId, fromName: fromDisplayName,
          callType: callTypeText
        };
        showOverlay('incoming', fromDisplayName, fromId, data.video);
        // زنگ با Web Audio — روی همه مرورگرها کار میکنه
        startRingTone();
        startPolling();
        break;

      case 'answer':
        if (toId !== myId || !callState.pc) break;
        await callState.pc.setRemoteDescription(new RTCSessionDescription(data.sdp));
        break;

      case 'reject':
        if (toId !== myId) break;
        cleanupCall();
        showToast && showToast('📵 تماس رد شد', '');
        break;

      case 'hangup':
        if (fromId === callState.remoteUserId || toId === myId) {
          cleanupCall();
          showToast && showToast('📵 تماس قطع شد', '');
        }
        break;

      case 'busy':
        if (toId !== myId) break;
        cleanupCall();
        showToast && showToast('📵 مشغول است', '');
        break;

      case 'missed':
        if (toId !== myId) break;
        // نمایش toast بی‌پاسخ برای receiver
        const callerNm = data.caller_name || 'کاربر';
        const callTp   = data.call_type  || 'صوتی';
        showToast && showToast(
          `📵 تماس ${callTp} از دست رفته`,
          `${callerNm} با شما تماس گرفت`,
          { onClick: () => {
            // کلیک روی toast = باز کردن چت با caller
            if (fromId && typeof usersById !== 'undefined') {
              const u = usersById.get(fromId);
              if (u) setConversation('private', fromId, `خصوصی: ${callerNm}`);
            }
          }}
        );
        break;
    }
  }

  // ━━━ UI ━━━
  function showOverlay(mode, name, userId, isVideo) {
    if (!overlay) return;
    callState.active = true;
    overlay.style.display = 'flex';

    const displayName = name || callState.remoteUserName;
    const callType = (isVideo !== undefined ? isVideo : callState.isVideo) ? 'تصویری' : 'صوتی';

    if (callName) callName.textContent = displayName;
    if (callAvatar) callAvatar.textContent = (displayName || '?')[0].toUpperCase();

    [actionsIn, actionsOut, actionsActive].forEach(el => { if(el) el.style.display = 'none'; });

    if (mode === 'incoming') {
      const pending = actionsIn._pendingOffer;
      const ct = pending?.callType || (callType === 'تصویری' ? 'تماس تصویری 📹' : 'تماس صوتی 📞');
      if (callStatus) callStatus.innerHTML =
        `<span style="color:#3aa4ff">${ct}</span><br>
         <span style="font-size:12px;opacity:0.7">قبول می‌کنید؟</span>`;
      if (actionsIn) actionsIn.style.display = 'flex';
    } else if (mode === 'outgoing') {
      const ct = callState.isVideo ? 'تماس تصویری 📹' : 'تماس صوتی 📞';
      if (callStatus) callStatus.innerHTML =
        `<span style="color:#3aa4ff">${ct}</span><br>
         <span style="font-size:12px;opacity:0.7">در حال زنگ زدن...</span>`;
      if (actionsOut) actionsOut.style.display = 'flex';
    } else if (mode === 'connecting') {
      if (callStatus) callStatus.textContent = 'در حال اتصال...';
    } else if (mode === 'active') {
      if (actionsActive) {
        actionsActive.style.display = 'flex';
        if (btnCam) btnCam.style.display = callState.isVideo ? 'flex' : 'none';
        const flipBtn = document.getElementById('btn-flip');
        if (flipBtn) flipBtn.style.display = callState.isVideo ? 'flex' : 'none';
      }
      if (callState.isVideo && callVideos && callAvatarWrap) {
        callVideos.style.display = 'block';
        callAvatarWrap.style.display = 'none';
        videoMode = 'normal';
      }
      // دکمه minimize به نوار در call-box
      const existMinBar = document.getElementById('btn-min-bar');
      if (!existMinBar) {
        const callBox = overlay?.querySelector('.call-box');
        if (callBox) {
          const minBarBtn = document.createElement('button');
          minBarBtn.id = 'btn-min-bar';
          minBarBtn.className = 'call-min-bar-btn';
          minBarBtn.title = 'ادامه گفتگو در پس‌زمینه';
          minBarBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>';
          minBarBtn.title = 'کمینه کردن';
          minBarBtn.onclick = minimizeToBar;
          callBox.insertBefore(minBarBtn, callBox.firstChild);
        }
      }
    }
  }

  function hideOverlay() {
    callState.active = false;
    if (overlay) overlay.style.display = 'none';
    [actionsIn, actionsOut, actionsActive].forEach(el => { if(el) el.style.display = 'none'; });
    if (callVideos) callVideos.style.display = 'none';
    if (callAvatarWrap) callAvatarWrap.style.display = 'block';
  }

  function cleanupCall() {
    stopDialTone();
    stopRingTone();
    // نمایش خلاصه تماس در چت
    if (callState.active && callState.callSeconds > 0 && messagesEl) {
      const callType = callState.isVideo ? '📹 تماس تصویری' : '📞 تماس صوتی';
      const dur = callState.callSeconds;
      const m = Math.floor(dur / 60), s = dur % 60;
      const durStr = dur >= 60
        ? `${m} دقیقه و ${s} ثانیه`
        : `${dur} ثانیه`;
      const note = document.createElement('div');
      note.className = 'call-ended-note';
      note.innerHTML = `<span>${callType} — ${durStr}</span>`;
      messagesEl.appendChild(note);
      note.scrollIntoView({ behavior: 'smooth', block: 'end' });
    }
    videoMode = 'normal';
    hideCallBar();
    // حذف دکمه minimize
    const minBarBtn = document.getElementById('btn-min-bar');
    if (minBarBtn) minBarBtn.remove();
    // پاک کردن missed timeout
    if (callState.missedTimeout) {
      clearTimeout(callState.missedTimeout);
      callState.missedTimeout = null;
    }
    // reload تاریخچه
    setTimeout(loadCallHistory, 1000);
    if (callState.pollTimer) { clearInterval(callState.pollTimer); callState.pollTimer = null; }
    if (callState.callTimer) { clearInterval(callState.callTimer); callState.callTimer = null; }
    if (callState.localStream) {
      callState.localStream.getTracks().forEach(t => t.stop());
      callState.localStream = null;
    }
    if (callState.pc) { callState.pc.close(); callState.pc = null; }
    if (remoteAudio) remoteAudio.srcObject = null;
    if (remoteVideo) remoteVideo.srcObject = null;
    if (localVideo)  localVideo.srcObject  = null;
    callState.active = false;
    callState.isMuted = false;
    callState.isCamOff = false;
    hideOverlay();
  }

  function startCallTimer() {
    callState.callSeconds = 0;
    callState.callTimer = setInterval(() => {
      callState.callSeconds++;
      const m = Math.floor(callState.callSeconds / 60);
      const s = callState.callSeconds % 60;
      const timeStr = `${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
      if (callStatus) callStatus.textContent = timeStr;
      updateCallBarTimer(callState.callSeconds);
    }, 1000);
  }

  // ━━━ Event Listeners ━━━

  // ━━━ Call Bar (تلگرام‌استایل) ━━━
  const callBar      = document.getElementById('call-bar');
  const callBarName  = document.getElementById('call-bar-name');
  const callBarTimer = document.getElementById('call-bar-timer');
  const callBarIcon  = document.getElementById('call-bar-icon');

  function showCallBar() {
    if (!callBar) return;
    callBar.style.display = 'block';
    document.body.classList.add('has-call-bar');
    if (callBarName)  callBarName.textContent  = callState.remoteUserName || 'تماس';
    if (callBarIcon)  callBarIcon.textContent   = callState.isVideo ? '📹' : '📞';
    if (callBarTimer) callBarTimer.textContent  = 'در انتظار...';
    // کلیک روی نوار = برگشت به overlay
    const inner = document.getElementById('call-bar-inner');
    if (inner) inner.onclick = returnToCall;
  }

  function hideCallBar() {
    if (!callBar) return;
    callBar.style.display = 'none';
    document.body.classList.remove('has-call-bar');
  }

  function updateCallBarTimer(secs) {
    if (!callBarTimer || callBar?.style.display === 'none') return;
    const m = Math.floor(secs / 60);
    const s = secs % 60;
    callBarTimer.textContent = `${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
  }

  function returnToCall() {
    hideCallBar();
    if (overlay) overlay.style.display = 'flex';
  }
  window.returnToCall = returnToCall;

  function minimizeToBar() {
    if (overlay) overlay.style.display = 'none';
    showCallBar();
  }
  window.minimizeToBar = minimizeToBar;


  // ━━━ Call History ━━━
  const callHistoryList    = document.getElementById('call-history-list');
  const sbCallsSection     = document.getElementById('sb-calls-section');

  async function loadCallHistory() {
    if (!callHistoryList) return;
    try {
      const r = await fetch('api/call.php?action=history', {credentials:'same-origin'});
      const d = await r.json();
      if (!d.ok || !d.history.length) {
        if (sbCallsSection) sbCallsSection.style.display = 'none';
        return;
      }
      if (sbCallsSection) sbCallsSection.style.display = '';
      callHistoryList.innerHTML = '';
      d.history.forEach(h => {
        const li  = document.createElement('li');
        const btn = document.createElement('button');
        btn.className = 'item';

        const wrap = document.createElement('div');
        wrap.className = 'user-item';

        // آیکون نوع تماس
        const av = document.createElement('div');
        av.className = 'ui-avatar';
        av.style.cssText = 'width:36px;height:36px;font-size:16px;border-radius:10px;';
        av.style.background = h.is_outgoing
          ? 'rgba(58,164,255,0.15)' : 'rgba(46,204,113,0.15)';
        av.textContent = h.call_type === 'video' ? '📹' : '📞';
        wrap.appendChild(av);

        // نام
        const col = document.createElement('div');
        col.className = 'ui-col';
        const nameEl = document.createElement('div');
        nameEl.className = 'ui-name';
        nameEl.style.fontSize = '13px';
        nameEl.textContent = h.peer_name;
        const subEl = document.createElement('div');
        subEl.className = 'ui-sub';
        const d = new Date(h.ts * 1000);
        const timeStr = d.toLocaleTimeString('fa-IR', {hour:'2-digit', minute:'2-digit'});
        subEl.textContent = (h.is_outgoing ? '↗ ' : '↙ ') + timeStr;
        subEl.style.color = h.is_outgoing ? '#3aa4ff' : '#2ecc71';
        col.appendChild(nameEl);
        col.appendChild(subEl);
        wrap.appendChild(col);

        // دکمه تماس مجدد
        const aside = document.createElement('div');
        aside.className = 'ui-aside';
        const callAgainBtn = document.createElement('button');
        callAgainBtn.style.cssText = 'font-size:16px;background:none;border:none;cursor:pointer;';
        callAgainBtn.textContent = h.call_type === 'video' ? '📹' : '📞';
        callAgainBtn.title = 'تماس مجدد';
        callAgainBtn.onclick = (e) => {
          e.stopPropagation();
          // پیدا کن user و تماس بگیر
          const targetId = h.is_outgoing
            ? h.receiver_id || null
            : h.caller_id || null;
          if (targetId) {
            window.__chatTargetId = targetId;
            window.__chatTargetName = h.peer_name;
            window.__chatMode = 'private';
            startCall(h.call_type === 'video');
          }
        };
        aside.appendChild(callAgainBtn);
        wrap.appendChild(aside);

        btn.appendChild(wrap);
        li.appendChild(btn);
        callHistoryList.appendChild(li);
      });
    } catch(e) {}
  }

  function clearCallHistory() {
    if (!callHistoryList) return;
    callHistoryList.innerHTML = '';
    if (sbCallsSection) sbCallsSection.style.display = 'none';
  }
  window.clearCallHistory = clearCallHistory;

  if (callAudioBtn) callAudioBtn.addEventListener('click', () => startCall(false));
  if (callVideoBtn) callVideoBtn.addEventListener('click', () => startCall(true));


  // ━━━ Flip Camera ━━━
  let currentFacingMode = 'user';
  async function flipCamera() {
    if (!callState.localStream || !callState.pc) return;
    currentFacingMode = currentFacingMode === 'user' ? 'environment' : 'user';
    try {
      const newStream = await navigator.mediaDevices.getUserMedia({
        audio: false,
        video: { facingMode: currentFacingMode, width:640, height:480 }
      });
      const newTrack = newStream.getVideoTracks()[0];
      const sender = callState.pc.getSenders().find(s => s.track && s.track.kind === 'video');
      if (sender) await sender.replaceTrack(newTrack);
      // stop old video track
      callState.localStream.getVideoTracks().forEach(t => t.stop());
      callState.localStream.removeTrack(callState.localStream.getVideoTracks()[0]);
      callState.localStream.addTrack(newTrack);
      if (localVideo) localVideo.srcObject = callState.localStream;
    } catch(e) {
      console.log('flip error:', e);
    }
  }
  window.flipCamera = flipCamera;

  // ━━━ Minimize Video ━━━
  // حالت‌های ویدیو: normal → fullscreen → minimized → normal
  let videoMode = 'normal';

  function setVideoMode(mode) {
    videoMode = mode;
    if (!callVideos || !overlay) return;

    // reset همه
    callVideos.classList.remove('fullscreen', 'minimized');
    overlay.classList.remove('video-fullscreen');
    overlay.style.background = '';
    overlay.style.pointerEvents = '';
    callVideos.style.pointerEvents = '';

    const minBtn = document.getElementById('btn-minimize');
    const activeActions = document.getElementById('call-actions-active');

    if (mode === 'fullscreen') {
      overlay.classList.add('video-fullscreen');
      // move videos out of box into overlay
      overlay.appendChild(callVideos);
      if (activeActions) overlay.appendChild(activeActions);
      if (minBtn) minBtn.textContent = '⊡';
    } else if (mode === 'minimized') {
      // ویدیو کوچک گوشه صفحه، overlay مخفی
      callVideos.classList.add('minimized');
      callVideos.style.pointerEvents = 'all';
      overlay.style.background = 'transparent';
      overlay.style.pointerEvents = 'none';
      if (minBtn) minBtn.textContent = '⤢';
      // کلیک روی ویدیوی کوچک = برگشت به normal
      callVideos.onclick = () => setVideoMode('normal');
    } else {
      // normal — بذار ویدیو برگرده داخل call-box
      const callBox = overlay.querySelector('.call-box');
      if (callBox) {
        const videoArea = callBox.querySelector('.call-videos');
        if (!videoArea && callVideos) {
          // ویدیو رو به جای اول برگردون
          const boxVideos = callBox.insertBefore(callVideos, callBox.firstChild);
        }
        if (activeActions) callBox.appendChild(activeActions);
      }
      callVideos.onclick = null;
      if (minBtn) minBtn.textContent = '⤡';
    }
  }

  function toggleMinimize() {
    if (videoMode === 'normal')      setVideoMode('fullscreen');
    else if (videoMode === 'fullscreen') setVideoMode('minimized');
    else                              setVideoMode('normal');
  }
  window.toggleMinimize = toggleMinimize;

  // Global functions for HTML onclick
  window.acceptCall = acceptCall;
  window.rejectCall = rejectCall;
  window.hangupCall = hangupCall;
  window.toggleMute = toggleMute;
  window.toggleCam  = toggleCam;

  // شروع polling پس‌زمینه برای incoming calls
  // initialize lastSignalId
  fetch('api/call.php?since=0', {credentials:'same-origin'})
    .then(r => r.json())
    .then(d => {
      if (d.ok && d.signals.length > 0) {
        callState.lastSignalId = Math.max(...d.signals.map(s => parseInt(s.id)));
      }
    }).catch(()=>{});

  // polling هوشمند: وقتی تماس فعاله هر 1.5s، وقتی نیست هر 5s
  setInterval(() => {
    pollSignals();
  }, 2000);

})();
})();

