// footer.js - coordinated footer loading with version badge support.

function getFooterVersionEndpoint() {
  return new URL('../backend/api/get_app_version.php', window.location.href);
}

function getStoredVersionInfo() {
  const version = localStorage.getItem('pwaAppVersion') || '';
  const build = localStorage.getItem('pwaAppBuildId') || '';
  const channel = localStorage.getItem('pwaAppChannel') || '';
  const schemaVersion = localStorage.getItem('pwaAppSchemaVersion') || '';

  if (!version && !build && !channel && !schemaVersion) {
    return null;
  }

  return {
    version: version || '--',
    display_version: version || '--',
    build,
    channel,
    schema_version: schemaVersion,
  };
}

function formatFooterVersionLabel(versionInfo) {
  if (!versionInfo || typeof versionInfo !== 'object') {
    return '--';
  }

  const displayVersion = String(versionInfo.display_version || versionInfo.version || '--').trim() || '--';
  const buildId = String(versionInfo.build || '').trim();

  return buildId ? `${displayVersion} - build ${buildId}` : displayVersion;
}

function applyVersionInfoToFooter(badge, valueNode, versionInfo) {
  valueNode.textContent = formatFooterVersionLabel(versionInfo);
  badge.classList.remove('hidden');

  const tooltipBits = [];
  if (versionInfo?.channel) {
    tooltipBits.push(`Channel: ${versionInfo.channel}`);
  }
  if (versionInfo?.schema_version) {
    tooltipBits.push(`Schema: ${versionInfo.schema_version}`);
  }
  if (versionInfo?.release_date) {
    tooltipBits.push(`Release: ${versionInfo.release_date}`);
  }

  badge.title = tooltipBits.join(' | ');
}

async function fetchFooterVersionInfo() {
  try {
    const url = getFooterVersionEndpoint();
    url.searchParams.set('sw-bypass', '1');
    url.searchParams.set('ts', Date.now().toString());

    const response = await fetch(url.toString(), {
      cache: 'no-store',
    });

    if (!response.ok) {
      return null;
    }

    const payload = await response.json();
    const versionInfo = payload && payload.success && payload.version && typeof payload.version === 'object'
      ? payload.version
      : null;

    if (versionInfo) {
      const label = String(versionInfo.display_version || versionInfo.version || '').trim();
      const buildId = String(versionInfo.build || '').trim();
      const channel = String(versionInfo.channel || '').trim();
      const schemaVersion = String(versionInfo.schema_version || '').trim();

      if (label) localStorage.setItem('pwaAppVersion', label);
      if (buildId) localStorage.setItem('pwaAppBuildId', buildId);
      if (channel) localStorage.setItem('pwaAppChannel', channel);
      if (schemaVersion) localStorage.setItem('pwaAppSchemaVersion', schemaVersion);
    }

    return versionInfo;
  } catch (_error) {
    return null;
  }
}

async function initFooterVersionBadge() {
  const badge = document.getElementById('footerBuildBadge');
  const valueNode = document.getElementById('footerBuildVersion');
  if (!badge || !valueNode) return;

  const storedVersionInfo = getStoredVersionInfo();
  if (storedVersionInfo) {
    applyVersionInfoToFooter(badge, valueNode, storedVersionInfo);
  } else {
    badge.classList.add('hidden');
  }

  const liveVersionInfo = await fetchFooterVersionInfo();
  if (liveVersionInfo) {
    applyVersionInfoToFooter(badge, valueNode, liveVersionInfo);
  }
}

function resolveFooterVariant(inputVariant = '') {
  const explicitVariant = String(
    typeof inputVariant === 'string'
      ? inputVariant
      : (inputVariant && typeof inputVariant === 'object' ? inputVariant.variant : '')
  ).trim().toLowerCase();

  if (explicitVariant === 'authenticated' || explicitVariant === 'auth') {
    return 'authenticated';
  }

  if (explicitVariant === 'public') {
    return 'public';
  }

  if (document.body?.classList?.contains('layout-authenticated')) {
    return 'authenticated';
  }

  if (sessionStorage.getItem('isLoggedIn') === 'true') {
    return 'authenticated';
  }

  return 'public';
}

function getFooterFileForVariant(variant) {
  return variant === 'authenticated' ? './footer1.html' : './footer.html';
}

export async function loadFooter(options = {}) {
  try {
    const footerVariant = resolveFooterVariant(options);
    const footerFile = getFooterFileForVariant(footerVariant);

    console.log(`Loading footer: ${footerFile}`);

    const existingFooter = document.querySelector('footer');
    if (existingFooter) existingFooter.remove();

    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 7000);

    const res = await fetch(footerFile, {
      signal: controller.signal,
      cache: 'no-cache',
    });

    clearTimeout(timeoutId);

    if (!res.ok) throw new Error(`Failed to fetch ${footerFile}: ${res.status}`);

    const footerHTML = await res.text();
    if (!/<footer[\s>]/i.test(footerHTML)) {
      throw new Error(`Invalid footer template returned from ${footerFile}`);
    }
    document.body.insertAdjacentHTML('beforeend', footerHTML);

    const yearSpan = document.getElementById('currentYear');
    if (yearSpan) {
      yearSpan.textContent = new Date().getFullYear();
      console.log('Current year set in footer');
    }

    initFooterVersionBadge().catch(() => {});
    initializeFooterFeatures();

    console.log(`Footer loaded successfully: ${footerFile}`);

    if (options.notify !== false) {
      notifyFooterLoaded();
    }
    return true;
  } catch (err) {
    console.error('Error loading footer:', err);
    createFallbackFooter(resolveFooterVariant(options));
    if (options.notify !== false) {
      notifyFooterLoaded();
    }
    return false;
  }
}

