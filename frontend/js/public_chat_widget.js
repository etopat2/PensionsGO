(function () {
  const PUBLIC_CHAT_PAGES = new Set(["index.html", "", "about.html", "faq.html", "podcast.html", "podcast_public.html", "feedback.html", "terms.html", "login.html", "pensioner_board.html"]);
  const state = {
    settings: null,
    availability: null,
    visitor: null,
    csrfToken: "",
    session: null,
    feedbackSession: null,
    lastId: 0,
    polling: false,
    sendingMessage: false,
    uploadingAttachment: false,
    sendingVoice: false,
    pollTimer: null,
    typingTimer: null,
    typingStopTimer: null,
    lastTypingAt: 0,
    mediaRecorder: null,
    recordingStream: null,
    voiceChunks: [],
    voiceStartedAt: 0,
    voiceTimer: null,
    voiceDraft: null,
    rating: 0
  };

  const api = (path) => `../backend/api/${path}`;
  const pageName = () => (location.pathname.split("/").pop() || "index.html").toLowerCase();
  const storeKey = () => "pensionsgo_public_chat_session";

  function escapeHtml(value) {
    return String(value || "").replace(/[&<>"']/g, (m) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" }[m]));
  }

  async function fetchJson(url, options = {}) {
    const response = await fetch(url, { credentials: "include", cache: "no-store", ...options });
    const data = await response.json().catch(() => ({ success: false, message: "The server returned an unreadable response." }));
    if (!response.ok && data && data.success !== true) {
      data.message = data.message || "The request could not be completed.";
    }
    return data;
  }

  function showChatFeedback(message, type = "info", title = "Public Live Support") {
    if (typeof window.appToast === "function") {
      window.appToast(message, { type, title });
      return;
    }
    const body = document.getElementById("publicChatBody");
    if (body) {
      body.insertAdjacentHTML("afterbegin", `<div class="public-chat-status ${escapeHtml(type)}">${escapeHtml(message)}</div>`);
    }
  }

  function allowedForPage(settings) {
    const page = pageName();
    if (!PUBLIC_CHAT_PAGES.has(page) || !settings?.enabled) return false;
    if (page === "pensioner_board.html") return Boolean(settings.pensionerPortalEnabled);
    if (!settings.publicPagesEnabled) return false;
    const pageMap = {
      "index.html": "homeEnabled",
      "": "homeEnabled",
      "about.html": "aboutEnabled",
      "faq.html": "faqEnabled",
      "podcast.html": "podcastEnabled",
      "podcast_public.html": "podcastEnabled",
      "feedback.html": "feedbackPageEnabled",
      "terms.html": "termsEnabled"
    };
    const key = pageMap[page];
    return key ? settings[key] !== false : true;
  }

  function renderShell() {
    if (document.getElementById("publicChatLauncher")) return;
    document.body.insertAdjacentHTML("beforeend", `
      <button id="publicChatLauncher" class="public-chat-launcher" type="button" aria-controls="publicChatPanel" aria-expanded="false">
        <span>Live Support</span>
        <small id="publicChatLauncherState">${state.availability?.online ? "Online" : "Offline"}</small>
      </button>
      <section id="publicChatPanel" class="public-chat-panel" hidden aria-live="polite">
        <div class="public-chat-head">
          <div>
            <h2 class="public-chat-title">Public Live Support</h2>
            <span class="public-chat-ref" id="publicChatRef">Welcome to UPS PensionsGo support</span>
          </div>
          <button type="button" class="public-chat-close" id="publicChatClose" aria-label="Close">&times;</button>
        </div>
        <div class="public-chat-body" id="publicChatBody"></div>
        <form class="public-chat-reply" id="publicChatReply" hidden>
          <div class="public-chat-typing" id="publicChatTyping" hidden>Officer is typing...</div>
          <div class="public-chat-voice-draft" id="publicChatVoiceDraft" hidden></div>
          <div class="public-chat-composer">
            <textarea id="publicChatReplyText" maxlength="${Number(state.settings?.maxMessageLength || 2000)}" placeholder="Type your message" aria-label="Type your message"></textarea>
            <button type="button" class="public-chat-composer-icon public-chat-attach-icon" id="publicChatAttachBtn" ${state.settings?.attachmentsEnabled ? "" : "hidden"} title="Attach file" aria-label="Attach file">+</button>
            <button type="button" class="public-chat-composer-icon public-chat-voice-icon" id="publicChatVoiceBtn" title="Record voice note" aria-label="Record voice note"><span class="public-chat-mic-icon" aria-hidden="true"></span></button>
          </div>
          <div class="public-chat-actions-row">
            <input type="file" id="publicChatAttachment" hidden accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
            <button type="button" class="public-chat-secondary" id="publicChatEndBtn">End Chat</button>
            <button type="submit" class="public-chat-send">Send</button>
          </div>
        </form>
      </section>
      <div class="public-chat-feedback-modal" id="publicChatFeedbackModal" hidden>
        <div class="public-chat-feedback-backdrop" data-public-chat-feedback-close="1"></div>
        <div class="public-chat-feedback-dialog" role="dialog" aria-modal="true" aria-labelledby="publicChatFeedbackTitle">
          <button type="button" class="public-chat-close public-chat-feedback-close" id="publicChatFeedbackClose" aria-label="Close feedback">&times;</button>
          <h2 id="publicChatFeedbackTitle">Rate this support chat</h2>
          <p>Help us improve public correspondence support.</p>
          <div class="public-chat-rating" id="publicChatRating"></div>
          <textarea id="publicChatFeedbackComments" maxlength="2000" placeholder="Optional comment"></textarea>
          <div class="public-chat-actions-row">
            <button type="button" class="public-chat-secondary" id="publicChatFeedbackSkip">Skip</button>
            <button type="button" class="public-chat-submit" id="publicChatFeedbackSend">Submit Feedback</button>
          </div>
        </div>
      </div>
    `);

    document.getElementById("publicChatLauncher").addEventListener("click", togglePanel);
    document.getElementById("publicChatClose").addEventListener("click", () => {
      document.getElementById("publicChatPanel").hidden = true;
      document.getElementById("publicChatLauncher").setAttribute("aria-expanded", "false");
    });
    document.getElementById("publicChatReply").addEventListener("submit", sendMessage);
    document.getElementById("publicChatEndBtn").addEventListener("click", endChat);
    document.getElementById("publicChatFeedbackSend").addEventListener("click", submitFeedback);
    document.getElementById("publicChatFeedbackClose").addEventListener("click", closeFeedbackPopup);
    document.getElementById("publicChatFeedbackSkip").addEventListener("click", closeFeedbackPopup);
    document.querySelector("[data-public-chat-feedback-close]")?.addEventListener("click", closeFeedbackPopup);
    document.getElementById("publicChatAttachBtn").addEventListener("click", () => document.getElementById("publicChatAttachment").click());
    document.getElementById("publicChatAttachment").addEventListener("change", uploadAttachment);
    document.getElementById("publicChatVoiceBtn").addEventListener("click", toggleVoiceRecording);
    document.getElementById("publicChatReplyText").addEventListener("input", notifyTyping);
    document.getElementById("publicChatReplyText").addEventListener("blur", () => stopTyping(false));
    renderRating();
    restoreSession();
    setInterval(refreshAvailability, 10000);
  }

  async function togglePanel() {
    const panel = document.getElementById("publicChatPanel");
    panel.hidden = !panel.hidden;
    document.getElementById("publicChatLauncher").setAttribute("aria-expanded", String(!panel.hidden));
    if (!panel.hidden) {
      await refreshAvailability();
      loadBody();
    }
  }

  async function refreshAvailability() {
    try {
      const data = await fetchJson(api("public_chat_availability.php"));
      if (data.success) {
        state.availability = data.availability || state.availability;
        const launcherState = document.getElementById("publicChatLauncherState");
        if (launcherState) launcherState.textContent = state.availability?.online ? "Online" : "Offline";
      }
    } catch (_) {}
  }

  function renderRating() {
    const wrap = document.getElementById("publicChatRating");
    if (!wrap) return;
    wrap.innerHTML = "";
    for (let i = 1; i <= 5; i += 1) {
      const btn = document.createElement("button");
      btn.type = "button";
      btn.textContent = String(i);
      btn.setAttribute("aria-label", `${i} out of 5`);
      btn.classList.toggle("active", state.rating === i);
      btn.addEventListener("click", () => {
        state.rating = i;
        renderRating();
      });
      wrap.appendChild(btn);
    }
  }

  function openFeedbackPopup() {
    state.feedbackSession = state.session;
    if (!state.settings?.feedbackEnabled) {
      state.session = null;
      resetChatPanel();
      return;
    }
    state.rating = 0;
    const modal = document.getElementById("publicChatFeedbackModal");
    if (!modal) return;
    modal.hidden = false;
    renderRating();
    document.getElementById("publicChatFeedbackSend")?.focus();
    resetChatPanel();
  }

  function closeFeedbackPopup() {
    const modal = document.getElementById("publicChatFeedbackModal");
    if (modal) modal.hidden = true;
    state.feedbackSession = null;
    state.session = null;
  }

  function resetChatPanel() {
    clearTimeout(state.pollTimer);
    state.session = null;
    state.lastId = 0;
    localStorage.removeItem(storeKey());
    document.getElementById("publicChatRef").textContent = "Welcome to UPS PensionsGo support";
    document.getElementById("publicChatReply").hidden = true;
    document.getElementById("publicChatReplyText").value = "";
    cleanupVoiceDraft();
    hideTypingIndicator();
    renderStartForm(state.availability?.online ? "online" : "offline");
  }

  function renderStartForm(mode) {
    document.getElementById("publicChatReply").hidden = true;
    const p = state.visitor?.prefill || {};
    const categories = (state.settings?.categories || []).map((cat) => `<option value="${escapeHtml(cat)}">${escapeHtml(cat)}</option>`).join("");
    const isOffline = mode === "offline";
    document.getElementById("publicChatBody").innerHTML = `
      <div class="public-chat-welcome">
        <strong>${isOffline ? "Support officers are currently offline." : "Welcome. How can we help?"}</strong>
        <p>${isOffline ? escapeHtml(state.availability?.offlineMessage || "Leave a message and the pensions team will follow up.") : escapeHtml(state.settings?.welcomeText || "Start a secure public correspondence with the pensions support team.")}</p>
      </div>
      <form class="public-chat-form" id="publicChatStartForm">
        <label>Full name <input name="visitor_name" value="${escapeHtml(p.name || "")}" required autocomplete="name"></label>
        <div class="public-chat-grid">
          <label>Phone number <input name="phone_number" value="${escapeHtml(p.phone_number || "")}" autocomplete="tel"></label>
          <label>Email address <input type="email" name="email" value="${escapeHtml(p.email || "")}" autocomplete="email"></label>
        </div>
        <div class="public-chat-grid">
          <label>Force number <input name="force_number" value="${escapeHtml(p.force_number || "")}"></label>
          <label>Pensioner number <input name="pensioner_number" value="${escapeHtml(p.pensioner_number || "")}"></label>
        </div>
        <label>Inquiry category <select name="inquiry_category" required><option value="">Select category</option>${categories}</select></label>
        <label>Subject <input name="subject" maxlength="220" required></label>
        <label>Initial message <textarea name="message" maxlength="${Number(state.settings?.maxMessageLength || 2000)}" required></textarea></label>
        <label class="public-chat-consent"><input type="checkbox" name="consent" value="1" required> <span>${escapeHtml(state.settings?.consentText || "I consent to UPS PensionsGo using these details to respond to this support request.")}</span></label>
        <div class="public-chat-status" id="publicChatStatus"></div>
        <button type="submit" class="public-chat-submit">${isOffline ? "Submit Offline Message" : "Start Chat"}</button>
      </form>
    `;
    document.getElementById("publicChatStartForm").addEventListener("submit", (event) => startChat(event, isOffline));
  }

  async function verifySessionAndShow() {
    if (state.session) {
      try {
        const params = new URLSearchParams({ session_id: state.session.session_id, token: state.session.token, last_id: state.lastId });
        const data = await fetchJson(`${api("public_chat_poll.php")}?${params.toString()}`);
        if (data.success && data.session?.status !== "closed") {
          showThread();
          addMessages(data.messages || []);
          renderPeerTyping(data.typing || []);
          poll();
          return;
        }
      } catch (_) {}
      state.session = null;
      localStorage.removeItem(storeKey());
      document.getElementById("publicChatReply").hidden = true;
      renderStartForm(state.availability?.online ? "online" : "offline");
      return;
    }
  }

  function loadBody() {
    if (state.session) {
      document.getElementById("publicChatReply").hidden = true;
      verifySessionAndShow();
      return;
    }
    document.getElementById("publicChatReply").hidden = true;
    renderStartForm(state.availability?.online ? "online" : "offline");
  }

  async function startChat(event, offlineMode) {
    event.preventDefault();
    const form = event.currentTarget;
    const status = form.querySelector("#publicChatStatus");
    status.textContent = offlineMode ? "Submitting offline request..." : "Starting chat...";
    const payload = Object.fromEntries(new FormData(form).entries());
    payload.source_page = pageName();
    payload.csrf_token = state.csrfToken;
    payload.consent = form.querySelector('[name="consent"]').checked;
    try {
      const endpoint = offlineMode ? "public_chat_offline.php" : "public_chat_start.php";
      const data = await fetchJson(api(endpoint), {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
      });
      if (!data.success) throw new Error(data.message || "Unable to submit support request.");
      state.session = data.session;
      localStorage.setItem(storeKey(), JSON.stringify(state.session));
      if (data.session?.offline) {
        showOfflineConfirmation(data);
      } else {
        showThread();
        poll();
      }
    } catch (error) {
      status.textContent = error.message || "Unable to submit support request.";
    }
  }

  function showOfflineConfirmation(data) {
    document.getElementById("publicChatRef").textContent = data.session?.chat_reference || "Offline request submitted";
    document.getElementById("publicChatBody").innerHTML = `
      <div class="public-chat-welcome">
        <strong>Request received</strong>
        <p>Your reference is <b>${escapeHtml(data.session?.chat_reference || "")}</b>${data.ticket_reference ? ` and follow-up ticket is <b>${escapeHtml(data.ticket_reference)}</b>` : ""}.</p>
      </div>
    `;
    document.getElementById("publicChatReply").hidden = true;
    openFeedbackPopup();
  }

  function restoreSession() {
    try {
      const saved = JSON.parse(localStorage.getItem(storeKey()) || "null");
      if (saved?.session_id && saved?.token && !saved.offline) {
        state.session = saved;
        document.getElementById("publicChatRef").textContent = saved.chat_reference || "Active chat";
      }
    } catch (_) {}
  }

  function showThread() {
    if (!state.session || state.session.offline) {
      document.getElementById("publicChatReply").hidden = true;
      return;
    }
    document.getElementById("publicChatRef").textContent = state.session?.chat_reference || "Active chat";
    document.getElementById("publicChatBody").innerHTML = `<div class="public-chat-thread" id="publicChatThread"></div><div class="public-chat-peer-typing" id="publicChatPeerTyping" hidden><span></span><span></span><span></span><strong>Officer is typing</strong></div>`;
    document.getElementById("publicChatReply").hidden = false;
  }

  function addMessages(messages) {
    const thread = document.getElementById("publicChatThread");
    if (!thread) return;
    messages.forEach((msg) => {
      if (thread.querySelector(`[data-message-id="${Number(msg.message_id || 0)}"]`)) return;
      state.lastId = Math.max(state.lastId, Number(msg.message_id || 0));
      const item = document.createElement("div");
      item.className = `public-chat-message ${msg.sender_type === "visitor" ? "visitor" : "agent"}`;
      item.dataset.messageId = String(Number(msg.message_id || 0));
      item.innerHTML = `${renderMessageContent(msg)}<small>${escapeHtml(msg.sender_name || msg.sender_type || "")} - ${escapeHtml(msg.created_at || "")}</small>`;
      thread.appendChild(item);
    });
    thread.scrollTop = thread.scrollHeight;
  }

  function formatFileSize(bytes) {
    const size = Number(bytes || 0);
    if (!size) return "";
    if (size < 1024) return `${size} B`;
    if (size < 1024 * 1024) return `${Math.round(size / 102.4) / 10} KB`;
    return `${Math.round(size / 104857.6) / 10} MB`;
  }

  function renderAttachment(att) {
    const name = escapeHtml(att.file_name || "Attachment");
    const size = formatFileSize(att.file_size);
    const url = `../backend/api/${att.view_url || ""}`;
    const download = `../backend/api/${att.download_url || att.view_url || ""}`;
    if (att.is_voice) {
      const mediaTag = String(att.mime_type || "").toLowerCase().startsWith("video/")
        ? `<video controls preload="metadata" src="${escapeHtml(url)}"></video>`
        : `<audio controls preload="metadata" src="${escapeHtml(url)}"></audio>`;
      return `
        <div class="public-chat-file-card voice">
          <div><strong>Voice note</strong><span>${name}${size ? ` - ${escapeHtml(size)}` : ""}</span></div>
          ${mediaTag}
        </div>
      `;
    }
    return `
      <div class="public-chat-file-card">
        <div><strong>${name}</strong><span>${escapeHtml(att.mime_type || "File")}${size ? ` - ${escapeHtml(size)}` : ""}</span></div>
        <div class="public-chat-file-actions">
          <a href="${escapeHtml(url)}" target="_blank" rel="noopener">View</a>
          <a href="${escapeHtml(download)}">Download</a>
        </div>
      </div>
    `;
  }

  function renderMessageContent(msg) {
    const attachments = Array.isArray(msg.attachments) ? msg.attachments : [];
    const text = String(msg.message_text || "").trim();
    const attachmentHtml = attachments.map(renderAttachment).join("");
    const showText = text && text !== "Attachment uploaded" && text !== "Voice note";
    return `${showText ? `<div>${escapeHtml(text)}</div>` : ""}${attachmentHtml || (!showText ? `<div>${escapeHtml(text || "Message")}</div>` : "")}`;
  }

  async function poll() {
    if (!state.session) return;
    clearTimeout(state.pollTimer);
    const activePollMs = Math.max(800, Math.min(Number(state.settings?.pollIntervalMs || 2500), 15000));
    if (state.polling) {
      state.pollTimer = setTimeout(poll, activePollMs);
      return;
    }
    state.polling = true;
    try {
      const params = new URLSearchParams({ session_id: state.session.session_id, token: state.session.token, last_id: state.lastId });
      const data = await fetchJson(`${api("public_chat_poll.php")}?${params.toString()}`);
      if (data.success) {
        addMessages(data.messages || []);
        renderPeerTyping(data.typing || []);
        if (data.session?.status === "closed") {
          clearTimeout(state.pollTimer);
          document.getElementById("publicChatReply").hidden = true;
          openFeedbackPopup();
          return;
        }
      }
    } catch (_) {
    } finally {
      state.polling = false;
    }
    state.pollTimer = setTimeout(poll, activePollMs);
  }

  async function sendMessage(event) {
    event.preventDefault();
    const textarea = document.getElementById("publicChatReplyText");
    const message = textarea.value.trim();
    if (!message || !state.session || state.sendingMessage) return;
    state.sendingMessage = true;
    textarea.value = "";
    const tempNode = appendVisitorMessage(message);
    await stopTyping(true);
    const data = await fetchJson(api("public_chat_send.php"), {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ session_id: state.session.session_id, token: state.session.token, message })
    }).catch((error) => ({ success: false, message: error.message || "Unable to send message." }));
    if (data.success) {
      const messageId = Number(data.message_id || 0);
      if (messageId > 0 && tempNode) {
        tempNode.dataset.messageId = String(messageId);
        tempNode.classList.remove("pending");
        tempNode.querySelector("small").textContent = `You - Sent`;
        state.lastId = Math.max(state.lastId, messageId);
      }
      poll();
    } else {
      if (tempNode) {
        tempNode.classList.add("failed");
        tempNode.querySelector("small").textContent = "Not sent";
      }
      showChatFeedback(data.message || "Unable to send message.", "error");
      textarea.value = message;
    }
    state.sendingMessage = false;
  }

  function appendVisitorMessage(message) {
    const thread = document.getElementById("publicChatThread");
    if (!thread) return null;
    const item = document.createElement("div");
    item.className = "public-chat-message visitor pending";
    item.innerHTML = `<div>${escapeHtml(message)}</div><small>You - Sending...</small>`;
    thread.appendChild(item);
    thread.scrollTop = thread.scrollHeight;
    return item;
  }

  async function notifyTyping() {
    if (!state.session) return;
    const now = Date.now();
    state.lastTypingAt = now;
    clearTimeout(state.typingStopTimer);
    state.typingStopTimer = setTimeout(() => stopTyping(false), 1200);
    if (state.typingTimer && now - state.typingTimer < 250) return;
    state.typingTimer = now;
    try {
      await fetchJson(api("public_chat_typing.php"), {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ session_id: state.session.session_id, token: state.session.token, typing: true })
      });
    } catch (_) {}
  }

  async function stopTyping(wait) {
    clearTimeout(state.typingStopTimer);
    state.typingStopTimer = null;
    state.lastTypingAt = 0;
    if (!state.session) return;
    const request = fetchJson(api("public_chat_typing.php"), {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ session_id: state.session.session_id, token: state.session.token, typing: false })
    }).catch(() => {});
    if (wait) await request;
  }

  function renderPeerTyping(typing) {
    const indicator = document.getElementById("publicChatPeerTyping");
    if (!indicator) return;
    if (!typing.length) {
      hideTypingIndicator();
      return;
    }
    const names = typing.map((item) => item.name || "Officer").slice(0, 2).join(", ");
    indicator.querySelector("strong").textContent = `${names} ${typing.length === 1 ? "is" : "are"} typing`;
    indicator.hidden = false;
    const thread = document.getElementById("publicChatThread");
    if (thread) thread.scrollTop = thread.scrollHeight;
  }

  function hideTypingIndicator() {
    const indicator = document.getElementById("publicChatPeerTyping");
    if (indicator) indicator.hidden = true;
  }

  async function uploadAttachment() {
    const fileInput = document.getElementById("publicChatAttachment");
    if (!fileInput?.files?.length) return;
    if (!state.session || state.uploadingAttachment) {
      fileInput.value = "";
      return;
    }
    state.uploadingAttachment = true;
    const form = new FormData();
    form.append("session_id", state.session.session_id);
    form.append("token", state.session.token);
    form.append("attachment", fileInput.files[0]);
    form.append("kind", "attachment");
    fileInput.value = "";
    const data = await fetchJson(api("public_chat_upload.php"), { method: "POST", body: form }).catch((error) => ({ success: false, message: error.message || "Unable to upload attachment." }));
    if (data.success) {
      addMessages(data.message ? [data.message] : []);
      poll();
    } else {
      showChatFeedback(data.message || "Unable to upload attachment.", "error");
    }
    state.uploadingAttachment = false;
  }

  function formatDuration(seconds) {
    const total = Math.max(0, Number(seconds || 0));
    const mins = String(Math.floor(total / 60)).padStart(2, "0");
    const secs = String(Math.floor(total % 60)).padStart(2, "0");
    return `${mins}:${secs}`;
  }

  async function toggleVoiceRecording() {
    const btn = document.getElementById("publicChatVoiceBtn");
    if (state.mediaRecorder?.state === "recording") {
      state.mediaRecorder.stop();
      btn?.classList.remove("recording");
      btn?.setAttribute("aria-pressed", "false");
      return;
    }
    if (state.voiceDraft) {
      renderVoiceDraft();
      return;
    }
    if (!state.session) return;
    if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) {
      showChatFeedback("Voice recording is not supported by this browser.", "warning");
      return;
    }
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      state.recordingStream = stream;
      state.voiceChunks = [];
      state.voiceStartedAt = Date.now();
      state.mediaRecorder = new MediaRecorder(stream);
      state.mediaRecorder.ondataavailable = (event) => {
        if (event.data?.size) state.voiceChunks.push(event.data);
      };
      state.mediaRecorder.onstop = () => {
        stream.getTracks().forEach((track) => track.stop());
        state.recordingStream = null;
        stopRecordingTimer();
        const blob = new Blob(state.voiceChunks, { type: "audio/webm" });
        state.voiceChunks = [];
        const file = new File([blob], `voice-note-${Date.now()}.webm`, { type: "audio/webm" });
        state.voiceDraft = {
          file,
          url: URL.createObjectURL(blob),
          duration: Math.max(1, Math.round((Date.now() - state.voiceStartedAt) / 1000))
        };
        state.mediaRecorder = null;
        renderVoiceDraft();
      };
      state.mediaRecorder.start();
      btn?.classList.add("recording");
      btn?.setAttribute("aria-pressed", "true");
      startRecordingTimer();
    } catch (_) {
      showChatFeedback("Unable to access the microphone.", "error");
    }
  }

  function startRecordingTimer() {
    const draft = document.getElementById("publicChatVoiceDraft");
    if (!draft) return;
    draft.hidden = false;
    stopRecordingTimer();
    const render = () => {
      const elapsed = Math.max(0, Math.floor((Date.now() - state.voiceStartedAt) / 1000));
      draft.innerHTML = `
        <div class="public-chat-voice-preview public-chat-recording-preview">
          <div class="public-chat-recording-status"><span></span><strong>Recording ${formatDuration(elapsed)}</strong></div>
        </div>
        <div class="public-chat-voice-actions">
          <button type="button" id="publicChatStopRecording" class="public-chat-submit">Stop</button>
          <button type="button" id="publicChatCancelRecording" class="public-chat-secondary">Cancel</button>
        </div>
      `;
      document.getElementById("publicChatStopRecording")?.addEventListener("click", toggleVoiceRecording, { once: true });
      document.getElementById("publicChatCancelRecording")?.addEventListener("click", cancelRecording, { once: true });
    };
    render();
    state.voiceTimer = setInterval(render, 1000);
  }

  function stopRecordingTimer() {
    if (state.voiceTimer) clearInterval(state.voiceTimer);
    state.voiceTimer = null;
  }

  function cancelRecording() {
    if (state.mediaRecorder?.state === "recording") {
      state.mediaRecorder.onstop = null;
      state.mediaRecorder.stop();
    }
    state.recordingStream?.getTracks().forEach((track) => track.stop());
    state.recordingStream = null;
    state.mediaRecorder = null;
    state.voiceChunks = [];
    stopRecordingTimer();
    document.getElementById("publicChatVoiceBtn")?.classList.remove("recording");
    document.getElementById("publicChatVoiceBtn")?.setAttribute("aria-pressed", "false");
    const draft = document.getElementById("publicChatVoiceDraft");
    if (draft) {
      draft.hidden = true;
      draft.innerHTML = "";
    }
  }

  function renderVoiceDraft() {
    const draft = document.getElementById("publicChatVoiceDraft");
    if (!draft || !state.voiceDraft) return;
    draft.hidden = false;
    draft.innerHTML = `
      <div class="public-chat-voice-preview">
        <strong>Voice note ${formatDuration(state.voiceDraft.duration)}</strong>
        <audio controls src="${escapeHtml(state.voiceDraft.url)}"></audio>
      </div>
      <div class="public-chat-voice-actions">
        <button type="button" id="publicChatSendVoiceDraft" class="public-chat-submit">Send</button>
        <button type="button" id="publicChatRedoVoiceDraft" class="public-chat-secondary">Re-record</button>
        <button type="button" id="publicChatDeleteVoiceDraft" class="public-chat-secondary">Delete</button>
      </div>
    `;
    document.getElementById("publicChatSendVoiceDraft")?.addEventListener("click", sendVoiceDraft, { once: true });
    document.getElementById("publicChatRedoVoiceDraft")?.addEventListener("click", redoVoiceDraft, { once: true });
    document.getElementById("publicChatDeleteVoiceDraft")?.addEventListener("click", clearVoiceDraft, { once: true });
  }

  async function sendVoiceDraft() {
    if (!state.voiceDraft || !state.session || state.sendingVoice) return;
    state.sendingVoice = true;
    const form = new FormData();
    form.append("session_id", state.session.session_id);
    form.append("token", state.session.token);
    form.append("kind", "voice");
    form.append("attachment", state.voiceDraft.file, state.voiceDraft.file.name || "voice-note.webm");
    const data = await fetchJson(api("public_chat_upload.php"), { method: "POST", body: form }).catch((error) => ({ success: false, message: error.message }));
    if (data.success) {
      clearVoiceDraft();
      addMessages(data.message ? [data.message] : []);
      poll();
    } else {
      showChatFeedback(data.message || "Unable to send voice note.", "error");
      renderVoiceDraft();
    }
    state.sendingVoice = false;
  }

  function redoVoiceDraft() {
    clearVoiceDraft();
    toggleVoiceRecording();
  }

  function clearVoiceDraft() {
    if (state.voiceDraft?.url) URL.revokeObjectURL(state.voiceDraft.url);
    state.voiceDraft = null;
    const draft = document.getElementById("publicChatVoiceDraft");
    if (draft) {
      draft.hidden = true;
      draft.innerHTML = "";
    }
  }

  function cleanupVoiceDraft() {
    cancelRecording();
    clearVoiceDraft();
  }

  async function endChat() {
    if (!state.session) return;
    const data = await fetchJson(api("public_chat_end.php"), {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ session_id: state.session.session_id, token: state.session.token, reason: "Ended by visitor" })
    });
    if (data.success) {
      clearTimeout(state.pollTimer);
      document.getElementById("publicChatReply").hidden = true;
      await stopTyping(true);
      openFeedbackPopup();
    }
  }

  async function submitFeedback() {
    const feedbackSession = state.feedbackSession || state.session;
    if (!feedbackSession || !state.rating) return;
    const comments = document.getElementById("publicChatFeedbackComments")?.value || "";
    const data = await fetchJson(api("public_chat_feedback.php"), {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ session_id: feedbackSession.session_id, token: feedbackSession.token, rating: state.rating, comments })
    });
    if (data.success) {
      closeFeedbackPopup();
      showChatFeedback("Thank you. Your feedback has been recorded.", "success");
    } else {
      showChatFeedback(data.message || "Unable to submit feedback.", "error");
    }
  }

  document.addEventListener("DOMContentLoaded", async () => {
    try {
      const data = await fetchJson(api("public_chat_bootstrap.php"));
      if (!data.success || !allowedForPage(data.settings)) return;
      state.settings = data.settings;
      state.availability = data.availability;
      state.visitor = data.visitor;
      state.csrfToken = data.csrfToken || "";
      renderShell();
    } catch (_) {}
  });
})();
