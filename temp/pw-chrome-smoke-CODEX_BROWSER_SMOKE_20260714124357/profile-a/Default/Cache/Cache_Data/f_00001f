document.addEventListener('DOMContentLoaded', () => {
  const searchInput = document.getElementById('faqSearch');
  const chipRow = document.getElementById('faqChipRow');
  const faqList = document.getElementById('faqList');
  const resultsSummary = document.getElementById('faqResultsSummary');
  const emptyState = document.getElementById('faqEmptyState');
  const expandAllBtn = document.getElementById('faqExpandAllBtn');
  const collapseAllBtn = document.getElementById('faqCollapseAllBtn');
  const audienceFilters = Array.from(document.querySelectorAll('.faq-audience-filter'));
  const entryCount = document.getElementById('faqEntryCount');
  const categoryCount = document.getElementById('faqCategoryCount');
  const audienceCount = document.getElementById('faqAudienceCount');
  const popularSearches = document.getElementById('faqPopularSearches');
  const assistantTitle = document.getElementById('faqAssistantTitle');
  const assistantSummary = document.getElementById('faqAssistantSummary');
  const assistantBullets = document.getElementById('faqAssistantBullets');
  const assistantCategory = document.getElementById('faqAssistantCategory');
  const assistantAudience = document.getElementById('faqAssistantAudience');
  const suggestedList = document.getElementById('faqSuggestedList');
  const openBestMatchBtn = document.getElementById('faqOpenBestMatchBtn');
  const clearSearchBtn = document.getElementById('faqClearSearchBtn');

  if (!searchInput || !chipRow || !faqList || !resultsSummary || !emptyState) {
    return;
  }

  const chips = Array.from(chipRow.querySelectorAll('.faq-chip'));
  const categoryLabels = {
    all: 'all topics',
    applications: 'applications',
    benefits: 'benefits',
    registry: 'registry and tracking',
    claims: 'claims and payroll',
    pensioners: 'pensioner access',
    security: 'security and access'
  };
  const audienceLabels = {
    all: 'all audiences',
    pensioners: 'pensioners',
    staff: 'operational staff',
    supervisors: 'supervisors and administrators'
  };
  const defaultAudienceByCategory = {
    applications: 'Public, pensioners, and staff',
    benefits: 'Public, pensioners, and staff',
    registry: 'Pensioners and staff',
    claims: 'Pensioners and staff',
    pensioners: 'Pensioners',
    security: 'All users'
  };

  let activeCategory = 'all';
  let activeAudience = 'all';
  let bestMatchEntry = null;
  let allowMultipleExpanded = false;
  let suppressExclusiveToggle = false;
  let lastManuallyToggledId = '';
  let faqEntries = [];

  loadFaqEntries();

  chipRow.addEventListener('click', (event) => {
    const chip = event.target.closest('.faq-chip');
    if (!chip) {
      return;
    }

    setActiveChip(chip.dataset.category || 'all');
  });

  popularSearches?.addEventListener('click', (event) => {
    const button = event.target.closest('.faq-popular-search');
    if (!button) {
      return;
    }

    searchInput.value = button.dataset.query || '';
    searchInput.focus();
    applyFilters();
  });

  audienceFilters.forEach((button) => {
    button.addEventListener('click', () => {
      setActiveAudience(button.dataset.audience || 'all');
    });
  });

  suggestedList?.addEventListener('click', (event) => {
    const action = event.target.closest('[data-open-id]');
    if (!action) {
      return;
    }

    openEntryById(action.dataset.openId || '');
  });

  searchInput.addEventListener('input', applyFilters);
  searchInput.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && searchInput.value) {
      searchInput.value = '';
      applyFilters();
      return;
    }

    if (event.key === 'Enter' && bestMatchEntry) {
      event.preventDefault();
      openEntryById(bestMatchEntry.id);
    }
  });

  openBestMatchBtn?.addEventListener('click', () => {
    if (bestMatchEntry) {
      openEntryById(bestMatchEntry.id);
    }
  });

  clearSearchBtn?.addEventListener('click', () => {
    searchInput.value = '';
    setActiveChip('all');
  });

  if (expandAllBtn) {
    expandAllBtn.addEventListener('click', () => {
      allowMultipleExpanded = true;
      suppressExclusiveToggle = true;
      faqEntries.forEach((entry) => {
        if (!entry.item.hidden) {
          entry.item.open = true;
        }
      });
      queueMicrotask(() => {
        suppressExclusiveToggle = false;
      });
    });
  }

  if (collapseAllBtn) {
    collapseAllBtn.addEventListener('click', () => {
      allowMultipleExpanded = false;
      suppressExclusiveToggle = true;
      faqEntries.forEach((entry) => {
        entry.item.open = false;
      });
      queueMicrotask(() => {
        suppressExclusiveToggle = false;
      });
    });
  }

  chips.forEach((chip) => {
    chip.setAttribute('aria-pressed', chip.classList.contains('active') ? 'true' : 'false');
  });

  async function loadFaqEntries() {
    setLoadingState(true);
    try {
      const response = await fetch('../backend/api/get_faq_entries.php?active_only=1', {
        credentials: 'include',
        cache: 'no-store',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      });
      const data = await response.json().catch(() => null);
      if (!response.ok || !data || !data.success) {
        throw new Error(data?.message || 'Unable to load the knowledge base.');
      }

      renderFaqList(Array.isArray(data.entries) ? data.entries : []);
      buildFaqEntries();
      bindExclusiveExpansionRules();
      renderCoverage();
      applyFilters();
    } catch (error) {
      showFaqError(error.message || 'Unable to load the knowledge base.');
    }
  }

  function setLoadingState(isLoading) {
    const loading = document.getElementById('faqLoadingState');
    if (!loading) {
      return;
    }
    loading.style.display = isLoading ? 'grid' : 'none';
  }

  function showFaqError(message) {
    faqList.innerHTML = `
      <div class="faq-loading faq-loading-error">
        <strong>Unable to load FAQs</strong>
        <p>${escapeHtml(message)}</p>
        <button type="button" class="faq-toolbar-btn" id="faqRetryLoadBtn">Try Again</button>
      </div>
    `;
    document.getElementById('faqRetryLoadBtn')?.addEventListener('click', () => {
      faqList.innerHTML = `
        <div class="faq-loading" id="faqLoadingState">
          <div class="loading-spinner"></div>
          <p>Loading knowledge base...</p>
        </div>
      `;
      loadFaqEntries();
    });
  }

  function normalize(value) {
    return String(value || '').trim().toLowerCase().replace(/\s+/g, ' ');
  }

  function slugify(value) {
    return normalize(value).replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
  }

  function normalizeCategory(value) {
    const key = normalize(value);
    if (categoryLabels[key]) {
      return key;
    }
    return 'applications';
  }

  function tokenize(query) {
    return normalize(query).split(' ').filter((token) => token.length > 1);
  }

  function compareAlphabetically(left, right) {
    return left.question.localeCompare(right.question);
  }

  function renderFaqList(entries) {
    faqList.innerHTML = '';

    if (!entries.length) {
      faqList.innerHTML = `
        <div class="faq-loading faq-loading-empty">
          <strong>No FAQ entries available</strong>
          <p>Add guidance in Admin Console to populate the knowledge base.</p>
        </div>
      `;
      return;
    }

    const fragment = document.createDocumentFragment();
    entries.forEach((entry, index) => {
      const question = String(entry.question || '').trim();
      const answer = String(entry.answer || '').trim();
      if (!question || !answer) {
        return;
      }

      const category = normalizeCategory(entry.category || 'applications');
      const audience = String(entry.audience_label || defaultAudienceByCategory[category] || 'Public guidance').trim();
      const bullets = Array.isArray(entry.bullets)
        ? entry.bullets.map((item) => String(item || '').trim()).filter(Boolean)
        : [];

      const details = document.createElement('details');
      details.className = 'faq-item';
      details.dataset.category = category;
      details.dataset.audience = audience;
      if (entry.is_featured) {
        details.dataset.featured = '1';
      }

      const summary = document.createElement('summary');
      summary.textContent = question;

      const answerWrap = document.createElement('div');
      answerWrap.className = 'faq-answer';

      const paragraph = document.createElement('p');
      paragraph.textContent = answer;
      answerWrap.appendChild(paragraph);

      if (bullets.length) {
        const list = document.createElement('ul');
        bullets.forEach((bullet) => {
          const li = document.createElement('li');
          li.textContent = bullet;
          list.appendChild(li);
        });
        answerWrap.appendChild(list);
      }

      details.appendChild(summary);
      details.appendChild(answerWrap);

      if (!details.id) {
        details.id = `faq-${slugify(question || `entry-${index + 1}`)}`;
      }

      fragment.appendChild(details);
    });

    faqList.appendChild(fragment);
  }

  function buildFaqEntries() {
    faqEntries = Array.from(faqList.querySelectorAll('.faq-item')).map((item, index) => {
      const summary = item.querySelector('summary');
      const answer = item.querySelector('.faq-answer');
      const category = normalize(item.dataset.category);
      const question = String(summary?.textContent || '').trim();
      const answerText = String(answer?.textContent || '').replace(/\s+/g, ' ').trim();
      const bullets = Array.from(answer?.querySelectorAll('li') || [])
        .map((bullet) => bullet.textContent.trim())
        .filter(Boolean);
      const id = item.id || `faq-${slugify(question || `entry-${index + 1}`)}`;
      const audience = String(item.dataset.audience || defaultAudienceByCategory[category] || 'Public guidance').trim();
      const featured = item.dataset.featured === '1';

      item.id = id;
      item.dataset.audience = audience;

      return {
        id,
        item,
        question,
        questionNorm: normalize(question),
        answerText,
        answerNorm: normalize(answerText),
        bullets,
        bulletsNorm: normalize(bullets.join(' ')),
        category,
        audience,
        featured
      };
    });
  }

  function renderCoverage() {
    if (entryCount) {
      entryCount.textContent = String(faqEntries.length);
    }

    if (categoryCount) {
      categoryCount.textContent = String(new Set(faqEntries.map((entry) => entry.category)).size);
    }

    if (audienceCount) {
      audienceCount.textContent = String(new Set(faqEntries.map((entry) => entry.audience)).size);
    }
  }

  function buildSummary(visibleCount, query) {
    const categoryLabel = categoryLabels[activeCategory] || 'all topics';
    const audienceLabel = audienceLabels[activeAudience] || 'all audiences';
    const audienceSuffix = activeAudience === 'all' ? '' : ` for ${audienceLabel}`;
    if (!visibleCount) {
      if (query) {
        return `No matching guidance was found for "${query}" under ${categoryLabel}${audienceSuffix}.`;
      }
      return `No guidance entries are currently available under ${categoryLabel}${audienceSuffix}.`;
    }

    if (query) {
      return `Showing ${visibleCount} matching guidance entr${visibleCount === 1 ? 'y' : 'ies'} for "${query}" under ${categoryLabel}${audienceSuffix}.`;
    }

    if (activeCategory === 'all' && activeAudience === 'all') {
      return `Showing all ${visibleCount} knowledge-base entr${visibleCount === 1 ? 'y' : 'ies'}.`;
    }

    return `Showing ${visibleCount} guidance entr${visibleCount === 1 ? 'y' : 'ies'} under ${categoryLabel}${audienceSuffix}.`;
  }

  function defaultAssistantSummary() {
    return 'Search in natural language and the knowledge guide will highlight the strongest answer first, then list related guidance below.';
  }

  function scoreEntry(entry, query) {
    const normalizedQuery = normalize(query);
    if (!normalizedQuery) {
      return entry.featured ? 100 : 10;
    }

    let score = 0;
    const tokens = tokenize(normalizedQuery);

    if (entry.questionNorm.includes(normalizedQuery)) score += 70;
    if (entry.answerNorm.includes(normalizedQuery)) score += 35;
    if (entry.bulletsNorm.includes(normalizedQuery)) score += 20;
    if (normalize(entry.audience).includes(normalizedQuery)) score += 14;
    if (entry.category.includes(normalizedQuery)) score += 14;

    tokens.forEach((token) => {
      if (entry.questionNorm.includes(token)) score += 12;
      if (entry.answerNorm.includes(token)) score += 7;
      if (entry.bulletsNorm.includes(token)) score += 5;
      if (normalize(entry.audience).includes(token)) score += 4;
      if (entry.category.includes(token)) score += 4;
    });

    if (entry.featured) {
      score += 4;
    }

    return score;
  }

  function renderAssistant(query, visibleEntries) {
    bestMatchEntry = visibleEntries[0] || null;

    if (!bestMatchEntry) {
      if (assistantTitle) assistantTitle.textContent = 'No guided answer found.';
      if (assistantSummary) {
        assistantSummary.textContent = query
          ? `Try a broader keyword or switch back to All Topics to widen the search for "${query}".`
          : defaultAssistantSummary();
      }
      if (assistantBullets) {
        assistantBullets.hidden = true;
        assistantBullets.innerHTML = '';
      }
      if (assistantCategory) assistantCategory.textContent = 'No active topic';
      if (assistantAudience) assistantAudience.textContent = 'Guidance unavailable';
      if (openBestMatchBtn) openBestMatchBtn.disabled = true;
      if (suggestedList) {
        suggestedList.innerHTML = '<p class="faq-suggested-empty">No related guidance available.</p>';
      }
      return;
    }

    const excerpt = bestMatchEntry.answerText.length > 220
      ? `${bestMatchEntry.answerText.slice(0, 217).trimEnd()}...`
      : bestMatchEntry.answerText;

    if (assistantTitle) assistantTitle.textContent = bestMatchEntry.question;
    if (assistantSummary) assistantSummary.textContent = excerpt || defaultAssistantSummary();
    if (assistantCategory) assistantCategory.textContent = categoryLabels[bestMatchEntry.category] || bestMatchEntry.category;
    if (assistantAudience) assistantAudience.textContent = bestMatchEntry.audience;
    if (openBestMatchBtn) openBestMatchBtn.disabled = false;

    if (assistantBullets) {
      if (bestMatchEntry.bullets.length) {
        assistantBullets.hidden = false;
        assistantBullets.innerHTML = bestMatchEntry.bullets
          .slice(0, 3)
          .map((bullet) => `<li>${escapeHtml(bullet)}</li>`)
          .join('');
      } else {
        assistantBullets.hidden = true;
        assistantBullets.innerHTML = '';
      }
    }

    const relatedEntries = visibleEntries
      .filter((entry) => entry.id !== bestMatchEntry.id)
      .slice(0, 3);

    if (suggestedList) {
      suggestedList.innerHTML = relatedEntries.length
        ? relatedEntries.map((entry) => `
            <article class="faq-suggested-item">
              <strong>${escapeHtml(entry.question)}</strong>
              <p>${escapeHtml(trimText(entry.answerText, 145))}</p>
              <button type="button" class="faq-suggested-action" data-open-id="${escapeHtml(entry.id)}">Open this answer</button>
            </article>
          `).join('')
        : '<p class="faq-suggested-empty">No stronger related guidance was found beyond the current best match.</p>';
    }
  }

  function reorderFaqList(visibleEntries) {
    const visibleIds = new Set(visibleEntries.map((entry) => entry.id));
    const hiddenEntries = faqEntries.filter((entry) => !visibleIds.has(entry.id)).sort(compareAlphabetically);

    [...visibleEntries, ...hiddenEntries].forEach((entry) => {
      faqList.appendChild(entry.item);
      const isVisible = visibleIds.has(entry.id);
      entry.item.hidden = !isVisible;
      if (!isVisible) {
        entry.item.open = false;
      }
    });
  }

  function getVisibleEntries(query) {
    const rankedEntries = faqEntries
      .map((entry) => ({
        entry,
        score: scoreEntry(entry, query)
      }))
      .filter(({ entry, score }) => {
        const categoryMatches = activeCategory === 'all' || entry.category === activeCategory;
        const audienceMatches = activeAudience === 'all' || normalize(entry.audience).includes(activeAudience);
        const queryMatches = !query || score > 0;
        return categoryMatches && audienceMatches && queryMatches;
      })
      .sort((left, right) => {
        if (query) {
          return right.score - left.score || compareAlphabetically(left.entry, right.entry);
        }

        if (right.entry.featured !== left.entry.featured) {
          return Number(right.entry.featured) - Number(left.entry.featured);
        }

        return compareAlphabetically(left.entry, right.entry);
      });

    return rankedEntries.map(({ entry }) => entry);
  }

  function applyFilters() {
    const query = normalize(searchInput.value);
    const visibleEntries = getVisibleEntries(query);

    reorderFaqList(visibleEntries);
    renderAssistant(query, visibleEntries);

    emptyState.hidden = faqEntries.length === 0 ? true : visibleEntries.length > 0;
    resultsSummary.textContent = buildSummary(visibleEntries.length, searchInput.value.trim());
  }

  function setActiveChip(nextCategory) {
    activeCategory = nextCategory;
    chips.forEach((chip) => {
      const isActive = chip.dataset.category === nextCategory;
      chip.classList.toggle('active', isActive);
      chip.setAttribute('aria-pressed', isActive ? 'true' : 'false');
    });
    applyFilters();
  }

  function setActiveAudience(nextAudience) {
    activeAudience = String(nextAudience || 'all').trim().toLowerCase();
    audienceFilters.forEach((button) => {
      const isActive = (button.dataset.audience || 'all') === activeAudience;
      button.classList.toggle('active', isActive);
      button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
    });
    applyFilters();
  }

  function openEntryById(entryId) {
    const entry = faqEntries.find((candidate) => candidate.id === entryId);
    if (!entry || entry.item.hidden) {
      return;
    }

    allowMultipleExpanded = false;
    closeOtherEntries(entry.id);
    entry.item.open = true;
    entry.item.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function bindExclusiveExpansionRules() {
    faqEntries.forEach((entry) => {
      const summary = entry.item.querySelector('summary');

      summary?.addEventListener('click', () => {
        lastManuallyToggledId = entry.id;
      });

      summary?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          lastManuallyToggledId = entry.id;
        }
      });

      entry.item.addEventListener('toggle', () => {
        if (suppressExclusiveToggle || entry.item.hidden) {
          return;
        }

        const wasManualToggle = lastManuallyToggledId === entry.id;

        if (entry.item.open && (wasManualToggle || !allowMultipleExpanded)) {
          allowMultipleExpanded = false;
          closeOtherEntries(entry.id);
        }

        if (wasManualToggle) {
          lastManuallyToggledId = '';
        }
      });
    });
  }

  function closeOtherEntries(activeId) {
    faqEntries.forEach((entry) => {
      if (entry.id !== activeId) {
        entry.item.open = false;
      }
    });
  }

  function trimText(value, maxLength) {
    const text = String(value || '').trim();
    if (text.length <= maxLength) {
      return text;
    }
    return `${text.slice(0, Math.max(0, maxLength - 3)).trimEnd()}...`;
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
