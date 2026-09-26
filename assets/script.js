console.log('Script v16.0 loaded');

const API = 'api.php';
const PREVIEW = 'preview.php';
const $ = (id) => document.getElementById(id);

const urlInput = $('urlInput');
const downloadBtn = $('downloadBtn');
const clearBtn = $('clearBtn');
const skeleton = $('skeleton');
const errorDiv = $('error');
const errorText = $('errorText');
const resultDiv = $('result');
const hint = $('hint');
const toast = $('toast');

const PLATFORMS = {
  instagram: /instagram\.com\/(p|reel|reels|tv|stories)\//i,
  tiktok: /(tiktok\.com|vt\.tiktok\.com|vm\.tiktok\.com)/i,
  youtube: /(youtube\.com|youtu\.be)/i,
  facebook: /(facebook\.com|fb\.watch|fb\.com)/i,
  twitter: /(twitter\.com|x\.com)/i,
  threads: /threads\.(net|com)/i,
  pinterest: /pinterest\.(com|co\.\w+)/i,
  reddit: /(reddit\.com|redd\.it)/i,
};

const detectPlatform = (url) => {
  for (const [name, re] of Object.entries(PLATFORMS)) if (re.test(url)) return name;
  return null;
};

const isValidUrl = (url) => {
  try { new URL(url); return true; } catch { return false; }
};

function showError(msg) {
  errorText.textContent = msg;
  errorDiv.classList.remove('hidden');
  resultDiv.classList.add('hidden');
}

function hideMessages() {
  errorDiv.classList.add('hidden');
  resultDiv.classList.add('hidden');
}

function showToast(msg, type = 'success') {
  toast.textContent = msg;
  toast.className = 'toast show ' + type;
  setTimeout(() => toast.className = 'toast hidden', 2200);
}

function formatBytes(bytes) {
  if (!bytes) return '';
  const k = 1024, sizes = ['B', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return (bytes / Math.pow(k, i)).toFixed(1) + ' ' + sizes[i];
}

function formatDuration(sec) {
  if (!sec || isNaN(sec)) return '';
  sec = Math.floor(sec);
  const m = Math.floor(sec / 60);
  const s = sec % 60;
  return m + ':' + s.toString().padStart(2, '0');
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str;
  return div.innerHTML;
}

function truncate(str, n) {
  return str.length > n ? str.substring(0, n) + '...' : str;
}

async function fetchMedia(url) {
  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), 300000);
  try {
    const res = await fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ url }),
      signal: controller.signal,
    });
    clearTimeout(timeoutId);
    const text = await res.text();
    let data;
    try { data = JSON.parse(text); } catch { throw new Error('Invalid server response'); }
    if (!res.ok || !data.success) throw new Error(data.message || 'Server error (' + res.status + ')');
    return data;
  } catch (err) {
    clearTimeout(timeoutId);
    if (err.name === 'AbortError') throw new Error('Request timeout');
    throw err;
  }
}

