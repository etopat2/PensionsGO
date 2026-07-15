const FALLBACK_APP_VERSION = '1.0.0-dev';
const ASSET_REVISION = '20260714n';
try {
  importScripts('../backend/api/pwa_version.php');
} catch (error) {
  // Ignore version script failures; fallback handles cache naming.
}
const APP_VERSION = `ups-pensionsgo-pwa-${self.PWA_CACHE_VERSION || self.PWA_BUILD_VERSION || FALLBACK_APP_VERSION}-${ASSET_REVISION}`;
const STATIC_CACHE = `${APP_VERSION}-static`;
const RUNTIME_CACHE = `${APP_VERSION}-runtime`;
const OFFLINE_URL = './offline.html';

const PRECACHE_URLS = [
  './',
  './offline.html',
  './login.html',
  './index.html',
  './about.html',
  './faq.html',
  './benefits_calculator.html',
  './application_status.html',
  './dashboard.html',
  './document_viewer.html',
  './admin_dashboard.html',
  './tasks.html',
  './staff_due.html',
  './pension_file_registry.html',
  './claims.html',
  './budgeting.html',
  './claim_form.html',
  './payroll_upload.html',
  './file_tracking.html',
  './messages.html',
  './profile.html',
  './users.html',
  './pensioner_board.html',
  './pensioner_lookup.html',
  './podcast.html',
  './header1.html',
  './header2.html',
  './footer.html',
  './footer1.html',
  './manifest.webmanifest',
  './css/styles.css',
  './css/overlay.css',
  './css/forms.css',
  './css/calculator.css',
  './css/track-status.css',
  './css/auth.css',
  './css/faq.css',
  './css/menu.css',
  './css/dashboard.css',
  './css/document_viewer.css',
  './css/admin-dashboard.css',
  './css/claims.css',
  './css/pension_file_registry.css',
  './css/pensioner_board.css',
  './css/pensioner_lookup.css',
  './css/podcast.css',
  './css/profile.css',
  './css/messages.css',
  './css/live_chat.css',
  './css/tasks.css',
  './css/users.css',
  './css/payroll_upload.css',
  './css/file_tracking.css',
  './css/ui_feedback.css',
  './css/broadcast_popup.css',
  './js/auth_bootstrap.js',
  './js/main.js',
  './js/auth.js?v=20260606b',
  './js/faq.js',
  './js/calculator.js',
  './js/track-status.js',
  './js/menu_toggle.js',
  './js/toggle-sections.js',
  './js/dashboard.js',
  './js/document_viewer.js',
  './js/admin-dashboard.js',
  './js/claims.js',
  './js/claim_form.js',
  './js/budgeting.js',
  './js/tasks.js',
  './js/pension_file_registry.js',
  './js/pensioner_board.js',
  './js/pensioner_lookup.js',
  './js/podcast.js',
  './js/about.js',
  './js/profile.js',
  './js/users.js',
  './js/file_tracking.js',
  './js/payroll_upload.js',
  './js/messages.js',
  './js/modules/district_selector.js',
  './js/modules/filterable_select.js',
  './js/modules/footer.js',
  './js/modules/header.js',
  './js/modules/header_interactions.js',
  './js/modules/session-worker.js',
  './js/modules/ui_feedback.js',
  './js/modules/pwa.js',
  './js/modules/chat_shared.js',
  './js/modules/live_chat.js',
  './images/default-user.png',
  './assets/favicon.ico',
  './assets/logo.png',
  './assets/pwa/icon-192.png',
  './assets/pwa/icon-512.png',
  './assets/pwa/icon.svg',
  './assets/pwa/apple-touch-icon.png',
  './audio/notification.mp3',
  './audio/notification.wav'
];

function normalizedCacheUrl(input) {
  const url = new URL(typeof input === 'string' ? input : input.url, self.location.origin);

  if (url.origin === self.location.origin) {
    url.hash = '';
    if (!url.pathname.includes('/backend/') && !/\.(?:js|css)$/i.test(url.pathname)) {
      url.search = '';
    }
  }

  return url.toString();
}

