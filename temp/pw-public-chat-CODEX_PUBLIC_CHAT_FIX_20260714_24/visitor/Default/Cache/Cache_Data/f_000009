const PWA_APP_ROOT = new URL('../../', import.meta.url);
const PWA_ASSET_VERSION = '20260528b';
const manifestUrl = new URL(`manifest.webmanifest?v=${PWA_ASSET_VERSION}`, PWA_APP_ROOT);
const PWA_MANIFEST_PATH = `${manifestUrl.pathname}${manifestUrl.search}`;
const PWA_APPLE_ICON_PATH = new URL('assets/pwa/apple-touch-icon.png', PWA_APP_ROOT).pathname;
const PWA_SERVICE_WORKER_PATH = new URL('service-worker.js', PWA_APP_ROOT).pathname;
const PWA_SERVICE_WORKER_SCOPE = PWA_APP_ROOT.pathname;
const PWA_VERSION_CHECK_URL = new URL('../backend/api/pwa_version.php', PWA_APP_ROOT);
const PWA_VERSION_INFO_URL = new URL('../backend/api/get_app_version.php', PWA_APP_ROOT);
const PWA_CONNECTIVITY_PROBE_URL = new URL('index.html', PWA_APP_ROOT);
const MANIFEST_DIAGNOSTIC_URL = new URL(`manifest.webmanifest?v=${PWA_ASSET_VERSION}&manifest_probe=1`, PWA_APP_ROOT);

const VERSION_STORAGE_KEY = 'pwaAppFingerprint';
const VERSION_LABEL_STORAGE_KEY = 'pwaAppVersion';
const VERSION_BUILD_STORAGE_KEY = 'pwaAppBuildId';
const VERSION_CHANNEL_STORAGE_KEY = 'pwaAppChannel';
const VERSION_SCHEMA_STORAGE_KEY = 'pwaAppSchemaVersion';
const VERSION_CACHE_STORAGE_KEY = 'pwaAppCacheVersion';
const VERSION_BUILD_FINGERPRINT_STORAGE_KEY = 'pwaAppBuildFingerprint';
const DISMISSED_UPDATE_STORAGE_KEY = 'pwaDismissedUpdateFingerprint';
const INSTALLED_UPDATE_STORAGE_KEY = 'pwaInstalledUpdateFingerprint';
const PWA_INSTALLED_MARKER_KEY = 'pwaInstalledOnDevice';
const PWA_SCOPE_REFRESH_KEY = 'pwaScopeRefresh20260528b';
const PWA_CONTROLLER_REFRESH_KEY = 'pwaControllerRefresh20260528b';
const INSTALL_PROMPT_RESOLUTION_TIMEOUT_MS = 1800;

const pwaState = {
  deferredInstallPrompt: null,
  installButton: null,
  offlineBanner: null,
  wasOffline: !navigator.onLine,
  isRefreshing: false,
  updateInFlight: null,
  skipControllerReload: false,
  updateCheckBound: false,
  registration: null,
  launchCheckCompleted: false,
  updatePromptOpen: false,
  pendingUpdateFingerprint: null,
  pendingVersionInfo: null,
  pendingUpdateReason: '',
  latestVersionInfo: null,
  installPromptCapabilityResolved: false,
  browserManagedInstallPrompt: false,
  installPromptResolutionTimer: null,
  installButtonObserver: null,
  installEntryPointUpdateScheduled: false,
  offlineBannerUpdateToken: 0
};

function isStandaloneDisplayMode() {
  try {
    return Boolean(
      window.matchMedia?.('(display-mode: standalone)').matches
      || window.matchMedia?.('(display-mode: fullscreen)').matches
      || window.matchMedia?.('(display-mode: minimal-ui)').matches
      || window.navigator?.standalone === true
    );
  } catch (_error) {
    return Boolean(window.navigator?.standalone === true);
  }
}

function hasInstalledMarker() {
  try {
    return localStorage.getItem(PWA_INSTALLED_MARKER_KEY) === '1';
  } catch (_error) {
    return false;
  }
}

function markAppInstalledOnDevice(installed = true) {
  try {
    if (installed) {
      localStorage.setItem(PWA_INSTALLED_MARKER_KEY, '1');
    } else {
      localStorage.removeItem(PWA_INSTALLED_MARKER_KEY);
    }
  } catch (_error) {}
}

function isAppConsideredInstalled() {
  return isStandaloneDisplayMode();
}

function isPrivateIpv4Address(hostname) {
  const match = String(hostname || '').match(/^(\d+)\.(\d+)\.(\d+)\.(\d+)$/);
  if (!match) return false;
  const a = Number(match[1]);
  const b = Number(match[2]);
  return a === 10
    || a === 127
    || (a === 192 && b === 168)
    || (a === 172 && b >= 16 && b <= 31);
}

