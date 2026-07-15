"use strict";

const fs = require("fs");
const path = require("path");
const { chromium } = require("./playwright-smoke/node_modules/playwright-core");

const ROOT = path.resolve(__dirname, "..");
const BASE_URL = process.env.PENSIONAPP_BASE_URL || "http://localhost/PROJECTS/PensionApp";
const FRONTEND_URL = `${BASE_URL}/frontend`;
const CHROME_PATH = process.env.CHROME_PATH || "C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe";
const PASSWORD = process.env.CODEX_SMOKE_PASSWORD || "Prisons123!";
const MARKER = process.env.CODEX_SMOKE_MARKER || `CODEX_BROWSER_SMOKE_${Date.now()}`;
const FAKE_MEDIA = process.env.CODEX_REAL_MEDIA === "1" ? false : true;
const profileRoot = path.join(ROOT, "temp", `pw-chrome-smoke-${MARKER.replace(/[^a-z0-9_-]/gi, "_")}`);

const STAFF_A = { email: "etomet2patrick@gmail.com", peerName: "Julius Onyango" };
const STAFF_B = { email: "onyangojulius144@gmail.com", peerName: "Patrick Etomet" };
const CHAT_API_RE = /\/backend\/api\/(?:login\.php|live_chat_|public_chat_|get_csrf_token\.php)/;

const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

function pushCapped(list, value, limit = 120) {
  list.push(value);
  while (list.length > limit) list.shift();
}

function writeReport(report, exitCode) {
  const json = `${JSON.stringify(report, null, 2)}\n`;
  if (process.env.CODEX_REPORT_FILE) {
    fs.writeFileSync(process.env.CODEX_REPORT_FILE, json);
  } else {
    fs.writeSync(exitCode ? 2 : 1, json);
  }
  process.exit(exitCode);
}

async function launchProfile(name, x) {
  const userDataDir = path.join(profileRoot, name);
  fs.mkdirSync(userDataDir, { recursive: true });
  const args = [
    "--no-first-run",
    "--no-default-browser-check",
    "--disable-background-networking",
    "--disable-background-timer-throttling",
    "--disable-backgrounding-occluded-windows",
    "--disable-renderer-backgrounding",
    "--disable-features=CalculateNativeWinOcclusion",
    "--autoplay-policy=no-user-gesture-required",
    "--use-fake-ui-for-media-stream",
    "--unsafely-treat-insecure-origin-as-secure=http://localhost",
    `--window-position=${x},40`,
    "--window-size=980,900"
  ];
  if (FAKE_MEDIA) args.push("--use-fake-device-for-media-stream");
  return chromium.launchPersistentContext(userDataDir, {
    executablePath: CHROME_PATH,
    headless: false,
    viewport: { width: 980, height: 900 },
    acceptDownloads: true,
    permissions: ["microphone", "camera", "notifications"],
    args
  });
}

function collectBrowserEvents(page, bucket) {
  bucket.apiRequests = bucket.apiRequests || [];
  bucket.apiResponses = bucket.apiResponses || [];
  page.on("console", (msg) => {
    if (["warning", "error"].includes(msg.type())) bucket.console.push({ type: msg.type(), text: msg.text().slice(0, 500) });
  });
  page.on("pageerror", (error) => bucket.pageErrors.push(String(error.message || error).slice(0, 500)));
  page.on("requestfailed", (request) => {
    const failure = request.failure();
    bucket.requestFailures.push({ url: request.url(), method: request.method(), error: failure?.errorText || "" });
  });
  page.on("request", (request) => {
    const url = request.url();
    if (CHAT_API_RE.test(url)) {
      pushCapped(bucket.apiRequests, { at: Date.now(), method: request.method(), url });
    }
  });
  page.on("response", (response) => {
    const url = response.url();
    if (CHAT_API_RE.test(url)) {
      pushCapped(bucket.apiResponses, { at: Date.now(), status: response.status(), url });
    }
    if (response.status() >= 400 && CHAT_API_RE.test(url)) {
      bucket.httpErrors.push({ status: response.status(), url });
    }
  });
}