function shouldSkipCache(request, response) {
  if (request.headers.has('range')) {
    return true;
  }

  if (!response) {
    return true;
  }

  return response.status === 206;
}

async function isUnexpectedHtmlAssetResponse(request, response) {
  const url = new URL(request.url);
  const contentType = String(response.headers.get('content-type') || '').toLowerCase();
  const expectsHtmlFragment = /\/(?:header1|header2|footer|footer1|offline|login|index)\.html$/i.test(url.pathname)
    || url.pathname.endsWith('/');

  if (!expectsHtmlFragment) {
    return false;
  }

  if (!contentType.includes('text/html')) {
    return true;
  }

  try {
    const text = await response.clone().text();
    return /you are about to visit/i.test(text)
      && /ngrok/i.test(text)
      && !/<(?:header|footer|main|body|html)[\s>]/i.test(text);
  } catch (_error) {
    return false;
  }
}

function hasExpectedStaticContentType(request, response) {
  const url = new URL(request.url);
  const contentType = String(response.headers.get('content-type') || '').toLowerCase();

  if (/\.webmanifest$/i.test(url.pathname)) {
    return contentType.includes('manifest') || contentType.includes('json');
  }
  if (/\.css$/i.test(url.pathname)) {
    return contentType.includes('text/css');
  }
  if (/\.js$/i.test(url.pathname)) {
    return contentType.includes('javascript') || contentType.includes('ecmascript');
  }
  if (/\.html$/i.test(url.pathname) || url.pathname.endsWith('/')) {
    return contentType.includes('text/html');
  }
  if (/\.(png|jpg|jpeg|gif|svg|ico|webp)$/i.test(url.pathname)) {
    return contentType.startsWith('image/');
  }
  if (/\.(mp3|wav)$/i.test(url.pathname)) {
    return contentType.startsWith('audio/');
  }

  return true;
}

async function canCacheResponse(request, response) {
  if (!response || !response.ok || shouldSkipCache(request, response)) {
    return false;
  }

  if (!hasExpectedStaticContentType(request, response)) {
    return false;
  }

  return !(await isUnexpectedHtmlAssetResponse(request, response));
}

function isSameOriginStaticRequest(request) {
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return false;
  if (url.pathname.includes('/backend/')) return false;
  return /\.(html|css|js|png|jpg|jpeg|gif|svg|ico|webp|mp3|wav|json|webmanifest)$/i.test(url.pathname)
    || url.pathname.endsWith('/')
    || url.pathname.includes('/header')
    || url.pathname.includes('/footer');
}

function isManifestRequest(request) {
  const url = new URL(request.url);
  return url.origin === self.location.origin
    && /\/manifest\.webmanifest$/i.test(url.pathname);
}

function manifestFallbackResponse() {
  return new Response(JSON.stringify({
    name: 'UPS PensionsGo',
    short_name: 'PensionsGo',
    id: './',
    start_url: './login.html',
    scope: './',
    display: 'standalone',
    background_color: '#f4f1eb',
    theme_color: '#6d1116',
    icons: [
      {
        src: './assets/pwa/icon-192.png',
        sizes: '192x192',
        type: 'image/png',
        purpose: 'any maskable'
      },
      {
        src: './assets/pwa/icon-512.png',
        sizes: '512x512',
        type: 'image/png',
        purpose: 'any maskable'
      }
    ]
  }), {
    headers: {
      'Content-Type': 'application/manifest+json; charset=utf-8',
      'Cache-Control': 'no-store'
    }
  });
}

