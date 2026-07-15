const pensionerBoardState = {
  activeTab: 'profile',
  portalSettings: {},
  modalOpen: false,
  dashboardData: null,
  profileEditOpen: false,
  stationOptionsLoaded: false,
  stationOptions: [],
  availableTabs: ['profile', 'application', 'benefits', 'compliance', 'claims', 'lifecycle']
};

const viewerReturnState = (() => {
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
  return restoreState && restoreState.page === 'pensioner_board' ? restoreState : null;
})();

function formatRetirementTypeLabel(value) {
  return window.PensionsGoRetirementTypes?.getLabel?.(value) || String(value || '').trim() || '--';
}

document.addEventListener('DOMContentLoaded', () => {
  initializePensionerDashboard().catch((error) => {
    console.error('Failed to initialize pensioner dashboard:', error);
    showFeedback(error.message || 'Unable to load your dashboard right now.', 'error');
  });
});

async function initializePensionerDashboard() {
  const role = (sessionStorage.getItem('userRole') || localStorage.getItem('userRole') || '').toLowerCase();
  if (role && role !== 'pensioner') {
    window.location.replace('dashboard.html');
    return;
  }

  bindEvents();
  await loadDashboard();
}

function bindEvents() {
  const refreshBtn = document.getElementById('refreshPensionerDashboardBtn');
  if (refreshBtn) {
    refreshBtn.addEventListener('click', async () => {
      refreshBtn.disabled = true;
      try {
        await loadDashboard();
        if (typeof window.appToast === 'function') {
          window.appToast('Dashboard refreshed.', { type: 'success', title: 'Pensioner Dashboard' });
        }
      } finally {
        refreshBtn.disabled = false;
      }
    });
  }

  document.querySelectorAll('[data-pensioner-tab]').forEach((button) => {
    button.addEventListener('click', () => openDetailsModal(button.dataset.pensionerTab || 'profile'));
  });

  document.querySelectorAll('.pensioner-tab-btn').forEach((button) => {
    button.addEventListener('click', () => switchModalTab(button.dataset.tab || 'profile'));
  });
  document.getElementById('pensionerMobileTabSelect')?.addEventListener('change', (event) => {
    switchModalTab(event.target.value || 'profile');
  });
  document.getElementById('pensionerMobilePrevTabBtn')?.addEventListener('click', () => moveMobileTab(-1));
  document.getElementById('pensionerMobileNextTabBtn')?.addEventListener('click', () => moveMobileTab(1));

  document.getElementById('closePensionerDetailsModal')?.addEventListener('click', closeDetailsModal);
  document.getElementById('closePensionerDetailsFooterBtn')?.addEventListener('click', closeDetailsModal);
  document.getElementById('pensionerDetailsModal')?.addEventListener('click', (event) => {
    if (event.target?.id === 'pensionerDetailsModal') {
      closeDetailsModal();
    }
  });
  document.getElementById('closePensionerProfileEditModal')?.addEventListener('click', () => toggleProfileEditForm(false));
  document.getElementById('pensionerProfileEditModal')?.addEventListener('click', (event) => {
    if (event.target?.id === 'pensionerProfileEditModal') {
      toggleProfileEditForm(false);
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && pensionerBoardState.profileEditOpen) {
      toggleProfileEditForm(false);
      return;
    }
    if (event.key === 'Escape' && pensionerBoardState.modalOpen) {
      closeDetailsModal();
    }
  });

  document.getElementById('openPensionerProfileEditBtn')?.addEventListener('click', () => toggleProfileEditForm(true));
  document.getElementById('cancelPensionerProfileEditBtn')?.addEventListener('click', () => toggleProfileEditForm(false));
  document.getElementById('pensionerProfileEditForm')?.addEventListener('submit', submitPensionerProfileEdit);
}

async function loadDashboard() {
  const response = await fetch('../backend/api/get_pensioner_dashboard.php', {
    credentials: 'include',
    cache: 'no-store',
    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
  });

  let payload = null;
  try {
    payload = await response.json();
  } catch (error) {
    throw new Error('The pensioner dashboard returned an invalid response.');
  }

  if (!response.ok || !payload?.success) {
    if (response.status === 401 || /authentication/i.test(payload?.message || '')) {
      window.location.replace('login.html?reason=session_required');
      return;
    }
    if (response.status === 403 || /access denied/i.test(payload?.message || '')) {
      window.location.replace('dashboard.html');
      return;
    }
    throw new Error(payload?.message || 'Unable to load your pensioner dashboard.');
  }

  renderDashboard(payload);
  if (viewerReturnState) {
    openDetailsModal(viewerReturnState.tab || 'lifecycle');
  }
}