async function login(page, user) {
  await page.goto(`${FRONTEND_URL}/login.html?smoke=${encodeURIComponent(MARKER)}`, { waitUntil: "domcontentloaded" });
  const data = await page.evaluate(async ({ email, password }) => {
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
  }, { email: user.email, password: PASSWORD });
  if (!data?.success) throw new Error(`Login failed for ${user.email}: ${JSON.stringify(data)}`);
  return data;
}

async function openDashboard(page) {
  await page.goto(`${FRONTEND_URL}/dashboard.html?smoke=${encodeURIComponent(MARKER)}`, { waitUntil: "domcontentloaded" });
  await page.waitForSelector("#liveChatDock", { timeout: 30000 });
  await page.waitForFunction(() => getComputedStyle(document.getElementById("liveChatDock")).visibility !== "hidden", null, { timeout: 30000 });
}

async function getStaffThreadState(page) {
  return page.evaluate(() => {
    const inst = window.PensionsGoLiveChat?.instance;
    const selectedThread = inst?.selectedThread || null;
    const key = selectedThread ? `${selectedThread.type}:${selectedThread.id}` : "";
    const messageBox = document.getElementById("liveChatMessages");
    const bubbles = Array.from(messageBox?.querySelectorAll(".live-chat-bubble") || []);
    return {
      selectedThread,
      key,
      hasCsrfToken: Boolean(inst?.csrfToken),
      inputDisabled: document.getElementById("liveChatInput")?.disabled ?? null,
      sendDisabled: document.getElementById("liveSendBtn")?.disabled ?? null,
      messagePollInFlight: inst?.messagePollInFlight ?? null,
      messagePollStartedAgoMs: inst?.messagePollStartedAt ? Date.now() - inst.messagePollStartedAt : 0,
      lastMessageId: key ? (inst?.lastMessageIdByThread?.[key] || 0) : 0,
      hasLastMessageKey: key ? Object.prototype.hasOwnProperty.call(inst?.lastMessageIdByThread || {}, key) : false,
      renderedCount: inst?.renderedMessageIds?.size || 0,
      bubbleCount: bubbles.length,
      lastBubbleText: bubbles.slice(-1)[0]?.textContent.replace(/\s+/g, " ").trim() || "",
      emptyText: messageBox?.querySelector(".live-chat-empty")?.textContent.replace(/\s+/g, " ").trim() || "",
      notice: Array.from(document.querySelectorAll(".live-chat-notice")).map((node) => node.textContent.trim()).slice(-5)
    };
  });
}

async function openStaffThread(page, peerName) {
  await page.waitForFunction(() => Boolean(window.PensionsGoLiveChat?.instance), null, { timeout: 30000 });
  await page.waitForFunction(() => {
    const inst = window.PensionsGoLiveChat?.instance;
    return Boolean(inst?.csrfToken) && Array.isArray(inst?.users) && inst.users.length > 0;
  }, null, { timeout: 30000 });
  await page.locator("#liveChatLauncher").click();
  await page.waitForFunction(() => !document.getElementById("liveChatDock")?.classList.contains("collapsed"), null, { timeout: 8000 });
  await page.waitForFunction(() => document.querySelectorAll(".live-chat-user").length > 0, null, { timeout: 20000 });
  const ok = await page.evaluate((name) => {
    const button = Array.from(document.querySelectorAll(".live-chat-user")).find((node) => node.textContent.includes(name));
    if (!button) return false;
    button.click();
    return true;
  }, peerName);
  if (!ok) throw new Error(`Could not find staff chat peer ${peerName}`);
  await page.waitForFunction(() => {
    const inst = window.PensionsGoLiveChat?.instance;
    const input = document.getElementById("liveChatInput");
    const messageBox = document.getElementById("liveChatMessages");
    const selectedThread = inst?.selectedThread;
    if (!inst?.csrfToken || !selectedThread || !input || input.disabled || !messageBox) return false;
    const key = `${selectedThread.type}:${selectedThread.id}`;
    const lastIds = inst.lastMessageIdByThread || {};
    const emptyText = messageBox.querySelector(".live-chat-empty")?.textContent || "";
    const hasBubbles = messageBox.querySelectorAll(".live-chat-bubble").length > 0;
    const isLoading = /loading conversation/i.test(emptyText);
    const hasLoadedEmptyState = /no messages/i.test(emptyText);
    const hasLoadedHistoryState = hasBubbles || Object.prototype.hasOwnProperty.call(lastIds, key);
    const hasInitialLoadState = hasLoadedHistoryState || hasLoadedEmptyState;
    return !isLoading && hasInitialLoadState;
  }, null, { timeout: 45000 });
  return getStaffThreadState(page);
}

