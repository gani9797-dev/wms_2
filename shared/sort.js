/* Общая сортировка таблиц WMS: стрелка у заголовка, клик — А→Я / Я→А.
   Подключение в модуле: <script src="../shared/sort.js"></script>
   Разметка:
     <th data-sort="doc_no">№ документа</th>  — колонка сортируется
     <table data-sort-mode="server">           — серверная сортировка (реестр с пагинацией)
   JS:
     Sort.init({mode:'server', key:'doc_date', dir:'DESC', onChange:(k,d)=>{...}});
     const p=new URLSearchParams({...}); Sort.params(p);   // добавит sort/dir
   Таблицы без data-sort-mode сортируются на клиенте (перестановка строк в tbody).
*/
(function (global) {
  'use strict';

  var ARROW_IDLE = '↕';
  var ARROW_ASC = '▲';
  var ARROW_DESC = '▼';
  var ACTIVE_COLOR = '#6432FF';
  var IDLE_COLOR = '#B9B9C7';

  var serverTable = null;   // таблица реестра (серверная сортировка)
  var seen = [];            // уже инициализированные таблицы
  var states = {};          // подпись таблицы -> {key, dir} (сортировка переживает перерисовку)
  var scanTimer = null;

  function qa(root, sel) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  function isSeen(el) { return seen.indexOf(el) >= 0; }

  /** Текст заголовка без стрелки сортировки (для панелей настройки столбцов). */
  function thLabel(th) {
    var s = (th.textContent || '').replace(/[▲▼↕]/g, '');
    return s.replace(/\s+/g, ' ').trim();
  }

  function setArrow(th, dir) {
    var sp = th.querySelector('.sort-arrow');
    if (!sp) {
      sp = document.createElement('span');
      sp.className = 'sort-arrow';
      th.appendChild(sp);
    }
    if (dir === 'ASC') { sp.textContent = ARROW_ASC; sp.style.color = ACTIVE_COLOR; }
    else if (dir === 'DESC') { sp.textContent = ARROW_DESC; sp.style.color = ACTIVE_COLOR; }
    else { sp.textContent = ARROW_IDLE; sp.style.color = IDLE_COLOR; }
  }

  /** Число из текста ячейки: "1 234,56 шт" -> 1234.56 ; "A-105" -> null (сортируем как текст). */
  function numOf(s) {
    if (s === null || s === undefined) return null;
    var t = String(s).replace(/\s/g, '').replace(/^[₽$€£]/, '');
    var m = t.match(/^[+-]?\d+(?:[.,]\d+)?/);
    if (!m) return null;
    if (/\d/.test(t.slice(m[0].length))) return null;
    var n = parseFloat(m[0].replace(',', '.'));
    return isFinite(n) ? n : null;
  }

  function cmpText(a, b) {
    var x = String(a).toLowerCase(), y = String(b).toLowerCase();
    return x < y ? -1 : x > y ? 1 : 0;
  }

  function paint(t) {
    t.heads.forEach(function (th) {
      var key = th.getAttribute('data-sort');
      var isClient = th.hasAttribute('data-sort-client');
      var active = isClient
        ? (t.clientKey === key)
        : (!t.clientKey && t.key === key);
      setArrow(th, active ? (isClient ? t.clientDir : t.dir) : '');
    });
  }

  /** Клиентская сортировка: перестановка строк tbody по тексту ячейки. */
  function sortRows(t, colIdx, dir) {
    var tb = t.el.tBodies[0];
    if (!tb) return;
    dir = dir === 'DESC' ? -1 : 1;
    var rows = Array.prototype.slice.call(tb.rows);
    var val = function (row) {
      var c = row.cells[colIdx];
      return c ? (c.textContent || '').trim() : '';
    };
    // Строки-заголовки (th внутри tbody) не сортируем — они остаются на месте.
    var body = rows.filter(function (r) { return !r.querySelector('th'); });
    var sorted = body.slice().sort(function (a, b) {
      var va = val(a), vb = val(b);
      if (!va && !vb) return 0;
      if (!va) return 1;            // пустые всегда в конце
      if (!vb) return -1;
      var na = numOf(va), nb = numOf(vb);
      if (na !== null && nb !== null) return (na - nb) * dir;
      return cmpText(va, vb) * dir;
    });
    var si = 0;
    var out = rows.map(function (r) { return r.querySelector('th') ? r : sorted[si++]; });
    var same = out.length === rows.length;
    if (same) for (var i = 0; i < rows.length; i++) if (rows[i] !== out[i]) { same = false; break; }
    if (same) return;
    for (var j = 0; j < out.length; j++) tb.appendChild(out[j]);
  }

  /** Подпись таблицы: по id (таблицы/тела) иначе по заголовкам колонок. */
  function signature(el) {
    var tb = el.tBodies[0];
    var tbId = tb && tb.id ? '#' + tb.id : '';
    var sig = el.id ? '#' + el.id : tbId;
    if (!sig) {
      sig = qa(el, 'th').map(function (th) { return thLabel(th); }).join('|');
    }
    return sig || 'table';
  }

  function initTable(el, mode, key, dir, onChange) {
    var heads = qa(el, 'th[data-sort]');
    if (!heads.length && mode !== 'server') {
      // Клиентская таблица без явных ключей: сортируем по всем столбцам
      // (кроме колонок с чекбоксом/кнопкой — их не трогаем).
      heads = qa(el, 'th').filter(function (th) {
        return !th.querySelector('input,button,select,textarea,a');
      });
      heads.forEach(function (th) {
        var row = th.parentNode;
        var idx = row && row.cells ? Array.prototype.indexOf.call(row.cells, th) : 0;
        th.setAttribute('data-sort', 'c' + idx);
      });
    }
    if (!heads.length) return null;
    var sig = signature(el);
    var st = states[sig];
    var t = {
      el: el, mode: mode, heads: heads, onChange: onChange, sig: sig,
      key: key || (st && st.key) || '', dir: dir || (st && st.dir) || 'ASC', colIdx: 0,
      clientKey: '', clientDir: 'ASC', clientIdx: 0
    };
    heads.forEach(function (th) {
      var row = th.parentNode;
      th.__colIdx = row && row.cells ? Array.prototype.indexOf.call(row.cells, th) : 0;
      if (!th.style.cursor) th.style.cursor = 'pointer';
      th.style.userSelect = 'none';
      if (!th.getAttribute('title')) th.setAttribute('title', 'Сортировка: А→Я / Я→А');
      th.addEventListener('click', function (ev) {
        // Клик по чекбоксу/ссылке внутри заголовка не сортирует.
        if (ev.target && ev.target.closest && ev.target.closest('input,button,select,textarea,a')) return;
        ev.preventDefault();
        ev.stopPropagation();
        var k = th.getAttribute('data-sort');
        if (th.hasAttribute('data-sort-client')) {
          // Столбец сортируется на клиенте (в пределах загруженной страницы).
          if (t.clientKey === k) t.clientDir = (t.clientDir === 'ASC' ? 'DESC' : 'ASC');
          else { t.clientKey = k; t.clientDir = 'ASC'; }
          t.clientIdx = th.__colIdx;
          paint(t);
          sortRows(t, t.clientIdx, t.clientDir);
          return;
        }
        if (t.key === k) t.dir = (t.dir === 'ASC' ? 'DESC' : 'ASC');
        else { t.key = k; t.dir = 'ASC'; }
        t.colIdx = th.__colIdx;
        t.clientKey = '';
        states[t.sig] = {key: t.key, dir: t.dir};
        paint(t);
        if (t.mode === 'server') { if (t.onChange) t.onChange(t.key, t.dir); }
        else sortRows(t, t.colIdx, t.dir);
      });
    });
    if (t.key) {
      var active = heads.filter(function (th) { return th.getAttribute('data-sort') === t.key; })[0];
      if (active) t.colIdx = active.__colIdx;
    }
    paint(t);
    seen.push(el);
    return t;
  }

  function observe(t) {
    var tb = t.el.tBodies[0];
    if (!tb || typeof MutationObserver === 'undefined') return;
    var mo = new MutationObserver(function () { if (t.key) sortRows(t, t.colIdx, t.dir); });
    mo.observe(tb, {childList: true});
  }

  /** Инициализация всех клиентских таблиц (в т.ч. появившихся позже). */
  function scanTables() {
    qa(document, 'table').forEach(function (el) {
      if (isSeen(el)) return;
      if (el.getAttribute('data-sort-mode') === 'server') return;
      if (!el.querySelector('th')) return;
      var t = initTable(el, 'client', '', '');
      if (t) {
        observe(t);
        if (t.key) sortRows(t, t.colIdx, t.dir);
      }
    });
  }

  function scheduleScan() {
    if (scanTimer) return;
    scanTimer = setTimeout(function () { scanTimer = null; scanTables(); }, 30);
  }

  var api = {
    /** @param {{mode?:string,table?:*,key?:string,dir?:string,onChange?:function}} opts */
    init: function (opts) {
      opts = opts || {};
      if (opts.mode === 'server') {
        var el = opts.table
          ? (typeof opts.table === 'string' ? document.querySelector(opts.table) : opts.table)
          : document.querySelector('table[data-sort-mode="server"]');
        if (el) serverTable = initTable(el, 'server', opts.key, opts.dir, opts.onChange);
      }
      scanTables();
      return api;
    },
    /** Добавить sort/dir в параметры запроса (серверная сортировка). */
    params: function (p) {
      if (p && serverTable) { p.set('sort', serverTable.key); p.set('dir', serverTable.dir); }
      return p;
    },
    state: function () { return serverTable ? {key: serverTable.key, dir: serverTable.dir} : null; },
    thLabel: thLabel,
    setArrow: setArrow
  };

  if (typeof MutationObserver !== 'undefined') {
    new MutationObserver(scheduleScan).observe(document.documentElement, {childList: true, subtree: true});
  }
  var css = 'th[data-sort]{cursor:pointer;user-select:none}.sort-arrow{margin-left:4px;font-size:11px}';
  var st = document.createElement('style');
  st.textContent = css;
  (document.head || document.documentElement).appendChild(st);

  global.Sort = api;
  // Клиентские таблицы подхватываем сразу (модули без серверной сортировки).
  scanTables();
})(window);
