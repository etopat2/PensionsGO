document.addEventListener('DOMContentLoaded', () => {
  if (window.__broadcastCheckerRunning) return;
  if (!sessionStorage.getItem('userLoggedIn')) return;
  const SEEN_KEY = "pensionsgo_seen_broadcasts";

  function showBroadcastAlert(broadcast) {
    const existing = document.getElementById('broadcastAlertModal');
    if (existing) existing.remove();

    const modal = document.createElement('div');
    modal.className = 'global-broadcast-overlay';
    modal.id = 'broadcastAlertModal';
    modal.innerHTML = `
      <div class="broadcast-popup">
        <div class="broadcast-header">
          <i class="fas fa-bullhorn"></i>
          <span>New Broadcast</span>
        </div>
        <div class="broadcast-body">
          <h4>${broadcast.subject || 'Broadcast Message'}</h4>
          <p>${(broadcast.message_preview || '').trim()}...</p>
          <small>From: ${broadcast.sender_name || 'System'}</small>
        </div>
        <div class="broadcast-actions">
          <button class="btn-dismiss" id="dismissBroadcast">Dismiss</button>
          <button class="btn-view" id="viewBroadcast">View Message</button>
        </div>
      </div>
    `;
    document.body.appendChild(modal);
    document.body.classList.add('modal-open');

    document.getElementById('dismissBroadcast').onclick = () => {
      modal.remove();
      document.body.classList.remove('modal-open');
    };
    document.getElementById('viewBroadcast').onclick = () => {
      document.body.classList.remove('modal-open');
      window.location.href = 'messages.html#broadcast';
    };

    modal.addEventListener('click', (e) => {
      if (e.target === modal) {
        modal.remove();
        document.body.classList.remove('modal-open');
      }
    });
  }

  async function checkNewBroadcasts() {
    try {
      if (window.AppSettingsManager && !window.AppSettingsManager.isBroadcastNotificationsEnabled()) {
        return;
      }
      const res = await fetch('../backend/api/check_broadcasts.php', { credentials: 'include' });
      const data = await res.json();
      if (data.success && data.has_new && data.latest_broadcast) {
        const b = data.latest_broadcast;
        const bId = b.broadcast_id || b.message_id || b.id;
        if (!bId) return;
        const seen = JSON.parse(localStorage.getItem(SEEN_KEY) || '[]');
        if (seen.includes(String(bId))) return;
        seen.push(String(bId));
        localStorage.setItem(SEEN_KEY, JSON.stringify(seen));
        showBroadcastAlert(b);
      }
    } catch (err) {
      console.warn('Broadcast check failed', err);
    }
  }

  setInterval(checkNewBroadcasts, 30000);
  checkNewBroadcasts();
});