async function fillAndInput(page, selector, value) {
  await page.locator(selector).fill(value);
  await page.locator(selector).dispatchEvent("input", { bubbles: true });
}

async function testAudioPreview(page, selector) {
  return page.evaluate(async (sel) => {
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
    try {
      await audio.play();
    } catch (error) {
      return { ok: false, reason: error.message, readyState: audio.readyState, duration: audio.duration || 0 };
    }
    await new Promise((resolve) => setTimeout(resolve, 450));
    audio.pause();
    return { ok: audio.currentTime > before || audio.readyState >= 2, readyState: audio.readyState, duration: Number.isFinite(audio.duration) ? audio.duration : 0, currentTime: audio.currentTime };
  }, selector);
}

async function staffTextAndReceipts(a, b, results) {
  const typingStarted = Date.now();
  await a.locator("#liveChatInput").focus();
  await a.keyboard.type(`${MARKER} typing`);
  const typingSeen = await b.waitForFunction(() => {
    const node = document.getElementById("liveTypingIndicator");
    return node && !node.classList.contains("hidden") && node.textContent.includes("typing");
  }, null, { timeout: 7000 }).then(() => true).catch(() => false);
  results.staff.typingIndicator = { ok: typingSeen, ms: Date.now() - typingStarted };

  const message = `${MARKER} staff text ${Date.now()}`;
  await fillAndInput(a, "#liveChatInput", message);
  results.staff.preSendState = await a.evaluate(() => ({
    selectedThread: window.PensionsGoLiveChat?.instance?.selectedThread || null,
    inputValue: document.getElementById("liveChatInput")?.value || "",
    sendDisabled: document.getElementById("liveSendBtn")?.disabled ?? null,
    voiceDisabled: document.getElementById("liveVoiceBtn")?.disabled ?? null,
    dockClass: document.getElementById("liveChatDock")?.className || "",
    notice: Array.from(document.querySelectorAll(".live-chat-notice")).map((node) => node.textContent.trim()).slice(-3)
  }));
  const started = Date.now();
  const sendResponsePromise = a.waitForResponse((response) => (
    response.url().includes("/backend/api/live_chat_send.php")
    && response.request().method() === "POST"
  ), { timeout: 15000 }).catch((error) => ({ smokeError: error.message || String(error) }));
  await a.locator("#liveSendBtn").click();
  const sendResponse = await sendResponsePromise;
  results.staff.sendApiMs = Date.now() - started;
  results.staff.sendApiStatus = typeof sendResponse.status === "function" ? sendResponse.status() : null;
  results.staff.sendApiError = sendResponse.smokeError || "";
  await delay(1200);
  results.staff.postSendState = await a.evaluate(() => ({
    sending: window.PensionsGoLiveChat?.instance?.sending ?? null,
    inputValue: document.getElementById("liveChatInput")?.value || "",
    pendingCount: document.querySelectorAll(".live-chat-bubble.pending").length,
    ownBubbleCount: document.querySelectorAll(".live-chat-bubble.own").length,
    lastOwnText: Array.from(document.querySelectorAll(".live-chat-bubble.own")).pop()?.textContent || "",
    notice: Array.from(document.querySelectorAll(".live-chat-notice")).map((node) => node.textContent.trim()).slice(-5)
  }));
  const recipientSawMessage = await b.waitForFunction((text) => Array.from(document.querySelectorAll(".live-chat-bubble:not(.own)")).some((node) => node.textContent.includes(text)), message, { timeout: 12000 })
    .then(() => true)
    .catch(() => false);
  if (!recipientSawMessage) {
    results.staff.recipientState = await b.evaluate(() => ({
      selectedThread: window.PensionsGoLiveChat?.instance?.selectedThread || null,
      lastIds: window.PensionsGoLiveChat?.instance?.lastMessageIdByThread || {},
      renderedIds: Array.from(window.PensionsGoLiveChat?.instance?.renderedMessageIds || []),
      bubbles: Array.from(document.querySelectorAll(".live-chat-bubble")).slice(-12).map((node) => ({
        id: node.dataset.messageId || "",
        own: node.dataset.own || "",
        className: node.className,
        text: node.textContent.replace(/\s+/g, " ").trim()
      })),
      typing: document.getElementById("liveTypingIndicator")?.textContent.trim() || "",
      notice: Array.from(document.querySelectorAll(".live-chat-notice")).map((node) => node.textContent.trim()).slice(-5)
    }));
    throw new Error("Recipient did not render staff text as incoming.");
  }
  results.staff.textDeliveryMs = Date.now() - started;
  const receipt = await a.waitForFunction((text) => {
    const node = Array.from(document.querySelectorAll(".live-chat-bubble.own")).find((bubble) => bubble.textContent.includes(text));
    return node?.dataset?.receiptStatus || "";
  }, message, { timeout: 12000 });
  results.staff.receiptStatus = await receipt.jsonValue();
}

