/**
 * EMS Student Portal — Universal JS
 * Loaded on every student page via student-sidebar.php
 */

(function () {
  'use strict';

  // ── Live Clock (Bangladesh Standard Time) ─────────────────────────
  function updateClock() {
    const el = document.getElementById('liveClock');
    if (!el) return;
    const now = new Date();
    const bstOffset = 6 * 60;
    const utc = now.getTime() + now.getTimezoneOffset() * 60000;
    const bst = new Date(utc + bstOffset * 60000);
    const h = bst.getHours();
    const m = String(bst.getMinutes()).padStart(2, '0');
    const ampm = h >= 12 ? 'PM' : 'AM';
    const h12 = h % 12 || 12;
    el.textContent = `${String(h12).padStart(2, '0')}:${m} ${ampm}`;
  }
  updateClock();
  setInterval(updateClock, 30000);

  // ── Sidebar Toggle (desktop collapse / mobile drawer) ─────────────
  const toggle = document.getElementById('sidebarToggle');
  const overlay = document.getElementById('mobOverlay');
  const MOBILE_BP = 768;

  function isMobile() { return window.innerWidth <= MOBILE_BP; }

  function openMobile() {
    document.body.classList.add('mob-open');
    document.body.classList.remove('sidebar-collapsed');
  }
  function closeMobile() {
    document.body.classList.remove('mob-open');
  }
  function toggleDesktop() {
    document.body.classList.toggle('sidebar-collapsed');
    localStorage.setItem('ems_student_sidebar_collapsed',
      document.body.classList.contains('sidebar-collapsed') ? '1' : '0');
  }

  if (!isMobile() && localStorage.getItem('ems_student_sidebar_collapsed') === '1') {
    document.body.classList.add('sidebar-collapsed');
  }

  if (toggle) {
    toggle.addEventListener('click', () => {
      if (isMobile()) {
        document.body.classList.contains('mob-open') ? closeMobile() : openMobile();
      } else {
        toggleDesktop();
      }
    });
  }

  if (overlay) overlay.addEventListener('click', closeMobile);

  window.addEventListener('resize', () => {
    if (!isMobile()) closeMobile();
  });

  // ── Collapsible nav groups (Exams: Results / Admit Card / Seat Plan) ──
  document.querySelectorAll('.nav-parent').forEach(parent => {
    parent.addEventListener('click', e => {
      e.preventDefault();
      const sub = parent.nextElementSibling;
      if (!sub || !sub.classList.contains('nav-sub')) return;
      const isOpen = parent.classList.contains('open');
      parent.classList.toggle('open', !isOpen);
      sub.classList.toggle('open', !isOpen);
    });
  });

  // ── Logout Confirmation Modal (custom — NOT browser confirm()) ───
  const logoutModal   = document.getElementById('logoutModal');
  const logoutTriggers = document.querySelectorAll('[data-logout-trigger]');
  const logoutCancel  = document.getElementById('logoutCancel');
  const logoutConfirm = document.getElementById('logoutConfirm');

  function openLogoutModal(e) {
    if (e) e.preventDefault();
    if (logoutModal) logoutModal.classList.add('show');
  }
  function closeLogoutModal() {
    if (logoutModal) logoutModal.classList.remove('show');
  }

  logoutTriggers.forEach(btn => btn.addEventListener('click', openLogoutModal));
  if (logoutCancel) logoutCancel.addEventListener('click', closeLogoutModal);
  if (logoutModal) {
    logoutModal.addEventListener('click', (e) => {
      if (e.target === logoutModal) closeLogoutModal();
    });
  }
  if (logoutConfirm) {
    logoutConfirm.addEventListener('click', () => {
      window.location.href = logoutConfirm.dataset.href;
    });
  }
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeLogoutModal();
  });

  // ── Auto-dismiss alerts ────────────────────────────────────────────
  document.querySelectorAll('.alert[data-auto-dismiss]').forEach(alert => {
    setTimeout(() => {
      alert.style.transition = 'opacity .4s ease, margin-bottom .4s ease';
      alert.style.opacity = '0';
      alert.style.marginBottom = '0';
      setTimeout(() => alert.remove(), 400);
    }, 6000);
  });

  // ── Active sidebar / tabbar link highlighting (fallback) ──────────
  const currentPath = window.location.pathname.split('/').pop();
  document.querySelectorAll('.nav-item[href], .mt-item[href]').forEach(link => {
    const href = link.getAttribute('href').split('/').pop().split('?')[0];
    if (href === currentPath) link.classList.add('active');
  });

})();