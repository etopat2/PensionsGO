(function () {
  if (window.__pensionsgoAuthBootstrapPatched) return;
  window.__pensionsgoAuthBootstrapPatched = true;

  var DEVICE_TOKEN_STORAGE_KEY = 'pensionsgo_device_token';
  var HOSTED_SESSION_ID_STORAGE_KEY = 'pensionsgo_hosted_session_id';
  var HOSTED_SESSION_USER_STORAGE_KEY = 'pensionsgo_hosted_session_user';
  var nativeFetch = window.fetch ? window.fetch.bind(window) : null;

  function randomHex(bytes) {
    if (window.crypto && window.crypto.getRandomValues) {
      var values = new Uint8Array(bytes);
      window.crypto.getRandomValues(values);
      return Array.prototype.map.call(values, function (byte) {
        return byte.toString(16).padStart(2, '0');
      }).join('');
    }
    var out = '';
    for (var i = 0; i < bytes * 2; i += 1) out += Math.floor(Math.random() * 16).toString(16);
    return out;
  }

  function getDeviceToken() {
    var existing = String(localStorage.getItem(DEVICE_TOKEN_STORAGE_KEY) || '').trim().toLowerCase();
    if (/^[a-f0-9]{64}$/.test(existing)) return existing;
    var token = randomHex(32);
    localStorage.setItem(DEVICE_TOKEN_STORAGE_KEY, token);
    return token;
  }

  function syncClientCookies(sessionId, userId) {
    if (!/^[a-f0-9]{64}$/i.test(sessionId || '') || !userId) return;
    var secure = window.location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = 'PENSION_APP_CLIENT_SID=' + encodeURIComponent(sessionId) + '; Path=/; SameSite=Lax' + secure;
    document.cookie = 'PENSION_APP_CLIENT_UID=' + encodeURIComponent(userId) + '; Path=/; SameSite=Lax' + secure;
  }

  function appendAuthHeaders(headers) {
    var deviceToken = getDeviceToken();
    if (deviceToken) headers.set('X-Device-Token', deviceToken);

    var sessionId = String(localStorage.getItem(HOSTED_SESSION_ID_STORAGE_KEY) || '').trim();
    var userId = String(localStorage.getItem(HOSTED_SESSION_USER_STORAGE_KEY) || '').trim();
    if (/^[a-f0-9]{64}$/i.test(sessionId) && userId) {
      syncClientCookies(sessionId, userId);
      headers.set('X-PensionsGo-Session-Id', sessionId);
      headers.set('X-PensionsGo-User-Id', userId);
    }
  }

  function isBackendApi(input) {
    try {
      var url = input instanceof Request ? input.url : String(input || '');
      var resolved = new URL(url, window.location.href);
      return resolved.origin === window.location.origin && resolved.pathname.toLowerCase().indexOf('/backend/api/') !== -1;
    } catch (_error) {
      return false;
    }
  }

  window.withDeploymentSessionHeaders = function (headers) {
    var next = new Headers(headers || {});
    appendAuthHeaders(next);
    var plain = {};
    next.forEach(function (value, key) { plain[key] = value; });
    return plain;
  };

  if (!nativeFetch) return;
  window.fetch = function (input, init) {
    init = init || {};
    if (!isBackendApi(input)) return nativeFetch(input, init);
    var headers = new Headers(input instanceof Request ? input.headers : undefined);
    if (init.headers) {
      new Headers(init.headers).forEach(function (value, key) { headers.set(key, value); });
    }
    appendAuthHeaders(headers);
    return nativeFetch(input, Object.assign({}, init, { headers: headers }));
  };
}());