// ============ VIDEO PREVIEW WITH AUDIO (via preview.php) ============
function createVideoPreview(media, data) {
  const wrap = document.createElement('div');
  wrap.className = 'preview';

  // Pakai preview.php (yang merge video+audio via yt-dlp + ffmpeg)
  const sourceUrl = data.source_url || urlInput.value.trim();
  const videoSrc = sourceUrl
    ? PREVIEW + '?source=' + encodeURIComponent(sourceUrl)
    : media.url;

  const video = document.createElement('video');
  video.src = videoSrc;
  video.playsInline = true;
  video.preload = 'metadata';
  video.setAttribute('webkit-playsinline', 'true');
  video.setAttribute('playsinline', 'true');
  video.setAttribute('disablepictureinpicture', 'true');
  video.setAttribute('controlslist', 'nodownload nofullscreen noremoteplayback');
  video.removeAttribute('controls');

  const overlay = document.createElement('div');
  overlay.className = 'play-overlay';
  overlay.innerHTML = 
    '<div class="play-btn">' +
      '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>' +
    '</div>' +
    '<div class="play-text">Tap untuk preview</div>';

  // Loading indicator saat video dimuat
  let loadingIndicator = null;
  function showVideoLoading() {
    if (loadingIndicator) return;
    loadingIndicator = document.createElement('div');
    loadingIndicator.className = 'video-loading';
    loadingIndicator.innerHTML = '<div class="video-spinner"></div><span>Memuat video...</span>';
    wrap.appendChild(loadingIndicator);
  }

  function hideVideoLoading() {
    if (loadingIndicator) {
      loadingIndicator.remove();
      loadingIndicator = null;
    }
  }

  // Loading state
  video.addEventListener('loadstart', showVideoLoading);
  video.addEventListener('waiting', showVideoLoading);
  video.addEventListener('canplay', hideVideoLoading);
  video.addEventListener('playing', hideVideoLoading);

  // Setelah metadata loaded → kasih durasi
  video.addEventListener('loadedmetadata', () => {
    hideVideoLoading();
    const duration = video.duration;
    if (duration && !isNaN(duration)) {
      // Update meta info dengan durasi
      const metaEl = document.querySelector('.dl-meta');
      if (metaEl && !metaEl.querySelector('.dur-item')) {
        const durSpan = document.createElement('span');
        durSpan.className = 'dur-item';
        durSpan.textContent = formatDuration(duration);
        
        // Insert durasi setelah quality
        const dot = document.createElement('span');
        dot.className = 'dl-meta-dot';
        
        metaEl.insertBefore(dot, metaEl.firstChild);
        metaEl.insertBefore(durSpan, metaEl.firstChild);
      }
    }
  });

  // Overlay click → play with sound
  overlay.addEventListener('click', (e) => {
    e.stopPropagation();
    video.muted = false;
    video.volume = 1;
    video.play().catch((err) => {
      console.log('Play error:', err);
      // Kalau autoplay blocked, coba muted dulu terus unmute
      video.muted = true;
      video.play().then(() => {
        video.muted = false;
      }).catch(() => {});
    });
    overlay.classList.add('hidden');
  });

  // Klik video → toggle play/pause
  video.addEventListener('click', (e) => {
    e.preventDefault();
    if (video.paused) {
      video.muted = false;
      video.play().catch(() => {});
      overlay.classList.add('hidden');
    } else {
      video.pause();
    }
  });

  // Pause → overlay muncul lagi
  video.addEventListener('pause', () => {
    if (!video.ended) overlay.classList.remove('hidden');
  });

  // Video selesai → overlay muncul
  video.addEventListener('ended', () => {
    overlay.classList.remove('hidden');
  });

  // Fallback: kalau video error → pakai thumbnail
  video.addEventListener('error', () => {
    hideVideoLoading();
    console.log('Video error, fallback ke thumbnail');
    if (data.thumbnail) {
      wrap.innerHTML = '';
      const img = document.createElement('img');
      img.src = data.thumbnail;
      img.alt = 'Preview';
      img.referrerPolicy = 'no-referrer';
      wrap.appendChild(img);
    }
  });

  wrap.appendChild(video);
  wrap.appendChild(overlay);
  return wrap;
}

function createImagePreview(media, data) {
  const wrap = document.createElement('div');
  wrap.className = 'preview';
  const img = document.createElement('img');
  img.src = media.url;
  img.alt = 'Preview';
  img.referrerPolicy = 'no-referrer';
  img.onerror = () => {
    if (data.thumbnail) img.src = data.thumbnail;
    else wrap.style.display = 'none';
  };
  wrap.appendChild(img);
  return wrap;
}

