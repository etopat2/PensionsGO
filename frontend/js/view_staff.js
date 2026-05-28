document.addEventListener("DOMContentLoaded", async () => {
  const urlParams = new URLSearchParams(window.location.search);
  const staffId = urlParams.get("id");
  const container = document.getElementById("staffDetails");
  let currentUserRole = "";
  let currentPermissions = {};
  const defaultStaffDueEditRoles = new Set(["admin", "clerk", "data_entry", "writeup_officer"]);
  const workflowActionRoles = new Set(["admin", "clerk", "data_entry"]);

  function formatRetirementType(value) {
    return window.PensionsGoRetirementTypes?.getLabel?.(value) || String(value || "").trim() || "N/A";
  }

  (function consumeViewerReturnState() {
    const params = new URLSearchParams(window.location.search || "");
    const returnKey = String(params.get("viewer_return") || "").trim();
    if (!returnKey || !window.PensionsGoDocumentViewer?.consumeReturnState) {
      return;
    }
    window.PensionsGoDocumentViewer.consumeReturnState(returnKey);
    params.delete("viewer_return");
    const nextQuery = params.toString();
    const cleanUrl = `${window.location.pathname.split("/").pop()}${nextQuery ? `?${nextQuery}` : ""}${window.location.hash || ""}`;
    window.history.replaceState({}, "", cleanUrl);
  })();

  if (!container) return;

  if (!staffId) {
    renderState("Invalid staff record.", "error");
    return;
  }

  function getPermissionValue(key, fallback = false) {
    if (Object.prototype.hasOwnProperty.call(currentPermissions, key)) {
      return Boolean(currentPermissions[key]);
    }
    return Boolean(fallback);
  }

  function canEditStaffDueRecord() {
    return getPermissionValue("staff_due.edit", defaultStaffDueEditRoles.has(currentUserRole));
  }

  function canRegisterStaffApplication() {
    return workflowActionRoles.has(currentUserRole);
  }

  async function loadCurrentPermissions() {
    try {
      const res = await fetch("../backend/api/get_current_permissions.php?keys=staff_due.edit", {
        credentials: "include",
        cache: "no-store"
      });
      const data = await res.json();
      if (res.ok && data.success && data.permissions && typeof data.permissions === "object") {
        currentPermissions = data.permissions;
        return;
      }
    } catch (error) {
      console.error("Unable to load current permissions:", error);
    }
    currentPermissions = {};
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
          return false;
        }
      currentUserRole = String(data.userRoleEffective || data.userRole || "").toLowerCase();
      await loadCurrentPermissions();
      return true;
    } catch (err) {
      console.error("Session check failed:", err);
      window.location.href = "login.html";
      return false;
    }
  }

  const sessionOk = await checkSession();
  if (!sessionOk) return;

  function setActiveViewTab(tabKey) {
    const buttons = Array.from(container.querySelectorAll(".workspace-tab"));
    const panels = Array.from(container.querySelectorAll(".workspace-panel"));
    const safeKey = buttons.some((button) => button.dataset.tabTarget === tabKey)
      ? tabKey
      : (buttons[0]?.dataset.tabTarget || "profile");

    buttons.forEach((button) => {
      const isActive = button.dataset.tabTarget === safeKey;
      button.classList.toggle("is-active", isActive);
      button.setAttribute("aria-selected", isActive ? "true" : "false");
    });
    panels.forEach((panel) => {
      panel.classList.toggle("is-active", panel.dataset.panel === safeKey);
    });

    const tabOrder = buttons.map((button) => button.dataset.tabTarget);
    const currentIndex = tabOrder.indexOf(safeKey);
    const prevBtn = container.querySelector("#staffViewPrevBtn");
    const nextBtn = container.querySelector("#staffViewNextBtn");
    if (prevBtn) prevBtn.disabled = currentIndex <= 0;
    if (nextBtn) {
      nextBtn.disabled = currentIndex >= tabOrder.length - 1;
      nextBtn.textContent = currentIndex >= tabOrder.length - 1 ? "Review Tabs" : "Next";
    }
  }

  function bindViewTabs() {
    const buttons = Array.from(container.querySelectorAll(".workspace-tab"));
    const tabOrder = buttons.map((button) => button.dataset.tabTarget);

    buttons.forEach((button) => {
      button.addEventListener("click", () => setActiveViewTab(button.dataset.tabTarget));
    });

    container.querySelector("#staffViewPrevBtn")?.addEventListener("click", () => {
      const active = buttons.find((button) => button.classList.contains("is-active"))?.dataset.tabTarget || tabOrder[0];
      const index = tabOrder.indexOf(active);
      if (index > 0) setActiveViewTab(tabOrder[index - 1]);
    });

    container.querySelector("#staffViewNextBtn")?.addEventListener("click", () => {
      const active = buttons.find((button) => button.classList.contains("is-active"))?.dataset.tabTarget || tabOrder[0];
      const index = tabOrder.indexOf(active);
      if (index < tabOrder.length - 1) setActiveViewTab(tabOrder[index + 1]);
    });

    setActiveViewTab(tabOrder[0] || "profile");
  }

  async function fetchStaffDetails() {
    renderState("Loading officer record...", "neutral");
    try {
      const res = await fetch(`../backend/api/get_staff.php?id=${encodeURIComponent(staffId)}`, {
        credentials: "include",
        cache: "no-store"
      });
      const data = await res.json();

      if (!data.success) {
        renderState(data.message || "Unable to load record.", "error");
        return;
      }

      const s = data.record || {};
      const fullName = formatTitleName(s.title || "", s.sName || "", s.fName || "");
      const tel = s.telNo || "";
      const showEditAction = canEditStaffDueRecord();
      const showRegisterAction = canRegisterStaffApplication();

      container.innerHTML = `
        <section class="workspace-shell staff-view-layout registry-view-layout">
          <div class="workspace-header-stack">
          <header class="workspace-header staff-hero registry-view-hero">
            <div>
              <h2>${escapeHtml(fullName || "Unknown Officer")}</h2>
              <p class="staff-hero-subtitle">File ${escapeHtml(s.regNo || "N/A")} | ${escapeHtml(s.prisonUnit || "Station not set")}</p>
              <div class="staff-status-row">
                <span class="status-chip ${statusClass(s.submissionStatus)}">${escapeHtml(formatStatus(s.submissionStatus))} Submission</span>
                <span class="status-chip ${statusClass(s.appnStatusEffective || s.appnStatus)}">${escapeHtml(formatStatus(s.appnStatusEffective || s.appnStatus))} Application</span>
              </div>
            </div>
            <div class="workspace-header-actions hero-actions">
              ${showEditAction ? '<button id="editBtn" class="workspace-btn primary">Edit Record</button>' : ''}
              ${showRegisterAction ? `<button id="registerBtn" class="workspace-btn secondary" ${s.submissionStatus === "submitted" ? "disabled" : ""}>Register Application</button>` : ''}
              <button id="backBtn" class="workspace-btn ghost">Back</button>
            </div>
          </header>

          <div class="workspace-banner registry-edit-form-banner registry-view-banner">
            <strong>Record Overview</strong>
            <p>Use the tabs below to review the officer profile, benefits, contact record, and linked documents without losing context.</p>
          </div>
          </div>

          <div class="workspace-tabs registry-edit-tabs" role="tablist" aria-label="View staff sections">
            <button type="button" class="workspace-tab registry-edit-tab is-active" data-tab-target="profile"><span>Profile</span><span class="workspace-tab-status" aria-hidden="true"></span></button>
            <button type="button" class="workspace-tab registry-edit-tab" data-tab-target="service"><span>Service & Benefits</span><span class="workspace-tab-status" aria-hidden="true"></span></button>
            <button type="button" class="workspace-tab registry-edit-tab" data-tab-target="contact"><span>Contact & Banking</span><span class="workspace-tab-status" aria-hidden="true"></span></button>
            <button type="button" class="workspace-tab registry-edit-tab" data-tab-target="documents"><span>Uploaded Documents</span><span class="workspace-tab-status" aria-hidden="true"></span></button>
          </div>

          <div class="workspace-panels registry-edit-panels">
            <section class="workspace-panel registry-edit-panel-group is-active" data-panel="profile" role="tabpanel">
              <div class="workspace-panel-scroll">
                <h3 class="workspace-section-title">Identity & Classification</h3>
                <div class="staff-info-grid registry-view-grid">
                  ${buildInfoItems([
                    ["File Number", s.regNo],
                    ["Computer Number", s.computerNo || s.supplierNo],
                    ["Title", s.title],
                    ["Gender", s.gender],
                    ["NIN", s.NIN],
                    ["TIN", s.TIN]
                  ])}
                </div>
              </div>
            </section>

            <section class="workspace-panel registry-edit-panel-group" data-panel="service" role="tabpanel">
              <div class="workspace-panel-scroll">
                <h3 class="workspace-section-title">Service Timeline</h3>
                <div class="staff-info-grid registry-view-grid">
                  ${buildInfoItems([
                    ["Date of Birth", s.birthDate],
                    ["Date of Enlistment", s.enlistmentDate],
                    ["Date of Retirement", s.retirementDate],
                    ["Financial Year", s.financialYear],
                    ["Retirement Type", formatRetirementType(s.retirementType)],
                    ["Length of Service (Months)", s.lengthOfService],
                    ["Monthly Salary", formatCurrency(s.monthlySalary)],
                    ["Annual Salary", formatCurrency(s.annualSalary)],
                    ["Reduced Pension", formatCurrency(s.reducedPension)],
                    ["Full Pension", formatCurrency(s.fullPension)],
                    ["Commuted Pension Gratuity", formatCurrency(s.gratuity)]
                  ])}
                </div>
              </div>
            </section>

            <section class="workspace-panel registry-edit-panel-group" data-panel="contact" role="tabpanel">
              <div class="workspace-panel-scroll">
                <h3 class="workspace-section-title">Contact & Banking</h3>
                <div class="staff-info-grid registry-view-grid">
                  ${buildInfoItems([
                    ["Phone", s.telNo],
                    ["Applicant Email", s.applicant_email],
                    ["District of Residence", s.address],
                    ["Next of Kin", s.next_of_kin],
                    ["Next of Kin Contact", s.next_of_kin_contact],
                    ["Bank Name", s.bank_name],
                    ["Bank Account", s.bank_account],
                    ["Bank Branch", s.bank_branch]
                  ])}
                </div>
                ${tel ? `
                  <div class="staff-mobile-actions">
                    <button class="call-btn" onclick="window.location.href='tel:${escapeHtml(tel)}'">Call</button>
                    <button class="message-btn" onclick="window.location.href='sms:${escapeHtml(tel)}'">Message</button>
                  </div>
                ` : ""}
              </div>
            </section>

            <section class="workspace-panel registry-edit-panel-group" data-panel="documents" role="tabpanel">
              <div class="workspace-panel-scroll">
                <h3 class="workspace-section-title">Uploaded Documents</h3>
                <div class="doc-panel registry-view-doc-panel">
                  <div id="docList" class="doc-list app-state-message app-state-neutral">Loading documents...</div>
                </div>
              </div>
            </section>
          </div>

          <div class="workspace-navigation">
            <div class="workspace-navigation-group">
              <button type="button" id="staffViewPrevBtn" class="workspace-nav-btn secondary">Previous</button>
              <button type="button" id="staffViewNextBtn" class="workspace-nav-btn secondary">Next</button>
            </div>
          </div>
        </section>
      `;

      const editBtn = document.getElementById("editBtn");
      if (editBtn) {
        editBtn.addEventListener("click", () => {
          window.location.href = `edit_staff.html?id=${encodeURIComponent(s.id)}&from=view_staff`;
        });
      }

      const registerBtn = document.getElementById("registerBtn");
      if (registerBtn) {
        registerBtn.addEventListener("click", () => registerApplication(s.id));
      }

      const backBtn = document.getElementById("backBtn");
      if (backBtn) {
        backBtn.addEventListener("click", () => {
          window.location.href = "staff_due.html";
        });
      }

      bindViewTabs();
      loadDocuments();
    } catch (err) {
      console.error(err);
      renderState("Error loading staff details.", "error");
    }
  }

  async function registerApplication(id) {
    const shouldRegister = await appConfirm("Are you sure you want to register this application?", {
      title: "Register Application",
      confirmText: "Register"
    });
    if (!shouldRegister) return;

    try {
      const res = await fetch("../backend/api/register_application.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: `id=${encodeURIComponent(id)}`,
        credentials: "include"
      });
      const data = await res.json();

      if (data.success) {
        showFeedbackModal("success", "Application Registered", "Application registered successfully.");
        localStorage.setItem("staffDueUpdated", Date.now().toString());
        fetchStaffDetails();
      } else {
        showFeedbackModal("error", "Registration Failed", data.message || "Unable to register application.");
      }
    } catch (err) {
      console.error(err);
      showFeedbackModal("error", "Registration Failed", "Network error occurred.");
    }
  }

  async function loadDocuments() {
    const list = document.getElementById("docList");
    if (!list) return;

    list.className = "doc-list app-state-message app-state-neutral";
    list.textContent = "Loading documents...";
    try {
      const res = await fetch(`../backend/api/get_staff_documents.php?staffId=${encodeURIComponent(staffId)}`, {
        credentials: "include",
        cache: "no-store"
      });
      const data = await res.json();
      if (!data.success || !data.documents.length) {
        list.className = "doc-list app-state-message app-state-neutral";
        list.textContent = "No documents uploaded.";
        return;
      }

      list.className = "doc-list";
      list.innerHTML = data.documents.map((doc) => {
        const fileName = doc.file_name || "Document";
        const sourceUrl = `../backend/api/view_staff_document.php?document_id=${encodeURIComponent(doc.document_id)}`;
        const viewerUrl = window.PensionsGoDocumentViewer?.buildViewerUrl
          ? (window.PensionsGoDocumentViewer.buildViewerUrl(sourceUrl, {
            label: fileName,
            backUrl: window.location.href,
            returnState: {
              page: "view_staff",
              staffId
            }
          }) || sourceUrl)
          : sourceUrl;
        return `
          <div class="doc-item">
            <div class="doc-item-info">
              <strong>${escapeHtml(doc.doc_type || "Document")}</strong>
              <small>${escapeHtml(doc.file_name || "")} | ${escapeHtml(doc.uploaded_at || "")}</small>
            </div>
            <a class="doc-item-link" href="${viewerUrl}">View</a>
          </div>
        `;
      }).join("");
    } catch (err) {
      console.error("Failed to load documents:", err);
      list.className = "doc-list app-state-message app-state-error";
      list.textContent = "Unable to load documents.";
    }
  }

  function renderState(message, type = "neutral") {
    container.innerHTML = `<div class="app-state-message app-state-${type}">${escapeHtml(message)}</div>`;
  }

  function buildInfoItems(items) {
    return items.map(([label, value]) => {
      return `
        <div class="staff-info-item">
          <span>${escapeHtml(label)}</span>
          <strong>${escapeHtml(displayValue(value))}</strong>
        </div>
      `;
    }).join("");
  }

  function displayValue(value) {
    if (value === null || value === undefined || value === "") {
      return "N/A";
    }
    return value;
  }

  function formatStatus(status) {
    if (!status) return "Pending";
    if (status === "querried" || status === "queried") return "Queried";
    return status.charAt(0).toUpperCase() + status.slice(1);
  }

  function formatTitleName(title, sName, fName) {
    const cleanTitle = String(title || "").trim();
    const cleanName = `${String(sName || "").trim()} ${String(fName || "").trim()}`.trim();
    if (cleanTitle && cleanName) {
      return `${cleanTitle} - ${cleanName}`;
    }
    return cleanTitle || cleanName;
  }

  function statusClass(status) {
    const safe = (status || "pending").toLowerCase();
    return `chip-${safe}`;
  }

  function formatCurrency(value) {
    if (value === null || value === undefined || value === "") {
      return "N/A";
    }
    const number = Number(value);
    if (Number.isNaN(number)) return "N/A";
    return `UGX ${number.toLocaleString()}`;
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

  fetchStaffDetails();
});