function renderDashboard(data) {
  const portalSettings = data.portalSettings || {};
  const registryStatus = data.registryStatus || {};
  const hasRegistryRecord = Boolean(registryStatus.hasRegistryRecord);
  const profile = data.profile || {};
  const benefits = data.benefits || {};
  const payroll = data.payroll || {};
  const lifeCertificate = data.lifeCertificate || {};
  const accountStatus = data.accountStatus || {};
  const application = data.application || {};
  const lifecycle = data.lifecycle || {};
  const claims = data.claims || { items: [] };
  const documents = data.documents || {};

  pensionerBoardState.portalSettings = portalSettings;
  pensionerBoardState.dashboardData = data;

  setText('pensionerHeroName', profile.name || profile.displayName || 'Pensioner Dashboard');
  setHtml('pensionerHeroMeta', buildHeroMeta(payroll, accountStatus, lifeCertificate, registryStatus));
  setText('pensionerPayrollStatus', payroll.status || (hasRegistryRecord ? 'Not on Payroll' : 'Unavailable'));
  setText('pensionerPayrollNote', buildPayrollNote(payroll, registryStatus));
  setText('pensionerMonthlyAmount', hasRegistryRecord ? formatCurrency(payroll.amount || benefits.reducedPension || 0) : '--');
  setText('pensionerMonthlySource', hasRegistryRecord
    ? (payroll.periodLabel ? `Latest payroll cycle: ${payroll.periodLabel}` : 'Latest recorded payment position')
    : 'Awaiting pension file registry linkage');
  setText('pensionerAccountStatus', accountStatus.status || (hasRegistryRecord ? 'Unknown' : 'Awaiting Registry Link'));
  setText('pensionerAccountReason', accountStatus.reason || registryStatus.message || 'Account standing is not yet available.');
  setText('pensionerLifeStatus', lifeCertificate.status || (hasRegistryRecord ? 'Unknown' : 'Unavailable'));
  setText('pensionerLifeAdvice', lifeCertificate.advice || registryStatus.message || '');

  applySummaryState('pensionerPayrollStatus', payroll.tone || payroll.status);
  applySummaryState('pensionerAccountStatus', accountStatus.tone || accountStatus.status);
  applySummaryState('pensionerLifeStatus', lifeCertificate.tone || lifeCertificate.status);
  applySummaryNoteState('pensionerLifeAdvice', lifeCertificate.tone, Boolean(lifeCertificate.advice));

  setImage('pensionerProfilePhoto', profile.photo);
  setText('pensionerProfileName', profile.name || profile.displayName || 'Beneficiary');
  setText('pensionerRegNoChip', `File: ${profile.regNo || '--'}`);
  setText('pensionerSupplierChip', `Supplier: ${profile.supplierNo || '--'}`);

  renderDetailGrid('pensionerProfileGrid', [
    ['Full Name', profile.name || profile.displayName || '--'],
    ['Email Address', profile.email || '--'],
    ['Phone Number', profile.phone || '--'],
    ['Station / Retirement Location', profile.station || '--'],
    ['District of Residence', profile.address || '--'],
    ['Bank Name', profile.bankName || '--'],
    ['Bank Account', profile.bankAccount || '--'],
    ['Bank Branch', profile.bankBranch || '--'],
    ['Next of Kin', profile.nextOfKin || '--'],
    ['Next of Kin Contact', profile.nextOfKinContact || '--']
  ]);
  updateProfileEditAccess(profile);
  applyProfileEditFieldVisibility(data);
  populateProfileEditForm(profile);

  const currentStep = application.currentStep || {};
  setText('pensionerCurrentStep', currentStep.label || 'Application Received');
  setText('pensionerApplicationStatus', application.applicationStatus || application.submissionStatus || 'Pending');
  renderApplicationSteps(application.steps || []);

  renderMetricGrid('pensionerBenefitsGrid', [
    ['Monthly Salary', formatCurrency(benefits.monthlySalary)],
    ['Annual Salary', formatCurrency(benefits.annualSalary)],
    ['Reduced Pension', formatCurrency(benefits.reducedPension)],
    ['Full Pension', formatCurrency(benefits.fullPension)],
    ['Commuted Gratuity', formatCurrency(benefits.commutedGratuity)],
    ['Length of Service', formatMonths(benefits.lengthOfServiceMonths)],
    ['Date On 15 Years', formatDate(benefits.dateOn15Years)],
    ['Pay Type', lifecycle.payType || 'Pensioner']
  ]);

  const complianceRows = hasRegistryRecord
    ? [
        ['Payroll Status', payroll.status || 'Not on Payroll'],
        ['Payroll Period', payroll.periodLabel || '--'],
        ['Financial Year', payroll.financialYear || '--'],
        ['Quarter', payroll.quarter || '--'],
        ['Account Status', accountStatus.status || '--'],
        ['Status Reason', accountStatus.reason || '--'],
        ['Life Certificate', lifeCertificate.status || '--'],
        ['Current Year', lifeCertificate.year || '--'],
        ['Submitted At', formatDateTime(lifeCertificate.submittedAt)],
        {
          label: 'Advice',
          value: portalSettings.showStatusHelp === false && !lifeCertificate.isPending
            ? 'Guidance hidden by administrator.'
            : (lifeCertificate.advice || accountStatus.reason || '--'),
          tone: lifeCertificate.tone || 'neutral',
          advisory: true,
          fullWidth: true
        }
      ]
    : [
        ['Registry Status', registryStatus.status || 'Awaiting Registry Link'],
        ['Payroll Status', 'Unavailable'],
        ['Account Status', accountStatus.status || 'Awaiting Registry Link'],
        ['Life Certificate', lifeCertificate.status || 'Unavailable'],
        {
          label: 'Registry Guidance',
          value: registryStatus.message || 'No pensioner data is currently available in the pension file registry for this account.',
          tone: registryStatus.tone || 'info',
          advisory: true,
          fullWidth: true
        }
      ];
  renderDetailGrid('pensionerComplianceGrid', complianceRows);

  renderDetailGrid('pensionerLifecycleGrid', [
    ['Retirement Type', formatRetirementTypeLabel(lifecycle.retirementType)],
    ['Retirement Date', formatDate(lifecycle.retirementDate)],
    ['Date of Birth', formatDate(lifecycle.birthDate)],
    ['Date of Enlistment', formatDate(lifecycle.enlistmentDate)],
    ['Living Status', lifecycle.livingStatus || '--'],
    ['Pay Type', lifecycle.payType || '--']
  ]);

  const claimsPanel = document.getElementById('pensionerClaimsPanel');
  const claimsLaunchBtn = document.getElementById('pensionerClaimsLaunchBtn');
  const claimsTabBtn = document.getElementById('pensionerClaimsTabBtn');
  const claimsMobileOption = document.querySelector('#pensionerMobileTabSelect option[value="claims"]');
  const claimsEnabled = portalSettings.showClaims !== false;
  if (claimsPanel) claimsPanel.hidden = !claimsEnabled;
  if (claimsLaunchBtn) claimsLaunchBtn.hidden = !claimsEnabled;
  if (claimsTabBtn) claimsTabBtn.hidden = !claimsEnabled;
  if (claimsMobileOption) claimsMobileOption.hidden = !claimsEnabled;
  pensionerBoardState.availableTabs = ['profile', 'application', 'benefits', 'compliance', 'lifecycle'];
  if (claimsEnabled) {
    pensionerBoardState.availableTabs.splice(4, 0, 'claims');
  }
  if (!claimsEnabled && pensionerBoardState.activeTab === 'claims') {
    pensionerBoardState.activeTab = 'profile';
  }
  renderClaimSummary(claimsEnabled ? (claims.items || []) : [], claims, registryStatus);

  const documentsPanel = document.getElementById('pensionerDocumentsPanel');
  const documentsEnabled = portalSettings.showDocuments !== false;
  const lookupBtn = document.getElementById('openPensionerLookupBtn');
  if (documentsPanel) documentsPanel.hidden = !documentsEnabled;
  if (lookupBtn) lookupBtn.hidden = portalSettings.lookupEnabled === false;
  setText('pensionerDocumentCount', hasRegistryRecord ? String(documents.count || 0) : '--');
  renderDocumentsList(documentsEnabled ? (documents.items || []) : [], registryStatus);

  renderFeedbackBanners({ portalSettings, registryStatus, payroll, accountStatus, lifeCertificate, lifecycle, claims });
}