function initializeFooterFeatures() {
  const backToTopBtn = document.getElementById('backToTop');
  if (backToTopBtn) {
    backToTopBtn.addEventListener('click', () => {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    window.addEventListener('scroll', () => {
      if (window.pageYOffset > 300) {
        backToTopBtn.style.display = 'block';
      } else {
        backToTopBtn.style.display = 'none';
      }
    });

    console.log('Back to top button initialized');
  }

  const footerLinks = document.querySelectorAll('footer a[data-external]');
  footerLinks.forEach((link) => {
    link.setAttribute('target', '_blank');
    link.setAttribute('rel', 'noopener noreferrer');
  });
}

function createFallbackFooter(variant = 'public') {
  console.log('Creating fallback footer');

  const fallbackFooter = variant === 'authenticated'
    ? `
      <footer style="
        background: #4c2035;
        color: white;
        padding: 0.5rem 0.75rem;
        text-align: center;
        margin-top: auto;
        border-top: 2px solid #d4af37;
      ">
        <div style="max-width: 920px; margin: 0 auto; display: grid; gap: 0.18rem;">
          <p style="margin: 0; font-size: 0.76rem; opacity: 0.88;">
            &copy; <span id="currentYear">${new Date().getFullYear()}</span> PensionsGo. All rights reserved.
          </p>
        </div>
      </footer>
    `
    : `
      <footer style="
        background: #4c2035;
        color: white;
        padding: 0.9rem 0.8rem;
        text-align: center;
        margin-top: auto;
        border-top: 2px solid #d4af37;
      ">
        <div style="max-width: 1120px; margin: 0 auto; display: grid; gap: 0.75rem;">
          <div style="display: grid; grid-template-columns: minmax(0, 1fr) 1px minmax(0, 1fr); gap: 1rem; align-items: start;">
            <div style="text-align: left; justify-self: end; max-width: 360px;">
              <div style="font-weight: 700; margin-bottom: 0.2rem;">Uganda Prisons Service Headquarters</div>
              <div style="opacity: 0.84; font-size: 0.84rem;">P.O. Box 7182, Kampala (U)</div>
            </div>
            <div style="background: rgba(255,255,255,0.22); min-height: 84px;"></div>
            <div style="text-align: left; justify-self: start; max-width: 360px;">
              <div style="font-weight: 700; margin-bottom: 0.2rem;">Connect With Us</div>
              <div style="opacity: 0.84; font-size: 0.84rem;">Email: support@pensionsgo.local</div>
            </div>
          </div>
          <div style="height: 1px; background: rgba(255,255,255,0.18);"></div>
          <div style="display: grid; gap: 0.22rem; justify-items: center;">
            <div style="display: flex; gap: 0.7rem; flex-wrap: wrap; justify-content: center; font-size: 0.82rem;">
              <a href="about.html" style="color: #f7d77d; text-decoration: none;">About</a>
              <a href="faq.html" style="color: #f7d77d; text-decoration: none;">FAQs</a>
              <a href="feedback.html" style="color: #f7d77d; text-decoration: none;">Feedback</a>
              <a href="terms.html" style="color: #f7d77d; text-decoration: none;">Terms</a>
            </div>
            <div style="opacity: 0.78; font-size: 0.78rem;">
              &copy; <span id="currentYear">${new Date().getFullYear()}</span> PensionsGo. All rights reserved.
            </div>
          </div>
        </div>
      </footer>
    `;

  document.body.insertAdjacentHTML('beforeend', fallbackFooter);
  console.log('Fallback footer created');
}

function notifyFooterLoaded() {
  if (window.AppLoader && typeof window.AppLoader.markFooterLoaded === 'function') {
    window.AppLoader.markFooterLoaded();
    return;
  }

  const footerLoadedEvent = new CustomEvent('footerLoaded', {
    detail: { timestamp: new Date().toISOString() },
  });
  window.dispatchEvent(footerLoadedEvent);

  window.footerLoaded = true;

  console.log('Footer loaded notification sent');
}

export async function reloadFooter() {
  console.log('Manually reloading footer...');

  const existingFooter = document.querySelector('footer');
  if (existingFooter) existingFooter.remove();

  return await loadFooter();
}

if (import.meta.url === document.currentScript?.src) {
  console.log('Footer module loaded directly, auto-initializing...');
  loadFooter().then((success) => {
    console.log(success ? 'Footer auto-load complete' : 'Footer auto-load failed');
  });
}