async function staffVoice(a, b, results) {
  const started = Date.now();
  await a.locator("#liveVoiceBtn").click();
  await a.waitForSelector("#liveStopRecording", { timeout: 8000 });
  await delay(1800);
  await a.locator("#liveStopRecording").click();
  await a.waitForSelector("#liveSendVoiceDraft", { timeout: 12000 });
  const draft = await a.evaluate(() => {
    const d = window.PensionsGoLiveChat?.instance?.voiceDraft;
    return d ? { size: d.file?.size || 0, type: d.mimeType || d.file?.type || "", duration: d.duration || 0 } : null;
  });
  const preview = await testAudioPreview(a, "#liveVoiceDraft audio");
  await a.locator("#liveSendVoiceDraft").click();
  await b.waitForSelector(".live-chat-bubble:not(.own) .live-thread-voice-note audio", { timeout: 25000 });
  const receivedPreview = await testAudioPreview(b, ".live-chat-bubble:not(.own) .live-thread-voice-note audio");
  results.staff.voice = { ok: draft?.size > 0 && preview.ok && receivedPreview.ok, ms: Date.now() - started, draft, preview, receivedPreview };
}

async function staffVideoCall(a, b, results) {
  const started = Date.now();
  await a.locator("#liveVideoCallBtn").click();
  await b.waitForFunction(() => {
    const modal = document.getElementById("liveCallModal");
    const accept = document.getElementById("liveAcceptCallBtn");
    return modal && !modal.classList.contains("hidden") && accept && !accept.classList.contains("hidden");
  }, null, { timeout: 25000 });
  const ringMs = Date.now() - started;
  await b.locator("#liveAcceptCallBtn").click();
  const accepted = Date.now();
  await a.waitForFunction(() => document.getElementById("liveCallStatus")?.textContent.toLowerCase().includes("connected") || !document.getElementById("liveCallTimer")?.classList.contains("hidden"), null, { timeout: 30000 });
  await b.waitForFunction(() => document.getElementById("liveCallStatus")?.textContent.toLowerCase().includes("connected") || !document.getElementById("liveCallTimer")?.classList.contains("hidden"), null, { timeout: 30000 });
  await delay(1500);
  const mediaEval = () => {
    const local = document.getElementById("liveLocalVideo");
    const remote = document.getElementById("liveRemoteVideo");
    const inst = window.PensionsGoLiveChat?.instance;
    return {
      status: document.getElementById("liveCallStatus")?.textContent || "",
      activeStatus: inst?.activeCall?.status || "",
      localTracks: inst?.localStream?.getTracks?.().map((track) => `${track.kind}:${track.readyState}`) || [],
      remoteTracks: inst?.remoteStream?.getTracks?.().map((track) => `${track.kind}:${track.readyState}`) || [],
      localReady: local?.readyState || 0,
      remoteReady: remote?.readyState || 0,
      localSize: [local?.videoWidth || 0, local?.videoHeight || 0],
      remoteSize: [remote?.videoWidth || 0, remote?.videoHeight || 0]
    };
  };
  const media = { caller: await a.evaluate(mediaEval), recipient: await b.evaluate(mediaEval) };
  await a.locator("#liveEndCallBtn").click();
  await a.waitForFunction(() => document.getElementById("liveCallModal")?.classList.contains("hidden"), null, { timeout: 15000 });
  await b.waitForFunction(() => document.getElementById("liveCallModal")?.classList.contains("hidden"), null, { timeout: 15000 });
  const outcomeEval = () => ({
    hidden: document.getElementById("liveCallOutcomeModal")?.classList.contains("hidden"),
    title: document.getElementById("liveCallOutcomeTitle")?.textContent || "",
    text: document.getElementById("liveCallOutcomeText")?.textContent || ""
  });
  results.staff.videoCall = {
    ok: media.caller.remoteTracks.some((track) => track.startsWith("video:")) && media.recipient.remoteTracks.some((track) => track.startsWith("video:")),
    ringMs,
    connectMs: Date.now() - accepted,
    media,
    outcome: { caller: await a.evaluate(outcomeEval), recipient: await b.evaluate(outcomeEval) }
  };
}

