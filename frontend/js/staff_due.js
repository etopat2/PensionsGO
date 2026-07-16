async function initStaffDueController() {
  const staffContainer = document.getElementById("staffContainer");
  const searchInput = document.getElementById("searchInput");
  const searchButton = document.getElementById("searchButton");
  const filterRetirementType = document.getElementById("filterRetirementType");
  const filterSubmissionStatus = document.getElementById("filterSubmissionStatus");
  const filterAppnStatus = document.getElementById("filterAppnStatus");
  const addStaffBtn = document.getElementById("addStaffBtn");
  const viewQueueBtn = document.getElementById("viewQueueBtn");
  const staffDeleteQueueBtn = document.getElementById("staffDeleteQueueBtn");
  const actionModal = document.getElementById("actionModal");
  const actionModalTitle = document.getElementById("actionModalTitle");
  const actionModalSubtitle = document.getElementById("actionModalSubtitle");
  const actionModalBody = document.getElementById("actionModalBody");
  const actionCancelBtn = document.getElementById("actionCancelBtn");
  const actionConfirmBtn = document.getElementById("actionConfirmBtn");
  const queueModal = document.getElementById("queueModal");
  const queueList = document.getElementById("queueList");
  const queueCloseBtn = document.getElementById("queueCloseBtn");
  const queueBulkActions = document.getElementById("queueBulkActions");
  const queueSubmitAllBtn = document.getElementById("queueSubmitAllBtn");
  const staffDeleteQueueModal = document.getElementById("staffDeleteQueueModal");
  const staffDeleteQueueList = document.getElementById("staffDeleteQueueList");
  const staffDeleteQueueCloseBtn = document.getElementById("staffDeleteQueueCloseBtn");
  const staffPagination = document.getElementById("staffPagination");
  const staffPaginationSummary = document.getElementById("staffPaginationSummary");
  const staffPaginationControls = document.getElementById("staffPaginationControls");

  let pendingAction = null;
  let pendingStaff = null;
  let currentUserRole = "";
  let currentUserPermissions = {};
  const editActionRoles = new Set(["admin", "clerk", "data_entry", "writeup_officer"]);
  const bulkUploadRoles = new Set(["admin", "oc_pen", "dep_oc", "deputy_oc", "deputy_oc_pen", "deputy_oc_pension", "data_entry"]);
  const workflowActionRoles = new Set(["admin", "clerk", "data_entry"]);
  const staffDeleteRequestRoles = new Set(["admin", "clerk", "data_entry", "oc_pen", "dep_oc", "deputy_oc", "deputy_oc_pen", "deputy_oc_pension"]);
  const staffDeleteProcessRoles = new Set(["admin", "oc_pen", "dep_oc", "deputy_oc", "deputy_oc_pen", "deputy_oc_pension"]);
  const searchDebounceMs = 220;
  const pageSize = 12;
  let searchDebounceTimer = null;
  let refreshDebounceTimer = null;
  let currentFetchController = null;
  let currentPage = 1;
  let currentRecords = [];
  let totalRecords = 0;
  let totalPages = 1;
  let lastRenderedStaffSignature = "";
  let verificationEscalationWindowDays = 60;
  let verificationDueSoonWindowDays = 45;
  const STAFF_DUE_CACHE_KEY = "pensionsgoStaffDueSessionCache:v1";
  const STAFF_DUE_CACHE_TTL_MS = 5 * 60 * 1000;

  function readStaffDueCache() {
    try {
      return JSON.parse(sessionStorage.getItem(STAFF_DUE_CACHE_KEY) || "{}") || {};
    } catch (_error) {
      return {};
    }
  }

  function writeStaffDueCache(cache) {
    try {
      sessionStorage.setItem(STAFF_DUE_CACHE_KEY, JSON.stringify(cache || {}));
    } catch (_error) {}
  }

  function getStaffDueCachedPayload(key) {
    const entry = readStaffDueCache()[key];
    if (!entry || !entry.payload || Date.now() - Number(entry.savedAt || 0) > STAFF_DUE_CACHE_TTL_MS) return null;
    return entry.payload;
  }

  function setStaffDueCachedPayload(key, payload) {
    const cache = readStaffDueCache();
    cache[key] = { savedAt: Date.now(), payload };
    const compact = Object.fromEntries(Object.entries(cache)
      .sort((a, b) => Number(b[1]?.savedAt || 0) - Number(a[1]?.savedAt || 0))
      .slice(0, 16));
    writeStaffDueCache(compact);
  }

  function clearStaffDueCache() {
    try {
      sessionStorage.removeItem(STAFF_DUE_CACHE_KEY);
    } catch (_error) {}
  }

  function applyStaffDuePayload(data, { resetPage = true } = {}) {
    verificationEscalationWindowDays = Math.max(7, Number(data.settings?.verificationEscalationWindowDays || verificationEscalationWindowDays || 60));
    verificationDueSoonWindowDays = Math.max(1, Number(data.settings?.verificationDueSoonWindowDays || verificationDueSoonWindowDays || Math.max(1, verificationEscalationWindowDays - 15)));
    currentRecords = Array.isArray(data.records) ? data.records : [];
    totalRecords = Number(data.totalRecords ?? currentRecords.length);
    totalPages = Math.max(1, Number(data.totalPages || 1));
    currentPage = Math.max(1, Number(data.page || (resetPage ? 1 : currentPage)));
    const signature = JSON.stringify([currentPage, totalRecords, currentRecords]);
    if (signature === lastRenderedStaffSignature) return;
    lastRenderedStaffSignature = signature;
    renderStaffCards();
  }

  function formatRetirementType(value) {
    return window.PensionsGoRetirementTypes?.getLabel?.(value) || String(value || "").trim() || "N/A";
  }

  function getPermissionValue(key, fallback = false) {
    if (Object.prototype.hasOwnProperty.call(currentUserPermissions, key)) {
      return Boolean(currentUserPermissions[key]);
    }
    return Boolean(fallback);
  }

  function canEditStaffRecords() {
    return getPermissionValue("staff_due.edit", editActionRoles.has(currentUserRole));
  }

  function canBulkUploadStaffDue() {
    const roleAllows = bulkUploadRoles.has(currentUserRole);
    return roleAllows && getPermissionValue("staff_due.bulk_upload", roleAllows);
  }

  function canOpenStaffDueWorkspace() {
    return canEditStaffRecords() || canBulkUploadStaffDue();
  }

  function canManageWorkflowActions() {
    return workflowActionRoles.has(currentUserRole);
  }

  function canRequestStaffDelete() {
    return getPermissionValue("staff_due.delete_request", staffDeleteRequestRoles.has(currentUserRole));
  }

  function canProcessStaffDeleteQueue() {
    return getPermissionValue("staff_due.delete_queue.process", staffDeleteProcessRoles.has(currentUserRole));
  }

  function canDeleteDirectly() {
    return staffDeleteProcessRoles.has(currentUserRole);
  }

  function canShowDeleteAction() {
    return canDeleteDirectly() || canRequestStaffDelete();
  }

  function formatTitleName(title, sName, fName) {
    const cleanTitle = String(title || "").trim();
    const cleanName = `${String(sName || "").trim()} ${String(fName || "").trim()}`.trim();
    if (cleanTitle && cleanName) {
      return `${cleanTitle} - ${cleanName}`;
    }
    return cleanTitle || cleanName;
  }

  function formatFullName(sName, fName) {
    const cleanName = `${String(sName || "").trim()} ${String(fName || "").trim()}`.trim();
    return cleanName;
  }

  async function checkSession() {
    try {
      const res = await fetch("../backend/api/check_session.php", {
        cache: "no-store",
        credentials: "include"
      });
      const data = await res.json();
      if (!data.active || !(data.userRole || data.userRoleEffective)) {
        window.location.href = "login.html";
        return null;
      }

      currentUserRole = String(data.userRoleEffective || data.userRole || "").toLowerCase();
      if (currentUserRole === "pensioner") {
        window.location.href = "dashboard.html";
        return null;
      }

      return data;
    } catch (err) {
      console.error("Session check failed:", err);
      window.location.href = "login.html";
      return null;
    }
  }

  async function loadCurrentPermissions() {
    try {
      const permissionKeys = [
        "staff_due.edit",
        "staff_due.bulk_upload",
        "staff_due.delete_request",
        "staff_due.delete_queue.process"
      ];
      const res = await fetch(`../backend/api/get_current_permissions.php?keys=${encodeURIComponent(permissionKeys.join(","))}`, {
        credentials: "include",
        cache: "no-store"
      });
      const data = await res.json();
      if (res.ok && data.success && data.permissions && typeof data.permissions === "object") {
        currentUserPermissions = data.permissions;
      } else {
        currentUserPermissions = {};
      }
    } catch (error) {
      console.error("Unable to load current permissions:", error);
      currentUserPermissions = {};
    }
  }

  const sessionData = await checkSession();
  if (!sessionData) return;
  await loadCurrentPermissions();

  if (!canOpenStaffDueWorkspace()) {
    if (addStaffBtn) addStaffBtn.style.display = "none";
  }
  if (!canManageWorkflowActions()) {
    if (viewQueueBtn) viewQueueBtn.style.display = "none";
  }
  if (staffDeleteQueueBtn) {
    staffDeleteQueueBtn.style.display = canProcessStaffDeleteQueue() ? "" : "none";
  }

  if (addStaffBtn) {
    addStaffBtn.addEventListener("click", () => {
      window.location.href = "add_staff.html";
    });
  }

  if (viewQueueBtn) {
    viewQueueBtn.addEventListener("click", () => {
      openQueueModal();
    });
  }

  if (staffDeleteQueueBtn) {
    staffDeleteQueueBtn.addEventListener("click", () => {
      openStaffDeleteQueueModal();
    });
  }

  if (queueCloseBtn) {
    queueCloseBtn.addEventListener("click", () => closeModal(queueModal));
  }

  if (staffDeleteQueueCloseBtn) {
    staffDeleteQueueCloseBtn.addEventListener("click", () => closeModal(staffDeleteQueueModal));
  }

  if (actionCancelBtn) {
    actionCancelBtn.addEventListener("click", () => closeModal(actionModal));
  }

  if (actionModal) {
    actionModal.addEventListener("click", (e) => {
      if (e.target === actionModal) closeModal(actionModal);
    });
  }

  if (queueModal) {
    queueModal.addEventListener("click", (e) => {
      if (e.target === queueModal) closeModal(queueModal);
    });
  }
  if (staffDeleteQueueModal) {
    staffDeleteQueueModal.addEventListener("click", (e) => {
      if (e.target === staffDeleteQueueModal) closeModal(staffDeleteQueueModal);
    });
  }

  async function fetchStaffData({ resetPage = false } = {}) {
    if (resetPage) currentPage = 1;
    if (currentFetchController) {
      currentFetchController.abort();
    }
    const fetchController = new AbortController();
    currentFetchController = fetchController;

    try {
      const params = new URLSearchParams({
        search: searchInput.value.trim(),
        retirementType: filterRetirementType.value,
        submissionStatus: filterSubmissionStatus.value,
        appnStatus: filterAppnStatus.value
        ,page: String(currentPage)
        ,limit: String(pageSize)
      });
      const cacheKey = params.toString();
      const cachedPayload = getStaffDueCachedPayload(cacheKey);
      if (cachedPayload) {
        applyStaffDuePayload(cachedPayload);
      } else {
        staffContainer.innerHTML = "<div class=\"app-state-message app-state-neutral\">Loading staff due records...</div>";
      }
      const res = await fetch(`../backend/api/fetch_staffdue.php?${params.toString()}`, {
        cache: "no-store",
        credentials: "include",
        signal: fetchController.signal
      });
      const data = await res.json();

      if (!data.success) {
        lastRenderedStaffSignature = "";
        currentRecords = [];
        currentPage = 1;
        renderPagination();
        staffContainer.innerHTML = `<div class="app-state-message app-state-error">${escapeHtml(data.message || "Unable to load records.")}</div>`;
        return;
      }
      setStaffDueCachedPayload(cacheKey, data);
      applyStaffDuePayload(data);
    } catch (err) {
      if (err?.name === "AbortError") return;
      console.error("Error fetching staff data:", err);
      lastRenderedStaffSignature = "";
      currentRecords = [];
      currentPage = 1;
      renderPagination();
      staffContainer.innerHTML = "<div class=\"app-state-message app-state-error\">Error loading data.</div>";
    } finally {
      if (currentFetchController === fetchController) {
        currentFetchController = null;
      }
    }
  }

  function scheduleStaffRefresh(delay = 120) {
    if (refreshDebounceTimer) {
      clearTimeout(refreshDebounceTimer);
    }
    refreshDebounceTimer = setTimeout(() => {
      refreshDebounceTimer = null;
      fetchStaffData();
    }, delay);
  }

  searchButton.addEventListener("click", () => fetchStaffData({ resetPage: true }));
  if (searchInput) {
    searchInput.addEventListener("input", () => {
      if (searchDebounceTimer) clearTimeout(searchDebounceTimer);
      searchDebounceTimer = setTimeout(() => {
        fetchStaffData({ resetPage: true });
      }, searchDebounceMs);
    });
  }
  [filterRetirementType, filterSubmissionStatus, filterAppnStatus].forEach((el) =>
    el.addEventListener("change", () => fetchStaffData({ resetPage: true }))
  );

  fetchStaffData();

  function getTotalPages() {
    return totalPages;
  }

  function renderStaffCards() {
    if (!currentRecords.length) {
      renderPagination();
      staffContainer.innerHTML = "<div class=\"app-state-message app-state-neutral\">No records found.</div>";
      return;
    }

    const totalPages = getTotalPages();
    if (currentPage > totalPages) {
      currentPage = totalPages;
    }

    const visibleRecords = currentRecords;
    const fragment = document.createDocumentFragment();

    visibleRecords.forEach((staff) => {
      const card = document.createElement("div");
      const cardStatus = getCardStatusChip(staff);
      const cardStateClass = `card-state-${String(cardStatus.className || "").replace("chip-", "")}`;
      card.className = `staff-card ${cardStateClass}`.trim();

      const name = [staff.firstName, staff.middleName, staff.lastName].filter(Boolean).join(" ")
        || [staff.fName, staff.sName].filter(Boolean).join(" ");
      const rank = staff.title || "N/A";
      const fileNumber = staff.employeeNo || staff.regNo || "N/A";
      const station = staff.prisonUnit || "N/A";
      const retirementDate = formatCardDate(staff.retirementDate);
      const retirementType = formatRetirementType(staff.retirementType);
      const initiationMeta = getWorkflowInitiationMeta(staff);
      const callAction = buildCallAction(staff.telNo);
      const manageActions = [];
      if (canEditStaffRecords()) {
        manageActions.push('<button class="card-action-btn secondary" data-action="edit">Edit</button>');
      }
      if (canManageWorkflowActions()) {
        const statusAction = buildStatusAction(staff);
        if (statusAction) {
          manageActions.push(statusAction);
        }
      }
      if (canShowDeleteAction()) {
        manageActions.push('<button class="card-action-btn danger" data-action="delete">Delete</button>');
      }

      card.innerHTML = `
        <div class="card-summary">
          <div class="summary-row summary-name"><span>${escapeHtml(name || "Unknown")}</span><span class="service-file-badge service-file-${escapeHtml(String(staff.service_file_availability || "not_availed").replaceAll("_", "-"))}" title="Service file: ${escapeHtml(String(staff.service_file_registry_stage || "pending processing").replaceAll("_", " "))}">${staff.service_file_availability === "available" ? "Service file available" : staff.service_file_availability === "out" ? "Service file out" : staff.service_file_availability === "archived" ? "Archived" : "Not availed"}</span></div>
          <div class="summary-row summary-file-rank">
            <span class="summary-file">${escapeHtml(fileNumber)}</span>
            <span class="summary-sep">-</span>
            <span class="summary-rank">${escapeHtml(rank)}</span>
          </div>
          <div class="summary-row summary-station">
            <span><span class="summary-label">Station:</span> ${escapeHtml(station)}</span>
            <span class="card-status-chip ${escapeHtml(cardStatus.className)}">${escapeHtml(cardStatus.label)}</span>
          </div>
          <div class="summary-row summary-retirement">
            <span class="summary-label">D.O.R:</span> ${escapeHtml(retirementDate)} - <strong>${escapeHtml(retirementType)}</strong>
          </div>
          ${initiationMeta ? `
            <div class="summary-row ${escapeHtml(initiationMeta.className)}">
              <strong>${escapeHtml(initiationMeta.label)}</strong>
              <span>${escapeHtml(initiationMeta.detail)}</span>
            </div>
          ` : ""}
        </div>

        <div class="card-footer">
          <div class="card-actions">
            <button class="card-action-btn secondary" data-action="view">View</button>
            ${manageActions.join("")}
            ${callAction}
          </div>
        </div>
      `;

      card.querySelectorAll(".card-action-btn").forEach((btn) => {
        btn.addEventListener("click", (event) => {
          event.preventDefault();
          event.stopPropagation();
          handleCardAction(btn.dataset.action, staff);
        });
      });

      fragment.appendChild(card);
    });
    staffContainer.replaceChildren(fragment);
    renderPagination();
  }

  function renderPagination() {
    if (!staffPagination || !staffPaginationSummary || !staffPaginationControls) {
      return;
    }

    if (!currentRecords.length) {
      staffPagination.hidden = true;
      staffPaginationSummary.textContent = "";
      staffPaginationControls.innerHTML = "";
      return;
    }

    staffPagination.hidden = false;
    const totalPages = getTotalPages();
    const startItem = ((currentPage - 1) * pageSize) + 1;
    const endItem = Math.min(currentPage * pageSize, totalRecords);
    staffPaginationSummary.textContent = `Showing ${startItem}-${endItem} of ${totalRecords} records`;

    const pageButtons = buildPaginationButtons(currentPage, totalPages);
    staffPaginationControls.innerHTML = `
      <button type="button" class="staff-page-btn staff-page-nav" data-page-nav="prev" ${currentPage <= 1 ? "disabled" : ""}>Previous</button>
      ${pageButtons.map((item) => item === "ellipsis"
        ? '<button type="button" class="staff-page-btn" aria-hidden="true" disabled>…</button>'
        : `<button type="button" class="staff-page-btn ${item === currentPage ? "is-active" : ""}" data-page-number="${item}">${item}</button>`
      ).join("")}
      <button type="button" class="staff-page-btn staff-page-nav" data-page-nav="next" ${currentPage >= totalPages ? "disabled" : ""}>Next</button>
    `;

    staffPaginationControls.querySelectorAll("[data-page-number]").forEach((button) => {
      button.addEventListener("click", () => {
        currentPage = Number(button.dataset.pageNumber || currentPage);
        fetchStaffData();
        scrollToCardGrid();
      });
    });

    staffPaginationControls.querySelectorAll("[data-page-nav]").forEach((button) => {
      button.addEventListener("click", () => {
        const direction = button.dataset.pageNav;
        if (direction === "prev" && currentPage > 1) {
          currentPage -= 1;
        } else if (direction === "next" && currentPage < totalPages) {
          currentPage += 1;
        } else {
          return;
        }
        fetchStaffData();
        scrollToCardGrid();
      });
    });
  }

  function buildPaginationButtons(page, totalPages) {
    if (totalPages <= 7) {
      return Array.from({ length: totalPages }, (_, index) => index + 1);
    }

    const pages = new Set([1, totalPages, page, page - 1, page + 1]);
    if (page <= 3) {
      pages.add(2);
      pages.add(3);
      pages.add(4);
    }
    if (page >= totalPages - 2) {
      pages.add(totalPages - 1);
      pages.add(totalPages - 2);
      pages.add(totalPages - 3);
    }

    const sortedPages = Array.from(pages)
      .filter((value) => value >= 1 && value <= totalPages)
      .sort((a, b) => a - b);

    const output = [];
    sortedPages.forEach((value, index) => {
      if (index > 0 && value - sortedPages[index - 1] > 1) {
        output.push("ellipsis");
      }
      output.push(value);
    });
    return output;
  }

  function scrollToCardGrid() {
    if (!staffContainer) return;
    const prefersReducedMotion = window.matchMedia?.("(prefers-reduced-motion: reduce)")?.matches;
    staffContainer.scrollIntoView({
      behavior: prefersReducedMotion ? "auto" : "smooth",
      block: "start"
    });
  }

  function buildCallAction(phoneNumber) {
    const telHref = normalizeTelHref(phoneNumber);
    if (!telHref) return "";
    return `<a class="card-action-btn mobile-call-action" data-action="call" href="tel:${escapeHtml(telHref)}">Call</a>`;
  }

  function normalizeTelHref(phoneNumber) {
    const raw = (phoneNumber || "").toString().trim().replace(/[\s().-]/g, "");
    if (!raw) return "";
    if (/^00[1-9]\d{7,14}$/.test(raw)) return `+${raw.slice(2)}`;
    if (/^\+[1-9]\d{7,14}$/.test(raw)) return raw;
    if (/^0\d{9}$/.test(raw)) return `+256${raw.slice(1)}`;
    if (/^[1-9]\d{7,14}$/.test(raw)) return `+${raw}`;
    return "";
  }

  function buildStatusAction(staff) {
    if (!canManageWorkflowActions()) {
      return "";
    }

    const workflowState = normalizeWorkflowActionState(staff?.workflow_action_state || staff?.workflowActionState);
    if (workflowState === "completed") {
      return "";
    }
    if (workflowState === "in_process") {
      return "";
    }

    const appnState = normalizeApplicationStatus(staff.appnStatus);

    if (appnState === "completed") {
      return "";
    }

    if (appnState === "in_process") {
      return "";
    }

    if (staff.submissionStatus !== "submitted") {
      return '<button class="card-action-btn" data-action="submit">Act</button>';
    }

    if (appnState === "verified") {
      return '<button class="card-action-btn" data-action="review">Queued</button>';
    }

    return '<button class="card-action-btn" data-action="verify">Verify</button>';
  }

  function handleCardAction(action, staff) {
    if (!canEditStaffRecords() && action === "edit") {
      showFeedbackModal("error", "Access Denied", "You are not allowed to perform this action.");
      return;
    }

    if (!canShowDeleteAction() && action === "delete") {
      showFeedbackModal("error", "Access Denied", "You are not allowed to perform this action.");
      return;
    }

    if (!canManageWorkflowActions() && ["submit", "verify", "review"].includes(action)) {
      showFeedbackModal("error", "Access Denied", "You are not allowed to perform this action.");
      return;
    }

    if (action === "view") {
      window.location.href = `view_staff.html?id=${encodeURIComponent(staff.id)}`;
      return;
    }

    if (action === "edit") {
      window.location.href = `edit_staff.html?id=${encodeURIComponent(staff.id)}&from=staff_due`;
      return;
    }

    if (action === "delete") {
      openDeleteModal(staff);
      return;
    }

    if (action === "submit") {
      openSubmissionModal(staff);
      return;
    }

    if (action === "verify") {
      openVerifyModal(staff);
      return;
    }

    if (action === "review") {
      openQueueModal();
      return;
    }

    if (action === "call") {
      const tel = normalizeTelHref(staff.telNo);
      if (tel) {
        window.location.href = `tel:${tel}`;
      }
      return;
    }

    if (action === "noop") {
      return;
    }
  }

  function openSubmissionModal(staff) {
    pendingAction = "submit";
    pendingStaff = staff;
    const isDeathRetirement = String(staff?.retirementType || "").trim().toLowerCase() === "death";
    actionModalTitle.textContent = "Submit Application";
    actionModalSubtitle.textContent = isDeathRetirement
      ? "Mark this record as submitted and log the timestamp. Death retirements must already have next of kin name and contact saved first."
      : "Mark this record as submitted and log the timestamp.";
    actionModalBody.innerHTML = `
      <div class="staff-field">
        <span>Officer</span>
        <strong>${escapeHtml(formatTitleName(staff.title || "", staff.sName || "", staff.fName || ""))}</strong>
      </div>
    `;
    actionConfirmBtn.textContent = "Submit";
    openModal(actionModal);
  }

  function openVerifyModal(staff) {
    pendingAction = "verify";
    pendingStaff = staff;
    actionModalTitle.textContent = "Verify Application Status";
    actionModalSubtitle.textContent = "Confirm every required document before passing the application to the verification queue.";
    const retirementMode = String(staff?.retirementType || "").trim().toLowerCase();
    const documents = [
      ["ap_pf7_ns3", "AP(PF7/NS3)"], ["ns7", "NS7"], ["psf18_20", "PSF18/20"],
      ["bank_statement", "Original Bank Statement"], ["national_id", "National ID"], ["tin", "TIN"],
      ["payslip", "Payslip"], ["first_appointment_letter", "First Appointment Letter"],
      ["confirmation_letter", "Confirmation Letter"], ["last_appointment_letter", "Last Appointment Letter"]
    ];
    if (retirementMode.includes("death")) {
      documents.push(["death_certificate", "Death Certificate"], ["letters_of_administration", "Letters of Administration"]);
    } else if (!retirementMode.includes("mandatory")) {
      documents.push(["discharge_certificate", "Discharge Certificate"]);
    }
    const checklist = documents.map(([code, label]) => `
      <label class="verification-check-item">
        <input type="checkbox" data-verification-document="${escapeHtml(code)}">
        <span>${escapeHtml(label)}</span>
      </label>`).join("");
    const officerName = [staff.firstName, staff.middleName, staff.lastName].filter(Boolean).join(" ") || formatFullName(staff.sName, staff.fName);
    actionModalBody.innerHTML = `
      <div class="verification-shell">
        <section class="verification-officer" aria-label="Officer identification">
          <div class="verification-officer-primary">
            <span>Officer being verified</span>
            <strong>${escapeHtml(officerName || "Name not recorded")}</strong>
            <small>${escapeHtml(staff.rankName || staff.positionName || staff.rankPosition || staff.title || "Rank / position not recorded")}</small>
          </div>
          <div class="verification-identity-chip"><span>Employee Number</span><strong>${escapeHtml(staff.employeeNo || staff.regNo || "Not recorded")}</strong></div>
          <div class="verification-identity-chip"><span>IPPS Number</span><strong>${escapeHtml(staff.ippsNo || staff.computerNo || "Not recorded")}</strong></div>
        </section>
        <div class="verification-workspace">
          <aside class="verification-controls" aria-label="Verification decision">
            <div class="verification-section-heading">
              <span class="verification-step">01</span>
              <div><strong>Decision</strong><small>Set the review outcome</small></div>
            </div>
            <label class="modal-field compact-field">
              <span>Application status</span>
              <select id="verifyStatusSelect">
                <option value="verified">Verified</option>
                <option value="queried">Queried</option>
                <option value="rejected">Rejected</option>
              </select>
            </label>
            <label class="modal-field compact-field">
              <span>Mode of retirement</span>
              <select id="verifyRetirementTypeSelect"></select>
            </label>
            <label class="modal-field compact-field verification-reason" id="verifyReasonWrap" style="display:none;">
              <span>Review reason</span>
              <textarea id="verifyReasonInput" rows="2" placeholder="State the reason clearly"></textarea>
            </label>
          </aside>
          <section class="verification-documents" aria-labelledby="verificationDocumentsTitle">
            <div class="verification-documents-header">
              <div class="verification-section-heading">
                <span class="verification-step">02</span>
                <div><strong id="verificationDocumentsTitle">Required application documents</strong><small>Confirm each original document sighted</small></div>
              </div>
              <span class="verification-progress" id="verificationProgress">0 of ${documents.length} confirmed</span>
            </div>
            <fieldset class="verification-checklist">
              <legend class="sr-only">Required application documents</legend>
              ${checklist}
            </fieldset>
          </section>
        </div>
      </div>
    `;
    const statusSelect = document.getElementById("verifyStatusSelect");
    const retirementSelect = document.getElementById("verifyRetirementTypeSelect");
    const retirementOptions = window.PensionsGoRetirementTypes?.getDefinitions?.() || ["Mandatory Retirement", "Death", "Medical Grounds", "Early Retirement", "Retirement in Public Interest"];
    retirementSelect.innerHTML = retirementOptions.map((option) => {
      const value = typeof option === "string" ? option : (option.key || option.value || option.label);
      const label = typeof option === "string" ? option : (option.label || option.value);
      const selectedValue = window.PensionsGoRetirementTypes?.normalizeValue?.(staff.retirementType) || String(staff.retirementType || "").toLowerCase();
      return `<option value="${escapeHtml(value)}" ${String(value).toLowerCase() === String(selectedValue).toLowerCase() ? "selected" : ""}>${escapeHtml(label)}</option>`;
    }).join("");
    retirementSelect.addEventListener("change", () => openVerifyModal({ ...staff, retirementType: retirementSelect.value }));
    const reasonWrap = document.getElementById("verifyReasonWrap");
    const checklistInputs = [...document.querySelectorAll("[data-verification-document]")];
    const checklistElement = actionModalBody.querySelector(".verification-checklist");
    const checklistLabels = [...actionModalBody.querySelectorAll(".verification-check-item span")];
    const sizeChecklistColumns = () => {
      if (!checklistElement || !checklistLabels.length) return;
      const widestText = Math.max(...checklistLabels.map((label) => Math.ceil(label.scrollWidth)));
      const naturalBasis = Math.min(360, Math.max(180, widestText + 66));
      const threeColumnFloor = checklistElement.clientWidth > 0
        ? Math.floor((checklistElement.clientWidth - 16) / 3)
        : 0;
      const uniformBasis = Math.max(naturalBasis, threeColumnFloor);
      checklistElement.style.setProperty("--verification-document-width", `${uniformBasis}px`);
    };
    sizeChecklistColumns();
    window.requestAnimationFrame(sizeChecklistColumns);
    const progress = document.getElementById("verificationProgress");
    const updateChecklistProgress = () => {
      const checked = checklistInputs.filter((input) => input.checked).length;
      if (progress) {
        progress.textContent = `${checked} of ${checklistInputs.length} confirmed`;
        progress.classList.toggle("is-complete", checked === checklistInputs.length);
      }
    };
    checklistInputs.forEach((input) => input.addEventListener("change", updateChecklistProgress));
    statusSelect.addEventListener("change", () => {
      const status = statusSelect.value;
      reasonWrap.style.display = status === "queried" || status === "rejected" ? "block" : "none";
    });
    actionConfirmBtn.textContent = "Update";
    openModal(actionModal);
  }

  function openDeleteModal(staff) {
    pendingAction = "delete";
    pendingStaff = staff;
    const isDirectDelete = canDeleteDirectly();
    actionModalTitle.textContent = isDirectDelete ? "Delete Record" : "Request Delete";
    actionModalSubtitle.textContent = isDirectDelete
      ? "This will remove the record from active staff due lists immediately."
      : "Provide a reason for the delete request.";
    actionModalBody.innerHTML = `
      <p>${isDirectDelete ? "Delete" : "Request deletion for"} file ${escapeHtml(staff.regNo || "N/A")} for ${escapeHtml(formatTitleName(staff.title || "", staff.sName || "", staff.fName || ""))}?</p>
      <label class="modal-field">
        <span>${isDirectDelete ? "Delete Note (optional)" : "Delete Reason"}</span>
        <textarea id="deleteReasonInput" rows="3" placeholder="${isDirectDelete ? "Optional note for audit trail" : "Provide the reason for this delete request"}"></textarea>
      </label>
    `;
    actionConfirmBtn.textContent = isDirectDelete ? "Delete Now" : "Submit Request";
    openModal(actionModal);
  }

  if (actionConfirmBtn) {
    actionConfirmBtn.addEventListener("click", async () => {
      if (!pendingStaff || !pendingAction) return;
      if (pendingAction === "submit") {
        await submitApplication(pendingStaff.id);
      } else if (pendingAction === "verify") {
        const verified = await verifyApplication(pendingStaff.id);
        if (!verified) return;
      } else if (pendingAction === "delete") {
        const reasonInput = document.getElementById("deleteReasonInput");
        const reason = reasonInput ? reasonInput.value.trim() : "";
        if (!canDeleteDirectly() && reason === "") {
          showFeedbackModal("error", "Delete Reason Required", "Provide a reason before submitting the delete request.");
          return;
        }
        await deleteRecord(pendingStaff.id, reason);
      }
      closeModal(actionModal);
      pendingAction = null;
      pendingStaff = null;
      triggerStaffRefresh();
    });
  }

  async function submitApplication(id) {
    try {
      const res = await fetch("../backend/api/update_submission_status.php", {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id })
      });
      const data = await res.json();
      if (!data.success) {
        showFeedbackModal("error", "Submit Failed", data.message || "Unable to submit record.");
      } else {
        showFeedbackModal("success", "Submitted", data.message || "Record submitted successfully.");
      }
    } catch (err) {
      console.error(err);
      showFeedbackModal("error", "Submit Failed", "Unable to submit record.");
    }
  }

  async function verifyApplication(id) {
    const statusSelect = document.getElementById("verifyStatusSelect");
    const reasonInput = document.getElementById("verifyReasonInput");
    const status = statusSelect ? statusSelect.value : "verified";
    const reason = reasonInput ? reasonInput.value.trim() : "";
    const documents = {};
    document.querySelectorAll("[data-verification-document]").forEach((input) => {
      documents[input.getAttribute("data-verification-document")] = input.checked;
    });
    const retirementType = document.getElementById("verifyRetirementTypeSelect")?.value || pendingStaff?.retirementType || "";
    if (status === "verified" && Object.values(documents).some((checked) => !checked)) {
      showFeedbackModal("error", "Checklist Incomplete", "Every required document must be confirmed before this application can pass verification.");
      return false;
    }

    try {
      const res = await fetch("../backend/api/update_appn_status.php", {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id, status, reason, documents, retirementType })
      });
      const data = await res.json();
      if (!data.success) {
        showFeedbackModal("error", "Status Update Failed", data.message || "Unable to update status.");
      } else {
        showFeedbackModal("success", "Status Updated", data.message || "Application status updated.");
      }
      return Boolean(data.success);
    } catch (err) {
      console.error(err);
      showFeedbackModal("error", "Status Update Failed", "Unable to update status.");
      return false;
    }
  }

  async function deleteRecord(id, reason = "") {
    try {
      const endpoint = canDeleteDirectly()
        ? "../backend/api/delete_staffdue.php"
        : "../backend/api/request_staff_due_delete.php";
      const payload = canDeleteDirectly()
        ? { id, reason: reason || "Direct deletion by privileged user." }
        : { staffdue_id: id, reason };
      const res = await fetch(endpoint, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
      });
      const data = await res.json();
      if (!data.success) {
        showFeedbackModal("error", canDeleteDirectly() ? "Delete Failed" : "Request Failed", data.message || "Unable to delete record.");
      } else {
        showFeedbackModal("success", canDeleteDirectly() ? "Record Deleted" : "Delete Requested", data.message || (canDeleteDirectly() ? "Record deleted successfully." : "Delete request queued successfully."));
        if (!canDeleteDirectly() && canProcessStaffDeleteQueue()) {
          void loadStaffDeleteQueue();
        }
      }
    } catch (err) {
      console.error(err);
      showFeedbackModal("error", canDeleteDirectly() ? "Delete Failed" : "Request Failed", canDeleteDirectly() ? "Unable to delete record." : "Unable to queue delete request.");
    }
  }

  function openModal(modal) {
    if (!modal) return;
    modal.style.display = "flex";
    document.body.classList.add("modal-open");
    document.body.style.overflow = "hidden";
  }

  function closeModal(modal) {
    if (!modal) return;
    modal.style.display = "none";
    document.body.classList.remove("modal-open");
    document.body.style.overflow = "";
  }

  function triggerStaffRefresh() {
    clearStaffDueCache();
    fetchStaffData();
    localStorage.setItem("staffDueUpdated", Date.now().toString());
  }

  window.addEventListener("focus", () => scheduleStaffRefresh());
  document.addEventListener("visibilitychange", () => {
    if (!document.hidden) scheduleStaffRefresh();
  });
  window.addEventListener("pageshow", () => scheduleStaffRefresh());
  window.addEventListener("storage", (event) => {
    if (event.key === "staffDueUpdated") {
      clearStaffDueCache();
      scheduleStaffRefresh(40);
    }
  });
  const staffRealtimeTimer = window.setInterval(() => {
    if (!document.hidden && !currentFetchController) scheduleStaffRefresh(0);
  }, 30000);
  window.addEventListener("pagehide", () => window.clearInterval(staffRealtimeTimer), { once: true });

  async function openQueueModal() {
    if (!canManageWorkflowActions()) {
      showFeedbackModal("error", "Access Denied", "Queue actions are restricted to verification roles.");
      return;
    }
    if (!queueModal) return;
    await loadQueue();
    openModal(queueModal);
  }

  async function loadQueue() {
    if (!queueList) return;
    queueList.innerHTML = "<div class=\"app-state-message app-state-neutral\">Loading queue...</div>";
    try {
      const res = await fetch("../backend/api/get_application_queue.php", {
        credentials: "include"
      });
      const data = await res.json();
      if (!data.success || !data.records.length) {
        if (queueBulkActions && queueSubmitAllBtn) {
          queueBulkActions.style.display = "none";
          queueSubmitAllBtn.onclick = null;
        }
        queueList.innerHTML = "<div class=\"app-state-message app-state-neutral\">No verified applications in queue.</div>";
        return;
      }

      const verifiedItems = data.records;
      if (queueBulkActions && queueSubmitAllBtn) {
        if (verifiedItems.length > 1) {
          queueBulkActions.style.display = "flex";
          queueSubmitAllBtn.textContent = `Submit All (${verifiedItems.length}) to OC/Pen`;
          queueSubmitAllBtn.onclick = async () => {
            const shouldSubmitAll = await appConfirm(`Submit ${verifiedItems.length} applications to OC/Pen?`, {
              title: "Submit Queue",
              confirmText: "Submit All"
            });
            if (!shouldSubmitAll) return;
            const selectedPriorityValue = await appPrompt(
              "Set task priority for OC/Pen forwarding (low/normal/high/urgent):",
              "normal",
              { title: "Task Priority", confirmText: "Use Priority" }
            );
            const selectedPriority = selectedPriorityValue === null ? "normal" : String(selectedPriorityValue || "normal");
            const priority = normalizePriority(selectedPriority);
            for (const item of verifiedItems) {
              await updateQueueStatus(item.queue_id, "submit_to_oc", priority);
            }
            await loadQueue();
            triggerStaffRefresh();
          };
        } else {
          queueBulkActions.style.display = "none";
          queueSubmitAllBtn.onclick = null;
        }
      }

      queueList.innerHTML = data.records.map((item) => {
        const statusLabel = formatQueueStatus(item.status);
        const actions = `
            <button class="card-action-btn" data-action="submit_to_oc" data-queue="${item.queue_id}">Submit to OC/Pen</button>
            <button class="card-action-btn danger" data-action="drop" data-queue="${item.queue_id}">Drop</button>
        `;

        return `
          <div class="queue-item">
            <div class="queue-item-header">
              <div>
                <div class="queue-item-title">${escapeHtml(item.name || "Unknown")} (${escapeHtml(item.regNo || "N/A")})</div>
                <div class="queue-item-meta">${escapeHtml(item.prisonUnit || "N/A")} | ${escapeHtml(formatRetirementType(item.retirementType))}</div>
              </div>
              <span class="status-badge badge-grey">${escapeHtml(statusLabel)}</span>
            </div>
            <div class="queue-item-actions">
              ${actions}
            </div>
          </div>
        `;
      }).join("");

      queueList.querySelectorAll("button[data-queue]").forEach((btn) => {
        btn.addEventListener("click", async () => {
          const queueId = btn.dataset.queue;
          const action = btn.dataset.action;
          let priority = "normal";
          if (action === "submit_to_oc") {
            const selectedPriorityValue = await appPrompt(
              "Set task priority for OC/Pen forwarding (low/normal/high/urgent):",
              "normal",
              { title: "Task Priority", confirmText: "Use Priority" }
            );
            const selectedPriority = selectedPriorityValue === null ? "normal" : String(selectedPriorityValue || "normal");
            priority = normalizePriority(selectedPriority);
          }
          await updateQueueStatus(queueId, action, priority);
          loadQueue();
          triggerStaffRefresh();
        });
      });
    } catch (err) {
      console.error(err);
      if (queueBulkActions && queueSubmitAllBtn) {
        queueBulkActions.style.display = "none";
        queueSubmitAllBtn.onclick = null;
      }
      queueList.innerHTML = "<div class=\"app-state-message app-state-error\">Unable to load queue.</div>";
    }
  }

  async function openStaffDeleteQueueModal() {
    if (!canProcessStaffDeleteQueue()) {
      showFeedbackModal("error", "Access Denied", "You do not have permission to process staff delete requests.");
      return;
    }
    await loadStaffDeleteQueue();
    openModal(staffDeleteQueueModal);
  }

  async function loadStaffDeleteQueue() {
    if (!staffDeleteQueueList) return;
    staffDeleteQueueList.innerHTML = "<div class=\"app-state-message app-state-neutral\">Loading delete requests...</div>";
    try {
      const res = await fetch("../backend/api/get_staff_due_delete_requests.php?status=pending", {
        credentials: "include",
        cache: "no-store"
      });
      const data = await res.json();
      if (!res.ok || !data.success) {
        throw new Error(data.message || `HTTP ${res.status}`);
      }
      const rows = Array.isArray(data.records) ? data.records : [];
      if (!rows.length) {
        staffDeleteQueueList.innerHTML = "<div class=\"app-state-message app-state-neutral\">No pending delete requests.</div>";
        return;
      }

      staffDeleteQueueList.innerHTML = rows.map((item) => {
        const staffLabel = formatTitleName(item.staff_title || "", item.staff_name || "", "") || (item.staff_name || "Unknown");
        return `
        <div class="queue-item">
          <div class="queue-item-header">
            <div>
              <div class="queue-item-title">${escapeHtml(staffLabel)} (${escapeHtml(item.regNo || "N/A")})</div>
              <div class="queue-item-meta">${escapeHtml(item.staff_title || "N/A")} | Requested by ${escapeHtml(item.requested_by_name || "Unknown")} (${escapeHtml(formatQueueStatus(item.requested_by_role || ""))})</div>
            </div>
            <span class="status-badge badge-grey">Pending</span>
          </div>
          <div class="queue-item-meta" style="margin: 0.5rem 0 0.75rem;">${escapeHtml(item.reason || "No reason provided.")}</div>
          <div class="queue-item-actions">
            <button class="card-action-btn" data-staff-delete-action="approve" data-request-id="${item.request_id}">Approve</button>
            <button class="card-action-btn danger" data-staff-delete-action="reject" data-request-id="${item.request_id}">Reject</button>
          </div>
        </div>
      `}).join("");

      staffDeleteQueueList.querySelectorAll("[data-staff-delete-action]").forEach((button) => {
        button.addEventListener("click", async () => {
          const requestId = Number(button.dataset.requestId || 0);
          const action = String(button.dataset.staffDeleteAction || "");
          await processStaffDeleteRequest(requestId, action);
          await loadStaffDeleteQueue();
          triggerStaffRefresh();
        });
      });
    } catch (error) {
      console.error("Unable to load staff delete requests:", error);
      staffDeleteQueueList.innerHTML = `<div class="app-state-message app-state-error">${escapeHtml(error.message || "Unable to load delete requests.")}</div>`;
    }
  }

  async function processStaffDeleteRequest(requestId, action) {
    if (!requestId || !["approve", "reject"].includes(action)) return;
    let note = "";
    if (action === "reject") {
      const response = await appPrompt("Provide a rejection reason:", "", {
        title: "Reject Delete Request",
        confirmText: "Reject"
      });
      if (response === null) return;
      note = String(response || "").trim();
      if (!note) {
        showFeedbackModal("error", "Rejection Note Required", "Provide a note before rejecting the request.");
        return;
      }
    } else {
      const confirmed = await appConfirm("Approve this delete request and remove the record from active staff due lists?", {
        title: "Approve Delete Request",
        confirmText: "Approve",
        type: "danger"
      });
      if (!confirmed) return;
    }

    try {
      const res = await fetch("../backend/api/process_staff_due_delete_request.php", {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ request_id: requestId, action, note })
      });
      const data = await res.json();
      if (!res.ok || !data.success) {
        throw new Error(data.message || `HTTP ${res.status}`);
      }
      showFeedbackModal("success", "Delete Queue Updated", data.message || "Delete request processed successfully.");
    } catch (error) {
      console.error("Failed to process staff delete request:", error);
      showFeedbackModal("error", "Queue Update Failed", error.message || "Unable to process delete request.");
    }
  }

  function normalizePriority(value) {
    const normalized = String(value || "").trim().toLowerCase();
    return ["low", "normal", "high", "urgent"].includes(normalized) ? normalized : "normal";
  }

  function normalizeSubmissionStatus(value) {
    const normalized = String(value || "").trim().toLowerCase();
    return normalized === "submitted" ? "submitted" : "pending";
  }

  function normalizeApplicationStatus(value) {
    const normalized = String(value || "").trim().toLowerCase();
    if (normalized === "verified") return "verified";
    if (normalized === "queried" || normalized === "querried") return "queried";
    if (normalized === "rejected") return "rejected";
    if (normalized === "in_process" || normalized === "in process" || normalized === "inprocess") return "in_process";
    if (normalized === "completed" || normalized === "approved" || normalized === "done") return "completed";
    return "pending";
  }

  function normalizeWorkflowActionState(value) {
    const normalized = String(value || "").trim().toLowerCase();
    if (normalized === "completed") return "completed";
    if (normalized === "in_process" || normalized === "in process" || normalized === "inprocess") return "in_process";
    return "";
  }

  function normalizeWorkflowInitiationState(value) {
    const normalized = String(value || "").trim().toLowerCase();
    if (normalized === "escalated") return "escalated";
    if (normalized === "due_soon" || normalized === "duesoon") return "due_soon";
    if (normalized === "initiated") return "initiated";
    if (normalized === "not_submitted" || normalized === "notsubmitted") return "not_submitted";
    return "pending";
  }

  function getCardStatusChip(staff) {
    const workflowState = normalizeWorkflowActionState(staff?.workflow_action_state || staff?.workflowActionState);
    if (workflowState === "completed") return { label: "Completed", className: "chip-completed" };
    if (workflowState === "in_process") return { label: "In Process", className: "chip-in-process" };

    const submissionState = normalizeSubmissionStatus(staff?.submissionStatus);
    const appnState = normalizeApplicationStatus(staff?.appnStatus);

    if (appnState === "completed") return { label: "Completed", className: "chip-completed" };
    if (appnState === "in_process") return { label: "In Process", className: "chip-in-process" };
    if (appnState === "rejected") return { label: "Rejected", className: "chip-rejected" };
    if (appnState === "queried") return { label: "Queried", className: "chip-queried" };
    if (appnState === "verified") return { label: "Verified", className: "chip-verified" };
    if (submissionState === "submitted") return { label: "Submitted", className: "chip-submitted" };
    return { label: "Pending", className: "chip-pending" };
  }

  function getWorkflowInitiationMeta(staff) {
    const state = normalizeWorkflowInitiationState(staff?.workflow_initiation_state || staff?.workflowInitiationState);
    const days = Number(staff?.days_since_submission ?? staff?.daysSinceSubmission ?? 0);

    if (state === "escalated") {
      return {
        className: "staff-escalation-alert is-danger",
        label: "Verification overdue",
        detail: days > 0
          ? `Submitted ${days} days ago and still awaiting verification start.`
          : `Submitted application has crossed the ${verificationEscalationWindowDays}-day verification-start rule.`
      };
    }

    if (state === "due_soon") {
      return {
        className: "staff-escalation-alert is-warning",
        label: "Verification due soon",
        detail: days > 0
          ? `Submitted ${days} days ago. Start verification before the ${verificationEscalationWindowDays}-day limit.`
          : `Submitted application is nearing the ${verificationEscalationWindowDays}-day verification-start rule.`
      };
    }

    return null;
  }

  async function updateQueueStatus(queueId, action, priority = "normal") {
    try {
      const res = await fetch("../backend/api/update_queue_status.php", {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ queue_id: Number(queueId), action, priority })
      });
      const data = await res.json();
      if (!data.success) {
        showFeedbackModal("error", "Queue Update Failed", data.message || "Unable to update queue.");
      } else {
        showFeedbackModal("success", "Queue Updated", data.message || "Queue updated successfully.");
      }
    } catch (err) {
      console.error(err);
      showFeedbackModal("error", "Queue Update Failed", "Unable to update queue.");
    }
  }

  function formatStatus(status) {
    if (!status) return "Pending";
    return status.charAt(0).toUpperCase() + status.slice(1);
  }

  function formatAppnStatus(status) {
    if (!status) return "Pending";
    if (status === "querried" || status === "queried") return "Queried";
    return status.charAt(0).toUpperCase() + status.slice(1);
  }

  function formatQueueStatus(status) {
    if (!status) return "Verified";
    return status.replace(/_/g, " ").replace(/\b\w/g, (m) => m.toUpperCase());
  }

  function formatDate(date) {
    if (!date) return "N/A";
    return date;
  }

  function formatCardDate(value) {
    if (!value) return "N/A";
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) {
      return String(value);
    }
    return parsed.toLocaleDateString("en-GB", {
      day: "2-digit",
      month: "short",
      year: "numeric"
    }).replace(/\s/g, "-");
  }

  function formatCurrency(value) {
    if (value === null || value === undefined || value === "") return "N/A";
    const num = Number(value);
    if (Number.isNaN(num)) return "N/A";
    return `UGX ${num.toLocaleString()}`;
  }

  function formatNumber(value) {
    if (value === null || value === undefined || value === "") return "N/A";
    const num = Number(value);
    if (Number.isNaN(num)) return "N/A";
    return num.toLocaleString();
  }

  function calcFinancialYearLabel(retirementDate) {
    if (!retirementDate) return "N/A";
    const date = new Date(retirementDate);
    if (Number.isNaN(date.getTime())) return "N/A";
    const year = date.getFullYear();
    const month = date.getMonth() + 1;
    const startYear = month <= 6 ? year - 1 : year;
    const endYear = month <= 6 ? year : year + 1;
    return `FY ${startYear}/${endYear}`;
  }

  function calcQuarterLabel(retirementDate) {
    if (!retirementDate) return "N/A";
    const date = new Date(retirementDate);
    if (Number.isNaN(date.getTime())) return "N/A";
    const month = date.getMonth() + 1;
    if (month >= 7 && month <= 9) return "Q1 (Jul-Sep)";
    if (month >= 10 && month <= 12) return "Q2 (Oct-Dec)";
    if (month >= 1 && month <= 3) return "Q3 (Jan-Mar)";
    return "Q4 (Apr-Jun)";
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
}

function startStaffDueController() {
  initStaffDueController().catch((error) => {
    console.error("Staff due controller initialization failed:", error);
    const staffContainer = document.getElementById("staffContainer");
    if (staffContainer) {
      staffContainer.innerHTML = "<div class=\"app-state-message app-state-error\">Unable to initialize staff due records.</div>";
    }
  });
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", startStaffDueController, { once: true });
} else {
  startStaffDueController();
}
