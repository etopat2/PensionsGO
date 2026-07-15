// 
// frontend/js/messages.js
// Complete Messages module with Enhanced Time Formatting & Modal System
// 
// Redirect if not logged in
if (sessionStorage.getItem('isLoggedIn') !== 'true') {
  window.location.replace('login.html');
}

class MessagesApp {
  constructor() {
    // API endpoints
    this.API = {
      getMessages: "../backend/api/get_messages.php",
      getMessageDetail: "../backend/api/get_message_detail.php",
      sendMessage: "../backend/api/send_message.php",
      deleteMessage: "../backend/api/delete_message.php",
      markUnread: "../backend/api/mark_unread.php",
      getUnreadCount: "../backend/api/get_unread_count.php",
      checkBroadcasts: "../backend/api/check_broadcasts.php",
      getUsers: "../backend/api/get_message_users.php",
      markBroadcastSeen: "../backend/api/mark_broadcast_seen.php",
      submitFeedback: "../backend/api/submit_feedback.php",
      liveChatBootstrap: "../backend/api/live_chat_bootstrap.php",
      liveChatPresence: "../backend/api/live_chat_presence.php",
      liveChatMessages: "../backend/api/live_chat_messages.php",
      liveChatSend: "../backend/api/live_chat_send.php",
      liveChatCall: "../backend/api/live_chat_call.php"
    };

    // UI & state
    this.currentType = "inbox"; // inbox | sent | broadcast
    this.currentPage = 1;
    this.limit = 20;
    this.messages = [];
    this.archivedMode = false;
    this.users = [];
    this.selectedMessage = null;
    this.selectedMessageIds = new Set();
    this.unreadCounts = { direct: 0, broadcast: 0 };
    this.currentBroadcastId = null;
    this.broadcastEscapeHandler = null;
    this.userRole = localStorage.getItem('userRoleEffective') || localStorage.getItem('userRole') || '';
    this.userId = sessionStorage.getItem('userId') || '';
    this.liveChat = {
      users: [],
      selectedUser: null,
      lastMessageIdByUser: {},
      messagePollTimer: null,
      presenceTimer: null,
      callPollTimer: null,
      signalPollTimer: null,
      recorder: null,
      recordedChunks: [],
      recordingStartedAt: 0,
      activeCall: null,
      peerConnection: null,
      localStream: null,
      remoteStream: null,
      lastSignalId: 0,
      isCallInitiator: false
    };
    
    // Enhanced recipient and file management
    this.selectedRecipients = [];
    this.selectedFiles = [];

    // Broadcast sound & seen-tracking
    this.BROADCAST_SOUND = "../frontend/audio/notification.mp3";
    this.SEEN_BROADCASTS_KEY = "pensionsgo_seen_broadcasts";
    this.preloadedSound = null;
    this.soundInitBound = this.initSoundOnFirstInteraction.bind(this);

    // Real-time time updates
    this.timeUpdateInterval = null;
    this.originalLoadMessages = null;

    // Bind once to allow autoplay after user interaction
    document.addEventListener("click", this.soundInitBound, { once: true });
    document.addEventListener("touchstart", this.soundInitBound, { once: true });

    // Start lifecycle
    document.addEventListener("DOMContentLoaded", () => {
      // Expose for debugging
      window.MessagesAppInstance = this;
      this.init();
    }, { once: true });

    // Clean up intervals when page unloads
    window.addEventListener('beforeunload', () => {
        this.stopRealTimeUpdates();
        this.stopBroadcastChecker();
        this.stopLiveChat();
    });
  }

  // 
  // Initialization
  // 
  async init() {
    if (window.initHeaderInteractions) window.initHeaderInteractions();
    
    // Only restrict broadcast sending, not viewing
    this.checkAdminPermissions();
    
    await this.loadUsers();           // populate compose recipients & roles
    const restoredViewerState = await this.restoreViewerReturnContext();
    if (!restoredViewerState) {
      await this.loadMessages();        // initial load
      await this.handleDeepLink();      // open specific broadcast/message if provided
    }
    this.setupEventListeners();       // UI controls
    this.setupRecipientSelection();   // Enhanced recipient selection
    this.setupFileAttachments();      // Enhanced file attachments
    this.setupRoleBasedUI();          // Hide/show buttons based on role
    this.startBroadcastChecker();     // broadcast checks
    await this.updateUnreadBadges();  // header + sidebar badges
    this.setupActionButtons();        // reply/forward/delete/mark unread
    // Live staff chat is initialized globally from main.js so it is available on every authenticated page.
    this.initRealTimeUpdates();       // Initialize real-time time updates
    // Load storage information
    await this.loadStorageInfo();
    console.log("MessagesApp initialized - User Role:", this.userRole);
  }

  async restoreViewerReturnContext() {
    const params = new URLSearchParams(window.location.search || '');
    const returnKey = String(params.get('viewer_return') || '').trim();
    if (!returnKey || !window.PensionsGoDocumentViewer?.consumeReturnState) {
      return false;
    }

    const restoreState = window.PensionsGoDocumentViewer.consumeReturnState(returnKey);
    params.delete('viewer_return');
    const nextQuery = params.toString();
    const cleanUrl = `${window.location.pathname.split('/').pop()}${nextQuery ? `?${nextQuery}` : ''}${window.location.hash || ''}`;
    window.history.replaceState({}, '', cleanUrl);

    if (!restoreState || restoreState.page !== 'messages') {
      return false;
    }

    this.currentType = String(restoreState.currentType || 'inbox');
    this.currentPage = Number(restoreState.currentPage || 1) || 1;
    this.archivedMode = Boolean(restoreState.archivedMode);

    document.querySelectorAll(".nav-item").forEach(item => {
      item.classList.toggle("active", item.dataset.type === this.currentType);
    });
    this.updateListTitle();

    await this.loadMessages(this.currentType, this.currentPage);

    const messageId = String(restoreState.messageId || '').trim();
    if (messageId) {
      await this.showMessageDetail(messageId);
    }

    return true;
  }

  // 
  // Deep link handling for broadcast/message
  // 
  async handleDeepLink() {
    const params = new URLSearchParams(window.location.search);
    const messageId = params.get('message_id');
    const broadcastId = params.get('broadcast_id');

    if (!messageId) return;

    // Switch to broadcast view for clarity
    this.currentType = 'broadcast';
    const listTitle = document.getElementById("listTitle");
    if (listTitle) listTitle.textContent = 'Broadcast';
    document.querySelectorAll(".nav-item").forEach(item => {
      item.classList.toggle("active", item.dataset.type === "broadcast");
    });

    await this.loadMessages('broadcast', 1);
    await this.showMessageDetail(messageId);

    if (broadcastId) {
      await this.markBroadcastAsRead(broadcastId);
    }
  }

  // Only restrict broadcast sending capabilities, not viewing
  checkAdminPermissions() {
    const isAdmin = this.userRole === 'admin';
    
    // Only hide the broadcast sending section in compose form for non-admins
    const broadcastSection = document.getElementById('broadcastSection');
    if (broadcastSection && !isAdmin) {
      broadcastSection.style.display = 'none';
    }
    
    console.log('Admin permissions checked - isAdmin:', isAdmin);
  }

  // 
  // SOUND (broadcast)
  // 
  initSoundOnFirstInteraction() {
    try {
      this.preloadedSound = new Audio(this.BROADCAST_SOUND);
      this.preloadedSound.volume = 0.7;
    } catch (e) {
      console.warn("Audio preload failed:", e);
    }
  }

  playBroadcastSound() {
    if (!this.preloadedSound) {
      try {
        const a = new Audio(this.BROADCAST_SOUND);
        a.volume = 0.7;
        a.play().catch(() => {});
      } catch (e) { /* ignore */ }
      return;
    }
    this.preloadedSound.currentTime = 0;
    this.preloadedSound.play().catch(() => {});
  }

  // 
  // USER LIST (for compose) - Filter out current user
  // 
  async loadUsers() {
    try {
      const res = await fetch(this.API.getUsers, { credentials: "include" });
      const data = await res.json();
      if (data.success) {
        // Filter out current user from recipient list
        this.users = (data.users || []).filter(user => user.userId !== this.userId);
        this.populateRecipientSelect();
        this.populateRoleCheckboxes();
      } else {
        console.warn("loadUsers: no users", data);
      }
    } catch (err) {
      console.error("loadUsers error", err);
    }
  }

  populateRecipientSelect() {
    const select = document.getElementById("messageRecipients");
    if (!select) return;
    select.innerHTML = "";
    this.users.forEach(u => {
      const opt = document.createElement("option");
      opt.value = u.userId;
      opt.textContent = `${u.userName} (${u.userRole})`;
      opt.dataset.role = u.userRole;
      select.appendChild(opt);
    });
  }

  populateRoleCheckboxes() {
    const container = document.querySelector(".role-checkboxes");
    if (!container) return;
    
    // Only show role checkboxes for admin users (for broadcast sending)
    if (this.userRole !== 'admin') {
      container.style.display = 'none';
      return;
    }
    
    const roles = [...new Set(this.users.map(u => u.userRole))].sort();
    container.innerHTML = roles.map(r => `
      <label class="checkbox-label small">
        <input type="checkbox" value="${r}" name="targetRoles">
        <span class="checkmark"></span>
        ${r}
      </label>
    `).join("");
  }

  // 
  // Recipient Selection With Chips //
  setupRecipientSelection() {
    const searchInput = document.getElementById('recipientsSearch');
    const dropdown = document.getElementById('recipientsDropdown');
    const selectedContainer = document.getElementById('selectedRecipients');
    const hiddenSelect = document.getElementById('messageRecipients');
    const recipientsSearch = document.getElementById('recipientsSearch');
    const recipientsContainer = document.querySelector('.recipients-container');

    if (!searchInput || !dropdown || !selectedContainer) return;

    this.selectedRecipients = [];

    searchInput.addEventListener('input', (e) => {
      const query = e.target.value.toLowerCase();
      this.filterRecipients(query);
    });

    searchInput.addEventListener('focus', () => {
      this.filterRecipients('');
    });

    document.addEventListener('click', (e) => {
      if (!dropdown.contains(e.target) && !searchInput.contains(e.target)) {
        dropdown.classList.add('hidden');
      }
    });

    if (recipientsSearch && recipientsContainer) {
      recipientsSearch.addEventListener('focus', () => {
          recipientsContainer.classList.add('dropdown-open');
      });
      
      recipientsSearch.addEventListener('blur', () => {
          // Small delay to allow for click events
          setTimeout(() => {
              recipientsContainer.classList.remove('dropdown-open');
          }, 200);
      });
    }
  }

  filterRecipients(query = '') {
    const dropdown = document.getElementById('recipientsDropdown');
    const searchInput = document.getElementById('recipientsSearch');
    
    if (!dropdown || !searchInput) return;

    const filteredUsers = this.users.filter(user => 
      user.userName.toLowerCase().includes(query) ||
      user.userRole.toLowerCase().includes(query) ||
      user.userEmail.toLowerCase().includes(query)
    ).filter(user => !this.selectedRecipients.some(selected => selected.userId === user.userId));

    if (filteredUsers.length === 0) {
      dropdown.innerHTML = '<div class="dropdown-item">No matching users found</div>';
    } else {
      dropdown.innerHTML = filteredUsers.map(user => `
        <div class="dropdown-item" data-user-id="${user.userId}">
          <img src="${this.getUserImage(user.userPhoto)}" class="dropdown-avatar">
          <div class="dropdown-user-info">
            <strong>${this.escapeHtml(user.userName)}</strong>
            <small>${this.escapeHtml(user.userRole)} - ${this.escapeHtml(user.userEmail)}</small>
          </div>
        </div>
      `).join('');
    }

    dropdown.classList.remove('hidden');

    // Add click listeners to dropdown items
    dropdown.querySelectorAll('.dropdown-item').forEach(item => {
      item.addEventListener('click', () => {
        const userId = item.dataset.userId;
        const user = this.users.find(u => u.userId === userId);
        if (user) {
          this.addRecipient(user);
          searchInput.value = '';
          dropdown.classList.add('hidden');
          searchInput.focus();
        }
      });
    });
  }

  addRecipient(user) {
    if (this.selectedRecipients.some(r => r.userId === user.userId)) return;

    this.selectedRecipients.push(user);
    this.renderSelectedRecipients();

    // Update hidden select
    const hiddenSelect = document.getElementById('messageRecipients');
    if (hiddenSelect) {
      const option = document.createElement('option');
      option.value = user.userId;
      option.selected = true;
      hiddenSelect.appendChild(option);
    }

    this.updateBroadcastSection();
  }