async function dismissStaffCallOutcome(...pages) {
  await Promise.all(pages.map((page) => page.evaluate(() => {
    document.getElementById("liveCallOutcomeOk")?.click();
    document.getElementById("liveCallOutcomeClose")?.click();
    document.getElementById("liveCallOutcomeModal")?.classList.add("hidden");
    document.getElementById("liveChatDock")?.classList.remove("call-outcome-active");
  }).catch(() => {})));
}

async function openPublicDashboard(a) {
  await a.evaluate(() => document.querySelector('[data-target="publicChatSection"]')?.click());
  await a.waitForFunction(() => Boolean(document.getElementById("publicChatDashboardMount") && !document.getElementById("publicChatDashboardMount").hidden), null, { timeout: 20000 });
  await a.waitForSelector("#publicChatAvailabilityBtn", { timeout: 15000 });
  const label = await a.locator("#publicChatAvailabilityBtn").textContent();
  if (/set online/i.test(label || "")) {
    await a.locator("#publicChatAvailabilityBtn").click();
    await a.waitForFunction(() => /set offline/i.test(document.getElementById("publicChatAvailabilityBtn")?.textContent || ""), null, { timeout: 12000 });
  }
  await a.locator("#publicChatOpenConsoleBtn").click();
  await a.waitForFunction(() => document.getElementById("publicChatConsoleModal")?.getAttribute("aria-hidden") === "false", null, { timeout: 10000 });
}

async function publicVisitorStart(page, context, results) {
  await context.clearCookies();
  await page.goto(`${FRONTEND_URL}/index.html?smoke=${encodeURIComponent(MARKER)}`, { waitUntil: "domcontentloaded" });
  await page.evaluate(() => { localStorage.clear(); sessionStorage.clear(); });
  await page.reload({ waitUntil: "domcontentloaded" });
  await page.waitForSelector("#publicChatLauncher", { timeout: 20000 });
  await page.locator("#publicChatLauncher").click();
  await page.waitForSelector("#publicChatStartForm", { timeout: 12000 });
  const subject = `${MARKER} public subject`;
  const initial = `${MARKER} initial public message`;
  await page.evaluate(({ marker, subject, initial }) => {
    const form = document.getElementById("publicChatStartForm");
    form.querySelector('[name="visitor_name"]').value = `Browser Smoke Visitor ${marker.slice(-6)}`;
    form.querySelector('[name="phone_number"]').value = "0700000042";
    form.querySelector('[name="email"]').value = "browser-smoke@example.com";
    form.querySelector('[name="inquiry_category"]').value = "General inquiry";
    form.querySelector('[name="subject"]').value = subject;
    form.querySelector('[name="message"]').value = initial;
    form.querySelector('[name="consent"]').checked = true;
    form.dispatchEvent(new Event("submit", { bubbles: true, cancelable: true }));
  }, { marker: MARKER, subject, initial });
  const started = Date.now();
  await page.waitForSelector("#publicChatThread", { timeout: 15000 });
  results.public.startMs = Date.now() - started;
  return { subject, initial };
}

