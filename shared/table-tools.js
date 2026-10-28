/* Единые инструменты таблиц для всех модулей, кроме ТСД:
 * - всегда доступный горизонтальный «бегунок» (не нужно листать вниз);
 * - шестерёнка: видимость/порядок столбцов и количество строк (0 = все);
 * - настройки запоминаются в localStorage для каждого экрана. */
(function () {
  'use strict';
  if (location.pathname.indexOf('/tsd/') === 0) return;

  var LSKEY = 'wms_table_tools:' + location.pathname;
  var state = {};
  try { state = JSON.parse(localStorage.getItem(LSKEY) || '{}') || {}; } catch (e) { state = {}; }
  if (!Array.isArray(state.order)) state.order = [];
  if (!state.cols || typeof state.cols !== 'object') state.cols = {};
  if (typeof state.perPage === 'undefined') state.perPage = null;

  var lastPP = null, lastTable = null, applying = false, gearReady = false;

  function save() {
    try { localStorage.setItem(LSKEY, JSON.stringify(state)); } catch (e) {}
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }
  function visibleTable() {
    var list = [].slice.call(document.querySelectorAll('table'));
    var best = null, bestScore = -1;
    for (var i = 0; i < list.length; i++) {
      var t = list[i];
      var r = t.getBoundingClientRect();
      if ((t.offsetParent === null && !t.getClientRects().length) || r.width < 20 || r.height < 20) continue;
      var score = r.width * 1000 + Math.min(r.height, 700) + (t.getAttribute('data-sort-mode') === 'server' ? 1e9 : 0);
      if (t.closest && t.closest('.open, .modal.open, .drawer.open, .wiz.open')) score += 2e9;
      if (score > bestScore) { bestScore = score; best = t; }
    }
    return best;
  }
  var observedTable = null, tableObserver = null, observeTimer = null;
  function observeTable(tbl) {
    if (tbl === observedTable) return;
    observedTable = tbl;
    if (tableObserver) { tableObserver.disconnect(); tableObserver = null; }
    if (!tbl || typeof MutationObserver === 'undefined') return;
    tableObserver = new MutationObserver(function () {
      if (observeTimer) clearTimeout(observeTimer);
      observeTimer = setTimeout(function () {
        if (observedTable) applyTable(observedTable, false);
        updateBar();
      }, 150);
    });
    tableObserver.observe(tbl, { childList: true, subtree: true });
  }
  function ensureColumns(tbl) {
    if (!tbl || !tbl.tHead || !tbl.tHead.rows.length) return;
    var ths = tbl.tHead.rows[0].children;
    var seen = {}, newHeader = false;
    for (var i = 0; i < ths.length; i++) {
      var th = ths[i];
      if (!th.getAttribute('data-wms-orig')) { th.setAttribute('data-wms-orig', String(i)); newHeader = true; }
      var key = th.getAttribute('data-wms-key');
      if (!key) {
        var label = (th.textContent || '').replace(/\s+/g, ' ').trim();
        key = th.getAttribute('data-col') || th.getAttribute('data-sort') || label || ('c' + i);
        if (th.querySelector('input[type=checkbox]')) key = '__check';
        else if (th.querySelector('.gear') || label === '⋮' || label === '') key = '__gear';
        newHeader = true;
      }
      if (seen[key]) key = key + '#' + i;
      seen[key] = true;
      th.setAttribute('data-wms-key', key);
    }
    if (newHeader) {
      [].slice.call(tbl.tBodies).forEach(function (tb) {
        [].slice.call(tb.rows).forEach(function (tr) { tr.removeAttribute('data-wms-ordered'); });
      });
    }
  }
  function isFixedKey(k) { return k === '__check' || k === '__gear'; }
  function applyTable(tbl, force) {
    if (!tbl || !tbl.tHead || !tbl.tHead.rows.length || applying) return;
    applying = true;
    try {
      ensureColumns(tbl);
      var headRow = tbl.tHead.rows[0];
      var ths = [].slice.call(headRow.children);
      var byOrig = {}, keyByOrig = {}, fixedOrigs = [], dataOrigs = [];
      var dirty = false;
      ths.forEach(function (th) {
        var o = parseInt(th.getAttribute('data-wms-orig'), 10);
        var k = th.getAttribute('data-wms-key') || ('c' + o);
        byOrig[o] = th; keyByOrig[o] = k;
        (isFixedKey(k) ? fixedOrigs : dataOrigs).push(o);
        if (!isFixedKey(k)) {
          var cfg = state.cols[k];
          if (!cfg || typeof cfg.hidden !== 'boolean') {
            state.cols[k] = { hidden: th.style.display === 'none' };
            dirty = true;
          }
        }
      });
      if (dirty) save();
      dataOrigs.sort(function (a, b) { return a - b; });
      var wantedData = [];
      state.order.forEach(function (k) {
        for (var i = 0; i < dataOrigs.length; i++) {
          var o = dataOrigs[i];
          if (keyByOrig[o] === k && wantedData.indexOf(o) < 0) { wantedData.push(o); return; }
        }
      });
      dataOrigs.forEach(function (o) { if (wantedData.indexOf(o) < 0) wantedData.push(o); });
      fixedOrigs.sort(function (a, b) { return a - b; });
      var minData = dataOrigs.length ? dataOrigs[0] : Infinity;
      var firstFixed = fixedOrigs.filter(function (o) { return o < minData; });
      var lastFixed = fixedOrigs.filter(function (o) { return o >= minData; });
      var wanted = firstFixed.concat(wantedData, lastFixed);
      var dispFor = function (o) {
        var cfg = state.cols[keyByOrig[o]];
        if (!cfg || typeof cfg.hidden !== 'boolean') return null;
        return cfg.hidden ? 'none' : '';
      };
      var oldHeaderOrigs = ths.map(function (th) { return parseInt(th.getAttribute('data-wms-orig'), 10); });
      var needHeader = oldHeaderOrigs.join(',') !== wanted.join(',');
      var hasUnmarked = false;
      [].slice.call(tbl.tBodies).forEach(function (tb) {
        [].slice.call(tb.rows).forEach(function (tr) {
          if (tr.cells.length === ths.length && tr.getAttribute('data-wms-ordered') !== '1') hasUnmarked = true;
        });
      });
      if (!force && !needHeader && !hasUnmarked) return;
      if (needHeader) {
        wanted.forEach(function (o) {
          var th = byOrig[o];
          if (th) headRow.appendChild(th);
        });
      }
      wanted.forEach(function (o) {
        var th = byOrig[o];
        if (!th) return;
        var d = dispFor(o);
        if (d !== null) th.style.display = d;
      });
      [].slice.call(tbl.tBodies).forEach(function (tb) {
        [].slice.call(tb.rows).forEach(function (tr) {
          if (tr.cells.length !== ths.length) return;
          var cells = [].slice.call(tr.children);
          var sameOrder = tr.getAttribute('data-wms-ordered') === '1'
            && oldHeaderOrigs.join(',') === wanted.join(',');
          if (sameOrder) {
            for (var wi = 0; wi < wanted.length; wi++) {
              var cw = cells[wi];
              var dw = dispFor(wanted[wi]);
              if (cw && dw !== null) cw.style.display = dw;
            }
            return;
          }
          var cellByOrig = {};
          if (tr.getAttribute('data-wms-ordered') === '1') {
            for (var ci = 0; ci < oldHeaderOrigs.length; ci++) cellByOrig[oldHeaderOrigs[ci]] = cells[ci];
          } else {
            for (var cj = 0; cj < cells.length; cj++) cellByOrig[cj] = cells[cj];
          }
          wanted.forEach(function (o) {
            var c = cellByOrig[o];
            if (!c) return;
            tr.appendChild(c);
            var d = dispFor(o);
            if (d !== null) c.style.display = d;
          });
          tr.setAttribute('data-wms-ordered', '1');
        });
      });
    } finally {
      applying = false;
    }
  }

  /* ---------- всплывающий горизонтальный бегунок ---------- */
  var bar, thumb, barCont = null;
  function ensureBar() {
    if (bar) return;
    bar = document.createElement('div');
    bar.id = 'wms-tt-hbar';
    bar.style.cssText = 'position:fixed;left:0;right:0;bottom:0;height:14px;z-index:99990;display:none;background:rgba(100,50,255,.08);border-top:1px solid rgba(100,50,255,.15)';
    thumb = document.createElement('div');
    thumb.style.cssText = 'position:absolute;top:2px;height:10px;border-radius:5px;background:#6432FF;cursor:grab;touch-action:none;min-width:30px';
    bar.appendChild(thumb);
    document.body.appendChild(bar);
    var drag = null;
    thumb.addEventListener('pointerdown', function (e) {
      if (!barCont) return;
      drag = { x: e.clientX, left: barCont.scrollLeft };
      try { thumb.setPointerCapture(e.pointerId); } catch (err) {}
      thumb.style.cursor = 'grabbing';
      e.preventDefault();
    });
    thumb.addEventListener('pointermove', function (e) {
      if (!drag || !barCont) return;
      var track = bar.clientWidth;
      var maxScroll = Math.max(1, barCont.scrollWidth - barCont.clientWidth);
      var thumbW = thumb.offsetWidth;
      var ratio = maxScroll / Math.max(1, track - thumbW);
      barCont.scrollLeft = drag.left + (e.clientX - drag.x) * ratio;
    });
    function endDrag() { drag = null; thumb.style.cursor = 'grab'; }
    thumb.addEventListener('pointerup', endDrag);
    thumb.addEventListener('pointercancel', endDrag);
    bar.addEventListener('pointerdown', function (e) {
      if (e.target === thumb || !barCont) return;
      var rect = bar.getBoundingClientRect();
      var x = e.clientX - rect.left - thumb.offsetWidth / 2;
      var track = bar.clientWidth - thumb.offsetWidth;
      if (track > 0) barCont.scrollLeft = (x / track) * Math.max(0, barCont.scrollWidth - barCont.clientWidth);
    });
  }
  function scrollableAncestor(el) {
    while (el && el !== document.body && el !== document.documentElement) {
      var st = getComputedStyle(el);
      if ((st.overflowX === 'auto' || st.overflowX === 'scroll') && el.scrollWidth > el.clientWidth + 1) return el;
      el = el.parentElement;
    }
    var doc = document.scrollingElement || document.documentElement;
    if (doc && doc.scrollWidth > doc.clientWidth + 1) return doc;
    return null;
  }
  function updateBar() {
    ensureBar();
    var tbl = visibleTable();
    var cont = tbl ? scrollableAncestor(tbl) : null;
    if (!cont && tbl) {
      // Некоторые карточки имеют overflow:hidden — включаем горизонтальную прокрутку,
      // иначе правую часть широкой таблицы не увидеть.
      var p = tbl.parentElement;
      while (p && p !== document.body && p !== document.documentElement) {
        if (p.scrollWidth > p.clientWidth + 1) {
          var st = getComputedStyle(p);
          if (st.overflowX === 'hidden') { p.style.overflowX = 'auto'; cont = p; break; }
        }
        p = p.parentElement;
      }
    }
    if (!cont) { bar.style.display = 'none'; barCont = null; return; }
    barCont = cont;
    var isDoc = cont === document.scrollingElement || cont === document.documentElement;
    var rect = isDoc ? { left: 0, width: window.innerWidth } : cont.getBoundingClientRect();
    var left = Math.max(0, Math.min(window.innerWidth - 40, rect.left));
    var width = Math.max(40, Math.min(window.innerWidth - left, rect.width));
    bar.style.left = left + 'px';
    bar.style.width = width + 'px';
    var ratio = Math.min(1, cont.clientWidth / Math.max(1, cont.scrollWidth));
    var tw = Math.max(30, Math.round(width * ratio));
    var maxScroll = Math.max(0, cont.scrollWidth - cont.clientWidth);
    var tx = maxScroll > 0 ? Math.round((cont.scrollLeft / maxScroll) * (width - tw)) : 0;
    thumb.style.width = tw + 'px';
    thumb.style.left = Math.max(0, Math.min(width - tw, tx)) + 'px';
    bar.style.display = 'block';
  }

  /* ---------- панель «Столбцы и строки» ---------- */
  var panel, colBox, ppInput, panelKeys = [];
  function ensurePanel() {
    if (panel) return;
    panel = document.createElement('div');
    panel.id = 'wms-tt-panel';
    panel.style.cssText = 'position:fixed;right:16px;bottom:56px;width:360px;max-width:94vw;max-height:78vh;overflow:auto;background:#fff;border:1px solid #EAEAEA;border-radius:12px;box-shadow:0 12px 32px rgba(0,0,0,.18);z-index:99995;display:none;padding:12px;font-family:Arial,Helvetica,sans-serif';
    panel.innerHTML =
      '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">' +
      '<b style="font-size:15px">Столбцы и строки</b><button id="wms-tt-close" class="btn" style="padding:4px 9px">✕</button></div>' +
      '<div style="font-size:12px;color:#8A92A6;margin-bottom:6px">Галка — показывать столбец. ▲▼ — порядок.</div>' +
      '<div id="wms-tt-cols" style="max-height:44vh;overflow:auto;border:1px solid #F0F0F0;border-radius:8px;padding:4px 8px"></div>' +
      '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:10px">' +
      '<span style="font-size:13px">Строк на странице</span>' +
      '<input id="wms-tt-pp" type="number" min="0" step="1" style="width:100px;padding:7px;border:1px solid #EAEAEA;border-radius:8px">' +
      '</div><div style="font-size:11px;color:#8A92A6;margin-top:4px">0 — показать все строки на одном листе</div>' +
      '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:10px">' +
      '<button id="wms-tt-reset" class="btn">Сбросить</button><button id="wms-tt-ok" class="btn pri">Готово</button></div>';
    document.body.appendChild(panel);
    colBox = panel.querySelector('#wms-tt-cols');
    ppInput = panel.querySelector('#wms-tt-pp');
    panel.querySelector('#wms-tt-close').onclick = function () { panel.style.display = 'none'; };
    panel.querySelector('#wms-tt-ok').onclick = function () { panel.style.display = 'none'; };
    panel.querySelector('#wms-tt-reset').onclick = function () {
      state.order = []; state.cols = {}; state.perPage = null; save();
      applyTable(visibleTable(), true); renderPanel(); applyPerPage(true);
    };
    ppInput.onchange = function () {
      var v = parseInt(ppInput.value, 10);
      if (isNaN(v) || v < 0) v = 0;
      state.perPage = v; save(); applyPerPage(false);
    };
    colBox.onclick = function (e) {
      var t = e.target;
      if (!t || t.tagName !== 'BUTTON') return;
      var i = panelKeys.indexOf(t.getAttribute('data-k'));
      if (i < 0) return;
      var dir = t.getAttribute('data-d');
      var j = dir === 'up' ? i - 1 : i + 1;
      if (j < 0 || j >= panelKeys.length) return;
      var tmp = panelKeys[i]; panelKeys[i] = panelKeys[j]; panelKeys[j] = tmp;
      state.order = panelKeys.slice(); save(); applyTable(visibleTable(), true); renderPanel();
    };
    colBox.onchange = function (e) {
      var t = e.target;
      if (!t || t.type !== 'checkbox') return;
      var k = t.getAttribute('data-k');
      state.cols[k] = { hidden: !t.checked }; save(); applyTable(visibleTable(), true);
    };
  }
  function renderPanel() {
    ensurePanel();
    var tbl = visibleTable();
    if (!tbl || !tbl.tHead || !tbl.tHead.rows.length) {
      panelKeys = []; colBox.innerHTML = '<div style="color:#8A92A6;font-size:12px;padding:6px">Таблица не найдена</div>'; return;
    }
    ensureColumns(tbl);
    var ths = [].slice.call(tbl.tHead.rows[0].children);
    var keys = [];
    ths.forEach(function (th) {
      var k = th.getAttribute('data-wms-key');
      if (!isFixedKey(k) && keys.indexOf(k) < 0) keys.push(k);
    });
    var ordered = [];
    state.order.forEach(function (k) { if (keys.indexOf(k) >= 0 && ordered.indexOf(k) < 0) ordered.push(k); });
    keys.forEach(function (k) { if (ordered.indexOf(k) < 0) ordered.push(k); });
    panelKeys = ordered;
    var byKey = {};
    ths.forEach(function (th) { byKey[th.getAttribute('data-wms-key')] = th; });
    colBox.innerHTML = ordered.map(function (k, i) {
      var th = byKey[k] || {};
      var label = (th.textContent || '').replace(/\s+/g, ' ').trim() || k;
      var cfg = state.cols[k] || {};
      return '<div style="display:flex;align-items:center;gap:6px;padding:5px 2px;border-bottom:1px solid #F5F5F5">' +
        '<input type="checkbox" data-k="' + esc(k) + '" ' + (cfg.hidden ? '' : 'checked') + ' style="width:auto">' +
        '<span style="flex:1;font-size:13px;color:#2C313C">' + esc(label) + '</span>' +
        '<button data-k="' + esc(k) + '" data-d="up" class="btn" style="padding:2px 7px" title="Выше">▲</button>' +
        '<button data-k="' + esc(k) + '" data-d="down" class="btn" style="padding:2px 7px" title="Ниже">▼</button></div>';
    }).join('');
    ppInput.value = state.perPage === null || typeof state.perPage === 'undefined' ? '' : String(state.perPage);
  }
  function openPanel() {
    ensurePanel();
    renderPanel();
    panel.style.display = 'block';
  }

  /* ---------- количество строк ---------- */
  function ppSelect() {
    var sels = [].slice.call(document.querySelectorAll('#pp, .pager select'));
    for (var i = 0; i < sels.length; i++) {
      var r = sels[i].getBoundingClientRect();
      if ((sels[i].offsetParent !== null || sels[i].getClientRects().length) && r.width > 0) return sels[i];
    }
    return null;
  }
  function applyPerPage(force) {
    var sel = ppSelect();
    if (!sel) return;
    if (state.perPage === null || typeof state.perPage === 'undefined') {
      if (force && sel.options.length) {
        var first = sel.options[0].value;
        if (sel.value !== first) {
          sel.value = first;
          try { sel.dispatchEvent(new Event('change', { bubbles: true })); } catch (e) {}
        }
      }
      return;
    }
    var v = String(state.perPage);
    var has = false;
    for (var i = 0; i < sel.options.length; i++) if (sel.options[i].value === v) has = true;
    if (!has) {
      var o = document.createElement('option');
      o.value = v; o.textContent = v === '0' ? '0 — все' : v;
      sel.appendChild(o);
    }
    if (sel.value !== v) {
      sel.value = v;
      try { sel.dispatchEvent(new Event('change', { bubbles: true })); } catch (e) {}
    }
    lastPP = v;
  }

  /* ---------- инициализация ---------- */
  function setupGear() {
    if (gearReady) return;
    var gears = [].slice.call(document.querySelectorAll('#colset'));
    if (gears.length) {
      gears.forEach(function (old) {
        var g = old.cloneNode(true);
        old.parentNode.replaceChild(g, old);
        g.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); openPanel(); });
      });
      gearReady = true;
      return;
    }
    if (!visibleTable()) return;
    var g = document.createElement('button');
    g.id = 'wms-tt-gear';
    g.type = 'button';
    g.title = 'Столбцы и строки';
    g.textContent = '⚙';
    g.style.cssText = 'position:fixed;right:16px;bottom:24px;width:38px;height:38px;border-radius:50%;border:1px solid #EAEAEA;background:#fff;box-shadow:0 4px 14px rgba(0,0,0,.14);z-index:99994;cursor:pointer;font-size:18px;line-height:1';
    g.onclick = function (e) { e.preventDefault(); e.stopPropagation(); openPanel(); };
    document.body.appendChild(g);
    gearReady = true;
  }

  function tick() {
    setupGear();
    var tbl = visibleTable();
    observeTable(tbl);
    if (tbl !== lastTable) {
      lastTable = tbl;
      if (tbl) applyTable(tbl, false);
    } else if (tbl) {
      applyTable(tbl, false);
    }
    updateBar();
    if (state.perPage !== null) applyPerPage(false);
  }

  function init() {
    setupGear();
    ensurePanel();
    ensureBar();
    window.addEventListener('resize', function () { updateBar(); });
    document.addEventListener('scroll', function () { updateBar(); }, true);
    setInterval(tick, 2000);
    setTimeout(function () { tick(); applyPerPage(false); }, 400);
    setTimeout(function () { tick(); applyPerPage(false); }, 1500);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
