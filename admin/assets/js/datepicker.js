/**
 * EMS Universal Date Picker
 * ─────────────────────────────────────────────────────────────────
 * Converts every <input type="date"> in the admin panel into a
 * custom-styled picker. Fully automatic:
 *
 *   - Runs on DOMContentLoaded for inputs already in the page.
 *   - Watches the DOM (MutationObserver) so inputs injected later
 *     (modals, AJAX-rendered forms, etc.) are picked up too, and
 *     cleans up if that markup is later removed.
 *   - The real <input type="date"> stays in the form (screen-reader-
 *     only styled, not display:none) so `required`, `name`, and
 *     native form submission all keep working untouched.
 *   - Programmatic `input.value = '2026-01-01'` (e.g. from an AJAX
 *     "edit" handler pre-filling a form) is intercepted and the
 *     visible picker display is kept in sync automatically — no
 *     page-specific glue code needed.
 *   - `min` / `max` attributes on the native input are respected;
 *     out-of-range days are shown disabled.
 *   - The visible field is directly typeable as dd/mm/yyyy (auto
 *     formatted as you type), as well as pickable from the calendar.
 *   - The calendar popup is appended to <body> and positioned with
 *     position:fixed, so it is never clipped by an ancestor card,
 *     modal, or scroll container with overflow:hidden.
 *
 * No manual wiring required on a page — just make sure this file
 * and datepicker.css are loaded (already included globally via
 * admin-sidebar.php / admin-footer.php).
 *
 * Manual re-scan is still exposed for edge cases:
 *   window.initDatePickers()               // whole document
 *   window.initDatePickers(someContainer)  // just a subtree
 * ─────────────────────────────────────────────────────────────────
 */