function isLocalAppServerContext() {
  const hostname = String(window.location.hostname || '').trim().toLowerCase();
  if (!hostname) return false;
  return hostname === 'localhost'
    || hostname === '::1'
    || hostname.endsWith('.localhost')
    || hostname.endsWith('.local')
    || isPrivateIpv4Address(hostname);
}

function shouldUpgradeToHttpsForPwa() {
  return window.location.protocol === 'http:'
    && !isLocalAppServerContext()
    && Boolean(window.location.hostname);
}

function upgradeToHttpsForPwa() {
  if (!shouldUpgradeToHttpsForPwa()) {
    return false;
  }

  const secureUrl = new URL(window.location.href);
  secureUrl.protocol = 'https:';
  window.location.replace(secureUrl.href);
  return true;
}

function persistVersionInfo(versionInfo) {
  if (!versionInfo || typeof versionInfo !== 'object') {
    return;
  }

  pwaState.latestVersionInfo = versionInfo;

  const label = String(versionInfo.display_version || versionInfo.version || '').trim();
  const buildId = String(versionInfo.build || '').trim();
  const channel = String(versionInfo.channel || '').trim();
  const schemaVersion = String(versionInfo.schema_version || '').trim();
  const cacheVersion = String(versionInfo.cache_version || '').trim();
  const buildFingerprint = String(versionInfo.build_fingerprint || '').trim();

  if (label) {
    localStorage.setItem(VERSION_LABEL_STORAGE_KEY, label);
  }
  if (buildId) {
    localStorage.setItem(VERSION_BUILD_STORAGE_KEY, buildId);
  }
  if (channel) {
    localStorage.setItem(VERSION_CHANNEL_STORAGE_KEY, channel);
  }
  if (schemaVersion) {
    localStorage.setItem(VERSION_SCHEMA_STORAGE_KEY, schemaVersion);
  }
  // Important: do not persist the active fingerprint/cache marker here.
  // This function is called for "latest known version" metadata fetched from
  // the server. If we overwrite the installed/current fingerprint at fetch
  // time, the app stops recognizing genuine new updates because the
  // comparison baseline moves forward too early.
  void cacheVersion;
  void buildFingerprint;
}

function getStoredVersionInfo() {
  const version = localStorage.getItem(VERSION_LABEL_STORAGE_KEY) || '';
  const build = localStorage.getItem(VERSION_BUILD_STORAGE_KEY) || '';
  const channel = localStorage.getItem(VERSION_CHANNEL_STORAGE_KEY) || '';
  const schemaVersion = localStorage.getItem(VERSION_SCHEMA_STORAGE_KEY) || '';
  const cacheVersion = localStorage.getItem(VERSION_CACHE_STORAGE_KEY) || '';
  const buildFingerprint = localStorage.getItem(VERSION_BUILD_FINGERPRINT_STORAGE_KEY) || '';

  if (!version && !build && !channel && !schemaVersion && !cacheVersion && !buildFingerprint) {
    return null;
  }

  return {
    version,
    display_version: version,
    build,
    channel,
    schema_version: schemaVersion,
    cache_version: cacheVersion,
    build_fingerprint: buildFingerprint
  };
}

function getVersionFingerprint(versionInfo) {
  if (!versionInfo || typeof versionInfo !== 'object') {
    return null;
  }

  return versionInfo.cache_version
    || versionInfo.build_fingerprint
    || versionInfo.build
    || versionInfo.version
    || null;
}

function fingerprintMatchesVersionInfo(fingerprint, versionInfo) {
  const candidate = String(fingerprint || '').trim();
  if (!candidate || !versionInfo || typeof versionInfo !== 'object') {
    return false;
  }

  const knownValues = [
    versionInfo.cache_version,
    versionInfo.build_fingerprint,
    versionInfo.build,
    versionInfo.version
  ]
    .map((value) => String(value || '').trim())
    .filter(Boolean);

  return knownValues.includes(candidate);
}

function persistResolvedFingerprint(fingerprint, versionInfo = null) {
  const resolved = String(fingerprint || '').trim();
  if (!resolved) {
    return;
  }

  localStorage.setItem(VERSION_STORAGE_KEY, resolved);
  localStorage.setItem(VERSION_CACHE_STORAGE_KEY, resolved);

  const buildFingerprint = String(versionInfo?.build_fingerprint || '').trim();
  if (buildFingerprint) {
    localStorage.setItem(VERSION_BUILD_FINGERPRINT_STORAGE_KEY, buildFingerprint);
  }
}