function openDetailsModal(tab) {
  const modal = document.getElementById('pensionerDetailsModal');
  if (!modal) return;
  pensionerBoardState.modalOpen = true;
  modal.hidden = false;
  document.body.classList.add('modal-open');
  switchModalTab(tab);
}

function closeDetailsModal() {
  const modal = document.getElementById('pensionerDetailsModal');
  if (!modal) return;
  pensionerBoardState.modalOpen = false;
  modal.hidden = true;
  document.body.classList.remove('modal-open');
  toggleProfileEditForm(false, { silent: true });
}

function switchModalTab(tab) {
  const nextTab = pensionerBoardState.availableTabs.includes(tab) ? tab : pensionerBoardState.availableTabs[0] || 'profile';
  const modalBody = document.querySelector('.pensioner-modal-body');

  pensionerBoardState.activeTab = nextTab;
  document.querySelectorAll('.pensioner-tab-btn').forEach((button) => {
    button.classList.toggle('active', button.dataset.tab === nextTab);
  });
  document.querySelectorAll('.pensioner-tab-panel').forEach((panel) => {
    panel.classList.toggle('active', panel.dataset.tabPanel === nextTab);
  });
  syncMobileTabNavigator(nextTab);
  const activeDesktopTab = document.querySelector(`.pensioner-tab-btn[data-tab="${nextTab}"]`);
  activeDesktopTab?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
  if (modalBody) {
    modalBody.scrollTop = 0;
  }

  const titleMap = {
    profile: ['Beneficiary Profile', 'Review your registry identity, maintain selected contact details, and confirm the banking information currently held on file.'],
    application: ['Application Progress', 'Track the current workflow stage, comments, and milestone history.'],
    benefits: ['Benefits Snapshot', 'Review salary, pension values, gratuity, and service timeline.'],
    compliance: ['Payroll & Compliance', 'Check payroll position, account standing, and life certificate status.'],
    claims: ['Claims & Arrears', 'View outstanding claims, arrears balances, and recent financial movement.'],
    lifecycle: ['Lifecycle & Documents', 'See retirement profile, pension category, and indexed document summary.']
  };
  setText('pensionerModalTitle', titleMap[nextTab]?.[0] || 'Pensioner Details');
  setText('pensionerModalSubtitle', titleMap[nextTab]?.[1] || 'Browse the sections below to review the details relevant to your account.');
}

