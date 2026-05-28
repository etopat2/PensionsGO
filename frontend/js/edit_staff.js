document.addEventListener("DOMContentLoaded", async () => {
  const form = document.getElementById("editStaffForm");
  const backToStaffBtn = document.getElementById("backToStaffBtn");
  const urlParams = new URLSearchParams(window.location.search);
  const staffId = urlParams.get("id");
  const returnFrom = (urlParams.get("from") || "").toLowerCase();
  const returnTaskId = Number.parseInt(urlParams.get("taskId") || "", 10);
  const hasTaskReturnContext = returnFrom === "tasks" && Number.isFinite(returnTaskId) && returnTaskId > 0;

  const regNo = document.getElementById("regNo");
  const computerNo = document.getElementById("computerNo");
  const title = document.getElementById("title");
  const sName = document.getElementById("sName");
  const fName = document.getElementById("fName");
  const gender = document.getElementById("gender");
  const prisonUnit = document.getElementById("prisonUnit");
  const nin = document.getElementById("NIN");
  const telNo = document.getElementById("telNo");
  const birthDate = document.getElementById("birthDate");
  const enlistmentDate = document.getElementById("enlistmentDate");
  const retirementDate = document.getElementById("retirementDate");
  const financialYear = document.getElementById("financialYear");
  const retirementType = document.getElementById("retirementType");
  const monthlySalary = document.getElementById("monthlySalary");
  const lengthOfService = document.getElementById("lengthOfService");
  const annualSalary = document.getElementById("annualSalary");
  const reducedPension = document.getElementById("reducedPension");
  const fullPension = document.getElementById("fullPension");
  const gratuity = document.getElementById("gratuity");
  const submissionStatus = document.getElementById("submissionStatus");
  const appnStatus = document.getElementById("appnStatus");
  const formMessage = document.getElementById("formMessage");
  const address = document.getElementById("address");
  const tin = document.getElementById("TIN");
  const applicantEmail = document.getElementById("applicant_email");
  const nextOfKin = document.getElementById("next_of_kin");
  const nextOfKinContact = document.getElementById("next_of_kin_contact");
  const bankName = document.getElementById("bank_name");
  const bankAccount = document.getElementById("bank_account");
  const bankBranch = document.getElementById("bank_branch");
  const retirementPolicyHint = document.getElementById("editRetirementPolicyHint");
  const docType = document.getElementById("docType");
  const docFile = document.getElementById("docFile");
  const uploadDocBtn = document.getElementById("uploadDocBtn");
  const docList = document.getElementById("docList");
  const tabButtons = Array.from(document.querySelectorAll("#editStaffTabs .workspace-tab"));
  const tabPanels = Array.from(document.querySelectorAll(".workspace-panel"));
  const prevBtn = document.getElementById("editStaffPrevBtn");
  const nextBtn = document.getElementById("editStaffNextBtn");
  const tabOrder = ["bio", "benefits", "contact", "documents", "workflow"];
  let currentUserRole = "";
  let currentPermissions = {};
  const defaultStaffDueEditRoles = new Set(["admin", "clerk", "data_entry", "writeup_officer"]);
  let submitAttempted = false;
  const touchedFields = new Set();
  const computedFields = [financialYear, lengthOfService, annualSalary, reducedPension, fullPension, gratuity];

  function getPermissionValue(key, fallback = false) {
    if (Object.prototype.hasOwnProperty.call(currentPermissions, key)) {
      return Boolean(currentPermissions[key]);
    }
    return Boolean(fallback);
  }

  function canEditStaffDueRecord() {
    return getPermissionValue("staff_due.edit", defaultStaffDueEditRoles.has(currentUserRole));
  }

  function syncFilterableSelect(selectEl) {
    if (!selectEl || !window.PensionsGoFilterableSelect?.syncElement) return;
    window.PensionsGoFilterableSelect.syncElement(selectEl);
  }

  function syncAllFilterableSelects() {
    [title, gender, prisonUnit, retirementType, docType, submissionStatus, appnStatus].forEach(syncFilterableSelect);
  }

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

  function setDistrictValue(value) {
    if (!address) return;
    if (window.PensionsGoDistrictSelector?.setValue) {
      window.PensionsGoDistrictSelector.setValue(address, value || "");
      return;
    }
    address.value = value || "";
  }

  async function initDistrictField() {
    if (!address || !window.PensionsGoDistrictSelector?.enhanceElement) {
      return;
    }
    await window.PensionsGoDistrictSelector.enhanceElement(address, {
      placeholder: "Type to search district"
    });
    window.PensionsGoDistrictSelector.syncElement(address);
  }

  function snapshotEditFormState() {
    if (!form) return {};
    const snapshot = {};
    new FormData(form).forEach((value, key) => {
      if (typeof value === "string") {
        snapshot[key] = value;
      }
    });
    return snapshot;
  }

  function applyEditFormState(snapshot) {
    if (!snapshot || typeof snapshot !== "object") return;
    Object.entries(snapshot).forEach(([key, value]) => {
      const field = form?.elements?.namedItem(key);
      if (!field) return;
      if (field instanceof RadioNodeList) {
        Array.from(field).forEach((node) => {
          if (node && "value" in node) {
            node.checked = String(node.value) === String(value);
          }
        });
        return;
      }
      if ("value" in field) {
        field.value = value;
      }
    });
    if (Object.prototype.hasOwnProperty.call(snapshot, "address")) {
      setDistrictValue(snapshot.address || "");
    }
    syncAllFilterableSelects();
    recomputeServiceBenefits();
    updateDeathRetirementContactRequirements();
    updateTabStates();
  }

  const viewerReturnState = (() => {
    const params = new URLSearchParams(window.location.search || "");
    const returnKey = String(params.get("viewer_return") || "").trim();
    if (!returnKey || !window.PensionsGoDocumentViewer?.consumeReturnState) {
      return null;
    }
    const restoreState = window.PensionsGoDocumentViewer.consumeReturnState(returnKey);
    params.delete("viewer_return");
    const nextQuery = params.toString();
    const cleanUrl = `${window.location.pathname.split("/").pop()}${nextQuery ? `?${nextQuery}` : ""}${window.location.hash || ""}`;
    window.history.replaceState({}, "", cleanUrl);
    return restoreState && restoreState.page === "edit_staff" ? restoreState : null;
  })();

  computedFields.forEach((field) => {
    if (!field) return;
    field.readOnly = true;
    field.setAttribute("aria-readonly", "true");
  });

  function normalizePhone(value) {
    const input = String(value || "").trim().replace(/[\s().-]/g, "");
    if (!input) return null;
    if (/^00[1-9]\d{7,14}$/.test(input)) return `+${input.slice(2)}`;
    if (/^\+[1-9]\d{7,14}$/.test(input)) return input;
    if (/^0\d{9}$/.test(input)) return `+256${input.slice(1)}`;
    if (/^[1-9]\d{7,14}$/.test(input)) return `+${input}`;
    return null;
  }

  function isDeathRetirementSelection() {
    return getRetirementTypesApi().normalizeValue(retirementType?.value || "") === "death";
  }

  function updateDeathRetirementContactRequirements() {
    const requiresNextOfKin = isDeathRetirementSelection();
    const nextOfKinLabel = nextOfKin?.closest("label")?.querySelector(".field-label");
    const nextOfKinContactLabel = nextOfKinContact?.closest("label")?.querySelector(".field-label");

    if (nextOfKinLabel) {
      nextOfKinLabel.textContent = requiresNextOfKin ? "Next of Kin (Required for Death)" : "Next of Kin";
    }
    if (nextOfKinContactLabel) {
      nextOfKinContactLabel.textContent = requiresNextOfKin ? "Next of Kin Contact (Required for Death)" : "Next of Kin Contact";
    }
    if (nextOfKin) {
      nextOfKin.required = requiresNextOfKin;
    }
    if (nextOfKinContact) {
      nextOfKinContact.required = requiresNextOfKin;
    }
  }

  function setFieldInvalid(field, invalid) {
    if (!field) return;
    field.classList.toggle("workspace-field-invalid", Boolean(invalid));
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
    bio: [
      { field: regNo, message: "Bio Data is missing the file number.", isInvalid: () => !String(regNo?.value || "").trim() },
      { field: title, message: "Bio Data is missing the title or rank.", isInvalid: () => !String(title?.value || "").trim() },
      { field: sName, message: "Bio Data is missing the surname.", isInvalid: () => !String(sName?.value || "").trim() },
      { field: fName, message: "Bio Data is missing the first name.", isInvalid: () => !String(fName?.value || "").trim() },
      { field: gender, message: "Bio Data is missing gender.", isInvalid: () => !String(gender?.value || "").trim() },
      {
        field: nin,
        message: () => validateNationalIdValue(nin?.value || "", {
          birthDate: birthDate?.value || "",
          gender: gender?.value || ""
        }).message || "Bio Data has an invalid NIN.",
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
        message: "Bio Data has an invalid phone number. Use +256700123456 or a valid Uganda local number.",
        isInvalid: () => !String(telNo?.value || "").trim() || !normalizePhone(telNo?.value || "")
      },
      { field: retirementType, message: "Bio Data is missing the mode of retirement.", isInvalid: () => !String(retirementType?.value || "").trim() },
      {
        field: retirementType,
        message: () => getRetirementPolicyAssessment().primaryMessage || "The retirement profile does not satisfy the configured policy checks.",
        isInvalid: () => Boolean(getRetirementPolicyAssessment().errors.length)
      }
    ],
    benefits: [],
    contact: [
      {
        field: applicantEmail,
        message: "Contact & Bank has an invalid applicant email address.",
        isInvalid: () => {
          const value = String(applicantEmail?.value || "").trim();
          return value !== "" && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
        }
      },
      {
        field: nextOfKin,
        message: "Contact & Bank is missing the next of kin name required for Death retirements.",
        isInvalid: () => isDeathRetirementSelection() && !String(nextOfKin?.value || "").trim()
      },
      {
        field: nextOfKinContact,
        message: "Contact & Bank is missing the next of kin contact required for Death retirements.",
        isInvalid: () => isDeathRetirementSelection() && !String(nextOfKinContact?.value || "").trim()
      },
      {
        field: nextOfKinContact,
        message: "Contact & Bank has an invalid next of kin phone number.",
        isInvalid: () => {
          const value = String(nextOfKinContact?.value || "").trim();
          return value !== "" && !normalizePhone(value);
        }
      }
    ],
    documents: [],
    workflow: []
  };

  const tabFieldGroups = {
    bio: [regNo, computerNo, title, sName, fName, gender, prisonUnit, nin, telNo, birthDate, enlistmentDate, retirementDate, financialYear, retirementType],
    benefits: [monthlySalary, lengthOfService, annualSalary, reducedPension, fullPension, gratuity],
    contact: [address, tin, applicantEmail, nextOfKin, nextOfKinContact, bankName, bankAccount, bankBranch],
    documents: [docType, docFile],
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
      window.setTimeout(() => focusField.focus(), 30);
    }
  }

  function getActiveTab() {
    return tabButtons.find((button) => button.classList.contains("is-active"))?.dataset.tabTarget || tabOrder[0];
  }

  function updateNavigationButtons() {
    const index = tabOrder.indexOf(getActiveTab());
    if (prevBtn) prevBtn.disabled = index <= 0;
    if (nextBtn) {
      nextBtn.disabled = index >= tabOrder.length - 1;
      nextBtn.textContent = index >= tabOrder.length - 1 ? "Review Tabs" : "Next";
    }
  }

  function findFirstTabError(tabKey) {
    return (validationRules[tabKey] || []).find((rule) => rule.isInvalid()) || null;
  }

  function getTabState(tabKey) {
    const fields = (tabFieldGroups[tabKey] || []).filter(Boolean);
    const touched = fields.some((field) => touchedFields.has(field.id));
    const firstError = findFirstTabError(tabKey);
    const hasValue = fields.some((field) => String(field.value || "").trim() !== "");
    const ruleCount = (validationRules[tabKey] || []).length;

    if (firstError) {
      return submitAttempted || touched ? "invalid" : "neutral";
    }
    if (tabKey === "workflow" || tabKey === "documents") {
      return "valid";
    }
    if (ruleCount === 0) {
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

  function validateEditForm() {
    for (const tabKey of tabOrder) {
      const rule = findFirstTabError(tabKey);
      if (rule) {
        return { tab: tabKey, field: rule.field, message: resolveRuleMessage(rule) };
      }
    }
    return null;
  }

  if (!staffId) {
    if (formMessage) setFormMessage("Invalid staff record.", "error");
    return;
  }

  function getTaskReturnUrl() {
    if (!hasTaskReturnContext) return "tasks.html";
    const params = new URLSearchParams();
    params.set("taskId", String(returnTaskId));
    params.set("from", "edit_staff");
    return `tasks.html?${params.toString()}`;
  }

  function getReturnUrl() {
    if (hasTaskReturnContext) return getTaskReturnUrl();
    if (returnFrom === "staff_due") return "staff_due.html";
    if (returnFrom === "view_staff") return `view_staff.html?id=${encodeURIComponent(staffId)}`;
    if (returnFrom === "dashboard") return "dashboard.html#staffDueSection";
    return "";
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
      await loadCurrentPermissions();
      if (!canEditStaffDueRecord()) {
        showFeedbackModal("error", "Access Denied", "You do not have permission to edit staff due records.", () => {
          const returnUrl = getReturnUrl();
          window.location.href = returnUrl || "staff_due.html";
        });
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

  function canEditFileNumber() {
    const role = String(currentUserRole || "").trim().toLowerCase();
    return role === "admin" || ["oc_pen", "dep_oc", "deputy_oc", "deputy_oc_pen", "deputy_oc_pension"].includes(role);
  }

  const sessionData = await checkSession();
  if (!sessionData) return;

  if (backToStaffBtn) {
    backToStaffBtn.addEventListener("click", () => {
      const returnUrl = getReturnUrl();
      if (returnUrl) {
        window.location.href = returnUrl;
        return;
      }
      window.history.back();
    });
  }

  async function loadPrisonUnits() {
    try {
      const res = await fetch("../backend/api/fetch_priunits.php", {
        credentials: "include",
        cache: "no-store"
      });
      const data = await res.json();
      prisonUnit.innerHTML = '<option value="">Select Prison Unit</option>';
      data.units.forEach((unit) => {
        const opt = document.createElement("option");
        opt.value = unit;
        opt.textContent = unit;
        prisonUnit.appendChild(opt);
      });
      syncFilterableSelect(prisonUnit);
    } catch (err) {
      console.error("Error loading prison units:", err);
    }
  }

  async function loadTitles() {
    try {
      const res = await fetch("../backend/api/get_titles.php", { credentials: "include" });
      const data = await res.json();
      if (!data.success || !Array.isArray(data.titles)) return;

      title.innerHTML = '<option value="">Select Title</option>';
      const groups = {
        "Uniformed - Junior": [],
        "Uniformed - Senior": [],
        "Non-uniformed - Junior": [],
        "Non-uniformed - Senior": []
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
        title.appendChild(group);
      });
      syncFilterableSelect(title);
    } catch (err) {
      console.error("Error loading titles:", err);
    }
  }

  async function loadDocumentTypeOptions() {
    if (!docType) return;
    try {
      const response = await fetch("../backend/api/get_document_type_options.php", {
        credentials: "include",
        cache: "no-store"
      });
      const data = await response.json();
      const options = response.ok && data.success && Array.isArray(data.options) ? data.options : [];
      docType.innerHTML = '<option value="">Select Document Type</option>';
      options.forEach((value) => {
        const option = document.createElement("option");
        option.value = value;
        option.textContent = value;
        docType.appendChild(option);
      });
      syncFilterableSelect(docType);
    } catch (error) {
      console.error("Error loading document type options:", error);
    }
  }

  async function loadStaffDetails() {
    try {
      const res = await fetch(`../backend/api/get_staff.php?id=${encodeURIComponent(staffId)}`, {
        credentials: "include",
        cache: "no-store"
      });
      const data = await res.json();
      if (!data.success) {
        if (formMessage) setFormMessage(data.message || "Unable to load record.", "error");
        return;
      }

      const staff = data.record;
      regNo.value = staff.regNo || "";
      computerNo.value = staff.computerNo || staff.supplierNo || "";
      title.value = staff.title || "";
      sName.value = staff.sName || "";
      fName.value = staff.fName || "";
      gender.value = staff.gender || "";
      prisonUnit.value = staff.prisonUnit || "";
      nin.value = normalizeNationalIdValue(staff.NIN || "");
      telNo.value = staff.telNo || "";
      birthDate.value = staff.birthDate || "";
      enlistmentDate.value = staff.enlistmentDate || "";
      retirementDate.value = staff.retirementDate || "";
      financialYear.value = staff.financialYear || "";
      retirementType.value = getRetirementTypesApi().normalizeValue(staff.retirementType || "");
      setMoneyInputValue(monthlySalary, staff.monthlySalary || "");
      lengthOfService.value = staff.lengthOfService || "";
      setMoneyInputValue(annualSalary, staff.annualSalary || "");
      setMoneyInputValue(reducedPension, staff.reducedPension || "");
      setMoneyInputValue(fullPension, staff.fullPension || "");
      setMoneyInputValue(gratuity, staff.gratuity || "");
      setDistrictValue(staff.address || "");
      if (tin) tin.value = staff.TIN || "";
      if (applicantEmail) applicantEmail.value = staff.applicant_email || "";
      if (nextOfKin) nextOfKin.value = staff.next_of_kin || "";
      if (nextOfKinContact) nextOfKinContact.value = staff.next_of_kin_contact || "";
      if (bankName) bankName.value = staff.bank_name || "";
      if (bankAccount) bankAccount.value = staff.bank_account || "";
      if (bankBranch) bankBranch.value = staff.bank_branch || "";

      recomputeServiceBenefits();
      updateDeathRetirementContactRequirements();

      if (submissionStatus) {
        submissionStatus.value = staff.submissionStatus || "pending";
        submissionStatus.setAttribute("disabled", "disabled");
      }
      if (appnStatus) {
        appnStatus.value = staff.appnStatus || "pending";
        appnStatus.setAttribute("disabled", "disabled");
      }

      // Admin/OC/Deputy can edit file number; others remain read-only.
      if (regNo) {
        const canEdit = canEditFileNumber();
        regNo.readOnly = !canEdit;
        regNo.setAttribute("aria-readonly", canEdit ? "false" : "true");
      }
      syncAllFilterableSelects();
      updateTabStates();
    } catch (err) {
      console.error("Error loading staff details:", err);
      if (formMessage) setFormMessage("Failed to load staff details.", "error");
    }
  }

  await loadPrisonUnits();
  await loadTitles();
  await loadDocumentTypeOptions();
  await initDistrictField();
  bindTabs();
  bindFieldTracking();
  syncAllFilterableSelects();
  setActiveTab("bio");
  await loadStaffDetails();
  await loadDocuments();
  applyEditFormState(viewerReturnState?.formState || null);

  [monthlySalary, birthDate, enlistmentDate, retirementDate, retirementType].forEach((field) => {
    if (!field) return;
    field.addEventListener("input", recomputeServiceBenefits);
    field.addEventListener("change", recomputeServiceBenefits);
  });

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

  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    submitAttempted = true;
    updateTabStates();

    const validationError = validateEditForm();
    if (validationError) {
      setFormMessage(validationError.message, "error");
      showFeedbackModal("error", "Validation Error", validationError.message, () => {
        setActiveTab(validationError.tab, { focusField: validationError.field });
      });
      return;
    }

    const normalizedTel = normalizePhone(telNo?.value || "");
    if (!normalizedTel) {
      setFormMessage("Please enter a valid phone number.", "error");
      showFeedbackModal("error", "Validation Error", "Use international or Uganda local format (e.g., +256700123456, 0770123456, 0312123456, 0800123456).", () => {
        setActiveTab("bio", { focusField: telNo });
      });
      return;
    }
    telNo.value = normalizedTel;

    const rawNextOfKin = String(nextOfKin?.value || "").trim();
    const rawNextOfKinContact = String(nextOfKinContact?.value || "").trim();
    const requiresNextOfKin = isDeathRetirementSelection();
    if (requiresNextOfKin && !rawNextOfKin) {
      setFormMessage("Next of kin name is required for Death retirements.", "error");
      showFeedbackModal("error", "Validation Error", "Next of kin name is required for Death retirements.", () => {
        setActiveTab("contact", { focusField: nextOfKin });
      });
      return;
    }
    if (requiresNextOfKin && !rawNextOfKinContact) {
      setFormMessage("Next of kin contact is required for Death retirements.", "error");
      showFeedbackModal("error", "Validation Error", "Next of kin contact is required for Death retirements.", () => {
        setActiveTab("contact", { focusField: nextOfKinContact });
      });
      return;
    }
    if (rawNextOfKinContact) {
      const normalizedNextOfKinContact = normalizePhone(rawNextOfKinContact);
      if (!normalizedNextOfKinContact) {
        setFormMessage("Next of kin contact must be a valid phone number.", "error");
        showFeedbackModal("error", "Validation Error", "Next of kin contact must be a valid phone number.", () => {
          setActiveTab("contact", { focusField: nextOfKinContact });
        });
        return;
      }
      nextOfKinContact.value = normalizedNextOfKinContact;
    }

    const formData = new FormData(form);
    formData.set("NIN", normalizeNationalIdValue(nin?.value || ""));
    formData.set("retirementType", getRetirementTypesApi().normalizeValue(retirementType?.value || ""));
    formData.set("monthlySalary", String(parseMoneyInputValue(monthlySalary?.value, 0)));
    formData.set("annualSalary", String(parseMoneyInputValue(annualSalary?.value, 0)));
    formData.set("reducedPension", String(parseMoneyInputValue(reducedPension?.value, 0)));
    formData.set("fullPension", String(parseMoneyInputValue(fullPension?.value, 0)));
    formData.set("gratuity", String(parseMoneyInputValue(gratuity?.value, 0)));
    formData.append("id", staffId);

    try {
      const res = await fetch("../backend/api/update_staff.php", {
        method: "POST",
        body: formData,
        credentials: "include"
      });
      const data = await res.json();
      if (formMessage) {
        setFormMessage(
          data.message || (data.success ? "Record updated." : "Update failed."),
          data.success ? "success" : "error"
        );
      }
      if (data.success) {
        const successMessage = hasTaskReturnContext
          ? `${data.message || "Staff record updated successfully."} Returning to task...`
          : (data.message || "Staff record updated successfully.");
        showFeedbackModal("success", "Update Complete", successMessage);
        localStorage.setItem("staffDueUpdated", Date.now().toString());
        if (hasTaskReturnContext) {
          window.setTimeout(() => {
            window.location.href = getTaskReturnUrl();
          }, 1200);
        }
      } else {
        showFeedbackModal("error", "Update Failed", data.message || "Could not update record.");
      }
    } catch (err) {
      console.error("Update failed:", err);
      if (formMessage) {
        setFormMessage("Unable to save changes.", "error");
      }
      showFeedbackModal("error", "Update Failed", "Unable to save changes.");
    }
  });

  function computeFinancialYear(dateValue) {
    if (!dateValue) return "";
    const date = new Date(dateValue);
    if (Number.isNaN(date.getTime())) return "";
    const year = date.getFullYear();
    const month = date.getMonth() + 1;
    const startYear = month <= 6 ? year - 1 : year;
    const endYear = month <= 6 ? year : year + 1;
    return `FY ${startYear}/${endYear}`;
  }

  function computeServicePeriod(startDate, endDate) {
    if (!startDate || !endDate) return { months: 0, days: 0, roundedMonths: 0 };
    const start = new Date(startDate);
    const end = new Date(endDate);
    if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime()) || end <= start) {
      return { months: 0, days: 0, roundedMonths: 0 };
    }

    let years = end.getFullYear() - start.getFullYear();
    let months = end.getMonth() - start.getMonth();
    let days = end.getDate() - start.getDate();

    if (days < 0) {
      const prevMonthEnd = new Date(end.getFullYear(), end.getMonth(), 0);
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

  function recomputeServiceBenefits() {
    if (!retirementDate || !enlistmentDate || !monthlySalary || !retirementType) {
      updateRetirementPolicyHint();
      updateDeathRetirementContactRequirements();
      return;
    }

    const retirementTypes = getRetirementTypesApi();
    const normalizedType = retirementTypes.normalizeValue(retirementType.value || "");
    if (normalizedType && retirementType.value !== normalizedType) {
      retirementType.value = normalizedType;
      syncFilterableSelect(retirementType);
    }

    const snapshot = retirementTypes.calculateBenefitSnapshot({
      retirementType: normalizedType,
      birthDate: birthDate?.value || "",
      enlistmentDate: enlistmentDate.value,
      retirementDate: retirementDate.value,
      monthlySalary: parseMoneyInputValue(monthlySalary.value, 0)
    });

    if (financialYear) {
      financialYear.value = computeFinancialYear(retirementDate.value);
    }
    if (lengthOfService) {
      lengthOfService.value = String(snapshot.lengthOfService || 0);
    }
    if (annualSalary) {
      setMoneyInputValue(annualSalary, Number(snapshot.annualSalary || 0).toFixed(2));
    }
    if (reducedPension) {
      setMoneyInputValue(reducedPension, Number(snapshot.reducedPension || 0).toFixed(2));
    }
    if (fullPension) {
      setMoneyInputValue(fullPension, Number(snapshot.fullPension || 0).toFixed(2));
    }
    if (gratuity) {
      setMoneyInputValue(gratuity, Number(snapshot.gratuity || 0).toFixed(2));
    }
    updateRetirementPolicyHint();
    updateDeathRetirementContactRequirements();
  }

  function bindFieldTracking() {
    Object.values(tabFieldGroups)
      .flat()
      .filter(Boolean)
      .forEach((field) => {
        const handler = () => {
          touchedFields.add(field.id);
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

  async function loadDocuments() {
    if (!docList) return;
    docList.className = "doc-list app-state-message app-state-neutral";
    docList.textContent = "Loading documents...";
    try {
      const res = await fetch(`../backend/api/get_staff_documents.php?staffId=${encodeURIComponent(staffId)}`, {
        credentials: "include",
        cache: "no-store"
      });
      const data = await res.json();
      if (!data.success || !data.documents.length) {
        docList.className = "doc-list app-state-message app-state-neutral";
        docList.textContent = "No documents uploaded.";
        return;
      }
      docList.className = "doc-list";
      docList.innerHTML = data.documents.map((doc) => {
        const fileName = doc.file_name || "Document";
        const sourceUrl = `../backend/api/view_staff_document.php?document_id=${encodeURIComponent(doc.document_id)}`;
        const viewerUrl = window.PensionsGoDocumentViewer?.buildViewerUrl
          ? (window.PensionsGoDocumentViewer.buildViewerUrl(sourceUrl, {
            label: fileName,
            backUrl: window.location.href,
            returnState: {
              page: "edit_staff",
              staffId,
              formState: snapshotEditFormState()
            }
          }) || sourceUrl)
          : sourceUrl;
        return `
          <div class="doc-item">
            <div class="doc-meta">
              <strong>${escapeHtml(doc.doc_type || "Document")}</strong>
              <small>${escapeHtml(doc.file_name || "")} - ${escapeHtml(doc.uploaded_at || "")}</small>
            </div>
            <div class="doc-actions">
              <a href="${viewerUrl}">View</a>
              <button type="button" data-doc-id="${doc.document_id}">Delete</button>
            </div>
          </div>
        `;
      }).join("");

        docList.querySelectorAll("button[data-doc-id]").forEach((btn) => {
          btn.addEventListener("click", async () => {
            const docId = btn.dataset.docId;
            if (!docId) return;
          const confirmed = await appConfirm("Delete this document?", {
            title: "Delete Document",
            confirmText: "Delete"
          });
          if (!confirmed) return;
          await deleteDocument(docId);
          await loadDocuments();
        });
      });
    } catch (err) {
      console.error("Failed to load documents:", err);
      docList.className = "doc-list app-state-message app-state-error";
      docList.textContent = "Unable to load documents.";
    }
  }

  async function deleteDocument(documentId) {
    try {
      const res = await fetch("../backend/api/delete_staff_document.php", {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ document_id: Number(documentId) })
      });
      const data = await res.json();
      if (!data.success) {
        showFeedbackModal("error", "Delete Failed", data.message || "Unable to delete document.");
        return;
      }
      showFeedbackModal("success", "Document Deleted", "The document was removed successfully.");
    } catch (err) {
      console.error("Failed to delete document:", err);
      showFeedbackModal("error", "Delete Failed", "Unable to delete document.");
    }
  }

  if (uploadDocBtn) {
    uploadDocBtn.addEventListener("click", async () => {
      if (!docType || !docFile) return;
      if (!docType.value) {
        showFeedbackModal("error", "Upload Error", "Select a document type.", () => {
          setActiveTab("documents", { focusField: docType });
        });
        return;
      }
      if (!docFile.files || !docFile.files.length) {
        showFeedbackModal("error", "Upload Error", "Choose a file to upload.", () => {
          setActiveTab("documents", { focusField: docFile });
        });
        return;
      }

      const formData = new FormData();
      formData.append("staffdue_id", staffId);
      formData.append("doc_type", docType.value);
      formData.append("document", docFile.files[0]);

      try {
        const res = await fetch("../backend/api/upload_document.php", {
          method: "POST",
          credentials: "include",
          body: formData
        });
        const data = await res.json();
        if (!data.success) {
          showFeedbackModal("error", "Upload Failed", data.message || "Unable to upload document.");
          return;
        }
        docType.value = "";
        docFile.value = "";
        touchedFields.add(docType.id);
        await loadDocuments();
        updateTabStates();
        showFeedbackModal("success", "Upload Complete", "Document uploaded successfully.");
      } catch (err) {
        console.error("Upload failed:", err);
        showFeedbackModal("error", "Upload Failed", "Upload failed. Please try again.");
      }
    });
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

  function setFormMessage(message, type = "") {
    if (!formMessage) return;
    formMessage.textContent = message;
    formMessage.className = "workspace-form-message form-message";
    if (type) {
      formMessage.classList.add(type);
    }
  }

  function showFeedbackModal(type, title, message, onClose = null) {
    const existing = document.getElementById("crudFeedbackModal");
    if (existing) {
      existing.remove();
    }

    const icon = type === "success" ? "Success" : "Error";
    const modal = document.createElement("div");
    modal.id = "crudFeedbackModal";
    modal.className = "auth-modal-overlay";
    modal.innerHTML = `
      <div class="auth-modal">
        <div class="auth-modal-header">
          <h3>${icon}: ${escapeHtml(title)}</h3>
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

    const close = () => {
      modal.remove();
      if (typeof onClose === "function") {
        onClose();
      }
    };

    const okBtn = document.getElementById("crudFeedbackOkBtn");
    if (okBtn) {
      okBtn.addEventListener("click", close, { once: true });
    }
    modal.addEventListener("click", (evt) => {
      if (evt.target === modal) {
        close();
      }
    });
  }
});