function buildUpdateMessage(versionInfo) {
  const displayVersion = String(versionInfo?.display_version || versionInfo?.version || '').trim();
  const buildId = String(versionInfo?.build || '').trim();
  const releaseBits = [];

  if (displayVersion) {
    releaseBits.push(`Version ${displayVersion}`);
  }
  if (buildId) {
    releaseBits.push(`build ${buildId}`);
  }

  const releaseLabel = releaseBits.length ? releaseBits.join(' - ') : 'A new version';
  return `${releaseLabel} of UPS PensionsGo is available. Install the update now? The app will refresh once to finish the installation.`;
}

function rememberInstalledUpdateFingerprint(fingerprint) {
  const resolved = String(fingerprint || '').trim();
  if (!resolved) {
    return;
  }

  try {
    sessionStorage.setItem(INSTALLED_UPDATE_STORAGE_KEY, resolved);
  } catch (_error) {}

  try {
    localStorage.setItem(INSTALLED_UPDATE_STORAGE_KEY, resolved);
  } catch (_error) {}
}

function getInstalledUpdateFingerprint() {
  try {
    const sessionValue = sessionStorage.getItem(INSTALLED_UPDATE_STORAGE_KEY);
    if (sessionValue) {
      return sessionValue;
    }
  } catch (_error) {}

  try {
    return localStorage.getItem(INSTALLED_UPDATE_STORAGE_KEY);
  } catch (_error) {
    return null;
  }
}

function clearInstalledUpdateFingerprint(expectedFingerprint = null) {
  const expected = String(expectedFingerprint || '').trim();
  const maybeClear = (storage) => {
    try {
      const current = storage.getItem(INSTALLED_UPDATE_STORAGE_KEY);
      if (!current) {
        return;
      }
      if (!expected || current === expected) {
        storage.removeItem(INSTALLED_UPDATE_STORAGE_KEY);
      }
    } catch (_error) {}
  };

  maybeClear(sessionStorage);
  maybeClear(localStorage);
}

async function promptForPendingUpdate(registration, options = {}) {
  const fingerprint = options.fingerprint || pwaState.pendingUpdateFingerprint || null;
  const versionInfo = options.versionInfo || pwaState.pendingVersionInfo || pwaState.latestVersionInfo || getStoredVersionInfo();

  if (!options.forcePrompt && fingerprint) {
    try {
      if (sessionStorage.getItem(DISMISSED_UPDATE_STORAGE_KEY) === fingerprint) {
        return false;
      }
    } catch (_error) {}
  }

  if (pwaState.updatePromptOpen) {
    return false;
  }

  pwaState.updatePromptOpen = true;

  let accepted = false;
  try {
    const message = buildUpdateMessage(versionInfo);
    accepted = typeof window.appConfirm === 'function'
      ? await window.appConfirm(message, {
          title: 'Update Available',
          confirmText: 'Install Update',
          cancelText: 'Later'
        })
      : window.confirm(message);
  } finally {
    pwaState.updatePromptOpen = false;
  }

  if (accepted) {
    try {
      sessionStorage.removeItem(DISMISSED_UPDATE_STORAGE_KEY);
    } catch (_error) {}
    return installPendingUpdate(registration, { fingerprint, versionInfo });
  }

  if (fingerprint) {
    try {
      sessionStorage.setItem(DISMISSED_UPDATE_STORAGE_KEY, fingerprint);
    } catch (_error) {}
  }

  if (typeof window.appToast === 'function') {
    window.appToast('Update postponed. You can install it later when you are ready.', {
      type: 'info',
      title: 'Update Available',
      duration: 3200
    });
  }

  return false;
}

async function installPendingUpdate(registration, options = {}) {
  const targetRegistration = registration || pwaState.registration;
  const fingerprint = options.fingerprint || pwaState.pendingUpdateFingerprint || null;
  const versionInfo = options.versionInfo || pwaState.pendingVersionInfo || pwaState.latestVersionInfo || null;
  const resolvedFingerprint = fingerprint || getVersionFingerprint(versionInfo);

  if (versionInfo) {
    persistVersionInfo(versionInfo);
  }

  if (resolvedFingerprint) {
    persistResolvedFingerprint(resolvedFingerprint, versionInfo);
    rememberInstalledUpdateFingerprint(resolvedFingerprint);
  }
  pwaState.pendingUpdateFingerprint = resolvedFingerprint;
  pwaState.pendingVersionInfo = versionInfo;
  pwaState.pendingUpdateReason = '';
  try {
    sessionStorage.removeItem(DISMISSED_UPDATE_STORAGE_KEY);
  } catch (_error) {}

  if (typeof window.appToast === 'function') {
    window.appToast('Installing update...', {
      type: 'info',
      title: 'Updating',
      duration: 2200
    });
  }

  await clearAppCaches();

  if (targetRegistration) {
    try {
      await targetRegistration.update();
    } catch (error) {
      console.warn('PWA update install check failed:', error);
    }
  }

  pwaState.isRefreshing = true;
  pwaState.skipControllerReload = true;

  if (targetRegistration?.waiting) {
    targetRegistration.waiting.postMessage({ type: 'SKIP_WAITING' });
  } else if (targetRegistration?.installing) {
    const installingWorker = targetRegistration.installing;
    await new Promise((resolve) => {
      const fallbackTimer = window.setTimeout(resolve, 1500);
      installingWorker.addEventListener('statechange', () => {
        if (installingWorker.state === 'installed' && targetRegistration.waiting) {
          targetRegistration.waiting.postMessage({ type: 'SKIP_WAITING' });
          window.clearTimeout(fallbackTimer);
          resolve();
        } else if (installingWorker.state === 'redundant') {
          window.clearTimeout(fallbackTimer);
          resolve();
        }
      });
    });
  }

  window.setTimeout(() => {
    window.location.reload();
  }, 700);

  return true;
}

