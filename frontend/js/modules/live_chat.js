const APP_ROOT = new URL('../../', import.meta.url);
const API_ROOT = new URL('../backend/api/', APP_ROOT);
const BACKEND_ROOT = new URL('../backend/', APP_ROOT);
const LIVE_CHAT_STYLE_ID = 'pensionsgo-live-chat-css';
const LIVE_CHAT_ICON_STYLE_ID = 'pensionsgo-live-chat-icons-css';
const DEFAULT_PHOTO = new URL('images/default-user.png', APP_ROOT).href;
let useFallbackIcons = false;

const API = {
  bootstrap: new URL('live_chat_bootstrap.php', API_ROOT).href,
  presence: new URL('live_chat_presence.php', API_ROOT).href,
  messages: new URL('live_chat_messages.php', API_ROOT).href,
  send: new URL('live_chat_send.php', API_ROOT).href,
  call: new URL('live_chat_call.php', API_ROOT).href,
  groupCreate: new URL('live_chat_group_create.php', API_ROOT).href,
  groupManage: new URL('live_chat_group_manage.php', API_ROOT).href,
  action: new URL('live_chat_action.php', API_ROOT).href,
  poll: new URL('live_chat_poll.php', API_ROOT).href
};

const REACTION_EMOJIS = ['\u{1F44D}', '\u{2764}\u{FE0F}', '\u{1F602}', '\u{1F622}', '\u{1F64F}', '\u{1F389}'];
const PICKER_EMOJIS = ['\u{1F600}', '\u{1F602}', '\u{1F60A}', '\u{1F60D}', '\u{1F44D}', '\u{1F64F}', '\u{1F44F}', '\u{2705}', '\u{26A0}\u{FE0F}', '\u{1F4CC}', '\u{1F4CE}', '\u{1F4DE}', '\u{1F3A5}', '\u{2615}', '\u{1F525}', '\u{1F4A1}', '\u{1F389}', '\u{2764}\u{FE0F}'];
const INCOMING_RING_URL = new URL('audio/notification.mp3', APP_ROOT).href;

function escapeHtml(value) {
  const div = document.createElement('div');
  div.textContent = String(value ?? '');
  return div.innerHTML;
}

function formatText(value) {
  return escapeHtml(value).replace(/\n/g, '<br>');
}

