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
  const historyReturnBtn = document.getElementById("fileHistoryReturnBtn");

  let lastLoadedRegNo = "";
  let suggestionTimer = null;
  let trackerSuggestionTimer = null;
  let trackerSuggestions = [];
  let trackerRequestSeq = 0;
  let movementRefreshInFlight = false;
  const serviceFileForm = document.getElementById("serviceFileFilter");
  const serviceFileResults = document.getElementById("serviceFileResults");
  const trackerModalOverlay = document.getElementById("trackerModalOverlay");
  const movementModalOverlay = document.getElementById("movementModalOverlay");
  const receiveModalOverlay = document.getElementById("receiveModalOverlay");
  const returnModalOverlay = document.getElementById("returnModalOverlay");
  const openMoveFileBtn = document.getElementById("openMoveFileBtn");
  const openReturnFileBtn = document.getElementById("openReturnFileBtn");
  const selectedServiceFileSummary = document.getElementById("selectedServiceFileSummary");
  const serviceFileDetailsOverlay = document.getElementById("serviceFileDetailsOverlay");
  const serviceFileDetailsBody = document.getElementById("serviceFileDetailsBody");
  const moveFileSubmitBtn = document.getElementById("moveFileSubmitBtn");
  const returnFileSubmitBtn = document.getElementById("returnFileSubmitBtn");
  const moveFileCustodyHint = document.getElementById("moveFileCustodyHint");
  const returnFileCustodyHint = document.getElementById("returnFileCustodyHint");
  let selectedServiceFile = null;
  const openOperationModal = (overlay) => { if (overlay) { overlay.style.display = "flex"; document.body.classList.add("file-history-modal-open"); } };
  const closeOperationModal = (overlay) => { if (overlay) { overlay.style.display = "none"; if (!isHistoryModalOpen) document.body.classList.remove("file-history-modal-open"); } };
  document.getElementById("openFileTrackerBtn")?.addEventListener("click", () => openOperationModal(trackerModalOverlay));
  document.getElementById("openReceiveFileBtn")?.addEventListener("click", () => openOperationModal(receiveModalOverlay));
  openMoveFileBtn?.addEventListener("click", () => {
    if (moveRegNoInput) moveRegNoInput.value = selectedServiceFile?.employeeNo || "";
    if (moveFromInput) { moveFromInput.value = String(selectedServiceFile?.registry_stage || "Registry").replaceAll("_", " "); moveFromInput.dataset.registry = selectedServiceFile?.registry_stage || "pending_processing"; }
    openOperationModal(movementModalOverlay);
    validateModalCustody("move");
  });
  openReturnFileBtn?.addEventListener("click", () => {
    document.getElementById("returnRegNo").value = selectedServiceFile?.employeeNo || "";
    document.getElementById("returnDestination").value = selectedServiceFile?.registry_stage || "pending_processing";
    openOperationModal(returnModalOverlay);
    validateModalCustody("return");
  });
  document.querySelectorAll("[data-close-operation]").forEach((button) => button.addEventListener("click", () => closeOperationModal(document.getElementById(button.dataset.closeOperation))));
  const receiveFileType = document.getElementById("receiveFileType");
  const receiveDestination = document.getElementById("receiveDestination");
  const receiveRegNo = document.getElementById("receiveRegNo");
  const receiveRecordMatch = document.getElementById("receiveRecordMatch");
  let receiveLookupTimer = null;
  let resolvedReceiveRecord = null;
  function syncReceiveDestinations() {
    if (!receiveDestination) return;
    const options = receiveFileType?.value === "pension"
      ? [["pension_file_registry", "Pension File Registry"]]
      : [["pending_processing", "Service Files Pending Processing"], ["still_in_process", "Service Files Still in Process"], ["archives", "Archives"]];
    receiveDestination.innerHTML = options.map(([value, label]) => `<option value="${value}">${label}</option>`).join("");
  }
  receiveFileType?.addEventListener("change", syncReceiveDestinations);
  syncReceiveDestinations();

  async function lookupReceivableFile(value) {
    const query = String(value || "").trim().toUpperCase();
    resolvedReceiveRecord = null;
    receiveRecordMatch?.classList.remove("is-match");
    if (!query) {
      if (receiveRecordMatch) receiveRecordMatch.textContent = "Enter an Employee Number or Pension Number to fetch the related officer.";
      return;
    }
    if (receiveRecordMatch) receiveRecordMatch.textContent = "Looking up the related officer...";
    try {
      const data = await fetch(`../backend/api/lookup_receivable_file.php?q=${encodeURIComponent(query)}`, { credentials: "include", cache: "no-store" }).then((response) => response.json());
      const matches = Array.isArray(data.records) ? data.records : [];
      const exact = matches.find((item) => String(item.number || "").toUpperCase() === query) || (matches.length === 1 ? matches[0] : null);
      if (!exact) {
        if (receiveRecordMatch) receiveRecordMatch.textContent = matches.length ? "Multiple records match. Enter the complete Employee or Pension Number." : "No related staff-due or pension-registry record was found.";
        return;
      }
      resolvedReceiveRecord = exact;
      receiveRegNo.value = exact.number;
      receiveFileType.value = exact.file_type;
      syncReceiveDestinations();
      receiveRecordMatch?.classList.add("is-match");
      if (receiveRecordMatch) receiveRecordMatch.innerHTML = `<strong>${escapeHtml(exact.name || "Unnamed officer")}</strong> &middot; ${escapeHtml(exact.title || "Rank not recorded")}<br>${exact.file_type === "service" ? "Employee" : "Pension"} Number: ${escapeHtml(exact.number)}`;
    } catch (error) {
      if (receiveRecordMatch) receiveRecordMatch.textContent = "Unable to fetch the related record. Try again.";
    }
  }
  receiveRegNo?.addEventListener("input", () => {
    clearTimeout(receiveLookupTimer);
    receiveLookupTimer = setTimeout(() => lookupReceivableFile(receiveRegNo.value), 280);
  });

  function updateSelectedFileState(file = null) {
    selectedServiceFile = file;
    const hasFile = Boolean(file?.employeeNo);
    const isOut = hasFile && file.availability_status === "out";
    if (selectedServiceFileSummary) {
      selectedServiceFileSummary.textContent = hasFile
        ? `${file.employeeNo} selected · ${isOut ? "File is out — return is available" : "File is in registry custody — move is available"}`
        : "Select a file card to view its details and available custody action.";
      selectedServiceFileSummary.classList.toggle("has-selection", hasFile);
    }
  }

  async function validateModalCustody(action) {
    const isMove = action === "move";
    const input = isMove ? moveRegNoInput : document.getElementById("returnRegNo");
    const button = isMove ? moveFileSubmitBtn : returnFileSubmitBtn;
    const hint = isMove ? moveFileCustodyHint : returnFileCustodyHint;
    const number = String(input?.value || "").trim();
    if (button) button.disabled = true;
    if (!number) { if (hint) hint.textContent = `Enter a file number to ${isMove ? "move" : "return"}.`; return; }
    if (hint) hint.textContent = "Checking file custody...";
    try {
      const fileType = isMove ? (document.getElementById("moveFileType")?.value || "service") : "service";
      const data = await fetch(`../backend/api/get_file_custody.php?number=${encodeURIComponent(number)}&file_type=${encodeURIComponent(fileType)}`, { credentials: "include", cache: "no-store" }).then((response) => response.json());
      const permitted = Boolean(data.exists) && (isMove ? !data.is_out : data.is_out);
      if (button) button.disabled = !permitted;
      if (hint) {
        hint.textContent = !data.exists ? "This file is not registered in the selected registry." : permitted ? (isMove ? "File is in registry custody and can be moved." : "File is out and can be returned.") : (isMove ? "This file is already out and cannot be moved again." : "This file is already in the registry and cannot be returned.");
        hint.classList.toggle("is-valid", permitted);
      }
    } catch (error) { if (hint) hint.textContent = "Unable to validate file custody."; }
  }

  let custodyTimer = null;
  moveRegNoInput?.addEventListener("input", () => { clearTimeout(custodyTimer); custodyTimer=setTimeout(()=>validateModalCustody("move"),250); });
  document.getElementById("moveFileType")?.addEventListener("change", () => validateModalCustody("move"));
  document.getElementById("returnRegNo")?.addEventListener("input", () => { clearTimeout(custodyTimer); custodyTimer=setTimeout(()=>validateModalCustody("return"),250); });

  function openServiceFileDetails(file) {
    updateSelectedFileState(file);
    const isOut = file.availability_status === "out";
    const isArchive = file.registry_stage === "archives";
    serviceFileDetailsBody.innerHTML = `<div class="service-details-grid"><div><span>Employee / File Number</span><strong>${escapeHtml(file.employeeNo)}</strong></div><div><span>Officer</span><strong>${escapeHtml([file.firstName,file.middleName,file.lastName].filter(Boolean).join(" "))}</strong></div><div><span>Registry</span><strong>${escapeHtml(String(file.registry_stage).replaceAll("_"," "))}</strong></div><div><span>Storage</span><strong>${isArchive ? "Transferred to Archives" : `Box ${escapeHtml(file.registry_box_no || "Pending")}`}</strong></div><div><span>Custody</span><strong>${isOut ? "Out" : "In registry"}</strong></div></div>`;
    document.getElementById("detailsMoveFileBtn").hidden = isOut;
    document.getElementById("detailsReturnFileBtn").hidden = !isOut;
    openOperationModal(serviceFileDetailsOverlay);
  }

  function renderServiceFiles(rows) {
    if (!serviceFileResults) return;
    if (!rows.length) {
      serviceFileResults.innerHTML = '<div class="app-state-message app-state-neutral">No files have been registered in this service registry.</div>';
      updateSelectedFileState(null);
      return;
    }
    serviceFileResults.innerHTML = `<div class="service-file-card-grid">${rows.map((row) => {
      const isSelected = selectedServiceFile?.service_file_id === row.service_file_id;
      const isArchive = row.registry_stage === "archives";
      const boxText = isArchive ? "Transferred to Archives" : `Box ${escapeHtml(row.registry_box_no || "Pending allocation")}`;
      const custody = row.availability_status === "out" ? "Out" : row.availability_status === "archived" ? "Archived" : "In registry";
      return `<article class="service-file-record${isSelected ? " is-selected" : ""}" tabindex="0" role="button" aria-pressed="${isSelected}" data-select-service-file="${Number(row.service_file_id)}">
        <div class="service-file-record-header"><span class="service-file-number">${escapeHtml(row.employeeNo || "Employee number unavailable")}</span><span class="service-custody ${row.availability_status === "out" ? "is-out" : "is-in"}">${escapeHtml(custody)}</span></div>
        <strong class="service-file-name">${escapeHtml([row.firstName,row.middleName,row.lastName].filter(Boolean).join(" ") || "Officer name unavailable")}</strong>
        <span class="service-file-rank">${escapeHtml(row.rankName || row.positionName || row.rankPosition || "Rank not recorded")}</span>
        <div class="service-file-location"><span>${escapeHtml(boxText)}</span>${!isArchive ? `<small>Maximum 10 files per box</small>` : ""}</div>
        <div class="movement-item-actions"><button class="btn-secondary registry-track" data-file-number="${escapeHtml(row.employeeNo)}">Track history</button><button class="btn-secondary card-custody-action ${row.availability_status === "out" ? "action-return" : "action-move"}" data-service-id="${Number(row.service_file_id)}">${row.availability_status === "out" ? "Mark Returned" : "Move File"}</button>${row.staffdue_id ? `<button class="btn-secondary service-action" data-staff-id="${Number(row.staffdue_id)}" data-action="${row.registry_stage === "pending_processing" ? "create_pension_file" : row.registry_stage === "archives" ? "retrieve" : "archive"}">${row.registry_stage === "pending_processing" ? "Advance file" : row.registry_stage === "archives" ? "Retrieve" : "Send to Archives"}</button>` : ""}</div>
      </article>`;
    }).join("")}</div>`;
    serviceFileResults.querySelectorAll("[data-select-service-file]").forEach((card) => {
      const select = () => {
        const row = rows.find((item) => Number(item.service_file_id) === Number(card.dataset.selectServiceFile));
        updateSelectedFileState(row || null);
        serviceFileResults.querySelectorAll("[data-select-service-file]").forEach((item) => { const active=item===card; item.classList.toggle("is-selected",active); item.setAttribute("aria-pressed",String(active)); });
        if (row) openServiceFileDetails(row);
      };
      card.addEventListener("click", (event) => { if (!event.target.closest("button")) select(); });
      card.addEventListener("keydown", (event) => { if (event.key === "Enter" || event.key === " ") { event.preventDefault(); select(); } });
      card.querySelector(".card-custody-action")?.addEventListener("click", () => {
        const row = rows.find((item) => Number(item.service_file_id) === Number(card.dataset.selectServiceFile));
        if (!row) return;
        updateSelectedFileState(row);
        (row.availability_status === "out" ? openReturnFileBtn : openMoveFileBtn)?.click();
      });
    });
  }

  async function loadServiceFiles() {
    if (!serviceFileResults) return;
    const params = new URLSearchParams({ stage: document.getElementById("serviceFileStage")?.value || "", search: document.getElementById("serviceFileSearch")?.value.trim() || "" });
    serviceFileResults.innerHTML = '<div class="app-state-message app-state-neutral">Loading service files...</div>';
    updateSelectedFileState(null);
    try {
      const response = await fetch(`../backend/api/service_files.php?${params}`, { credentials: "include" });
      const data = await response.json();
      if (!data.success) throw new Error(data.message || "Unable to load service files.");
      const rows = data.records || [];
      serviceFileResults.innerHTML = rows.length ? `<div class="service-file-card-grid">${rows.map((row) => `
        <article class="movement-item">
          <div class="movement-item-main"><strong>${escapeHtml(row.employeeNo)} · ${escapeHtml(row.pensionNo || "Pension file not created")}</strong>
          <div>${escapeHtml([row.rankName || row.positionName || row.rankPosition, row.firstName, row.middleName, row.lastName].filter(Boolean).join(" "))}</div>
          <div class="movement-meta">${escapeHtml(String(row.registry_stage || "").replaceAll("_", " "))} · Shelf ${escapeHtml(row.shelf_reference || "Not indexed")} · ${escapeHtml(row.availability_status || "Unknown")}</div></div>
          <div class="movement-item-actions"><button class="btn-secondary registry-track" data-file-number="${escapeHtml(row.pensionNo || row.employeeNo)}">Track</button><button class="btn-secondary registry-move" data-file-number="${escapeHtml(row.pensionNo || row.employeeNo)}" data-stage="${escapeHtml(row.registry_stage || "pending_processing")}">Move Out</button>${row.availability_status === "out" ? `<button class="btn-secondary registry-return" data-file-number="${escapeHtml(row.pensionNo || row.employeeNo)}" data-stage="${escapeHtml(row.registry_stage || "pending_processing")}">Return</button>` : ""}<button class="btn-secondary service-action" data-staff-id="${Number(row.staffdue_id)}" data-action="${row.availability_status === "not_availed" ? "avail" : row.registry_stage === "pending_processing" ? "create_pension_file" : row.registry_stage === "archives" ? "retrieve" : "archive"}">${row.availability_status === "not_availed" ? "Mark Availed" : row.registry_stage === "pending_processing" ? "Create Pension File" : row.registry_stage === "archives" ? "Retrieve" : "Archive"}</button></div>
        </article>`).join("")}</div>` : '<div class="app-state-message app-state-neutral">No service files match this registry.</div>';
      renderServiceFiles(rows);
    } catch (error) { serviceFileResults.innerHTML = `<div class="app-state-message app-state-error">${escapeHtml(error.message)}</div>`; updateSelectedFileState(null); }
  }
  serviceFileForm?.addEventListener("submit", (event) => { event.preventDefault(); loadServiceFiles(); });
  document.querySelectorAll("[data-registry-stage]").forEach((tab) => {
    tab.addEventListener("click", () => {
      const stage = tab.dataset.registryStage || "pending_processing";
      const stageInput = document.getElementById("serviceFileStage");
      if (stageInput) stageInput.value = stage;
      document.querySelectorAll("[data-registry-stage]").forEach((item) => {
        const active = item === tab;
        item.classList.toggle("is-active", active);
        item.setAttribute("aria-selected", String(active));
      });
      loadServiceFiles();
    });
  });
  document.getElementById("detailsTrackFileBtn")?.addEventListener("click", async () => {
    if (!selectedServiceFile) return;
    closeOperationModal(serviceFileDetailsOverlay);
    openOperationModal(trackerModalOverlay);
    if (regNoInput) regNoInput.value=selectedServiceFile.employeeNo;
    await loadMovements(selectedServiceFile.employeeNo,{openModal:false});
  });
  document.getElementById("detailsMoveFileBtn")?.addEventListener("click", () => {
    closeOperationModal(serviceFileDetailsOverlay); openMoveFileBtn?.click();
  });
  document.getElementById("detailsReturnFileBtn")?.addEventListener("click", () => {
    closeOperationModal(serviceFileDetailsOverlay); openReturnFileBtn?.click();
  });
  serviceFileResults?.addEventListener("click", async (event) => {
    const trackButton=event.target.closest(".registry-track"); if(trackButton){if(regNoInput)regNoInput.value=trackButton.dataset.fileNumber;openOperationModal(trackerModalOverlay);await loadMovements(trackButton.dataset.fileNumber,{openModal:false});return;}
    const moveButton=event.target.closest(".registry-move"); if(moveButton){if(moveRegNoInput)moveRegNoInput.value=moveButton.dataset.fileNumber;document.getElementById("moveFileType").value="service";if(moveFromInput){moveFromInput.value=String(moveButton.dataset.stage||"Registry").replaceAll("_"," ");moveFromInput.dataset.registry=moveButton.dataset.stage||"pending_processing";}openOperationModal(movementModalOverlay);return;}
    const returnButton=event.target.closest(".registry-return"); if(returnButton){document.getElementById("returnRegNo").value=returnButton.dataset.fileNumber;document.getElementById("returnDestination").value=returnButton.dataset.stage||"pending_processing";openOperationModal(returnModalOverlay);return;}
    const button = event.target.closest(".service-action"); if (!button) return;
    const response = await fetch("../backend/api/service_files.php", { method: "POST", credentials: "include", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ staffdue_id: Number(button.dataset.staffId), action: button.dataset.action }) });
    const data = await response.json(); showFeedbackModal(data.success ? "success" : "error", "Service File", data.message); if (data.success) loadServiceFiles();
  });
  loadServiceFiles();
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
    if (historyRecordBtn) historyRecordBtn.disabled = true;
    if (historyReturnBtn) historyReturnBtn.disabled = true;

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
      const isOut = movements.some((movement) => !movement.returned_at);
      if (historyRecordBtn) historyRecordBtn.disabled = isOut;
      if (historyReturnBtn) historyReturnBtn.disabled = !isOut;
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
        <div class="movement-meta"><strong>${escapeHtml(item.file_type === "service" ? "Service File" : "Pension File")}</strong>${item.source_registry ? ` · Source: ${escapeHtml(String(item.source_registry).replaceAll("_", " "))}` : ""}</div>
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
      if (historyRecordBtn.disabled || !lastLoadedRegNo) return;
      if (moveRegNoInput && lastLoadedRegNo) {
        moveRegNoInput.value = lastLoadedRegNo;
        setMoveFromValue(moveFromInput?.value || "Front Desk");
      }
      closeHistoryModal();
      openOperationModal(movementModalOverlay);
      validateModalCustody("move");
      if (moveToInput) {
        moveToInput.focus();
      }
    });
  }
  historyReturnBtn?.addEventListener("click", () => {
    if (historyReturnBtn.disabled || !lastLoadedRegNo) return;
    if (lastLoadedRegNo) document.getElementById("returnRegNo").value = lastLoadedRegNo;
    closeHistoryModal(); openOperationModal(returnModalOverlay); validateModalCustody("return");
  });

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
        movement_action: "move",
        file_type: document.getElementById("moveFileType")?.value || "pension",
        source_registry: moveFromInput?.dataset.registry || "pension_file_registry",
        from_office: moveFromInput ? moveFromInput.value.trim() : "Front Desk",
        to_office: moveToInput ? moveToInput.value.trim() : "",
        reason: moveReasonInput ? moveReasonInput.value.trim() : "",
        delivered_by: moveDeliveredByInput ? moveDeliveredByInput.value.trim() : "",
        expected_return_at: moveExpectedReturnInput ? moveExpectedReturnInput.value : ""
      };

      if (!payload.regNo || !payload.delivered_by || !payload.to_office) {
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
        closeOperationModal(movementModalOverlay);
        await loadServiceFiles();
      } catch (err) {
        console.error("Record movement failed:", err);
        moveMessage.innerHTML = '<div class="app-state-message app-state-error">Failed to record movement.</div>';
        showFeedbackModal("error", "Save Failed", "Failed to record movement.");
      }
    });
  }

  document.getElementById("receiveFileForm")?.addEventListener("submit", async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const message = document.getElementById("receiveFileMessage");
    const payload = {
      movement_action: "receive",
      file_type: document.getElementById("receiveFileType")?.value || "service",
      regNo: document.getElementById("receiveRegNo")?.value.trim() || "",
      source_registry: document.getElementById("receiveSource")?.value || "staff_registry",
      destination_registry: document.getElementById("receiveDestination")?.value || "pending_processing",
      to_office: "Registry",
      delivered_by: document.getElementById("receiveDeliveredBy")?.value.trim() || "",
      received_by: document.getElementById("receiveBy")?.value.trim() || "",
      reason: document.getElementById("receiveReason")?.value.trim() || "File received and registered"
    };
    if (!payload.regNo || !payload.delivered_by || !payload.received_by) {
      message.innerHTML = '<div class="app-state-message app-state-error">File number, deliverer, and receiving officer are required.</div>'; return;
    }
    if (!resolvedReceiveRecord || String(resolvedReceiveRecord.number).toUpperCase() !== payload.regNo.toUpperCase()) {
      message.innerHTML = '<div class="app-state-message app-state-error">Wait for and select a valid related officer record before receiving this file.</div>'; return;
    }
    try {
      const data = await fetch("../backend/api/record_file_movement.php", { method: "POST", credentials: "include", headers: { "Content-Type": "application/json" }, body: JSON.stringify(payload) }).then((response) => response.json());
      if (!data.success) throw new Error(data.message || "Unable to receive file.");
      showFeedbackModal("success", "File Received", data.message || "File received into registry custody."); form.reset(); closeOperationModal(receiveModalOverlay); await loadServiceFiles();
    } catch (error) { message.innerHTML = `<div class="app-state-message app-state-error">${escapeHtml(error.message)}</div>`; }
  });

  document.getElementById("returnFileForm")?.addEventListener("submit", async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const message = document.getElementById("returnFileMessage");
    const regNo = document.getElementById("returnRegNo")?.value.trim() || "";
    const receivedBy = document.getElementById("returnReceivedBy")?.value.trim() || "";
    if (!regNo || !receivedBy) { message.innerHTML = '<div class="app-state-message app-state-error">File number and receiving officer are required.</div>'; return; }
    try {
      const history = await fetch(`../backend/api/get_file_movements.php?regNo=${encodeURIComponent(regNo)}`, { credentials: "include" }).then((response) => response.json());
      const openMovement = (history.movements || []).find((movement) => movement.can_return);
      if (!openMovement) throw new Error("This file is not currently recorded as moved out.");
      const data = await fetch("../backend/api/return_file_movement.php", { method: "POST", credentials: "include", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ movement_id: openMovement.movement_id, regNo, received_by: receivedBy, destination_registry: document.getElementById("returnDestination")?.value || "pending_processing", note: document.getElementById("returnNote")?.value.trim() || "" }) }).then((response) => response.json());
      if (!data.success) throw new Error(data.message || "Unable to return file.");
      showFeedbackModal("success", "File Returned", data.message); form.reset(); closeOperationModal(returnModalOverlay); await loadServiceFiles();
    } catch (error) { message.innerHTML = `<div class="app-state-message app-state-error">${escapeHtml(error.message)}</div>`; }
  });

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