async function announceAvailableUpdate(registration, options = {}) {
  const fingerprint = options.fingerprint || pwaState.pendingUpdateFingerprint || null;
  const versionInfo = options.versionInfo || pwaState.pendingVersionInfo || pwaState.latestVersionInfo || getStoredVersionInfo();

  pwaState.pendingUpdateFingerprint = fingerprint;
  pwaState.pendingVersionInfo = versionInfo;
  pwaState.pendingUpdateReason = String(options.reason || '');

  if (options.forceReload) {
    return installPendingUpdate(registration, { fingerprint, versionInfo });
  }

  return promptForPendingUpdate(registration, {
    fingerprint,
    versionInfo,
    forcePrompt: Boolean(options.forcePrompt)
  });
}

async function fetchVersionInfo() {
  try {
    const url = new URL(PWA_VERSION_INFO_URL.toString());
    url.searchParams.set('sw-bypass', '1');
    url.searchParams.set('ts', Date.now().toString());

    const response = await fetch(url.toString(), { cache: 'no-store' });
    if (!response.ok) return null;

    const payload = await response.json();
    const versionInfo = payload && payload.success && payload.version && typeof payload.version === 'object'
      ? payload.version
      : null;

    if (versionInfo) {
      persistVersionInfo(versionInfo);
    }

    return versionInfo;
  } catch (_error) {
    return null;
  }
}

async function fetchAppFingerprint() {
  const versionInfo = await fetchVersionInfo();
  if (versionInfo) {
    return versionInfo.cache_version
      || versionInfo.build_fingerprint
      || versionInfo.build
      || versionInfo.version
      || null;
  }

  try {
    const url = new URL(PWA_VERSION_CHECK_URL.toString());
    url.searchParams.set('sw-bypass', '1');
    url.searchParams.set('ts', Date.now().toString());

    const response = await fetch(url.toString(), { cache: 'no-store' });
    if (!response.ok) return null;
    const etag = response.headers.get('etag');
    const lastModified = response.headers.get('last-modified');
    let text = '';
    try {
      text = await response.text();
    } catch (_error) {
      text = '';
    }

    if (text) {
      const appMatch = text.match(/PWA_APP_VERSION\s*=\s*['"]([^'"]+)['"]/);
      if (appMatch?.[1]) {
        localStorage.setItem(VERSION_LABEL_STORAGE_KEY, appMatch[1]);
      }

      const buildMatch = text.match(/PWA_BUILD_ID\s*=\s*['"]([^'"]+)['"]/);
      if (buildMatch?.[1]) {
        localStorage.setItem(VERSION_BUILD_STORAGE_KEY, buildMatch[1]);
      }

      const channelMatch = text.match(/PWA_RELEASE_CHANNEL\s*=\s*['"]([^'"]+)['"]/);
      if (channelMatch?.[1]) {
        localStorage.setItem(VERSION_CHANNEL_STORAGE_KEY, channelMatch[1]);
      }

      const schemaMatch = text.match(/PWA_SCHEMA_VERSION\s*=\s*['"]([^'"]+)['"]/);
      if (schemaMatch?.[1]) {
        localStorage.setItem(VERSION_SCHEMA_STORAGE_KEY, schemaMatch[1]);
      }

      const buildFingerprintMatch = text.match(/PWA_BUILD_VERSION\s*=\s*['"]([^'"]+)['"]/);
      void buildFingerprintMatch;

      const cacheVersionMatch = text.match(/PWA_CACHE_VERSION\s*=\s*['"]([^'"]+)['"]/);
      void cacheVersionMatch;
    }

    const match = text ? text.match(/PWA_CACHE_VERSION\s*=\s*['"]([^'"]+)['"]/) : null;
    const legacyMatch = text ? text.match(/PWA_BUILD_VERSION\s*=\s*['"]([^'"]+)['"]/) : null;
    return etag || lastModified || (match ? match[1] : (legacyMatch ? legacyMatch[1] : null));
  } catch (error) {
    return null;
  }
}