function renderResult(data) {
  resultDiv.innerHTML = '';

  const head = document.createElement('div');
  head.className = 'result-head';
  head.innerHTML = 
    '<span class="plat-badge ' + data.platform + '">' + data.platform + '</span>' +
    '<div class="meta">' +
      '<div class="meta-user">@' + escapeHtml(data.username || 'unknown') + '</div>' +
      (data.title ? '<div class="meta-title">' + escapeHtml(truncate(data.title, 100)) + '</div>' : '') +
    '</div>';
  resultDiv.appendChild(head);

  const medias = data.medias || [];
  const first = medias[0];

  if (first) {
    const preview = first.type === 'video'
      ? createVideoPreview(first, data)
      : createImagePreview(first, data);
    resultDiv.appendChild(preview);
  }

  // Progress bar
  const progressWrap = document.createElement('div');
  progressWrap.className = 'dl-progress hidden';
  progressWrap.id = 'dlProgress';
  progressWrap.innerHTML = 
    '<div class="dl-progress-header">' +
      '<span class="dl-progress-label">Menyiapkan video...</span>' +
      '<span class="dl-progress-percent">0%</span>' +
    '</div>' +
    '<div class="dl-progress-track"><div class="dl-progress-fill"></div></div>';
  resultDiv.appendChild(progressWrap);

  // Tombol Download
  const btn = document.createElement('button');
  btn.className = 'dl-main';
  btn.id = 'dlMainBtn';
  btn.innerHTML = 
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">' +
      '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>' +
      '<polyline points="7 10 12 15 17 10"/>' +
      '<line x1="12" y1="15" x2="12" y2="3"/>' +
    '</svg>' +
    '<span>Download Video HD</span>';
  btn.onclick = () => handleDownloadWithProgress(first, data.platform, data.source_url || urlInput.value.trim());
  resultDiv.appendChild(btn);

  // Meta
  const meta = document.createElement('div');
  meta.className = 'dl-meta';
  const parts = [];
  if (first.quality) parts.push(first.quality.toUpperCase());
  if (first.filesize) parts.push(formatBytes(first.filesize));
  parts.push((first.ext || 'mp4').toUpperCase());
  if (medias.length > 1) parts.push(medias.length + ' media');
  meta.innerHTML = parts.map((p, i) =>
    (i > 0 ? '<span class="dl-meta-dot"></span>' : '') + '<span>' + p + '</span>'
  ).join('');
  resultDiv.appendChild(meta);

  resultDiv.classList.remove('hidden');
}

// ============ DOWNLOAD WITH PROGRESS ============
function handleDownloadWithProgress(media, platform, sourceUrl) {
  const filename = platform + '_' + Date.now() + '.' + (media.ext || 'mp4');
  const progressWrap = document.getElementById('dlProgress');
  const progressFill = progressWrap.querySelector('.dl-progress-fill');
  const progressPercent = progressWrap.querySelector('.dl-progress-percent');
  const progressLabel = progressWrap.querySelector('.dl-progress-label');
  const dlBtn = document.getElementById('dlMainBtn');

  let url;
  if (sourceUrl && media.type === 'video') {
    url = 'proxy.php?mode=download&source=' + encodeURIComponent(sourceUrl) + '&filename=' + encodeURIComponent(filename);
  } else {
    url = 'proxy.php?url=' + encodeURIComponent(media.url) + '&filename=' + encodeURIComponent(filename);
  }

  progressWrap.classList.remove('hidden');
  dlBtn.disabled = true;
  dlBtn.innerHTML = 
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="animation: spin 1s linear infinite;">' +
      '<path d="M21 12a9 9 0 1 1-6.219-8.56"/>' +
    '</svg>' +
    '<span>Memproses...</span>';

  const xhr = new XMLHttpRequest();
  xhr.open('GET', url, true);
  xhr.responseType = 'blob';

  let lastLoaded = 0;
  let lastTime = Date.now();

  xhr.onprogress = (e) => {
    if (e.lengthComputable) {
      const percent = Math.min(100, Math.round((e.loaded / e.total) * 100));
      progressFill.style.width = percent + '%';
      progressPercent.textContent = percent + '%';
      progressLabel.textContent = 'Downloading... ' + formatBytes(e.loaded) + ' / ' + formatBytes(e.total);
    } else {
      const loaded = e.loaded;
      const now = Date.now();
      const speed = (loaded - lastLoaded) / ((now - lastTime) / 1000);
      lastLoaded = loaded;
      lastTime = now;
      progressLabel.textContent = 'Downloading... ' + formatBytes(loaded) + (speed > 0 ? ' (' + formatBytes(speed) + '/s)' : '');
      const fakePercent = Math.min(95, Math.round((loaded / (loaded + 500000)) * 100));
      progressFill.style.width = fakePercent + '%';
      progressPercent.textContent = fakePercent + '%';
    }
  };

  xhr.onload = () => {
    if (xhr.status === 200) {
      progressFill.style.width = '100%';
      progressPercent.textContent = '100%';
      progressLabel.textContent = 'Selesai! Menyimpan file...';

      const blob = xhr.response;
      const blobUrl = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = blobUrl;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      setTimeout(() => window.URL.revokeObjectURL(blobUrl), 30000);

      setTimeout(() => {
        progressWrap.classList.add('hidden');
        progressFill.style.width = '0%';
        dlBtn.disabled = false;
        dlBtn.innerHTML = 
          '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">' +
            '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>' +
            '<polyline points="7 10 12 15 17 10"/>' +
            '<line x1="12" y1="15" x2="12" y2="3"/>' +
          '</svg>' +
          '<span>Download Video HD</span>';
      }, 800);
    } else {
      progressWrap.classList.add('hidden');
      dlBtn.disabled = false;
      showError('Gagal download (HTTP ' + xhr.status + ')');
    }
  };

  xhr.onerror = () => {
    progressWrap.classList.add('hidden');
    dlBtn.disabled = false;
    showError('Error koneksi saat download');
  };

  xhr.timeout = 600000;
  xhr.send();
}

