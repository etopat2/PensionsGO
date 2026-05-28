(function () {
  const state = {
    enabled: true,
    results: [],
    selectedRegNo: '',
    recentSearches: loadRecentSearches(),
    activeQuery: '',
    searchAbortController: null
  };

  const elements = {};

  document.addEventListener('DOMContentLoaded', initializePensionerLookup);

  async function initializePensionerLookup() {
    const role = (sessionStorage.getItem('userRole') || localStorage.getItem('userRole') || '').toLowerCase();
    if (role && role !== 'pensioner') {
      window.location.replace('dashboard.html');
      return;
    }

    bindElements();
    bindEvents();
    await loadContext();
    renderRecentSearches();
  }

  function bindElements() {
    elements.feedback = document.getElementById('lookupFeedback');
    elements.directoryStat = document.getElementById('lookupDirectoryStat');
    elements.searchInput = document.getElementById('pensionerLookupInput');
    elements.clearBtn = document.getElementById('clearPensionerLookupBtn');
    elements.searchStatus = document.getElementById('lookupSearchStatus');
    elements.recentSearches = document.getElementById('lookupRecentSearches');
    elements.resultList = document.getElementById('lookupResultList');
    elements.detailBody = document.getElementById('lookupDetailBody');
  }

  function bindEvents() {
    elements.searchInput?.addEventListener('input', debounce(() => {
      const query = String(elements.searchInput.value || '').trim();
      if (!query) {
        state.activeQuery = '';
        state.results = [];
        state.selectedRegNo = '';
        renderResults();
        renderDetail();
        setSearchStatus('Start typing to search the directory.');
        renderRecentSearches();
        return;
      }
      performSearch(query);
    }, 280));

    elements.clearBtn?.addEventListener('click', () => {
      if (elements.searchInput) elements.searchInput.value = '';
      state.activeQuery = '';
      state.results = [];
      state.selectedRegNo = '';
      renderResults();
      renderDetail();
      setSearchStatus('Start typing to search the directory.');
      renderRecentSearches();
    });
  }

  async function loadContext() {
    try {
      const response = await fetch('../backend/api/get_pensioner_lookup_context.php', {
        credentials: 'include',
        cache: 'no-store',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || !data.success) {
        throw new Error(data.message || 'Unable to load pensioner lookup settings.');
      }

      state.enabled = Boolean(data.enabled);
      renderContext(data);
      if (!state.enabled) {
        showFeedback('Pensioner lookup is currently disabled by the pensions office.', 'warning', 'Directory Disabled');
      }
    } catch (error) {
      showFeedback(error.message || 'Unable to load pensioner lookup settings.', 'error', 'Lookup Unavailable');
      if (elements.searchInput) elements.searchInput.disabled = true;
    }
  }

  function renderContext(data) {
    if (elements.directoryStat) {
      elements.directoryStat.textContent = `${Number(data.directoryCount || 0).toLocaleString()} visible records`;
    }

    if (elements.searchInput) {
      elements.searchInput.disabled = !data.enabled;
    }
    if (elements.clearBtn) {
      elements.clearBtn.disabled = !data.enabled;
    }
  }

  async function performSearch(query) {
    if (!state.enabled) {
      return;
    }
    const trimmedQuery = String(query || '').trim();
    state.activeQuery = trimmedQuery;

    if (trimmedQuery.length < 2) {
      state.results = [];
      state.selectedRegNo = '';
      renderResults();
      renderDetail();
      setSearchStatus('Enter at least two characters to search the directory.');
      return;
    }

    if (state.searchAbortController) {
      state.searchAbortController.abort();
    }

    const controller = new AbortController();
    state.searchAbortController = controller;
    setSearchStatus(`Searching for "${trimmedQuery}"...`);

    try {
      const url = `../backend/api/search_pensioners.php?q=${encodeURIComponent(trimmedQuery)}`;
      const response = await fetch(url, {
        credentials: 'include',
        cache: 'no-store',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        signal: controller.signal
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || !data.success) {
        throw new Error(data.message || 'Unable to search the pensioner directory.');
      }
      if (state.activeQuery !== trimmedQuery) {
        return;
      }

      state.results = Array.isArray(data.results) ? data.results : [];
      state.selectedRegNo = state.results[0]?.regNo || '';
      rememberRecentSearch(trimmedQuery);
      renderResults();
      renderDetail();
      setSearchStatus(data.message || `${state.results.length} result${state.results.length === 1 ? '' : 's'} found.`);
      renderRecentSearches();
    } catch (error) {
      if (error.name === 'AbortError') {
        return;
      }
      state.results = [];
      state.selectedRegNo = '';
      renderResults();
      renderDetail();
      setSearchStatus(error.message || 'Unable to search the pensioner directory.');
    }
  }

  function renderResults() {
    if (!elements.resultList) return;
    if (!state.activeQuery) {
      elements.resultList.innerHTML = '<div class="lookup-empty-state">Search by force number or by name to view matching pensioners.</div>';
      return;
    }
    if (!state.results.length) {
      elements.resultList.innerHTML = '<div class="lookup-empty-state">No pensioner matched the current search.</div>';
      return;
    }

    elements.resultList.innerHTML = state.results.map((row) => `
      <button type="button" class="lookup-result-card${row.regNo === state.selectedRegNo ? ' active' : ''}" data-reg-no="${escapeHtml(row.regNo)}">
        <div class="lookup-result-head">
          <div>
            <strong class="lookup-result-name">${escapeHtml(row.name || 'Unnamed Pensioner')}</strong>
            <div class="lookup-card-subtle">${escapeHtml(row.rankTitle || 'Pensioner')}</div>
          </div>
          <span class="lookup-result-pill">${escapeHtml(row.regNo || '--')}</span>
        </div>
        <div class="lookup-result-meta">
          <span>${escapeHtml(row.station || 'Station not recorded')}</span>
        </div>
      </button>
    `).join('');

    elements.resultList.querySelectorAll('[data-reg-no]').forEach((button) => {
      button.addEventListener('click', () => {
        state.selectedRegNo = String(button.dataset.regNo || '');
        renderResults();
        renderDetail();
      });
    });
  }

  function renderDetail() {
    if (!elements.detailBody) return;
    const record = state.results.find((row) => row.regNo === state.selectedRegNo);
    if (!record) {
      elements.detailBody.innerHTML = '<div class="lookup-empty-state">Select a pensioner from the directory results to review the available contact details.</div>';
      return;
    }

    const telHref = record.phoneNumber ? `tel:${record.phoneNumber.replace(/\s+/g, '')}` : '';
    const displayName = getLookupDisplayName(record);
    elements.detailBody.innerHTML = `
      <article class="lookup-detail-card">
        <div class="lookup-detail-head">
          <strong class="lookup-detail-name">${escapeHtml(displayName || 'Unnamed Pensioner')}</strong>
          <span class="lookup-card-subtle">${escapeHtml(record.rankTitle || 'Pensioner')}</span>
        </div>
        <div class="lookup-detail-grid">
          <div class="lookup-detail-item">
            <span>Force Number</span>
            <strong>${escapeHtml(record.regNo || '--')}</strong>
          </div>
          <div class="lookup-detail-item">
            <span>Station at Retirement</span>
            <strong>${escapeHtml(record.station || '--')}</strong>
          </div>
          <div class="lookup-detail-item">
            <span>Phone Number</span>
            <strong>${escapeHtml(record.phoneNumber || '--')}</strong>
          </div>
          <div class="lookup-detail-item">
            <span>Email Address</span>
            <strong>${escapeHtml(record.emailAddress || '--')}</strong>
          </div>
        </div>
        <div class="lookup-mobile-actions">
          ${record.phoneNumber ? `<a class="lookup-action-btn" href="${escapeHtml(telHref)}">Call</a>` : ''}
          ${record.phoneNumber ? `<button type="button" class="lookup-action-btn secondary" id="copyLookupPhoneBtn">Copy Number</button>` : ''}
        </div>
      </article>
    `;

    document.getElementById('copyLookupPhoneBtn')?.addEventListener('click', async () => {
      try {
        await navigator.clipboard.writeText(record.phoneNumber || '');
        if (typeof window.appToast === 'function') {
          window.appToast('Phone number copied.', { type: 'success', title: 'Pensioner Directory' });
        }
      } catch (_error) {
        showFeedback('Unable to copy the phone number on this device.', 'warning', 'Copy Unavailable');
      }
    });
  }

  function getLookupDisplayName(record) {
    const fullName = String(record?.name || '').trim();
    const rankTitle = String(record?.rankTitle || '').trim();
    if (!fullName || !rankTitle) {
      return fullName;
    }

    const lowerName = fullName.toLowerCase();
    const lowerTitle = rankTitle.toLowerCase();
    if (lowerName === lowerTitle) {
      return fullName;
    }

    if (lowerName.startsWith(`${lowerTitle} `)) {
      return fullName.slice(rankTitle.length).trim().replace(/^[,\-\s]+/, '');
    }

    if (lowerName.startsWith(`${lowerTitle},`)) {
      return fullName.slice(rankTitle.length + 1).trim();
    }

    return fullName;
  }

  function renderRecentSearches() {
    if (!elements.recentSearches) return;
    if (!state.recentSearches.length || state.activeQuery) {
      elements.recentSearches.hidden = true;
      elements.recentSearches.innerHTML = '';
      return;
    }
    elements.recentSearches.hidden = false;
    elements.recentSearches.innerHTML = state.recentSearches.map((item) => `
      <button type="button" class="lookup-recent-chip" data-recent-search="${escapeHtml(item)}">${escapeHtml(item)}</button>
    `).join('');
    elements.recentSearches.querySelectorAll('[data-recent-search]').forEach((button) => {
      button.addEventListener('click', () => {
        const term = String(button.dataset.recentSearch || '').trim();
        if (!term || !elements.searchInput) return;
        elements.searchInput.value = term;
        performSearch(term);
      });
    });
  }

  function setSearchStatus(message) {
    if (elements.searchStatus) {
      elements.searchStatus.textContent = message || '';
    }
  }

  function showFeedback(message, tone = 'info', title = 'Notice') {
    if (!elements.feedback) return;
    elements.feedback.hidden = false;
    elements.feedback.innerHTML = `
      <article class="lookup-feedback-card ${escapeHtml(tone)}">
        <strong>${escapeHtml(title)}</strong>
        <p>${escapeHtml(message)}</p>
      </article>
    `;
  }

  function rememberRecentSearch(query) {
    const normalized = String(query || '').trim();
    if (!normalized) return;
    state.recentSearches = [normalized]
      .concat(state.recentSearches.filter((item) => item.toLowerCase() !== normalized.toLowerCase()))
      .slice(0, 5);
    try {
      sessionStorage.setItem('pensioner_lookup_recent', JSON.stringify(state.recentSearches));
    } catch (_error) {
      // Ignore storage write failures.
    }
  }

  function loadRecentSearches() {
    try {
      const raw = sessionStorage.getItem('pensioner_lookup_recent');
      const parsed = raw ? JSON.parse(raw) : [];
      return Array.isArray(parsed) ? parsed.filter((item) => typeof item === 'string' && item.trim() !== '') : [];
    } catch (_error) {
      return [];
    }
  }

  function debounce(fn, wait = 250) {
    let timer = null;
    return (...args) => {
      window.clearTimeout(timer);
      timer = window.setTimeout(() => fn(...args), wait);
    };
  }

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }
})();