async function clearAppCaches() {
  if (!('caches' in window)) return;
  const keys = await caches.keys();
  await Promise.all(keys.map((key) => caches.delete(key)));
}

async function checkForPwaUpdates(registration, options = {}) {
  if (!registration) return;

  const { skipReload = false, forceReload = false } = options;

  if (pwaState.updateInFlight) {
    return pwaState.updateInFlight;
  }

  pwaState.updateInFlight = (async () => {
    try {
      await registration.update();
    } catch (error) {
      console.warn('PWA update check failed:', error);
    }

    const fingerprint = await fetchAppFingerprint();
    const versionInfo = pwaState.latestVersionInfo || getStoredVersionInfo();
    const effectiveFingerprint = fingerprint || getVersionFingerprint(versionInfo);
    const installedFingerprint = getInstalledUpdateFingerprint();

    if (effectiveFingerprint && (
      installedFingerprint === effectiveFingerprint
      || fingerprintMatchesVersionInfo(installedFingerprint, versionInfo)
    )) {
      persistResolvedFingerprint(effectiveFingerprint, versionInfo);
      rememberInstalledUpdateFingerprint(effectiveFingerprint);
      pwaState.pendingUpdateFingerprint = null;
      pwaState.pendingVersionInfo = versionInfo;
      pwaState.pendingUpdateReason = '';

      if (registration.waiting) {
        try {
          registration.waiting.postMessage({ type: 'SKIP_WAITING' });
        } catch (_error) {}
        return false;
      }

      if (!forceReload) {
        return;
      }
    }

    if (registration.waiting) {
      return announceAvailableUpdate(registration, {
        fingerprint: effectiveFingerprint,
        versionInfo,
        forceReload,
        forcePrompt: Boolean(options.forcePrompt),
        reason: options.reason || 'waiting_worker'
      });
    }

    if (!effectiveFingerprint) {
      if (forceReload) {
        return installPendingUpdate(registration, {
          fingerprint: null,
          versionInfo
        });
      }
      return;
    }

    const previous = localStorage.getItem(VERSION_STORAGE_KEY);
    if (!previous) {
      persistResolvedFingerprint(effectiveFingerprint, versionInfo);
      return;
    }

    if (previous === effectiveFingerprint && !forceReload) {
      return;
    }

    if (forceReload) {
      return installPendingUpdate(registration, {
        fingerprint: effectiveFingerprint,
        versionInfo
      });
    }

    if (skipReload) {
      return false;
    }

    return announceAvailableUpdate(registration, {
      fingerprint: effectiveFingerprint,
      versionInfo,
      reason: options.reason || 'fingerprint_changed'
    });
  })();

  try {
    return await pwaState.updateInFlight;
  } finally {
    pwaState.updateInFlight = null;
  }
}

function ensureUpdateManager(registration) {
  if (registration) {
    pwaState.registration = registration;
  }

  if (!window.PwaUpdateManager) {
    window.PwaUpdateManager = {};
  }

  window.PwaUpdateManager.check = (options = {}) => (
    checkForPwaUpdates(pwaState.registration || registration, options)
  );
  window.PwaUpdateManager.applyPendingUpdate = (options = {}) => (
    installPendingUpdate(pwaState.registration || registration, options)
  );

  if (!pwaState.updateCheckBound) {
    pwaState.updateCheckBound = true;
    document.addEventListener('pwa:check-update', (event) => {
      const options = event?.detail && typeof event.detail === 'object' ? event.detail : {};
      checkForPwaUpdates(pwaState.registration || registration, options);
    });
  }

  if (!pwaState.launchCheckCompleted) {
    pwaState.launchCheckCompleted = true;
    checkForPwaUpdates(pwaState.registration || registration, { reason: 'launch' });
  }

  if (window.__pendingPwaUpdateCheck) {
    const pending = window.__pendingPwaUpdateCheck;
    window.__pendingPwaUpdateCheck = null;
    checkForPwaUpdates(pwaState.registration || registration, pending);
  }
}

function ensureHeadTag(selector, factory) {
  let node = document.head.querySelector(selector);
  if (node) return node;
  node = factory();
  document.head.appendChild(node);
  return node;
}

function absolutizeManifestUrl(value) {
  const text = String(value || '').trim();
  if (!text) return text;
  try {
    return new URL(text, PWA_APP_ROOT).href;
  } catch (_error) {
    return text;
  }
}

