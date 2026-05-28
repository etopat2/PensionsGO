const PODCAST_FEED_URLS = {
  authenticated: '../backend/api/get_podcast_feed.php',
  public: '../backend/api/get_public_podcast_feed.php'
};

const podcastState = {
  mode: 'public',
  role: 'public',
  items: [],
  filteredItems: [],
  featured: null,
  categories: {},
  activeAudience: 'all',
  search: '',
  selectedId: null,
  viewsLogged: new Set()
};

document.addEventListener('DOMContentLoaded', () => {
  initializePodcastPage().catch((error) => {
    console.error('Podcast initialization failed:', error);
    showPodcastFeedback(error.message || 'Unable to load podcast videos.', 'error');
  });
});

async function initializePodcastPage() {
  const sessionState = await resolvePodcastSessionState();
  podcastState.mode = sessionState.active ? 'authenticated' : 'public';
  podcastState.role = sessionState.userRole || (sessionState.active ? 'staff' : 'public');
  document.body.dataset.podcastMode = podcastState.mode;
  syncPodcastPageMode();
  bindPodcastEvents();
  await loadPodcastFeed();
}

async function resolvePodcastSessionState() {
  try {
    const response = await fetch('../backend/api/check_session.php', {
      credentials: 'include',
      cache: 'no-store',
      headers: getSessionCheckHeaders()
    });
    if (!response.ok) {
      return readCachedPodcastSessionState();
    }
    const data = await parseJson(response);
    if (data?.active) {
      return {
        active: true,
        userRole: String(data.userRole || sessionStorage.getItem('userRole') || localStorage.getItem('userRole') || '').toLowerCase()
      };
    }
  } catch (_error) {
    return readCachedPodcastSessionState();
  }
  return { active: false, userRole: 'public' };
}

function getSessionCheckHeaders() {
  const headers = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
  if (typeof window.withDeviceTokenHeaders === 'function') {
    return window.withDeviceTokenHeaders(headers);
  }
  if (typeof window.getPersistentDeviceToken === 'function') {
    const deviceToken = window.getPersistentDeviceToken();
    if (deviceToken) headers['X-Device-Token'] = deviceToken;
  }
  return headers;
}

function readCachedPodcastSessionState() {
  const isLoggedIn = sessionStorage.getItem('isLoggedIn') === 'true'
    || sessionStorage.getItem('userLoggedIn') === 'true'
    || localStorage.getItem('loggedInUser');
  if (!isLoggedIn) {
    return { active: false, userRole: 'public' };
  }
  return {
    active: true,
    userRole: String(sessionStorage.getItem('userRole') || localStorage.getItem('userRole') || 'staff').toLowerCase()
  };
}

function syncPodcastPageMode() {
  const isPublic = podcastState.mode === 'public';
  const main = document.querySelector('.podcast-page-main');
  const layout = document.querySelector('.podcast-layout');
  main?.classList.toggle('public-mode', isPublic);
  layout?.classList.toggle('single-feed', isPublic);
  document.querySelector('.podcast-insight-grid')?.classList.toggle('hidden', isPublic);

  setText('podcastAudienceLabel', isPublic ? 'Public' : 'Secure Access');
  const kicker = document.querySelector('.podcast-kicker');
  if (kicker) {
    kicker.textContent = isPublic ? 'Public Video Library' : 'Podcast & Video Guidance';
  }

  const featureTitle = document.querySelector('.podcast-feature-panel .podcast-panel-head h2');
  if (featureTitle) {
    featureTitle.textContent = isPublic ? 'Featured Public Video' : 'Featured Video';
  }
  const featureCopy = document.querySelector('.podcast-feature-panel .podcast-panel-head p');
  if (featureCopy) {
    featureCopy.textContent = isPublic
      ? 'Play the latest published public guidance directly inside the app.'
      : 'Play guidance, common pension questions, or service updates without leaving the system.';
  }

  const libraryTitle = document.querySelector('.podcast-library-panel .podcast-panel-head h2');
  if (libraryTitle) {
    libraryTitle.textContent = isPublic ? 'Public Video Library' : 'Video Library';
  }
  const libraryCopy = document.querySelector('.podcast-library-panel .podcast-panel-head p');
  if (libraryCopy) {
    libraryCopy.textContent = isPublic
      ? 'Only videos cleared for the public are shown here.'
      : 'Find the right video quickly by channel or topic.';
  }

  const refreshButton = document.getElementById('refreshPodcastFeedBtn');
  if (refreshButton) {
    refreshButton.textContent = isPublic ? 'Refresh Public Videos' : 'Refresh';
  }

  const searchInput = document.getElementById('podcastSearchInput');
  if (searchInput) {
    searchInput.placeholder = isPublic ? 'Search public pension guidance' : 'Search title, topic, or keyword';
  }
}

