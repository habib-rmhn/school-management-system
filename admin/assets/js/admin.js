/**
 * EMS Admin Panel — Universal JS
 * Loaded on every admin page via admin-sidebar.php
 */

(function () {
  'use strict';

  // ── Live Clock (Bangladesh Standard Time) ─────────────────────────
  function updateClock() {
    const el = document.getElementById('liveClock');
    if (!el) return;
    const now = new Date();
    // Format in BST — the server already outputs BST dates via PHP,
    // but the JS Date() is client-side. Show server's BST using offset.
    const bstOffset = 6 * 60; // UTC+6 in minutes
    const utc   = now.getTime() + now.getTimezoneOffset() * 60000;
    const bst   = new Date(utc + bstOffset * 60000);
    const h   = bst.getHours();
    const m   = String(bst.getMinutes()).padStart(2, '0');
    const s   = String(bst.getSeconds()).padStart(2, '0');
    const ampm = h >= 12 ? 'PM' : 'AM';
    const h12  = h % 12 || 12;
    el.textContent = `${String(h12).padStart(2,'0')}:${m}:${s} ${ampm}`;
  }
  updateClock();
  setInterval(updateClock, 1000);

  // ── Sidebar Toggle (desktop collapse / mobile open) ───────────────
  const toggle   = document.getElementById('sidebarToggle');
  const overlay  = document.getElementById('mobOverlay');
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
    localStorage.setItem('ems_sidebar_collapsed',
      document.body.classList.contains('sidebar-collapsed') ? '1' : '0');
  }

  // Restore desktop collapse state
  if (!isMobile() && localStorage.getItem('ems_sidebar_collapsed') === '1') {
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

  // Close mobile sidebar on resize to desktop
  window.addEventListener('resize', () => {
    if (!isMobile()) closeMobile();
  });

  // ── Collapsible nav groups ─────────────────────────────────────────
  document.querySelectorAll('.nav-parent').forEach(parent => {
    parent.addEventListener('click', e => {
      e.preventDefault();
      const sub = parent.nextElementSibling;
      if (!sub || !sub.classList.contains('nav-sub')) return;

      // Toggle this group
      const isOpen = parent.classList.contains('open');
      parent.classList.toggle('open', !isOpen);
      sub.classList.toggle('open', !isOpen);
    });
  });

  // ── Confirm dialogs (data-confirm attribute) ──────────────────────
  document.addEventListener('click', e => {
    const el = e.target.closest('[data-confirm]');
    if (!el) return;
    const msg = el.dataset.confirm || 'Are you sure?';
    if (!confirm(msg)) e.preventDefault();
  });

  // ── Logout confirmation (custom modal, not native confirm()) ──────
  document.addEventListener('click', e => {
    const el = e.target.closest('[data-logout-confirm]');
    if (!el) return;
    e.preventDefault();
    const msg  = el.dataset.logoutConfirm || 'Are you sure you want to log out?';
    const href = el.getAttribute('href');
    window.openModal({
      icon: 'fa-right-from-bracket',
      iconClass: 'icon-warning',
      title: 'Log Out?',
      subtitle: msg,
      confirmLabel: 'Yes, Logout',
      confirmClass: 'btn-danger',
      onConfirm: function () {
        window.location.href = href;
      }
    });
  });

  // ── Auto-dismiss alerts after 6 seconds ───────────────────────────
  document.querySelectorAll('.alert[data-auto-dismiss]').forEach(alert => {
    setTimeout(() => {
      alert.style.transition = 'opacity .4s ease, margin-bottom .4s ease';
      alert.style.opacity = '0';
      alert.style.marginBottom = '0';
      setTimeout(() => alert.remove(), 400);
    }, 6000);
  });

  // ── Flash message in URL (?flash=success&msg=...) ─────────────────
  // (Handled server-side in PHP; this just enables dismiss button)
  document.querySelectorAll('.alert .alert-close').forEach(btn => {
    btn.addEventListener('click', () => {
      const alert = btn.closest('.alert');
      if (alert) {
        alert.style.opacity = '0';
        setTimeout(() => alert.remove(), 300);
      }
    });
  });

  // ── Animate stat numbers (count-up) ──────────────────────────────
  function animateCount(el) {
    const raw = el.textContent.trim();
    const num  = parseFloat(raw.replace(/[^0-9.]/g, ''));
    const suffix = raw.replace(/[0-9.,]/g, '').trim();
    if (isNaN(num) || num === 0) return;
    const duration = 900;
    const step     = 16;
    const steps    = duration / step;
    let current    = 0;
    const increment = num / steps;
    const timer = setInterval(() => {
      current += increment;
      if (current >= num) { current = num; clearInterval(timer); }
      const display = Number.isInteger(num) ? Math.round(current) : current.toFixed(2);
      el.textContent = display + (suffix ? ' ' + suffix : '');
    }, step);
  }

  // Trigger on visibility
  const observer = new IntersectionObserver(entries => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        entry.target.querySelectorAll('.stat-value[data-count]').forEach(animateCount);
        observer.unobserve(entry.target);
      }
    });
  }, { threshold: 0.2 });

  document.querySelectorAll('.stat-card').forEach(card => observer.observe(card));

  // ── Active sidebar link highlighting (fallback) ───────────────────
  const currentPath = window.location.pathname.split('/').pop();
  document.querySelectorAll('.nav-item[href]').forEach(link => {
    const href = link.getAttribute('href').split('/').pop().split('?')[0];
    if (href === currentPath) link.classList.add('active');
  });

})();
/* ================================================================
   TOAST NOTIFICATION SYSTEM
   showToast(type, title, message, duration)
   type: 'success' | 'danger' | 'warning' | 'info'
   ================================================================ */
