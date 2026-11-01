/**
 * EMS Teacher Portal — Universal JS v1.0
 * Loaded on every teacher page via teacher-sidebar.php
 */

(function () {
  'use strict';

  // ── Live Clock (Bangladesh Standard Time UTC+6) ───────────────────
  function updateClock() {
    const el = document.getElementById('liveClock');
    if (!el) return;
    const now = new Date();
    const bstOffset = 6 * 60;
    const utc  = now.getTime() + now.getTimezoneOffset() * 60000;
    const bst  = new Date(utc + bstOffset * 60000);
    const h    = bst.getHours();
    const m    = String(bst.getMinutes()).padStart(2, '0');
    const s    = String(bst.getSeconds()).padStart(2, '0');
    const ampm = h >= 12 ? 'PM' : 'AM';
    const h12  = h % 12 || 12;
    el.textContent = `${String(h12).padStart(2,'0')}:${m}:${s} ${ampm}`;
  }
  updateClock();
  setInterval(updateClock, 1000);

  // ── Sidebar Toggle (desktop collapse / mobile drawer) ─────────────
  const toggle    = document.getElementById('sidebarToggle');
  const overlay   = document.getElementById('mobOverlay');
  const MOBILE_BP = 768;

  function isMobile() { return window.innerWidth <= MOBILE_BP; }
  function openMobile()  { document.body.classList.add('mob-open'); document.body.classList.remove('sidebar-collapsed'); }
  function closeMobile() { document.body.classList.remove('mob-open'); }
  function toggleDesktop() {
    document.body.classList.toggle('sidebar-collapsed');
    localStorage.setItem('ems_teacher_sb_collapsed',
      document.body.classList.contains('sidebar-collapsed') ? '1' : '0');
  }

  // Restore desktop collapse state
  if (!isMobile() && localStorage.getItem('ems_teacher_sb_collapsed') === '1') {
    document.body.classList.add('sidebar-collapsed');
  }

  if (toggle) {
    toggle.addEventListener('click', () => {
      isMobile() ? (document.body.classList.contains('mob-open') ? closeMobile() : openMobile()) : toggleDesktop();
    });
  }

  if (overlay) overlay.addEventListener('click', closeMobile);
  window.addEventListener('resize', () => { if (!isMobile()) closeMobile(); });

  // ── Active sidebar link highlight (fallback) ──────────────────────
  const currentPage = window.location.pathname.split('/').pop().split('?')[0];
  document.querySelectorAll('.nav-item[href]').forEach(link => {
    const href = link.getAttribute('href').split('/').pop().split('?')[0];
    if (href === currentPage) link.classList.add('active');
  });

  // ── Logout Modal ──────────────────────────────────────────────────
  // Intercepts all elements with data-logout or the sidebar logout btn
  const logoutUrl = document.body.dataset.logoutUrl || '/ems/logout.php';

  function openLogoutModal() {
    // Remove any existing modal
    const existing = document.getElementById('logoutModal');
    if (existing) existing.remove();

    const bd = document.createElement('div');
    bd.className = 'logout-modal-bd';
    bd.id        = 'logoutModal';
    bd.setAttribute('role', 'dialog');
    bd.setAttribute('aria-modal', 'true');
    bd.setAttribute('aria-labelledby', 'lmTitle');

    bd.innerHTML = `
      <div class="logout-modal">
        <div class="lm-icon-wrap">
          <div class="lm-icon"><i class="fas fa-right-from-bracket"></i></div>
          <div class="lm-title" id="lmTitle">Sign Out?</div>
        </div>
        <div class="lm-body">
          Are you sure you want to <strong>log out</strong>?
        </div>
        <div class="lm-actions">
          <button type="button" class="btn btn-outline" id="lmCancel">
            <i class="fas fa-xmark"></i> Cancel
          </button>
          <a href="${logoutUrl}" class="btn btn-danger" id="lmConfirm">
            <i class="fas fa-right-from-bracket"></i> Yes, Logout
          </a>
        </div>
      </div>`;

    document.body.appendChild(bd);

    // Focus the cancel button for keyboard accessibility
    setTimeout(() => { document.getElementById('lmCancel').focus(); }, 60);

    // Close handlers
    function closeModal() {
      bd.classList.add('lm-closing');
      setTimeout(() => bd.remove(), 200);
    }

    document.getElementById('lmCancel').addEventListener('click', closeModal);

    bd.addEventListener('click', e => {
      if (e.target === bd) closeModal();
    });

    document.addEventListener('keydown', function escHandler(e) {
      if (e.key === 'Escape') { closeModal(); document.removeEventListener('keydown', escHandler); }
    });
  }

  // Bind to sidebar logout button
  const sidebarLogout = document.getElementById('sidebarLogoutBtn');
  if (sidebarLogout) sidebarLogout.addEventListener('click', openLogoutModal);

  // Bind to topbar logout button
  document.querySelectorAll('[data-logout]').forEach(el => {
    el.addEventListener('click', e => { e.preventDefault(); openLogoutModal(); });
  });

  // Expose globally so pages can trigger it
  window.openLogoutModal = openLogoutModal;

  // ── Stat count-up animation ───────────────────────────────────────
  function animateCount(el) {
    const raw  = el.textContent.trim();
    const num  = parseFloat(raw.replace(/[^0-9.]/g, ''));
    const suffix = raw.replace(/[0-9.,]/g, '').trim();
    if (isNaN(num) || num === 0) return;
    const duration = 900, step = 16, steps = duration / step;
    let current = 0;
    const inc = num / steps;
    const timer = setInterval(() => {
      current += inc;
      if (current >= num) { current = num; clearInterval(timer); }
      const display = Number.isInteger(num) ? Math.round(current) : current.toFixed(1);
      el.textContent = display + (suffix ? ' ' + suffix : '');
    }, step);
  }

  const countObserver = new IntersectionObserver(entries => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        entry.target.querySelectorAll('.stat-value[data-count]').forEach(animateCount);
        countObserver.unobserve(entry.target);
      }
    });
  }, { threshold: 0.2 });

  document.querySelectorAll('.stat-card').forEach(card => countObserver.observe(card));

  // ── Auto-dismiss alerts ───────────────────────────────────────────
  document.querySelectorAll('.alert[data-auto-dismiss]').forEach(alert => {
    setTimeout(() => {
      alert.style.transition = 'opacity .4s ease, margin-bottom .4s ease, padding .4s ease';
      alert.style.opacity = '0';
      alert.style.marginBottom = '0';
      alert.style.padding = '0';
      setTimeout(() => alert.remove(), 400);
    }, 6000);
  });

  // ── Alert close button ────────────────────────────────────────────
  document.querySelectorAll('.alert .alert-close').forEach(btn => {
    btn.addEventListener('click', () => {
      const a = btn.closest('.alert');
      if (a) { a.style.opacity = '0'; setTimeout(() => a.remove(), 300); }
    });
  });

  // ── Progress bar animation on load ───────────────────────────────
  const barObserver = new IntersectionObserver(entries => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        const fill = entry.target.querySelector('.progress-fill[data-width]');
        if (fill) {
          setTimeout(() => { fill.style.width = fill.dataset.width + '%'; }, 100);
        }
        barObserver.unobserve(entry.target);
      }
    });
  }, { threshold: 0.1 });

  document.querySelectorAll('.progress-wrap').forEach(w => {
    const fill = w.querySelector('.progress-fill[data-width]');
    if (fill) { fill.style.width = '0%'; barObserver.observe(w); }
  });

})(); // end IIFE