function bindPodcastEvents() {
  document.getElementById('refreshPodcastFeedBtn')?.addEventListener('click', async () => {
    const btn = document.getElementById('refreshPodcastFeedBtn');
    if (btn) btn.disabled = true;
    try {
      await loadPodcastFeed();
      if (typeof window.appToast === 'function') {
        window.appToast('Podcast library refreshed.', { type: 'success', title: 'Podcast' });
      }
    } finally {
      if (btn) btn.disabled = false;
    }
  });

  document.getElementById('podcastSearchInput')?.addEventListener('input', (event) => {
    podcastState.search = String(event.target.value || '').trim().toLowerCase();
    applyPodcastFilters();
  });
}

async function loadPodcastFeed() {
  const response = await fetch(PODCAST_FEED_URLS[podcastState.mode], {
    credentials: podcastState.mode === 'authenticated' ? 'include' : 'same-origin',
    cache: 'no-store',
    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
  });

  const data = await parseJson(response);
  if (!response.ok || !data.success) {
    if (response.status === 401 && podcastState.mode === 'authenticated') {
      window.location.replace('login.html?return=' + encodeURIComponent(window.location.href));
      return;
    }
    throw new Error(data.message || 'Unable to load the podcast library.');
  }

  podcastState.items = Array.isArray(data.items) ? data.items : [];
  podcastState.categories = data.categories || buildCategoryMap(podcastState.items);
  podcastState.featured = data.featured || podcastState.items[0] || null;
  podcastState.activeAudience = 'all';
  podcastState.search = '';
  podcastState.selectedId = podcastState.featured?.id || null;

  renderPodcastOverview(data);
  renderAudienceChips();
  applyPodcastFilters();
}

function renderPodcastOverview(data) {
  const mode = podcastState.mode;
  const role = data.settings?.role || podcastState.role || (mode === 'public' ? 'public' : (sessionStorage.getItem('userRole') || 'staff'));
  setText('podcastVideoCount', String(podcastState.items.length));
  setText('podcastAudienceLabel', getAudienceSummaryLabel(mode, role));
  setText('podcastHeroTitle', mode === 'public' ? 'Public pension video library' : 'PensionsGo Media Centre');
  setText('podcastHeroSubtitle', buildHeroSubtitle(mode, role, podcastState.items.length));
  renderFeaturedVideo(podcastState.featured);
}

function renderAudienceChips() {
  const container = document.getElementById('podcastFilterChips');
  if (!container) return;
  const entries = [['all', 'All Videos'], ...Object.entries(podcastState.categories || {})];
  container.innerHTML = entries.map(([key, label]) => `
    <button type="button" class="podcast-chip ${key === podcastState.activeAudience ? 'active' : ''}" data-audience="${escapeHtml(key)}">${escapeHtml(label)}</button>
  `).join('');
  container.querySelectorAll('[data-audience]').forEach((button) => {
    button.addEventListener('click', () => {
      podcastState.activeAudience = button.dataset.audience || 'all';
      renderAudienceChips();
      applyPodcastFilters();
    });
  });
}

function applyPodcastFilters() {
  const search = podcastState.search;
  podcastState.filteredItems = podcastState.items.filter((item) => {
    const audienceMatches = podcastState.activeAudience === 'all' || item.audience === podcastState.activeAudience;
    if (!audienceMatches) return false;
    if (!search) return true;
    const haystack = [item.title, item.description, ...(item.tags || [])].join(' ').toLowerCase();
    return haystack.includes(search);
  });

  if (!podcastState.filteredItems.some((item) => item.id === podcastState.selectedId)) {
    podcastState.selectedId = podcastState.filteredItems[0]?.id || podcastState.featured?.id || null;
  }

  renderPodcastList();
  const selected = podcastState.filteredItems.find((item) => item.id === podcastState.selectedId)
    || podcastState.items.find((item) => item.id === podcastState.selectedId)
    || podcastState.filteredItems[0]
    || podcastState.featured;
  renderFeaturedVideo(selected || null);
}

function renderPodcastList() {
  const container = document.getElementById('podcastLibraryList');
  if (!container) return;
  if (!podcastState.filteredItems.length) {
    container.innerHTML = '<div class="podcast-empty-state">No videos matched the current filter.</div>';
    return;
  }

  container.innerHTML = podcastState.filteredItems.map((item) => `
    <article class="podcast-video-card ${item.id === podcastState.selectedId ? 'active' : ''}" data-video-id="${item.id}">
      <div class="podcast-video-thumb">
        <img src="${escapeAttribute(item.thumbnailUrl)}" alt="${escapeAttribute(item.title)} thumbnail" loading="lazy">
        <span class="podcast-video-audience ${escapeHtml(item.audience)}">${escapeHtml(item.audienceLabel)}</span>
      </div>
      <div class="podcast-video-copy">
        <strong>${escapeHtml(item.title)}</strong>
        <p>${escapeHtml(getPodcastDescriptionText(item.description))}</p>
        <div class="podcast-card-meta">
          <span>${escapeHtml(formatDate(item.updatedAt || item.publishedAt))}</span>
          <span>${escapeHtml(String(item.viewCount || 0))} views</span>
        </div>
      </div>
    </article>
  `).join('');

  container.querySelectorAll('[data-video-id]').forEach((card) => {
    card.addEventListener('click', () => {
      podcastState.selectedId = Number(card.dataset.videoId || 0);
      const selected = podcastState.items.find((item) => item.id === podcastState.selectedId) || null;
      renderFeaturedVideo(selected);
      renderPodcastList();
    });
  });
}