window.showToast = (function () {
  const ICONS  = { success:'fa-circle-check', danger:'fa-circle-exclamation', warning:'fa-triangle-exclamation', info:'fa-circle-info' };
  const TITLES = { success:'Success', danger:'Error', warning:'Warning', info:'Info' };

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
    duration = duration || 5000;

    let box = document.getElementById('ems-toast-box');
    if (!box) {
      box = document.createElement('div');
      box.id = 'ems-toast-box';
      document.body.appendChild(box);
    }

    const t = document.createElement('div');
    t.className = 'ems-toast ems-toast-' + type;
    t.innerHTML =
      '<div class="et-icon"><i class="fas ' + (ICONS[type] || 'fa-circle-info') + '"></i></div>' +
      '<div class="et-body"><div class="et-title">' + title + '</div>' +
      (message ? '<div class="et-msg">' + message + '</div>' : '') + '</div>' +
      '<button class="et-close" aria-label="Close"><i class="fas fa-xmark"></i></button>' +
      '<div class="et-bar"></div>';

    box.appendChild(t);

    const bar = t.querySelector('.et-bar');
    requestAnimationFrame(() => requestAnimationFrame(() => {
      bar.style.transition = 'width ' + duration + 'ms linear';
      bar.style.width = '0%';
    }));

    t._t = setTimeout(() => dismiss(t), duration);
    t.querySelector('.et-close').addEventListener('click', () => dismiss(t));
    t.addEventListener('mouseenter', () => { clearTimeout(t._t); bar.style.transition = 'none'; });
    t.addEventListener('mouseleave', () => {
      const rem = (parseFloat(bar.style.width || '100') / 100) * duration;
      if (rem < 200) { dismiss(t); return; }
      bar.style.transition = 'width ' + rem + 'ms linear';
      bar.style.width = '0%';
      t._t = setTimeout(() => dismiss(t), rem);
    });
  };
})();

/* ================================================================
   CONFIRM MODAL
   openModal({ icon, iconClass, title, subtitle, body,
               confirmLabel, confirmClass, onConfirm })
   iconClass: 'icon-danger' | 'icon-warning' | 'icon-info' | 'icon-success'
   confirmClass: 'btn-danger' | 'btn-primary'
   ================================================================ */
window.openModal = (function () {
  let _active = null;

  function close() {
    if (!_active) return;
    const b = _active;
    _active  = null;
    b.classList.add('em-closing');
    setTimeout(() => { if (b.parentNode) b.parentNode.removeChild(b); }, 200);
  }

  function onEsc(e) { if (e.key === 'Escape') { close(); document.removeEventListener('keydown', onEsc); } }

  return function openModal(opts) {
    if (_active) close();
    opts = opts || {};

    const bd = document.createElement('div');
    bd.className = 'ems-modal-bd';

    bd.innerHTML =
      '<div class="ems-modal" role="dialog" aria-modal="true">' +
        '<div class="em-icon-wrap">' +
          '<div class="em-icon ' + (opts.iconClass || 'icon-info') + '"><i class="fas ' + (opts.icon || 'fa-circle-question') + '"></i></div>' +
        '</div>' +
        '<div class="em-content">' +
          '<div class="em-title">' + (opts.title || 'Confirm') + '</div>' +
          (opts.subtitle ? '<div class="em-subtitle">' + opts.subtitle + '</div>' : '') +
          (opts.body     ? '<div class="em-body">'     + opts.body     + '</div>' : '') +
        '</div>' +
        '<div class="em-actions">' +
          '<button class="btn btn-outline em-cancel">' + (opts.cancelLabel || 'Cancel') + '</button>' +
          '<button class="btn ' + (opts.confirmClass || 'btn-primary') + ' em-confirm">' + (opts.confirmLabel || 'Confirm') + '</button>' +
        '</div>' +
      '</div>';

    document.body.appendChild(bd);
    _active = bd;

  //  setTimeout(() => { const c = bd.querySelector('.em-confirm'); if (c) c.focus(); }, 60);

    bd.querySelector('.em-confirm').addEventListener('click', () => {
      close(); document.removeEventListener('keydown', onEsc);
      if (typeof opts.onConfirm === 'function') opts.onConfirm();
    });
    bd.querySelector('.em-cancel').addEventListener('click', () => {
      close(); document.removeEventListener('keydown', onEsc);
    });
    bd.addEventListener('click', e => {
      if (e.target === bd) { close(); document.removeEventListener('keydown', onEsc); }
    });
    document.addEventListener('keydown', onEsc);
  };
})();
/* ================================================================
   NOTIFICATIONS — bell dropdown (unread activity feed)
   Universal: loaded on every admin page via admin-sidebar.php.
   Endpoint: window.EMS_NOTIF_URL (set in admin-sidebar.php)

   No CSRF token is sent here on purpose. This widget polls in the
   background on EVERY admin page (on load, every 45s, and on click),
   independent of whatever page-specific AJAX a page is running (e.g.
   attendance.php saving, students.php fetching its list). Those pages
   share one rotating session-wide CSRF token with each other — this
   widget used to as well, which meant a background poll could rotate
   that token out from under a page's own in-flight request and cause
   a bogus "Session expired" error on a completely unrelated action.
   activity-log-ajax.php now treats notif_count/notif_fetch as a
   CSRF-exempt, admin-session-only code path specifically so this
   widget can run in the background safely. See the comment at the
   top of that file for the full explanation.
   ================================================================ */
