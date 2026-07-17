(function () {
  const state = {
    page: 1,
    limit: 20,
    totalPages: 1,
    permissions: {
      canManage: false,
      canViewStrategic: false,
      canUploadSuspension: false,
      canManageBudget: false
    },
    filters: {
      search: '',
      claimType: '',
      status: '',
      claimStatus: '',
      year: '',
      quarter: ''
    },
    searchLookupMap: new Map(),
    paymentLookupMap: new Map(),
    selectedBeneficiaryRegNo: '',
    selectedBeneficiaryDetails: null,
    accountabilityContext: null,
    paymentModalMode: 'create',
    exportSummaryFilters: null,
    exportSummaryTitle: '',
    periodOptions: null
  };

  const elements = {};
  const MONTH_LABELS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

  document.addEventListener('DOMContentLoaded', initClaimsDashboard);

  async function initClaimsDashboard() {
    bindElements();
    if (!elements.summaryCards) return;
    const hasSession = await ensureActiveSession();
    if (!hasSession) return;
    applyLaunchContext();
    bindEvents();
    initCollapsiblePanels();
    setDefaultModalDates();
    await loadClaimsDashboard();
    if (state.selectedBeneficiaryRegNo) {
      await loadSelectedBeneficiary(state.selectedBeneficiaryRegNo);
    }
    await loadSuspensionCycles();
    await loadGratuityScheduleCycles();
  }

  function applyLaunchContext() {
    const params = new URLSearchParams(window.location.search);
    const regNo = String(params.get('regNo') || '').trim();
    const claimType = String(params.get('claimType') || '').trim();
    if (regNo) {
      state.selectedBeneficiaryRegNo = regNo;
      state.filters.search = regNo;
      if (elements.searchInput) {
        elements.searchInput.value = regNo;
      }
    }
    if (claimType) {
      state.filters.claimType = claimType;
    }
  }

  function bindElements() {
    elements.feedback = document.getElementById('claimsFeedback');
    elements.summaryCards = document.getElementById('claimsSummaryCards');
    elements.typeBars = document.getElementById('claimsByTypeBars');
    elements.execStackedBar = document.getElementById('execStackedBar');
    elements.execStackedLegend = document.getElementById('execStackedLegend');
    elements.execMiniTrends = document.getElementById('execMiniTrends');
    elements.periodTableBody = document.getElementById('claimsPeriodTableBody');
    elements.ledgerBody = document.getElementById('claimsLedgerTableBody');
    elements.ledgerMeta = document.getElementById('claimsLedgerMeta');
    elements.pageIndicator = document.getElementById('claimsPageIndicator');
    elements.paymentsBody = document.getElementById('claimsPaymentsTableBody');
    elements.prevBtn = document.getElementById('claimsPrevPageBtn');
    elements.nextBtn = document.getElementById('claimsNextPageBtn');
    elements.refreshBtn = document.getElementById('refreshClaimsBtn');
    elements.collapseAllBtn = document.getElementById('claimsCollapseAllBtn');
    elements.expandAllBtn = document.getElementById('claimsExpandAllBtn');
    elements.openClaimsPaymentModalBtn = document.getElementById('openClaimsPaymentModalBtn');
    elements.openClaimsBulkPaymentModalBtn = document.getElementById('openClaimsBulkPaymentModalBtn');
    elements.openGratuityScheduleUploadModalBtn = document.getElementById('openGratuityScheduleUploadModalBtn');
    elements.openSuspensionUploadModalBtn = document.getElementById('openSuspensionUploadModalBtn');
    elements.openClaimsExportFilterBtn = document.getElementById('openClaimsExportFilterBtn');

    elements.searchInput = document.getElementById('claimsSearchInput');
    elements.searchList = document.getElementById('claimsSearchList');
    elements.typeFilter = document.getElementById('claimsTypeFilter');
    elements.statusFilter = document.getElementById('claimsStatusFilter');
    elements.claimStatusFilter = document.getElementById('claimsClaimStatusFilter');
    elements.yearFilter = document.getElementById('claimsYearFilter');
    elements.quarterFilter = document.getElementById('claimsQuarterFilter');

    elements.strategicPanel = document.getElementById('strategicClaimsPanel');
    elements.estateTotal = document.getElementById('estateExpiredTotal');
    elements.estateMale = document.getElementById('estateExpiredMale');
    elements.estateFemale = document.getElementById('estateExpiredFemale');
    elements.estateRows = document.getElementById('estateExpiredRows');
    elements.fullDueTotal = document.getElementById('fullPensionDueTotal');
    elements.fullDueMale = document.getElementById('fullPensionDueMale');
    elements.fullDueFemale = document.getElementById('fullPensionDueFemale');
    elements.fullDueRows = document.getElementById('fullPensionDueRows');

    elements.suspensionCyclesBody = document.getElementById('suspensionCyclesTableBody');
    elements.suspensionEntriesModal = document.getElementById('suspensionEntriesModal');
    elements.suspensionEntriesTitle = document.getElementById('suspensionEntriesTitle');
    elements.suspensionEntriesBody = document.getElementById('suspensionEntriesBody');
    elements.closeSuspensionEntriesBtn = document.getElementById('closeSuspensionEntriesBtn');

    elements.paymentModal = document.getElementById('claimsPaymentModal');
    elements.paymentModalTitle = document.getElementById('claimsPaymentModalTitle');
    elements.paymentForm = document.getElementById('claimsPaymentForm');
    elements.paymentIdInput = document.getElementById('paymentIdInput');
    elements.paymentRegNo = document.getElementById('paymentRegNo');
    elements.paymentClaimType = document.getElementById('paymentClaimType');
    elements.paymentBeneficiaryDisplay = document.getElementById('paymentBeneficiaryDisplay');
    elements.paymentAmountInput = document.getElementById('paymentAmountInput');
    elements.paymentDateInput = document.getElementById('paymentDateInput');
    elements.paymentRefInput = document.getElementById('paymentRefInput');
    elements.paymentNotesInput = document.getElementById('paymentNotesInput');
    elements.closePaymentModalBtn = document.getElementById('closePaymentModalBtn');
    elements.savePaymentBtn = document.getElementById('savePaymentBtn');
    elements.paymentBeneficiaryList = document.getElementById('claimsPaymentBeneficiaryList');

    elements.bulkPaymentModal = document.getElementById('claimsBulkPaymentModal');
    elements.bulkPaymentForm = document.getElementById('claimsBulkPaymentForm');
    elements.bulkPaymentFileInput = document.getElementById('claimsBulkPaymentFileInput');
    elements.downloadBulkPaymentTemplateBtn = document.getElementById('downloadBulkPaymentTemplateBtn');
    elements.bulkPaymentDefaultDate = document.getElementById('claimsBulkPaymentDefaultDate');
    elements.bulkPaymentDefaultType = document.getElementById('claimsBulkPaymentDefaultType');
    elements.closeBulkPaymentModalBtn = document.getElementById('closeBulkPaymentModalBtn');
    elements.saveBulkPaymentBtn = document.getElementById('saveBulkPaymentBtn');

    elements.gratuityScheduleCyclesBody = document.getElementById('gratuityScheduleCyclesTableBody');
    elements.gratuityScheduleUploadModal = document.getElementById('claimsGratuityScheduleUploadModal');
    elements.gratuityScheduleUploadForm = document.getElementById('claimsGratuityScheduleUploadForm');
    elements.gratuityScheduleYearInput = document.getElementById('claimsGratuityScheduleYearInput');
    elements.gratuityScheduleMonthInput = document.getElementById('claimsGratuityScheduleMonthInput');
    elements.gratuityScheduleFileInput = document.getElementById('claimsGratuityScheduleFileInput');
    elements.gratuityScheduleNotesInput = document.getElementById('claimsGratuityScheduleNotesInput');
    elements.downloadGratuityScheduleTemplateBtn = document.getElementById('downloadGratuityScheduleTemplateBtn');
    elements.closeGratuityScheduleUploadModalBtn = document.getElementById('closeGratuityScheduleUploadModalBtn');
    elements.saveGratuityScheduleUploadBtn = document.getElementById('saveGratuityScheduleUploadBtn');
    elements.gratuityScheduleEntriesModal = document.getElementById('gratuityScheduleEntriesModal');
    elements.gratuityScheduleEntriesTitle = document.getElementById('gratuityScheduleEntriesTitle');
    elements.gratuityScheduleEntriesSubtitle = document.getElementById('gratuityScheduleEntriesSubtitle');
    elements.gratuityScheduleSummaryTotal = document.getElementById('gratuityScheduleSummaryTotal');
    elements.gratuityScheduleSummaryMatched = document.getElementById('gratuityScheduleSummaryMatched');
    elements.gratuityScheduleSummaryGratuity = document.getElementById('gratuityScheduleSummaryGratuity');
    elements.gratuityScheduleSummaryAllocated = document.getElementById('gratuityScheduleSummaryAllocated');
    elements.gratuityScheduleSummaryUnallocated = document.getElementById('gratuityScheduleSummaryUnallocated');
    elements.gratuityScheduleSummaryRemaining = document.getElementById('gratuityScheduleSummaryRemaining');
    elements.gratuityScheduleEntriesBody = document.getElementById('gratuityScheduleEntriesBody');
    elements.gratuityScheduleAllocationsBody = document.getElementById('gratuityScheduleAllocationsBody');
    elements.closeGratuityScheduleEntriesBtn = document.getElementById('closeGratuityScheduleEntriesBtn');

    elements.editEntryModal = document.getElementById('claimsEditEntryModal');
    elements.editLedgerId = document.getElementById('editLedgerId');
    elements.editBeneficiaryDisplay = document.getElementById('editBeneficiaryDisplay');
    elements.editClaimTypeInput = document.getElementById('editClaimTypeInput');
    elements.editExpectedAmountInput = document.getElementById('editExpectedAmountInput');
    elements.editPeriodYearInput = document.getElementById('editPeriodYearInput');
    elements.editPeriodMonthInput = document.getElementById('editPeriodMonthInput');
    elements.editReasonInput = document.getElementById('editReasonInput');
    elements.editNotesInput = document.getElementById('editNotesInput');
    elements.closeEditEntryModalBtn = document.getElementById('closeEditEntryModalBtn');
    elements.saveEditEntryBtn = document.getElementById('saveEditEntryBtn');

    elements.suspensionUploadModal = document.getElementById('claimsSuspensionUploadModal');
    elements.claimsSuspensionUploadForm = document.getElementById('claimsSuspensionUploadForm');
    elements.claimsSuspensionYearInput = document.getElementById('claimsSuspensionYearInput');
    elements.claimsSuspensionMonthInput = document.getElementById('claimsSuspensionMonthInput');
    elements.claimsSuspensionFileInput = document.getElementById('claimsSuspensionFileInput');
    elements.downloadSuspensionUploadTemplateBtn = document.getElementById('downloadSuspensionUploadTemplateBtn');
    elements.claimsSuspensionNotesInput = document.getElementById('claimsSuspensionNotesInput');
    elements.claimsSuspensionReasonKey = document.getElementById('claimsSuspensionReasonKey');
    elements.claimsSuspensionReasonFyWrap = document.getElementById('claimsSuspensionReasonFyWrap');
    elements.claimsSuspensionReasonFy = document.getElementById('claimsSuspensionReasonFy');
    elements.closeSuspensionUploadModalBtn = document.getElementById('closeSuspensionUploadModalBtn');
    elements.saveSuspensionUploadBtn = document.getElementById('saveSuspensionUploadBtn');

    elements.accountabilityModal = document.getElementById('claimsAccountabilityModal');
    elements.accountabilityForm = document.getElementById('claimsAccountabilityForm');
    elements.accountabilityPaymentId = document.getElementById('accountabilityPaymentId');
    elements.accountabilityRegNo = document.getElementById('accountabilityRegNo');
    elements.accountabilityBeneficiaryName = document.getElementById('accountabilityBeneficiaryName');
    elements.accountabilityBeneficiaryRegNo = document.getElementById('accountabilityBeneficiaryRegNo');
    elements.accountabilitySupplierNo = document.getElementById('accountabilitySupplierNo');
    elements.accountabilityOutstanding = document.getElementById('accountabilityOutstanding');
    elements.accountabilityClaimType = document.getElementById('accountabilityClaimType');
    elements.accountabilityPaymentFy = document.getElementById('accountabilityPaymentFy');
    elements.accountabilityFiles = document.getElementById('accountabilityFiles');
    elements.accountabilityNotes = document.getElementById('accountabilityNotes');
    elements.closeAccountabilityModalBtn = document.getElementById('closeAccountabilityModalBtn');
    elements.saveAccountabilityBtn = document.getElementById('saveAccountabilityBtn');

    elements.claimsBeneficiarySummary = document.getElementById('claimsBeneficiarySummary');
    elements.claimsBeneficiaryName = document.getElementById('claimsBeneficiaryName');
    elements.claimsBeneficiaryRegNo = document.getElementById('claimsBeneficiaryRegNo');
    elements.claimsBeneficiarySupplier = document.getElementById('claimsBeneficiarySupplier');
    elements.claimsBeneficiaryContact = document.getElementById('claimsBeneficiaryContact');
    elements.claimsBeneficiaryAddress = document.getElementById('claimsBeneficiaryAddress');
    elements.claimsBeneficiaryOutstanding = document.getElementById('claimsBeneficiaryOutstanding');
    elements.exportButtons = Array.from(document.querySelectorAll('[data-claims-export]'));

    elements.exportFilterModal = document.getElementById('claimsExportFilterModal');
    elements.exportFilterForm = document.getElementById('claimsExportFilterForm');
    elements.exportFilterCloseBtn = document.getElementById('closeClaimsExportFilterBtn');
    elements.exportFilterCloseTopBtn = document.getElementById('closeClaimsExportFilterBtnTop');
    elements.exportFilterResetBtn = document.getElementById('resetClaimsExportFiltersBtn');
    elements.exportFilterPreviewBtn = document.getElementById('previewClaimsExportBtn');
    elements.exportFilterXlsxBtn = document.getElementById('exportClaimsSummaryXlsxBtn');
    elements.exportFilterPdfBtn = document.getElementById('exportClaimsSummaryPdfBtn');
    elements.exportAggregationMode = document.getElementById('claimsExportAggregationMode');
    elements.exportTypeMode = document.getElementById('claimsExportTypeMode');
    elements.exportPeriodScope = document.getElementById('claimsExportPeriodScope');
    elements.exportFinancialYearWrap = document.getElementById('claimsExportFinancialYearWrap');
    elements.exportQuarterWrap = document.getElementById('claimsExportQuarterWrap');
    elements.exportYearWrap = document.getElementById('claimsExportYearWrap');
    elements.exportMonthWrap = document.getElementById('claimsExportMonthWrap');
    elements.exportFromYearWrap = document.getElementById('claimsExportFromYearWrap');
    elements.exportFromMonthWrap = document.getElementById('claimsExportFromMonthWrap');
    elements.exportToYearWrap = document.getElementById('claimsExportToYearWrap');
    elements.exportToMonthWrap = document.getElementById('claimsExportToMonthWrap');
    elements.exportFinancialYear = document.getElementById('claimsExportFinancialYear');
    elements.exportQuarter = document.getElementById('claimsExportQuarter');
    elements.exportYear = document.getElementById('claimsExportYear');
    elements.exportMonth = document.getElementById('claimsExportMonth');
    elements.exportFromYear = document.getElementById('claimsExportFromYear');
    elements.exportFromMonth = document.getElementById('claimsExportFromMonth');
    elements.exportToYear = document.getElementById('claimsExportToYear');
    elements.exportToMonth = document.getElementById('claimsExportToMonth');
    elements.exportPeriodValueWrap = document.getElementById('claimsExportPeriodValueWrap');
    elements.exportPeriodValue = document.getElementById('claimsExportPeriodValue');
    elements.exportPeriodRangeWrap = document.getElementById('claimsExportPeriodRangeWrap');
    elements.exportPeriodFrom = document.getElementById('claimsExportPeriodFrom');
    elements.exportPeriodTo = document.getElementById('claimsExportPeriodTo');
    elements.exportSearch = document.getElementById('claimsExportSearch');
    elements.exportRetirementType = document.getElementById('claimsExportRetirementType');
    elements.exportLivingStatus = document.getElementById('claimsExportLivingStatus');
    elements.exportOutstandingOnly = document.getElementById('claimsExportOutstandingOnly');
    elements.exportIncludeSubtotal = document.getElementById('claimsExportIncludeSubtotal');
    elements.exportClaimTypesWrap = document.getElementById('claimsExportClaimTypes');
    elements.exportStatusWrap = document.getElementById('claimsExportStatus');
    elements.exportClaimStatusWrap = document.getElementById('claimsExportClaimStatus');
    elements.exportExtraColumnsWrap = document.getElementById('claimsExportExtraColumns');

    elements.exportPreviewModal = document.getElementById('claimsExportPreviewModal');
    elements.exportPreviewCloseBtn = document.getElementById('closeClaimsExportPreviewBtn');
    elements.exportPreviewCloseTopBtn = document.getElementById('closeClaimsExportPreviewBtnTop');
    elements.exportPreviewHead = document.getElementById('claimsExportPreviewHead');
    elements.exportPreviewBody = document.getElementById('claimsExportPreviewBody');
    elements.exportPreviewMeta = document.getElementById('claimsExportPreviewMeta');
    elements.exportPreviewNote = document.getElementById('claimsExportPreviewNote');
    elements.exportPreviewXlsxBtn = document.getElementById('exportClaimsSummaryXlsxBtnPreview');
    elements.exportPreviewPdfBtn = document.getElementById('exportClaimsSummaryPdfBtnPreview');
    elements.exportPreviewPrevBtn = document.getElementById('claimsExportPreviewPrevBtn');
    elements.exportPreviewNextBtn = document.getElementById('claimsExportPreviewNextBtn');
    elements.exportPreviewPage = document.getElementById('claimsExportPreviewPage');
  }

  function parseMoneyInputValue(value, fallback = 0) {
    if (window.PensionsGoMoney?.parse) {
      return window.PensionsGoMoney.parse(value, fallback);
    }
    const parsed = Number.parseFloat(String(value || '').replace(/,/g, ''));
    return Number.isFinite(parsed) ? parsed : fallback;
  }

  function setMoneyInputValue(field, value) {
    if (!field) return;
    if (window.PensionsGoMoney?.setInputValue) {
      window.PensionsGoMoney.setInputValue(field, value);
      return;
    }
    field.value = value ?? '';
  }

  function bindEvents() {
    if (elements.refreshBtn) {
      elements.refreshBtn.addEventListener('click', () => {
        loadClaimsDashboard();
        loadSuspensionCycles();
        loadGratuityScheduleCycles();
      });
    }

    if (elements.collapseAllBtn) {
      elements.collapseAllBtn.addEventListener('click', () => setAllPanelsCollapsed(true));
    }

    if (elements.expandAllBtn) {
      elements.expandAllBtn.addEventListener('click', () => setAllPanelsCollapsed(false));
    }

    if (elements.openClaimsPaymentModalBtn) {
      elements.openClaimsPaymentModalBtn.addEventListener('click', () => openPaymentModal({}));
    }

    if (elements.openClaimsBulkPaymentModalBtn) {
      elements.openClaimsBulkPaymentModalBtn.addEventListener('click', () => {
        if (elements.bulkPaymentDefaultDate && !elements.bulkPaymentDefaultDate.value) {
          elements.bulkPaymentDefaultDate.value = new Date().toISOString().slice(0, 10);
        }
        openModal(elements.bulkPaymentModal);
      });
    }

    if (elements.openGratuityScheduleUploadModalBtn) {
      elements.openGratuityScheduleUploadModalBtn.addEventListener('click', () => {
        setDefaultModalDates();
        openModal(elements.gratuityScheduleUploadModal);
      });
    }

    if (elements.openSuspensionUploadModalBtn) {
      elements.openSuspensionUploadModalBtn.addEventListener('click', () => {
        setDefaultModalDates();
        openModal(elements.suspensionUploadModal);
      });
    }

    if (elements.openClaimsExportFilterBtn) {
      elements.openClaimsExportFilterBtn.addEventListener('click', () => {
        loadClaimsPeriodOptions();
        updateExportPeriodVisibility();
        openModal(elements.exportFilterModal);
      });
    }

    if (elements.exportFilterCloseBtn) {
      elements.exportFilterCloseBtn.addEventListener('click', () => closeModal(elements.exportFilterModal));
    }
    if (elements.exportFilterCloseTopBtn) {
      elements.exportFilterCloseTopBtn.addEventListener('click', () => closeModal(elements.exportFilterModal));
    }
    if (elements.exportFilterResetBtn) {
      elements.exportFilterResetBtn.addEventListener('click', resetExportFilters);
    }
    if (elements.exportFilterPreviewBtn) {
      elements.exportFilterPreviewBtn.addEventListener('click', () => previewClaimsAggregation());
    }
    if (elements.exportFilterXlsxBtn) {
      elements.exportFilterXlsxBtn.addEventListener('click', () => exportClaimsAggregation('xlsx'));
    }
    if (elements.exportFilterPdfBtn) {
      elements.exportFilterPdfBtn.addEventListener('click', () => exportClaimsAggregation('pdf'));
    }
    if (elements.exportPreviewCloseBtn) {
      elements.exportPreviewCloseBtn.addEventListener('click', () => closeExportPreview());
    }
    if (elements.exportPreviewCloseTopBtn) {
      elements.exportPreviewCloseTopBtn.addEventListener('click', () => closeExportPreview());
    }
    if (elements.exportPreviewXlsxBtn) {
      elements.exportPreviewXlsxBtn.addEventListener('click', () => exportClaimsAggregation('xlsx'));
    }
    if (elements.exportPreviewPdfBtn) {
      elements.exportPreviewPdfBtn.addEventListener('click', () => exportClaimsAggregation('pdf'));
    }
    if (elements.exportPeriodScope) {
      elements.exportPeriodScope.addEventListener('change', updateExportPeriodVisibility);
    }
    if (elements.exportPeriodValue) {
      elements.exportPeriodValue.addEventListener('change', () => {
        applyPeriodValueSelection();
      });
    }
    if (elements.exportPeriodFrom) {
      elements.exportPeriodFrom.addEventListener('change', () => applyPeriodRangeSelection());
    }
    if (elements.exportPeriodTo) {
      elements.exportPeriodTo.addEventListener('change', () => applyPeriodRangeSelection());
    }
    if (elements.exportFinancialYear) {
      elements.exportFinancialYear.addEventListener('change', () => {
        syncQuarterOptions();
      });
    }
    if (elements.exportYear) {
      elements.exportYear.addEventListener('change', () => {
        syncMonthOptionsForYear(elements.exportYear, elements.exportMonth, 'Select Month');
      });
    }
    if (elements.exportFromYear) {
      elements.exportFromYear.addEventListener('change', () => {
        syncMonthOptionsForYear(elements.exportFromYear, elements.exportFromMonth, 'Select Month');
      });
    }
    if (elements.exportToYear) {
      elements.exportToYear.addEventListener('change', () => {
        syncMonthOptionsForYear(elements.exportToYear, elements.exportToMonth, 'Select Month');
      });
    }
    if (elements.exportPreviewPrevBtn) {
      elements.exportPreviewPrevBtn.addEventListener('click', () => {
        const page = Math.max(1, Number(state.exportPreviewPage || 1) - 1);
        previewClaimsAggregation(page);
      });
    }
    if (elements.exportPreviewNextBtn) {
      elements.exportPreviewNextBtn.addEventListener('click', () => {
        const page = Math.min(Number(state.exportPreviewTotalPages || 1), Number(state.exportPreviewPage || 1) + 1);
        previewClaimsAggregation(page);
      });
    }
    if (elements.exportTypeMode) {
      elements.exportTypeMode.addEventListener('change', updateExportTypeVisibility);
      updateExportTypeVisibility();
    }

    const onFilterChange = () => {
      state.page = 1;
      loadClaimsDashboard();
    };

    if (elements.typeFilter) elements.typeFilter.addEventListener('change', onFilterChange);
    if (elements.statusFilter) elements.statusFilter.addEventListener('change', onFilterChange);
    if (elements.claimStatusFilter) elements.claimStatusFilter.addEventListener('change', onFilterChange);
    if (elements.yearFilter) elements.yearFilter.addEventListener('change', onFilterChange);
    if (elements.quarterFilter) elements.quarterFilter.addEventListener('change', onFilterChange);
    if (elements.searchInput) {
      elements.searchInput.addEventListener('input', debounce(async () => {
        await loadBeneficiarySuggestions(elements.searchInput.value);
        const regNo = syncSearchBeneficiarySelection();
        if (regNo) {
          await loadSelectedBeneficiary(regNo);
        } else {
          hideSelectedBeneficiary();
        }
        onFilterChange();
      }, 260));
      elements.searchInput.addEventListener('change', async () => {
        const regNo = syncSearchBeneficiarySelection();
        if (regNo) {
          await loadSelectedBeneficiary(regNo);
          loadClaimsDashboard();
        }
      });
    }

    if (elements.prevBtn) {
      elements.prevBtn.addEventListener('click', () => {
        if (state.page <= 1) return;
        state.page -= 1;
        loadClaimsDashboard();
      });
    }

    if (elements.nextBtn) {
      elements.nextBtn.addEventListener('click', () => {
        if (state.page >= state.totalPages) return;
        state.page += 1;
        loadClaimsDashboard();
      });
    }

    if (Array.isArray(elements.exportButtons)) {
      elements.exportButtons.forEach((button) => {
        button.addEventListener('click', () => exportClaimsTable(button));
      });
    }

    if (elements.closePaymentModalBtn) {
      elements.closePaymentModalBtn.addEventListener('click', closePaymentModal);
    }

    if (elements.savePaymentBtn) {
      elements.savePaymentBtn.addEventListener('click', submitPayment);
    }

    if (elements.paymentClaimType) {
      elements.paymentClaimType.addEventListener('change', () => {
        syncPaymentBeneficiarySelection();
        void populatePaymentAmountFromOutstanding();
      });
    }

    if (elements.closeBulkPaymentModalBtn) {
      elements.closeBulkPaymentModalBtn.addEventListener('click', () => closeModal(elements.bulkPaymentModal));
    }

    if (elements.saveBulkPaymentBtn) {
      elements.saveBulkPaymentBtn.addEventListener('click', submitBulkPayments);
    }

    if (elements.downloadBulkPaymentTemplateBtn) {
      elements.downloadBulkPaymentTemplateBtn.addEventListener('click', downloadBulkPaymentTemplate);
    }

    if (elements.downloadGratuityScheduleTemplateBtn) {
      elements.downloadGratuityScheduleTemplateBtn.addEventListener('click', downloadGratuityScheduleTemplate);
    }

    if (elements.closeGratuityScheduleUploadModalBtn) {
      elements.closeGratuityScheduleUploadModalBtn.addEventListener('click', () => closeModal(elements.gratuityScheduleUploadModal));
    }

    if (elements.saveGratuityScheduleUploadBtn) {
      elements.saveGratuityScheduleUploadBtn.addEventListener('click', submitGratuityScheduleUpload);
    }

    if (elements.closeSuspensionEntriesBtn) {
      elements.closeSuspensionEntriesBtn.addEventListener('click', () => closeModal(elements.suspensionEntriesModal));
    }

    if (elements.closeGratuityScheduleEntriesBtn) {
      elements.closeGratuityScheduleEntriesBtn.addEventListener('click', () => closeModal(elements.gratuityScheduleEntriesModal));
    }

    if (elements.closeEditEntryModalBtn) {
      elements.closeEditEntryModalBtn.addEventListener('click', () => closeModal(elements.editEntryModal));
    }

    if (elements.saveEditEntryBtn) {
      elements.saveEditEntryBtn.addEventListener('click', submitEditEntry);
    }

    if (elements.closeSuspensionUploadModalBtn) {
      elements.closeSuspensionUploadModalBtn.addEventListener('click', () => closeModal(elements.suspensionUploadModal));
    }

    if (elements.saveSuspensionUploadBtn) {
      elements.saveSuspensionUploadBtn.addEventListener('click', submitSuspensionUpload);
    }

    if (elements.downloadSuspensionUploadTemplateBtn) {
      elements.downloadSuspensionUploadTemplateBtn.addEventListener('click', downloadSuspensionUploadTemplate);
    }

    if (elements.claimsSuspensionReasonKey) {
      elements.claimsSuspensionReasonKey.addEventListener('change', toggleSuspensionReasonFyVisibility);
      toggleSuspensionReasonFyVisibility();
    }

    if (elements.closeAccountabilityModalBtn) {
      elements.closeAccountabilityModalBtn.addEventListener('click', closeAccountabilityModal);
    }

    if (elements.saveAccountabilityBtn) {
      elements.saveAccountabilityBtn.addEventListener('click', submitAccountability);
    }

    if (elements.paymentBeneficiaryDisplay) {
      elements.paymentBeneficiaryDisplay.addEventListener('input', debounce(async () => {
        await loadBeneficiarySuggestions(elements.paymentBeneficiaryDisplay.value, 'payment');
        syncPaymentBeneficiarySelection();
        await populatePaymentAmountFromOutstanding();
      }, 250));
      elements.paymentBeneficiaryDisplay.addEventListener('change', async () => {
        syncPaymentBeneficiarySelection();
        await populatePaymentAmountFromOutstanding();
      });
      elements.paymentBeneficiaryDisplay.addEventListener('blur', async () => {
        syncPaymentBeneficiarySelection();
        await populatePaymentAmountFromOutstanding();
      });
    }

    if (elements.paymentModal) {
      elements.paymentModal.addEventListener('click', (event) => {
        if (event.target === elements.paymentModal) closePaymentModal();
      });
    }

    if (elements.bulkPaymentModal) {
      elements.bulkPaymentModal.addEventListener('click', (event) => {
        if (event.target === elements.bulkPaymentModal) closeModal(elements.bulkPaymentModal);
      });
    }

    if (elements.gratuityScheduleUploadModal) {
      elements.gratuityScheduleUploadModal.addEventListener('click', (event) => {
        if (event.target === elements.gratuityScheduleUploadModal) closeModal(elements.gratuityScheduleUploadModal);
      });
    }

    if (elements.suspensionEntriesModal) {
      elements.suspensionEntriesModal.addEventListener('click', (event) => {
        if (event.target === elements.suspensionEntriesModal) closeModal(elements.suspensionEntriesModal);
      });
    }

    if (elements.gratuityScheduleEntriesModal) {
      elements.gratuityScheduleEntriesModal.addEventListener('click', (event) => {
        if (event.target === elements.gratuityScheduleEntriesModal) closeModal(elements.gratuityScheduleEntriesModal);
      });
    }

    if (elements.editEntryModal) {
      elements.editEntryModal.addEventListener('click', (event) => {
        if (event.target === elements.editEntryModal) closeModal(elements.editEntryModal);
      });
    }

    if (elements.suspensionUploadModal) {
      elements.suspensionUploadModal.addEventListener('click', (event) => {
        if (event.target === elements.suspensionUploadModal) closeModal(elements.suspensionUploadModal);
      });
    }

    if (elements.accountabilityModal) {
      elements.accountabilityModal.addEventListener('click', (event) => {
        if (event.target === elements.accountabilityModal) closeAccountabilityModal();
      });
    }
  }

  async function loadClaimsDashboard() {
    readFilters();
    showFeedback('', '');
    setSummaryLoadingState();

    const query = new URLSearchParams({
      page: String(state.page),
      limit: String(state.limit)
    });

    if (state.filters.search) query.set('search', state.filters.search);
    if (state.filters.claimType) query.set('claim_type', state.filters.claimType);
    if (state.filters.status) query.set('status', state.filters.status);
    if (state.filters.year) query.set('year', state.filters.year);
    if (state.filters.quarter) query.set('quarter', state.filters.quarter);

    try {
      const response = await fetch(`../backend/api/get_claims_dashboard.php?${query.toString()}`, {
        credentials: 'include',
        cache: 'no-store'
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error(data.message || `HTTP ${response.status}`);
      }

      state.totalPages = Number(data.pagination?.totalPages || 1);
      state.permissions = data.permissions || state.permissions;

      applyPermissionVisibility();
      renderSummaryCards(data.summary || {});
      renderByTypeBars(data.byType || []);
      renderExecutiveCharts(data.byType || [], data.quarterly || []);
      renderPeriodTable(data.quarterly || [], data.yearly || []);
      renderLedgerRows(data.rows || []);
      renderRecentPayments(data.recentPayments || []);
      renderStrategicPanel(data.strategic || {}, Boolean(state.permissions.canViewStrategic));
      populateClaimTypeOptions(data.claimTypeOptions || []);
      populateYearOptions(data.yearly || []);
      updatePaginationDisplay(data.pagination || {});
    } catch (error) {
      console.error('Claims dashboard error:', error);
      showFeedback(error.message || 'Failed to load claims dashboard', 'error');
      showModalMessage(error.message || 'Failed to load claims dashboard', 'error');
      renderEmptyLedger('Unable to load claims data.');
      renderRecentPayments([]);
      setSummaryEmptyState();
      renderExecutiveCharts([], []);
    }
  }

  async function loadSuspensionCycles() {
    if (!elements.suspensionCyclesBody) return;
    elements.suspensionCyclesBody.innerHTML = '<tr><td colspan="8">Loading...</td></tr>';

    try {
      const response = await fetch('../backend/api/get_suspension_uploads.php?limit=10', {
        credentials: 'include',
        cache: 'no-store'
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error(data.message || `HTTP ${response.status}`);
      }

      const rows = Array.isArray(data.cycles) ? data.cycles : [];
      if (!rows.length) {
        elements.suspensionCyclesBody.innerHTML = '<tr><td colspan="8">No suspension uploads yet.</td></tr>';
        return;
      }

      elements.suspensionCyclesBody.innerHTML = '';
      rows.forEach((cycle) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td>${escapeHtml(formatMonthYear(cycle.month, cycle.year))}</td>
          <td>${escapeHtml(cycle.reasonLabel || 'Row-level reasons captured in upload file')}</td>
          <td>${Number(cycle.totalRows || 0)}</td>
          <td>${Number(cycle.matchedRows || 0)}</td>
          <td>${Number(cycle.unmatchedRows || 0)}</td>
          <td>${formatCurrency(cycle.savedAmount || cycle.totalAmount || 0)}</td>
          <td>${escapeHtml(formatDateTime(cycle.createdAt))}</td>
          <td><button class="claims-btn" type="button" data-cycle-id="${Number(cycle.cycleId || 0)}">View Rows</button></td>
        `;
        const btn = tr.querySelector('button[data-cycle-id]');
        if (btn) {
          btn.addEventListener('click', () => {
            const id = Number(btn.getAttribute('data-cycle-id') || 0);
            if (id > 0) {
              loadSuspensionEntries(id, cycle);
            }
          });
        }
        elements.suspensionCyclesBody.appendChild(tr);
      });
    } catch (error) {
      console.error('Suspension cycles error:', error);
      elements.suspensionCyclesBody.innerHTML = '<tr><td colspan="8">Failed to load suspension uploads.</td></tr>';
      showModalMessage('Failed to load suspension uploads.', 'error');
    }
  }

  async function loadSuspensionEntries(cycleId, cycle) {
    try {
      const response = await fetch(`../backend/api/get_suspension_uploads.php?cycle_id=${encodeURIComponent(String(cycleId))}`, {
        credentials: 'include',
        cache: 'no-store'
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error(data.message || `HTTP ${response.status}`);
      }

      const entries = Array.isArray(data.entries) ? data.entries : [];
      if (elements.suspensionEntriesTitle) {
        const cycleReason = String(cycle.reasonLabel || '').trim();
        elements.suspensionEntriesTitle.textContent = cycleReason
          ? `Suspension Entries - ${formatMonthYear(cycle.month, cycle.year)} (${cycleReason})`
          : `Suspension Entries - ${formatMonthYear(cycle.month, cycle.year)}`;
      }

      if (!entries.length) {
        elements.suspensionEntriesBody.innerHTML = '<tr><td colspan="6">No entries found for this upload cycle.</td></tr>';
      } else {
        elements.suspensionEntriesBody.innerHTML = '';
        entries.forEach((entry) => {
          const tr = document.createElement('tr');
          tr.innerHTML = `
            <td>${escapeHtml(entry.regNo || '')}</td>
            <td>${escapeHtml(entry.supplierNo || '')}</td>
            <td>${escapeHtml(entry.beneficiaryName || '')}</td>
            <td>${formatCurrency(entry.amount || 0)}</td>
            <td>${escapeHtml(entry.reason || '')}</td>
            <td>${entry.isMatched ? `<span class="claims-badge">${escapeHtml(entry.matchedRegNo || '')}</span>` : '<span class="claims-badge">No</span>'}</td>
          `;
          elements.suspensionEntriesBody.appendChild(tr);
        });
      }

      openModal(elements.suspensionEntriesModal);
    } catch (error) {
      console.error('Suspension entries error:', error);
      showFeedback(error.message || 'Failed to load suspension entries.', 'error');
      showModalMessage(error.message || 'Failed to load suspension entries.', 'error');
    }
  }

  async function loadGratuityScheduleCycles() {
    if (!elements.gratuityScheduleCyclesBody) return;
    elements.gratuityScheduleCyclesBody.innerHTML = '<tr><td colspan="8">Loading...</td></tr>';

    try {
      const response = await fetch('../backend/api/get_gratuity_schedule_uploads.php?limit=10', {
        credentials: 'include',
        cache: 'no-store'
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error(data.message || `HTTP ${response.status}`);
      }

      const rows = Array.isArray(data.cycles) ? data.cycles : [];
      if (!rows.length) {
        elements.gratuityScheduleCyclesBody.innerHTML = '<tr><td colspan="8">No monthly gratuity schedules uploaded yet.</td></tr>';
        return;
      }

      elements.gratuityScheduleCyclesBody.innerHTML = '';
      rows.forEach((cycle) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td>${escapeHtml(formatMonthYear(cycle.month, cycle.year))}</td>
          <td>${Number(cycle.totalRows || 0)}</td>
          <td>${Number(cycle.matchedRows || 0)} / ${Number(cycle.unmatchedRows || 0)}</td>
          <td>${formatCurrency(cycle.totalGratuityComponent || 0)}</td>
          <td>${formatCurrency(cycle.totalAllocatedPensionAmount || 0)}</td>
          <td>${formatCurrency(cycle.totalUnallocatedAmount || 0)}</td>
          <td>${escapeHtml(formatDateTime(cycle.createdAt))}</td>
          <td><button class="claims-btn" type="button" data-gratuity-cycle-id="${Number(cycle.cycleId || 0)}">View Analysis</button></td>
        `;
        const btn = tr.querySelector('button[data-gratuity-cycle-id]');
        if (btn) {
          btn.addEventListener('click', () => {
            const id = Number(btn.getAttribute('data-gratuity-cycle-id') || 0);
            if (id > 0) {
              loadGratuityScheduleEntries(id, cycle);
            }
          });
        }
        elements.gratuityScheduleCyclesBody.appendChild(tr);
      });
    } catch (error) {
      console.error('Gratuity schedule cycles error:', error);
      elements.gratuityScheduleCyclesBody.innerHTML = '<tr><td colspan="8">Failed to load monthly gratuity schedules.</td></tr>';
      showModalMessage(error.message || 'Failed to load monthly gratuity schedules.', 'error');
    }
  }

  async function loadGratuityScheduleEntries(cycleId, cycle) {
    try {
      const response = await fetch(`../backend/api/get_gratuity_schedule_uploads.php?cycle_id=${encodeURIComponent(String(cycleId))}`, {
        credentials: 'include',
        cache: 'no-store'
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error(data.message || `HTTP ${response.status}`);
      }

      const detail = data.cycle || cycle || {};
      const entries = Array.isArray(data.entries) ? data.entries : [];
      const allocations = Array.isArray(data.allocations) ? data.allocations : [];

      if (elements.gratuityScheduleEntriesTitle) {
        elements.gratuityScheduleEntriesTitle.textContent = `Monthly Gratuity Schedule - ${formatMonthYear(detail.month, detail.year) || 'Detail'}`;
      }
      if (elements.gratuityScheduleEntriesSubtitle) {
        const financialYear = String(detail.financialYear || '').trim();
        elements.gratuityScheduleEntriesSubtitle.textContent = financialYear
          ? `Financial year ${financialYear}. Matched rows are broken down into gratuity coverage, pension-arrears month coverage, and unresolved surplus.`
          : 'Review row-by-row analysis and pension-arrears month allocations.';
      }
      if (elements.gratuityScheduleSummaryTotal) {
        elements.gratuityScheduleSummaryTotal.textContent = formatCurrency(detail.totalScheduledAmount || 0);
      }
      if (elements.gratuityScheduleSummaryMatched) {
        elements.gratuityScheduleSummaryMatched.textContent = `${Number(detail.matchedRows || 0)} matched / ${Number(detail.unmatchedRows || 0)} unmatched`;
      }
      if (elements.gratuityScheduleSummaryGratuity) {
        elements.gratuityScheduleSummaryGratuity.textContent = formatCurrency(detail.totalGratuityComponent || 0);
      }
      if (elements.gratuityScheduleSummaryAllocated) {
        elements.gratuityScheduleSummaryAllocated.textContent = formatCurrency(detail.totalAllocatedPensionAmount || 0);
      }
      if (elements.gratuityScheduleSummaryUnallocated) {
        elements.gratuityScheduleSummaryUnallocated.textContent = formatCurrency(detail.totalUnallocatedAmount || 0);
      }
      if (elements.gratuityScheduleSummaryRemaining) {
        elements.gratuityScheduleSummaryRemaining.textContent = formatCurrency(detail.totalRemainingArrearsAmount || 0);
      }

      if (!entries.length) {
        elements.gratuityScheduleEntriesBody.innerHTML = '<tr><td colspan="11">No schedule analysis entries were found for this cycle.</td></tr>';
      } else {
        elements.gratuityScheduleEntriesBody.innerHTML = '';
        entries.forEach((entry) => {
          const tr = document.createElement('tr');
          tr.innerHTML = `
            <td>${Number(entry.rowNumber || 0)}</td>
            <td>${escapeHtml(entry.matchedRegNo || entry.regNo || '')}</td>
            <td>${escapeHtml(entry.supplierNo || '')}</td>
            <td>${escapeHtml(entry.matchedName || entry.beneficiaryName || '')}</td>
            <td>${formatCurrency(entry.scheduledAmount || 0)}</td>
            <td>${formatCurrency(entry.registryGratuityEstimate || 0)}</td>
            <td>${formatCurrency(entry.latestMonthlyPension || 0)}</td>
            <td>${escapeHtml(formatGratuityScheduleClassification(entry.classification || ''))}</td>
            <td>${Number(entry.allocatedMonths || 0)} / ${Number(entry.scheduledFullMonths || 0)}</td>
            <td>${formatCurrency(entry.remainingArrearsAmount || 0)}</td>
            <td>${escapeHtml(entry.analysisNote || '')}</td>
          `;
          elements.gratuityScheduleEntriesBody.appendChild(tr);
        });
      }

      if (!allocations.length) {
        elements.gratuityScheduleAllocationsBody.innerHTML = '<tr><td colspan="7">No pension-arrears month allocations were created for this cycle.</td></tr>';
      } else {
        elements.gratuityScheduleAllocationsBody.innerHTML = '';
        allocations.forEach((allocation) => {
          const tr = document.createElement('tr');
          tr.innerHTML = `
            <td>${escapeHtml(allocation.matchedRegNo || '')}</td>
            <td>${escapeHtml(allocation.matchedName || allocation.beneficiaryName || '')}</td>
            <td>${escapeHtml(formatMonthYear(allocation.periodMonth, allocation.periodYear))}</td>
            <td>${formatCurrency(allocation.allocatedAmount || 0)}</td>
            <td>${formatCurrency(allocation.monthlyPensionAmount || 0)}</td>
            <td>${escapeHtml(allocation.allocationStatus || '')}</td>
            <td>${escapeHtml(allocation.note || '')}</td>
          `;
          elements.gratuityScheduleAllocationsBody.appendChild(tr);
        });
      }

      openModal(elements.gratuityScheduleEntriesModal);
    } catch (error) {
      console.error('Gratuity schedule detail error:', error);
      showFeedback(error.message || 'Failed to load gratuity schedule detail.', 'error');
      showModalMessage(error.message || 'Failed to load gratuity schedule detail.', 'error');
    }
  }

  function renderSummaryCards(summary) {
    if (!elements.summaryCards) return;

    const cards = [
      { label: 'Expected Arrears', value: formatCurrency(summary.expectedTotal || 0) },
      { label: 'Paid Arrears', value: formatCurrency(summary.paidTotal || 0) },
      { label: 'Outstanding Balance', value: formatCurrency(summary.balanceTotal || 0) },
      { label: 'Pending Accountability', value: Number(summary.pendingAccountabilityCount || 0).toLocaleString() },
      { label: 'Accountability Submitted', value: Number(summary.accountabilitySubmittedCount || 0).toLocaleString() },
      { label: 'Ledger Records', value: Number(summary.entryCount || 0).toLocaleString() },
      { label: 'Open Claims', value: Number(summary.openCount || 0).toLocaleString() }
    ];

    elements.summaryCards.innerHTML = '';
    cards.forEach((card) => {
      const article = document.createElement('article');
      article.className = 'claims-kpi';
      article.innerHTML = `
        <span class="claims-kpi-label">${escapeHtml(card.label)}</span>
        <span class="claims-kpi-value">${escapeHtml(card.value)}</span>
      `;
      elements.summaryCards.appendChild(article);
    });
  }

  function renderByTypeBars(rows) {
    if (!elements.typeBars) return;
    if (!rows.length) {
      elements.typeBars.innerHTML = '<div class="claims-panel-muted">No claim type data found.</div>';
      return;
    }

    elements.typeBars.innerHTML = '';
    rows.forEach((row) => {
      const expected = Number(row.expected || 0);
      const paid = Number(row.paid || 0);
      const paidRatio = expected > 0 ? Math.min(100, Math.round((paid / expected) * 100)) : 0;
      const paidWidth = paidRatio > 0 ? Math.max(2, paidRatio) : 0;
      const wrap = document.createElement('div');
      wrap.className = 'claims-type-row';
      wrap.innerHTML = `
        <span class="claims-type-label">${escapeHtml(row.claimType || 'Unknown')}</span>
        <span class="claims-type-track"><span class="claims-type-fill" style="width:${paidWidth}%;"></span></span>
        <span class="claims-type-value">${Number(row.entries || 0)} entries · ${escapeHtml(formatCompactCurrencyDetailed(paid))} paid / ${escapeHtml(formatCompactCurrencyDetailed(expected))} expected</span>
      `;
      elements.typeBars.appendChild(wrap);
    });
  }

  function renderExecutiveCharts(byTypeRows, quarterlyRows) {
    renderStackedComposition(byTypeRows || []);
    renderMiniQuarterTrends(quarterlyRows || []);
  }

  function renderStackedComposition(rows) {
    if (!elements.execStackedBar || !elements.execStackedLegend) return;
    if (!rows.length) {
      elements.execStackedBar.innerHTML = '';
      elements.execStackedLegend.innerHTML = '<div class="claims-panel-muted">No composition data available.</div>';
      return;
    }

    const totals = rows.map((item) => ({
      label: String(item.claimType || 'Unknown'),
      balance: Math.max(0, Number(item.balance || 0))
    }));
    const totalBalance = totals.reduce((sum, item) => sum + item.balance, 0);
    if (totalBalance <= 0) {
      elements.execStackedBar.innerHTML = '';
      elements.execStackedLegend.innerHTML = '<div class="claims-panel-muted">All balances are fully settled.</div>';
      return;
    }

    elements.execStackedBar.innerHTML = '';
    elements.execStackedLegend.innerHTML = '';

    totals.forEach((item, idx) => {
      const width = Math.max(2, Math.round((item.balance / totalBalance) * 100));
      const color = chartColor(idx);

      const segment = document.createElement('span');
      segment.className = 'claims-stacked-segment';
      segment.style.width = `${width}%`;
      segment.style.background = color;
      segment.title = `${item.label}: ${formatCurrency(item.balance)} (${width}%)`;
      elements.execStackedBar.appendChild(segment);

      const legend = document.createElement('div');
      legend.className = 'claims-legend-item';
      legend.innerHTML = `
        <span class="claims-legend-swatch" style="background:${escapeHtml(color)};"></span>
        <span class="claims-legend-text">${escapeHtml(item.label)} - ${escapeHtml(formatCurrency(item.balance))}</span>
      `;
      elements.execStackedLegend.appendChild(legend);
    });
  }

  function renderMiniQuarterTrends(rows) {
    if (!elements.execMiniTrends) return;
    if (!rows.length) {
      elements.execMiniTrends.innerHTML = '<div class="claims-panel-muted">No quarter trend data available.</div>';
      return;
    }

    const latest = rows.slice(0, 6);
    const maxValue = latest.reduce((max, row) => {
      return Math.max(max, Number(row.expected || 0), Number(row.paid || 0), Number(row.balance || 0));
    }, 0) || 1;

    elements.execMiniTrends.innerHTML = '';
    latest.forEach((row) => {
      const expected = Math.max(0, Number(row.expected || 0));
      const paid = Math.max(0, Number(row.paid || 0));
      const balance = Math.max(0, Number(row.balance || 0));
      const label = `${row.financialYear || ''} ${row.quarter || ''}`.trim() || 'Quarter';

      const card = document.createElement('article');
      card.className = 'claims-mini-trend-card';
      card.innerHTML = `
        <div class="claims-mini-trend-label">${escapeHtml(label)}</div>
        <div class="claims-mini-trend-values">
          ${renderMiniMetric('Expected', expected, maxValue, '#7a1420')}
          ${renderMiniMetric('Paid', paid, maxValue, '#0f7a4b')}
          ${renderMiniMetric('Balance', balance, maxValue, '#1d4b8f')}
        </div>
      `;
      elements.execMiniTrends.appendChild(card);
    });
  }

  function renderMiniMetric(label, value, maxValue, color) {
    const width = Math.max(2, Math.round((value / maxValue) * 100));
    return `
      <div class="claims-mini-trend-row">
        <span>${escapeHtml(label)}</span>
        <span class="claims-mini-track"><span class="claims-mini-fill" style="width:${width}%; background:${escapeHtml(color)};"></span></span>
        <span>${escapeHtml(formatCompactCurrency(value))}</span>
      </div>
    `;
  }

  function renderPeriodTable(quarterlyRows, yearlyRows) {
    if (!elements.periodTableBody) return;
    elements.periodTableBody.innerHTML = '';

    const quarterList = quarterlyRows.map((row) => ({
      period: `${row.financialYear || ''} ${row.quarter || ''}`.trim(),
      entries: Number(row.entries || 0),
      expected: Number(row.expected || 0),
      paid: Number(row.paid || 0),
      balance: Number(row.balance || 0)
    }));

    const yearList = yearlyRows.map((row) => ({
      period: row.year ? `Year ${row.year}` : '',
      entries: Number(row.entries || 0),
      expected: Number(row.expected || 0),
      paid: Number(row.paid || 0),
      balance: Number(row.balance || 0)
    }));

    const dedupePeriods = (list) => {
      const seen = new Set();
      return list.filter((row) => {
        const key = row.period || '';
        if (!key) return false;
        if (seen.has(key)) return false;
        seen.add(key);
        return true;
      });
    };

    const quarterUnique = dedupePeriods(quarterList);
    const yearUnique = dedupePeriods(yearList);
    const showQuarterOnly = Boolean(state.filters.quarter);
    const showYearOnly = !showQuarterOnly && Boolean(state.filters.year);
    const merged = showQuarterOnly
      ? quarterUnique
      : (showYearOnly ? yearUnique : quarterUnique.concat(yearUnique));

    const capped = merged.slice(0, showQuarterOnly ? 12 : (showYearOnly ? 8 : 12));
    if (!merged.length) {
      elements.periodTableBody.innerHTML = '<tr><td colspan="5">No period aggregates found.</td></tr>';
      return;
    }

    capped.forEach((row) => {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${escapeHtml(row.period)}</td>
        <td>${row.entries}</td>
        <td>${formatCurrency(row.expected)}</td>
        <td>${formatCurrency(row.paid)}</td>
        <td>${formatCurrency(row.balance)}</td>
      `;
      elements.periodTableBody.appendChild(tr);
    });
  }

  function renderLedgerRows(rows) {
    if (!elements.ledgerBody) return;
    if (!rows.length) {
      renderEmptyLedger('No ledger records found for the selected filters.');
      return;
    }

    elements.ledgerBody.innerHTML = '';
    rows.forEach((row) => {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${escapeHtml(row.regNo || '')}</td>
        <td>${escapeHtml(row.title || '')}</td>
        <td>${escapeHtml(row.displayName || row.name || '')}</td>
        <td>${escapeHtml(row.claimType || '')}</td>
        <td>${escapeHtml(formatMonthYear(row.periodMonth, row.periodYear))}</td>
        <td>${renderStatusPill(row.status)}</td>
        <td>${renderClaimStatusPill(row.claimStatus)}</td>
        <td>${renderAccountabilityPill(row.accountabilityStatus, row.accountabilityRequired)}</td>
        <td>${formatCurrency(row.expectedAmount || 0)}</td>
        <td>${formatCurrency(row.paidAmount || 0)}</td>
        <td>${formatCurrency(row.balanceAmount || 0)}</td>
        <td>${renderRowActions(row)}</td>
      `;

      wireRowActions(tr, row);
      elements.ledgerBody.appendChild(tr);
    });
  }

  function renderRecentPayments(rows) {
    if (!elements.paymentsBody) return;
    if (!Array.isArray(rows) || !rows.length) {
      elements.paymentsBody.innerHTML = '<tr><td colspan="8">No payment records found.</td></tr>';
      return;
    }

    elements.paymentsBody.innerHTML = '';
    rows.forEach((row) => {
      const tr = document.createElement('tr');
      const canEdit = state.permissions.canManage && !row.hasSubmittedAccountability;
      const canSubmitAccountability = state.permissions.canManage && Boolean(row.accountabilityRequired) && String(row.accountabilityStatus || '').trim() !== 'Accountability Submitted';
      tr.innerHTML = `
        <td>${escapeHtml(formatDate(row.paymentDate || ''))}</td>
        <td>${escapeHtml(row.regNo || '')}</td>
        <td>${escapeHtml(row.title || '')}</td>
        <td>${escapeHtml(row.displayName || row.name || '')}</td>
        <td>${escapeHtml(row.claimType || '')}</td>
        <td>${formatCurrency(row.amount || 0)}</td>
        <td>${renderAccountabilityPill(row.accountabilityStatus, row.accountabilityRequired)}</td>
        <td>
          <div class="claims-inline-actions">
            ${canEdit ? '<button class="claims-btn js-edit-payment-btn" type="button">Edit</button>' : ''}
            ${canEdit ? '<button class="claims-btn js-delete-payment-btn" type="button">Deregister</button>' : ''}
            ${canSubmitAccountability ? '<button class="claims-btn claims-btn-attention js-payment-accountability-btn" type="button">Accountability</button>' : ''}
          </div>
        </td>
      `;

      tr.querySelector('.js-edit-payment-btn')?.addEventListener('click', () => openPaymentModal(row));
      tr.querySelector('.js-delete-payment-btn')?.addEventListener('click', () => deletePaymentRecord(row));
      tr.querySelector('.js-payment-accountability-btn')?.addEventListener('click', () => openAccountabilityModal({
        paymentId: row.paymentId,
        regNo: row.regNo,
        claimType: row.claimType,
        paymentFinancialYear: row.paymentFinancialYear || '',
          beneficiaryName: row.name || formatTitleName(row.title || '', row.displayName || ''),
        supplierNo: row.supplierNo || '',
        outstanding: row.unappliedAmount || 0
      }));

      elements.paymentsBody.appendChild(tr);
    });
  }

  function renderStrategicPanel(strategic, canView) {
    if (!elements.strategicPanel) return;
    if (!canView) {
      elements.strategicPanel.style.display = 'none';
      return;
    }

    elements.strategicPanel.style.display = '';
    const estate = strategic.estateExpired || {};
    const fullDue = strategic.fullPensionDue || {};

    if (elements.estateTotal) elements.estateTotal.textContent = Number(estate.total || 0).toLocaleString();
    if (elements.estateMale) elements.estateMale.textContent = Number(estate.male || 0).toLocaleString();
    if (elements.estateFemale) elements.estateFemale.textContent = Number(estate.female || 0).toLocaleString();

    if (elements.fullDueTotal) elements.fullDueTotal.textContent = Number(fullDue.total || 0).toLocaleString();
    if (elements.fullDueMale) elements.fullDueMale.textContent = Number(fullDue.male || 0).toLocaleString();
    if (elements.fullDueFemale) elements.fullDueFemale.textContent = Number(fullDue.female || 0).toLocaleString();

    renderEstateStrategicRows(elements.estateRows, estate.rows || []);
    renderFullDueStrategicRows(elements.fullDueRows, fullDue.rows || []);
  }

  function renderEstateStrategicRows(target, rows) {
    if (!target) return;
    if (!rows.length) {
      target.innerHTML = '<tr><td colspan="4">No records.</td></tr>';
      return;
    }

    target.innerHTML = '';
    rows.slice(0, 8).forEach((row) => {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${escapeHtml(row.regNo || '')}</td>
        <td>${escapeHtml(row.name || '')}</td>
        <td>${escapeHtml(formatDate(row.dateOfDeath || ''))}</td>
        <td>${escapeHtml(formatDate(row.estateExpiryDate || ''))}</td>
      `;
      target.appendChild(tr);
    });
  }

  function renderFullDueStrategicRows(target, rows) {
    if (!target) return;
    if (!rows.length) {
      target.innerHTML = '<tr><td colspan="4">No records.</td></tr>';
      return;
    }

    target.innerHTML = '';
    rows.slice(0, 8).forEach((row) => {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${escapeHtml(row.regNo || '')}</td>
        <td>${escapeHtml(row.name || '')}</td>
        <td>${escapeHtml(window.PensionsGoRetirementTypes?.getLabel?.(row.retirementType || '') || row.retirementType || 'N/A')}</td>
        <td>${escapeHtml(formatDate(row.dateOn15yrs || ''))}</td>
      `;
      target.appendChild(tr);
    });
  }

  function renderRowActions(row) {
    if (!state.permissions.canManage) {
      return '<span class="claims-panel-muted">View</span>';
    }

    const status = String(row.status || '').toLowerCase();
    const canPay = status !== 'paid' && status !== 'waived';
    const canWaive = status !== 'paid' && status !== 'waived';
    const needsAccountability = Boolean(row.accountabilityRequired) && String(row.accountabilityStatus || '').trim() !== 'Accountability Submitted';

    return `
      <div class="claims-inline-actions">
        <button class="claims-btn js-pay-btn" type="button" ${canPay ? '' : 'disabled'}>Pay</button>
        ${needsAccountability ? '<button class="claims-btn claims-btn-attention js-accountability-btn" type="button">Accountability</button>' : ''}
        <button class="claims-btn js-edit-btn" type="button">Edit</button>
        <button class="claims-btn js-waive-btn" type="button" ${canWaive ? '' : 'disabled'}>Waive</button>
        <button class="claims-btn js-delete-btn" type="button">Delete</button>
      </div>
    `;
  }

  function wireRowActions(rowElement, rowData) {
    const payBtn = rowElement.querySelector('.js-pay-btn');
    if (payBtn) {
      payBtn.addEventListener('click', () => openPaymentModal(rowData));
    }

    const accountabilityBtn = rowElement.querySelector('.js-accountability-btn');
    if (accountabilityBtn) {
      accountabilityBtn.addEventListener('click', () => openAccountabilityModal({
        paymentId: 0,
        regNo: rowData.regNo,
        claimType: rowData.claimType,
        paymentFinancialYear: '',
          beneficiaryName: rowData.name || formatTitleName(rowData.title || '', rowData.displayName || ''),
        supplierNo: rowData.supplierNo || '',
        outstanding: rowData.balanceAmount || 0
      }));
    }

    const waiveBtn = rowElement.querySelector('.js-waive-btn');
    if (waiveBtn) {
      waiveBtn.addEventListener('click', () => waiveLedgerRow(rowData));
    }

    const editBtn = rowElement.querySelector('.js-edit-btn');
    if (editBtn) {
      editBtn.addEventListener('click', () => openEditEntryModal(rowData));
    }

    const deleteBtn = rowElement.querySelector('.js-delete-btn');
    if (deleteBtn) {
      deleteBtn.addEventListener('click', () => deleteLedgerRow(rowData));
    }
  }

  async function waiveLedgerRow(rowData) {
    const confirmed = await askConfirm(`Waive this claim for ${rowData.regNo}?`);
    if (!confirmed) return;

    try {
      const response = await fetch('../backend/api/post_arrears_tracking.php', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'mark_waived',
          ledgerId: Number(rowData.ledgerId || 0)
        })
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error(data.message || `HTTP ${response.status}`);
      }

      showFeedback(data.message || 'Ledger record waived.', 'success');
      loadClaimsDashboard();
    } catch (error) {
      showFeedback(error.message || 'Failed to waive ledger row.', 'error');
      await showModalMessage(error.message || 'Failed to waive ledger row.', 'error');
    }
  }

  async function openPaymentModal(rowData) {
    if (!elements.paymentModal) return;

    const selectedRegNo = state.selectedBeneficiaryRegNo || '';
    const regNo = String(rowData?.regNo || selectedRegNo || '').trim();
    const isExistingPayment = Number(rowData?.paymentId || 0) > 0;
    state.paymentModalMode = isExistingPayment ? 'edit' : 'create';

    if (elements.paymentIdInput) elements.paymentIdInput.value = isExistingPayment ? String(rowData.paymentId) : '';
    if (elements.paymentRegNo) elements.paymentRegNo.value = regNo;
    if (elements.paymentModalTitle) elements.paymentModalTitle.textContent = isExistingPayment ? 'Edit Arrears Payment' : 'Record Arrears Payment';
    if (elements.savePaymentBtn) elements.savePaymentBtn.textContent = isExistingPayment ? 'Update Payment' : 'Save Payment';

    let beneficiary = regNo ? await fetchBeneficiarySummary(regNo) : null;
    if (!beneficiary && regNo && state.selectedBeneficiaryDetails?.regNo === regNo) {
      beneficiary = state.selectedBeneficiaryDetails;
    }

    const beneficiaryName = beneficiary?.name || rowData?.name || rowData?.displayName || '';
    if (elements.paymentBeneficiaryDisplay) {
      elements.paymentBeneficiaryDisplay.value = regNo ? `${regNo} - ${beneficiaryName}`.trim() : '';
    }

    if (elements.paymentClaimType) {
      let claimType = String(rowData?.claimType || '').trim();
      if (!claimType && Array.isArray(beneficiary?.claimBreakdown)) {
        const firstOpenType = beneficiary.claimBreakdown.find((item) => Number(item.balanceTotal || 0) > 0);
        claimType = String(firstOpenType?.claimType || '');
      }
      elements.paymentClaimType.value = claimType || 'Pension Arrears';
    }
    if (elements.paymentRefInput) elements.paymentRefInput.value = String(rowData?.referenceNo || '');
    if (elements.paymentNotesInput) elements.paymentNotesInput.value = String(rowData?.notes || '');
    if (elements.paymentDateInput) {
      elements.paymentDateInput.value = String(rowData?.paymentDate || '').trim() || new Date().toISOString().slice(0, 10);
    }
    if (elements.paymentAmountInput) {
      if (isExistingPayment) {
        setMoneyInputValue(elements.paymentAmountInput, Number(rowData?.amount || 0) > 0 ? rowData.amount : '');
      } else {
        elements.paymentAmountInput.value = '';
      }
    }

    openModal(elements.paymentModal);
    await populatePaymentAmountFromOutstanding(isExistingPayment);
  }

  function closePaymentModal() {
    if (elements.paymentForm) elements.paymentForm.reset();
    if (elements.paymentIdInput) elements.paymentIdInput.value = '';
    state.paymentModalMode = 'create';
    closeModal(elements.paymentModal);
  }

  async function submitPayment() {
    const paymentId = Number(elements.paymentIdInput?.value || 0);
    const regNo = String(syncPaymentBeneficiarySelection() || elements.paymentRegNo?.value || '').trim();
    const claimType = String(elements.paymentClaimType?.value || '').trim();
    const amount = parseMoneyInputValue(elements.paymentAmountInput?.value, 0);
    const paymentDate = String(elements.paymentDateInput?.value || '').trim();
    const referenceNo = String(elements.paymentRefInput?.value || '').trim();
    const notes = String(elements.paymentNotesInput?.value || '').trim();

    if (!regNo || !claimType || amount <= 0 || !paymentDate) {
      showFeedback('Provide a valid payment amount and date.', 'error');
      await showModalMessage('Provide a valid beneficiary, claim type, payment amount and date.', 'error');
      return;
    }

    try {
      const beneficiaryLabel = String(elements.paymentBeneficiaryDisplay?.value || '').trim();
      const isEdit = paymentId > 0;
      const response = await fetch('../backend/api/post_arrears_tracking.php', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: isEdit ? 'update_payment' : 'record_payment',
          paymentId,
          regNo,
          claimType,
          amount,
          paymentDate,
          referenceNo,
          notes
        })
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error(data.message || `HTTP ${response.status}`);
      }

      closePaymentModal();
      showFeedback(data.message || (isEdit ? 'Payment updated.' : 'Payment recorded.'), 'success');
      await loadClaimsDashboard();
      if (!isEdit && data.payment?.accountabilityRequired) {
        await openAccountabilityModal({
          paymentId: Number(data.payment.payment_id || 0),
          regNo,
          claimType,
          paymentFinancialYear: String(data.payment.payment_financial_year || ''),
          beneficiaryName: beneficiaryLabel.split(' - ').slice(1).join(' - ').trim(),
          supplierNo: '',
          outstanding: 0
        });
        await showModalMessage('Payment recorded. Accountability is required for this transaction.', 'warning');
      }
    } catch (error) {
      showFeedback(error.message || 'Failed to save payment.', 'error');
      await showModalMessage(error.message || 'Failed to save payment.', 'error');
    }
  }

  async function submitSuspensionUpload() {
    if (!state.permissions.canUploadSuspension) {
      showFeedback('You are not authorized to upload suspended amount files.', 'error');
      await showModalMessage('You are not authorized to upload suspended amount files.', 'error');
      return;
    }

    const year = Number(elements.claimsSuspensionYearInput?.value || 0);
    const month = Number(elements.claimsSuspensionMonthInput?.value || 0);
    const notes = String(elements.claimsSuspensionNotesInput?.value || '').trim();
    const file = elements.claimsSuspensionFileInput?.files?.[0] || null;
    if (year < 2000 || month < 1 || month > 12 || !file) {
      showFeedback('Provide year, month and the suspended amount file.', 'error');
      await showModalMessage('Provide year, month and the suspended amount file.', 'error');
      return;
    }

    try {
      const formData = new FormData();
      formData.append('suspension_year', String(year));
      formData.append('suspension_month', String(month));
      formData.append('notes', notes);
      formData.append('suspension_file', file);

      const response = await fetch('../backend/api/upload_suspension_arrears.php', {
        method: 'POST',
        credentials: 'include',
        body: formData
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error(data.message || `HTTP ${response.status}`);
      }

      const rowsUploaded = Number(data.stats?.rows_uploaded || 0);
      const matchedRows = Number(data.stats?.matched_rows || 0);
      const unmatchedRows = Number(data.stats?.unmatched_rows || 0);
      const savedAmount = Number(data.stats?.saved_amount || data.stats?.total_saved_amount || 0);
      const matchedSavedAmount = Number(data.stats?.matched_saved_amount || 0);
      const reviewDownloadStarted = downloadImportReviewExport(data.review_export, 'suspension_upload_review.csv');
      const suspensionSummary = [
        data.message || 'Suspension upload completed.',
        `Rows uploaded: ${rowsUploaded}.`,
        `Matched pensioners: ${matchedRows}.`,
        `Unmatched rows: ${unmatchedRows}.`,
        `Saved amount captured: ${formatCurrency(savedAmount)}.`,
        `Matched saved amount: ${formatCurrency(matchedSavedAmount)}.`,
        reviewDownloadStarted ? 'A review file download has started for rows that need attention.' : ''
      ].join('\n');
      const suspensionType = unmatchedRows > 0 ? 'warning' : 'success';

      closeModal(elements.suspensionUploadModal);
      showFeedback(
        unmatchedRows > 0
          ? `Suspension upload completed with review items. Matched ${matchedRows}, unmatched ${unmatchedRows}. Saved amount captured: ${formatCompactCurrency(savedAmount)}.`
          : `Suspension upload completed. Saved amount captured: ${formatCompactCurrency(savedAmount)}.`,
        suspensionType
      );
      await showModalMessage(suspensionSummary, unmatchedRows > 0 ? 'warning' : 'info');
      if (elements.claimsSuspensionUploadForm) elements.claimsSuspensionUploadForm.reset();
      setDefaultModalDates();
      await loadSuspensionCycles();
      await loadClaimsDashboard();
    } catch (error) {
      showFeedback(error.message || 'Failed to upload the suspended amount file.', 'error');
      await showModalMessage(error.message || 'Failed to upload the suspended amount file.', 'error');
    }
  }

  async function downloadTemplateFile({ endpoint, fallbackTitle, successMessage, errorMessage }) {
    try {
      const response = await fetch(endpoint, {
        credentials: 'include',
        cache: 'no-store'
      });
      if (!response.ok) {
        let message = `HTTP ${response.status}`;
        try {
          const data = await response.json();
          message = data.message || message;
        } catch (_error) {
          const text = await response.text();
          if (text) message = text;
        }
        throw new Error(message);
      }
      const blob = await response.blob();
      const fileName = parseExportFilename(response.headers.get('Content-Disposition'), fallbackTitle, 'xlsx');
      triggerBlobDownload(blob, fileName);
      showFeedback(successMessage, 'success');
    } catch (error) {
      showFeedback(error.message || errorMessage, 'error');
      await showModalMessage(error.message || errorMessage, 'error');
    }
  }

  async function downloadBulkPaymentTemplate() {
    if (!state.permissions.canManage) {
      showFeedback('You are not authorized to download bulk payment templates.', 'error');
      await showModalMessage('You are not authorized to download bulk payment templates.', 'error');
      return;
    }

    await downloadTemplateFile({
      endpoint: '../backend/api/download_arrears_payments_template.php',
      fallbackTitle: 'arrears_payments_upload_template',
      successMessage: 'Bulk payment template downloaded.',
      errorMessage: 'Failed to download the bulk payment template.'
    });
  }

  async function downloadSuspensionUploadTemplate() {
    if (!state.permissions.canUploadSuspension) {
      showFeedback('You are not authorized to download suspension templates.', 'error');
      await showModalMessage('You are not authorized to download suspension templates.', 'error');
      return;
    }

    await downloadTemplateFile({
      endpoint: '../backend/api/download_suspension_upload_template.php',
      fallbackTitle: 'suspension_upload_template',
      successMessage: 'Suspension upload template downloaded.',
      errorMessage: 'Failed to download the suspension upload template.'
    });
  }

  async function downloadGratuityScheduleTemplate() {
    if (!state.permissions.canManage) {
      showFeedback('You are not authorized to download monthly gratuity schedule templates.', 'error');
      await showModalMessage('You are not authorized to download monthly gratuity schedule templates.', 'error');
      return;
    }

    await downloadTemplateFile({
      endpoint: '../backend/api/download_gratuity_schedule_template.php',
      fallbackTitle: 'monthly_gratuity_schedule_template',
      successMessage: 'Monthly gratuity schedule template downloaded.',
      errorMessage: 'Failed to download the gratuity schedule template.'
    });
  }

  async function submitGratuityScheduleUpload() {
    if (!state.permissions.canManage) {
      showFeedback('You are not authorized to upload monthly gratuity schedules.', 'error');
      await showModalMessage('You are not authorized to upload monthly gratuity schedules.', 'error');
      return;
    }

    const year = Number(elements.gratuityScheduleYearInput?.value || 0);
    const month = Number(elements.gratuityScheduleMonthInput?.value || 0);
    const notes = String(elements.gratuityScheduleNotesInput?.value || '').trim();
    const file = elements.gratuityScheduleFileInput?.files?.[0] || null;

    if (year < 2000 || month < 1 || month > 12 || !file) {
      showFeedback('Provide year, month, and the gratuity schedule file.', 'error');
      await showModalMessage('Provide year, month, and the gratuity schedule file.', 'error');
      return;
    }

    try {
      const formData = new FormData();
      formData.append('schedule_year', String(year));
      formData.append('schedule_month', String(month));
      formData.append('notes', notes);
      formData.append('schedule_file', file);

      const response = await fetch('../backend/api/upload_gratuity_schedule.php', {
        method: 'POST',
        credentials: 'include',
        body: formData
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error(data.message || `HTTP ${response.status}`);
      }

      closeModal(elements.gratuityScheduleUploadModal);
      if (elements.gratuityScheduleUploadForm) {
        elements.gratuityScheduleUploadForm.reset();
      }
      setDefaultModalDates();
      const rowsUploaded = Number(data.stats?.rowsUploaded || 0);
      const matchedRows = Number(data.stats?.matchedRows || 0);
      const unmatchedRows = Number(data.stats?.unmatchedRows || 0);
      const reviewRows = Number(data.stats?.reviewRows || 0);
      const pensionArrearsRows = Number(data.stats?.pensionArrearsRows || 0);
      const allocatedPensionAmount = Number(data.stats?.totalAllocatedPensionAmount || 0);
      const unallocatedAmount = Number(data.stats?.totalUnallocatedAmount || 0);
      const reviewDownloadStarted = downloadImportReviewExport(data.review_export, 'gratuity_schedule_review.csv');
      const gratuitySummary = [
        data.message || 'Monthly gratuity schedule uploaded and analysed.',
        `Rows uploaded: ${rowsUploaded}.`,
        `Matched pensioners: ${matchedRows}.`,
        `Unmatched rows: ${unmatchedRows}.`,
        `Needs review: ${reviewRows}.`,
        `Pension arrears rows identified: ${pensionArrearsRows}.`,
        `Allocated pension amount: ${formatCurrency(allocatedPensionAmount)}.`,
        `Unallocated schedule amount: ${formatCurrency(unallocatedAmount)}.`,
        reviewDownloadStarted ? 'A review file download has started for the rows that need follow-up.' : ''
      ].join('\n');
      const gratuityType = (unmatchedRows > 0 || reviewRows > 0 || unallocatedAmount > 0) ? 'warning' : 'success';

      showFeedback(
        gratuityType === 'warning'
          ? `Gratuity schedule analysed with review items. Matched ${matchedRows}, review ${reviewRows}, unmatched ${unmatchedRows}.`
          : `Gratuity schedule analysed successfully. Matched ${matchedRows} pensioner(s).`,
        gratuityType
      );
      await showModalMessage(gratuitySummary, gratuityType === 'warning' ? 'warning' : 'info');
      await loadGratuityScheduleCycles();
      if (data.cycle?.cycleId) {
        await loadGratuityScheduleEntries(Number(data.cycle.cycleId), data.cycle);
      }
    } catch (error) {
      showFeedback(error.message || 'Failed to upload the monthly gratuity schedule.', 'error');
      await showModalMessage(error.message || 'Failed to upload the monthly gratuity schedule.', 'error');
    }
  }

  async function loadBeneficiarySuggestions(phrase, context = 'search') {
    const value = String(phrase || '').trim();
    if (value.length < 2) {
      if (context === 'search' && elements.searchList) elements.searchList.innerHTML = '';
      if (context === 'payment' && elements.paymentBeneficiaryList) elements.paymentBeneficiaryList.innerHTML = '';
      return;
    }
    try {
      const response = await fetch(`../backend/api/get_arrears_beneficiaries.php?q=${encodeURIComponent(value)}&limit=20`, {
        credentials: 'include',
        cache: 'no-store'
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        return;
      }
      const suggestions = Array.isArray(data.suggestions) ? data.suggestions : [];
      const mapRef = context === 'payment' ? state.paymentLookupMap : state.searchLookupMap;
      const listEl = context === 'payment' ? elements.paymentBeneficiaryList : elements.searchList;
      mapRef.clear();
      if (!listEl) return;
      listEl.innerHTML = '';
      suggestions.forEach((item) => {
        const label = `${item.regNo || ''} - ${item.name || ''}`.trim();
        if (!label) return;
        mapRef.set(label, String(item.regNo || '').trim());
        mapRef.set(String(item.regNo || '').trim(), String(item.regNo || '').trim());
        const option = document.createElement('option');
        option.value = label;
        listEl.appendChild(option);
      });
    } catch (error) {
      console.error('Arrears beneficiary suggestion error:', error);
    }
  }

  function syncSearchBeneficiarySelection() {
    const raw = String(elements.searchInput?.value || '').trim();
    if (!raw) {
      state.selectedBeneficiaryRegNo = '';
      return '';
    }
    const mapped = state.searchLookupMap.get(raw);
    if (mapped) {
      state.selectedBeneficiaryRegNo = String(mapped);
      return state.selectedBeneficiaryRegNo;
    }
    const fileNo = raw.split('-')[0].trim();
    state.selectedBeneficiaryRegNo = fileNo;
    return state.selectedBeneficiaryRegNo;
  }

  function syncPaymentBeneficiarySelection() {
    const raw = String(elements.paymentBeneficiaryDisplay?.value || '').trim();
    if (!raw) {
      if (elements.paymentRegNo) elements.paymentRegNo.value = '';
      return '';
    }
    const mapped = state.paymentLookupMap.get(raw);
    const regNo = mapped ? String(mapped) : raw.split('-')[0].trim();
    if (elements.paymentRegNo) elements.paymentRegNo.value = regNo;
    return regNo;
  }

  async function loadSelectedBeneficiary(regNo) {
    const fileNo = String(regNo || '').trim();
    if (!fileNo) {
      hideSelectedBeneficiary();
      return;
    }
    try {
      const b = await fetchBeneficiarySummary(fileNo);
      if (!b) {
        hideSelectedBeneficiary();
        return;
      }
      state.selectedBeneficiaryDetails = b;
      if (elements.claimsBeneficiarySummary) elements.claimsBeneficiarySummary.style.display = '';
      if (elements.claimsBeneficiaryName) elements.claimsBeneficiaryName.textContent = b.name || 'Beneficiary';
      if (elements.claimsBeneficiaryRegNo) elements.claimsBeneficiaryRegNo.textContent = b.regNo || '';
      if (elements.claimsBeneficiarySupplier) elements.claimsBeneficiarySupplier.textContent = b.supplierNo || '-';
      if (elements.claimsBeneficiaryContact) elements.claimsBeneficiaryContact.textContent = b.contact || '-';
      if (elements.claimsBeneficiaryAddress) elements.claimsBeneficiaryAddress.textContent = b.address || '-';
      if (elements.claimsBeneficiaryOutstanding) elements.claimsBeneficiaryOutstanding.textContent = formatCurrency(b.balanceTotal || 0);
    } catch (error) {
      console.error('Load selected beneficiary error:', error);
      hideSelectedBeneficiary();
    }
  }

  function hideSelectedBeneficiary() {
    state.selectedBeneficiaryDetails = null;
    if (elements.claimsBeneficiarySummary) elements.claimsBeneficiarySummary.style.display = 'none';
    if (elements.claimsBeneficiaryName) elements.claimsBeneficiaryName.textContent = 'Beneficiary';
    if (elements.claimsBeneficiaryRegNo) elements.claimsBeneficiaryRegNo.textContent = '';
    if (elements.claimsBeneficiarySupplier) elements.claimsBeneficiarySupplier.textContent = '-';
    if (elements.claimsBeneficiaryContact) elements.claimsBeneficiaryContact.textContent = '-';
    if (elements.claimsBeneficiaryAddress) elements.claimsBeneficiaryAddress.textContent = '-';
    if (elements.claimsBeneficiaryOutstanding) elements.claimsBeneficiaryOutstanding.textContent = formatCurrency(0);
  }

  function openEditEntryModal(row) {
    if (!elements.editEntryModal) return;
    if (elements.editLedgerId) elements.editLedgerId.value = String(row.ledgerId || '');
    if (elements.editBeneficiaryDisplay) elements.editBeneficiaryDisplay.value = `${row.regNo || ''} - ${row.name || ''}`.trim();
    if (elements.editClaimTypeInput) elements.editClaimTypeInput.value = String(row.claimType || 'Pension Arrears');
    if (elements.editExpectedAmountInput) setMoneyInputValue(elements.editExpectedAmountInput, Number(row.expectedAmount || 0));
    if (elements.editPeriodYearInput) elements.editPeriodYearInput.value = Number(row.periodYear || 0);
    if (elements.editPeriodMonthInput) elements.editPeriodMonthInput.value = Number(row.periodMonth || 1);
    if (elements.editReasonInput) elements.editReasonInput.value = String(row.reason || '');
    if (elements.editNotesInput) elements.editNotesInput.value = String(row.notes || '');
    openModal(elements.editEntryModal);
  }

  async function submitEditEntry() {
    const ledgerId = Number(elements.editLedgerId?.value || 0);
    const claimType = String(elements.editClaimTypeInput?.value || '').trim();
    const expectedAmount = parseMoneyInputValue(elements.editExpectedAmountInput?.value, 0);
    const periodYear = Number(elements.editPeriodYearInput?.value || 0);
    const periodMonth = Number(elements.editPeriodMonthInput?.value || 0);
    const reason = String(elements.editReasonInput?.value || '').trim();
    const notes = String(elements.editNotesInput?.value || '').trim();
    if (ledgerId <= 0 || !claimType || expectedAmount < 0 || periodYear < 2000 || periodMonth < 1 || periodMonth > 12) {
      showFeedback('Provide valid arrears values before saving.', 'error');
      await showModalMessage('Provide valid arrears values before saving.', 'error');
      return;
    }

    try {
      const response = await fetch('../backend/api/post_arrears_tracking.php', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'update_entry',
          ledgerId,
          claimType,
          expectedAmount,
          periodYear,
          periodMonth,
          reason,
          notes
        })
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error(data.message || `HTTP ${response.status}`);
      }
      closeModal(elements.editEntryModal);
      showFeedback(data.message || 'Arrears entry updated.', 'success');
      loadClaimsDashboard();
    } catch (error) {
      showFeedback(error.message || 'Failed to update arrears entry.', 'error');
      await showModalMessage(error.message || 'Failed to update arrears entry.', 'error');
    }
  }

  async function deleteLedgerRow(rowData) {
    const confirmed = await askConfirm(`Delete arrears record for ${rowData.regNo}? This cannot be undone.`);
    if (!confirmed) return;
    try {
      const response = await fetch('../backend/api/post_arrears_tracking.php', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'delete_entry',
          ledgerId: Number(rowData.ledgerId || 0)
        })
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error(data.message || `HTTP ${response.status}`);
      }
      showFeedback(data.message || 'Record deleted.', 'success');
      loadClaimsDashboard();
    } catch (error) {
      showFeedback(error.message || 'Failed to delete arrears entry.', 'error');
      await showModalMessage(error.message || 'Failed to delete arrears entry.', 'error');
    }
  }

  function applyPermissionVisibility() {
    if (elements.openClaimsPaymentModalBtn) {
      elements.openClaimsPaymentModalBtn.style.display = state.permissions.canManage ? '' : 'none';
    }
    if (elements.openClaimsBulkPaymentModalBtn) {
      elements.openClaimsBulkPaymentModalBtn.style.display = state.permissions.canManage ? '' : 'none';
    }
    if (elements.openGratuityScheduleUploadModalBtn) {
      elements.openGratuityScheduleUploadModalBtn.style.display = state.permissions.canManage ? '' : 'none';
    }
    if (elements.openSuspensionUploadModalBtn) {
      elements.openSuspensionUploadModalBtn.style.display = state.permissions.canUploadSuspension ? '' : 'none';
    }
    if (elements.saveBulkPaymentBtn) {
      elements.saveBulkPaymentBtn.disabled = !state.permissions.canManage;
    }
    if (elements.downloadBulkPaymentTemplateBtn) {
      elements.downloadBulkPaymentTemplateBtn.disabled = !state.permissions.canManage;
    }
    if (elements.saveGratuityScheduleUploadBtn) {
      elements.saveGratuityScheduleUploadBtn.disabled = !state.permissions.canManage;
    }
    if (elements.downloadGratuityScheduleTemplateBtn) {
      elements.downloadGratuityScheduleTemplateBtn.disabled = !state.permissions.canManage;
    }
    if (elements.saveSuspensionUploadBtn) {
      elements.saveSuspensionUploadBtn.disabled = !state.permissions.canUploadSuspension;
    }
    if (elements.downloadSuspensionUploadTemplateBtn) {
      elements.downloadSuspensionUploadTemplateBtn.disabled = !state.permissions.canUploadSuspension;
    }
  }

  function ensureCollapseButton(panel, index, body) {
    const head = panel.querySelector('.claims-panel-head');
    if (!head) return null;
    let tools = head.querySelector('.claims-panel-tools');
    if (!tools) {
      tools = document.createElement('div');
      tools.className = 'claims-panel-tools';
      head.appendChild(tools);
    }

    let btn = panel.querySelector('[data-collapse-btn]');
    if (btn && !head.contains(btn)) {
      tools.appendChild(btn);
    }

    if (!btn) {
      btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'claims-btn claims-collapse-btn';
      btn.setAttribute('data-collapse-btn', '');
      btn.textContent = 'Collapse';
      tools.appendChild(btn);
    }

    if (body) {
      if (!body.id) {
        body.id = `claims-panel-body-${index + 1}`;
      }
      btn.setAttribute('aria-controls', body.id);
    }
    return btn;
  }

  function getCollapsiblePanels() {
    return Array.from(document.querySelectorAll('.claims-panel'));
  }

  function initCollapsiblePanels() {
    const panels = getCollapsiblePanels();
    panels.forEach((panel, index) => {
      panel.setAttribute('data-collapsible', 'true');
      const body = panel.querySelector('.claims-panel-body');
      const btn = ensureCollapseButton(panel, index, body);
      if (!btn) return;
      const startCollapsed = panel.getAttribute('data-collapsed') !== 'false';
      applyCollapseState(panel, btn, body, startCollapsed);
      btn.addEventListener('click', () => {
        const next = !panel.classList.contains('is-collapsed');
        applyCollapseState(panel, btn, body, next);
      });
    });
  }

  function setAllPanelsCollapsed(collapsed) {
    const panels = getCollapsiblePanels();
    panels.forEach((panel, index) => {
      panel.setAttribute('data-collapsible', 'true');
      const body = panel.querySelector('.claims-panel-body');
      const btn = ensureCollapseButton(panel, index, body);
      if (!btn) return;
      applyCollapseState(panel, btn, body, collapsed);
    });
  }

  function applyCollapseState(panel, btn, body, collapsed) {
    panel.classList.toggle('is-collapsed', collapsed);
    panel.setAttribute('data-collapsed', collapsed ? 'true' : 'false');
    panel.style.minHeight = collapsed ? '0' : '';
    btn.classList.toggle('is-collapsed', collapsed);
    btn.setAttribute('aria-expanded', String(!collapsed));
    btn.textContent = collapsed ? 'Expand' : 'Collapse';
    if (body) {
      body.setAttribute('aria-hidden', String(collapsed));
    }
  }

  function updatePaginationDisplay(pagination) {
    const current = Number(pagination.page || state.page || 1);
    const totalPages = Math.max(1, Number(pagination.totalPages || state.totalPages || 1));
    state.page = current;
    state.totalPages = totalPages;

    if (elements.pageIndicator) {
      elements.pageIndicator.textContent = `Page ${current} of ${totalPages}`;
    }
    if (elements.prevBtn) {
      elements.prevBtn.disabled = current <= 1;
    }
    if (elements.nextBtn) {
      elements.nextBtn.disabled = current >= totalPages;
    }
    if (elements.ledgerMeta) {
      const totalRows = Number(pagination.totalRows || 0);
      elements.ledgerMeta.textContent = `${totalRows.toLocaleString()} total records`;
    }
  }

  function populateClaimTypeOptions(options) {
    if (!elements.typeFilter) return;
    const current = elements.typeFilter.value;
    const baseOption = '<option value="">All Claim Types</option>';
    const html = [baseOption];
    options.forEach((option) => {
      const value = String(option || '').trim();
      if (value) {
        html.push(`<option value="${escapeHtml(value)}">${escapeHtml(value)}</option>`);
      }
    });
    elements.typeFilter.innerHTML = html.join('');
    if (current && options.includes(current)) {
      elements.typeFilter.value = current;
    } else if (state.filters.claimType && options.includes(state.filters.claimType)) {
      elements.typeFilter.value = state.filters.claimType;
    }
  }

  function populateYearOptions(yearlyRows) {
    if (!elements.yearFilter) return;
    const selected = elements.yearFilter.value;
    const years = new Set();
    yearlyRows.forEach((item) => {
      const year = Number(item.year || 0);
      if (year > 0) years.add(String(year));
    });
    const nowYear = new Date().getFullYear();
    years.add(String(nowYear));
    years.add(String(nowYear - 1));

    const sorted = Array.from(years).sort((a, b) => Number(b) - Number(a));
    const options = ['<option value="">All Years</option>'].concat(
      sorted.map((year) => `<option value="${year}">${year}</option>`)
    );
    elements.yearFilter.innerHTML = options.join('');
    if (selected && sorted.includes(selected)) {
      elements.yearFilter.value = selected;
    } else if (state.filters.year && sorted.includes(state.filters.year)) {
      elements.yearFilter.value = state.filters.year;
    }
  }

  function readFilters() {
    const rawSearch = String(elements.searchInput?.value || '').trim();
    state.filters.search = state.selectedBeneficiaryRegNo || rawSearch;
    state.filters.claimType = String(elements.typeFilter?.value || '').trim();
    state.filters.status = String(elements.statusFilter?.value || '').trim();
    state.filters.claimStatus = String(elements.claimStatusFilter?.value || '').trim();
    state.filters.year = String(elements.yearFilter?.value || '').trim();
    state.filters.quarter = String(elements.quarterFilter?.value || '').trim();
  }

  function setDefaultModalDates() {
    const now = new Date();
    if (elements.paymentDateInput && !elements.paymentDateInput.value) {
      elements.paymentDateInput.value = now.toISOString().slice(0, 10);
    }
    if (elements.claimsSuspensionYearInput && !elements.claimsSuspensionYearInput.value) {
      elements.claimsSuspensionYearInput.value = String(now.getFullYear());
    }
    if (elements.claimsSuspensionMonthInput && !elements.claimsSuspensionMonthInput.value) {
      elements.claimsSuspensionMonthInput.value = String(now.getMonth() + 1);
    }
    if (elements.gratuityScheduleYearInput && !elements.gratuityScheduleYearInput.value) {
      elements.gratuityScheduleYearInput.value = String(now.getFullYear());
    }
    if (elements.gratuityScheduleMonthInput && !elements.gratuityScheduleMonthInput.value) {
      elements.gratuityScheduleMonthInput.value = String(now.getMonth() + 1);
    }
    if (elements.bulkPaymentDefaultDate && !elements.bulkPaymentDefaultDate.value) {
      elements.bulkPaymentDefaultDate.value = now.toISOString().slice(0, 10);
    }
  }

  function renderStatusPill(status) {
    const normalized = String(status || '').trim();
    const css = normalized.toLowerCase().replace(/\s+/g, '-');
    return `<span class="claims-status-pill claims-status-${escapeHtml(css)}">${escapeHtml(normalized || 'Pending')}</span>`;
  }

  function renderClaimStatusPill(status) {
    const normalized = String(status || '').trim() || 'Incomplete';
    const css = normalized.toLowerCase().replace(/\s+/g, '-');
    return `<span class="claims-status-pill claims-claim-status claims-claim-${escapeHtml(css)}">${escapeHtml(normalized)}</span>`;
  }

  function renderAccountabilityPill(status, required) {
    const rawStatus = String(status || '').trim();
    const normalized = rawStatus || (required ? 'Pending Accountability' : 'No Accountability Required');
    const css = normalized.toLowerCase().replace(/\s+/g, '-');
    return `<span class="claims-status-pill claims-accountability-pill claims-accountability-${escapeHtml(css)}">${escapeHtml(normalized)}</span>`;
  }

  async function openAccountabilityModal(context) {
    if (!elements.accountabilityModal) return;
    const regNo = String(context?.regNo || '').trim();
    if (!regNo) return;

    state.accountabilityContext = {
      paymentId: Number(context?.paymentId || 0),
      regNo,
      claimType: String(context?.claimType || 'Pension Arrears'),
      paymentFinancialYear: String(context?.paymentFinancialYear || ''),
      beneficiaryName: String(context?.beneficiaryName || '').trim(),
      supplierNo: String(context?.supplierNo || '').trim(),
      outstanding: Number(context?.outstanding || 0)
    };

    if (elements.accountabilityPaymentId) elements.accountabilityPaymentId.value = String(state.accountabilityContext.paymentId || '');
    if (elements.accountabilityRegNo) elements.accountabilityRegNo.value = regNo;
    if (elements.accountabilityClaimType) elements.accountabilityClaimType.value = state.accountabilityContext.claimType;
    if (elements.accountabilityPaymentFy) elements.accountabilityPaymentFy.value = state.accountabilityContext.paymentFinancialYear || '-';
    if (elements.accountabilityNotes) elements.accountabilityNotes.value = '';
    if (elements.accountabilityFiles) elements.accountabilityFiles.value = '';

    const beneficiary = await fetchBeneficiarySummary(regNo);
    const displayName = beneficiary?.name || state.accountabilityContext.beneficiaryName || regNo;
    const supplierNo = beneficiary?.supplierNo || state.accountabilityContext.supplierNo || '-';
    const outstanding = beneficiary?.balanceTotal ?? state.accountabilityContext.outstanding ?? 0;

    if (elements.accountabilityBeneficiaryName) elements.accountabilityBeneficiaryName.textContent = displayName;
    if (elements.accountabilityBeneficiaryRegNo) elements.accountabilityBeneficiaryRegNo.textContent = regNo;
    if (elements.accountabilitySupplierNo) elements.accountabilitySupplierNo.textContent = supplierNo || '-';
    if (elements.accountabilityOutstanding) elements.accountabilityOutstanding.textContent = formatCurrency(outstanding || 0);

    openModal(elements.accountabilityModal);
  }

  function closeAccountabilityModal() {
    if (elements.accountabilityForm) elements.accountabilityForm.reset();
    state.accountabilityContext = null;
    closeModal(elements.accountabilityModal);
  }

  async function submitAccountability() {
    const regNo = String(elements.accountabilityRegNo?.value || state.accountabilityContext?.regNo || '').trim();
    const paymentId = Number(elements.accountabilityPaymentId?.value || state.accountabilityContext?.paymentId || 0);
    const claimType = String(elements.accountabilityClaimType?.value || state.accountabilityContext?.claimType || '').trim();
    const notes = String(elements.accountabilityNotes?.value || '').trim();
    const files = Array.from(elements.accountabilityFiles?.files || []);

    if (!regNo || !claimType || files.length === 0) {
      showFeedback('Attach at least one accountability form before submitting.', 'error');
      await showModalMessage('Attach at least one accountability form before submitting.', 'error');
      return;
    }

    try {
      const formData = new FormData();
      formData.append('regNo', regNo);
      formData.append('payment_id', String(paymentId || 0));
      formData.append('claimType', claimType);
      formData.append('notes', notes);
      files.forEach((file) => formData.append('accountability_files[]', file));

      const response = await fetch('../backend/api/submit_arrears_accountability.php', {
        method: 'POST',
        credentials: 'include',
        body: formData
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error(data.message || `HTTP ${response.status}`);
      }

      closeAccountabilityModal();
      showFeedback(data.message || 'Accountability submitted successfully.', 'success');
      await loadClaimsDashboard();
      if (state.selectedBeneficiaryRegNo === regNo) {
        await loadSelectedBeneficiary(regNo);
      }
    } catch (error) {
      showFeedback(error.message || 'Failed to submit accountability.', 'error');
      await showModalMessage(error.message || 'Failed to submit accountability.', 'error');
    }
  }

  async function populatePaymentAmountFromOutstanding(preserveCurrentAmount = false) {
    const regNo = String(syncPaymentBeneficiarySelection() || elements.paymentRegNo?.value || '').trim();
    const claimType = String(elements.paymentClaimType?.value || '').trim();
    if (!elements.paymentAmountInput || !regNo || !claimType) return;

    let beneficiary = state.selectedBeneficiaryDetails && state.selectedBeneficiaryDetails.regNo === regNo
      ? state.selectedBeneficiaryDetails
      : await fetchBeneficiarySummary(regNo);
    if (!beneficiary) return;

    const breakdown = Array.isArray(beneficiary.claimBreakdown) ? beneficiary.claimBreakdown : [];
    const match = breakdown.find((item) => String(item.claimType || '').trim() === claimType);
    const amount = Number(match?.balanceTotal || 0);

    if (elements.paymentBeneficiaryDisplay && !elements.paymentBeneficiaryDisplay.value.trim()) {
      elements.paymentBeneficiaryDisplay.value = `${regNo} - ${beneficiary.name || ''}`.trim();
    }

    const currentAmount = parseMoneyInputValue(elements.paymentAmountInput.value, 0);
    if (!preserveCurrentAmount || currentAmount <= 0) {
      setMoneyInputValue(elements.paymentAmountInput, amount > 0 ? amount.toFixed(2) : '');
    }
  }

  async function submitBulkPayments() {
    const file = elements.bulkPaymentFileInput?.files?.[0] || null;
    const defaultDate = String(elements.bulkPaymentDefaultDate?.value || '').trim();
    const defaultClaimType = String(elements.bulkPaymentDefaultType?.value || 'Pension Arrears').trim();

    if (!file) {
      showFeedback('Select a payment file to upload.', 'error');
      await showModalMessage('Select a payment file to upload.', 'error');
      return;
    }

    try {
      const formData = new FormData();
      formData.append('payment_file', file);
      formData.append('default_payment_date', defaultDate || new Date().toISOString().slice(0, 10));
      formData.append('default_claim_type', defaultClaimType);

      const response = await fetch('../backend/api/upload_arrears_payments.php', {
        method: 'POST',
        credentials: 'include',
        body: formData
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error(data.message || `HTTP ${response.status}`);
      }

      closeModal(elements.bulkPaymentModal);
      if (elements.bulkPaymentForm) elements.bulkPaymentForm.reset();
      const rowsUploaded = Number(data.stats?.rowsUploaded || 0);
      const matchedRows = Number(data.stats?.matchedRows || 0);
      const unmatchedRows = Number(data.stats?.unmatchedRows || 0);
      const savedPayments = Number(data.stats?.savedPayments || 0);
      const failedRows = Number(data.stats?.failedRows || 0);
      const reviewDownloadStarted = downloadImportReviewExport(data.review_export, 'arrears_payments_review.csv');
      const errorLines = Array.isArray(data.errors) ? data.errors.slice(0, 5) : [];
      const paymentSummaryParts = [
        data.message || 'Bulk payment upload processed.',
        `Rows uploaded: ${rowsUploaded}.`,
        `Matched rows: ${matchedRows}.`,
        `Unmatched rows: ${unmatchedRows}.`,
        `Saved payments: ${savedPayments}.`,
        `Failed rows: ${failedRows}.`
      ];
      if (reviewDownloadStarted) {
        paymentSummaryParts.push('A review file download has started for the rows that need correction.');
      }
      if (errorLines.length) {
        paymentSummaryParts.push('', 'Review items:');
        paymentSummaryParts.push(...errorLines);
      }
      const paymentSummary = paymentSummaryParts.join('\n');
      const paymentType = (failedRows > 0 || unmatchedRows > 0 || errorLines.length > 0) ? 'warning' : 'success';

      showFeedback(
        paymentType === 'warning'
          ? `Bulk payment upload completed with review items. Saved ${savedPayments}, failed ${failedRows}, unmatched ${unmatchedRows}.`
          : `Bulk payment upload complete. Saved ${savedPayments} payment(s).`,
        paymentType
      );
      await showModalMessage(paymentSummary, paymentType === 'warning' ? 'warning' : 'info');
      await loadClaimsDashboard();
    } catch (error) {
      showFeedback(error.message || 'Failed to upload bulk payments.', 'error');
      await showModalMessage(error.message || 'Failed to upload bulk payments.', 'error');
    }
  }

  async function deletePaymentRecord(rowData) {
    const confirmed = await askConfirm(`Deregister payment for ${rowData.regNo}? This will reverse its allocations.`);
    if (!confirmed) return;

    try {
      const response = await fetch('../backend/api/post_arrears_tracking.php', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'delete_payment',
          paymentId: Number(rowData.paymentId || 0)
        })
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error(data.message || `HTTP ${response.status}`);
      }
      showFeedback(data.message || 'Payment deregistered successfully.', 'success');
      await loadClaimsDashboard();
      if (state.selectedBeneficiaryRegNo === rowData.regNo) {
        await loadSelectedBeneficiary(rowData.regNo);
      }
    } catch (error) {
      showFeedback(error.message || 'Failed to deregister payment.', 'error');
      await showModalMessage(error.message || 'Failed to deregister payment.', 'error');
    }
  }

  async function fetchBeneficiarySummary(regNo) {
    const fileNo = String(regNo || '').trim();
    if (!fileNo) return null;
    try {
      const response = await fetch(`../backend/api/get_arrears_beneficiaries.php?regNo=${encodeURIComponent(fileNo)}`, {
        credentials: 'include',
        cache: 'no-store'
      });
      const data = await response.json();
      if (!response.ok || !data.success || !data.beneficiary) {
        return null;
      }
      return data.beneficiary;
    } catch (error) {
      console.error('Fetch arrears beneficiary summary error:', error);
      return null;
    }
  }

  function toggleSuspensionReasonFyVisibility() {}

  function renderEmptyLedger(message) {
    if (!elements.ledgerBody) return;
    elements.ledgerBody.innerHTML = `<tr><td colspan="12">${escapeHtml(message)}</td></tr>`;
  }

  function setSummaryLoadingState() {
    if (!elements.summaryCards) return;
    const cards = ['Expected Arrears', 'Paid Arrears', 'Outstanding Balance', 'Pending Accountability', 'Accountability Submitted', 'Ledger Records', 'Open Claims'];
    elements.summaryCards.innerHTML = cards
      .map((label) => `<article class="claims-kpi"><span class="claims-kpi-label">${escapeHtml(label)}</span><span class="claims-kpi-value">...</span></article>`)
      .join('');
  }

  function setSummaryEmptyState() {
    renderSummaryCards({
      expectedTotal: 0,
      paidTotal: 0,
      balanceTotal: 0,
      entryCount: 0,
      openCount: 0,
      pendingAccountabilityCount: 0,
      accountabilitySubmittedCount: 0
    });
  }

  function chartColor(index) {
    const palette = ['#7a1420', '#1d4b8f', '#c78e12', '#137a50', '#8c4a16', '#6b5b95', '#3e7cb1', '#c84c5a'];
    return palette[index % palette.length];
  }

  async function exportClaimsTable(button) {
    const tableId = String(button?.dataset?.tableTarget || '').trim();
    const format = String(button?.dataset?.claimsExport || 'xlsx').trim().toLowerCase();
    const exportTitle = String(button?.dataset?.exportTitle || 'Claims Table Export').trim();
    const table = tableId ? document.getElementById(tableId) : null;

    if (!table) {
      await showModalMessage('The selected claims table could not be found for export.', 'error');
      return;
    }

    const payload = tableId === 'claimsLedgerTable'
      ? await buildClaimsLedgerExportPayload(exportTitle)
      : buildClaimsTableExportPayload(table, exportTitle);
    const hasRows = Array.isArray(payload.rows) ? payload.rows.length > 0 : false;
    const hasGroups = Array.isArray(payload.groups) ? payload.groups.length > 0 : false;
    const isServerSide = Boolean(payload.serverSide);
    if (!hasRows && !hasGroups && !isServerSide) {
      await showModalMessage('The selected table has no rows to export.', 'warning');
      return;
    }

    const originalLabel = button.textContent;
    button.disabled = true;
    button.textContent = format === 'pdf' ? 'Exporting PDF...' : 'Exporting XLSX...';

    try {
      const response = await fetch('../backend/api/export_claims_table.php', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          title: payload.title,
          exportKind: payload.exportKind || '',
          headers: payload.headers,
          groups: payload.groups || [],
          totals: payload.totals || null,
          fileCount: payload.fileCount || 0,
          recordCount: payload.recordCount || 0,
          rows: payload.rows,
          serverSide: payload.serverSide || false,
          filters: payload.filters || null,
          format
        })
      });

      if (!response.ok) {
        let message = `HTTP ${response.status}`;
        try {
          const data = await response.json();
          message = data.message || message;
        } catch (_error) {
          const text = await response.text();
          if (text) message = text;
        }
        throw new Error(message);
      }

      const blob = await response.blob();
      const fileName = parseExportFilename(response.headers.get('Content-Disposition'), exportTitle, format);
      triggerBlobDownload(blob, fileName);
      showFeedback(`${format.toUpperCase()} export generated successfully.`, 'success');
    } catch (error) {
      showFeedback(error.message || 'Failed to export claims table.', 'error');
      await showModalMessage(error.message || 'Failed to export claims table.', 'error');
    } finally {
      button.disabled = false;
      button.textContent = originalLabel;
    }
  }

  function buildClaimsTableExportPayload(table, title) {
    const headerCells = Array.from(table.querySelectorAll('thead th'));
    const includedIndexes = [];
    const headers = [];

    headerCells.forEach((cell, index) => {
      const label = normalizeExportCellText(cell.textContent || '');
      if (!label) return;
      if (/^actions?$/i.test(label)) return;
      includedIndexes.push(index);
      headers.push(label);
    });

    const rows = Array.from(table.querySelectorAll('tbody tr'))
      .map((row) => {
        const cells = Array.from(row.children);
        const values = includedIndexes.map((index) => normalizeExportCellText(extractExportCellValue(cells[index])));
        return values;
      })
      .filter((row) => row.some((value) => value !== ''));

    return appendClaimsTableTotals({ title, headers, rows });
  }

  async function buildClaimsLedgerExportPayload(title) {
    readFilters();
    return {
      title: title || 'Claims Arrears Ledger',
      exportKind: 'claims_ledger_grouped',
      serverSide: true,
      headers: ['Entry', 'Claim Type', 'Period', 'Status', 'Expected (UGX)', 'Paid (UGX)', 'Balance (UGX)'],
      filters: {
        search: state.filters.search || '',
        claim_type: state.filters.claimType || '',
        status: state.filters.status || '',
        claim_status: state.filters.claimStatus || '',
        year: state.filters.year || '',
        quarter: state.filters.quarter || ''
      }
    };
  }

  function buildGroupedClaimsLedgerExportPayload(title, rows) {
    const groupsMap = new Map();

    (Array.isArray(rows) ? rows : []).forEach((row) => {
      const fileNo = String(row.regNo || '').trim() || 'Unspecified File';
      const titleText = String(row.title || '').trim();
      const nameText = String(row.displayName || row.name || '').trim() || 'Unnamed Pensioner';
      const pensionerName = titleText
        ? `${titleText} - ${nameText}`.trim()
        : (nameText || 'Unnamed Pensioner');
      const expectedAmount = Number(row.expectedAmount || 0) || 0;
      const paidAmount = Number(row.paidAmount || 0) || 0;
      const balanceAmount = Number(row.balanceAmount || 0) || 0;
      const sortYear = Number(row.periodYear || 0) || 0;
      const sortMonth = Number(row.periodMonth || 0) || 0;

      if (!groupsMap.has(fileNo)) {
        groupsMap.set(fileNo, {
          fileNo,
          pensionerName,
          title: titleText,
          name: nameText,
          rows: [],
          totals: {
            expectedAmount: 0,
            paidAmount: 0,
            balanceAmount: 0
          }
        });
      }

      const group = groupsMap.get(fileNo);
      group.rows.push({
        entry: 0,
        claimType: String(row.claimType || '').trim(),
        period: formatMonthYear(row.periodMonth, row.periodYear),
        status: String(row.status || '').trim(),
        expectedAmount,
        paidAmount,
        balanceAmount,
        sortYear,
        sortMonth
      });
      group.totals.expectedAmount += expectedAmount;
      group.totals.paidAmount += paidAmount;
      group.totals.balanceAmount += balanceAmount;
    });

    const groups = Array.from(groupsMap.values())
      .sort((left, right) => String(left.fileNo || '').localeCompare(String(right.fileNo || ''), undefined, { numeric: true, sensitivity: 'base' }))
      .map((group) => {
        group.rows.sort((left, right) => {
          if ((left.sortYear || 0) !== (right.sortYear || 0)) return (left.sortYear || 0) - (right.sortYear || 0);
          if ((left.sortMonth || 0) !== (right.sortMonth || 0)) return (left.sortMonth || 0) - (right.sortMonth || 0);
          return String(left.claimType || '').localeCompare(String(right.claimType || ''), undefined, { sensitivity: 'base' });
        });
        group.rows = group.rows.map((item, index) => ({
          entry: index + 1,
          claimType: item.claimType,
          period: item.period,
          status: item.status,
          expectedAmount: item.expectedAmount,
          paidAmount: item.paidAmount,
          balanceAmount: item.balanceAmount
        }));
        group.recordCount = group.rows.length;
        return group;
      });

    const totals = groups.reduce((summary, group) => {
      summary.expectedAmount += Number(group.totals?.expectedAmount || 0);
      summary.paidAmount += Number(group.totals?.paidAmount || 0);
      summary.balanceAmount += Number(group.totals?.balanceAmount || 0);
      summary.recordCount += Number(group.recordCount || 0);
      return summary;
    }, {
      expectedAmount: 0,
      paidAmount: 0,
      balanceAmount: 0,
      recordCount: 0
    });

    return {
      title,
      exportKind: 'claims_ledger_grouped',
      headers: ['Entry', 'Claim Type', 'Period', 'Status', 'Expected (UGX)', 'Paid (UGX)', 'Balance (UGX)'],
      groups,
      totals: {
        expectedAmount: totals.expectedAmount,
        paidAmount: totals.paidAmount,
        balanceAmount: totals.balanceAmount
      },
      fileCount: groups.length,
      recordCount: totals.recordCount,
      rows: []
    };
  }

  function buildClaimsDashboardQuery(overrides = {}) {
    readFilters();
    const query = new URLSearchParams({
      page: String(overrides.page || state.page || 1),
      limit: String(overrides.limit || state.limit || 20)
    });

    if (state.filters.search) query.set('search', state.filters.search);
    if (state.filters.claimType) query.set('claim_type', state.filters.claimType);
    if (state.filters.status) query.set('status', state.filters.status);
    if (state.filters.claimStatus) query.set('claim_status', state.filters.claimStatus);
    if (state.filters.year) query.set('year', state.filters.year);
    if (state.filters.quarter) query.set('quarter', state.filters.quarter);
    return query;
  }

  function appendClaimsTableTotals(payload) {
    const headers = Array.isArray(payload?.headers) ? payload.headers.slice() : [];
    const rows = Array.isArray(payload?.rows) ? payload.rows.map((row) => Array.isArray(row) ? row.slice() : []) : [];
    if (!headers.length || !rows.length) {
      return { title: payload?.title || 'Claims Table Export', headers, rows };
    }

    const amountColumns = headers.reduce((list, header, index) => {
      if (/(amount|balance|paid|expected|total amount|total)/i.test(String(header || ''))) {
        list.push(index);
      }
      return list;
    }, []);

    if (!amountColumns.length) {
      return { title: payload?.title || 'Claims Table Export', headers, rows };
    }

    const totalsRow = new Array(headers.length).fill('');
    totalsRow[0] = 'Total';

    amountColumns.forEach((columnIndex) => {
      const total = rows.reduce((sum, row) => sum + parseClaimsCurrency(row[columnIndex]), 0);
      totalsRow[columnIndex] = formatCurrency(total);
    });

    rows.push(totalsRow);
    return {
      title: payload?.title || 'Claims Table Export',
      headers,
      rows
    };
  }

  function parseClaimsCurrency(value) {
    const normalized = String(value || '').replace(/[^0-9.-]+/g, '');
    const parsed = Number(normalized);
    return Number.isFinite(parsed) ? parsed : 0;
  }

  function extractExportCellValue(cell) {
    if (!cell) return '';
    const clone = cell.cloneNode(true);
    clone.querySelectorAll('button, a, .claims-inline-actions, .status-badge, .claims-status-pill').forEach((node) => node.remove());
    return clone.textContent || '';
  }

  function normalizeExportCellText(value) {
    return String(value || '').replace(/\s+/g, ' ').trim();
  }

  function formatTitleName(title, name) {
    const cleanTitle = String(title || '').trim();
    const cleanName = String(name || '').trim();
    if (cleanTitle && cleanName) {
      return `${cleanTitle} - ${cleanName}`;
    }
    return cleanTitle || cleanName;
  }

  function formatAccountabilityExportValue(status, required) {
    const clean = String(status || '').trim();
    if (clean) return clean;
    return required ? 'Pending Accountability' : 'Not Required';
  }

  function formatGratuityScheduleClassification(value) {
    const normalized = String(value || '').trim();
    if (!normalized) return 'Review';
    const map = {
      exact_gratuity_match: 'Exact Gratuity Match',
      partial_gratuity_schedule: 'Partial Gratuity Coverage',
      gratuity_plus_small_surplus: 'Gratuity + Small Surplus',
      small_surplus_review: 'Small Surplus Review',
      gratuity_plus_pension_arrears: 'Gratuity + Pension Arrears',
      pension_only_schedule: 'Pension Arrears Only',
      scheduled_without_open_arrears: 'Scheduled Without Open Arrears',
      review_missing_gratuity_estimate: 'Review Missing Gratuity Estimate',
      review_missing_monthly_pension: 'Review Missing Monthly Pension',
      pension_review_missing_monthly_pension: 'Pension Review Missing Monthly Pension',
      unmatched_registry: 'Registry Match Required'
    };
    return map[normalized] || normalized.replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase());
  }

  function parseExportFilename(contentDisposition, fallbackTitle, format) {
    const safeFallback = `${String(fallbackTitle || 'claims_table_export').toLowerCase().replace(/[^a-z0-9]+/g, '_') || 'claims_table_export'}.${format}`;
    if (!contentDisposition) return safeFallback;

    const utfMatch = contentDisposition.match(/filename\*=UTF-8''([^;]+)/i);
    if (utfMatch?.[1]) {
      return decodeURIComponent(utfMatch[1]);
    }

    const plainMatch = contentDisposition.match(/filename=\"?([^\";]+)\"?/i);
    return plainMatch?.[1] ? plainMatch[1] : safeFallback;
  }

  function triggerBlobDownload(blob, fileName) {
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = fileName;
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
  }

  function downloadImportReviewExport(reviewExport, fallbackName = 'import_review.csv') {
    if (!reviewExport || !reviewExport.content_base64) {
      return false;
    }

    try {
      const binary = window.atob(String(reviewExport.content_base64 || ''));
      const bytes = new Uint8Array(binary.length);
      for (let index = 0; index < binary.length; index += 1) {
        bytes[index] = binary.charCodeAt(index);
      }
      const blob = new Blob([bytes], { type: reviewExport.mime || 'text/csv;charset=utf-8;' });
      triggerBlobDownload(blob, reviewExport.file_name || fallbackName);
      return true;
    } catch (error) {
      console.error('Unable to download import review export:', error);
      return false;
    }
  }

  function openModal(modal) {
    if (!modal) return;
    modal.classList.add('open');
    document.body.classList.add('modal-open');
  }

  function closeModal(modal) {
    if (!modal) return;
    modal.classList.remove('open');
    if (!document.querySelector('.claims-modal-overlay.open')) {
      document.body.classList.remove('modal-open');
    }
  }

  async function loadClaimsPeriodOptions(force = false) {
    if (state.periodOptions && !force) {
      applyClaimsPeriodOptions();
      return;
    }
    try {
      const response = await fetch('../backend/api/get_claims_period_options.php', {
        credentials: 'include',
        cache: 'no-store',
        headers: { 'Accept': 'application/json' }
      });
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }
      const data = await response.json();
      if (!data.success) {
        throw new Error(data.message || 'Unable to load period options.');
      }
      state.periodOptions = normalizePeriodOptions(data);
    } catch (error) {
      console.warn('Period options load failed:', error.message || error);
      state.periodOptions = normalizePeriodOptions({});
    }
    applyClaimsPeriodOptions();
  }

  function normalizePeriodOptions(payload) {
    const raw = payload.options || payload || {};
    const financialYears = Array.isArray(raw.financialYears) ? raw.financialYears : [];
    const years = Array.isArray(raw.years) ? raw.years : [];
    const monthsByYear = raw.monthsByYear && typeof raw.monthsByYear === 'object' ? raw.monthsByYear : {};
    const quartersByFinancialYear = raw.quartersByFinancialYear && typeof raw.quartersByFinancialYear === 'object'
      ? raw.quartersByFinancialYear
      : {};
    const monthYearOptions = Array.isArray(raw.monthYearOptions) ? raw.monthYearOptions : [];
    const allMonths = collectAllMonths(monthsByYear);
    return {
      financialYears,
      years,
      monthsByYear,
      quartersByFinancialYear,
      monthYearOptions,
      allMonths
    };
  }

  function collectAllMonths(monthsByYear) {
    const set = new Set();
    Object.values(monthsByYear || {}).forEach((months) => {
      (months || []).forEach((value) => {
        const month = Number(value || 0);
        if (month >= 1 && month <= 12) set.add(month);
      });
    });
    const all = Array.from(set);
    if (!all.length) {
      return Array.from({ length: 12 }, (_v, idx) => idx + 1);
    }
    return all.sort((a, b) => a - b);
  }

  function populateSelect(select, options, placeholder) {
    if (!select) return;
    const currentValue = String(select.value || '');
    select.innerHTML = '';
    const placeholderOption = document.createElement('option');
    placeholderOption.value = '';
    placeholderOption.textContent = placeholder;
    select.appendChild(placeholderOption);

    options.forEach((opt) => {
      const value = opt && typeof opt === 'object' ? opt.value : opt;
      const label = opt && typeof opt === 'object' ? opt.label : opt;
      const option = document.createElement('option');
      option.value = String(value ?? '');
      option.textContent = String(label ?? '');
      select.appendChild(option);
    });

    if (!options.length) {
      placeholderOption.textContent = 'No records available';
    }

    if (currentValue && options.some((opt) => String((opt && typeof opt === 'object' ? opt.value : opt) ?? '') === currentValue)) {
      select.value = currentValue;
    } else {
      select.value = '';
    }
  }

  function clearExportPeriodFields() {
    if (elements.exportFinancialYear) elements.exportFinancialYear.value = '';
    if (elements.exportQuarter) elements.exportQuarter.value = '';
    if (elements.exportYear) elements.exportYear.value = '';
    if (elements.exportMonth) elements.exportMonth.value = '';
    if (elements.exportFromYear) elements.exportFromYear.value = '';
    if (elements.exportFromMonth) elements.exportFromMonth.value = '';
    if (elements.exportToYear) elements.exportToYear.value = '';
    if (elements.exportToMonth) elements.exportToMonth.value = '';
  }

  function parseMonthYearValue(value) {
    const match = String(value || '').trim().match(/^(\d{4})-(\d{2})$/);
    if (!match) return null;
    const year = Number(match[1]);
    const month = Number(match[2]);
    if (!year || !month) return null;
    return { year: String(year), month: String(month) };
  }

  function applyPeriodValueSelection() {
    const scope = String(elements.exportPeriodScope?.value || 'all');
    const value = String(elements.exportPeriodValue?.value || '').trim();
    clearExportPeriodFields();

    if (!value) return;

    if (scope === 'financial_year') {
      if (elements.exportFinancialYear) elements.exportFinancialYear.value = value;
    } else if (scope === 'quarter') {
      const parts = value.split('||');
      if (parts.length === 2) {
        if (elements.exportFinancialYear) elements.exportFinancialYear.value = parts[0];
        syncQuarterOptions();
        if (elements.exportQuarter) elements.exportQuarter.value = parts[1];
      } else if (elements.exportQuarter) {
        elements.exportQuarter.value = value;
      }
    } else if (scope === 'year') {
      if (elements.exportYear) elements.exportYear.value = value;
    } else if (scope === 'month') {
      const parsed = parseMonthYearValue(value);
      if (parsed) {
        if (elements.exportYear) elements.exportYear.value = parsed.year;
        syncMonthOptionsForYear(elements.exportYear, elements.exportMonth, 'Select Month');
        if (elements.exportMonth) elements.exportMonth.value = parsed.month;
      }
    }
  }

  function applyPeriodRangeSelection() {
    clearExportPeriodFields();
    const fromValue = String(elements.exportPeriodFrom?.value || '').trim();
    const toValue = String(elements.exportPeriodTo?.value || '').trim();
    const fromParsed = parseMonthYearValue(fromValue);
    const toParsed = parseMonthYearValue(toValue);
    if (fromParsed) {
      if (elements.exportFromYear) elements.exportFromYear.value = fromParsed.year;
      syncMonthOptionsForYear(elements.exportFromYear, elements.exportFromMonth, 'Select Month');
      if (elements.exportFromMonth) elements.exportFromMonth.value = fromParsed.month;
    }
    if (toParsed) {
      if (elements.exportToYear) elements.exportToYear.value = toParsed.year;
      syncMonthOptionsForYear(elements.exportToYear, elements.exportToMonth, 'Select Month');
      if (elements.exportToMonth) elements.exportToMonth.value = toParsed.month;
    }
  }

  function updatePeriodValueLabel(text) {
    const label = elements.exportPeriodValueWrap?.querySelector('label');
    if (label) {
      label.textContent = text;
    }
  }

  function updateRangeLabels() {
    const labels = elements.exportPeriodRangeWrap?.querySelectorAll('label');
    if (!labels || labels.length < 2) return;
    labels[0].textContent = 'From Month';
    labels[1].textContent = 'To Month';
  }

  function updateExportPeriodValueOptions(scope) {
    if (!elements.exportPeriodValue) return;
    const periodOptions = state.periodOptions || {};
    let options = [];
    let label = 'Select Period';

    if (scope === 'financial_year') {
      options = periodOptions.financialYears || [];
      label = 'Financial Year';
    } else if (scope === 'quarter') {
      const financialYears = periodOptions.financialYears || [];
      const quartersByFy = periodOptions.quartersByFinancialYear || {};
      financialYears.forEach((fy) => {
        const quarters = quartersByFy[fy] || ['Q1', 'Q2', 'Q3', 'Q4'];
        quarters.forEach((quarter) => {
          options.push({
            value: `${fy}||${quarter}`,
            label: `${fy} - ${quarter}`
          });
        });
      });
      if (!options.length && (quartersByFy || {})) {
        ['Q1', 'Q2', 'Q3', 'Q4'].forEach((quarter) => {
          options.push({ value: quarter, label: quarter });
        });
      }
      label = 'Quarter';
    } else if (scope === 'year') {
      options = periodOptions.years || [];
      label = 'Year';
    } else if (scope === 'month') {
      options = periodOptions.monthYearOptions || [];
      label = 'Month';
    }

    populateSelect(elements.exportPeriodValue, options, `Select ${label}`);
    updatePeriodValueLabel(label);
  }

  function updateExportPeriodRangeOptions() {
    if (!elements.exportPeriodFrom || !elements.exportPeriodTo) return;
    const options = state.periodOptions?.monthYearOptions || [];
    populateSelect(elements.exportPeriodFrom, options, 'Start Month');
    populateSelect(elements.exportPeriodTo, options, 'End Month');
    updateRangeLabels();
  }

  function populateMonthSelect(select, months, placeholder) {
    const options = (months || []).map((month) => ({
      value: String(month),
      label: MONTH_LABELS[(Number(month) || 1) - 1] || String(month)
    }));
    populateSelect(select, options, placeholder);
  }

  function getMonthsForYear(yearValue) {
    const yearKey = String(yearValue || '').trim();
    const periodOptions = state.periodOptions;
    if (!periodOptions) {
      return Array.from({ length: 12 }, (_v, idx) => idx + 1);
    }
    if (yearKey && periodOptions.monthsByYear && periodOptions.monthsByYear[yearKey]) {
      return [...periodOptions.monthsByYear[yearKey]].map((v) => Number(v)).filter((v) => v >= 1 && v <= 12).sort((a, b) => a - b);
    }
    return periodOptions.allMonths || Array.from({ length: 12 }, (_v, idx) => idx + 1);
  }

  function syncQuarterOptions() {
    if (!elements.exportQuarter) return;
    const periodOptions = state.periodOptions || {};
    const fyValue = String(elements.exportFinancialYear?.value || '').trim();
    let quarters = [];
    if (fyValue && periodOptions.quartersByFinancialYear && periodOptions.quartersByFinancialYear[fyValue]) {
      quarters = periodOptions.quartersByFinancialYear[fyValue];
    }
    if (!quarters || !quarters.length) {
      quarters = ['Q1', 'Q2', 'Q3', 'Q4'];
    }
    populateSelect(elements.exportQuarter, quarters, 'Select Quarter');
  }

  function syncMonthOptionsForYear(yearSelect, monthSelect, placeholder) {
    if (!monthSelect) return;
    const months = getMonthsForYear(yearSelect?.value || '');
    populateMonthSelect(monthSelect, months, placeholder);
  }

  function applyClaimsPeriodOptions() {
    const periodOptions = state.periodOptions;
    if (!periodOptions) return;

    if (elements.exportFinancialYear) {
      populateSelect(elements.exportFinancialYear, periodOptions.financialYears, 'Select Financial Year');
    }
    if (elements.exportYear) {
      populateSelect(elements.exportYear, periodOptions.years, 'Select Year');
    }
    if (elements.exportFromYear) {
      populateSelect(elements.exportFromYear, periodOptions.years, 'Select Year');
    }
    if (elements.exportToYear) {
      populateSelect(elements.exportToYear, periodOptions.years, 'Select Year');
    }

    syncQuarterOptions();
    syncMonthOptionsForYear(elements.exportYear, elements.exportMonth, 'Select Month');
    syncMonthOptionsForYear(elements.exportFromYear, elements.exportFromMonth, 'Select Month');
    syncMonthOptionsForYear(elements.exportToYear, elements.exportToMonth, 'Select Month');

    const scope = String(elements.exportPeriodScope?.value || 'all');
    updateExportPeriodValueOptions(scope);
    updateExportPeriodRangeOptions();
  }

  function updateExportPeriodVisibility() {
    if (!elements.exportPeriodScope) return;
    loadClaimsPeriodOptions();
    const scope = String(elements.exportPeriodScope.value || 'all');
    const showValue = ['financial_year', 'quarter', 'year', 'month'].includes(scope);
    const showRange = scope === 'range';

    if (elements.exportPeriodValueWrap) {
      elements.exportPeriodValueWrap.style.display = showValue ? '' : 'none';
    }
    if (elements.exportPeriodRangeWrap) {
      elements.exportPeriodRangeWrap.style.display = showRange ? '' : 'none';
    }

    const hides = [
      elements.exportFinancialYearWrap,
      elements.exportQuarterWrap,
      elements.exportYearWrap,
      elements.exportMonthWrap,
      elements.exportFromYearWrap,
      elements.exportFromMonthWrap,
      elements.exportToYearWrap,
      elements.exportToMonthWrap
    ];
    hides.forEach((wrap) => {
      if (wrap) wrap.style.display = 'none';
    });

    updateExportPeriodValueOptions(scope);
    if (showRange) {
      updateExportPeriodRangeOptions();
    }

    if (scope === 'all') {
      clearExportPeriodFields();
    } else if (showValue) {
      applyPeriodValueSelection();
    } else if (showRange) {
      applyPeriodRangeSelection();
    }
  }

  function updateExportTypeVisibility() {
    if (!elements.exportTypeMode || !elements.exportClaimTypesWrap) return;
    const mode = String(elements.exportTypeMode.value || 'by_type');
    const disabled = mode === 'total_only';
    elements.exportClaimTypesWrap.querySelectorAll('input[type="checkbox"]').forEach((input) => {
      input.disabled = disabled;
    });
    elements.exportClaimTypesWrap.style.opacity = disabled ? '0.6' : '';
  }

  function readCheckedValues(wrapper) {
    if (!wrapper) return [];
    return Array.from(wrapper.querySelectorAll('input[type="checkbox"]:checked'))
      .map((input) => String(input.value || '').trim())
      .filter((value) => value !== '');
  }

  function resetExportFilters() {
    if (elements.exportFilterForm) {
      elements.exportFilterForm.reset();
    }
    if (elements.exportOutstandingOnly) {
      elements.exportOutstandingOnly.checked = true;
    }
    if (elements.exportIncludeSubtotal) {
      elements.exportIncludeSubtotal.checked = true;
    }
    loadClaimsPeriodOptions();
    updateExportPeriodVisibility();
    updateExportTypeVisibility();
  }

  function buildClaimsAggregationFilters() {
    const filters = {
      claim_types: readCheckedValues(elements.exportClaimTypesWrap),
      status: readCheckedValues(elements.exportStatusWrap),
      claim_status: readCheckedValues(elements.exportClaimStatusWrap),
      extra_columns: readCheckedValues(elements.exportExtraColumnsWrap),
      aggregation_mode: String(elements.exportAggregationMode?.value || 'by_pensioner'),
      type_mode: String(elements.exportTypeMode?.value || 'by_type'),
      period_scope: String(elements.exportPeriodScope?.value || 'all'),
      financial_year: String(elements.exportFinancialYear?.value || '').trim(),
      quarter: String(elements.exportQuarter?.value || '').trim(),
      year: String(elements.exportYear?.value || '').trim(),
      month: String(elements.exportMonth?.value || '').trim(),
      from_year: String(elements.exportFromYear?.value || '').trim(),
      from_month: String(elements.exportFromMonth?.value || '').trim(),
      to_year: String(elements.exportToYear?.value || '').trim(),
      to_month: String(elements.exportToMonth?.value || '').trim(),
      search: String(elements.exportSearch?.value || '').trim(),
      retirement_type: String(elements.exportRetirementType?.value || '').trim(),
      living_status: String(elements.exportLivingStatus?.value || '').trim(),
      outstanding_only: Boolean(elements.exportOutstandingOnly?.checked),
      include_subtotal: Boolean(elements.exportIncludeSubtotal?.checked)
    };
    state.exportSummaryFilters = filters;
    return filters;
  }

  function validateClaimsAggregationFilters(filters) {
    const scope = String(filters.period_scope || 'all');
    if (scope === 'financial_year' && !filters.financial_year) {
      return 'Select a financial year for the chosen period scope.';
    }
    if (scope === 'quarter' && (!filters.quarter || !filters.financial_year)) {
      return 'Select both quarter and financial year for quarter-based filtering.';
    }
    if (scope === 'year' && !filters.year) {
      return 'Enter a year for year-based filtering.';
    }
    if (scope === 'month' && (!filters.year || !filters.month)) {
      return 'Select both month and year for month-based filtering.';
    }
    if (scope === 'range' && (!filters.from_year || !filters.from_month || !filters.to_year || !filters.to_month)) {
      return 'Select both from and to months/years for the range filter.';
    }
    return '';
  }

  function openExportPreview() {
    if (!elements.exportPreviewModal) return;
    elements.exportPreviewModal.classList.add('is-foreground');
    openModal(elements.exportPreviewModal);
  }

  function closeExportPreview() {
    if (!elements.exportPreviewModal) return;
    elements.exportPreviewModal.classList.remove('is-foreground');
    closeModal(elements.exportPreviewModal);
  }

  async function previewClaimsAggregation(page = 1) {
    const filters = buildClaimsAggregationFilters();
    const validationMessage = validateClaimsAggregationFilters(filters);
    if (validationMessage) {
      await showModalMessage(validationMessage, 'warning');
      return;
    }
    if (!filters.claim_types.length && filters.type_mode === 'by_type') {
      await showModalMessage('Select at least one claim type column to preview.', 'warning');
      return;
    }
    try {
      if (!state.exportPreviewLimit) {
        state.exportPreviewLimit = 20;
      }
      state.exportSummaryTitle = buildClaimsAggregationTitle(filters);
      const response = await fetch('../backend/api/get_claims_aggregation_preview.php', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          filters,
          page,
          limit: state.exportPreviewLimit || 20
        })
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error(data.message || `HTTP ${response.status}`);
      }
      renderClaimsAggregationPreview(data);
      openExportPreview();
    } catch (error) {
      showFeedback(error.message || 'Failed to preview arrears summary.', 'error');
      await showModalMessage(error.message || 'Failed to preview arrears summary.', 'error');
    }
  }

  function renderClaimsAggregationPreview(data) {
    if (!elements.exportPreviewHead || !elements.exportPreviewBody) return;
    const columns = Array.isArray(data.columns) ? data.columns : [];
    const rows = Array.isArray(data.rows) ? data.rows : [];
    const pagination = data.pagination || {};
    state.exportPreviewPage = Number(pagination.page || 1);
    state.exportPreviewTotalPages = Number(pagination.totalPages || 1);
    state.exportPreviewLimit = Number(pagination.limit || 20);
    elements.exportPreviewHead.innerHTML = '';
    elements.exportPreviewBody.innerHTML = '';

    const nameColumnIndex = columns.findIndex((col) => col.key === 'name');
    let nameWidth = '';
    if (nameColumnIndex >= 0) {
      const serverMax = Number(data.maxNameLength || 0);
      const localMax = rows.reduce((maxLen, row) => {
        const value = row[columns[nameColumnIndex].key];
        const len = String(value || '').length;
        return Math.max(maxLen, len);
      }, 0);
      const maxNameLen = Math.max(serverMax, localMax);
      if (maxNameLen > 0) {
        const widthCh = Math.min(90, Math.max(28, maxNameLen + 6));
        nameWidth = `${widthCh}ch`;
      }
    }

    const headerRow = document.createElement('tr');
    const snHead = document.createElement('th');
    snHead.textContent = 'S/N';
    headerRow.appendChild(snHead);
    columns.forEach((col) => {
      const th = document.createElement('th');
      th.textContent = col.label || '';
      if (col.key === 'name') {
        th.classList.add('claims-name-cell');
        if (nameWidth) {
          th.style.minWidth = nameWidth;
          th.style.width = nameWidth;
        }
      }
      headerRow.appendChild(th);
    });
    elements.exportPreviewHead.appendChild(headerRow);

    if (!rows.length) {
      const tr = document.createElement('tr');
      tr.innerHTML = `<td colspan="${columns.length + 1}">No arrears records found for the selected filters.</td>`;
      elements.exportPreviewBody.appendChild(tr);
    } else {
      rows.forEach((row, index) => {
        const rowKey = columns[0]?.key;
        const isTotalRow = rowKey ? String(row[rowKey] || '').trim().toLowerCase() === 'total' : false;
        const sn = ((state.exportPreviewPage - 1) * state.exportPreviewLimit) + index + 1;
        const tr = document.createElement('tr');
        if (isTotalRow) {
          tr.classList.add('claims-total-row');
        }
        if (isTotalRow) {
          const totalTd = document.createElement('td');
          totalTd.colSpan = Math.min(3, columns.length + 1);
          totalTd.textContent = 'Total';
          totalTd.classList.add('claims-name-cell');
          if (nameWidth) {
            totalTd.style.minWidth = nameWidth;
            totalTd.style.width = nameWidth;
          }
          tr.appendChild(totalTd);

          columns.slice(2).forEach((col) => {
            const td = document.createElement('td');
            const rawValue = row[col.key];
            const displayValue = col.type === 'currency'
              ? formatCurrency(rawValue || 0)
              : (rawValue == null ? '' : String(rawValue));
            td.textContent = displayValue;
            tr.appendChild(td);
          });
        } else {
          const snTd = document.createElement('td');
          snTd.textContent = String(sn);
          tr.appendChild(snTd);
          columns.forEach((col) => {
            const td = document.createElement('td');
            const rawValue = row[col.key];
            const displayValue = col.type === 'currency'
              ? formatCurrency(rawValue || 0)
              : (rawValue == null ? '' : String(rawValue));
            td.textContent = displayValue;
            if (col.key === 'name') {
              td.classList.add('claims-name-cell');
              if (nameWidth) {
                td.style.minWidth = nameWidth;
                td.style.width = nameWidth;
              }
            }
            tr.appendChild(td);
          });
        }
        elements.exportPreviewBody.appendChild(tr);
      });
    }

    if (elements.exportPreviewMeta) {
      const total = Number(data.totalRows || rows.length || 0);
      const title = state.exportSummaryTitle || 'Arrears Summary';
      elements.exportPreviewMeta.textContent = `${title} - ${total.toLocaleString()} record${total === 1 ? '' : 's'} found.`;
    }
    if (elements.exportPreviewNote) {
      const truncated = Boolean(data.truncated);
      elements.exportPreviewNote.style.display = truncated ? '' : 'none';
      elements.exportPreviewNote.textContent = truncated
        ? 'Preview shows the first set of results. Export to retrieve the full dataset.'
        : '';
    }
    if (elements.exportPreviewPage) {
      elements.exportPreviewPage.textContent = `Page ${state.exportPreviewPage} of ${state.exportPreviewTotalPages}`;
    }
    if (elements.exportPreviewPrevBtn) {
      elements.exportPreviewPrevBtn.disabled = state.exportPreviewPage <= 1;
    }
    if (elements.exportPreviewNextBtn) {
      elements.exportPreviewNextBtn.disabled = state.exportPreviewPage >= state.exportPreviewTotalPages;
    }
  }

  async function exportClaimsAggregation(format) {
    const filters = state.exportSummaryFilters || buildClaimsAggregationFilters();
    const validationMessage = validateClaimsAggregationFilters(filters);
    if (validationMessage) {
      await showModalMessage(validationMessage, 'warning');
      return;
    }
    if (!filters.claim_types.length && filters.type_mode === 'by_type') {
      await showModalMessage('Select at least one claim type column to export.', 'warning');
      return;
    }
    const buttons = format === 'pdf'
      ? [elements.exportFilterPdfBtn, elements.exportPreviewPdfBtn]
      : [elements.exportFilterXlsxBtn, elements.exportPreviewXlsxBtn];
    buttons.forEach((btn) => {
      if (!btn) return;
      btn.disabled = true;
      btn.textContent = format === 'pdf' ? 'Exporting PDF...' : 'Exporting XLSX...';
    });

    try {
      const title = buildClaimsAggregationTitle(filters);
      const response = await fetch('../backend/api/export_claims_table.php', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          title,
          exportKind: 'claims_aggregation_summary',
          serverSide: true,
          filters,
          format
        })
      });
      if (!response.ok) {
        let message = `HTTP ${response.status}`;
        try {
          const data = await response.json();
          message = data.message || message;
        } catch (_error) {
          const text = await response.text();
          if (text) message = text;
        }
        throw new Error(message);
      }
      const blob = await response.blob();
      const fileName = parseExportFilename(response.headers.get('Content-Disposition'), 'arrears_summary', format);
      triggerBlobDownload(blob, fileName);
      showFeedback(`${format.toUpperCase()} export generated successfully.`, 'success');
    } catch (error) {
      showFeedback(error.message || 'Failed to export arrears summary.', 'error');
      await showModalMessage(error.message || 'Failed to export arrears summary.', 'error');
    } finally {
      buttons.forEach((btn) => {
        if (!btn) return;
        btn.disabled = false;
        btn.textContent = format === 'pdf' ? 'Export PDF' : 'Export XLSX';
      });
    }
  }

  async function ensureActiveSession() {
    try {
      const response = await fetch('../backend/api/check_session.php', {
        credentials: 'include',
        cache: 'no-store'
      });
      const data = await response.json();
      if (!response.ok || !data.active) {
        const returnUrl = encodeURIComponent(window.location.href);
        window.location.replace(`login.html?return=${returnUrl}&reason=session_required`);
        return false;
      }
      return true;
    } catch (_error) {
      const returnUrl = encodeURIComponent(window.location.href);
      window.location.replace(`login.html?return=${returnUrl}&reason=session_required`);
      return false;
    }
  }

  async function askConfirm(message) {
    if (typeof window.appConfirm === 'function') {
      return window.appConfirm(String(message || ''), {
        title: 'Confirm Action',
        confirmText: 'Proceed',
        cancelText: 'Cancel'
      });
    }
    return fallbackConfirmModal(String(message || 'Are you sure?'));
  }

  async function showModalMessage(message, type = 'info') {
    const text = String(message || '').trim();
    if (!text) return;
    if (typeof window.appAlert === 'function') {
      const title = type === 'error' ? 'Action Failed' : type === 'warning' ? 'Notice' : 'Information';
      await window.appAlert(text, { title, type });
      return;
    }
    await fallbackAlertModal(text, type);
  }

  function fallbackAlertModal(message, type) {
    return new Promise((resolve) => {
      const overlay = document.createElement('div');
      overlay.className = 'claims-modal-overlay open';
      const title = type === 'error' ? 'Action Failed' : type === 'warning' ? 'Notice' : 'Information';
      overlay.innerHTML = `
        <div class=\"claims-modal\" style=\"width:min(430px, 94vw);\">
          <header class=\"claims-modal-header\"><h3>${escapeHtml(title)}</h3></header>
          <div class=\"claims-modal-body\"><p style=\"margin:0; font-size:0.9rem;\">${escapeHtml(message)}</p></div>
          <footer class=\"claims-modal-footer\">
            <button class=\"claims-btn claims-btn-primary\" type=\"button\" data-close-alert>OK</button>
          </footer>
        </div>
      `;
      overlay.querySelector('[data-close-alert]')?.addEventListener('click', () => {
        overlay.remove();
        if (!document.querySelector('.claims-modal-overlay.open')) {
          document.body.classList.remove('modal-open');
        }
        resolve();
      });
      overlay.addEventListener('click', (event) => {
        if (event.target === overlay) {
          overlay.remove();
          if (!document.querySelector('.claims-modal-overlay.open')) {
            document.body.classList.remove('modal-open');
          }
          resolve();
        }
      });
      document.body.appendChild(overlay);
      document.body.classList.add('modal-open');
    });
  }

  function fallbackConfirmModal(message) {
    return new Promise((resolve) => {
      const overlay = document.createElement('div');
      overlay.className = 'claims-modal-overlay open';
      overlay.innerHTML = `
        <div class=\"claims-modal\" style=\"width:min(450px, 95vw);\">
          <header class=\"claims-modal-header\"><h3>Confirm Action</h3></header>
          <div class=\"claims-modal-body\"><p style=\"margin:0; font-size:0.9rem;\">${escapeHtml(message)}</p></div>
          <footer class=\"claims-modal-footer\">
            <button class=\"claims-btn\" type=\"button\" data-confirm-no>Cancel</button>
            <button class=\"claims-btn claims-btn-primary\" type=\"button\" data-confirm-yes>Proceed</button>
          </footer>
        </div>
      `;
      const close = (value) => {
        overlay.remove();
        if (!document.querySelector('.claims-modal-overlay.open')) {
          document.body.classList.remove('modal-open');
        }
        resolve(value);
      };
      overlay.querySelector('[data-confirm-no]')?.addEventListener('click', () => close(false));
      overlay.querySelector('[data-confirm-yes]')?.addEventListener('click', () => close(true));
      overlay.addEventListener('click', (event) => {
        if (event.target === overlay) close(false);
      });
      document.body.appendChild(overlay);
      document.body.classList.add('modal-open');
    });
  }

  function showFeedback(message, type) {
    if (!elements.feedback) return;
    const clean = String(message || '').trim();
    if (!clean) {
      elements.feedback.style.display = 'none';
      elements.feedback.className = 'claims-toast';
      elements.feedback.textContent = '';
      return;
    }
    elements.feedback.style.display = '';
    let toastClass = 'claims-toast-success';
    if (type === 'error') {
      toastClass = 'claims-toast-error';
    } else if (type === 'warning') {
      toastClass = 'claims-toast-warning';
    }
    elements.feedback.className = `claims-toast ${toastClass}`;
    elements.feedback.textContent = clean;
  }

  function formatCurrency(value) {
    const amount = Number(value || 0);
    return `UGX ${amount.toLocaleString('en-UG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  }

  function formatCompactCurrency(value) {
    const amount = Number(value || 0);
    if (amount >= 1e9) return `${(amount / 1e9).toFixed(1)}B`;
    if (amount >= 1e6) return `${(amount / 1e6).toFixed(1)}M`;
    if (amount >= 1e3) return `${(amount / 1e3).toFixed(1)}K`;
    return amount.toFixed(0);
  }

  function formatCompactCurrencyDetailed(value) {
    const amount = Number(value || 0);
    if (amount >= 1e9) return `${(amount / 1e9).toFixed(2)}B`;
    if (amount >= 1e6) return `${(amount / 1e6).toFixed(2)}M`;
    if (amount >= 1e3) return `${(amount / 1e3).toFixed(2)}K`;
    return amount.toFixed(2);
  }

  function formatMonthYear(month, year) {
    const mm = Number(month || 0);
    const yy = Number(year || 0);
    if (mm < 1 || mm > 12 || yy <= 0) return '';
    const monthName = new Date(yy, mm - 1, 1).toLocaleString('en-US', { month: 'short' });
    return `${monthName} ${yy}`;
  }

  function formatDate(value) {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);
    return date.toLocaleDateString('en-GB');
  }

  function formatDateTime(value) {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);
    return `${date.toLocaleDateString('en-GB')} ${date.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })}`;
  }

  function formatMonthYearLabel(month, year) {
    const mm = Number(month || 0);
    const yy = Number(year || 0);
    if (mm < 1 || mm > 12 || !yy) return '';
    return `${MONTH_LABELS[mm - 1]} ${yy}`;
  }

  function buildClaimsAggregationTitle(filters = {}) {
    const scope = String(filters.period_scope || 'all');
    const fy = String(filters.financial_year || '').trim();
    const quarter = String(filters.quarter || '').trim();
    const year = String(filters.year || '').trim();
    const month = String(filters.month || '').trim();
    const fromYear = String(filters.from_year || '').trim();
    const fromMonth = String(filters.from_month || '').trim();
    const toYear = String(filters.to_year || '').trim();
    const toMonth = String(filters.to_month || '').trim();

    let periodLabel = '';
    if (scope === 'financial_year' && fy) {
      periodLabel = `FY ${fy.replace(/^FY\\s*/i, '')}`;
    } else if (scope === 'quarter') {
      const fyLabel = fy ? `FY ${fy.replace(/^FY\\s*/i, '')}` : '';
      periodLabel = [fyLabel, quarter].filter(Boolean).join(' ');
    } else if (scope === 'year' && year) {
      periodLabel = `Year ${year}`;
    } else if (scope === 'month' && year && month) {
      periodLabel = formatMonthYearLabel(month, year);
    } else if (scope === 'range' && fromYear && fromMonth && toYear && toMonth) {
      periodLabel = `${formatMonthYearLabel(fromMonth, fromYear)} to ${formatMonthYearLabel(toMonth, toYear)}`;
    }

    const retirementType = String(filters.retirement_type || '').trim();
    const retirementTypeLabel = window.PensionsGoRetirementTypes?.getLabel?.(retirementType) || retirementType;
    const livingStatus = String(filters.living_status || '').trim();
    const statusFilters = Array.isArray(filters.status) ? filters.status : [];
    const claimStatusFilters = Array.isArray(filters.claim_status) ? filters.claim_status : [];
    const search = String(filters.search || '').trim();

    const descriptors = [];
    if (retirementTypeLabel) descriptors.push(retirementTypeLabel);
    if (livingStatus) descriptors.push(livingStatus);

    let title = 'Arrears Summary';
    if (periodLabel) {
      title += ` (${periodLabel})`;
    }

    if (descriptors.length) {
      title += ` - ${descriptors.join(' ')} pensioners`;
    }

    const badges = [];
    if (statusFilters.length && statusFilters.length < 4) {
      badges.push(`Status: ${statusFilters.join('/')}`);
    }
    if (claimStatusFilters.length && claimStatusFilters.length < 4) {
      badges.push(`Claim: ${claimStatusFilters.join('/')}`);
    }
    if (search) {
      badges.push(`Match: ${search}`);
    }

    const typeMode = String(filters.type_mode || 'by_type');
    if (typeMode === 'total_only') {
      badges.push('Totals Only');
    }

    if (badges.length) {
      title += ` - ${badges.join(' | ')}`;
    }

    return title;
  }

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function debounce(fn, waitMs) {
    let timer = null;
    return function debounced(...args) {
      window.clearTimeout(timer);
      timer = window.setTimeout(() => fn.apply(this, args), waitMs);
    };
  }
})();
