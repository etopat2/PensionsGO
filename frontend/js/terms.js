document.addEventListener('DOMContentLoaded', () => {
  const searchInput = document.getElementById('termsSearch');
  const chipRow = document.getElementById('termsChipRow');
  const chips = Array.from(document.querySelectorAll('.terms-chip'));
  const sectionsContainer = document.getElementById('termsSections');
  const navList = document.getElementById('termsNavList');
  const emptyState = document.getElementById('termsEmptyState');
  const accessPlatformBtn = document.getElementById('termsAccessPlatformBtn');
  const toggleAllBtn = document.getElementById('termsToggleAllBtn');

  let sections = [];
  let navLinks = [];
  let activeTopic = 'all';
  let allExpanded = false;

  applyLocalSessionCtaVisibility();
  applyAuthenticatedCtaVisibility().catch((error) => {
    console.error('Unable to determine terms CTA visibility:', error);
  });

  searchInput?.addEventListener('input', applyFilters);
  chipRow?.addEventListener('click', (event) => {
    const chip = event.target instanceof HTMLElement ? event.target.closest('.terms-chip') : null;
    if (!chip) return;
    activeTopic = String(chip.dataset.topic || 'all');
    chips.forEach((item) => item.classList.toggle('active', item === chip));
    applyFilters();
  });

  toggleAllBtn?.addEventListener('click', () => {
    allExpanded = !allExpanded;
    sections.forEach((section) => {
      if (!section.classList.contains('hidden')) {
        setSectionExpanded(section, allExpanded);
      } else if (!allExpanded) {
        setSectionExpanded(section, false);
      }
    });
    updateToggleAllButton();
  });

  loadTermsClauses();

  async function loadTermsClauses() {
    setLoadingState(true);
    try {
      const response = await fetch('../backend/api/get_terms_clauses.php?active_only=1&section_key=operational', {
        credentials: 'include',
        cache: 'no-store',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      });
      const data = await response.json().catch(() => null);
      if (!response.ok || !data || !data.success) {
        throw new Error(data?.message || 'Unable to load terms.');
      }

      renderTermsClauses(Array.isArray(data.clauses) ? data.clauses : []);
      setupClauseToggles();
      applyFilters();
      bindObserver();
    } catch (error) {
      showTermsError(error.message || 'Unable to load terms.');
    }
  }

  function setLoadingState(isLoading) {
    if (!sectionsContainer) return;
    if (isLoading) {
      sectionsContainer.innerHTML = `
        <div class="terms-loading">
          <div class="loading-spinner"></div>
          <p>Loading terms...</p>
        </div>
      `;
    }
  }

  function showTermsError(message) {
    if (!sectionsContainer) return;
    sectionsContainer.innerHTML = `
      <div class="terms-loading terms-loading-error">
        <strong>Unable to load terms</strong>
        <p>${escapeHtml(message)}</p>
        <button type="button" class="terms-toggle-all-btn" id="termsRetryLoadBtn">Try Again</button>
      </div>
    `;
    document.getElementById('termsRetryLoadBtn')?.addEventListener('click', loadTermsClauses);
  }

  function renderTermsClauses(clauses) {
    if (!sectionsContainer || !navList) return;
    sectionsContainer.innerHTML = '';
    navList.innerHTML = '';

    if (!clauses.length) {
      sections = [];
      navLinks = [];
      if (emptyState) emptyState.hidden = false;
      return;
    }

    const fragment = document.createDocumentFragment();
    const navFragment = document.createDocumentFragment();

    clauses.forEach((clause, index) => {
      const title = String(clause.title || '').trim();
      const body = String(clause.body || '').trim();
      if (!title || !body) {
        return;
      }

      const article = document.createElement('article');
      article.className = 'terms-section';
      article.dataset.topic = String(clause.topics || '').trim();
      article.id = clause.clause_id ? `terms-clause-${clause.clause_id}` : `terms-clause-${index + 1}`;

      const heading = document.createElement('h3');
      heading.textContent = `${index + 1}. ${title}`;
      article.appendChild(heading);

      const paragraphs = body.split(/\n\n+/).map((part) => part.trim()).filter(Boolean);
      paragraphs.forEach((text) => {
        const p = document.createElement('p');
        p.textContent = text;
        article.appendChild(p);
      });

      fragment.appendChild(article);

      const navItem = document.createElement('li');
      const navLink = document.createElement('a');
      navLink.href = `#${article.id}`;
      navLink.textContent = `${index + 1}. ${title}`;
      navItem.appendChild(navLink);
      navFragment.appendChild(navItem);
    });

    sectionsContainer.appendChild(fragment);
    navList.appendChild(navFragment);

    sections = Array.from(sectionsContainer.querySelectorAll('.terms-section'));
    navLinks = Array.from(navList.querySelectorAll('a'));

    navLinks.forEach((link) => {
      link.addEventListener('click', () => {
        navLinks.forEach((item) => item.classList.remove('active'));
        link.classList.add('active');
      });
    });
  }

  function setupClauseToggles() {
    sections.forEach((section, index) => {
      const heading = section.querySelector('h3');
      if (!heading) return;

      const body = document.createElement('div');
      body.className = 'terms-section-body';
      body.id = `${section.id || `terms-section-${index + 1}`}-body`;

      const fragments = [];
      Array.from(section.children).forEach((child) => {
        if (child !== heading) {
          fragments.push(child);
        }
      });
      fragments.forEach((node) => body.appendChild(node));

      const head = document.createElement('div');
      head.className = 'terms-section-head';

      const toggle = document.createElement('button');
      toggle.type = 'button';
      toggle.className = 'terms-section-toggle';
      toggle.setAttribute('aria-expanded', 'false');
      toggle.setAttribute('aria-controls', body.id);
      toggle.innerHTML = '<span class="terms-section-toggle-label">Show</span>';

      heading.classList.add('terms-section-title');
      heading.tabIndex = 0;
      heading.setAttribute('role', 'button');
      heading.setAttribute('aria-expanded', 'false');
      heading.setAttribute('aria-controls', body.id);

      head.appendChild(heading);
      head.appendChild(toggle);
      section.prepend(head);
      section.appendChild(body);

      const toggleSection = () => {
        const shouldForceOpen = allExpanded || !section.classList.contains('expanded');
        allExpanded = false;
        closeExpandedSections(section);
        setSectionExpanded(section, shouldForceOpen);
        syncExpandedState();
      };

      toggle.addEventListener('click', toggleSection);
      heading.addEventListener('click', toggleSection);
      heading.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          toggleSection();
        }
      });

      setSectionExpanded(section, false);
    });
    updateToggleAllButton();
  }

  function applyFilters() {
    const query = String(searchInput?.value || '').trim().toLowerCase();
    let visibleCount = 0;

    sections.forEach((section) => {
      const topic = String(section.dataset.topic || '');
      const text = section.textContent.toLowerCase();
      const matchesTopic = activeTopic === 'all' || topic.includes(activeTopic);
      const matchesQuery = query === '' || text.includes(query);
      const visible = matchesTopic && matchesQuery;
      section.classList.toggle('hidden', !visible);
      if (visible && allExpanded) {
        setSectionExpanded(section, true);
      }
      if (visible) visibleCount += 1;

      const navItem = section.id ? document.querySelector(`.terms-nav-list a[href="#${section.id}"]`)?.parentElement : null;
      if (navItem) {
        navItem.classList.toggle('hidden', !visible);
      }
    });

    if (emptyState) {
      emptyState.hidden = visibleCount !== 0;
    }

    syncExpandedState();
  }

  function setSectionExpanded(section, expanded) {
    const body = section.querySelector('.terms-section-body');
    const heading = section.querySelector('.terms-section-title');
    const toggle = section.querySelector('.terms-section-toggle');
    if (!body || !heading || !toggle) return;

    section.classList.toggle('expanded', expanded);
    body.hidden = !expanded;
    heading.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');

    const label = expanded ? 'Hide' : 'Show';
    toggle.querySelector('.terms-section-toggle-label').textContent = label;
  }

  function syncExpandedState() {
    updateToggleAllButton();
  }

  function updateToggleAllButton() {
    if (!toggleAllBtn) return;
    toggleAllBtn.textContent = allExpanded ? 'Hide All Clauses' : 'Show All Clauses';
    toggleAllBtn.setAttribute('aria-expanded', allExpanded ? 'true' : 'false');
  }

  function closeExpandedSections(exceptSection = null) {
    sections.forEach((section) => {
      if (exceptSection && section === exceptSection) {
        return;
      }
      setSectionExpanded(section, false);
    });
  }

  function bindObserver() {
    if (!('IntersectionObserver' in window)) return;
    const observer = new IntersectionObserver((entries) => {
      const visibleEntries = entries.filter((entry) => entry.isIntersecting);
      if (!visibleEntries.length) return;
      const current = visibleEntries.sort((a, b) => b.intersectionRatio - a.intersectionRatio)[0];
      const id = current.target.id;
      if (!id) return;
      navLinks.forEach((link) => {
        link.classList.toggle('active', link.getAttribute('href') === `#${id}`);
      });
    }, {
      rootMargin: '-25% 0px -55% 0px',
      threshold: [0.2, 0.45, 0.7]
    });

    sections.forEach((section) => observer.observe(section));
  }

  async function applyAuthenticatedCtaVisibility() {
    try {
      const response = await fetch('../backend/api/check_session.php', {
        credentials: 'include',
        cache: 'no-store',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      });
      const data = await response.json().catch(() => ({ success: false }));
      if (response.ok && data && data.success && accessPlatformBtn) {
        hideAccessPlatformButton();
      }
    } catch (error) {
      console.error('Terms session CTA check failed:', error);
    }
  }

  function applyLocalSessionCtaVisibility() {
    const hasLocalSession = sessionStorage.getItem('isLoggedIn') === 'true'
      || Boolean(sessionStorage.getItem('userRole') || localStorage.getItem('userRole'));
    if (hasLocalSession) {
      hideAccessPlatformButton();
    }
  }

  function hideAccessPlatformButton() {
    if (accessPlatformBtn) {
      accessPlatformBtn.style.display = 'none';
    }
  }

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }
});
