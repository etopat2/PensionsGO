document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("fileTrackForm");
  const regNoInput = document.getElementById("fileTrackRegNo");
  const trackerDatalist = document.getElementById("trackerRegistryFiles");
  const fileTrackMatches = document.getElementById("fileTrackMatches");
  const results = document.getElementById("fileTrackResults");
  const moveForm = document.getElementById("fileMoveForm");
  const moveMessage = document.getElementById("fileMoveMessage");

  const moveRegNoInput = document.getElementById("moveRegNo");
  const moveFromInput = document.getElementById("moveFrom");
  const moveToInput = document.getElementById("moveTo");
  const moveReasonInput = document.getElementById("moveReason");
  const moveDeliveredByInput = document.getElementById("moveDeliveredBy");
  const moveExpectedReturnInput = document.getElementById("moveExpectedReturn");
  const moveTimestampInput = document.getElementById("moveTimestamp");
  const registryDatalist = document.getElementById("registryFileNumbers");

  const historyModalOverlay = document.getElementById("fileHistoryModalOverlay");
  const historyModalBody = document.getElementById("fileHistoryModalBody");
  const historyModalFileInfo = document.getElementById("fileHistoryModalFileInfo");
  const historyModalCloseBtn = document.getElementById("fileHistoryModalCloseBtn");
  const historyModalCloseFooterBtn = document.getElementById("fileHistoryModalCloseFooterBtn");
  const historyRefreshBtn = document.getElementById("fileHistoryRefreshBtn");
  const historyRecordBtn = document.getElementById("fileHistoryRecordBtn");

  let lastLoadedRegNo = "";
  let suggestionTimer = null;
  let trackerSuggestionTimer = null;
  let trackerSuggestions = [];
  let trackerRequestSeq = 0;
  let movementRefreshInFlight = false;
  let isHistoryModalOpen = false;
  let moveOriginRequestSeq = 0;

  function setCurrentTimestamp() {
    if (!moveTimestampInput) return;
    moveTimestampInput.value = formatDateTime(new Date());
  }

  function setTrackerMessage(message, type = "neutral") {
    if (!results) return;
    const safeType = type === "error" ? "error" : type === "success" ? "success" : "neutral";
    results.innerHTML = `<div class="app-state-message app-state-${safeType}">${escapeHtml(message)}</div>`;
  }

  function setMoveFromValue(value) {
    if (!moveFromInput) return;
    moveFromInput.value = String(value || "").trim() || "Front Desk";
  }

  function findTrackerFile(regNo) {
    const target = String(regNo || "").trim().toLowerCase();
    if (!target) return null;
    return trackerSuggestions.find((file) => String(file.regNo || "").trim().toLowerCase() === target) || null;
  }

  function setHistoryFileInfo(regNo) {
    if (!historyModalFileInfo) return;
    const file = findTrackerFile(regNo);
    const name = file && file.name ? ` - ${String(file.name).trim()}` : "";
    historyModalFileInfo.textContent = `${regNo}${name}`;
  }

  function openHistoryModal(regNo) {
    if (!historyModalOverlay) return;
    setHistoryFileInfo(regNo);
    historyModalOverlay.style.display = "flex";
    document.body.classList.add("file-history-modal-open");
    isHistoryModalOpen = true;
  }

  function closeHistoryModal() {
    if (!historyModalOverlay) return;
    historyModalOverlay.style.display = "none";
    document.body.classList.remove("file-history-modal-open");
    isHistoryModalOpen = false;
  }

  setCurrentTimestamp();
  setInterval(setCurrentTimestamp, 1000);

  async function fetchRegistrySuggestions(query, limit = 20) {
    if (!query || query.trim().length < 1) {
      return [];
    }

    try {
      const res = await fetch(`../backend/api/search_registry_files.php?q=${encodeURIComponent(query.trim())}&limit=${encodeURIComponent(String(limit))}`, {
        credentials: "include",
        cache: "no-store"
      });
      const data = await res.json();
      if (!data.success || !Array.isArray(data.files)) {
        return [];
      }
      return data.files;
    } catch (err) {
      console.error("Unable to load registry suggestions:", err);
      return [];
    }
  }

  function renderTrackerMatches(files, query) {
    if (!fileTrackMatches) return;
    const searchTerm = String(query || "").trim();
    if (!searchTerm) {
      fileTrackMatches.innerHTML = "";
      return;
    }

    if (!Array.isArray(files) || files.length === 0) {
      fileTrackMatches.innerHTML = '<div class="app-state-message app-state-neutral">No matching files found.</div>';
      return;
    }

    fileTrackMatches.innerHTML = `
      <div class="file-match-list">
        ${files.map((file) => {
          const regNo = String(file.regNo || "").trim();
          const name = String(file.name || "Unknown").trim();
          const title = String(file.title || "N/A").trim();
          const availability = String(file.availability_status || "in_shelf").trim();
          return `
            <button type="button" class="file-match-item" data-reg="${escapeHtml(regNo)}">
              <span class="file-match-primary">${escapeHtml(regNo)} - ${escapeHtml(name)}</span>
              <span class="file-match-meta">${escapeHtml(title)} | ${escapeHtml(availability)}</span>
            </button>
          `;
        }).join("")}
      </div>
    `;

    fileTrackMatches.querySelectorAll(".file-match-item").forEach((btn) => {
      btn.addEventListener("click", async () => {
        const regNo = String(btn.getAttribute("data-reg") || "").trim();
        if (!regNo) return;
        if (regNoInput) regNoInput.value = regNo;
        await loadMovements(regNo);
      });
    });
  }

  async function loadTrackerSuggestions(query) {
    const requestId = ++trackerRequestSeq;
    const files = await fetchRegistrySuggestions(query, 30);
    if (requestId !== trackerRequestSeq) {
      // Ignore stale async responses when a newer query is already in-flight.
      return;
    }

    trackerSuggestions = files;

    if (trackerDatalist) {
      trackerDatalist.innerHTML = files.map((file) => {
        const label = `${file.regNo} - ${file.name || "Unknown"} (${file.availability_status || "in_shelf"})`;
        return `<option value="${escapeHtml(file.regNo)}" label="${escapeHtml(label)}"></option>`;
      }).join("");
    }

    renderTrackerMatches(files, query);

    // Auto-open history once typing narrows down to exactly one registry file.
    if (files.length === 1) {
      const uniqueRegNo = String(files[0]?.regNo || "").trim();
      if (uniqueRegNo && (uniqueRegNo !== lastLoadedRegNo || !isHistoryModalOpen)) {
        await loadMovements(uniqueRegNo);
      }
    }
  }

  function movementSort(a, b) {
    const aTime = parseDateTime(a?.moved_at)?.getTime() || 0;
    const bTime = parseDateTime(b?.moved_at)?.getTime() || 0;
    if (bTime !== aTime) return bTime - aTime;
    return Number(b?.movement_id || 0) - Number(a?.movement_id || 0);
  }

  async function loadMovements(regNo, options = {}) {
    const targetRegNo = String(regNo || "").trim();
    if (!targetRegNo || !historyModalBody) return;

    const shouldOpenModal = options.openModal !== false;
    lastLoadedRegNo = targetRegNo;
    setHistoryFileInfo(targetRegNo);

    if (shouldOpenModal) {
      openHistoryModal(targetRegNo);
      setTrackerMessage(`Showing movement history for ${targetRegNo}.`, "success");
    }

    const hasExistingList = !!historyModalBody.querySelector(".movement-list");
    if (!hasExistingList) {
      historyModalBody.innerHTML = '<div class="app-state-message app-state-neutral">Loading history...</div>';
    }

    try {
      const res = await fetch(`../backend/api/get_file_movements.php?regNo=${encodeURIComponent(targetRegNo)}`, {
        credentials: "include",
        cache: "no-store"
      });
      const data = await res.json();
      if (!data.success) {
        historyModalBody.innerHTML = `<div class="app-state-message app-state-error">${escapeHtml(data.message || "Unable to load movement history.")}</div>`;
        return;
      }

      const movements = Array.isArray(data.movements) ? data.movements.slice().sort(movementSort) : [];
      setMoveFromValue(data.summary?.current_holder_office || "Front Desk");
      if (!movements.length) {
        historyModalBody.innerHTML = '<div class="app-state-message app-state-neutral">No movement history found.</div>';
        return;
      }

      upsertMovementItems(movements, targetRegNo, data.summary || {});
      updateVisibleDurations();
    } catch (err) {
      console.error("Load movements failed:", err);
      historyModalBody.innerHTML = '<div class="app-state-message app-state-error">Unable to load movement history.</div>';
    }
  }

  async function resolveMovementOrigin(regNo) {
    const targetRegNo = String(regNo || "").trim();
    if (!targetRegNo) {
      setMoveFromValue("Front Desk");
      return;
    }

    const requestId = ++moveOriginRequestSeq;
    try {
      const res = await fetch(`../backend/api/get_file_movements.php?regNo=${encodeURIComponent(targetRegNo)}`, {
        credentials: "include",
        cache: "no-store"
      });
      const data = await res.json();
      if (requestId !== moveOriginRequestSeq) return;
      if (data.success) {
        setMoveFromValue(data.summary?.current_holder_office || "Front Desk");
      }
    } catch (error) {
      console.error("Unable to resolve file origin:", error);
    }
  }

  function ensureMovementListContainer(summary = {}) {
    if (!historyModalBody) return null;

    let list = historyModalBody.querySelector(".movement-list");
    let headerText = historyModalBody.querySelector(".movement-list-summary");

    if (!list) {
      historyModalBody.innerHTML = `
        <div class="movement-list-header">
          <h4>Movement History</h4>
          <small class="movement-list-summary">Live updates enabled</small>
        </div>
        <div class="movement-list"></div>
      `;
      list = historyModalBody.querySelector(".movement-list");
      headerText = historyModalBody.querySelector(".movement-list-summary");
    }

    if (headerText) {
      const total = Number(summary.total_movements || 0);
      const open = Number(summary.open_movements || 0);
      const returned = Number(summary.returned_movements || 0);
      headerText.textContent = `Total ${total} | Open ${open} | Returned ${returned}`;
    }

    return list;
  }

  function movementCardMarkup(item, regNo) {
    const duration = item.duration_seconds ? formatDuration(item.duration_seconds) : "N/A";
    const isReturned = Boolean(item.returned_at);
    const deliveredBy = item.delivered_by_display || item.delivered_by || "N/A";
    const toOffice = item.to_office_display || item.to_office || "N/A";
    const movedAt = item.moved_at || "";
    const returnedAt = item.returned_at || "";

    return `
      <div class="movement-item-main">
        <strong>${escapeHtml(item.from_office || "Unknown")} -> ${escapeHtml(toOffice)}</strong>
        <div class="movement-meta movement-timeline">
          <span>Moved: ${escapeHtml(movedAt)}</span>
          ${isReturned ? `<span>Returned: ${escapeHtml(returnedAt)}</span>` : ""}
          <span>Duration: <b class="movement-duration" data-moved-at="${escapeHtml(movedAt)}" data-returned-at="${escapeHtml(returnedAt)}">${duration}</b></span>
        </div>
        <div class="movement-meta movement-route">
          <span><b>Delivered by:</b> ${escapeHtml(deliveredBy)}</span>
          <span><b>To Office:</b> ${escapeHtml(toOffice)}</span>
        </div>
        <div class="movement-reason">${escapeHtml(item.reason || "No reason provided")}</div>
      </div>
      <div class="movement-item-actions">
        ${item.can_return ? `<button class="btn-secondary return-btn" data-return-id="${Number(item.movement_id)}" data-regno="${escapeHtml(item.regNo || regNo)}">Mark as Returned</button>` : `<span class="movement-status ${isReturned ? "returned" : "open"}">${isReturned ? "Returned" : "Out"}</span>`}
      </div>
    `;
  }

  function upsertMovementItems(movements, regNo, summary = {}) {
    const list = ensureMovementListContainer(summary);
    if (!list) return;

    // Reconcile by movement_id so periodic refresh updates cards in place
    // instead of replacing the entire history container.
    const seen = new Set();
    movements.forEach((item) => {
      const movementId = Number(item.movement_id);
      if (!movementId) return;
      seen.add(String(movementId));

      let node = list.querySelector(`.movement-item[data-movement-id="${movementId}"]`);
      if (!node) {
        node = document.createElement("div");
        node.className = "movement-item";
        node.setAttribute("data-movement-id", String(movementId));
      }

      node.innerHTML = movementCardMarkup(item, regNo);
      list.appendChild(node);
    });

    list.querySelectorAll(".movement-item").forEach((node) => {
      const id = String(node.getAttribute("data-movement-id") || "");
      if (!seen.has(id)) {
        node.remove();
      }
    });

    bindReturnButtons();
  }

  function bindReturnButtons() {
    if (!historyModalBody) return;
    historyModalBody.querySelectorAll(".return-btn").forEach((btn) => {
      btn.onclick = async () => {
        const movementId = Number(btn.getAttribute("data-return-id"));
        const movementRegNo = btn.getAttribute("data-regno") || lastLoadedRegNo;
        const receiverValue = await appPrompt("Enter receiver name (optional):", "", {
          title: "Mark File Returned",
          confirmText: "Continue"
        });
        const receiver = receiverValue === null ? "" : String(receiverValue || "");
        await markMovementReturned(movementId, movementRegNo, receiver);
      };
    });
  }

  function parseDateTime(value) {
    const text = String(value || "").trim();
    if (!text) return null;
    const normalized = text.includes("T") ? text : text.replace(" ", "T");
    const dt = new Date(normalized);
    if (Number.isNaN(dt.getTime())) return null;
    return dt;
  }

  function updateVisibleDurations() {
    if (!historyModalBody) return;
    historyModalBody.querySelectorAll(".movement-duration").forEach((el) => {
      const movedAtText = String(el.getAttribute("data-moved-at") || "");
      const returnedAtText = String(el.getAttribute("data-returned-at") || "");
      const movedAt = parseDateTime(movedAtText);
      if (!movedAt) {
        el.textContent = "N/A";
        return;
      }
      const returnedAt = parseDateTime(returnedAtText);
      const end = returnedAt || new Date();
      const seconds = Math.max(0, Math.floor((end.getTime() - movedAt.getTime()) / 1000));
      el.textContent = formatDuration(seconds);
    });
  }

  async function markMovementReturned(movementId, regNo, receiver = "") {
    try {
      const res = await fetch("../backend/api/return_file_movement.php", {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          movement_id: movementId,
          regNo,
          received_by: receiver
        })
      });
      const data = await res.json();
      if (!data.success) {
        showFeedbackModal("error", "Return Failed", data.message || "Unable to return file.");
        return;
      }
      showFeedbackModal("success", "Returned", data.message || "File returned successfully.");
      await loadMovements(regNo, { openModal: false });
    } catch (err) {
      console.error("Return movement failed:", err);
      showFeedbackModal("error", "Return Failed", "Unable to mark file as returned.");
    }
  }

  async function loadRegistrySuggestions(query) {
    if (!registryDatalist) return;
    if (!query || query.trim().length < 1) {
      registryDatalist.innerHTML = "";
      return;
    }

    const files = await fetchRegistrySuggestions(query, 20);
    registryDatalist.innerHTML = files.map((file) => {
      const label = `${file.regNo} - ${file.name || "Unknown"} (${file.availability_status || "in_shelf"})`;
      return `<option value="${escapeHtml(file.regNo)}" label="${escapeHtml(label)}"></option>`;
    }).join("");
  }

  if (historyModalCloseBtn) {
    historyModalCloseBtn.addEventListener("click", closeHistoryModal);
  }

  if (historyModalCloseFooterBtn) {
    historyModalCloseFooterBtn.addEventListener("click", closeHistoryModal);
  }

  if (historyModalOverlay) {
    historyModalOverlay.addEventListener("click", (evt) => {
      if (evt.target === historyModalOverlay) {
        closeHistoryModal();
      }
    });
  }

  document.addEventListener("keydown", (evt) => {
    if (evt.key === "Escape" && isHistoryModalOpen) {
      closeHistoryModal();
    }
  });

  if (historyRefreshBtn) {
    historyRefreshBtn.addEventListener("click", async () => {
      if (!lastLoadedRegNo) return;
      await loadMovements(lastLoadedRegNo, { openModal: false });
    });
  }

  if (historyRecordBtn) {
    historyRecordBtn.addEventListener("click", () => {
      if (moveRegNoInput && lastLoadedRegNo) {
        moveRegNoInput.value = lastLoadedRegNo;
        setMoveFromValue(moveFromInput?.value || "Front Desk");
      }
      closeHistoryModal();
      const recorderPanel = document.querySelector(".recorder-panel");
      if (recorderPanel) {
        recorderPanel.scrollIntoView({ behavior: "smooth", block: "start" });
      }
      if (moveToInput) {
        moveToInput.focus();
      }
    });
  }

  if (regNoInput) {
    regNoInput.addEventListener("input", () => {
      if (trackerSuggestionTimer) clearTimeout(trackerSuggestionTimer);
      const query = regNoInput.value;
      trackerSuggestionTimer = setTimeout(() => {
        loadTrackerSuggestions(query);
      }, 150);
    });

    regNoInput.addEventListener("change", () => {
      const inputVal = String(regNoInput.value || "").trim();
      if (!inputVal) return;
      const exact = trackerSuggestions.find((file) => String(file.regNo || "").toLowerCase() === inputVal.toLowerCase());
      if (exact) {
        loadMovements(String(exact.regNo));
      }
    });
  }

  if (moveRegNoInput) {
    moveRegNoInput.addEventListener("input", () => {
      if (suggestionTimer) clearTimeout(suggestionTimer);
      const query = moveRegNoInput.value;
      suggestionTimer = setTimeout(() => {
        loadRegistrySuggestions(query);
      }, 180);
    });

    moveRegNoInput.addEventListener("change", () => {
      resolveMovementOrigin(moveRegNoInput.value);
    });

    moveRegNoInput.addEventListener("blur", () => {
      resolveMovementOrigin(moveRegNoInput.value);
    });
  }

  if (form) {
    form.addEventListener("submit", async (e) => {
      e.preventDefault();
      const query = regNoInput ? regNoInput.value.trim() : "";
      if (!query) {
        setTrackerMessage("Enter a file number.", "error");
        return;
      }

      const exact = trackerSuggestions.find((file) => String(file.regNo || "").toLowerCase() === query.toLowerCase());
      if (exact) {
        await loadMovements(String(exact.regNo));
        return;
      }

      const matches = await fetchRegistrySuggestions(query, 30);
      trackerSuggestions = matches;
      // Search priority:
      // 1) exact match, 2) one narrowed match, 3) list candidates, 4) fallback load.
      if (matches.length === 1) {
        if (regNoInput) regNoInput.value = String(matches[0].regNo || "");
        await loadMovements(String(matches[0].regNo || ""));
        return;
      }

      if (matches.length > 1) {
        renderTrackerMatches(matches, query);
        setTrackerMessage("Multiple files match. Select one from the list above.", "neutral");
        return;
      }

      if (/^[A-Za-z0-9/_-]+$/.test(query)) {
        await loadMovements(query);
        return;
      }

      setTrackerMessage("No matching file found.", "error");
    });
  }

  if (moveForm) {
    moveForm.addEventListener("submit", async (e) => {
      e.preventDefault();
      const payload = {
        regNo: moveRegNoInput ? moveRegNoInput.value.trim() : "",
        from_office: moveFromInput ? moveFromInput.value.trim() : "Front Desk",
        to_office: moveToInput ? moveToInput.value.trim() : "",
        reason: moveReasonInput ? moveReasonInput.value.trim() : "",
        delivered_by: moveDeliveredByInput ? moveDeliveredByInput.value.trim() : "",
        expected_return_at: moveExpectedReturnInput ? moveExpectedReturnInput.value : ""
      };

      if (!payload.regNo || !payload.to_office || !payload.delivered_by) {
        moveMessage.innerHTML = '<div class="app-state-message app-state-error">File Number, destination office, and Delivered By are required.</div>';
        showFeedbackModal("error", "Validation Error", "File Number, destination office, and Delivered By are required.");
        return;
      }

      try {
        const res = await fetch("../backend/api/record_file_movement.php", {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (!data.success) {
          moveMessage.innerHTML = `<div class="app-state-message app-state-error">${escapeHtml(data.message || "Unable to record movement.")}</div>`;
          showFeedbackModal("error", "Save Failed", data.message || "Unable to record movement.");
          return;
        }

        moveMessage.innerHTML = '<div class="app-state-message app-state-success">Movement recorded.</div>';
        showFeedbackModal("success", "Saved", "File movement recorded successfully.");
        moveForm.reset();
        if (moveDeliveredByInput) {
          moveDeliveredByInput.value = "";
        }
        if (moveRegNoInput) {
          moveRegNoInput.value = payload.regNo;
        }
        setMoveFromValue(payload.to_office);
        setCurrentTimestamp();
        await loadMovements(payload.regNo, { openModal: isHistoryModalOpen });
      } catch (err) {
        console.error("Record movement failed:", err);
        moveMessage.innerHTML = '<div class="app-state-message app-state-error">Failed to record movement.</div>';
        showFeedbackModal("error", "Save Failed", "Failed to record movement.");
      }
    });
  }

  setInterval(() => {
    // Poll movement updates conservatively and guard against overlap.
    if (lastLoadedRegNo && isHistoryModalOpen && !movementRefreshInFlight) {
      movementRefreshInFlight = true;
      loadMovements(lastLoadedRegNo, { openModal: false })
        .catch(() => {})
        .finally(() => {
          movementRefreshInFlight = false;
        });
    }
  }, 60000);

  setInterval(() => {
    // Recompute elapsed durations between refresh windows for live readability.
    if (isHistoryModalOpen) {
      updateVisibleDurations();
    }
  }, 30000);

  function formatDuration(seconds) {
    const totalSeconds = Number(seconds || 0);
    if (!totalSeconds || totalSeconds <= 0) return "0m";
    const mins = Math.floor(totalSeconds / 60);
    const hours = Math.floor(mins / 60);
    const days = Math.floor(hours / 24);
    if (days > 0) return `${days}d ${hours % 24}h`;
    if (hours > 0) return `${hours}h ${mins % 60}m`;
    return `${mins}m`;
  }

  function formatDateTime(dateObj) {
    const d = dateObj instanceof Date ? dateObj : new Date();
    const pad = (v) => String(v).padStart(2, "0");
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
  }

  function escapeHtml(value) {
    if (value === null || value === undefined) return "";
    return value
      .toString()
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function showFeedbackModal(type, title, message) {
    const existing = document.getElementById("crudFeedbackModal");
    if (existing) existing.remove();

    const modal = document.createElement("div");
    modal.id = "crudFeedbackModal";
    modal.className = "auth-modal-overlay";
    modal.innerHTML = `
      <div class="auth-modal">
        <div class="auth-modal-header">
          <h3>${escapeHtml(type === "success" ? "Success" : "Error")}: ${escapeHtml(title)}</h3>
        </div>
        <div class="auth-modal-body">
          <p>${escapeHtml(message)}</p>
        </div>
        <div class="auth-modal-footer logout-actions">
          <button type="button" class="auth-btn auth-btn-secondary" id="crudFeedbackOkBtn">OK</button>
        </div>
      </div>
    `;

    document.body.appendChild(modal);

    const close = () => modal.remove();
    const okBtn = document.getElementById("crudFeedbackOkBtn");
    if (okBtn) okBtn.addEventListener("click", close, { once: true });
    modal.addEventListener("click", (evt) => {
      if (evt.target === modal) close();
    });
  }
});