async function publicAgentAccept(a, subject, results) {
  const started = Date.now();
  await a.waitForFunction((text) => {
    document.getElementById("publicChatRefreshDashboardBtn")?.click();
    const row = Array.from(document.querySelectorAll("[data-public-chat-session]")).find((node) => node.textContent.includes(text));
    if (row) {
      row.click();
      return true;
    }
    return false;
  }, subject, { timeout: 25000, polling: 500 });
  results.public.queueAppearMs = Date.now() - started;
  await a.waitForFunction(() => Boolean(document.getElementById("publicChatDashboardAcceptBtn")) && !document.getElementById("publicChatDashboardAcceptBtn").disabled, null, { timeout: 12000 });
  await a.locator("#publicChatDashboardAcceptBtn").click();
  await a.waitForFunction(() => !document.getElementById("publicChatDashboardReplyText")?.disabled, null, { timeout: 12000 });
}

async function publicText(a, b, results) {
  const visitorMessage = `${MARKER} visitor public followup`;
  const agentMessage = `${MARKER} agent public reply`;
  await fillAndInput(b, "#publicChatReplyText", visitorMessage);
  let started = Date.now();
  await b.evaluate(() => document.getElementById("publicChatReply")?.dispatchEvent(new Event("submit", { bubbles: true, cancelable: true })));
  await a.waitForFunction((text) => Array.from(document.querySelectorAll(".public-chat-dashboard-message.visitor")).some((node) => node.textContent.includes(text)), visitorMessage, { timeout: 12000 });
  results.public.visitorToAgentMs = Date.now() - started;

  await fillAndInput(a, "#publicChatDashboardReplyText", agentMessage);
  started = Date.now();
  await a.evaluate(() => document.getElementById("publicChatDashboardReplyForm")?.dispatchEvent(new Event("submit", { bubbles: true, cancelable: true })));
  await b.waitForFunction((text) => Array.from(document.querySelectorAll(".public-chat-message.agent")).some((node) => node.textContent.includes(text)), agentMessage, { timeout: 12000 });
  results.public.agentToVisitorMs = Date.now() - started;
}

async function publicVoice(a, b, results) {
  const started = Date.now();
  await b.locator("#publicChatVoiceBtn").click();
  await b.waitForSelector("#publicChatStopRecording", { timeout: 8000 });
  await delay(1600);
  await b.locator("#publicChatStopRecording").click();
  await b.waitForSelector("#publicChatSendVoiceDraft", { timeout: 12000 });
  const preview = await testAudioPreview(b, "#publicChatVoiceDraft audio");
  await b.locator("#publicChatSendVoiceDraft").click();
  await a.waitForSelector(".public-chat-dashboard-message.visitor .live-thread-voice-note audio", { timeout: 25000 });
  const receivedPreview = await testAudioPreview(a, ".public-chat-dashboard-message.visitor .live-thread-voice-note audio");
  results.public.visitorVoice = { ok: preview.ok && receivedPreview.ok, ms: Date.now() - started, preview, receivedPreview };

  const agentStarted = Date.now();
  await a.locator("#publicChatDashboardVoiceBtn").click();
  await a.waitForSelector("#publicChatDashboardStopRecording", { timeout: 8000 });
  await delay(1600);
  await a.locator("#publicChatDashboardStopRecording").click();
  await a.waitForSelector("#publicChatDashboardSendVoiceDraft", { timeout: 12000 });
  const agentPreview = await testAudioPreview(a, "#publicChatDashboardVoiceDraft audio");
  await a.locator("#publicChatDashboardSendVoiceDraft").click();
  await b.waitForSelector(".public-chat-message.agent .live-thread-voice-note audio", { timeout: 25000 });
  const agentReceivedPreview = await testAudioPreview(b, ".public-chat-message.agent .live-thread-voice-note audio");
  results.public.agentVoice = { ok: agentPreview.ok && agentReceivedPreview.ok, ms: Date.now() - agentStarted, preview: agentPreview, receivedPreview: agentReceivedPreview };
}