function formatTime(value) {
  if (!value) return '';
  const date = new Date(String(value).replace(' ', 'T'));
  if (Number.isNaN(date.getTime())) return '';
  return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function ensureStylesheet() {
  if (document.getElementById(LIVE_CHAT_STYLE_ID)) return;
  const link = document.createElement('link');
  link.id = LIVE_CHAT_STYLE_ID;
  link.rel = 'stylesheet';
  link.href = new URL('css/live_chat.css?v=20260528d', APP_ROOT).href;
  document.head.appendChild(link);
}

function ensureIconStylesheet() {
  if (document.getElementById(LIVE_CHAT_ICON_STYLE_ID)) return;
  if (document.querySelector('link[href*="font-awesome"],link[href*="fontawesome"]')) return;
  const csp = document.querySelector('meta[http-equiv="Content-Security-Policy" i]')?.content || '';
  const stylePolicy = csp.match(/(?:^|;)\s*style-src\s+([^;]+)/i)?.[1] || '';
  const allowsExternalStyles = !stylePolicy
    || /\*/.test(stylePolicy)
    || /\bhttps:\b/i.test(stylePolicy)
    || /cdnjs\.cloudflare\.com/i.test(stylePolicy);
  if (!allowsExternalStyles) {
    useFallbackIcons = true;
    return;
  }
  const link = document.createElement('link');
  link.id = LIVE_CHAT_ICON_STYLE_ID;
  link.rel = 'stylesheet';
  link.href = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css';
  link.onerror = () => {
    useFallbackIcons = true;
    document.getElementById('liveChatDock')?.classList.add('live-fallback-icons');
  };
  document.head.appendChild(link);
}

async function parseJsonResponse(response) {
  const text = await response.text();
  try {
    return JSON.parse(text);
  } catch (_error) {
    const clean = text.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
    throw new Error(clean || `Live chat server returned ${response.status}.`);
  }
}

function showNotice(title, message, type = 'error') {
  if (typeof window.appToast === 'function') {
    window.appToast(message, { title, type, duration: 4200 });
    return;
  }
  window.alert(`${title}: ${message}`);
}

function normalizePhoto(path) {
  const value = String(path || '').trim().replace(/\\/g, '/');
  if (!value) return DEFAULT_PHOTO;
  if (/^(?:https?:|data:|blob:)/i.test(value)) return value;
  if (value.startsWith('backend/')) return new URL(`../${value}`, APP_ROOT).href;
  if (value.startsWith('../uploads/')) return new URL(`../backend/${value.replace(/^\.\.\//, '')}`, APP_ROOT).href;
  if (value.startsWith('uploads/')) return new URL(value, BACKEND_ROOT).href;
  if (value.startsWith('../backend/')) return new URL(value.replace(/^\.\.\//, ''), APP_ROOT).href;
  if (value.startsWith('../')) return new URL(value, APP_ROOT).href;
  return new URL(value, APP_ROOT).href;
}

class LiveChatApp {
  constructor(options = {}) {
    this.currentUserId = String(options.userId || localStorage.getItem('loggedInUser') || '');
    this.users = [];
    this.groups = [];
    this.filteredQuery = '';
    this.selectedThread = null;
    this.lastMessageIdByThread = {};
    this.renderedMessageIds = new Set();
    this.selectedMessageIds = new Set();
    this.replyToMessage = null;
    this.editingMessage = null;
    this.sending = false;
    this.recorder = null;
    this.recordedChunks = [];
    this.recordingStream = null;
    this.recordingStartTime = 0;
    this.recordingTimer = null;
    this.voiceDraft = null;
    this.attachmentDraft = null;
    this.cameraStream = null;
    this.drawingOnAttachment = false;
    this.lastPointer = null;
    this.activeCall = null;
    this.groupCallPeerIds = [];
    this.groupCallSessions = new Map();
    this.peerConnection = null;
    this.localStream = null;
    this.remoteStream = null;
    this.pendingIceCandidates = [];
    this.lastSignalId = 0;
    this.incomingRing = null;
    this.outgoingRing = null;
    this.callSettings = {
      incomingSoundEnabled: true,
      outgoingSoundEnabled: true,
      desktopAlertsEnabled: true,
      incomingSoundPath: 'audio/notification.mp3',
      outgoingSoundPath: 'audio/notification.mp3',
      incomingVolume: 85,
      outgoingVolume: 55,
      incomingRepeatCount: 0,
      outgoingRepeatCount: 0,
      ringingTimeoutSeconds: 45
    };
    this.messageSettings = {
      messageSoundEnabled: true,
      desktopAlertsEnabled: true,
      messageSoundPath: 'audio/notification.mp3',
      messageVolume: 70,
      messageRepeatCount: 1
    };
    this.unreadTotal = 0;
    this.unreadCountsReady = false;
    this.messageNoticeTimer = null;
    this.autoScrollEnabled = true;
    this.availableVideoDevices = [];
    this.selectedVideoDeviceId = '';
    this.cameraFacingMode = 'environment';
    this.cameraZoom = 1;
    this.cameraSwitching = false;
    this.mirrorFrontCamera = true;
    this.callSpeakerEnabled = localStorage.getItem('liveCallSpeakerEnabled') !== '0';
    this.callSpeakerVolume = Math.max(0, Math.min(100, Number(localStorage.getItem('liveCallSpeakerVolume') || 100)));
    this.callTimeoutTimer = null;
    this.videoUpgradeConsentOverlay = null;
    this.remoteTrackMuteHandlers = new Map();
    this.bound = false;
    this.timers = { presence: null, messages: null, calls: null, signals: null };
  }

  async init() {
    ensureStylesheet();
    ensureIconStylesheet();
    this.injectDock();
    this.bindEvents();
    this.syncIncomingSoundButton();
    await this.loadUsers();
    this.startPolling();
    window.addEventListener('beforeunload', () => this.stop());
  }

  injectDock() {
    if (document.getElementById('liveChatDock')) return;
    const dock = document.createElement('section');
    dock.id = 'liveChatDock';
    dock.className = 'live-chat-dock collapsed';
    if (useFallbackIcons) dock.classList.add('live-fallback-icons');
    dock.innerHTML = `
      <button class="live-chat-launcher" id="liveChatLauncher" type="button" aria-label="Open live chat">
        <i class="fas fa-comments" aria-hidden="true"></i><span>Live Chat</span><strong id="liveChatUnreadTotal" class="hidden" title="Unread messages">0</strong>
      </button>
      <div class="live-message-notice hidden" id="liveMessageNotice" role="status" aria-live="polite"></div>
      <div class="live-chat-panel" id="liveChatPanel" aria-label="Staff live chat">
        <div class="live-chat-head">
          <div><strong>Staff Live Chat</strong><small id="liveChatStatusText">Connecting...</small></div>
          <div class="live-chat-head-actions">
            <button type="button" id="liveSoundToggleBtn" title="Incoming call sound"><i class="fas fa-volume-high" aria-hidden="true"></i></button>
            <button type="button" id="liveCallHistoryBtn" title="Call history"><i class="fas fa-clock-rotate-left" aria-hidden="true"></i></button>
            <button type="button" id="liveChatClose" title="Minimize"><i class="fas fa-minus" aria-hidden="true"></i></button>
          </div>
        </div>
        <div class="live-chat-body">
          <aside class="live-chat-sidebar">
            <div class="live-chat-list-tools">
              <button type="button" id="liveCreateGroupBtn" title="Create group"><i class="fas fa-users" aria-hidden="true"></i></button>
              <input id="liveContactSearch" type="search" placeholder="Search contacts or groups">
            </div>
            <div class="live-chat-users" id="liveChatUsers"></div>
          </aside>
          <section class="live-chat-thread">
            <div class="live-chat-peer" id="liveChatPeer">
              <div><span>Select a staff member or group</span></div>
              <div class="live-peer-actions">
                <button type="button" id="liveAudioCallBtn" title="Audio call" disabled><i class="fas fa-phone" aria-hidden="true"></i></button>
                <button type="button" id="liveVideoCallBtn" title="Video call" disabled><i class="fas fa-video" aria-hidden="true"></i></button>
              </div>
            </div>
            <div class="live-pinned-bar hidden" id="livePinnedBar"></div>
            <div class="live-selection-bar hidden" id="liveSelectionBar"></div>
            <div class="live-chat-messages" id="liveChatMessages">
              <div class="live-chat-empty">Choose a staff member or group to start a live conversation.</div>
            </div>
            <div class="live-chat-composer">
              <div class="emoji-picker hidden" id="liveEmojiPicker"></div>
              <div class="attachment-picker hidden" id="liveAttachmentPicker">
                <button type="button" data-accept=".pdf,.doc,.docx,.xls,.xlsx,.txt" data-label="Document"><i class="fas fa-file-alt"></i> Document</button>
                <button type="button" data-accept="image/*,video/*" data-label="Photos & Videos"><i class="fas fa-photo-video"></i> Photos & Videos</button>
                <button type="button" data-accept="image/*" data-capture="environment" data-label="Camera"><i class="fas fa-camera"></i> Camera</button>
                <button type="button" data-accept="audio/*" data-label="Audio"><i class="fas fa-music"></i> Audio</button>
                <button type="button" data-poll="true"><i class="fas fa-square-poll-vertical"></i> Poll</button>
              </div>
              <div class="live-reply-preview hidden" id="liveReplyPreview"></div>
              <div class="live-voice-draft hidden" id="liveVoiceDraft"></div>
              <div class="live-attachment-compose-preview hidden" id="liveAttachmentComposerPreview">
                <strong id="liveAttachmentComposeName">Attachment ready</strong>
                <textarea id="liveAttachmentCaption" rows="2" placeholder="Add a description or tagline"></textarea>
              </div>
              <button type="button" id="liveAttachBtn" class="live-plus-btn" title="Attachments" disabled><i class="fas fa-plus" aria-hidden="true"></i></button>
              <div class="live-input-wrap">
                <textarea id="liveChatInput" rows="2" placeholder="Type a message..." disabled></textarea>
                <button type="button" id="liveVoiceBtn" title="Record voice note" disabled><i class="fas fa-microphone" aria-hidden="true"></i></button>
              </div>
              <button type="button" id="liveSendBtn" disabled><i class="fas fa-paper-plane" aria-hidden="true"></i></button>
              <input type="file" id="liveChatAttachmentInput" class="hidden">
            </div>
          </section>
        </div>
      </div>
      <div class="live-call-modal hidden" id="liveCallModal">
        <div class="live-call-card">
          <div class="live-call-head"><strong id="liveCallTitle">Call</strong><span id="liveCallStatus">Preparing...</span></div>
          <div class="live-call-toolbar">
            <select id="liveCameraSelect" title="Camera"></select>
            <button type="button" id="liveSwitchCameraBtn" title="Switch camera"><i class="fas fa-camera-rotate"></i></button>
            <label class="live-call-zoom" title="Camera zoom"><i class="fas fa-magnifying-glass-plus"></i><input id="liveCallZoom" type="range" min="1" max="3" step="0.1" value="1"></label>
            <button type="button" id="liveToggleCameraBtn" title="Camera on/off"><i class="fas fa-video"></i></button>
            <button type="button" id="liveRequestVideoBtn" title="Switch audio call to video"><i class="fas fa-video"></i> Video</button>
            <button type="button" id="liveToggleMicBtn" title="Mute/unmute"><i class="fas fa-microphone"></i></button>
            <button type="button" id="liveToggleSpeakerBtn" title="Speaker on/off"><i class="fas fa-volume-high"></i></button>
            <label class="live-call-volume" title="Call volume"><i class="fas fa-volume-low"></i><input id="liveCallVolume" type="range" min="0" max="100" step="1" value="100"></label>
            <button type="button" id="liveFullscreenCallBtn" title="Fullscreen"><i class="fas fa-expand"></i></button>
          </div>
          <div class="live-video-grid">
            <video id="liveRemoteVideo" autoplay playsinline></video>
            <video id="liveLocalVideo" autoplay muted playsinline></video>
          </div>
          <div class="live-group-remote-grid hidden" id="liveGroupRemoteGrid"></div>
          <div class="live-call-actions">
            <button type="button" id="liveAcceptCallBtn" class="hidden"><i class="fas fa-phone" aria-hidden="true"></i> Accept</button>
            <button type="button" id="liveRejectCallBtn" class="hidden"><i class="fas fa-phone-slash" aria-hidden="true"></i> Reject</button>
            <button type="button" id="liveEndCallBtn"><i class="fas fa-phone-slash" aria-hidden="true"></i> End</button>
          </div>
        </div>
      </div>
      <div class="live-group-modal hidden" id="liveGroupModal">
        <div class="live-group-card">
          <div class="live-group-head"><strong>Create Group Chat</strong><button type="button" id="liveGroupClose"><i class="fas fa-times"></i></button></div>
          <input id="liveGroupName" type="text" placeholder="Group name">
          <div class="live-group-members" id="liveGroupMembers"></div>
          <button type="button" id="liveGroupSave">Create Group</button>
        </div>
      </div>
      <div class="live-call-history-modal hidden" id="liveCallHistoryModal">
        <div class="live-call-history-card">
          <div class="live-group-head"><strong>Call History</strong><button type="button" id="liveCallHistoryClose"><i class="fas fa-times"></i></button></div>
          <div class="live-call-history-list" id="liveCallHistoryList"></div>
        </div>
      </div>
      <div class="live-group-manage-modal hidden" id="liveGroupManageModal">
        <div class="live-group-card">
          <div class="live-group-head"><strong id="liveManageGroupTitle">Group Members</strong><button type="button" id="liveManageGroupClose"><i class="fas fa-times"></i></button></div>
          <div class="live-group-members" id="liveManageGroupMembers"></div>
          <button type="button" id="liveLeaveGroupBtn" class="live-secondary-btn hidden">Leave Group</button>
          <button type="button" id="liveManageGroupSave" class="hidden">Save Members</button>
        </div>
      </div>
      <div class="live-poll-modal hidden" id="livePollModal">
        <div class="live-poll-card">
          <div class="live-poll-head"><strong>Create Poll</strong><button type="button" id="livePollClose"><i class="fas fa-times"></i></button></div>
          <label>Question<input id="livePollQuestion" type="text" placeholder="Ask a clear question"></label>
          <div class="live-poll-options" id="livePollOptions">
            <input type="text" placeholder="Option 1">
            <input type="text" placeholder="Option 2">
          </div>
          <button type="button" id="livePollAddOption" class="live-secondary-btn"><i class="fas fa-plus"></i> Add option</button>
          <div class="live-poll-grid">
            <label>Priority<select id="livePollPriority"><option value="normal">Normal</option><option value="low">Low</option><option value="high">High</option><option value="urgent">Urgent</option></select></label>
            <label>Tag<input id="livePollTag" type="text" placeholder="e.g. HR, Urgent, Field"></label>
            <label>Close time<input id="livePollClosesAt" type="datetime-local"></label>
            <label class="live-switch"><input id="livePollMulti" type="checkbox"><span>Allow multiple votes per user</span></label>
          </div>
          <div class="live-poll-actions">
            <button type="button" id="livePollCancel" class="live-secondary-btn">Cancel</button>
            <button type="button" id="livePollCreate">Create Poll</button>
          </div>
        </div>
      </div>
      <div class="live-attachment-modal hidden" id="liveAttachmentModal">
        <div class="live-attachment-card">
          <div class="live-attachment-head"><strong id="liveAttachmentTitle">Attachment Preview</strong><button type="button" id="liveAttachmentClose"><i class="fas fa-times"></i></button></div>
          <div class="live-attachment-stage" id="liveAttachmentStage"></div>
          <div class="live-attachment-tools">
            <button type="button" id="liveAttachmentCrop"><i class="fas fa-crop"></i> Crop</button>
            <button type="button" id="liveAttachmentDoodle"><i class="fas fa-pen"></i> Doodle</button>
            <button type="button" id="liveAttachmentRetake"><i class="fas fa-camera-rotate"></i> Retake</button>
            <button type="button" id="liveAttachmentDelete"><i class="fas fa-trash"></i> Delete</button>
            <button type="button" id="liveAttachmentSend"><i class="fas fa-paper-plane"></i> Send</button>
          </div>
        </div>
      </div>
    `;
    document.body.appendChild(dock);
    document.getElementById('liveEmojiPicker').innerHTML = PICKER_EMOJIS.map((emoji) => `<button type="button" data-emoji="${emoji}">${emoji}</button>`).join('');
  }

  bindEvents() {
    if (this.bound) return;
    this.bound = true;
    document.getElementById('liveChatLauncher')?.addEventListener('click', () => {
      document.getElementById('liveChatDock')?.classList.remove('collapsed');
      if (!this.users.length) this.loadUsers().catch(() => {});
      this.requestCallNotificationPermission();
    });
    document.getElementById('liveChatClose')?.addEventListener('click', () => document.getElementById('liveChatDock')?.classList.add('collapsed'));
    document.getElementById('liveSoundToggleBtn')?.addEventListener('click', () => this.toggleIncomingCallSound());
    document.getElementById('liveCallHistoryBtn')?.addEventListener('click', () => this.openCallHistory());
    document.getElementById('liveCallHistoryClose')?.addEventListener('click', () => this.closeCallHistory());
    document.getElementById('liveSendBtn')?.addEventListener('click', () => this.sendText());
    document.getElementById('liveContactSearch')?.addEventListener('input', (event) => {
      this.filteredQuery = event.target.value || '';
      this.renderThreads();
    });
    document.getElementById('liveChatInput')?.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        this.sendText();
      }
    });
    document.getElementById('liveAttachBtn')?.addEventListener('click', (event) => {
      event.stopPropagation();
      document.getElementById('liveAttachmentPicker')?.classList.toggle('hidden');
      document.getElementById('liveEmojiPicker')?.classList.add('hidden');
    });
    document.getElementById('liveAttachmentPicker')?.addEventListener('click', (event) => this.handleAttachmentMenu(event));
    document.getElementById('liveChatAttachmentInput')?.addEventListener('change', (event) => this.sendAttachment(event.target.files?.[0]));
    document.getElementById('liveVoiceBtn')?.addEventListener('click', () => this.toggleVoiceRecording());
    document.getElementById('liveAudioCallBtn')?.addEventListener('click', () => this.startCall('audio'));
    document.getElementById('liveVideoCallBtn')?.addEventListener('click', () => this.startCall('video'));
    document.getElementById('liveAcceptCallBtn')?.addEventListener('click', () => this.acceptIncomingCall());
    document.getElementById('liveRejectCallBtn')?.addEventListener('click', () => this.rejectIncomingCall());
    document.getElementById('liveEndCallBtn')?.addEventListener('click', () => this.endCall());
    document.getElementById('liveCameraSelect')?.addEventListener('change', (event) => this.switchCamera(event.target.value));
    document.getElementById('liveSwitchCameraBtn')?.addEventListener('click', () => this.switchFacingCamera());
    document.getElementById('liveCallZoom')?.addEventListener('input', (event) => this.applyCameraZoom(Number(event.target.value || 1)));
    document.getElementById('liveToggleCameraBtn')?.addEventListener('click', () => this.toggleCamera());
    document.getElementById('liveRequestVideoBtn')?.addEventListener('click', () => this.requestVideoUpgrade());
    document.getElementById('liveToggleMicBtn')?.addEventListener('click', () => this.toggleMicrophone());
    document.getElementById('liveToggleSpeakerBtn')?.addEventListener('click', () => this.toggleSpeaker());
    document.getElementById('liveCallVolume')?.addEventListener('input', (event) => this.setSpeakerVolume(Number(event.target.value || 0)));
    document.getElementById('liveFullscreenCallBtn')?.addEventListener('click', () => this.toggleCallFullscreen());
    document.getElementById('liveCreateGroupBtn')?.addEventListener('click', () => this.openGroupModal());
    document.getElementById('liveGroupClose')?.addEventListener('click', () => this.closeGroupModal());
    document.getElementById('liveGroupSave')?.addEventListener('click', () => this.createGroup());
    document.getElementById('liveManageGroupClose')?.addEventListener('click', () => this.closeGroupManageModal());
    document.getElementById('liveManageGroupSave')?.addEventListener('click', () => this.saveGroupMembers());
    document.getElementById('liveLeaveGroupBtn')?.addEventListener('click', () => this.leaveCurrentGroup());
    document.getElementById('livePollClose')?.addEventListener('click', () => this.closePollModal());
    document.getElementById('livePollCancel')?.addEventListener('click', () => this.closePollModal());
    document.getElementById('livePollAddOption')?.addEventListener('click', () => this.addPollOption());
    document.getElementById('livePollCreate')?.addEventListener('click', () => this.createPoll());
    document.getElementById('liveChatMessages')?.addEventListener('click', (event) => this.handleMessageClick(event));
    document.getElementById('liveChatMessages')?.addEventListener('keydown', (event) => this.handleMessageKeydown(event));
    document.getElementById('liveChatMessages')?.addEventListener('scroll', () => this.updateAutoScrollPreference());
    document.getElementById('liveAttachmentClose')?.addEventListener('click', () => this.closeAttachmentEditor());
    document.getElementById('liveAttachmentCrop')?.addEventListener('click', () => this.cropAttachmentCenter());
    document.getElementById('liveAttachmentDoodle')?.addEventListener('click', () => this.toggleAttachmentDoodle());
    document.getElementById('liveAttachmentRetake')?.addEventListener('click', () => this.retakeAttachment());
    document.getElementById('liveAttachmentDelete')?.addEventListener('click', () => this.clearAttachmentDraft());
    document.getElementById('liveAttachmentSend')?.addEventListener('click', () => this.sendAttachmentDraft());
    document.getElementById('liveSelectionBar')?.addEventListener('click', (event) => this.handleSelectionAction(event));
    document.getElementById('livePinnedBar')?.addEventListener('click', (event) => this.handlePinnedBarClick(event));
    document.addEventListener('click', (event) => this.closeTransientPopups(event));
  }

  async requestCallNotificationPermission() {
    if (!('Notification' in window) || Notification.permission !== 'default') return;
    await Notification.requestPermission().catch(() => {});
  }

  async openCallHistory() {
    const modal = document.getElementById('liveCallHistoryModal');
    const list = document.getElementById('liveCallHistoryList');
    modal?.classList.remove('hidden');
    if (list) list.innerHTML = '<div class="live-chat-empty">Loading call history...</div>';
    try {
      const data = await this.postCall({ action: 'history' }, 'GET');
      this.renderCallHistory(data.history || []);
    } catch (error) {
      if (list) list.innerHTML = `<div class="live-chat-empty">${escapeHtml(error.message || 'Unable to load call history.')}</div>`;
    }
  }

  closeCallHistory() {
    document.getElementById('liveCallHistoryModal')?.classList.add('hidden');
  }

  renderCallHistory(history = []) {
    const list = document.getElementById('liveCallHistoryList');
    if (!list) return;
    if (!history.length) {
      list.innerHTML = '<div class="live-chat-empty">No audio or video call history yet.</div>';
      return;
    }
    list.innerHTML = history.map((call) => {
      const icon = call.callType === 'video' ? 'fa-video' : 'fa-phone';
      const directionIcon = call.direction === 'outgoing' ? 'fa-arrow-up-right-from-square' : 'fa-arrow-down-left';
      const duration = Number(call.durationSeconds || 0) > 0 ? this.formatCallDuration(call.durationSeconds) : 'No duration';
      return `
        <article class="live-call-history-item ${escapeHtml(call.status || '')}">
          <div class="live-call-history-icon"><i class="fas ${icon}"></i></div>
          <div>
            <strong>${escapeHtml(call.peerName || 'Staff member')}</strong>
            <small><i class="fas ${directionIcon}"></i> ${escapeHtml(call.direction || '')} ${escapeHtml(call.callType || 'audio')} call</small>
            <span>${escapeHtml(formatTime(call.createdAt))} - ${escapeHtml(duration)}</span>
          </div>
          <em>${escapeHtml(call.status || 'logged')}</em>
        </article>
      `;
    }).join('');
  }

  formatCallDuration(seconds) {
    const value = Math.max(0, Number(seconds || 0));
    const minutes = Math.floor(value / 60);
    const remaining = Math.floor(value % 60);
    return minutes > 0 ? `${minutes}m ${remaining}s` : `${remaining}s`;
  }

  closeTransientPopups(event) {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;
    if (!target.closest('.live-message-menu') && !target.closest('.live-message-actions-btn')) {
      document.querySelectorAll('.live-message-menu').forEach((menu) => menu.remove());
    }
    if (!target.closest('#liveAttachmentPicker') && !target.closest('#liveAttachBtn')) {
      document.getElementById('liveAttachmentPicker')?.classList.add('hidden');
    }
  }

  async fetchJson(url, options = {}) {
    const response = await fetch(url, { credentials: 'include', cache: 'no-store', ...options });
    const data = await parseJsonResponse(response);
    if (!data.success) throw new Error(data.message || 'Live chat request failed.');
    return data;
  }

  threadKey(thread = this.selectedThread) {
    return thread ? `${thread.type}:${thread.id}` : '';
  }

  async loadUsers() {
    try {
      const data = await this.fetchJson(API.bootstrap);
      this.currentUserId = String(data.currentUserId || this.currentUserId);
      this.users = data.users || [];
      this.groups = data.groups || [];
      const previousUnreadTotal = this.unreadTotal;
      this.unreadTotal = Number(data.unreadTotal || 0);
      this.messageSettings = { ...this.messageSettings, ...(data.messageSettings || {}) };
      this.callSettings = { ...this.callSettings, ...(data.callSettings || {}) };
      if (this.unreadCountsReady && this.unreadTotal > previousUnreadTotal) {
        this.playMessageNotification({ senderName: 'PensionsGo Live Chat', text: `${this.unreadTotal - previousUnreadTotal} new unread message${this.unreadTotal - previousUnreadTotal === 1 ? '' : 's'}.` });
      }
      this.unreadCountsReady = true;
      this.renderThreads();
      this.updateStatus();
    } catch (error) {
      this.users = [];
      this.groups = [];
      this.renderThreads(error.message || 'Live chat is unavailable.');
      this.updateStatus('Unavailable');
      console.warn('Live chat initialization failed:', error);
    }
  }

  getThreads() {
    const groups = this.groups.map((group) => ({
      type: 'group',
      id: group.groupId,
      name: group.groupName,
      subtitle: `${group.memberCount || 0} members`,
      photo: '',
      isOnline: false,
      unreadCount: Number(group.unreadCount || 0)
    }));
    const users = this.users.map((user) => ({
      type: 'user',
      id: user.userId,
      name: user.userName,
      subtitle: `${user.userRoleLabel || this.formatRoleLabel(user.userRole)}${user.isOnline ? ' - online' : ''}`,
      photo: user.userPhoto,
      isOnline: user.isOnline,
      unreadCount: Number(user.unreadCount || 0)
    }));
    const query = this.filteredQuery.trim().toLowerCase();
    users.sort((a, b) => Number(b.isOnline) - Number(a.isOnline) || a.name.localeCompare(b.name));
    return [...groups, ...users].filter((thread) => !query || `${thread.name} ${thread.subtitle}`.toLowerCase().includes(query));
  }

  formatRoleLabel(role) {
    return String(role || '')
      .split('_')
      .filter(Boolean)
      .map((part) => part.charAt(0).toUpperCase() + part.slice(1).toLowerCase())
      .join(' ') || 'Staff';
  }

  renderThreads(message = '') {
    const container = document.getElementById('liveChatUsers');
    if (!container) return;
    const threads = this.getThreads();
    if (!threads.length) {
      container.innerHTML = `<div class="live-chat-empty">${escapeHtml(message || 'No contacts or groups available.')}</div>`;
      return;
    }
    container.innerHTML = threads.map((thread) => {
      const active = this.threadKey() === `${thread.type}:${thread.id}`;
      return `
        <button type="button" class="live-chat-user ${active ? 'active' : ''}" data-thread-type="${thread.type}" data-thread-id="${escapeHtml(thread.id)}">
          <span class="presence-dot ${thread.isOnline ? 'online' : ''}"></span>
          ${thread.type === 'group' ? '<span class="live-group-avatar"><i class="fas fa-users"></i></span>' : `<img src="${normalizePhoto(thread.photo)}" alt="${escapeHtml(thread.name)}" onerror="this.onerror=null;this.src='${DEFAULT_PHOTO}'">`}
          <span><strong>${escapeHtml(thread.name)}${thread.unreadCount > 0 ? `<em class="live-thread-unread">${thread.unreadCount > 99 ? '99+' : thread.unreadCount}</em>` : ''}</strong><small>${escapeHtml(thread.subtitle)}</small></span>
        </button>
      `;
    }).join('');
    container.querySelectorAll('.live-chat-user').forEach((button) => {
      button.addEventListener('click', () => this.selectThread(button.dataset.threadType, button.dataset.threadId));
    });
  }

  updateStatus(text = '') {
    const online = this.users.filter((user) => user.isOnline).length;
    document.getElementById('liveChatStatusText').textContent = text || `${online} staff online`;
    const unreadBadge = document.getElementById('liveChatUnreadTotal');
    if (unreadBadge) {
      unreadBadge.textContent = this.unreadTotal > 99 ? '99+' : String(this.unreadTotal || 0);
      unreadBadge.classList.toggle('hidden', this.unreadTotal <= 0);
      unreadBadge.setAttribute('aria-label', `${this.unreadTotal || 0} unread messages`);
    }
  }

  async selectThread(type, id) {
    const thread = this.getThreads().find((item) => item.type === type && item.id === id);
    if (!thread) return;
    this.selectedThread = thread;
    this.clearMessageSelection();
    document.getElementById('liveChatPanel')?.classList.toggle('thread-open', true);
    this.replyToMessage = null;
    this.editingMessage = null;
    this.renderThreads();
    this.renderReplyPreview();
    document.getElementById('liveChatPeer').innerHTML = `
      <button type="button" class="live-thread-back" id="liveThreadBackBtn" title="Back"><i class="fas fa-arrow-left"></i></button>
      <div><strong>${escapeHtml(thread.name)}</strong><small>${escapeHtml(thread.subtitle)}</small></div>
      <div class="live-peer-actions">
        ${thread.type === 'group' ? '<button type="button" id="liveGroupInfoBtn" title="Group members"><i class="fas fa-users-gear"></i></button>' : ''}
        <button type="button" id="liveAudioCallBtn" title="Audio call"><i class="fas fa-phone" aria-hidden="true"></i></button>
        <button type="button" id="liveVideoCallBtn" title="Video call"><i class="fas fa-video" aria-hidden="true"></i></button>
      </div>
    `;
    document.getElementById('liveThreadBackBtn')?.addEventListener('click', () => this.showThreadList());
    document.getElementById('liveGroupInfoBtn')?.addEventListener('click', () => this.openGroupManageModal());
    document.getElementById('liveAudioCallBtn')?.addEventListener('click', () => this.startCall('audio'));
    document.getElementById('liveVideoCallBtn')?.addEventListener('click', () => this.startCall('video'));
    ['liveChatInput','liveSendBtn','liveVoiceBtn','liveAttachBtn'].forEach((elementId) => {
      const element = document.getElementById(elementId);
      if (element) element.disabled = false;
    });
    this.lastMessageIdByThread[this.threadKey()] = 0;
    this.renderedMessageIds.clear();
    document.getElementById('liveChatMessages').innerHTML = '<div class="live-chat-empty">Loading conversation...</div>';
    await this.loadMessages(true);
  }

  showThreadList() {
    document.getElementById('liveChatPanel')?.classList.remove('thread-open');
  }

  startPolling() {
    this.stop();
    this.timers.presence = setInterval(() => this.refreshPresence(), 10000);
    this.timers.messages = setInterval(() => this.loadMessages(false), 3000);
    this.timers.calls = setInterval(() => this.pollCalls(), 900);
    this.refreshPresence();
    this.pollCalls();
  }

  stop() {
    Object.keys(this.timers).forEach((key) => {
      if (this.timers[key]) clearInterval(this.timers[key]);
      this.timers[key] = null;
    });
    this.cleanupCall();
  }

  async refreshPresence() {
    try {
      const data = await this.fetchJson(API.bootstrap);
      this.users = data.users || this.users;
      this.groups = data.groups || this.groups;
      const previousUnreadTotal = this.unreadTotal;
      this.unreadTotal = Number(data.unreadTotal || 0);
      this.messageSettings = { ...this.messageSettings, ...(data.messageSettings || {}) };
      this.callSettings = { ...this.callSettings, ...(data.callSettings || {}) };
      if (this.unreadCountsReady && this.unreadTotal > previousUnreadTotal) {
        this.playMessageNotification({ senderName: 'PensionsGo Live Chat', text: `${this.unreadTotal - previousUnreadTotal} new unread message${this.unreadTotal - previousUnreadTotal === 1 ? '' : 's'}.` });
      }
      this.unreadCountsReady = true;
      this.renderThreads();
      this.updateStatus();
    } catch (_error) {}
  }

  async loadMessages(force = false) {
    if (!this.selectedThread) return;
    const key = this.threadKey();
    const since = force ? 0 : (this.lastMessageIdByThread[key] || 0);
    try {
      const url = `${API.messages}?peer_type=${encodeURIComponent(this.selectedThread.type)}&peer_id=${encodeURIComponent(this.selectedThread.id)}&since_id=${since}`;
      const data = await this.fetchJson(url);
      const incoming = (data.messages || []).filter((message) => !message.isOwn && !this.renderedMessageIds.has(Number(message.id || 0)));
      this.renderMessages(data.messages || [], force);
      if (!force && incoming.length > 0) {
        this.playMessageNotification(incoming[incoming.length - 1]);
      }
      if (force || incoming.length > 0) {
        this.loadUsers().catch(() => {});
      }
    } catch (error) {
      console.warn('Live chat message poll failed:', error);
    }
  }

  renderMessages(messages, replace = false) {
    const container = document.getElementById('liveChatMessages');
    if (!container) return;
    const shouldScroll = replace || this.shouldStickToBottom(container);
    if (replace) {
      container.innerHTML = '';
      this.renderedMessageIds.clear();
      this.renderPinnedMessages(messages);
    }
    if (!messages.length && replace) {
      container.innerHTML = '<div class="live-chat-empty">No messages yet. Say hello.</div>';
      return;
    }
    container.querySelector('.live-chat-empty')?.remove();
    messages.forEach((message) => {
      const id = Number(message.id || 0);
      if (this.renderedMessageIds.has(id)) return;
      this.renderedMessageIds.add(id);
      this.lastMessageIdByThread[this.threadKey()] = Math.max(this.lastMessageIdByThread[this.threadKey()] || 0, id);
      const bubble = document.createElement('div');
      bubble.className = `live-chat-bubble ${message.isOwn ? 'own' : ''} ${message.isPinned ? 'pinned' : ''}`;
      bubble.dataset.messageId = String(id);
      bubble.dataset.own = message.isOwn ? '1' : '0';
      bubble.dataset.deleted = message.isDeleted ? '1' : '0';
      bubble.dataset.read = message.isRead ? '1' : '0';
      bubble.dataset.createdAt = message.createdAt || '';
      bubble.dataset.readAt = message.readAt || '';
      bubble.dataset.text = message.text || '';
      bubble.dataset.sender = message.senderName || '';
      bubble.dataset.pinned = message.isPinned ? '1' : '0';
      bubble.innerHTML = this.messageHtml(message);
      bubble.classList.toggle('selected', this.selectedMessageIds.has(id));
      this.bindMessageGestures(bubble);
      container.appendChild(bubble);
    });
    if (shouldScroll && this.autoScrollEnabled) {
      container.scrollTop = container.scrollHeight;
    }
  }

  shouldStickToBottom(container) {
    return container.scrollHeight - container.scrollTop - container.clientHeight < 80;
  }

  updateAutoScrollPreference() {
    const container = document.getElementById('liveChatMessages');
    if (!container) return;
    this.autoScrollEnabled = this.shouldStickToBottom(container);
  }

  renderPinnedMessages(messages = []) {
    const bar = document.getElementById('livePinnedBar');
    if (!bar) return;
    const pinned = messages.filter((message) => message.isPinned && !message.isDeleted).slice(-3).reverse();
    bar.classList.toggle('hidden', pinned.length === 0);
    bar.innerHTML = pinned.map((message) => `
      <button type="button" data-pinned-message-id="${Number(message.id || 0)}">
        <i class="fas fa-thumbtack"></i>
        <span>${escapeHtml(message.text || message.fileName || 'Pinned message')}</span>
      </button>
    `).join('');
  }

  handlePinnedBarClick(event) {
    const button = event.target instanceof Element ? event.target.closest('[data-pinned-message-id]') : null;
    if (!button) return;
    this.scrollToMessage(button.dataset.pinnedMessageId);
  }

  async scrollToMessage(messageId) {
    const id = Number(messageId || 0);
    if (!id) return;
    let bubble = document.querySelector(`.live-chat-bubble[data-message-id="${id}"]`);
    if (!bubble && this.selectedThread) {
      await this.loadMessages(true);
      bubble = document.querySelector(`.live-chat-bubble[data-message-id="${id}"]`);
    }
    if (!bubble) {
      showNotice('Live Chat', 'The replied message is not available in this thread.', 'info');
      return;
    }
    bubble?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    bubble?.classList.add('selected-flash');
    window.setTimeout(() => bubble?.classList.remove('selected-flash'), 900);
  }

  replyReferenceHtml(message) {
    const replyId = Number(message.replyToMessageId || 0);
    if (!replyId) return '';
    const sender = message.replyToSenderName || 'Message';
    const content = message.replyToMessageText || message.replyToFileName || 'Original message';
    return `
      <div class="live-reply-ref" role="button" tabindex="0" data-reply-message-id="${replyId}" title="Open replied message">
        <strong>Replying to ${escapeHtml(sender)}:</strong>
        <span>${escapeHtml(content)}</span>
      </div>
    `;
  }

  messageHtml(message) {
    if (message.isDeleted) {
      return `<button type="button" class="live-message-actions-btn" title="Message actions"><i class="fas fa-chevron-down"></i></button><em class="live-deleted-message">This message was deleted</em><small>${formatTime(message.createdAt)}</small>`;
    }
    const fileUrl = message.filePath ? new URL(message.filePath, BACKEND_ROOT).href : '';
    const fileName = escapeHtml(message.fileName || 'Attachment');
    const mimeType = String(message.mimeType || '').toLowerCase();
    let filePart = '';
    if (fileUrl) {
      const downloadLink = `<a class="live-file-download" href="${fileUrl}" download="${fileName}"><i class="fas fa-download"></i> Download</a>`;
      if (message.kind === 'voice' || mimeType.startsWith('audio/')) {
        filePart = `<div class="live-file-preview-message"><audio controls src="${fileUrl}"></audio>${downloadLink}</div>`;
      } else if (mimeType.startsWith('image/')) {
        filePart = `<figure class="live-file-preview-message"><a href="${fileUrl}" target="_blank" rel="noopener"><img src="${fileUrl}" alt="${fileName}" loading="lazy"></a><figcaption>${fileName}</figcaption>${downloadLink}</figure>`;
      } else if (mimeType.startsWith('video/')) {
        filePart = `<div class="live-file-preview-message"><video controls preload="metadata" src="${fileUrl}"></video><span>${fileName}</span>${downloadLink}</div>`;
      } else {
        filePart = `<div class="live-file-preview-message compact"><a href="${fileUrl}" target="_blank" rel="noopener"><i class="fas fa-paperclip" aria-hidden="true"></i> ${fileName}</a>${downloadLink}</div>`;
      }
    }
    const pollPart = message.poll ? this.pollHtml(message.poll) : '';
    return `
      <button type="button" class="live-message-actions-btn" title="Message actions"><i class="fas fa-chevron-down"></i></button>
      ${this.selectedThread?.type === 'group' && !message.isOwn ? `<strong class="live-message-sender">${escapeHtml(message.senderName || 'Staff')}</strong>` : ''}
      ${this.replyReferenceHtml(message)}
      ${message.text && !message.poll ? `<p>${formatText(message.text)}</p>` : ''}
      ${filePart}
      ${pollPart}
      <small>${formatTime(message.createdAt)}${message.isEdited ? ' edited' : ''}${message.isPinned ? ' pinned' : ''}</small>
      ${message.reactionEmoji ? `<span class="live-message-reaction">${escapeHtml(message.reactionEmoji)}</span>` : ''}
    `;
  }

  pollHtml(poll) {
    const totalVotes = Math.max(0, Number(poll.totalVotes || 0));
    const options = (poll.options || []).map((option) => {
      const count = Number(option.voteCount || 0);
      const percent = totalVotes > 0 ? Math.round((count / totalVotes) * 100) : 0;
      return `
        <button type="button" class="live-poll-option ${option.votedByMe ? 'voted' : ''}" data-poll-id="${poll.pollId}" data-option-id="${option.optionId}">
          <span>${escapeHtml(option.text)}</span>
          <strong>${percent}%</strong>
          <em style="width:${percent}%"></em>
        </button>
      `;
    }).join('');
    return `
      <div class="live-poll-message">
        <div class="live-poll-meta">
          <span>${escapeHtml(poll.priority || 'normal')}</span>
          ${poll.tag ? `<span>${escapeHtml(poll.tag)}</span>` : ''}
          ${poll.allowMultiple ? '<span>multi-vote</span>' : '<span>single vote</span>'}
        </div>
        <strong>${escapeHtml(poll.question)}</strong>
        <div class="live-poll-option-list">${options}</div>
        <small>${totalVotes} vote${totalVotes === 1 ? '' : 's'}${poll.closesAt ? ` - closes ${escapeHtml(poll.closesAt)}` : ''}</small>
      </div>
    `;
  }

  handleMessageClick(event) {
    const replyRef = event.target instanceof Element ? event.target.closest('.live-reply-ref[data-reply-message-id]') : null;
    if (replyRef) {
      event.stopPropagation();
      this.scrollToMessage(replyRef.dataset.replyMessageId);
      return;
    }
    const pollOption = event.target instanceof Element ? event.target.closest('.live-poll-option') : null;
    if (pollOption) {
      this.votePoll(pollOption);
      return;
    }
    const bubbleFromTap = event.target instanceof Element ? event.target.closest('.live-chat-bubble') : null;
    if (bubbleFromTap?.dataset.suppressTap === '1') {
      delete bubbleFromTap.dataset.suppressTap;
      return;
    }
    const tappedActionSurface = event.target instanceof Element
      ? event.target.closest('.live-message-actions-btn,.live-message-menu,a,audio,video,button')
      : null;
    if (bubbleFromTap && this.selectedMessageIds.size > 0 && !tappedActionSurface) {
      this.toggleMessageSelection(bubbleFromTap);
      return;
    }
    const button = event.target instanceof Element ? event.target.closest('.live-message-actions-btn') : null;
    if (!button) return;
    event.stopPropagation();
    document.querySelectorAll('.live-message-menu').forEach((menu) => menu.remove());
    const bubble = button.closest('.live-chat-bubble');
    if (!bubble) return;
    const isDeleted = bubble.dataset.deleted === '1';
    const isOwn = bubble.dataset.own === '1';
    const isPinned = bubble.dataset.pinned === '1';
    const menu = document.createElement('div');
    menu.className = 'live-message-menu';
    if (isDeleted) {
      menu.innerHTML = `
        <button type="button" data-action="delete">Delete</button>
        <button type="button" data-action="select">Select</button>
      `;
    } else {
      menu.innerHTML = `
        ${isOwn ? '<button type="button" data-action="info">Info</button>' : ''}
        <button type="button" data-action="reply">Reply</button>
        <button type="button" data-action="copy">Copy</button>
        <button type="button" data-action="forward">Forward</button>
        <button type="button" data-action="${isPinned ? 'unpin' : 'pin'}">${isPinned ? 'Unpin' : 'Pin'}</button>
        <button type="button" data-action="select">Select</button>
        ${isOwn ? '<button type="button" data-action="edit">Edit</button>' : ''}
        <button type="button" data-action="delete">Delete</button>
        <div class="live-reaction-row">${REACTION_EMOJIS.map((emoji) => `<button type="button" data-reaction="${emoji}">${emoji}</button>`).join('')}</div>
      `;
    }
    bubble.appendChild(menu);
    menu.addEventListener('click', (menuEvent) => this.handleMessageAction(menuEvent, bubble));
  }

  handleMessageKeydown(event) {
    if (!['Enter', ' '].includes(event.key)) return;
    const replyRef = event.target instanceof Element ? event.target.closest('.live-reply-ref[data-reply-message-id]') : null;
    if (!replyRef) return;
    event.preventDefault();
    this.scrollToMessage(replyRef.dataset.replyMessageId);
  }

  async votePoll(button) {
    const pollId = Number(button.dataset.pollId || 0);
    const optionId = Number(button.dataset.optionId || 0);
    if (!pollId || !optionId) return;
    try {
      await this.fetchJson(API.poll, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'vote', poll_id: pollId, option_ids: [optionId] })
      });
      await this.reloadCurrentThread();
    } catch (error) {
      showNotice('Poll', error.message || 'Unable to record vote.');
    }
  }

  async handleMessageAction(event, bubble) {
    event.stopPropagation();
    const reactionButton = event.target instanceof Element ? event.target.closest('[data-reaction]') : null;
    const actionButton = event.target instanceof Element ? event.target.closest('[data-action]') : null;
    const messageId = Number(bubble.dataset.messageId || 0);
    const text = bubble.dataset.text || '';
    if (reactionButton) {
      await this.runMessageAction('react', messageId, { emoji: reactionButton.dataset.reaction || '' });
      await this.reloadCurrentThread();
      return;
    }
    if (!actionButton) return;
    const action = actionButton.dataset.action;
    if (action === 'copy') {
      await navigator.clipboard?.writeText(text).catch(() => {});
      showNotice('Copied', 'Message copied to clipboard.', 'success');
    } else if (action === 'reply') {
      this.replyToMessage = { id: messageId, text, sender: bubble.dataset.sender || 'Message' };
      this.renderReplyPreview();
    } else if (action === 'edit') {
      this.editingMessage = { id: messageId };
      document.getElementById('liveChatInput').value = text;
      document.getElementById('liveChatInput').focus();
      this.renderReplyPreview('Editing message');
    } else if (action === 'delete') {
      await this.runMessageAction('delete', messageId);
      bubble.remove();
      this.renderedMessageIds.delete(messageId);
      await this.reloadCurrentThread();
    } else if (action === 'pin' || action === 'unpin') {
      await this.runMessageAction('pin', messageId, { is_pinned: action === 'pin' });
      await this.reloadCurrentThread();
    } else if (action === 'forward') {
      this.prepareForward(text);
    } else if (action === 'select') {
      this.toggleMessageSelection(bubble);
    } else if (action === 'info') {
      if (bubble.dataset.own !== '1') return;
      const delivered = bubble.dataset.createdAt ? new Date(String(bubble.dataset.createdAt).replace(' ', 'T')).toLocaleString() : 'Not available';
      const read = bubble.dataset.readAt ? new Date(String(bubble.dataset.readAt).replace(' ', 'T')).toLocaleString() : 'Not read yet';
      showNotice('Message Info', `Delivered: ${delivered}\nRead: ${read}`, 'info');
    }
    document.querySelectorAll('.live-message-menu').forEach((menu) => menu.remove());
  }

  toggleMessageSelection(bubble, forceState = null) {
    const messageId = Number(bubble?.dataset?.messageId || 0);
    if (!messageId) return;
    const shouldSelect = forceState === null ? !this.selectedMessageIds.has(messageId) : Boolean(forceState);
    if (shouldSelect) this.selectedMessageIds.add(messageId);
    else this.selectedMessageIds.delete(messageId);
    bubble.classList.toggle('selected', shouldSelect);
    this.renderSelectionBar();
  }

  bindMessageGestures(bubble) {
    if (!bubble || bubble.dataset.gesturesBound === '1') return;
    bubble.dataset.gesturesBound = '1';
    let startX = 0;
    let startY = 0;
    let longPressTimer = null;
    let longPressed = false;
    let gestureActive = false;
    const clearTimer = () => {
      if (longPressTimer) window.clearTimeout(longPressTimer);
      longPressTimer = null;
    };
    bubble.addEventListener('pointerdown', (event) => {
      if (event.target instanceof Element && event.target.closest('.live-message-actions-btn,.live-message-menu,.live-reply-ref,a,audio,video,button')) {
        gestureActive = false;
        return;
      }
      gestureActive = true;
      startX = event.clientX;
      startY = event.clientY;
      longPressed = false;
      clearTimer();
      longPressTimer = window.setTimeout(() => {
        longPressed = true;
        bubble.dataset.suppressTap = '1';
        this.toggleMessageSelection(bubble, true);
      }, 650);
    });
    bubble.addEventListener('pointermove', (event) => {
      if (!gestureActive) return;
      if (Math.abs(event.clientX - startX) > 12 || Math.abs(event.clientY - startY) > 12) clearTimer();
    });
    bubble.addEventListener('pointerup', (event) => {
      if (!gestureActive) return;
      const dx = event.clientX - startX;
      const dy = event.clientY - startY;
      gestureActive = false;
      clearTimer();
      if (longPressed) return;
      if (Math.abs(dx) > 70 && Math.abs(dx) > Math.abs(dy) * 1.4) {
        bubble.dataset.suppressTap = '1';
        this.replyToMessage = {
          id: Number(bubble.dataset.messageId || 0),
          text: bubble.dataset.text || '',
          sender: bubble.dataset.sender || 'Message'
        };
        this.renderReplyPreview();
      }
    });
    bubble.addEventListener('pointercancel', () => {
      gestureActive = false;
      clearTimer();
    });
    bubble.addEventListener('pointerleave', clearTimer);
  }

  clearMessageSelection() {
    this.selectedMessageIds.clear();
    document.querySelectorAll('.live-chat-bubble.selected').forEach((bubble) => bubble.classList.remove('selected'));
    this.renderSelectionBar();
  }

  getSelectedBubbles() {
    return Array.from(document.querySelectorAll('.live-chat-bubble.selected'));
  }

  renderSelectionBar() {
    const bar = document.getElementById('liveSelectionBar');
    if (!bar) return;
    const count = this.selectedMessageIds.size;
    bar.classList.toggle('hidden', count === 0);
    if (!count) {
      bar.innerHTML = '';
      return;
    }
    bar.innerHTML = `
      <strong>${count} selected</strong>
      <div class="live-selection-actions">
        <button type="button" data-selection-action="delete"><i class="fas fa-trash"></i> Delete</button>
        <button type="button" data-selection-action="copy"><i class="fas fa-copy"></i> Copy</button>
        <button type="button" data-selection-action="forward"><i class="fas fa-share"></i> Forward</button>
        <button type="button" data-selection-action="clear"><i class="fas fa-times"></i></button>
      </div>
    `;
  }

  async handleSelectionAction(event) {
    const button = event.target instanceof Element ? event.target.closest('[data-selection-action]') : null;
    if (!button) return;
    const action = button.dataset.selectionAction;
    const bubbles = this.getSelectedBubbles();
    const texts = bubbles.map((bubble) => bubble.dataset.text || '').filter(Boolean);
    if (action === 'clear') {
      this.clearMessageSelection();
      return;
    }
    if (action === 'copy') {
      await navigator.clipboard?.writeText(texts.join('\n')).catch(() => {});
      showNotice('Copied', 'Selected messages copied to clipboard.', 'success');
    } else if (action === 'forward') {
      this.prepareForward(texts.join('\n'));
    } else if (action === 'delete') {
      for (const bubble of bubbles) {
        const messageId = Number(bubble.dataset.messageId || 0);
        if (messageId) await this.runMessageAction('delete', messageId).catch(() => {});
      }
      await this.reloadCurrentThread();
    }
    this.clearMessageSelection();
  }

  async runMessageAction(action, messageId, extra = {}) {
    await this.fetchJson(API.action, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action, message_id: messageId, ...extra })
    });
  }

  async reloadCurrentThread() {
    if (!this.selectedThread) return;
    this.lastMessageIdByThread[this.threadKey()] = 0;
    await this.loadMessages(true);
  }

  prepareForward(text) {
    document.getElementById('liveChatInput').value = text;
    showNotice('Forward', 'Select another contact or group, then press send.', 'info');
  }

  renderReplyPreview(label = '') {
    const preview = document.getElementById('liveReplyPreview');
    if (!preview) return;
    if (!this.replyToMessage && !this.editingMessage) {
      preview.classList.add('hidden');
      preview.innerHTML = '';
      return;
    }
    const title = label || (this.editingMessage ? 'Editing message' : `Replying to ${this.replyToMessage.sender}`);
    const text = this.editingMessage ? document.getElementById('liveChatInput')?.value || '' : this.replyToMessage.text;
    preview.classList.remove('hidden');
    preview.innerHTML = `<strong>${escapeHtml(title)}</strong><span>${escapeHtml(text || '')}</span><button type="button" id="liveCancelReply"><i class="fas fa-times"></i></button>`;
    document.getElementById('liveCancelReply')?.addEventListener('click', () => {
      this.replyToMessage = null;
      this.editingMessage = null;
      this.renderReplyPreview();
    });
  }

  async sendText() {
    const input = document.getElementById('liveChatInput');
    const text = (input?.value || '').trim();
    if (!text || !this.selectedThread || this.sending) return;
    if (this.editingMessage) {
      await this.runMessageAction('edit', this.editingMessage.id, { message_text: text });
      this.editingMessage = null;
      input.value = '';
      this.renderReplyPreview();
      await this.reloadCurrentThread();
      return;
    }
    await this.sendPayload({ message_text: text, message_kind: 'text' });
    input.value = '';
  }

  handleAttachmentMenu(event) {
    const button = event.target instanceof Element ? event.target.closest('button') : null;
    if (!button) return;
    if (button.dataset.poll === 'true') {
      this.openPollModal();
      document.getElementById('liveAttachmentPicker')?.classList.add('hidden');
      return;
    }
    if (button.dataset.label === 'Camera') {
      this.openCameraCapture();
      document.getElementById('liveAttachmentPicker')?.classList.add('hidden');
      return;
    }
    const input = document.getElementById('liveChatAttachmentInput');
    input.accept = button.dataset.accept || '';
    if (button.dataset.capture) input.setAttribute('capture', button.dataset.capture);
    else input.removeAttribute('capture');
    input.click();
    document.getElementById('liveAttachmentPicker')?.classList.add('hidden');
  }

  async openCameraCapture() {
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: this.cameraFacingMode }, audio: false });
      this.cameraStream = stream;
      document.getElementById('liveAttachmentModal')?.classList.remove('hidden', 'image-preview-mode');
      document.getElementById('liveAttachmentModal')?.classList.add('camera-capture-mode');
      document.getElementById('liveAttachmentTitle').textContent = 'Camera';
      const stage = document.getElementById('liveAttachmentStage');
      stage.innerHTML = `
        <div class="live-camera-frame">
          <video id="liveCameraPreview" autoplay playsinline></video>
          <button type="button" id="liveMirrorCameraBtn" class="live-camera-mirror-btn" title="Mirror front camera"><i class="fas fa-arrows-left-right"></i></button>
        </div>
        <div class="live-camera-actions">
          <button type="button" id="liveCameraFlip"><i class="fas fa-camera-rotate"></i> Front/Back</button>
          <label class="live-camera-zoom"><i class="fas fa-magnifying-glass-plus"></i><input id="liveCameraZoom" type="range" min="1" max="3" step="0.1" value="${this.cameraZoom}"></label>
          <button type="button" id="liveCapturePhoto"><i class="fas fa-camera"></i> Capture</button>
          <button type="button" id="liveCancelCamera">Cancel</button>
        </div>
      `;
      document.getElementById('liveCameraPreview').srcObject = stream;
      this.syncCameraMirrorPreview();
      this.applyCameraZoom(this.cameraZoom);
      document.getElementById('liveCameraFlip')?.addEventListener('click', () => this.switchAttachmentCamera());
      document.getElementById('liveMirrorCameraBtn')?.addEventListener('click', () => this.toggleCameraMirror());
      document.getElementById('liveCameraZoom')?.addEventListener('input', (event) => this.applyCameraZoom(Number(event.target.value || 1)));
      document.getElementById('liveCapturePhoto')?.addEventListener('click', () => this.captureCameraPhoto(), { once: true });
      document.getElementById('liveCancelCamera')?.addEventListener('click', () => this.closeAttachmentEditor(), { once: true });
    } catch (_error) {
      const input = document.getElementById('liveChatAttachmentInput');
      input.accept = 'image/*';
      input.setAttribute('capture', 'environment');
      input.click();
    }
  }

  captureCameraPhoto() {
    const video = document.getElementById('liveCameraPreview');
    if (!video) return;
    const canvas = document.createElement('canvas');
    canvas.width = video.videoWidth || 1280;
    canvas.height = video.videoHeight || 720;
    const ctx = canvas.getContext('2d');
    if (this.shouldMirrorCamera()) {
      ctx.translate(canvas.width, 0);
      ctx.scale(-1, 1);
    }
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
    canvas.toBlob((blob) => {
      if (!blob) return;
      const file = new File([blob], `camera-${Date.now()}.jpg`, { type: 'image/jpeg' });
      this.prepareAttachmentDraft(file, '', true);
    }, 'image/jpeg', 0.92);
  }

  async prepareAttachmentDraft(file, caption = '', fromCamera = false) {
    this.stopCameraStream();
    this.attachmentDraft = { file, caption, url: URL.createObjectURL(file), fromCamera, editedBlob: null };
    document.getElementById('liveAttachmentModal')?.classList.remove('hidden', 'camera-capture-mode');
    document.getElementById('liveAttachmentModal')?.classList.add('image-preview-mode');
    document.getElementById('liveAttachmentTitle').textContent = fromCamera ? 'Review Photo' : 'Attachment Preview';
    document.getElementById('liveAttachmentComposerPreview')?.classList.remove('hidden');
    document.getElementById('liveAttachmentComposeName').textContent = file.name || 'Attachment ready';
    document.getElementById('liveAttachmentCaption').value = caption || '';
    const stage = document.getElementById('liveAttachmentStage');
    if (file.type.startsWith('image/')) {
      stage.innerHTML = `<canvas id="liveAttachmentCanvas"></canvas>`;
      const img = new Image();
      img.onload = () => {
        const canvas = document.getElementById('liveAttachmentCanvas');
        const maxWidth = 720;
        const scale = Math.min(1, maxWidth / img.width);
        canvas.width = Math.round(img.width * scale);
        canvas.height = Math.round(img.height * scale);
        canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
        this.bindAttachmentCanvas(canvas);
      };
      img.src = this.attachmentDraft.url;
    } else if (file.type.startsWith('video/')) {
      stage.innerHTML = `<video controls src="${this.attachmentDraft.url}"></video>`;
    } else if (file.type.startsWith('audio/')) {
      stage.innerHTML = `<audio controls src="${this.attachmentDraft.url}"></audio>`;
    } else {
      stage.innerHTML = `<div class="live-file-preview"><i class="fas fa-file-alt"></i><strong>${escapeHtml(file.name)}</strong><small>${Math.round(file.size / 1024)} KB</small></div>`;
    }
  }

  bindAttachmentCanvas(canvas) {
    canvas.onpointerdown = (event) => {
      if (!this.drawingOnAttachment) return;
      this.lastPointer = { x: event.offsetX, y: event.offsetY };
      canvas.setPointerCapture(event.pointerId);
    };
    canvas.onpointermove = (event) => {
      if (!this.drawingOnAttachment || !this.lastPointer) return;
      const ctx = canvas.getContext('2d');
      ctx.strokeStyle = '#d71920';
      ctx.lineWidth = 4;
      ctx.lineCap = 'round';
      ctx.beginPath();
      ctx.moveTo(this.lastPointer.x, this.lastPointer.y);
      ctx.lineTo(event.offsetX, event.offsetY);
      ctx.stroke();
      this.lastPointer = { x: event.offsetX, y: event.offsetY };
    };
    canvas.onpointerup = () => {
      this.lastPointer = null;
    };
  }

  cropAttachmentCenter() {
    const canvas = document.getElementById('liveAttachmentCanvas');
    if (!canvas) return;
    const size = Math.min(canvas.width, canvas.height);
    const sx = Math.round((canvas.width - size) / 2);
    const sy = Math.round((canvas.height - size) / 2);
    const temp = document.createElement('canvas');
    temp.width = size;
    temp.height = size;
    temp.getContext('2d').drawImage(canvas, sx, sy, size, size, 0, 0, size, size);
    canvas.width = size;
    canvas.height = size;
    canvas.getContext('2d').drawImage(temp, 0, 0);
    this.bindAttachmentCanvas(canvas);
  }

  toggleAttachmentDoodle() {
    this.drawingOnAttachment = !this.drawingOnAttachment;
    document.getElementById('liveAttachmentDoodle')?.classList.toggle('active', this.drawingOnAttachment);
  }

  retakeAttachment() {
    this.closeAttachmentEditor();
    this.openCameraCapture();
  }

  clearAttachmentDraft() {
    if (this.attachmentDraft?.url) URL.revokeObjectURL(this.attachmentDraft.url);
    this.attachmentDraft = null;
    document.getElementById('liveAttachmentModal')?.classList.add('hidden');
    document.getElementById('liveAttachmentModal')?.classList.remove('camera-capture-mode', 'image-preview-mode');
    document.getElementById('liveAttachmentStage').innerHTML = '';
    document.getElementById('liveAttachmentComposerPreview')?.classList.add('hidden');
    document.getElementById('liveAttachmentCaption').value = '';
    const input = document.getElementById('liveChatAttachmentInput');
    if (input) input.value = '';
    this.stopCameraStream();
  }

  closeAttachmentEditor() {
    this.clearAttachmentDraft();
  }

  async sendAttachmentDraft() {
    if (!this.attachmentDraft) return;
    const caption = document.getElementById('liveAttachmentCaption')?.value?.trim() || '';
    let file = this.attachmentDraft.file;
    const canvas = document.getElementById('liveAttachmentCanvas');
    if (canvas) {
      const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.92));
      if (blob) file = new File([blob], file.name.replace(/\.[^.]+$/, '.jpg'), { type: 'image/jpeg' });
    }
    const draft = this.attachmentDraft;
    this.attachmentDraft = null;
    if (draft.url) URL.revokeObjectURL(draft.url);
    document.getElementById('liveAttachmentModal')?.classList.add('hidden');
    document.getElementById('liveAttachmentModal')?.classList.remove('camera-capture-mode', 'image-preview-mode');
    document.getElementById('liveAttachmentComposerPreview')?.classList.add('hidden');
    await this.sendPayload({ message_kind: 'attachment', message_text: caption, file });
  }

  stopCameraStream() {
    this.cameraStream?.getTracks().forEach((track) => track.stop());
    this.cameraStream = null;
  }

  async switchAttachmentCamera() {
    this.cameraFacingMode = this.cameraFacingMode === 'environment' ? 'user' : 'environment';
    this.stopCameraStream();
    await this.openCameraCapture();
    this.syncCameraMirrorPreview();
  }

  shouldMirrorCamera() {
    return this.cameraFacingMode === 'user' && this.mirrorFrontCamera;
  }

  syncCameraMirrorPreview() {
    const video = document.getElementById('liveCameraPreview');
    const button = document.getElementById('liveMirrorCameraBtn');
    const mirrored = this.shouldMirrorCamera();
    if (video) video.classList.toggle('mirrored', mirrored);
    if (button) {
      button.classList.toggle('active', mirrored);
      button.classList.toggle('hidden', this.cameraFacingMode !== 'user');
    }
  }

  syncCallCameraPreview() {
    const localVideo = document.getElementById('liveLocalVideo');
    if (localVideo) localVideo.classList.toggle('mirrored', this.shouldMirrorCamera());
  }

  toggleCameraMirror() {
    this.mirrorFrontCamera = !this.mirrorFrontCamera;
    this.syncCameraMirrorPreview();
    this.syncCallCameraPreview();
  }

  openPollModal() {
    if (!this.selectedThread) {
      showNotice('Poll', 'Select a contact or group before creating a poll.');
      return;
    }
    document.getElementById('livePollQuestion').value = '';
    document.getElementById('livePollPriority').value = 'normal';
    document.getElementById('livePollTag').value = '';
    document.getElementById('livePollClosesAt').value = '';
    document.getElementById('livePollMulti').checked = false;
    document.getElementById('livePollOptions').innerHTML = `
      <input type="text" placeholder="Option 1">
      <input type="text" placeholder="Option 2">
    `;
    document.getElementById('livePollModal')?.classList.remove('hidden');
    document.getElementById('livePollQuestion')?.focus();
  }

  closePollModal() {
    document.getElementById('livePollModal')?.classList.add('hidden');
  }

  addPollOption() {
    const options = document.getElementById('livePollOptions');
    if (!options) return;
    const count = options.querySelectorAll('input').length + 1;
    const input = document.createElement('input');
    input.type = 'text';
    input.placeholder = `Option ${count}`;
    options.appendChild(input);
    input.focus();
  }

  async createPoll() {
    if (!this.selectedThread) return;
    const question = document.getElementById('livePollQuestion').value.trim();
    const options = Array.from(document.querySelectorAll('#livePollOptions input'))
      .map((input) => input.value.trim())
      .filter(Boolean);
    const payload = {
      action: 'create',
      recipient_type: this.selectedThread.type,
      recipient_id: this.selectedThread.id,
      question,
      options,
      allow_multiple: document.getElementById('livePollMulti').checked,
      priority: document.getElementById('livePollPriority').value,
      tag: document.getElementById('livePollTag').value.trim(),
      closes_at: document.getElementById('livePollClosesAt').value
    };
    try {
      await this.fetchJson(API.poll, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      this.closePollModal();
      await this.loadMessages(false);
    } catch (error) {
      showNotice('Poll', error.message || 'Unable to create poll.');
    }
  }

  async sendAttachment(file, kind = 'attachment', text = '') {
    if (!file || !this.selectedThread || this.sending) return;
    if (kind === 'attachment') {
      await this.prepareAttachmentDraft(file, text);
      return;
    }
    await this.sendPayload({ message_kind: kind, message_text: text, file });
    const input = document.getElementById('liveChatAttachmentInput');
    if (input) input.value = '';
  }

  async sendPayload({ message_text = '', message_kind = 'text', file = null } = {}) {
    if (!this.selectedThread || this.sending) return;
    this.sending = true;
    const form = new FormData();
    form.append('recipient_type', this.selectedThread.type);
    form.append('recipient_id', this.selectedThread.id);
    form.append('message_text', message_text);
    form.append('message_kind', message_kind);
    if (this.replyToMessage?.id) form.append('reply_to_message_id', String(this.replyToMessage.id));
    if (file) form.append('file', file, file.name || `${message_kind}.webm`);
    try {
      await this.fetchJson(API.send, { method: 'POST', body: form });
      this.replyToMessage = null;
      this.renderReplyPreview();
      await this.loadMessages(false);
    } catch (error) {
      showNotice('Live Chat', error.message || 'Unable to send chat message.');
    } finally {
      this.sending = false;
    }
  }

  async toggleVoiceRecording() {
    const button = document.getElementById('liveVoiceBtn');
    if (this.recorder?.state === 'recording') {
      this.recorder.stop();
      button?.classList.remove('recording');
      return;
    }
    if (this.voiceDraft) {
      this.renderVoiceDraft();
      return;
    }
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      this.recordingStream = stream;
      this.recordingStartTime = Date.now();
      this.recordedChunks = [];
      this.recorder = new MediaRecorder(stream);
      this.recorder.ondataavailable = (event) => {
        if (event.data?.size) this.recordedChunks.push(event.data);
      };
      this.recorder.onstop = async () => {
        stream.getTracks().forEach((track) => track.stop());
        this.recordingStream = null;
        this.stopRecordingTimer();
        const blob = new Blob(this.recordedChunks, { type: 'audio/webm' });
        const file = new File([blob], `voice-note-${Date.now()}.webm`, { type: 'audio/webm' });
        this.voiceDraft = {
          file,
          url: URL.createObjectURL(blob),
          duration: Math.max(1, Math.round((Date.now() - this.recordingStartTime) / 1000))
        };
        this.renderVoiceDraft();
      };
      this.recorder.start();
      button?.classList.add('recording');
      this.startRecordingTimer();
    } catch (_error) {
      showNotice('Voice Note', 'Microphone access is required to record voice notes.');
    }
  }

  startRecordingTimer() {
    const draft = document.getElementById('liveVoiceDraft');
    draft?.classList.remove('hidden');
    this.stopRecordingTimer();
    const render = () => {
      const elapsed = Math.max(0, Math.floor((Date.now() - this.recordingStartTime) / 1000));
      if (draft) {
        draft.innerHTML = `
          <div class="live-recording-status"><span></span><strong>Recording ${this.formatDuration(elapsed)}</strong></div>
          <button type="button" id="liveStopRecording">Stop</button>
          <button type="button" id="liveCancelRecording">Cancel</button>
        `;
        document.getElementById('liveStopRecording')?.addEventListener('click', () => this.toggleVoiceRecording(), { once: true });
        document.getElementById('liveCancelRecording')?.addEventListener('click', () => this.cancelRecording(), { once: true });
      }
    };
    render();
    this.recordingTimer = setInterval(render, 1000);
  }

  stopRecordingTimer() {
    if (this.recordingTimer) clearInterval(this.recordingTimer);
    this.recordingTimer = null;
  }

  cancelRecording() {
    if (this.recorder?.state === 'recording') {
      this.recorder.onstop = null;
      this.recorder.stop();
    }
    this.recordingStream?.getTracks().forEach((track) => track.stop());
    this.recordingStream = null;
    this.recorder = null;
    this.recordedChunks = [];
    this.stopRecordingTimer();
    document.getElementById('liveVoiceBtn')?.classList.remove('recording');
    const draft = document.getElementById('liveVoiceDraft');
    if (draft) {
      draft.classList.add('hidden');
      draft.innerHTML = '';
    }
  }

  renderVoiceDraft() {
    const draft = document.getElementById('liveVoiceDraft');
    if (!draft || !this.voiceDraft) return;
    draft.classList.remove('hidden');
    draft.innerHTML = `
      <div class="live-voice-preview">
        <strong>Voice note ${this.formatDuration(this.voiceDraft.duration)}</strong>
        <audio controls src="${this.voiceDraft.url}"></audio>
      </div>
      <div class="live-voice-actions">
        <button type="button" id="liveSendVoiceDraft"><i class="fas fa-paper-plane"></i> Send</button>
        <button type="button" id="liveRedoVoiceDraft"><i class="fas fa-redo"></i> Re-record</button>
        <button type="button" id="liveDeleteVoiceDraft"><i class="fas fa-trash"></i> Delete</button>
      </div>
    `;
    document.getElementById('liveSendVoiceDraft')?.addEventListener('click', () => this.sendVoiceDraft(), { once: true });
    document.getElementById('liveRedoVoiceDraft')?.addEventListener('click', () => this.redoVoiceDraft(), { once: true });
    document.getElementById('liveDeleteVoiceDraft')?.addEventListener('click', () => this.clearVoiceDraft(), { once: true });
  }

  async sendVoiceDraft() {
    if (!this.voiceDraft) return;
    await this.sendAttachment(this.voiceDraft.file, 'voice', 'Voice note');
    this.clearVoiceDraft();
  }

  redoVoiceDraft() {
    this.clearVoiceDraft();
    this.toggleVoiceRecording();
  }

  clearVoiceDraft() {
    if (this.voiceDraft?.url) URL.revokeObjectURL(this.voiceDraft.url);
    this.voiceDraft = null;
    const draft = document.getElementById('liveVoiceDraft');
    if (draft) {
      draft.classList.add('hidden');
      draft.innerHTML = '';
    }
  }

  formatDuration(seconds) {
    const total = Math.max(0, Number(seconds || 0));
    const mins = Math.floor(total / 60).toString().padStart(2, '0');
    const secs = Math.floor(total % 60).toString().padStart(2, '0');
    return `${mins}:${secs}`;
  }

  openGroupModal() {
    document.getElementById('liveGroupModal')?.classList.remove('hidden');
    document.getElementById('liveGroupMembers').innerHTML = this.users.map((user) => `
      <label><input type="checkbox" value="${escapeHtml(user.userId)}"> <span>${escapeHtml(user.userName)} <small>${escapeHtml(user.userRoleLabel || this.formatRoleLabel(user.userRole))}</small></span></label>
    `).join('');
  }

  closeGroupModal() {
    document.getElementById('liveGroupModal')?.classList.add('hidden');
  }

  async createGroup() {
    const name = document.getElementById('liveGroupName').value.trim();
    const memberIds = Array.from(document.querySelectorAll('#liveGroupMembers input:checked')).map((input) => input.value);
    try {
      await this.fetchJson(API.groupCreate, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ group_name: name, member_ids: memberIds })
      });
      document.getElementById('liveGroupName').value = '';
      this.closeGroupModal();
      await this.loadUsers();
    } catch (error) {
      showNotice('Group Chat', error.message || 'Unable to create group.');
    }
  }

  async openGroupManageModal() {
    if (!this.selectedThread || this.selectedThread.type !== 'group') return;
    try {
      const data = await this.fetchJson(`${API.groupManage}?action=members&group_id=${encodeURIComponent(this.selectedThread.id)}`);
      const selected = new Set((data.members || []).map((member) => member.userId));
      const canEdit = Boolean(data.group?.isAdmin);
      document.getElementById('liveManageGroupTitle').textContent = `${data.group?.groupName || this.selectedThread.name} Members`;
      const manageList = document.getElementById('liveManageGroupMembers');
      if (manageList) {
        if (canEdit) {
          const availableUsers = data.availableUsers?.length ? data.availableUsers : this.users;
          manageList.innerHTML = availableUsers.map((user) => {
            const checked = selected.has(user.userId) ? 'checked' : '';
            const disabled = user.userId === this.currentUserId ? 'disabled' : '';
            const label = user.userRoleLabel || this.formatRoleLabel(user.userRole);
            const creator = user.userId === this.currentUserId ? '<em>Creator</em>' : '';
            return `<label><input type="checkbox" value="${escapeHtml(user.userId)}" ${checked} ${disabled}> <span>${escapeHtml(user.userName)} <small>${escapeHtml(label)}</small>${creator}</span></label>`;
          }).join('');
        } else {
          manageList.innerHTML = (data.members || []).map((member) => {
            const label = member.userRoleLabel || this.formatRoleLabel(member.userRole);
            const marker = member.isAdmin ? '<em>Creator</em>' : (member.userId === this.currentUserId ? '<em>You</em>' : '');
            return `<div class="live-group-member-row"><span>${escapeHtml(member.userName)} <small>${escapeHtml(label)}</small>${marker}</span></div>`;
          }).join('');
        }
      }
      document.getElementById('liveManageGroupSave')?.classList.toggle('hidden', !canEdit);
      document.getElementById('liveLeaveGroupBtn')?.classList.toggle('hidden', canEdit);
      document.getElementById('liveGroupManageModal')?.classList.remove('hidden');
    } catch (error) {
      showNotice('Group Members', error.message || 'Unable to load group members.');
    }
  }

  closeGroupManageModal() {
    document.getElementById('liveGroupManageModal')?.classList.add('hidden');
  }

  async saveGroupMembers() {
    if (!this.selectedThread || this.selectedThread.type !== 'group') return;
    const memberIds = Array.from(document.querySelectorAll('#liveManageGroupMembers input:checked')).map((input) => input.value);
    try {
      await this.fetchJson(API.groupManage, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'update', group_id: this.selectedThread.id, member_ids: memberIds })
      });
      this.closeGroupManageModal();
      await this.loadUsers();
    } catch (error) {
      showNotice('Group Members', error.message || 'Unable to save group members.');
    }
  }

  async leaveCurrentGroup() {
    if (!this.selectedThread || this.selectedThread.type !== 'group') return;
    const groupId = this.selectedThread.id;
    try {
      await this.fetchJson(API.groupManage, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'leave', group_id: groupId })
      });
      this.closeGroupManageModal();
      if (this.selectedThread?.id === groupId) {
        this.selectedThread = null;
        document.getElementById('liveChatPeer').innerHTML = '<div><span>Select a staff member or group</span></div>';
        document.getElementById('liveChatMessages').innerHTML = '<div class="live-chat-empty">Choose a staff member or group to start a live conversation.</div>';
        ['liveChatInput','liveSendBtn','liveVoiceBtn','liveAttachBtn'].forEach((elementId) => {
          const element = document.getElementById(elementId);
          if (element) element.disabled = true;
        });
      }
      await this.loadUsers();
      showNotice('Group Members', 'You left the group.', 'info');
    } catch (error) {
      showNotice('Group Members', error.message || 'Unable to leave group.');
    }
  }

  async startCall(callType = 'audio') {
    if (!this.selectedThread) return;
    if (this.selectedThread.type === 'group') {
      await this.startGroupCall(callType);
      return;
    }
    try {
      const response = await this.postCall({ action: 'start', callee_id: this.selectedThread.id, call_type: callType });
      this.activeCall = response.call;
      this.lastSignalId = 0;
      this.pendingIceCandidates = [];
      await this.preparePeerConnection(callType);
      const offer = await this.peerConnection.createOffer();
      await this.peerConnection.setLocalDescription(offer);
      await this.sendSignal('offer', offer);
      this.showCallModal(`Calling ${this.selectedThread.name}`, 'Ringing...', callType, false);
      this.playOutgoingRing();
      this.startCallTimeout('outgoing');
      this.startSignalPolling();
    } catch (error) {
      showNotice('Call Failed', error.message || 'Unable to start call.');
      this.cleanupCall();
    }
  }

  async startGroupCall(callType = 'audio') {
    try {
      const data = await this.fetchJson(`${API.groupManage}?action=members&group_id=${encodeURIComponent(this.selectedThread.id)}`);
      const peers = (data.members || []).filter((member) => member.userId !== this.currentUserId);
      if (!peers.length) {
        showNotice('Group Call', 'There are no other members to call.');
        return;
      }
      this.groupCallPeerIds = peers.map((member) => member.userId);
      this.groupCallSessions.clear();
      this.activeCall = {
        callId: `group_${Date.now()}`,
        callType,
        status: 'ringing',
        isGroupCall: true,
        groupId: this.selectedThread.id,
        groupName: this.selectedThread.name
      };
      this.lastSignalId = 0;
      this.pendingIceCandidates = [];
      this.configureCallMediaElements();
      const videoConstraint = callType === 'video'
        ? (this.selectedVideoDeviceId ? { deviceId: { exact: this.selectedVideoDeviceId } } : { facingMode: { ideal: this.cameraFacingMode } })
        : false;
      this.localStream = await this.getCallMediaStream(callType, videoConstraint);
      await this.tuneCallAudioTrack(this.localStream);
      const localVideo = document.getElementById('liveLocalVideo');
      if (localVideo) localVideo.srcObject = this.localStream;
      this.showCallModal(`Calling ${this.selectedThread.name}`, `Ringing ${peers.length} members...`, callType, false);
      this.renderGroupRemoteGrid();
      this.playOutgoingRing();
      this.startCallTimeout('outgoing');
      await Promise.all(peers.map((peer) => this.startGroupCallToPeer(peer, callType)));
      this.startSignalPolling();
      showNotice('Group Call', `Calling ${peers.length} group member${peers.length === 1 ? '' : 's'}.`, 'info');
    } catch (error) {
      showNotice('Group Call', error.message || 'Unable to start group call.');
      this.cleanupCall();
    }
  }

  async startGroupCallToPeer(peer, callType) {
    const response = await this.postCall({ action: 'start', callee_id: peer.userId, call_type: callType });
    const call = response.call;
    const session = await this.createGroupPeerSession(call, peer);
    this.groupCallSessions.set(call.callId, session);
    const offer = await session.peerConnection.createOffer();
    await session.peerConnection.setLocalDescription(offer);
    await this.sendSignalForCall(call, 'offer', offer);
    this.renderGroupRemoteGrid();
  }

  async startCallToPeer(peer, callType, title) {
    const response = await this.postCall({ action: 'start', callee_id: peer.userId, call_type: callType });
    this.activeCall = response.call;
    this.lastSignalId = 0;
    this.pendingIceCandidates = [];
    await this.preparePeerConnection(callType);
    const offer = await this.peerConnection.createOffer();
    await this.peerConnection.setLocalDescription(offer);
    await this.sendSignal('offer', offer);
    this.showCallModal(`Calling ${title || peer.userName}`, 'Ringing...', callType, false);
    this.playOutgoingRing();
    this.startCallTimeout('outgoing');
    this.startSignalPolling();
  }

  async pollCalls() {
    try {
      const data = await this.postCall({ action: 'poll' }, 'GET');
      if (this.activeCall) {
        if (this.activeCall.isGroupCall) return;
        const currentCall = (data.calls || []).find((call) => call.callId === this.activeCall.callId);
        if (currentCall) {
          this.activeCall = { ...this.activeCall, ...currentCall };
          if (currentCall.status === 'accepted') {
            this.stopIncomingRing();
            this.stopOutgoingRing();
            this.clearCallTimeout();
            const status = document.getElementById('liveCallStatus');
            if (status && ['Ringing...', 'audio call', 'video call'].includes(status.textContent || '')) {
              status.textContent = 'Connecting...';
            }
          }
        } else if (this.activeCall.status === 'ringing') {
          showNotice('Call Ended', 'The call was not answered in time or was declined.', 'info');
          this.cleanupCall();
        }
        return;
      }
      const incoming = (data.calls || []).find((call) => call.calleeId === this.currentUserId && call.status === 'ringing');
      if (incoming) {
        this.activeCall = incoming;
        this.lastSignalId = 0;
        this.playIncomingRing();
        this.showIncomingCallNotification(incoming);
        this.showCallModal(`${incoming.callerName} is calling`, `${incoming.callType} call`, incoming.callType, true);
        this.startCallTimeout('incoming');
      }
    } catch (_error) {}
  }

  showCallModal(title, status, callType = 'audio', incoming = false) {
    const modal = document.getElementById('liveCallModal');
    document.getElementById('liveChatDock')?.classList.add('call-active');
    modal?.classList.remove('hidden', 'audio-only');
    if (callType === 'audio') modal?.classList.add('audio-only');
    document.getElementById('liveCallTitle').textContent = title;
    document.getElementById('liveCallStatus').textContent = status;
    document.getElementById('liveAcceptCallBtn')?.classList.toggle('hidden', !incoming);
    document.getElementById('liveRejectCallBtn')?.classList.toggle('hidden', !incoming);
    document.getElementById('liveEndCallBtn')?.classList.toggle('hidden', incoming && this.activeCall?.status === 'ringing');
    document.getElementById('liveRequestVideoBtn')?.classList.toggle('hidden', callType !== 'audio' || incoming);
    this.syncSpeakerControls();
  }

  async acceptIncomingCall() {
    if (!this.activeCall) return;
    const acceptButton = document.getElementById('liveAcceptCallBtn');
    const rejectButton = document.getElementById('liveRejectCallBtn');
    const status = document.getElementById('liveCallStatus');
    try {
      this.stopIncomingRing();
      this.stopOutgoingRing();
      this.clearCallTimeout();
      if (status) status.textContent = 'Connecting...';
      acceptButton?.classList.add('hidden');
      rejectButton?.classList.add('hidden');
      document.getElementById('liveEndCallBtn')?.classList.remove('hidden');
      acceptButton && (acceptButton.disabled = true);
      rejectButton && (rejectButton.disabled = true);
      const updatePromise = this.postCall({ action: 'update', call_id: this.activeCall.callId, status: 'accepted' }).catch(() => {});
      this.activeCall.status = 'accepted';
      const acceptSignalPromise = this.sendSignal('call_accept', { acceptedAt: new Date().toISOString() }).catch(() => {});
      await this.preparePeerConnection(this.activeCall.callType);
      this.startSignalPolling();
      await Promise.allSettled([updatePromise, acceptSignalPromise]);
    } catch (error) {
      await this.sendSignal('hangup', { reason: 'accept_failed' }).catch(() => {});
      showNotice('Call Failed', error.message || 'Unable to accept call.');
      this.cleanupCall();
    } finally {
      acceptButton && (acceptButton.disabled = false);
      rejectButton && (rejectButton.disabled = false);
    }
  }

  async rejectIncomingCall() {
    this.stopIncomingRing();
    this.stopOutgoingRing();
    this.clearCallTimeout();
    const call = this.activeCall;
    const signalPromise = call ? this.sendSignal('hangup', { reason: 'rejected' }).catch(() => {}) : Promise.resolve();
    const updatePromise = call ? this.postCall({ action: 'update', call_id: call.callId, status: 'rejected' }).catch(() => {}) : Promise.resolve();
    this.cleanupCall();
    await Promise.allSettled([signalPromise, updatePromise]);
  }

  async endCall() {
    this.stopIncomingRing();
    this.stopOutgoingRing();
    this.clearCallTimeout();
    if (this.activeCall?.isGroupCall) {
      const sessions = Array.from(this.groupCallSessions.values());
      const signalPromises = sessions.map((session) => this.sendSignalForCall(session.call, 'hangup', { reason: 'ended' }).catch(() => {}));
      const updatePromises = sessions.map((session) => this.postCall({ action: 'update', call_id: session.call.callId, status: 'ended' }).catch(() => {}));
      this.cleanupCall();
      await Promise.allSettled([...signalPromises, ...updatePromises]);
      return;
    }
    const call = this.activeCall;
    const signalPromise = call ? this.sendSignal('hangup', { reason: 'ended' }).catch(() => {}) : Promise.resolve();
    const updatePromise = call ? this.postCall({ action: 'update', call_id: call.callId, status: 'ended' }).catch(() => {}) : Promise.resolve();
    this.cleanupCall();
    await Promise.allSettled([signalPromise, updatePromise]);
  }

  playIncomingRing() {
    const muted = localStorage.getItem('liveChatMuteIncomingCalls') === '1';
    if (muted || !this.callSettings.incomingSoundEnabled) return;
    this.incomingRing = this.playRepeatingCallSound(
      this.incomingRing,
      this.callSettings.incomingSoundPath || 'audio/notification.mp3',
      this.callSettings.incomingVolume,
      this.callSettings.incomingRepeatCount
    );
  }

  playOutgoingRing() {
    if (!this.callSettings.outgoingSoundEnabled) return;
    this.outgoingRing = this.playRepeatingCallSound(
      this.outgoingRing,
      this.callSettings.outgoingSoundPath || this.callSettings.incomingSoundPath || 'audio/notification.mp3',
      this.callSettings.outgoingVolume,
      this.callSettings.outgoingRepeatCount
    );
  }

  playRepeatingCallSound(existingAudio, path, volumePercent = 80, repeatCount = 0) {
    try {
      const source = new URL(path || 'audio/notification.mp3', APP_ROOT).href;
      const audio = existingAudio?.src === source ? existingAudio : new Audio(source);
      audio.loop = Number(repeatCount || 0) === 0;
      audio.volume = Math.max(0, Math.min(1, Number(volumePercent || 0) / 100));
      audio.dataset.playCount = '0';
      audio.onended = null;
      if (!audio.loop) {
        audio.onended = () => {
          const played = Number(audio.dataset.playCount || 1);
          if (played >= Number(repeatCount || 1)) return;
          audio.dataset.playCount = String(played + 1);
          audio.currentTime = 0;
          audio.play().catch(() => {});
        };
      }
      audio.currentTime = 0;
      audio.dataset.playCount = '1';
      audio.play().catch(() => {});
      return audio;
    } catch (_error) {
      return existingAudio || null;
    }
  }

  playMessageNotification(message = {}) {
    if (this.activeCall?.status === 'accepted') return;
    if (this.messageSettings.messageSoundEnabled) {
      this.playRepeatingCallSound(
        null,
        this.messageSettings.messageSoundPath || 'audio/notification.mp3',
        this.messageSettings.messageVolume,
        this.messageSettings.messageRepeatCount || 1
      );
    }
    if (this.messageSettings.desktopAlertsEnabled) {
      this.showMessageNotification(message);
    }
  }

  showMessageNotification(message = {}) {
    const notice = document.getElementById('liveMessageNotice');
    if (!notice) return;
    const sender = message.senderName || 'PensionsGo Live Chat';
    const preview = message.text || message.fileName || 'New live chat message received.';
    notice.innerHTML = `
      <div class="live-message-notice-card" role="dialog" aria-modal="false" aria-label="New live chat message">
        <span><i class="fas fa-comments" aria-hidden="true"></i></span>
        <span class="live-message-notice-copy">
          <em>New message</em>
          <strong>${escapeHtml(sender)}</strong>
          <small>${escapeHtml(preview)}</small>
        </span>
        <span class="live-message-notice-actions">
          <button type="button" data-notice-action="open">Open</button>
          <button type="button" data-notice-action="dismiss" aria-label="Dismiss"><i class="fas fa-times" aria-hidden="true"></i></button>
        </span>
      </div>
    `;
    notice.classList.remove('hidden');
    notice.querySelector('[data-notice-action="open"]')?.addEventListener('click', () => {
      document.getElementById('liveChatDock')?.classList.remove('collapsed');
      notice.classList.add('hidden');
    }, { once: true });
    notice.querySelector('[data-notice-action="dismiss"]')?.addEventListener('click', () => {
      notice.classList.add('hidden');
    }, { once: true });
    window.clearTimeout(this.messageNoticeTimer);
    this.messageNoticeTimer = window.setTimeout(() => {
      notice.classList.add('hidden');
    }, 5600);
  }

  async showIncomingCallNotification(call) {
    if (!this.callSettings.desktopAlertsEnabled || !('Notification' in window)) return;
    try {
      if (Notification.permission === 'default') {
        await Notification.requestPermission();
      }
      if (Notification.permission !== 'granted') return;
      const notification = new Notification('Incoming PensionsGo call', {
        body: `${call.callerName || 'Staff'} is starting a ${call.callType || 'voice'} call.`,
        icon: new URL('images/icon-192.png', APP_ROOT).href,
        tag: `live-call-${call.callId || 'incoming'}`,
        requireInteraction: true
      });
      notification.onclick = () => {
        window.focus();
        document.getElementById('liveChatDock')?.classList.remove('collapsed');
        notification.close();
      };
    } catch (_error) {}
  }

  toggleIncomingCallSound() {
    const muted = localStorage.getItem('liveChatMuteIncomingCalls') === '1';
    localStorage.setItem('liveChatMuteIncomingCalls', muted ? '0' : '1');
    const button = document.getElementById('liveSoundToggleBtn');
    if (button) {
      button.innerHTML = muted ? '<i class="fas fa-volume-high" aria-hidden="true"></i>' : '<i class="fas fa-volume-xmark" aria-hidden="true"></i>';
      button.classList.toggle('muted', !muted);
    }
    if (!muted) this.stopIncomingRing();
  }

  syncIncomingSoundButton() {
    const muted = localStorage.getItem('liveChatMuteIncomingCalls') === '1';
    const button = document.getElementById('liveSoundToggleBtn');
    if (button) {
      button.innerHTML = muted ? '<i class="fas fa-volume-xmark" aria-hidden="true"></i>' : '<i class="fas fa-volume-high" aria-hidden="true"></i>';
      button.classList.toggle('muted', muted);
    }
  }

  stopIncomingRing() {
    if (!this.incomingRing) return;
    this.incomingRing.onended = null;
    this.incomingRing.loop = false;
    this.incomingRing.pause();
    this.incomingRing.currentTime = 0;
    this.incomingRing = null;
  }

  stopOutgoingRing() {
    if (!this.outgoingRing) return;
    this.outgoingRing.onended = null;
    this.outgoingRing.loop = false;
    this.outgoingRing.pause();
    this.outgoingRing.currentTime = 0;
    this.outgoingRing = null;
  }

  startCallTimeout(direction = 'outgoing') {
    this.clearCallTimeout();
    const seconds = Math.max(10, Math.min(300, Number(this.callSettings.ringingTimeoutSeconds || 45)));
    this.callTimeoutTimer = window.setTimeout(() => this.handleUnansweredCallTimeout(direction), seconds * 1000);
  }

  clearCallTimeout() {
    if (this.callTimeoutTimer) window.clearTimeout(this.callTimeoutTimer);
    this.callTimeoutTimer = null;
  }

  async handleUnansweredCallTimeout(direction = 'outgoing') {
    if (!this.activeCall || this.activeCall.status !== 'ringing') return;
    this.stopIncomingRing();
    this.stopOutgoingRing();
    if (this.activeCall.isGroupCall) {
      const sessions = Array.from(this.groupCallSessions.values());
      await Promise.allSettled(sessions.flatMap((session) => [
        this.sendSignalForCall(session.call, 'hangup', { reason: 'unanswered' }).catch(() => {}),
        this.postCall({ action: 'update', call_id: session.call.callId, status: 'missed' }).catch(() => {})
      ]));
      showNotice('Group Call', 'The group call was not answered in time.', 'info');
      this.cleanupCall();
      return;
    }
    await this.sendSignal('hangup', { reason: 'unanswered' }).catch(() => {});
    await this.postCall({ action: 'update', call_id: this.activeCall.callId, status: 'missed' }).catch(() => {});
    showNotice(direction === 'incoming' ? 'Missed Call' : 'Call Ended', 'The call was not answered in time.', 'info');
    this.cleanupCall();
  }

  getCallAudioConstraints() {
    return {
      echoCancellation: { ideal: true },
      noiseSuppression: { ideal: true },
      autoGainControl: { ideal: true },
      channelCount: { ideal: 1 },
      latency: { ideal: 0.02 },
      sampleRate: { ideal: 48000 },
      sampleSize: { ideal: 16 }
    };
  }

  getCallVideoConstraints(videoConstraint = true) {
    const base = videoConstraint && typeof videoConstraint === 'object' ? { ...videoConstraint } : {};
    return {
      ...base,
      width: base.width || { ideal: 960, max: 1280 },
      height: base.height || { ideal: 540, max: 720 },
      frameRate: base.frameRate || { ideal: 24, max: 30 }
    };
  }

  isHandheldCallDevice() {
    return window.matchMedia?.('(pointer: coarse), (max-width: 768px)')?.matches || false;
  }

  async getCallMediaStream(callType, videoConstraint = false) {
    const constraints = {
      audio: this.getCallAudioConstraints(),
      video: callType === 'video' ? this.getCallVideoConstraints(videoConstraint) : false
    };
    try {
      return await navigator.mediaDevices.getUserMedia(constraints);
    } catch (error) {
      if (!constraints.video) {
        return navigator.mediaDevices.getUserMedia({ audio: this.getCallAudioConstraints(), video: false });
      }
      return navigator.mediaDevices.getUserMedia({
        audio: this.getCallAudioConstraints(),
        video: this.getCallVideoConstraints(true)
      });
    }
  }

  async tuneCallAudioTrack(stream) {
    const audioTrack = stream?.getAudioTracks?.()[0];
    if (!audioTrack) return;
    audioTrack.contentHint = 'speech';
    if (audioTrack.applyConstraints) {
      await audioTrack.applyConstraints(this.getCallAudioConstraints()).catch(() => {});
    }
  }

  async tunePeerConnectionSenders(pc) {
    if (!pc?.getSenders) return;
    for (const sender of pc.getSenders()) {
      if (!sender.track || !sender.getParameters || !sender.setParameters) continue;
      const parameters = sender.getParameters();
      parameters.encodings = parameters.encodings?.length ? parameters.encodings : [{}];
      if (sender.track.kind === 'audio') {
        parameters.encodings[0].priority = 'high';
        parameters.encodings[0].networkPriority = 'high';
        parameters.encodings[0].maxBitrate = 32000;
      }
      if (sender.track.kind === 'video') {
        parameters.encodings[0].priority = 'medium';
        parameters.encodings[0].networkPriority = 'medium';
        parameters.encodings[0].maxBitrate = this.isHandheldCallDevice() ? 450000 : 800000;
      }
      await sender.setParameters(parameters).catch(() => {});
    }
  }

  async createGroupPeerSession(call, peer) {
    const remoteStream = new MediaStream();
    const pc = new RTCPeerConnection({ iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] });
    const session = {
      call,
      peer,
      peerConnection: pc,
      remoteStream,
      pendingIceCandidates: [],
      lastSignalId: 0,
      status: 'ringing'
    };
    this.localStream?.getTracks().forEach((track) => pc.addTrack(track, this.localStream));
    await this.tunePeerConnectionSenders(pc);
    pc.ontrack = (event) => {
      const tracks = event.streams[0]?.getTracks?.()?.length ? event.streams[0].getTracks() : [event.track];
      tracks.forEach((track) => {
        const sameKindTracks = remoteStream.getTracks().filter((existing) => existing.kind === track.kind);
        sameKindTracks.forEach((existing) => {
          if (existing.id !== track.id) remoteStream.removeTrack(existing);
        });
        if (!remoteStream.getTracks().some((existing) => existing.id === track.id)) {
          remoteStream.addTrack(track);
        }
      });
      this.renderGroupRemoteGrid();
    };
    pc.onicecandidate = (event) => {
      if (event.candidate) {
        this.sendSignalForCall(call, 'ice', event.candidate.toJSON ? event.candidate.toJSON() : event.candidate).catch(() => {});
      }
    };
    pc.onconnectionstatechange = () => {
      session.status = pc.connectionState;
      this.updateGroupCallStatus();
      if (['failed', 'closed'].includes(pc.connectionState)) {
        setTimeout(() => this.closeGroupPeerSession(call.callId), 1200);
      }
    };
    return session;
  }

  renderGroupRemoteGrid() {
    const grid = document.getElementById('liveGroupRemoteGrid');
    const singleRemote = document.getElementById('liveRemoteVideo');
    if (!grid || !this.activeCall?.isGroupCall) {
      grid?.classList.add('hidden');
      singleRemote?.classList.remove('hidden');
      return;
    }
    singleRemote?.classList.add('hidden');
    grid.classList.remove('hidden');
    const existingTiles = new Set();
    this.groupCallSessions.forEach((session, callId) => {
      existingTiles.add(callId);
      let tile = grid.querySelector(`[data-group-call-id="${CSS.escape(callId)}"]`);
      if (!tile) {
        tile = document.createElement('article');
        tile.className = 'live-group-remote-tile';
        tile.dataset.groupCallId = callId;
        tile.innerHTML = `
          <video autoplay playsinline></video>
          <strong></strong>
          <small></small>
        `;
        grid.appendChild(tile);
      }
      const video = tile.querySelector('video');
      if (video && video.srcObject !== session.remoteStream) {
        video.srcObject = session.remoteStream;
        video.autoplay = true;
        video.playsInline = true;
        video.muted = !this.callSpeakerEnabled;
        video.volume = Math.max(0, Math.min(1, this.callSpeakerVolume / 100));
        video.play?.().catch(() => {});
      }
      tile.querySelector('strong').textContent = session.peer?.userName || session.call?.peerName || 'Group member';
      tile.querySelector('small').textContent = session.status || session.call?.status || 'ringing';
    });
    Array.from(grid.querySelectorAll('[data-group-call-id]')).forEach((tile) => {
      if (!existingTiles.has(tile.dataset.groupCallId)) tile.remove();
    });
  }

  updateGroupCallStatus() {
    if (!this.activeCall?.isGroupCall) return;
    const sessions = Array.from(this.groupCallSessions.values());
    const connected = sessions.filter((session) => ['connected', 'completed'].includes(session.peerConnection.connectionState)).length;
    const ringing = sessions.filter((session) => session.status === 'ringing' || session.call?.status === 'ringing').length;
    const status = document.getElementById('liveCallStatus');
    if (status) status.textContent = connected > 0
      ? `${connected} connected${ringing > 0 ? `, ${ringing} ringing` : ''}`
      : `${ringing || sessions.length} ringing`;
    if (connected > 0) {
      this.stopOutgoingRing();
      this.clearCallTimeout();
    }
    this.renderGroupRemoteGrid();
  }

  attachRemoteTrack(track) {
    if (!track || !this.remoteStream) return;
    const sameKindTracks = this.remoteStream.getTracks().filter((existing) => existing.kind === track.kind);
    sameKindTracks.forEach((existing) => {
      if (existing.id !== track.id) this.remoteStream.removeTrack(existing);
    });
    if (!this.remoteStream.getTracks().some((existing) => existing.id === track.id)) {
      this.remoteStream.addTrack(track);
    }
    const removeEndedTrack = () => {
      this.remoteStream?.removeTrack(track);
      this.remoteTrackMuteHandlers.delete(track.id);
    };
    if (!this.remoteTrackMuteHandlers.has(track.id)) {
      track.addEventListener?.('ended', removeEndedTrack, { once: true });
      this.remoteTrackMuteHandlers.set(track.id, removeEndedTrack);
    }
  }

  configureCallMediaElements() {
    const localVideo = document.getElementById('liveLocalVideo');
    const remoteVideo = document.getElementById('liveRemoteVideo');
    if (localVideo) {
      localVideo.muted = true;
      localVideo.volume = 0;
      localVideo.autoplay = true;
      localVideo.playsInline = true;
      localVideo.setAttribute('playsinline', '');
    }
    if (remoteVideo) {
      remoteVideo.muted = !this.callSpeakerEnabled;
      remoteVideo.autoplay = true;
      remoteVideo.playsInline = true;
      remoteVideo.setAttribute('playsinline', '');
      remoteVideo.controls = false;
      remoteVideo.volume = Math.max(0, Math.min(1, this.callSpeakerVolume / 100));
    }
    this.syncSpeakerControls();
  }

  async preparePeerConnection(callType) {
    const videoConstraint = callType === 'video'
      ? (this.selectedVideoDeviceId ? { deviceId: { exact: this.selectedVideoDeviceId } } : { facingMode: { ideal: this.cameraFacingMode } })
      : false;
    this.configureCallMediaElements();
    this.localStream = await this.getCallMediaStream(callType, videoConstraint);
    await this.tuneCallAudioTrack(this.localStream);
    this.remoteStream = new MediaStream();
    const localVideo = document.getElementById('liveLocalVideo');
    const remoteVideo = document.getElementById('liveRemoteVideo');
    if (localVideo) localVideo.srcObject = this.localStream;
    if (remoteVideo) remoteVideo.srcObject = this.remoteStream;
    this.syncCallCameraPreview();
    const pc = new RTCPeerConnection({ iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] });
    this.peerConnection = pc;
    this.localStream.getTracks().forEach((track) => pc.addTrack(track, this.localStream));
    await this.tunePeerConnectionSenders(pc);
    pc.ontrack = (event) => {
      const tracks = event.streams[0]?.getTracks?.()?.length ? event.streams[0].getTracks() : [event.track];
      tracks.forEach((track) => this.attachRemoteTrack(track));
      if (remoteVideo) remoteVideo.srcObject = this.remoteStream;
      remoteVideo?.play?.().catch(() => {});
    };
    pc.onicecandidate = (event) => {
      if (event.candidate) this.sendSignal('ice', event.candidate.toJSON ? event.candidate.toJSON() : event.candidate).catch(() => {});
    };
    pc.onconnectionstatechange = () => {
      document.getElementById('liveCallStatus').textContent = pc.connectionState;
      if (['failed', 'closed'].includes(pc.connectionState)) {
        setTimeout(() => this.cleanupCall(), 1200);
      } else if (pc.connectionState === 'disconnected') {
        setTimeout(() => {
          if (this.peerConnection === pc && ['disconnected', 'failed', 'closed'].includes(pc.connectionState)) {
            this.cleanupCall();
          }
        }, 10000);
      }
    };
    await this.populateCameraDevices();
    this.applyCameraZoom(this.cameraZoom);
  }

  async requestVideoUpgrade() {
    if (!this.activeCall || this.activeCall.callType === 'video') return;
    await this.sendSignal('video_request', { requestedAt: new Date().toISOString() }).catch((error) => {
      showNotice('Video Request', error.message || 'Unable to request video upgrade.');
    });
    document.getElementById('liveCallStatus').textContent = 'Video request sent...';
  }

  async respondToVideoUpgrade(accepted) {
    if (!this.activeCall) return;
    await this.sendSignal(accepted ? 'video_accept' : 'video_decline', { respondedAt: new Date().toISOString() }).catch(() => {});
    if (!accepted) {
      document.getElementById('liveCallStatus').textContent = 'Video request declined';
      return;
    }
    this.activeCall.callType = 'video';
    this.showCallModal('Video call', 'Switching to video...', 'video', false);
    await this.addVideoToCurrentCall(false);
  }

  async addVideoToCurrentCall(createOffer = false) {
    if (!this.peerConnection || !this.localStream) return;
    const existing = this.localStream.getVideoTracks()[0];
    if (!existing) {
      const videoConstraint = this.selectedVideoDeviceId ? { deviceId: { exact: this.selectedVideoDeviceId } } : { facingMode: { ideal: this.cameraFacingMode } };
      const stream = await navigator.mediaDevices.getUserMedia({ video: this.getCallVideoConstraints(videoConstraint), audio: false });
      const track = stream.getVideoTracks()[0];
      if (track) {
        this.localStream.addTrack(track);
        this.peerConnection.addTrack(track, this.localStream);
        await this.tunePeerConnectionSenders(this.peerConnection);
      }
      document.getElementById('liveLocalVideo').srcObject = this.localStream;
      this.syncCallCameraPreview();
      await this.populateCameraDevices();
      this.applyCameraZoom(this.cameraZoom);
    }
    if (createOffer) {
      const offer = await this.peerConnection.createOffer();
      await this.peerConnection.setLocalDescription(offer);
      await this.sendSignal('offer', offer);
    }
  }

  async populateCameraDevices() {
    if (!navigator.mediaDevices?.enumerateDevices) return;
    const select = document.getElementById('liveCameraSelect');
    if (!select) return;
    const devices = await navigator.mediaDevices.enumerateDevices();
    this.availableVideoDevices = devices.filter((device) => device.kind === 'videoinput');
    const activeDeviceId = this.localStream?.getVideoTracks?.()[0]?.getSettings?.().deviceId || this.selectedVideoDeviceId;
    select.innerHTML = this.availableVideoDevices.map((device, index) => {
      const selected = device.deviceId === activeDeviceId ? 'selected' : '';
      return `<option value="${escapeHtml(device.deviceId)}" ${selected}>${escapeHtml(device.label || `Camera ${index + 1}`)}</option>`;
    }).join('');
    select.disabled = this.availableVideoDevices.length === 0;
  }

  async switchCamera(deviceId) {
    if (!deviceId || !this.localStream || !this.peerConnection || this.cameraSwitching) return;
    const button = document.getElementById('liveSwitchCameraBtn');
    const select = document.getElementById('liveCameraSelect');
    try {
      this.cameraSwitching = true;
      if (button) button.disabled = true;
      if (select) select.disabled = true;
      this.selectedVideoDeviceId = deviceId;
      await this.replaceCallVideoTrack({ deviceId: { exact: deviceId } }, { stopOldFirst: this.isHandheldCallDevice() });
      await this.populateCameraDevices();
    } catch (error) {
      showNotice('Camera Switch Failed', error.message || 'Unable to switch to the selected camera.');
    } finally {
      this.cameraSwitching = false;
      if (button) button.disabled = false;
      if (select) select.disabled = this.availableVideoDevices.length === 0;
    }
  }

  async switchFacingCamera() {
    if (!this.localStream || !this.peerConnection || this.cameraSwitching) return;
    const button = document.getElementById('liveSwitchCameraBtn');
    const select = document.getElementById('liveCameraSelect');
    try {
      this.cameraSwitching = true;
      if (button) button.disabled = true;
      if (select) select.disabled = true;
      await this.populateCameraDevices();
      const currentTrack = this.localStream.getVideoTracks()[0];
      const settings = currentTrack?.getSettings?.() || {};
      const currentFacing = settings.facingMode === 'user' || settings.facingMode === 'environment'
        ? settings.facingMode
        : this.cameraFacingMode;
      const nextFacing = currentFacing === 'user' ? 'environment' : 'user';

      if (this.isHandheldCallDevice()) {
        this.cameraFacingMode = nextFacing;
        this.selectedVideoDeviceId = '';
        await this.replaceCallVideoTrack([
          { facingMode: { exact: nextFacing } },
          { facingMode: { ideal: nextFacing } },
          nextFacing === 'user' ? { facingMode: 'user' } : { facingMode: 'environment' }
        ], { stopOldFirst: true });
      } else if (this.availableVideoDevices.length > 1) {
        const currentDeviceId = this.selectedVideoDeviceId || settings.deviceId || '';
        const currentIndex = Math.max(0, this.availableVideoDevices.findIndex((device) => device.deviceId === currentDeviceId));
        const nextDevice = this.availableVideoDevices[(currentIndex + 1) % this.availableVideoDevices.length];
        this.selectedVideoDeviceId = nextDevice.deviceId;
        await this.replaceCallVideoTrack({ deviceId: { exact: nextDevice.deviceId } });
      } else {
        this.cameraFacingMode = nextFacing;
        this.selectedVideoDeviceId = '';
        await this.replaceCallVideoTrack({ facingMode: { ideal: nextFacing } });
      }
      await this.populateCameraDevices();
    } catch (error) {
      showNotice('Camera Switch Failed', error.message || 'Unable to switch camera during the call.');
    } finally {
      this.cameraSwitching = false;
      if (button) button.disabled = false;
      if (select) select.disabled = this.availableVideoDevices.length === 0;
    }
  }

  async replaceCallVideoTrack(videoConstraint, { stopOldFirst = false } = {}) {
    if (!this.localStream || !this.peerConnection) return;
    const constraints = Array.isArray(videoConstraint) ? videoConstraint : [videoConstraint];
    const oldTrack = this.localStream.getVideoTracks()[0];
    const sender = this.peerConnection.getSenders().find((item) => item.track?.kind === 'video');
    if (stopOldFirst && oldTrack) {
      if (sender) await sender.replaceTrack(null).catch(() => {});
      oldTrack.stop();
      this.localStream.removeTrack(oldTrack);
    }
    let stream = null;
    let lastError = null;
    for (const constraint of constraints) {
      try {
        stream = await navigator.mediaDevices.getUserMedia({ video: this.getCallVideoConstraints(constraint), audio: false });
        break;
      } catch (error) {
        lastError = error;
      }
    }
    if (!stream) throw lastError || new Error('Unable to open the selected camera.');
    const newTrack = stream.getVideoTracks()[0];
    if (!newTrack) throw new Error('No camera track was returned.');
    if (sender) {
      await sender.replaceTrack(newTrack);
    } else {
      this.peerConnection.addTrack(newTrack, this.localStream);
    }
    await this.tunePeerConnectionSenders(this.peerConnection);
    if (oldTrack && !stopOldFirst) {
      oldTrack.stop();
      this.localStream.removeTrack(oldTrack);
    }
    this.localStream.addTrack(newTrack);
    const settings = newTrack.getSettings?.() || {};
    if (settings.facingMode === 'user' || settings.facingMode === 'environment') {
      this.cameraFacingMode = settings.facingMode;
    }
    if (settings.deviceId) {
      this.selectedVideoDeviceId = settings.deviceId;
    }
    const localVideo = document.getElementById('liveLocalVideo');
    if (localVideo) localVideo.srcObject = this.localStream;
    this.syncCallCameraPreview();
    this.applyCameraZoom(this.cameraZoom);
  }

  async applyCameraZoom(value = 1) {
    this.cameraZoom = Math.max(1, Number(value) || 1);
    document.getElementById('liveCallZoom') && (document.getElementById('liveCallZoom').value = String(this.cameraZoom));
    document.getElementById('liveCameraZoom') && (document.getElementById('liveCameraZoom').value = String(this.cameraZoom));
    const track = this.localStream?.getVideoTracks?.()[0] || this.cameraStream?.getVideoTracks?.()[0];
    if (!track?.getCapabilities || !track?.applyConstraints) return;
    const capabilities = track.getCapabilities();
    if (!capabilities.zoom) return;
    const zoom = Math.min(Math.max(this.cameraZoom, capabilities.zoom.min || 1), capabilities.zoom.max || this.cameraZoom);
    await track.applyConstraints({ advanced: [{ zoom }] }).catch(() => {});
  }

  toggleCamera() {
    const track = this.localStream?.getVideoTracks?.()[0];
    if (!track) return;
    track.enabled = !track.enabled;
    document.getElementById('liveToggleCameraBtn')?.classList.toggle('active', track.enabled);
  }

  toggleMicrophone() {
    const track = this.localStream?.getAudioTracks?.()[0];
    if (!track) return;
    track.enabled = !track.enabled;
    document.getElementById('liveToggleMicBtn')?.classList.toggle('muted', !track.enabled);
  }

  syncSpeakerControls() {
    const remoteVideo = document.getElementById('liveRemoteVideo');
    if (remoteVideo) {
      remoteVideo.muted = !this.callSpeakerEnabled;
      remoteVideo.volume = Math.max(0, Math.min(1, this.callSpeakerVolume / 100));
    }
    document.querySelectorAll('#liveGroupRemoteGrid video').forEach((media) => {
      media.muted = !this.callSpeakerEnabled;
      media.volume = Math.max(0, Math.min(1, this.callSpeakerVolume / 100));
    });
    const button = document.getElementById('liveToggleSpeakerBtn');
    if (button) {
      button.classList.toggle('muted', !this.callSpeakerEnabled || this.callSpeakerVolume <= 0);
      button.innerHTML = this.callSpeakerEnabled && this.callSpeakerVolume > 0
        ? '<i class="fas fa-volume-high"></i>'
        : '<i class="fas fa-volume-xmark"></i>';
    }
    const volume = document.getElementById('liveCallVolume');
    if (volume) {
      volume.value = String(this.callSpeakerVolume);
      volume.setAttribute('aria-valuetext', `${this.callSpeakerVolume}%`);
    }
  }

  toggleSpeaker() {
    this.callSpeakerEnabled = !this.callSpeakerEnabled;
    localStorage.setItem('liveCallSpeakerEnabled', this.callSpeakerEnabled ? '1' : '0');
    this.syncSpeakerControls();
  }

  setSpeakerVolume(value) {
    this.callSpeakerVolume = Math.max(0, Math.min(100, Number(value) || 0));
    if (this.callSpeakerVolume > 0 && !this.callSpeakerEnabled) {
      this.callSpeakerEnabled = true;
      localStorage.setItem('liveCallSpeakerEnabled', '1');
    }
    localStorage.setItem('liveCallSpeakerVolume', String(this.callSpeakerVolume));
    this.syncSpeakerControls();
  }

  async toggleCallFullscreen() {
    const card = document.querySelector('.live-call-card');
    if (!card) return;
    if (!document.fullscreenElement) {
      await card.requestFullscreen?.();
      card.classList.add('fullscreen');
    } else {
      await document.exitFullscreen?.();
      card.classList.remove('fullscreen');
    }
  }

  startSignalPolling() {
    if (this.timers.signals) clearInterval(this.timers.signals);
    if (this.activeCall?.isGroupCall) {
      this.timers.signals = setInterval(() => this.pollGroupSignals(), 350);
      this.pollGroupSignals();
      return;
    }
    this.timers.signals = setInterval(() => this.pollSignals(), 350);
    this.pollSignals();
  }

  async pollGroupSignals() {
    if (!this.activeCall?.isGroupCall) return;
    const sessions = Array.from(this.groupCallSessions.values());
    await Promise.all(sessions.map(async (session) => {
      try {
        const data = await this.postCall({ action: 'signals', call_id: session.call.callId, after_id: session.lastSignalId || 0 }, 'GET');
        for (const signal of data.signals || []) {
          session.lastSignalId = Math.max(session.lastSignalId || 0, Number(signal.signalId || 0));
          await this.handleGroupSignal(session, signal);
        }
      } catch (_error) {}
    }));
  }

  async handleGroupSignal(session, signal) {
    if (!session?.peerConnection) return;
    if (signal.signalType === 'hangup') {
      this.closeGroupPeerSession(session.call.callId);
      return;
    }
    if (signal.signalType === 'call_accept') {
      session.call.status = 'accepted';
      session.status = 'connecting';
      this.updateGroupCallStatus();
      return;
    }
    if (signal.signalType === 'answer') {
      await session.peerConnection.setRemoteDescription(new RTCSessionDescription(signal.payload));
      await this.flushGroupPendingIceCandidates(session);
      session.call.status = 'accepted';
      session.status = 'connected';
      this.updateGroupCallStatus();
      return;
    }
    if (signal.signalType === 'ice' && signal.payload) {
      if (!session.peerConnection.remoteDescription) {
        session.pendingIceCandidates.push(signal.payload);
        return;
      }
      await session.peerConnection.addIceCandidate(new RTCIceCandidate(signal.payload)).catch(() => {});
    }
  }

  async flushGroupPendingIceCandidates(session) {
    if (!session?.peerConnection?.remoteDescription || !session.pendingIceCandidates?.length) return;
    const candidates = session.pendingIceCandidates.splice(0);
    for (const candidate of candidates) {
      await session.peerConnection.addIceCandidate(new RTCIceCandidate(candidate)).catch(() => {});
    }
  }

  async pollSignals() {
    if (!this.activeCall) return;
    try {
      const data = await this.postCall({ action: 'signals', call_id: this.activeCall.callId, after_id: this.lastSignalId }, 'GET');
      for (const signal of data.signals || []) {
        this.lastSignalId = Math.max(this.lastSignalId, Number(signal.signalId || 0));
        await this.handleSignal(signal);
      }
    } catch (_error) {}
  }

  async handleSignal(signal) {
    if (!this.activeCall) return;
    if (signal.signalType === 'hangup') {
      this.cleanupCall();
      return;
    }
    if (signal.signalType === 'call_accept') {
      this.activeCall.status = 'accepted';
      this.stopIncomingRing();
      this.stopOutgoingRing();
      this.clearCallTimeout();
      const status = document.getElementById('liveCallStatus');
      if (status && status.textContent !== 'Connected') status.textContent = 'Connecting...';
      return;
    }
    if (signal.signalType === 'video_request') {
      const accepted = await this.confirmVideoUpgradeRequest(signal);
      await this.respondToVideoUpgrade(accepted);
      return;
    }
    if (signal.signalType === 'video_accept') {
      this.activeCall.callType = 'video';
      this.showCallModal('Video call', 'Video request accepted. Connecting video...', 'video', false);
      await this.addVideoToCurrentCall(true);
      return;
    }
    if (signal.signalType === 'video_decline') {
      document.getElementById('liveCallStatus').textContent = 'Video request declined';
      showNotice('Video Request', 'The call partner declined switching to video.', 'info');
      return;
    }
    if (signal.signalType === 'offer') {
      if (this.activeCall.callType === 'video') await this.addVideoToCurrentCall(false);
      if (!this.peerConnection) await this.preparePeerConnection(this.activeCall.callType);
      await this.peerConnection.setRemoteDescription(new RTCSessionDescription(signal.payload));
      await this.flushPendingIceCandidates();
      const answer = await this.peerConnection.createAnswer();
      await this.peerConnection.setLocalDescription(answer);
      await this.sendSignal('answer', answer);
      this.activeCall.status = 'accepted';
      this.stopIncomingRing();
      this.stopOutgoingRing();
      this.clearCallTimeout();
      document.getElementById('liveCallStatus').textContent = 'Connected';
      return;
    }
    if (signal.signalType === 'answer' && this.peerConnection) {
      await this.peerConnection.setRemoteDescription(new RTCSessionDescription(signal.payload));
      await this.flushPendingIceCandidates();
      this.activeCall.status = 'accepted';
      this.stopIncomingRing();
      this.stopOutgoingRing();
      this.clearCallTimeout();
      document.getElementById('liveCallStatus').textContent = 'Connected';
      return;
    }
    if (signal.signalType === 'ice' && this.peerConnection && signal.payload) {
      await this.addIceCandidateSafely(signal.payload);
    }
  }

  async addIceCandidateSafely(payload) {
    if (!this.peerConnection || !payload) return;
    if (!this.peerConnection.remoteDescription) {
      this.pendingIceCandidates.push(payload);
      return;
    }
    await this.peerConnection.addIceCandidate(new RTCIceCandidate(payload)).catch(() => {});
  }

  async flushPendingIceCandidates() {
    if (!this.peerConnection?.remoteDescription || !this.pendingIceCandidates.length) return;
    const candidates = this.pendingIceCandidates.splice(0);
    for (const candidate of candidates) {
      await this.peerConnection.addIceCandidate(new RTCIceCandidate(candidate)).catch(() => {});
    }
  }

  async sendSignal(signalType, payload) {
    if (!this.activeCall) return null;
    const recipientId = this.activeCall.callerId === this.currentUserId ? this.activeCall.calleeId : this.activeCall.callerId;
    return this.postCall({ action: 'signal', call_id: this.activeCall.callId, recipient_id: recipientId, signal_type: signalType, payload });
  }

  async sendSignalForCall(call, signalType, payload) {
    if (!call) return null;
    const recipientId = call.callerId === this.currentUserId ? call.calleeId : call.callerId;
    return this.postCall({ action: 'signal', call_id: call.callId, recipient_id: recipientId, signal_type: signalType, payload });
  }

  closeGroupPeerSession(callId) {
    const session = this.groupCallSessions.get(callId);
    if (!session) return;
    session.peerConnection?.close();
    session.remoteStream?.getTracks().forEach((track) => track.stop());
    this.groupCallSessions.delete(callId);
    this.updateGroupCallStatus();
  }

  async confirmVideoUpgradeRequest() {
    this.closeVideoUpgradeConsent();
    return new Promise((resolve) => {
      const overlay = document.createElement('div');
      overlay.className = 'live-video-consent-modal';
      overlay.innerHTML = `
        <div class="live-video-consent-card" role="dialog" aria-modal="true" aria-labelledby="liveVideoConsentTitle">
          <header>
            <i class="fas fa-video" aria-hidden="true"></i>
            <h3 id="liveVideoConsentTitle">Switch to Video?</h3>
          </header>
          <p>The call partner wants to switch this audio call to video.</p>
          <div class="live-video-consent-actions">
            <button type="button" class="decline">Decline</button>
            <button type="button" class="accept">Accept</button>
          </div>
        </div>
      `;
      const finish = (accepted) => {
        this.closeVideoUpgradeConsent();
        resolve(Boolean(accepted));
      };
      overlay.querySelector('.accept')?.addEventListener('click', () => finish(true), { once: true });
      overlay.querySelector('.decline')?.addEventListener('click', () => finish(false), { once: true });
      overlay.addEventListener('click', (event) => {
        if (event.target === overlay) finish(false);
      });
      overlay.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
          event.preventDefault();
          finish(false);
        }
      });
      document.body.appendChild(overlay);
      this.videoUpgradeConsentOverlay = overlay;
      overlay.querySelector('.accept')?.focus();
    });
  }

  closeVideoUpgradeConsent() {
    this.videoUpgradeConsentOverlay?.remove();
    this.videoUpgradeConsentOverlay = null;
  }

  async postCall(payload, method = 'POST') {
    const url = method === 'GET' ? `${API.call}?${new URLSearchParams(payload)}` : API.call;
    const options = method === 'GET' ? {} : { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) };
    return this.fetchJson(url, options);
  }

  cleanupCall() {
    this.closeVideoUpgradeConsent();
    if (this.timers.signals) clearInterval(this.timers.signals);
    this.timers.signals = null;
    this.stopIncomingRing();
    this.stopOutgoingRing();
    this.clearCallTimeout();
    this.groupCallSessions.forEach((session) => {
      session.peerConnection?.close();
      session.remoteStream?.getTracks().forEach((track) => track.stop());
    });
    this.groupCallSessions.clear();
    this.peerConnection?.close();
    this.localStream?.getTracks().forEach((track) => track.stop());
    this.remoteStream?.getTracks().forEach((track) => track.stop());
    this.remoteStream?.getTracks().forEach((track) => {
      const handler = this.remoteTrackMuteHandlers.get(track.id);
      if (handler) track.removeEventListener?.('ended', handler);
    });
    this.peerConnection = null;
    this.localStream = null;
    this.remoteStream = null;
    this.remoteTrackMuteHandlers.clear();
    this.pendingIceCandidates = [];
    this.activeCall = null;
    this.lastSignalId = 0;
    const localVideo = document.getElementById('liveLocalVideo');
    const remoteVideo = document.getElementById('liveRemoteVideo');
    if (localVideo) localVideo.srcObject = null;
    if (remoteVideo) remoteVideo.srcObject = null;
    const groupGrid = document.getElementById('liveGroupRemoteGrid');
    if (groupGrid) {
      groupGrid.innerHTML = '';
      groupGrid.classList.add('hidden');
    }
    remoteVideo?.classList.remove('hidden');
    document.getElementById('liveChatDock')?.classList.remove('call-active');
    document.getElementById('liveCallModal')?.classList.add('hidden');
  }
}

export async function initLiveChat(options = {}) {
  if (window.PensionsGoLiveChat?.instance) return window.PensionsGoLiveChat.instance;
  const instance = new LiveChatApp(options);
  window.PensionsGoLiveChat = { instance };
  await instance.init();
  return instance;
}