function normalizeManifestForBlob(manifest) {
  const normalized = { ...(manifest || {}) };
  ['start_url', 'scope'].forEach((key) => {
    if (normalized[key]) normalized[key] = absolutizeManifestUrl(normalized[key]);
  });
  ['icons', 'screenshots'].forEach((key) => {
    if (!Array.isArray(normalized[key])) return;
    normalized[key] = normalized[key].map((item) => ({
      ...item,
      src: item?.src ? absolutizeManifestUrl(item.src) : item?.src
    }));
  });
  if (Array.isArray(normalized.shortcuts)) {
    normalized.shortcuts = normalized.shortcuts.map((shortcut) => ({
      ...shortcut,
      url: absolutizeManifestUrl(shortcut?.url || './'),
      icons: Array.isArray(shortcut?.icons)
        ? shortcut.icons.map((icon) => ({ ...icon, src: icon?.src ? absolutizeManifestUrl(icon.src) : icon?.src }))
        : shortcut?.icons
    }));
  }
  return normalized;
}

function buildInlineManifestUrl(manifest) {
  const json = JSON.stringify(normalizeManifestForBlob(manifest));
  const blob = new Blob([json], { type: 'application/manifest+json' });
  return URL.createObjectURL(blob);
}

async function fetchValidatedManifest() {
  const response = await fetch(MANIFEST_DIAGNOSTIC_URL.href, {
    cache: 'no-store',
    credentials: 'same-origin'
  });
  const contentType = String(response.headers.get('content-type') || '').toLowerCase();
  const body = await response.text();
  const startsLikeJson = /^\s*[\[{]/.test(body);
  if (!response.ok || !startsLikeJson) {
    const preview = body.replace(/\s+/g, ' ').slice(0, 140);
    throw new Error(`Manifest returned ${response.status} ${contentType || 'unknown type'}: ${preview || 'empty response'}`);
  }
  return JSON.parse(body);
}

async function ensureManifestAndIcons() {
  document.head.querySelectorAll('link[rel="manifest"]').forEach((link) => link.remove());
  const manifestLink = ensureHeadTag('link[rel="manifest"]', () => {
    const link = document.createElement('link');
    link.rel = 'manifest';
    return link;
  });
  try {
    const manifest = await fetchValidatedManifest();
    if (pwaState.inlineManifestUrl) URL.revokeObjectURL(pwaState.inlineManifestUrl);
    pwaState.inlineManifestUrl = buildInlineManifestUrl(manifest);
    manifestLink.href = pwaState.inlineManifestUrl;
  } catch (error) {
    manifestLink.remove();
    console.warn('PWA manifest unavailable or invalid:', error.message || error);
  }

  ensureHeadTag('meta[name="theme-color"]', () => {
    const meta = document.createElement('meta');
    meta.name = 'theme-color';
    meta.content = '#6d1116';
    return meta;
  });

  ensureHeadTag('link[rel="apple-touch-icon"]', () => {
    const link = document.createElement('link');
    link.rel = 'apple-touch-icon';
    link.href = PWA_APPLE_ICON_PATH;
    return link;
  });
}

function ensureOfflineBanner() {
  let banner = document.getElementById('pwaOfflineBanner');
  if (!banner) {
    const detailText = isLocalAppServerContext()
      ? 'Internet is unavailable. If your local server is still reachable, you can keep using live app features through localhost/XAMPP.'
      : 'Cached pages remain available. Live workflow updates and server actions will resume when the connection returns.';
    const compactText = isLocalAppServerContext()
      ? 'Local'
      : 'Cached';
    banner = document.createElement('div');
    banner.id = 'pwaOfflineBanner';
    banner.className = 'pwa-offline-banner hidden';
    banner.setAttribute('role', 'status');
    banner.setAttribute('aria-live', 'polite');
    banner.setAttribute('title', detailText);
    banner.setAttribute('aria-label', `Offline mode. ${detailText}`);
    banner.innerHTML = `
      <div class="pwa-offline-banner-inner">
        <strong>Offline</strong>
        <span>${compactText}</span>
      </div>
    `;
    document.body.appendChild(banner);
  }

  pwaState.offlineBanner = banner;
  return banner;
}

async function canReachAppShell() {
  if (!window.isSecureContext && !isLocalAppServerContext()) {
    return navigator.onLine;
  }

  let timeoutId = null;
  try {
    const controller = new AbortController();
    timeoutId = window.setTimeout(() => controller.abort(), 7000);
    const probeUrl = new URL(PWA_CONNECTIVITY_PROBE_URL.href);
    probeUrl.searchParams.set('connectivity_probe', Date.now().toString());
    const response = await fetch(probeUrl.href, {
      method: 'GET',
      cache: 'no-store',
      signal: controller.signal
    });
    return response.ok;
  } catch (_error) {
    return false;
  } finally {
    if (timeoutId) {
      window.clearTimeout(timeoutId);
    }
  }
}

function applyOfflineBannerState(offline) {
  const banner = ensureOfflineBanner();
  banner.classList.toggle('hidden', !offline);
  document.body.classList.toggle('pwa-is-offline', offline);

  if (offline) {
    pwaState.wasOffline = true;
    return;
  }

  if (pwaState.wasOffline && typeof window.appToast === 'function') {
    window.appToast('Connection restored. Live data sync is available again.', {
      type: 'success',
      title: 'Back Online',
      duration: 2800
    });
  }
  pwaState.wasOffline = false;
}

function updateOfflineBanner() {
  const updateToken = ++pwaState.offlineBannerUpdateToken;

  if (!navigator.onLine) {
    applyOfflineBannerState(true);
    return;
  }

  canReachAppShell().then((reachable) => {
    if (updateToken !== pwaState.offlineBannerUpdateToken) {
      return;
    }
    applyOfflineBannerState(!reachable);
  });
}

function clearInstallPromptResolutionTimer() {
  if (!pwaState.installPromptResolutionTimer) {
    return;
  }
  window.clearTimeout(pwaState.installPromptResolutionTimer);
  pwaState.installPromptResolutionTimer = null;
}

function removeCustomInstallEntryPoints() {
  clearInstallPromptResolutionTimer();
  pwaState.deferredInstallPrompt = null;
  pwaState.installPromptCapabilityResolved = true;
  pwaState.browserManagedInstallPrompt = true;

  if (pwaState.installButtonObserver) {
    pwaState.installButtonObserver.disconnect();
    pwaState.installButtonObserver = null;
  }

  const installButton = document.getElementById('pwaInstallButton');
  if (installButton) {
    installButton.remove();
  }

  pwaState.installButton = null;
}

function bindNativeInstallLifecycle() {
  removeCustomInstallEntryPoints();

  window.addEventListener('beforeinstallprompt', () => {
    // Let the browser keep full control of the native PWA install UX.
    markAppInstalledOnDevice(false);
    pwaState.deferredInstallPrompt = null;
    pwaState.browserManagedInstallPrompt = true;
  });

  window.addEventListener('appinstalled', () => {
    markAppInstalledOnDevice(true);
    pwaState.deferredInstallPrompt = null;
    pwaState.browserManagedInstallPrompt = true;
  });

  window.matchMedia?.('(display-mode: standalone)')?.addEventListener?.('change', () => {
    document.body?.classList?.toggle('pwa-standalone-active', isStandaloneDisplayMode());
  });
}

function exposePwaDiagnostics() {
  window.PensionsGoPwaDiagnostics = {
    getState() {
      const button = document.getElementById('pwaInstallButton');
      return {
        href: window.location.href,
        secureContext: window.isSecureContext,
        standalone: isStandaloneDisplayMode(),
        storedInstalledMarker: hasInstalledMarker(),
        hasNativeInstallPrompt: Boolean(pwaState.deferredInstallPrompt),
        installPromptCapabilityResolved: pwaState.installPromptCapabilityResolved,
        browserManagedInstallPrompt: pwaState.browserManagedInstallPrompt,
        manifest: document.querySelector('link[rel="manifest"]')?.href || '',
        serviceWorkerController: navigator.serviceWorker?.controller?.scriptURL || '',
        serviceWorkerScope: pwaState.registration?.scope || '',
        installButton: button ? {
          hidden: button.classList.contains('hidden'),
          mode: button.dataset.installMode || '',
          title: button.title || '',
          text: button.textContent || ''
        } : null
      };
    },
    clearInstallMarker() {
      markAppInstalledOnDevice(false);
      removeCustomInstallEntryPoints();
      return this.getState();
    }
  };
}

async function registerServiceWorker() {
  if (!window.isSecureContext || !('serviceWorker' in navigator)) {
    return null;
  }

  try {
    const registration = await navigator.serviceWorker.register(PWA_SERVICE_WORKER_PATH, {
      scope: PWA_SERVICE_WORKER_SCOPE,
      updateViaCache: 'none'
    });
    const removedStaleRegistration = await cleanupStalePwaRegistrations(registration);
    if (removedStaleRegistration && shouldRefreshAfterScopeMigration(registration)) {
      markScopeRefreshAttempted();
      window.location.reload();
      return registration;
    }
    if (await shouldRefreshForServiceWorkerControl(registration)) {
      markControllerRefreshAttempted();
      window.location.reload();
      return registration;
    }

    const triggerUpdateActivation = async () => {
      if (!registration.waiting) return;

      const pendingVersionInfo = await fetchVersionInfo() || pwaState.latestVersionInfo || getStoredVersionInfo();
      const pendingFingerprint = pwaState.pendingUpdateFingerprint
        || getVersionFingerprint(pendingVersionInfo)
        || localStorage.getItem(VERSION_STORAGE_KEY);

      if (pendingFingerprint && (
        getInstalledUpdateFingerprint() === pendingFingerprint
        || fingerprintMatchesVersionInfo(getInstalledUpdateFingerprint(), pendingVersionInfo)
      )) {
        try {
          registration.waiting.postMessage({ type: 'SKIP_WAITING' });
        } catch (_error) {}
        persistResolvedFingerprint(pendingFingerprint, pendingVersionInfo);
        rememberInstalledUpdateFingerprint(pendingFingerprint);
        return;
      }

      announceAvailableUpdate(registration, {
        fingerprint: pendingFingerprint,
        versionInfo: pendingVersionInfo,
        reason: 'waiting_worker'
      });
    };

    navigator.serviceWorker.addEventListener('controllerchange', () => {
      if (pwaState.skipControllerReload) {
        pwaState.skipControllerReload = false;
        return;
      }
      if (!pwaState.isRefreshing) {
        return;
      }
    });

    registration.addEventListener('updatefound', () => {
      const worker = registration.installing;
      if (!worker) return;

      worker.addEventListener('statechange', () => {
        if (worker.state === 'installed' && navigator.serviceWorker.controller) {
          triggerUpdateActivation();
        }
      });
    });

    if (registration.waiting) {
      triggerUpdateActivation();
    }

    ensureUpdateManager(registration);
    return registration;
  } catch (error) {
    console.warn('Service worker registration failed:', error.message || error);
    return null;
  }
}

export async function initPwaShell() {
  if (upgradeToHttpsForPwa()) {
    return null;
  }

  exposePwaDiagnostics();
  await ensureManifestAndIcons();
  ensureOfflineBanner();
  bindNativeInstallLifecycle();
  updateOfflineBanner();
  window.addEventListener('online', updateOfflineBanner);
  window.addEventListener('offline', updateOfflineBanner);
  const registration = await registerServiceWorker();
  if (!registration && 'serviceWorker' in navigator) {
    navigator.serviceWorker.ready
      .then((readyRegistration) => ensureUpdateManager(readyRegistration))
      .catch(() => {});
  }
  removeCustomInstallEntryPoints();
  return registration;
}

async function cleanupStalePwaRegistrations(activeRegistration) {
  if (!activeRegistration || !navigator.serviceWorker?.getRegistrations) {
    return false;
  }

  const scriptUrl = new URL(PWA_SERVICE_WORKER_PATH, window.location.origin).href;
  const appScope = new URL(PWA_SERVICE_WORKER_SCOPE, window.location.origin).href;
  let removed = false;

  try {
    const registrations = await navigator.serviceWorker.getRegistrations();
    await Promise.all(registrations.map(async (registration) => {
      if (registration.scope === activeRegistration.scope) {
        return;
      }

      const workerScript = registration.active?.scriptURL
        || registration.waiting?.scriptURL
        || registration.installing?.scriptURL
        || '';
      const isPensionsGoWorker = workerScript === scriptUrl;
      const isRelatedScope = registration.scope === appScope
        || appScope.startsWith(registration.scope)
        || registration.scope.startsWith(appScope);

      if (!isPensionsGoWorker || !isRelatedScope) {
        return;
      }

      removed = (await registration.unregister()) || removed;
    }));
  } catch (error) {
    console.warn('PWA scope migration cleanup failed:', error.message || error);
  }

  return removed;
}

function hasScopeRefreshBeenAttempted() {
  try {
    return sessionStorage.getItem(PWA_SCOPE_REFRESH_KEY) === '1';
  } catch (_error) {
    return true;
  }
}

function markScopeRefreshAttempted() {
  try {
    sessionStorage.setItem(PWA_SCOPE_REFRESH_KEY, '1');
  } catch (_error) {}
}

function shouldRefreshAfterScopeMigration(registration) {
  if (hasScopeRefreshBeenAttempted()) {
    return false;
  }

  const controllerScript = navigator.serviceWorker.controller?.scriptURL || '';
  const expectedScript = new URL(PWA_SERVICE_WORKER_PATH, window.location.origin).href;
  const expectedScope = new URL(PWA_SERVICE_WORKER_SCOPE, window.location.origin).href;
  return !controllerScript || controllerScript === expectedScript || registration.scope !== expectedScope;
}

function hasControllerRefreshBeenAttempted() {
  try {
    return sessionStorage.getItem(PWA_CONTROLLER_REFRESH_KEY) === '1';
  } catch (_error) {
    return true;
  }
}

function markControllerRefreshAttempted() {
  try {
    sessionStorage.setItem(PWA_CONTROLLER_REFRESH_KEY, '1');
  } catch (_error) {}
}

async function shouldRefreshForServiceWorkerControl(registration) {
  if (navigator.serviceWorker.controller || hasControllerRefreshBeenAttempted()) {
    return false;
  }

  try {
    const currentUrl = new URL(window.location.href);
    if (!currentUrl.href.startsWith(registration.scope)) {
      return false;
    }

    await navigator.serviceWorker.ready;
    await new Promise((resolve) => window.setTimeout(resolve, 600));
    return !navigator.serviceWorker.controller;
  } catch (_error) {
    return false;
  }
}