function renderFeaturedVideo(item) {
  const player = document.getElementById('podcastFeaturedPlayer');
  const title = document.getElementById('podcastFeaturedTitle');
  const description = document.getElementById('podcastFeaturedDescription');
  const tags = document.getElementById('podcastFeaturedTags');
  if (!player || !title || !description || !tags) return;

  if (!item) {
    player.innerHTML = '<div class="podcast-empty-state">No video is currently available for this view.</div>';
    title.textContent = 'No video selected';
    description.innerHTML = buildPodcastDescriptionHtml('Select a video from the library to start playing it here.');
    tags.innerHTML = '';
    return;
  }

  podcastState.selectedId = item.id;
  player.innerHTML = `
    <div class="podcast-player-frame">
      <iframe src="${escapeAttribute(item.embedUrl)}" title="${escapeAttribute(item.title)}" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe>
    </div>
  `;
  title.textContent = item.title || 'Podcast Video';
  description.innerHTML = buildPodcastDescriptionHtml(item.description);
  tags.innerHTML = (item.tags || []).map((tag) => `<span class="podcast-tag">${escapeHtml(tag)}</span>`).join('');
  if (!tags.innerHTML) {
    tags.innerHTML = `<span class="podcast-tag muted">${escapeHtml(item.audienceLabel)}</span>`;
  }
  logPodcastView(item.id);
}

async function logPodcastView(podcastId) {
  if (!podcastId || podcastState.viewsLogged.has(podcastId)) return;
  podcastState.viewsLogged.add(podcastId);
  try {
    await fetch('../backend/api/record_podcast_view.php', {
      method: 'POST',
      credentials: podcastState.mode === 'authenticated' ? 'include' : 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ podcast_id: podcastId })
    });
  } catch (error) {
    console.warn('Unable to log podcast view:', error);
  }
}

async function parseJson(response) {
  try {
    return await response.json();
  } catch (error) {
    return { success: false, message: 'The server returned an invalid response.' };
  }
}

function buildCategoryMap(items) {
  const categories = {};
  items.forEach((item) => {
    if (item.audience && item.audienceLabel) {
      categories[item.audience] = item.audienceLabel;
    }
  });
  return categories;
}

function getAudienceSummaryLabel(mode, role) {
  if (mode === 'public') return 'Public';
  if ((role || '').toLowerCase() === 'pensioner') return 'Pensioner Channel';
  if ((role || '').toLowerCase() === 'admin') return 'Administrator View';
  return 'Staff Channel';
}

function buildHeroSubtitle(mode, role, count) {
  if (mode === 'public') {
    return count ? 'Browse approved public pension videos and play them directly inside the app.' : 'Public pension videos will appear here when published.';
  }
  if ((role || '').toLowerCase() === 'pensioner') {
    return count ? 'Pensioner and public guidance videos are available below.' : 'No pensioner videos are currently available.';
  }
  if ((role || '').toLowerCase() === 'admin') {
    return count ? 'You can review public, staff, and pensioner-targeted videos from this secure library.' : 'No podcast videos are currently available.';
  }
  return count ? 'Staff and public guidance videos are available below.' : 'No staff videos are currently available.';
}

function setText(id, value) {
  const element = document.getElementById(id);
  if (element) element.textContent = value || '--';
}

function formatDate(value) {
  if (!value) return 'Recently updated';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return String(value);
  return date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

function showPodcastFeedback(message, tone = 'error') {
  const container = document.getElementById('podcastFeedback');
  if (!container) return;
  container.hidden = false;
  container.innerHTML = `<div class="podcast-feedback-card ${escapeHtml(tone)}"><strong>${tone === 'error' ? 'Unable to load videos' : 'Notice'}</strong><p>${escapeHtml(message)}</p></div>`;
}

function normalizePodcastDescription(value) {
  return String(value ?? '')
    .replace(/\r\n?/g, '\n')
    .trim();
}

function getPodcastDescriptionText(value, fallback = 'No description provided.') {
  const normalized = normalizePodcastDescription(value);
  return normalized || fallback;
}

function buildPodcastDescriptionHtml(value, fallback = 'No description provided.') {
  const description = getPodcastDescriptionText(value, fallback);
  const paragraphs = description
    .split(/\n\s*\n/g)
    .map((paragraph) => paragraph.trim())
    .filter(Boolean);

  return paragraphs
    .map((paragraph) => `<p>${escapeHtml(paragraph).replace(/\n/g, '<br>')}</p>`)
    .join('');
}

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function escapeAttribute(value) {
  return escapeHtml(value);
}