  removeRecipient(userId) {
    this.selectedRecipients = this.selectedRecipients.filter(r => r.userId !== userId);
    this.renderSelectedRecipients();

    // Update hidden select
    const hiddenSelect = document.getElementById('messageRecipients');
    if (hiddenSelect) {
      const option = hiddenSelect.querySelector(`option[value="${userId}"]`);
      if (option) option.remove();
    }

    this.updateBroadcastSection();
  }

  renderSelectedRecipients() {
    const container = document.getElementById('selectedRecipients');
    if (!container) return;

    container.innerHTML = this.selectedRecipients.map(recipient => `
      <div class="recipient-chip">
        <img src="${this.getUserImage(recipient.userPhoto)}" class="recipient-avatar">
        <span class="recipient-name">${this.escapeHtml(recipient.userName)}</span>
        <button type="button" class="recipient-remove" data-user-id="${recipient.userId}">
          <i class="fas fa-times"></i>
        </button>
      </div>
    `).join('');

    // Add remove event listeners
    container.querySelectorAll('.recipient-remove').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const userId = btn.dataset.userId;
        this.removeRecipient(userId);
      });
    });
  }

  updateBroadcastSection() {
    const broadcastSection = document.getElementById('broadcastSection');
    if (!broadcastSection) return;
    
    const selectedCount = this.selectedRecipients.length;
    if (selectedCount > 1) {
      broadcastSection.classList.add("multiple-recipients");
    } else {
      broadcastSection.classList.remove("multiple-recipients");
    }
  }

  // 
  // File Attachments With Custom Names //
  setupFileAttachments() {
    const fileInput = document.getElementById('fileAttachments');
    const selectedContainer = document.getElementById('selectedFiles');

    if (!fileInput || !selectedContainer) return;

    this.selectedFiles = [];

    fileInput.addEventListener('change', (e) => {
      Array.from(e.target.files).forEach(file => {
        this.addFile(file);
      });
      fileInput.value = ''; // Reset input to allow selecting same file again
    });
  }

  addFile(file) {
    const fileId = 'file_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    
    this.selectedFiles.push({
      id: fileId,
      file: file,
      customName: file.name,
      size: file.size
    });

    this.renderSelectedFiles();
  }

  removeFile(fileId) {
    this.selectedFiles = this.selectedFiles.filter(f => f.id !== fileId);
    this.renderSelectedFiles();
  }

  updateFileName(fileId, newName) {
    const fileObj = this.selectedFiles.find(f => f.id === fileId);
    if (fileObj) {
      fileObj.customName = newName;
    }
  }

  renderSelectedFiles() {
    const container = document.getElementById('selectedFiles');
    if (!container) return;

    container.innerHTML = this.selectedFiles.map(fileObj => `
      <div class="file-chip">
        <i class="fas fa-file"></i>
        <input type="text" class="file-name-input" value="${this.escapeHtml(fileObj.customName)}" 
               data-file-id="${fileObj.id}" placeholder="Enter file name...">
        <small>(${this.formatFileSize(fileObj.size)})</small>
        <button type="button" class="file-remove" data-file-id="${fileObj.id}">
          <i class="fas fa-times"></i>
        </button>
      </div>
    `).join('');

    // Add event listeners for file name changes
    container.querySelectorAll('.file-name-input').forEach(input => {
      input.addEventListener('change', (e) => {
        const fileId = e.target.dataset.fileId;
        const fileObj = this.selectedFiles.find(f => f.id === fileId);
        const newName = e.target.value.trim() || fileObj.file.name;
        this.updateFileName(fileId, newName);
      });

      input.addEventListener('blur', (e) => {
        const fileId = e.target.dataset.fileId;
        const fileObj = this.selectedFiles.find(f => f.id === fileId);
        const newName = e.target.value.trim() || fileObj.file.name;
        this.updateFileName(fileId, newName);
      });
    });

    // Add remove event listeners
    container.querySelectorAll('.file-remove').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const fileId = btn.dataset.fileId;
        this.removeFile(fileId);
      });
    });
  }

  // 
  // MESSAGES: list & pagination
  // 
  async loadMessages(type = this.currentType, page = this.currentPage) {
    // All users can access broadcast view (only sending is restricted)
    this.currentType = type;
    this.currentPage = page;
    this.clearMessageSelection();
    const list = document.getElementById("messagesList");
    if (!list) return;

    list.innerHTML = `<div class="loading">Loading messages...</div>`;
    try {
      const archivedParam = this.archivedMode && type !== "broadcast" ? 1 : 0;
      const url = `${this.API.getMessages}?type=${encodeURIComponent(type)}&page=${page}&limit=${this.limit}&archived=${archivedParam}`;
      const res = await fetch(url, { credentials: "include" });
      const data = await res.json();
      if (!data.success) {
        list.innerHTML = `<div class="empty-state"><h3>Error</h3><p>${data.message || "Unable to fetch messages"}</p></div>`;
        return;
      }

      this.messages = data.messages || [];
      if (!this.messages.length) {
        list.innerHTML = `<div class="empty-state"><i class="fas fa-inbox"></i><h3>No messages</h3><p>Your selected folder is empty.</p></div>`;
        this.renderPagination(data.pagination || { page: 1, pages: 1 });
        this.updateBatchDeleteControls();
        return;
      }

      list.innerHTML = this.messages.map(m => this.messageRowHtml(m)).join("");
      this.bindMessageListInteractions(list);

      this.renderPagination(data.pagination || { page: 1, pages: 1 });
      this.updateBatchDeleteControls();
      await this.updateUnreadBadges();
    } catch (err) {
      console.error("loadMessages error", err);
      list.innerHTML = `<div class="empty-state"><h3>Error</h3><p>${err.message || err}</p></div>`;
    }
  }

  // 
  // TIME FORMATTING - Enhanced with relative time for lists & detailed format for detail view
  // 
  /**
   * Format timestamp to "d mmm, yyyy HH:MM:ss" format (with seconds)
   * Used for detailed views where precise timing is important
   */
  formatTime(ts) {
      if (!ts) return "";

      if (window.AppSettingsManager?.formatDateTime) {
          return window.AppSettingsManager.formatDateTime(ts, { includeSeconds: true });
      }

      const d = new Date(ts);
      const day = d.getDate();
      const month = d.toLocaleDateString('en-US', { month: 'short' });
      const year = d.getFullYear();
      
      // Format time with leading zeros including seconds
      const hours = d.getHours().toString().padStart(2, '0');
      const minutes = d.getMinutes().toString().padStart(2, '0');
      const seconds = d.getSeconds().toString().padStart(2, '0');
      
      return `${day} ${month}, ${year} ${hours}:${minutes}:${seconds}`;
  }

  /**
   * Format timestamp to relative time format for message lists
   * Used for Sent and Inbox folders only - maintains "just now", "minutes", "hours", etc.
   */
  formatTimeShort(ts) {
      if (!ts) return "";
      
      // For broadcast messages, always use the detailed format
      if (this.currentType === "broadcast") {
          if (window.AppSettingsManager?.formatDateTime) {
              return window.AppSettingsManager.formatDateTime(ts, { includeSeconds: false });
          }
          const d = new Date(ts);
          const day = d.getDate();
          const month = d.toLocaleDateString('en-US', { month: 'short' });
          const year = d.getFullYear();
          const hours = d.getHours().toString().padStart(2, '0');
          const minutes = d.getMinutes().toString().padStart(2, '0');
          return `${day} ${month}, ${year} ${hours}:${minutes}`;
      }
      
      // For Sent and Inbox folders, use relative time format
      const d = new Date(ts);
      const now = new Date();
      const diffMs = now - d;
      const diffSecs = Math.floor(diffMs / 1000);
      const diffMins = Math.floor(diffSecs / 60);
      const diffHours = Math.floor(diffMins / 60);
      const diffDays = Math.floor(diffHours / 24);
      const diffWeeks = Math.floor(diffDays / 7);
      const diffMonths = Math.floor(diffDays / 30);
      const diffYears = Math.floor(diffDays / 365);
      
      if (diffSecs < 60) {
          return "Just now";
      } else if (diffMins < 60) {
          return `${diffMins}m ago`;
      } else if (diffHours < 24) {
          return `${diffHours}h ago`;
      } else if (diffDays < 7) {
          return `${diffDays}d ago`;
      } else if (diffWeeks < 4) {
          return `${diffWeeks}w ago`;
      } else if (diffMonths < 12) {
          return `${diffMonths}mo ago`;
      } else {
          return `${diffYears}y ago`;
      }
  }

  // Sent message display logic with enhanced time formatting
  messageRowHtml(m) {
    const unread = (m.is_read === "0" || m.is_read === false || m.is_read === 0) && this.currentType === "inbox";
    const unreadClass = unread ? "unread" : "";
    const urgent = m.is_urgent ? `<i class="fas fa-exclamation-circle urgent-icon" title="Urgent"></i>` : "";
    const preview = (m.preview || "").length > 220 ? `${m.preview.slice(0, 220)}...` : (m.preview || "");
    
    let avatarSrc, displayName;
    
    if (this.currentType === "sent") {
        // Handle recipient information
        const totalRecipients = m.total_recipients || 1;
        const activeRecipients = m.active_recipients || 0;
        
        // Use the primary recipient name (will show deleted recipients if no active ones)
        const primaryName = m.primary_recipient_name || 'Unknown';
        
        if (activeRecipients === 0) {
            // All recipients deleted
            displayName = `To: ${primaryName} (deleted)`;
        } else if (totalRecipients === 1) {
            // Single recipient
            displayName = `To: ${primaryName}`;
        } else {
            // Multiple recipients
            displayName = `To: ${primaryName} + ${totalRecipients - 1} more`;
        }
        
        avatarSrc = this.getUserImage(m.recipient_photo);
        
    } else if (this.currentType === "broadcast") {
        avatarSrc = this.getUserImage(m.sender_photo);
        displayName = m.sender_name || 'Unknown';
    } else {
        // inbox
        avatarSrc = this.getUserImage(m.sender_photo);
        displayName = m.sender_name || 'Unknown';
    }

    return `
    <div class="message-item ${unreadClass} ${m.is_urgent ? "urgent" : ""}" data-message-id="${m.message_id}" data-timestamp="${m.created_at}">
        <label class="message-select" aria-label="Select message">
          <input type="checkbox" class="message-select-checkbox" data-message-id="${m.message_id}">
          <span class="message-select-indicator"></span>
        </label>
        <img src="${avatarSrc}" alt="${this.escapeHtml(displayName)}" class="message-avatar" />
        <div class="message-content">
        <div class="message-header">
            <h4 class="message-sender">${this.escapeHtml(displayName)}</h4>
            <span class="message-time" data-timestamp="${m.created_at}">${this.formatTimeShort(m.created_at)}</span>
        </div>
        <h5 class="message-subject">${urgent} ${this.escapeHtml(m.subject || "(No subject)")}</h5>
        <p class="message-preview">${this.escapeHtml(preview)}</p>
        <div class="message-meta">
            ${m.attachment_count > 0 ? `<span class="meta-attachment"><i class="fas fa-paperclip"></i> ${m.attachment_count}</span>` : ""}
            ${this.currentType === "sent" && m.total_recipients > 1 ? `<span class="meta-recipients">To: ${m.total_recipients} recipients</span>` : ""}
            ${this.currentType === "sent" && m.active_recipients === 0 ? `<span class="meta-deleted"><i class="fas fa-trash"></i> All recipients deleted</span>` : ""}
            ${this.currentType === "broadcast" ? `<span class="meta-broadcast"><i class="fas fa-bullhorn"></i> Broadcast</span>` : ""}
        </div>
        </div>
    </div>
    `;
  }

  bindMessageListInteractions(list) {
    if (!list) return;

    list.querySelectorAll(".message-item").forEach((el) => {
      const checkbox = el.querySelector(".message-select-checkbox");
      const messageId = String(el.dataset.messageId || "").trim();
      if (!messageId) return;

      if (checkbox) {
        checkbox.checked = this.selectedMessageIds.has(messageId);
        el.classList.toggle("selected", checkbox.checked);

        checkbox.addEventListener("click", (event) => {
          event.stopPropagation();
        });
        checkbox.addEventListener("change", (event) => {
          this.toggleMessageSelection(messageId, event.target.checked);
        });
      }

      el.addEventListener("click", (event) => {
        if (event.target.closest(".message-select")) {
          return;
        }
        this.showMessageDetail(messageId);
      });
    });

    this.syncSelectAllCheckbox();
  }

  toggleMessageSelection(messageId, isSelected) {
    const normalizedId = String(messageId || "").trim();
    if (!normalizedId) return;

    if (isSelected) {
      this.selectedMessageIds.add(normalizedId);
    } else {
      this.selectedMessageIds.delete(normalizedId);
    }

    const item = document.querySelector(`.message-item[data-message-id="${CSS.escape(normalizedId)}"]`);
    if (item) {
      item.classList.toggle("selected", isSelected);
      const checkbox = item.querySelector(".message-select-checkbox");
      if (checkbox) checkbox.checked = isSelected;
    }

    this.updateBatchDeleteControls();
    this.syncSelectAllCheckbox();
  }

  clearMessageSelection() {
    this.selectedMessageIds.clear();
    this.updateBatchDeleteControls();
    this.syncSelectAllCheckbox();
  }

  getVisibleMessageIds() {
    return Array.from(document.querySelectorAll(".message-item"))
      .filter((item) => item.style.display !== "none")
      .map((item) => String(item.dataset.messageId || "").trim())
      .filter(Boolean);
  }

  syncSelectAllCheckbox() {
    const selectAll = document.getElementById("selectAllMessages");
    if (!selectAll) return;

    const visibleIds = this.getVisibleMessageIds();
    if (!visibleIds.length) {
      selectAll.checked = false;
      selectAll.indeterminate = false;
      selectAll.disabled = true;
      return;
    }

    const selectedVisibleCount = visibleIds.filter((id) => this.selectedMessageIds.has(id)).length;
    selectAll.disabled = false;
    selectAll.checked = selectedVisibleCount > 0 && selectedVisibleCount === visibleIds.length;
    selectAll.indeterminate = selectedVisibleCount > 0 && selectedVisibleCount < visibleIds.length;
  }

  updateBatchDeleteControls() {
    const batchDeleteBtn = document.getElementById("batchDeleteBtn");
    const batchDeleteLabel = document.getElementById("batchDeleteLabel");
    if (!batchDeleteBtn || !batchDeleteLabel) {
      return;
    }

    const count = this.selectedMessageIds.size;
    batchDeleteBtn.disabled = count === 0;
    batchDeleteBtn.classList.toggle("active", count > 0);
    batchDeleteLabel.textContent = count > 0 ? `Delete Selected (${count})` : "Delete Selected";
  }

  async deleteMessagesByIds(messageIds, options = {}) {
    const ids = Array.from(new Set((messageIds || []).map((id) => Number(id)).filter((id) => Number.isInteger(id) && id > 0)));
    if (!ids.length) {
      this.showWarningModal("No Messages Selected", "Select at least one message to delete.");
      return false;
    }

    const loadingModal = this.showLoadingModal(
      options.loadingTitle || 'Deleting',
      options.loadingMessage || 'Please wait while we delete the selected message(s)...'
    );

    try {
      const res = await fetch(this.API.deleteMessage, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "include",
        body: JSON.stringify({ ids })
      });
      const data = await res.json();

      loadingModal.close();

      if (!data.success) {
        throw new Error(data.message || "Delete failed");
      }

      ids.forEach((id) => this.selectedMessageIds.delete(String(id)));
      this.updateBatchDeleteControls();
      this.syncSelectAllCheckbox();

      if (this.selectedMessage && ids.includes(Number(this.selectedMessage.message?.message_id || 0))) {
        this.selectedMessage = null;
        this.showListView();
      }

      await this.loadMessages(this.currentType, 1);
      await this.updateUnreadBadges();

      if (options.successMessage !== false) {
        this.showSuccessModal("Success", options.successMessage || data.message || "Message(s) deleted successfully");
      }

      return true;
    } catch (err) {
      loadingModal.close();
      console.error("deleteMessagesByIds error", err);
      this.showErrorModal("Delete Failed", "Failed to delete message(s): " + err.message);
      return false;
    }
  }

  confirmBatchDelete() {
    const selectedIds = Array.from(this.selectedMessageIds);
    if (!selectedIds.length) {
      this.showWarningModal("No Messages Selected", "Select at least one message to delete.");
      return;
    }

    this.showConfirmationModal({
      title: 'Delete Selected Messages',
      message: `Delete ${selectedIds.length} selected message${selectedIds.length === 1 ? '' : 's'}? This action cannot be undone.`,
      confirmText: 'Delete Selected',
      cancelText: 'Cancel',
      danger: true,
      details: {
        Folder: this.currentType.charAt(0).toUpperCase() + this.currentType.slice(1),
        Selected: String(selectedIds.length)
      },
      onConfirm: async () => {
        await this.deleteMessagesByIds(selectedIds, {
          loadingMessage: `Deleting ${selectedIds.length} selected message${selectedIds.length === 1 ? '' : 's'}...`,
          successMessage: `${selectedIds.length} message${selectedIds.length === 1 ? '' : 's'} deleted successfully`
        });
      }
    });
  }

  // 
  // REAL-TIME TIME UPDATES - For relative time formatting in lists
  // 
  /**
   * Initialize real-time time updates
   * Updates every minute to keep relative times current in list views
   */
  initRealTimeUpdates() {
    // For relative time formatting, update every minute
    this.updateAllMessageTimes();
    this.timeUpdateInterval = setInterval(() => {
        this.updateAllMessageTimes();
    }, 60000); // Update every minute for relative time updates

    // Also update when switching views or loading new messages
    this.originalLoadMessages = this.loadMessages;
    this.loadMessages = async (type = this.currentType, page = this.currentPage) => {
        await this.originalLoadMessages(type, page);
        this.updateAllMessageTimes();
    };
  }

  /**
   * Update all message times in the current list
   * Ensures relative time displays are always current for Sent and Inbox
   */
  updateAllMessageTimes() {
      const messageItems = document.querySelectorAll('.message-item');
      messageItems.forEach(item => {
          const timeElement = item.querySelector('.message-time');
          const messageId = item.dataset.messageId;
          
          if (timeElement && messageId) {
              // Find the message data to get the original timestamp
              const message = this.messages.find(m => m.message_id == messageId);
              if (message && message.created_at) {
                  const newTime = this.formatTimeShort(message.created_at);
                  if (timeElement.textContent !== newTime) {
                      timeElement.textContent = newTime;
                  }
              }
          }
      });

      // Also update detail view if open
      this.updateDetailViewTime();
  }

  /**
   * Update time in detail view if open
   * Ensures detailed timestamps with seconds stay current
   */
  updateDetailViewTime() {
      const detailView = document.getElementById('messageDetailView');
      if (detailView && !detailView.classList.contains('hidden') && this.selectedMessage) {
          const accessInfo = this.selectedMessage.access_info || {};
          // Update sent time in detail view
          const sentTimeElement = document.querySelector('.sent-time');
          if (sentTimeElement && this.selectedMessage.message) {
              const newTime = this.formatTime(this.selectedMessage.message.created_at);
              sentTimeElement.innerHTML = `<i class="fas fa-paper-plane"></i> Sent: ${newTime}`;
          }

          // Update read status times in recipients list
          if (accessInfo.is_sender) {
              const readStatusElements = document.querySelectorAll('.read-status');
              readStatusElements.forEach(element => {
                  if (element.classList.contains('read') && element.textContent.includes('Read at')) {
                      const recipientItem = element.closest('.recipient-item');
                      if (recipientItem) {
                          const recipientName = recipientItem.querySelector('strong')?.textContent;
                          if (recipientName && this.selectedMessage.recipients) {
                              const recipient = this.selectedMessage.recipients.find(r => 
                                  r.userName === recipientName && r.read_at
                              );
                              if (recipient) {
                                  element.textContent = `Read at ${this.formatTime(recipient.read_at)}`;
                              }
                          }
                      }
                  }
              });
          }
      }
  }

  /**
   * Clean up interval when needed
   */
  stopRealTimeUpdates() {
      if (this.timeUpdateInterval) {
          clearInterval(this.timeUpdateInterval);
          this.timeUpdateInterval = null;
      }
  }

  renderPagination(pagination = { page: 1, pages: 1 }) {
    const container = document.getElementById("pagination");
    if (!container) return;
    if (!pagination || pagination.pages <= 1) { container.innerHTML = ""; return; }

    let html = "";
    if (pagination.page > 1) html += `<button class="page-btn" data-page="${pagination.page - 1}">Previous</button>`;
    for (let i = 1; i <= pagination.pages; i++) {
      html += i === pagination.page ? `<span class="page-current">${i}</span>` : `<button class="page-btn" data-page="${i}">${i}</button>`;
    }
    if (pagination.page < pagination.pages) html += `<button class="page-btn" data-page="${pagination.page + 1}">Next</button>`;

    container.innerHTML = html;
    container.querySelectorAll(".page-btn").forEach(btn => btn.addEventListener("click", () => {
      this.currentPage = parseInt(btn.dataset.page, 10);
      this.loadMessages(this.currentType, this.currentPage);
    }));
  }

  // 
  // MESSAGE DETAIL with Enhanced Time Display
  // 
  async showMessageDetail(messageId) {
    try {
      const res = await fetch(`${this.API.getMessageDetail}?message_id=${encodeURIComponent(messageId)}`, { credentials: "include" });
      const data = await res.json();
      if (!data.success) throw new Error(data.message || "Failed to load message");

      this.selectedMessage = data;
      this.renderMessageDetail();
      await this.updateUnreadBadges();
      
      // Update action buttons based on ownership
      this.updateActionButtons();
      
      await this.loadMessages(this.currentType, this.currentPage);
    } catch (err) {
      console.error("showMessageDetail error", err);
      this.showErrorModal("Load Failed", "Failed to load message details. Please try again.");
    }
  }

  renderMessageDetail() {
    const container = document.getElementById("messageDetail");
    if (!container || !this.selectedMessage) return;
    const m = this.selectedMessage.message;
    const accessInfo = this.selectedMessage.access_info || {};
    const canSeeRecipients = !!accessInfo.is_sender;
    const recipients = this.selectedMessage.recipients || [];
    const attachments = this.selectedMessage.attachments || [];

    // Enhanced recipients HTML with precise read timestamps
    const recipientsHtml = canSeeRecipients && recipients.length ? `
      <div class="recipients-info">
        <strong>Recipients (${recipients.length})</strong>
        <div class="recipients-list">
            ${recipients.map(r => {
                const readStatus = r.is_read ? 
                    `<span class="read-status read">Read at ${this.formatTime(r.read_at)}</span>` : 
                    '<span class="read-status unread">Unread</span>';
                
                return `
                <div class="recipient-item">
                    <div class="recipient-info">
                        <strong>${this.escapeHtml(r.userName)}</strong>
                        <span class="recipient-role">${this.escapeHtml(r.userRole || '')}</span>
                    </div>
                    ${readStatus}
                </div>`;
            }).join("")}
        </div>
      </div>
    ` : "";

    // Enhanced attachments HTML with upload timestamps
    const attachmentsHtml = attachments.length
    ? `
        <div class="attachments-section">
        <h4><i class="fas fa-paperclip"></i> Attachments (${attachments.length})</h4>
        <div class="attachments-list">
            ${attachments
            .map((a) => {
                const attachmentId = Number(a.attachment_id || 0);
                const attachmentUrl = attachmentId > 0
                    ? `../backend/api/view_message_attachment.php?attachment_id=${attachmentId}`
                    : "#";
                const viewerUrl = attachmentId > 0 && window.PensionsGoDocumentViewer?.buildViewerUrl
                    ? (window.PensionsGoDocumentViewer.buildViewerUrl(attachmentUrl, {
                        label: a.file_name || 'Attachment',
                        backUrl: window.location.href,
                        returnState: {
                            page: 'messages',
                            currentType: this.currentType,
                            currentPage: this.currentPage,
                            archivedMode: this.archivedMode,
                            messageId: m.message_id
                        }
                    }) || attachmentUrl)
                    : attachmentUrl;
                const isImage = a.mime_type?.startsWith("image/");
                const uploadTime = a.uploaded_at ? this.formatTime(a.uploaded_at) : 'Unknown';

                return `
                <div class="attachment-item">
                    <a href="${viewerUrl}" class="attachment-link">
                    ${isImage 
                        ? `<img src="${attachmentUrl}" class="attachment-thumb" alt="${this.escapeHtml(a.file_name)}">`
                        : `<i class="fas fa-file"></i>`}
                    <div class="attachment-info">
                        <span class="attachment-name">${this.escapeHtml(a.file_name)}</span>
                        <div class="attachment-meta">
                            <small>${this.formatFileSize(a.file_size)}</small>
                            <small>- Uploaded: ${uploadTime}</small>
                        </div>
                    </div>
                    </a>
                </div>
                `;
            })
            .join("")}
        </div>
        </div>
    `
    : "";

    // Use full timestamp format with seconds for detail view
    const sentDateFormatted = this.formatTime(m.created_at);

    container.innerHTML = `
      <div class="detail-header-info">
        <div class="sender-info">
          <img src="${this.getUserImage(m.sender_photo)}" class="sender-avatar" alt="${this.escapeHtml(m.sender_name)}" />
          <div class="sender-details">
            <strong>${this.escapeHtml(m.sender_name)}</strong>
            <div class="sender-meta">
                <span class="sender-role">${this.escapeHtml(m.sender_role || "")}</span>
                <span class="sender-email">${this.escapeHtml(m.sender_email || "")}</span>
            </div>
            <div class="sent-time">
                <i class="fas fa-paper-plane"></i>
                Sent: ${sentDateFormatted}
            </div>
          </div>
        </div>
        ${recipientsHtml}
      </div>
      <div class="detail-content">
        <h2>${this.escapeHtml(m.subject || "(No subject)")}</h2>
        <div class="message-text">${this.formatMessageText(m.message_text || "")}</div>
        ${attachmentsHtml}
      </div>
    `;

    document.getElementById("messagesListView").classList.add("hidden");
    document.getElementById("messageDetailView").classList.remove("hidden");
    this.updateActionButtons();
  }

  formatMessageText(text) {
    return this.escapeHtml(text).replace(/\n/g, "<br>");
  }

  // 
  // Comprehensive Modal System //
  showModal(options) {
    // Remove any existing modal
    const existingModal = document.querySelector('.modal-system-overlay');
    if (existingModal) {
        existingModal.remove();
    }

    const {
        type = 'info',
        title = '',
        message = '',
        html = '',
        actions = [],
        onClose = null,
        onConfirm = null,
        onCancel = null,
        showCloseButton = true,
        closeOnOverlay = true
    } = options;

    const icons = {
        error: 'fas fa-exclamation-circle',
        success: 'fas fa-check-circle',
        info: 'fas fa-info-circle',
        warning: 'fas fa-exclamation-triangle',
        confirm: 'fas fa-question-circle',
        feedback: 'fas fa-comment-alt'
    };

    const modal = document.createElement('div');
    modal.className = 'modal-system-overlay';
    modal.innerHTML = `
        <div class="modal-system">
            <div class="modal-header ${type}">
                <i class="modal-icon ${icons[type]}"></i>
                <h3 class="modal-title">${this.escapeHtml(title)}</h3>
            </div>
            <div class="modal-content">
                ${message ? `<p class="modal-message ${options.center ? 'center' : ''}">${this.escapeHtml(message)}</p>` : ''}
                ${html || ''}
            </div>
            <div class="modal-actions ${actions.length === 1 ? 'single' : 'dual'}">
                ${actions.map(action => `
                    <button class="modal-btn ${action.type || 'secondary'}" 
                            ${action.id ? `id="${action.id}"` : ''}
                            ${action.disabled ? 'disabled' : ''}>
                        ${action.icon ? `<i class="${action.icon}"></i>` : ''}
                        ${this.escapeHtml(action.label)}
                    </button>
                `).join('')}
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);

    // Add event listeners
    const closeModal = (result = false) => {
        modal.remove();
        document.removeEventListener('keydown', handleEscape);
        if (onClose) onClose(result);
    };

    const handleEscape = (e) => {
        if (e.key === 'Escape' && showCloseButton) {
            closeModal(false);
            if (onCancel) onCancel();
        }
    };

    // Action button handlers
    actions.forEach(action => {
        if (action.id) {
            const btn = document.getElementById(action.id);
            if (btn) {
                btn.addEventListener('click', () => {
                    if (action.handler) {
                        action.handler();
                    }
                    if (action.close !== false) {
                        closeModal(true);
                    }
                });
            }
        }
    });

    // Overlay click handler
    if (closeOnOverlay) {
        modal.addEventListener('click', (e) => {
            if (e.target === modal && showCloseButton) {
                closeModal(false);
                if (onCancel) onCancel();
            }
        });
    }

    document.addEventListener('keydown', handleEscape);

    // Focus the first button for accessibility
    setTimeout(() => {
        const firstBtn = modal.querySelector('.modal-btn');
        if (firstBtn) firstBtn.focus();
    }, 100);

    return {
        close: () => closeModal(false),
        update: (newOptions) => {
            // Implementation for dynamic modal updates
        }
    };
  }

  // Specific modal methods
  showConfirmationModal(options) {
    const {
        title = 'Confirm Action',
        message = 'Are you sure you want to proceed?',
        confirmText = 'Confirm',
        cancelText = 'Cancel',
        danger = false,
        details = null,
        ...rest
    } = options;

    let detailsHtml = '';
    if (details && typeof details === 'object') {
        detailsHtml = `
            <div class="confirmation-details">
                ${Object.entries(details).map(([key, value]) => `
                    <div class="confirmation-item">
                        <span class="confirmation-label">${this.escapeHtml(key)}:</span>
                        <span class="confirmation-value">${this.escapeHtml(value)}</span>
                    </div>
                `).join('')}
            </div>
        `;
    }

    // Create the complete HTML content
    const messageHtml = `
        <p class="modal-message">${this.escapeHtml(message)}</p>
        ${detailsHtml}
    `;

    return this.showModal({
        type: 'confirm',
        title,
        html: messageHtml, // Use html instead of message
        actions: [
            {
                id: 'modalCancel',
                label: cancelText,
                type: 'secondary',
                handler: rest.onCancel
            },
            {
                id: 'modalConfirm',
                label: confirmText,
                type: danger ? 'danger' : 'primary',
                handler: rest.onConfirm
            }
        ],
        ...rest
    });
  }

  showFeedbackModal() {
      const feedbackHtml = `
          <form class="feedback-form" id="feedbackForm">
              <div class="feedback-group">
                  <label for="feedbackType">Feedback Type:</label>
                  <select id="feedbackType" class="feedback-select" required>
                      <option value="">Select type...</option>
                      <option value="bug">Bug Report</option>
                      <option value="suggestion">Suggestion</option>
                      <option value="compliment">Compliment</option>
                      <option value="other">Other</option>
                  </select>
              </div>
              <div class="feedback-group">
                  <label for="feedbackMessage">Your Feedback:</label>
                  <textarea id="feedbackMessage" class="feedback-textarea" 
                          placeholder="Please provide your feedback here..." 
                          maxlength="1000" required></textarea>
                  <div class="feedback-char-count" id="charCount">0/1000</div>
              </div>
          </form>
      `;

      const modal = this.showModal({
          type: 'feedback',
          title: 'Send Feedback',
          html: feedbackHtml,
          actions: [
              {
                  id: 'feedbackCancel',
                  label: 'Cancel',
                  type: 'secondary'
              },
              {
                  id: 'feedbackSubmit',
                  label: 'Submit Feedback',
                  type: 'primary',
                  handler: () => this.submitFeedback()
              }
          ]
      });

      // Setup character counter
      this.setupFeedbackCharCounter();
      return modal;
  }

  showSuccessModal(title, message, options = {}) {
      return this.showModal({
          type: 'success',
          title,
          message,
          center: true,
          actions: [
              {
                  id: 'successOk',
                  label: options.buttonText || 'OK',
                  type: 'primary',
                  icon: 'fas fa-check'
              }
          ],
          ...options
      });
  }

  showErrorModal(title, message, options = {}) {
      return this.showModal({
          type: 'error',
          title,
          message,
          center: true,
          actions: [
              {
                  id: 'errorOk',
                  label: options.buttonText || 'OK',
                  type: 'primary',
                  icon: 'fas fa-times'
              }
          ],
          ...options
      });
  }

  showWarningModal(title, message, options = {}) {
      return this.showModal({
          type: 'warning',
          title,
          message,
          center: true,
          actions: [
              {
                  id: 'warningOk',
                  label: options.buttonText || 'OK',
                  type: 'primary',
                  icon: 'fas fa-exclamation-triangle'
              }
          ],
          ...options
      });
  }

  showInfoModal(title, message, options = {}) {
      return this.showModal({
          type: 'info',
          title,
          message,
          center: true,
          actions: [
              {
                  id: 'infoOk',
                  label: options.buttonText || 'OK',
                  type: 'primary',
                  icon: 'fas fa-info-circle'
              }
          ],
          ...options
      });
  }

  showLoadingModal(title = 'Processing', message = 'Please wait...') {
      const loadingHtml = `
          <div class="modal-loading">
              <div class="modal-spinner"></div>
              <div class="modal-loading-text">${this.escapeHtml(message)}</div>
          </div>
      `;

      return this.showModal({
          type: 'info',
          title,
          html: loadingHtml,
          actions: [],
          showCloseButton: false,
          closeOnOverlay: false
      });
  }

  // Feedback submission handler
  async submitFeedback() {
      const type = document.getElementById('feedbackType')?.value;
      const message = document.getElementById('feedbackMessage')?.value;
      
      if (!type || !message) {
          this.showErrorModal('Missing Information', 'Please select a feedback type and provide your message.');
          return;
      }

      const loadingModal = this.showLoadingModal('Submitting Feedback', 'Sending your feedback...');
      
      try {
          const response = await fetch(this.API.submitFeedback, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              credentials: 'include',
              body: JSON.stringify({
                  type: type,
                  message: message,
                  page: 'messages',
                  timestamp: new Date().toISOString()
              })
          });
          
          const data = await response.json();
          
          loadingModal.close();
          
          if (data.success) {
              this.showSuccessModal('Thank You!', 'Your feedback has been submitted successfully. We appreciate your input!');
              
              // Reset form
              document.getElementById('feedbackType').value = '';
              document.getElementById('feedbackMessage').value = '';
              document.getElementById('charCount').textContent = '0/1000';
          } else {
              throw new Error(data.message || 'Failed to submit feedback');
          }
          
      } catch (error) {
          loadingModal.close();
          this.showErrorModal('Submission Failed', 'Failed to submit feedback. Please try again later.');
      }
  }

  // Character counter for feedback
  setupFeedbackCharCounter() {
      const messageInput = document.getElementById('feedbackMessage');
      const charCount = document.getElementById('charCount');
      
      if (messageInput && charCount) {
          messageInput.addEventListener('input', () => {
              const length = messageInput.value.length;
              charCount.textContent = `${length}/1000`;
              
              charCount.className = 'feedback-char-count';
              if (length > 900) {
                  charCount.classList.add('warning');
              }
              if (length > 990) {
                  charCount.classList.add('error');
              }
          });
      }
  }

  // 
  // Compose / Send //
  showComposeModal(prefill = {}) {
    const modal = document.getElementById("composeModal");
    if (!modal) return;
    
    // Only disable broadcast checkbox for non-admins (not hide entire section)
    if (this.userRole !== 'admin') {
      const broadcastCheckbox = document.getElementById('isBroadcast');
      if (broadcastCheckbox) {
        broadcastCheckbox.checked = false;
        broadcastCheckbox.disabled = true;
        broadcastCheckbox.title = "Broadcast messages can only be sent by administrators";
      }
    }
    
    if (prefill.subject) document.getElementById("messageSubject").value = prefill.subject;
    if (prefill.message) document.getElementById("messageText").value = prefill.message;
    if (prefill.recipients && prefill.recipients.length) {
      // Add recipients to enhanced selection
      prefill.recipients.forEach(recipientId => {
        const user = this.users.find(u => u.userId === recipientId);
        if (user) this.addRecipient(user);
      });
    }
    modal.classList.remove("hidden");
    document.body.classList.add("modal-open");
    setTimeout(() => document.getElementById("messageSubject")?.focus(), 100);
  }

  hideComposeModal() {
    const modal = document.getElementById("composeModal");
    if (!modal) return;
    modal.classList.add("hidden");
    document.getElementById("composeForm")?.reset();
    document.body.classList.remove("modal-open");
    document.body.style.overflow = "";
    
    // Reset enhanced components
    this.selectedRecipients = [];
    this.selectedFiles = [];
    this.renderSelectedRecipients();
    this.renderSelectedFiles();
    
    // Reset search
    const searchInput = document.getElementById('recipientsSearch');
    if (searchInput) searchInput.value = '';
    
    const dropdown = document.getElementById('recipientsDropdown');
    if (dropdown) dropdown.classList.add('hidden');
  }

  // Storage management methods
  async loadStorageInfo() {
      try {
          const response = await fetch('../backend/api/get_storage_usage.php', {
              credentials: 'include'
          });
          const data = await response.json();
          
          if (data.success) {
              this.updateStorageDisplay(data.storage);
          } else {
              console.warn('Failed to load storage info:', data.message);
              // Set default values
              this.updateStorageDisplay({
                  used_mb: 0,
                  max_mb: 300,
                  percentage: 0,
                  remaining_mb: 300
              });
          }
      } catch (error) {
          console.error('Error loading storage info:', error);
          // Set default values on error
          const fallbackMax = window.AppSettingsManager?.get
            ? Number(window.AppSettingsManager.get('message_storage_quota_mb', 300))
            : 300;
          const safeMax = Number.isFinite(fallbackMax) && fallbackMax > 0 ? fallbackMax : 300;
          this.updateStorageDisplay({
              used_mb: 0,
              max_mb: safeMax,
              percentage: 0,
              remaining_mb: safeMax
          });
      }
  }

  updateStorageDisplay(storage) {
      const progressBar = document.querySelector('.storage-progress');
      const storageText = document.querySelector('.storage-info small');
      
      if (progressBar && storageText) {
          // Update progress bar width and color
          const percentage = storage.percentage;
          progressBar.style.width = `${percentage}%`;
          
          // Change color based on usage
          if (percentage >= 90) {
              progressBar.style.backgroundColor = 'var(--urgent-color)';
          } else if (percentage >= 75) {
              progressBar.style.backgroundColor = 'var(--broadcast-color)';
          } else {
              progressBar.style.backgroundColor = 'var(--primary-blue)';
          }
          
          // Update text
          storageText.textContent = `${storage.used_mb} MB of ${storage.max_mb} MB used`;
          
          // Add warning tooltip if near limit
          if (percentage >= 80) {
              storageText.title = `Warning: You have only ${storage.remaining_mb} MB remaining`;
              storageText.style.color = 'var(--urgent-color)';
              storageText.style.fontWeight = '600';
          } else {
              storageText.title = `${storage.remaining_mb} MB remaining`;
              storageText.style.color = '';
              storageText.style.fontWeight = '';
          }
      }
  }

  // Storage check before sending messages
  async checkStorageBeforeSend(files = []) {
      try {
          const response = await fetch('../backend/api/get_storage_usage.php', {
              credentials: 'include'
          });
          const data = await response.json();
          
          if (data.success) {
              const storage = data.storage;
              const newFilesSize = files.reduce((total, file) => total + file.size, 0);
              const newTotal = storage.used_bytes + newFilesSize;
              const maxBytes = storage.max_bytes;
              
              if (newTotal > maxBytes) {
                  const remainingMB = Math.max(0, (maxBytes - storage.used_bytes) / (1024 * 1024)).toFixed(2);
                  throw new Error(`Storage limit will be exceeded. You have ${remainingMB}MB remaining. Please remove some attachments or delete old messages.`);
              }
              
              return true;
          }
      } catch (error) {
          console.error('Storage check error:', error);
          throw error;
      }
  }

  async sendMessage(e) {
    e?.preventDefault();
    const sendBtn = document.querySelector("#composeForm button[type='submit']");
    const originalBtnHTML = sendBtn?.innerHTML;
    
    if (sendBtn) {
      sendBtn.disabled = true;
      sendBtn.innerHTML = "<i class='fas fa-spinner fa-spin'></i> Sending...";
    }

    try {
      // Check storage before sending
      await this.checkStorageBeforeSend(this.selectedFiles.map(f => f.file));

      const subject = (document.getElementById("messageSubject")?.value || "").trim();
      const messageText = (document.getElementById("messageText")?.value || "").trim();
      const isUrgent = !!document.getElementById("isUrgent")?.checked;
      const isBroadcast = !!document.getElementById("isBroadcast")?.checked;

      // Validate broadcast permissions (only admins can send broadcasts)
      if (isBroadcast && this.userRole !== 'admin') {
        throw new Error("Only administrators can send broadcast messages");
      }

      if (!subject || !messageText) throw new Error("Subject and message are required.");

      let recipients = [];
      if (!isBroadcast) {
        recipients = this.selectedRecipients.map(r => r.userId);
        if (!recipients.length) throw new Error("Please select at least one recipient.");
      }

      let targetRoles = [];
      if (isBroadcast) {
        targetRoles = Array.from(document.querySelectorAll("input[name='targetRoles']:checked")).map(cb => cb.value);
      }

      const hasFiles = this.selectedFiles.length > 0;

      let res;
      if (hasFiles) {
        const fd = new FormData();
        const payload = { 
          subject, 
          message: messageText, 
          recipients, 
          isUrgent, 
          isBroadcast, 
          targetRoles, 
          messageType: (recipients.length > 1 ? "group" : "direct"),
          fileNames: this.selectedFiles.map(f => ({ id: f.id, name: f.customName }))
        };
        fd.append("data", JSON.stringify(payload));
        
        this.selectedFiles.forEach(fileObj => {
          fd.append("attachments[]", fileObj.file);
          fd.append("attachment_names[]", fileObj.customName);
        });
        
        res = await fetch(this.API.sendMessage, { method: "POST", body: fd, credentials: "include" });
      } else {
        const payload = { 
          subject, 
          message: messageText, 
          recipients, 
          isUrgent, 
          isBroadcast, 
          targetRoles, 
          messageType: (recipients.length > 1 ? "group" : "direct") 
        };
        res = await fetch(this.API.sendMessage, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(payload), credentials: "include" });
      }

      const result = await res.json();
      if (!result.success) throw new Error(result.message || "Failed to send message");

      this.showSuccessModal("Message Sent", "Your message has been sent successfully.");
      this.hideComposeModal();
      await this.loadMessages(this.currentType, 1);
      await this.updateUnreadBadges();
      // After successful send, update storage info
      await this.loadStorageInfo();
    } catch (err) {
      console.error("sendMessage error", err);
      this.showErrorModal("Send Failed", err.message || "Failed to send message. Please try again.");
    } finally {
      if (sendBtn) { 
        sendBtn.disabled = false; 
        sendBtn.innerHTML = originalBtnHTML || "Send Message"; 
      }
    }
  }

  // 
  // Actions //
  setupActionButtons() {
    document.querySelector(".btn-action[title='Reply']")?.addEventListener("click", () => this.replyToMessage());
    document.querySelector(".btn-action[title='Forward']")?.addEventListener("click", () => this.forwardMessage());
    document.querySelector(".btn-action[title='Delete']")?.addEventListener("click", () => this.deleteSelectedMessage());
    document.querySelector(".btn-action[title='Mark as Unread']")?.addEventListener("click", () => this.markSelectedAsUnread());
    document.getElementById("backToList")?.addEventListener("click", () => this.showListView());
  }

  updateActionButtons() {
    const hasSelected = !!this.selectedMessage;
    const deleteBtn = document.querySelector(".btn-action[title='Delete']");
    
    if (deleteBtn && this.selectedMessage) {
      const message = this.selectedMessage.message;
      const isBroadcast = message.message_type === 'broadcast';
      const accessInfo = this.selectedMessage.access_info || {};
      
      let canDelete = false;
      let deleteTitle = "Delete";
      
      if (isBroadcast) {
        canDelete = this.userRole === 'admin';
        deleteTitle = canDelete ? "Delete broadcast for all users" : "Only administrators can delete broadcast messages";
      } else {
        canDelete = !!accessInfo.is_sender || !!accessInfo.is_recipient;
        deleteTitle = canDelete ? "Delete" : "You can only delete messages you sent or received";
      }
      
      deleteBtn.disabled = !canDelete;
      deleteBtn.title = deleteTitle;
    }
    
    document.querySelectorAll(".btn-action").forEach(b => {
      if (b !== deleteBtn) {
        b.disabled = !hasSelected;
      }
    });
  }

  replyToMessage() {
    if (!this.selectedMessage) return;
    const message = this.selectedMessage.message;
    
    if (message.message_type === 'broadcast') {
      this.showInfoModal("Cannot Reply", "You cannot reply to broadcast messages.");
      return;
    }
    
    const replySubject = `Re: ${message.subject}`;
    const replyBody = `\n\n--- Original Message ---\nFrom: ${message.sender_name}\nDate: ${this.formatTime(message.created_at)}\n\n${message.message_text}`;
    this.showComposeModal({ subject: replySubject, message: replyBody, recipients: [message.sender_id?.toString()] });
  }

  forwardMessage() {
    if (!this.selectedMessage) return;
    const message = this.selectedMessage.message;
    const fwdSubject = `Fwd: ${message.subject}`;
    const fwdBody = `\n\n--- Forwarded Message ---\nFrom: ${message.sender_name}\nDate: ${this.formatTime(message.created_at)}\nSubject: ${message.subject}\n\n${message.message_text}`;
    this.showComposeModal({ subject: fwdSubject, message: fwdBody, recipients: [] });
  }

  async deleteSelectedMessage() {
      if (!this.selectedMessage) return;
      
      const message = this.selectedMessage.message;
      const isBroadcast = message.message_type === 'broadcast';
      const accessInfo = this.selectedMessage.access_info || {};
      
      const isSender = !!accessInfo.is_sender;
      const isRecipient = !!accessInfo.is_recipient;
      
      let canDelete = false;
      
      if (isBroadcast) {
          canDelete = this.userRole === 'admin';
      } else {
          canDelete = isSender || isRecipient;
      }
      
      if (!canDelete) {
          const errorMsg = isBroadcast 
              ? "Only administrators can delete broadcast messages" 
              : "You can only delete messages you sent or received";
          
          this.showErrorModal("Permission Denied", errorMsg);
          return;
      }

      // Use confirmation modal instead of native confirm
      this.showConfirmationModal({
          title: isBroadcast ? 'Delete Broadcast Message' : 'Delete Message',
          message: isBroadcast 
              ? 'This action will delete this broadcast message for ALL users. This cannot be undone.'
              : 'Are you sure you want to delete this message? This action cannot be undone.',
          confirmText: 'Delete',
          cancelText: 'Cancel',
          danger: true,
          details: {
              Subject: message.subject || '(No subject)',
              From: message.sender_name || 'Unknown',
              Date: this.formatTime(message.created_at)
          },
          onConfirm: async () => {
              try {
                  const messageId = this.selectedMessage.message.message_id;
                  const successMessage = isBroadcast 
                      ? "Broadcast message deleted for all users" 
                      : "Message deleted successfully";
                  await this.deleteMessagesByIds([messageId], {
                      loadingTitle: 'Deleting',
                      loadingMessage: 'Please wait while we delete the message...',
                      successMessage
                  });
              } catch (err) {
                  console.error("deleteSelectedMessage error", err);
                  this.showErrorModal("Delete Failed", "Failed to delete message: " + err.message);
              }
          }
      });
  }

  // Hide/show MarkAsUnread button based on role
  setupRoleBasedUI() {
      const markUnreadBtn = document.querySelector('.mark-unread-btn');
      if (markUnreadBtn) {
          if (this.userRole === 'admin') {
              markUnreadBtn.style.display = 'flex';
              markUnreadBtn.addEventListener("click", () => this.markSelectedAsUnread());
          } else {
              markUnreadBtn.style.display = 'none';
          }
      }
  }

  async markSelectedAsUnread() {
    if (!this.selectedMessage) return;
    
    const message = this.selectedMessage.message;
    if (message.message_type === 'broadcast') {
      this.showInfoModal("Cannot Mark Unread", "You cannot mark broadcast messages as unread.");
      return;
    }
    
    const messageId = this.selectedMessage.message.message_id;
    const loadingModal = this.showLoadingModal('Updating', 'Marking message as unread...');
    
    try {
      const res = await fetch(this.API.markUnread, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "include",
        body: JSON.stringify({ message_id: messageId })
      });
      const data = await res.json();
      
      loadingModal.close();
      
      if (!data.success) throw new Error(data.message || "Mark unread failed");

      this.showSuccessModal("Success", "Message marked as unread");
      this.showListView();
      await this.loadMessages(this.currentType, this.currentPage);
      await this.updateUnreadBadges();
      this.selectedMessage = null;
    } catch (err) {
      loadingModal.close();
      console.error("markSelectedAsUnread error", err);
      this.showErrorModal("Failed", "Failed to mark message as unread");
    }
  }

  // 
  // Unread Counts / Badges //
  async updateUnreadBadges() {
    try {
      const res = await fetch(this.API.getUnreadCount, { credentials: "include" });
      const data = await res.json();
      if (!data.success) return;

      this.unreadCounts.direct = data.direct_unread || 0;
      this.unreadCounts.broadcast = data.broadcast_unread || 0;

      const headerBubble = document.querySelector(".message-bubble");
      if (headerBubble) {
        const total = (data.direct_unread || 0) + (data.broadcast_unread || 0);
        headerBubble.textContent = total > 99 ? "99+" : (total > 0 ? total : "");
        headerBubble.classList.toggle("hidden", total === 0);
      }

      const inboxBadge = document.getElementById("inboxBadge");
      const broadcastBadge = document.getElementById("broadcastBadge");
      
      if (inboxBadge) { 
        inboxBadge.textContent = this.unreadCounts.direct > 0 ? this.unreadCounts.direct : ""; 
        inboxBadge.style.display = this.unreadCounts.direct > 0 ? "flex" : "none"; 
      }
      
      if (broadcastBadge) { 
        broadcastBadge.textContent = this.unreadCounts.broadcast > 0 ? this.unreadCounts.broadcast : ""; 
        broadcastBadge.style.display = this.unreadCounts.broadcast > 0 ? "flex" : "none"; 
      }
    } catch (err) {
      console.warn("updateUnreadBadges error", err);
    }
  }

  // 
  // Broadcast Checking & Popup //
  startBroadcastChecker() {
    if (window.AppSettingsManager && !window.AppSettingsManager.isBroadcastNotificationsEnabled()) {
      return;
    }
    this.checkNewBroadcasts();
    this.broadcastInterval = setInterval(() => this.checkNewBroadcasts(), 30000);
  }

  stopBroadcastChecker() {
    if (this.broadcastInterval) clearInterval(this.broadcastInterval);
  }

  async checkNewBroadcasts() {
    try {
      if (window.AppSettingsManager && !window.AppSettingsManager.isBroadcastNotificationsEnabled()) {
        return;
      }
      const res = await fetch(this.API.checkBroadcasts, { credentials: "include" });
      if (!res.ok) return;
      const data = await res.json();
      if (data.success && data.has_new && data.latest_broadcast) {
        const b = data.latest_broadcast;
        const bId = b.broadcast_id || b.message_id || b.id;
        if (!bId) return;

        const seen = this.getSeenBroadcasts();
        if (seen.includes(String(bId))) {
          return;
        }

        this.markBroadcastSeenLocal(bId);
        this.playBroadcastSound();
        this.showBroadcastModal(b);
      }
    } catch (err) {
      console.warn("checkNewBroadcasts error", err);
    }
  }

  showBroadcastModal(b) {
    const modal = document.getElementById("broadcastAlertModal");
    if (!modal) return;
    
    this.currentBroadcastId = b.broadcast_id || b.message_id;
    
    document.getElementById("broadcastAlertSubject").textContent = b.subject || "(No subject)";
    document.getElementById("broadcastAlertMessage").textContent = (b.message_preview || "").slice(0, 800);
    document.getElementById("broadcastAlertSender").textContent = b.sender_name || "";
    modal.classList.remove("hidden");
    
    this.removeBroadcastEventListeners();
    
    document.getElementById("dismissBroadcast").addEventListener("click", () => {
      this.dismissBroadcastModal(false);
    });

    document.getElementById("viewBroadcast").addEventListener("click", () => {
      this.dismissBroadcastModal(true);
      this.switchView("broadcast");
    });

    modal.addEventListener("click", (e) => {
      if (e.target === modal) this.dismissBroadcastModal(false);
    });

    this.broadcastEscapeHandler = (ev) => {
      if (ev.key === 'Escape') this.dismissBroadcastModal(false);
    };
    document.addEventListener("keydown", this.broadcastEscapeHandler);
  }

  removeBroadcastEventListeners() {
    const dismissBtn = document.getElementById("dismissBroadcast");
    const viewBtn = document.getElementById("viewBroadcast");
    const modal = document.getElementById("broadcastAlertModal");
    
    if (dismissBtn) dismissBtn.replaceWith(dismissBtn.cloneNode(true));
    if (viewBtn) viewBtn.replaceWith(viewBtn.cloneNode(true));
    if (modal) modal.replaceWith(modal.cloneNode(true));
    
    if (this.broadcastEscapeHandler) {
      document.removeEventListener("keydown", this.broadcastEscapeHandler);
    }
  }

  dismissBroadcastModal(markAsRead = false) {
    const modal = document.getElementById("broadcastAlertModal");
    if (!modal) return;
    
    if (markAsRead && this.currentBroadcastId) {
      this.markBroadcastAsRead(this.currentBroadcastId);
    }
    
    modal.classList.add("hidden");
    this.removeBroadcastEventListeners();
    this.currentBroadcastId = null;
  }

  async markBroadcastAsRead(broadcastId) {
    try {
      const response = await fetch(this.API.markBroadcastSeen, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({ broadcast_id: broadcastId })
      });
      
      const data = await response.json();
      if (data.success) {
        console.log('Broadcast marked as read');
        await this.updateUnreadBadges();
      }
    } catch (error) {
      console.error('Error marking broadcast as read:', error);
    }
  }

  getSeenBroadcasts() {
    try {
      const raw = localStorage.getItem(this.SEEN_BROADCASTS_KEY) || "[]";
      return JSON.parse(raw);
    } catch {
      return [];
    }
  }
  
  markBroadcastSeenLocal(id) {
    try {
      const seen = this.getSeenBroadcasts();
      if (!seen.includes(String(id))) {
        seen.push(String(id));
        localStorage.setItem(this.SEEN_BROADCASTS_KEY, JSON.stringify(seen));
      }
    } catch (e) { /* ignore */ }
  }

  // 
  // Utilities //
  showListView() {
    document.getElementById("messagesListView").classList.remove("hidden");
    document.getElementById("messageDetailView").classList.add("hidden");
  }

  escapeHtml(unsafe) {
    if (!unsafe && unsafe !== 0) return "";
    return String(unsafe)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  formatFileSize(bytes) {
    if (!bytes || bytes === 0) return "0 B";
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    const sizes = ["B", "KB", "MB", "GB", "TB"];
    return (bytes / Math.pow(1024, i)).toFixed(2) + " " + sizes[i];
  }

  getUserImage(photoPath) {
    if (!photoPath || typeof photoPath !== "string") {
      return "../backend/api/get_image.php?file=default-user.png&type=profile";
    }

    const normalized = photoPath.trim();
    if (!normalized) {
      return "../backend/api/get_image.php?file=default-user.png&type=profile";
    }

    if (normalized.startsWith("http://") || normalized.startsWith("https://") || normalized.startsWith("data:")) {
      return normalized;
    }

    if (normalized.includes("backend/api/get_image.php")) {
      return normalized;
    }

    if (normalized === "images/default-user.png" || normalized.endsWith("/default-user.png")) {
      return "../backend/api/get_image.php?file=default-user.png&type=profile";
    }

    const filename = normalized.split(/[\\/]/).pop();
    if (!filename) {
      return "../backend/api/get_image.php?file=default-user.png&type=profile";
    }

    return `../backend/api/get_image.php?file=${encodeURIComponent(filename)}&type=profile`;
  }

  // 
  // Search & Filter //
  updateListTitle() {
    const titleEl = document.getElementById("listTitle");
    if (!titleEl) return;
    const baseTitle = this.currentType.charAt(0).toUpperCase() + this.currentType.slice(1);
    titleEl.textContent = this.archivedMode ? `${baseTitle} (Archived)` : baseTitle;
  }

  searchMessages(q) {
    const items = document.querySelectorAll(".message-item");
    if (!items) return;
    items.forEach(it => {
      const txt = it.textContent.toLowerCase();
      it.style.display = txt.includes((q||"").toLowerCase()) ? "flex" : "none";
    });
    this.syncSelectAllCheckbox();
  }

  filterMessages(filter) {
    if (filter === "archived") {
      this.archivedMode = true;
      this.updateListTitle();
      this.loadMessages(this.currentType, 1);
      return;
    }

    if (this.archivedMode) {
      this.archivedMode = false;
      this.updateListTitle();
      this.loadMessages(this.currentType, 1);
    }

    if (filter === "all") return this.renderMessagesOnly(this.messages);
    if (filter === "unread") return this.renderMessagesOnly(this.messages.filter(m => !m.is_read));
    if (filter === "urgent") return this.renderMessagesOnly(this.messages.filter(m => m.is_urgent));
    if (filter === "with-attachments") {
      return this.renderMessagesOnly(this.messages.filter(m => Number(m.attachment_count || 0) > 0));
    }
    this.renderMessagesOnly(this.messages);
  }

  renderMessagesOnly(msgs) {
    const list = document.getElementById("messagesList");
    if (!list) return;
    if (!msgs || msgs.length === 0) {
      list.innerHTML = `<div class="empty-state"><i class="fas fa-inbox"></i><h3>No messages</h3></div>`;
      this.updateBatchDeleteControls();
      this.syncSelectAllCheckbox();
      return;
    }
    list.innerHTML = msgs.map(m => this.messageRowHtml(m)).join("");
    this.bindMessageListInteractions(list);
    this.updateBatchDeleteControls();
  }

  // 
  // LIVE STAFF CHAT, VOICE NOTES & WEBRTC CALLS
  // 
  async initializeLiveChat() {
    try {
      this.injectLiveChatDock();
      await this.loadLiveChatUsers();
      this.bindLiveChatEvents();
      this.startLiveChatPolling();
    } catch (error) {
      console.warn("Live chat initialization failed:", error);
    }
  }

  injectLiveChatDock() {
    if (document.getElementById("liveChatDock")) return;
    const dock = document.createElement("section");
    dock.id = "liveChatDock";
    dock.className = "live-chat-dock collapsed";
    dock.innerHTML = `
      <button class="live-chat-launcher" id="liveChatLauncher" type="button">
        <i class="fas fa-comments"></i><span>Live Chat</span><strong id="liveChatOnlineCount">0</strong>
      </button>
      <div class="live-chat-panel" id="liveChatPanel" aria-label="Staff live chat">
        <div class="live-chat-head">
          <div><strong>Staff Live Chat</strong><small id="liveChatStatusText">Connecting...</small></div>
          <button type="button" id="liveChatClose" title="Minimize"><i class="fas fa-minus"></i></button>
        </div>
        <div class="live-chat-body">
          <aside class="live-chat-users" id="liveChatUsers"></aside>
          <section class="live-chat-thread">
            <div class="live-chat-peer" id="liveChatPeer">
              <span>Select a staff member</span>
            </div>
            <div class="live-chat-messages" id="liveChatMessages">
              <div class="live-chat-empty">Choose a staff member to start a live conversation.</div>
            </div>
            <div class="live-chat-composer">
              <div class="emoji-picker hidden" id="liveEmojiPicker"></div>
              <div class="live-chat-tools">
                <button type="button" id="liveEmojiBtn" title="Emoji"><i class="far fa-smile"></i></button>
                <button type="button" id="liveAttachBtn" title="Attachment"><i class="fas fa-paperclip"></i></button>
                <button type="button" id="liveVoiceBtn" title="Record voice note"><i class="fas fa-microphone"></i></button>
                <button type="button" id="liveAudioCallBtn" title="Audio call"><i class="fas fa-phone"></i></button>
                <button type="button" id="liveVideoCallBtn" title="Video call"><i class="fas fa-video"></i></button>
              </div>
              <textarea id="liveChatInput" rows="2" placeholder="Type a message..." disabled></textarea>
              <button type="button" id="liveSendBtn" disabled><i class="fas fa-paper-plane"></i></button>
              <input type="file" id="liveChatAttachmentInput" class="hidden">
            </div>
          </section>
        </div>
      </div>
      <div class="live-call-modal hidden" id="liveCallModal">
        <div class="live-call-card">
          <div class="live-call-head">
            <strong id="liveCallTitle">Call</strong>
            <span id="liveCallStatus">Preparing...</span>
          </div>
          <div class="live-video-grid">
            <video id="liveRemoteVideo" autoplay playsinline></video>
            <video id="liveLocalVideo" autoplay muted playsinline></video>
          </div>
          <div class="live-call-actions">
            <button type="button" id="liveAcceptCallBtn" class="hidden"><i class="fas fa-phone"></i> Accept</button>
            <button type="button" id="liveRejectCallBtn" class="hidden"><i class="fas fa-phone-slash"></i> Reject</button>
            <button type="button" id="liveEndCallBtn"><i class="fas fa-phone-slash"></i> End</button>
          </div>
        </div>
      </div>
    `;
    document.body.appendChild(dock);

    const emojis = ["😀","😂","😊","😍","👍","🙏","👏","✅","⚠️","📌","📎","📞","🎥","☕","🔥","💡","🎉","❤️"];
    const picker = document.getElementById("liveEmojiPicker");
    if (picker) {
      picker.innerHTML = emojis.map((emoji) => `<button type="button" data-emoji="${emoji}">${emoji}</button>`).join("");
    }
  }

  async loadLiveChatUsers() {
    const response = await fetch(this.API.liveChatBootstrap, { credentials: "include", cache: "no-store" });
    const data = await response.json();
    if (!data.success) throw new Error(data.message || "Unable to load live chat users.");
    this.liveChat.users = data.users || [];
    this.renderLiveChatUsers();
    this.updateLiveChatStatus();
  }

  bindLiveChatEvents() {
    document.getElementById("liveChatLauncher")?.addEventListener("click", () => {
      document.getElementById("liveChatDock")?.classList.remove("collapsed");
    });
    document.getElementById("liveChatClose")?.addEventListener("click", () => {
      document.getElementById("liveChatDock")?.classList.add("collapsed");
    });
    document.getElementById("liveSendBtn")?.addEventListener("click", () => this.sendLiveChatText());
    document.getElementById("liveChatInput")?.addEventListener("keydown", (event) => {
      if (event.key === "Enter" && !event.shiftKey) {
        event.preventDefault();
        this.sendLiveChatText();
      }
    });
    document.getElementById("liveEmojiBtn")?.addEventListener("click", () => {
      document.getElementById("liveEmojiPicker")?.classList.toggle("hidden");
    });
    document.getElementById("liveEmojiPicker")?.addEventListener("click", (event) => {
      const button = event.target.closest("[data-emoji]");
      if (!button) return;
      const input = document.getElementById("liveChatInput");
      if (input) {
        input.value += button.dataset.emoji || "";
        input.focus();
      }
    });
    document.getElementById("liveAttachBtn")?.addEventListener("click", () => document.getElementById("liveChatAttachmentInput")?.click());
    document.getElementById("liveChatAttachmentInput")?.addEventListener("change", (event) => this.sendLiveChatAttachment(event.target.files?.[0]));
    document.getElementById("liveVoiceBtn")?.addEventListener("click", () => this.toggleVoiceRecording());
    document.getElementById("liveAudioCallBtn")?.addEventListener("click", () => this.startLiveCall("audio"));
    document.getElementById("liveVideoCallBtn")?.addEventListener("click", () => this.startLiveCall("video"));
    document.getElementById("liveAcceptCallBtn")?.addEventListener("click", () => this.acceptIncomingCall());
    document.getElementById("liveRejectCallBtn")?.addEventListener("click", () => this.rejectIncomingCall());
    document.getElementById("liveEndCallBtn")?.addEventListener("click", () => this.endLiveCall());
  }

  renderLiveChatUsers() {
    const container = document.getElementById("liveChatUsers");
    if (!container) return;
    container.innerHTML = this.liveChat.users.map((user) => `
      <button type="button" class="live-chat-user ${this.liveChat.selectedUser?.userId === user.userId ? "active" : ""}" data-user-id="${user.userId}">
        <span class="presence-dot ${user.isOnline ? "online" : ""}"></span>
        <img src="${this.getUserImage(user.userPhoto)}" alt="${this.escapeHtml(user.userName)}">
        <span><strong>${this.escapeHtml(user.userName)}</strong><small>${this.escapeHtml(user.userRole)}${user.isOnline ? " - online" : ""}</small></span>
      </button>
    `).join("") || `<div class="live-chat-empty">No staff users available.</div>`;
    container.querySelectorAll(".live-chat-user").forEach((button) => {
      button.addEventListener("click", () => this.selectLiveChatUser(button.dataset.userId));
    });
  }

  updateLiveChatStatus() {
    const online = this.liveChat.users.filter((user) => user.isOnline).length;
    const count = document.getElementById("liveChatOnlineCount");
    const status = document.getElementById("liveChatStatusText");
    if (count) count.textContent = String(online);
    if (status) status.textContent = `${online} staff online`;
  }

  async selectLiveChatUser(userId) {
    const user = this.liveChat.users.find((item) => item.userId === userId);
    if (!user) return;
    this.liveChat.selectedUser = user;
    this.liveChat.lastMessageIdByUser[userId] = 0;
    this.renderLiveChatUsers();
    const peer = document.getElementById("liveChatPeer");
    if (peer) {
      peer.innerHTML = `<strong>${this.escapeHtml(user.userName)}</strong><small>${this.escapeHtml(user.userRole)}${user.isOnline ? " - online" : ""}</small>`;
    }
    ["liveChatInput","liveSendBtn","liveAudioCallBtn","liveVideoCallBtn","liveVoiceBtn","liveAttachBtn","liveEmojiBtn"].forEach((id) => {
      const element = document.getElementById(id);
      if (element) element.disabled = false;
    });
    const messages = document.getElementById("liveChatMessages");
    if (messages) messages.innerHTML = `<div class="live-chat-empty">Loading conversation...</div>`;
    await this.loadLiveChatMessages(true);
  }

  startLiveChatPolling() {
    this.stopLiveChat();
    this.liveChat.presenceTimer = setInterval(() => this.refreshLivePresence(), 10000);
    this.liveChat.messagePollTimer = setInterval(() => this.loadLiveChatMessages(false), 3000);
    this.liveChat.callPollTimer = setInterval(() => this.pollLiveCalls(), 2500);
    this.refreshLivePresence();
    this.pollLiveCalls();
  }

  stopLiveChat() {
    ["presenceTimer","messagePollTimer","callPollTimer","signalPollTimer"].forEach((key) => {
      if (this.liveChat[key]) clearInterval(this.liveChat[key]);
      this.liveChat[key] = null;
    });
  }

  async refreshLivePresence() {
    try {
      const response = await fetch(this.API.liveChatPresence, { credentials: "include", cache: "no-store" });
      const data = await response.json();
      if (!data.success) return;
      const presence = data.presence || {};
      this.liveChat.users = this.liveChat.users.map((user) => ({ ...user, ...(presence[user.userId] || {}) }));
      if (this.liveChat.selectedUser) {
        this.liveChat.selectedUser = this.liveChat.users.find((user) => user.userId === this.liveChat.selectedUser.userId) || this.liveChat.selectedUser;
      }
      this.renderLiveChatUsers();
      this.updateLiveChatStatus();
    } catch (_error) {}
  }

  async loadLiveChatMessages(force = false) {
    const peer = this.liveChat.selectedUser;
    if (!peer) return;
    const since = force ? 0 : (this.liveChat.lastMessageIdByUser[peer.userId] || 0);
    try {
      const response = await fetch(`${this.API.liveChatMessages}?peer_id=${encodeURIComponent(peer.userId)}&since_id=${since}`, {
        credentials: "include",
        cache: "no-store"
      });
      const data = await response.json();
      if (!data.success) return;
      this.renderLiveChatMessages(data.messages || [], force);
    } catch (error) {
      console.warn("Live chat message poll failed:", error);
    }
  }

  renderLiveChatMessages(messages, replace = false) {
    const container = document.getElementById("liveChatMessages");
    if (!container) return;
    if (replace) container.innerHTML = "";
    if (!messages.length && replace) {
      container.innerHTML = `<div class="live-chat-empty">No messages yet. Say hello.</div>`;
      return;
    }
    const empty = container.querySelector(".live-chat-empty");
    if (empty && messages.length) empty.remove();
    messages.forEach((message) => {
      this.liveChat.lastMessageIdByUser[this.liveChat.selectedUser.userId] = Math.max(this.liveChat.lastMessageIdByUser[this.liveChat.selectedUser.userId] || 0, Number(message.id || 0));
      const bubble = document.createElement("div");
      bubble.className = `live-chat-bubble ${message.isOwn ? "own" : ""}`;
      bubble.innerHTML = this.liveChatMessageHtml(message);
      container.appendChild(bubble);
    });
    container.scrollTop = container.scrollHeight;
  }

  liveChatMessageHtml(message) {
    const fileUrl = message.filePath ? `../backend/${message.filePath}` : "";
    const filePart = message.kind === "voice" && fileUrl
      ? `<audio controls src="${fileUrl}"></audio>`
      : (fileUrl ? `<a href="${fileUrl}" target="_blank" rel="noopener"><i class="fas fa-paperclip"></i> ${this.escapeHtml(message.fileName || "Attachment")}</a>` : "");
    return `
      ${message.text ? `<p>${this.formatMessageText(message.text)}</p>` : ""}
      ${filePart}
      <small>${this.formatTimeShort(message.createdAt)}</small>
    `;
  }

  async sendLiveChatText() {
    const input = document.getElementById("liveChatInput");
    const text = (input?.value || "").trim();
    if (!text || !this.liveChat.selectedUser) return;
    await this.sendLiveChatPayload({ message_text: text, message_kind: "text" });
    input.value = "";
  }

  async sendLiveChatAttachment(file, kind = "attachment", text = "") {
    if (!file || !this.liveChat.selectedUser) return;
    await this.sendLiveChatPayload({ message_kind: kind, message_text: text, file });
    const input = document.getElementById("liveChatAttachmentInput");
    if (input) input.value = "";
  }

  async sendLiveChatPayload({ message_text = "", message_kind = "text", file = null } = {}) {
    const peer = this.liveChat.selectedUser;
    if (!peer) return;
    const form = new FormData();
    form.append("recipient_id", peer.userId);
    form.append("message_text", message_text);
    form.append("message_kind", message_kind);
    if (file) form.append("file", file, file.name || `${message_kind}.webm`);
    try {
      const response = await fetch(this.API.liveChatSend, { method: "POST", credentials: "include", body: form });
      const data = await response.json();
      if (!data.success) throw new Error(data.message || "Unable to send chat message.");
      await this.loadLiveChatMessages(false);
    } catch (error) {
      this.showErrorModal("Live Chat", error.message || "Unable to send chat message.");
    }
  }

  async toggleVoiceRecording() {
    const button = document.getElementById("liveVoiceBtn");
    if (this.liveChat.recorder && this.liveChat.recorder.state === "recording") {
      this.liveChat.recorder.stop();
      button?.classList.remove("recording");
      return;
    }
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      this.liveChat.recordedChunks = [];
      this.liveChat.recorder = new MediaRecorder(stream);
      this.liveChat.recorder.ondataavailable = (event) => {
        if (event.data?.size) this.liveChat.recordedChunks.push(event.data);
      };
      this.liveChat.recorder.onstop = async () => {
        stream.getTracks().forEach((track) => track.stop());
        const blob = new Blob(this.liveChat.recordedChunks, { type: "audio/webm" });
        const file = new File([blob], `voice-note-${Date.now()}.webm`, { type: "audio/webm" });
        await this.sendLiveChatAttachment(file, "voice", "Voice note");
      };
      this.liveChat.recorder.start();
      button?.classList.add("recording");
    } catch (error) {
      this.showErrorModal("Voice Note", "Microphone access is required to record voice notes.");
    }
  }

  async startLiveCall(callType = "audio") {
    if (!this.liveChat.selectedUser) return;
    try {
      const response = await this.postLiveCall({ action: "start", callee_id: this.liveChat.selectedUser.userId, call_type: callType });
      this.liveChat.activeCall = response.call;
      this.liveChat.isCallInitiator = true;
      this.liveChat.lastSignalId = 0;
      await this.preparePeerConnection(callType, this.liveChat.selectedUser.userId);
      const offer = await this.liveChat.peerConnection.createOffer();
      await this.liveChat.peerConnection.setLocalDescription(offer);
      await this.sendLiveSignal("offer", offer);
      this.showCallModal(`Calling ${this.liveChat.selectedUser.userName}`, "Ringing...", callType, false);
      this.startSignalPolling();
    } catch (error) {
      this.showErrorModal("Call Failed", error.message || "Unable to start call.");
      this.cleanupLiveCall();
    }
  }

  async pollLiveCalls() {
    if (this.liveChat.activeCall) return;
    try {
      const data = await this.postLiveCall({ action: "poll" }, "GET");
      const incoming = (data.calls || []).find((call) => call.calleeId === this.userId && call.status === "ringing");
      if (incoming) {
        this.liveChat.activeCall = incoming;
        this.liveChat.isCallInitiator = false;
        this.liveChat.lastSignalId = 0;
        this.showCallModal(`${incoming.callerName} is calling`, `${incoming.callType} call`, incoming.callType, true);
      }
    } catch (_error) {}
  }

  showCallModal(title, status, callType = "audio", incoming = false) {
    const modal = document.getElementById("liveCallModal");
    modal?.classList.remove("hidden", "audio-only");
    if (callType === "audio") modal?.classList.add("audio-only");
    document.getElementById("liveCallTitle").textContent = title;
    document.getElementById("liveCallStatus").textContent = status;
    document.getElementById("liveAcceptCallBtn")?.classList.toggle("hidden", !incoming);
    document.getElementById("liveRejectCallBtn")?.classList.toggle("hidden", !incoming);
  }

  async acceptIncomingCall() {
    const call = this.liveChat.activeCall;
    if (!call) return;
    try {
      await this.postLiveCall({ action: "update", call_id: call.callId, status: "accepted" });
      await this.preparePeerConnection(call.callType, call.callerId);
      this.startSignalPolling();
      document.getElementById("liveCallStatus").textContent = "Connecting...";
      document.getElementById("liveAcceptCallBtn")?.classList.add("hidden");
      document.getElementById("liveRejectCallBtn")?.classList.add("hidden");
    } catch (error) {
      this.showErrorModal("Call Failed", error.message || "Unable to accept call.");
      this.cleanupLiveCall();
    }
  }

  async rejectIncomingCall() {
    if (this.liveChat.activeCall) {
      await this.sendLiveSignal("hangup", { reason: "rejected" }).catch(() => {});
      await this.postLiveCall({ action: "update", call_id: this.liveChat.activeCall.callId, status: "rejected" }).catch(() => {});
    }
    this.cleanupLiveCall();
  }

  async endLiveCall() {
    const call = this.liveChat.activeCall;
    if (call) {
      const peerId = call.callerId === this.userId ? call.calleeId : call.callerId;
      await this.sendLiveSignal("hangup", { reason: "ended" }).catch(() => {});
      await this.postLiveCall({ action: "update", call_id: call.callId, status: "ended" }).catch(() => {});
      console.log("Ended call with", peerId);
    }
    this.cleanupLiveCall();
  }

  getLiveCallAudioConstraints() {
    return {
      echoCancellation: { ideal: true },
      noiseSuppression: { ideal: true },
      autoGainControl: { ideal: true },
      channelCount: { ideal: 1 },
      sampleRate: { ideal: 48000 },
      sampleSize: { ideal: 16 }
    };
  }

  isHandheldLiveCallDevice() {
    return window.matchMedia?.("(pointer: coarse), (max-width: 768px)")?.matches || false;
  }

  async getLiveCallMediaStream(callType) {
    const constraints = {
      audio: this.getLiveCallAudioConstraints(),
      video: callType === "video"
    };
    try {
      return await navigator.mediaDevices.getUserMedia(constraints);
    } catch (error) {
      return navigator.mediaDevices.getUserMedia({ audio: true, video: callType === "video" });
    }
  }

  async tuneLiveCallAudioTrack(stream) {
    const audioTrack = stream?.getAudioTracks?.()[0];
    if (!audioTrack) return;
    audioTrack.contentHint = "speech";
    if (audioTrack.applyConstraints) {
      await audioTrack.applyConstraints(this.getLiveCallAudioConstraints()).catch(() => {});
    }
  }

  configureLiveCallMediaElements() {
    const localVideo = document.getElementById("liveLocalVideo");
    const remoteVideo = document.getElementById("liveRemoteVideo");
    if (localVideo) {
      localVideo.muted = true;
      localVideo.volume = 0;
      localVideo.autoplay = true;
      localVideo.playsInline = true;
      localVideo.setAttribute("playsinline", "");
    }
    if (remoteVideo) {
      remoteVideo.muted = false;
      remoteVideo.autoplay = true;
      remoteVideo.playsInline = true;
      remoteVideo.setAttribute("playsinline", "");
      remoteVideo.volume = this.isHandheldLiveCallDevice() ? 0.78 : 1;
    }
  }

  async preparePeerConnection(callType, peerId) {
    this.configureLiveCallMediaElements();
    this.liveChat.localStream = await this.getLiveCallMediaStream(callType);
    await this.tuneLiveCallAudioTrack(this.liveChat.localStream);
    this.liveChat.remoteStream = new MediaStream();
    const localVideo = document.getElementById("liveLocalVideo");
    const remoteVideo = document.getElementById("liveRemoteVideo");
    if (localVideo) localVideo.srcObject = this.liveChat.localStream;
    if (remoteVideo) remoteVideo.srcObject = this.liveChat.remoteStream;
    const pc = new RTCPeerConnection({ iceServers: [{ urls: "stun:stun.l.google.com:19302" }] });
    this.liveChat.peerConnection = pc;
    this.liveChat.localStream.getTracks().forEach((track) => pc.addTrack(track, this.liveChat.localStream));
    pc.ontrack = (event) => {
      event.streams[0]?.getTracks().forEach((track) => {
        if (!this.liveChat.remoteStream.getTracks().some((existing) => existing.id === track.id)) {
          this.liveChat.remoteStream.addTrack(track);
        }
      });
      if (remoteVideo) remoteVideo.srcObject = this.liveChat.remoteStream;
      remoteVideo?.play?.().catch(() => {});
    };
    pc.onicecandidate = (event) => {
      if (event.candidate) this.sendLiveSignal("ice", event.candidate.toJSON ? event.candidate.toJSON() : event.candidate).catch(() => {});
    };
    pc.onconnectionstatechange = () => {
      const status = document.getElementById("liveCallStatus");
      if (status) status.textContent = pc.connectionState;
      if (["failed", "closed", "disconnected"].includes(pc.connectionState)) {
        setTimeout(() => this.cleanupLiveCall(), 1200);
      }
    };
  }

  startSignalPolling() {
    if (this.liveChat.signalPollTimer) clearInterval(this.liveChat.signalPollTimer);
    this.liveChat.signalPollTimer = setInterval(() => this.pollLiveSignals(), 1200);
    this.pollLiveSignals();
  }

  async pollLiveSignals() {
    const call = this.liveChat.activeCall;
    if (!call) return;
    try {
      const data = await this.postLiveCall({ action: "signals", call_id: call.callId, after_id: this.liveChat.lastSignalId }, "GET");
      for (const signal of data.signals || []) {
        this.liveChat.lastSignalId = Math.max(this.liveChat.lastSignalId, Number(signal.signalId || 0));
        await this.handleLiveSignal(signal);
      }
    } catch (_error) {}
  }

  async handleLiveSignal(signal) {
    const call = this.liveChat.activeCall;
    const pc = this.liveChat.peerConnection;
    if (!call) return;
    if (signal.signalType === "hangup") {
      this.cleanupLiveCall();
      return;
    }
    if (signal.signalType === "offer") {
      if (!pc) {
        await this.preparePeerConnection(call.callType, signal.senderId);
      }
      await this.liveChat.peerConnection.setRemoteDescription(new RTCSessionDescription(signal.payload));
      const answer = await this.liveChat.peerConnection.createAnswer();
      await this.liveChat.peerConnection.setLocalDescription(answer);
      await this.sendLiveSignal("answer", answer);
      document.getElementById("liveCallStatus").textContent = "Connected";
      return;
    }
    if (signal.signalType === "answer" && pc) {
      await pc.setRemoteDescription(new RTCSessionDescription(signal.payload));
      document.getElementById("liveCallStatus").textContent = "Connected";
      return;
    }
    if (signal.signalType === "ice" && pc && signal.payload) {
      await pc.addIceCandidate(new RTCIceCandidate(signal.payload)).catch(() => {});
    }
  }

  async sendLiveSignal(signalType, payload) {
    const call = this.liveChat.activeCall;
    if (!call) return;
    const recipientId = call.callerId === this.userId ? call.calleeId : call.callerId;
    return this.postLiveCall({ action: "signal", call_id: call.callId, recipient_id: recipientId, signal_type: signalType, payload });
  }

  async postLiveCall(payload, method = "POST") {
    const options = method === "GET"
      ? { credentials: "include", cache: "no-store" }
      : { method: "POST", credentials: "include", headers: { "Content-Type": "application/json" }, body: JSON.stringify(payload) };
    const url = method === "GET" ? `${this.API.liveChatCall}?${new URLSearchParams(payload)}` : this.API.liveChatCall;
    const response = await fetch(url, options);
    const data = await response.json();
    if (!data.success) throw new Error(data.message || "Live call request failed.");
    return data;
  }

  cleanupLiveCall() {
    if (this.liveChat.signalPollTimer) clearInterval(this.liveChat.signalPollTimer);
    this.liveChat.signalPollTimer = null;
    this.liveChat.peerConnection?.close();
    this.liveChat.localStream?.getTracks().forEach((track) => track.stop());
    this.liveChat.remoteStream?.getTracks().forEach((track) => track.stop());
    this.liveChat.peerConnection = null;
    this.liveChat.localStream = null;
    this.liveChat.remoteStream = null;
    this.liveChat.activeCall = null;
    this.liveChat.lastSignalId = 0;
    document.getElementById("liveCallModal")?.classList.add("hidden");
  }

  // 
  // Event listeners wiring
  // 
  setupEventListeners() {
    // nav items - All users can click broadcast nav item
    document.querySelectorAll(".nav-item").forEach(item => {
      item.addEventListener("click", (e) => {
        e.preventDefault();
        const t = item.dataset.type || "inbox";
        
        document.querySelectorAll(".nav-item").forEach(i => i.classList.toggle("active", i === item));
        this.switchView(t);
      });
    });

    // compose buttons
    document.getElementById("composeBtn")?.addEventListener("click", () => this.showComposeModal());
    document.getElementById("closeCompose")?.addEventListener("click", () => this.hideComposeModal());
    document.getElementById("cancelCompose")?.addEventListener("click", () => this.hideComposeModal());

    // compose form submit + file input
    document.getElementById("composeForm")?.addEventListener("submit", (e) => this.sendMessage(e));

    // refresh
    document.getElementById("refreshBtn")?.addEventListener("click", () => this.loadMessages(this.currentType, this.currentPage));
    // search + filter
    document.getElementById("messageSearch")?.addEventListener("input", (e) => this.searchMessages(e.target.value));
    document.getElementById("filterSelect")?.addEventListener("change", (e) => this.filterMessages(e.target.value));
    document.getElementById("batchDeleteBtn")?.addEventListener("click", () => this.confirmBatchDelete());
    document.getElementById("selectAllMessages")?.addEventListener("change", (e) => {
      const visibleIds = this.getVisibleMessageIds();
      visibleIds.forEach((id) => this.toggleMessageSelection(id, e.target.checked));
    });

    // back to list
    document.getElementById("backToList")?.addEventListener("click", () => this.showListView());

    // broadcast modal close on overlay/dismiss
    document.getElementById("broadcastAlertModal")?.addEventListener("click", (e) => {
      if (e.target.id === "broadcastAlertModal") this.dismissBroadcastModal(false);
    });

    // Add feedback button if exists
    const feedbackBtn = document.getElementById('feedbackBtn');
    if (feedbackBtn) {
      feedbackBtn.addEventListener('click', () => this.showFeedbackModal());
    }

    // detail action buttons are wired in setupActionButtons() and updateActionButtons()
  }

  switchView(type) {
    // Always show list view when switching navigation
    this.showListView();
    this.currentType = type;
    this.currentPage = 1;
    this.archivedMode = false;
    const filterSelect = document.getElementById("filterSelect");
    if (filterSelect) {
      filterSelect.value = "all";
    }
    this.updateListTitle();
    this.loadMessages(type, 1);
  }
}

// instantiate
new MessagesApp();