async function handleSubmit() {
  const url = urlInput.value.trim();
  if (!url) { showError('Masukkan link terlebih dahulu'); urlInput.focus(); return; }
  if (!isValidUrl(url)) { showError('Format URL tidak valid'); return; }
  const platform = detectPlatform(url);
  if (!platform) { showError('Platform tidak didukung'); return; }

  hideMessages();
  skeleton.classList.remove('hidden');
  downloadBtn.disabled = true;
  hint.classList.add('hidden');

  try {
    const data = await fetchMedia(url);
    skeleton.classList.add('hidden');
    renderResult(data);
    setTimeout(() => resultDiv.scrollIntoView({ behavior: 'smooth', block: 'center' }), 80);
  } catch (err) {
    skeleton.classList.add('hidden');
    showError(err.message || 'Terjadi kesalahan');
  } finally {
    downloadBtn.disabled = false;
  }
}

downloadBtn.addEventListener('click', handleSubmit);

urlInput.addEventListener('keypress', (e) => {
  if (e.key === 'Enter') handleSubmit();
});

urlInput.addEventListener('input', () => {
  clearBtn.classList.toggle('hidden', !urlInput.value);
  hint.classList.toggle('hidden', !!urlInput.value);
});

clearBtn.addEventListener('click', () => {
  urlInput.value = '';
  urlInput.focus();
  clearBtn.classList.add('hidden');
  hint.classList.remove('hidden');
  hideMessages();
});

urlInput.addEventListener('paste', () => {
  setTimeout(() => {
    const url = urlInput.value.trim();
    if (url && detectPlatform(url)) handleSubmit();
  }, 150);
});

/* ============ DRAWER MENU ============ */
const burgerBtn = document.getElementById('burgerBtn');
const drawer = document.getElementById('drawer');
const drawerOverlay = document.getElementById('drawerOverlay');
const drawerClose = document.getElementById('drawerClose');

function openDrawer() {
  drawer.classList.add('show');
  drawerOverlay.classList.add('show');
  document.body.style.overflow = 'hidden';
}

function closeDrawer() {
  drawer.classList.remove('show');
  drawerOverlay.classList.remove('show');
  document.body.style.overflow = '';
}

if (burgerBtn) burgerBtn.addEventListener('click', openDrawer);
if (drawerClose) drawerClose.addEventListener('click', closeDrawer);
if (drawerOverlay) drawerOverlay.addEventListener('click', closeDrawer);

document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') closeDrawer();
});