(function () {
  const btn      = document.getElementById('notifBtn');
  const wrap     = document.getElementById('notifWrap');
  const dropdown = document.getElementById('notifDropdown');
  const body     = document.getElementById('notifDdBody');
  const badge    = document.getElementById('notifBadge');
  if (!btn || !wrap || !dropdown || !body || !badge || !window.EMS_NOTIF_URL) return;

  // Still serialized (not strictly required anymore since there's no
  // shared token to race on, but it keeps requests tidy either way).
  let queue = Promise.resolve();
  function call(action) {
    const run = () => fetch(window.EMS_NOTIF_URL, {
      method: 'POST',
      body: (() => {
        const fd = new FormData();
        fd.append('action', action);
        return fd;
      })()
    }).then(r => r.json()).catch(() => ({ ok: false }));
    const result = queue.then(run, run);
    queue = result.catch(() => {});
    return result;
  }

  function esc(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }

  // ── Badge: exact count under 10, "9+" at 10 or more, hidden at 0 ──
  function setBadge(count) {
    if (!count) { badge.style.display = 'none'; return; }
    badge.textContent = count > 9 ? '9+' : String(count);
    badge.style.display = 'flex';
  }

  // ── Relative time under 24h, absolute date/time beyond that ───────
  function timeLabel(dt) {
    if (!dt) return '';
    const then = new Date(dt.replace(' ', 'T'));
    const sec  = Math.floor((Date.now() - then.getTime()) / 1000);
    if (sec < 24 * 3600) {
      if (sec < 60) return (sec <= 1 ? '1 second ago' : sec + ' seconds ago');
      const min = Math.floor(sec / 60);
      if (min < 60) return (min === 1 ? '1 minute ago' : min + ' minutes ago');
      const hr = Math.floor(min / 60);
      return (hr === 1 ? '1 hour ago' : hr + ' hours ago');
    }
    return then.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) + ', ' +
           then.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
  }

  function renderList(items) {
    if (!items.length) {
      body.innerHTML = `<div class="notif-dd-empty"><i class="fas fa-bell-slash"></i>No notifications yet</div>`;
      return;
    }
    body.innerHTML = items.map(n => `
      <div class="notif-item${n.unread ? ' unread' : ''}">
        <div class="notif-icon" style="background:${n.icon_bg};color:${n.icon_color}"><i class="fas ${n.icon}"></i></div>
        <div class="notif-text">
          ${esc(n.text)}
          <div class="notif-time">${timeLabel(n.logged_at)}</div>
        </div>
        ${n.unread ? '<span class="notif-unread-dot" title="New"></span>' : ''}
      </div>`).join('');
  }

  function refreshCount() {
    call('notif_count').then(r => { if (r && r.ok) setBadge(r.count); });
  }

  function openDropdown() {
    dropdown.classList.add('open');
    btn.setAttribute('aria-expanded', 'true');
    body.innerHTML = `<div class="notif-dd-empty"><i class="fas fa-spinner spin"></i></div>`;
    call('notif_fetch').then(r => {
      if (!r || !r.ok) { body.innerHTML = `<div class="notif-dd-empty">Couldn't load notifications.</div>`; return; }
      renderList(r.notifications || []);
      setBadge(0); // everything just shown was marked read server-side
    });
  }

  function closeDropdown() {
    dropdown.classList.remove('open');
    btn.setAttribute('aria-expanded', 'false');
  }

  btn.addEventListener('click', e => {
    e.stopPropagation();
    dropdown.classList.contains('open') ? closeDropdown() : openDropdown();
  });
  document.addEventListener('click', e => { if (!wrap.contains(e.target)) closeDropdown(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDropdown(); });

  refreshCount();
  setInterval(refreshCount, 45000);
})();