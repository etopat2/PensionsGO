(function () {
  const DEFAULT_PREVIEW_PAGE_SIZE = 8;
  const BUDGET_EXPORT_PRESET_ENDPOINT = '../backend/api/budget_export_preset.php';

  const state = {
    selectedFinancialYear: '',
    selectedPensioner: '',
    canManageBudget: false,
    pensionerLookup: new Map(),
    currentSelectedFinancialYearLabel: '',
    lastPrefillKey: '',
    exportPensionerLookup: new Map(),
    exportFilters: null,
    exportPreviewData: null,
    exportFilterOptions: { claimTypes: [], statuses: [], sourceTypes: [] },
    exportPreviewPage: 1,
    exportPreviewPageSize: DEFAULT_PREVIEW_PAGE_SIZE,
    exportPreviewTotalPages: 1,
    exportPresetApplied: false
  };

  const analyticsSettings = {
    exportEnabled: true,
    includeFinancialForecasts: true
  };

  const elements = {};
  let presetCache = null;
  let presetLoaded = false;
  let presetLoading = null;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initBudgetingPage);
  } else {
    initBudgetingPage();
  }

  async function initBudgetingPage() {
    bindElements();
    if (!elements.summaryCards) return;
    bindEvents();
    const hasSession = await ensureActiveSession();
    if (!hasSession) return;
    await loadAnalyticsSettings();
    await loadBudgetSummary();
  }

  function bindElements() {
    elements.feedback = document.getElementById('budgetFeedback');
    elements.fyFilter = document.getElementById('budgetFyFilter');
    elements.pensionerFilter = document.getElementById('budgetPensionerFilter');
    elements.pensionerFilterList = document.getElementById('budgetPensionerFilterList');
    elements.refreshBtn = document.getElementById('refreshBudgetBtn');
    elements.exportBudgetPdfBtn = document.getElementById('exportBudgetPdfBtn');
    elements.exportBudgetXlsxBtn = document.getElementById('exportBudgetXlsxBtn');
    elements.exportBudgetCsvBtn = document.getElementById('exportBudgetCsvBtn');
    elements.openExportBuilderBtn = document.getElementById('openBudgetExportBuilderBtn');
    elements.exportFilterModal = document.getElementById('budgetExportFilterModal');
    elements.exportPreviewModal = document.getElementById('budgetExportPreviewModal');
    elements.exportFilterForm = document.getElementById('budgetExportFilterForm');
    elements.exportFilterCloseBtn = document.getElementById('closeBudgetExportFilterBtn');
    elements.exportFilterCloseTopBtn = document.getElementById('closeBudgetExportFilterBtnTop');
    elements.exportFilterResetBtn = document.getElementById('resetBudgetExportFiltersBtn');
    elements.exportFilterPreviewBtn = document.getElementById('previewBudgetExportBtn');
    elements.exportFilterCsvBtn = document.getElementById('exportBudgetBuilderCsvBtn');
    elements.exportFilterXlsxBtn = document.getElementById('exportBudgetBuilderXlsxBtn');
    elements.exportFilterPdfBtn = document.getElementById('exportBudgetBuilderPdfBtn');
    elements.exportPreviewCloseBtn = document.getElementById('closeBudgetExportPreviewBtn');
    elements.exportPreviewCloseTopBtn = document.getElementById('closeBudgetExportPreviewBtnTop');
    elements.exportPreviewCsvBtn = document.getElementById('exportBudgetPreviewCsvBtn');
    elements.exportPreviewXlsxBtn = document.getElementById('exportBudgetPreviewXlsxBtn');
    elements.exportPreviewPdfBtn = document.getElementById('exportBudgetPreviewPdfBtn');
    elements.exportPreviewMeta = document.getElementById('budgetExportPreviewMeta');
    elements.exportPreviewNote = document.getElementById('budgetExportPreviewNote');
    elements.exportPreviewSummaryBody = document.getElementById('budgetExportPreviewSummaryBody');
    elements.exportPreviewMatrixBody = document.getElementById('budgetExportPreviewMatrixBody');
    elements.exportPreviewMatrixTotals = document.getElementById('budgetExportPreviewMatrixTotals');
    elements.exportPreviewProjectionBody = document.getElementById('budgetExportPreviewProjectionBody');
    elements.exportPreviewPager = document.getElementById('budgetExportPreviewPager');
    elements.exportPreviewPrevBtn = document.getElementById('budgetExportPreviewPrev');
    elements.exportPreviewNextBtn = document.getElementById('budgetExportPreviewNext');
    elements.exportPreviewPageInfo = document.getElementById('budgetExportPreviewPageInfo');
    elements.exportFy = document.getElementById('budgetExportFy');
    elements.exportPensioner = document.getElementById('budgetExportPensioner');
    elements.exportPensionerList = document.getElementById('budgetExportPensionerList');
    elements.exportClaimTypesWrap = document.getElementById('budgetExportClaimTypes');
    elements.exportStatusesWrap = document.getElementById('budgetExportStatuses');
    elements.exportSourcesWrap = document.getElementById('budgetExportSources');
    elements.exportMinTotal = document.getElementById('budgetExportMinTotal');
    elements.exportMaxTotal = document.getElementById('budgetExportMaxTotal');
    elements.exportSort = document.getElementById('budgetExportSort');
    elements.exportIncludeZero = document.getElementById('budgetExportIncludeZero');
    elements.exportSavePresetToggle = document.getElementById('budgetExportSavePreset');
    elements.exportPresetStatus = document.getElementById('budgetExportPresetStatus');
    elements.exportPresetClearBtn = document.getElementById('clearBudgetExportPresetBtn');

    elements.summaryCards = document.getElementById('budgetSummaryCards');
    elements.budgetScheduleBridgeBody = document.getElementById('budgetScheduleBridgeBody');
    elements.budgetScheduleSnapshotBody = document.getElementById('budgetScheduleSnapshotBody');
    elements.saveHint = document.getElementById('budgetSaveHint');

    elements.forecastFyInput = document.getElementById('forecastFyInput');
    elements.forecastPensionInput = document.getElementById('forecastPensionInput');
    elements.forecastGratuityInput = document.getElementById('forecastGratuityInput');
    elements.forecastPensionArrearsInput = document.getElementById('forecastPensionArrearsInput');
    elements.forecastFullArrearsInput = document.getElementById('forecastFullArrearsInput');
    elements.forecastGratuityArrearsInput = document.getElementById('forecastGratuityArrearsInput');
    elements.forecastUnderpaymentInput = document.getElementById('forecastUnderpaymentInput');
    elements.forecastSuspensionInput = document.getElementById('forecastSuspensionInput');
    elements.forecastNotesInput = document.getElementById('forecastNotesInput');
    elements.saveForecastBtn = document.getElementById('saveForecastBtn');
    elements.forecastForm = document.getElementById('budgetForecastForm');
    elements.forecastSection = elements.forecastForm?.closest('section') || null;

    elements.comparisonBody = document.getElementById('budgetComparisonBody');
    elements.historyBody = document.getElementById('budgetHistoryBody');
    elements.budgetMatrixBody = document.getElementById('budgetMatrixBody');
    elements.budgetMatrixTotalsRow = document.getElementById('budgetMatrixTotalsRow');
    elements.budgetCurrentFyProjectionBody = document.getElementById('budgetCurrentFyProjectionBody');
    elements.budgetNextFyProjectionBody = document.getElementById('budgetNextFyProjectionBody');
    elements.projectionSection = elements.budgetCurrentFyProjectionBody?.closest('section') || null;
    elements.historySection = elements.historyBody?.closest('section') || null;
  }

  function bindEvents() {
    if (elements.fyFilter) {
      elements.fyFilter.addEventListener('change', () => {
        state.selectedFinancialYear = String(elements.fyFilter.value || '').trim();
        loadBudgetSummary();
      });
    }

    if (elements.pensionerFilter) {
      elements.pensionerFilter.addEventListener('input', debounce(async () => {
        await populatePensionerSuggestions(String(elements.pensionerFilter.value || '').trim());
        syncPensionerSelection();
        loadBudgetSummary();
      }, 260));
      elements.pensionerFilter.addEventListener('change', () => {
        syncPensionerSelection();
        loadBudgetSummary();
      });
    }

    if (elements.refreshBtn) {
      elements.refreshBtn.addEventListener('click', () => {
        state.lastPrefillKey = '';
        loadBudgetSummary();
      });
    }

    if (elements.saveForecastBtn) {
      elements.saveForecastBtn.addEventListener('click', saveForecast);
    }

    if (elements.exportBudgetPdfBtn) {
      elements.exportBudgetPdfBtn.addEventListener('click', () => exportBudget('pdf'));
    }

    if (elements.exportBudgetXlsxBtn) {
      elements.exportBudgetXlsxBtn.addEventListener('click', () => exportBudget('xlsx'));
    }

    if (elements.exportBudgetCsvBtn) {
      elements.exportBudgetCsvBtn.addEventListener('click', () => exportBudget('csv'));
    }

    if (elements.openExportBuilderBtn) {
      elements.openExportBuilderBtn.addEventListener('click', async () => {
        await openBudgetExportBuilder();
      });
    }

    if (elements.exportFilterCloseBtn) {
      elements.exportFilterCloseBtn.addEventListener('click', () => closeModal(elements.exportFilterModal));
    }
    if (elements.exportFilterCloseTopBtn) {
      elements.exportFilterCloseTopBtn.addEventListener('click', () => closeModal(elements.exportFilterModal));
    }
    if (elements.exportFilterModal) {
      elements.exportFilterModal.addEventListener('click', (event) => {
        if (event.target === elements.exportFilterModal) {
          closeModal(elements.exportFilterModal);
        }
      });
    }
    if (elements.exportPreviewCloseBtn) {
      elements.exportPreviewCloseBtn.addEventListener('click', () => closeModal(elements.exportPreviewModal));
    }
    if (elements.exportPreviewCloseTopBtn) {
      elements.exportPreviewCloseTopBtn.addEventListener('click', () => closeModal(elements.exportPreviewModal));
    }
    if (elements.exportPreviewModal) {
      elements.exportPreviewModal.addEventListener('click', (event) => {
        if (event.target === elements.exportPreviewModal) {
          closeModal(elements.exportPreviewModal);
        }
      });
    }
    if (elements.exportFilterResetBtn) {
      elements.exportFilterResetBtn.addEventListener('click', resetBudgetExportFilters);
    }
    if (elements.exportFilterPreviewBtn) {
      elements.exportFilterPreviewBtn.addEventListener('click', () => previewBudgetExport());
    }
    if (elements.exportFilterCsvBtn) {
      elements.exportFilterCsvBtn.addEventListener('click', async () => exportBudgetFromBuilder('csv'));
    }
    if (elements.exportFilterXlsxBtn) {
      elements.exportFilterXlsxBtn.addEventListener('click', async () => exportBudgetFromBuilder('xlsx'));
    }
    if (elements.exportFilterPdfBtn) {
      elements.exportFilterPdfBtn.addEventListener('click', async () => exportBudgetFromBuilder('pdf'));
    }
    if (elements.exportPreviewCsvBtn) {
      elements.exportPreviewCsvBtn.addEventListener('click', async () => exportBudgetFromBuilder('csv'));
    }
    if (elements.exportPreviewXlsxBtn) {
      elements.exportPreviewXlsxBtn.addEventListener('click', async () => exportBudgetFromBuilder('xlsx'));
    }
    if (elements.exportPreviewPdfBtn) {
      elements.exportPreviewPdfBtn.addEventListener('click', async () => exportBudgetFromBuilder('pdf'));
    }

    if (elements.exportPreviewPrevBtn) {
      elements.exportPreviewPrevBtn.addEventListener('click', () => {
        const nextPage = Math.max(1, Number(state.exportPreviewPage || 1) - 1);
        updatePreviewMatrixPage(nextPage);
      });
    }
    if (elements.exportPreviewNextBtn) {
      elements.exportPreviewNextBtn.addEventListener('click', () => {
        const nextPage = Math.min(Number(state.exportPreviewTotalPages || 1), Number(state.exportPreviewPage || 1) + 1);
        updatePreviewMatrixPage(nextPage);
      });
    }

    if (elements.exportPensioner) {
      elements.exportPensioner.addEventListener('input', debounce(() => {
        syncExportPensionerSelection();
      }, 200));
      elements.exportPensioner.addEventListener('change', () => {
        syncExportPensionerSelection();
      });
    }

    if (elements.exportPresetClearBtn) {
      elements.exportPresetClearBtn.addEventListener('click', () => {
        clearBudgetExportPreset();
      });
    }
  }

  function parseSettingBool(value, fallback = true) {
    if (typeof value === 'boolean') return value;
    if (typeof value === 'number') return value !== 0;
    if (typeof value === 'string') {
      const normalized = value.trim().toLowerCase();
      if (['1', 'true', 'yes', 'on', 'enabled'].includes(normalized)) return true;
      if (['0', 'false', 'no', 'off', 'disabled'].includes(normalized)) return false;
    }
    return fallback;
  }

  function toggleElement(element, shouldShow) {
    if (!element) return;
    element.classList.toggle('hidden', !shouldShow);
  }

  function applyAnalyticsSettings() {
    const allowForecasts = Boolean(analyticsSettings.includeFinancialForecasts);
    const allowExports = Boolean(analyticsSettings.exportEnabled && allowForecasts);

    toggleElement(elements.projectionSection, allowForecasts);
    toggleElement(elements.forecastSection, allowForecasts);
    toggleElement(elements.historySection, allowForecasts);

    [elements.exportBudgetPdfBtn, elements.exportBudgetXlsxBtn, elements.exportBudgetCsvBtn, elements.openExportBuilderBtn].forEach((button) => {
      toggleElement(button, allowExports);
    });
  }

  async function loadAnalyticsSettings() {
    let settings = null;
    try {
      if (window.AppSettingsManager?.load) {
        await window.AppSettingsManager.load();
        settings = {
          analytics_export_enabled: window.AppSettingsManager.get('analytics_export_enabled', analyticsSettings.exportEnabled),
          analytics_include_financial_forecasts: window.AppSettingsManager.get('analytics_include_financial_forecasts', analyticsSettings.includeFinancialForecasts)
        };
      } else {
        const response = await fetch('../backend/api/get_public_settings.php', { credentials: 'include', cache: 'no-store' });
        const data = await response.json();
        if (response.ok && data.success && data.settings) {
          settings = data.settings;
        }
      }
    } catch (error) {
      console.warn('Unable to load analytics settings:', error);
    }

    if (settings) {
      analyticsSettings.exportEnabled = parseSettingBool(settings.analytics_export_enabled, analyticsSettings.exportEnabled);
      analyticsSettings.includeFinancialForecasts = parseSettingBool(settings.analytics_include_financial_forecasts, analyticsSettings.includeFinancialForecasts);
    }

    applyAnalyticsSettings();
    return analyticsSettings;
  }

  function isBudgetExportAllowed() {
    return Boolean(analyticsSettings.exportEnabled && analyticsSettings.includeFinancialForecasts);
  }

  async function loadBudgetSummary() {
    setLoadingState();
    showFeedback('', '');

    try {
      const query = new URLSearchParams();
      if (state.selectedFinancialYear) {
        query.set('financial_year', state.selectedFinancialYear);
      }
      if (state.selectedPensioner) {
        query.set('pensioner', state.selectedPensioner);
      }
      const url = query.toString()
        ? `../backend/api/get_budget_summary.php?${query.toString()}`
        : '../backend/api/get_budget_summary.php';

      const response = await fetch(url, {
        credentials: 'include',
        cache: 'no-store'
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error(data.message || `HTTP ${response.status}`);
      }

      state.canManageBudget = Boolean(data.permissions?.canManageBudget);
      applyPermissionState();
      state.currentSelectedFinancialYearLabel = String(data.selectedFinancialYear || '').trim();
      populateFyFilter(data.financialYearOptions || [], data.selectedFinancialYear || '');
      if (!state.selectedFinancialYear && state.currentSelectedFinancialYearLabel && elements.fyFilter) {
        state.selectedFinancialYear = state.currentSelectedFinancialYearLabel;
        if (Array.from(elements.fyFilter.options).some((opt) => opt.value === state.selectedFinancialYear)) {
          elements.fyFilter.value = state.selectedFinancialYear;
        }
      }
      renderSummaryCards(data);
      renderScheduleBridge(data.scheduleBridge || {});
      renderComparison(data.forecast || null, data.actuals || {});
      renderHistory(data.history || []);
      renderArrearsMatrix(data.matrix || {});
      renderProjections(data.projection || {});
      renderPensionerOptions((data.matrix && data.matrix.pensionerOptions) || []);
      prefillForecastForm(data.forecast || null, data.selectedFinancialYear || '', data.forecastSeed || null);
      updateForecastHint(data.forecast || null, state.currentSelectedFinancialYearLabel);
      applyBudgetExportOptions(data);
    } catch (error) {
      console.error('Budget summary error:', error);
      showFeedback(error.message || 'Failed to load budget summary.', 'error');
      showModalMessage(error.message || 'Failed to load budget summary.', 'error');
      renderEmptyState();
    }
  }

  async function saveForecast() {
    if (!analyticsSettings.includeFinancialForecasts) {
      showFeedback('Forecasting is disabled by analytics settings.', 'error');
      await showModalMessage('Forecasting is disabled by analytics settings.', 'error');
      return;
    }
    if (!state.canManageBudget) {
      showFeedback('You are not authorized to save budget forecasts.', 'error');
      await showModalMessage('You are not authorized to save budget forecasts.', 'error');
      return;
    }

    const financialYear = String(elements.forecastFyInput?.value || '').trim();
    const payload = {
      financialYear,
      estimatedPensionAmount: getAmount(elements.forecastPensionInput),
      estimatedGratuityAmount: getAmount(elements.forecastGratuityInput),
      estimatedPensionArrears: getAmount(elements.forecastPensionArrearsInput),
      estimatedFullPensionArrears: getAmount(elements.forecastFullArrearsInput),
      estimatedGratuityArrears: getAmount(elements.forecastGratuityArrearsInput),
      estimatedUnderpaymentClaims: getAmount(elements.forecastUnderpaymentInput),
      estimatedSuspensionArrears: getAmount(elements.forecastSuspensionInput),
      notes: String(elements.forecastNotesInput?.value || '').trim()
    };

    if (!financialYear || Number(financialYear) < 2000 || Number(financialYear) > 2200) {
      showFeedback('Provide a valid financial year start (e.g. 2026).', 'error');
      await showModalMessage('Provide a valid financial year start (e.g. 2026).', 'error');
      return;
    }

    try {
      const response = await fetch('../backend/api/post_budget_forecast.php', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error(data.message || `HTTP ${response.status}`);
      }

      showFeedback(data.message || 'Budget forecast saved.', 'success');
      state.selectedFinancialYear = String(data.financialYear || '');
      loadBudgetSummary();
    } catch (error) {
      showFeedback(error.message || 'Unable to save budget forecast.', 'error');
      await showModalMessage(error.message || 'Unable to save budget forecast.', 'error');
    }
  }

  function renderSummaryCards(data) {
    if (!elements.summaryCards) return;
    const forecast = data.forecast || {};
    const actuals = data.actuals || {};
    const scheduleBridge = data.scheduleBridge || {};
    const projection = data.projection || {};
    const hasForecast = Boolean(forecast && forecast.id);
    const forecastArrearsTotal = hasForecast
      ? (Number(forecast.estimatedPensionArrears || 0)
        + Number(forecast.estimatedFullPensionArrears || 0)
        + Number(forecast.estimatedGratuityArrears || 0)
        + Number(forecast.estimatedUnderpaymentClaims || 0)
        + Number(forecast.estimatedSuspensionArrears || 0))
      : null;
    const actualTotal = Number(actuals.total_balance || 0);
    const variance = forecastArrearsTotal !== null ? actualTotal - forecastArrearsTotal : null;
    const cards = [
      { label: 'Financial Year', value: data.selectedFinancialYear || '' },
      { label: 'Actual Arrears Balance', value: formatCurrency(actualTotal) },
      { label: 'Scheduled Coverage', value: formatCurrency(scheduleBridge.combinedCoverage || 0) },
      { label: 'Gap After Schedule', value: formatCurrency(scheduleBridge.combinedGap ?? actualTotal) },
      { label: 'Matrix Grand Total', value: formatCurrency(data.matrix?.totals?.total || 0) }
    ];

    if (analyticsSettings.includeFinancialForecasts) {
      cards.splice(1, 0,
        { label: 'Forecast Pension', value: hasForecast ? formatCurrency(forecast.estimatedPensionAmount || 0) : 'Not saved' },
        { label: 'Forecast Gratuity', value: hasForecast ? formatCurrency(forecast.estimatedGratuityAmount || 0) : 'Not saved' },
        { label: 'Forecast Arrears Total', value: hasForecast ? formatCurrency(forecastArrearsTotal || 0) : 'Not saved' }
      );
      cards.splice(cards.length - 1, 0,
        { label: 'Forecast Variance', value: variance === null ? 'N/A' : formatVariance(variance) },
        { label: 'Current FY Projection', value: formatCurrency(projection.current?.total || 0) },
        { label: 'Subsequent FY Projection', value: formatCurrency(projection.next?.total || 0) }
      );
    }

    elements.summaryCards.innerHTML = '';
    cards.forEach((card) => {
      const article = document.createElement('article');
      article.className = 'claims-kpi';
      article.innerHTML = `
        <span class="claims-kpi-label">${escapeHtml(card.label)}</span>
        <span class="claims-kpi-value">${escapeHtml(String(card.value))}</span>
      `;
      elements.summaryCards.appendChild(article);
    });
  }

  function renderScheduleBridge(scheduleBridge) {
    const bridgeBody = elements.budgetScheduleBridgeBody;
    const snapshotBody = elements.budgetScheduleSnapshotBody;
    if (!bridgeBody && !snapshotBody) return;

    const bridge = scheduleBridge || {};
    const latestCycle = bridge.latestCycle || null;
    const coverageRatio = typeof bridge.coverageRatio === 'number' ? bridge.coverageRatio : null;
    const selectedFinancialYear = String(bridge.selectedFinancialYear || state.currentSelectedFinancialYearLabel || '').trim();
    const latestCycleLabel = latestCycle?.scheduleLabel
      ? `${latestCycle.scheduleLabel}${latestCycle.quarterLabel ? ` (${latestCycle.quarterLabel})` : ''}`
      : 'No schedule batch uploaded';
    const latestCycleMeta = latestCycle?.createdAt ? formatDateTime(latestCycle.createdAt) : '';
    const latestCycleFile = latestCycle?.sourceFileName ? String(latestCycle.sourceFileName) : 'Not available';

    if (bridgeBody) {
      const rows = [
        ['Open Pension Arrears', formatCurrency(bridge.openPensionArrears || 0)],
        ['Scheduled Pension Coverage (raw)', formatCurrency(bridge.allocatedPensionAmount || 0)],
        ['Effective Pension Coverage', formatCurrency(bridge.effectivePensionCoverage || 0)],
        ['Pension Gap After Schedule', formatCurrency(bridge.remainingPensionGap || 0)],
        ['Open Gratuity Arrears', formatCurrency(bridge.openGratuityArrears || 0)],
        ['Scheduled Gratuity Coverage (raw)', formatCurrency(bridge.gratuityComponentAmount || 0)],
        ['Effective Gratuity Coverage', formatCurrency(bridge.effectiveGratuityCoverage || 0)],
        ['Gratuity Gap After Schedule', formatCurrency(bridge.remainingGratuityGap || 0)],
        ['Combined Open Arrears', formatCurrency(bridge.openCombinedArrears || 0)],
        ['Combined Effective Coverage', formatCurrency(bridge.combinedCoverage || 0)],
        ['Combined Gap After Schedule', formatCurrency(bridge.combinedGap || 0)],
        ['Coverage Ratio', coverageRatio === null ? 'N/A' : formatPercent(coverageRatio)]
      ];

      bridgeBody.innerHTML = rows
        .map(([label, value]) => `<tr><th>${escapeHtml(label)}</th><td>${escapeHtml(String(value))}</td></tr>`)
        .join('');
    }

    if (snapshotBody) {
      const rows = bridge.hasData
        ? [
            ['Financial Year Window', selectedFinancialYear || 'Not resolved'],
            ['Schedule Cycles Analysed', String(bridge.cyclesCount || 0)],
            ['Latest Schedule Batch', latestCycleLabel],
            ['Latest Batch Received', latestCycleMeta || 'Not recorded'],
            ['Latest Source File', latestCycleFile],
            ['Rows Uploaded', String(bridge.rowsUploaded || 0)],
            ['Matched Rows', `${Number(bridge.matchedRows || 0).toLocaleString('en-UG')} (${formatPercent(bridge.matchRate || 0)})`],
            ['Attention Rows', String(bridge.attentionRows || 0)],
            ['Unmatched Rows', String(bridge.unmatchedRows || 0)],
            ['Review Rows', String(bridge.reviewRows || 0)],
            ['Small Surplus Rows', String(bridge.smallSurplusRows || 0)],
            ['Pension-Arrears Rows', String(bridge.pensionArrearsRows || 0)],
            ['Total Scheduled Amount', formatCurrency(bridge.totalScheduledAmount || 0)],
            ['Raw Gratuity Component', formatCurrency(bridge.gratuityComponentAmount || 0)],
            ['Raw Pension-Sized Surplus', formatCurrency(bridge.pensionSurplusAmount || 0)],
            ['Allocated Pension Coverage', formatCurrency(bridge.allocatedPensionAmount || 0)],
            ['Small Surplus Held For Review', formatCurrency(bridge.smallSurplusAmount || 0)],
            ['Unallocated Schedule Amount', formatCurrency(bridge.unallocatedScheduledAmount || 0)],
            ['Scheduled Full Months', String(bridge.scheduledFullMonths || 0)],
            ['Months Mapped To Open Arrears', String(bridge.allocatedMonths || 0)],
            ['Months Still Unallocated', String(bridge.unallocatedScheduledMonths || 0)]
          ]
        : [
            ['Financial Year Window', selectedFinancialYear || 'Not resolved'],
            ['Monthly Gratuity Schedules', 'No uploads recorded yet for this financial year.'],
            ['Why it matters', 'Once schedules are uploaded from Claims, this bridge will show how much open pension and gratuity exposure is already reflected in the monthly schedules.']
          ];

      snapshotBody.innerHTML = rows
        .map(([label, value]) => `<tr><th>${escapeHtml(label)}</th><td>${escapeHtml(String(value))}</td></tr>`)
        .join('');
    }
  }

  function applyBudgetExportOptions(data) {
    const options = data.filterOptions || {};
    state.exportFilterOptions = {
      claimTypes: Array.isArray(options.claimTypes) ? options.claimTypes : [],
      statuses: Array.isArray(options.statuses) ? options.statuses : [],
      sourceTypes: Array.isArray(options.sourceTypes) ? options.sourceTypes : []
    };
    if (elements.exportFy) {
      const currentValue = String(elements.exportFy.value || '').trim();
      populateSelect(elements.exportFy, data.financialYearOptions || [], 'Select Financial Year');
      if (currentValue && Array.from(elements.exportFy.options).some((opt) => opt.value === currentValue)) {
        elements.exportFy.value = currentValue;
      }
    }

    if (elements.exportPensionerList) {
      const list = Array.isArray(data.matrix?.pensionerOptions) ? data.matrix.pensionerOptions : [];
      state.exportPensionerLookup.clear();
      elements.exportPensionerList.innerHTML = '';
      list.forEach((row) => {
        const regNo = String(row.regNo || '').trim();
        const name = String(row.name || '').trim();
        if (!regNo) return;
        const label = `${regNo} - ${name}`.trim();
        state.exportPensionerLookup.set(label, regNo);
        state.exportPensionerLookup.set(regNo, regNo);
        const option = document.createElement('option');
        option.value = label;
        elements.exportPensionerList.appendChild(option);
      });
    }

    if (elements.exportClaimTypesWrap) {
      const selected = new Set(readCheckedValues(elements.exportClaimTypesWrap));
      renderCheckboxOptions(elements.exportClaimTypesWrap, state.exportFilterOptions.claimTypes, selected);
    }
    if (elements.exportStatusesWrap) {
      const selected = new Set(readCheckedValues(elements.exportStatusesWrap));
      renderCheckboxOptions(elements.exportStatusesWrap, state.exportFilterOptions.statuses, selected);
    }
    if (elements.exportSourcesWrap) {
      const selected = new Set(readCheckedValues(elements.exportSourcesWrap));
      renderCheckboxOptions(elements.exportSourcesWrap, state.exportFilterOptions.sourceTypes, selected);
    }

    applySavedBudgetExportPreset();
  }

  function renderArrearsMatrix(matrix) {
    if (!elements.budgetMatrixBody) return;
    const rows = Array.isArray(matrix.rows) ? matrix.rows : [];
    const totals = matrix.totals || {};
    if (!rows.length) {
      elements.budgetMatrixBody.innerHTML = '<tr><td colspan="9">No arrears rows found for selected filters.</td></tr>';
    } else {
      elements.budgetMatrixBody.innerHTML = '';
      rows.forEach((row) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td>${escapeHtml(row.regNo || '')}</td>
          <td>${escapeHtml(row.title || '')}</td>
          <td>${escapeHtml(row.displayName || row.name || '')}</td>
          <td>${formatCurrency(row.pension_arrears || 0)}</td>
          <td>${formatCurrency(row.gratuity_arrears || 0)}</td>
          <td>${formatCurrency(row.full_pension_arrears || 0)}</td>
          <td>${formatCurrency(row.pension_gratuity || 0)}</td>
          <td>${formatCurrency(row.underpayment || 0)}</td>
          <td>${formatCurrency(row.total || 0)}</td>
        `;
        elements.budgetMatrixBody.appendChild(tr);
      });
    }

    if (elements.budgetMatrixTotalsRow) {
      elements.budgetMatrixTotalsRow.innerHTML = `
        <th colspan="3">Subtotals</th>
        <th>${formatCurrency(totals.pension_arrears || 0)}</th>
        <th>${formatCurrency(totals.gratuity_arrears || 0)}</th>
        <th>${formatCurrency(totals.full_pension_arrears || 0)}</th>
        <th>${formatCurrency(totals.pension_gratuity || 0)}</th>
        <th>${formatCurrency(totals.underpayment || 0)}</th>
        <th>${formatCurrency(totals.total || 0)}</th>
      `;
    }
  }

  function renderProjections(projection) {
    if (!analyticsSettings.includeFinancialForecasts) {
      if (elements.budgetCurrentFyProjectionBody) elements.budgetCurrentFyProjectionBody.innerHTML = '';
      if (elements.budgetNextFyProjectionBody) elements.budgetNextFyProjectionBody.innerHTML = '';
      return;
    }
    const current = projection.current || {};
    const next = projection.next || {};
    const meta = projection.meta || {};
    const currentFy = String(meta.current_fy_label || state.currentSelectedFinancialYearLabel || '').trim();
    const nextFy = String(meta.next_fy_label || getNextFinancialYearLabel(currentFy) || '').trim();

    const currentFyPrefix = currentFy ? `Current ${currentFy}` : 'Current FY';
    const nextFyPrefix = nextFy ? `Subsequent ${nextFy}` : 'Subsequent FY';
    const currentMonthLabel = meta.current_fy_is_active ? 'months remaining' : 'months';
    const buildMeta = (count, months, monthLabel) => {
      const parts = [];
      if (count) parts.push(`${count} officers`);
      if (months) parts.push(`${months} ${monthLabel || 'months'}`);
      return parts.length ? ` (${parts.join(', ')})` : '';
    };

    if (elements.budgetCurrentFyProjectionBody) {
      const activeMeta = buildMeta(current.active_count || 0, current.active_months || 0, currentMonthLabel);
      const retireeMeta = buildMeta(current.retirees_count || 0, 0, '');
      elements.budgetCurrentFyProjectionBody.innerHTML = `
        <tr><th>${escapeHtml(currentFyPrefix)} Active Pensioners (Monthly)${escapeHtml(activeMeta)}</th><td>${formatCurrency(current.active_monthly || 0)}</td></tr>
        <tr><th>${escapeHtml(currentFyPrefix)} Retirees (Monthly)${escapeHtml(retireeMeta)}</th><td>${formatCurrency(current.retirees_monthly || 0)}</td></tr>
        <tr><th>${escapeHtml(currentFyPrefix)} Retirees (Gratuity)${escapeHtml(buildMeta(current.retirees_count || 0, 0, ''))}</th><td>${formatCurrency(current.retirees_gratuity || 0)}</td></tr>
        <tr><th>Total ${escapeHtml(currentFyPrefix)} Requirement</th><td><strong>${formatCurrency(current.total || 0)}</strong></td></tr>
      `;
    }

    if (elements.budgetNextFyProjectionBody) {
      const nextActiveMeta = buildMeta(next.active_count || 0, next.active_months || 0, 'months');
      const continuingMeta = buildMeta(next.current_retirees_count || 0, 0, '');
      const newRetireesMeta = buildMeta(next.next_retirees_count || 0, 0, '');
      elements.budgetNextFyProjectionBody.innerHTML = `
        <tr><th>${escapeHtml(nextFyPrefix)} Active Pensioners (Monthly)${escapeHtml(nextActiveMeta)}</th><td>${formatCurrency(next.active_monthly || 0)}</td></tr>
        <tr><th>${escapeHtml(nextFyPrefix)} Continuing Retirees (Monthly)${escapeHtml(continuingMeta)}</th><td>${formatCurrency(next.current_retirees_monthly || 0)}</td></tr>
        <tr><th>${escapeHtml(nextFyPrefix)} New Retirees (Monthly)${escapeHtml(newRetireesMeta)}</th><td>${formatCurrency(next.next_retirees_monthly || 0)}</td></tr>
        <tr><th>${escapeHtml(nextFyPrefix)} New Retirees (Gratuity)${escapeHtml(newRetireesMeta)}</th><td>${formatCurrency(next.next_retirees_gratuity || 0)}</td></tr>
        <tr><th>Total ${escapeHtml(nextFyPrefix)} Requirement</th><td><strong>${formatCurrency(next.total || 0)}</strong></td></tr>
      `;
    }
  }

  function getNextFinancialYearLabel(currentFy) {
    const match = /^FY\s+(\d{4})\/(\d{4})$/i.exec(String(currentFy || '').trim());
    if (!match) return '';
    const start = Number(match[1]) + 1;
    const end = Number(match[2]) + 1;
    return `FY ${start}/${end}`;
  }

  function renderComparison(forecast, actuals) {
    if (!elements.comparisonBody) return;
    if (!analyticsSettings.includeFinancialForecasts) {
      elements.comparisonBody.innerHTML = '';
      return;
    }
    const safeForecast = forecast || {};
    const safeActuals = actuals || {};

    const rows = [
      { label: 'Pension Arrears', forecast: safeForecast.estimatedPensionArrears || 0, actual: safeActuals.pension_arrears || 0 },
      { label: 'Full Pension Arrears', forecast: safeForecast.estimatedFullPensionArrears || 0, actual: safeActuals.full_pension_arrears || 0 },
      { label: 'Gratuity Arrears', forecast: safeForecast.estimatedGratuityArrears || 0, actual: safeActuals.gratuity_arrears || 0 },
      { label: 'Underpayment Claims', forecast: safeForecast.estimatedUnderpaymentClaims || 0, actual: safeActuals.underpayment_claim || 0 },
      { label: 'Suspended Amount', forecast: safeForecast.estimatedSuspensionArrears || 0, actual: safeActuals.suspension_arrears || 0 }
    ];

    elements.comparisonBody.innerHTML = '';
    let totalForecast = 0;
    let totalActual = 0;
    rows.forEach((row) => {
      totalForecast += Number(row.forecast || 0);
      totalActual += Number(row.actual || 0);
      const variance = Number(row.actual || 0) - Number(row.forecast || 0);
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${escapeHtml(row.label)}</td>
        <td>${formatCurrency(row.forecast)}</td>
        <td>${formatCurrency(row.actual)}</td>
        <td>${formatVariance(variance)}</td>
      `;
      elements.comparisonBody.appendChild(tr);
    });

    const totalVariance = totalActual - totalForecast;
    const totalRow = document.createElement('tr');
    totalRow.innerHTML = `
      <th>Total</th>
      <th>${formatCurrency(totalForecast)}</th>
      <th>${formatCurrency(totalActual)}</th>
      <th>${formatVariance(totalVariance)}</th>
    `;
    elements.comparisonBody.appendChild(totalRow);
  }

  function renderHistory(historyRows) {
    if (!elements.historyBody) return;
    if (!analyticsSettings.includeFinancialForecasts) {
      elements.historyBody.innerHTML = '';
      return;
    }
    if (!historyRows.length) {
      elements.historyBody.innerHTML = '<tr><td colspan="10">No forecast history available.</td></tr>';
      return;
    }

    elements.historyBody.innerHTML = '';
    historyRows.forEach((row) => {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${escapeHtml(row.financialYear || '')}</td>
        <td>${formatCurrency(row.estimatedPensionAmount || 0)}</td>
        <td>${formatCurrency(row.estimatedGratuityAmount || 0)}</td>
        <td>${formatCurrency(row.estimatedPensionArrears || 0)}</td>
        <td>${formatCurrency(row.estimatedFullPensionArrears || 0)}</td>
        <td>${formatCurrency(row.estimatedGratuityArrears || 0)}</td>
        <td>${formatCurrency(row.estimatedUnderpaymentClaims || 0)}</td>
        <td>${formatCurrency(row.estimatedSuspensionArrears || 0)}</td>
        <td>${escapeHtml(row.createdBy || '')}</td>
        <td>${escapeHtml(formatDateTime(row.createdAt || ''))}</td>
      `;
      elements.historyBody.appendChild(tr);
    });
  }

  function populateFyFilter(options, selectedFinancialYear) {
    if (!elements.fyFilter) return;
    const selected = state.selectedFinancialYear || selectedFinancialYear || '';
    const values = Array.from(new Set((options || []).filter(Boolean)));

    const latestLabel = selectedFinancialYear || values[0] || '';
    const latestText = latestLabel ? `Latest Financial Year (${latestLabel})` : 'Latest Financial Year';
    elements.fyFilter.innerHTML = `<option value="">${escapeHtml(latestText)}</option>`;
    values.forEach((value) => {
      const option = document.createElement('option');
      option.value = String(value);
      option.textContent = String(value);
      elements.fyFilter.appendChild(option);
    });

    if (selected && values.includes(selected)) {
      elements.fyFilter.value = selected;
      state.selectedFinancialYear = selected;
    } else if (selectedFinancialYear && values.includes(selectedFinancialYear)) {
      elements.fyFilter.value = selectedFinancialYear;
      state.selectedFinancialYear = selectedFinancialYear;
    } else {
      elements.fyFilter.value = '';
    }
  }

  function prefillForecastForm(forecast, selectedFinancialYear, seed) {
    const resolved = forecast || seed || {};
    const year = parseFinancialYearStart(selectedFinancialYear || resolved.financialYear || '');
    const keyBase = forecast && forecast.id ? `forecast-${forecast.id}` : `seed-${year || selectedFinancialYear || 'none'}`;
    if (state.lastPrefillKey === keyBase) {
      return;
    }
    state.lastPrefillKey = keyBase;

    if (elements.forecastFyInput) elements.forecastFyInput.value = year > 0 ? String(year) : '';
    if (elements.forecastPensionInput) setMoneyInputValue(elements.forecastPensionInput, Number(resolved.estimatedPensionAmount || 0));
    if (elements.forecastGratuityInput) setMoneyInputValue(elements.forecastGratuityInput, Number(resolved.estimatedGratuityAmount || 0));
    if (elements.forecastPensionArrearsInput) setMoneyInputValue(elements.forecastPensionArrearsInput, Number(resolved.estimatedPensionArrears || 0));
    if (elements.forecastFullArrearsInput) setMoneyInputValue(elements.forecastFullArrearsInput, Number(resolved.estimatedFullPensionArrears || 0));
    if (elements.forecastGratuityArrearsInput) setMoneyInputValue(elements.forecastGratuityArrearsInput, Number(resolved.estimatedGratuityArrears || 0));
    if (elements.forecastUnderpaymentInput) setMoneyInputValue(elements.forecastUnderpaymentInput, Number(resolved.estimatedUnderpaymentClaims || 0));
    if (elements.forecastSuspensionInput) setMoneyInputValue(elements.forecastSuspensionInput, Number(resolved.estimatedSuspensionArrears || 0));
    if (elements.forecastNotesInput) elements.forecastNotesInput.value = String(resolved.notes || '');
  }

  function applyPermissionState() {
    if (elements.saveForecastBtn) {
      elements.saveForecastBtn.disabled = !state.canManageBudget;
    }
  }

  function updateForecastHint(forecast, selectedLabel) {
    if (!elements.saveHint) return;
    if (!state.canManageBudget) {
      elements.saveHint.textContent = 'Read-only view. Contact Admin/OC to update forecast.';
      return;
    }
    const fyLabel = selectedLabel ? ` ${selectedLabel}` : '';
    if (forecast && forecast.id) {
      const savedAt = forecast.createdAt ? formatDateTime(forecast.createdAt) : '';
      elements.saveHint.textContent = savedAt
        ? `Latest forecast saved for${fyLabel} (${savedAt}).`
        : `Latest forecast saved for${fyLabel}.`;
      return;
    }
    elements.saveHint.textContent = `No forecast saved for${fyLabel}. Use the form below to capture the baseline.`;
  }

  function setLoadingState() {
    if (!elements.summaryCards) return;
    elements.summaryCards.innerHTML = [
      'Financial Year',
      'Forecast Pension',
      'Forecast Gratuity',
      'Forecast Arrears Total',
      'Actual Arrears Balance',
      'Scheduled Coverage',
      'Gap After Schedule',
      'Forecast Variance',
      'Current FY Projection',
      'Subsequent FY Projection',
      'Matrix Grand Total'
    ]
      .map((label) => `<article class="claims-kpi"><span class="claims-kpi-label">${escapeHtml(label)}</span><span class="claims-kpi-value">...</span></article>`)
      .join('');
    if (elements.comparisonBody) {
      elements.comparisonBody.innerHTML = '<tr><td colspan="4">Loading...</td></tr>';
    }
    if (elements.historyBody) {
      elements.historyBody.innerHTML = '<tr><td colspan="10">Loading...</td></tr>';
    }
    if (elements.budgetScheduleBridgeBody) {
      elements.budgetScheduleBridgeBody.innerHTML = '<tr><td colspan="2">Loading schedule bridge...</td></tr>';
    }
    if (elements.budgetScheduleSnapshotBody) {
      elements.budgetScheduleSnapshotBody.innerHTML = '<tr><td colspan="2">Loading schedule intake snapshot...</td></tr>';
    }
  }

  function renderEmptyState() {
    renderSummaryCards({
      selectedFinancialYear: '',
      forecast: {},
      actuals: {},
      scheduleBridge: {}
    });
    renderScheduleBridge({});
    if (elements.comparisonBody) {
      elements.comparisonBody.innerHTML = '<tr><td colspan="4">No data.</td></tr>';
    }
    if (elements.historyBody) {
      elements.historyBody.innerHTML = '<tr><td colspan="10">No data.</td></tr>';
    }
    if (elements.budgetMatrixBody) {
      elements.budgetMatrixBody.innerHTML = '<tr><td colspan="9">No data.</td></tr>';
    }
    if (elements.budgetCurrentFyProjectionBody) {
      elements.budgetCurrentFyProjectionBody.innerHTML = '<tr><td colspan="2">No data.</td></tr>';
    }
    if (elements.budgetNextFyProjectionBody) {
      elements.budgetNextFyProjectionBody.innerHTML = '<tr><td colspan="2">No data.</td></tr>';
    }
  }

  function parseMoneyInputValue(value, fallback = 0) {
    if (window.PensionsGoMoney?.parse) {
      return window.PensionsGoMoney.parse(value, fallback);
    }
    const parsed = Number.parseFloat(String(value || '').replace(/,/g, ''));
    return Number.isFinite(parsed) ? parsed : fallback;
  }

  function normalizeMoneyInputValue(value) {
    if (window.PensionsGoMoney?.normalize) {
      return window.PensionsGoMoney.normalize(value, { maxDecimals: 2 });
    }
    return String(value || '').replace(/,/g, '').trim();
  }

  function setMoneyInputValue(field, value) {
    if (!field) return;
    if (window.PensionsGoMoney?.setInputValue) {
      window.PensionsGoMoney.setInputValue(field, value);
      return;
    }
    field.value = value ?? '';
  }

  function getAmount(inputEl) {
    return parseMoneyInputValue(inputEl?.value, 0);
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
    elements.feedback.className = `claims-toast ${type === 'error' ? 'claims-toast-error' : 'claims-toast-success'}`;
    elements.feedback.textContent = clean;
  }

  function renderPensionerOptions(options) {
    if (!elements.pensionerFilterList) return;
    const list = Array.isArray(options) ? options : [];
    state.pensionerLookup.clear();
    elements.pensionerFilterList.innerHTML = '';
    list.forEach((row) => {
      const regNo = String(row.regNo || '').trim();
      const name = String(row.name || '').trim();
      if (!regNo) return;
      const label = `${regNo} - ${name}`.trim();
      state.pensionerLookup.set(label, regNo);
      state.pensionerLookup.set(regNo, regNo);
      const option = document.createElement('option');
      option.value = label;
      elements.pensionerFilterList.appendChild(option);
    });
  }

  function renderCheckboxOptions(container, options, selectedValues) {
    if (!container) return;
    const values = Array.isArray(options) ? options : [];
    const safeSelected = selectedValues instanceof Set ? selectedValues : new Set();
    const shouldSelectAll = safeSelected.size === 0;
    container.innerHTML = '';
    values.forEach((value) => {
      const clean = String(value || '').trim();
      if (!clean) return;
      const label = document.createElement('label');
      const checkbox = document.createElement('input');
      checkbox.type = 'checkbox';
      checkbox.value = clean;
      checkbox.checked = shouldSelectAll || safeSelected.has(clean);
      label.appendChild(checkbox);
      label.append(` ${clean}`);
      container.appendChild(label);
    });
  }

  function readCheckedValues(container) {
    if (!container) return [];
    return Array.from(container.querySelectorAll('input[type="checkbox"]'))
      .filter((input) => input.checked)
      .map((input) => String(input.value || '').trim())
      .filter(Boolean);
  }

  function populateSelect(selectEl, values, placeholder) {
    if (!selectEl) return;
    selectEl.innerHTML = '';
    const defaultOption = document.createElement('option');
    defaultOption.value = '';
    defaultOption.textContent = placeholder || 'Select';
    selectEl.appendChild(defaultOption);
    (Array.isArray(values) ? values : []).forEach((value) => {
      const clean = String(value || '').trim();
      if (!clean) return;
      const option = document.createElement('option');
      option.value = clean;
      option.textContent = clean;
      selectEl.appendChild(option);
    });
  }

  async function populatePensionerSuggestions(phrase) {
    const text = String(phrase || '').trim();
    if (!text || text.length < 2) return;
    try {
      const query = new URLSearchParams();
      if (state.selectedFinancialYear) query.set('financial_year', state.selectedFinancialYear);
      query.set('pensioner', text);
      const response = await fetch(`../backend/api/get_budget_summary.php?${query.toString()}`, {
        credentials: 'include',
        cache: 'no-store'
      });
      const data = await response.json();
      if (!response.ok || !data.success) return;
      renderPensionerOptions((data.matrix && data.matrix.pensionerOptions) || []);
    } catch (_error) {
      // silent suggestion errors
    }
  }

  function syncPensionerSelection() {
    const text = String(elements.pensionerFilter?.value || '').trim();
    if (!text) {
      state.selectedPensioner = '';
      return;
    }
    const mapped = state.pensionerLookup.get(text);
    if (mapped) {
      state.selectedPensioner = String(mapped);
      return;
    }
    state.selectedPensioner = text.split('-')[0].trim();
  }

  function exportBudget(format) {
    const normalized = String(format || '').toLowerCase();
    if (!['pdf', 'xlsx', 'csv'].includes(normalized)) {
      return;
    }
    if (!isBudgetExportAllowed()) {
      showFeedback('Budget exports are disabled by analytics settings.', 'error');
      showModalMessage('Budget exports are disabled by analytics settings.', 'error');
      return;
    }
    const query = new URLSearchParams();
    if (state.selectedFinancialYear) query.set('financial_year', state.selectedFinancialYear);
    if (state.selectedPensioner) query.set('pensioner', state.selectedPensioner);
    query.set('format', normalized);
    deliverBudgetExport(`../backend/api/exports/export_budget_planning.php?${query.toString()}`, normalized, 'Budget Forecast & Arrears Planning Report');
  }

  function collectBudgetExportFilters() {
    const filters = {};
    const fy = String(elements.exportFy?.value || '').trim();
    const pensioner = resolveExportPensioner();
    const claimTypes = readCheckedValues(elements.exportClaimTypesWrap);
    const statuses = readCheckedValues(elements.exportStatusesWrap);
    const sources = readCheckedValues(elements.exportSourcesWrap);
    const minTotal = normalizeMoneyInputValue(elements.exportMinTotal?.value || '');
    const maxTotal = normalizeMoneyInputValue(elements.exportMaxTotal?.value || '');
    const sort = String(elements.exportSort?.value || '').trim();
    const includeZero = elements.exportIncludeZero?.checked ? '1' : '0';

    if (fy) filters.financial_year = fy;
    if (pensioner) filters.pensioner = pensioner;
    if (claimTypes.length) filters.claim_types = claimTypes.join(',');
    if (statuses.length) filters.statuses = statuses.join(',');
    if (sources.length) filters.source_types = sources.join(',');
    if (minTotal) filters.min_total = minTotal;
    if (maxTotal) filters.max_total = maxTotal;
    if (sort) filters.sort = sort;
    if (includeZero === '1') filters.include_zero = includeZero;
    return filters;
  }

  function resolveExportPensioner() {
    const text = String(elements.exportPensioner?.value || '').trim();
    if (!text) return '';
    const mapped = state.exportPensionerLookup.get(text);
    if (mapped) return String(mapped);
    return text.split('-')[0].trim();
  }

  function syncExportPensionerSelection() {
    const resolved = resolveExportPensioner();
    state.exportFilters = { ...(state.exportFilters || {}), pensioner: resolved };
  }

  async function previewBudgetExport() {
    try {
      if (!isBudgetExportAllowed()) {
        showFeedback('Budget exports are disabled by analytics settings.', 'error');
        await showModalMessage('Budget exports are disabled by analytics settings.', 'error');
        return;
      }
      const filters = collectBudgetExportFilters();
      state.exportFilters = filters;
      await persistBudgetExportPresetIfNeeded(filters);
      const data = await fetchBudgetSummary(filters);
      state.exportPreviewData = data;
      state.exportPreviewPage = 1;
      renderBudgetExportPreview(data, filters);
      openModal(elements.exportPreviewModal);
    } catch (error) {
      showFeedback(error.message || 'Unable to preview export.', 'error');
      await showModalMessage(error.message || 'Unable to preview export.', 'error');
    }
  }

  async function exportBudgetFromBuilder(format) {
    const normalized = String(format || '').toLowerCase();
    if (!['pdf', 'xlsx', 'csv'].includes(normalized)) return;
    if (!isBudgetExportAllowed()) {
      showFeedback('Budget exports are disabled by analytics settings.', 'error');
      await showModalMessage('Budget exports are disabled by analytics settings.', 'error');
      return;
    }
    const filters = collectBudgetExportFilters();
    state.exportFilters = filters;
    await persistBudgetExportPresetIfNeeded(filters);
    const query = new URLSearchParams(filters);
    query.set('format', normalized);
    deliverBudgetExport(`../backend/api/exports/export_budget_planning.php?${query.toString()}`, normalized, 'Budget Forecast & Arrears Planning Report');
  }

  function triggerCurrentTabDownload(url, fileName = '') {
    const safeUrl = String(url || '').trim();
    if (!safeUrl) return false;
    const anchor = document.createElement('a');
    anchor.href = safeUrl;
    if (fileName) {
      anchor.download = fileName;
    }
    anchor.rel = 'noopener';
    anchor.style.display = 'none';
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    return true;
  }

  function openPdfInSecureViewer(url, label = 'Budget Export PDF') {
    const safeUrl = String(url || '').trim();
    if (!safeUrl) return false;
    const viewerUrl = window.PensionsGoDocumentViewer?.buildViewerUrl
      ? window.PensionsGoDocumentViewer.buildViewerUrl(safeUrl, {
        label,
        backUrl: window.location.href
      })
      : '';
    if (viewerUrl) {
      window.location.assign(viewerUrl);
      return true;
    }
    window.location.assign(safeUrl);
    return true;
  }

  function deliverBudgetExport(url, format, label) {
    const safeUrl = String(url || '').trim();
    const normalized = String(format || '').trim().toLowerCase();
    if (!safeUrl) return false;
    if (normalized === 'pdf') {
      return openPdfInSecureViewer(safeUrl, label);
    }
    return triggerCurrentTabDownload(safeUrl);
  }

  async function fetchBudgetSummary(filters) {
    const query = new URLSearchParams(filters || {});
    const url = query.toString()
      ? `../backend/api/get_budget_summary.php?${query.toString()}`
      : '../backend/api/get_budget_summary.php';
    const response = await fetch(url, { credentials: 'include', cache: 'no-store' });
    const data = await response.json();
    if (!response.ok || !data.success) {
      throw new Error(data.message || `HTTP ${response.status}`);
    }
    return data;
  }

  function renderBudgetExportPreview(data, filters) {
    if (elements.exportPreviewMeta) {
      const fy = filters?.financial_year || data.selectedFinancialYear || 'Latest FY';
      const pensioner = filters?.pensioner ? ` | Pensioner: ${filters.pensioner}` : '';
      elements.exportPreviewMeta.textContent = `Preview for ${fy}${pensioner}.`;
    }

    const actuals = data.actuals || {};
    if (elements.exportPreviewSummaryBody) {
      const summaryRows = [
        ['Pension Arrears', actuals.pension_arrears || 0],
        ['Gratuity Arrears', actuals.gratuity_arrears || 0],
        ['Full Pension Arrears', actuals.full_pension_arrears || 0],
        ['Pension & Gratuity', (Number(actuals.pension_arrears || 0) + Number(actuals.gratuity_arrears || 0))],
        ['Underpayment', actuals.underpayment_claim || 0],
        ['Suspended Amount', actuals.suspension_arrears || 0],
        ['Grand Total', actuals.total_balance || 0]
      ];
      elements.exportPreviewSummaryBody.innerHTML = '';
      summaryRows.forEach((row) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${escapeHtml(row[0])}</td><td>${formatCurrency(row[1])}</td>`;
        elements.exportPreviewSummaryBody.appendChild(tr);
      });
    }

    updatePreviewMatrixPage(state.exportPreviewPage || 1, data);

    if (elements.exportPreviewMatrixTotals) {
      const totals = data.matrix?.totals || {};
      elements.exportPreviewMatrixTotals.innerHTML = `
        <th colspan="3">Totals</th>
        <th>${formatCurrency(totals.pension_arrears || 0)}</th>
        <th>${formatCurrency(totals.gratuity_arrears || 0)}</th>
        <th>${formatCurrency(totals.full_pension_arrears || 0)}</th>
        <th>${formatCurrency(totals.pension_gratuity || 0)}</th>
        <th>${formatCurrency(totals.underpayment || 0)}</th>
        <th>${formatCurrency(totals.total || 0)}</th>
      `;
    }

    if (elements.exportPreviewProjectionBody) {
      const projection = data.projection || {};
      const currentFy = projection.meta?.current_fy_label || data.selectedFinancialYear || 'Current FY';
      const nextFy = projection.meta?.next_fy_label || getNextFinancialYearLabel(currentFy) || 'Subsequent FY';
      const rows = [
        [`Current ${currentFy} Active Pensioners (Monthly)`, projection.current?.active_monthly || 0],
        [`Current ${currentFy} Retirees (Monthly)`, projection.current?.retirees_monthly || 0],
        [`Current ${currentFy} Retirees (Gratuity)`, projection.current?.retirees_gratuity || 0],
        [`Current ${currentFy} Total`, projection.current?.total || 0],
        [`Subsequent ${nextFy} Active Pensioners (Monthly)`, projection.next?.active_monthly || 0],
        [`Subsequent ${nextFy} Continuing Retirees (Monthly)`, projection.next?.current_retirees_monthly || 0],
        [`Subsequent ${nextFy} New Retirees (Monthly)`, projection.next?.next_retirees_monthly || 0],
        [`Subsequent ${nextFy} New Retirees (Gratuity)`, projection.next?.next_retirees_gratuity || 0],
        [`Subsequent ${nextFy} Total`, projection.next?.total || 0]
      ];
      elements.exportPreviewProjectionBody.innerHTML = '';
      rows.forEach((row) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${escapeHtml(row[0])}</td><td>${formatCurrency(row[1])}</td>`;
        elements.exportPreviewProjectionBody.appendChild(tr);
      });
    }
  }

  function resetBudgetExportFilters() {
    if (elements.exportFilterForm) {
      elements.exportFilterForm.reset();
    }
    if (elements.exportClaimTypesWrap) {
      renderCheckboxOptions(elements.exportClaimTypesWrap, state.exportFilterOptions.claimTypes, new Set());
    }
    if (elements.exportStatusesWrap) {
      renderCheckboxOptions(elements.exportStatusesWrap, state.exportFilterOptions.statuses, new Set());
    }
    if (elements.exportSourcesWrap) {
      renderCheckboxOptions(elements.exportSourcesWrap, state.exportFilterOptions.sourceTypes, new Set());
    }
    if (elements.exportSavePresetToggle) {
      elements.exportSavePresetToggle.checked = false;
    }
    updateBudgetPresetStatus();
    state.exportFilters = null;
  }

  async function openBudgetExportBuilder() {
    if (!isBudgetExportAllowed()) {
      showFeedback('Budget exports are disabled by analytics settings.', 'error');
      await showModalMessage('Budget exports are disabled by analytics settings.', 'error');
      return;
    }
    state.exportPresetApplied = false;
    await applySavedBudgetExportPreset();
    openModal(elements.exportFilterModal);
  }

  async function loadBudgetExportPreset(force = false) {
    if (presetLoaded && !force) {
      return presetCache;
    }
    if (presetLoading && !force) {
      return presetLoading;
    }

    presetLoading = (async () => {
      try {
        const response = await fetch(BUDGET_EXPORT_PRESET_ENDPOINT, {
          credentials: 'include',
          cache: 'no-store',
          headers: { 'Accept': 'application/json' }
        });
        const data = await response.json();
        if (!response.ok || !data.success) {
          throw new Error(data.message || `HTTP ${response.status}`);
        }
        presetCache = data.preset || null;
      } catch (_error) {
        presetCache = null;
      }
      presetLoaded = true;
      presetLoading = null;
      return presetCache;
    })();

    return presetLoading;
  }

  async function saveBudgetExportPreset(filters) {
    const payload = { filters: filters || {} };
    try {
      const response = await fetch(BUDGET_EXPORT_PRESET_ENDPOINT, {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify(payload)
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error(data.message || `HTTP ${response.status}`);
      }
      presetCache = data.preset || null;
      presetLoaded = true;
      updateBudgetPresetStatus('Preset saved.');
    } catch (_error) {
      updateBudgetPresetStatus('Unable to save preset.', true);
    }
  }

  async function clearBudgetExportPreset() {
    try {
      const response = await fetch(BUDGET_EXPORT_PRESET_ENDPOINT, {
        method: 'DELETE',
        credentials: 'include',
        headers: { 'Accept': 'application/json' }
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error(data.message || `HTTP ${response.status}`);
      }
      presetCache = null;
      presetLoaded = true;
      updateBudgetPresetStatus('Preset cleared.');
    } catch (_error) {
      updateBudgetPresetStatus('Unable to clear preset.', true);
    }
  }

  async function persistBudgetExportPresetIfNeeded(filters) {
    if (!elements.exportSavePresetToggle?.checked) return;
    await saveBudgetExportPreset(filters);
  }

  function updateBudgetPresetStatus(message = '', isError = false) {
    if (!elements.exportPresetStatus) return;
    const text = String(message || '').trim();
    if (!text) {
      const preset = presetCache;
      if (preset?.savedAt) {
        elements.exportPresetStatus.textContent = `Saved preset (${formatDateTime(preset.savedAt)})`;
        elements.exportPresetStatus.classList.remove('status-error');
      } else {
        elements.exportPresetStatus.textContent = 'No preset saved yet.';
        elements.exportPresetStatus.classList.remove('status-error');
      }
      return;
    }
    elements.exportPresetStatus.textContent = text;
    elements.exportPresetStatus.classList.toggle('status-error', isError);
  }

  async function applySavedBudgetExportPreset() {
    if (state.exportPresetApplied) {
      updateBudgetPresetStatus();
      return;
    }
    const preset = await loadBudgetExportPreset();
    if (!preset || !preset.filters) {
      updateBudgetPresetStatus();
      return;
    }

    const filters = preset.filters || {};
    if (elements.exportFy && filters.financial_year) {
      elements.exportFy.value = filters.financial_year;
    }
    if (elements.exportPensioner && filters.pensioner) {
      elements.exportPensioner.value = filters.pensioner;
    }
    if (elements.exportMinTotal && filters.min_total !== undefined) {
      setMoneyInputValue(elements.exportMinTotal, filters.min_total);
    }
    if (elements.exportMaxTotal && filters.max_total !== undefined) {
      setMoneyInputValue(elements.exportMaxTotal, filters.max_total);
    }
    if (elements.exportSort && filters.sort) {
      elements.exportSort.value = filters.sort;
    }
    if (elements.exportIncludeZero) {
      elements.exportIncludeZero.checked = String(filters.include_zero || '') === '1';
    }

    if (elements.exportClaimTypesWrap) {
      const selected = new Set(String(filters.claim_types || '').split(',').map((v) => v.trim()).filter(Boolean));
      renderCheckboxOptions(elements.exportClaimTypesWrap, state.exportFilterOptions.claimTypes, selected);
    }
    if (elements.exportStatusesWrap) {
      const selected = new Set(String(filters.statuses || '').split(',').map((v) => v.trim()).filter(Boolean));
      renderCheckboxOptions(elements.exportStatusesWrap, state.exportFilterOptions.statuses, selected);
    }
    if (elements.exportSourcesWrap) {
      const selected = new Set(String(filters.source_types || '').split(',').map((v) => v.trim()).filter(Boolean));
      renderCheckboxOptions(elements.exportSourcesWrap, state.exportFilterOptions.sourceTypes, selected);
    }

    state.exportPresetApplied = true;
    updateBudgetPresetStatus();
  }

  function updatePreviewMatrixPage(page, dataOverride) {
    const data = dataOverride || state.exportPreviewData || {};
    const rows = Array.isArray(data.matrix?.rows) ? data.matrix.rows : [];
    const pageSize = Math.max(3, Number(state.exportPreviewPageSize || DEFAULT_PREVIEW_PAGE_SIZE));
    const totalPages = Math.max(1, Math.ceil(rows.length / pageSize));
    const nextPage = Math.min(Math.max(1, Number(page || 1)), totalPages);

    state.exportPreviewPage = nextPage;
    state.exportPreviewTotalPages = totalPages;

    const startIndex = (nextPage - 1) * pageSize;
    const slice = rows.slice(startIndex, startIndex + pageSize);

    if (elements.exportPreviewMatrixBody) {
      elements.exportPreviewMatrixBody.innerHTML = '';
      if (!slice.length) {
        const tr = document.createElement('tr');
        tr.innerHTML = '<td colspan="9">No arrears rows matched the current filters.</td>';
        elements.exportPreviewMatrixBody.appendChild(tr);
      } else {
        slice.forEach((row) => {
          const tr = document.createElement('tr');
          tr.innerHTML = `
            <td>${escapeHtml(row.regNo || '')}</td>
            <td>${escapeHtml(row.title || '')}</td>
            <td>${escapeHtml(row.displayName || row.name || '')}</td>
            <td>${formatCurrency(row.pension_arrears || 0)}</td>
            <td>${formatCurrency(row.gratuity_arrears || 0)}</td>
            <td>${formatCurrency(row.full_pension_arrears || 0)}</td>
            <td>${formatCurrency(row.pension_gratuity || 0)}</td>
            <td>${formatCurrency(row.underpayment || 0)}</td>
            <td>${formatCurrency(row.total || 0)}</td>
          `;
          elements.exportPreviewMatrixBody.appendChild(tr);
        });
      }
    }

    if (elements.exportPreviewPageInfo) {
      elements.exportPreviewPageInfo.textContent = `Page ${nextPage} of ${totalPages}`;
    }
    if (elements.exportPreviewPrevBtn) {
      elements.exportPreviewPrevBtn.disabled = nextPage <= 1;
    }
    if (elements.exportPreviewNextBtn) {
      elements.exportPreviewNextBtn.disabled = nextPage >= totalPages;
    }

    if (elements.exportPreviewNote) {
      const totalRows = rows.length;
      if (!totalRows) {
        elements.exportPreviewNote.textContent = 'No arrears rows matched the current filters.';
      } else {
        const shownStart = totalRows ? startIndex + 1 : 0;
        const shownEnd = Math.min(startIndex + slice.length, totalRows);
        elements.exportPreviewNote.textContent = `Showing ${shownStart}-${shownEnd} of ${totalRows} arrears rows. Export to include all matching rows.`;
      }
    }
  }

  function parseFinancialYearStart(value) {
    const text = String(value || '').trim();
    const fyMatch = text.match(/FY\s*(\d{4})/i);
    if (fyMatch) return Number(fyMatch[1]);
    const yearMatch = text.match(/^(\d{4})/);
    return yearMatch ? Number(yearMatch[1]) : 0;
  }

  function formatCurrency(value) {
    const amount = Number(value || 0);
    return `UGX ${amount.toLocaleString('en-UG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  }

  function formatPercent(value) {
    const numeric = Number(value);
    if (!Number.isFinite(numeric)) return 'N/A';
    return `${(numeric * 100).toLocaleString('en-UG', { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%`;
  }

  function formatVariance(value) {
    const amount = Number(value || 0);
    if (!Number.isFinite(amount)) return 'N/A';
    const abs = Math.abs(amount);
    const sign = amount > 0 ? '+' : amount < 0 ? '-' : '';
    const formatted = formatCurrency(abs);
    return sign ? `${sign} ${formatted}` : formatted;
  }

  function formatDateTime(value) {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);
    return `${date.toLocaleDateString('en-GB')} ${date.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })}`;
  }

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
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

  async function showModalMessage(message, type = 'info') {
    const text = String(message || '').trim();
    if (!text) return;
    if (typeof window.appAlert === 'function') {
      const title = type === 'error' ? 'Action Failed' : type === 'warning' ? 'Notice' : 'Information';
      await window.appAlert(text, { title, type });
      return;
    }
    await new Promise((resolve) => {
      const overlay = document.createElement('div');
      overlay.className = 'claims-modal-overlay open';
      const title = type === 'error' ? 'Action Failed' : type === 'warning' ? 'Notice' : 'Information';
      overlay.innerHTML = `
        <div class=\"claims-modal\" style=\"width:min(430px, 94vw);\">
          <header class=\"claims-modal-header\"><h3>${escapeHtml(title)}</h3></header>
          <div class=\"claims-modal-body\"><p style=\"margin:0; font-size:0.9rem;\">${escapeHtml(text)}</p></div>
          <footer class=\"claims-modal-footer\">
            <button class=\"claims-btn claims-btn-primary\" type=\"button\" data-close-alert>OK</button>
          </footer>
        </div>
      `;
      const close = () => {
        overlay.remove();
        document.body.classList.remove('modal-open');
        resolve();
      };
      overlay.querySelector('[data-close-alert]')?.addEventListener('click', close);
      overlay.addEventListener('click', (event) => {
        if (event.target === overlay) close();
      });
      document.body.appendChild(overlay);
      document.body.classList.add('modal-open');
    });
  }

  function debounce(fn, waitMs) {
    let timer = null;
    return function debounced(...args) {
      window.clearTimeout(timer);
      timer = window.setTimeout(() => fn.apply(this, args), waitMs);
    };
  }
})();
