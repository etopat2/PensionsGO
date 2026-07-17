(function () {
  const state = {
    permissions: {
      canManage: false,
      canUploadSuspension: false
    },
    claimLookupMap: new Map(),
    segments: []
  };

  const elements = {};

  document.addEventListener('DOMContentLoaded', initClaimForm);

  async function initClaimForm() {
    bindElements();
    if (!elements.arrearsEntryForm) return;

    const hasSession = await ensureActiveSession();
    if (!hasSession) return;

    bindEvents();
    setDefaultValues();
    renderSegments();
    await loadPermissions();
  }

  function bindElements() {
    elements.feedback = document.getElementById('claimFormFeedback');

    elements.arrearsEntryForm = document.getElementById('arrearsEntryForm');
    elements.claimBeneficiaryInput = document.getElementById('claimBeneficiaryInput');
    elements.claimBeneficiaryList = document.getElementById('claimBeneficiaryList');
    elements.claimRegNoInput = document.getElementById('claimRegNoInput');
    elements.claimTypeInput = document.getElementById('claimTypeInput');
    elements.claimExpectedAmountInput = document.getElementById('claimExpectedAmountInput');
    elements.claimPeriodYearInput = document.getElementById('claimPeriodYearInput');
    elements.claimPeriodMonthInput = document.getElementById('claimPeriodMonthInput');
    elements.claimReasonInput = document.getElementById('claimReasonInput');
    elements.claimSourceTypeInput = document.getElementById('claimSourceTypeInput');
    elements.claimStatusInput = document.getElementById('claimStatusInput');
    elements.claimNotesInput = document.getElementById('claimNotesInput');
    elements.saveClaimEntryBtn = document.getElementById('saveClaimEntryBtn');

    elements.claimSegmentsBody = document.getElementById('claimSegmentsBody');
    elements.addClaimSegmentBtn = document.getElementById('addClaimSegmentBtn');

    elements.openClaimsUploadModalBtn = document.getElementById('openClaimsUploadModalBtn');
    elements.claimsUploadModal = document.getElementById('claimsUploadModal');
    elements.closeClaimsUploadModalBtn = document.getElementById('closeClaimsUploadModalBtn');
    elements.downloadClaimsTemplateBtn = document.getElementById('downloadClaimsTemplateBtn');
    elements.uploadClaimsBtnTop = document.getElementById('uploadClaimsBtnTop');

    elements.claimsUploadForm = document.getElementById('claimsUploadForm');
    elements.claimsUploadFileInput = document.getElementById('claimsUploadFileInput');
    elements.claimsUploadTypeInput = document.getElementById('claimsUploadTypeInput');
    elements.claimsUploadStatusInput = document.getElementById('claimsUploadStatusInput');
    elements.claimsUploadSourceTypeInput = document.getElementById('claimsUploadSourceTypeInput');
    elements.claimsUploadReasonInput = document.getElementById('claimsUploadReasonInput');
    elements.claimsUploadNotesInput = document.getElementById('claimsUploadNotesInput');
    elements.uploadClaimsBtn = document.getElementById('uploadClaimsBtn');
  }

  function bindEvents() {
    if (elements.claimBeneficiaryInput) {
      elements.claimBeneficiaryInput.addEventListener('input', debounce(async () => {
        await populateBeneficiarySuggestions(
          elements.claimBeneficiaryInput,
          elements.claimBeneficiaryList,
          state.claimLookupMap
        );
        syncSelectedRegNo(elements.claimBeneficiaryInput, state.claimLookupMap, elements.claimRegNoInput);
      }, 260));
      elements.claimBeneficiaryInput.addEventListener('change', () => {
        syncSelectedRegNo(elements.claimBeneficiaryInput, state.claimLookupMap, elements.claimRegNoInput);
      });
      elements.claimBeneficiaryInput.addEventListener('blur', () => {
        syncSelectedRegNo(elements.claimBeneficiaryInput, state.claimLookupMap, elements.claimRegNoInput);
      });
    }

    if (elements.saveClaimEntryBtn) {
      elements.saveClaimEntryBtn.addEventListener('click', submitArrearsEntry);
    }

    if (elements.addClaimSegmentBtn) {
      elements.addClaimSegmentBtn.addEventListener('click', () => {
        state.segments.push({
          start: '',
          end: '',
          monthlyAmount: '',
          reason: '',
          notes: ''
        });
        renderSegments();
      });
    }

    if (elements.openClaimsUploadModalBtn) {
      elements.openClaimsUploadModalBtn.addEventListener('click', openClaimsUploadModal);
    }

    if (elements.closeClaimsUploadModalBtn) {
      elements.closeClaimsUploadModalBtn.addEventListener('click', closeClaimsUploadModal);
    }

    if (elements.claimsUploadModal) {
      elements.claimsUploadModal.addEventListener('click', (event) => {
        if (event.target === elements.claimsUploadModal) {
          closeClaimsUploadModal();
        }
      });
    }

    if (elements.downloadClaimsTemplateBtn) {
      elements.downloadClaimsTemplateBtn.addEventListener('click', downloadClaimsTemplate);
    }

    if (elements.uploadClaimsBtnTop) {
      elements.uploadClaimsBtnTop.addEventListener('click', submitClaimsUpload);
    }

    if (elements.uploadClaimsBtn) {
      elements.uploadClaimsBtn.addEventListener('click', submitClaimsUpload);
    }

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && elements.claimsUploadModal?.classList.contains('open')) {
        closeClaimsUploadModal();
      }
    });
  }

  function parseMoneyInputValue(value, fallback = 0) {
    if (window.PensionsGoMoney?.parse) {
      return window.PensionsGoMoney.parse(value, fallback);
    }
    const parsed = Number.parseFloat(String(value || '').replace(/,/g, ''));
    return Number.isFinite(parsed) ? parsed : fallback;
  }

  async function loadPermissions() {
    try {
      const response = await fetch('../backend/api/get_claims_dashboard.php?page=1&limit=1', {
        credentials: 'include',
        cache: 'no-store'
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        const debugMessage = data && typeof data.debug === "string" ? data.debug : "";
        throw new Error(debugMessage || data.message || `HTTP ${response.status}`);
      }

      state.permissions = data.permissions || state.permissions;
      applyPermissionState();
    } catch (error) {
      console.error('Claim form permission load failed:', error);
      showFeedback('Unable to validate permissions for this module.', 'error');
      showModalMessage('Unable to validate permissions for this module.', 'error');
      applyPermissionState();
    }
  }

  function applyPermissionState() {
    const canManage = Boolean(state.permissions.canManage);

    if (elements.saveClaimEntryBtn) elements.saveClaimEntryBtn.disabled = !canManage;
    if (elements.uploadClaimsBtn) elements.uploadClaimsBtn.disabled = !canManage;
    if (elements.uploadClaimsBtnTop) elements.uploadClaimsBtnTop.disabled = !canManage;
    if (elements.addClaimSegmentBtn) elements.addClaimSegmentBtn.disabled = !canManage;
    if (elements.openClaimsUploadModalBtn) elements.openClaimsUploadModalBtn.disabled = !canManage;
    if (elements.downloadClaimsTemplateBtn) elements.downloadClaimsTemplateBtn.disabled = !canManage;
  }

  function renderSegments() {
    if (!elements.claimSegmentsBody) return;
    if (!state.segments.length) {
      elements.claimSegmentsBody.innerHTML = '<tr><td colspan="6">No segments added. Leave empty to save single-month entry.</td></tr>';
      return;
    }

    elements.claimSegmentsBody.innerHTML = '';
    state.segments.forEach((segment, index) => {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td><input type="month" data-field="start" data-index="${index}" value="${escapeAttr(segment.start)}" /></td>
        <td><input type="month" data-field="end" data-index="${index}" value="${escapeAttr(segment.end)}" /></td>
        <td><input type="number" min="0" step="0.01" data-field="monthlyAmount" data-index="${index}" value="${escapeAttr(segment.monthlyAmount)}" data-money-input data-money-fixed-decimals="2" /></td>
        <td><input type="text" maxlength="255" data-field="reason" data-index="${index}" value="${escapeAttr(segment.reason)}" /></td>
        <td><input type="text" maxlength="255" data-field="notes" data-index="${index}" value="${escapeAttr(segment.notes)}" /></td>
        <td><button class="claims-btn" type="button" data-remove="${index}">Remove</button></td>
      `;
      elements.claimSegmentsBody.appendChild(tr);
    });

    elements.claimSegmentsBody.querySelectorAll('input[data-index]').forEach((input) => {
      input.addEventListener('input', (event) => {
        const idx = Number(event.target.getAttribute('data-index') || -1);
        const field = String(event.target.getAttribute('data-field') || '').trim();
        if (idx < 0 || !field || !state.segments[idx]) return;
        state.segments[idx][field] = String(event.target.value || '').trim();
      });
    });

    elements.claimSegmentsBody.querySelectorAll('button[data-remove]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const idx = Number(btn.getAttribute('data-remove') || -1);
        if (idx < 0 || idx >= state.segments.length) return;
        state.segments.splice(idx, 1);
        renderSegments();
      });
    });

    window.PensionsGoMoney?.scanInputs?.(elements.claimSegmentsBody);
  }

  function getValidSegments() {
    return state.segments
      .map((segment) => ({
        start: String(segment.start || '').trim(),
        end: String(segment.end || '').trim(),
        monthlyAmount: parseMoneyInputValue(segment.monthlyAmount, 0),
        reason: String(segment.reason || '').trim(),
        notes: String(segment.notes || '').trim()
      }))
      .filter((segment) => segment.start !== '' && segment.end !== '' && segment.monthlyAmount > 0);
  }

  async function submitArrearsEntry() {
    const regNo = String(elements.claimRegNoInput?.value || '').trim();
    const claimType = String(elements.claimTypeInput?.value || '').trim();
    const expectedAmount = parseMoneyInputValue(elements.claimExpectedAmountInput?.value, 0);
    const periodYear = Number(elements.claimPeriodYearInput?.value || 0);
    const periodMonth = Number(elements.claimPeriodMonthInput?.value || 0);
    const reason = String(elements.claimReasonInput?.value || '').trim();
    const sourceType = String(elements.claimSourceTypeInput?.value || 'missed_payment').trim();
    const claimStatus = String(elements.claimStatusInput?.value || '').trim();
    const notes = String(elements.claimNotesInput?.value || '').trim();

    if (!regNo) {
      showFeedback('Select a beneficiary with a valid file number.', 'error');
      await showModalMessage('Select a beneficiary with a valid file number.', 'error');
      return;
    }

    const segments = getValidSegments();
    if (!segments.length) {
      if (!claimType || expectedAmount < 0 || periodYear < 2000 || periodMonth < 1 || periodMonth > 12) {
        showFeedback('Provide valid claim details.', 'error');
        await showModalMessage('Provide valid claim details.', 'error');
        return;
      }
    }

    try {
      const payload = segments.length
        ? {
            action: 'create_segmented_entry',
            regNo,
            claimType,
            sourceType,
            claimStatus,
            reason,
            notes,
            segments
          }
        : {
            action: 'create_entry',
            regNo,
            claimType,
            expectedAmount,
            periodYear,
            periodMonth,
            reason,
            sourceType,
            claimStatus,
            notes
          };

      const response = await fetch('../backend/api/post_arrears_tracking.php', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error(data.message || `HTTP ${response.status}`);
      }

      const successMessage = data.message || 'Claim entry saved successfully.';
      showFeedback(successMessage, 'success');
      await showModalMessage(successMessage, 'success');
      if (elements.claimExpectedAmountInput) elements.claimExpectedAmountInput.value = '';
      if (elements.claimReasonInput) elements.claimReasonInput.value = '';
      if (elements.claimNotesInput) elements.claimNotesInput.value = '';
      state.segments = [];
      renderSegments();
    } catch (error) {
      showFeedback(error.message || 'Failed to save claim entry.', 'error');
      await showModalMessage(error.message || 'Failed to save claim entry.', 'error');
    }
  }

  async function submitClaimsUpload() {
    const file = elements.claimsUploadFileInput?.files?.[0] || null;
    if (!file) {
      showFeedback('Select a claims file to upload.', 'error');
      await showModalMessage('Select a claims file to upload.', 'error');
      return;
    }

    try {
      const formData = new FormData();
      formData.append('claims_file', file);
      formData.append('claim_type', String(elements.claimsUploadTypeInput?.value || 'Pension Arrears'));
      formData.append('claim_status', String(elements.claimsUploadStatusInput?.value || 'Incomplete'));
      formData.append('source_type', String(elements.claimsUploadSourceTypeInput?.value || 'uploaded_claims'));
      formData.append('reason', String(elements.claimsUploadReasonInput?.value || '').trim());
      formData.append('notes', String(elements.claimsUploadNotesInput?.value || '').trim());

      const response = await fetch('../backend/api/upload_claim_entries.php', {
        method: 'POST',
        credentials: 'include',
        body: formData
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error(data.message || `HTTP ${response.status}`);
      }

      const savedRows = Number(data.savedRows || 0);
      const skippedRows = Number(data.skippedRows || 0);
      const reviewDownloadStarted = downloadImportReviewExport(data.review_export, 'claims_upload_review.csv');
      const summary = [
        data.message || 'Claims upload completed.',
        `Saved rows: ${savedRows}.`,
        `Skipped rows: ${skippedRows}.`,
        reviewDownloadStarted ? 'A review file download has started for the rows that need correction.' : ''
      ].join('\n');
      const feedbackType = skippedRows > 0 ? 'warning' : 'success';

      showFeedback(
        skippedRows > 0
          ? `Claims upload completed with review items. Saved ${savedRows}, skipped ${skippedRows}.`
          : `Claims upload completed. Saved ${savedRows} row(s).`,
        feedbackType
      );
      await showModalMessage(summary, skippedRows > 0 ? 'warning' : 'info');
      if (elements.claimsUploadForm) elements.claimsUploadForm.reset();
      closeClaimsUploadModal();
    } catch (error) {
      showFeedback(error.message || 'Failed to upload claims file.', 'error');
      await showModalMessage(error.message || 'Failed to upload claims file.', 'error');
    }
  }

  function openClaimsUploadModal() {
    if (!elements.claimsUploadModal) return;
    elements.claimsUploadModal.classList.add('open');
    document.body.classList.add('modal-open');
  }

  function closeClaimsUploadModal() {
    if (!elements.claimsUploadModal) return;
    elements.claimsUploadModal.classList.remove('open');
    if (!document.querySelector('.claims-modal-overlay.open')) {
      document.body.classList.remove('modal-open');
    }
  }

  async function downloadClaimsTemplate() {
    if (!state.permissions.canManage) {
      showFeedback('You do not have permission to download the claims template.', 'error');
      await showModalMessage('You do not have permission to download the claims template.', 'error');
      return;
    }

    try {
      const response = await fetch('../backend/api/download_claims_upload_template.php', {
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
      const header = String(response.headers.get('Content-Disposition') || '');
      const utf8Match = header.match(/filename\*=UTF-8''([^;]+)/i);
      const plainMatch = header.match(/filename="?([^";]+)"?/i);
      const fileName = utf8Match?.[1]
        ? decodeURIComponent(utf8Match[1])
        : (plainMatch?.[1] || 'claims_upload_template.xlsx');

      const objectUrl = window.URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = objectUrl;
      link.download = fileName;
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.URL.revokeObjectURL(objectUrl);

      showFeedback('Claims upload template downloaded.', 'success');
    } catch (error) {
      showFeedback(error.message || 'Failed to download the claims template.', 'error');
      await showModalMessage(error.message || 'Failed to download the claims template.', 'error');
    }
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
      const objectUrl = window.URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = objectUrl;
      link.download = reviewExport.file_name || fallbackName;
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.URL.revokeObjectURL(objectUrl);
      return true;
    } catch (error) {
      console.error('Unable to download import review export:', error);
      return false;
    }
  }

  async function populateBeneficiarySuggestions(inputEl, datalistEl, mapRef) {
    if (!inputEl || !datalistEl || !mapRef) return;
    const phrase = String(inputEl.value || '').trim();
    if (phrase.length < 2) {
      return;
    }

    try {
      const response = await fetch(`../backend/api/search_registry_files.php?q=${encodeURIComponent(phrase)}&limit=20`, {
        credentials: 'include',
        cache: 'no-store'
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error(data.message || `HTTP ${response.status}`);
      }

      const files = Array.isArray(data.files) ? data.files : [];
      mapRef.clear();
      datalistEl.innerHTML = '';
      files.forEach((file) => {
        const regNo = String(file.regNo || '').trim();
        const name = String(file.name || '').trim();
        if (!regNo) return;
        const label = `${regNo} - ${name}`;
        mapRef.set(label, regNo);
        mapRef.set(regNo, regNo);
        const option = document.createElement('option');
        option.value = label;
        datalistEl.appendChild(option);
      });
    } catch (error) {
      console.error('Beneficiary suggestion error:', error);
    }
  }

  function syncSelectedRegNo(inputEl, mapRef, hiddenFieldEl) {
    if (!inputEl || !hiddenFieldEl) return;
    const value = String(inputEl.value || '').trim();
    if (!value) {
      hiddenFieldEl.value = '';
      return;
    }

    if (mapRef.has(value)) {
      hiddenFieldEl.value = String(mapRef.get(value) || '');
      return;
    }

    const fileNo = value.split('-')[0].trim();
    hiddenFieldEl.value = fileNo;
  }

  function setDefaultValues() {
    const now = new Date();
    const year = now.getFullYear();
    const month = now.getMonth() + 1;

    if (elements.claimPeriodYearInput) elements.claimPeriodYearInput.value = String(year);
    if (elements.claimPeriodMonthInput) elements.claimPeriodMonthInput.value = String(month);
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
          <header class=\"claims-modal-header\"><h3>${escapeAttr(title)}</h3></header>
          <div class=\"claims-modal-body\"><p style=\"margin:0; font-size:0.9rem;\">${escapeAttr(text)}</p></div>
          <footer class=\"claims-modal-footer\">
            <button class=\"claims-btn claims-btn-primary\" type=\"button\" data-close-alert>OK</button>
          </footer>
        </div>
      `;
      const close = () => {
        overlay.remove();
        if (!document.querySelector('.claims-modal-overlay.open')) {
          document.body.classList.remove('modal-open');
        }
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

  function escapeAttr(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  function debounce(fn, waitMs) {
    let timer = null;
    return function debounced(...args) {
      window.clearTimeout(timer);
      timer = window.setTimeout(() => fn.apply(this, args), waitMs);
    };
  }
})();
