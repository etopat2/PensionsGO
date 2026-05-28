document.addEventListener("DOMContentLoaded", async () => {
  const form = document.getElementById("addStaffForm");
  if (!form) return;

  const backBtn = document.getElementById("staffDueBackBtn");
  const bulkUploadBtn = document.getElementById("staffDueBulkUploadBtn");
  const bulkUploadModal = document.getElementById("staffDueBulkUploadModal");
  const bulkUploadForm = document.getElementById("staffDueBulkUploadForm");
  const bulkUploadFile = document.getElementById("staffDueBulkUploadFile");
  const bulkUploadReport = document.getElementById("staffDueBulkUploadReport");
  const bulkDownloadTemplateBtn = document.getElementById("staffDueBulkDownloadTemplateBtn");
  const bulkDryRunBtn = document.getElementById("staffDueBulkDryRunBtn");
  const bulkImportBtn = document.getElementById("staffDueBulkImportBtn");
  const formMessage = document.getElementById("addStaffFormMessage");
  const tabButtons = Array.from(document.querySelectorAll("#addStaffTabs .workspace-tab"));
  const tabPanels = Array.from(document.querySelectorAll(".workspace-panel"));
  const prevBtn = document.getElementById("addStaffPrevBtn");
  const nextBtn = document.getElementById("addStaffNextBtn");
  const tabOrder = ["identity", "service", "workflow"];

  const regNo = document.getElementById("regNo");
  const computerNo = document.getElementById("computerNo");
  const sName = document.getElementById("sName");
  const fName = document.getElementById("fName");
  const gender = document.getElementById("gender");
  const nin = document.getElementById("NIN");
  const telNo = document.getElementById("telNo");
  const birthDate = document.getElementById("birthDate");
  const enlistmentDate = document.getElementById("enlistmentDate");
  const retirementDate = document.getElementById("retirementDate");
  const monthlySalary = document.getElementById("monthlySalary");
  const retirementType = document.getElementById("retirementType");
  const submissionStatus = document.getElementById("submissionStatus");
  const appnStatus = document.getElementById("appnStatus");

  const prisonUnitSelect = document.getElementById("prisonUnit");
  const prisonTrigger = document.getElementById("mobilePrisonSelect");
  const prisonModal = document.getElementById("prisonModal");
  const closePrisonModalBtn = document.getElementById("closePrisonModalBtn");
  const prisonSearch = document.getElementById("prisonSearch");
  const prisonList = document.getElementById("prisonList");
  const titleSelect = document.getElementById("title");

  const lengthOfService = document.getElementById("lengthOfService");
  const annualSalary = document.getElementById("annualSalary");
  const reducedPension = document.getElementById("reducedPension");
  const fullPension = document.getElementById("fullPension");
  const gratuity = document.getElementById("gratuity");
  const financialYear = document.getElementById("financialYear");
  const retirementPolicyHint = document.getElementById("retirementPolicyHint");

  [lengthOfService, annualSalary, reducedPension, fullPension, gratuity, financialYear].forEach((el) => {
    if (el) el.readOnly = true;
  });

  let currentUserRole = "";
  let currentPermissions = {};
  let submitAttempted = false;
  const touchedFields = new Set();
  const defaultEditRoles = new Set(["admin", "clerk", "data_entry", "writeup_officer"]);
  const defaultBulkUploadRoles = new Set(["admin", "oc_pen", "dep_oc", "deputy_oc", "deputy_oc_pen", "deputy_oc_pension", "data_entry"]);
  let prisonUnits = [];

  function getRetirementTypesApi() {
    return window.PensionsGoRetirementTypes || {
      getLabel: (value) => String(value || "").trim(),
      normalizeValue: (value) => String(value || "").trim(),
      validateRetirementProfile: () => ({
        valid: true,
        errors: [],
        warnings: [],
        primaryMessage: "",
        status: "neutral"
      }),
      calculateBenefitSnapshot: () => ({
        lengthOfService: 0,
        annualSalary: 0,
        reducedPension: 0,
        fullPension: 0,
        gratuity: 0
      })
    };
  }

  function parseMoneyInputValue(value, fallback = 0) {
    if (window.PensionsGoMoney?.parse) {
      return window.PensionsGoMoney.parse(value, fallback);
    }
    const parsed = Number.parseFloat(String(value || "").replace(/,/g, ""));
    return Number.isFinite(parsed) ? parsed : fallback;
  }

  function setMoneyInputValue(field, value) {
    if (!field) return;
    if (window.PensionsGoMoney?.setInputValue) {
      window.PensionsGoMoney.setInputValue(field, value);
      return;
    }
    field.value = value ?? "";
  }

  function normalizeNationalIdValue(value) {
    if (window.PensionsGoNin?.normalize) {
      return window.PensionsGoNin.normalize(value);
    }
    return String(value || "").trim().toUpperCase();
  }

  function validateNationalIdValue(value, options = {}) {
    if (window.PensionsGoNin?.validate) {
      return window.PensionsGoNin.validate(value, options);
    }
    const normalized = normalizeNationalIdValue(value);
    if (!normalized) {
      return { valid: true, normalized: "", message: "" };
    }
    if (!/^C[MF][A-Z0-9]{12}$/.test(normalized)) {
      return {
        valid: false,
        normalized,
        message: "NIN must start with CM or CF, use letters and numbers only, and be exactly 14 characters long."
      };
    }
    return { valid: true, normalized, message: "" };
  }

  function showFeedbackModal(type, title, message, onClose = null) {
    const existing = document.querySelector(".auth-modal-overlay");
    if (existing) existing.remove();

    const overlay = document.createElement("div");
    overlay.className = "auth-modal-overlay";
    overlay.innerHTML = `
      <div class="auth-modal">
        <div class="auth-modal-header">
          <h3>${escapeHtml(title)}</h3>
        </div>
        <div class="auth-modal-body">
          <p>${escapeHtml(message)}</p>
        </div>
        <div class="auth-modal-footer">
          <button type="button" class="auth-btn auth-btn-secondary" id="feedbackOkBtn">OK</button>
        </div>
      </div>
    `;

    document.body.appendChild(overlay);
    document.body.classList.add("modal-open");
    document.body.style.overflow = "hidden";

    const closeModal = () => {
      overlay.remove();
      document.body.classList.remove("modal-open");
      document.body.style.overflow = "";
      if (typeof onClose === "function") onClose();
    };

    overlay.addEventListener("click", (e) => {
      if (e.target === overlay) closeModal();
    });

    const okBtn = overlay.querySelector("#feedbackOkBtn");
    if (okBtn) okBtn.addEventListener("click", closeModal);
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

  function humanizeKey(value) {
    return String(value || "")
      .replace(/_/g, " ")
      .replace(/\b\w/g, (match) => match.toUpperCase());
  }

  function setFormMessage(message = "", type = "") {
    if (!formMessage) return;
    formMessage.textContent = message;
    formMessage.className = "workspace-form-message";
    if (type) {
      formMessage.classList.add(type);
    }
  }

  function getPermissionValue(key, fallback = false) {
    if (Object.prototype.hasOwnProperty.call(currentPermissions, key)) {
      return Boolean(currentPermissions[key]);
    }
    return Boolean(fallback);
  }

  function canEditStaffDue() {
    return getPermissionValue("staff_due.edit", defaultEditRoles.has(currentUserRole));
  }

  function canBulkUploadStaffDue() {
    const roleAllows = defaultBulkUploadRoles.has(currentUserRole);
    return roleAllows && getPermissionValue("staff_due.bulk_upload", roleAllows);
  }

  function syncFilterableSelect(selectEl) {
    if (!selectEl || !window.PensionsGoFilterableSelect?.syncElement) return;
    window.PensionsGoFilterableSelect.syncElement(selectEl);
  }

  function syncAllFilterableSelects() {
    [titleSelect, gender, prisonUnitSelect, retirementType].forEach(syncFilterableSelect);
  }

  function syncDirectCreateAccess() {
    const allowDirectCreate = canEditStaffDue();
    Array.from(form.elements || []).forEach((element) => {
      if (!element || typeof element.disabled === "undefined") return;
      if (element.type === "hidden") return;
      element.disabled = !allowDirectCreate;
    });
    if (!allowDirectCreate) {
      setFormMessage("Direct staff capture is unavailable for your role. You can still use bulk upload for validated schedules.", "error");
    } else if (formMessage?.textContent?.includes("Direct staff capture is unavailable")) {
      setFormMessage("");
    }
    updateNavigationButtons();
  }

  function getSelectedPrisonUnit() {
    const desktopValue = String(prisonUnitSelect?.value || "").trim();
    const mobileValue = String(prisonTrigger?.dataset.value || "").trim();
    return desktopValue || mobileValue;
  }

  function setFieldInvalid(field, invalid) {
    if (!field) return;
    field.classList.toggle("workspace-field-invalid", Boolean(invalid));
    if (field === prisonUnitSelect && prisonTrigger) {
      prisonTrigger.classList.toggle("workspace-field-invalid", Boolean(invalid));
    }
  }

  function getRetirementPolicyAssessment() {
    return getRetirementTypesApi().validateRetirementProfile({
      retirementType: retirementType?.value || "",
      birthDate: birthDate?.value || "",
      enlistmentDate: enlistmentDate?.value || "",
      retirementDate: retirementDate?.value || ""
    });
  }

  function updateRetirementPolicyHint() {
    if (!retirementPolicyHint) return;
    const assessment = getRetirementPolicyAssessment();
    const selectedRetirementType = String(retirementType?.value || "").trim();
    const label = selectedRetirementType ? getRetirementTypesApi().getLabel(selectedRetirementType) : "";
    const hasInputs = Boolean(
      selectedRetirementType
      || String(birthDate?.value || "").trim()
      || String(enlistmentDate?.value || "").trim()
      || String(retirementDate?.value || "").trim()
    );

    if (!hasInputs) {
      retirementPolicyHint.hidden = true;
      retirementPolicyHint.textContent = "";
      retirementPolicyHint.dataset.state = "neutral";
      return;
    }

    if (!selectedRetirementType) {
      retirementPolicyHint.hidden = false;
      retirementPolicyHint.textContent = "Select a retirement type to validate the age and service policy checks for this record.";
      retirementPolicyHint.dataset.state = "neutral";
      return;
    }

    if (assessment.primaryMessage) {
      retirementPolicyHint.hidden = false;
      retirementPolicyHint.textContent = assessment.primaryMessage;
      retirementPolicyHint.dataset.state = assessment.status || "warning";
      return;
    }

    if (label && assessment.valid && String(retirementDate?.value || "").trim()) {
      retirementPolicyHint.hidden = false;
      retirementPolicyHint.textContent = `${label} passes the current age and service policy checks for capture.`;
      retirementPolicyHint.dataset.state = "valid";
      return;
    }

    retirementPolicyHint.hidden = true;
    retirementPolicyHint.textContent = "";
    retirementPolicyHint.dataset.state = "neutral";
  }

  function resolveRuleMessage(rule) {
    if (!rule) return "";
    return typeof rule.message === "function" ? String(rule.message() || "") : String(rule.message || "");
  }

  const validationRules = {
    identity: [
      { field: regNo, message: "Identity Profile is missing the file number.", isInvalid: () => !String(regNo?.value || "").trim() },
      { field: titleSelect, message: "Identity Profile is missing the title or rank.", isInvalid: () => !String(titleSelect?.value || "").trim() },
      { field: sName, message: "Identity Profile is missing the surname.", isInvalid: () => !String(sName?.value || "").trim() },
      { field: fName, message: "Identity Profile is missing the first name.", isInvalid: () => !String(fName?.value || "").trim() },
      { field: gender, message: "Identity Profile is missing gender.", isInvalid: () => !String(gender?.value || "").trim() },
      {
        field: nin,
        message: () => validateNationalIdValue(nin?.value || "", {
          birthDate: birthDate?.value || "",
          gender: gender?.value || ""
        }).message || "Identity Profile has an invalid NIN.",
        isInvalid: () => {
          const value = String(nin?.value || "").trim();
          if (value === "") return false;
          return !validateNationalIdValue(value, {
            birthDate: birthDate?.value || "",
            gender: gender?.value || ""
          }).valid;
        }
      },
      {
        field: telNo,
        message: "Identity Profile has an invalid phone number. Use +256700123456 or a valid Uganda local number.",
        isInvalid: () => {
          const raw = String(telNo?.value || "").trim();
          return raw !== "" && !normalizePhone(raw);
        }
      }
    ],
    service: [
      { field: retirementDate, message: "Service & Benefits is missing the retirement date.", isInvalid: () => !String(retirementDate?.value || "").trim() },
      { field: retirementType, message: "Service & Benefits is missing the mode of retirement.", isInvalid: () => !String(retirementType?.value || "").trim() },
      {
        field: retirementType,
        message: () => getRetirementPolicyAssessment().primaryMessage || "The retirement profile does not satisfy the configured policy checks.",
        isInvalid: () => Boolean(getRetirementPolicyAssessment().errors.length)
      }
    ],
    workflow: []
  };

  const tabFieldGroups = {
    identity: [regNo, computerNo, titleSelect, sName, fName, gender, prisonUnitSelect, nin, telNo, birthDate],
    service: [enlistmentDate, retirementDate, financialYear, retirementType, monthlySalary, lengthOfService, annualSalary, reducedPension, fullPension, gratuity],
    workflow: [submissionStatus, appnStatus]
  };

  function setActiveTab(tabKey, { focusField = null } = {}) {
    const safeKey = tabOrder.includes(tabKey) ? tabKey : tabOrder[0];
    tabButtons.forEach((button) => {
      const isActive = button.dataset.tabTarget === safeKey;
      button.classList.toggle("is-active", isActive);
      button.setAttribute("aria-selected", isActive ? "true" : "false");
    });
    tabPanels.forEach((panel) => {
      panel.classList.toggle("is-active", panel.dataset.panel === safeKey);
    });
    updateNavigationButtons();
    if (focusField) {
      window.setTimeout(() => focusValidationTarget(focusField), 30);
    }
  }

  function getActiveTab() {
    return tabButtons.find((button) => button.classList.contains("is-active"))?.dataset.tabTarget || tabOrder[0];
  }

  function updateNavigationButtons() {
    const index = tabOrder.indexOf(getActiveTab());
    if (prevBtn) prevBtn.disabled = !canEditStaffDue() || index <= 0;
    if (nextBtn) {
      nextBtn.disabled = !canEditStaffDue() || index >= tabOrder.length - 1;
      nextBtn.textContent = index >= tabOrder.length - 1 ? "Review Tabs" : "Next";
    }
  }

  function focusValidationTarget(field) {
    if (!field) return;
    if (field === prisonUnitSelect) {
      if (window.matchMedia("(max-width: 768px)").matches && prisonTrigger) {
        prisonTrigger.focus();
        prisonTrigger.click();
      } else {
        prisonUnitSelect.focus();
      }
      return;
    }
    field.focus();
    if (typeof field.select === "function" && field.tagName === "INPUT") {
      field.select();
    }
  }

  function findFirstTabError(tabKey) {
    return (validationRules[tabKey] || []).find((rule) => rule.isInvalid()) || null;
  }

  function getTabState(tabKey) {
    const fields = (tabFieldGroups[tabKey] || []).filter(Boolean);
    const touched = fields.some((field) => touchedFields.has(field.id));
    const firstError = findFirstTabError(tabKey);
    const hasValue = fields.some((field) => {
      if (field === prisonUnitSelect) return getSelectedPrisonUnit() !== "";
      return String(field.value || "").trim() !== "";
    });

    if (firstError) {
      return submitAttempted || touched ? "invalid" : "neutral";
    }
    if (tabKey === "workflow") {
      return "valid";
    }
    return hasValue ? "valid" : "neutral";
  }

  function updateTabStates() {
    Object.entries(validationRules).forEach(([tabKey, rules]) => {
      const firstError = findFirstTabError(tabKey);
      const touched = (tabFieldGroups[tabKey] || []).some((field) => field && touchedFields.has(field.id));
      rules.forEach((rule) => setFieldInvalid(rule.field, Boolean(firstError === rule && (submitAttempted || touched))));
    });

    tabButtons.forEach((button) => {
      button.dataset.validState = getTabState(button.dataset.tabTarget);
    });
  }

  function validateForm() {
    for (const tabKey of tabOrder) {
      const rule = findFirstTabError(tabKey);
      if (rule) {
        return { tab: tabKey, field: rule.field, message: resolveRuleMessage(rule) };
      }
    }
    return null;
  }

  function showValidationError(error) {
    if (!error) return;
    setFormMessage(error.message, "error");
    showFeedbackModal("error", "Validation Error", error.message, () => {
      setActiveTab(error.tab, { focusField: error.field });
    });
  }

  async function checkSession() {
    try {
      const res = await fetch("../backend/api/check_session.php", {
        cache: "no-store",
        credentials: "include",
      });
      const data = await res.json();

      if (!data.active || !(data.userRole || data.userRoleEffective)) {
        window.location.href = "login.html";
        return false;
      }

      currentUserRole = String(data.userRoleEffective || data.userRole || "").toLowerCase();
      await loadCurrentPermissions();
      if (!canEditStaffDue() && !canBulkUploadStaffDue()) {
        showFeedbackModal(
          "error",
          "Access Denied",
          "You do not have access to direct staff capture or staff-due bulk upload.",
          () => {
            window.location.href = "dashboard.html";
          }
        );
        return false;
      }

      if (bulkUploadBtn) {
        bulkUploadBtn.classList.toggle("hidden", !canBulkUploadStaffDue());
      }
      syncDirectCreateAccess();
      enableLogout();
      return true;
    } catch (err) {
      console.error("Session check failed:", err);
      window.location.href = "login.html";
      return false;
    }
  }

  async function loadCurrentPermissions() {
    try {
      const res = await fetch("../backend/api/get_current_permissions.php?keys=staff_due.edit,staff_due.bulk_upload", {
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

  function enableLogout() {
    const logoutBtn = document.getElementById("logoutBtn");
    if (!logoutBtn) return;

    logoutBtn.addEventListener("click", async (e) => {
      e.preventDefault();

      const overlay = document.createElement("div");
      overlay.className = "logout-modal-overlay";
      overlay.innerHTML = `
        <div class="logout-modal">
          <div class="logout-modal-header">
            <div>
              <h3>Confirm Logout</h3>
            </div>
          </div>
          <p>Are you sure you want to logout?</p>
          <div class="modal-actions">
            <button class="btn-cancel">Cancel</button>
            <button class="btn-confirm">Logout</button>
          </div>
        </div>
      `;
      document.body.appendChild(overlay);
      document.body.classList.add("modal-open");
      document.body.style.overflow = "hidden";

      const closeOverlay = () => {
        overlay.remove();
        document.body.classList.remove("modal-open");
        document.body.style.overflow = "";
      };

      const cancelBtn = overlay.querySelector(".btn-cancel");
      const confirmBtn = overlay.querySelector(".btn-confirm");

      if (cancelBtn) cancelBtn.addEventListener("click", closeOverlay);

      overlay.addEventListener("click", (evt) => {
        if (evt.target === overlay) closeOverlay();
      });

      if (confirmBtn) {
        confirmBtn.addEventListener("click", async () => {
          overlay.innerHTML = `
            <div class="logout-overlay">
              <div class="spinner"></div>
              <p>Logging out...</p>
            </div>
          `;

          try {
            const csrfToken = window.fetchCsrfToken ? await window.fetchCsrfToken() : '';
            const res = await fetch("../backend/api/logout.php", {
              method: "POST",
              credentials: "include",
              headers: (window.withDeviceTokenHeaders
                ? window.withDeviceTokenHeaders({ "Content-Type": "application/json", "X-CSRF-Token": csrfToken })
                : { "Content-Type": "application/json", "X-CSRF-Token": csrfToken }),
            });
            const data = await res.json();
            if (data.success) {
              localStorage.clear();
              sessionStorage.clear();
              window.location.href = "login.html";
            } else {
              closeOverlay();
              showFeedbackModal("error", "Logout Failed", data.message || "Unable to logout right now.");
            }
          } catch (err) {
            console.error("Logout error:", err);
            window.location.href = "login.html";
          }
        });
      }
    });
  }

  function calcServicePeriod(start, end) {
    if (!start || !end) {
      return { months: 0, days: 0, roundedMonths: 0 };
    }
    const s = new Date(start);
    const e = new Date(end);
    if (Number.isNaN(s.getTime()) || Number.isNaN(e.getTime()) || e <= s) {
      return { months: 0, days: 0, roundedMonths: 0 };
    }

    let years = e.getFullYear() - s.getFullYear();
    let months = e.getMonth() - s.getMonth();
    let days = e.getDate() - s.getDate();

    if (days < 0) {
      const prevMonthEnd = new Date(e.getFullYear(), e.getMonth(), 0);
      days += prevMonthEnd.getDate();
      months -= 1;
    }

    if (months < 0) {
      months += 12;
      years -= 1;
    }

    const totalMonths = Math.max(0, (years * 12) + months);
    const safeDays = Math.max(0, days);
    const roundedMonths = safeDays >= 15 ? totalMonths + 1 : totalMonths;
    return { months: totalMonths, days: safeDays, roundedMonths };
  }

  function calcFinancialYear(retireDate) {
    if (!retireDate) return "";
    const d = new Date(retireDate);
    const year = d.getFullYear();
    const month = d.getMonth() + 1;
    return month <= 6 ? `FY ${year - 1}/${year}` : `FY ${year}/${year + 1}`;
  }

  function normalizePhone(value) {
    const input = String(value || "").trim().replace(/[\s().-]/g, "");
    if (!input) return null;
    if (/^00[1-9]\d{7,14}$/.test(input)) return `+${input.slice(2)}`;
    if (/^\+[1-9]\d{7,14}$/.test(input)) return input;
    if (/^0\d{9}$/.test(input)) return `+256${input.slice(1)}`;
    if (/^[1-9]\d{7,14}$/.test(input)) return `+${input}`;
    return null;
  }

  function updateComputedFields() {
    const retirementTypes = getRetirementTypesApi();
    const normalizedType = retirementTypes.normalizeValue(retirementType?.value || "");
    if (retirementType && normalizedType && retirementType.value !== normalizedType) {
      retirementType.value = normalizedType;
      syncFilterableSelect(retirementType);
    }

    const snapshot = retirementTypes.calculateBenefitSnapshot({
      retirementType: normalizedType,
      birthDate: birthDate?.value || "",
      enlistmentDate: enlistmentDate?.value || "",
      retirementDate: retirementDate?.value || "",
      monthlySalary: parseMoneyInputValue(monthlySalary?.value, 0)
    });

    if (lengthOfService) lengthOfService.value = String(snapshot.lengthOfService || 0);
    if (annualSalary) setMoneyInputValue(annualSalary, Number(snapshot.annualSalary || 0).toFixed(2));
    if (reducedPension) setMoneyInputValue(reducedPension, Number(snapshot.reducedPension || 0).toFixed(2));
    if (fullPension) setMoneyInputValue(fullPension, Number(snapshot.fullPension || 0).toFixed(2));
    if (gratuity) setMoneyInputValue(gratuity, Number(snapshot.gratuity || 0).toFixed(2));
    if (financialYear) financialYear.value = calcFinancialYear(retirementDate?.value);
    updateRetirementPolicyHint();
  }

  function renderPrisonList(list) {
    if (!prisonList) return;
    prisonList.innerHTML = "";

    list.forEach((unit) => {
      const div = document.createElement("div");
      div.textContent = unit;
      div.className = "prison-item";

      div.addEventListener("click", () => {
        if (prisonTrigger) {
          prisonTrigger.textContent = unit;
          prisonTrigger.dataset.value = unit;
        }
        if (prisonUnitSelect) {
          prisonUnitSelect.value = unit;
          syncFilterableSelect(prisonUnitSelect);
        }
        if (prisonModal) prisonModal.style.display = "none";
        touchedFields.add(prisonUnitSelect?.id || "prisonUnit");
        updateTabStates();
      });

      prisonList.appendChild(div);
    });
  }

  function bindFieldTracking() {
    Object.values(tabFieldGroups)
      .flat()
      .filter(Boolean)
      .forEach((field) => {
        const handler = () => {
          touchedFields.add(field.id);
          if (field === prisonUnitSelect && prisonTrigger) {
            prisonTrigger.dataset.value = field.value;
            prisonTrigger.textContent = field.value || "Tap to select Prison Unit";
          }
          updateComputedFields();
          updateTabStates();
        };
        field.addEventListener("input", handler);
        field.addEventListener("change", handler);
      });
  }

  function bindTabs() {
    tabButtons.forEach((button) => {
      button.addEventListener("click", () => setActiveTab(button.dataset.tabTarget));
    });

    prevBtn?.addEventListener("click", () => {
      const index = tabOrder.indexOf(getActiveTab());
      if (index > 0) {
        setActiveTab(tabOrder[index - 1]);
      }
    });

    nextBtn?.addEventListener("click", () => {
      const index = tabOrder.indexOf(getActiveTab());
      if (index < tabOrder.length - 1) {
        setActiveTab(tabOrder[index + 1]);
      }
    });
  }

  function resetBulkUploadReport({ clearFile = false } = {}) {
    if (bulkUploadReport) {
      bulkUploadReport.classList.add("hidden");
      bulkUploadReport.innerHTML = "";
    }
    if (clearFile && bulkUploadForm) {
      bulkUploadForm.reset();
    }
  }

  function setBulkUploadBusy(isBusy, mode = "dry_run") {
    if (bulkDryRunBtn) {
      bulkDryRunBtn.disabled = isBusy;
      bulkDryRunBtn.textContent = isBusy && mode === "dry_run" ? "Running..." : "Run Dry Check";
    }
    if (bulkImportBtn) {
      bulkImportBtn.disabled = isBusy;
      bulkImportBtn.textContent = isBusy && mode === "import" ? "Importing..." : "Import Data";
    }
    if (bulkDownloadTemplateBtn) bulkDownloadTemplateBtn.disabled = isBusy;
    if (bulkUploadFile) bulkUploadFile.disabled = isBusy;
  }

  function renderBulkUploadLoading(mode = "dry_run") {
    if (!bulkUploadReport) return;
    bulkUploadReport.classList.remove("hidden");
    bulkUploadReport.innerHTML = `
      <div class="registry-import-loading">
        <span class="registry-import-spinner" aria-hidden="true"></span>
        <div>
          <strong>${escapeHtml(mode === "import" ? "Importing staff-due records..." : "Running dry check on staff-due records...")}</strong>
          <p>Please wait while the upload is validated and summarized.</p>
        </div>
      </div>
    `;
  }

  function formatImportFieldList(fields) {
    if (!Array.isArray(fields) || fields.length === 0) return "-";
    return fields.map((field) => humanizeKey(field)).join(", ");
  }

  function renderBulkUploadResult(report, status = "success", message = "") {
    if (!bulkUploadReport) return;
    const summary = report?.summary || {};
    const rows = Array.isArray(report?.rows) ? report.rows : [];
    const statusLabel = status === "partial" ? "Needs Review" : status === "failed" ? "Failed" : "Ready";

    bulkUploadReport.classList.remove("hidden");
    bulkUploadReport.innerHTML = `
      <section class="registry-import-report-panel">
        <div class="registry-import-report-head">
          <div>
            <h4>${escapeHtml(report?.dataset_label || "Staff Due Import Report")}</h4>
            <p>${escapeHtml(message || "Review the validation outcome below.")}</p>
          </div>
          <span class="registry-import-status-pill ${escapeHtml(status)}">${escapeHtml(statusLabel)}</span>
        </div>
        <div class="registry-import-summary-grid">
          <article class="registry-import-metric"><strong>${escapeHtml(String(summary.total_rows ?? 0))}</strong><span>Rows Checked</span></article>
          <article class="registry-import-metric"><strong>${escapeHtml(String(summary.inserted_rows ?? 0))}</strong><span>Inserted</span></article>
          <article class="registry-import-metric"><strong>${escapeHtml(String(summary.merged_rows ?? 0))}</strong><span>Merged</span></article>
          <article class="registry-import-metric"><strong>${escapeHtml(String(summary.skipped_exact_rows ?? 0))}</strong><span>Already Matched</span></article>
          <article class="registry-import-metric"><strong>${escapeHtml(String(summary.conflict_rows ?? 0))}</strong><span>Conflicts</span></article>
          <article class="registry-import-metric"><strong>${escapeHtml(String(summary.invalid_rows ?? 0))}</strong><span>Invalid</span></article>
        </div>
        ${rows.length ? `
          <div class="registry-import-table-wrap">
            <table class="registry-import-table">
              <thead>
                <tr>
                  <th>Row</th>
                  <th>Outcome</th>
                  <th>File Number</th>
                  <th>Details</th>
                </tr>
              </thead>
              <tbody>
                ${rows.map((row) => `
                  <tr>
                    <td>${escapeHtml(String(row.row_number ?? "-"))}</td>
                    <td>${escapeHtml(humanizeKey(row.outcome || "review"))}</td>
                    <td>${escapeHtml(String(row.key_value ?? row.mapped_key ?? "-"))}</td>
                    <td>
                      ${escapeHtml(String(row.message || "No message supplied."))}
                      ${row.changed_fields?.length ? `<br><small>Changed: ${escapeHtml(formatImportFieldList(row.changed_fields))}</small>` : ""}
                    </td>
                  </tr>
                `).join("")}
              </tbody>
            </table>
          </div>
        ` : ""}
      </section>
    `;
  }

  function openBulkUploadModal() {
    if (!canBulkUploadStaffDue()) {
      showFeedbackModal("error", "Access Denied", "Bulk upload is only available to authorized staff-due editors.");
      return;
    }
    resetBulkUploadReport({ clearFile: true });
    bulkUploadModal?.classList.remove("hidden");
    bulkUploadModal?.setAttribute("aria-hidden", "false");
    document.body.classList.add("modal-open");
    document.body.style.overflow = "hidden";
  }

  function closeBulkUploadModal() {
    bulkUploadModal?.classList.add("hidden");
    bulkUploadModal?.setAttribute("aria-hidden", "true");
    document.body.classList.remove("modal-open");
    document.body.style.overflow = "";
  }

  async function downloadBulkUploadTemplate() {
    try {
      const response = await fetch("../backend/api/download_staff_due_template.php", {
        credentials: "include",
        cache: "no-store"
      });
      if (!response.ok) {
        let message = "Unable to download the staff-due template.";
        try {
          const data = await response.json();
          message = data.message || message;
        } catch (_error) {
          // Ignore JSON parsing for file responses.
        }
        throw new Error(message);
      }

      const blob = await response.blob();
      const url = window.URL.createObjectURL(blob);
      const anchor = document.createElement("a");
      const disposition = response.headers.get("content-disposition") || "";
      const match = disposition.match(/filename=\"?([^\"]+)\"?/i);
      anchor.href = url;
      anchor.download = match?.[1] || "staff_due_template.csv";
      document.body.appendChild(anchor);
      anchor.click();
      anchor.remove();
      window.URL.revokeObjectURL(url);
    } catch (error) {
      console.error("Template download failed:", error);
      showFeedbackModal("error", "Template Download Failed", error.message || "Unable to download the staff-due template.");
    }
  }

  async function submitBulkUpload(mode = "dry_run") {
    if (!canBulkUploadStaffDue()) {
      showFeedbackModal("error", "Access Denied", "You do not have permission to bulk upload staff-due records.");
      return;
    }

    const file = bulkUploadFile?.files?.[0];
    if (!file) {
      showFeedbackModal("error", "Validation Error", "Choose a CSV or XLSX file before continuing.", () => {
        bulkUploadFile?.focus();
      });
      return;
    }

    const formData = new FormData();
    formData.append("mode", mode);
    formData.append("import_file", file);

    setBulkUploadBusy(true, mode);
    renderBulkUploadLoading(mode);

    try {
      const response = await fetch("../backend/api/process_staff_due_import.php", {
        method: "POST",
        credentials: "include",
        body: formData
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error(data.message || "Unable to process the staff-due upload.");
      }

      renderBulkUploadResult(data.report, data.status, data.message);
      const reviewDownloadStarted = downloadImportReviewExport(data.review_export);
      showFeedbackModal(
        data.status === "failed" ? "error" : "success",
        mode === "import" ? "Bulk Upload Complete" : "Dry Check Complete",
        (data.message || (mode === "import" ? "Staff-due upload completed." : "Dry check completed."))
          + (reviewDownloadStarted ? " The review file download has started." : "")
      );
    } catch (error) {
      console.error("Staff due bulk upload failed:", error);
      renderBulkUploadResult({ summary: {}, rows: [] }, "failed", error.message || "Unable to process the staff-due upload.");
      showFeedbackModal("error", mode === "import" ? "Bulk Upload Failed" : "Dry Check Failed", error.message || "Unable to process the staff-due upload.");
    } finally {
      setBulkUploadBusy(false, mode);
    }
  }

  function downloadImportReviewExport(reviewExport) {
    if (!reviewExport || !reviewExport.content_base64) {
      return false;
    }

    try {
      const binary = window.atob(String(reviewExport.content_base64 || ""));
      const bytes = new Uint8Array(binary.length);
      for (let index = 0; index < binary.length; index += 1) {
        bytes[index] = binary.charCodeAt(index);
      }
      const blob = new Blob([bytes], {
        type: reviewExport.mime || "text/csv;charset=utf-8;"
      });
      const url = window.URL.createObjectURL(blob);
      const anchor = document.createElement("a");
      anchor.href = url;
      anchor.download = reviewExport.file_name || "staff_due_import_review.csv";
      document.body.appendChild(anchor);
      anchor.click();
      anchor.remove();
      window.URL.revokeObjectURL(url);
      return true;
    } catch (error) {
      console.error("Unable to download review export:", error);
      return false;
    }
  }

  async function loadPrisonUnits() {
    try {
      const res = await fetch("../backend/api/fetch_priunits.php", { credentials: "include" });
      const data = await res.json();
      if (!data.success || !Array.isArray(data.units)) return;

      prisonUnits = data.units;
      if (prisonUnitSelect) {
        prisonUnitSelect.innerHTML = "<option value=\"\">Select Prison Unit</option>";
        prisonUnits.forEach((unit) => {
          const opt = document.createElement("option");
          opt.value = unit;
          opt.textContent = unit;
          prisonUnitSelect.appendChild(opt);
        });
        syncFilterableSelect(prisonUnitSelect);
      }
      renderPrisonList(prisonUnits);
    } catch (err) {
      console.error("Error fetching prison units:", err);
    }
  }

  bindFieldTracking();
  bindTabs();
  syncAllFilterableSelects();

  if (prisonTrigger) {
    prisonTrigger.addEventListener("click", () => {
      if (prisonSearch) prisonSearch.value = "";
      renderPrisonList(prisonUnits);
      if (prisonModal) {
        prisonModal.style.display = "flex";
        prisonModal.scrollTop = 0;
      }
    });
  }

  if (prisonSearch) {
    prisonSearch.addEventListener("input", () => {
      const q = prisonSearch.value.toLowerCase();
      const filtered = prisonUnits.filter((unit) => unit.toLowerCase().includes(q));
      renderPrisonList(filtered);
    });
  }

  if (closePrisonModalBtn) {
    closePrisonModalBtn.addEventListener("click", () => {
      if (prisonModal) prisonModal.style.display = "none";
    });
  }

  if (prisonModal) {
    prisonModal.addEventListener("click", (e) => {
      if (e.target === prisonModal) prisonModal.style.display = "none";
    });
  }

  backBtn?.addEventListener("click", () => {
    if (window.history.length > 1) {
      window.history.back();
      return;
    }
    window.location.href = "staff_due.html";
  });

  bulkUploadBtn?.addEventListener("click", openBulkUploadModal);
  bulkDownloadTemplateBtn?.addEventListener("click", downloadBulkUploadTemplate);
  bulkDryRunBtn?.addEventListener("click", () => submitBulkUpload("dry_run"));
  bulkUploadForm?.addEventListener("submit", (event) => {
    event.preventDefault();
    submitBulkUpload("import");
  });
  bulkUploadFile?.addEventListener("change", () => resetBulkUploadReport());
  bulkUploadModal?.querySelectorAll("[data-close-modal='staff-due-bulk']").forEach((element) => {
    element.addEventListener("click", closeBulkUploadModal);
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && bulkUploadModal && !bulkUploadModal.classList.contains("hidden")) {
      closeBulkUploadModal();
    }
  });

  async function loadTitles() {
    if (!titleSelect) return;

    try {
      const res = await fetch("../backend/api/get_titles.php", { credentials: "include" });
      const data = await res.json();
      if (!data.success || !Array.isArray(data.titles)) return;

      titleSelect.innerHTML = '<option value="">Select Title</option>';
      const groups = {
        "Uniformed - Junior": [],
        "Uniformed - Senior": [],
        "Non-uniformed - Junior": [],
        "Non-uniformed - Senior": [],
      };

      data.titles
        .filter((t) => t.is_active)
        .forEach((t) => {
          const key =
            t.category === "uniformed"
              ? t.level === "senior"
                ? "Uniformed - Senior"
                : "Uniformed - Junior"
              : t.level === "senior"
                ? "Non-uniformed - Senior"
                : "Non-uniformed - Junior";
          groups[key].push(t.title_name);
        });

      Object.entries(groups).forEach(([label, titles]) => {
        if (!titles.length) return;
        const group = document.createElement("optgroup");
        group.label = label;

        titles.forEach((name) => {
          const opt = document.createElement("option");
          opt.value = name;
          opt.textContent = name;
          group.appendChild(opt);
        });

        titleSelect.appendChild(group);
      });
      syncFilterableSelect(titleSelect);
    } catch (err) {
      console.error("Error loading titles:", err);
    }
  }

  form.addEventListener("reset", () => {
    window.setTimeout(() => {
      touchedFields.clear();
      submitAttempted = false;
      setFormMessage("");
      if (prisonTrigger) {
        prisonTrigger.textContent = "Tap to select Prison Unit";
        delete prisonTrigger.dataset.value;
      }
      if (submissionStatus) submissionStatus.value = "pending";
      if (appnStatus) appnStatus.value = "pending";
      updateComputedFields();
      syncAllFilterableSelects();
      updateTabStates();
      setActiveTab("identity");
    }, 0);
  });

  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (!canEditStaffDue()) {
      showFeedbackModal("error", "Access Denied", "You do not have permission to add staff due records directly.");
      return;
    }
    submitAttempted = true;
    updateComputedFields();
    updateTabStates();

    const validationError = validateForm();
    if (validationError) {
      showValidationError(validationError);
      return;
    }

    const data = Object.fromEntries(new FormData(form).entries());
    data.retirementType = getRetirementTypesApi().normalizeValue(data.retirementType);
    data.prisonUnit = getSelectedPrisonUnit();
    data.submissionStatus = "pending";
    data.appnStatus = "pending";
    data.NIN = normalizeNationalIdValue(data.NIN || "");
    data.monthlySalary = parseMoneyInputValue(data.monthlySalary, 0);
    data.annualSalary = parseMoneyInputValue(data.annualSalary, 0);
    data.reducedPension = parseMoneyInputValue(data.reducedPension, 0);
    data.fullPension = parseMoneyInputValue(data.fullPension, 0);
    data.gratuity = parseMoneyInputValue(data.gratuity, 0);

    const rawTel = String(data.telNo || "").trim();
    if (rawTel) {
      const normalizedTel = normalizePhone(rawTel);
      if (!normalizedTel) {
        showValidationError({
          tab: "identity",
          field: telNo,
          message: "Identity Profile has an invalid phone number. Use +256700123456 or a valid Uganda local number."
        });
        return;
      }
      data.telNo = normalizedTel;
      telNo.value = normalizedTel;
    }

    try {
      setFormMessage("Saving staff record...", "");
      const res = await fetch("../backend/api/add_staff.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "include",
        body: JSON.stringify(data),
      });

      const result = await res.json();
      if (!res.ok || !result.success) {
        throw new Error(result.message || "Failed to add staff record.");
      }

      setFormMessage(result.message || "Staff added successfully.", "success");
      showFeedbackModal("success", "Saved", result.message || "Staff added successfully.", () => {
        form.reset();
      });
    } catch (err) {
      console.error("Error submitting form:", err);
      setFormMessage(err.message || "Failed to add staff record.", "error");
      showFeedbackModal("error", "Save Failed", err.message || "A network or server error occurred while adding staff.");
    }
  });

  const sessionOk = await checkSession();
  if (!sessionOk) return;

  await loadPrisonUnits();
  await loadTitles();
  if (nin) {
    const syncNin = () => {
      const normalized = normalizeNationalIdValue(nin.value);
      if (nin.value !== normalized) {
        nin.value = normalized;
      }
    };
    nin.addEventListener("input", syncNin);
    nin.addEventListener("change", syncNin);
    nin.addEventListener("blur", syncNin);
  }
  updateComputedFields();
  updateTabStates();
  setActiveTab("identity");
});
