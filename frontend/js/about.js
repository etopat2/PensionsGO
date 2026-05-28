document.addEventListener('DOMContentLoaded', () => {
  loadPublicPodcastPreview().catch((error) => {
    console.error('Unable to load public podcast preview:', error);
  });
});

async function loadPublicPodcastPreview() {
  const cta = document.getElementById('publicPodcastCta');
  const preview = document.getElementById('publicPodcastPreview');
  const heroButton = document.getElementById('publicPodcastHeroButton');
  if (!cta || !preview) return;

  const response = await fetch('../backend/api/get_public_podcast_feed.php', {
    cache: 'no-store',
    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
  });
  const data = await response.json().catch(() => ({ success: false }));
  if (!response.ok || !data.success || data.ctaEnabled === false) {
    cta.hidden = true;
    if (heroButton) heroButton.hidden = true;
    return;
  }

  cta.hidden = false;
  if (heroButton) heroButton.hidden = false;
  const items = Array.isArray(data.items) ? data.items.slice(0, 2) : [];
  if (!items.length) {
    preview.innerHTML = '<div class="about-podcast-preview-card">Public videos will appear here once they are published.</div>';
    return;
  }

  preview.innerHTML = items.map((item) => `
    <article class="about-podcast-preview-card">
      <img src="${escapeHtml(item.thumbnailUrl || '')}" alt="${escapeHtml(item.title || 'Podcast video')} thumbnail" loading="lazy">
      <div>
        <strong>${escapeHtml(item.title || 'Podcast video')}</strong>
        <p>${escapeHtml(item.description || 'Approved public pension guidance video.')}</p>
      </div>
    </article>
  `).join('');
}

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}
