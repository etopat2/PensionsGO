async function initPensionFileRegistryController() {
  const buildViewerUrl = (sourceUrl, label, returnState = null) => {
    if (window.PensionsGoDocumentViewer?.buildViewerUrl) {
      const viewerUrl = window.PensionsGoDocumentViewer.buildViewerUrl(sourceUrl, {
        label,
        backUrl: window.location.href,
        returnState
      });
      if (viewerUrl) {
        return viewerUrl;
      }
    }
    return sourceUrl;
  };

  const grid = document.getElementById("registryGrid");
  const searchInput = document.getElementById("registrySearchInput");
  const boxNumberFilter = document.getElementById("registryBoxNumberFilter");
  const availabilityFilter = document.getElementById("registryAvailabilityFilter");
  const payTypeFilter = document.getElementById("registryPayTypeFilter");
  const sortSelect = document.getElementById("registrySortSelect");
  const pageSizeSelect = document.getElementById("registryPageSize");
  const refreshBtn = document.getElementById("registryRefreshBtn");
  const addFileBtn = document.getElementById("registryAddFileBtn");
  const registryPagination = document.getElementById("registryPagination");
  const registryPaginationSummary = document.getElementById("registryPaginationSummary");
  const registryPaginationControls = document.getElementById("registryPaginationControls");

  const detailsModal = document.getElementById("registryDetailsModal");
  const detailsBody = document.getElementById("registryDetailsBody");
  const detailsLifeCertBtn = document.getElementById("registryDetailsLifeCertBtn");
  const detailsEditBtn = document.getElementById("registryDetailsEditBtn");
  const detailsDeleteBtn = document.getElementById("registryDetailsDeleteBtn");
  const editModal = document.getElementById("registryEditModal");
  const editForm = document.getElementById("registryEditForm");
  const editTitleHeading = document.getElementById("registryEditTitle");
  const editModeHint = document.getElementById("registryEditModeHint");
  const editFormBanner = document.getElementById("registryEditFormBanner");
  const editFormBannerTitle = document.getElementById("registryEditFormBannerTitle");
  const editFormBannerText = document.getElementById("registryEditFormBannerText");
  const editSubmitBtn = document.getElementById("registryEditSubmitBtn");
  const openBulkUploadBtn = document.getElementById("registryOpenBulkUploadBtn");
  const deleteQueueBtn = document.getElementById("registryDeleteQueueBtn");
  const deleteQueueCountEl = document.getElementById("registryDeleteQueueCount");
  const deleteQueueModal = document.getElementById("registryDeleteQueueModal");
  const deleteQueueBody = document.getElementById("registryDeleteQueueBody");
  const deleteHistoryWrap = document.getElementById("registryDeleteHistoryWrap");
  const deleteHistoryBody = document.getElementById("registryDeleteHistoryBody");
  const toggleDeleteHistoryBtn = document.getElementById("toggleDeleteHistoryBtn");
  const lifeCertBtn = document.getElementById("registryLifeCertBtn");
  const lifeCertModal = document.getElementById("registryLifeCertModal");
  const lifeCertForm = document.getElementById("registryLifeCertForm");
  const lifeCertRegNo = document.getElementById("registryLifeCertRegNo");
  const lifeCertRegNoList = document.getElementById("registryLifeCertRegNoList");
  const lifeCertYear = document.getElementById("registryLifeCertYear");
  const lifeCertNotes = document.getElementById("registryLifeCertNotes");
  const lifeCertSubmitBtn = document.getElementById("registryLifeCertSubmitBtn");
  const lifeCertEditBtn = document.getElementById("registryLifeCertEditBtn");
  const lifeCertProfileHint = document.getElementById("registryLifeCertProfileHint");
  const lifeCertPensionerName = document.getElementById("registryLifeCertPensionerName");
  const lifeCertPhone = document.getElementById("registryLifeCertPhone");
  const lifeCertAddress = document.getElementById("registryLifeCertAddress");
  const lifeCertNok = document.getElementById("registryLifeCertNok");
  const lifeCertNokContact = document.getElementById("registryLifeCertNokContact");
  const lifeCertBankName = document.getElementById("registryLifeCertBankName");
  const lifeCertBankAccount = document.getElementById("registryLifeCertBankAccount");
  const lifeCertBankBranch = document.getElementById("registryLifeCertBankBranch");
  const bulkUploadModal = document.getElementById("registryBulkUploadModal");
  const bulkUploadForm = document.getElementById("registryBulkUploadForm");
  const bulkUploadFile = document.getElementById("registryBulkUploadFile");
  const bulkUploadReport = document.getElementById("registryBulkUploadReport");
  const bulkDownloadTemplateBtn = document.getElementById("registryBulkDownloadTemplateBtn");
  const bulkDryRunBtn = document.getElementById("registryBulkDryRunBtn");
  const bulkImportBtn = document.getElementById("registryBulkImportBtn");

  const editRecordId = document.getElementById("editRecordId");
  const editRegNo = document.getElementById("editRegNo");
  const editComputerNo = document.getElementById("editComputerNo");
  const editSupplierNo = document.getElementById("editSupplierNo");
  const editTitle = document.getElementById("editTitle");
  const editBoxNo = document.getElementById("editBoxNo");
  const editSName = document.getElementById("editSName");
  const editFName = document.getElementById("editFName");
  const editGender = document.getElementById("editGender");
  const editLivingStatus = document.getElementById("editLivingStatus");
  const editLifeCertificate = document.getElementById("editLifeCertificate");
  const editBirthDate = document.getElementById("editBirthDate");
  const editEnlistmentDate = document.getElementById("editEnlistmentDate");
  const editRetirementDate = document.getElementById("editRetirementDate");
  const editRetirementType = document.getElementById("editRetirementType");
  const editNIN = document.getElementById("editNIN");
  const editTIN = document.getElementById("editTIN");
  const editPayrollStatus = document.getElementById("editPayrollStatus");
  const editPayType = document.getElementById("editPayType");
  const registryRetirementPolicyHint = document.getElementById("registryRetirementPolicyHint");
  const editTelNo = document.getElementById("editTelNo");
  const editApplicantEmail = document.getElementById("editApplicantEmail");
  const editNextOfKin = document.getElementById("editNextOfKin");
  const editNextOfKinContact = document.getElementById("editNextOfKinContact");
  const editBankName = document.getElementById("editBankName");
  const editBankAccount = document.getElementById("editBankAccount");
  const editBankBranch = document.getElementById("editBankBranch");
  const editMonthlySalary = document.getElementById("editMonthlySalary");
  const editLengthOfService = document.getElementById("editLengthOfService");
  const editAnnualSalary = document.getElementById("editAnnualSalary");
  const editReducedPension = document.getElementById("editReducedPension");
  const editFullPension = document.getElementById("editFullPension");
  const editGratuity = document.getElementById("editGratuity");
  const editDateOn15yrs = document.getElementById("editDateOn15yrs");
  const editPeriodTo15yrs = document.getElementById("editPeriodTo15yrs");
  const editPeriodFrom15yrs = document.getElementById("editPeriodFrom15yrs");
  const editAvailabilityStatus = document.getElementById("editAvailabilityStatus");
  const editAvailabilityReason = document.getElementById("editAvailabilityReason");
  const editAddress = document.getElementById("editAddress");
  const editOther = document.getElementById("editOther");
  const editTitleOptions = document.getElementById("editTitleOptions");
  const editDocumentsList = document.getElementById("editDocumentsList");
  const registryDocumentTargetId = document.getElementById("registryDocumentTargetId");
  const registryDocumentType = document.getElementById("registryDocumentType");
  const registryDocumentFile = document.getElementById("registryDocumentFile");
  const registryDocumentModeBadge = document.getElementById("registryDocumentModeBadge");
  const registryDocumentHint = document.getElementById("registryDocumentHint");
  const registryDocumentResetBtn = document.getElementById("registryDocumentResetBtn");
  const registryDocumentSaveBtn = document.getElementById("registryDocumentSaveBtn");
  const editTabButtons = Array.from(document.querySelectorAll(".registry-edit-tab"));
  const editTabPanels = Array.from(document.querySelectorAll(".registry-edit-panel-group"));
  const createEditableFields = [
    editRegNo,
    editComputerNo,
    editSupplierNo,
    editTitle,
    editBoxNo,
    editSName,
    editFName,
    editGender,
    editLivingStatus,
    editLifeCertificate,
    editBirthDate,
    editEnlistmentDate,
    editRetirementDate,
    editRetirementType,
    editNIN,
    editTIN,
    editAddress,
    editTelNo,
    editApplicantEmail,
    editNextOfKin,
    editNextOfKinContact,
    editBankName,
    editBankAccount,
    editBankBranch,
    editMonthlySalary,
    editLengthOfService,
    editAnnualSalary,
    editReducedPension,
    editFullPension,
    editGratuity,
    editPayrollStatus,
    editPayType,
    editAvailabilityStatus,
    editAvailabilityReason,
    editOther
  ].filter(Boolean);

  let currentUserRole = "";
  let currentUserPermissions = {};
  let currentPage = 1;
  let totalPages = 1;
  let totalRecords = 0;
  let pageSize = Number(pageSizeSelect?.value || 24);
  let searchTimer = null;
  let lifeCertSearchTimer = null;
  let currentDetailsRecord = null;
  let currentEditRecordContext = null;
  let registryFormMode = "edit";
  let allowedTitles = [];
  let bankOptions = [];
  let documentTypeOptions = [];
  let registrySubmitAttempted = false;
  const registryTouchedFields = new Set();
  const REGISTRY_CACHE_KEY = "pensionsgoRegistrySessionCache:v1";
  const REGISTRY_CACHE_TTL_MS = 3 * 60 * 1000;
  let lifeCertProfileRecordId = 0;
  let lifeCertProfileEditable = false;
  let lifeCertProfileLoadedRegNo = "";
  let lifeCertProfileLoadingRegNo = "";
  let lifeCertProfileLoadPromise = null;
  let lifeCertProfileRequestSeq = 0;
  let deleteHistoryVisible = false;
  let cardsRequestSeq = 0;
  let activeCardsController = null;
  const REGISTRY_FILE_NUMBER_PREFIX = "PEN/";
  const REGISTRY_FILE_NUMBER_PATTERN = /^PEN\/(?:[1-9][0-9]{0,4}|[A-Z]\/[1-9][0-9]{0,3})$/;

  function normalizeRegistryFileNumber(value, { preservePrefix = false } = {}) {
    let normalized = String(value || "")
      .trim()
      .toUpperCase()
      .replace(/\s+/g, "");

    if (!normalized) {
      return preservePrefix ? REGISTRY_FILE_NUMBER_PREFIX : "";
    }

    if (normalized === "PEN") {
      return REGISTRY_FILE_NUMBER_PREFIX;
    }

    if (!normalized.startsWith(REGISTRY_FILE_NUMBER_PREFIX)) {
      normalized = `${REGISTRY_FILE_NUMBER_PREFIX}${normalized.replace(/^\/+/, "")}`;
    }

    normalized = `${REGISTRY_FILE_NUMBER_PREFIX}${normalized.slice(REGISTRY_FILE_NUMBER_PREFIX.length).replace(/[^A-Z0-9/]/g, "")}`;
    return normalized || (preservePrefix ? REGISTRY_FILE_NUMBER_PREFIX : "");
  }

  function validateRegistryFileNumber(value) {
    const normalized = normalizeRegistryFileNumber(value, { preservePrefix: true });
    if (normalized === REGISTRY_FILE_NUMBER_PREFIX) {
      return {
        valid: false,
        normalized,
        message: 'File number must continue after the "PEN/" prefix.'
      };
    }
    if (!REGISTRY_FILE_NUMBER_PATTERN.test(normalized)) {
      return {
        valid: false,
        normalized,
        message: 'File number must use "PEN/1" or "PEN/A/1" format. Use only one capital letter when present; numbers must start from 1, have no leading zeroes, and must not exceed 99999 without a letter or 9999 with a letter.'
      };
    }
    return { valid: true, normalized, message: "" };
  }

  function syncRegistryFileNumberInput({ preservePrefix = registryFormMode === "create" } = {}) {
    if (!editRegNo) return;
    const normalized = normalizeRegistryFileNumber(editRegNo.value, { preservePrefix });
    if (editRegNo.value !== normalized) {
      editRegNo.value = normalized;
    }
  }

  function queueCardsReload() {
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
      currentPage = 1;
      loadCards();
    }, 220);
  }

  function readRegistryCache() {
    try {
      return JSON.parse(sessionStorage.getItem(REGISTRY_CACHE_KEY) || "{}") || {};
    } catch (_error) {
      return {};
    }
  }

  function writeRegistryCache(cache) {
    try {
      sessionStorage.setItem(REGISTRY_CACHE_KEY, JSON.stringify(cache || {}));
    } catch (_error) {}
  }

  function getRegistryCachedPayload(key) {
    const entry = readRegistryCache()[key];
    if (!entry || !entry.payload || Date.now() - Number(entry.savedAt || 0) > REGISTRY_CACHE_TTL_MS) return null;
    return entry.payload;
  }

  function setRegistryCachedPayload(key, payload) {
    const cache = readRegistryCache();
    cache[key] = { savedAt: Date.now(), payload };
    const compact = Object.fromEntries(Object.entries(cache)
      .sort((a, b) => Number(b[1]?.savedAt || 0) - Number(a[1]?.savedAt || 0))
      .slice(0, 24));
    writeRegistryCache(compact);
  }

  function clearRegistryCache() {
    try {
      sessionStorage.removeItem(REGISTRY_CACHE_KEY);
    } catch (_error) {}
  }

  function syncFilterableSelect(selectEl) {
    if (!selectEl || !window.PensionsGoFilterableSelect?.syncElement) return;
    window.PensionsGoFilterableSelect.syncElement(selectEl);
  }

  function populateSelectOptions(selectEl, values = [], placeholder = "All") {
    if (!selectEl) return;
    const currentValue = String(selectEl.value || "").trim();
    const normalizedValues = Array.from(new Set(
      (Array.isArray(values) ? values : [])
        .map((value) => String(value || "").trim())
        .filter(Boolean)
    ));
    selectEl.innerHTML = [`<option value="">${escapeHtml(placeholder)}</option>`]
      .concat(normalizedValues.map((value) => `<option value="${escapeHtml(value)}">${escapeHtml(value)}</option>`))
      .join("");
    if (currentValue && normalizedValues.includes(currentValue)) {
      selectEl.value = currentValue;
    }
    syncFilterableSelect(selectEl);
  }

  function setDistrictValue(field, value) {
    if (!field) return;
    if (window.PensionsGoDistrictSelector?.setValue) {
      window.PensionsGoDistrictSelector.setValue(field, value || "");
      return;
    }
    field.value = value || "";
  }

  function syncDistrictState(field, readOnly = null) {
    if (!field || !window.PensionsGoDistrictSelector) return;
    if (readOnly !== null && window.PensionsGoDistrictSelector.setReadOnly) {
      window.PensionsGoDistrictSelector.setReadOnly(field, Boolean(readOnly));
      return;
    }
    if (window.PensionsGoDistrictSelector.syncElement) {
      window.PensionsGoDistrictSelector.syncElement(field);
    }
  }

  function setRegistryTitleValue(value) {
    if (!editTitle) return;
    editTitle.value = String(value || "").trim();
    syncDistrictState(editTitle);
  }

  function focusRegistryTitleField() {
    if (!editTitle) return;
    const visibleInput = editTitle.nextElementSibling instanceof HTMLElement
      && editTitle.nextElementSibling.classList.contains("district-select")
      ? editTitle.nextElementSibling.querySelector(".district-select-input")
      : null;
    if (visibleInput instanceof HTMLElement && typeof visibleInput.focus === "function") {
      visibleInput.focus();
      return;
    }
    editTitle.focus();
  }

  function getRetirementTypesApi() {
    return window.PensionsGoRetirementTypes || {
      normalizeValue: (value) => String(value || "").trim(),
      getLabel: (value) => String(value || "").trim(),
      validateRetirementProfile: () => ({
        valid: true,
        errors: [],
        warnings: [],
        primaryMessage: "",
        status: "neutral"
      }),
      normalizePayType: (value) => {
        const raw = String(value || "").trim().toLowerCase();
        if (!raw) return "Pensioner";
        const compact = raw.replace(/[^a-z0-9]/g, "");
        if (["oneoffpayment", "oneoff", "oneoffpayout", "oneoffpay", "gratuityonly"].includes(compact)) {
          return "One-off Payment";
        }
        return "Pensioner";
      },
      derivePayType: (payload = {}) => {
        const normalizedType = String(payload.retirementType || "").trim().toLowerCase();
        const rawFallback = String(payload.payType ?? "").trim();
        if (["mandatory", "voluntary", "oldage", "abolition"].includes(normalizedType.replace(/[^a-z0-9]/g, ""))) {
          return "Pensioner";
        }
        if (["marriage", "contract", "tx"].includes(normalizedType.replace(/[^a-z0-9]/g, ""))) {
          return "One-off Payment";
        }
        if (!rawFallback) return "";
        const compact = rawFallback.toLowerCase().replace(/[^a-z0-9]/g, "");
        if (["oneoffpayment", "oneoff", "oneoffpayout", "oneoffpay", "gratuityonly"].includes(compact)) {
          return "One-off Payment";
        }
        return "Pensioner";
      },
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

  function ensureBankOption(selectElement, value) {
    if (!selectElement) return;
    const normalized = String(value || "").trim();
    if (!normalized) return;
    const exists = Array.from(selectElement.options).some((option) => {
      return String(option.value || "").trim().toLowerCase() === normalized.toLowerCase();
    });
    if (exists) return;

    const option = document.createElement("option");
    option.value = normalized;
    option.textContent = `${normalized} (inactive)`;
    option.dataset.dynamicBank = "true";
    selectElement.appendChild(option);
  }

  function ensureRegistryBankOption(value) {
    ensureBankOption(editBankName, value);
  }

  function setRegistryBankValue(value) {
    if (!editBankName) return;
    Array.from(editBankName.querySelectorAll('option[data-dynamic-bank="true"]')).forEach((option) => option.remove());
    ensureRegistryBankOption(value);
    editBankName.value = String(value || "").trim();
    syncFilterableSelect(editBankName);
  }

  function populateBankSelect(selectElement, currentValue = "") {
    if (!selectElement) return;
    const selectedValue = String(currentValue || selectElement.value || "").trim();
    selectElement.innerHTML = '<option value="">Select bank</option>';
    bankOptions.forEach((bank) => {
      const bankName = String(bank.bank_name || "").trim();
      if (!bankName) return;
      const option = document.createElement("option");
      option.value = bankName;
      option.textContent = bankName;
      selectElement.appendChild(option);
    });
    ensureBankOption(selectElement, selectedValue);
    selectElement.value = selectedValue;
  }

  function setLifeCertBankValue(value) {
    if (!lifeCertBankName) return;
    Array.from(lifeCertBankName.querySelectorAll('option[data-dynamic-bank="true"]')).forEach((option) => option.remove());
    ensureBankOption(lifeCertBankName, value);
    lifeCertBankName.value = String(value || "").trim();
  }

  function setMoneyInputValue(field, value) {
    if (!field) return;
    if (window.PensionsGoMoney?.setInputValue) {
      window.PensionsGoMoney.setInputValue(field, value);
      return;
    }
    field.value = value ?? "";
  }

  function normalizeRetirementTypeValue(value) {
    return getRetirementTypesApi().normalizeValue(value);
  }

  function formatRetirementTypeLabel(value) {
    return getRetirementTypesApi().getLabel(value) || formatDisplay(value);
  }

  function getRegistryRetirementPolicyAssessment() {
    return getRetirementTypesApi().validateRetirementProfile({
      retirementType: editRetirementType?.value || "",
      birthDate: editBirthDate?.value || "",
      enlistmentDate: editEnlistmentDate?.value || "",
      retirementDate: editRetirementDate?.value || ""
    });
  }

  function updateRegistryRetirementPolicyHint() {
    if (!registryRetirementPolicyHint) return;
    const assessment = getRegistryRetirementPolicyAssessment();
    const selectedRetirementType = String(editRetirementType?.value || "").trim();
    const label = selectedRetirementType ? formatRetirementTypeLabel(selectedRetirementType) : "";
    const hasInputs = Boolean(
      selectedRetirementType
      || String(editBirthDate?.value || "").trim()
      || String(editEnlistmentDate?.value || "").trim()
      || String(editRetirementDate?.value || "").trim()
    );

    if (!hasInputs) {
      registryRetirementPolicyHint.hidden = true;
      registryRetirementPolicyHint.textContent = "";
      registryRetirementPolicyHint.dataset.state = "neutral";
      return;
    }

    if (!selectedRetirementType) {
      registryRetirementPolicyHint.hidden = false;
      registryRetirementPolicyHint.textContent = "Select a retirement type to validate the age and service policy checks for registry capture.";
      registryRetirementPolicyHint.dataset.state = "neutral";
      return;
    }

    if (assessment.primaryMessage) {
      registryRetirementPolicyHint.hidden = false;
      registryRetirementPolicyHint.textContent = assessment.primaryMessage;
      registryRetirementPolicyHint.dataset.state = assessment.status || "warning";
      return;
    }

    if (label && assessment.valid && String(editRetirementDate?.value || "").trim()) {
      registryRetirementPolicyHint.hidden = false;
      registryRetirementPolicyHint.textContent = `${label} passes the current age and service policy checks for registry capture.`;
      registryRetirementPolicyHint.dataset.state = "valid";
      return;
    }

    registryRetirementPolicyHint.hidden = true;
    registryRetirementPolicyHint.textContent = "";
    registryRetirementPolicyHint.dataset.state = "neutral";
  }

  function recomputeRegistryBenefitFields() {
    if (!editMonthlySalary || !editEnlistmentDate || !editRetirementDate || !editRetirementType) {
      updateRegistryRetirementPolicyHint();
      return;
    }

    const normalizedType = normalizeRetirementTypeValue(editRetirementType.value || "");
    if (normalizedType && editRetirementType.value !== normalizedType) {
      editRetirementType.value = normalizedType;
      syncFilterableSelect(editRetirementType);
    }

    const snapshot = getRetirementTypesApi().calculateBenefitSnapshot({
      retirementType: normalizedType,
      birthDate: editBirthDate?.value || "",
      enlistmentDate: editEnlistmentDate.value,
      retirementDate: editRetirementDate.value,
      monthlySalary: parseMoneyInputValue(editMonthlySalary.value, 0)
    });

    if (editLengthOfService) editLengthOfService.value = String(snapshot.lengthOfService || 0);
    if (editAnnualSalary) setMoneyInputValue(editAnnualSalary, Number(snapshot.annualSalary || 0).toFixed(2));
    if (editReducedPension) setMoneyInputValue(editReducedPension, Number(snapshot.reducedPension || 0).toFixed(2));
    if (editFullPension) setMoneyInputValue(editFullPension, Number(snapshot.fullPension || 0).toFixed(2));
    if (editGratuity) setMoneyInputValue(editGratuity, Number(snapshot.gratuity || 0).toFixed(2));
    if (editPayType) {
      editPayType.value = deriveRegistryPayTypeValue({
        retirementType: normalizedType,
        enlistmentDate: editEnlistmentDate.value,
        retirementDate: editRetirementDate.value,
        payType: editPayType.value
      });
    }
    updateRegistryRetirementPolicyHint();
    updateLifeCertificateEditability();
  }

  function ensureRetirementTypeOption(value) {
    if (!editRetirementType) return;
    const normalizedValue = normalizeRetirementTypeValue(value);
    if (!normalizedValue) return;

    const existingOption = Array.from(editRetirementType.options).find((option) => {
      return String(option.value || "").trim().toLowerCase() === normalizedValue.toLowerCase();
    });

    if (existingOption) {
      existingOption.value = normalizedValue;
      existingOption.textContent = formatRetirementTypeLabel(normalizedValue);
      return;
    }

    const fallbackOption = document.createElement("option");
    fallbackOption.value = normalizedValue;
    fallbackOption.textContent = formatRetirementTypeLabel(normalizedValue);
    fallbackOption.dataset.dynamicRetirementType = "true";
    editRetirementType.appendChild(fallbackOption);
  }

  async function initDistrictFields() {
    if (!window.PensionsGoDistrictSelector?.enhanceElement) {
      return;
    }
    if (editTitle) {
      await window.PensionsGoDistrictSelector.enhanceElement(editTitle, {
        placeholder: "Type to search title or rank",
        items: allowedTitles,
        noResultsText: "No matching titles found.",
        currentValueLabel: "Current title"
      });
      syncDistrictState(editTitle);
      const visibleInput = editTitle.nextElementSibling instanceof HTMLElement
        && editTitle.nextElementSibling.classList.contains("district-select")
        ? editTitle.nextElementSibling.querySelector(".district-select-input")
        : null;
      if (visibleInput instanceof HTMLElement && visibleInput.dataset.registryTitleValidationBound !== "1") {
        visibleInput.dataset.registryTitleValidationBound = "1";
        visibleInput.addEventListener("blur", () => {
          validateSelectedTitle(true);
        });
      }
    }
    if (editAddress) {
      await window.PensionsGoDistrictSelector.enhanceElement(editAddress, {
        placeholder: "Type to search district"
      });
      syncDistrictState(editAddress);
    }
    if (lifeCertAddress) {
      await window.PensionsGoDistrictSelector.enhanceElement(lifeCertAddress, {
        placeholder: "Type to search district"
      });
      syncDistrictState(lifeCertAddress, true);
    }
  }

  function getActiveDetailsTabKey() {
    const activeButton = detailsBody?.querySelector(".registry-details-tab.is-active");
    return String(activeButton?.getAttribute("data-details-tab") || "identity").trim() || "identity";
  }

  function getActiveEditTabKey() {
    const activeButton = editTabButtons.find((btn) => btn.classList.contains("is-active"));
    return String(activeButton?.getAttribute("data-edit-tab") || "identity").trim() || "identity";
  }

  async function restoreViewerReturnContextIfPresent() {
    const params = new URLSearchParams(window.location.search || "");
    const returnKey = String(params.get("viewer_return") || "").trim();
    if (!returnKey || !window.PensionsGoDocumentViewer?.consumeReturnState) {
      return;
    }

    const restoreState = window.PensionsGoDocumentViewer.consumeReturnState(returnKey);
    params.delete("viewer_return");
    const nextQuery = params.toString();
    const cleanUrl = `${window.location.pathname.split("/").pop()}${nextQuery ? `?${nextQuery}` : ""}${window.location.hash || ""}`;
    window.history.replaceState({}, "", cleanUrl);

    if (!restoreState || restoreState.page !== "pension_file_registry") {
      return;
    }

    const recordId = Number(restoreState.recordId || 0);
    if (!recordId) {
      return;
    }

    if (restoreState.modal === "edit") {
      await openEditModal(recordId);
      if (restoreState.tab) {
        setEditTab(String(restoreState.tab));
      }
      return;
    }

    await openDetailsModal(recordId);
    const tabKey = String(restoreState.tab || "documents").trim() || "documents";
    const tabButton = detailsBody?.querySelector(`.registry-details-tab[data-details-tab="${tabKey}"]`);
    if (tabButton) {
      tabButton.click();
    }
  }

  const registryEditDefaultRoles = new Set(["admin", "clerk", "data_entry", "writeup_officer"]);
  const registryBulkUploadDefaultRoles = new Set(["admin", "oc_pen", "dep_oc", "deputy_oc", "deputy_oc_pen", "deputy_oc_pension", "data_entry"]);
  const lifeCertDefaultRoles = new Set(["admin", "clerk", "data_entry", "oc_pen", "writeup_officer"]);
  const registryDeleteRequestDefaultRoles = new Set(["admin", "clerk", "data_entry", "oc_pen", "dep_oc", "deputy_oc", "deputy_oc_pen", "deputy_oc_pension"]);
  const registryDeleteProcessDefaultRoles = new Set(["admin", "oc_pen", "dep_oc", "deputy_oc", "deputy_oc_pen", "deputy_oc_pension"]);
  const benefitsMonthlyDefaultRoles = new Set(["admin", "clerk", "oc_pen", "writeup_officer", "file_creator", "data_entry", "assessor", "auditor", "approver", "user"]);
  const benefitsPrivilegedRoles = new Set(["admin", "assessor"]);

  function getPermissionValue(key, fallback = false) {
    if (Object.prototype.hasOwnProperty.call(currentUserPermissions, key)) {
      return Boolean(currentUserPermissions[key]);
    }
    return Boolean(fallback);
  }

  function canEditRegistry() {
    return getPermissionValue("registry.edit", registryEditDefaultRoles.has(currentUserRole));
  }

  function canBulkUploadRegistry() {
    const roleAllowsBulk = registryBulkUploadDefaultRoles.has(currentUserRole);
    return roleAllowsBulk && getPermissionValue("registry.bulk_upload", roleAllowsBulk);
  }

  function canManageLifeCertificates() {
    return getPermissionValue("registry.life_certificate.submit", lifeCertDefaultRoles.has(currentUserRole));
  }

  function canRequestRegistryDelete() {
    return getPermissionValue("registry.delete_request", registryDeleteRequestDefaultRoles.has(currentUserRole));
  }

  function canProcessDeleteQueue() {
    return getPermissionValue("registry.delete_queue.process", registryDeleteProcessDefaultRoles.has(currentUserRole));
  }

  function canDeleteDirectlyByRole() {
    const role = String(currentUserRole || "").trim().toLowerCase();
    return ["admin", "oc_pen", "dep_oc", "deputy_oc", "deputy_oc_pen", "deputy_oc_pension"].includes(role);
  }

  function canEditRegistryFileNumber() {
    const role = String(currentUserRole || "").trim().toLowerCase();
    return role === "admin" || ["oc_pen", "dep_oc", "deputy_oc", "deputy_oc_pen", "deputy_oc_pension"].includes(role);
  }

  function applyRegistryFileNumberEditability() {
    if (!editRegNo) return;
    const allowed = registryFormMode === "create"
      ? canEditRegistry()
      : canEditRegistryFileNumber();
    editRegNo.readOnly = !allowed;
    editRegNo.setAttribute("aria-readonly", allowed ? "false" : "true");
    editRegNo.classList.toggle("is-readonly", !allowed);
  }

  function updateAddFileEntryPointVisibility() {
    if (!addFileBtn) return;
    addFileBtn.classList.toggle("hidden", !(canEditRegistry() || canBulkUploadRegistry()));
  }

  function setCreateFieldsEnabled(enabled) {
    createEditableFields.forEach((field) => {
      if (!field) return;
      if (field.tagName === "SELECT") {
        field.disabled = !enabled;
      } else {
        field.disabled = !enabled;
        field.readOnly = !enabled;
      }
      field.classList.toggle("field-readonly", !enabled);
    });
    if (editAddress) {
      syncDistrictState(editAddress, !enabled);
    }
  }

  function getRegistryEditBannerCopy(mode = registryFormMode) {
    const isCreate = mode === "create";
    const canCreateDirect = canEditRegistry();
    const canOpenBulk = canBulkUploadRegistry();
    if (isCreate && !canCreateDirect && canOpenBulk) {
      return {
        title: "Registry Intake Workspace",
        text: "Direct file creation is limited to users with registry edit rights. You can still use Bulk Upload to add approved registry schedules from CSV or XLSX."
      };
    }
    if (isCreate) {
      return {
        title: "New Registry Intake",
        text: "Complete the file profile section by section. Leave Box Number blank if you want the registry to allocate a shelf box automatically."
      };
    }
    return {
      title: "Registry Update Workspace",
      text: "Use the sections below to maintain the pension file profile. Leave Box Number blank during creation to let the registry allocate one automatically."
    };
  }

  function setRegistryFormMode(mode = "edit") {
    registryFormMode = mode === "create" ? "create" : "edit";
    const isCreate = registryFormMode === "create";
    const canCreateDirect = canEditRegistry();
    const canOpenBulk = canBulkUploadRegistry();
    const bannerCopy = getRegistryEditBannerCopy(registryFormMode);

    if (editTitleHeading) {
      editTitleHeading.textContent = isCreate ? "Add Pension File" : "Edit Registry Record";
    }
    if (editModeHint) {
      editModeHint.textContent = isCreate
        ? "Capture a new pension file profile here, or switch to bulk upload when you already have an approved registry schedule."
        : "Update identity, service, benefits, and registry tracking details for the selected pension file.";
    }
    if (editFormBannerTitle) {
      editFormBannerTitle.textContent = bannerCopy.title;
    }
    if (editFormBannerText) {
      editFormBannerText.textContent = bannerCopy.text;
    }
    if (editForm) {
      editForm.classList.toggle("is-create-readonly", isCreate && !canCreateDirect);
    }
    if (editSubmitBtn) {
      editSubmitBtn.textContent = isCreate ? "Create File" : "Save Changes";
      editSubmitBtn.disabled = isCreate && !canCreateDirect;
    }
    if (openBulkUploadBtn) {
      openBulkUploadBtn.classList.toggle("hidden", !isCreate || !canOpenBulk);
    }

    if (isCreate) {
      setCreateFieldsEnabled(canCreateDirect);
      applyRegistryFileNumberEditability();
      updateLivingStatusEditability();
      updateLifeCertificateEditability();
      setRegistryDocumentManagerEnabled(false);
      resetRegistryDocumentDraft();
      return;
    }

    setCreateFieldsEnabled(true);
    applyBenefitsFieldPermissions();
    applyRegistryFileNumberEditability();
    updateLivingStatusEditability();
    updateLifeCertificateEditability();
    setRegistryDocumentManagerEnabled(canEditRegistry());
    resetRegistryDocumentDraft();
  }

  function canEditMonthlySalaryField() {
    return getPermissionValue("registry.benefits.monthly_salary.edit", benefitsMonthlyDefaultRoles.has(currentUserRole));
  }

  function canEditLengthOfServiceField() {
    return getPermissionValue("registry.benefits.length_service.edit", benefitsPrivilegedRoles.has(currentUserRole));
  }

  function canEditBenefitAmountFields() {
    return getPermissionValue("registry.benefits.amounts.edit", benefitsPrivilegedRoles.has(currentUserRole));
  }

  async function loadCurrentPermissions() {
    try {
      // Fetch only page-relevant keys so UI decisions remain explicit and
      // default-role fallbacks are easy to audit in one place.
      const permissionKeys = [
        "registry.edit",
        "registry.bulk_upload",
        "registry.life_certificate.submit",
        "registry.delete_request",
        "registry.delete_queue.process",
        "registry.benefits.monthly_salary.edit",
        "registry.benefits.length_service.edit",
        "registry.benefits.amounts.edit"
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

  async function checkSession() {
    try {
      const res = await fetch("../backend/api/check_session.php", {
        credentials: "include",
        cache: "no-store"
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

  const sessionData = await checkSession();
  if (!sessionData) return;
  await loadCurrentPermissions();
  await loadTitleSuggestions();
  await initDistrictFields();
  setRegistryFormMode("edit");
  updateAddFileEntryPointVisibility();

  if (deleteQueueBtn && canProcessDeleteQueue()) {
    deleteQueueBtn.classList.remove("hidden");
    void refreshDeleteQueueBadge();
  }
  if (lifeCertBtn && canManageLifeCertificates()) {
    lifeCertBtn.classList.remove("hidden");
  }

  function setEditTab(tabKey) {
    if (!tabKey) return;
    editTabButtons.forEach((btn) => {
      const isActive = btn.getAttribute("data-edit-tab") === tabKey;
      btn.classList.toggle("is-active", isActive);
      btn.setAttribute("aria-selected", isActive ? "true" : "false");
      btn.tabIndex = isActive ? 0 : -1;
      if (isActive && typeof btn.scrollIntoView === "function") {
        btn.scrollIntoView({ behavior: "smooth", block: "nearest", inline: "center" });
      }
    });

    editTabPanels.forEach((panel) => {
      const isActive = panel.getAttribute("data-edit-panel") === tabKey;
      panel.classList.toggle("is-active", isActive);
    });
  }

  function initEditTabs() {
    if (!editTabButtons.length || !editTabPanels.length) return;
    editTabButtons.forEach((btn) => {
      btn.addEventListener("click", () => {
        const targetTab = btn.getAttribute("data-edit-tab");
        setEditTab(targetTab);
      });
    });
  }

  function normalizeEditablePhone(value) {
    const input = String(value || "").trim().replace(/[\s().-]/g, "");
    if (!input) return null;
    if (/^00[1-9]\d{7,14}$/.test(input)) return `+${input.slice(2)}`;
    if (/^\+[1-9]\d{7,14}$/.test(input)) return input;
    if (/^0\d{9}$/.test(input)) return `+256${input.slice(1)}`;
    if (/^[1-9]\d{7,14}$/.test(input)) return `+${input}`;
    return null;
  }

  function setRegistryFieldInvalid(field, invalid) {
    if (!field) return;
    const isInvalid = Boolean(invalid);
    field.classList.toggle("registry-field-invalid", isInvalid);
    field.toggleAttribute("aria-invalid", isInvalid);

    const selectorShell = field.nextElementSibling instanceof HTMLElement
      && field.nextElementSibling.classList.contains("district-select")
      ? field.nextElementSibling
      : null;
    if (selectorShell) {
      selectorShell.classList.toggle("registry-field-invalid", isInvalid);
      const visibleInput = selectorShell.querySelector(".district-select-input");
      if (visibleInput instanceof HTMLElement) {
        visibleInput.classList.toggle("registry-field-invalid", isInvalid);
      }
    }
  }

  const registryValidationRules = {
    identity: [
      { field: editRegNo, message: "Identity Profile is missing the file number.", isInvalid: () => registryFormMode === "create" ? !String(editRegNo?.value || "").trim() : false },
      {
        field: editRegNo,
        message: () => validateRegistryFileNumber(editRegNo?.value || "").message,
        isInvalid: () => {
          const value = String(editRegNo?.value || "").trim();
          if (!value) return registryFormMode === "create";
          if (registryFormMode !== "create") {
            const originalValue = String(currentEditRecordContext?.regNo || "").trim();
            if (!canEditRegistryFileNumber() || value === originalValue) return false;
          }
          return !validateRegistryFileNumber(value).valid;
        }
      },
      { field: editTitle, message: "Identity Profile is missing the title or rank.", isInvalid: () => !String(editTitle?.value || "").trim() },
      { field: editSName, message: "Identity Profile is missing the surname.", isInvalid: () => !String(editSName?.value || "").trim() },
      { field: editFName, message: "Identity Profile is missing the first name.", isInvalid: () => !String(editFName?.value || "").trim() },
      {
        field: editNIN,
        message: () => validateNationalIdValue(editNIN?.value || "", {
          birthDate: editBirthDate?.value || "",
          gender: editGender?.value || ""
        }).message || "Identity Profile has an invalid NIN.",
        isInvalid: () => {
          const value = String(editNIN?.value || "").trim();
          if (value === "") return false;
          return !validateNationalIdValue(value, {
            birthDate: editBirthDate?.value || "",
            gender: editGender?.value || ""
          }).valid;
        }
      }
    ],
    service: [
      { field: editRetirementType, message: "Service Profile is missing the mode of retirement.", isInvalid: () => !String(editRetirementType?.value || "").trim() },
      {
        field: editRetirementType,
        message: () => getRegistryRetirementPolicyAssessment().primaryMessage || "The retirement profile does not satisfy the configured policy checks.",
        isInvalid: () => Boolean(getRegistryRetirementPolicyAssessment().errors.length)
      }
    ],
    benefits: [
      {
        field: editMonthlySalary,
        message: "Benefits Snapshot has an invalid monthly salary amount.",
        isInvalid: () => {
          const value = String(editMonthlySalary?.value || "").trim();
          const parsed = parseMoneyInputValue(value, Number.NaN);
          return value !== "" && (!Number.isFinite(parsed) || parsed < 0);
        }
      }
    ],
    contact: [
      {
        field: editTelNo,
        message: "Contact & Banking has an invalid phone number format.",
        isInvalid: () => {
          const value = String(editTelNo?.value || "").trim();
          return value !== "" && !normalizeEditablePhone(value);
        }
      },
      {
        field: editApplicantEmail,
        message: "Contact & Banking has an invalid email address.",
        isInvalid: () => {
          const value = String(editApplicantEmail?.value || "").trim();
          return value !== "" && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
        }
      },
      {
        field: editNextOfKin,
        message: "Contact & Banking is missing the next of kin name required for death records.",
        isInvalid: () => requiresNextOfKinForCurrentRegistryRecord() && !String(editNextOfKin?.value || "").trim()
      },
      {
        field: editNextOfKinContact,
        message: "Contact & Banking is missing the next of kin contact required for death records.",
        isInvalid: () => requiresNextOfKinForCurrentRegistryRecord() && !String(editNextOfKinContact?.value || "").trim()
      },
      {
        field: editNextOfKinContact,
        message: "Contact & Banking has an invalid next of kin phone number format.",
        isInvalid: () => {
          const value = String(editNextOfKinContact?.value || "").trim();
          return value !== "" && !normalizeEditablePhone(value);
        }
      }
    ],
    registry: [
      {
        field: editAvailabilityReason,
        message: "Registry Tracking needs an availability reason when the file is marked out of shelf.",
        isInvalid: () => String(editAvailabilityStatus?.value || "").trim() === "out_of_shelf" && !String(editAvailabilityReason?.value || "").trim()
      }
    ],
    documents: []
  };

  const registryTabFieldGroups = {
    identity: [editRegNo, editComputerNo, editSupplierNo, editTitle, editSName, editFName, editGender, editNIN, editTIN, editTelNo, editAddress],
    service: [editBirthDate, editEnlistmentDate, editRetirementDate, editRetirementType, editLivingStatus, editLifeCertificate, editPayrollStatus, editPayType],
    benefits: [editMonthlySalary, editLengthOfService, editAnnualSalary, editReducedPension, editFullPension, editGratuity],
    contact: [editApplicantEmail, editBankName, editBankAccount, editBankBranch, editNextOfKin, editNextOfKinContact],
    registry: [editBoxNo, editAvailabilityStatus, editAvailabilityReason, editOther],
    documents: [registryDocumentType, registryDocumentFile]
  };

  function loadRegistryValidationStates() {
    Object.entries(registryValidationRules).forEach(([tabKey, rules]) => {
      const firstError = rules.find((rule) => rule.isInvalid()) || null;
      const touched = (registryTabFieldGroups[tabKey] || []).some((field) => field && registryTouchedFields.has(field.id));
      rules.forEach((rule) => setRegistryFieldInvalid(rule.field, Boolean(firstError === rule && (registrySubmitAttempted || touched))));
    });

    editTabButtons.forEach((button) => {
      const tabKey = String(button.getAttribute("data-edit-tab") || "").trim();
      const fields = (registryTabFieldGroups[tabKey] || []).filter(Boolean);
      const touched = fields.some((field) => registryTouchedFields.has(field.id));
      const firstError = (registryValidationRules[tabKey] || []).find((rule) => rule.isInvalid()) || null;
      const hasValue = fields.some((field) => String(field.value || "").trim() !== "");
      const ruleCount = (registryValidationRules[tabKey] || []).length;

      let state = "neutral";
      if (firstError) {
        state = registrySubmitAttempted || touched ? "invalid" : "neutral";
      } else if (tabKey === "documents") {
        state = registryFormMode === "edit" ? "valid" : "neutral";
      } else if (ruleCount === 0) {
        state = hasValue ? "valid" : "neutral";
      } else if (tabKey === "benefits" && editMonthlySalary && String(editMonthlySalary.value || "").trim() === "") {
        state = "neutral";
      } else {
        state = hasValue ? "valid" : "neutral";
      }
      button.dataset.validState = state;
    });
  }

  function validateRegistryEditForm() {
    for (const tabKey of ["identity", "service", "benefits", "contact", "registry"]) {
      const rule = (registryValidationRules[tabKey] || []).find((entry) => entry.isInvalid()) || null;
      if (rule) {
        return {
          tab: tabKey,
          field: rule.field,
          message: typeof rule.message === "function" ? String(rule.message() || "") : String(rule.message || "")
        };
      }
    }
    return null;
  }

  function resetRegistryEditBanner() {
    if (!editFormBanner || !editFormBannerTitle || !editFormBannerText) return;
    if (!editFormBanner.classList.contains("is-error")) return;
    const bannerCopy = getRegistryEditBannerCopy(registryFormMode);
    editFormBanner.classList.remove("is-error");
    editFormBannerTitle.textContent = bannerCopy.title;
    editFormBannerText.textContent = bannerCopy.text;
  }

  function showRegistryValidationIssue(validationError) {
    if (!validationError) return;
    registrySubmitAttempted = true;
    loadRegistryValidationStates();
    setEditTab(validationError.tab);
    if (editFormBanner && editFormBannerTitle && editFormBannerText) {
      editFormBanner.classList.add("is-error");
      editFormBannerTitle.textContent = "Fix Required";
      editFormBannerText.textContent = validationError.message;
    }
    window.setTimeout(() => {
      validationError.field?.focus?.({ preventScroll: true });
      validationError.field?.scrollIntoView?.({ behavior: "smooth", block: "center" });
    }, 80);
    showFeedbackModal("error", "Validation Error", validationError.message, () => {
      setEditTab(validationError.tab);
      validationError.field?.focus?.();
    });
  }

  async function loadDocumentTypeOptions() {
    try {
      const response = await fetch("../backend/api/get_document_type_options.php", {
        credentials: "include",
        cache: "no-store"
      });
      const data = await response.json();
      documentTypeOptions = response.ok && data.success && Array.isArray(data.options) ? data.options : [];
    } catch (error) {
      console.error("Unable to load document type options:", error);
      documentTypeOptions = [];
    }

    if (!registryDocumentType) return;
    registryDocumentType.innerHTML = '<option value="">Select document type</option>';
    documentTypeOptions.forEach((value) => {
      const option = document.createElement("option");
      option.value = value;
      option.textContent = value;
      registryDocumentType.appendChild(option);
    });
  }

  async function loadBankOptions() {
    try {
      const response = await fetch("../backend/api/get_banks.php?active_only=1", {
        credentials: "include",
        cache: "no-store"
      });
      const data = await response.json();
      bankOptions = response.ok && data.success && Array.isArray(data.banks) ? data.banks : [];
    } catch (error) {
      console.error("Unable to load bank options:", error);
      bankOptions = [];
    }

    const currentValue = String(editBankName?.value || "").trim();
    populateBankSelect(editBankName, currentValue);
    populateBankSelect(lifeCertBankName, lifeCertBankName?.value || "");
    setRegistryBankValue(currentValue);
  }

  function bindRegistryEditFieldTracking() {
    Object.values(registryTabFieldGroups)
      .flat()
      .filter(Boolean)
      .forEach((field) => {
        const handler = () => {
          registryTouchedFields.add(field.id);
          loadRegistryValidationStates();
          if (!validateRegistryEditForm()) {
            resetRegistryEditBanner();
          }
        };
        field.addEventListener("input", handler);
        field.addEventListener("change", handler);
      });
  }

  async function loadTitleSuggestions() {
    try {
      const response = await fetch("../backend/api/get_titles.php?active_only=1", {
        credentials: "include",
        cache: "no-store"
      });
      const data = await response.json();
      if (!response.ok || !data.success || !Array.isArray(data.titles)) {
        return;
      }
      allowedTitles = data.titles
        .map((row) => String(row.title_name || "").trim())
        .filter((row) => row !== "");
      if (editTitleOptions) {
        editTitleOptions.innerHTML = allowedTitles
          .map((title) => `<option value="${escapeHtml(title)}"></option>`)
          .join("");
      }
    } catch (error) {
      console.error("Unable to load title suggestions:", error);
    }
  }

  function normalizeTitleValue(value) {
    return String(value || "").trim().toLowerCase();
  }

  function validateSelectedTitle(showError = true) {
    const value = String(editTitle?.value || "").trim();
    if (value === "") {
      return true;
    }
    if (!Array.isArray(allowedTitles) || allowedTitles.length === 0) {
      return true;
    }
    const normalized = normalizeTitleValue(value);
    const match = allowedTitles.find((title) => normalizeTitleValue(title) === normalized) || "";
    if (match) {
      if (editTitle.value !== match) {
        setRegistryTitleValue(match);
      }
      return true;
    }
    if (showError) {
      showFeedbackModal(
        "error",
        "Invalid Title",
        "Selected Title/Rank is not in the approved titles list. Ask Admin to add this title in settings."
      );
    }
    return false;
  }

  function getRegistryDocumentContext() {
    const recordId = Number(editRecordId?.value || currentEditRecordContext?.id || 0);
    const regNo = String(currentEditRecordContext?.regNo || editRegNo?.value || "").trim();
    const staffdueId = Number(currentEditRecordContext?.staffdueId || 0);
    return {
      id: recordId,
      regNo,
      staffdueId
    };
  }

  function setRegistryDocumentManagerEnabled(enabled) {
    const manager = registryDocumentType?.closest(".registry-doc-manager");
    manager?.classList.toggle("is-disabled", !enabled);

    [registryDocumentType, registryDocumentFile, registryDocumentResetBtn, registryDocumentSaveBtn].forEach((control) => {
      if (!control) return;
      control.disabled = !enabled;
    });

    if (registryDocumentModeBadge) {
      registryDocumentModeBadge.textContent = enabled ? "New Upload" : "Available After Save";
    }
  }

  function resetRegistryDocumentDraft({ keepHint = false } = {}) {
    if (registryDocumentTargetId) {
      registryDocumentTargetId.value = "";
    }
    if (registryDocumentType) {
      registryDocumentType.value = "";
    }
    if (registryDocumentFile) {
      registryDocumentFile.value = "";
    }
    if (registryDocumentModeBadge) {
      registryDocumentModeBadge.textContent = registryFormMode === "edit" ? "New Upload" : "Available After Save";
    }
    if (!keepHint && registryDocumentHint) {
      registryDocumentHint.textContent = registryFormMode === "edit"
        ? "Choose a document type and a file to upload it to this pension file record."
        : "Save the pension file first before you can upload or manage linked documents.";
    }
    if (registryDocumentSaveBtn) {
      registryDocumentSaveBtn.textContent = "Upload Document";
      registryDocumentSaveBtn.disabled = registryFormMode !== "edit" || !canEditRegistry();
    }
  }

  function primeRegistryDocumentDraft(documentId, docType, fileName) {
    if (!registryDocumentTargetId || !registryDocumentType) return;
    registryDocumentTargetId.value = String(documentId || "");
    registryDocumentType.value = String(docType || "");
    if (registryDocumentFile) {
      registryDocumentFile.value = "";
    }
    if (registryDocumentModeBadge) {
      registryDocumentModeBadge.textContent = "Editing Existing";
    }
    if (registryDocumentHint) {
      registryDocumentHint.textContent = `Editing ${fileName || "selected document"}. You can change the document type only, or choose a replacement file before saving changes.`;
    }
    if (registryDocumentSaveBtn) {
      registryDocumentSaveBtn.textContent = "Save Document Changes";
    }
    registryDocumentType.focus();
  }

  function setRegistryDocumentBusy(isBusy, mode = "upload") {
    if (registryDocumentSaveBtn) {
      registryDocumentSaveBtn.disabled = isBusy || registryFormMode !== "edit" || !canEditRegistry();
      registryDocumentSaveBtn.textContent = isBusy
        ? (mode === "update" ? "Saving..." : "Uploading...")
        : (Number(registryDocumentTargetId?.value || 0) > 0 ? "Save Document Changes" : "Upload Document");
    }
    if (registryDocumentResetBtn) {
      registryDocumentResetBtn.disabled = isBusy || registryFormMode !== "edit";
    }
    if (registryDocumentType) {
      registryDocumentType.disabled = isBusy || registryFormMode !== "edit" || !canEditRegistry();
    }
    if (registryDocumentFile) {
      registryDocumentFile.disabled = isBusy || registryFormMode !== "edit" || !canEditRegistry();
    }
  }

  function renderCreateDocumentsPlaceholder() {
    if (!editDocumentsList) return;
    editDocumentsList.innerHTML = `
      <div class="app-state-message app-state-neutral">
        Documents become available after the file is created. Save the new pension file first, then return here to review uploaded documents.
      </div>
    `;
    setRegistryDocumentManagerEnabled(false);
    resetRegistryDocumentDraft();
  }

  function resetRegistryFormForCreate() {
    if (!editForm) return;

    editForm.reset();
    registrySubmitAttempted = false;
    registryTouchedFields.clear();
    currentEditRecordContext = null;
    setRegistryFormMode("create");
    editRecordId.value = "";
    Array.from(editRetirementType?.querySelectorAll('option[data-dynamic-retirement-type="true"]') || []).forEach((option) => option.remove());

    editRegNo.value = REGISTRY_FILE_NUMBER_PREFIX;
    editComputerNo.value = "";
    editSupplierNo.value = "";
    setRegistryTitleValue("");
    editBoxNo.value = "";
    editSName.value = "";
    editFName.value = "";
    editGender.value = "";
    editLivingStatus.value = "Alive";
    editLifeCertificate.value = "Not Submitted";
    editBirthDate.value = "";
    editEnlistmentDate.value = "";
    editRetirementDate.value = "";
    editRetirementType.value = "";
    editNIN.value = "";
    editTIN.value = "";
    editPayrollStatus.value = "Not on Payroll";
    editPayType.value = "";
    editTelNo.value = "";
    editApplicantEmail.value = "";
    editNextOfKin.value = "";
    editNextOfKinContact.value = "";
    setRegistryBankValue("");
    editBankAccount.value = "";
    editBankBranch.value = "";
    editMonthlySalary.value = "";
    editLengthOfService.value = "";
    editAnnualSalary.value = "";
    editReducedPension.value = "";
    editFullPension.value = "";
    editGratuity.value = "";
    editDateOn15yrs.value = "";
    editPeriodTo15yrs.value = "";
    editPeriodFrom15yrs.value = "";
    editAvailabilityStatus.value = "in_shelf";
    editAvailabilityReason.value = "";
    setDistrictValue(editAddress, "");
    editOther.value = "";

    renderCreateDocumentsPlaceholder();
    setEditTab("identity");
    validateSelectedTitle(false);
    updateLivingStatusEditability();
    updateLifeCertificateEditability();
    updateNextOfKinRequirementUi();
    recomputeRegistryBenefitFields();
    loadRegistryValidationStates();
  }

  function buildRegistryFormPayload() {
    updateLivingStatusEditability();
    updateLifeCertificateEditability();
    if (registryFormMode === "create") {
      syncRegistryFileNumberInput({ preservePrefix: true });
    }

    return {
      regNo: registryFormMode === "create"
        ? normalizeRegistryFileNumber(editRegNo.value, { preservePrefix: false })
        : String(editRegNo.value || "").trim().toUpperCase(),
      computerNo: editComputerNo.value.trim(),
      supplierNo: editSupplierNo.value.trim(),
      title: editTitle.value.trim(),
      boxNo: editBoxNo.value.trim(),
      sName: editSName.value.trim(),
      fName: editFName.value.trim(),
      gender: editGender.value,
      livingStatus: editLivingStatus.value,
      lifeCertificate: editLifeCertificate.value,
      birthDate: editBirthDate.value,
      enlistmentDate: editEnlistmentDate.value,
      retirementDate: editRetirementDate.value,
      retirementType: normalizeRetirementTypeValue(editRetirementType.value.trim()),
      NIN: normalizeNationalIdValue(editNIN.value),
      TIN: editTIN.value.trim(),
      address: editAddress.value.trim(),
      telNo: editTelNo.value.trim(),
      applicant_email: editApplicantEmail.value.trim(),
      next_of_kin: editNextOfKin.value.trim(),
      next_of_kin_contact: editNextOfKinContact.value.trim(),
      bank_name: editBankName.value.trim(),
      bank_account: editBankAccount.value.trim(),
      bank_branch: editBankBranch.value.trim(),
      monthlySalary: String(parseMoneyInputValue(editMonthlySalary.value.trim(), 0)),
      lengthOfService: editLengthOfService.value.trim(),
      annualSalary: String(parseMoneyInputValue(editAnnualSalary.value.trim(), 0)),
      reducedPension: String(parseMoneyInputValue(editReducedPension.value.trim(), 0)),
      fullPension: String(parseMoneyInputValue(editFullPension.value.trim(), 0)),
      gratuity: String(parseMoneyInputValue(editGratuity.value.trim(), 0)),
      payrollStatus: editPayrollStatus.value.trim(),
      payType: deriveRegistryPayTypeValue({
        retirementType: normalizeRetirementTypeValue(editRetirementType.value.trim()),
        enlistmentDate: editEnlistmentDate.value,
        retirementDate: editRetirementDate.value,
        payType: editPayType.value.trim()
      }),
      availability_status: editAvailabilityStatus.value,
      availability_reason: editAvailabilityReason.value.trim(),
      other: editOther.value.trim()
    };
  }

  function setRegistryFormBusy(isBusy) {
    if (!editSubmitBtn) return;
    if (isBusy) {
      editSubmitBtn.disabled = true;
      editSubmitBtn.dataset.busy = "true";
      editSubmitBtn.textContent = registryFormMode === "create" ? "Creating..." : "Saving...";
      return;
    }
    delete editSubmitBtn.dataset.busy;
    setRegistryFormMode(registryFormMode);
  }

  function resetRegistryBulkUploadReport({ clearFile = false } = {}) {
    if (bulkUploadReport) {
      bulkUploadReport.classList.add("hidden");
      bulkUploadReport.innerHTML = "";
    }
    if (clearFile && bulkUploadForm) {
      bulkUploadForm.reset();
    }
  }

  function renderRegistryBulkUploadLoading(mode = "dry_run") {
    if (!bulkUploadReport) return;
    const actionLabel = mode === "import" ? "Importing registry file data..." : "Running dry check on registry data...";
    bulkUploadReport.classList.remove("hidden");
    bulkUploadReport.innerHTML = `
      <div class="registry-import-loading">
        <span class="registry-import-spinner" aria-hidden="true"></span>
        <div>
          <strong>${escapeHtml(actionLabel)}</strong>
          <p>Please wait while the registry upload is validated and summarized.</p>
        </div>
      </div>
    `;
  }

  function formatImportFieldList(fields) {
    if (!Array.isArray(fields) || fields.length === 0) {
      return "—";
    }
    return fields.map((field) => humanizeKey(field)).join(", ");
  }

  function renderRegistryBulkUploadReport(report, status = "success", message = "") {
    if (!bulkUploadReport) return;
    const summary = report?.summary || {};
    const rows = Array.isArray(report?.rows) ? report.rows : [];
    const statusLabelMap = {
      success: "Ready",
      partial: "Needs Review",
      failed: "Failed"
    };
    const statusLabel = statusLabelMap[status] || "Ready";

    bulkUploadReport.classList.remove("hidden");
    bulkUploadReport.innerHTML = `
      <section class="registry-import-report-panel">
        <div class="registry-import-report-head">
          <div>
            <h4>${escapeHtml(report?.dataset_label || "Registry Import Report")}</h4>
            <p>${escapeHtml(message || "Review the validation outcome below.")}</p>
          </div>
          <span class="registry-import-status-pill ${escapeHtml(status)}">${escapeHtml(statusLabel)}</span>
        </div>
        <div class="registry-import-summary-grid">
          <article class="registry-import-metric">
            <strong>${escapeHtml(String(summary.total_rows ?? 0))}</strong>
            <span>Rows Checked</span>
          </article>
          <article class="registry-import-metric">
            <strong>${escapeHtml(String(summary.inserted_rows ?? 0))}</strong>
            <span>New Files</span>
          </article>
          <article class="registry-import-metric">
            <strong>${escapeHtml(String(summary.merged_rows ?? 0))}</strong>
            <span>Enriched Rows</span>
          </article>
          <article class="registry-import-metric">
            <strong>${escapeHtml(String(summary.conflict_rows ?? 0))}</strong>
            <span>Conflicts</span>
          </article>
          <article class="registry-import-metric">
            <strong>${escapeHtml(String(summary.invalid_rows ?? 0))}</strong>
            <span>Invalid Rows</span>
          </article>
          <article class="registry-import-metric">
            <strong>${escapeHtml(String(summary.failed_rows ?? 0))}</strong>
            <span>Failed Rows</span>
          </article>
        </div>
        <div class="registry-import-table-wrap">
          <table class="registry-import-table">
            <thead>
              <tr>
                <th>Row</th>
                <th>File Number</th>
                <th>Status</th>
                <th>Notes</th>
                <th>Merged Fields</th>
                <th>Conflicts</th>
              </tr>
            </thead>
            <tbody>
              ${rows.length ? rows.map((row) => `
                <tr>
                  <td>${escapeHtml(String(row.row_number ?? "—"))}</td>
                  <td>${escapeHtml(String(row.key_value || "—"))}</td>
                  <td>${escapeHtml(humanizeKey(row.status || "ready"))}</td>
                  <td>${escapeHtml(String(row.message || "—"))}</td>
                  <td>${escapeHtml(formatImportFieldList(row.merged_fields))}</td>
                  <td>${escapeHtml(formatImportFieldList(row.conflict_fields))}</td>
                </tr>
              `).join("") : `
                <tr>
                  <td colspan="6">No row-level issues were reported for this upload.</td>
                </tr>
              `}
            </tbody>
          </table>
        </div>
      </section>
    `;
  }

  function buildRegistryImportFeedbackMessage(mode, status, report, fallbackMessage = "") {
    const summary = report?.summary || {};
    const total = Number(summary.total_rows ?? 0);
    const inserted = Number(summary.inserted_rows ?? 0);
    const merged = Number(summary.merged_rows ?? 0);
    const conflicts = Number(summary.conflict_rows ?? 0);
    const invalid = Number(summary.invalid_rows ?? 0);
    const failed = Number(summary.failed_rows ?? 0);
    const reviewedLabel = mode === "import" ? "upload" : "dry check";

    if (status === "success") {
      return fallbackMessage
        || `${humanizeKey(reviewedLabel)} completed for ${total} row(s): ${inserted} new file(s), ${merged} merged row(s), and no review issues.`;
    }

    if (status === "partial") {
      return fallbackMessage
        || `${humanizeKey(reviewedLabel)} finished with review items across ${total} row(s): ${inserted} new file(s), ${merged} merged row(s), ${conflicts} conflict row(s), ${invalid} invalid row(s), and ${failed} failed row(s).`;
    }

    return fallbackMessage
      || `${humanizeKey(reviewedLabel)} failed after checking ${total} row(s). Review the report and try again.`;
  }

  function setRegistryBulkUploadBusy(isBusy, mode = "dry_run") {
    if (bulkDownloadTemplateBtn) {
      bulkDownloadTemplateBtn.disabled = isBusy;
    }
    if (bulkDryRunBtn) {
      bulkDryRunBtn.disabled = isBusy;
      bulkDryRunBtn.textContent = isBusy && mode === "dry_run" ? "Checking..." : "Run Dry Check";
    }
    if (bulkImportBtn) {
      bulkImportBtn.disabled = isBusy;
      bulkImportBtn.textContent = isBusy && mode === "import" ? "Importing..." : "Import Data";
    }
    if (bulkUploadFile) {
      bulkUploadFile.disabled = isBusy;
    }
  }

  async function downloadRegistryBulkTemplate() {
    if (!canBulkUploadRegistry()) {
      showFeedbackModal("error", "Access Denied", "You do not have access to registry bulk upload templates.");
      return;
    }

    setRegistryBulkUploadBusy(true, "template");
    try {
      const response = await fetch("../backend/api/download_registry_import_template.php", {
        credentials: "include",
        cache: "no-store"
      });
      const contentType = String(response.headers.get("content-type") || "").toLowerCase();
      if (!response.ok || contentType.includes("application/json")) {
        const errorData = contentType.includes("application/json")
          ? await response.json().catch(() => ({}))
          : {};
        throw new Error(errorData.message || "Unable to download the registry import template.");
      }

      const blob = await response.blob();
      const disposition = String(response.headers.get("content-disposition") || "");
      const fileNameMatch = disposition.match(/filename=\"?([^\";]+)\"?/i);
      const fileName = fileNameMatch?.[1] || "file_registry_template.csv";
      const objectUrl = URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = objectUrl;
      link.download = fileName;
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(objectUrl);
      showFeedbackModal("success", "Template Ready", "The pension file registry template download has started.");
    } catch (error) {
      console.error("Registry template download failed:", error);
      showFeedbackModal("error", "Download Failed", error.message || "Unable to download the registry import template.");
    } finally {
      setRegistryBulkUploadBusy(false);
    }
  }

  async function submitRegistryBulkUpload(mode = "dry_run") {
    if (!canBulkUploadRegistry()) {
      showFeedbackModal("error", "Access Denied", "You do not have permission to bulk upload pension file data.");
      return;
    }

    const selectedFile = bulkUploadFile?.files?.[0];
    if (!selectedFile) {
      showFeedbackModal("error", "No File Selected", "Choose a CSV or XLSX file before running the registry upload.");
      bulkUploadFile?.focus();
      return;
    }

    const formData = new FormData();
    formData.append("import_file", selectedFile);
    formData.append("mode", mode);

    setRegistryBulkUploadBusy(true, mode);
    renderRegistryBulkUploadLoading(mode);

    try {
      const response = await fetch("../backend/api/process_registry_file_import.php", {
        method: "POST",
        credentials: "include",
        body: formData
      });
      const data = await response.json().catch(() => ({}));

      if (!response.ok || !data.success) {
        const failureMessage = data.message || "Unable to process the pension file upload.";
        renderRegistryBulkUploadReport(data.report || { summary: {}, rows: [] }, "failed", failureMessage);
        showFeedbackModal("error", mode === "import" ? "Bulk Upload Failed" : "Dry Check Failed", failureMessage);
        return;
      }

      const report = data.report || { summary: data.summary || {}, rows: [] };
      const status = String(data.status || "success").toLowerCase();
      const feedbackMessage = buildRegistryImportFeedbackMessage(mode, status, report, data.message || "");
      renderRegistryBulkUploadReport(report, status, feedbackMessage);
      const reviewDownloadStarted = downloadRegistryImportReviewExport(data.review_export);

      const modalTitle = mode === "import"
        ? (status === "success" ? "Bulk Upload Complete" : "Bulk Upload Needs Review")
        : (status === "success" ? "Dry Check Complete" : "Dry Check Needs Review");
      showFeedbackModal(
        status === "failed" ? "error" : "success",
        modalTitle,
        feedbackMessage + (reviewDownloadStarted ? " The review file download has started." : "")
      );

      if (mode === "import") {
        await loadCards();
      }
    } catch (error) {
      console.error("Registry bulk upload failed:", error);
      const failureMessage = error.message || "Unable to process the pension file upload.";
      renderRegistryBulkUploadReport({ summary: {}, rows: [] }, "failed", failureMessage);
      showFeedbackModal("error", mode === "import" ? "Bulk Upload Failed" : "Dry Check Failed", failureMessage);
    } finally {
      setRegistryBulkUploadBusy(false);
    }
  }

  function downloadRegistryImportReviewExport(reviewExport) {
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
      const objectUrl = URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = objectUrl;
      link.download = reviewExport.file_name || "registry_import_review.csv";
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(objectUrl);
      return true;
    } catch (error) {
      console.error("Unable to download registry review export:", error);
      return false;
    }
  }

  function openBulkUploadModal() {
    if (!canBulkUploadRegistry()) {
      showFeedbackModal("error", "Access Denied", "Bulk upload is only available to Admin, OC/Pension, Deputy OC/Pension, and Data Entrant users.");
      return;
    }
    resetRegistryBulkUploadReport({ clearFile: true });
    openModal(bulkUploadModal);
  }

  function openCreateModal() {
    if (!(canEditRegistry() || canBulkUploadRegistry())) {
      showFeedbackModal("error", "Access Denied", "You do not have access to add pension files to the registry.");
      return;
    }

    resetRegistryFormForCreate();
    openModal(editModal);

    if (canEditRegistry()) {
      const primaryField = editRegNo && !editRegNo.readOnly ? editRegNo : editSName;
      primaryField?.focus();
      return;
    }

    if (canBulkUploadRegistry()) {
      openBulkUploadBtn?.focus();
    }
  }

  function renderEditDocuments(documents) {
    if (!editDocumentsList) return;
    setRegistryDocumentManagerEnabled(registryFormMode === "edit" && canEditRegistry());
    resetRegistryDocumentDraft({ keepHint: false });
    if (!Array.isArray(documents) || documents.length === 0) {
      editDocumentsList.innerHTML = '<div class="app-state-message app-state-neutral">No documents uploaded.</div>';
      return;
    }

    editDocumentsList.innerHTML = documents.map((doc) => {
      const documentId = Number(doc.document_id || 0);
      const fileLabel = doc.file_name || "Document";
      const fileUrl = documentId > 0
        ? buildViewerUrl(
          `../backend/api/view_staff_document.php?document_id=${documentId}`,
          fileLabel,
          {
            page: "pension_file_registry",
            modal: "edit",
            recordId: Number(editRecordId?.value || currentDetailsRecord?.id || 0),
            tab: getActiveEditTabKey()
          }
        )
        : "#";
      const downloadUrl = documentId > 0
        ? `../backend/api/view_staff_document.php?document_id=${encodeURIComponent(documentId)}&download=1`
        : "#";
      return `
        <article class="registry-doc-item">
          <div class="registry-doc-copy">
            <strong>${escapeHtml(doc.doc_type || "Document")}</strong>
            <small>${escapeHtml(fileLabel)} - ${escapeHtml(formatDisplay(doc.uploaded_at))}</small>
          </div>
          <div class="registry-doc-actions">
            <a class="registry-doc-button link" href="${escapeHtml(fileUrl)}">Open</a>
            <a class="registry-doc-button link" href="${escapeHtml(downloadUrl)}">Download</a>
            <button type="button" class="registry-doc-button" data-registry-doc-edit="${documentId}" data-registry-doc-type="${escapeHtml(doc.doc_type || "Document")}" data-registry-doc-name="${escapeHtml(fileLabel)}">Edit</button>
            <button type="button" class="registry-doc-button danger" data-registry-doc-delete="${documentId}" data-registry-doc-name="${escapeHtml(fileLabel)}">Delete</button>
          </div>
        </article>
      `;
    }).join("");

    editDocumentsList.querySelectorAll("[data-registry-doc-edit]").forEach((button) => {
      button.addEventListener("click", () => {
        const documentId = Number(button.getAttribute("data-registry-doc-edit") || 0);
        const docType = button.getAttribute("data-registry-doc-type") || "Document";
        const fileName = button.getAttribute("data-registry-doc-name") || "selected document";
        if (!documentId) return;
        primeRegistryDocumentDraft(documentId, docType, fileName);
      });
    });

    editDocumentsList.querySelectorAll("[data-registry-doc-delete]").forEach((button) => {
      button.addEventListener("click", async () => {
        const documentId = Number(button.getAttribute("data-registry-doc-delete") || 0);
        const fileName = button.getAttribute("data-registry-doc-name") || "this document";
        if (!documentId) return;
        const confirmed = await appConfirm(`Delete ${fileName} from this pension file record?`, {
          title: "Delete Registry Document",
          confirmText: "Delete",
          cancelText: "Cancel",
          danger: true
        });
        if (!confirmed) return;
        await deleteRegistryDocument(documentId);
      });
    });
  }

  async function loadRegistryDocuments() {
    const context = getRegistryDocumentContext();
    if (!context.regNo && !context.staffdueId) {
      renderCreateDocumentsPlaceholder();
      return;
    }

    if (!editDocumentsList) return;
    editDocumentsList.innerHTML = '<div class="app-state-message app-state-neutral">Loading documents...</div>';
    try {
      const params = new URLSearchParams();
      if (context.staffdueId > 0) {
        params.set("staffId", String(context.staffdueId));
      } else {
        params.set("regNo", context.regNo);
      }
      const response = await fetch(`../backend/api/get_staff_documents.php?${params.toString()}`, {
        credentials: "include",
        cache: "no-store"
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error(data.message || "Unable to load linked documents.");
      }
      renderEditDocuments(Array.isArray(data.documents) ? data.documents : []);
      loadRegistryValidationStates();
    } catch (error) {
      console.error("Registry documents load failed:", error);
      editDocumentsList.innerHTML = `<div class="app-state-message app-state-error">${escapeHtml(error.message || "Unable to load linked documents.")}</div>`;
      loadRegistryValidationStates();
    }
  }

  async function saveRegistryDocument() {
    if (registryFormMode !== "edit" || !canEditRegistry()) {
      showFeedbackModal("error", "Access Denied", "You need registry edit rights to manage uploaded documents.");
      return;
    }

    const context = getRegistryDocumentContext();
    if (!context.id || !context.regNo) {
      showFeedbackModal("error", "Registry Record Required", "Save or reopen the registry record first before managing its documents.");
      return;
    }

    const documentId = Number(registryDocumentTargetId?.value || 0);
    const docType = String(registryDocumentType?.value || "").trim();
    const file = registryDocumentFile?.files?.[0] || null;

    if (!docType) {
      showFeedbackModal("error", "Validation Error", "Document type is required.");
      registryDocumentType?.focus();
      return;
    }
    if (!documentId && !file) {
      showFeedbackModal("error", "Validation Error", "Choose a file to upload.");
      registryDocumentFile?.focus();
      return;
    }
    if (documentId && !file && registryDocumentType?.value.trim() === "") {
      showFeedbackModal("error", "Validation Error", "Provide a document type or replacement file.");
      return;
    }

    const formData = new FormData();
    formData.append("registry_id", String(context.id));
    formData.append("regNo", context.regNo);
    formData.append("doc_type", docType);
    if (documentId > 0) {
      formData.append("document_id", String(documentId));
    }
    if (file) {
      formData.append("document", file);
    }

    const endpoint = documentId > 0
      ? "../backend/api/update_registry_document.php"
      : "../backend/api/upload_registry_document.php";

    setRegistryDocumentBusy(true, documentId > 0 ? "update" : "upload");
    try {
      const response = await fetch(endpoint, {
        method: "POST",
        credentials: "include",
        body: formData
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error(data.message || "Unable to save document.");
      }

      resetRegistryDocumentDraft();
      await loadRegistryDocuments();
      showFeedbackModal("success", documentId > 0 ? "Document Updated" : "Document Uploaded", data.message || (documentId > 0 ? "Document updated successfully." : "Document uploaded successfully."));
    } catch (error) {
      console.error("Registry document save failed:", error);
      showFeedbackModal("error", documentId > 0 ? "Update Failed" : "Upload Failed", error.message || "Unable to save document.");
    } finally {
      setRegistryDocumentBusy(false);
    }
  }

  async function deleteRegistryDocument(documentId) {
    if (registryFormMode !== "edit" || !canEditRegistry()) {
      showFeedbackModal("error", "Access Denied", "You need registry edit rights to delete linked documents.");
      return;
    }

    try {
      const response = await fetch("../backend/api/delete_registry_document.php", {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ document_id: Number(documentId) })
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error(data.message || "Unable to delete document.");
      }
      resetRegistryDocumentDraft();
      await loadRegistryDocuments();
      showFeedbackModal("success", "Document Deleted", data.message || "Document deleted successfully.");
    } catch (error) {
      console.error("Registry document delete failed:", error);
      showFeedbackModal("error", "Delete Failed", error.message || "Unable to delete document.");
    }
  }

  function setDeleteQueueBadge(count) {
    if (!deleteQueueCountEl) return;
    const pending = Number.isFinite(Number(count)) ? Math.max(0, Number(count)) : 0;
    if (pending <= 0) {
      deleteQueueCountEl.textContent = "0";
      deleteQueueCountEl.classList.add("hidden");
      return;
    }
    deleteQueueCountEl.textContent = pending > 99 ? "99+" : String(pending);
    deleteQueueCountEl.classList.remove("hidden");
  }

  async function refreshDeleteQueueBadge() {
    if (!canProcessDeleteQueue()) {
      setDeleteQueueBadge(0);
      return;
    }
    try {
      const res = await fetch("../backend/api/get_file_registry_delete_requests.php", {
        credentials: "include",
        cache: "no-store"
      });
      const data = await res.json();
      if (!res.ok || !data.success || !Array.isArray(data.requests)) {
        setDeleteQueueBadge(0);
        return;
      }
      setDeleteQueueBadge(data.requests.length);
    } catch (error) {
      console.error("Delete queue badge refresh failed:", error);
      setDeleteQueueBadge(0);
    }
  }

  function applyRegistryPayload(data) {
    populateSelectOptions(boxNumberFilter, data.boxNumberOptions || [], "All Box Numbers");

    totalRecords = Number(data.totalRecords || 0);
    totalPages = Number(data.totalPages || 1);
    currentPage = Number(data.page || 1);
    updateSummary(totalRecords, currentPage, totalPages);

    if (!Array.isArray(data.records) || !data.records.length) {
      grid.innerHTML = '<div class="app-state-message app-state-neutral">No pension registry records found.</div>';
      return;
    }

    grid.innerHTML = data.records.map((record) => renderCard(record)).join("");
    bindCardActions(data.records);
  }

  async function loadCards() {
    if (!grid) return;
    if (activeCardsController) {
      activeCardsController.abort();
    }
    const requestSeq = ++cardsRequestSeq;
    activeCardsController = new AbortController();

    // Keep heavy filtering, sorting, and pagination on the server for scale.
    const params = new URLSearchParams({
      page: String(currentPage),
      limit: String(pageSize),
      search: (searchInput?.value || "").trim(),
      box_number: (boxNumberFilter?.value || "").trim(),
      availability: availabilityFilter?.value || "",
      pay_type: payTypeFilter?.value || "",
      sort: sortSelect?.value || "recent"
    });
    const cacheKey = params.toString();
    const cachedPayload = getRegistryCachedPayload(cacheKey);
    if (cachedPayload) {
      applyRegistryPayload(cachedPayload);
    } else {
      grid.innerHTML = '<div class="app-state-message app-state-neutral">Loading pension file registry...</div>';
    }

    try {
      const res = await fetch(`../backend/api/fetch_file_registry.php?${params.toString()}`, {
        credentials: "include",
        cache: "no-store",
        signal: activeCardsController.signal
      });
      const data = await res.json();
      if (requestSeq !== cardsRequestSeq) {
        return;
      }
      if (!res.ok || !data.success) {
        grid.innerHTML = `<div class="app-state-message app-state-error">${escapeHtml(data.message || "Unable to load registry records.")}</div>`;
        updateSummary(0, currentPage, 1);
        return;
      }

      setRegistryCachedPayload(cacheKey, data);
      applyRegistryPayload(data);
    } catch (err) {
      if (err?.name === "AbortError") {
        return;
      }
      console.error("Registry load failed:", err);
      updateSummary(0, 1, 1);
      grid.innerHTML = '<div class="app-state-message app-state-error">Unable to load registry records.</div>';
    } finally {
      if (requestSeq === cardsRequestSeq) {
        activeCardsController = null;
      }
    }
  }

  async function fetchRegistryFileSuggestions(query, limit = 12) {
    const phrase = String(query || "").trim();
    if (!phrase) return [];
    try {
      const res = await fetch(`../backend/api/search_registry_files.php?q=${encodeURIComponent(phrase)}&limit=${encodeURIComponent(String(limit))}`, {
        credentials: "include",
        cache: "no-store"
      });
      const data = await res.json();
      if (!res.ok || !data.success || !Array.isArray(data.files)) {
        return [];
      }
      return data.files;
    } catch (error) {
      console.error("Life certificate file lookup failed:", error);
      return [];
    }
  }

  function setLifeCertProfileHint(message = "") {
    if (!lifeCertProfileHint) return;
    lifeCertProfileHint.textContent = message || "Select a file number to load details.";
  }

  function setLifeCertProfileEditable(editable) {
    const isEditable = Boolean(editable);
    lifeCertProfileEditable = isEditable;
    const fields = [
      lifeCertPhone,
      lifeCertAddress,
      lifeCertNok,
      lifeCertNokContact,
      lifeCertBankName,
      lifeCertBankAccount,
      lifeCertBankBranch
    ];
    fields.forEach((field) => {
      if (!field) return;
      if (field === lifeCertBankName) {
        field.disabled = !isEditable;
      } else {
        field.readOnly = !isEditable;
      }
      if (field === lifeCertAddress) {
        syncDistrictState(field, !isEditable);
      }
    });
    if (lifeCertEditBtn) {
      lifeCertEditBtn.textContent = isEditable ? "Save Changes" : "Edit Details";
    }
  }

  function clearLifeCertProfile() {
    if (lifeCertPensionerName) {
      lifeCertPensionerName.value = "";
    }

    const fields = [
      lifeCertPhone,
      lifeCertAddress,
      lifeCertNok,
      lifeCertNokContact,
      lifeCertBankName,
      lifeCertBankAccount,
      lifeCertBankBranch
    ];
    fields.forEach((field) => {
      if (!field) return;
      if (field === lifeCertAddress) {
        setDistrictValue(field, "");
        return;
      }
      if (field === lifeCertBankName) {
        setLifeCertBankValue("");
        return;
      }
      field.value = "";
    });
    lifeCertProfileRecordId = 0;
    lifeCertProfileLoadedRegNo = "";
    setLifeCertProfileEditable(false);
  }

  function fillLifeCertProfile(record, options = {}) {
    const safe = record || {};
    const preserveEditable = Boolean(options.preserveEditable);
    lifeCertProfileRecordId = Number(safe.id || 0);
    if (lifeCertPensionerName) {
      const displayName = String(safe.pensioner_name || safe.name || "").trim();
      lifeCertPensionerName.value = displayName;
    }
    if (lifeCertPhone) lifeCertPhone.value = String(safe.telNo || safe.phone || "").trim();
    setDistrictValue(lifeCertAddress, String(safe.address || "").trim());
    if (lifeCertNok) lifeCertNok.value = String(safe.next_of_kin || "").trim();
    if (lifeCertNokContact) lifeCertNokContact.value = String(safe.next_of_kin_contact || "").trim();
    setLifeCertBankValue(String(safe.bank_name || "").trim());
    if (lifeCertBankAccount) lifeCertBankAccount.value = String(safe.bank_account || "").trim();
    if (lifeCertBankBranch) lifeCertBankBranch.value = String(safe.bank_branch || "").trim();
    if (!preserveEditable) {
      setLifeCertProfileEditable(false);
    }
  }

  async function loadLifeCertProfile(regNoInputValue, options = {}) {
    const force = Boolean(options.force);
    const regNo = String(regNoInputValue || lifeCertRegNo?.value || "").trim();
    if (!regNo) {
      clearLifeCertProfile();
      setLifeCertProfileHint("Select a file number to load details.");
      return false;
    }

    if (!force && lifeCertProfileRecordId > 0 && regNo === lifeCertProfileLoadedRegNo) {
      return true;
    }

    if (lifeCertProfileLoadPromise && lifeCertProfileLoadingRegNo === regNo) {
      return lifeCertProfileLoadPromise;
    }

    const requestSeq = ++lifeCertProfileRequestSeq;
    lifeCertProfileLoadingRegNo = regNo;
    setLifeCertProfileHint("Loading beneficiary details...");
    // Guard against stale responses: only the latest request may update fields.
    lifeCertProfileLoadPromise = (async () => {
      try {
        const res = await fetch(`../backend/api/get_registry_contact_profile.php?regNo=${encodeURIComponent(regNo)}`, {
          credentials: "include",
          cache: "no-store"
        });
        const data = await res.json();
        if (requestSeq !== lifeCertProfileRequestSeq) {
          return false;
        }
        if (!res.ok || !data.success || !data.record) {
          clearLifeCertProfile();
          setLifeCertProfileHint(data.message || "No beneficiary details found for this file.");
          return false;
        }

        fillLifeCertProfile(data.record);
        lifeCertProfileLoadedRegNo = regNo;
        setLifeCertProfileHint("Beneficiary details loaded. Click Edit Details to update if needed.");
        return true;
      } catch (error) {
        if (requestSeq !== lifeCertProfileRequestSeq) {
          return false;
        }
        console.error("Unable to load life certificate profile:", error);
        clearLifeCertProfile();
        setLifeCertProfileHint("Unable to load beneficiary details.");
        return false;
      } finally {
        if (requestSeq === lifeCertProfileRequestSeq) {
          lifeCertProfileLoadingRegNo = "";
          lifeCertProfileLoadPromise = null;
        }
      }
    })();

    return lifeCertProfileLoadPromise;
  }

  async function saveLifeCertProfileChanges(showSuccessFeedback = true) {
    const regNo = String(lifeCertRegNo?.value || "").trim();
    if (!regNo) {
      showFeedbackModal("error", "Validation Error", "File number is required before saving details.");
      return false;
    }

    const payload = {
      regNo,
      telNo: String(lifeCertPhone?.value || "").trim(),
      address: String(lifeCertAddress?.value || "").trim(),
      next_of_kin: String(lifeCertNok?.value || "").trim(),
      next_of_kin_contact: String(lifeCertNokContact?.value || "").trim(),
      bank_name: String(lifeCertBankName?.value || "").trim(),
      bank_account: String(lifeCertBankAccount?.value || "").trim(),
      bank_branch: String(lifeCertBankBranch?.value || "").trim()
    };

    try {
      if (lifeCertEditBtn) lifeCertEditBtn.disabled = true;
      const res = await fetch("../backend/api/update_registry_contact_profile.php", {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
      });
      const data = await res.json();
      if (!res.ok || !data.success) {
        showFeedbackModal("error", "Save Failed", data.message || "Unable to save beneficiary details.");
        return false;
      }

      if (showSuccessFeedback) {
        showFeedbackModal("success", "Saved", data.message || "Beneficiary details updated.");
      }
      setLifeCertProfileEditable(false);
      setLifeCertProfileHint("Beneficiary details saved.");
      await loadCards();
      return true;
    } catch (error) {
      console.error("Unable to save life certificate profile:", error);
      showFeedbackModal("error", "Save Failed", "Unable to save beneficiary details.");
      return false;
    } finally {
      if (lifeCertEditBtn) lifeCertEditBtn.disabled = false;
    }
  }

  function setLifeCertDefaults(seedRecord = null) {
    if (lifeCertYear) {
      lifeCertYear.value = String(new Date().getFullYear());
    }
    if (lifeCertNotes) {
      lifeCertNotes.value = "";
    }
    clearLifeCertProfile();
    setLifeCertProfileHint("Select a file number to load details.");
    if (lifeCertRegNo) {
      const seeded = String(seedRecord?.regNo || currentDetailsRecord?.regNo || "").trim();
      lifeCertRegNo.value = seeded || "";
      if (seeded) {
        loadLifeCertProfile(seeded);
      }
    }
  }

  function openLifeCertQuickAction(seedRecord = null) {
    if (!canManageLifeCertificates()) {
      showFeedbackModal("error", "Access Denied", "You do not have permission to mark life certificate submissions.");
      return;
    }

    setLifeCertDefaults(seedRecord || null);
    openModal(lifeCertModal);
    if (lifeCertRegNo) {
      lifeCertRegNo.focus();
      lifeCertRegNo.select();
    }
  }

  async function submitLifeCertificateFromRegistry() {
    if (!canManageLifeCertificates()) {
      showFeedbackModal("error", "Access Denied", "You do not have permission to mark life certificate submissions.");
      return;
    }

    const regNo = String(lifeCertRegNo?.value || "").trim();
    const year = Number(lifeCertYear?.value || new Date().getFullYear());
    const notes = String(lifeCertNotes?.value || "").trim();

    if (!regNo) {
      showFeedbackModal("error", "Validation Error", "File number is required.");
      return;
    }

    if (!Number.isInteger(year) || year < 2000 || year > 2100) {
      showFeedbackModal("error", "Validation Error", "Enter a valid submission year.");
      return;
    }

    try {
      if (lifeCertProfileEditable) {
        const saved = await saveLifeCertProfileChanges(false);
        if (!saved) {
          return;
        }
      }
      if (lifeCertSubmitBtn) lifeCertSubmitBtn.disabled = true;
      const res = await fetch("../backend/api/mark_life_certificate_submission.php", {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ regNo, year, notes })
      });
      const data = await res.json();
      if (!res.ok || !data.success) {
        showFeedbackModal("error", "Submission Failed", data.message || "Unable to mark life certificate submission.");
        return;
      }

      closeModal(lifeCertModal);
      showFeedbackModal("success", "Submitted", data.message || "Life certificate marked as submitted.");
      await loadCards();

      if (detailsModal && !detailsModal.classList.contains("hidden") && currentDetailsRecord?.id) {
        await openDetailsModal(Number(currentDetailsRecord.id));
      }
    } catch (error) {
      console.error("Life certificate submission failed:", error);
      showFeedbackModal("error", "Submission Failed", "Unable to mark life certificate submission.");
    } finally {
      if (lifeCertSubmitBtn) lifeCertSubmitBtn.disabled = false;
    }
  }

  function updateSummary(total, page, pages) {
    renderPagination(page || 1, pages || 1, total || 0);
  }

  function renderPagination(page, pages, total) {
    if (!registryPagination || !registryPaginationSummary || !registryPaginationControls) {
      return;
    }

    if (!total || pages <= 0) {
      registryPagination.hidden = true;
      registryPaginationSummary.textContent = "";
      registryPaginationControls.innerHTML = "";
      return;
    }

    registryPagination.hidden = false;
    const startItem = ((page - 1) * pageSize) + 1;
    const endItem = Math.min(page * pageSize, total);
    registryPaginationSummary.textContent = `Showing ${startItem}-${endItem} of ${Number(total).toLocaleString()} records`;

    const buttons = buildPaginationButtons(page, pages);
    registryPaginationControls.innerHTML = `
      <button type="button" class="registry-page-btn registry-page-nav" data-page-nav="prev" ${page <= 1 ? "disabled" : ""}>Previous</button>
      ${buttons.map((item) => item === "ellipsis"
        ? '<button type="button" class="registry-page-btn" aria-hidden="true" disabled>…</button>'
        : `<button type="button" class="registry-page-btn ${item === page ? "is-active" : ""}" data-page-number="${item}">${item}</button>`
      ).join("")}
      <button type="button" class="registry-page-btn registry-page-nav" data-page-nav="next" ${page >= pages ? "disabled" : ""}>Next</button>
    `;

    registryPaginationControls.querySelectorAll("[data-page-number]").forEach((button) => {
      button.addEventListener("click", () => {
        const targetPage = Number(button.getAttribute("data-page-number") || page);
        if (!targetPage || targetPage === currentPage) return;
        currentPage = targetPage;
        loadCards();
        scrollRegistryGridIntoView();
      });
    });

    registryPaginationControls.querySelectorAll("[data-page-nav]").forEach((button) => {
      button.addEventListener("click", () => {
        const direction = button.getAttribute("data-page-nav");
        if (direction === "prev" && currentPage > 1) {
          currentPage -= 1;
        } else if (direction === "next" && currentPage < totalPages) {
          currentPage += 1;
        } else {
          return;
        }
        loadCards();
        scrollRegistryGridIntoView();
      });
    });
  }

  function buildPaginationButtons(page, pages) {
    if (pages <= 7) {
      return Array.from({ length: pages }, (_, index) => index + 1);
    }

    const pageSet = new Set([1, pages, page, page - 1, page + 1]);
    if (page <= 3) {
      pageSet.add(2);
      pageSet.add(3);
      pageSet.add(4);
    }
    if (page >= pages - 2) {
      pageSet.add(pages - 1);
      pageSet.add(pages - 2);
      pageSet.add(pages - 3);
    }

    const sortedPages = Array.from(pageSet)
      .filter((value) => value >= 1 && value <= pages)
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

  function scrollRegistryGridIntoView() {
    if (!grid) return;
    const prefersReducedMotion = window.matchMedia?.("(prefers-reduced-motion: reduce)")?.matches;
    grid.scrollIntoView({
      behavior: prefersReducedMotion ? "auto" : "smooth",
      block: "start"
    });
  }

  function renderCard(record) {
    const nameCore = `${record.sName || ""} ${record.fName || ""}`.trim();
    const formattedName = nameCore;
    const fallbackName = String(record.name || "").trim();
    const displayName = escapeHtml(formattedName || fallbackName || "Unknown");
    const normalizedPayType = normalizePayType(record.payType || "");
    const payTypeClass = normalizedPayType === "One-off Payment"
      ? "registry-pay-type-chip registry-pay-type-oneoff"
      : "registry-pay-type-chip registry-pay-type-pensioner";
    const fileNo = escapeHtml(record.regNo || "N/A");
    const rank = escapeHtml(record.title || "N/A");
    const retirementType = escapeHtml(formatRetirementTypeLabel(record.retirementType));
    const retirementDate = escapeHtml(formatDateBadge(record.retirementDate));
    const payrollStatus = normalizePayrollStatus(record.payrollStatus);
    const payrollBadgeClass = payrollStatus === "On Payroll" ? "registry-payroll-on" : "registry-payroll-off";
    const boxNo = escapeHtml(record.boxNo || "N/A");
    const phone = normalizePhoneForDial(record.phone || "");
    const statusClass = (record.availability_status || "in_shelf") === "out_of_shelf" ? "registry-status-out" : "registry-status-in";
    const statusText = formatAvailability(record.availability_status || "in_shelf");
    const cardAvailabilityClass = (record.availability_status || "in_shelf") === "out_of_shelf"
      ? "registry-card-out"
      : "registry-card-in";
    const cardPayrollClass = payrollStatus === "On Payroll" ? "registry-card-payroll-on" : "registry-card-payroll-off";
    const editBtn = canEditRegistry()
      ? `<button class="registry-action-btn secondary" data-action="edit" data-id="${Number(record.id)}">Edit</button>`
      : "";
    const deleteBtn = canRequestRegistryDelete()
      ? `<button class="registry-action-btn secondary" data-action="delete-request" data-id="${Number(record.id)}" data-reg="${escapeHtml(record.regNo || "")}" data-title="${escapeHtml(record.title || "")}" data-name="${displayName}">Delete</button>`
      : "";
    const lifeCertQuickBtn = canManageLifeCertificates()
      ? `<button class="registry-action-btn secondary" data-action="life-cert" data-id="${Number(record.id)}">Life Cert</button>`
      : "";
    const callBtn = phone
      ? `<a class="registry-action-link success mobile-only-call" data-action="call" href="tel:${escapeHtml(phone)}">Call</a>`
      : `<button class="registry-action-btn success mobile-only-call" data-action="call" disabled>No Phone</button>`;

    return `
      <article class="registry-card ${cardAvailabilityClass} ${cardPayrollClass}">
        <div class="registry-card-name-row">
          <div class="registry-card-name" title="${displayName}">${displayName}</div>
          <span class="${payTypeClass}">(${escapeHtml(normalizedPayType)})</span>
        </div>
        <div class="registry-card-line registry-card-line-top">
          <span class="registry-card-line-text">${fileNo} - ${rank}</span>
          <span class="registry-payroll-badge ${payrollBadgeClass}">${escapeHtml(payrollStatus)}</span>
        </div>
        <div class="registry-card-meta-row registry-card-retirement-row">
          <span class="registry-card-line"><span class="registry-card-label">Retirement:</span> ${retirementType}</span>
          <span class="registry-card-status registry-retirement-date-badge">${retirementDate}</span>
        </div>
        <div class="registry-card-meta-row">
          <span class="registry-card-box"><span class="registry-card-label">Box:</span> ${boxNo}</span>
          <span class="registry-card-status ${statusClass}">${escapeHtml(statusText)}</span>
        </div>
        <div class="registry-card-actions">
          <button class="registry-action-btn" data-action="details" data-id="${Number(record.id)}">Details</button>
          ${editBtn}
          ${deleteBtn}
          ${lifeCertQuickBtn}
          ${callBtn}
        </div>
      </article>
    `;
  }

  function bindCardActions(records) {
    grid.querySelectorAll("[data-action='details']").forEach((btn) => {
      btn.addEventListener("click", async () => {
        const id = Number(btn.getAttribute("data-id"));
        if (!id) return;
        await openDetailsModal(id);
      });
    });

    grid.querySelectorAll("[data-action='edit']").forEach((btn) => {
      btn.addEventListener("click", async () => {
        const id = Number(btn.getAttribute("data-id"));
        if (!id) return;
        await openEditModal(id);
      });
    });

    grid.querySelectorAll("[data-action='delete-request']").forEach((btn) => {
      btn.addEventListener("click", async () => {
        const id = Number(btn.getAttribute("data-id"));
        const regNo = String(btn.getAttribute("data-reg") || "");
        const title = String(btn.getAttribute("data-title") || "");
        const name = String(btn.getAttribute("data-name") || "");
        if (!id) return;
        await queueDeleteRequest(id, regNo, title, name);
      });
    });

    grid.querySelectorAll("[data-action='life-cert']").forEach((btn) => {
      btn.addEventListener("click", () => {
        const id = Number(btn.getAttribute("data-id"));
        const record = records.find((row) => Number(row.id) === id) || null;
        if (record) {
          currentDetailsRecord = record;
        }
        openLifeCertQuickAction(record);
      });
    });

    grid.querySelectorAll("[data-action='call']").forEach((el) => {
      const href = el.getAttribute("href") || "";
      if (!href) return;
      el.addEventListener("click", () => {
        // keep native tel: behavior
      });
    });
  }

  async function fetchRecordDetails(id) {
    const res = await fetch(`../backend/api/get_file_registry_record.php?id=${encodeURIComponent(id)}`, {
      credentials: "include",
      cache: "no-store"
    });
    const data = await res.json();
    if (!res.ok || !data.success || !data.record) {
      throw new Error(data.message || "Unable to load registry details.");
    }
    return {
      record: data.record,
      documents: Array.isArray(data.documents) ? data.documents : []
    };
  }

  async function openDetailsModal(id) {
    try {
      const detailsPayload = await fetchRecordDetails(id);
      const record = detailsPayload.record;
      const documents = detailsPayload.documents;
      currentDetailsRecord = record;
      const canEdit = canEditRegistry();
      detailsBody.innerHTML = buildDetailsSections(record, documents, canEdit);
      bindDetailsTabs();
      const inlineEditBtn = document.getElementById("registryInlineEditBtn");
      if (inlineEditBtn && canEdit) {
        inlineEditBtn.addEventListener("click", () => {
          closeModal(detailsModal);
          openEditModal(Number(record.id));
        });
      }
      if (detailsEditBtn) {
        detailsEditBtn.classList.toggle("hidden", !canEdit);
        detailsEditBtn.onclick = canEdit ? (() => {
          closeModal(detailsModal);
          openEditModal(Number(record.id));
        }) : null;
      }
      if (detailsDeleteBtn) {
        const canDeleteRequest = canRequestRegistryDelete();
        detailsDeleteBtn.classList.toggle("hidden", !canDeleteRequest);
        const deleteName = formatTitleName(String(record.title || "").trim(), `${record.sName || ""} ${record.fName || ""}`.trim()) || String(record.name || "").trim();
        detailsDeleteBtn.onclick = canDeleteRequest ? (() => queueDeleteRequest(Number(record.id), String(record.regNo || ""), String(record.title || ""), deleteName)) : null;
      }
      if (detailsLifeCertBtn) {
        const canUseLifeCert = canManageLifeCertificates();
        detailsLifeCertBtn.classList.toggle("hidden", !canUseLifeCert);
        detailsLifeCertBtn.onclick = canUseLifeCert ? (() => {
          closeModal(detailsModal);
          openLifeCertQuickAction(record);
        }) : null;
      }
      openModal(detailsModal);
    } catch (err) {
      console.error(err);
      showFeedbackModal("error", "Load Failed", err.message || "Unable to load details.");
    }
  }

  function buildDetailsSections(record, documents, canEdit) {
    const identityFields = [
      ["File Number", record.regNo],
      ["Computer Number", record.computerNo],
      ["Supplier Number", record.supplierNo],
      ["Title/Rank", record.title],
      ["Surname", record.sName],
      ["First Name", record.fName],
      ["Gender", record.gender],
      ["Station", record.station],
      ["Phone", record.telNo || record.phone],
      ["NIN", record.NIN],
      ["TIN", record.TIN],
      ["District of Residence", record.address]
    ];

    const serviceFields = [
      ["Date of Birth", record.birthDate],
      ["Date of Enlistment", record.enlistmentDate],
      ["Date of Retirement", record.retirementDate],
      ["Retirement Type", formatRetirementTypeLabel(record.retirementType)],
      ["Pay Type", record.payType],
      ["Living Status", record.livingStatus],
      ["Life Certificate", record.lifeCertificateStatus || record.lifeCertificate || "Not Submitted"]
    ];

    const benefitsFields = [
      ["Monthly Salary", formatCurrency(record.monthlySalary)],
      ["Length of Service (Months)", record.lengthOfService],
      ["Annual Salary", formatCurrency(record.annualSalary)],
      ["Reduced Pension", formatCurrency(record.reducedPension)],
      ["Full Pension", formatCurrency(record.fullPension)],
      ["Commuted Gratuity", formatCurrency(record.gratuity)]
    ];

    const bankingFields = [
      ["Applicant Email", record.applicant_email],
      ["Next of Kin", record.next_of_kin],
      ["Next of Kin Contact", record.next_of_kin_contact],
      ["Bank Name", record.bank_name],
      ["Bank Account", record.bank_account],
      ["Bank Branch", record.bank_branch]
    ];

    const registryFields = [
      ["Box Number", record.boxNo],
      ["Payroll Status", record.payrollStatus],
      ["Availability", formatAvailability(record.availability_status)],
      ["Availability Reason", record.availability_reason],
      ["Date On 15 Years", record.dateOn15yrs],
      ["Period To 15 Years", record.periodTo15yrs],
      ["Period From 15 Years", record.periodFrom15yrs],
      ["Created", record.timeStamp]
    ];

    // Tabbed grouping exposes complete record context without turning the
    // details modal into a single long scroll block.
    const inlineEdit = canEdit
      ? `<button type="button" id="registryInlineEditBtn" class="registry-action-btn secondary details-inline-edit">Edit Record</button>`
      : "";
    const tabs = [
      ["identity", "Identity Profile"],
      ["service", "Service Profile"],
      ["benefits", "Benefits Snapshot"],
      ["contact", "Contact & Banking"],
      ["registry", "Registry Tracking"],
      ["documents", `Uploaded Documents (${Array.isArray(documents) ? documents.length : 0})`]
    ];

    return `
      <div class="details-top-actions">${inlineEdit}</div>
      <div class="registry-details-tabs" role="tablist" aria-label="File detail sections">
        ${tabs.map((tab, idx) => `
          <button
            type="button"
            class="registry-details-tab ${idx === 0 ? "is-active" : ""}"
            data-details-tab="${escapeHtml(tab[0])}"
            role="tab"
            aria-selected="${idx === 0 ? "true" : "false"}"
          >${escapeHtml(tab[1])}</button>
        `).join("")}
      </div>
      <div class="registry-details-panels">
        ${renderDetailsPanel("identity", identityFields, true)}
        ${renderDetailsPanel("service", serviceFields, false)}
        ${renderDetailsPanel("benefits", benefitsFields, false)}
        ${renderDetailsPanel("contact", bankingFields, false)}
        ${renderDetailsPanel("registry", registryFields, false, ["Availability", "Date On 15 Years", "Period From 15 Years"])}
        ${renderDocumentsPanel(documents, false)}
      </div>
    `;
  }

  function renderDetailsPanel(key, fields, isActive, importantLabels = []) {
    const importantSet = new Set((importantLabels || []).map((item) => String(item).toLowerCase()));
    const content = fields.map(([label, value]) => {
      const importantClass = importantSet.has(String(label).toLowerCase()) ? "details-item-important" : "";
      return `
        <div class="details-item ${importantClass}">
          <span>${escapeHtml(label)}</span>
          <strong>${escapeHtml(formatDisplay(value))}</strong>
        </div>
      `;
    }).join("");

    return `
      <section class="registry-details-panel-group ${isActive ? "is-active" : ""}" data-details-panel="${escapeHtml(key)}" role="tabpanel">
        <div class="details-grid">${content}</div>
      </section>
    `;
  }

  function renderDocumentsPanel(documents, isActive) {
    if (!Array.isArray(documents) || documents.length === 0) {
      return `
        <section class="registry-details-panel-group ${isActive ? "is-active" : ""}" data-details-panel="documents" role="tabpanel">
          <div class="app-state-message app-state-neutral">No documents uploaded.</div>
        </section>
      `;
    }

    const rows = documents.map((doc) => {
      const documentId = Number(doc.document_id || 0);
      const fileLabel = doc.file_name || "Document";
      const fileUrl = documentId > 0
        ? buildViewerUrl(
          `../backend/api/view_staff_document.php?document_id=${documentId}`,
          fileLabel,
          {
            page: "pension_file_registry",
            modal: "details",
            recordId: Number(currentDetailsRecord?.id || 0),
            tab: getActiveDetailsTabKey()
          }
        )
        : "#";
      return `
        <article class="registry-doc-item">
          <div class="registry-doc-copy">
            <strong>${escapeHtml(doc.doc_type || "Document")}</strong>
            <small>${escapeHtml(fileLabel)} - ${escapeHtml(formatDisplay(doc.uploaded_at))}</small>
          </div>
          <a class="registry-action-link secondary" href="${escapeHtml(fileUrl)}">Open</a>
        </article>
      `;
    }).join("");

    return `
      <section class="registry-details-panel-group ${isActive ? "is-active" : ""}" data-details-panel="documents" role="tabpanel">
        <div class="registry-doc-list">${rows}</div>
      </section>
    `;
  }

  function bindDetailsTabs() {
    const tabButtons = Array.from(detailsBody.querySelectorAll(".registry-details-tab"));
    const panels = Array.from(detailsBody.querySelectorAll(".registry-details-panel-group"));
    if (!tabButtons.length || !panels.length) return;

    const activate = (tabKey) => {
      tabButtons.forEach((btn) => {
        const active = btn.getAttribute("data-details-tab") === tabKey;
        btn.classList.toggle("is-active", active);
        btn.setAttribute("aria-selected", active ? "true" : "false");
      });
      panels.forEach((panel) => {
        const active = panel.getAttribute("data-details-panel") === tabKey;
        panel.classList.toggle("is-active", active);
      });
    };

    tabButtons.forEach((btn) => {
      btn.addEventListener("click", () => {
        const key = btn.getAttribute("data-details-tab");
        activate(key);
      });
    });
  }

  async function openEditModal(id) {
    if (!canEditRegistry()) {
      showFeedbackModal("error", "Access Denied", "You do not have permission to edit registry records.");
      return;
    }

    try {
      registrySubmitAttempted = false;
      registryTouchedFields.clear();
      const detailsPayload = await fetchRecordDetails(id);
      const record = detailsPayload.record;
      setRegistryFormMode("edit");
      currentEditRecordContext = {
        id: Number(record.id || id || 0),
        regNo: String(record.regNo || "").trim(),
        staffdueId: Number(record.staffdue_id || 0)
      };
      editRecordId.value = String(record.id || "");
      editRegNo.value = record.regNo || "";
      editComputerNo.value = record.computerNo || "";
      editSupplierNo.value = record.supplierNo || "";
      setRegistryTitleValue(record.title || "");
      editBoxNo.value = record.boxNo || "";
      editSName.value = record.sName || "";
      editFName.value = record.fName || "";
      editGender.value = record.gender || "";
      editLivingStatus.value = record.livingStatus || "";
      editLifeCertificate.value = record.lifeCertificateStatus || record.lifeCertificate || "Not Submitted";
      editBirthDate.value = record.birthDate || "";
      editEnlistmentDate.value = record.enlistmentDate || "";
      editRetirementDate.value = record.retirementDate || "";
      Array.from(editRetirementType?.querySelectorAll('option[data-dynamic-retirement-type="true"]') || []).forEach((option) => option.remove());
      ensureRetirementTypeOption(record.retirementType || "");
      editRetirementType.value = normalizeRetirementTypeValue(record.retirementType || "");
      editNIN.value = normalizeNationalIdValue(record.NIN || "");
      editTIN.value = record.TIN || "";
      editPayrollStatus.value = record.payrollStatus || "Not on Payroll";
      editPayType.value = deriveRegistryPayTypeValue({
        retirementType: normalizeRetirementTypeValue(record.retirementType || ""),
        enlistmentDate: record.enlistmentDate || "",
        retirementDate: record.retirementDate || "",
        payType: record.payType || ""
      });
      editTelNo.value = record.telNo || record.phone || "";
      editApplicantEmail.value = record.applicant_email || "";
      editNextOfKin.value = record.next_of_kin || "";
      editNextOfKinContact.value = record.next_of_kin_contact || "";
      setRegistryBankValue(record.bank_name || "");
      editBankAccount.value = record.bank_account || "";
      editBankBranch.value = record.bank_branch || "";
      setMoneyInputValue(editMonthlySalary, record.monthlySalary || "");
      editLengthOfService.value = record.lengthOfService || "";
      setMoneyInputValue(editAnnualSalary, record.annualSalary || "");
      setMoneyInputValue(editReducedPension, record.reducedPension || "");
      setMoneyInputValue(editFullPension, record.fullPension || "");
      setMoneyInputValue(editGratuity, record.gratuity || "");
      recomputeRegistryBenefitFields();
      editDateOn15yrs.value = record.dateOn15yrs || "";
      editPeriodTo15yrs.value = record.periodTo15yrs || "";
      editPeriodFrom15yrs.value = record.periodFrom15yrs || "";
      editAvailabilityStatus.value = record.availability_status || "in_shelf";
      editAvailabilityReason.value = record.availability_reason || "";
      setDistrictValue(editAddress, record.address || "");
      editOther.value = record.other || "";
      applyBenefitsFieldPermissions();
      updateLivingStatusEditability();
      updateLifeCertificateEditability();
      updateNextOfKinRequirementUi();
      renderEditDocuments(detailsPayload.documents);
      setEditTab("identity");
      validateSelectedTitle(false);
      loadRegistryValidationStates();
      openModal(editModal);
    } catch (err) {
      console.error(err);
      showFeedbackModal("error", "Load Failed", err.message || "Unable to load edit form.");
    }
  }

  async function saveEditForm() {
    const isCreate = registryFormMode === "create";
    if (isCreate && !canEditRegistry()) {
      showFeedbackModal("error", "Access Denied", "Only users with registry edit rights can add a pension file directly.");
      return;
    }

    const id = Number(editRecordId.value || 0);
    if (!isCreate && !id) {
      showFeedbackModal("error", "Save Failed", "Invalid registry record.");
      return;
    }

    registrySubmitAttempted = true;
    loadRegistryValidationStates();

    const validationError = validateRegistryEditForm();
    if (validationError) {
      showRegistryValidationIssue(validationError);
      return;
    }

    if (!validateSelectedTitle(true)) {
      setEditTab("identity");
      focusRegistryTitleField();
      return;
    }

    const payload = buildRegistryFormPayload();
    const rawPhone = String(payload.telNo || "").trim();
    if (rawPhone !== "") {
      const normalizedPhone = normalizeEditablePhone(rawPhone);
      if (!normalizedPhone) {
        showFeedbackModal("error", "Validation Error", "Contact & Banking has an invalid phone number format.", () => {
          setEditTab("contact");
          editTelNo?.focus();
        });
        return;
      }
      payload.telNo = normalizedPhone;
      if (editTelNo) editTelNo.value = normalizedPhone;
    }

    const rawNextOfKinContact = String(payload.next_of_kin_contact || "").trim();
    if (rawNextOfKinContact !== "") {
      const normalizedNextOfKinContact = normalizeEditablePhone(rawNextOfKinContact);
      if (!normalizedNextOfKinContact) {
        showFeedbackModal("error", "Validation Error", "Contact & Banking has an invalid next of kin phone number format.", () => {
          setEditTab("contact");
          editNextOfKinContact?.focus();
        });
        return;
      }
      payload.next_of_kin_contact = normalizedNextOfKinContact;
      if (editNextOfKinContact) editNextOfKinContact.value = normalizedNextOfKinContact;
    }

    if (!isCreate) {
      payload.id = id;
      if (!canEditRegistryFileNumber()) {
        delete payload.regNo;
      }
    }

    if (isCreate && !payload.regNo) {
      showFeedbackModal("error", "Validation Error", "File number is required when creating a new pension file.");
      setEditTab("identity");
      editRegNo.focus();
      return;
    }

    const shouldValidateFileNumber = isCreate
      || (Object.prototype.hasOwnProperty.call(payload, "regNo")
        && String(payload.regNo || "").trim() !== String(currentEditRecordContext?.regNo || "").trim());
    if (shouldValidateFileNumber) {
      const fileNumberValidation = validateRegistryFileNumber(payload.regNo);
      if (!fileNumberValidation.valid) {
        if (editRegNo) {
          editRegNo.value = fileNumberValidation.normalized;
        }
        showFeedbackModal("error", "Validation Error", fileNumberValidation.message, () => {
          setEditTab("identity");
          editRegNo?.focus();
        });
        return;
      }
      payload.regNo = fileNumberValidation.normalized;
    }

    if (!payload.sName || !payload.fName) {
      showFeedbackModal("error", "Validation Error", "Surname and first name are required.");
      setEditTab("identity");
      return;
    }

    setRegistryFormBusy(true);
    try {
      const endpoint = isCreate
        ? "../backend/api/add_file_registry_record.php"
        : "../backend/api/update_file_registry_record.php";
      const res = await fetch(endpoint, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
      });
      const data = await res.json();
      if (!res.ok || !data.success) {
        showFeedbackModal(
          "error",
          isCreate ? "Create Failed" : "Save Failed",
          data.message || (isCreate ? "Unable to add pension file." : "Unable to update record.")
        );
        return;
      }

      closeModal(editModal);
      if (isCreate) {
        setRegistryFormMode("edit");
      }
      showFeedbackModal(
        "success",
        isCreate ? "File Added" : "Updated",
        data.message || (isCreate ? "Pension file added to the registry successfully." : "Registry record updated successfully.")
      );
      await loadCards();
    } catch (err) {
      console.error(err);
      const fallbackMessage = isCreate ? "Unable to add pension file." : "Unable to update record.";
      const resolvedMessage = String(err?.message || "").trim();
      showFeedbackModal(
        "error",
        isCreate ? "Create Failed" : "Save Failed",
        resolvedMessage || fallbackMessage
      );
    } finally {
      setRegistryFormBusy(false);
    }
  }

  async function queueDeleteRequest(registryId, regNo, staffTitle = "", staffName = "") {
    if (!canRequestRegistryDelete()) {
      showFeedbackModal("error", "Access Denied", "You do not have permission to request registry deletions.");
      return;
    }

    const targetLabel = String(regNo || "").trim() || "selected file";
    const applicantLabel = formatTitleName(String(staffTitle || "").trim(), String(staffName || "").trim());
    let reason = "";
    const directDeleteActor = canDeleteDirectlyByRole();

    if (directDeleteActor) {
      const confirmed = await appConfirm(
        `You are about to permanently delete ${targetLabel}${applicantLabel ? ` for ${applicantLabel}` : ""}. Continue?`,
        {
          title: "Confirm Direct Delete",
          confirmText: "Delete Now",
          cancelText: "Cancel",
          danger: true
        }
      );
      if (!confirmed) {
        return;
      }
      reason = "Direct privileged deletion";
    } else {
      const reasonPrompt = await appPrompt(
        `Enter the reason for deleting ${targetLabel}:`,
        "Duplicate or obsolete file record",
        {
          title: "Delete Request Reason",
          confirmText: "Submit Request"
        }
      );

      if (reasonPrompt === null) {
        return;
      }

      reason = String(reasonPrompt || "").trim();
      if (!reason) {
        showFeedbackModal("error", "Validation Error", "Delete reason is required.");
        return;
      }
    }

    // Deletion is approval-driven: requesters queue intent, approvers action it.
    try {
      const res = await fetch("../backend/api/request_file_registry_delete.php", {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          registry_id: Number(registryId),
          reason
        })
      });
      const data = await res.json();
      if (!res.ok || !data.success) {
        showFeedbackModal("error", "Request Failed", data.message || "Unable to process delete action.");
        return;
      }

      showFeedbackModal(
        "success",
        data.direct_delete ? "Deleted" : "Queued",
        data.message || (data.direct_delete ? "Registry record deleted successfully." : "Delete request submitted for approval.")
      );
      await loadCards();
      await refreshDeleteQueueBadge();
      if (deleteQueueModal && !deleteQueueModal.classList.contains("hidden")) {
        await loadDeleteQueue();
      }
    } catch (error) {
      console.error("Delete request failed:", error);
      showFeedbackModal("error", "Request Failed", "Unable to queue delete request.");
    }
  }

  async function loadDeleteQueue() {
    if (!deleteQueueBody) return;
    deleteQueueBody.innerHTML = '<div class="app-state-message app-state-neutral">Loading pending requests...</div>';
    if (deleteHistoryVisible && deleteHistoryBody) {
      deleteHistoryBody.innerHTML = '<div class="app-state-message app-state-neutral">Loading delete history...</div>';
    }

    try {
      const params = new URLSearchParams();
      if (deleteHistoryVisible) {
        params.set("include_history", "1");
      }
      const endpoint = `../backend/api/get_file_registry_delete_requests.php${params.toString() ? `?${params.toString()}` : ""}`;
      const res = await fetch(endpoint, {
        credentials: "include",
        cache: "no-store"
      });
      const data = await res.json();
      if (!res.ok || !data.success) {
        deleteQueueBody.innerHTML = `<div class="app-state-message app-state-error">${escapeHtml(data.message || "Unable to load delete requests.")}</div>`;
        return;
      }

      const rows = Array.isArray(data.requests) ? data.requests : [];
      if (rows.length === 0) {
        deleteQueueBody.innerHTML = '<div class="app-state-message app-state-neutral">No pending delete requests.</div>';
      } else {
        const canProcess = canProcessDeleteQueue();
        deleteQueueBody.innerHTML = `
          <div class="delete-request-list">
            ${rows.map((row) => renderDeleteQueueItem(row, canProcess)).join("")}
          </div>
        `;

        deleteQueueBody.querySelectorAll("[data-queue-action='approve'], [data-queue-action='reject']").forEach((button) => {
          button.addEventListener("click", async () => {
            const requestId = Number(button.getAttribute("data-request-id"));
            const action = String(button.getAttribute("data-queue-action") || "");
            if (!requestId || !action) return;
            await processDeleteRequest(requestId, action);
          });
        });
      }

      renderDeleteHistorySection(data);
    } catch (error) {
      console.error("Delete queue load failed:", error);
      deleteQueueBody.innerHTML = '<div class="app-state-message app-state-error">Unable to load delete requests.</div>';
      if (deleteHistoryVisible && deleteHistoryBody) {
        deleteHistoryBody.innerHTML = '<div class="app-state-message app-state-error">Unable to load delete history.</div>';
      }
    }
  }

  function renderDeleteHistorySection(data) {
    if (!deleteHistoryWrap || !deleteHistoryBody) return;
    deleteHistoryWrap.classList.toggle("hidden", !deleteHistoryVisible);
    if (toggleDeleteHistoryBtn) {
      toggleDeleteHistoryBtn.textContent = deleteHistoryVisible ? "Hide Deleted History" : "Show Deleted History";
    }
    if (!deleteHistoryVisible) {
      deleteHistoryBody.innerHTML = '<div class="app-state-message app-state-neutral">History is hidden.</div>';
      return;
    }

      const recycleRows = Array.isArray(data?.recycle_bin) ? data.recycle_bin : [];
      if (recycleRows.length === 0) {
      deleteHistoryBody.innerHTML = '<div class="app-state-message app-state-neutral">No delete history found.</div>';
      return;
    }

    deleteHistoryBody.innerHTML = `
      <section class="delete-history-section">
        <h4>Deleted Record History</h4>
        ${recycleRows.length ? `<div class="delete-request-list">${recycleRows.map((row) => renderRecycleBinItem(row)).join("")}</div>` : '<div class="app-state-message app-state-neutral">Recycle bin is empty.</div>'}
      </section>
    `;

    deleteHistoryBody.querySelectorAll("[data-recycle-restore]").forEach((button) => {
      button.addEventListener("click", async () => {
        const recycleId = Number(button.getAttribute("data-recycle-id"));
        if (!recycleId) return;
        await restoreRecycleBinItem(recycleId);
      });
    });
    deleteHistoryBody.querySelectorAll("[data-recycle-clear]").forEach((button) => {
      button.addEventListener("click", async () => {
        const recycleId = Number(button.getAttribute("data-recycle-id"));
        if (!recycleId) return;
        await clearRecycleBinItem(recycleId);
      });
    });
  }

  function renderRecycleBinItem(row) {
    const recycleId = Number(row.recycle_id || 0);
    const restored = Boolean(row.restored);
    const statusClass = restored ? "approved" : "pending";
    const statusLabel = restored ? "Restored" : "Deleted";
    const deletedMeta = `Deleted by ${escapeHtml(row.deleted_by_name || "Unknown")} (${escapeHtml(humanizeKey(row.deleted_by_role || ""))}) on ${escapeHtml(formatDisplay(row.deleted_at))}`;
    const restoredMeta = restored
      ? `Restored by ${escapeHtml(row.restored_by_name || "Unknown")} (${escapeHtml(humanizeKey(row.restored_by_role || ""))}) on ${escapeHtml(formatDisplay(row.restored_at))}`
      : "";
    const applicantLabel = formatTitleName(String(row.staff_title || "").trim(), String(row.staff_name || "").trim());
    return `
      <article class="delete-request-item recycle-item">
        <div class="delete-request-header">
          <div>
            <div class="delete-request-title">${escapeHtml(row.regNo || "Unknown File")} - ${escapeHtml(applicantLabel || "Unknown Applicant")}</div>
            <div class="delete-request-meta">${deletedMeta}</div>
            ${row.delete_reason ? `<div class="delete-request-meta">Reason: ${escapeHtml(formatDisplay(row.delete_reason))}</div>` : ""}
            ${restoredMeta ? `<div class="delete-request-meta">${restoredMeta}</div>` : ""}
          </div>
          <span class="delete-request-status ${statusClass}">${statusLabel}</span>
        </div>
        ${canProcessDeleteQueue() ? `
          <div class="delete-request-actions">
            ${!restored ? `<button type="button" class="registry-action-btn success" data-recycle-restore="1" data-recycle-id="${recycleId}">Restore Record</button>` : ""}
            <button type="button" class="registry-action-btn danger" data-recycle-clear="1" data-recycle-id="${recycleId}">Clear Record</button>
          </div>
        ` : ""}
      </article>
    `;
  }

  function renderDeleteQueueItem(row, canProcess) {
    const id = Number(row.request_id || 0);
    const status = String(row.status || "pending").toLowerCase();
    const statusClass = status === "approved" ? "approved" : (status === "rejected" ? "rejected" : "pending");
    const canAct = canProcess && status === "pending";
    const processedMeta = row.processed_by_name
      ? `Processed by ${escapeHtml(row.processed_by_name)} (${escapeHtml(humanizeKey(row.processed_by_role || ""))}) on ${escapeHtml(formatDisplay(row.processed_at))}`
      : "";
    const titleLabel = String(row.staff_title || "").trim();
    const applicantName = String(row.staff_name || "").trim();
    const applicantLabel = formatTitleName(titleLabel, applicantName);

    return `
      <article class="delete-request-item">
        <div class="delete-request-header">
          <div>
            <div class="delete-request-title">${escapeHtml(row.regNo || "Unknown File")} - ${escapeHtml(applicantLabel || "Unknown Applicant")}</div>
            <div class="delete-request-meta">Requested by ${escapeHtml(row.requested_by_name || "Unknown")} (${escapeHtml(humanizeKey(row.requested_by_role || ""))}) on ${escapeHtml(formatDisplay(row.created_at))}</div>
            <div class="delete-request-meta">Reason: ${escapeHtml(formatDisplay(row.reason))}</div>
            ${processedMeta ? `<div class="delete-request-meta">${processedMeta}</div>` : ""}
            ${row.processed_note ? `<div class="delete-request-meta">Decision Note: ${escapeHtml(formatDisplay(row.processed_note))}</div>` : ""}
          </div>
          <span class="delete-request-status ${statusClass}">${escapeHtml(humanizeKey(status))}</span>
        </div>
        ${canAct ? `
          <div class="delete-request-actions">
            <button type="button" class="registry-action-btn success" data-queue-action="approve" data-request-id="${id}">Approve Delete</button>
            <button type="button" class="registry-action-btn secondary" data-queue-action="reject" data-request-id="${id}">Reject</button>
          </div>
        ` : ""}
      </article>
    `;
  }

  async function processDeleteRequest(requestId, action) {
    if (!canProcessDeleteQueue()) {
      showFeedbackModal("error", "Access Denied", "You do not have permission to process delete requests.");
      return;
    }

    // Every approval/rejection carries operator notes for audit clarity.
    const isApprove = action === "approve";
    const note = await appPrompt(
      isApprove ? "Optional approval note:" : "Provide rejection note:",
      isApprove ? "" : "Record should not be deleted.",
      {
        title: isApprove ? "Approve Delete Request" : "Reject Delete Request",
        confirmText: isApprove ? "Approve" : "Reject"
      }
    );
    if (note === null) {
      return;
    }
    if (!isApprove && String(note).trim() === "") {
      showFeedbackModal("error", "Validation Error", "A rejection note is required.");
      return;
    }

    try {
      const res = await fetch("../backend/api/process_file_registry_delete_request.php", {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          request_id: Number(requestId),
          action,
          note: String(note || "").trim()
        })
      });
      const data = await res.json();
      if (!res.ok || !data.success) {
        showFeedbackModal("error", "Processing Failed", data.message || "Unable to process request.");
        return;
      }

      showFeedbackModal("success", "Updated", data.message || "Delete request processed.");
      await loadDeleteQueue();
      await loadCards();
      await refreshDeleteQueueBadge();
    } catch (error) {
      console.error("Process delete request failed:", error);
      showFeedbackModal("error", "Processing Failed", "Unable to process delete request.");
    }
  }

  async function restoreRecycleBinItem(recycleId) {
    if (!canProcessDeleteQueue()) {
      showFeedbackModal("error", "Access Denied", "You do not have permission to restore deleted records.");
      return;
    }

    const confirmed = await appConfirm(
      "Restore this deleted registry record from recycle bin?",
      {
        title: "Restore Registry Record",
        confirmText: "Restore",
        cancelText: "Cancel",
        danger: false
      }
    );
    if (!confirmed) {
      return;
    }

    try {
      const res = await fetch("../backend/api/restore_file_registry_recycle_item.php", {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ recycle_id: Number(recycleId) })
      });
      const data = await res.json();
      if (!res.ok || !data.success) {
        showFeedbackModal("error", "Restore Failed", data.message || "Unable to restore record.");
        return;
      }

      showFeedbackModal("success", "Restored", data.message || "Registry record restored successfully.");
      await loadCards();
      await refreshDeleteQueueBadge();
      await loadDeleteQueue();
    } catch (error) {
      console.error("Restore from recycle bin failed:", error);
      showFeedbackModal("error", "Restore Failed", "Unable to restore record.");
    }
  }

  async function clearRecycleBinItem(recycleId) {
    if (!canProcessDeleteQueue()) {
      showFeedbackModal("error", "Access Denied", "You do not have permission to clear recycle bin records.");
      return;
    }

    const confirmed = await appConfirm(
      "Clear this recycle bin record permanently? This action cannot be undone.",
      {
        title: "Clear Recycle Bin Record",
        confirmText: "Clear Record",
        cancelText: "Cancel",
        danger: true
      }
    );
    if (!confirmed) {
      return;
    }

    try {
      const res = await fetch("../backend/api/clear_registry_recycle_item.php", {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ recycle_id: Number(recycleId) })
      });
      const data = await res.json();
      if (!res.ok || !data.success) {
        showFeedbackModal("error", "Clear Failed", data.message || "Unable to clear recycle bin record.");
        return;
      }

      showFeedbackModal("success", "Cleared", data.message || "Recycle bin record cleared permanently.");
      await loadDeleteQueue();
    } catch (error) {
      console.error("Clear recycle bin item failed:", error);
      showFeedbackModal("error", "Clear Failed", "Unable to clear recycle bin record.");
    }
  }

  function openModal(modal) {
    if (!modal) return;
    modal.classList.remove("hidden");
    modal.setAttribute("aria-hidden", "false");
    document.body.classList.add("modal-open");
    document.body.style.overflow = "hidden";
  }

  function closeModal(modal) {
    if (!modal) return;
    modal.classList.add("hidden");
    modal.setAttribute("aria-hidden", "true");
    if (document.querySelectorAll(".registry-modal-overlay:not(.hidden)").length === 0) {
      document.body.classList.remove("modal-open");
      document.body.style.overflow = "";
    }
  }

  function normalizePhoneForDial(phone) {
    const raw = String(phone || "").trim().replace(/[\s().-]/g, "");
    if (!raw) return "";
    if (/^00[1-9]\d{7,14}$/.test(raw)) return `+${raw.slice(2)}`;
    if (/^\+[1-9]\d{7,14}$/.test(raw)) return raw;
    if (/^0\d{9}$/.test(raw)) return `+256${raw.slice(1)}`;
    if (/^[1-9]\d{7,14}$/.test(raw)) return `+${raw}`;
    return "";
  }

  function parseOtherFields(other) {
    if (other === null || other === undefined || String(other).trim() === "") {
      return [];
    }
    try {
      const parsed = JSON.parse(other);
      if (parsed && typeof parsed === "object" && !Array.isArray(parsed)) {
        return Object.entries(parsed).map(([key, value]) => [humanizeKey(key), value]);
      }
      return [["Other", String(other)]];
    } catch {
      return [["Other", String(other)]];
    }
  }

  function humanizeKey(key) {
    return String(key || "")
      .replace(/_/g, " ")
      .replace(/([a-z])([A-Z])/g, "$1 $2")
      .replace(/\s+/g, " ")
      .trim()
      .replace(/\b\w/g, (char) => char.toUpperCase());
  }

  function formatDisplay(value) {
    if (value === null || value === undefined) return "N/A";
    const text = String(value).trim();
    return text === "" ? "N/A" : text;
  }

  function formatTitleName(title, name) {
    const cleanTitle = String(title || "").trim();
    const cleanName = String(name || "").trim();
    if (cleanTitle && cleanName) {
      return `${cleanTitle} - ${cleanName}`;
    }
    return cleanTitle || cleanName;
  }

  function formatCurrency(value) {
    if (value === null || value === undefined || String(value).trim() === "") {
      return "N/A";
    }
    const amount = Number(value);
    if (!Number.isFinite(amount)) {
      return formatDisplay(value);
    }
    return `UGX ${amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  }

  function formatDateBadge(value) {
    if (value === null || value === undefined || String(value).trim() === "") {
      return "No Date";
    }
    const date = new Date(value);
    if (!Number.isNaN(date.getTime())) {
      return date.toLocaleDateString("en-GB", {
        day: "2-digit",
        month: "short",
        year: "numeric"
      });
    }
    return formatDisplay(value);
  }

  function normalizePayrollStatus(value) {
    const raw = String(value || "").trim().toLowerCase();
    return raw === "on payroll" ? "On Payroll" : "Not on Payroll";
  }

  function formatAvailability(status) {
    if (!status) return "In Shelf";
    return status === "out_of_shelf" ? "Out of Shelf" : "In Shelf";
  }

  function normalizePayType(value) {
    return getRetirementTypesApi().normalizePayType(value);
  }

  function deriveRegistryPayTypeValue(payload = {}) {
    return getRetirementTypesApi().derivePayType(payload);
  }

  function isDeathRetirementTypeSelected() {
    return normalizeRetirementTypeValue(editRetirementType?.value || "") === "death";
  }

  function requiresNextOfKinForCurrentRegistryRecord() {
    const livingStatus = String(editLivingStatus?.value || "").trim().toLowerCase();
    return isDeathRetirementTypeSelected() || livingStatus === "deceased";
  }

  function updateNextOfKinRequirementUi() {
    const requiresNextOfKin = requiresNextOfKinForCurrentRegistryRecord();
    const nextOfKinLabel = editNextOfKin?.closest("label")?.querySelector("span");
    const nextOfKinContactLabel = editNextOfKinContact?.closest("label")?.querySelector("span");

    if (nextOfKinLabel) {
      nextOfKinLabel.textContent = requiresNextOfKin ? "Next of Kin (Required for Death)" : "Next of Kin";
    }
    if (nextOfKinContactLabel) {
      nextOfKinContactLabel.textContent = requiresNextOfKin ? "Next of Kin Contact (Required for Death)" : "Next of Kin Contact";
    }
    if (editNextOfKin) {
      editNextOfKin.toggleAttribute("aria-required", requiresNextOfKin);
    }
    if (editNextOfKinContact) {
      editNextOfKinContact.toggleAttribute("aria-required", requiresNextOfKin);
    }
  }

  function updateLivingStatusEditability() {
    if (!editLivingStatus) return;

    if (isDeathRetirementTypeSelected()) {
      editLivingStatus.value = "Deceased";
      editLivingStatus.disabled = true;
      updateNextOfKinRequirementUi();
      return;
    }

    editLivingStatus.disabled = false;
    if (!editLivingStatus.value) {
      editLivingStatus.value = "Alive";
    }
    updateNextOfKinRequirementUi();
  }

  function isLifeCertificateExemptUi() {
    const payType = normalizePayType(editPayType?.value || "");
    const livingStatus = String(editLivingStatus?.value || "").trim().toLowerCase();
    return payType === "One-off Payment" || livingStatus === "deceased";
  }

  function updateLifeCertificateEditability() {
    if (!editLifeCertificate) return;
    if (isLifeCertificateExemptUi()) {
      editLifeCertificate.value = "Exempt";
      editLifeCertificate.disabled = true;
      return;
    }
    editLifeCertificate.disabled = false;
    if (editLifeCertificate.value === "Exempt" || editLifeCertificate.value === "") {
      editLifeCertificate.value = "Not Submitted";
    }
  }

  function applyBenefitsFieldPermissions() {
    // Monthly salary has broader edit rights; service length and computed
    // benefits stay restricted to privileged roles.
    const canEditMonthlySalary = canEditMonthlySalaryField();
    const canEditLengthService = canEditLengthOfServiceField();
    const canEditAmounts = canEditBenefitAmountFields();

    if (editMonthlySalary) {
      editMonthlySalary.readOnly = !canEditMonthlySalary;
      editMonthlySalary.classList.toggle("field-readonly", !canEditMonthlySalary);
    }
    if (editLengthOfService) {
      editLengthOfService.readOnly = !canEditLengthService;
      editLengthOfService.classList.toggle("field-readonly", !canEditLengthService);
    }

    [editAnnualSalary, editReducedPension, editFullPension, editGratuity].forEach((field) => {
      if (!field) return;
      field.readOnly = !canEditAmounts;
      field.classList.toggle("field-readonly", !canEditAmounts);
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

  function showFeedbackModal(type, title, message, onClose = null) {
    const existing = document.getElementById("registryFeedbackModal");
    if (existing) existing.remove();

    const modal = document.createElement("div");
    modal.id = "registryFeedbackModal";
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
          <button type="button" class="auth-btn auth-btn-secondary" id="registryFeedbackOkBtn">OK</button>
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
    const okBtn = document.getElementById("registryFeedbackOkBtn");
    if (okBtn) okBtn.addEventListener("click", close, { once: true });
    modal.addEventListener("click", (e) => {
      if (e.target === modal) close();
    });
  }

  if (searchInput) {
    searchInput.addEventListener("input", queueCardsReload);
  }

  if (boxNumberFilter) {
    boxNumberFilter.addEventListener("input", queueCardsReload);
  }

  if (availabilityFilter) {
    availabilityFilter.addEventListener("change", () => {
      currentPage = 1;
      loadCards();
    });
  }

  if (payTypeFilter) {
    payTypeFilter.addEventListener("change", () => {
      currentPage = 1;
      loadCards();
    });
  }

  if (sortSelect) {
    sortSelect.addEventListener("change", () => {
      currentPage = 1;
      loadCards();
    });
  }

  if (pageSizeSelect) {
    pageSizeSelect.addEventListener("change", () => {
      pageSize = Number(pageSizeSelect.value || 24);
      currentPage = 1;
      loadCards();
    });
  }

  if (refreshBtn) {
    refreshBtn.addEventListener("click", () => {
      clearRegistryCache();
      loadCards();
    });
  }

  if (addFileBtn) {
    addFileBtn.addEventListener("click", () => {
      openCreateModal();
    });
  }

  if (deleteQueueBtn) {
    deleteQueueBtn.addEventListener("click", async () => {
      deleteHistoryVisible = false;
      await loadDeleteQueue();
      await refreshDeleteQueueBadge();
      openModal(deleteQueueModal);
    });
  }

  if (toggleDeleteHistoryBtn) {
    toggleDeleteHistoryBtn.addEventListener("click", async () => {
      deleteHistoryVisible = !deleteHistoryVisible;
      await loadDeleteQueue();
    });
  }

  if (lifeCertBtn) {
    lifeCertBtn.addEventListener("click", () => {
      openLifeCertQuickAction();
    });
  }

  document.querySelectorAll("[data-close-modal='details']").forEach((el) => {
    el.addEventListener("click", () => closeModal(detailsModal));
  });

  document.querySelectorAll("[data-close-modal='edit']").forEach((el) => {
    el.addEventListener("click", () => closeModal(editModal));
  });

  document.querySelectorAll("[data-close-modal='bulk-upload']").forEach((el) => {
    el.addEventListener("click", () => closeModal(bulkUploadModal));
  });

  document.querySelectorAll("[data-close-modal='delete-queue']").forEach((el) => {
    el.addEventListener("click", () => {
      deleteHistoryVisible = false;
      closeModal(deleteQueueModal);
      if (deleteHistoryWrap) deleteHistoryWrap.classList.add("hidden");
      if (deleteHistoryBody) {
        deleteHistoryBody.innerHTML = '<div class="app-state-message app-state-neutral">History is hidden.</div>';
      }
      if (toggleDeleteHistoryBtn) toggleDeleteHistoryBtn.textContent = "Show Deleted History";
    });
  });

  document.querySelectorAll("[data-close-modal='life-cert']").forEach((el) => {
    el.addEventListener("click", () => closeModal(lifeCertModal));
  });

  document.addEventListener("keydown", (event) => {
    if (event.key !== "Escape") return;
    if (bulkUploadModal && !bulkUploadModal.classList.contains("hidden")) {
      closeModal(bulkUploadModal);
      return;
    }
    if (editModal && !editModal.classList.contains("hidden")) {
      closeModal(editModal);
      return;
    }
    if (detailsModal && !detailsModal.classList.contains("hidden")) {
      closeModal(detailsModal);
      return;
    }
    if (deleteQueueModal && !deleteQueueModal.classList.contains("hidden")) {
      closeModal(deleteQueueModal);
      return;
    }
    if (lifeCertModal && !lifeCertModal.classList.contains("hidden")) {
      closeModal(lifeCertModal);
    }
  });

  if (editForm) {
    editForm.addEventListener("submit", async (event) => {
      event.preventDefault();
      await saveEditForm();
    });
  }

  if (editRegNo) {
    editRegNo.addEventListener("input", () => {
      syncRegistryFileNumberInput({ preservePrefix: registryFormMode === "create" });
      registryTouchedFields.add(editRegNo.id);
      loadRegistryValidationStates();
    });

    editRegNo.addEventListener("keydown", (event) => {
      if (editRegNo.readOnly || editRegNo.disabled) return;
      const cursorStart = Number(editRegNo.selectionStart || 0);
      const cursorEnd = Number(editRegNo.selectionEnd || cursorStart);
      const prefixLength = REGISTRY_FILE_NUMBER_PREFIX.length;
      const isDeletingPrefix = (event.key === "Backspace" && cursorStart <= prefixLength && cursorEnd <= prefixLength)
        || (event.key === "Delete" && cursorStart < prefixLength);
      if (isDeletingPrefix) {
        event.preventDefault();
      }
    });

    editRegNo.addEventListener("focus", () => {
      if (registryFormMode !== "create" || editRegNo.value !== REGISTRY_FILE_NUMBER_PREFIX) return;
      window.setTimeout(() => {
        editRegNo.setSelectionRange(REGISTRY_FILE_NUMBER_PREFIX.length, REGISTRY_FILE_NUMBER_PREFIX.length);
      }, 0);
    });

    editRegNo.addEventListener("blur", () => {
      syncRegistryFileNumberInput({ preservePrefix: registryFormMode === "create" });
      loadRegistryValidationStates();
    });
  }

  if (lifeCertForm) {
    lifeCertForm.addEventListener("submit", async (event) => {
      event.preventDefault();
      await submitLifeCertificateFromRegistry();
    });
  }

  if (lifeCertEditBtn) {
    lifeCertEditBtn.addEventListener("click", async () => {
      if (!lifeCertProfileEditable) {
        if (!String(lifeCertRegNo?.value || "").trim()) {
          showFeedbackModal("error", "Validation Error", "Enter a file number first.");
          return;
        }
        // Cancel stale pending profile loads so they don't flip fields back to readonly.
        lifeCertProfileRequestSeq += 1;
        lifeCertProfileLoadingRegNo = "";
        lifeCertProfileLoadPromise = null;
        lifeCertProfileLoadedRegNo = String(lifeCertRegNo?.value || "").trim();
        setLifeCertProfileEditable(true);
        if (lifeCertPhone) {
          lifeCertPhone.focus();
        }
        setLifeCertProfileHint("Editing enabled. Update fields and click Save Changes.");
        return;
      }
      await saveLifeCertProfileChanges(true);
    });
  }

  if (lifeCertRegNo) {
    lifeCertRegNo.addEventListener("input", () => {
      const currentValue = String(lifeCertRegNo.value || "").trim();
      if (!currentValue) {
        clearLifeCertProfile();
        setLifeCertProfileHint("Select a file number to load details.");
      }
      if (lifeCertSearchTimer) clearTimeout(lifeCertSearchTimer);
      lifeCertSearchTimer = setTimeout(async () => {
        const files = await fetchRegistryFileSuggestions(lifeCertRegNo.value, 12);
        if (!lifeCertRegNoList) return;
        lifeCertRegNoList.innerHTML = files.map((file) => {
          const label = `${file.regNo} - ${file.name || "Unknown"} (${file.availability_status || "in_shelf"})`;
          return `<option value="${escapeHtml(file.regNo)}" label="${escapeHtml(label)}"></option>`;
        }).join("");
      }, 220);
    });

    lifeCertRegNo.addEventListener("change", async () => {
      await loadLifeCertProfile(lifeCertRegNo.value);
    });

    lifeCertRegNo.addEventListener("blur", async () => {
      const regNo = String(lifeCertRegNo.value || "").trim();
      if (!regNo) return;
      await loadLifeCertProfile(regNo);
    });
  }

  if (editTitle) {
    editTitle.addEventListener("blur", () => {
      validateSelectedTitle(true);
    });
  }

  if (editNIN) {
    const syncRegistryNin = () => {
      const normalized = normalizeNationalIdValue(editNIN.value);
      if (editNIN.value !== normalized) {
        editNIN.value = normalized;
      }
    };
    editNIN.addEventListener("input", syncRegistryNin);
    editNIN.addEventListener("change", syncRegistryNin);
    editNIN.addEventListener("blur", syncRegistryNin);
  }

  if (registryDocumentResetBtn) {
    registryDocumentResetBtn.addEventListener("click", () => {
      resetRegistryDocumentDraft();
    });
  }

  if (registryDocumentSaveBtn) {
    registryDocumentSaveBtn.addEventListener("click", async () => {
      await saveRegistryDocument();
    });
  }

  if (registryDocumentFile) {
    registryDocumentFile.addEventListener("change", () => {
      if (!registryDocumentHint) return;
      const file = registryDocumentFile.files?.[0];
      if (!file) {
        if (Number(registryDocumentTargetId?.value || 0) > 0) {
          registryDocumentHint.textContent = "You can change the document type only, or choose a replacement file before saving changes.";
        } else {
          registryDocumentHint.textContent = "Choose a document type and a file to upload it to this pension file record.";
        }
        return;
      }
      registryDocumentHint.textContent = `Selected file: ${file.name}. Save when ready to ${Number(registryDocumentTargetId?.value || 0) > 0 ? "replace the current attachment" : "upload it to this registry record"}.`;
    });
  }

  if (openBulkUploadBtn) {
    openBulkUploadBtn.addEventListener("click", () => {
      openBulkUploadModal();
    });
  }

  if (bulkDownloadTemplateBtn) {
    bulkDownloadTemplateBtn.addEventListener("click", async () => {
      await downloadRegistryBulkTemplate();
    });
  }

  if (bulkDryRunBtn) {
    bulkDryRunBtn.addEventListener("click", async () => {
      await submitRegistryBulkUpload("dry_run");
    });
  }

  if (bulkUploadForm) {
    bulkUploadForm.addEventListener("submit", async (event) => {
      event.preventDefault();
      await submitRegistryBulkUpload("import");
    });
  }

  if (bulkUploadFile) {
    bulkUploadFile.addEventListener("change", () => {
      resetRegistryBulkUploadReport();
    });
  }

  if (editPayType) {
    editPayType.addEventListener("change", updateLifeCertificateEditability);
  }

  if (editLivingStatus) {
    editLivingStatus.addEventListener("change", () => {
      updateLifeCertificateEditability();
      updateNextOfKinRequirementUi();
    });
  }

  if (editRetirementType) {
    editRetirementType.addEventListener("change", () => {
      updateLivingStatusEditability();
      updateLifeCertificateEditability();
      updateNextOfKinRequirementUi();
      recomputeRegistryBenefitFields();
    });
  }

  [editBirthDate, editEnlistmentDate, editRetirementDate, editMonthlySalary].forEach((field) => {
    if (!field) return;
    field.addEventListener("input", recomputeRegistryBenefitFields);
    field.addEventListener("change", recomputeRegistryBenefitFields);
  });

  await loadDocumentTypeOptions();
  await loadBankOptions();
  bindRegistryEditFieldTracking();
  initEditTabs();
  await loadCards();
  await restoreViewerReturnContextIfPresent();
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", () => {
    initPensionFileRegistryController().catch((error) => {
      console.error("Unable to initialize pension file registry:", error);
    });
  }, { once: true });
} else {
  initPensionFileRegistryController().catch((error) => {
    console.error("Unable to initialize pension file registry:", error);
  });
}