(function () {
  'use strict';

  const MONTHS = [
    'January','February','March','April','May','June',
    'July','August','September','October','November','December'
  ];
  const MONTHS_SHORT = [
    'Jan','Feb','Mar','Apr','May','Jun',
    'Jul','Aug','Sep','Oct','Nov','Dec'
  ];
  const DAYS = ['Su','Mo','Tu','We','Th','Fr','Sa'];

  const ICON_SVG = `
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <rect x="3" y="4" width="18" height="18" rx="2"/>
      <line x1="16" y1="2" x2="16" y2="6"/>
      <line x1="8" y1="2" x2="8" y2="6"/>
      <line x1="3" y1="10" x2="21" y2="10"/>
    </svg>`;

  function pad2(n) { return String(n).padStart(2, '0'); }

  // ISO 'YYYY-MM-DD' — what the native <input type="date"> stores
  function formatISO(d) {
    return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
  }

  // dd/mm/yyyy — what the visible field shows and accepts while typing
  function formatDisplay(d) {
    return pad2(d.getDate()) + '/' + pad2(d.getMonth() + 1) + '/' + d.getFullYear();
  }

  // Parses 'YYYY-MM-DD' as a local date (avoids UTC offset shifting the day)
  function parseISO(str) {
    if (!str || !/^\d{4}-\d{2}-\d{2}$/.test(str)) return null;
    const d = new Date(str + 'T00:00:00');
    return isNaN(d) ? null : d;
  }

  // Parses a complete 'dd/mm/yyyy' string, rejecting invalid calendar
  // dates (e.g. 31/02/2026 rolling over into March)
  function parseDMY(str) {
    const m = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(str);
    if (!m) return null;
    const day = parseInt(m[1], 10), month = parseInt(m[2], 10), year = parseInt(m[3], 10);
    if (month < 1 || month > 12 || day < 1 || day > 31) return null;
    const d = new Date(year, month - 1, day);
    if (d.getFullYear() !== year || d.getMonth() !== month - 1 || d.getDate() !== day) return null;
    return d;
  }

  // Digit-only mask -> dd/mm/yyyy, inserting slashes as the user types
  function maskDateInput(raw) {
    const digits = raw.replace(/\D/g, '').slice(0, 8);
    let out = digits.slice(0, 2);
    if (digits.length > 2) out += '/' + digits.slice(2, 4);
    if (digits.length > 4) out += '/' + digits.slice(4, 8);
    return out;
  }

  function sameDay(a, b) {
    return !!a && !!b &&
      a.getFullYear() === b.getFullYear() &&
      a.getMonth()    === b.getMonth() &&
      a.getDate()     === b.getDate();
  }

  function DatePicker(nativeInput) {
    const now = new Date();
    let viewMonth = now.getMonth();
    let viewYear  = now.getFullYear();
    let selectedDate = null;
    let lastValidDisplay = '';
    let mode = 'days';
    let yearStart = Math.floor(viewYear / 12) * 12;

    function getMin() { return parseISO(nativeInput.getAttribute('min')); }
    function getMax() { return parseISO(nativeInput.getAttribute('max')); }
    function isDisabledDate(d) {
      const min = getMin(), max = getMax();
      if (min && d < min) return true;
      if (max && d > max) return true;
      return false;
    }

    const isFieldDisabled = () => nativeInput.disabled;

    /* ── Build wrapper ─────────────────────────────── */
    const wrap = document.createElement('div');
    wrap.className = 'dp-input-wrap';
    nativeInput.parentNode.insertBefore(wrap, nativeInput);
    nativeInput.classList.add('dp-native-input');
    nativeInput.setAttribute('tabindex', '-1');
    nativeInput.setAttribute('aria-hidden', 'true');
    wrap.appendChild(nativeInput);

    const fakeInput = document.createElement('input');
    fakeInput.type = 'text';
    fakeInput.className = 'dp-input';
    fakeInput.placeholder = 'DD/m/yyyy';
    fakeInput.autocomplete = 'off';
    fakeInput.setAttribute('inputmode', 'numeric');
    fakeInput.setAttribute('maxlength', '10');
    if (isFieldDisabled()) { fakeInput.classList.add('dp-disabled'); fakeInput.disabled = true; }
    wrap.insertBefore(fakeInput, nativeInput);

    const iconSpan = document.createElement('span');
    iconSpan.className = 'dp-icon';
    iconSpan.innerHTML = ICON_SVG;
    wrap.insertBefore(iconSpan, nativeInput);

    // Popup is a body-level layer (position:fixed), not nested inside
    // wrap — this is what keeps it from ever being clipped by an
    // ancestor card/modal with overflow:hidden.
    const popup = document.createElement('div');
    popup.className = 'dp-popup';
    document.body.appendChild(popup);
    wrap.__dpPopup = popup; // referenced by the cleanup observer below

    /* ── Sync display FROM the native input's current value ── */
    function syncFromNative() {
      const raw = rawGet.call(nativeInput);
      const d = parseISO(raw);
      selectedDate = d;
      if (d) {
        viewMonth = d.getMonth();
        viewYear  = d.getFullYear();
        lastValidDisplay = formatDisplay(d);
        fakeInput.value = lastValidDisplay;
      } else {
        lastValidDisplay = '';
        fakeInput.value = '';
      }
      fakeInput.classList.remove('dp-invalid');
      if (popup.classList.contains('dp-open')) render();
    }

    /* ── Intercept programmatic `.value =` sets so any existing
       AJAX "populate the edit form" code stays in sync automatically ── */
    const desc = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
    const rawGet = desc.get;
    const rawSet = desc.set;
    try {
      Object.defineProperty(nativeInput, 'value', {
        configurable: true,
        get() { return rawGet.call(nativeInput); },
        set(v) { rawSet.call(nativeInput, v); syncFromNative(); }
      });
    } catch (e) { /* older browser fallback: no live sync on programmatic set */ }

    // Initial sync (handles server-rendered value="...")
    syncFromNative();

    // Resync after a native form reset
    const ownerForm = nativeInput.form;
    if (ownerForm) {
      ownerForm.addEventListener('reset', function () {
        setTimeout(syncFromNative, 0);
      });
    }

    function fireNativeEvents() {
      nativeInput.dispatchEvent(new Event('input',  { bubbles: true }));
      nativeInput.dispatchEvent(new Event('change', { bubbles: true }));
    }

    // Commits a concrete date: updates native value + display + calendar
    // view. Does not close the popup — callers decide that.
    function selectDate(d) {
      selectedDate = d;
      viewMonth = d.getMonth();
      viewYear  = d.getFullYear();
      lastValidDisplay = formatDisplay(d);
      fakeInput.value = lastValidDisplay;
      fakeInput.classList.remove('dp-invalid');
      rawSet.call(nativeInput, formatISO(d));
      fireNativeEvents();
    }

    function clearDate() {
      selectedDate = null;
      lastValidDisplay = '';
      fakeInput.value = '';
      fakeInput.classList.remove('dp-invalid');
      rawSet.call(nativeInput, '');
      fireNativeEvents();
    }

    /* ── Render helpers ────────────────────────────── */
    function render() {
      if      (mode === 'days')   renderDays();
      else if (mode === 'months') renderMonths();
      else                        renderYears();
    }

    function renderDays() {
      const firstDow = new Date(viewYear, viewMonth, 1).getDay();
      const lastDay  = new Date(viewYear, viewMonth + 1, 0).getDate();
      const prevLast = new Date(viewYear, viewMonth, 0).getDate();

      let html = `
        <div class="dp-header">
          <button type="button" class="dp-nav-btn" data-act="prev">&#8249;</button>
          <span class="dp-month-year" data-act="switchMonth">${MONTHS[viewMonth]} ${viewYear}</span>
          <button type="button" class="dp-nav-btn" data-act="next">&#8250;</button>
        </div>
        <div class="dp-weekdays">
          ${DAYS.map(d => `<div class="dp-wd">${d}</div>`).join('')}
        </div>
        <div class="dp-days">`;

      for (let i = 0; i < firstDow; i++) {
        html += `<div class="dp-day dp-other-month dp-day-empty">${prevLast - firstDow + 1 + i}</div>`;
      }
      for (let d = 1; d <= lastDay; d++) {
        const dateObj  = new Date(viewYear, viewMonth, d);
        const isToday  = sameDay(dateObj, now);
        const isSel    = sameDay(dateObj, selectedDate);
        const disabled = isDisabledDate(dateObj);
        let cls = 'dp-day';
        if (isToday)  cls += ' dp-today';
        if (isSel)    cls += ' dp-selected';
        if (disabled) cls += ' dp-disabled';
        html += `<div class="${cls}" data-act="${disabled ? 'noop' : 'pick'}" data-d="${d}">${d}</div>`;
      }

      const trailing = 42 - firstDow - lastDay;
      for (let i = 1; i <= trailing && trailing < 14; i++) {
        html += `<div class="dp-day dp-other-month dp-day-empty">${i}</div>`;
      }

      const todayDisabled = isDisabledDate(new Date(now.getFullYear(), now.getMonth(), now.getDate()));

      html += `</div>
        <div class="dp-footer">
          <button type="button" class="dp-today-btn" data-act="today" ${todayDisabled ? 'disabled' : ''}>Today</button>
          <button type="button" class="dp-clear-btn" data-act="clear">Clear</button>
        </div>`;

      popup.innerHTML = html;
    }

    function renderMonths() {
      let html = `
        <div class="dp-header">
          <button type="button" class="dp-nav-btn" data-act="prevY">&#8249;</button>
          <span class="dp-month-year" data-act="switchYear">${viewYear}</span>
          <button type="button" class="dp-nav-btn" data-act="nextY">&#8250;</button>
        </div>
        <div class="dp-month-grid">`;
      MONTHS_SHORT.forEach((m, i) => {
        const active = i === viewMonth;
        html += `<div class="dp-month-cell${active ? ' dp-cell-active' : ''}" data-act="pickMonth" data-m="${i}">${m}</div>`;
      });
      html += '</div>';
      popup.innerHTML = html;
    }

    function renderYears() {
      let html = `
        <div class="dp-header">
          <button type="button" class="dp-nav-btn" data-act="prevYears">&#8249;</button>
          <span class="dp-year-range">${yearStart} – ${yearStart + 11}</span>
          <button type="button" class="dp-nav-btn" data-act="nextYears">&#8250;</button>
        </div>
        <div class="dp-year-grid">`;
      for (let y = yearStart; y < yearStart + 12; y++) {
        const active = y === viewYear;
        html += `<div class="dp-year-cell${active ? ' dp-cell-active' : ''}" data-act="pickYear" data-y="${y}">${y}</div>`;
      }
      html += '</div>';
      popup.innerHTML = html;
    }

    /* ── Positioning (popup lives in <body>, so it must track the
       field's on-screen position manually) ─────────────────────── */
    function reposition() {
      const rect = fakeInput.getBoundingClientRect();
      const pw = popup.offsetWidth  || 280;
      const ph = popup.offsetHeight || 340;

      let left = rect.left;
      if (left + pw > window.innerWidth - 8)  left = rect.right - pw;
      if (left < 8) left = 8;

      let top = rect.bottom + 6;
      if (top + ph > window.innerHeight - 8 && rect.top - ph - 6 > 0) {
        top = rect.top - ph - 6; // flip above the field if no room below
      }

      popup.style.left = left + 'px';
      popup.style.top  = top  + 'px';
    }

    const onScrollOrResize = () => reposition();

    /* ── Open / Close ──────────────────────────────── */
    function closeAllOthers() {
      document.querySelectorAll('.dp-popup.dp-open').forEach(function (p) {
        if (p !== popup) p.classList.remove('dp-open');
      });
      document.querySelectorAll('.dp-input.dp-active').forEach(function (i) {
        if (i !== fakeInput) i.classList.remove('dp-active');
      });
    }

    function open() {
      if (isFieldDisabled()) return;
      if (popup.classList.contains('dp-open')) return;
      closeAllOthers();
      popup.classList.add('dp-open');
      fakeInput.classList.add('dp-active');
      mode = 'days';
      render();
      reposition();
      // capture:true catches scroll on any scrollable ancestor, not just window
      window.addEventListener('scroll', onScrollOrResize, true);
      window.addEventListener('resize', onScrollOrResize);
    }

    function close() {
      popup.classList.remove('dp-open');
      fakeInput.classList.remove('dp-active');
      window.removeEventListener('scroll', onScrollOrResize, true);
      window.removeEventListener('resize', onScrollOrResize);
    }

    // Validates whatever is currently typed and either commits it or
    // reverts to the last valid value. Used on blur / Enter / Escape.
    function finalizeTyped(revertOnly) {
      const val = fakeInput.value.trim();

      if (revertOnly) {
        fakeInput.value = lastValidDisplay;
        fakeInput.classList.remove('dp-invalid');
        return;
      }
      if (val === '') {
        if (selectedDate !== null) clearDate();
        return;
      }
      if (val.length === 10) {
        const d = parseDMY(val);
        if (d && !isDisabledDate(d)) { selectDate(d); return; }
      }
      // Incomplete or invalid — revert to the last good value
      fakeInput.value = lastValidDisplay;
      fakeInput.classList.remove('dp-invalid');
    }

    /* ── Events ────────────────────────────────────── */
    // Clicking anywhere on the field opens the picker (the icon sits
    // on top with pointer-events:none, so this fires for the whole box).
    fakeInput.addEventListener('click', function (e) {
      e.stopPropagation();
      open();
    });

    fakeInput.addEventListener('focus', function () {
      open();
      setTimeout(() => fakeInput.select(), 0);
    });

    fakeInput.addEventListener('input', function () {
      const masked = maskDateInput(fakeInput.value);
      fakeInput.value = masked;
      fakeInput.classList.remove('dp-invalid');

      if (masked.length === 10) {
        const d = parseDMY(masked);
        if (d && !isDisabledDate(d)) {
          selectDate(d);
        } else {
          fakeInput.classList.add('dp-invalid');
        }
      }
      if (popup.classList.contains('dp-open')) { mode = 'days'; render(); reposition(); }
    });

    fakeInput.addEventListener('keydown', function (e) {
      if (isFieldDisabled()) return;
      if (e.key === 'Enter') {
        e.preventDefault(); // stop the surrounding <form> from submitting
        finalizeTyped(false);
        close();
      } else if (e.key === 'Escape') {
        finalizeTyped(true);
        close();
      }
    });

    fakeInput.addEventListener('blur', function () {
      // Let a click on the popup register first (mousedown fires before blur)
      setTimeout(function () {
        if (!popup.contains(document.activeElement)) {
          finalizeTyped(false);
          close();
        }
      }, 0);
    });

    popup.addEventListener('mousedown', function (e) {
      // Prevent the field from blurring when interacting with the popup
      e.preventDefault();
    });

    popup.addEventListener('click', function (e) {
      e.stopPropagation();
      const t = e.target.closest('[data-act]');
      if (!t || t.dataset.act === 'noop') return;
      const act = t.dataset.act;

      if      (act === 'prev')       { viewMonth--; if (viewMonth < 0)  { viewMonth = 11; viewYear--; } render(); }
      else if (act === 'next')       { viewMonth++; if (viewMonth > 11) { viewMonth = 0;  viewYear++; } render(); }
      else if (act === 'prevY')      { viewYear--;  render(); }
      else if (act === 'nextY')      { viewYear++;  render(); }
      else if (act === 'prevYears')  { yearStart -= 12; render(); }
      else if (act === 'nextYears')  { yearStart += 12; render(); }
      else if (act === 'switchMonth'){ mode = 'months'; render(); }
      else if (act === 'switchYear') { mode = 'years'; yearStart = Math.floor(viewYear / 12) * 12; render(); }
      else if (act === 'pickMonth')  { viewMonth = parseInt(t.dataset.m, 10); mode = 'days'; render(); }
      else if (act === 'pickYear')   { viewYear  = parseInt(t.dataset.y, 10); mode = 'months'; render(); }
      else if (act === 'pick') {
        selectDate(new Date(viewYear, viewMonth, parseInt(t.dataset.d, 10)));
        close();
        fakeInput.blur();
      }
      else if (act === 'today') {
        selectDate(new Date(now.getFullYear(), now.getMonth(), now.getDate()));
        close();
        fakeInput.blur();
      }
      else if (act === 'clear') {
        clearDate();
        render();
      }
    });
  }

  /* ── Global outside-click closes all pickers ──────── */
  document.addEventListener('click', function () {
    document.querySelectorAll('.dp-popup.dp-open').forEach(function (p) {
      p.classList.remove('dp-open');
    });
    document.querySelectorAll('.dp-input.dp-active').forEach(function (i) {
      i.classList.remove('dp-active');
    });
  });

  /* ── Init / scan ───────────────────────────────────── */
  function scan(root) {
    const scope = root || document;
    const inputs = scope.matches && scope.matches('input[type="date"]')
      ? [scope]
      : Array.from(scope.querySelectorAll('input[type="date"]'));
    inputs.forEach(function (el) {
      if (el.dataset.dpInit) return;
      el.dataset.dpInit = '1';
      new DatePicker(el);
    });
  }

  window.initDatePickers = function (root) {
    scan(root);
  };

  // Legacy helper kept for compatibility — no longer required since
  // programmatic `.value =` sets sync automatically, but harmless if
  // older page code still calls it.
  window.dpSetValue = function (idOrEl, dateStr) {
    const el = typeof idOrEl === 'string' ? document.getElementById(idOrEl) : idOrEl;
    if (el) el.value = dateStr || '';
  };

  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  ready(function () {
    scan(document);

    // Auto-detect date inputs injected later (modals, AJAX-rendered
    // forms, etc.) and clean up popups whose field markup was removed
    // (e.g. a modal rebuilt from scratch on next open), so no page
    // ever needs to remember to call initDatePickers() manually.
    const observer = new MutationObserver(function (mutations) {
      for (const m of mutations) {
        for (const node of m.addedNodes) {
          if (node.nodeType !== 1) continue;
          if (node.matches && node.matches('input[type="date"]')) {
            scan(node);
          } else if (node.querySelectorAll) {
            if (node.querySelectorAll('input[type="date"]').length) scan(node);
          }
        }
        for (const node of m.removedNodes) {
          if (node.nodeType !== 1) continue;
          const wraps = node.matches && node.matches('.dp-input-wrap')
            ? [node]
            : (node.querySelectorAll ? Array.from(node.querySelectorAll('.dp-input-wrap')) : []);
          wraps.forEach(function (w) {
            if (w.__dpPopup) w.__dpPopup.remove();
          });
        }
      }
    });
    observer.observe(document.body, { childList: true, subtree: true });
  });

})();