/* ============ TAB PER PLATFORM ============ */
const PLATFORM_DATA = {
  default: { badge: 'Downloader Video HD', title: 'Download Video dari <em>Instagram, TikTok & YouTube</em>', sub: 'Simpan video, foto, Reels, Shorts, dan carousel dalam kualitas HD. Gratis, cepat, tanpa login.', placeholder: 'Tempel link video di sini (Instagram, TikTok, YouTube...)' },
  instagram: { badge: 'Instagram Downloader', title: 'Download <em>Instagram</em> Video & Reels HD', sub: 'Simpan Reels, IGTV, Post video, carousel foto, dan story Instagram dalam kualitas HD tanpa watermark.', placeholder: 'Tempel link Instagram (Reels, Post, IGTV, Story)...' },
  tiktok: { badge: 'TikTok Downloader', title: 'Download <em>TikTok</em> Video No Watermark HD', sub: 'Unduh video TikTok tanpa watermark, kualitas HD. Support slideshow foto dan video dengan musik.', placeholder: 'Tempel link TikTok di sini...' },
  youtube: { badge: 'YouTube Downloader', title: 'Download <em>YouTube</em> Video & Shorts HD', sub: 'Simpan video YouTube dalam MP4 kualitas HD, atau ekstrak audio MP3 dari video favorit kamu.', placeholder: 'Tempel link YouTube (video atau Shorts)...' },
  facebook: { badge: 'Facebook Downloader', title: 'Download <em>Facebook</em> Video HD', sub: 'Unduh video dari Facebook, Reels, dan Watch dalam kualitas HD.', placeholder: 'Tempel link Facebook di sini...' },
  twitter: { badge: 'Twitter/X Downloader', title: 'Download <em>Twitter/X</em> Video HD', sub: 'Simpan video dan GIF dari Twitter/X dalam kualitas asli tanpa kompresi.', placeholder: 'Tempel link Twitter/X di sini...' },
  threads: { badge: 'Threads Downloader', title: 'Download <em>Threads</em> Video & Foto', sub: 'Unduh video dan foto dari postingan Threads dengan mudah.', placeholder: 'Tempel link Threads di sini...' },
  pinterest: { badge: 'Pinterest Downloader', title: 'Download <em>Pinterest</em> Video & Gambar HD', sub: 'Simpan video dan gambar Pinterest dalam kualitas HD.', placeholder: 'Tempel link Pinterest di sini...' },
  reddit: { badge: 'Reddit Downloader', title: 'Download <em>Reddit</em> Video & GIF HD', sub: 'Unduh video dan GIF dari Reddit dalam kualitas HD tanpa watermark.', placeholder: 'Tempel link Reddit di sini...' },
};

const heroSection = document.getElementById('heroSection');
const heroBadgeText = document.getElementById('heroBadgeText');
const heroTitle = document.getElementById('heroTitle');
const heroSub = document.getElementById('heroSub');

function switchTab(platform) {
  const data = PLATFORM_DATA[platform] || PLATFORM_DATA.default;
  heroSection.classList.add('tab-switching');
  setTimeout(() => {
    heroBadgeText.textContent = data.badge;
    heroTitle.innerHTML = data.title;
    heroSub.textContent = data.sub;
    if (urlInput) urlInput.placeholder = data.placeholder;
    const inputWrap = document.querySelector('.input-wrap');
    if (inputWrap) inputWrap.setAttribute('data-platform', platform);
    heroSection.classList.remove('tab-switching');
  }, 200);
  document.querySelectorAll('.drawer-item').forEach(el => {
    el.classList.toggle('active', el.dataset.tab === platform);
  });
  document.title = 'Download ' + (platform.charAt(0).toUpperCase() + platform.slice(1)) + ' — Multi Downloader';
}

document.querySelectorAll('.drawer-item[data-tab]').forEach(el => {
  el.addEventListener('click', (e) => {
    e.preventDefault();
    const tab = el.dataset.tab;
    switchTab(tab);
    closeDrawer();
    setTimeout(() => {
      heroSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
      setTimeout(() => urlInput.focus(), 400);
    }, 350);
  });
});

document.querySelectorAll('.plat').forEach(el => {
  el.addEventListener('click', () => switchTab(el.dataset.platform));
});