function syncMobileTabNavigator(activeTab) {
  const select = document.getElementById('pensionerMobileTabSelect');
  const prevBtn = document.getElementById('pensionerMobilePrevTabBtn');
  const nextBtn = document.getElementById('pensionerMobileNextTabBtn');
  if (!select) return;

  const availableTabs = pensionerBoardState.availableTabs || [];
  select.value = availableTabs.includes(activeTab) ? activeTab : (availableTabs[0] || 'profile');

  Array.from(select.options).forEach((option) => {
    const visible = availableTabs.includes(option.value);
    option.hidden = !visible;
    option.disabled = !visible;
  });

  const activeIndex = availableTabs.indexOf(select.value);
  if (prevBtn) {
    prevBtn.disabled = activeIndex <= 0;
  }
  if (nextBtn) {
    nextBtn.disabled = activeIndex === -1 || activeIndex >= availableTabs.length - 1;
  }
}

function moveMobileTab(direction) {
  const tabs = pensionerBoardState.availableTabs || [];
  const currentIndex = tabs.indexOf(pensionerBoardState.activeTab);
  if (currentIndex === -1) return;

  const nextIndex = currentIndex + Number(direction || 0);
  if (nextIndex < 0 || nextIndex >= tabs.length) {
    return;
  }

  switchModalTab(tabs[nextIndex]);
}

function renderFeedbackBanners({ portalSettings, registryStatus, accountStatus, lifeCertificate, lifecycle, claims }) {
  const feedback = document.getElementById('pensionerBoardFeedback');
  if (!feedback) return;

  const messages = [];
  if (registryStatus && registryStatus.hasRegistryRecord === false) {
    messages.push({
      tone: registryStatus.tone || 'info',
      title: 'Registry record pending',
      body: registryStatus.message || 'No pensioner data is currently available in the pension file registry for this account.'
    });
  } else if ((accountStatus.status || '').toLowerCase() === 'suspended') {
    messages.push({ tone: 'warning', title: 'Account requires attention', body: accountStatus.reason || 'Your account is currently suspended. Please contact the pensions office for guidance.' });
    if ((lifeCertificate.status || '').toLowerCase() === 'not submitted') {
      messages.push({
        tone: lifeCertificate.tone || 'warning',
        title: lifeCertificate.previousYearSubmitted === false
          ? `Life certificate for ${lifeCertificate.year || 'current year'} is now urgent`
          : `Life certificate for ${lifeCertificate.year || 'current year'} is pending`,
        body: lifeCertificate.advice || 'Please submit your life certificate for the current year as required by the pensions office.'
      });
    }
    if ((lifecycle.payType || '').toLowerCase() === 'one-off payment') {
      messages.push({ tone: 'info', title: 'One-off payment record', body: 'This account is held as a one-off payment file. Monthly pension is not expected after settlement.' });
    }
    if ((claims.totalOutstanding || 0) > 0 && portalSettings.showClaims !== false) {
      messages.push({ tone: 'info', title: 'Outstanding claims on record', body: `Recorded outstanding arrears currently stand at ${formatCurrency(claims.totalOutstanding)}.` });
    }
  }

  if (!messages.length) {
    feedback.hidden = true;
    feedback.innerHTML = '';
    return;
  }

  feedback.hidden = false;
  feedback.innerHTML = messages.map((message) => `
    <article class="pensioner-feedback-card ${escapeHtml(message.tone)}">
      <strong>${escapeHtml(message.title)}</strong>
      <p>${escapeHtml(message.body)}</p>
    </article>
  `).join('');
}

