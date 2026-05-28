document.addEventListener("DOMContentLoaded", () => {
  const tasksList = document.getElementById("tasksList");
  const taskDetails = document.getElementById("taskDetails");
  const taskDetailsModal = document.getElementById("taskDetailsModal");
  const taskDetailsBackdrop = document.getElementById("taskDetailsBackdrop");
  const taskDetailsClose = document.getElementById("taskDetailsClose");
  const scheduleAdjustModal = document.getElementById("scheduleAdjustModal");
  const scheduleAdjustBackdrop = document.getElementById("scheduleAdjustBackdrop");
  const scheduleAdjustClose = document.getElementById("scheduleAdjustClose");
  const scheduleAdjustCancelBtn = document.getElementById("scheduleAdjustCancelBtn");
  const scheduleAdjustApplyBtn = document.getElementById("scheduleAdjustApplyBtn");
  const scheduleAdjustDays = document.getElementById("scheduleAdjustDays");
  const scheduleAdjustDueAt = document.getElementById("scheduleAdjustDueAt");
  const scheduleAdjustNote = document.getElementById("scheduleAdjustNote");
  const writeupCheckpointModal = document.getElementById("writeupCheckpointModal");
  const writeupCheckpointBackdrop = document.getElementById("writeupCheckpointBackdrop");
  const writeupCheckpointClose = document.getElementById("writeupCheckpointClose");
  const writeupCheckpointCancelBtn = document.getElementById("writeupCheckpointCancelBtn");
  const writeupCheckpointSaveBtn = document.getElementById("writeupCheckpointSaveBtn");
  const writeupTitle = document.getElementById("writeupTitle");
  const writeupRetirementType = document.getElementById("writeupRetirementType");
  const writeupBirthDate = document.getElementById("writeupBirthDate");
  const writeupEnlistmentDate = document.getElementById("writeupEnlistmentDate");
  const writeupRetirementDate = document.getElementById("writeupRetirementDate");
  const writeupMonthlySalary = document.getElementById("writeupMonthlySalary");
  const writeupFinancialYear = document.getElementById("writeupFinancialYear");
  const writeupLengthOfService = document.getElementById("writeupLengthOfService");
  const writeupAnnualSalary = document.getElementById("writeupAnnualSalary");
  const writeupReducedPension = document.getElementById("writeupReducedPension");
  const writeupFullPension = document.getElementById("writeupFullPension");
  const writeupGratuity = document.getElementById("writeupGratuity");
  const writeupCheckpointPolicyHint = document.getElementById("writeupCheckpointPolicyHint");
  const assessorCheckpointModal = document.getElementById("assessorCheckpointModal");
  const assessorCheckpointBackdrop = document.getElementById("assessorCheckpointBackdrop");
  const assessorCheckpointClose = document.getElementById("assessorCheckpointClose");
  const assessorCheckpointCancelBtn = document.getElementById("assessorCheckpointCancelBtn");
  const assessorCheckpointSaveBtn = document.getElementById("assessorCheckpointSaveBtn");
  const assessorReducedPension = document.getElementById("assessorReducedPension");
  const assessorFullPension = document.getElementById("assessorFullPension");
  const assessorGratuity = document.getElementById("assessorGratuity");
  const assessorPayType = document.getElementById("assessorPayType");
  const dataEntryCheckpointModal = document.getElementById("dataEntryCheckpointModal");
  const dataEntryCheckpointBackdrop = document.getElementById("dataEntryCheckpointBackdrop");
  const dataEntryCheckpointClose = document.getElementById("dataEntryCheckpointClose");
  const dataEntryCheckpointCancelBtn = document.getElementById("dataEntryCheckpointCancelBtn");
  const dataEntryCheckpointSaveBtn = document.getElementById("dataEntryCheckpointSaveBtn");
  const dataEntryCheckpointHint = document.getElementById("dataEntryCheckpointHint");
  const dataEntryLivingStatus = document.getElementById("dataEntryLivingStatus");
  const dataEntryPayType = document.getElementById("dataEntryPayType");
  const dataEntryAddress = document.getElementById("dataEntryAddress");
  const dataEntryApplicantEmail = document.getElementById("dataEntryApplicantEmail");
  const dataEntryNextOfKin = document.getElementById("dataEntryNextOfKin");
  const dataEntryNextOfKinContact = document.getElementById("dataEntryNextOfKinContact");
  const dataEntryBankName = document.getElementById("dataEntryBankName");
  const dataEntryBankAccount = document.getElementById("dataEntryBankAccount");
  const dataEntryBankBranch = document.getElementById("dataEntryBankBranch");
  const dataEntryAddressLabel = document.getElementById("dataEntryAddressLabel");
  const dataEntryNextOfKinLabel = document.getElementById("dataEntryNextOfKinLabel");
  const dataEntryNextOfKinContactLabel = document.getElementById("dataEntryNextOfKinContactLabel");
  const taskStatusFilter = document.getElementById("taskStatusFilter");
  const taskUserFilter = document.getElementById("taskUserFilter");
  const taskRoleFilter = document.getElementById("taskRoleFilter");
  const taskBucketFilter = document.getElementById("taskBucketFilter");
  const taskSearchInput = document.getElementById("taskSearchInput");
  const refreshTasksBtn = document.getElementById("refreshTasksBtn");
  const openTaskQueueBtn = document.getElementById("openTaskQueueBtn");
  const taskAlertsToggleBtn = document.getElementById("taskAlertsToggleBtn");
  const tasksHeading = document.getElementById("tasksHeading");
  const tasksSubheading = document.getElementById("tasksSubheading");
  const taskAlertsPanel = document.getElementById("taskAlertsPanel");
  const taskAlertsSummary = document.getElementById("taskAlertsSummary");
  const taskAlertsList = document.getElementById("taskAlertsList");
  const refreshTaskAlertsBtn = document.getElementById("refreshTaskAlertsBtn");
  const taskQueueModal = document.getElementById("taskQueueModal");
  const taskQueueBackdrop = document.getElementById("taskQueueBackdrop");
  const taskQueueClose = document.getElementById("taskQueueClose");
  const taskQueuePanel = document.getElementById("taskQueuePanel");
  const taskQueueSummary = document.getElementById("taskQueueSummary");
  const taskQueueList = document.getElementById("taskQueueList");
  const taskQueueStatusFilter = document.getElementById("taskQueueStatusFilter");
  const taskQueueSearchInput = document.getElementById("taskQueueSearchInput");
  const refreshTaskQueueBtn = document.getElementById("refreshTaskQueueBtn");
  const processTaskQueueBtn = document.getElementById("processTaskQueueBtn");
  const pageParams = new URLSearchParams(window.location.search);

  const ACTIVE_STATUSES = new Set(["pending", "assigned", "in_progress", "deferred", "returned"]);
  const TERMINAL_STATUSES = new Set(["completed", "declined", "cancelled"]);
  const DUE_SOON_MS = 6 * 60 * 60 * 1000;

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
        const normalizedType = String(payload.retirementType || "").trim().toLowerCase().replace(/[^a-z0-9]/g, "");
        const rawFallback = String(payload.payType ?? "").trim();
        if (["mandatory", "voluntary", "oldage", "abolition"].includes(normalizedType)) {
          return "Pensioner";
        }
        if (["marriage", "contract", "tx"].includes(normalizedType)) {
          return "One-off Payment";
        }
        return rawFallback
          ? (["oneoffpayment", "oneoff", "oneoffpayout", "oneoffpay", "gratuityonly"].includes(rawFallback.toLowerCase().replace(/[^a-z0-9]/g, ""))
              ? "One-off Payment"
              : "Pensioner")
          : "";
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

  let tasks = [];
  let users = [];
  let userRoleLookup = new Map();
  let activeTaskId = null;
  let currentUserRole = "";
  let currentUserRoleRaw = "";
  let currentUserId = "";
  let scheduleContextTaskId = null;
  let scheduleContextMode = "admin";
  let writeupContextTask = null;
  let assessorContextTask = null;
  let writeupCheckpointResolver = null;
  let assessorCheckpointResolver = null;
  let assessorCheckpointStaff = null;
  let dataEntryContextTask = null;
  let dataEntryCheckpointResolver = null;
  let dataEntryCheckpointStaff = null;
  let dataEntryRetirementType = "";
  let taskAlerts = [];
  let taskAlertsCollapsed = false;
  let taskAlertSummary = {
    open_total: 0,
    critical_open: 0,
    overdue_open: 0,
    due_soon_open: 0,
    stalled_open: 0,
    acknowledged_total: 0
  };
  let taskCompletionQueue = [];
  let taskQueueSummaryState = {
    queued: 0,
    failed: 0,
    processed_recent: 0
  };
  let pendingFocusTaskId = Number.parseInt(pageParams.get("taskId") || "", 10);
  const feedbackDetailCache = new Map();

  if (!Number.isFinite(pendingFocusTaskId) || pendingFocusTaskId <= 0) {
    pendingFocusTaskId = null;
  }

  if (pendingFocusTaskId) {
    if (taskBucketFilter) taskBucketFilter.value = "all";
    if (taskStatusFilter) taskStatusFilter.value = "";
    if (taskSearchInput) taskSearchInput.value = "";
  }

  function normalizeRoleKey(roleValue) {
    const normalized = String(roleValue || "").trim().toLowerCase().replace(/[^a-z0-9]+/g, "_").replace(/_+/g, "_").replace(/^_+|_+$/g, "");
    if (["dep_oc", "deputy_oc", "deputy_oc_pen", "deputy_oc_pension"].includes(normalized)) {
      return "oc_pen";
    }
    return normalized;
  }

  function isOcPenLikeRole(roleValue) {
    return normalizeRoleKey(roleValue) === "oc_pen";
  }

  function canAccessTaskAlerts() {
    return currentUserRole === "admin" || isOcPenLikeRole(currentUserRole);
  }

  function getTaskAlertsPrefKey() {
    return `pensionsgo:task-alerts-collapsed:${currentUserId || currentUserRole || "unknown"}`;
  }

  function applyTaskAlertsPanelState() {
    const allowed = canAccessTaskAlerts();

    if (taskAlertsToggleBtn) {
      taskAlertsToggleBtn.style.display = allowed ? "" : "none";
      taskAlertsToggleBtn.textContent = taskAlertsCollapsed ? "Show Task Alerts" : "Hide Task Alerts";
      taskAlertsToggleBtn.setAttribute("aria-expanded", (!taskAlertsCollapsed).toString());
    }

    if (!taskAlertsPanel) return;
    taskAlertsPanel.style.display = allowed && !taskAlertsCollapsed ? "" : "none";
  }

  function shouldShowTaskCompletionQueue() {
    return currentUserRole !== "admin";
  }

  function applyTaskQueueVisibility() {
    const allowed = shouldShowTaskCompletionQueue();
    if (openTaskQueueBtn) {
      openTaskQueueBtn.style.display = allowed ? "" : "none";
    }
    if (!allowed) {
      closeTaskQueueModal();
    }
  }

  function isTaskQueueModalOpen() {
    return taskQueueModal && !taskQueueModal.classList.contains("hidden");
  }

  function isTaskModalOpen() {
    return taskDetailsModal && !taskDetailsModal.classList.contains("hidden");
  }

  function isScheduleModalOpen() {
    return scheduleAdjustModal && !scheduleAdjustModal.classList.contains("hidden");
  }

  function isWriteupModalOpen() {
    return writeupCheckpointModal && !writeupCheckpointModal.classList.contains("hidden");
  }

  function isAssessorModalOpen() {
    return assessorCheckpointModal && !assessorCheckpointModal.classList.contains("hidden");
  }

  function isDataEntryModalOpen() {
    return dataEntryCheckpointModal && !dataEntryCheckpointModal.classList.contains("hidden");
  }

  function syncBodyModalState() {
    if (isTaskModalOpen() || isTaskQueueModalOpen() || isScheduleModalOpen() || isWriteupModalOpen() || isAssessorModalOpen() || isDataEntryModalOpen()) {
      document.body.classList.add("modal-open");
      return;
    }
    document.body.classList.remove("modal-open");
  }

  function openTaskQueueModal() {
    if (!taskQueueModal) return;
    taskQueueModal.classList.remove("hidden");
    taskQueueModal.setAttribute("aria-hidden", "false");
    syncBodyModalState();
  }

  function closeTaskQueueModal() {
    if (!taskQueueModal) return;
    taskQueueModal.classList.add("hidden");
    taskQueueModal.setAttribute("aria-hidden", "true");
    syncBodyModalState();
  }

  function openTaskModal() {
    if (!taskDetailsModal) return;
    taskDetailsModal.classList.remove("hidden");
    taskDetailsModal.setAttribute("aria-hidden", "false");
    syncBodyModalState();
  }

  function closeTaskModal() {
    if (!taskDetailsModal) return;
    taskDetailsModal.classList.add("hidden");
    taskDetailsModal.setAttribute("aria-hidden", "true");
    syncBodyModalState();
  }

  function openScheduleModal(taskId, note = "", mode = "admin") {
    if (!scheduleAdjustModal) return;
    scheduleContextTaskId = taskId;
    scheduleContextMode = mode;
    if (scheduleAdjustDays) scheduleAdjustDays.value = "3";
    if (scheduleAdjustDueAt) scheduleAdjustDueAt.value = "";
    if (scheduleAdjustNote) scheduleAdjustNote.value = note || "";
    const title = document.getElementById("scheduleAdjustTitle");
    if (title) {
      title.textContent = mode === "feedback" ? "Reschedule Feedback Task" : "Adjust Task Schedule";
    }
    scheduleAdjustModal.classList.remove("hidden");
    scheduleAdjustModal.setAttribute("aria-hidden", "false");
    syncBodyModalState();
  }

  function closeScheduleModal() {
    if (!scheduleAdjustModal) return;
    scheduleAdjustModal.classList.add("hidden");
    scheduleAdjustModal.setAttribute("aria-hidden", "true");
    scheduleContextTaskId = null;
    scheduleContextMode = "admin";
    const title = document.getElementById("scheduleAdjustTitle");
    if (title) {
      title.textContent = "Adjust Task Schedule";
    }
    syncBodyModalState();
  }

  function openWriteupCheckpointModal(task) {
    if (!writeupCheckpointModal) return;
    writeupContextTask = task;
    writeupCheckpointModal.classList.remove("hidden");
    writeupCheckpointModal.setAttribute("aria-hidden", "false");
    syncBodyModalState();
  }

  function closeWriteupCheckpointModal() {
    if (!writeupCheckpointModal) return;
    writeupCheckpointModal.classList.add("hidden");
    writeupCheckpointModal.setAttribute("aria-hidden", "true");
    writeupContextTask = null;
    syncBodyModalState();
  }

  function openAssessorCheckpointModal(task) {
    if (!assessorCheckpointModal) return;
    assessorContextTask = task;
    assessorCheckpointModal.classList.remove("hidden");
    assessorCheckpointModal.setAttribute("aria-hidden", "false");
    syncBodyModalState();
  }

  function closeAssessorCheckpointModal() {
    if (!assessorCheckpointModal) return;
    assessorCheckpointModal.classList.add("hidden");
    assessorCheckpointModal.setAttribute("aria-hidden", "true");
    assessorContextTask = null;
    assessorCheckpointStaff = null;
    syncBodyModalState();
  }

  function openDataEntryCheckpointModal(task) {
    if (!dataEntryCheckpointModal) return;
    dataEntryContextTask = task;
    dataEntryCheckpointModal.classList.remove("hidden");
    dataEntryCheckpointModal.setAttribute("aria-hidden", "false");
    syncBodyModalState();
  }

  function closeDataEntryCheckpointModal() {
    if (!dataEntryCheckpointModal) return;
    dataEntryCheckpointModal.classList.add("hidden");
    dataEntryCheckpointModal.setAttribute("aria-hidden", "true");
    dataEntryContextTask = null;
    dataEntryCheckpointStaff = null;
    syncBodyModalState();
  }

  function resolveWriteupCheckpoint(result) {
    const resolver = writeupCheckpointResolver;
    writeupCheckpointResolver = null;
    closeWriteupCheckpointModal();
    if (resolver) resolver(result);
  }

  function resolveAssessorCheckpoint(result) {
    const resolver = assessorCheckpointResolver;
    assessorCheckpointResolver = null;
    closeAssessorCheckpointModal();
    if (resolver) resolver(result);
  }

  function resolveDataEntryCheckpoint(result) {
    const resolver = dataEntryCheckpointResolver;
    dataEntryCheckpointResolver = null;
    closeDataEntryCheckpointModal();
    if (resolver) resolver(result);
  }

  function toNumber(value) {
    const parsed = window.PensionsGoMoney?.parse
      ? window.PensionsGoMoney.parse(value, 0)
      : Number.parseFloat(String(value || "").replace(/,/g, ""));
    return Number.isFinite(parsed) ? parsed : 0;
  }

  function setMoneyInputValue(field, value) {
    if (!field) return;
    if (window.PensionsGoMoney?.setInputValue) {
      window.PensionsGoMoney.setInputValue(field, value);
      return;
    }
    field.value = value ?? "";
  }

  function formatCurrency(value) {
    const amount = Number.isFinite(Number(value)) ? Number(value) : 0;
    return `UGX ${amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
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

  function setDistrictFieldValue(field, value) {
    if (!field) return;
    if (window.PensionsGoDistrictSelector?.setValue) {
      window.PensionsGoDistrictSelector.setValue(field, value || "");
      return;
    }
    field.value = value || "";
  }

  function syncDistrictFieldState(field) {
    if (!field || !window.PensionsGoDistrictSelector?.syncElement) return;
    window.PensionsGoDistrictSelector.syncElement(field);
  }

  function applyDataEntryRequirementState(retirementType) {
    dataEntryRetirementType = getRetirementTypesApi().normalizeValue(String(retirementType || "").trim());
    const isDeathRetirement = dataEntryRetirementType === "death";
    const deathLabel = getRetirementTypesApi().getLabel("death") || "Death";

    if (dataEntryNextOfKinLabel) {
      dataEntryNextOfKinLabel.textContent = isDeathRetirement ? "Next of Kin (Required)" : "Next of Kin (Optional)";
    }
    if (dataEntryNextOfKinContactLabel) {
      dataEntryNextOfKinContactLabel.textContent = isDeathRetirement ? "Next of Kin Contact (Required)" : "Next of Kin Contact (Optional)";
    }
    if (dataEntryCheckpointHint) {
      dataEntryCheckpointHint.textContent = isDeathRetirement
        ? `Complete the contact and banking details before forwarding. Applicant email is optional. Because this retirement is ${deathLabel}, living status is locked to Deceased. Next of kin name and contact are mandatory before the file can move on.`
        : `Complete the contact and banking details before forwarding. Applicant email is optional. Next of kin details may be captured when available, but they are not mandatory.`;
    }
    if (dataEntryLivingStatus) {
      if (isDeathRetirement) {
        dataEntryLivingStatus.value = "Deceased";
        dataEntryLivingStatus.disabled = true;
        dataEntryLivingStatus.setAttribute("aria-disabled", "true");
      } else {
        dataEntryLivingStatus.disabled = false;
        dataEntryLivingStatus.removeAttribute("aria-disabled");
      }
    }
    if (dataEntryNextOfKin) {
      dataEntryNextOfKin.required = isDeathRetirement;
    }
    if (dataEntryNextOfKinContact) {
      dataEntryNextOfKinContact.required = isDeathRetirement;
    }
    if (dataEntryAddressLabel) {
      dataEntryAddressLabel.textContent = "District of Residence";
    }
  }

  function computeFinancialYear(dateValue) {
    if (!dateValue) return "-";
    const date = new Date(dateValue);
    if (Number.isNaN(date.getTime())) return "-";
    const year = date.getFullYear();
    const month = date.getMonth() + 1;
    const startYear = month <= 6 ? year - 1 : year;
    const endYear = month <= 6 ? year : year + 1;
    return `FY ${startYear}/${endYear}`;
  }

  function computeServicePeriod(enlistment, retirement) {
    if (!enlistment || !retirement) return { months: 0, days: 0, roundedMonths: 0 };
    const start = new Date(enlistment);
    const end = new Date(retirement);
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

  function computeBenefits(monthlySalary, enlistment, retirement, retirementType) {
    const retirementTypes = getRetirementTypesApi();
    const snapshot = retirementTypes.calculateBenefitSnapshot({
      retirementType: retirementTypes.normalizeValue(retirementType),
      enlistmentDate: enlistment,
      retirementDate: retirement,
      monthlySalary: Math.max(0, toNumber(monthlySalary))
    });
    return {
      months: snapshot.lengthOfService || 0,
      annual: snapshot.annualSalary || 0,
      reduced: snapshot.reducedPension || 0,
      full: snapshot.fullPension || 0,
      gratuity: snapshot.gratuity || 0
    };
  }

  function getWriteupPolicyAssessment() {
    return getRetirementTypesApi().validateRetirementProfile({
      retirementType: writeupRetirementType?.value || "",
      birthDate: writeupBirthDate?.value || "",
      enlistmentDate: writeupEnlistmentDate?.value || "",
      retirementDate: writeupRetirementDate?.value || ""
    });
  }

  function updateWriteupPolicyHint() {
    if (!writeupCheckpointPolicyHint) return;
    const assessment = getWriteupPolicyAssessment();
    const selectedRetirementType = String(writeupRetirementType?.value || "").trim();
    const label = selectedRetirementType ? getRetirementTypesApi().getLabel(selectedRetirementType) : "";
    const hasInputs = Boolean(
      selectedRetirementType
      || String(writeupBirthDate?.value || "").trim()
      || String(writeupEnlistmentDate?.value || "").trim()
      || String(writeupRetirementDate?.value || "").trim()
    );

    if (!hasInputs) {
      writeupCheckpointPolicyHint.hidden = true;
      writeupCheckpointPolicyHint.textContent = "";
      writeupCheckpointPolicyHint.dataset.state = "neutral";
      return;
    }

    if (!selectedRetirementType) {
      writeupCheckpointPolicyHint.hidden = false;
      writeupCheckpointPolicyHint.textContent = "Select a retirement type to validate the age and service policy checks for workflow capture.";
      writeupCheckpointPolicyHint.dataset.state = "neutral";
      return;
    }

    if (assessment.primaryMessage) {
      writeupCheckpointPolicyHint.hidden = false;
      writeupCheckpointPolicyHint.textContent = assessment.primaryMessage;
      writeupCheckpointPolicyHint.dataset.state = assessment.status || "warning";
      return;
    }

    if (label && assessment.valid && String(writeupRetirementDate?.value || "").trim()) {
      writeupCheckpointPolicyHint.hidden = false;
      writeupCheckpointPolicyHint.textContent = `${label} passes the current age and service policy checks for workflow capture.`;
      writeupCheckpointPolicyHint.dataset.state = "valid";
      return;
    }

    writeupCheckpointPolicyHint.hidden = true;
    writeupCheckpointPolicyHint.textContent = "";
    writeupCheckpointPolicyHint.dataset.state = "neutral";
  }

  function refreshWriteupPreview() {
    if (!writeupMonthlySalary || !writeupEnlistmentDate || !writeupRetirementDate || !writeupRetirementType) {
      updateWriteupPolicyHint();
      return;
    }
    const result = computeBenefits(
      writeupMonthlySalary.value,
      writeupEnlistmentDate.value,
      writeupRetirementDate.value,
      writeupRetirementType.value
    );
    if (writeupFinancialYear) writeupFinancialYear.textContent = computeFinancialYear(writeupRetirementDate.value);
    if (writeupLengthOfService) writeupLengthOfService.textContent = String(result.months);
    if (writeupAnnualSalary) writeupAnnualSalary.textContent = formatCurrency(result.annual);
    if (writeupReducedPension) writeupReducedPension.textContent = formatCurrency(result.reduced);
    if (writeupFullPension) writeupFullPension.textContent = formatCurrency(result.full);
    if (writeupGratuity) writeupGratuity.textContent = formatCurrency(result.gratuity);
    updateWriteupPolicyHint();
  }

  async function fetchStaffRecord(staffId) {
    const res = await fetch(`../backend/api/get_staff.php?id=${encodeURIComponent(staffId)}`, {
      credentials: "include",
      cache: "no-store"
    });
    const data = await res.json();
    if (!res.ok || !data.success || !data.record) {
      throw new Error(data.message || "Unable to load applicant record.");
    }
    return data.record;
  }

  async function saveTaskCheckpoint(taskId, staffId, mode, payload) {
    const res = await fetch("../backend/api/update_staff_task_checkpoint.php", {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        taskId,
        staffId,
        mode,
        payload
      })
    });
    const data = await res.json();
    if (!res.ok || !data.success) {
      throw new Error(data.message || "Unable to save verification details.");
    }
    return data;
  }

  async function initDistrictFields() {
    if (!window.PensionsGoDistrictSelector?.enhanceElement || !dataEntryAddress) {
      return;
    }
    await window.PensionsGoDistrictSelector.enhanceElement(dataEntryAddress, {
      placeholder: "Type to search district"
    });
    syncDistrictFieldState(dataEntryAddress);
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
        currentUserRoleRaw = (data.userRoleEffective || data.userRole || "").toLowerCase();
      currentUserRole = normalizeRoleKey(currentUserRoleRaw);
      currentUserId = data.userId || "";
      applyHeaderForRole();
      try {
        taskAlertsCollapsed = localStorage.getItem(getTaskAlertsPrefKey()) === "1";
      } catch (storageError) {
        taskAlertsCollapsed = false;
      }
      applyTaskAlertsPanelState();
      applyTaskQueueVisibility();
      return true;
    } catch (err) {
      console.error("Session check failed:", err);
      window.location.href = "login.html";
      return false;
    }
  }

  function applyHeaderForRole() {
    if (!tasksHeading || !tasksSubheading) return;
    if (currentUserRole === "admin") {
      tasksHeading.textContent = "Tasks Collection";
      tasksSubheading.textContent = "Administrative command view for all workflow tasks. Monitor timelines, reprioritize, extend schedules, realign ownership, and enforce controls.";
      return;
    }
    tasksHeading.textContent = "My Tasks";
    tasksSubheading.textContent = "Track assignments, collaborate with colleagues, and move workflows forward.";
  }

  async function loadUsers() {
    try {
      const res = await fetch("../backend/api/get_users.php?exclude_pensioner=1", { credentials: "include" });
      const data = await res.json();
      users = data.success && Array.isArray(data.users) ? data.users : [];
      users = users.map((user) => ({ ...user, userRole: normalizeRoleKey(user.userRole || "") }));
      userRoleLookup = new Map(users.map((user) => [user.userId, normalizeRoleKey(user.userRole || "")]));
      populateUserRoleFilters();
    } catch (err) {
      console.error("Unable to load users:", err);
      users = [];
      userRoleLookup = new Map();
      populateUserRoleFilters();
    }
  }

  function populateUserRoleFilters() {
    if (taskUserFilter) {
      const previous = taskUserFilter.value;
      const userOptions = users
        .slice()
        .sort((a, b) => (a.userName || "").localeCompare(b.userName || ""))
        .map((user) => `<option value="${escapeHtml(user.userId)}">${escapeHtml(user.userName || user.userId)}</option>`)
        .join("");
      taskUserFilter.innerHTML = `<option value="">All Users</option>${userOptions}`;
      if (previous && users.some((user) => user.userId === previous)) {
        taskUserFilter.value = previous;
      }
    }

    if (taskRoleFilter) {
      const previous = taskRoleFilter.value;
      const roleSet = new Set(users.map((user) => (user.userRole || "").toLowerCase()).filter(Boolean));
      const roleOptions = Array.from(roleSet)
        .sort((a, b) => formatRoleLabel(a).localeCompare(formatRoleLabel(b)))
        .map((role) => `<option value="${escapeHtml(role)}">${escapeHtml(formatRoleLabel(role))}</option>`)
        .join("");
      taskRoleFilter.innerHTML = `<option value="">All Roles</option>${roleOptions}`;
      if (previous && roleSet.has(previous)) {
        taskRoleFilter.value = previous;
      }
    }
  }

  function resolveUserName(userId) {
    if (!userId) return "";
    const user = users.find((u) => u.userId === userId);
    return user ? user.userName : "";
  }

  function parseMetadata(metadata) {
    if (!metadata) return {};
    try {
      const parsed = JSON.parse(metadata);
      return parsed && typeof parsed === "object" ? parsed : {};
    } catch {
      return {};
    }
  }

  function getApplicantName(task) {
    if (task.applicant_name && String(task.applicant_name).trim() !== "") {
      return String(task.applicant_name).trim();
    }
    const metadata = parseMetadata(task.metadata);
    if (metadata.applicant_name) return String(metadata.applicant_name).trim();
    if (metadata.full_name) return String(metadata.full_name).trim();
    if (metadata.submitter_name) return String(metadata.submitter_name).trim();
    return "Unknown Applicant";
  }

  function isFeedbackTask(task) {
    if (!task) return false;
    if (String(task.task_type || "").trim() === "feedback_followup") return true;
    const metadata = parseMetadata(task.metadata);
    return Boolean(metadata.submission_id || metadata.source === "feedback_assignment");
  }

  function formatFeedbackStatus(status) {
    if (!status) return "New";
    return String(status).replace(/_/g, " ").replace(/\b\w/g, (m) => m.toUpperCase());
  }

  function getFeedbackStatusTone(status) {
    const value = String(status || "new").toLowerCase();
    if (value === "resolved" || value === "closed") return "completed";
    if (value === "reviewed") return "in_progress";
    return "pending";
  }

  async function fetchFeedbackSubmissionDetail(submissionId) {
    const key = String(submissionId || "");
    if (!key) return null;
    if (feedbackDetailCache.has(key)) return feedbackDetailCache.get(key);
    try {
      const res = await fetch(`../backend/api/get_feedback_submission_detail.php?submission_id=${encodeURIComponent(key)}`, {
        credentials: "include",
        cache: "no-store"
      });
      const data = await res.json();
      if (!res.ok || !data.success) {
        return null;
      }
      feedbackDetailCache.set(key, data);
      return data;
    } catch (error) {
      console.warn("Unable to load feedback detail:", error);
      return null;
    }
  }

  function buildFeedbackFallbackDetail(task, metadata) {
    const submissionId = Number(metadata.submission_id || 0) || null;
    return {
      submission: {
        submission_id: submissionId,
        reference_no: metadata.reference_no || task.related_reg_no || "",
        feedback_type: metadata.feedback_type || "",
        feedback_type_label: metadata.feedback_type_label || formatFeedbackStatus(metadata.feedback_type || "Feedback"),
        audience: metadata.audience || "",
        audience_label: metadata.audience_label || formatFeedbackStatus(metadata.audience || "Public"),
        full_name: metadata.full_name || metadata.submitter_name || getApplicantName(task),
        email_address: metadata.email_address || metadata.email || "",
        phone_number: metadata.phone_number || metadata.phone || "",
        subject: metadata.subject || task.task_title || "Feedback submission",
        message: metadata.message || task.task_description || "No message provided.",
        page_context: metadata.page_context || "",
        status: metadata.feedback_status || "new",
        status_label: formatFeedbackStatus(metadata.feedback_status || "new"),
        priority: metadata.priority || task.priority || "normal",
        priority_label: formatFeedbackStatus(metadata.priority || task.priority || "normal"),
        assigned_to_user_id: task.assigned_to || "",
        assigned_to_name: task.assigned_to_name || "",
        assigned_to_role: task.assigned_role || "",
        submitted_at: metadata.submitted_at || task.created_at || "",
        reviewed_at: metadata.reviewed_at || "",
        resolved_at: metadata.resolved_at || "",
        closed_at: metadata.closed_at || "",
        resolution_summary: metadata.resolution_summary || "",
        due_at: task.due_at || ""
      },
      permissions: {
        can_manage: currentUserRole === "admin"
      },
      activity: []
    };
  }

  async function getFeedbackDetailForTask(task, metadata) {
    const submissionId = Number(metadata.submission_id || 0);
    if (!submissionId) {
      return buildFeedbackFallbackDetail(task, metadata);
    }
    const detail = await fetchFeedbackSubmissionDetail(submissionId);
    return detail || buildFeedbackFallbackDetail(task, metadata);
  }

  function computeDueFlags(task) {
    if (!task.due_at || !ACTIVE_STATUSES.has(task.status)) {
      return { isOverdue: false, isDueSoon: false };
    }
    const dueAtTs = new Date(task.due_at).getTime();
    if (!Number.isFinite(dueAtTs)) {
      return { isOverdue: false, isDueSoon: false };
    }
    const diff = dueAtTs - Date.now();
    return {
      isOverdue: diff < 0,
      isDueSoon: diff >= 0 && diff <= DUE_SOON_MS
    };
  }

  function isCurrentAssignee(task) {
    if (!task) return false;
    if (task.assigned_to) {
      return task.assigned_to === currentUserId;
    }
    return !!task.assigned_role && normalizeRoleKey(task.assigned_role) === currentUserRole;
  }

  function canManageTask(task) {
    return currentUserRole === "admin" || isCurrentAssignee(task);
  }

  function rolesAreEquivalentForTask(roleA, roleB) {
    return normalizeRoleKey(roleA) !== "" && normalizeRoleKey(roleA) === normalizeRoleKey(roleB);
  }

  function getUsersByWorkflowRole(roleValue) {
    const normalizedRole = normalizeRoleKey(roleValue);
    if (!normalizedRole) return [];
    return users
      .filter((user) => rolesAreEquivalentForTask(user.userRole, normalizedRole))
      .sort((left, right) => (left.userName || "").localeCompare(right.userName || ""));
  }

  function getTaskRequiredAssignmentRole(task, metadata = parseMetadata(task?.metadata)) {
    const taskType = String(task?.task_type || "").trim();
    if (taskType === "authorize_writeup") return "writeup_officer";
    if (taskType === "authorize_file_creation") return "file_creator";
    if (taskType === "authorize_data_entry") return "data_entry";
    if (taskType === "review_return") {
      const returnedFrom = String(metadata?.returned_from || "").trim();
      if (returnedFrom === "writeup") return "writeup_officer";
      if (returnedFrom === "file_creation") return "file_creator";
      if (returnedFrom === "data_entry") return "data_entry";
    }
    return "";
  }

  function formatQueueStatus(status) {
    const value = String(status || "queued").trim().toLowerCase();
    if (value === "queued") return "Ready";
    if (value === "failed") return "Needs Review";
    if (value === "processed") return "Processed";
    if (value === "removed") return "Removed";
    return formatStatus(value);
  }

  function formatQueueRelative(timestampValue) {
    const ts = new Date(timestampValue || "").getTime();
    if (!Number.isFinite(ts)) return "now";
    const diffMs = Math.max(0, Date.now() - ts);
    const mins = Math.floor(diffMs / 60000);
    const hours = Math.floor(diffMs / 3600000);
    const days = Math.floor(diffMs / 86400000);
    if (days >= 7) {
      const weeks = Math.max(1, Math.floor(days / 7));
      return `${weeks} wk${weeks === 1 ? "" : "s"} ago`;
    }
    if (days > 0) return `${days} day${days === 1 ? "" : "s"} ago`;
    if (hours > 0) return `${hours} hr${hours === 1 ? "" : "s"} ago`;
    if (mins > 0) return `${mins} min${mins === 1 ? "" : "s"} ago`;
    return "Just now";
  }

  function getTaskHandlerRole(task, metadata = parseMetadata(task?.metadata)) {
    const explicitRole = normalizeRoleKey(task?.assigned_role || "");
    if (explicitRole) {
      return explicitRole;
    }

    const workflowRoleMap = {
      authorize_writeup: "oc_pen",
      writeup: "writeup_officer",
      authorize_file_creation: "oc_pen",
      file_creation: "file_creator",
      authorize_data_entry: "oc_pen",
      data_entry: "data_entry",
      assessment: "assessor",
      audit: "auditor",
      approval: "approver",
      review_return: "oc_pen"
    };

    if (task?.task_type === "review_return") {
      return "oc_pen";
    }

    return workflowRoleMap[String(task?.task_type || "").trim()] || "";
  }

  function getAssignedToLabel(task, metadata = parseMetadata(task?.metadata)) {
    const assignedName = String(task?.assigned_to_name || resolveUserName(task?.assigned_to) || "").trim();
    if (assignedName) {
      return assignedName;
    }

    const roleKey = getTaskHandlerRole(task, metadata);
    if (roleKey) {
      return `${formatRoleLabel(roleKey)} Queue`;
    }

    return "Unassigned";
  }

  function getEligibleRealignmentUsers(task, metadata = parseMetadata(task?.metadata)) {
    const requiredRole = getTaskHandlerRole(task, metadata);
    if (!requiredRole) {
      return users.filter((user) => user.userId !== task?.assigned_to);
    }

    return users
      .filter((user) => rolesAreEquivalentForTask(user.userRole, requiredRole))
      .filter((user) => user.userId !== task?.assigned_to)
      .sort((left, right) => (left.userName || "").localeCompare(right.userName || ""));
  }

  function getTaskSortGroup(task) {
    const status = String(task?.status || "").toLowerCase();
    const isDelegatedTask = task?.created_by === currentUserId && task?.assigned_to && task?.assigned_to !== currentUserId;

    // Operational order: pending intake first, then rejected outcomes,
    // then delegated work, then the remaining active tasks, and finally
    // completed history. Oldest items stay first within each group.
    if (status === "pending" || status === "assigned") return 0;
    if (status === "declined" || status === "cancelled" || status === "rejected") return 1;
    if (isDelegatedTask) return 2;
    if (status === "in_progress" || status === "returned" || status === "deferred") return 3;
    if (status === "completed") return 4;
    return 5;
  }

  function getTaskSortTimestamp(task) {
    const createdAt = new Date(task?.created_at || "").getTime();
    if (Number.isFinite(createdAt)) {
      return createdAt;
    }

    const updatedAt = new Date(task?.updated_at || "").getTime();
    if (Number.isFinite(updatedAt)) {
      return updatedAt;
    }

    return Number(task?.taskId || 0);
  }

  function formatAlertType(type) {
    const value = String(type || "").toLowerCase();
    if (value === "due_soon") return "Due Soon";
    if (value === "overdue") return "Overdue";
    if (value === "stalled") return "Stalled";
    return value ? value.replace(/_/g, " ").replace(/\b\w/g, (ch) => ch.toUpperCase()) : "Alert";
  }

  function formatAlertRelative(timestampValue) {
    const ts = new Date(timestampValue || "").getTime();
    if (!Number.isFinite(ts)) return "now";
    const diffMs = Date.now() - ts;
    const absMs = Math.abs(diffMs);
    const mins = Math.floor(absMs / 60000);
    const hours = Math.floor(absMs / 3600000);
    const days = Math.floor(absMs / 86400000);
    if (days > 0) return `${days}d ${hours % 24}h`;
    if (hours > 0) return `${hours}h ${mins % 60}m`;
    return `${Math.max(1, mins)}m`;
  }

  function renderTaskAlerts() {
    if (!taskAlertsPanel || !taskAlertsSummary || !taskAlertsList) return;
    if (!canAccessTaskAlerts()) return;

    taskAlertsSummary.innerHTML = `
      <div class="task-alert-summary-card warning">
        <span>Open Alerts</span>
        <strong>${Number(taskAlertSummary.open_total || 0).toLocaleString()}</strong>
      </div>
      <div class="task-alert-summary-card critical">
        <span>Critical</span>
        <strong>${Number(taskAlertSummary.critical_open || 0).toLocaleString()}</strong>
      </div>
      <div class="task-alert-summary-card warning">
        <span>Overdue</span>
        <strong>${Number(taskAlertSummary.overdue_open || 0).toLocaleString()}</strong>
      </div>
      <div class="task-alert-summary-card info">
        <span>Due Soon</span>
        <strong>${Number(taskAlertSummary.due_soon_open || 0).toLocaleString()}</strong>
      </div>
      <div class="task-alert-summary-card warning">
        <span>Stalled</span>
        <strong>${Number(taskAlertSummary.stalled_open || 0).toLocaleString()}</strong>
      </div>
      <div class="task-alert-summary-card">
        <span>Acknowledged</span>
        <strong>${Number(taskAlertSummary.acknowledged_total || 0).toLocaleString()}</strong>
      </div>
    `;

    if (!Array.isArray(taskAlerts) || taskAlerts.length === 0) {
      taskAlertsList.innerHTML = '<div class="task-alerts-empty">No open alerts in your queue.</div>';
      return;
    }

    taskAlertsList.innerHTML = taskAlerts.map((alert) => {
      const alertType = formatAlertType(alert.alert_type);
      const severityClass = `severity-${escapeHtml(alert.severity || "warning")}`;
      const statusClass = `status-${escapeHtml(alert.alert_status || "open")}`;
      const applicant = alert.applicant_name ? ` - ${escapeHtml(alert.applicant_name)}` : "";
      const fileNo = escapeHtml(alert.related_reg_no || "N/A");
      const taskId = Number(alert.task_id || 0);
      const canAcknowledge = String(alert.alert_status || "") === "open";
      const canResolve = currentUserRole === "admin" || isOcPenLikeRole(currentUserRole);
      return `
        <article class="task-alert-item" data-alert-id="${Number(alert.alert_id || 0)}" data-task-id="${taskId}">
          <div class="task-alert-main">
            <p class="task-alert-title">${escapeHtml(alert.task_title || "Workflow Task")} (${escapeHtml(fileNo)}${applicant})</p>
            <p class="task-alert-meta">${escapeHtml(alertType)} | Assigned: ${escapeHtml(alert.assigned_name || alert.assigned_role_label || "Unassigned")} | Triggered ${escapeHtml(formatAlertRelative(alert.triggered_at))} ago</p>
          </div>
          <div class="task-alert-tags">
            <span class="task-alert-tag ${severityClass}">${escapeHtml(alert.severity || "warning")}</span>
            <span class="task-alert-tag ${statusClass}">${escapeHtml(alert.alert_status || "open")}</span>
            <div class="task-alert-actions">
              <button class="alert-open-task" type="button" data-action="open-task">Open Task</button>
              ${canAcknowledge ? '<button class="alert-ack" type="button" data-action="acknowledge">Acknowledge</button>' : ''}
              ${canResolve ? '<button class="alert-ack" type="button" data-action="resolve">Resolve</button>' : ''}
            </div>
          </div>
        </article>
      `;
    }).join("");

    taskAlertsList.querySelectorAll("[data-action='open-task']").forEach((button) => {
      button.addEventListener("click", async (event) => {
        const parent = event.currentTarget.closest(".task-alert-item");
        if (!parent) return;
        const taskId = Number(parent.dataset.taskId || 0);
        if (!Number.isFinite(taskId) || taskId <= 0) return;
        const targetTask = tasks.find((item) => Number(item.taskId) === taskId);
        if (targetTask) {
          activeTaskId = taskId;
          renderTasks();
          await renderTaskDetails(targetTask);
          openTaskModal();
          return;
        }
        await loadTasks(taskId);
      });
    });

    taskAlertsList.querySelectorAll("[data-action='acknowledge']").forEach((button) => {
      button.addEventListener("click", async (event) => {
        const parent = event.currentTarget.closest(".task-alert-item");
        if (!parent) return;
        const alertId = Number(parent.dataset.alertId || 0);
        if (!Number.isFinite(alertId) || alertId <= 0) return;
        await updateTaskAlert(alertId, "acknowledge");
      });
    });

    taskAlertsList.querySelectorAll("[data-action='resolve']").forEach((button) => {
      button.addEventListener("click", async (event) => {
        const parent = event.currentTarget.closest(".task-alert-item");
        if (!parent) return;
        const alertId = Number(parent.dataset.alertId || 0);
        if (!Number.isFinite(alertId) || alertId <= 0) return;
        await updateTaskAlert(alertId, "resolve");
      });
    });
  }

  async function loadTaskAlerts() {
    if (!taskAlertsPanel || !taskAlertsSummary || !taskAlertsList) return;
    if (!canAccessTaskAlerts()) return;

    try {
      const params = new URLSearchParams();
      params.set("scope", (currentUserRole === "admin" || isOcPenLikeRole(currentUserRole)) ? "all" : "mine");
      params.set("limit", "40");
      const response = await fetch(`../backend/api/get_task_alerts.php?${params.toString()}`, {
        credentials: "include",
        cache: "no-store"
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error(data.message || `HTTP error! status: ${response.status}`);
      }

      taskAlertSummary = {
        ...taskAlertSummary,
        ...(data.summary || {})
      };
      taskAlerts = Array.isArray(data.alerts) ? data.alerts : [];
      renderTaskAlerts();
    } catch (error) {
      console.error("Unable to load task alerts:", error);
      taskAlertSummary = {
        open_total: 0,
        critical_open: 0,
        overdue_open: 0,
        due_soon_open: 0,
        stalled_open: 0,
        acknowledged_total: 0
      };
      taskAlerts = [];
      taskAlertsSummary.innerHTML = '<div class="task-alerts-empty">Unable to load task alert summary.</div>';
      taskAlertsList.innerHTML = '<div class="task-alerts-empty">Unable to load task alerts.</div>';
    }
  }

  async function updateTaskAlert(alertId, action) {
    try {
      const response = await fetch("../backend/api/update_task_alert.php", {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ alert_id: alertId, action })
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error(data.message || "Unable to update task alert.");
      }
      await loadTaskAlerts();
      return true;
    } catch (error) {
      console.error("Unable to update task alert:", error);
      appAlert(error.message || "Unable to update task alert.");
      return false;
    }
  }

  function getActiveQueueItemForTask(taskId) {
    const numericTaskId = Number(taskId || 0);
    if (!Number.isFinite(numericTaskId) || numericTaskId <= 0) return null;
    return taskCompletionQueue.find((item) => Number(item.task_id || 0) === numericTaskId && ["queued", "failed"].includes(String(item.queue_status || "").toLowerCase())) || null;
  }

  function getFilteredTaskQueueItems() {
    const filterValue = taskQueueStatusFilter ? taskQueueStatusFilter.value : "active";
    const query = (taskQueueSearchInput ? taskQueueSearchInput.value : "").trim().toLowerCase();
    return taskCompletionQueue.filter((item) => {
      const status = String(item.queue_status || "queued").toLowerCase();
      if (filterValue === "active" && !["queued", "failed"].includes(status)) return false;
      if (filterValue !== "all" && filterValue !== "active" && status !== filterValue) return false;
      if (!query) return true;
      const searchable = [
        item.task_title || "",
        item.related_reg_no || "",
        formatTaskType(item.task_type || ""),
        item.next_assigned_to_name || "",
        formatRoleLabel(item.required_assignment_role || ""),
        item.last_error || ""
      ].join(" ").toLowerCase();
      return searchable.includes(query);
    }).sort((left, right) => {
      const order = { queued: 0, failed: 1, processed: 2 };
      const groupDiff = (order[left.queue_status] ?? 9) - (order[right.queue_status] ?? 9);
      if (groupDiff !== 0) return groupDiff;
      return new Date(left.created_at || 0).getTime() - new Date(right.created_at || 0).getTime();
    });
  }

  function renderTaskCompletionQueue() {
    if (!taskQueuePanel || !taskQueueSummary || !taskQueueList) return;
    applyTaskQueueVisibility();
    if (!shouldShowTaskCompletionQueue()) return;

    const readyCount = Number(taskQueueSummaryState.queued || 0);
    const reviewCount = readyCount + Number(taskQueueSummaryState.failed || 0);
    if (openTaskQueueBtn) {
      openTaskQueueBtn.textContent = reviewCount > 0 ? `Completion Queue (${reviewCount})` : 'Completion Queue';
    }
    const failedCount = Number(taskQueueSummaryState.failed || 0);
    const processedCount = Number(taskQueueSummaryState.processed_recent || 0);

    taskQueueSummary.innerHTML = `
      <div class="task-queue-summary-card queued">
        <span>Ready To Process</span>
        <strong>${readyCount.toLocaleString()}</strong>
      </div>
      <div class="task-queue-summary-card failed">
        <span>Needs Review</span>
        <strong>${failedCount.toLocaleString()}</strong>
      </div>
      <div class="task-queue-summary-card processed">
        <span>Recently Processed</span>
        <strong>${processedCount.toLocaleString()}</strong>
      </div>
    `;

    if (processTaskQueueBtn) {
      processTaskQueueBtn.disabled = readyCount < 1;
    }

    const filteredItems = getFilteredTaskQueueItems();
    if (!filteredItems.length) {
      taskQueueList.innerHTML = `
        <div class="task-queue-empty">
          <h4>No queued task completions found.</h4>
          <p>Queue in-progress work from the task modal, then review the staged hand-offs here before processing the batch.</p>
        </div>
      `;
      return;
    }

    taskQueueList.innerHTML = filteredItems.map((item) => {
      const queueStatus = String(item.queue_status || "queued").toLowerCase();
      const requiredRole = String(item.required_assignment_role || "");
      const needsAssignee = requiredRole !== "";
      const eligibleUsers = needsAssignee ? getUsersByWorkflowRole(requiredRole) : [];
      const editable = queueStatus === "queued" || queueStatus === "failed";
      const selectedAssignee = String(item.next_assigned_to || "");
      const statusLabel = formatQueueStatus(queueStatus);
      const currentTaskStatus = formatStatus(item.current_task_status || "pending");
      const assignedSummary = item.next_assigned_to_name
        ? escapeHtml(item.next_assigned_to_name)
        : (requiredRole ? `${escapeHtml(formatRoleLabel(requiredRole))} required` : "Not required");
      const assigneeOptions = needsAssignee
        ? `
          <div class="inline-field">
            <label>Next Assignee</label>
            <select data-queue-field="next_assigned_to" ${editable ? "" : "disabled"}>
              <option value="">${eligibleUsers.length ? "Select user" : "No eligible users available"}</option>
              ${eligibleUsers.map((user) => `
                <option value="${escapeHtml(user.userId)}" ${user.userId === selectedAssignee ? "selected" : ""}>${escapeHtml(user.userName)} (${escapeHtml(formatRoleLabel(user.userRole))})</option>
              `).join("")}
            </select>
          </div>
        `
        : "";
      const helper = needsAssignee
        ? `<p class="task-queue-helper">This workflow step must go to ${escapeHtml(formatRoleLabel(requiredRole))}. Queue changes save automatically.</p>`
        : `<p class="task-queue-helper">No named assignee is required. Queue changes save automatically.</p>`;

      return `
        <article class="task-queue-item status-${escapeHtml(queueStatus)}" data-queue-id="${Number(item.queue_id || 0)}" data-task-id="${Number(item.task_id || 0)}">
          <div class="task-queue-item-header">
            <div class="task-queue-item-title">
              <h4>${escapeHtml(item.task_title || "Queued Task")}</h4>
              <p>${escapeHtml(formatTaskType(item.task_type || "task"))} - ${escapeHtml(item.related_reg_no || "N/A")}</p>
            </div>
            <div class="task-queue-badges">
              <span class="queue-pill queue-status-${escapeHtml(queueStatus)}">${escapeHtml(statusLabel)}</span>
              <span class="priority-pill priority-${escapeHtml(item.next_priority || "normal")}">${escapeHtml(String(item.next_priority || "normal").toUpperCase())}</span>
            </div>
          </div>
          <div class="task-queue-meta">
            <div class="detail-field"><span>Current Task Status</span><strong>${escapeHtml(currentTaskStatus)}</strong></div>
            <div class="detail-field"><span>Queued</span><strong>${escapeHtml(formatQueueRelative(item.created_at))}</strong></div>
            <div class="detail-field"><span>Next Receiver</span><strong>${assignedSummary}</strong></div>
            <div class="detail-field"><span>Processed</span><strong>${escapeHtml(item.processed_at ? formatQueueRelative(item.processed_at) : "Not yet")}</strong></div>
          </div>
          ${item.last_error ? `<div class="task-queue-error"><strong>Last failure:</strong> ${escapeHtml(item.last_error)}</div>` : ""}
          <div class="task-queue-fields">
            ${assigneeOptions}
            <div class="inline-field">
              <label>Forward Priority</label>
              <select data-queue-field="next_priority" ${editable ? "" : "disabled"}>
                <option value="low" ${item.next_priority === "low" ? "selected" : ""}>Low</option>
                <option value="normal" ${item.next_priority === "normal" ? "selected" : ""}>Normal</option>
                <option value="high" ${item.next_priority === "high" ? "selected" : ""}>High</option>
                <option value="urgent" ${item.next_priority === "urgent" ? "selected" : ""}>Urgent</option>
              </select>
            </div>
            <div class="inline-field" style="grid-column: 1 / -1;">
              <label>Action Note</label>
              <textarea data-queue-field="action_note" placeholder="Optional note saved with the batch action" ${editable ? "" : "disabled"}>${escapeHtml(item.action_note || "")}</textarea>
            </div>
          </div>
          ${helper}
          <div class="task-queue-actions">
            <button class="ghost queue-open" type="button">Open Task</button>
            ${editable ? '<button class="danger queue-remove" type="button">Remove</button>' : ''}
          </div>
        </article>
      `;
    }).join("");

    taskQueueList.querySelectorAll(".queue-open").forEach((button) => {
      button.addEventListener("click", async (event) => {
        const card = event.currentTarget.closest(".task-queue-item");
        const queueId = Number(card?.dataset.queueId || 0);
        const item = taskCompletionQueue.find((entry) => Number(entry.queue_id) === queueId);
        if (!item) return;
        if (taskBucketFilter) taskBucketFilter.value = "all";
        if (taskStatusFilter) taskStatusFilter.value = "";
        if (taskUserFilter) taskUserFilter.value = "";
        if (taskRoleFilter) taskRoleFilter.value = "";
        if (taskSearchInput) taskSearchInput.value = "";
        closeTaskQueueModal();
        await loadTasks(Number(item.task_id || 0));
      });
    });

    taskQueueList.querySelectorAll('[data-queue-field="next_assigned_to"], [data-queue-field="next_priority"]').forEach((field) => {
      field.addEventListener("change", async (event) => {
        const card = event.currentTarget.closest(".task-queue-item");
        if (!card) return;
        const queueId = Number(card.dataset.queueId || 0);
        const nextAssignedTo = card.querySelector('[data-queue-field="next_assigned_to"]')?.value || "";
        const nextPriority = card.querySelector('[data-queue-field="next_priority"]')?.value || "normal";
        const note = card.querySelector('[data-queue-field="action_note"]')?.value?.trim() || "";
        await updateTaskCompletionQueueItem(queueId, {
          next_assigned_to: nextAssignedTo,
          next_priority: nextPriority,
          note
        });
      });
    });

    taskQueueList.querySelectorAll('[data-queue-field="action_note"]').forEach((field) => {
      field.addEventListener("blur", async (event) => {
        const card = event.currentTarget.closest(".task-queue-item");
        if (!card) return;
        const queueId = Number(card.dataset.queueId || 0);
        const nextAssignedTo = card.querySelector('[data-queue-field="next_assigned_to"]')?.value || "";
        const nextPriority = card.querySelector('[data-queue-field="next_priority"]')?.value || "normal";
        const note = card.querySelector('[data-queue-field="action_note"]')?.value?.trim() || "";
        await updateTaskCompletionQueueItem(queueId, {
          next_assigned_to: nextAssignedTo,
          next_priority: nextPriority,
          note
        });
      });
    });

    taskQueueList.querySelectorAll(".queue-remove").forEach((button) => {
      button.addEventListener("click", async (event) => {
        const card = event.currentTarget.closest(".task-queue-item");
        if (!card) return;
        const queueId = Number(card.dataset.queueId || 0);
        const confirmed = await appConfirm("Remove this queued task completion?", {
          title: "Remove Queued Task",
          confirmText: "Remove"
        });
        if (!confirmed) return;
        await removeTaskCompletionQueueItem(queueId);
      });
    });
  }

  async function loadTaskCompletionQueue() {
    if (!taskQueuePanel || !shouldShowTaskCompletionQueue()) {
      if (taskQueuePanel) taskQueuePanel.style.display = shouldShowTaskCompletionQueue() ? "" : "none";
      return;
    }

    try {
      const response = await fetch("../backend/api/get_task_completion_queue.php", {
        credentials: "include",
        cache: "no-store"
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error(data.message || "Unable to load task completion queue.");
      }

      taskQueueSummaryState = {
        queued: Number(data.summary?.queued || 0),
        failed: Number(data.summary?.failed || 0),
        processed_recent: Number(data.summary?.processed_recent || 0)
      };
      taskCompletionQueue = Array.isArray(data.items) ? data.items : [];
      renderTaskCompletionQueue();
    } catch (error) {
      console.error("Unable to load task completion queue:", error);
      taskQueueSummaryState = { queued: 0, failed: 0, processed_recent: 0 };
      taskCompletionQueue = [];
      if (taskQueueSummary) {
        taskQueueSummary.innerHTML = '<div class="task-alerts-empty">Unable to load queue summary.</div>';
      }
      if (taskQueueList) {
        taskQueueList.innerHTML = '<div class="task-queue-empty">Unable to load queued task completions.</div>';
      }
    }
  }

  async function queueTaskCompletion(task, options = {}) {
    try {
      const response = await fetch("../backend/api/queue_task_completion.php", {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          taskId: task.taskId,
          note: options.note || "",
          next_assigned_to: options.nextAssignedTo || "",
          next_priority: options.nextPriority || "normal"
        })
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error(data.message || "Unable to queue task completion.");
      }
      await loadTaskCompletionQueue();
      if (Number(activeTaskId || 0) === Number(task.taskId || 0)) {
        const refreshedTask = tasks.find((item) => Number(item.taskId) === Number(task.taskId));
        if (refreshedTask) {
          await renderTaskDetails(refreshedTask);
        }
      }
      appAlert(data.message || "Task queued for batch forwarding.");
      return true;
    } catch (error) {
      console.error("Unable to queue task completion:", error);
      appAlert(error.message || "Unable to queue task completion.");
      return false;
    }
  }

  async function updateTaskCompletionQueueItem(queueId, payload) {
    try {
      const response = await fetch("../backend/api/update_task_completion_queue_item.php", {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          queue_id: queueId,
          next_assigned_to: payload.next_assigned_to || "",
          next_priority: payload.next_priority || "normal",
          note: payload.note || ""
        })
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error(data.message || "Unable to update queued task.");
      }
      await loadTaskCompletionQueue();
      return true;
    } catch (error) {
      console.error("Unable to update queued task:", error);
      appAlert(error.message || "Unable to update queued task.");
      return false;
    }
  }

  async function removeTaskCompletionQueueItem(queueId) {
    try {
      const response = await fetch("../backend/api/remove_task_completion_queue_item.php", {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ queue_id: queueId })
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error(data.message || "Unable to remove queued task.");
      }
      await loadTaskCompletionQueue();
      return true;
    } catch (error) {
      console.error("Unable to remove queued task:", error);
      appAlert(error.message || "Unable to remove queued task.");
      return false;
    }
  }

  async function processTaskCompletionQueue() {
    try {
      const response = await fetch("../backend/api/process_task_completion_queue.php", {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({})
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error(data.message || "Unable to process queued tasks.");
      }
      await loadTasks();
      let message = data.message || "Queued tasks processed.";
      if (Array.isArray(data.failures) && data.failures.length) {
        const failureLines = data.failures.slice(0, 5).map((failure) => `Task ${failure.task_id}: ${failure.message}`);
        message = `${message}\n\n${failureLines.join("\n")}`;
      }
      appAlert(message);
      return true;
    } catch (error) {
      console.error("Unable to process queued tasks:", error);
      appAlert(error.message || "Unable to process queued tasks.");
      return false;
    }
  }

  async function loadTasks(focusTaskId = null) {
    try {
      // Server-side scope keeps payloads small and enforces role visibility:
      // admin/OC-Pen can request global scope, others get assignee-scoped data.
      const status = taskStatusFilter ? taskStatusFilter.value : "";
      const bucket = taskBucketFilter ? taskBucketFilter.value : "all";
      const params = new URLSearchParams();
      if (status) params.append("status", status);
      if (currentUserRole === "admin") {
        params.append("scope", "all");
      }
      if (bucket === "delegated") {
        params.append("include_delegated", "1");
      }

      const res = await fetch(`../backend/api/get_tasks.php?${params.toString()}`, {
        credentials: "include",
        cache: "no-store"
      });
      if (!res.ok) throw new Error(`HTTP error! status: ${res.status}`);

      const data = await res.json();
      if (!data.success) throw new Error(data.message || "Unable to load tasks.");

      // Normalize API records with derived fields used by UI-only states
      // (name fallback, overdue pulses, due-soon labels).
      tasks = (Array.isArray(data.tasks) ? data.tasks : []).map((task) => {
        const flags = computeDueFlags(task);
        return {
          ...task,
          assigned_role: normalizeRoleKey(task.assigned_role || ""),
          applicant_name: getApplicantName(task),
          created_by_name: task.created_by_name || resolveUserName(task.created_by) || "System",
          is_overdue: Boolean(task.is_overdue) || flags.isOverdue,
          is_due_soon: flags.isDueSoon
        };
      });

      renderTasks();
      await focusTaskFromUrlIfNeeded();
      if (focusTaskId && Number.isFinite(Number(focusTaskId))) {
        const id = Number(focusTaskId);
        const targetTask = tasks.find((task) => Number(task.taskId) === id);
        if (targetTask) {
          activeTaskId = id;
          renderTasks();
          await renderTaskDetails(targetTask);
          openTaskModal();
        }
      }

      if (isTaskModalOpen() && activeTaskId) {
        const refreshedTask = tasks.find((task) => task.taskId === activeTaskId);
        if (refreshedTask) {
          renderTaskDetails(refreshedTask);
        } else {
          closeTaskModal();
        }
      }

      await loadTaskAlerts();
      await loadTaskCompletionQueue();
    } catch (err) {
      console.error("Unable to load tasks:", err);
      tasksList.innerHTML = `<div class="tasks-empty">${escapeHtml(err.message || "Unable to load tasks.")}</div>`;
      taskDetails.innerHTML = `
        <div class="task-empty">
          <h3>Unable to load task details</h3>
          <p>Please refresh and try again.</p>
        </div>
      `;
      closeTaskModal();
      await loadTaskAlerts();
      await loadTaskCompletionQueue();
    }
  }

  async function focusTaskFromUrlIfNeeded() {
    if (!pendingFocusTaskId) return;

    const targetTask = tasks.find((task) => Number(task.taskId) === Number(pendingFocusTaskId));
    pendingFocusTaskId = null;

    const cleanUrl = new URL(window.location.href);
    cleanUrl.searchParams.delete("taskId");
    cleanUrl.searchParams.delete("from");
    window.history.replaceState({}, "", `${cleanUrl.pathname}${cleanUrl.search}${cleanUrl.hash}`);

    if (!targetTask) return;

    activeTaskId = Number(targetTask.taskId);
    renderTasks();
    await renderTaskDetails(targetTask);
    openTaskModal();
  }

  function applyBucketFilter(task, bucket) {
    // Buckets represent operational inbox views instead of raw statuses.
    // This keeps each role's task lens predictable across workflow stages.
    switch (bucket) {
      case "received":
        return isCurrentAssignee(task) && ACTIVE_STATUSES.has(task.status);
      case "delegated":
        return task.created_by === currentUserId && task.assigned_to && task.assigned_to !== currentUserId;
      case "pending":
        return task.status === "pending" || task.status === "assigned";
      case "in_progress":
        return task.status === "in_progress";
      case "scheduled":
        return task.status === "deferred";
      case "returned":
        return task.status === "returned";
      case "completed":
        return task.status === "completed";
      case "declined_rejected":
        return task.status === "declined" || task.status === "cancelled";
      case "overdue":
        return Boolean(task.is_overdue);
      case "due_soon":
        return Boolean(task.is_due_soon) && !Boolean(task.is_overdue);
      case "all":
      default:
        return true;
    }
  }

  function getFilteredTasks() {
    const query = (taskSearchInput ? taskSearchInput.value : "").trim().toLowerCase();
    const bucket = taskBucketFilter ? taskBucketFilter.value : "all";
    const selectedUser = taskUserFilter ? taskUserFilter.value : "";
    const selectedRole = taskRoleFilter ? taskRoleFilter.value : "";
    return tasks.filter((task) => {
      if (!applyBucketFilter(task, bucket)) return false;

      if (selectedUser) {
        const effectiveUser = task.assigned_to || task.created_by || "";
        if (effectiveUser !== selectedUser) return false;
      }

      if (selectedRole) {
        const effectiveRole = (task.assigned_role || userRoleLookup.get(task.assigned_to || "") || "").toLowerCase();
        if (effectiveRole !== selectedRole) return false;
      }

      if (!query) return true;
      const searchable = [
        task.task_title || "",
        task.task_description || "",
        task.related_reg_no || "",
        task.applicant_name || "",
        task.applicant_station || "",
        task.created_by_name || "",
        formatTaskType(task.task_type || ""),
        formatStatus(task.status || "")
      ].join(" ").toLowerCase();
      return searchable.includes(query);
    }).sort((left, right) => {
      const groupDiff = getTaskSortGroup(left) - getTaskSortGroup(right);
      if (groupDiff !== 0) {
        return groupDiff;
      }

      const timeDiff = getTaskSortTimestamp(left) - getTaskSortTimestamp(right);
      if (timeDiff !== 0) {
        return timeDiff;
      }

      return Number(left.taskId || 0) - Number(right.taskId || 0);
    });
  }

  function renderTasks() {
    const filtered = getFilteredTasks();

    if (!filtered.length) {
      tasksList.innerHTML = '<div class="tasks-empty">No tasks found for the selected filter.</div>';
      activeTaskId = null;
      taskDetails.innerHTML = `
        <div class="task-empty">
          <h3>Select a task</h3>
          <p>No tasks match the current filter.</p>
        </div>
      `;
      closeTaskModal();
      return;
    }

    tasksList.innerHTML = filtered.map((task) => {
      const classNames = ["task-card"];
      if (task.taskId === activeTaskId) classNames.push("active");
      if (task.is_overdue) classNames.push("task-card-overdue");
      else if (task.is_due_soon) classNames.push("task-card-due-soon");
      const dueBadge = task.is_overdue
        ? '<span class="due-pill due-overdue pulse">Overdue</span>'
        : (task.is_due_soon ? '<span class="due-pill due-soon pulse">Due Soon</span>' : "");
      const metadata = parseMetadata(task.metadata);
      const applicantName = getApplicantName(task);
      const isFeedback = isFeedbackTask(task);
      const subMeta = isFeedback
        ? `Feedback: ${escapeHtml(metadata.subject || task.task_title || "Feedback submission")}`
        : `Station: ${escapeHtml(task.applicant_station || "N/A")}`;
      return `
        <div class="${classNames.join(" ")}" data-task="${task.taskId}">
          <div class="task-card-main">
            <div class="task-card-title">${escapeHtml(task.task_title || "Untitled Task")}</div>
            <div class="task-card-meta"><strong>${escapeHtml(task.related_reg_no || "N/A")}</strong> - ${escapeHtml(applicantName)}</div>
            <div class="task-card-submeta">${subMeta}</div>
          </div>
          <div class="task-card-side">
            <span class="status-pill status-${escapeHtml(task.status || "pending")}">${escapeHtml(formatStatus(task.status))}</span>
            ${dueBadge}
          </div>
        </div>
      `;
    }).join("");

    tasksList.querySelectorAll(".task-card").forEach((card) => {
      card.addEventListener("click", () => {
        activeTaskId = Number(card.dataset.task);
        const selectedTask = filtered.find((task) => task.taskId === activeTaskId);
        renderTasks();
        if (selectedTask) {
          renderTaskDetails(selectedTask);
          openTaskModal();
        }
      });
    });
  }

  function buildTaskActions(task, metadata = parseMetadata(task?.metadata), activeQueueItem = null) {
    if (!canManageTask(task) && currentUserRole !== "admin") return "";

    // Button generation follows task-state transitions, not static role menus.
    // This prevents exposing invalid actions for the current state.
    const buttons = [];
    const status = task.status || "pending";
    const canAct = canManageTask(task);
    const isOc = isOcPenLikeRole(currentUserRole);
    const requiresNamedAssignee = Boolean(getTaskRequiredAssignmentRole(task, metadata));
    const isQueuedForBatch = Boolean(activeQueueItem);
    const disabledAttr = isQueuedForBatch ? ' disabled' : '';

    if ((status === "pending" || status === "assigned") && canAct) {
      if (task.assigned_to) {
        buttons.push(`<button class="primary" data-action="start"${disabledAttr}>Start Task</button>`);
      } else {
        buttons.push(`<button class="primary" data-action="accept"${disabledAttr}>Accept Task</button>`);
      }
      buttons.push(`<button class="danger" data-action="decline"${disabledAttr}>Decline</button>`);
    }

    if (status === "in_progress" && canAct) {
      if (!requiresNamedAssignee) {
        buttons.push(`<button class="primary" data-action="complete"${disabledAttr}>Complete & Forward</button>`);
      }
      if (currentUserRole !== "admin" && !requiresNamedAssignee) {
        const queueLabel = isQueuedForBatch ? "Queued" : "Queue For Batch Forwarding";
        buttons.push(`<button class="secondary" data-action="queue_complete"${disabledAttr}>${queueLabel}</button>`);
      }
      if (isOcPenLikeRole(currentUserRole) || currentUserRole === "admin") {
        buttons.push(`<button class="secondary" data-action="defer"${disabledAttr}>Schedule Later</button>`);
      }
      if (!isOc) {
        buttons.push(`<button class="secondary" data-action="return_to_oc"${disabledAttr}>Return to Sender</button>`);
      }
      buttons.push(`<button class="danger" data-action="decline"${disabledAttr}>Decline</button>`);
    }

    if (status === "deferred" && canAct) {
      buttons.push(`<button class="primary" data-action="resume"${disabledAttr}>Resume</button>`);
      buttons.push(`<button class="danger" data-action="decline"${disabledAttr}>Decline</button>`);
    }

    return buttons.join("");
  }

  function getFeedbackDelegationUsers() {
    if (currentUserRole === "admin" || isOcPenLikeRole(currentUserRole)) {
      return users
        .filter((user) => {
          const roleKey = normalizeRoleKey(user.userRole);
          return roleKey !== "pensioner" && roleKey !== "user";
        })
        .filter((user) => user.userId !== currentUserId)
        .sort((left, right) => (left.userName || "").localeCompare(right.userName || ""));
    }
    return getDelegationUsers({ assigned_role: currentUserRole });
  }

  function buildFeedbackActions(task, feedbackStatus) {
    const canAct = canManageTask(task) || currentUserRole === "admin";
    if (!canAct) return "";

    const buttons = [];
    const status = String(feedbackStatus || "new").toLowerCase();
    const taskClosed = TERMINAL_STATUSES.has(task.status);

    if (!taskClosed) {
      if (status === "new") {
        buttons.push(`<button class="primary" data-feedback-action="review">Mark In Review</button>`);
      }
      if (status === "new" || status === "reviewed") {
        buttons.push(`<button class="primary" data-feedback-action="resolve">Resolve Feedback</button>`);
      }
      if (status !== "closed") {
        buttons.push(`<button class="warning" data-feedback-action="close">Close Feedback</button>`);
      }
      buttons.push(`<button class="secondary" data-feedback-action="reschedule">Reschedule</button>`);
    }

    if (!taskClosed && (status === "resolved" || status === "closed")) {
      buttons.push(`<button class="secondary" data-feedback-action="complete_task">Complete Task</button>`);
    }

    return buttons.join("");
  }

  async function updateFeedbackSubmission(submissionId, payload) {
    try {
      const res = await fetch("../backend/api/update_feedback_submission.php", {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ submission_id: submissionId, ...payload })
      });
      const data = await res.json();
      if (!res.ok || !data.success) {
        appAlert(data.message || "Unable to update feedback.");
        return false;
      }
      feedbackDetailCache.delete(String(submissionId));
      return true;
    } catch (error) {
      console.error("Unable to update feedback:", error);
      appAlert("Unable to update feedback.");
      return false;
    }
  }

  async function ensureTaskInProgress(task) {
    if (!task) return true;
    if (task.status === "in_progress") return true;
    if (TERMINAL_STATUSES.has(task.status)) return false;

    let action = "";
    if (task.status === "pending" || task.status === "assigned") {
      action = task.assigned_to ? "resume" : "accept";
    } else if (task.status === "deferred" || task.status === "returned") {
      action = "resume";
    }

    if (!action) return true;
    return updateTaskStatus(task.taskId, action, "", "", "", "normal");
  }

  async function renderFeedbackTaskDetails(task) {
    const modalTitle = document.getElementById("taskModalTitle");
    if (modalTitle) {
      modalTitle.textContent = "Feedback Task";
    }
    const metadata = parseMetadata(task.metadata);
    const detail = await getFeedbackDetailForTask(task, metadata);
    const submission = detail?.submission || {};
    const feedbackStatus = String(submission.status || "new").toLowerCase();
    const feedbackStatusLabel = submission.status_label || formatFeedbackStatus(feedbackStatus);
    const priority = String(submission.priority || task.priority || "normal").toLowerCase();
    const priorityTone = priority === "critical" ? "urgent" : priority;
    const dueLabel = task.due_at || submission.due_at || "N/A";
    const normalizedPhone = normalizePhone(submission.phone_number || "") || submission.phone_number || "";
    const applicantName = submission.full_name || getApplicantName(task);
    const assignedByLabel = task.created_by_name || resolveUserName(task.created_by) || task.created_by || "System";
    const assignedToLabel = getAssignedToLabel(task, metadata);
    const dueFlags = computeDueFlags(task);
    const dueBadge = dueFlags.isOverdue
      ? '<span class="due-pill due-overdue pulse">Overdue</span>'
      : (dueFlags.isDueSoon ? '<span class="due-pill due-soon pulse">Due Soon</span>' : "");
    const canAct = canManageTask(task) || currentUserRole === "admin";
    const actionButtons = buildFeedbackActions(task, feedbackStatus);

    taskDetails.innerHTML = `
      <div class="feedback-task-shell">
        <div class="feedback-case-hero">
          <div>
            <h3>${escapeHtml(submission.subject || task.task_title || "Feedback Submission")}</h3>
            <p>Reference ${escapeHtml(submission.reference_no || task.related_reg_no || "N/A")} | ${escapeHtml(submission.feedback_type_label || formatFeedbackStatus(submission.feedback_type || "Feedback"))} | ${escapeHtml(submission.audience_label || formatFeedbackStatus(submission.audience || "Public"))}</p>
          </div>
          <div class="feedback-case-badges">
            <span class="status-pill status-${escapeHtml(getFeedbackStatusTone(feedbackStatus))}">${escapeHtml(feedbackStatusLabel)}</span>
            <span class="priority-pill priority-${escapeHtml(priorityTone)}">${escapeHtml(formatFeedbackStatus(priority))}</span>
            ${dueBadge}
          </div>
        </div>
        <div class="task-detail-grid feedback-detail-grid">
          <div class="detail-field"><span>Task Status</span><strong>${escapeHtml(formatStatus(task.status))}</strong></div>
          <div class="detail-field"><span>Feedback Status</span><strong>${escapeHtml(feedbackStatusLabel)}</strong></div>
          <div class="detail-field"><span>Assigned By</span><strong>${escapeHtml(assignedByLabel)}</strong></div>
          <div class="detail-field"><span>Assigned To</span><strong>${escapeHtml(assignedToLabel)}</strong></div>
          <div class="detail-field"><span>Due</span><strong>${escapeHtml(dueLabel)}</strong></div>
          <div class="detail-field"><span>Submitted</span><strong>${escapeHtml(submission.submitted_at || task.created_at || "N/A")}</strong></div>
        </div>
        <div class="feedback-case-grid">
          <div class="feedback-card">
            <h4>Submitter</h4>
            <div class="feedback-contact-list">
              <div class="feedback-contact-item"><span>Name</span><strong>${escapeHtml(applicantName)}</strong></div>
              <div class="feedback-contact-item"><span>Email</span><strong>${escapeHtml(submission.email_address || "Not provided")}</strong></div>
              <div class="feedback-contact-item"><span>Phone</span><strong>${escapeHtml(submission.phone_number || "Not provided")}</strong></div>
              <div class="feedback-contact-item"><span>Page Context</span><strong>${escapeHtml(submission.page_context || "General")}</strong></div>
            </div>
            <div class="feedback-contact-actions">
              ${submission.email_address ? `<a class="btn-secondary" href="mailto:${escapeHtml(submission.email_address)}">Email</a>` : ""}
              ${normalizedPhone ? `<a class="btn-secondary" href="tel:${escapeHtml(normalizedPhone)}">Call</a>` : ""}
              ${normalizedPhone ? `<button class="btn-secondary" data-feedback-action="copy_phone">Copy Phone</button>` : ""}
            </div>
          </div>
          <div class="feedback-card feedback-message-card">
            <h4>Message</h4>
            <p class="feedback-message-text">${escapeHtml(submission.message || "No message provided.")}</p>
            <div class="feedback-meta-row">
              <span>Priority: <strong>${escapeHtml(formatFeedbackStatus(priority))}</strong></span>
              <span>Type: <strong>${escapeHtml(submission.feedback_type_label || formatFeedbackStatus(submission.feedback_type || "Feedback"))}</strong></span>
            </div>
          </div>
          <div class="feedback-card">
            <h4>Case Timeline</h4>
            <ul class="feedback-timeline">
              <li class="active">Submitted <small>${escapeHtml(submission.submitted_at || "N/A")}</small></li>
              <li class="${submission.reviewed_at ? "active" : ""}">In Review <small>${escapeHtml(submission.reviewed_at || "Pending")}</small></li>
              <li class="${submission.resolved_at ? "active" : ""}">Resolved <small>${escapeHtml(submission.resolved_at || "Pending")}</small></li>
              <li class="${submission.closed_at ? "active" : ""}">Closed <small>${escapeHtml(submission.closed_at || "Pending")}</small></li>
            </ul>
          </div>
        </div>
        ${canAct ? `
        <div class="feedback-action-panel">
          <div class="feedback-action-grid">
            <div class="inline-field">
              <label>Action Note</label>
              <input type="text" id="feedbackActionNote" placeholder="Optional note for logs">
            </div>
            <div class="inline-field full">
              <label>Resolution Summary</label>
              <textarea id="feedbackResolutionSummary" placeholder="What steps were taken and the outcome">${escapeHtml(submission.resolution_summary || "")}</textarea>
            </div>
            <div class="inline-field full">
              <label>Case Note</label>
              <textarea id="feedbackInternalNote" placeholder="Add a case note for the audit trail"></textarea>
              <button class="btn-secondary feedback-note-btn" type="button" id="feedbackNoteBtn">Save Case Note</button>
            </div>
          </div>
          <div class="task-actions feedback-actions">
            ${actionButtons || '<span class="tasks-empty">No action available for this feedback task.</span>'}
          </div>
        </div>
        ` : '<div class="tasks-empty">This feedback task is read-only for your role.</div>'}
        ${canManageTask(task) || currentUserRole === "admin" ? `
        <div class="delegate-box">
          <strong>Delegate Feedback Task</strong>
          <select id="delegateUserSelect">
            <option value="">Select User</option>
            ${getFeedbackDelegationUsers().map((user) => `
              <option value="${escapeHtml(user.userId)}">${escapeHtml(user.userName)} (${escapeHtml(formatRoleLabel(user.userRole))})</option>
            `).join("")}
          </select>
          <input type="text" id="delegateNoteInput" placeholder="Delegation note (optional)">
          <select id="delegatePrioritySelect">
            <option value="low">Low</option>
            <option value="normal" selected>Normal</option>
            <option value="high">High</option>
            <option value="urgent">Urgent</option>
          </select>
          <div class="button-row">
            <button class="secondary" id="delegateTaskBtn">Delegate</button>
          </div>
        </div>
        ` : ""}
        <div class="task-comments">
          <h4>Case Notes</h4>
          <div id="taskCommentsList">Loading notes...</div>
          <div class="comment-form">
            <textarea id="taskCommentInput" placeholder="Add a note for the case"></textarea>
            <button id="taskCommentBtn">Post Note</button>
          </div>
        </div>
      </div>
    `;

    attachFeedbackActionHandlers(task, submission);
    attachDelegateHandler(task);
    attachCommentHandler(task);
    await loadTaskComments(task.taskId);
  }

  function attachFeedbackActionHandlers(task, submission) {
    const actionButtons = taskDetails.querySelectorAll("[data-feedback-action]");
    const actionNoteInput = document.getElementById("feedbackActionNote");
    const resolutionInput = document.getElementById("feedbackResolutionSummary");
    const internalNoteInput = document.getElementById("feedbackInternalNote");
    const noteBtn = document.getElementById("feedbackNoteBtn");

    if (noteBtn && internalNoteInput) {
      noteBtn.addEventListener("click", async () => {
        const note = internalNoteInput.value.trim();
        if (!note) {
          appAlert("Add a case note before saving.");
          return;
        }
        const submissionId = submission?.submission_id;
        if (!submissionId) {
          appAlert("Feedback submission reference is missing.");
          return;
        }
        const ok = await updateFeedbackSubmission(submissionId, { internal_note: note });
        if (ok) {
          internalNoteInput.value = "";
          await addTaskComment(task.taskId, note);
          await loadTaskComments(task.taskId);
        }
      });
    }

    actionButtons.forEach((btn) => {
      btn.addEventListener("click", async () => {
        const action = btn.dataset.feedbackAction;
        const submissionId = submission?.submission_id;
        const note = actionNoteInput ? actionNoteInput.value.trim() : "";
        const resolutionSummary = resolutionInput ? resolutionInput.value.trim() : "";

        if (action === "copy_phone") {
          if (submission?.phone_number) {
            try {
              await navigator.clipboard.writeText(submission.phone_number);
              appAlert("Phone number copied.");
            } catch (err) {
              appAlert("Unable to copy phone number.");
            }
          }
          return;
        }

        if (action === "reschedule") {
          openScheduleModal(task.taskId, note, "feedback");
          return;
        }

        if (!submissionId) {
          appAlert("Feedback submission reference is missing.");
          return;
        }

        if (action === "review") {
          const okToProceed = await ensureTaskInProgress(task);
          if (!okToProceed) {
            appAlert("This task is already closed.");
            return;
          }
          const ok = await updateFeedbackSubmission(submissionId, {
            status: "reviewed",
            internal_note: note
          });
          if (ok) {
            if (note) {
              await addTaskComment(task.taskId, note);
            }
            await loadTasks();
          }
          return;
        }

        if (action === "resolve" || action === "close") {
          if (!resolutionSummary) {
            appAlert("Add a resolution summary before resolving or closing.");
            return;
          }
          const okToProceed = await ensureTaskInProgress(task);
          if (!okToProceed) {
            appAlert("This task is already closed.");
            return;
          }
          const ok = await updateFeedbackSubmission(submissionId, {
            status: action === "resolve" ? "resolved" : "closed",
            internal_note: note,
            resolution_summary: resolutionSummary
          });
          if (ok && action === "close") {
            await updateTaskStatus(task.taskId, "complete", note);
          }
          if (ok) {
            if (note) {
              await addTaskComment(task.taskId, note);
            }
            await loadTasks();
          }
          return;
        }

        if (action === "complete_task") {
          const ok = await updateTaskStatus(task.taskId, "complete", note);
          if (ok && note) {
            await addTaskComment(task.taskId, note);
          }
          if (ok) {
            await loadTasks();
          }
        }
      });
    });
  }

  async function renderTaskDetails(task) {
    const modalTitle = document.getElementById("taskModalTitle");
    if (modalTitle) {
      modalTitle.textContent = "Task Details";
    }
    if (isFeedbackTask(task)) {
      await renderFeedbackTaskDetails(task);
      return;
    }
    const metadata = parseMetadata(task.metadata);
    const dueLabel = task.due_at ? task.due_at : "N/A";
    const applicantName = task.applicant_name || "Unknown Applicant";
    const assignedByLabel = task.created_by_name || resolveUserName(task.created_by) || task.created_by || "System";
    const assignedToLabel = getAssignedToLabel(task, metadata);
    const ocAssignmentRole = getOcAssignmentRole(task, metadata);
    const ocAssignmentUsers = ocAssignmentRole ? getUsersByWorkflowRole(ocAssignmentRole) : [];
    const eligibleRealignUsers = getEligibleRealignmentUsers(task, metadata);
    const taskHandlerRole = getTaskHandlerRole(task, metadata);
    const activeQueueItem = getActiveQueueItemForTask(task.taskId);
    const isQueuedForBatch = Boolean(activeQueueItem);
    const disableAttr = isQueuedForBatch ? "disabled" : "";
    const queueStateNotice = isQueuedForBatch
      ? `<div class="task-queue-error"><strong>Queued for batch forwarding:</strong> This task is already staged in your completion queue. Review or process it from the Completion Queue window.</div>`
      : "";
    const actionButtons = buildTaskActions(task, metadata, activeQueueItem);

    taskDetails.innerHTML = `
      <h3>${escapeHtml(task.task_title || "Untitled Task")}</h3>
      <p>${escapeHtml(task.task_description || "No description provided.")}</p>
      ${queueStateNotice}
      <div class="task-detail-grid">
        <div class="detail-field"><span>Status</span><strong>${escapeHtml(formatStatus(task.status))}</strong></div>
        <div class="detail-field"><span>Priority</span><strong>${escapeHtml((task.priority || "normal").toUpperCase())}</strong></div>
        <div class="detail-field"><span>Workflow Step</span><strong>${escapeHtml(formatTaskType(task.task_type))}</strong></div>
        <div class="detail-field"><span>File Number</span><strong>${escapeHtml(task.related_reg_no || "N/A")}</strong></div>
        <div class="detail-field"><span>Applicant Name</span><strong>${escapeHtml(applicantName)}</strong></div>
        <div class="detail-field"><span>Station</span><strong>${escapeHtml(task.applicant_station || "N/A")}</strong></div>
        <div class="detail-field"><span>Assigned By</span><strong>${escapeHtml(assignedByLabel)}</strong></div>
        <div class="detail-field"><span>Assigned To</span><strong>${escapeHtml(assignedToLabel)}</strong></div>
        <div class="detail-field"><span>Due</span><strong>${escapeHtml(dueLabel)}</strong></div>
        <div class="detail-field"><span>Created</span><strong>${escapeHtml(task.created_at || "N/A")}</strong></div>
      </div>
      <div class="task-forward-controls">
        <div class="inline-field">
          <label>Forward Priority</label>
          <select id="taskForwardPriority" ${disableAttr}>
            <option value="low">Low</option>
            <option value="normal" selected>Normal</option>
            <option value="high">High</option>
            <option value="urgent">Urgent</option>
          </select>
        </div>
        <div class="inline-field">
          <label>Action Note</label>
          <input type="text" id="taskActionNote" placeholder="Optional note for this action" ${disableAttr}>
        </div>
      </div>
      <div class="task-actions">
        ${actionButtons || (ocAssignmentRole && isOcPenLikeRole(currentUserRole) ? '<span class="tasks-empty">Use the assignment panel below to forward now or queue this task for batch processing.</span>' : '<span class="tasks-empty">No action available for this task state.</span>')}
      </div>
      ${ocAssignmentRole && isOcPenLikeRole(currentUserRole) ? `
      <div class="delegate-box">
        <strong>Assign & Forward (${escapeHtml(formatRoleLabel(ocAssignmentRole))})</strong>
        <select id="assignNextUserSelect" ${disableAttr}>
          <option value="">${ocAssignmentUsers.length ? "Select User" : "No eligible users available"}</option>
          ${ocAssignmentUsers.map((user) => `
            <option value="${escapeHtml(user.userId)}">${escapeHtml(user.userName)} (${escapeHtml(formatRoleLabel(user.userRole))})</option>
          `).join("")}
        </select>
        <input type="text" id="assignNextNote" placeholder="Add note (optional)" ${disableAttr} />
        <select id="assignNextPriority" ${disableAttr}>
          <option value="low">Low</option>
          <option value="normal" selected>Normal</option>
          <option value="high">High</option>
          <option value="urgent">Urgent</option>
        </select>
        <p class="tasks-empty">Only ${escapeHtml(formatRoleLabel(ocAssignmentRole))} users can receive this workflow step. Queue it when you want to forward multiple verified files at once.</p>
        <div class="button-row">
          <button class="primary" id="assignNextBtn" ${(ocAssignmentUsers.length && !isQueuedForBatch) ? "" : "disabled"}>Assign & Forward</button>
          <button class="secondary" id="assignNextQueueBtn" ${(ocAssignmentUsers.length && !isQueuedForBatch) ? "" : "disabled"}>${isQueuedForBatch ? "Queued" : "Queue For Batch Forwarding"}</button>
        </div>
      </div>
      ` : ""}
      ${currentUserRole !== "admin" && canManageTask(task) && !TERMINAL_STATUSES.has(task.status) ? `
      <div class="delegate-box">
        <strong>Delegate Task</strong>
        ${isQueuedForBatch ? `<p class="task-queue-helper">Delegation is disabled because this task is already queued for batch forwarding.</p>` : ""}
        <select id="delegateUserSelect" ${disableAttr}>
          <option value="">Select User</option>
          ${getDelegationUsers(task).map((user) => `
            <option value="${escapeHtml(user.userId)}">${escapeHtml(user.userName)} (${escapeHtml(formatRoleLabel(user.userRole))})</option>
          `).join("")}
        </select>
        <input type="text" id="delegateNoteInput" placeholder="Delegation note (optional)" ${disableAttr}>
        <select id="delegatePrioritySelect" ${disableAttr}>
          <option value="low">Low</option>
          <option value="normal" selected>Normal</option>
          <option value="high">High</option>
          <option value="urgent">Urgent</option>
        </select>
        <div class="button-row">
          <button class="secondary" id="delegateTaskBtn" ${disableAttr}>Delegate</button>
        </div>
      </div>
      ` : ""}
      ${currentUserRole === "admin" ? `
      <div class="admin-governance">
        <h4>Admin Task Governance</h4>
        <div class="admin-grid">
          <div class="inline-field">
            <label>Realign To User</label>
            <select id="adminAssignUser">
              <option value="">${eligibleRealignUsers.length ? "Select User" : "No eligible users available"}</option>
              ${eligibleRealignUsers.map((user) => `
                <option value="${escapeHtml(user.userId)}">${escapeHtml(user.userName)} (${escapeHtml(formatRoleLabel(user.userRole))})</option>
              `).join("")}
            </select>
          </div>
          <div class="inline-field">
            <label>Priority</label>
            <select id="adminPriority">
              <option value="low">Low</option>
              <option value="normal" selected>Normal</option>
              <option value="high">High</option>
              <option value="urgent">Urgent</option>
            </select>
          </div>
          <div class="inline-field">
            <label>Admin Note</label>
            <input type="text" id="adminTaskNote" placeholder="Reason/note">
          </div>
        </div>
        <p class="tasks-empty">${taskHandlerRole ? `Only ${escapeHtml(formatRoleLabel(taskHandlerRole))} users can be assigned at this workflow step.` : "Realignment is limited to users permitted for this workflow step."}</p>
        <div class="button-row">
          <button class="primary" id="adminRealignBtn" ${eligibleRealignUsers.length ? "" : "disabled"}>Realign</button>
          <button class="secondary" id="adminExtendScheduleBtn">Extend Schedule</button>
          <button class="warning" id="adminCancelTaskBtn">Cancel Task</button>
          <button class="danger" id="adminRemoveTaskBtn">Remove Task</button>
        </div>
      </div>
      ` : ""}
      ${task.task_type === "data_entry" ? `
      <div class="delegate-box">
        <strong>Data Entry Form</strong>
        <p>Open the applicant record to update details and upload documents.</p>
        <button class="secondary" id="openDataEntryBtn">Open Applicant Record</button>
      </div>
      ` : ""}
      <div class="task-comments">
        <h4>Comments</h4>
        <div id="taskCommentsList">Loading comments...</div>
        <div class="comment-form">
          <textarea id="taskCommentInput" placeholder="Add a comment"></textarea>
          <button id="taskCommentBtn">Post Comment</button>
        </div>
      </div>
    `;

    attachTaskActionHandlers(task);
    attachOcAssignmentHandler(task, ocAssignmentRole);
    attachDelegateHandler(task);
    attachAdminGovernance(task);
    attachDataEntryHandler(task);
    attachCommentHandler(task);
    await loadTaskComments(task.taskId);
  }

  function getDelegationUsers(task) {
    const targetRole = isOcPenLikeRole(currentUserRole)
      ? (task.assigned_role || null)
      : currentUserRole;
    if (!targetRole) return [];
    return users.filter((u) => u.userRole === targetRole && u.userId !== currentUserId);
  }

  function getOcAssignmentRole(task, metadata) {
    // OC/Pen controls cross-role handoffs. Returned tasks resolve assignee pool
    // from metadata.returned_from so rework goes back to the correct stream.
    if (!isOcPenLikeRole(currentUserRole)) return "";
    if (task.task_type === "authorize_writeup") return "writeup_officer";
    if (task.task_type === "authorize_file_creation") return "file_creator";
    if (task.task_type === "authorize_data_entry") return "data_entry";
    if (task.task_type === "review_return") {
      const returnedFrom = metadata.returned_from || "";
      if (returnedFrom === "writeup") return "writeup_officer";
      if (returnedFrom === "file_creation") return "file_creator";
      if (returnedFrom === "data_entry") return "data_entry";
    }
    return "";
  }

  function requiresWriteupCheckpoint(task) {
    return currentUserRole === "writeup_officer" && task.task_type === "writeup";
  }

  function requiresAssessorCheckpoint(task) {
    return currentUserRole === "assessor" && task.task_type === "assessment";
  }

  function requiresDataEntryCheckpoint(task) {
    return currentUserRole === "data_entry" && task.task_type === "data_entry";
  }

  function normalizePayType(value) {
    return getRetirementTypesApi().normalizePayType(value);
  }

  function deriveTaskPayType(payload = {}) {
    return getRetirementTypesApi().derivePayType(payload);
  }

  async function openWriteupCheckpointAndSave(task) {
    if (!task.related_staff_id) {
      appAlert("Applicant record is missing. Open the staff record and link this task first.");
      return false;
    }

    try {
      // Preload the latest applicant snapshot so write-up decisions are based
      // on current service and salary values before forwarding.
      const staff = await fetchStaffRecord(task.related_staff_id);
      if (writeupTitle) writeupTitle.value = staff.title || "";
      if (writeupRetirementType) writeupRetirementType.value = getRetirementTypesApi().normalizeValue(staff.retirementType || "");
      if (writeupBirthDate) writeupBirthDate.value = staff.birthDate || "";
      if (writeupEnlistmentDate) writeupEnlistmentDate.value = staff.enlistmentDate || "";
      if (writeupRetirementDate) writeupRetirementDate.value = staff.retirementDate || "";
      if (writeupMonthlySalary) setMoneyInputValue(writeupMonthlySalary, staff.monthlySalary || "");
      refreshWriteupPreview();
      openWriteupCheckpointModal(task);
    } catch (err) {
      console.error("Unable to load staff record:", err);
      appAlert(err.message || "Unable to load applicant record.");
      return false;
    }

    return new Promise((resolve) => {
      writeupCheckpointResolver = resolve;
    });
  }

  async function openAssessorCheckpointAndSave(task) {
    if (!task.related_staff_id) {
      appAlert("Applicant record is missing. Open the staff record and link this task first.");
      return false;
    }

    try {
      // Assessors verify monetary outputs before closing their stage.
      // Edits here are persisted via task checkpoint APIs.
      const staff = await fetchStaffRecord(task.related_staff_id);
      assessorCheckpointStaff = staff;
      if (assessorReducedPension) setMoneyInputValue(assessorReducedPension, staff.reducedPension || "");
      if (assessorFullPension) setMoneyInputValue(assessorFullPension, staff.fullPension || "");
      if (assessorGratuity) setMoneyInputValue(assessorGratuity, staff.gratuity || "");
      if (assessorPayType) {
        assessorPayType.value = deriveTaskPayType({
          retirementType: staff.retirementType || "",
          enlistmentDate: staff.enlistmentDate || "",
          retirementDate: staff.retirementDate || "",
          payType: staff.payType || ""
        });
      }
      openAssessorCheckpointModal(task);
    } catch (err) {
      console.error("Unable to load staff record:", err);
      appAlert(err.message || "Unable to load applicant record.");
      return false;
    }

    return new Promise((resolve) => {
      assessorCheckpointResolver = resolve;
    });
  }

  async function openDataEntryCheckpointAndSave(task) {
    if (!task.related_staff_id) {
      appAlert("Applicant record is missing. Open the staff record and link this task first.");
      return false;
    }

    try {
      // Data-entry completion captures living/pay-type controls that affect
      // downstream assessment, payroll classification, and registry behavior.
      const staff = await fetchStaffRecord(task.related_staff_id);
      dataEntryCheckpointStaff = staff;
      if (dataEntryLivingStatus) {
        dataEntryLivingStatus.value = (staff.livingStatus === "Deceased") ? "Deceased" : "Alive";
      }
      if (dataEntryPayType) {
        dataEntryPayType.value = deriveTaskPayType({
          retirementType: staff.retirementType || "",
          enlistmentDate: staff.enlistmentDate || "",
          retirementDate: staff.retirementDate || "",
          payType: staff.payType || ""
        });
      }
      setDistrictFieldValue(dataEntryAddress, staff.address || "");
      if (dataEntryApplicantEmail) {
        dataEntryApplicantEmail.value = String(staff.applicant_email || "").trim();
      }
      if (dataEntryNextOfKin) {
        dataEntryNextOfKin.value = String(staff.next_of_kin || "").trim();
      }
      if (dataEntryNextOfKinContact) {
        dataEntryNextOfKinContact.value = String(staff.next_of_kin_contact || "").trim();
      }
      if (dataEntryBankName) {
        dataEntryBankName.value = String(staff.bank_name || "").trim();
      }
      if (dataEntryBankAccount) {
        dataEntryBankAccount.value = String(staff.bank_account || "").trim();
      }
      if (dataEntryBankBranch) {
        dataEntryBankBranch.value = String(staff.bank_branch || "").trim();
      }
      applyDataEntryRequirementState(staff.retirementType || "");
      syncDistrictFieldState(dataEntryAddress);
      openDataEntryCheckpointModal(task);
    } catch (err) {
      console.error("Unable to load staff record:", err);
      appAlert(err.message || "Unable to load applicant record.");
      return false;
    }

    return new Promise((resolve) => {
      dataEntryCheckpointResolver = resolve;
    });
  }

  function attachTaskActionHandlers(task) {
    const actionButtons = taskDetails.querySelectorAll(".task-actions button");
    const forwardNoteInput = document.getElementById("taskActionNote");
    const forwardPrioritySelect = document.getElementById("taskForwardPriority");

    actionButtons.forEach((btn) => {
      btn.addEventListener("click", async () => {
        // Normalize UI intents into backend actions and collect required notes
        // before submission, so task transitions remain auditable.
        let action = btn.dataset.action;
        let reason = forwardNoteInput ? forwardNoteInput.value.trim() : "";
        let dueAt = "";
        const forwardPriority = forwardPrioritySelect ? forwardPrioritySelect.value : "normal";

        if (action === "start") {
          action = task.assigned_to ? "resume" : "accept";
        }

        if (action === "decline") {
          if (!reason) {
            const declineReason = await appPrompt("Provide a reason for declining this task:", "", {
              title: "Decline Task",
              confirmText: "Use Reason"
            });
            reason = declineReason === null ? "" : String(declineReason || "");
          }
          if (!reason) return;
        }

        if (action === "defer") {
          const dueAtValue = await appPrompt(
            "Enter schedule date/time (YYYY-MM-DD HH:MM). Leave empty to use configured business-day due window:",
            "",
            {
              title: "Schedule Task",
              confirmText: "Set Schedule"
            }
          );
          dueAt = dueAtValue === null ? "" : String(dueAtValue || "");
          if (!reason) {
            const deferNote = await appPrompt("Add a note (optional):", "", {
              title: "Schedule Note",
              confirmText: "Use Note"
            });
            reason = deferNote === null ? "" : String(deferNote || "");
          }
        }

        if (action === "return_to_oc") {
          if (!reason) {
            const returnNote = await appPrompt("Provide notes for the previous sender:", "", {
              title: "Return to Sender",
              confirmText: "Use Note"
            });
            reason = returnNote === null ? "" : String(returnNote || "");
          }
          if (!reason) return;
        }

        if ((action === "complete" || action === "queue_complete") && requiresWriteupCheckpoint(task)) {
          // Role-specific checkpoint gates completion until critical fields
          // are reviewed and saved.
          const saved = await openWriteupCheckpointAndSave(task);
          if (!saved) return;
        }

        if ((action === "complete" || action === "queue_complete") && requiresAssessorCheckpoint(task)) {
          const saved = await openAssessorCheckpointAndSave(task);
          if (!saved) return;
        }

        if ((action === "complete" || action === "queue_complete") && requiresDataEntryCheckpoint(task)) {
          const saved = await openDataEntryCheckpointAndSave(task);
          if (!saved) return;
        }

        if (action === "queue_complete") {
          await queueTaskCompletion(task, {
            note: reason,
            nextPriority: forwardPriority
          });
          return;
        }

        const ok = await updateTaskStatus(task.taskId, action, reason, dueAt, "", forwardPriority);
        if (ok) {
          await loadTasks();
        }
      });
    });
  }

  function attachOcAssignmentHandler(task, requiredRole) {
    if (!isOcPenLikeRole(currentUserRole) || !requiredRole) return;
    const assignBtn = document.getElementById("assignNextBtn");
    const assignQueueBtn = document.getElementById("assignNextQueueBtn");
    const assignSelect = document.getElementById("assignNextUserSelect");
    const assignNote = document.getElementById("assignNextNote");
    const assignPriority = document.getElementById("assignNextPriority");
    if (!assignSelect) return;

    const runForward = async (mode) => {
      const userId = assignSelect.value;
      if (!userId) {
        appAlert("Select a user to assign.");
        return;
      }
      const note = assignNote ? assignNote.value.trim() : "";
      const priority = assignPriority ? assignPriority.value : "normal";

      if (mode === "queue") {
        await queueTaskCompletion(task, {
          note,
          nextAssignedTo: userId,
          nextPriority: priority
        });
        return;
      }

      const ok = await updateTaskStatus(task.taskId, "complete", note, "", userId, priority);
      if (ok && note) {
        await addTaskComment(task.taskId, note);
      }
      if (ok) {
        await loadTasks();
      }
    };

    if (assignBtn) {
      assignBtn.addEventListener("click", async () => {
        await runForward("now");
      });
    }

    if (assignQueueBtn) {
      assignQueueBtn.addEventListener("click", async () => {
        await runForward("queue");
      });
    }
  }

  function attachDelegateHandler(task) {
    const delegateBtn = document.getElementById("delegateTaskBtn");
    const delegateSelect = document.getElementById("delegateUserSelect");
    const delegateNoteInput = document.getElementById("delegateNoteInput");
    const delegatePrioritySelect = document.getElementById("delegatePrioritySelect");
    if (!delegateBtn || !delegateSelect) return;

    delegateBtn.addEventListener("click", async () => {
      const userId = delegateSelect.value;
      if (!userId) {
        appAlert("Select a user to delegate this task.");
        return;
      }
      const note = delegateNoteInput ? delegateNoteInput.value.trim() : "";
      const priority = delegatePrioritySelect ? delegatePrioritySelect.value : "normal";
      const ok = await delegateTask(task.taskId, userId, note, priority);
      if (ok && isFeedbackTask(task)) {
        const metadata = parseMetadata(task.metadata);
        if (metadata.submission_id) {
          feedbackDetailCache.delete(String(metadata.submission_id));
        }
      }
      if (ok && note) {
        await addTaskComment(task.taskId, note);
      }
      if (ok) {
        await loadTasks();
      }
    });
  }

  function attachAdminGovernance(task) {
    if (currentUserRole !== "admin") return;
    const realignBtn = document.getElementById("adminRealignBtn");
    const extendScheduleBtn = document.getElementById("adminExtendScheduleBtn");
    const cancelBtn = document.getElementById("adminCancelTaskBtn");
    const removeBtn = document.getElementById("adminRemoveTaskBtn");

    if (realignBtn) {
      realignBtn.addEventListener("click", async () => {
        const assignedTo = document.getElementById("adminAssignUser")?.value || "";
        const priority = document.getElementById("adminPriority")?.value || "normal";
        const note = document.getElementById("adminTaskNote")?.value?.trim() || "";

        if (!assignedTo) {
          appAlert("Select a user to realign this task.");
          return;
        }

        const ok = await adminManageTask(task.taskId, "realign", {
          assigned_to: assignedTo,
          priority,
          note,
          status: "assigned"
        });
        if (ok) await loadTasks();
      });
    }

    if (extendScheduleBtn) {
      extendScheduleBtn.addEventListener("click", async () => {
        const note = document.getElementById("adminTaskNote")?.value?.trim() || "";
        openScheduleModal(task.taskId, note);
      });
    }

    if (cancelBtn) {
      cancelBtn.addEventListener("click", async () => {
        let reason = document.getElementById("adminTaskNote")?.value?.trim() || "";
        if (!reason) {
          const cancellationNote = await appPrompt("Reason for cancellation:", "", {
            title: "Cancel Task",
            confirmText: "Cancel Task"
          });
          reason = cancellationNote === null ? "" : String(cancellationNote || "");
        }
        reason = reason || "Cancelled by administrator";
        const ok = await adminManageTask(task.taskId, "cancel", { reason });
        if (ok) await loadTasks();
      });
    }

    if (removeBtn) {
      removeBtn.addEventListener("click", async () => {
        const shouldRemove = await appConfirm("Remove this workflow task permanently?", {
          title: "Remove Task",
          confirmText: "Remove"
        });
        if (!shouldRemove) return;
        const ok = await adminManageTask(task.taskId, "remove");
        if (ok) {
          activeTaskId = null;
          await loadTasks();
        }
      });
    }
  }

  function attachDataEntryHandler(task) {
    const btn = document.getElementById("openDataEntryBtn");
    if (!btn) return;
    btn.addEventListener("click", () => {
      if (task.related_staff_id) {
        const params = new URLSearchParams();
        params.set("id", String(task.related_staff_id));
        params.set("from", "tasks");
        params.set("taskId", String(task.taskId));
        window.location.href = `edit_staff.html?${params.toString()}`;
      }
    });
  }

  function attachCommentHandler(task) {
    const commentBtn = document.getElementById("taskCommentBtn");
    const commentInput = document.getElementById("taskCommentInput");
    if (!commentBtn || !commentInput) return;

    commentBtn.addEventListener("click", async () => {
      const comment = commentInput.value.trim();
      if (!comment) return;
      const ok = await addTaskComment(task.taskId, comment);
      if (ok) {
        commentInput.value = "";
        await loadTaskComments(task.taskId);
      }
    });
  }

  async function updateTaskStatus(
    taskId,
    action,
    reason = "",
    dueAt = "",
    nextAssignedTo = "",
    nextPriority = "normal",
    days = null
  ) {
    try {
      // Single transition gateway for task actions. Keeping payload shape
      // centralized avoids drift between multiple action buttons.
      const payload = {
        taskId,
        action,
        reason,
        due_at: dueAt,
        next_assigned_to: nextAssignedTo,
        next_priority: nextPriority
      };
      if (Number.isFinite(days) && days !== null && days > 0) {
        payload.days = days;
      }

      const res = await fetch("../backend/api/update_task_status.php", {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
      });
      const data = await res.json();
      if (!data.success) {
        appAlert(data.message || "Unable to update task.");
        return false;
      }
      return true;
    } catch (err) {
      console.error("Unable to update task:", err);
      appAlert("Unable to update task.");
      return false;
    }
  }

  async function delegateTask(taskId, userId, note = "", priority = "normal") {
    try {
      const res = await fetch("../backend/api/delegate_task.php", {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ taskId, assigned_to: userId, note, priority })
      });
      const data = await res.json();
      if (!data.success) {
        appAlert(data.message || "Unable to delegate task.");
        return false;
      }
      return true;
    } catch (err) {
      console.error("Unable to delegate task:", err);
      appAlert("Unable to delegate task.");
      return false;
    }
  }

  async function adminManageTask(taskId, action, payload = {}) {
    try {
      const res = await fetch("../backend/api/admin_manage_task.php", {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ taskId, action, ...payload })
      });
      const data = await res.json();
      if (!data.success) {
        appAlert(data.message || "Unable to apply admin task action.");
        return false;
      }
      return true;
    } catch (err) {
      console.error("Admin task action failed:", err);
      appAlert("Unable to apply admin task action.");
      return false;
    }
  }

  async function loadTaskComments(taskId) {
    const list = document.getElementById("taskCommentsList");
    if (!list) return;
    try {
      const res = await fetch(`../backend/api/get_task_comments.php?taskId=${encodeURIComponent(taskId)}`, {
        credentials: "include",
        cache: "no-store"
      });
      const data = await res.json();
      if (!data.success || !Array.isArray(data.comments) || !data.comments.length) {
        list.innerHTML = '<div class="tasks-empty">No comments yet.</div>';
        return;
      }
      list.innerHTML = data.comments.map((comment) => `
        <div class="comment-item">
          <strong>${escapeHtml(comment.author_name || "User")} (${escapeHtml(formatRoleLabel(comment.author_role || ""))})</strong>
          <small>${escapeHtml(comment.created_at || "")}</small>
          <div>${escapeHtml(comment.comment || "")}</div>
        </div>
      `).join("");
    } catch (err) {
      console.error("Unable to load comments:", err);
      list.innerHTML = '<div class="tasks-empty">Unable to load comments.</div>';
    }
  }

  async function addTaskComment(taskId, comment) {
    try {
      const res = await fetch("../backend/api/add_task_comment.php", {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ taskId, comment })
      });
      const data = await res.json();
      if (!data.success) {
        appAlert(data.message || "Unable to add comment.");
        return false;
      }
      return true;
    } catch (err) {
      console.error("Unable to add comment:", err);
      appAlert("Unable to add comment.");
      return false;
    }
  }

  function formatStatus(status) {
    if (!status) return "Pending";
    if (status === "returned") return "Returned";
    if (status === "deferred") return "Scheduled";
    if (status === "in_progress") return "In Progress";
    return status.replace(/_/g, " ").replace(/\b\w/g, (m) => m.toUpperCase());
  }

  function formatTaskType(type) {
    if (!type) return "Task";
    if (type === "feedback_followup") return "Feedback Follow-up";
    return type.replace(/_/g, " ").replace(/\b\w/g, (m) => m.toUpperCase());
  }

  function formatRoleLabel(role) {
    const map = {
      super_admin: "Super Administrator",
      admin: "Administrator",
      clerk: "Clerk",
      oc_pen: "OC/Pension",
      dep_oc: "Deputy OC/Pension",
      deputy_oc_pen: "Deputy OC/Pension",
      deputy_oc: "Deputy OC/Pension",
      writeup_officer: "Writeup Officer",
      file_creator: "File Creator",
      data_entry: "Data Entrant",
      assessor: "Assessor",
      auditor: "Auditor",
      approver: "Approver",
      user: "User",
      pensioner: "Pensioner"
    };
    return map[role] || role || "User";
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

  if (taskDetailsClose) {
    taskDetailsClose.addEventListener("click", closeTaskModal);
  }

  if (taskDetailsBackdrop) {
    taskDetailsBackdrop.addEventListener("click", closeTaskModal);
  }

  if (scheduleAdjustClose) {
    scheduleAdjustClose.addEventListener("click", closeScheduleModal);
  }

  if (scheduleAdjustCancelBtn) {
    scheduleAdjustCancelBtn.addEventListener("click", closeScheduleModal);
  }

  if (scheduleAdjustBackdrop) {
    scheduleAdjustBackdrop.addEventListener("click", closeScheduleModal);
  }

  [writeupMonthlySalary, writeupBirthDate, writeupEnlistmentDate, writeupRetirementDate, writeupRetirementType].forEach((field) => {
    if (!field) return;
    field.addEventListener("input", refreshWriteupPreview);
    field.addEventListener("change", refreshWriteupPreview);
  });

  if (writeupCheckpointSaveBtn) {
    writeupCheckpointSaveBtn.addEventListener("click", async () => {
      if (!writeupContextTask || !writeupContextTask.related_staff_id) {
        resolveWriteupCheckpoint(false);
        return;
      }

      const payload = {
        title: writeupTitle ? writeupTitle.value.trim() : "",
        retirementType: writeupRetirementType ? getRetirementTypesApi().normalizeValue(writeupRetirementType.value) : "",
        birthDate: writeupBirthDate ? writeupBirthDate.value : "",
        enlistmentDate: writeupEnlistmentDate ? writeupEnlistmentDate.value : "",
        retirementDate: writeupRetirementDate ? writeupRetirementDate.value : "",
        monthlySalary: writeupMonthlySalary ? String(toNumber(writeupMonthlySalary.value)) : ""
      };

      if (!payload.title || !payload.retirementType || !payload.enlistmentDate || !payload.retirementDate || payload.monthlySalary === "") {
        appAlert("Complete all required fields before continuing.");
        return;
      }

      const policyAssessment = getWriteupPolicyAssessment();
      if (policyAssessment.errors.length) {
        appAlert(policyAssessment.primaryMessage || "The retirement profile does not satisfy the configured policy checks.");
        return;
      }

      try {
        writeupCheckpointSaveBtn.disabled = true;
        await saveTaskCheckpoint(writeupContextTask.taskId, writeupContextTask.related_staff_id, "writeup_verify", payload);
        resolveWriteupCheckpoint(true);
      } catch (err) {
        console.error("Write-up verification save failed:", err);
        appAlert(err.message || "Unable to save write-up verification.");
      } finally {
        writeupCheckpointSaveBtn.disabled = false;
      }
    });
  }

  if (writeupCheckpointCancelBtn) {
    writeupCheckpointCancelBtn.addEventListener("click", () => resolveWriteupCheckpoint(false));
  }

  if (writeupCheckpointClose) {
    writeupCheckpointClose.addEventListener("click", () => resolveWriteupCheckpoint(false));
  }

  if (writeupCheckpointBackdrop) {
    writeupCheckpointBackdrop.addEventListener("click", () => resolveWriteupCheckpoint(false));
  }

  if (assessorCheckpointSaveBtn) {
    assessorCheckpointSaveBtn.addEventListener("click", async () => {
      if (!assessorContextTask || !assessorContextTask.related_staff_id) {
        resolveAssessorCheckpoint(false);
        return;
      }

      const payload = {
        reducedPension: assessorReducedPension ? String(toNumber(assessorReducedPension.value)) : "",
        fullPension: assessorFullPension ? String(toNumber(assessorFullPension.value)) : "",
        gratuity: assessorGratuity ? String(toNumber(assessorGratuity.value)) : "",
        payType: deriveTaskPayType({
          retirementType: assessorCheckpointStaff?.retirementType || "",
          enlistmentDate: assessorCheckpointStaff?.enlistmentDate || "",
          retirementDate: assessorCheckpointStaff?.retirementDate || "",
          payType: assessorPayType ? assessorPayType.value.trim() : ""
        })
      };

      if (payload.reducedPension === "" || payload.fullPension === "" || payload.gratuity === "") {
        appAlert("Provide all benefit values before continuing.");
        return;
      }
      if (!payload.payType) {
        appAlert("Pay type will auto-populate after the retirement label and any required service dates are available.");
        return;
      }

      try {
        assessorCheckpointSaveBtn.disabled = true;
        await saveTaskCheckpoint(assessorContextTask.taskId, assessorContextTask.related_staff_id, "assessor_verify", payload);
        resolveAssessorCheckpoint(true);
      } catch (err) {
        console.error("Assessor verification save failed:", err);
        appAlert(err.message || "Unable to save assessment verification.");
      } finally {
        assessorCheckpointSaveBtn.disabled = false;
      }
    });
  }

  if (assessorCheckpointCancelBtn) {
    assessorCheckpointCancelBtn.addEventListener("click", () => resolveAssessorCheckpoint(false));
  }

  if (assessorCheckpointClose) {
    assessorCheckpointClose.addEventListener("click", () => resolveAssessorCheckpoint(false));
  }

  if (assessorCheckpointBackdrop) {
    assessorCheckpointBackdrop.addEventListener("click", () => resolveAssessorCheckpoint(false));
  }

  if (dataEntryCheckpointSaveBtn) {
    dataEntryCheckpointSaveBtn.addEventListener("click", async () => {
      if (!dataEntryContextTask || !dataEntryContextTask.related_staff_id) {
        resolveDataEntryCheckpoint(false);
        return;
      }

      const nextOfKinContactRaw = String(dataEntryNextOfKinContact?.value || "").trim();
      const normalizedNextOfKinContact = nextOfKinContactRaw ? normalizePhone(nextOfKinContactRaw) : "";
      const derivedPayType = deriveTaskPayType({
        retirementType: dataEntryRetirementType,
        enlistmentDate: dataEntryCheckpointStaff?.enlistmentDate || "",
        retirementDate: dataEntryCheckpointStaff?.retirementDate || "",
        payType: dataEntryPayType ? dataEntryPayType.value : ""
      });
      const payload = {
        livingStatus: String(dataEntryRetirementType || "").toLowerCase() === "death"
          ? "Deceased"
          : (dataEntryLivingStatus ? dataEntryLivingStatus.value : ""),
        payType: derivedPayType,
        address: String(dataEntryAddress?.value || "").trim(),
        applicant_email: String(dataEntryApplicantEmail?.value || "").trim(),
        next_of_kin: String(dataEntryNextOfKin?.value || "").trim(),
        next_of_kin_contact: normalizedNextOfKinContact || nextOfKinContactRaw,
        bank_name: String(dataEntryBankName?.value || "").trim(),
        bank_account: String(dataEntryBankAccount?.value || "").trim(),
        bank_branch: String(dataEntryBankBranch?.value || "").trim()
      };

      if (!payload.livingStatus || !payload.payType) {
        appAlert("Pay type will auto-populate after the retirement label and any required service dates are available.");
        return;
      }

      if (!payload.address || !payload.bank_name || !payload.bank_account || !payload.bank_branch) {
        appAlert("District of residence and all bank details are required before continuing.");
        return;
      }

      if (payload.applicant_email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(payload.applicant_email)) {
        appAlert("Applicant email is optional, but if provided it must be valid.");
        return;
      }

      if (String(dataEntryRetirementType || "").toLowerCase() === "death" && !payload.next_of_kin) {
        appAlert("Next of kin name is required for Death retirements.");
        dataEntryNextOfKin?.focus();
        return;
      }

      if (String(dataEntryRetirementType || "").toLowerCase() === "death" && !nextOfKinContactRaw) {
        appAlert("Next of kin contact is required for Death retirements.");
        dataEntryNextOfKinContact?.focus();
        return;
      }

      if (nextOfKinContactRaw && !normalizedNextOfKinContact) {
        appAlert("Next of kin contact must be a valid phone number.");
        return;
      }

      try {
        dataEntryCheckpointSaveBtn.disabled = true;
        await saveTaskCheckpoint(dataEntryContextTask.taskId, dataEntryContextTask.related_staff_id, "data_entry_verify", payload);
        resolveDataEntryCheckpoint(true);
      } catch (err) {
        console.error("Data-entry verification save failed:", err);
        appAlert(err.message || "Unable to save data-entry verification.");
      } finally {
        dataEntryCheckpointSaveBtn.disabled = false;
      }
    });
  }

  if (dataEntryCheckpointCancelBtn) {
    dataEntryCheckpointCancelBtn.addEventListener("click", () => resolveDataEntryCheckpoint(false));
  }

  if (dataEntryCheckpointClose) {
    dataEntryCheckpointClose.addEventListener("click", () => resolveDataEntryCheckpoint(false));
  }

  if (dataEntryCheckpointBackdrop) {
    dataEntryCheckpointBackdrop.addEventListener("click", () => resolveDataEntryCheckpoint(false));
  }

  if (scheduleAdjustApplyBtn) {
    scheduleAdjustApplyBtn.addEventListener("click", async () => {
      if (!scheduleContextTaskId) {
        closeScheduleModal();
        return;
      }

      const daysValue = Number(scheduleAdjustDays?.value || "0");
      const dueRaw = (scheduleAdjustDueAt?.value || "").trim();
      const note = (scheduleAdjustNote?.value || "").trim();

      if ((!dueRaw || dueRaw === "") && (!Number.isFinite(daysValue) || daysValue <= 0)) {
        appAlert("Enter extension days or set an exact due date/time.");
        return;
      }

      const dueAtValue = dueRaw !== "" ? dueRaw.replace("T", " ") : "";

      let ok = false;
      if (scheduleContextMode === "feedback") {
        ok = await updateTaskStatus(
          scheduleContextTaskId,
          "defer",
          note,
          dueAtValue,
          "",
          "normal",
          dueAtValue ? null : Math.floor(daysValue)
        );
      } else {
        const payload = { note };
        if (dueRaw !== "") {
          payload.due_at = dueRaw.replace("T", " ");
        } else {
          payload.days = Math.floor(daysValue);
        }
        ok = await adminManageTask(scheduleContextTaskId, "extend_schedule", payload);
      }
      if (ok) {
        closeScheduleModal();
        await loadTasks();
      }
    });
  }

  document.addEventListener("keydown", (event) => {
    if (event.key !== "Escape") return;
    if (isWriteupModalOpen()) {
      resolveWriteupCheckpoint(false);
      return;
    }
    if (isAssessorModalOpen()) {
      resolveAssessorCheckpoint(false);
      return;
    }
    if (isDataEntryModalOpen()) {
      resolveDataEntryCheckpoint(false);
      return;
    }
    if (isScheduleModalOpen()) {
      closeScheduleModal();
      return;
    }
    if (isTaskQueueModalOpen()) {
      closeTaskQueueModal();
      return;
    }
    if (isTaskModalOpen()) {
      closeTaskModal();
    }
  });

  if (openTaskQueueBtn) {
    openTaskQueueBtn.addEventListener("click", async () => {
      await loadTaskCompletionQueue();
      openTaskQueueModal();
    });
  }

  if (taskQueueClose) {
    taskQueueClose.addEventListener("click", closeTaskQueueModal);
  }

  if (taskQueueBackdrop) {
    taskQueueBackdrop.addEventListener("click", closeTaskQueueModal);
  }

  refreshTasksBtn.addEventListener("click", async () => {
    await loadTasks();
  });

  if (refreshTaskAlertsBtn) {
    refreshTaskAlertsBtn.addEventListener("click", async () => {
      if (!canAccessTaskAlerts()) return;
      await loadTaskAlerts();
    });
  }

  if (taskAlertsToggleBtn) {
    taskAlertsToggleBtn.addEventListener("click", () => {
      if (!canAccessTaskAlerts()) return;
      taskAlertsCollapsed = !taskAlertsCollapsed;
      try {
        localStorage.setItem(getTaskAlertsPrefKey(), taskAlertsCollapsed ? "1" : "0");
      } catch (storageError) {
        // Ignore client storage errors and keep runtime state only.
      }
      applyTaskAlertsPanelState();
    });
  }

  if (taskStatusFilter) {
    taskStatusFilter.addEventListener("change", async () => {
      await loadTasks();
    });
  }

  if (taskBucketFilter) {
    taskBucketFilter.addEventListener("change", async () => {
      await loadTasks();
    });
  }

  if (taskUserFilter) {
    taskUserFilter.addEventListener("change", renderTasks);
  }

  if (taskRoleFilter) {
    taskRoleFilter.addEventListener("change", renderTasks);
  }

  taskSearchInput.addEventListener("input", renderTasks);

  if (taskQueueStatusFilter) {
    taskQueueStatusFilter.addEventListener("change", renderTaskCompletionQueue);
  }

  if (taskQueueSearchInput) {
    taskQueueSearchInput.addEventListener("input", renderTaskCompletionQueue);
  }

  if (refreshTaskQueueBtn) {
    refreshTaskQueueBtn.addEventListener("click", async () => {
      await loadTaskCompletionQueue();
    });
  }

  if (processTaskQueueBtn) {
    processTaskQueueBtn.addEventListener("click", async () => {
      const confirmed = await appConfirm("Process all queued task completions that are marked ready?", {
        title: "Process Completion Queue",
        confirmText: "Process Queue"
      });
      if (!confirmed) return;
      await processTaskCompletionQueue();
    });
  }

  applyTaskAlertsPanelState();
  applyTaskQueueVisibility();

  checkSession().then(async (ok) => {
    if (!ok) return;
    await initDistrictFields();
    await loadUsers();
    await loadTasks();

    window.setInterval(async () => {
      if (document.hidden) return;
      if (!canAccessTaskAlerts() || taskAlertsCollapsed) return;
      await loadTaskAlerts();
    }, 45000);
  });
});