async function manifestResponse(request) {
  const cacheKey = normalizedCacheUrl(request);
  try {
    const response = await fetch(request, { cache: 'no-store' });
    if (await canCacheResponse(request, response)) {
      const runtime = await caches.open(RUNTIME_CACHE);
      runtime.put(cacheKey, response.clone());
      return response;
    }
  } catch (_error) {}

  const cached = await caches.match(cacheKey)
    || await caches.match('./manifest.webmanifest')
    || await caches.match(request, { ignoreSearch: true });
  if (cached && hasExpectedStaticContentType(request, cached)) {
    return cached;
  }
  return manifestFallbackResponse();
}

function isCriticalShellAssetRequest(request) {
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return false;
  if (url.pathname.includes('/backend/')) return false;
  return /\.(js|css|webmanifest)$/i.test(url.pathname)
    || /\/(?:header1|header2|footer|footer1)\.html$/i.test(url.pathname);
}

function shouldBypassCache(request) {
  const url = new URL(request.url);
  if (url.searchParams.has('sw-bypass')) return true;
  if (request.cache === 'reload' || request.cache === 'no-store') return true;
  if (url.origin === self.location.origin && /\/backend\/(?:api|uploads|cache)\//i.test(url.pathname)) return true;
  return false;
}

async function precacheStaticAssets() {
  const cache = await caches.open(STATIC_CACHE);
  const results = await Promise.allSettled(PRECACHE_URLS.map(async (url) => {
    const request = new Request(new URL(url, self.location.href), { cache: 'reload' });
    const response = await fetch(request);

    if (!(await canCacheResponse(request, response))) {
      return;
    }

    await cache.put(normalizedCacheUrl(request), response.clone());
  }));

  const failedCount = results.filter((result) => result.status === 'rejected').length;
  if (failedCount > 0) {
    console.warn(`PWA precache skipped ${failedCount} asset(s); install will continue.`);
  }
}

self.addEventListener('install', (event) => {
  event.waitUntil(
    precacheStaticAssets().then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(
      keys
        .filter((key) => key !== STATIC_CACHE && key !== RUNTIME_CACHE)
        .map((key) => caches.delete(key))
    )).then(() => self.clients.claim())
  );
});

self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});

async function networkFirst(request) {
  const runtime = await caches.open(RUNTIME_CACHE);
  const cacheKey = normalizedCacheUrl(request);

  try {
    const response = await fetch(request);
    if (await canCacheResponse(request, response)) {
      runtime.put(cacheKey, response.clone());
    }
    return response;
  } catch (error) {
    const cached = await runtime.match(cacheKey) || await caches.match(cacheKey) || await caches.match(request, { ignoreSearch: true });
    if (cached) {
      return cached;
    }

    if (request.mode === 'navigate') {
      const offline = await caches.match(OFFLINE_URL);
      if (offline) return offline;
    }

    throw error;
  }
}

async function staleWhileRevalidate(request) {
  const runtime = await caches.open(RUNTIME_CACHE);
  const cacheKey = normalizedCacheUrl(request);
  const cached = await runtime.match(cacheKey) || await caches.match(cacheKey) || await caches.match(request, { ignoreSearch: true });

  const networkPromise = fetch(request)
    .then(async (response) => {
      if (await canCacheResponse(request, response)) {
        runtime.put(cacheKey, response.clone());
      }
      return response;
    })
    .catch(() => cached);

  return cached || networkPromise;
}

self.addEventListener('fetch', (event) => {
  const { request } = event;

  if (request.method !== 'GET') {
    return;
  }

  if (shouldBypassCache(request)) {
    event.respondWith(fetch(request, { cache: 'no-store' }));
    return;
  }

  if (request.headers.has('range')) {
    event.respondWith(fetch(request));
    return;
  }

  if (isManifestRequest(request)) {
    event.respondWith(manifestResponse(request));
    return;
  }

  if (request.mode === 'navigate') {
    event.respondWith(networkFirst(request));
    return;
  }

  if (isCriticalShellAssetRequest(request)) {
    event.respondWith(networkFirst(request));
    return;
  }

  if (isSameOriginStaticRequest(request)) {
    event.respondWith(staleWhileRevalidate(request));
  }
});
