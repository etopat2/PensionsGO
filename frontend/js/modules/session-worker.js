/**
 * session-worker.js
 * 
 * FIXES:
 * ✔ 403 ≠ session expired
 * ✔ Expiry broadcast once
 * ✔ Grace-aware
 * ✔ No modal spam
 */

let BASE_API = null;
let DEVICE_TOKEN = '';
let HOSTED_SESSION_ID = '';
let HOSTED_SESSION_USER = '';
let sessionExpiredBroadcasted = false;

const state = {
  pollingInterval: 15000,
  failures: 0
};

self.onmessage = (e) => {
  if (e.data.type === "CONFIG") {
    BASE_API = e.data.BASE_API;
    DEVICE_TOKEN = e.data.deviceToken || '';
    HOSTED_SESSION_ID = e.data.hostedSessionId || '';
    HOSTED_SESSION_USER = e.data.hostedSessionUser || '';
  }
  if (e.data.type === "START_MONITORING") poll();
};

async function poll() {
  if (!BASE_API) return;

  try {
    const headers = {};
    if (DEVICE_TOKEN) headers["X-Device-Token"] = DEVICE_TOKEN;
    if (/^[a-f0-9]{64}$/i.test(HOSTED_SESSION_ID) && HOSTED_SESSION_USER) {
      headers["X-PensionsGo-Session-Id"] = HOSTED_SESSION_ID;
      headers["X-PensionsGo-User-Id"] = HOSTED_SESSION_USER;
    }

    const res = await fetch(BASE_API + "check_session.php", {
      credentials: "include",
      cache: "no-store",
      headers
    });

    if (!res.ok) return;

    const data = await res.json();

    self.postMessage({ type: "SESSION_CHECK_RESULT", data });

    if (!data.active && !sessionExpiredBroadcasted) {
      sessionExpiredBroadcasted = true;
      self.postMessage({
        type: "SESSION_EXPIRED",
        reason: data.reason || "expired",
        message: data.message || "Session expired"
      });
    }

    state.failures = 0;
  } catch {
    state.failures++;
  }

  setTimeout(poll, state.pollingInterval);
}

self.postMessage({ type: "WORKER_READY" });