async function finishPublicChat(a, b, results) {
  await b.locator("#publicChatEndBtn").click().catch(() => {});
  const feedbackVisible = await b.waitForFunction(() => {
    const modal = document.getElementById("publicChatFeedbackModal");
    return modal && !modal.hidden;
  }, null, { timeout: 10000 }).then(() => true).catch(() => false);
  if (feedbackVisible) {
    await b.evaluate(() => {
      const stars = Array.from(document.querySelectorAll("#publicChatRating button"));
      (stars[4] || stars[0])?.click();
      const comments = document.getElementById("publicChatFeedbackComments");
      if (comments) comments.value = "Browser smoke feedback";
      document.getElementById("publicChatFeedbackSend")?.click();
    });
    await b.waitForFunction(() => document.getElementById("publicChatFeedbackStatus")?.textContent.toLowerCase().includes("thank"), null, { timeout: 12000 });
    results.public.feedback = "submitted";
  } else {
    results.public.feedback = "not shown";
  }
  await a.evaluate(() => {
    if (/set offline/i.test(document.getElementById("publicChatAvailabilityBtn")?.textContent || "")) {
      document.getElementById("publicChatAvailabilityBtn")?.click();
    }
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
    browserErrors: { a: { console: [], pageErrors: [], requestFailures: [], httpErrors: [], apiRequests: [], apiResponses: [] }, b: { console: [], pageErrors: [], requestFailures: [], httpErrors: [], apiRequests: [], apiResponses: [] } }
  };

  let contextA;
  let contextB;
  try {
    contextA = await launchProfile("profile-a", 20);
    contextB = await launchProfile("profile-b", 1020);
    const pageA = contextA.pages()[0] || await contextA.newPage();
    const pageB = contextB.pages()[0] || await contextB.newPage();
    collectBrowserEvents(pageA, results.browserErrors.a);
    collectBrowserEvents(pageB, results.browserErrors.b);

    results.staff.login = { a: await login(pageA, STAFF_A), b: await login(pageB, STAFF_B) };
    await Promise.all([openDashboard(pageA), openDashboard(pageB)]);
    results.staff.threadReady = {
      a: await openStaffThread(pageA, STAFF_A.peerName),
      b: await openStaffThread(pageB, STAFF_B.peerName)
    };
    await delay(1200);
    await staffTextAndReceipts(pageA, pageB, results);
    await staffVoice(pageA, pageB, results);
    await staffVideoCall(pageA, pageB, results);
    await dismissStaffCallOutcome(pageA, pageB);

    await openPublicDashboard(pageA);
    const publicSeed = await publicVisitorStart(pageB, contextB, results);
    await publicAgentAccept(pageA, publicSeed.subject, results);
    await publicText(pageA, pageB, results);
    await publicVoice(pageA, pageB, results);
    await finishPublicChat(pageA, pageB, results);
    results.ok = true;
  } catch (error) {
    results.ok = false;
    results.error = error.message || String(error);
  } finally {
    await contextA?.close().catch(() => {});
    await contextB?.close().catch(() => {});
  }
  writeReport(results, results.ok ? 0 : 1);
}

main().catch((error) => writeReport({ ok: false, marker: MARKER, error: error.message || String(error) }, 1));