function renderClaimSummary(items, claims, registryStatus = {}) {
  const summary = document.getElementById('pensionerClaimsSummary');
  const list = document.getElementById('pensionerClaimsList');
  if (!summary || !list) return;

  const hasRegistryRecord = registryStatus.hasRegistryRecord !== false;
  summary.innerHTML = `
    <div class="pensioner-claim-stat">
      <span>Total Outstanding</span>
      <strong>${hasRegistryRecord ? formatCurrency(claims.totalOutstanding || 0) : '--'}</strong>
    </div>
    <div class="pensioner-claim-stat">
      <span>Open Claim Types</span>
      <strong>${escapeHtml(hasRegistryRecord ? String(claims.openEntries || 0) : '--')}</strong>
    </div>
  `;

  if (!hasRegistryRecord) {
    list.innerHTML = '<div class="pensioner-empty-state">Claims and arrears will appear after your pensioner file is linked in the pension file registry.</div>';
    return;
  }

  if (!Array.isArray(items) || !items.length) {
    list.innerHTML = '<div class="pensioner-empty-state">No arrears or claim entries are currently recorded against your file.</div>';
    return;
  }

  list.innerHTML = items.map((item) => `
    <article class="pensioner-claim-card">
      <div class="pensioner-claim-head">
        <strong>${escapeHtml(item.claimType || 'Claim')}</strong>
        <span>${escapeHtml(String(item.entries || 0))} entr${Number(item.entries) === 1 ? 'y' : 'ies'}</span>
      </div>
      <div class="pensioner-claim-grid">
        <div><span>Expected</span><strong>${formatCurrency(item.expectedTotal)}</strong></div>
        <div><span>Paid</span><strong>${formatCurrency(item.paidTotal)}</strong></div>
        <div><span>Balance</span><strong>${formatCurrency(item.balanceTotal)}</strong></div>
        <div><span>Last Updated</span><strong>${formatDateTime(item.lastRecordedAt)}</strong></div>
      </div>
    </article>
  `).join('');
}

function renderApplicationSteps(steps) {
  const container = document.getElementById('pensionerStepList');
  if (!container) return;
  if (!Array.isArray(steps) || !steps.length) {
    container.innerHTML = '<div class="pensioner-empty-state">Application workflow updates will appear here when processing begins.</div>';
    return;
  }

  container.innerHTML = steps.map((step) => {
    const state = normalizeStatus(step.status);
    const comment = step.comment || defaultStatusNarrative(step.label, step.status);
    return `
      <article class="pensioner-step-card ${escapeHtml(state)}">
        <div class="pensioner-step-head">
          <strong>${escapeHtml(step.label || 'Workflow Step')}</strong>
          <span class="pensioner-status-pill ${escapeHtml(state)}">${escapeHtml(step.status || 'Pending')}</span>
        </div>
        <p>${escapeHtml(comment)}</p>
        <small>${escapeHtml(formatDateTime(step.time))}</small>
      </article>
    `;
  }).join('');
}

function renderDetailGrid(targetId, rows) {
  const container = document.getElementById(targetId);
  if (!container) return;
  container.innerHTML = rows.map((row) => {
    const item = Array.isArray(row)
      ? { label: row[0], value: row[1] }
      : (row || {});
    const classes = ['pensioner-detail-item'];
    if (item.fullWidth) classes.push('full-width');
    if (item.advisory) classes.push('advisory');
    const tone = normalizeTone(item.tone);
    if (tone !== 'neutral') classes.push(tone);
    return `
      <div class="${classes.join(' ')}">
        <span>${escapeHtml(item.label || '')}</span>
        <strong>${escapeHtml(item.value || '--')}</strong>
      </div>
    `;
  }).join('');
}

function renderDocumentsList(items, registryStatus = {}) {
  const list = document.getElementById('pensionerDocumentsList');
  if (!list) return;
  if (registryStatus.hasRegistryRecord === false) {
    list.innerHTML = '<div class="pensioner-empty-state">Indexed documents will appear after your pensioner file is linked in the pension file registry.</div>';
    return;
  }
  if (!Array.isArray(items) || !items.length) {
    list.innerHTML = '<div class="pensioner-empty-state">No indexed documents are currently available for your record.</div>';
    return;
  }

  list.innerHTML = items.map((item) => {
    const documentId = Number(item.documentId || 0);
    const sourceUrl = documentId > 0 ? `../backend/api/view_staff_document.php?document_id=${encodeURIComponent(documentId)}` : '';
    const viewerUrl = sourceUrl && window.PensionsGoDocumentViewer?.buildViewerUrl
      ? window.PensionsGoDocumentViewer.buildViewerUrl(sourceUrl, {
          label: `${item.docType || 'Document'} - ${item.fileName || 'Indexed Document'}`,
          returnState: { page: 'pensioner_board', tab: 'lifecycle' }
        })
      : sourceUrl;
    const downloadUrl = sourceUrl && window.PensionsGoDocumentViewer?.buildDownloadUrl
      ? window.PensionsGoDocumentViewer.buildDownloadUrl(sourceUrl)
      : (sourceUrl ? `${sourceUrl}${sourceUrl.includes('?') ? '&' : '?'}download=1` : '');

    return `
      <article class="pensioner-document-card">
        <div class="pensioner-document-card-head">
          <strong>${escapeHtml(item.fileName || 'Indexed Document')}</strong>
          <span class="pensioner-chip">${escapeHtml(item.docType || 'Document')}</span>
        </div>
        <div class="pensioner-document-meta">
          <span>Added ${escapeHtml(formatDateTime(item.uploadedAt))}</span>
        </div>
        <div class="pensioner-document-actions">
          <a class="pensioner-action-btn secondary slim" href="${escapeHtml(viewerUrl || '#')}">View</a>
          <a class="pensioner-action-btn secondary slim" href="${escapeHtml(downloadUrl || '#')}">Download</a>
        </div>
      </article>
    `;
  }).join('');
}

