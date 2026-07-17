document.addEventListener('DOMContentLoaded', () => {
  const accessNotice = document.getElementById('payrollHistoryAccessNotice');
  const filtersContainer = document.getElementById('payrollHistoryFilters');
  const historySummaryCards = document.getElementById('historySummaryCards');

  const historyYear = document.getElementById('historyYear');
  const historyMonth = document.getElementById('historyMonth');
  const historyFinancialYear = document.getElementById('historyFinancialYear');
  const historyQuarter = document.getElementById('historyQuarter');
  const historySearch = document.getElementById('historySearch');
  const historyRefreshBtn = document.getElementById('historyRefreshBtn');

  const cycleList = document.getElementById('cycleList');
  const cyclePageLabel = document.getElementById('cyclePageLabel');
  const cyclePrevBtn = document.getElementById('cyclePrevBtn');
  const cycleNextBtn = document.getElementById('cycleNextBtn');

  const cycleDetailsEmpty = document.getElementById('cycleDetailsEmpty');
  const cycleDetailsContent = document.getElementById('cycleDetailsContent');
  const detailCycleTitle = document.getElementById('detailCycleTitle');
  const detailCycleMeta = document.getElementById('detailCycleMeta');
  const detailSourceFile = document.getElementById('detailSourceFile');
  const detailPaymentRegisterFile = document.getElementById('detailPaymentRegisterFile');
  const detailExportXlsxBtn = document.getElementById('detailExportXlsxBtn');
  const detailEditMonthBtn = document.getElementById('detailEditMonthBtn');
  const detailSummary = document.getElementById('detailSummary');
  const payrollSectionValidation = document.getElementById('payrollSectionValidation');
  const payrollPaymentSummary = document.getElementById('payrollPaymentSummary');
  const payrollClassifiedSectionButtons = document.getElementById('payrollClassifiedSectionButtons');
  const openPaymentExceptionsBtn = document.getElementById('openPaymentExceptionsBtn');
  const payrollAnalysisModal = document.getElementById('payrollAnalysisModal');
  const payrollAnalysisModalTitle = document.getElementById('payrollAnalysisModalTitle');
  const payrollAnalysisModalSubtitle = document.getElementById('payrollAnalysisModalSubtitle');
  const payrollAnalysisSearch = document.getElementById('payrollAnalysisSearch');
  const payrollAnalysisReviewFilter = document.getElementById('payrollAnalysisReviewFilter');
  const payrollAnalysisMatchFilter = document.getElementById('payrollAnalysisMatchFilter');
  const openCycleEntriesBtn = document.getElementById('openCycleEntriesBtn');
  const payrollAnalysisResultSummary = document.getElementById('payrollAnalysisResultSummary');
  const payrollAnalysisTableHead = document.getElementById('payrollAnalysisTableHead');
  const payrollAnalysisTableBody = document.getElementById('payrollAnalysisTableBody');
  const payrollAnalysisPageLabel = document.getElementById('payrollAnalysisPageLabel');
  const payrollAnalysisPrev = document.getElementById('payrollAnalysisPrev');
  const payrollAnalysisNext = document.getElementById('payrollAnalysisNext');
  const payrollAnalysisModalClose = document.getElementById('payrollAnalysisModalClose');
  const detailSearch = document.getElementById('detailSearch');
  const detailRefreshBtn = document.getElementById('detailRefreshBtn');
  const detailTableBody = document.getElementById('detailTableBody');
  const detailPageLabel = document.getElementById('detailPageLabel');
  const detailPrevBtn = document.getElementById('detailPrevBtn');
  const detailNextBtn = document.getElementById('detailNextBtn');
  const statusTabs = Array.from(document.querySelectorAll('.status-tab'));

  const replaceCycleModal = document.getElementById('replaceCycleModal');
  const replaceCycleForm = document.getElementById('replaceCycleForm');
  const replaceCycleIdInput = document.getElementById('replaceCycleId');
  const replaceCycleContext = document.getElementById('replaceCycleContext');
  const replacePayrollFileInput = document.getElementById('replacePayrollFile');
  const replacePaymentRegisterFileInput = document.getElementById('replacePaymentRegisterFile');
  const replaceCycleDownloadTemplateBtn = document.getElementById('replaceCycleDownloadTemplateBtn');
  const replaceCycleSubmitBtn = document.getElementById('replaceCycleSubmitBtn');
  const replaceCycleCancelBtn = document.getElementById('replaceCycleCancelBtn');
  const replaceCycleCloseBtn = document.getElementById('replaceCycleCloseBtn');

  const editCyclePeriodModal = document.getElementById('editCyclePeriodModal');
  const editCyclePeriodForm = document.getElementById('editCyclePeriodForm');
  const editCyclePeriodIdInput = document.getElementById('editCyclePeriodId');
  const editCyclePeriodContext = document.getElementById('editCyclePeriodContext');
  const editCyclePayrollYearInput = document.getElementById('editCyclePayrollYear');
  const editCyclePayrollMonthInput = document.getElementById('editCyclePayrollMonth');
  const editCyclePeriodSubmitBtn = document.getElementById('editCyclePeriodSubmitBtn');
  const editCyclePeriodCancelBtn = document.getElementById('editCyclePeriodCancelBtn');
  const editCyclePeriodCloseBtn = document.getElementById('editCyclePeriodCloseBtn');

  const state = {
    canAccess: false,
    canManage: false,
    cycles: [],
    cyclePage: 1,
    cycleLimit: 12,
    cycleTotalPages: 1,
    cycleSearchTimer: null,
    selectedCycleId: null,
    selectedCycle: null,
    detailStatus: 'all',
    detailSearchTimer: null,
    detailPage: 1,
    detailLimit: 20,
    detailTotalPages: 1,
    detailTotalRows: 0,
    detailRows: [],
    replacingCycleId: null,
    editingCycleId: null
  };

  function consumeViewerReturnState() {
    const params = new URLSearchParams(window.location.search || '');
    const returnKey = String(params.get('viewer_return') || '').trim();
    if (!returnKey || !window.PensionsGoDocumentViewer?.consumeReturnState) {
      return null;
    }

    const restoreState = window.PensionsGoDocumentViewer.consumeReturnState(returnKey);
    params.delete('viewer_return');
    const nextQuery = params.toString();
    const cleanUrl = `${window.location.pathname.split('/').pop()}${nextQuery ? `?${nextQuery}` : ''}${window.location.hash || ''}`;
    window.history.replaceState({}, '', cleanUrl);

    return restoreState && restoreState.page === 'payroll_upload' ? restoreState : null;
  }

  function applyViewerReturnState(restoreState) {
    if (!restoreState) {
      return;
    }

    if (historyYear) historyYear.value = String(restoreState.filters?.year || '');
    if (historyMonth) historyMonth.value = String(restoreState.filters?.month || '');
    if (historyFinancialYear) historyFinancialYear.value = String(restoreState.filters?.financialYear || '');
    if (historyQuarter) historyQuarter.value = String(restoreState.filters?.quarter || '');
    if (historySearch) historySearch.value = String(restoreState.filters?.search || '');
    if (detailSearch) detailSearch.value = String(restoreState.detailSearch || '');

    state.cyclePage = Number(restoreState.cyclePage || 1) || 1;
    state.selectedCycleId = Number(restoreState.selectedCycleId || 0) || null;
    state.detailStatus = String(restoreState.detailStatus || 'all');
    state.detailPage = Number(restoreState.detailPage || 1) || 1;
    statusTabs.forEach((tab) => {
      tab.classList.toggle('active', String(tab.getAttribute('data-status') || 'all') === state.detailStatus);
    });
  }

  function formatUGX(value) {
    const amount = Number(value || 0);
    if (!Number.isFinite(amount)) return 'UGX 0.00';
    return `UGX ${amount.toLocaleString(undefined, {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    })}`;
  }

  function buildPayrollReplacementSummary(data) {
    const matched = Number(data?.stats?.matched || 0);
    const unmatched = Number(data?.stats?.unmatched || 0);
    const onPayroll = Number(data?.stats?.on_payroll || 0);
    const offPayroll = Number(data?.stats?.off_payroll || 0);
    const reviewDownloadStarted = downloadImportReviewExport(data?.review_export, 'payroll_replacement_review.csv');
    return [
      data?.message || 'Payroll cycle replaced successfully.',
      `Matched rows: ${matched}.`,
      `Unmatched rows: ${unmatched}.`,
      `Registry marked On Payroll: ${onPayroll}.`,
      `Registry marked Not on Payroll: ${offPayroll}.`,
      reviewDownloadStarted ? 'A review file download has started for the rows that still need reconciliation.' : ''
    ].join('\n');
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
      const objectUrl = URL.createObjectURL(blob);
      const anchor = document.createElement('a');
      anchor.href = objectUrl;
      anchor.download = reviewExport.file_name || fallbackName;
      document.body.appendChild(anchor);
      anchor.click();
      anchor.remove();
      window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
      return true;
    } catch (error) {
      console.error('Unable to download import review export:', error);
      return false;
    }
  }

  function parseDownloadFilename(contentDisposition, fallbackName) {
    const header = String(contentDisposition || '').trim();
    const utf8Match = /filename\*=UTF-8''([^;]+)/i.exec(header);
    if (utf8Match?.[1]) {
      return decodeURIComponent(utf8Match[1]);
    }
    const quotedMatch = /filename="([^"]+)"/i.exec(header);
    if (quotedMatch?.[1]) {
      return quotedMatch[1];
    }
    const plainMatch = /filename=([^;]+)/i.exec(header);
    if (plainMatch?.[1]) {
      return plainMatch[1].trim();
    }
    return fallbackName;
  }

  function triggerBlobDownload(blob, fileName) {
    const objectUrl = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = objectUrl;
    anchor.download = fileName;
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
  }

  async function downloadPayrollTemplate() {
    if (!state.canManage) {
      appAlert('You do not have permission to download the payroll template.');
      return;
    }

    try {
      const response = await fetch('../backend/api/download_payroll_template.php', {
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
      const fileName = parseDownloadFilename(response.headers.get('Content-Disposition'), 'payroll_upload_template.xlsx');
      triggerBlobDownload(blob, fileName);
      appAlert('Payroll template download has started.', { title: 'Template Ready', type: 'success' });
    } catch (error) {
      console.error('Failed to download payroll template:', error);
      appAlert(error.message || 'Unable to download the payroll template.');
    }
  }

  function escapeHtml(value) {
    if (value === null || value === undefined) return '';
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function monthLabel(month) {
    const num = Number(month || 0);
    if (!num || num < 1 || num > 12) return '--';
    return String(num).padStart(2, '0');
  }

  function monthName(month) {
    const names = [
      '',
      'January', 'February', 'March', 'April', 'May', 'June',
      'July', 'August', 'September', 'October', 'November', 'December'
    ];
    const num = Number(month || 0);
    return names[num] || '--';
  }

  function setAccessMessage(message, isError = false) {
    if (!accessNotice) return;
    accessNotice.classList.remove('hidden');
    accessNotice.textContent = message;
    accessNotice.style.borderColor = isError
      ? 'color-mix(in srgb, var(--error-color) 55%, transparent 45%)'
      : 'var(--border-color)';
  }

  function hideAccessMessage() {
    if (!accessNotice) return;
    accessNotice.classList.add('hidden');
    accessNotice.textContent = '';
  }

  function setPageEnabled(enabled) {
    const sections = [
      filtersContainer,
      historySummaryCards,
      cycleList,
      cyclePrevBtn,
      cycleNextBtn,
      detailRefreshBtn,
      detailSearch,
      detailPrevBtn,
      detailNextBtn
    ];

    sections.forEach((node) => {
      if (!node) return;
      node.style.pointerEvents = enabled ? '' : 'none';
      node.style.opacity = enabled ? '' : '0.55';
    });

    statusTabs.forEach((tab) => {
      tab.disabled = !enabled;
    });
  }

  function buildSummaryCards(summary) {
    if (!historySummaryCards) return;
    const cards = [
      { label: 'Upload Cycles', value: Number(summary.totalCycles || 0), cls: 'total', kind:'cycles', status:'all' },
      { label: 'Uploaded Rows', value: Number(summary.totalRows || 0), cls: 'info', kind:'rows', status:'all' },
      { label: 'Matched Rows', value: Number(summary.matchedRows || 0), cls: 'good', kind:'rows', status:'matched' },
      { label: 'Unmatched Rows', value: Number(summary.unmatchedRows || 0), cls: 'warn', kind:'rows', status:'unmatched' },
      { label: 'Matched Amount', value: formatUGX(summary.matchedAmount || 0), cls: 'good', kind:'rows', status:'matched' },
      { label: 'Unmatched Amount', value: formatUGX(summary.unmatchedAmount || 0), cls: 'warn', kind:'rows', status:'unmatched' }
    ];

    historySummaryCards.innerHTML = cards.map((item) => `
      <button type="button" class="history-stat-card analysis-card ${escapeHtml(item.cls)}" data-history-kind="${item.kind}" data-history-status="${item.status}" data-history-title="${escapeHtml(item.label)}">
        <span class="label">${escapeHtml(item.label)}</span>
        <span class="value">${escapeHtml(item.value)}</span>
      </button>
    `).join('');
  }

  function buildCycleCard(cycle) {
    const isActive = state.selectedCycleId === Number(cycle.cycleId);
    return `
      <article class="cycle-item ${isActive ? 'active' : ''}" data-cycle-id="${Number(cycle.cycleId)}" role="button" tabindex="0">
        <div class="cycle-tools">
          ${state.canManage ? `<button type="button" class="cycle-replace-btn" data-replace-cycle-id="${Number(cycle.cycleId)}" title="Replace cycle payroll files">Replace</button>` : ''}
          ${state.canManage ? `<button type="button" class="cycle-delete-btn" data-delete-cycle-id="${Number(cycle.cycleId)}" title="Delete cycle from active history">Delete</button>` : ''}
        </div>
        <div class="row-top">
          <span class="cycle-title">${escapeHtml(cycle.financialYear || '')} ${escapeHtml(cycle.quarter || '')}</span>
          <span class="cycle-date">${escapeHtml(monthLabel(cycle.month))}/${escapeHtml(String(cycle.year || ''))}</span>
        </div>
        <div class="meta">
          <span>Uploader: ${escapeHtml(cycle.uploadedByName || 'Unknown')}</span>
          <span>Rows: ${escapeHtml(String(cycle.totalRows || 0))}</span>
        </div>
        <div class="stats">
          <span>Matched: ${escapeHtml(String(cycle.matchedRows || 0))}</span>
          <span>Unmatched: ${escapeHtml(String(cycle.unmatchedRows || 0))}</span>
          <span>Total: ${escapeHtml(formatUGX(cycle.totalAmount || 0))}</span>
          <span>${cycle.sourceFile ? 'Payroll: Available' : 'Payroll: Missing'}${cycle.paymentRegisterFile ? ' • Register: Available' : ''}</span>
        </div>
      </article>
    `;
  }

  function bindCycleCardEvents() {
    if (!cycleList) return;
    cycleList.querySelectorAll('.cycle-replace-btn').forEach((node) => {
      node.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        const cycleId = Number(node.getAttribute('data-replace-cycle-id') || 0);
        if (!cycleId) return;
        handleCycleReplace(cycleId);
      });
    });

    cycleList.querySelectorAll('.cycle-delete-btn').forEach((node) => {
      node.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        const cycleId = Number(node.getAttribute('data-delete-cycle-id') || 0);
        if (!cycleId) return;
        handleCycleArchive(cycleId);
      });
    });

    cycleList.querySelectorAll('.cycle-item').forEach((node) => {
      node.addEventListener('click', () => {
        const cycleId = Number(node.getAttribute('data-cycle-id') || 0);
        if (!cycleId) return;
        state.selectedCycleId = cycleId;
        state.detailPage = 1;
        loadCycleDetails();
        cycleList.querySelectorAll('.cycle-item').forEach((el) => el.classList.remove('active'));
        node.classList.add('active');
      });
      node.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        event.preventDefault();
        node.click();
      });
    });
  }

  function openEditPeriodModal(cycle) {
    if (!editCyclePeriodModal || !editCyclePeriodForm || !editCyclePeriodIdInput || !editCyclePeriodContext) return;
    if (!cycle || !cycle.cycleId) return;

    state.editingCycleId = Number(cycle.cycleId);
    editCyclePeriodForm.reset();
    editCyclePeriodIdInput.value = String(state.editingCycleId);
    if (editCyclePayrollYearInput) {
      editCyclePayrollYearInput.value = String(Number(cycle.year || 0) || new Date().getFullYear());
    }
    if (editCyclePayrollMonthInput) {
      editCyclePayrollMonthInput.value = String(Number(cycle.month || 0) || 1);
    }
    editCyclePeriodContext.innerHTML = `
      <strong>${escapeHtml(String(cycle.financialYear || ''))} ${escapeHtml(String(cycle.quarter || ''))}</strong>
      <span>Current period: ${escapeHtml(monthName(cycle.month))} ${escapeHtml(String(cycle.year || ''))}</span>
      <span>Cycle ID: ${escapeHtml(String(cycle.cycleId))}</span>
    `;
    editCyclePeriodModal.classList.remove('hidden');
    document.body.classList.add('modal-open');
  }

  function closeEditPeriodModal() {
    if (!editCyclePeriodModal || !editCyclePeriodForm) return;
    editCyclePeriodModal.classList.add('hidden');
    document.body.classList.remove('modal-open');
    editCyclePeriodForm.reset();
    state.editingCycleId = null;
  }

  function handleCycleEdit(cycleId) {
    if (!state.canManage) return;
    const cycle = state.cycles.find((item) => Number(item.cycleId) === Number(cycleId)) || state.selectedCycle;
    if (!cycle) {
      appAlert('Unable to load selected cycle details for month edit.');
      return;
    }
    openEditPeriodModal(cycle);
  }

  function openReplaceModal(cycle) {
    if (!replaceCycleModal || !replaceCycleForm || !replaceCycleIdInput || !replaceCycleContext) return;
    if (!cycle || !cycle.cycleId) return;
    state.replacingCycleId = Number(cycle.cycleId);
    replaceCycleForm.reset();
    replaceCycleIdInput.value = String(state.replacingCycleId);
    replaceCycleContext.innerHTML = `
      <strong>${escapeHtml(String(cycle.financialYear || ''))} ${escapeHtml(String(cycle.quarter || ''))}</strong>
      <span>${escapeHtml(monthLabel(cycle.month))}/${escapeHtml(String(cycle.year || ''))}</span>
      <span>Current payroll file: ${escapeHtml(String(cycle.sourceFileName || cycle.sourceFile || 'Not available'))}</span>
    `;
    replaceCycleModal.classList.remove('hidden');
    document.body.classList.add('modal-open');
  }

  function closeReplaceModal() {
    if (!replaceCycleModal || !replaceCycleForm) return;
    replaceCycleModal.classList.add('hidden');
    document.body.classList.remove('modal-open');
    replaceCycleForm.reset();
    state.replacingCycleId = null;
  }

  function handleCycleReplace(cycleId) {
    if (!state.canManage) return;
    const cycle = state.cycles.find((item) => Number(item.cycleId) === Number(cycleId)) || state.selectedCycle;
    if (!cycle) {
      appAlert('Unable to load selected cycle details for replacement.');
      return;
    }
    openReplaceModal(cycle);
  }

  async function handleCycleArchive(cycleId) {
    if (!state.canManage) return;
    const confirmed = await appConfirm(
      'Delete this payroll cycle and all related uploaded rows? The system will automatically realign the same month from the latest replacement if available.',
      { title: 'Delete Payroll Cycle', confirmText: 'Delete' }
    );
    if (!confirmed) return;

    try {
      const response = await fetch('../backend/api/manage_payroll_cycle.php', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'delete',
          cycle_id: cycleId
        })
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        appAlert(data.message || 'Unable to delete payroll cycle.');
        return;
      }

      if (Number(state.selectedCycleId) === Number(cycleId)) {
        state.selectedCycleId = null;
      }
      await loadHistory();
    } catch (error) {
      console.error('Failed to delete payroll cycle:', error);
      appAlert('Unable to delete payroll cycle.');
    }
  }

  function renderCycleSummary(summary) {
    if (!detailSummary) return;
    const cards = [
      { label: 'Rows (Current Filter)', value: Number(summary.totalRows || 0), status: 'all' },
      { label: 'Matched', value: Number(summary.matchedRows || 0), status: 'matched' },
      { label: 'Unmatched', value: Number(summary.unmatchedRows || 0), status: 'unmatched' },
      { label: 'Matched Amount', value: formatUGX(summary.matchedAmount || 0), status: 'matched' },
      { label: 'Unmatched Amount', value: formatUGX(summary.unmatchedAmount || 0), status: 'unmatched' },
      { label: 'Total Amount', value: formatUGX(summary.totalAmount || 0), status: 'all' }
    ];

    detailSummary.innerHTML = cards.map((item) => `
      <button type="button" class="detail-stat analysis-card" data-analysis-dataset="payroll" data-analysis-status="${item.status}" data-analysis-title="${escapeHtml(item.label)}">
        <span class="label">${escapeHtml(item.label)}</span>
        <div class="value">${escapeHtml(item.value)}</div>
      </button>
    `).join('');
  }

  function analysisAction(target, id, status) {
    if (!state.canManage) return '';
    if (String(status) === 'Approved') return `<button type="button" class="cycle-replace-btn" data-analysis-review="unapprove" data-analysis-target="${target}" data-analysis-id="${Number(id)}">Unapprove</button>`;
    if (String(status) === 'Rejected') return `<button type="button" class="cycle-delete-btn" data-analysis-review="unreject" data-analysis-target="${target}" data-analysis-id="${Number(id)}">Unreject</button>`;
    if (!['Pending Review', 'Needs Review'].includes(String(status))) return '';
    return `<span class="cycle-tools"><button type="button" class="cycle-replace-btn" data-analysis-review="approve" data-analysis-target="${target}" data-analysis-id="${Number(id)}">Approve</button><button type="button" class="cycle-delete-btn" data-analysis-review="reject" data-analysis-target="${target}" data-analysis-id="${Number(id)}">Reject</button></span>`;
  }

  function renderPayrollAnalysis(data) {
    const summaries = Array.isArray(data.section_summaries) ? data.section_summaries : [];
    if (payrollSectionValidation) payrollSectionValidation.innerHTML = summaries.map((row) => `<button type="button" class="detail-stat analysis-card" data-analysis-dataset="${row.source_section === 'VALID_PAYMENTS' ? 'payroll' : 'classified'}" data-analysis-section="${escapeHtml(row.source_section || '')}" data-analysis-title="${escapeHtml(String(row.source_section || '').replaceAll('_',' '))}"><span class="label">${escapeHtml(String(row.source_section || '').replaceAll('_',' '))}</span><div class="value">${escapeHtml(String(row.extracted_count || 0))}</div><small>${escapeHtml(row.validation_status || '')}</small></button>`).join('') || '<p>No classified section summary is available.</p>';
    const payments = Array.isArray(data.payment_summary) ? data.payment_summary : [];
    if (payrollPaymentSummary) payrollPaymentSummary.innerHTML = payments.map((row) => `<button type="button" class="detail-stat analysis-card" data-analysis-dataset="payment" data-analysis-status="${escapeHtml(row.reconciliation_status || '')}" data-analysis-title="${escapeHtml(row.reconciliation_status || '')}"><span class="label">${escapeHtml(row.reconciliation_status || '')}</span><div class="value">${escapeHtml(String(row.total || 0))}</div><small>${escapeHtml(formatUGX(row.amount_paid || 0))}</small></button>`).join('') || '<p>No parsed payment register is attached.</p>';
    const sections = summaries.filter(row => row.source_section !== 'VALID_PAYMENTS' && Number(row.extracted_count || 0) > 0).map(row => row.source_section);
    if (payrollClassifiedSectionButtons) payrollClassifiedSectionButtons.innerHTML = sections.map(section => `<button type="button" class="btn-action analysis-section-button" data-analysis-dataset="classified" data-analysis-section="${escapeHtml(section)}" data-analysis-title="${escapeHtml(section.replaceAll('_',' '))}">${escapeHtml(section.replaceAll('_',' '))}</button>`).join('') || '<span>No review sections in this cycle.</span>';
  }

  const analysisModalState = { dataset:'', section:'', status:'', title:'', page:1, totalPages:1, timer:null };
  function analysisColumns() {
    if (analysisModalState.dataset === 'history_cycles') return [['cycle_id','Cycle ID'],['payroll_month','Month'],['payroll_year','Year'],['financial_year_label','Financial Year'],['quarter_label','Quarter'],['source_file_original_name','Source File'],['uploaded_by_name','Uploaded By'],['created_at','Uploaded']];
    if (analysisModalState.dataset === 'history_rows') return [['supplierNo','Supplier No.'],['beneficiary_name','Beneficiary'],['invoice_number','Invoice'],['amount','Amount','money'],['matched_regNo','Matched File'],['is_matched','Match Status','match'],['payroll_month','Month'],['payroll_year','Year']];
    if (analysisModalState.dataset === 'payment') return [['invoice_number','Invoice'],['supplierNo','Supplier No.'],['supplier_name','Beneficiary'],['payment_date','Payment Date'],['amount_paid','Amount Paid','money'],['amount_variance','Variance','money'],['eft_number','EFT No.'],['bank_name','Bank'],['account_number_masked','Account'],['reconciliation_status','Status'],['match_confidence','Confidence','percent'],['review_status','Review']];
    if (analysisModalState.dataset === 'payroll') return [['supplierNo','Supplier No.'],['beneficiary_name','Beneficiary'],['invoice_number','Invoice'],['amount','Amount','money'],['matched_regNo','Matched File'],['is_matched','Match Status','match']];
    if (analysisModalState.section === 'RECOVERY') return [['supplierNo','Supplier No.'],['appeared_amount','Pension','money'],['recovery_amount','Recovery Amount','money'],['payable_amount','Balance Payable','money'],['reason','Recovery %age','recoveryPercent'],['beneficiary_name','Name'],['review_status','Review']];
    return [['supplierNo','Supplier No.'],['beneficiary_name','Beneficiary'],['invoice_number','Invoice'],['appeared_amount','Amount','money'],['reason','Reason / Detail'],['matched_regNo','Registry Match'],['review_status','Review']];
  }
  function formatAnalysisCell(row, column) { const [key,,kind]=column; if(kind==='money')return formatUGX(row[key]||0);if(kind==='percent')return `${row[key]||0}%`;if(kind==='recoveryPercent'){const raw=String(row[key]||'');const match=raw.match(/^([0-9.]+)(.*)$/);if(!match)return raw||'-';const numeric=Number(match[1]);return `${numeric<=1?(numeric*100).toFixed(2).replace(/\.00$/,''):numeric}%${match[2]||''}`;}if(kind==='match')return Number(row[key])===1?'Matched':'Unmatched';return row[key]??'-'; }
  async function loadAnalysisModal() {
    const isHistory=analysisModalState.dataset.startsWith('history_');
    const params=isHistory?new URLSearchParams({kind:analysisModalState.dataset==='history_cycles'?'cycles':'rows',status:analysisModalState.status||'all',page:analysisModalState.page,limit:20,year:historyYear?.value||'',month:historyMonth?.value||'',financial_year:historyFinancialYear?.value||'',quarter:historyQuarter?.value||'',search:payrollAnalysisSearch?.value.trim()||historySearch?.value.trim()||''}):new URLSearchParams({cycle_id:state.selectedCycleId,dataset:analysisModalState.dataset,page:analysisModalState.page,limit:20});
    if(analysisModalState.section)params.set('section',analysisModalState.section);if(analysisModalState.status)params.set('status',analysisModalState.status);if(payrollAnalysisSearch?.value.trim())params.set('search',payrollAnalysisSearch.value.trim());if(payrollAnalysisReviewFilter?.value)params.set('review_status',payrollAnalysisReviewFilter.value);
    payrollAnalysisTableBody.innerHTML='<tr><td>Loading records...</td></tr>';
    const response=await fetch(`../backend/api/${isHistory?'get_payroll_history_summary_records.php':'get_payroll_analysis_records.php'}?${params}`,{credentials:'include',cache:'no-store'});const data=await response.json();if(!response.ok||!data.success)throw new Error(data.message||'Unable to load records.');
    if(!isHistory&&analysisModalState.dataset!=='payroll'){
      const selectedReview=payrollAnalysisReviewFilter.value;
      const statuses=Array.isArray(data.available_review_statuses)?data.available_review_statuses:[];
      payrollAnalysisReviewFilter.innerHTML=`<option value="">All available statuses (${statuses.reduce((sum,item)=>sum+Number(item.count||0),0)})</option>${statuses.map(item=>`<option value="${escapeHtml(item.status||'')}">${escapeHtml(item.status||'')} (${Number(item.count||0)})</option>`).join('')}`;
      payrollAnalysisReviewFilter.value=statuses.some(item=>item.status===selectedReview)?selectedReview:'';
    }
    const columns=analysisColumns();payrollAnalysisTableHead.innerHTML=`<tr>${columns.map(c=>`<th>${escapeHtml(c[1])}</th>`).join('')}<th>Action</th></tr>`;
    payrollAnalysisTableBody.innerHTML=data.rows.length?data.rows.map(row=>`<tr>${columns.map(c=>`<td>${escapeHtml(formatAnalysisCell(row,c))}</td>`).join('')}<td>${analysisModalState.dataset==='payroll'?'':analysisAction(analysisModalState.dataset,row.classified_entry_id||row.register_entry_id,row.review_status)}</td></tr>`).join(''):`<tr><td colspan="${columns.length+1}">No records match these filters.</td></tr>`;
    analysisModalState.totalPages=Number(data.total_pages||1);payrollAnalysisPageLabel.textContent=`Page ${data.page} of ${data.total_pages}`;payrollAnalysisPrev.disabled=data.page<=1;payrollAnalysisNext.disabled=data.page>=data.total_pages;payrollAnalysisResultSummary.textContent=`${data.total} record(s) • ${formatUGX(data.total_amount||0)}`;
  }
  function openAnalysisModal(config){Object.assign(analysisModalState,config,{page:1});const history=config.dataset.startsWith('history_');payrollAnalysisModalTitle.textContent=config.title||'Payroll records';payrollAnalysisModalSubtitle.textContent=history?'Filtered using the payroll history controls currently selected on the page.':'Filtered to the selected summary and current payroll cycle.';payrollAnalysisSearch.value='';payrollAnalysisReviewFilter.innerHTML='<option value="">Loading available statuses...</option>';payrollAnalysisMatchFilter.value=config.status||'all';payrollAnalysisReviewFilter.classList.toggle('hidden',config.dataset==='payroll'||history);payrollAnalysisMatchFilter.classList.toggle('hidden',config.dataset!=='payroll');payrollAnalysisModal.classList.remove('hidden');document.body.classList.add('modal-open');loadAnalysisModal().catch(error=>appAlert(error.message));}

  async function loadPayrollAnalysis() {
    if (!state.selectedCycleId) return;
    const response = await fetch(`../backend/api/get_payroll_cycle_analysis.php?cycle_id=${encodeURIComponent(state.selectedCycleId)}`, { credentials: 'include', cache: 'no-store' });
    const data = await response.json();
    if (!response.ok || !data.success) throw new Error(data.message || 'Unable to load payroll analysis.');
    renderPayrollAnalysis(data);
  }

  function renderDetailsRows(rows) {
    state.detailRows = Array.isArray(rows) ? rows : [];
    if (!detailTableBody) return;
    if (!rows.length) {
      detailTableBody.innerHTML = '<tr><td colspan="7">No rows found for current filter.</td></tr>';
      return;
    }

    detailTableBody.innerHTML = rows.map((row) => {
      const statusClass = row.isMatched ? 'matched' : 'unmatched';
      const statusText = row.isMatched ? 'Matched' : 'Unmatched';
      return `
        <tr>
          <td>${escapeHtml(row.supplierNo || '')}</td>
          <td>${escapeHtml(row.beneficiaryName || '')}</td>
          <td>${escapeHtml(formatUGX(row.amount || 0))}</td>
          <td><span class="status-pill ${statusClass}">${statusText}</span></td>
          <td>${escapeHtml(row.matchedRegNo || '-')}</td>
          <td>${escapeHtml(row.matchedName || '-')}</td>
          <td>${escapeHtml(row.matchReason || '')}</td>
        </tr>
      `;
    }).join('');
  }

  async function exportDetailTableAsXlsx() {
    if (!state.selectedCycleId) {
      appAlert('Select a cycle first.');
      return;
    }

    if (!window.XLSX || !window.XLSX.utils) {
      appAlert('XLSX export library is not available. Reload page and try again.');
      return;
    }

    let rows = Array.isArray(state.detailRows) ? [...state.detailRows] : [];
    const totalRows = Number(state.detailTotalRows || rows.length || 0);

    try {
      if (totalRows > rows.length) {
        const pageSize = 200; // Endpoint cap
        const totalPages = Math.max(1, Math.ceil(totalRows / pageSize));
        const allRows = [];

        for (let page = 1; page <= totalPages; page += 1) {
          const params = new URLSearchParams({
            cycle_id: String(state.selectedCycleId),
            status: String(state.detailStatus || 'all'),
            search: String(detailSearch?.value?.trim() || ''),
            page: String(page),
            limit: String(pageSize)
          });

          const response = await fetch(`../backend/api/get_payroll_upload_cycle_details.php?${params.toString()}`, {
            credentials: 'include',
            cache: 'no-store'
          });
          const data = await response.json();
          if (!response.ok || !data.success) {
            appAlert(data.message || 'Unable to load complete rows for export.');
            return;
          }
          const pageRows = Array.isArray(data.rows) ? data.rows : [];
          allRows.push(...pageRows);
        }

        rows = allRows;
      }
    } catch (error) {
      console.error('Failed to fetch rows for xlsx export:', error);
      appAlert('Unable to load complete rows for export.');
      return;
    }

    if (!rows.length) {
      appAlert('No rows available for export.');
      return;
    }

    const headers = [
      'Supplier Number',
      'Beneficiary (Upload)',
      'Amount (UGX)',
      'Status',
      'Matched File',
      'Registry Name',
      'Reason'
    ];

    const dataRows = rows.map((row) => ([
      String(row.supplierNo || ''),
      String(row.beneficiaryName || ''),
      Number(row.amount || 0),
      row.isMatched ? 'Matched' : 'Unmatched',
      String(row.matchedRegNo || ''),
      String(row.matchedName || ''),
      String(row.matchReason || '')
    ]));

    const worksheet = XLSX.utils.aoa_to_sheet([headers, ...dataRows]);
    worksheet['!cols'] = [
      { wch: 18 },
      { wch: 26 },
      { wch: 16 },
      { wch: 12 },
      { wch: 14 },
      { wch: 24 },
      { wch: 28 }
    ];

    const workbook = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(workbook, worksheet, 'Payroll Details');

    const cycle = state.selectedCycle || {};
    const year = String(cycle.year || '');
    const month = monthLabel(cycle.month || '');
    const suffix = `${year}_${month}`.replace(/[^0-9_]/g, '').replace(/^_+|_+$/g, '') || 'cycle';
    XLSX.writeFile(workbook, `payroll_cycle_details_${suffix}.xlsx`);
  }

  async function ensureHistoryAccess() {
    try {
      const response = await fetch('../backend/api/check_session.php', {
        credentials: 'include',
        cache: 'no-store'
      });
      const data = await response.json();

      if (!data.active) {
        window.location.replace('login.html');
        return false;
      }

      // Payroll history is available to all authenticated staff roles except
      // pensioner accounts. Cycle management actions remain admin-only.
      const role = String(data.userRoleEffective || data.userRole || '').toLowerCase().trim();
      if (role === 'pensioner') {
        setAccessMessage('Payroll upload history is not available for pensioner accounts.', true);
        setPageEnabled(false);
        return false;
      }

      hideAccessMessage();
      setPageEnabled(true);
      state.canAccess = true;
      state.canManage = (role === 'admin' || role === 'super_admin');
      if (replaceCycleDownloadTemplateBtn) {
        replaceCycleDownloadTemplateBtn.disabled = !state.canManage;
      }
      return true;
    } catch (error) {
      console.error('Session verification failed:', error);
      setAccessMessage('Unable to verify session. Please reload and try again.', true);
      setPageEnabled(false);
      return false;
    }
  }

  async function loadHistory() {
    if (!state.canAccess) return;

    if (cycleList) {
      cycleList.innerHTML = '<p class="state-message">Loading payroll upload cycles...</p>';
    }

    const params = new URLSearchParams({
      page: String(state.cyclePage),
      limit: String(state.cycleLimit),
      year: String(historyYear?.value || ''),
      month: String(historyMonth?.value || ''),
      financial_year: String(historyFinancialYear?.value || ''),
      quarter: String(historyQuarter?.value || ''),
      search: String(historySearch?.value?.trim() || '')
    });

    try {
      const response = await fetch(`../backend/api/get_payroll_upload_history.php?${params.toString()}`, {
        credentials: 'include',
        cache: 'no-store'
      });
      const data = await response.json();

      if (!response.ok || !data.success) {
        const message = data.message || `HTTP ${response.status}`;
        if (cycleList) {
          cycleList.innerHTML = `<p class="state-message">${escapeHtml(message)}</p>`;
        }
        return;
      }

      buildSummaryCards(data.summary || {});
      state.canManage = !!data.canManage;
      if (replaceCycleDownloadTemplateBtn) {
        replaceCycleDownloadTemplateBtn.disabled = !state.canManage;
      }

      const cycles = Array.isArray(data.cycles) ? data.cycles : [];
      state.cycles = cycles;
      state.cycleTotalPages = Number(data.totalPages || 1);
      if (cyclePageLabel) {
        cyclePageLabel.textContent = `Page ${Number(data.page || 1)} of ${state.cycleTotalPages}`;
      }
      if (cyclePrevBtn) cyclePrevBtn.disabled = Number(data.page || 1) <= 1;
      if (cycleNextBtn) cycleNextBtn.disabled = Number(data.page || 1) >= state.cycleTotalPages;

      if (historyFinancialYear) {
        const availableFY = Array.isArray(data.filters?.financialYears) ? data.filters.financialYears : [];
        const previous = historyFinancialYear.value;
        historyFinancialYear.innerHTML = `<option value="">All</option>${availableFY
          .map((fy) => `<option value="${escapeHtml(fy)}">${escapeHtml(fy)}</option>`)
          .join('')}`;
        if (availableFY.includes(previous)) {
          historyFinancialYear.value = previous;
        }
      }

      if (!cycles.length) {
        if (cycleList) {
          cycleList.innerHTML = '<p class="state-message">No upload cycles found for current filters.</p>';
        }
        state.selectedCycleId = null;
        state.selectedCycle = null;
        showCycleDetailsPlaceholder();
        return;
      }

      // Preserve active cycle context across filter and paging changes.
      // If the selected cycle drops out of scope, pin to first visible cycle.
      const availableCycleIds = new Set(cycles.map((cycle) => Number(cycle.cycleId)));
      if (!state.selectedCycleId || !availableCycleIds.has(Number(state.selectedCycleId))) {
        state.selectedCycleId = Number(cycles[0].cycleId);
      }

      if (cycleList) {
        cycleList.innerHTML = cycles.map((cycle) => buildCycleCard(cycle)).join('');
      }
      bindCycleCardEvents();

      state.selectedCycle = cycles.find((cycle) => Number(cycle.cycleId) === Number(state.selectedCycleId)) || null;
      await loadCycleDetails();
    } catch (error) {
      console.error('Unable to load payroll history:', error);
      if (cycleList) {
        cycleList.innerHTML = '<p class="state-message">Failed to load payroll upload history.</p>';
      }
    }
  }

  function showCycleDetailsPlaceholder() {
    if (cycleDetailsEmpty) cycleDetailsEmpty.classList.remove('hidden');
    if (cycleDetailsContent) cycleDetailsContent.classList.add('hidden');
    if (detailExportXlsxBtn) detailExportXlsxBtn.classList.add('hidden');
    if (detailEditMonthBtn) detailEditMonthBtn.classList.add('hidden');
  }

  function showCycleDetails() {
    if (cycleDetailsEmpty) cycleDetailsEmpty.classList.add('hidden');
    if (cycleDetailsContent) cycleDetailsContent.classList.remove('hidden');
  }

  async function loadCycleDetails() {
    if (!state.canAccess || !state.selectedCycleId) {
      showCycleDetailsPlaceholder();
      return;
    }

    showCycleDetails();
    if (detailTableBody) {
      detailTableBody.innerHTML = '<tr><td colspan="7">Loading cycle details...</td></tr>';
    }

    const params = new URLSearchParams({
      cycle_id: String(state.selectedCycleId),
      status: String(state.detailStatus),
      search: String(detailSearch?.value?.trim() || ''),
      page: String(state.detailPage),
      limit: String(state.detailLimit)
    });

    try {
      const response = await fetch(`../backend/api/get_payroll_upload_cycle_details.php?${params.toString()}`, {
        credentials: 'include',
        cache: 'no-store'
      });
      const data = await response.json();

      if (!response.ok || !data.success) {
        const message = data.message || `HTTP ${response.status}`;
        if (detailTableBody) {
          detailTableBody.innerHTML = `<tr><td colspan="7">${escapeHtml(message)}</td></tr>`;
        }
        return;
      }

      const cycle = data.cycle || null;
      state.selectedCycle = cycle;
      state.detailTotalRows = Number(data.totalRows || 0);
      // Header and source links are refreshed per cycle page so actions
      // (view source, view register, edit month) stay bound to current context.
      if (detailCycleTitle) {
        detailCycleTitle.textContent = cycle
          ? `${cycle.financialYear || ''} ${cycle.quarter || ''} • ${monthLabel(cycle.month)}/${cycle.year || ''}`
          : 'Cycle';
      }
      if (detailCycleMeta) {
        detailCycleMeta.textContent = cycle
          ? `Uploaded by ${cycle.uploadedByName || 'Unknown'} on ${cycle.createdAt || 'N/A'}${cycle.notes ? ` • ${cycle.notes}` : ''}`
          : '-';
      }
      if (detailSourceFile) {
        const sourcePath = String(cycle?.sourceFile || '').trim();
        if (sourcePath) {
          detailSourceFile.classList.remove('hidden');
          const sourceUrl = `../backend/api/view_payroll_document.php?cycle_id=${Number(cycle?.cycleId || state.selectedCycleId)}&type=source`;
          detailSourceFile.href = window.PensionsGoDocumentViewer?.buildViewerUrl
            ? (window.PensionsGoDocumentViewer.buildViewerUrl(sourceUrl, {
              label: 'Payroll Source File',
              backUrl: window.location.href,
              returnState: {
                page: 'payroll_upload',
                cyclePage: state.cyclePage,
                selectedCycleId: Number(cycle?.cycleId || state.selectedCycleId || 0),
                detailStatus: state.detailStatus,
                detailPage: state.detailPage,
                detailSearch: String(detailSearch?.value?.trim() || ''),
                filters: {
                  year: String(historyYear?.value || ''),
                  month: String(historyMonth?.value || ''),
                  financialYear: String(historyFinancialYear?.value || ''),
                  quarter: String(historyQuarter?.value || ''),
                  search: String(historySearch?.value?.trim() || '')
                }
              }
            }) || sourceUrl)
            : sourceUrl;
        } else {
          detailSourceFile.classList.add('hidden');
          detailSourceFile.href = '#';
        }
      }
      if (detailPaymentRegisterFile) {
        const registerPath = String(cycle?.paymentRegisterFile || '').trim();
        if (registerPath) {
          detailPaymentRegisterFile.classList.remove('hidden');
          const registerUrl = `../backend/api/view_payroll_document.php?cycle_id=${Number(cycle?.cycleId || state.selectedCycleId)}&type=register`;
          detailPaymentRegisterFile.href = window.PensionsGoDocumentViewer?.buildViewerUrl
            ? (window.PensionsGoDocumentViewer.buildViewerUrl(registerUrl, {
              label: 'Payment Register',
              backUrl: window.location.href,
              returnState: {
                page: 'payroll_upload',
                cyclePage: state.cyclePage,
                selectedCycleId: Number(cycle?.cycleId || state.selectedCycleId || 0),
                detailStatus: state.detailStatus,
                detailPage: state.detailPage,
                detailSearch: String(detailSearch?.value?.trim() || ''),
                filters: {
                  year: String(historyYear?.value || ''),
                  month: String(historyMonth?.value || ''),
                  financialYear: String(historyFinancialYear?.value || ''),
                  quarter: String(historyQuarter?.value || ''),
                  search: String(historySearch?.value?.trim() || '')
                }
              }
            }) || registerUrl)
            : registerUrl;
        } else {
          detailPaymentRegisterFile.classList.add('hidden');
          detailPaymentRegisterFile.href = '#';
        }
      }
      if (detailExportXlsxBtn) {
        detailExportXlsxBtn.classList.remove('hidden');
      }
      if (detailEditMonthBtn) {
        if (state.canManage) {
          detailEditMonthBtn.classList.remove('hidden');
        } else {
          detailEditMonthBtn.classList.add('hidden');
        }
      }

      renderCycleSummary(data.filteredSummary || {});
      renderDetailsRows(Array.isArray(data.rows) ? data.rows : []);
      await loadPayrollAnalysis();

      state.detailTotalPages = Number(data.totalPages || 1);
      if (detailPageLabel) {
        detailPageLabel.textContent = `Page ${Number(data.page || 1)} of ${state.detailTotalPages}`;
      }
      if (detailPrevBtn) detailPrevBtn.disabled = Number(data.page || 1) <= 1;
      if (detailNextBtn) detailNextBtn.disabled = Number(data.page || 1) >= state.detailTotalPages;
    } catch (error) {
      console.error('Unable to load cycle details:', error);
      state.detailRows = [];
      state.detailTotalRows = 0;
      if (detailTableBody) {
        detailTableBody.innerHTML = '<tr><td colspan="7">Failed to load cycle details.</td></tr>';
      }
    }
  }

  document.addEventListener('click', async (event) => {
    const historyCard=event.target.closest('[data-history-kind]');
    if(historyCard){openAnalysisModal({dataset:historyCard.dataset.historyKind==='cycles'?'history_cycles':'history_rows',section:'',status:historyCard.dataset.historyStatus||'all',title:historyCard.dataset.historyTitle||'Payroll history'});return;}
    const analysisCard = event.target.closest('[data-analysis-dataset]:not([data-analysis-review])');
    if (analysisCard) {
      openAnalysisModal({dataset:analysisCard.dataset.analysisDataset,section:analysisCard.dataset.analysisSection||'',status:analysisCard.dataset.analysisStatus||'',title:analysisCard.dataset.analysisTitle||'Payroll records'});
      return;
    }
    const button = event.target.closest('[data-analysis-review]');
    if (!button) return;
    const decision = button.dataset.analysisReview;
    const target = button.dataset.analysisTarget;
    const id = Number(button.dataset.analysisId || 0);
    if (!id || !state.canManage) return;
    button.disabled = true;
    try {
      const response = await fetch('../backend/api/review_payroll_analysis_entry.php', { method:'POST', credentials:'include', headers:{'Content-Type':'application/json'}, body:JSON.stringify({target,id,decision}) });
      const data = await response.json();
      if (!response.ok || !data.success) throw new Error(data.message || 'Unable to save review decision.');
      appAlert(data.message, { title:'Review Saved', type:'success' });
      await loadPayrollAnalysis();
      if (!payrollAnalysisModal.classList.contains('hidden')) await loadAnalysisModal();
    } catch (error) {
      appAlert(error.message || 'Unable to save review decision.');
      button.disabled = false;
    }
  });

  openPaymentExceptionsBtn?.addEventListener('click',()=>openAnalysisModal({dataset:'payment',section:'',status:'exceptions',title:'Payment Reconciliation Exceptions'}));
  openCycleEntriesBtn?.addEventListener('click',()=>openAnalysisModal({dataset:'payroll',section:'',status:'all',title:'Payroll Cycle Records'}));
  function closeAnalysisModal(){payrollAnalysisModal.classList.add('hidden');document.body.classList.remove('modal-open');}
  payrollAnalysisModalClose?.addEventListener('click',closeAnalysisModal);
  payrollAnalysisModal?.addEventListener('click',event=>{if(event.target===payrollAnalysisModal)closeAnalysisModal();});
  payrollAnalysisSearch?.addEventListener('input',()=>{clearTimeout(analysisModalState.timer);analysisModalState.timer=setTimeout(()=>{analysisModalState.page=1;loadAnalysisModal();},220);});
  payrollAnalysisReviewFilter?.addEventListener('change',()=>{analysisModalState.page=1;loadAnalysisModal();});
  payrollAnalysisMatchFilter?.addEventListener('change',()=>{analysisModalState.status=payrollAnalysisMatchFilter.value;analysisModalState.page=1;loadAnalysisModal();});
  payrollAnalysisPrev?.addEventListener('click',()=>{if(analysisModalState.page>1){analysisModalState.page--;loadAnalysisModal();}});
  payrollAnalysisNext?.addEventListener('click',()=>{if(analysisModalState.page<analysisModalState.totalPages){analysisModalState.page++;loadAnalysisModal();}});

  function bindEvents() {
    const currentYear = new Date().getFullYear();
    if (historyYear) {
      const years = [];
      for (let year = currentYear; year >= currentYear - 10; year -= 1) {
        years.push(`<option value="${year}">${year}</option>`);
      }
      historyYear.innerHTML = `<option value="">All</option>${years.join('')}`;
    }

    [historyYear, historyMonth, historyFinancialYear, historyQuarter].forEach((control) => {
      if (!control) return;
      control.addEventListener('change', () => {
        state.cyclePage = 1;
        loadHistory();
      });
    });

    if (historySearch) {
      historySearch.addEventListener('input', () => {
        // Debounce to reduce API load during typing while keeping near-live feel.
        if (state.cycleSearchTimer) clearTimeout(state.cycleSearchTimer);
        state.cycleSearchTimer = setTimeout(() => {
          state.cyclePage = 1;
          loadHistory();
        }, 220);
      });
    }

    if (historyRefreshBtn) {
      historyRefreshBtn.addEventListener('click', () => {
        state.cyclePage = 1;
        loadHistory();
      });
    }

    if (cyclePrevBtn) {
      cyclePrevBtn.addEventListener('click', () => {
        if (state.cyclePage <= 1) return;
        state.cyclePage -= 1;
        loadHistory();
      });
    }

    if (cycleNextBtn) {
      cycleNextBtn.addEventListener('click', () => {
        if (state.cyclePage >= state.cycleTotalPages) return;
        state.cyclePage += 1;
        loadHistory();
      });
    }

    statusTabs.forEach((tab) => {
      tab.addEventListener('click', () => {
        // Status tabs slice the selected cycle rows (matched/unmatched/all)
        // without resetting the currently selected cycle.
        const status = String(tab.getAttribute('data-status') || 'all');
        state.detailStatus = status;
        state.detailPage = 1;
        statusTabs.forEach((node) => node.classList.remove('active'));
        tab.classList.add('active');
        loadCycleDetails();
      });
    });

    if (detailSearch) {
      detailSearch.addEventListener('input', () => {
        if (state.detailSearchTimer) clearTimeout(state.detailSearchTimer);
        state.detailSearchTimer = setTimeout(() => {
          state.detailPage = 1;
          loadCycleDetails();
        }, 220);
      });
    }

    if (detailRefreshBtn) {
      detailRefreshBtn.addEventListener('click', () => {
        state.detailPage = 1;
        loadCycleDetails();
      });
    }
    if (detailExportXlsxBtn) {
      detailExportXlsxBtn.addEventListener('click', () => {
        exportDetailTableAsXlsx();
      });
    }
    if (detailEditMonthBtn) {
      detailEditMonthBtn.addEventListener('click', () => {
        if (!state.canManage || !state.selectedCycleId) return;
        handleCycleEdit(state.selectedCycleId);
      });
    }

    if (detailPrevBtn) {
      detailPrevBtn.addEventListener('click', () => {
        if (state.detailPage <= 1) return;
        state.detailPage -= 1;
        loadCycleDetails();
      });
    }

    if (detailNextBtn) {
      detailNextBtn.addEventListener('click', () => {
        if (state.detailPage >= state.detailTotalPages) return;
        state.detailPage += 1;
        loadCycleDetails();
      });
    }

    if (replaceCycleCloseBtn) {
      replaceCycleCloseBtn.addEventListener('click', closeReplaceModal);
    }
    if (replaceCycleCancelBtn) {
      replaceCycleCancelBtn.addEventListener('click', closeReplaceModal);
    }
    if (replaceCycleDownloadTemplateBtn) {
      replaceCycleDownloadTemplateBtn.addEventListener('click', () => {
        void downloadPayrollTemplate();
      });
    }
    if (replaceCycleModal) {
      replaceCycleModal.addEventListener('click', (event) => {
        if (event.target === replaceCycleModal) {
          closeReplaceModal();
        }
      });
    }
    if (editCyclePeriodCloseBtn) {
      editCyclePeriodCloseBtn.addEventListener('click', closeEditPeriodModal);
    }
    if (editCyclePeriodCancelBtn) {
      editCyclePeriodCancelBtn.addEventListener('click', closeEditPeriodModal);
    }
    if (editCyclePeriodModal) {
      editCyclePeriodModal.addEventListener('click', (event) => {
        if (event.target === editCyclePeriodModal) {
          closeEditPeriodModal();
        }
      });
    }
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && replaceCycleModal && !replaceCycleModal.classList.contains('hidden')) {
        closeReplaceModal();
      }
      if (event.key === 'Escape' && editCyclePeriodModal && !editCyclePeriodModal.classList.contains('hidden')) {
        closeEditPeriodModal();
      }
    });

    if (replaceCycleForm) {
      replaceCycleForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!state.canManage) return;
        const cycleId = Number(replaceCycleIdInput?.value || state.replacingCycleId || 0);
        if (!cycleId) {
          appAlert('Invalid cycle selected.');
          return;
        }
        if (!replacePayrollFileInput || !replacePayrollFileInput.files || replacePayrollFileInput.files.length === 0) {
          appAlert('Select a replacement payroll file first.');
          return;
        }

        const formData = new FormData();
        formData.append('cycle_id', String(cycleId));
        formData.append('payroll_file', replacePayrollFileInput.files[0]);
        if (replacePaymentRegisterFileInput && replacePaymentRegisterFileInput.files && replacePaymentRegisterFileInput.files.length > 0) {
          formData.append('payment_register_file', replacePaymentRegisterFileInput.files[0]);
        }

        if (replaceCycleSubmitBtn) {
          replaceCycleSubmitBtn.disabled = true;
          replaceCycleSubmitBtn.textContent = 'Replacing...';
        }

        try {
          const response = await fetch('../backend/api/replace_payroll_cycle.php', {
            method: 'POST',
            credentials: 'include',
            body: formData
          });
          const data = await response.json();
          if (!response.ok || !data.success) {
            appAlert(data.message || 'Unable to replace payroll cycle.');
            return;
          }

          state.selectedCycleId = cycleId;
          closeReplaceModal();
          appAlert(buildPayrollReplacementSummary(data), {
            title: Number(data?.stats?.unmatched || 0) > 0 ? 'Payroll Replacement Needs Review' : 'Payroll Replacement Complete',
            type: Number(data?.stats?.unmatched || 0) > 0 ? 'warning' : 'success'
          });
          await loadHistory();
        } catch (error) {
          console.error('Failed to replace payroll cycle:', error);
          appAlert('Unable to replace payroll cycle.');
        } finally {
          if (replaceCycleSubmitBtn) {
            replaceCycleSubmitBtn.disabled = false;
            replaceCycleSubmitBtn.textContent = 'Replace Payroll';
          }
        }
      });
    }

    if (editCyclePeriodForm) {
      editCyclePeriodForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!state.canManage) return;

        const cycleId = Number(editCyclePeriodIdInput?.value || state.editingCycleId || 0);
        const payrollYear = Number(editCyclePayrollYearInput?.value || 0);
        const payrollMonth = Number(editCyclePayrollMonthInput?.value || 0);
        if (!cycleId || payrollYear < 2000 || payrollYear > 2100 || payrollMonth < 1 || payrollMonth > 12) {
          appAlert('Provide a valid payroll year and month.');
          return;
        }

        if (editCyclePeriodSubmitBtn) {
          editCyclePeriodSubmitBtn.disabled = true;
          editCyclePeriodSubmitBtn.textContent = 'Saving...';
        }

        try {
          const response = await fetch('../backend/api/manage_payroll_cycle.php', {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              action: 'edit_period',
              cycle_id: cycleId,
              payroll_year: payrollYear,
              payroll_month: payrollMonth
            })
          });
          const data = await response.json();
          if (!response.ok || !data.success) {
            appAlert(data.message || 'Unable to update payroll period.');
            return;
          }

          state.selectedCycleId = cycleId;
          closeEditPeriodModal();
          await loadHistory();
        } catch (error) {
          console.error('Failed to update payroll cycle period:', error);
          appAlert('Unable to update payroll period.');
        } finally {
          if (editCyclePeriodSubmitBtn) {
            editCyclePeriodSubmitBtn.disabled = false;
            editCyclePeriodSubmitBtn.textContent = 'Save Month';
          }
        }
      });
    }
  }

  async function initialize() {
    bindEvents();
    applyViewerReturnState(consumeViewerReturnState());
    const hasAccess = await ensureHistoryAccess();
    if (!hasAccess) {
      showCycleDetailsPlaceholder();
      if (detailExportXlsxBtn) detailExportXlsxBtn.classList.add('hidden');
      if (detailEditMonthBtn) detailEditMonthBtn.classList.add('hidden');
      return;
    }

    await loadHistory();
  }

  initialize();
});