/* ================================================================
   TOAST NOTIFICATION SYSTEM
   showToast(type, title, message, duration)
   type: 'success' | 'danger' | 'warning' | 'info'
   ================================================================ */
window.showToast = (function () {
  const ICONS  = { success:'fa-circle-check', danger:'fa-circle-exclamation', warning:'fa-triangle-exclamation', info:'fa-circle-info' };
  const TITLES = { success:'Done!', danger:'Error', warning:'Warning', info:'Info' };

  function dismiss(toast) {
    if (toast._gone) return;
    toast._gone = true;
    clearTimeout(toast._t);
    toast.style.transition = 'opacity .25s ease, transform .25s ease';
    toast.style.opacity    = '0';
    toast.style.transform  = 'translateX(20px)';
    setTimeout(() => { if (toast.parentNode) toast.parentNode.removeChild(toast); }, 270);
  }

  return function showToast(type, title, message, duration) {
    type     = type     || 'info';
    title    = title    || TITLES[type];
    message  = message  || '';
    duration = duration || 4500;

    let box = document.getElementById('ems-toast-box');
    if (!box) {
      box = document.createElement('div');
      box.id = 'ems-toast-box';
      document.body.appendChild(box);
    }

    const t = document.createElement('div');
    t.className = 'ems-toast ems-toast-' + type;
    t.setAttribute('role', 'status');
    t.innerHTML =
      '<div class="et-icon"><i class="fas ' + (ICONS[type] || 'fa-circle-info') + '"></i></div>' +
      '<div class="et-body"><div class="et-title">' + title + '</div>' +
      (message ? '<div class="et-msg">' + message + '</div>' : '') + '</div>' +
      '<button class="et-close" aria-label="Close notification"><i class="fas fa-xmark"></i></button>' +
      '<div class="et-bar" style="transition:width ' + (duration/1000) + 's linear;width:100%"></div>';

    box.appendChild(t);

    const bar = t.querySelector('.et-bar');
    requestAnimationFrame(() => requestAnimationFrame(() => { bar.style.width = '0%'; }));

    t.querySelector('.et-close').addEventListener('click', () => dismiss(t));
    t._t = setTimeout(() => dismiss(t), duration);

    return t;
  };
})();