function renderMetricGrid(targetId, rows) {
  const container = document.getElementById(targetId);
  if (!container) return;
  container.innerHTML = rows.map(([label, value]) => `
    <article class="pensioner-metric-card">
      <span>${escapeHtml(label)}</span>
      <strong>${escapeHtml(value || '--')}</strong>
    </article>
  `).join('');
}

function buildHeroMeta(payroll, accountStatus, lifeCertificate, registryStatus = {}) {
  if (registryStatus.hasRegistryRecord === false) {
    return escapeHtml(registryStatus.shortMessage || registryStatus.message || 'No pensioner data is currently available in the pension file registry for this account.');
  }
  const parts = [];
  if (payroll.status) parts.push(`Payroll: <strong>${escapeHtml(payroll.status)}</strong>`);
  if (accountStatus.status) parts.push(`Account: <strong>${escapeHtml(accountStatus.status)}</strong>`);
  if (lifeCertificate.status) parts.push(`Life Certificate: <strong>${escapeHtml(lifeCertificate.status)}</strong>`);
  return parts.join(' | ') || 'Your pension record, payroll standing, and compliance status are shown below.';
}

function buildPayrollNote(payroll, registryStatus = {}) {
  if (registryStatus.hasRegistryRecord === false) {
    return registryStatus.message || 'No pensioner data is currently available in the pension file registry for this account.';
  }
  if (!payroll.status) return 'Please wait while payroll data loads.';
  const fragments = [payroll.status];
  if (payroll.periodLabel) fragments.push(payroll.periodLabel);
  if (payroll.financialYear) fragments.push(payroll.financialYear);
  return fragments.join(' | ');
}

function defaultStatusNarrative(label, status) {
  const normalized = normalizeStatus(status);
  if (normalized === 'success') return `${label} has been completed successfully.`;
  if (normalized === 'warning') return `${label} has been raised for attention. Please follow up with the pensions office.`;
  if (normalized === 'danger') return `${label} was rejected. Please contact the pensions office for clarification.`;
  return `${label} is still pending.`;
}

function normalizeStatus(status) {
  const normalized = String(status || '').trim().toLowerCase();
  if (['done', 'completed', 'approved', 'success', 'submitted', 'verified'].includes(normalized)) return 'success';
  if (['queried', 'warning', 'review', 'in process', 'in_progress', 'processing'].includes(normalized)) return 'warning';
  if (['rejected', 'failed', 'declined', 'suspended'].includes(normalized)) return 'danger';
  return 'neutral';
}

function applySummaryState(targetId, value) {
  const element = document.getElementById(targetId);
  if (!element) return;
  element.classList.remove('success', 'warning', 'danger', 'neutral', 'info');
  element.classList.add(normalizeTone(value));
}

function applySummaryNoteState(targetId, tone, emphasized = false) {
  const element = document.getElementById(targetId);
  if (!element) return;
  element.classList.remove('success', 'warning', 'danger', 'neutral', 'info', 'emphasis');
  if (emphasized) {
    element.classList.add('emphasis');
  }
  const normalized = normalizeTone(tone);
  if (normalized !== 'neutral') {
    element.classList.add(normalized);
  }
}

function normalizeTone(value) {
  const normalized = String(value || '').trim().toLowerCase();
  if (['info', 'informational'].includes(normalized)) return 'info';
  if (['success', 'submitted', 'complete', 'completed', 'approved', 'active'].includes(normalized)) return 'success';
  if (['warning', 'pending', 'not submitted', 'attention', 'review'].includes(normalized)) return 'warning';
  if (['danger', 'error', 'urgent', 'late', 'suspended', 'rejected'].includes(normalized)) return 'danger';
  return normalizeStatus(value);
}

function setText(targetId, value) {
  const element = document.getElementById(targetId);
  if (!element) return;
  element.textContent = value || '--';
}

