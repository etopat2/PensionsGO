"use strict";

const { spawn } = require("child_process");
const fs = require("fs");
const path = require("path");

const ROOT = path.resolve(__dirname, "..");
const BASE_URL = process.env.PENSIONAPP_BASE_URL || "http://localhost/PROJECTS/PensionApp";
const FRONTEND_URL = `${BASE_URL}/frontend`;
const CHROME_PATH = process.env.CHROME_PATH || "C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe";
const PASSWORD = process.env.CODEX_SMOKE_PASSWORD || "Prisons123!";
const MARKER = process.env.CODEX_SMOKE_MARKER || `CODEX_BROWSER_SMOKE_${Date.now()}`;
const FAKE_MEDIA = process.env.CODEX_REAL_MEDIA === "1" ? false : true;

const STAFF_A = {
  name: "Patrick Etomet",
  email: "etomet2patrick@gmail.com",
  role: "admin",
  peerName: "Julius Onyango"
};
const STAFF_B = {
  name: "Julius Onyango",
  email: "onyangojulius144@gmail.com",
  role: "clerk",
  peerName: "Patrick Etomet"
};

const ports = { a: Number(process.env.CODEX_CHROME_PORT_A || 9331), b: Number(process.env.CODEX_CHROME_PORT_B || 9332) };
const profileRoot = path.join(ROOT, "temp", `chrome-smoke-${MARKER.replace(/[^a-z0-9_-]/gi, "_")}`);

