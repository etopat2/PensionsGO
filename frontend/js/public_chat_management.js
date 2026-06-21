(function () {
  const api = (path) => `../backend/api/${path}`;
  const consoleViews = [
    ["queue", "Chat Queue"],
    ["active", "Active Chats"],
    ["assigned", "Assigned Chats"],
    ["offline", "Offline Messages"],
    ["tickets", "Tickets"],
    ["escalations", "Escalations"],
    ["canned", "Canned Responses"]
  ];
  const state = {
    allowed: false,
    stats: null,
    consoleView: "queue",
    selectedId: null,
    selected: null,
    selectedCanReply: false,
    heartbeatTimer: null,
    detailTimer: null,
    listTimer: null,
    typingTimer: null,
    typingStopTimer: null,
    notificationTimer: null,
    lastConsoleListHtml: "",
    lastDetailMessagesHtml: "",
    lastDetailMessageId: 0,
    notifiedChats: new Set(JSON.parse(localStorage.getItem("pensionsgo_public_chat_notified") || "[]")),
    actionResolver: null,
    transferAgents: [],
    sendingReply: false,
    uploadingAttachment: false,
    sendingVoice: false,
    agentOnline: false,
    mediaRecorder: null,
    recordingStream: null,
    voiceChunks: [],
    voiceStartedAt: 0,
    voiceTimer: null,
    voiceDraft: null,
    detailPolling: false,
    listPolling: false
  };

  function escapeHtml(value) {
    return String(value ?? "").replace(/[&<>"']/g, (char) => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#039;"
    }[char]));
  }

  async function fetchJson(path, payload = null, method = "POST") {
    const options = { credentials: "include", cache: "no-store" };
    let url = api(path);
    if (method === "GET") {
      url += payload ? `?${new URLSearchParams(payload).toString()}` : "";
    } else {
      options.method = method;
      options.headers = { "Content-Type": "application/json" };
      options.body = JSON.stringify(payload || {});
    }
    const response = await fetch(url, options);
    const data = await response.json().catch(() => ({ success: false, message: "The server returned an unreadable response." }));
    if (!response.ok && data && data.success !== true) {
      data.message = data.message || "The request could not be completed.";
    }
    return data;
  }

  function showToast(message, type = "info", title = "Public Chat") {
    if (typeof window.appToast === "function") {
      window.appToast(message, { type, title });
    }
  }

  function revealDashboardEntry() {
    document.getElementById("publicChatDashboardNavItem")?.removeAttribute("hidden");
    document.getElementById("publicChatDashboardMobileOption")?.removeAttribute("hidden");
    document.getElementById("publicChatDashboardAccessNotice")?.classList.add("hidden");
    document.getElementById("publicChatDashboardMount")?.removeAttribute("hidden");
  }

  function updateAvailabilityButton(availability) {
    const button = document.getElementById("publicChatAvailabilityBtn");
    if (!button) return;
    const agent = availability?.agent || {};
    state.agentOnline = Boolean(agent.online || agent.status === "online");
    button.textContent = state.agentOnline ? "Set Offline" : "Set Online";
    button.classList.toggle("btn-secondary", state.agentOnline);
    button.setAttribute("aria-pressed", String(state.agentOnline));
  }

  function statusForView(view = state.consoleView) {
    if (view === "queue") return "waiting";
    if (view === "active") return "active";
    if (view === "assigned") return "assigned";
    if (view === "offline") return "closed";
    return "";
  }

  function metricCard(key, label, value, hint) {
    return `
      <button type="button" class="analytics-stat-card public-chat-stat-link" data-public-chat-analytics="${escapeHtml(key)}">
        <span>${escapeHtml(label)}</span>
        <strong>${escapeHtml(value)}</strong>
        <small>${escapeHtml(hint || "Open records")}</small>
      </button>
    `;
  }

  function renderDashboardShell() {
    const mount = document.getElementById("publicChatDashboardMount");
    if (!mount) return;
    mount.innerHTML = `
      <div class="public-chat-dashboard-toolbar">
        <button type="button" class="btn-action" id="publicChatOpenConsoleBtn">Chat Console</button>
      </div>
      <div class="analytics-stat-grid public-chat-dashboard-stats" id="publicChatDashboardStats"></div>
      <div class="dashboard-data-modal public-chat-console-modal" id="publicChatConsoleModal" aria-hidden="true">
        <div class="dashboard-data-modal-backdrop" data-public-chat-console-close="1"></div>
        <div class="dashboard-data-modal-panel public-chat-console-panel" role="dialog" aria-modal="true" aria-labelledby="publicChatConsoleTitle">
          <div class="dashboard-data-modal-header">
            <div>
              <p class="dashboard-data-modal-eyebrow">Public Correspondence</p>
              <h3 id="publicChatConsoleTitle">Chat Console</h3>
            </div>
            <button type="button" class="dashboard-data-modal-close" data-public-chat-console-close="1">Close</button>
          </div>
          <div class="public-chat-console-shell">
            <aside class="public-chat-console-sidebar" aria-label="Public chat console views">
              ${consoleViews.map(([key, label]) => `<button type="button" class="${key === state.consoleView ? "active" : ""}" data-public-chat-console-view="${key}">${escapeHtml(label)}</button>`).join("")}
            </aside>
            <main class="public-chat-console-workspace">
              <div class="public-chat-console-list-wrap">
                <div class="analytics-panel-header">
                  <h3 id="publicChatConsoleListTitle">Chat Queue</h3>
                  <p id="publicChatConsoleListHelp">Waiting public support correspondences.</p>
                </div>
                <div id="publicChatConsoleList" class="public-chat-dashboard-list"></div>
              </div>
              <section class="public-chat-console-detail">
                <div class="analytics-panel-header">
                  <h3 id="publicChatDashboardDetailTitle">Conversation</h3>
                  <p id="publicChatDashboardDetailMeta">Select a chat to view visitor details and transcript.</p>
                </div>
                <div class="public-chat-dashboard-actions">
                  <button type="button" class="btn-action btn-secondary" id="publicChatDashboardAcceptBtn" disabled>Accept</button>
                  <button type="button" class="btn-action btn-secondary" id="publicChatDashboardTransferBtn" disabled>Transfer</button>
                  <button type="button" class="btn-action btn-secondary" id="publicChatDashboardPensionerBtn" hidden disabled>Pensioner Reference</button>
                  <button type="button" class="btn-action btn-secondary" id="publicChatDashboardEscalateBtn" disabled>Escalate</button>
                  <button type="button" class="btn-action btn-secondary" id="publicChatDashboardTicketBtn" disabled>Create Ticket</button>
                  <button type="button" class="btn-action btn-danger" id="publicChatDashboardCloseBtn" disabled>Close</button>
                </div>
                <div class="public-chat-dashboard-visitor" id="publicChatDashboardVisitor"></div>
                <div class="chat-oversight-thread public-chat-dashboard-thread" id="publicChatDashboardMessages"></div>
                <div class="public-chat-peer-typing" id="publicChatDashboardTyping" hidden><span></span><span></span><span></span><strong>Visitor is typing</strong></div>
                <form id="publicChatDashboardReplyForm" class="public-chat-dashboard-form">
                  <div class="public-chat-dashboard-voice-draft" id="publicChatDashboardVoiceDraft" hidden></div>
                  <div class="public-chat-dashboard-composer">
                    <textarea id="publicChatDashboardReplyText" rows="3" placeholder="Reply to visitor" disabled></textarea>
                    <button type="button" class="public-chat-dashboard-composer-icon attach" id="publicChatDashboardAttachBtn" title="Attach file" aria-label="Attach file" disabled>+</button>
                    <button type="button" class="public-chat-dashboard-composer-icon voice" id="publicChatDashboardVoiceBtn" title="Record voice note" aria-label="Record voice note" disabled><span class="public-chat-mic-icon" aria-hidden="true"></span></button>
                  </div>
                  <input type="file" id="publicChatDashboardAttachment" hidden accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                  <button type="submit" class="btn-action" id="publicChatDashboardSendBtn" disabled>Send Reply</button>
                </form>
                <form id="publicChatDashboardNoteForm" class="public-chat-dashboard-form">
                  <textarea id="publicChatDashboardNoteText" rows="2" placeholder="Internal note" disabled></textarea>
                  <button type="submit" class="btn-action btn-secondary" id="publicChatDashboardNoteBtn" disabled>Add Note</button>
                </form>
                <div class="public-chat-dashboard-notes" id="publicChatDashboardNotes"></div>
              </section>
            </main>
          </div>
        </div>
      </div>
      <div class="dashboard-data-modal" id="publicChatDashboardReferenceModal" aria-hidden="true">
        <div class="dashboard-data-modal-backdrop" data-public-chat-reference-close="1"></div>
        <div class="dashboard-data-modal-panel" role="dialog" aria-modal="true" aria-labelledby="publicChatDashboardReferenceTitle">
          <div class="dashboard-data-modal-header">
            <div>
              <p class="dashboard-data-modal-eyebrow">Public Chat Reference</p>
              <h3 id="publicChatDashboardReferenceTitle">Pensioner Reference</h3>
            </div>
            <button type="button" class="dashboard-data-modal-close" data-public-chat-reference-close="1">Close</button>
          </div>
          <div class="dashboard-data-modal-body" id="publicChatDashboardReferenceBody"></div>
        </div>
      </div>
      <div class="dashboard-data-modal public-chat-analytics-modal" id="publicChatAnalyticsModal" aria-hidden="true">
        <div class="dashboard-data-modal-backdrop" data-public-chat-analytics-close="1"></div>
        <div class="dashboard-data-modal-panel public-chat-analytics-panel" role="dialog" aria-modal="true" aria-labelledby="publicChatAnalyticsTitle">
          <div class="dashboard-data-modal-header">
            <div>
              <p class="dashboard-data-modal-eyebrow">Analytics Records</p>
              <h3 id="publicChatAnalyticsTitle">Public Live Chat Records</h3>
            </div>
            <button type="button" class="dashboard-data-modal-close" data-public-chat-analytics-close="1">Close</button>
          </div>
          <div class="dashboard-data-modal-body" id="publicChatAnalyticsBody"></div>
        </div>
      </div>
      <div class="dashboard-data-modal public-chat-action-modal" id="publicChatActionModal" aria-hidden="true">
        <div class="dashboard-data-modal-backdrop" data-public-chat-action-cancel="1"></div>
        <div class="dashboard-data-modal-panel public-chat-action-panel" role="dialog" aria-modal="true" aria-labelledby="publicChatActionTitle">
          <form id="publicChatActionForm">
            <div class="dashboard-data-modal-header">
              <div>
                <p class="dashboard-data-modal-eyebrow">Chat Console Action</p>
                <h3 id="publicChatActionTitle">Confirm Action</h3>
              </div>
              <button type="button" class="dashboard-data-modal-close" data-public-chat-action-cancel="1">Close</button>
            </div>
            <div class="dashboard-data-modal-body">
              <p id="publicChatActionMessage" class="public-chat-action-message"></p>
              <div id="publicChatActionFields" class="public-chat-action-fields"></div>
              <div id="publicChatActionError" class="public-chat-action-error" hidden></div>
            </div>
            <div class="dashboard-data-modal-footer">
              <button type="button" class="btn-action btn-secondary" data-public-chat-action-cancel="1">Cancel</button>
              <button type="button" class="btn-action" id="publicChatActionSubmitBtn">Continue</button>
            </div>
          </form>
        </div>
      </div>
      <div class="dashboard-data-modal public-chat-document-modal" id="publicChatDocumentModal" aria-hidden="true">
        <div class="dashboard-data-modal-backdrop" data-public-chat-document-close="1"></div>
        <div class="dashboard-data-modal-panel public-chat-document-panel" role="dialog" aria-modal="true" aria-labelledby="publicChatDocumentTitle">
          <div class="dashboard-data-modal-header">
            <div>
              <p class="dashboard-data-modal-eyebrow">Secure Public Chat Document Viewer</p>
              <h3 id="publicChatDocumentTitle">Attachment</h3>
            </div>
            <button type="button" class="dashboard-data-modal-close" data-public-chat-document-close="1">Close</button>
          </div>
          <div class="dashboard-data-modal-body public-chat-document-body" id="publicChatDocumentBody"></div>
          <div class="dashboard-data-modal-footer">
            <a class="btn-action btn-secondary" id="publicChatDocumentDownload" href="#" target="_blank" rel="noopener">Download</a>
          </div>
        </div>
      </div>
    `;

    document.getElementById("publicChatOpenConsoleBtn")?.addEventListener("click", openConsole);
    document.getElementById("publicChatConsoleList")?.addEventListener("click", (event) => {
      const button = event.target.closest("[data-public-chat-session]");
      if (button) selectConsoleSession(button.dataset.publicChatSession);
      const canned = event.target.closest("[data-public-chat-canned-body]");
      if (canned) {
        const reply = document.getElementById("publicChatDashboardReplyText");
        if (reply) reply.value = canned.dataset.publicChatCannedBody || "";
      }
    });
    document.querySelectorAll("[data-public-chat-console-view]").forEach((button) => {
      button.addEventListener("click", () => {
        state.consoleView = button.dataset.publicChatConsoleView || "queue";
        state.selectedId = null;
        state.selected = null;
        state.selectedCanReply = false;
        cleanupAgentVoiceDraft();
        state.lastConsoleListHtml = "";
        renderConsoleSidebarState();
        resetDetail();
        loadConsoleWorkspace({ silent: false });
      });
    });
    document.querySelectorAll("[data-public-chat-console-close]").forEach((button) => button.addEventListener("click", closeConsole));
    document.querySelectorAll("[data-public-chat-reference-close]").forEach((button) => button.addEventListener("click", closePensionerReference));
    document.querySelectorAll("[data-public-chat-analytics-close]").forEach((button) => button.addEventListener("click", closeAnalyticsModal));
    document.getElementById("publicChatDashboardAcceptBtn")?.addEventListener("click", () => runConsoleAction("accept"));
    document.getElementById("publicChatDashboardTransferBtn")?.addEventListener("click", () => runConsoleAction("transfer"));
    document.getElementById("publicChatDashboardEscalateBtn")?.addEventListener("click", () => runConsoleAction("escalate"));
    document.getElementById("publicChatDashboardTicketBtn")?.addEventListener("click", () => runConsoleAction("ticket"));
    document.getElementById("publicChatDashboardCloseBtn")?.addEventListener("click", () => runConsoleAction("close"));
    document.getElementById("publicChatDashboardPensionerBtn")?.addEventListener("click", showPensionerReference);
    document.getElementById("publicChatDashboardReplyForm")?.addEventListener("submit", sendReply);
    document.getElementById("publicChatDashboardNoteForm")?.addEventListener("submit", addNote);
    document.getElementById("publicChatDashboardReplyText")?.addEventListener("input", notifyTyping);
    document.getElementById("publicChatDashboardReplyText")?.addEventListener("blur", () => stopTyping(false));
    document.getElementById("publicChatDashboardAttachBtn")?.addEventListener("click", () => document.getElementById("publicChatDashboardAttachment")?.click());
    document.getElementById("publicChatDashboardAttachment")?.addEventListener("change", uploadAgentAttachment);
    document.getElementById("publicChatDashboardVoiceBtn")?.addEventListener("click", toggleAgentVoiceRecording);
    document.getElementById("publicChatActionForm")?.addEventListener("submit", submitActionModal);
    document.getElementById("publicChatActionSubmitBtn")?.addEventListener("click", handleActionModalSubmitClick);
    document.querySelectorAll("[data-public-chat-action-cancel]").forEach((button) => button.addEventListener("click", closeActionModal));
    document.querySelectorAll("[data-public-chat-document-close]").forEach((button) => button.addEventListener("click", closeDocumentViewer));
    document.getElementById("publicChatDashboardMessages")?.addEventListener("click", (event) => {
      const button = event.target.closest("[data-public-chat-view-attachment]");
      if (button) openDocumentViewer(button.dataset.publicChatViewAttachment, button.dataset.publicChatDownloadAttachment, button.dataset.publicChatAttachmentName, button.dataset.publicChatAttachmentMime);
    });
  }

  async function loadStats() {
    const grid = document.getElementById("publicChatDashboardStats");
    if (!grid) return;
    try {
      const data = await fetchJson("public_chat_agent.php", { action: "stats" }, "GET");
      if (!data.success) throw new Error(data.message || "Stats unavailable");
      state.stats = data.stats || {};
      const s = state.stats;
      grid.innerHTML = [
        metricCard("today", "Chats Today", s.totalToday || 0, "Today's records"),
        metricCard("week", "This Week", s.totalWeek || 0, "Week records"),
        metricCard("month", "This Month", s.totalMonth || 0, "Month records"),
        metricCard("waiting", "Waiting", s.waiting || 0, "Queue"),
        metricCard("active", "Active", s.active || 0, "Active and assigned"),
        metricCard("escalated", "Escalated", s.escalated || 0, "Escalations"),
        metricCard("tickets", "Tickets", s.ticketsCreated || 0, "Ticket records"),
        metricCard("feedback", "Feedback Avg", s.feedbackAverageRating || 0, "Feedback summary")
      ].join("");
      grid.querySelectorAll("[data-public-chat-analytics]").forEach((card) => {
        card.addEventListener("click", () => openAnalyticsModal(card.dataset.publicChatAnalytics));
      });
    } catch (error) {
      grid.innerHTML = `<div class="dashboard-empty-message">Public chat reports are restricted for this account.</div>`;
    }
  }

  async function refreshAgentAvailability() {
    const data = await fetchJson("public_chat_availability.php", null, "GET").catch(() => null);
    if (data?.success) updateAvailabilityButton(data.availability);
  }

  function openConsole() {
    const modal = document.getElementById("publicChatConsoleModal");
    modal?.classList.add("open");
    modal?.setAttribute("aria-hidden", "false");
    if (state.agentOnline) fetchJson("public_chat_agent.php", { action: "heartbeat" }).catch(() => {});
    state.lastConsoleListHtml = "";
    loadConsoleWorkspace({ silent: false });
    startListPolling();
  }

  function closeConsole() {
    const modal = document.getElementById("publicChatConsoleModal");
    modal?.classList.remove("open");
    modal?.setAttribute("aria-hidden", "true");
    clearInterval(state.detailTimer);
    clearInterval(state.listTimer);
    cleanupAgentVoiceDraft();
    stopTyping(false);
  }

  function sendOfflineBeacon() {
    if (!state.allowed || !state.agentOnline) return;
    const payload = JSON.stringify({ action: "status", agent_status: "offline" });
    try {
      navigator.sendBeacon?.(api("public_chat_agent.php"), new Blob([payload], { type: "application/json" }));
    } catch (_) {}
  }

  function renderConsoleSidebarState() {
    document.querySelectorAll("[data-public-chat-console-view]").forEach((button) => {
      button.classList.toggle("active", button.dataset.publicChatConsoleView === state.consoleView);
    });
  }

  function normalizeSessionId(value) {
    const id = Number.parseInt(String(value ?? "").replace(/[^\d]/g, ""), 10);
    return Number.isFinite(id) && id > 0 ? String(id) : "";
  }

  function markSelectedConsoleRow(sessionId) {
    const normalizedId = normalizeSessionId(sessionId);
    document.querySelectorAll("[data-public-chat-session]").forEach((row) => {
      row.classList.toggle("active", normalizeSessionId(row.dataset.publicChatSession) === normalizedId);
      row.setAttribute("aria-selected", normalizeSessionId(row.dataset.publicChatSession) === normalizedId ? "true" : "false");
    });
  }

  function selectConsoleSession(sessionId) {
    const normalizedId = normalizeSessionId(sessionId);
    if (!normalizedId) return;
    if (normalizeSessionId(state.selectedId) !== normalizedId) cleanupAgentVoiceDraft();
    state.selectedId = normalizedId;
    state.lastDetailMessagesHtml = "";
    state.lastDetailMessageId = 0;
    state.selectedCanReply = false;
    markSelectedConsoleRow(normalizedId);
    loadDetail(normalizedId).catch((error) => showActionError(error.message || "Unable to load selected chat."));
  }

  function sessionRow(item) {
    const active = normalizeSessionId(item.session_id) === normalizeSessionId(state.selectedId) ? " active" : "";
    return `
      <button type="button" class="public-chat-dashboard-row${active}" data-public-chat-session="${escapeHtml(item.session_id)}" aria-selected="${active ? "true" : "false"}">
        <strong>${escapeHtml(item.chat_reference || "Public chat")}</strong>
        <span>${escapeHtml(item.visitor_name || "Visitor")} - ${escapeHtml(item.inquiry_category || "General inquiry")}</span>
        <small>${escapeHtml(item.district || "No district")} - ${escapeHtml(item.status || "waiting")} - ${escapeHtml(item.last_message_at || item.created_at || "")}</small>
      </button>
    `;
  }

  async function loadConsoleWorkspace(options = {}) {
    const silent = options.silent === true;
    const title = document.getElementById("publicChatConsoleListTitle");
    const help = document.getElementById("publicChatConsoleListHelp");
    const list = document.getElementById("publicChatConsoleList");
    if (!list) return;
    const label = consoleViews.find(([key]) => key === state.consoleView)?.[1] || "Chat Queue";
    if (title) title.textContent = label;
    if (help) help.textContent = state.consoleView === "offline" ? "Offline public support requests and follow-up messages." : "Select a record to continue handling it.";
    if (!silent) {
      list.innerHTML = `<div class="dashboard-empty-message">Loading ${escapeHtml(label.toLowerCase())}...</div>`;
    }

    if (state.consoleView === "tickets") {
      const data = await fetchJson("public_chat_agent.php", { action: "tickets" }, "GET");
      updateConsoleList(data.success ? renderRecords(data.tickets || [], ["ticket_reference", "status", "subject", "visitor_name"]) : `<div class="dashboard-empty-message">${escapeHtml(data.message || "Unable to load tickets.")}</div>`);
      return;
    }
    if (state.consoleView === "escalations") {
      const data = await fetchJson("public_chat_agent.php", { action: "escalations" }, "GET");
      updateConsoleList(data.success ? renderRecords(data.escalations || [], ["chat_reference", "priority", "reason", "escalated_by_name"]) : `<div class="dashboard-empty-message">${escapeHtml(data.message || "Unable to load escalations.")}</div>`);
      return;
    }
    if (state.consoleView === "canned") {
      const data = await fetchJson("public_chat_agent.php", { action: "canned" }, "GET");
      updateConsoleList(data.success ? renderCannedResponses(data.responses || []) : `<div class="dashboard-empty-message">${escapeHtml(data.message || "Unable to load canned responses.")}</div>`);
      return;
    }

    const data = await fetchJson("public_chat_agent.php", { action: "list", status: statusForView() }, "GET");
    if (!data.success) {
      updateConsoleList(`<div class="dashboard-empty-message">${escapeHtml(data.message || "Unable to load chats.")}</div>`);
      return;
    }
    let sessions = data.sessions || [];
    if (state.consoleView === "offline") {
      sessions = sessions.filter((item) => String(item.close_reason || "").includes("Offline") || item.status === "closed");
    }
    updateConsoleList(sessions.length ? sessions.map(sessionRow).join("") : `<div class="dashboard-empty-message">No records found.</div>`);
  }

  function updateConsoleList(html) {
    const list = document.getElementById("publicChatConsoleList");
    if (!list || state.lastConsoleListHtml === html) return;
    state.lastConsoleListHtml = html;
    list.innerHTML = html;
    markSelectedConsoleRow(state.selectedId);
  }

  function renderRecords(rows, fields) {
    return rows.length ? rows.map((row) => `
      <article class="public-chat-dashboard-row as-record">
        <strong>${escapeHtml(row[fields[0]] || "Record")}</strong>
        <span>${escapeHtml(row[fields[1]] || "")} - ${escapeHtml(row[fields[2]] || "")}</span>
        <small>${escapeHtml(row[fields[3]] || row.created_at || "")}</small>
      </article>
    `).join("") : `<div class="dashboard-empty-message">No records found.</div>`;
  }

  function renderCannedResponses(rows) {
    return rows.length ? rows.map((row) => `
      <button type="button" class="public-chat-dashboard-row" data-public-chat-canned="${escapeHtml(row.response_id)}" data-public-chat-canned-body="${escapeHtml(row.body || "")}">
        <strong>${escapeHtml(row.title || "Quick Reply")}</strong>
        <span>${escapeHtml(row.inquiry_category || "General")}</span>
        <small>${escapeHtml(row.body || "")}</small>
      </button>
    `).join("") : `<div class="dashboard-empty-message">No canned responses found.</div>`;
  }

  function resetDetail() {
    document.getElementById("publicChatDashboardDetailTitle").textContent = "Conversation";
    document.getElementById("publicChatDashboardDetailMeta").textContent = "Select a chat to view visitor details and transcript.";
    document.getElementById("publicChatDashboardVisitor").innerHTML = "";
    document.getElementById("publicChatDashboardMessages").innerHTML = "";
    state.lastDetailMessagesHtml = "";
    state.lastDetailMessageId = 0;
    document.getElementById("publicChatDashboardNotes").innerHTML = "";
    hideDashboardTyping();
    setDetailEnabled(false);
    const refBtn = document.getElementById("publicChatDashboardPensionerBtn");
    if (refBtn) {
      refBtn.hidden = true;
      refBtn.disabled = true;
    }
  }

  function setDetailEnabled(enabled, canReply = enabled) {
    ["AcceptBtn", "TransferBtn", "EscalateBtn", "TicketBtn", "CloseBtn", "NoteText", "NoteBtn"].forEach((suffix) => {
      const el = document.getElementById(`publicChatDashboard${suffix}`);
      if (el) el.disabled = !enabled;
    });
    ["ReplyText", "SendBtn", "AttachBtn", "VoiceBtn"].forEach((suffix) => {
      const el = document.getElementById(`publicChatDashboard${suffix}`);
      if (el) el.disabled = !canReply;
    });
  }

  async function loadDetail(sessionId, options = {}) {
    const normalizedId = normalizeSessionId(sessionId);
    if (!normalizedId) return;
    const refreshList = options.refreshList !== false;
    const restartPolling = options.restartPolling !== false;
    const requestedId = normalizedId;
    const data = await fetchJson("public_chat_agent.php", { action: "detail", session_id: requestedId }, "GET");
    if (!data.success) {
      if (normalizeSessionId(state.selectedId) === requestedId) {
        resetDetail();
        showActionError(data.message || "Unable to load selected chat.");
      }
      return;
    }
    if (normalizeSessionId(state.selectedId) !== requestedId) return;
    if (String(state.selected?.session?.session_id || "") !== requestedId) {
      state.lastDetailMessagesHtml = "";
      state.lastDetailMessageId = 0;
    }
    state.selectedId = requestedId;
    state.selected = data;
    state.selectedCanReply = Boolean(data.canReply);
    markSelectedConsoleRow(requestedId);
    const session = data.session || {};
    document.getElementById("publicChatDashboardDetailTitle").textContent = session.chat_reference || "Conversation";
    document.getElementById("publicChatDashboardDetailMeta").textContent = `${session.visitor_name || "Visitor"} - ${session.inquiry_category || "General inquiry"} - ${session.district || "No district"}`;
    document.getElementById("publicChatDashboardVisitor").innerHTML = `
      <span><strong>Phone:</strong> ${escapeHtml(session.phone_number || "N/A")}</span>
      <span><strong>Email:</strong> ${escapeHtml(session.email || "N/A")}</span>
      <span><strong>Force No:</strong> ${escapeHtml(session.force_number || "N/A")}</span>
      <span><strong>Pensioner No:</strong> ${escapeHtml(session.pensioner_number || "N/A")}</span>
      <span><strong>Source:</strong> ${escapeHtml(session.source_page || "N/A")}</span>
      <span><strong>Priority:</strong> ${escapeHtml(session.priority || "normal")}</span>
    `;
    renderDashboardMessages(data.messages || [], { replace: true });
    document.getElementById("publicChatDashboardNotes").innerHTML = (data.notes || []).map((note) => `
      <div class="public-chat-dashboard-note"><strong>${escapeHtml(note.agent_name || "Staff")}</strong><p>${escapeHtml(note.note_text || "")}</p><small>${escapeHtml(note.created_at || "")}</small></div>
    `).join("");
    renderDashboardTyping(data.typing || []);
    const hasPensioner = Boolean(data.pensionerContext && data.pensionerContext.matched);
    const refBtn = document.getElementById("publicChatDashboardPensionerBtn");
    if (refBtn) {
      refBtn.hidden = !hasPensioner;
      refBtn.disabled = !hasPensioner;
    }
    setDetailEnabled(session.status !== "closed", state.selectedCanReply);
    if (refreshList) loadConsoleWorkspace({ silent: true });
    if (restartPolling) startDetailPolling();
  }

  function startDetailPolling() {
    clearInterval(state.detailTimer);
    state.detailTimer = setInterval(() => {
      const consoleOpen = document.getElementById("publicChatConsoleModal")?.classList.contains("open");
      if (!state.selectedId || !consoleOpen || document.hidden || state.sendingReply) return;
      pollDetailMessages().catch(() => {});
    }, 1200);
  }

  async function pollDetailMessages() {
    const selectedId = normalizeSessionId(state.selectedId);
    if (!selectedId || state.detailPolling) return;
    state.detailPolling = true;
    try {
      const data = await fetchJson("public_chat_poll.php", { session_id: selectedId, as_agent: true, last_id: state.lastDetailMessageId || 0 }, "GET");
      if (!data.success) {
        if (normalizeSessionId(state.selectedId) === selectedId) {
          state.selectedCanReply = false;
          setDetailEnabled(false, false);
          hideDashboardTyping();
        }
        return;
      }
      if (normalizeSessionId(state.selectedId) !== selectedId) return;
      state.selectedCanReply = Boolean(data.canReply);
      renderDashboardMessages(data.messages || [], { replace: false });
      renderDashboardTyping(data.typing || []);
      if (data.session?.status === "closed") {
        state.selectedCanReply = false;
        setDetailEnabled(false, false);
      } else {
        setDetailEnabled(true, state.selectedCanReply);
      }
    } finally {
      state.detailPolling = false;
    }
  }

  function startListPolling() {
    clearInterval(state.listTimer);
    state.listTimer = setInterval(() => {
      const consoleOpen = document.getElementById("publicChatConsoleModal")?.classList.contains("open");
      if (!consoleOpen || document.hidden) return;
      if (state.listPolling) return;
      state.listPolling = true;
      loadConsoleWorkspace({ silent: true }).catch(() => {}).finally(() => {
        state.listPolling = false;
      });
    }, 5000);
  }

  async function sessionAction(action, extra = {}) {
    if (!state.selectedId) return false;
    const data = await fetchJson("public_chat_agent.php", { action, session_id: state.selectedId, ...extra });
    if (!data.success) {
      showActionError(data.message || "Action failed.");
      return false;
    }
    await loadDetail(state.selectedId);
    await loadStats();
    return true;
  }

  async function runConsoleAction(action) {
    if (!state.selectedId) return;
    const selectedSubject = state.selected?.session?.subject || "Public chat follow-up";
    if (action === "transfer" && !state.transferAgents.length) {
      const data = await fetchJson("public_chat_agent.php", { action: "transfer_agents" }, "GET").catch(() => null);
      state.transferAgents = data?.success ? (data.agents || []) : [];
      if (!state.transferAgents.length) {
        showActionError(data?.message || "No enabled public chat handlers are available for transfer.");
        return;
      }
    }
    const configs = {
      accept: {
        title: "Accept Chat",
        message: "Accept this public live chat and mark it as assigned to you?",
        submitText: "Accept Chat",
        fields: []
      },
      transfer: {
        title: "Transfer Chat",
        message: "Choose the permitted public chat handler who should receive this correspondence.",
        submitText: "Transfer",
        fields: [{
          name: "agent_user_id",
          label: "Target agent",
          type: "select",
          required: true,
          options: state.transferAgents.map((agent) => ({
            value: agent.userId,
            label: `${agent.userName || "Unnamed agent"} - ${formatRole(agent.userRole || agent.roleLabel)}`
          }))
        }]
      },
      escalate: {
        title: "Escalate Chat",
        message: "Record the reason for escalation. The case will be marked for supervisory attention.",
        submitText: "Escalate",
        fields: [{ name: "reason", label: "Escalation reason", type: "textarea", required: true }]
      },
      ticket: {
        title: "Create Ticket",
        message: "Create a follow-up ticket from this public chat.",
        submitText: "Create Ticket",
        fields: [
          { name: "subject", label: "Ticket subject", type: "text", value: selectedSubject, required: true },
          { name: "description", label: "Ticket description", type: "textarea", value: selectedSubject, required: false }
        ]
      },
      close: {
        title: "Close Chat",
        message: "Close this public live chat and record the outcome.",
        submitText: "Close Chat",
        fields: [
          { name: "reason", label: "Close reason", type: "textarea", value: "Resolved", required: true },
          { name: "outcome", label: "Outcome", type: "text", value: "Resolved", required: false }
        ]
      }
    };
    const values = await openActionModal(configs[action]);
    if (!values) return;
    const ok = await sessionAction(action, values);
    if (ok) {
      closeActionModal();
      showToast(`${configs[action].submitText || "Action"} completed.`, "success");
    }
  }

  function openActionModal(config) {
    const modal = document.getElementById("publicChatActionModal");
    const form = document.getElementById("publicChatActionForm");
    const title = document.getElementById("publicChatActionTitle");
    const message = document.getElementById("publicChatActionMessage");
    const fields = document.getElementById("publicChatActionFields");
    const submit = document.getElementById("publicChatActionSubmitBtn");
    const error = document.getElementById("publicChatActionError");
    if (!modal || !form || !config) return Promise.resolve(null);
    title.textContent = config.title || "Confirm Action";
    message.textContent = config.message || "";
    submit.textContent = config.submitText || "Continue";
    if (error) {
      error.hidden = true;
      error.textContent = "";
    }
    fields.innerHTML = (config.fields || []).map((field) => `
      <label class="public-chat-action-field">
        <span>${escapeHtml(field.label || field.name)}</span>
        ${field.type === "select"
          ? `<select name="${escapeHtml(field.name)}" ${field.required ? "required" : ""}><option value="">Select agent</option>${(field.options || []).map((option) => `<option value="${escapeHtml(option.value)}">${escapeHtml(option.label)}</option>`).join("")}</select>`
          : field.type === "textarea"
          ? `<textarea name="${escapeHtml(field.name)}" rows="4" ${field.required ? "required" : ""}>${escapeHtml(field.value || "")}</textarea>`
          : `<input name="${escapeHtml(field.name)}" type="${escapeHtml(field.type || "text")}" value="${escapeHtml(field.value || "")}" ${field.required ? "required" : ""}>`}
      </label>
    `).join("");
    modal.classList.add("open");
    modal.setAttribute("aria-hidden", "false");
    return new Promise((resolve) => {
      state.actionResolver = resolve;
      window.setTimeout(() => fields.querySelector("input, textarea, select")?.focus() || submit.focus(), 40);
    });
  }

  function formatRole(role) {
    return String(role || "User")
      .replace(/_/g, " ")
      .split(/\s+/)
      .filter(Boolean)
      .map((part) => part.charAt(0).toUpperCase() + part.slice(1).toLowerCase())
      .join(" ");
  }

  function submitActionModal(event) {
    event.preventDefault();
    const form = event.currentTarget || document.getElementById("publicChatActionForm");
    if (!form) return;
    const values = Object.fromEntries(new FormData(form).entries());
    if (state.actionResolver) {
      const resolve = state.actionResolver;
      state.actionResolver = null;
      resolve(values);
    }
  }

  function handleActionModalSubmitClick(event) {
    event.preventDefault();
    const form = document.getElementById("publicChatActionForm");
    if (!form) return;
    if (typeof form.reportValidity === "function" && !form.reportValidity()) return;
    submitActionModal({ preventDefault() {}, currentTarget: form });
  }

  function closeActionModal() {
    const modal = document.getElementById("publicChatActionModal");
    modal?.classList.remove("open");
    modal?.setAttribute("aria-hidden", "true");
    if (state.actionResolver) {
      const resolve = state.actionResolver;
      state.actionResolver = null;
      resolve(null);
    }
  }

  function showActionError(message) {
    const modal = document.getElementById("publicChatActionModal");
    const error = document.getElementById("publicChatActionError");
    if (modal && error && modal.classList.contains("open")) {
      error.textContent = message;
      error.hidden = false;
      return;
    }
    openActionModal({
      title: "Action Failed",
      message,
      submitText: "Close",
      fields: []
    }).then(() => closeActionModal());
  }

  async function sendReply(event) {
    event.preventDefault();
    const text = document.getElementById("publicChatDashboardReplyText");
    const message = text?.value.trim();
    if (!message || !state.selectedId || !state.selectedCanReply || state.sendingReply) return;
    const sendBtn = document.getElementById("publicChatDashboardSendBtn");
    if (sendBtn) sendBtn.disabled = true;
    const pendingNode = appendDashboardMessage({ sender_type: "agent", sender_name: "You", message_text: message, created_at: "Sending..." }, true);
    text.value = "";
    await stopTyping(true);
    state.sendingReply = true;
    const data = await fetchJson("public_chat_send.php", { session_id: state.selectedId, message, as_agent: true }).catch((error) => ({ success: false, message: error.message || "Unable to send reply." }));
    state.sendingReply = false;
    if (!data.success) {
      showActionError(data.message || "Unable to send reply.");
      pendingNode?.classList.add("failed");
      const status = pendingNode?.querySelector("small");
      if (status) status.textContent = "Not sent";
      if (text) text.value = message;
      if (sendBtn) sendBtn.disabled = !state.selectedCanReply;
      return;
    }
    if (sendBtn) sendBtn.disabled = !state.selectedCanReply;
    const messageId = Number(data.message_id || 0);
    if (messageId > 0 && pendingNode) {
      pendingNode.dataset.messageId = String(messageId);
      pendingNode.classList.remove("pending");
      const status = pendingNode.querySelector("small");
      if (status) status.textContent = "You - Sent";
      state.lastDetailMessageId = Math.max(state.lastDetailMessageId || 0, messageId);
    }
    showToast("Reply sent.", "success");
    pollDetailMessages().catch(() => {});
  }

  function appendDashboardMessage(msg, pending = false) {
    const wrap = document.getElementById("publicChatDashboardMessages");
    if (!wrap) return;
    wrap.querySelector(".dashboard-empty-message")?.remove();
    const id = Number(msg.message_id || 0);
    if (id > 0 && wrap.querySelector(`[data-message-id="${id}"]`)) return null;
    const node = document.createElement("div");
    node.className = `public-chat-dashboard-message ${msg.sender_type === "visitor" ? "visitor" : "agent"}${pending ? " pending" : ""}`;
    if (id > 0) node.dataset.messageId = String(id);
    node.innerHTML = `${renderDashboardMessageContent(msg)}<small>${escapeHtml(msg.sender_name || msg.sender_type || "")} - ${escapeHtml(msg.created_at || "")}</small>`;
    wrap.appendChild(node);
    wrap.scrollTop = wrap.scrollHeight;
    if (id > 0) state.lastDetailMessageId = Math.max(state.lastDetailMessageId || 0, id);
    return node;
  }

  function renderDashboardMessages(messages, options = {}) {
    const wrap = document.getElementById("publicChatDashboardMessages");
    if (!wrap) return;
    const rows = (messages || [])
      .filter((msg) => Number(msg.is_internal || 0) === 0)
      .sort((a, b) => Number(a.message_id || 0) - Number(b.message_id || 0));
    if (options.replace) {
      state.lastDetailMessageId = 0;
      wrap.innerHTML = "";
      if (!rows.length) {
        wrap.innerHTML = `<div class="dashboard-empty-message">No messages yet.</div>`;
        state.lastDetailMessagesHtml = wrap.innerHTML;
        return;
      }
    }
    wrap.querySelector(".dashboard-empty-message")?.remove();
    let changed = false;
    rows.forEach((msg) => {
      const id = Number(msg.message_id || 0);
      if (id > 0 && wrap.querySelector(`[data-message-id="${id}"]`)) {
        state.lastDetailMessageId = Math.max(state.lastDetailMessageId || 0, id);
        return;
      }
      const node = appendDashboardMessage(msg);
      if (node && id > 0) {
        node.dataset.messageId = String(id);
        state.lastDetailMessageId = Math.max(state.lastDetailMessageId || 0, id);
      }
      changed = true;
    });
    if (changed) {
      state.lastDetailMessagesHtml = wrap.innerHTML;
      wrap.scrollTop = wrap.scrollHeight;
    }
  }

  async function uploadAgentAttachment() {
    const input = document.getElementById("publicChatDashboardAttachment");
    if (!input?.files?.length) return;
    if (!state.selectedId || !state.selectedCanReply || state.uploadingAttachment) {
      input.value = "";
      return;
    }
    state.uploadingAttachment = true;
    const form = new FormData();
    form.append("session_id", state.selectedId);
    form.append("as_agent", "1");
    form.append("kind", "attachment");
    form.append("attachment", input.files[0]);
    input.value = "";
    const data = await fetch(api("public_chat_upload.php"), { method: "POST", credentials: "include", body: form })
      .then((response) => response.json())
      .catch((error) => ({ success: false, message: error.message || "Upload failed." }));
    if (!data.success) {
      showActionError(data.message || "Unable to upload attachment.");
      state.uploadingAttachment = false;
      return;
    }
    showToast("Attachment uploaded.", "success");
    if (data.message) appendDashboardMessage(data.message);
    pollDetailMessages().catch(() => {});
    state.uploadingAttachment = false;
  }

  function formatDuration(seconds) {
    const total = Math.max(0, Number(seconds || 0));
    return `${String(Math.floor(total / 60)).padStart(2, "0")}:${String(Math.floor(total % 60)).padStart(2, "0")}`;
  }

  async function toggleAgentVoiceRecording() {
    const btn = document.getElementById("publicChatDashboardVoiceBtn");
    if (state.mediaRecorder?.state === "recording") {
      state.mediaRecorder.stop();
      btn?.classList.remove("recording");
      btn?.setAttribute("aria-pressed", "false");
      return;
    }
    if (state.voiceDraft) {
      renderAgentVoiceDraft();
      return;
    }
    if (!state.selectedId || !state.selectedCanReply) return;
    if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) {
      showActionError("Voice recording is not supported by this browser.");
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
        stopAgentRecordingTimer();
        const blob = new Blob(state.voiceChunks, { type: "audio/webm" });
        state.voiceChunks = [];
        const file = new File([blob], `voice-note-${Date.now()}.webm`, { type: "audio/webm" });
        state.voiceDraft = {
          file,
          url: URL.createObjectURL(blob),
          duration: Math.max(1, Math.round((Date.now() - state.voiceStartedAt) / 1000))
        };
        state.mediaRecorder = null;
        renderAgentVoiceDraft();
      };
      state.mediaRecorder.start();
      btn?.classList.add("recording");
      btn?.setAttribute("aria-pressed", "true");
      startAgentRecordingTimer();
    } catch (_) {
      showActionError("Unable to access the microphone.");
    }
  }

  function startAgentRecordingTimer() {
    const draft = document.getElementById("publicChatDashboardVoiceDraft");
    if (!draft) return;
    draft.hidden = false;
    stopAgentRecordingTimer();
    const render = () => {
      const elapsed = Math.max(0, Math.floor((Date.now() - state.voiceStartedAt) / 1000));
      draft.innerHTML = `
        <div class="public-chat-voice-preview public-chat-recording-preview">
          <div class="public-chat-recording-status"><span></span><strong>Recording ${formatDuration(elapsed)}</strong></div>
        </div>
        <div class="public-chat-voice-actions">
          <button type="button" class="btn-action" id="publicChatDashboardStopRecording">Stop</button>
          <button type="button" class="btn-action btn-secondary" id="publicChatDashboardCancelRecording">Cancel</button>
        </div>
      `;
      document.getElementById("publicChatDashboardStopRecording")?.addEventListener("click", toggleAgentVoiceRecording, { once: true });
      document.getElementById("publicChatDashboardCancelRecording")?.addEventListener("click", cancelAgentRecording, { once: true });
    };
    render();
    state.voiceTimer = setInterval(render, 1000);
  }

  function stopAgentRecordingTimer() {
    if (state.voiceTimer) clearInterval(state.voiceTimer);
    state.voiceTimer = null;
  }

  function cancelAgentRecording() {
    if (state.mediaRecorder?.state === "recording") {
      state.mediaRecorder.onstop = null;
      state.mediaRecorder.stop();
    }
    state.recordingStream?.getTracks().forEach((track) => track.stop());
    state.recordingStream = null;
    state.mediaRecorder = null;
    state.voiceChunks = [];
    stopAgentRecordingTimer();
    document.getElementById("publicChatDashboardVoiceBtn")?.classList.remove("recording");
    document.getElementById("publicChatDashboardVoiceBtn")?.setAttribute("aria-pressed", "false");
    const draft = document.getElementById("publicChatDashboardVoiceDraft");
    if (draft) {
      draft.hidden = true;
      draft.innerHTML = "";
    }
  }

  function renderAgentVoiceDraft() {
    const draft = document.getElementById("publicChatDashboardVoiceDraft");
    if (!draft || !state.voiceDraft) return;
    draft.hidden = false;
    draft.innerHTML = `
      <div class="public-chat-voice-preview">
        <strong>Voice note ${formatDuration(state.voiceDraft.duration)}</strong>
        <audio controls src="${escapeHtml(state.voiceDraft.url)}"></audio>
      </div>
      <div class="public-chat-voice-actions">
        <button type="button" class="btn-action" id="publicChatDashboardSendVoiceDraft">Send</button>
        <button type="button" class="btn-action btn-secondary" id="publicChatDashboardRedoVoiceDraft">Re-record</button>
        <button type="button" class="btn-action btn-secondary" id="publicChatDashboardDeleteVoiceDraft">Delete</button>
      </div>
    `;
    document.getElementById("publicChatDashboardSendVoiceDraft")?.addEventListener("click", sendAgentVoiceDraft, { once: true });
    document.getElementById("publicChatDashboardRedoVoiceDraft")?.addEventListener("click", redoAgentVoiceDraft, { once: true });
    document.getElementById("publicChatDashboardDeleteVoiceDraft")?.addEventListener("click", clearAgentVoiceDraft, { once: true });
  }

  async function sendAgentVoiceDraft() {
    if (!state.voiceDraft || !state.selectedId || !state.selectedCanReply || state.sendingVoice) return;
    state.sendingVoice = true;
    const form = new FormData();
    form.append("session_id", state.selectedId);
    form.append("as_agent", "1");
    form.append("kind", "voice");
    form.append("attachment", state.voiceDraft.file, state.voiceDraft.file.name || "voice-note.webm");
    const data = await fetch(api("public_chat_upload.php"), { method: "POST", credentials: "include", body: form })
      .then((response) => response.json())
      .catch((error) => ({ success: false, message: error.message || "Unable to send voice note." }));
    if (!data.success) {
      showActionError(data.message || "Unable to send voice note.");
      renderAgentVoiceDraft();
      state.sendingVoice = false;
      return;
    }
    showToast("Voice note sent.", "success");
    clearAgentVoiceDraft();
    if (data.message) appendDashboardMessage(data.message);
    pollDetailMessages().catch(() => {});
    state.sendingVoice = false;
  }

  function redoAgentVoiceDraft() {
    clearAgentVoiceDraft();
    toggleAgentVoiceRecording();
  }

  function clearAgentVoiceDraft() {
    if (state.voiceDraft?.url) URL.revokeObjectURL(state.voiceDraft.url);
    state.voiceDraft = null;
    const draft = document.getElementById("publicChatDashboardVoiceDraft");
    if (draft) {
      draft.hidden = true;
      draft.innerHTML = "";
    }
  }

  function cleanupAgentVoiceDraft() {
    cancelAgentRecording();
    clearAgentVoiceDraft();
  }

  function formatFileSize(bytes) {
    const size = Number(bytes || 0);
    if (!size) return "";
    if (size < 1024) return `${size} B`;
    if (size < 1024 * 1024) return `${Math.round(size / 102.4) / 10} KB`;
    return `${Math.round(size / 104857.6) / 10} MB`;
  }

  function renderDashboardAttachment(att) {
    const name = escapeHtml(att.file_name || "Attachment");
    const size = formatFileSize(att.file_size);
    const url = `../backend/api/${att.view_url || ""}`;
    const download = `../backend/api/${att.download_url || att.view_url || ""}`;
    if (att.is_voice) {
      const mediaTag = String(att.mime_type || "").toLowerCase().startsWith("video/")
        ? `<video controls preload="metadata" src="${escapeHtml(url)}"></video>`
        : `<audio controls preload="metadata" src="${escapeHtml(url)}"></audio>`;
      return `
        <div class="public-chat-dashboard-attachment voice">
          <div><strong>Voice note</strong><span>${name}${size ? ` - ${escapeHtml(size)}` : ""}</span></div>
          ${mediaTag}
        </div>
      `;
    }
    return `
      <div class="public-chat-dashboard-attachment">
        <div><strong>${name}</strong><span>${escapeHtml(att.mime_type || "File")}${size ? ` - ${escapeHtml(size)}` : ""}</span></div>
        <div class="public-chat-dashboard-attachment-actions">
          <button type="button" data-public-chat-view-attachment="${escapeHtml(url)}" data-public-chat-download-attachment="${escapeHtml(download)}" data-public-chat-attachment-name="${name}" data-public-chat-attachment-mime="${escapeHtml(att.mime_type || "")}">View</button>
          <a href="${escapeHtml(download)}" target="_blank" rel="noopener">Download</a>
        </div>
      </div>
    `;
  }

  function renderDashboardMessageContent(msg) {
    const attachments = Array.isArray(msg.attachments) ? msg.attachments : [];
    const text = String(msg.message_text || "").trim();
    const attachmentHtml = attachments.map(renderDashboardAttachment).join("");
    const showText = text && text !== "Attachment uploaded" && text !== "Voice note";
    return `${showText ? `<div>${escapeHtml(text)}</div>` : ""}${attachmentHtml || (!showText ? `<div>${escapeHtml(text || "Message")}</div>` : "")}`;
  }

  function openDocumentViewer(url, downloadUrl, name, mime) {
    const modal = document.getElementById("publicChatDocumentModal");
    const title = document.getElementById("publicChatDocumentTitle");
    const body = document.getElementById("publicChatDocumentBody");
    const download = document.getElementById("publicChatDocumentDownload");
    if (!modal || !body) return;
    const safeName = name || "Attachment";
    const type = String(mime || "").toLowerCase();
    title.textContent = safeName;
    if (download) download.href = downloadUrl || url;
    if (type.startsWith("image/")) {
      body.innerHTML = `<img src="${escapeHtml(url)}" alt="${escapeHtml(safeName)}">`;
    } else if (type.startsWith("audio/")) {
      body.innerHTML = `<audio controls autoplay src="${escapeHtml(url)}"></audio>`;
    } else if (type.startsWith("video/")) {
      body.innerHTML = `<video controls autoplay src="${escapeHtml(url)}"></video>`;
    } else {
      body.innerHTML = `<iframe src="${escapeHtml(url)}" title="${escapeHtml(safeName)}"></iframe>`;
    }
    modal.classList.add("open");
    modal.setAttribute("aria-hidden", "false");
  }

  function closeDocumentViewer() {
    const modal = document.getElementById("publicChatDocumentModal");
    const body = document.getElementById("publicChatDocumentBody");
    if (body) body.innerHTML = "";
    modal?.classList.remove("open");
    modal?.setAttribute("aria-hidden", "true");
  }

  async function notifyTyping() {
    if (!state.selectedId) return;
    const now = Date.now();
    clearTimeout(state.typingStopTimer);
    state.typingStopTimer = setTimeout(() => stopTyping(false), 1200);
    if (state.typingTimer && now - state.typingTimer < 250) return;
    state.typingTimer = now;
    await fetchJson("public_chat_typing.php", { session_id: state.selectedId, as_agent: true, typing: true }).catch(() => {});
  }

  async function stopTyping(wait) {
    clearTimeout(state.typingStopTimer);
    state.typingStopTimer = null;
    if (!state.selectedId) return;
    const request = fetchJson("public_chat_typing.php", { session_id: state.selectedId, as_agent: true, typing: false }).catch(() => {});
    if (wait) await request;
  }

  function renderDashboardTyping(typing) {
    const indicator = document.getElementById("publicChatDashboardTyping");
    if (!indicator) return;
    if (!typing.length) {
      hideDashboardTyping();
      return;
    }
    const names = typing.map((item) => item.name || "Visitor").slice(0, 2).join(", ");
    indicator.querySelector("strong").textContent = `${names} ${typing.length === 1 ? "is" : "are"} typing`;
    indicator.hidden = false;
  }

  function hideDashboardTyping() {
    const indicator = document.getElementById("publicChatDashboardTyping");
    if (indicator) indicator.hidden = true;
  }

  async function addNote(event) {
    event.preventDefault();
    const text = document.getElementById("publicChatDashboardNoteText");
    const note = text?.value.trim();
    if (!note || !state.selectedId) return;
    const data = await fetchJson("public_chat_agent.php", { action: "note", session_id: state.selectedId, note });
    if (!data.success) {
      showActionError(data.message || "Unable to add note.");
      return;
    }
    text.value = "";
    await loadDetail(state.selectedId);
    showToast("Internal note added.", "success");
  }

  function showPensionerReference() {
    const context = state.selected?.pensionerContext;
    if (!context || !context.matched) return;
    const modal = document.getElementById("publicChatDashboardReferenceModal");
    const body = document.getElementById("publicChatDashboardReferenceBody");
    body.innerHTML = `
      ${renderReferenceGroup("Registry Record", context.registry)}
      ${renderReferenceGroup("Claims Record", context.claims)}
      ${renderReferenceGroup("Life Certificate", context.life_certificate || context.lifeCertificate)}
      ${renderReferenceGroup("Payroll", context.payroll)}
      ${renderReferenceGroup("Documents", context.documents)}
      ${renderReferenceGroup("Account Activity", context.account_activity || context.accountActivity)}
      ${renderReferenceGroup("Prior Public Chats", context.prior_chats || context.priorChats)}
    ` || `<div class="dashboard-empty-message">No reference details available.</div>`;
    modal?.setAttribute("aria-hidden", "false");
    modal?.classList.add("open");
  }

  function referenceLabel(key) {
    const labels = {
      regNo: "Registration Number",
      computerNo: "Computer Number",
      supplierNo: "Supplier Number",
      staff_appn_status: "Application Status",
      submitted_by: "Submitted By",
      registryLinked: "Registry Linked"
    };
    if (labels[key]) return labels[key];
    return String(key || "")
      .replace(/[_-]+/g, " ")
      .replace(/([a-z])([A-Z])/g, "$1 $2")
      .replace(/\s+/g, " ")
      .trim()
      .replace(/\b\w/g, (char) => char.toUpperCase());
  }

  function referenceValue(value) {
    if (value === null || value === undefined || value === "") return "N/A";
    if (typeof value === "boolean") return value ? "Yes" : "No";
    if (Array.isArray(value)) return value.length ? value.map(referenceValue).join(", ") : "N/A";
    if (typeof value === "object") return Object.entries(value).map(([key, item]) => `${referenceLabel(key)}: ${referenceValue(item)}`).join("; ");
    return String(value);
  }

  function isTechnicalReferenceKey(key) {
    return /(^id$|_id$|userid|user_id|staffid|staff_id|actor_id|session_id|message_id|attachment_id)/i.test(String(key || ""));
  }

  function renderReferenceObject(value) {
    const entries = Object.entries(value || {}).filter(([key, item]) => !isTechnicalReferenceKey(key) && item !== null && item !== undefined && item !== "");
    if (!entries.length) return `<div class="dashboard-empty-message">No details available.</div>`;
    return `
      <dl class="public-chat-reference-details">
        ${entries.map(([key, item]) => `
          <div>
            <dt>${escapeHtml(referenceLabel(key))}</dt>
            <dd>${escapeHtml(referenceValue(item))}</dd>
          </div>
        `).join("")}
      </dl>
    `;
  }

  function renderReferenceArray(rows) {
    const cleanRows = (rows || []).filter((row) => row && typeof row === "object");
    if (!cleanRows.length) return `<div class="dashboard-empty-message">No records found.</div>`;
    const fields = Array.from(cleanRows.reduce((set, row) => {
      Object.keys(row || {}).forEach((key) => {
        if (!isTechnicalReferenceKey(key) && row[key] !== null && row[key] !== undefined && row[key] !== "") set.add(key);
      });
      return set;
    }, new Set())).slice(0, 10);
    if (!fields.length) return `<div class="dashboard-empty-message">No records found.</div>`;
    return `
      <div class="dashboard-table-wrap public-chat-reference-table-wrap">
        <table class="dashboard-data-table public-chat-reference-table">
          <thead><tr>${fields.map((field) => `<th>${escapeHtml(referenceLabel(field))}</th>`).join("")}</tr></thead>
          <tbody>
            ${cleanRows.map((row) => `<tr>${fields.map((field) => `<td>${escapeHtml(referenceValue(row[field]))}</td>`).join("")}</tr>`).join("")}
          </tbody>
        </table>
      </div>
    `;
  }

  function renderReferenceGroup(title, value) {
    if (!value || (Array.isArray(value) && !value.length)) return "";
    const content = Array.isArray(value)
      ? renderReferenceArray(value)
      : typeof value === "object"
      ? renderReferenceObject(value)
      : `<p>${escapeHtml(referenceValue(value))}</p>`;
    return `<section class="public-chat-reference-group"><h4>${escapeHtml(title)}</h4>${content}</section>`;
  }

  function closePensionerReference() {
    const modal = document.getElementById("publicChatDashboardReferenceModal");
    modal?.setAttribute("aria-hidden", "true");
    modal?.classList.remove("open");
  }

  async function openAnalyticsModal(key) {
    const modal = document.getElementById("publicChatAnalyticsModal");
    const title = document.getElementById("publicChatAnalyticsTitle");
    const body = document.getElementById("publicChatAnalyticsBody");
    if (!modal || !body || !title) return;
    title.textContent = `Analytics Records - ${labelForAnalytics(key)}`;
    body.innerHTML = `<div class="dashboard-empty-message">Loading records...</div>`;
    modal.classList.add("open");
    modal.setAttribute("aria-hidden", "false");
    try {
      body.innerHTML = await analyticsRecordsHtml(key);
    } catch (error) {
      body.innerHTML = `<div class="dashboard-empty-message">${escapeHtml(error.message || "Unable to load records.")}</div>`;
    }
  }

  function closeAnalyticsModal() {
    const modal = document.getElementById("publicChatAnalyticsModal");
    modal?.classList.remove("open");
    modal?.setAttribute("aria-hidden", "true");
  }

  function labelForAnalytics(key) {
    return ({
      today: "Chats Today",
      week: "Chats This Week",
      month: "Chats This Month",
      waiting: "Waiting Chats",
      active: "Active Chats",
      escalated: "Escalated Chats",
      tickets: "Tickets",
      feedback: "Feedback"
    })[key] || "Public Chat";
  }

  async function analyticsRecordsHtml(key) {
    if (key === "tickets") {
      const data = await fetchJson("public_chat_agent.php", { action: "tickets" }, "GET");
      if (!data.success) throw new Error(data.message);
      return renderAnalyticsTable(data.tickets || [], ["ticket_reference", "status", "subject", "visitor_name", "created_at"]);
    }
    if (key === "escalated") {
      const data = await fetchJson("public_chat_agent.php", { action: "list", status: "escalated" }, "GET");
      if (!data.success) throw new Error(data.message);
      return renderAnalyticsTable(data.sessions || [], ["chat_reference", "visitor_name", "district", "inquiry_category", "created_at"]);
    }
    if (key === "waiting") {
      const data = await fetchJson("public_chat_agent.php", { action: "list", status: "waiting" }, "GET");
      if (!data.success) throw new Error(data.message);
      return renderAnalyticsTable(data.sessions || [], ["chat_reference", "visitor_name", "district", "inquiry_category", "created_at"]);
    }
    if (key === "active") {
      const active = await fetchJson("public_chat_agent.php", { action: "list", status: "active" }, "GET");
      const assigned = await fetchJson("public_chat_agent.php", { action: "list", status: "assigned" }, "GET");
      if (!active.success) throw new Error(active.message);
      return renderAnalyticsTable([...(active.sessions || []), ...(assigned.sessions || [])], ["chat_reference", "visitor_name", "district", "inquiry_category", "assigned_agent_name"]);
    }
    if (key === "feedback") {
      const s = state.stats || {};
      return renderGroupSummary(s.groups || {});
    }
    const data = await fetchJson("public_chat_agent.php", { action: "list" }, "GET");
    if (!data.success) throw new Error(data.message);
    let rows = data.sessions || [];
    if (key === "today" || key === "week" || key === "month") {
      rows = rows.filter((row) => isWithinAnalyticsPeriod(row.created_at, key));
    }
    return renderAnalyticsTable(rows, ["chat_reference", "visitor_name", "district", "inquiry_category", "created_at"]);
  }

  function isWithinAnalyticsPeriod(value, key) {
    const date = new Date(String(value || "").replace(" ", "T"));
    if (Number.isNaN(date.getTime())) return false;
    const now = new Date();
    if (key === "today") {
      return date.toDateString() === now.toDateString();
    }
    if (key === "month") {
      return date.getFullYear() === now.getFullYear() && date.getMonth() === now.getMonth();
    }
    const start = new Date(now);
    const day = start.getDay() || 7;
    start.setDate(start.getDate() - day + 1);
    start.setHours(0, 0, 0, 0);
    return date >= start;
  }

  function renderAnalyticsTable(rows, fields) {
    if (!rows.length) return `<div class="dashboard-empty-message">No records found.</div>`;
    return `
      <div class="dashboard-table-wrap">
        <table class="dashboard-data-table">
          <thead><tr>${fields.map((field) => `<th>${escapeHtml(field.replace(/_/g, " "))}</th>`).join("")}</tr></thead>
          <tbody>
            ${rows.map((row) => `<tr>${fields.map((field) => `<td>${escapeHtml(row[field] || "")}</td>`).join("")}</tr>`).join("")}
          </tbody>
        </table>
      </div>
    `;
  }

  function renderGroupSummary(groups) {
    const sections = Object.entries(groups || {}).map(([key, rows]) => `
      <section class="public-chat-reference-group">
        <h4>${escapeHtml(key.replace(/([A-Z])/g, " $1").trim())}</h4>
        ${renderAnalyticsTable(rows || [], ["label", "total"])}
      </section>
    `).join("");
    return sections || `<div class="dashboard-empty-message">No analytics breakdown available.</div>`;
  }

  async function loadAll() {
    if (!state.allowed) return;
    await loadStats();
  }

  function startHeartbeat() {
    clearInterval(state.heartbeatTimer);
    state.heartbeatTimer = setInterval(() => {
      const sectionActive = document.getElementById("publicChatSection")?.classList.contains("active");
      const consoleOpen = document.getElementById("publicChatConsoleModal")?.classList.contains("open");
      if (!state.allowed || document.hidden || !sectionActive || !consoleOpen) return;
      fetchJson("public_chat_agent.php", { action: "heartbeat" }).catch(() => {});
      refreshAgentAvailability();
    }, 20000);
  }

  function startNewChatNotifications() {
    clearInterval(state.notificationTimer);
    state.notificationTimer = setInterval(checkForNewPublicChats, 2500);
    checkForNewPublicChats();
  }

  async function checkForNewPublicChats() {
    if (!state.allowed || document.hidden) return;
    const availability = await fetchJson("public_chat_availability.php", null, "GET").catch(() => null);
    if (!availability?.success) return;
    updateAvailabilityButton(availability.availability);
    if (!availability.availability?.agent?.online) return;
    const data = await fetchJson("public_chat_agent.php", { action: "list", status: "waiting" }, "GET").catch(() => null);
    if (!data?.success) return;
    (data.sessions || []).forEach((chat) => {
      const id = String(chat.session_id || "");
      if (!id || state.notifiedChats.has(id)) return;
      state.notifiedChats.add(id);
      showPublicChatNotification(chat);
    });
    localStorage.setItem("pensionsgo_public_chat_notified", JSON.stringify(Array.from(state.notifiedChats).slice(-80)));
  }

  function showPublicChatNotification(chat) {
    document.querySelector(".global-broadcast-overlay.public-chat-new-overlay")?.remove();
    const modal = document.createElement("div");
    modal.className = "global-broadcast-overlay public-chat-new-overlay";
    modal.innerHTML = `
      <div class="broadcast-popup public-chat-notification-popup">
        <div class="broadcast-header">
          <h3>New Public Live Chat</h3>
          <button type="button" class="broadcast-close" aria-label="Close">&times;</button>
        </div>
        <div class="broadcast-body">
          <h4>${escapeHtml(chat.chat_reference || "Public support request")}</h4>
          <p>${escapeHtml(chat.visitor_name || "Visitor")} needs help with ${escapeHtml(chat.inquiry_category || "General inquiry")}.</p>
          <small>${escapeHtml(chat.district || "Detected location")} - ${escapeHtml(chat.created_at || "")}</small>
        </div>
        <div class="broadcast-actions">
          <button type="button" class="broadcast-btn secondary" data-public-chat-dismiss>Later</button>
          <button type="button" class="broadcast-btn primary" data-public-chat-open>${document.getElementById("publicChatConsoleModal") ? "Open Chat Console" : "Open Dashboard"}</button>
        </div>
      </div>
    `;
    document.body.appendChild(modal);
    playPublicChatNotificationSound();
    modal.querySelector(".broadcast-close")?.addEventListener("click", () => modal.remove());
    modal.querySelector("[data-public-chat-dismiss]")?.addEventListener("click", () => modal.remove());
    modal.querySelector("[data-public-chat-open]")?.addEventListener("click", () => {
      modal.remove();
      if (document.getElementById("publicChatConsoleModal")) {
        openConsole();
        selectConsoleSession(chat.session_id);
      } else {
        window.location.href = "dashboard.html";
      }
    });
  }

  function resolveClientAssetUrl(path) {
    const value = String(path || "").trim();
    if (!value) return "";
    if (/^(https?:)?\/\//i.test(value) || value.startsWith("data:") || value.startsWith("blob:")) return value;
    const clean = value.replace(/^(\.\.\/)+frontend\//, "").replace(/^frontend\//, "");
    const prefix = location.pathname.includes("/frontend/") ? "" : "../frontend/";
    return new URL(`${prefix}${clean}`, location.href).href;
  }

  function playPublicChatNotificationSound() {
    try {
      if (window.AppSettingsManager?.isBroadcastSoundEnabled && !window.AppSettingsManager.isBroadcastSoundEnabled()) return;
      const soundPath = window.AppSettingsManager?.getBroadcastSoundPath?.() || "audio/notification.mp3";
      const volume = window.AppSettingsManager?.getBroadcastSoundVolume?.() ?? 0.85;
      const repeats = window.AppSettingsManager?.getBroadcastSoundRepeatCount?.() ?? 1;
      const audio = new Audio(resolveClientAssetUrl(soundPath));
      audio.preload = "auto";
      audio.volume = Math.max(0, Math.min(1, Number(volume)));
      let played = 0;
      audio.addEventListener("ended", () => {
        played += 1;
        if (played < repeats) {
          audio.currentTime = 0;
          audio.play().catch(() => {});
        }
      });
      audio.play().catch(() => {});
    } catch (_) {}
  }

  document.addEventListener("DOMContentLoaded", async () => {
    const mount = document.getElementById("publicChatDashboardMount");
    try {
      const data = await fetchJson("public_chat_bootstrap.php", null, "GET");
      state.allowed = Boolean(data.success && data.agent && data.agent.canManage);
      if (!state.allowed) {
        const notice = document.getElementById("publicChatDashboardAccessNotice");
        if (notice) notice.textContent = "You do not have Public Live Chat correspondence rights.";
        return;
      }
      if (mount) {
        revealDashboardEntry();
        renderDashboardShell();
        document.getElementById("publicChatRefreshDashboardBtn")?.addEventListener("click", loadAll);
        document.getElementById("publicChatAvailabilityBtn")?.addEventListener("click", async () => {
          const nextStatus = state.agentOnline ? "offline" : "online";
          const data = await fetchJson("public_chat_agent.php", { action: "status", agent_status: nextStatus });
          if (data.success) updateAvailabilityButton(data.availability);
          if (nextStatus === "online") fetchJson("public_chat_agent.php", { action: "heartbeat" }).catch(() => {});
        });
        updateAvailabilityButton(data.availability);
        await loadAll();
        await refreshAgentAvailability();
        startHeartbeat();
      }
      startNewChatNotifications();
      window.addEventListener("beforeunload", sendOfflineBeacon);
    } catch (error) {
      const notice = document.getElementById("publicChatDashboardAccessNotice");
      if (notice) notice.textContent = "Unable to verify Public Live Chat access.";
    }
  });
})();