function setHtml(targetId, value) {
  const element = document.getElementById(targetId);
  if (!element) return;
  element.innerHTML = value || '--';
}

function setImage(targetId, photoValue) {
  const element = document.getElementById(targetId);
  if (!element) return;
  const filename = String(photoValue || '').split(/[\/]/).pop();
  element.src = filename ? `../backend/api/get_image.php?file=${encodeURIComponent(filename)}&type=profile` : '../backend/api/get_image.php?file=default-user.png&type=profile';
  element.onerror = () => {
    element.onerror = null;
    element.src = '../backend/api/get_image.php?file=default-user.png&type=profile';
  };
}

function formatCurrency(value) {
  const amount = Number(value || 0);
  return `UGX ${amount.toLocaleString('en-UG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function formatDate(value) {
  if (!value) return '--';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return String(value);
  return date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

function formatDateTime(value) {
  if (!value) return '--';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return String(value);
  return date.toLocaleString('en-GB', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  });
}

function formatMonths(value) {
  const months = Number(value || 0);
  if (!months) return '0 Months';
  const years = Math.floor(months / 12);
  const remainder = months % 12;
  if (!years) return `${remainder} Month${remainder === 1 ? '' : 's'}`;
  if (!remainder) return `${years} Year${years === 1 ? '' : 's'}`;
  return `${years} Year${years === 1 ? '' : 's'}, ${remainder} Month${remainder === 1 ? '' : 's'}`;
}

function showFeedback(message, tone = 'error') {
  const feedback = document.getElementById('pensionerBoardFeedback');
  if (!feedback) return;
  feedback.hidden = false;
  feedback.innerHTML = `
    <article class="pensioner-feedback-card ${escapeHtml(tone)}">
      <strong>${tone === 'error' ? 'Unable to load dashboard' : 'Notice'}</strong>
      <p>${escapeHtml(message)}</p>
    </article>
  `;
}

function updateProfileEditAccess(profile) {
  const trigger = document.getElementById('openPensionerProfileEditBtn');
  const modal = document.getElementById('pensionerProfileEditModal');
  const canEdit = Boolean(profile?.canEditContact && profile?.regNo);
  if (trigger) {
    trigger.hidden = !canEdit;
    trigger.disabled = !canEdit;
  }
  if (modal && !canEdit) {
    modal.hidden = true;
  }
}

function normalizeLifecycleRestrictionValue(value) {
  if (window.PensionsGoRetirementTypes?.normalizeValue) {
    return String(window.PensionsGoRetirementTypes.normalizeValue(value) || '')
      .trim()
      .toLowerCase();
  }
  return String(value || '')
    .trim()
    .toLowerCase()
    .replace(/[_-]+/g, ' ')
    .replace(/\s+/g, ' ');
}

function shouldRestrictBereavementContactFields(data = pensionerBoardState.dashboardData) {
  const lifecycle = data?.lifecycle || {};
  const retirementType = normalizeLifecycleRestrictionValue(lifecycle.retirementType);
  const livingStatus = normalizeLifecycleRestrictionValue(lifecycle.livingStatus);
  return retirementType === 'death' || livingStatus === 'deceased';
}

function applyProfileEditFieldVisibility(data = pensionerBoardState.dashboardData) {
  const restricted = shouldRestrictBereavementContactFields(data);
  const fieldIds = [
    'pensionerEditStationField',
    'pensionerEditNextOfKinField',
    'pensionerEditNextOfKinContactField'
  ];
  const inputIds = [
    'pensionerEditStation',
    'pensionerEditNextOfKin',
    'pensionerEditNextOfKinContact'
  ];

  fieldIds.forEach((fieldId) => {
    const field = document.getElementById(fieldId);
    if (field) {
      field.hidden = restricted;
      field.style.display = restricted ? 'none' : '';
      field.setAttribute('aria-hidden', restricted ? 'true' : 'false');
    }
  });

  inputIds.forEach((inputId) => {
    const input = document.getElementById(inputId);
    if (input) {
      input.disabled = restricted;
    }
  });

  const subtitle = document.getElementById('pensionerProfileEditSubtitle');
  if (subtitle) {
    subtitle.textContent = restricted
      ? 'Use this form to update your district of residence, phone number, and email address.'
      : 'Update the contact and retirement-location details that the pensions office keeps against your registry record.';
  }
}

function populateProfileEditForm(profile) {
  const regNoInput = document.getElementById('pensionerEditRegNo');
  const districtInput = document.getElementById('pensionerEditDistrict');
  const phoneInput = document.getElementById('pensionerEditPhone');
  const emailInput = document.getElementById('pensionerEditEmail');
  const stationInput = document.getElementById('pensionerEditStation');
  const nextOfKinInput = document.getElementById('pensionerEditNextOfKin');
  const nextOfKinContactInput = document.getElementById('pensionerEditNextOfKinContact');
  if (regNoInput) regNoInput.value = profile?.regNo || '';
  if (districtInput) districtInput.value = profile?.address || '';
  if (phoneInput) phoneInput.value = profile?.phone || '';
  if (emailInput) emailInput.value = profile?.email || '';
  if (stationInput) stationInput.value = profile?.station || '';
  if (nextOfKinInput) nextOfKinInput.value = profile?.nextOfKin || '';
  if (nextOfKinContactInput) nextOfKinContactInput.value = profile?.nextOfKinContact || '';
  ensureDistrictSelectorReady(districtInput);
  if (!shouldRestrictBereavementContactFields()) {
    ensureStationOptionsReady(stationInput);
  }
}

async function ensureDistrictSelectorReady(source) {
  if (!source || !window.PensionsGoDistrictSelector?.enhanceElement) {
    return;
  }
  await window.PensionsGoDistrictSelector.enhanceElement(source, {
    placeholder: 'Type to search district'
  });
  window.PensionsGoDistrictSelector.setValue(source, source.value || '');
}

function toggleProfileEditForm(shouldOpen, options = {}) {
  const modal = document.getElementById('pensionerProfileEditModal');
  if (!modal) return;
  pensionerBoardState.profileEditOpen = Boolean(shouldOpen);
  modal.hidden = !shouldOpen;
  if (shouldOpen) {
    const districtInput = document.getElementById('pensionerEditDistrict');
    const stationInput = document.getElementById('pensionerEditStation');
    ensureDistrictSelectorReady(districtInput);
    if (!shouldRestrictBereavementContactFields()) {
      ensureStationOptionsReady(stationInput);
    }
  } else if (!options.silent) {
    populateProfileEditForm(pensionerBoardState.dashboardData?.profile || {});
  }
}

async function ensureStationOptionsReady(input) {
  if (!input) {
    return;
  }
  try {
    if (!pensionerBoardState.stationOptionsLoaded) {
      const response = await fetch('../backend/api/fetch_priunits.php', {
        credentials: 'include',
        cache: 'no-store',
        headers: { 'Accept': 'application/json' }
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || !Array.isArray(data.units)) {
        throw new Error(data.message || 'Unable to load retirement locations.');
      }
      pensionerBoardState.stationOptions = data.units
        .map((unit) => String(unit || '').trim())
        .filter(Boolean);
      pensionerBoardState.stationOptionsLoaded = true;
    }

    if (window.PensionsGoDistrictSelector?.enhanceElement) {
      await window.PensionsGoDistrictSelector.enhanceElement(input, {
        placeholder: 'Type to search station or retirement location',
        toggleLabel: 'Show station options',
        noResultsText: 'No matching stations found.',
        items: pensionerBoardState.stationOptions
      });
      window.PensionsGoDistrictSelector.setValue(input, input.value || '');
      return;
    }
  } catch (error) {
    console.error('Unable to load station options:', error);
  }
}

async function submitPensionerProfileEdit(event) {
  event.preventDefault();
  const saveBtn = document.getElementById('savePensionerProfileEditBtn');
  const payload = {
    regNo: String(document.getElementById('pensionerEditRegNo')?.value || '').trim(),
    address: String(document.getElementById('pensionerEditDistrict')?.value || '').trim(),
    telNo: String(document.getElementById('pensionerEditPhone')?.value || '').trim(),
    applicant_email: String(document.getElementById('pensionerEditEmail')?.value || '').trim()
  };
  const restrictedBereavementFields = shouldRestrictBereavementContactFields();
  if (!restrictedBereavementFields) {
    payload.station = String(document.getElementById('pensionerEditStation')?.value || '').trim();
    payload.next_of_kin = String(document.getElementById('pensionerEditNextOfKin')?.value || '').trim();
    payload.next_of_kin_contact = String(document.getElementById('pensionerEditNextOfKinContact')?.value || '').trim();
  }

  if (!payload.regNo) {
    showFeedback('Your registry record could not be identified for update.', 'error');
    return;
  }

  if (saveBtn) saveBtn.disabled = true;
  try {
    const response = await fetch('../backend/api/update_registry_contact_profile.php', {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify(payload)
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok || !result?.success) {
      throw new Error(result?.message || 'Unable to update the selected profile details.');
    }

    toggleProfileEditForm(false, { silent: true });
    await loadDashboard();
    openDetailsModal('profile');
    if (typeof window.appToast === 'function') {
      window.appToast(result.message || 'Selected profile details updated.', { type: 'success', title: 'Pensioner Profile' });
    }
  } catch (error) {
    showFeedback(error.message || 'Unable to update the selected profile details.', 'error');
  } finally {
    if (saveBtn) saveBtn.disabled = false;
  }
}

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}