function delay(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

async function waitFor(fn, timeoutMs = 15000, intervalMs = 150, label = "condition") {
  const started = Date.now();
  let lastError = null;
  while (Date.now() - started < timeoutMs) {
    try {
      const value = await fn();
      if (value) return value;
    } catch (error) {
      lastError = error;
    }
    await delay(intervalMs);
  }
  const suffix = lastError ? ` Last error: ${lastError.message || lastError}` : "";
  throw new Error(`Timed out waiting for ${label}.${suffix}`);
}

async function waitForHttp(url, timeoutMs = 12000) {
  return waitFor(async () => {
    try {
      const response = await fetch(url);
      return response.ok ? response : false;
    } catch (_) {
      return false;
    }
  }, timeoutMs, 200, url);
}

class CdpPage {
  constructor(port, wsUrl, label) {
    this.port = port;
    this.wsUrl = wsUrl;
    this.label = label;
    this.nextId = 1;
    this.pending = new Map();
    this.events = [];
    this.console = [];
    this.errors = [];
  }

  async connect() {
    this.ws = new WebSocket(this.wsUrl);
    await new Promise((resolve, reject) => {
      const timer = setTimeout(() => reject(new Error(`WebSocket open timed out for ${this.label}`)), 10000);
      this.ws.addEventListener("open", () => {
        clearTimeout(timer);
        resolve();
      }, { once: true });
      this.ws.addEventListener("error", (event) => {
        clearTimeout(timer);
        reject(new Error(`WebSocket error for ${this.label}: ${event.message || "unknown"}`));
      }, { once: true });
    });

    this.ws.addEventListener("message", (event) => {
      const message = JSON.parse(event.data);
      if (message.id && this.pending.has(message.id)) {
        const { resolve, reject } = this.pending.get(message.id);
        this.pending.delete(message.id);
        if (message.error) reject(new Error(`${message.error.message || "CDP error"} ${JSON.stringify(message.error.data || "")}`));
        else resolve(message.result || {});
        return;
      }
      if (message.method) {
        this.events.push(message);
        if (message.method === "Runtime.consoleAPICalled") {
          this.console.push({
            type: message.params?.type || "log",
            text: (message.params?.args || []).map((arg) => arg.value ?? arg.description ?? "").join(" ")
          });
        }
        if (message.method === "Runtime.exceptionThrown" || message.method === "Log.entryAdded") {
          this.errors.push(message);
        }
      }
    });

    await this.send("Page.enable");
    await this.send("Runtime.enable");
    await this.send("Log.enable").catch(() => {});
    await this.send("Network.enable").catch(() => {});
  }

  send(method, params = {}) {
    const id = this.nextId++;
    const payload = JSON.stringify({ id, method, params });
    const promise = new Promise((resolve, reject) => {
      this.pending.set(id, { resolve, reject });
      setTimeout(() => {
        if (this.pending.has(id)) {
          this.pending.delete(id);
          reject(new Error(`CDP command timed out: ${method}`));
        }
      }, 30000);
    });
    this.ws.send(payload);
    return promise;
  }

  async eval(expression, { timeoutMs = 30000 } = {}) {
    const result = await this.send("Runtime.evaluate", {
      expression,
      awaitPromise: true,
      returnByValue: true,
      userGesture: true,
      timeout: timeoutMs
    });
    if (result.exceptionDetails) {
      throw new Error(result.exceptionDetails.text || result.exceptionDetails.exception?.description || "Runtime exception");
    }
    return result.result?.value;
  }

  async call(fn, ...args) {
    const source = `(${fn})(...${JSON.stringify(args)})`;
    return this.eval(source);
  }

  async navigate(url) {
    await this.send("Page.navigate", { url });
    await this.waitForReady();
  }

  async waitForReady() {
    await waitFor(() => this.eval("document.readyState === 'complete' || document.readyState === 'interactive'"), 20000, 150, `${this.label} ready`);
  }

  async clearBrowserState() {
    await this.send("Network.clearBrowserCookies").catch(() => {});
    await this.send("Network.clearBrowserCache").catch(() => {});
    await this.eval("try { localStorage.clear(); sessionStorage.clear(); indexedDB?.databases?.().then(dbs => dbs.forEach(db => indexedDB.deleteDatabase(db.name))); } catch (_) {} true;");
  }

  close() {
    try { this.ws?.close(); } catch (_) {}
  }
}

async function createTarget(port, url = "about:blank", label = "") {
  await waitForHttp(`http://127.0.0.1:${port}/json/version`);
  let response = await fetch(`http://127.0.0.1:${port}/json/new?${encodeURIComponent(url)}`, { method: "PUT" });
  if (!response.ok) {
    response = await fetch(`http://127.0.0.1:${port}/json/new?${encodeURIComponent(url)}`);
  }
  if (!response.ok) throw new Error(`Unable to create Chrome tab on ${port}: ${response.status}`);
  const target = await response.json();
  const page = new CdpPage(port, target.webSocketDebuggerUrl, label || String(port));
  await page.connect();
  return page;
}

function launchChrome(port, profileDir, label, x) {
  fs.mkdirSync(profileDir, { recursive: true });
  const args = [
    `--remote-debugging-port=${port}`,
    `--user-data-dir=${profileDir}`,
    "--no-first-run",
    "--no-default-browser-check",
    "--disable-background-networking",
    "--autoplay-policy=no-user-gesture-required",
    "--use-fake-ui-for-media-stream",
    "--allow-file-access-from-files",
    "--unsafely-treat-insecure-origin-as-secure=http://localhost",
    `--window-position=${x},40`,
    "--window-size=980,900",
    "about:blank"
  ];
  if (FAKE_MEDIA) args.splice(args.indexOf("--use-fake-ui-for-media-stream") + 1, 0, "--use-fake-device-for-media-stream");
  const child = spawn(CHROME_PATH, args, { stdio: "ignore", detached: false });
  child.on("error", (error) => console.error(`Chrome ${label} launch error:`, error.message || error));
  return child;
}

async function login(page, user) {
  await page.navigate(`${FRONTEND_URL}/login.html?smoke=${encodeURIComponent(MARKER)}`);
  const data = await page.call(async (email, password) => {
    const form = new FormData();
    form.append("email", email);
    form.append("password", password);
    const response = await fetch("../backend/api/login.php", { method: "POST", body: form, credentials: "include" });
    const json = await response.json().catch(() => ({}));
    if (!response.ok || !json.success) return { success: false, status: response.status, json };
    const loginData = json;
    sessionStorage.setItem("isLoggedIn", "true");
    sessionStorage.setItem("userName", loginData.userName || "");
    sessionStorage.setItem("userRole", loginData.userRole || "");
    sessionStorage.setItem("userRoleEffective", loginData.userRoleEffective || "");
    sessionStorage.setItem("userId", loginData.userId || "");
    sessionStorage.setItem("phoneNo", loginData.phoneNo || "");
    sessionStorage.setItem("userPhoto", loginData.userPhoto || "");
    sessionStorage.setItem("lastActivity", Date.now().toString());
    sessionStorage.setItem("sessionTimeout", loginData.sessionTimeout || 1800);
    sessionStorage.setItem("gracePeriod", loginData.gracePeriod || 5);
    sessionStorage.setItem("pensionsgo_tab_auth_verified", "true");
    localStorage.setItem("loggedInUser", JSON.stringify({
      name: loginData.userName || "",
      role: loginData.userRole || "",
      effectiveRole: loginData.userRoleEffective || loginData.userRole || "",
      id: loginData.userId || "",
      photo: loginData.userPhoto || "images/default-user.png",
      phone: loginData.phoneNo || "",
      sessionTimeout: loginData.sessionTimeout || 1800,
      gracePeriod: loginData.gracePeriod || 5
    }));
    localStorage.setItem("userRole", loginData.userRole || "");
    localStorage.setItem("userRoleEffective", loginData.userRoleEffective || "");
    if (loginData.sessionId && loginData.userId) {
      localStorage.setItem("pensionsgo_hosted_session_id", String(loginData.sessionId));
      localStorage.setItem("pensionsgo_hosted_session_user", String(loginData.userId));
      localStorage.setItem("pensionsgo_hosted_session_verified_at", Date.now().toString());
      document.cookie = `PENSION_APP_CLIENT_SID=${encodeURIComponent(loginData.sessionId)}; Path=/; SameSite=Lax`;
      document.cookie = `PENSION_APP_CLIENT_UID=${encodeURIComponent(loginData.userId)}; Path=/; SameSite=Lax`;
    }
    return { success: true, userId: loginData.userId, userName: loginData.userName, role: loginData.userRole };
  }, user.email, PASSWORD);
  if (!data?.success) throw new Error(`${page.label} login failed for ${user.email}: ${JSON.stringify(data)}`);
  return data;
}

async function openDashboard(page) {
  await page.navigate(`${FRONTEND_URL}/dashboard.html?smoke=${encodeURIComponent(MARKER)}`);
  await waitFor(() => page.eval("Boolean(document.getElementById('liveChatDock'))"), 30000, 250, `${page.label} live chat dock`);
  await waitFor(() => page.eval("getComputedStyle(document.getElementById('liveChatDock')).visibility !== 'hidden'"), 30000, 250, `${page.label} visible live chat dock`);
}

async function openStaffThread(page, peerName) {
  await page.call(() => document.getElementById("liveChatLauncher")?.click());
  await waitFor(() => page.eval("!document.getElementById('liveChatDock')?.classList.contains('collapsed')"), 8000, 150, `${page.label} dock opened`);
  await waitFor(() => page.eval("document.querySelectorAll('.live-chat-user').length > 0"), 20000, 250, `${page.label} contact list`);
  const clicked = await page.call((name) => {
    const button = Array.from(document.querySelectorAll(".live-chat-user")).find((node) => node.textContent.includes(name));
    if (!button) return { ok: false, contacts: Array.from(document.querySelectorAll(".live-chat-user")).map((node) => node.textContent.trim()).slice(0, 10) };
    button.click();
    return { ok: true };
  }, peerName);
  if (!clicked?.ok) throw new Error(`${page.label} could not find peer ${peerName}. Contacts: ${JSON.stringify(clicked?.contacts || [])}`);
  await waitFor(() => page.eval("Boolean(window.PensionsGoLiveChat?.instance?.selectedThread) && !document.getElementById('liveChatInput')?.disabled"), 12000, 200, `${page.label} selected ${peerName}`);
}

async function setTextareaAndInput(page, selector, text) {
  return page.call((sel, value) => {
    const el = document.querySelector(sel);
    if (!el) return false;
    el.focus();
    el.value = value;
    el.dispatchEvent(new InputEvent("input", { bubbles: true, inputType: "insertText", data: value }));
    return true;
  }, selector, text);
}

async function staffTextAndReceipts(a, b, results) {
  const message = `${MARKER} staff text ${Date.now()}`;
  const typingStarted = Date.now();
  await setTextareaAndInput(a, "#liveChatInput", `${MARKER} typing`);
  const typing = await waitFor(() => b.eval("document.getElementById('liveTypingIndicator') && !document.getElementById('liveTypingIndicator').classList.contains('hidden') && document.getElementById('liveTypingIndicator').textContent.includes('typing')"), 7000, 100, "staff typing indicator").catch(() => false);
  results.staff.typingIndicator = { ok: Boolean(typing), ms: Date.now() - typingStarted };

  await setTextareaAndInput(a, "#liveChatInput", message);
  const sendStarted = Date.now();
  await a.call(() => document.getElementById("liveSendBtn")?.click());
  await waitFor(() => b.call((text) => Array.from(document.querySelectorAll(".live-chat-bubble:not(.own)")).some((node) => node.textContent.includes(text)), message), 10000, 120, "staff recipient message");
  results.staff.textDeliveryMs = Date.now() - sendStarted;

  const receipt = await waitFor(() => a.call((text) => {
    const node = Array.from(document.querySelectorAll(".live-chat-bubble.own")).find((bubble) => bubble.textContent.includes(text));
    return node ? (node.dataset.receiptStatus || "") : "";
  }, message), 10000, 150, "staff receipt status");
  results.staff.receiptStatus = receipt;
}

async function testAudioPreview(page, selector) {
  return page.call(async (sel) => {
    const audio = document.querySelector(sel);
    if (!audio) return { ok: false, reason: "missing audio" };
    audio.muted = true;
    audio.volume = 0;
    audio.load?.();
    await new Promise((resolve) => {
      if (audio.readyState >= 1) return resolve();
      audio.addEventListener("loadedmetadata", resolve, { once: true });
      setTimeout(resolve, 2500);
    });
    const before = audio.currentTime;
    try { await audio.play(); } catch (error) { return { ok: false, reason: error.message, readyState: audio.readyState, duration: audio.duration || 0 }; }
    await new Promise((resolve) => setTimeout(resolve, 450));
    audio.pause();
    return { ok: audio.currentTime > before || audio.readyState >= 2, readyState: audio.readyState, duration: Number.isFinite(audio.duration) ? audio.duration : 0, currentTime: audio.currentTime };
  }, selector);
}

async function staffVoice(a, b, results) {
  const started = Date.now();
  await a.call(() => document.getElementById("liveVoiceBtn")?.click());
  await waitFor(() => a.eval("document.getElementById('liveStopRecording') !== null"), 8000, 150, "staff recording controls");
  await delay(1800);
  await a.call(() => document.getElementById("liveStopRecording")?.click());
  await waitFor(() => a.eval("Boolean(document.getElementById('liveSendVoiceDraft'))"), 12000, 150, "staff voice draft");
  const draft = await a.eval("(() => { const d = window.PensionsGoLiveChat?.instance?.voiceDraft; return d ? { size: d.file?.size || 0, type: d.mimeType || d.file?.type || '', duration: d.duration || 0 } : null; })()");
  const preview = await testAudioPreview(a, "#liveVoiceDraft audio");
  await a.call(() => document.getElementById("liveSendVoiceDraft")?.click());
  await waitFor(() => b.eval("Boolean(document.querySelector('.live-chat-bubble:not(.own) .live-thread-voice-note audio'))"), 20000, 250, "staff received voice note");
  const receivedPreview = await testAudioPreview(b, ".live-chat-bubble:not(.own) .live-thread-voice-note audio");
  results.staff.voice = { ok: draft?.size > 0 && preview.ok && receivedPreview.ok, ms: Date.now() - started, draft, preview, receivedPreview };
}

async function staffVideoCall(a, b, results) {
  const started = Date.now();
  await a.call(() => document.getElementById("liveVideoCallBtn")?.click());
  const ringingAt = await waitFor(() => b.eval("!document.getElementById('liveCallModal')?.classList.contains('hidden') && !document.getElementById('liveAcceptCallBtn')?.classList.contains('hidden')"), 20000, 150, "recipient ringing modal");
  const ringMs = Date.now() - started;
  await b.call(() => document.getElementById("liveAcceptCallBtn")?.click());
  const acceptedStarted = Date.now();
  await waitFor(() => a.eval("document.getElementById('liveCallStatus')?.textContent.toLowerCase().includes('connected') || !document.getElementById('liveCallTimer')?.classList.contains('hidden')"), 25000, 200, "caller connected");
  await waitFor(() => b.eval("document.getElementById('liveCallStatus')?.textContent.toLowerCase().includes('connected') || !document.getElementById('liveCallTimer')?.classList.contains('hidden')"), 25000, 200, "recipient connected");
  await delay(1500);
  const media = {
    caller: await a.eval("(() => { const local = document.getElementById('liveLocalVideo'); const remote = document.getElementById('liveRemoteVideo'); const inst = window.PensionsGoLiveChat?.instance; return { status: document.getElementById('liveCallStatus')?.textContent || '', activeStatus: inst?.activeCall?.status || '', localTracks: inst?.localStream?.getTracks?.().map(t => `${t.kind}:${t.readyState}`) || [], remoteTracks: inst?.remoteStream?.getTracks?.().map(t => `${t.kind}:${t.readyState}`) || [], localReady: local?.readyState || 0, remoteReady: remote?.readyState || 0, localSize: [local?.videoWidth || 0, local?.videoHeight || 0], remoteSize: [remote?.videoWidth || 0, remote?.videoHeight || 0] }; })()"),
    recipient: await b.eval("(() => { const local = document.getElementById('liveLocalVideo'); const remote = document.getElementById('liveRemoteVideo'); const inst = window.PensionsGoLiveChat?.instance; return { status: document.getElementById('liveCallStatus')?.textContent || '', activeStatus: inst?.activeCall?.status || '', localTracks: inst?.localStream?.getTracks?.().map(t => `${t.kind}:${t.readyState}`) || [], remoteTracks: inst?.remoteStream?.getTracks?.().map(t => `${t.kind}:${t.readyState}`) || [], localReady: local?.readyState || 0, remoteReady: remote?.readyState || 0, localSize: [local?.videoWidth || 0, local?.videoHeight || 0], remoteSize: [remote?.videoWidth || 0, remote?.videoHeight || 0] }; })()")
  };
  await a.call(() => document.getElementById("liveEndCallBtn")?.click());
  await waitFor(() => a.eval("document.getElementById('liveCallModal')?.classList.contains('hidden')"), 12000, 200, "caller call modal closed");
  await waitFor(() => b.eval("document.getElementById('liveCallModal')?.classList.contains('hidden')"), 12000, 200, "recipient call modal closed");
  const outcome = {
    caller: await a.eval("({ hidden: document.getElementById('liveCallOutcomeModal')?.classList.contains('hidden'), title: document.getElementById('liveCallOutcomeTitle')?.textContent || '', text: document.getElementById('liveCallOutcomeText')?.textContent || '' })"),
    recipient: await b.eval("({ hidden: document.getElementById('liveCallOutcomeModal')?.classList.contains('hidden'), title: document.getElementById('liveCallOutcomeTitle')?.textContent || '', text: document.getElementById('liveCallOutcomeText')?.textContent || '' })")
  };
  results.staff.videoCall = {
    ok: media.caller.remoteTracks.some((track) => track.startsWith("video:")) && media.recipient.remoteTracks.some((track) => track.startsWith("video:")),
    ringMs,
    connectMs: Date.now() - acceptedStarted,
    media,
    outcome
  };
  void ringingAt;
}

async function openPublicDashboard(a) {
  await a.call(() => document.querySelector('[data-target="publicChatSection"]')?.click());
  await waitFor(() => a.eval("Boolean(document.getElementById('publicChatDashboardMount') && !document.getElementById('publicChatDashboardMount').hidden)"), 20000, 200, "public dashboard mount");
  await waitFor(() => a.eval("Boolean(document.getElementById('publicChatAvailabilityBtn'))"), 15000, 200, "public availability button");
  const state = await a.eval("document.getElementById('publicChatAvailabilityBtn')?.textContent || ''");
  if (/set online/i.test(state)) {
    await a.call(() => document.getElementById("publicChatAvailabilityBtn")?.click());
    await waitFor(() => a.eval("/set offline/i.test(document.getElementById('publicChatAvailabilityBtn')?.textContent || '')"), 12000, 200, "agent online UI");
  }
  await a.call(() => document.getElementById("publicChatOpenConsoleBtn")?.click());
  await waitFor(() => a.eval("document.getElementById('publicChatConsoleModal')?.getAttribute('aria-hidden') === 'false'"), 10000, 200, "public console open");
}

async function publicVisitorStart(b, results) {
  await b.clearBrowserState();
  await b.navigate(`${FRONTEND_URL}/index.html?smoke=${encodeURIComponent(MARKER)}`);
  await waitFor(() => b.eval("Boolean(document.getElementById('publicChatLauncher'))"), 20000, 200, "public chat launcher");
  await b.call(() => document.getElementById("publicChatLauncher")?.click());
  await waitFor(() => b.eval("Boolean(document.getElementById('publicChatStartForm'))"), 12000, 200, "public chat start form");
  const subject = `${MARKER} public subject`;
  const initial = `${MARKER} initial public message`;
  await b.call((marker, subjectText, messageText) => {
    const form = document.getElementById("publicChatStartForm");
    form.querySelector('[name="visitor_name"]').value = `Browser Smoke Visitor ${marker.slice(-6)}`;
    form.querySelector('[name="phone_number"]').value = "0700000042";
    form.querySelector('[name="email"]').value = "browser-smoke@example.com";
    form.querySelector('[name="inquiry_category"]').value = "General inquiry";
    form.querySelector('[name="subject"]').value = subjectText;
    form.querySelector('[name="message"]').value = messageText;
    form.querySelector('[name="consent"]').checked = true;
    form.dispatchEvent(new Event("submit", { bubbles: true, cancelable: true }));
  }, MARKER, subject, initial);
  const started = Date.now();
  await waitFor(() => b.eval("Boolean(document.getElementById('publicChatThread'))"), 15000, 200, "public visitor thread");
  results.public.startMs = Date.now() - started;
  return { subject, initial };
}

async function publicAgentAccept(a, subject, results) {
  const started = Date.now();
  await waitFor(async () => {
    await a.call(() => document.getElementById("publicChatRefreshDashboardBtn")?.click()).catch(() => {});
    return a.call((text) => {
      const row = Array.from(document.querySelectorAll("[data-public-chat-session]")).find((node) => node.textContent.includes(text));
      if (row) {
        row.click();
        return true;
      }
      return false;
    }, subject);
  }, 20000, 500, "public queue row");
  results.public.queueAppearMs = Date.now() - started;
  await waitFor(() => a.eval("Boolean(document.getElementById('publicChatDashboardAcceptBtn')) && !document.getElementById('publicChatDashboardAcceptBtn').disabled"), 10000, 200, "public accept enabled");
  await a.call(() => document.getElementById("publicChatDashboardAcceptBtn")?.click());
  await waitFor(() => a.eval("!document.getElementById('publicChatDashboardReplyText')?.disabled"), 12000, 200, "public agent reply enabled");
}

async function publicText(a, b, results) {
  const visitorMessage = `${MARKER} visitor public followup`;
  const agentMessage = `${MARKER} agent public reply`;
  await setTextareaAndInput(b, "#publicChatReplyText", visitorMessage);
  let started = Date.now();
  await b.call(() => document.getElementById("publicChatReply")?.dispatchEvent(new Event("submit", { bubbles: true, cancelable: true })));
  await waitFor(() => a.call((text) => Array.from(document.querySelectorAll(".public-chat-dashboard-message.visitor")).some((node) => node.textContent.includes(text)), visitorMessage), 10000, 150, "agent sees visitor public text");
  results.public.visitorToAgentMs = Date.now() - started;

  await setTextareaAndInput(a, "#publicChatDashboardReplyText", agentMessage);
  started = Date.now();
  await a.call(() => document.getElementById("publicChatDashboardReplyForm")?.dispatchEvent(new Event("submit", { bubbles: true, cancelable: true })));
  await waitFor(() => b.call((text) => Array.from(document.querySelectorAll(".public-chat-message.agent")).some((node) => node.textContent.includes(text)), agentMessage), 10000, 150, "visitor sees agent public text");
  results.public.agentToVisitorMs = Date.now() - started;
}

async function publicVoice(a, b, results) {
  const started = Date.now();
  await b.call(() => document.getElementById("publicChatVoiceBtn")?.click());
  await waitFor(() => b.eval("Boolean(document.getElementById('publicChatStopRecording'))"), 8000, 150, "public visitor voice recording");
  await delay(1600);
  await b.call(() => document.getElementById("publicChatStopRecording")?.click());
  await waitFor(() => b.eval("Boolean(document.getElementById('publicChatSendVoiceDraft'))"), 12000, 150, "public visitor voice draft");
  const preview = await testAudioPreview(b, "#publicChatVoiceDraft audio");
  await b.call(() => document.getElementById("publicChatSendVoiceDraft")?.click());
  await waitFor(() => a.eval("Boolean(document.querySelector('.public-chat-dashboard-message.visitor .live-thread-voice-note audio'))"), 25000, 250, "agent receives public visitor voice");
  const receivedPreview = await testAudioPreview(a, ".public-chat-dashboard-message.visitor .live-thread-voice-note audio");
  results.public.visitorVoice = { ok: preview.ok && receivedPreview.ok, ms: Date.now() - started, preview, receivedPreview };

  const agentStarted = Date.now();
  await a.call(() => document.getElementById("publicChatDashboardVoiceBtn")?.click());
  await waitFor(() => a.eval("Boolean(document.getElementById('publicChatDashboardStopRecording'))"), 8000, 150, "public agent voice recording");
  await delay(1600);
  await a.call(() => document.getElementById("publicChatDashboardStopRecording")?.click());
  await waitFor(() => a.eval("Boolean(document.getElementById('publicChatDashboardSendVoiceDraft'))"), 12000, 150, "public agent voice draft");
  const agentPreview = await testAudioPreview(a, "#publicChatDashboardVoiceDraft audio");
  await a.call(() => document.getElementById("publicChatDashboardSendVoiceDraft")?.click());
  await waitFor(() => b.eval("Boolean(document.querySelector('.public-chat-message.agent .live-thread-voice-note audio'))"), 25000, 250, "visitor receives public agent voice");
  const agentReceivedPreview = await testAudioPreview(b, ".public-chat-message.agent .live-thread-voice-note audio");
  results.public.agentVoice = { ok: agentPreview.ok && agentReceivedPreview.ok, ms: Date.now() - agentStarted, preview: agentPreview, receivedPreview: agentReceivedPreview };
}

async function finishPublicChat(a, b, results) {
  await b.call(() => document.getElementById("publicChatEndBtn")?.click()).catch(() => {});
  await waitFor(() => b.eval("Boolean(document.getElementById('publicChatFeedbackModal')) && !document.getElementById('publicChatFeedbackModal').hidden"), 10000, 200, "public feedback modal").catch(() => false);
  const feedbackVisible = await b.eval("Boolean(document.getElementById('publicChatFeedbackModal')) && !document.getElementById('publicChatFeedbackModal').hidden");
  if (feedbackVisible) {
    await b.call(() => {
      const stars = Array.from(document.querySelectorAll("#publicChatRating button"));
      (stars[4] || stars[0])?.click();
      const comments = document.getElementById("publicChatFeedbackComments");
      if (comments) comments.value = "Browser smoke feedback";
      document.getElementById("publicChatFeedbackSend")?.click();
    });
    await waitFor(() => b.eval("document.getElementById('publicChatFeedbackStatus')?.textContent.toLowerCase().includes('thank')"), 12000, 250, "public feedback submitted");
    results.public.feedback = "submitted";
  } else {
    results.public.feedback = "not shown";
  }
  await a.call(() => {
    const close = document.querySelector("[data-public-chat-console-close]");
    close?.click();
  }).catch(() => {});
}

async function main() {
  if (!fs.existsSync(CHROME_PATH)) throw new Error(`Chrome not found at ${CHROME_PATH}`);
  fs.mkdirSync(profileRoot, { recursive: true });

  const results = {
    marker: MARKER,
    baseUrl: BASE_URL,
    fakeMedia: FAKE_MEDIA,
    staff: {},
    public: {},
    browserErrors: {}
  };

  const processes = [
    launchChrome(ports.a, path.join(profileRoot, "profile-a"), "A", 20),
    launchChrome(ports.b, path.join(profileRoot, "profile-b"), "B", 1020)
  ];

  let a;
  let b;
  try {
    a = await createTarget(ports.a, "about:blank", "Profile A");
    b = await createTarget(ports.b, "about:blank", "Profile B");
    await a.clearBrowserState();
    await b.clearBrowserState();

    const loginA = await login(a, STAFF_A);
    const loginB = await login(b, STAFF_B);
    results.staff.login = { a: loginA, b: loginB };

    await Promise.all([openDashboard(a), openDashboard(b)]);
    await openStaffThread(a, STAFF_A.peerName);
    await openStaffThread(b, STAFF_B.peerName);
    await staffTextAndReceipts(a, b, results);
    await staffVoice(a, b, results);
    await staffVideoCall(a, b, results);

    await openPublicDashboard(a);
    const publicSeed = await publicVisitorStart(b, results);
    await publicAgentAccept(a, publicSeed.subject, results);
    await publicText(a, b, results);
    await publicVoice(a, b, results);
    await finishPublicChat(a, b, results);

    results.ok = true;
  } catch (error) {
    results.ok = false;
    results.error = error.message || String(error);
  } finally {
    if (a) {
      results.browserErrors.a = {
        console: a.console.filter((entry) => ["error", "warning"].includes(entry.type)).slice(-20),
        errors: a.errors.slice(-10)
      };
      await a.call(() => document.getElementById("publicChatAvailabilityBtn")?.textContent.match(/set offline/i) && document.getElementById("publicChatAvailabilityBtn")?.click()).catch(() => {});
      await a.send("Browser.close").catch(() => {});
      a.close();
    }
    if (b) {
      results.browserErrors.b = {
        console: b.console.filter((entry) => ["error", "warning"].includes(entry.type)).slice(-20),
        errors: b.errors.slice(-10)
      };
      await b.send("Browser.close").catch(() => {});
      b.close();
    }
    for (const child of processes) {
      try { child.kill(); } catch (_) {}
    }
  }

  fs.writeSync(1, `${JSON.stringify(results, null, 2)}\n`);
  process.exit(results.ok ? 0 : 1);
}

main().catch((error) => {
  fs.writeSync(2, `${JSON.stringify({ ok: false, marker: MARKER, error: error.message || String(error) }, null, 2)}\n`);
  process.exit(1);
});
