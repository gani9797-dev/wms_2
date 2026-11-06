/* Общий блок входа/пользователя для всех вкладок WMS. */
(function () {
  'use strict';

  var lastKey = '';

  function esc(v) {
    return String(v == null ? '' : v);
  }

  function initials(fio) {
    var parts = esc(fio).trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return '?';
    return (parts[0][0] || '') + (parts[1] ? (parts[1][0] || '') : '');
  }

  function userMarkup(user) {
    var wrap = document.createElement('span');
    wrap.className = 'wms-auth-name';
    wrap.style.cssText = 'display:inline-block;line-height:1.2';

    var name = document.createElement('b');
    name.textContent = esc(user.fio || user.login || 'Пользователь');
    name.style.cssText = 'font-weight:700';

    var meta = document.createElement('small');
    meta.style.cssText = 'display:block;color:#8A92A6;font-size:11px';
    meta.textContent = (user.role || '') + (user.login ? ' · ' + user.login : '');

    wrap.appendChild(name);
    wrap.appendChild(document.createElement('br'));
    wrap.appendChild(meta);
    return wrap;
  }

  function logoutLink() {
    var a = document.createElement('a');
    a.href = '/logout';
    a.textContent = 'Выйти';
    a.title = 'Выйти из аккаунта';
    a.style.cssText = 'color:#6432FF;text-decoration:none;font-size:12px;white-space:nowrap';
    return a;
  }

  function paintUser(user) {
    if (!user) return;
    var found = false;
    document.querySelectorAll('.user').forEach(function (el) {
      found = true;
      var bell = el.querySelector('#bellbtn') || el.querySelector('.bell');
      while (el.firstChild) el.removeChild(el.firstChild);
      if (bell) el.appendChild(bell);

      var ava = document.createElement('span');
      ava.className = 'ava';
      ava.textContent = initials(user.fio || user.login);
      el.appendChild(ava);
      el.appendChild(userMarkup(user));
      el.appendChild(logoutLink());
      el.style.gap = '8px';
      el.style.alignItems = 'center';
    });

    if (!found) {
      // Модули без шапки пользователя: ставим блок в сайдбар (единый вид во всех модулях).
      var aside = document.querySelector('aside') || document.querySelector('.sidebar');
      if (aside) {
        var old = aside.querySelector('.wms-auth-aside');
        if (old) old.parentNode.removeChild(old);
        var box = document.createElement('div');
        box.className = 'wms-auth-aside';
        box.style.cssText = 'display:flex;gap:8px;align-items:center;margin-top:16px;padding-top:12px;border-top:1px solid #333';
        var ava2 = document.createElement('span');
        ava2.className = 'ava';
        ava2.style.cssText = 'width:30px;height:30px;border-radius:50%;background:#2A3446;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:11px;flex:0 0 auto';
        ava2.textContent = initials(user.fio || user.login);
        var name = document.createElement('span');
        name.style.cssText = 'display:block;line-height:1.2;min-width:0';
        var b = document.createElement('b');
        b.style.cssText = 'font-weight:700;color:#fff';
        b.textContent = esc(user.fio || user.login || 'Пользователь');
        var sm = document.createElement('small');
        sm.style.cssText = 'display:block;color:#8A92A6;font-size:11px';
        sm.textContent = (user.position || user.role || '') + (user.login ? ' · ' + user.login : '');
        name.appendChild(b);
        name.appendChild(document.createElement('br'));
        name.appendChild(sm);
        var out = logoutLink();
        out.style.marginLeft = 'auto';
        box.appendChild(ava2);
        box.appendChild(name);
        box.appendChild(out);
        aside.appendChild(box);
        return;
      }
      var w = document.getElementById('wms-auth-widget');
      if (!w) {
        w = document.createElement('div');
        w.id = 'wms-auth-widget';
        w.style.cssText = 'position:fixed;top:10px;right:14px;z-index:2147483000;background:#fff;border:1px solid #EAEAEA;border-radius:12px;box-shadow:0 6px 18px rgba(0,0,0,.12);padding:7px 10px;display:flex;gap:8px;align-items:center;font:13px Arial,Helvetica,sans-serif;color:#1C2331';
        document.body.appendChild(w);
      }
      while (w.firstChild) w.removeChild(w.firstChild);
      var ava3 = document.createElement('span');
      ava3.className = 'ava';
      ava3.style.cssText = 'width:30px;height:30px;border-radius:50%;background:#EAEAEA;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:11px';
      ava3.textContent = initials(user.fio || user.login);
      w.appendChild(ava3);
      w.appendChild(userMarkup(user));
      w.appendChild(logoutLink());
    }
  }

  function applyPerms(perms) {
    window.WMS_PERMS = perms || {};
    document.querySelectorAll('[data-perm]').forEach(function (el) {
      var key = el.getAttribute('data-perm');
      var ok = !!window.WMS_PERMS[key];
      el.style.display = ok ? '' : 'none';
      if ('disabled' in el) el.disabled = !ok || el.getAttribute('data-locked') === '1';
    });
  }

  window.wmsApplyPerms = function () {
    applyPerms(window.WMS_PERMS || {});
  };

  function ensureNav() {
    var nav = document.querySelector('aside nav.nav');
    if (!nav) return;
    var levels = window.WMS_MODULE_LEVELS || {};
    var a = nav.querySelector('a[href$="15-etiketki/app.html"]');
    if (!a && levels.labels !== 'none') {
      a = document.createElement('a');
      a.className = 'mi';
      a.href = '../15-etiketki/app.html';
      a.textContent = '🏷️ 15 Этикетки';
    }
    var bill = nav.querySelector('a[href$="16-billing/app.html"]');
    if (!bill && levels.billing !== 'none') {
      bill = document.createElement('a');
      bill.className = 'mi';
      bill.href = '../16-billing/app.html';
      bill.textContent = '💳 16 Биллинги';
    }
    var tsd = nav.querySelector('a[href$="tsd/app.html"]');
    if (!tsd && levels.tsd !== 'none') {
      tsd = document.createElement('a');
      tsd.className = 'mi';
      tsd.href = '../tsd/app.html';
      tsd.textContent = '📱 ТСД';
    }
    // Порядок: … 15 Этикетки → 16 Биллинги → ТСД.
    if (bill && bill.parentNode !== nav) {
      if (tsd && tsd.parentNode === nav) nav.insertBefore(bill, tsd);
      else nav.appendChild(bill);
    }
    if (a && a.parentNode !== nav) {
      if (bill && bill.parentNode === nav) nav.insertBefore(a, bill);
      else if (tsd && tsd.parentNode === nav) nav.insertBefore(a, tsd);
      else nav.appendChild(a);
    }
    if (tsd && tsd.parentNode !== nav) nav.appendChild(tsd);
  }
  // Печать этикеток и просмотр кодов — только администратор и разработчик.
  window.wmsCanPrintLabels = function (data) {
    var perms = (data && data.permissions) || window.WMS_PERMS || {};
    if (perms.labels_print) return true;
    var u = (data && data.user) || window.WMS_USER || null;
    var role = u ? String(u.role || '') : '';
    return role === 'Администратор' || role === 'Разработчик';
  };
  // --- Колокольчик журнала ошибок (виден только роли «Разработчик») ---
  var devTimer = null;
  var devPanelEl = null;

  function devAllowed(data) {
    var perms = (data && data.permissions) || window.WMS_PERMS || {};
    if (perms.dev_errors) return true;
    var u = (data && data.user) || window.WMS_USER || null;
    return !!u && String(u.role || '') === 'Разработчик';
  }

  function devEsc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]);
    });
  }

  function devBellEl() {
    return document.getElementById('wms-dev-bell');
  }

  function devBellRemove() {
    var b = devBellEl();
    if (b && b.parentNode) b.parentNode.removeChild(b);
    if (devPanelEl && devPanelEl.parentNode) devPanelEl.parentNode.removeChild(devPanelEl);
    devPanelEl = null;
    if (devTimer) { clearInterval(devTimer); devTimer = null; }
  }

  function devMount(data) {
    if (!devAllowed(data)) { devBellRemove(); return; }
    if (devBellEl()) { devCount(); return; }
    var btn = document.createElement('button');
    btn.id = 'wms-dev-bell';
    btn.type = 'button';
    btn.title = 'Журнал ошибок (роль «Разработчик»)';
    btn.style.cssText = 'display:flex;align-items:center;gap:6px;background:#1C2331;color:#fff;border:1px solid #3A4658;'
      + 'border-radius:10px;padding:7px 11px;cursor:pointer;font:13px Arial,Helvetica,sans-serif;margin:0 0 10px'
      + (document.querySelector('aside') ? ';width:100%;justify-content:center' : ';position:fixed;left:12px;top:12px;z-index:2147483000;box-shadow:0 6px 18px rgba(0,0,0,.18)');
    btn.innerHTML = '🐞 Ошибки <span id="wms-dev-count" style="background:#C62828;color:#fff;border-radius:10px;'
      + 'padding:0 7px;font-size:11px;font-weight:700;display:none">0</span>';
    btn.onclick = function (e) {
      e.stopPropagation();
      devTogglePanel();
    };
    var aside = document.querySelector('aside');
    if (aside) {
      var hide = document.getElementById('navhidebtn');
      if (hide && hide.parentNode === aside) aside.insertBefore(btn, hide.nextSibling);
      else aside.insertBefore(btn, aside.firstChild);
    } else {
      document.body.appendChild(btn);
    }
    devCount();
    if (devTimer) clearInterval(devTimer);
    devTimer = setInterval(devCount, 60000);
  }

  function devCount() {
    if (!devBellEl()) return;
    fetch('/shared/errors.php?action=count', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        var c = document.getElementById('wms-dev-count');
        if (!c || !j) return;
        var n = Number(j.unread || 0);
        c.textContent = n > 99 ? '99+' : String(n);
        c.style.display = n > 0 ? 'inline-block' : 'none';
      })
      .catch(function () {});
  }

  function devPanelEnsure() {
    if (devPanelEl) return devPanelEl;
    var p = document.createElement('div');
    p.id = 'wms-dev-panel';
    p.style.cssText = 'position:fixed;left:12px;top:60px;width:620px;max-width:96vw;max-height:76vh;overflow:auto;'
      + 'background:#fff;border:1px solid #EAEAEA;border-radius:12px;box-shadow:0 12px 32px rgba(0,0,0,.18);'
      + 'z-index:2147483001;display:none;font:13px Arial,Helvetica,sans-serif;color:#1C2331;padding:14px 16px';
    p.innerHTML = '<div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">'
      + '<b style="font-size:15px">Журнал ошибок</b><span id="wms-dev-total" class=""></span>'
      + '<span style="flex:1"></span>'
      + '<button type="button" id="wms-dev-reload" style="border:1px solid #EAEAEA;background:#fff;border-radius:8px;padding:6px 10px;cursor:pointer">Обновить</button>'
      + '<button type="button" id="wms-dev-read" style="border:1px solid #EAEAEA;background:#fff;border-radius:8px;padding:6px 10px;cursor:pointer">Прочитать все</button>'
      + '<button type="button" id="wms-dev-clear" style="border:1px solid #EF9A9A;background:#FFF3F3;color:#B71C1C;border-radius:8px;padding:6px 10px;cursor:pointer">Очистить</button>'
      + '<button type="button" id="wms-dev-close" style="border:0;background:none;font-size:18px;cursor:pointer;color:#8A92A6">✕</button>'
      + '</div><div id="wms-dev-list"><div style="color:#8A92A6">Загрузка…</div></div>';
    document.body.appendChild(p);
    devPanelEl = p;
    p.querySelector('#wms-dev-close').onclick = function () { p.style.display = 'none'; };
    p.querySelector('#wms-dev-reload').onclick = devLoad;
    p.querySelector('#wms-dev-read').onclick = function () {
      fetch('/shared/errors.php?action=read', {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ids: [] })
      }).then(devLoad).catch(function () {});
    };
    p.querySelector('#wms-dev-clear').onclick = function () {
      if (!confirm('Очистить журнал ошибок полностью?')) return;
      fetch('/shared/errors.php?action=clear', {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
        body: '{}'
      }).then(devLoad).catch(function () {});
    };
    return p;
  }

  function devTogglePanel() {
    var p = devPanelEnsure();
    var open = p.style.display === 'block';
    p.style.display = open ? 'none' : 'block';
    if (!open) devLoad();
  }

  var DEV_LEVEL_COLORS = { fatal: '#B71C1C', error: '#C62828', api: '#7A4B00', warning: '#7A4B00', php: '#1C2331' };

  function devLoad() {
    var p = devPanelEnsure();
    var box = p.querySelector('#wms-dev-list');
    box.innerHTML = '<div style="color:#8A92A6">Загрузка…</div>';
    fetch('/shared/errors.php?action=list&limit=100', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) {
        if (r.status === 403) throw new Error('Журнал ошибок доступен только роли «Разработчик»');
        return r.ok ? r.json() : Promise.reject(new Error('HTTP ' + r.status));
      })
      .then(function (j) {
        var rows = j.rows || [];
        devCount();
        if (!rows.length) {
          box.innerHTML = '<div style="color:#8A92A6">Ошибок нет — журнал пуст.</div>';
          return;
        }
        box.innerHTML = rows.map(function (r) {
          var col = DEV_LEVEL_COLORS[r.level] || '#1C2331';
          var dt = String(r.created_at || '').replace('T', ' ').slice(0, 19);
          return '<div class="wms-dev-row" data-id="' + r.id + '" style="border:1px solid ' + (r.is_read ? '#F0F0F0' : '#F5C6C6') + ';'
            + 'border-radius:8px;padding:8px 10px;margin-bottom:8px;cursor:pointer;background:' + (r.is_read ? '#fff' : '#FFF7F7') + '">'
            + '<div style="display:flex;gap:8px;align-items:baseline">'
            + '<span style="font-size:11px;font-weight:700;color:' + col + ';text-transform:uppercase">' + devEsc(r.level) + '</span>'
            + '<span style="color:#8A92A6;font-size:12px">' + devEsc(dt) + '</span>'
            + (r.module ? '<span style="color:#8A92A6;font-size:12px">· ' + devEsc(r.module) + '</span>' : '')
            + (Number(r.seen_count) > 1 ? '<span style="color:#7A4B00;font-size:12px">· повторов: ' + Number(r.seen_count) + '</span>' : '')
            + '</div>'
            + '<div style="margin-top:4px;word-break:break-word">' + devEsc(String(r.message || '').slice(0, 400)) + '</div>'
            + '<div style="color:#8A92A6;font-size:12px;margin-top:4px">' + devEsc(r.method || '') + ' ' + devEsc(String(r.url || '').slice(0, 160))
            + (r.user_fio ? ' · ' + devEsc(r.user_fio) : '') + (r.role ? ' (' + devEsc(r.role) + ')' : '') + '</div>'
            + '<div class="wms-dev-more" style="display:none;margin-top:8px;background:#FAFAFC;border:1px solid #F0F0F0;border-radius:6px;padding:8px">'
            + (r.file ? '<div style="color:#8A92A6;font-size:12px">' + devEsc(r.file) + (r.line ? ':' + r.line : '') + '</div>' : '')
            + (r.trace ? '<pre style="white-space:pre-wrap;font-size:11px;margin:6px 0 0;color:#444">' + devEsc(String(r.trace).slice(0, 4000)) + '</pre>' : '')
            + (r.context ? '<pre style="white-space:pre-wrap;font-size:11px;margin:6px 0 0;color:#444">' + devEsc(String(r.context).slice(0, 2000)) + '</pre>' : '')
            + '</div></div>';
        }).join('');
        box.querySelectorAll('.wms-dev-row').forEach(function (el) {
          el.onclick = function () {
            var more = el.querySelector('.wms-dev-more');
            more.style.display = more.style.display === 'block' ? 'none' : 'block';
            var id = Number(el.getAttribute('data-id'));
            if (id && el.style.background !== '#fff') {
              el.style.background = '#fff';
              el.style.borderColor = '#F0F0F0';
              fetch('/shared/errors.php?action=read', {
                method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ids: [id] })
              }).then(function () { devCount(); }).catch(function () {});
            }
          };
        });
      })
      .catch(function (e) {
        box.innerHTML = '<div style="color:#B71C1C">' + devEsc(e.message) + '</div>';
      });
  }
  function applySession(data) {
    if (!data || !data.user) {
      location.href = '/login';
      return;
    }
    window.WMS_USER = data.user;
    window.WMS_MODULE_LEVELS = data.module_levels || {};
    window.WMS_PERMS = data.permissions || {};
    paintUser(data.user);
    applyPerms(window.WMS_PERMS);
    devMount(data);

    var key = JSON.stringify({
      id: data.user.id,
      role: data.user.role,
      perms: data.permissions || {},
      levels: data.module_levels || {}
    });
    if (lastKey && key !== lastKey) {
      document.dispatchEvent(new CustomEvent('wms:auth', { detail: data }));
    }
    lastKey = key;
  }

  function refresh() {
    return fetch('/shared/session.php', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) {
        if (r.status === 401) {
          location.href = '/login';
          return null;
        }
        return r.ok ? r.json() : null;
      })
      .then(function (data) {
        if (data) applySession(data);
      })
      .catch(function () {});
  }

  // --- Скрыть/показать весь список модулей (кнопка внизу слева, под пунктами) ---
  function ensureNavToggle() {
    var aside = document.querySelector('aside');
    if (!aside) return;                       // ТСД и страницы без меню
    if (document.getElementById('wms-nav-hide')) return;
    // Свои кнопки модуля (если есть) убираем, чтобы не дублировать.
    var ownHide = document.getElementById('navhidebtn');
    if (ownHide && ownHide.parentNode) {
      ownHide.parentNode.removeChild(ownHide);
    }

    var css = document.createElement('style');
    css.textContent = ''
      + '#wms-nav-hide{margin-top:14px;width:100%;box-sizing:border-box;background:#2A3446;color:#C9CEDA;'
      + 'border:1px solid #3A4658;border-radius:8px;padding:9px 10px;cursor:pointer;font:13px Arial,Helvetica,sans-serif;display:block}'
      + '#wms-nav-hide:hover{background:#3A4658;color:#fff}'
      + 'body.wms-nav-hidden aside{display:none !important}'
      + '#wms-nav-show{position:fixed;left:12px;bottom:14px;z-index:2147483000;display:none;background:#1C2331;color:#fff;'
      + 'border:1px solid #3A4658;border-radius:10px;padding:9px 13px;cursor:pointer;font:13px Arial,Helvetica,sans-serif;'
      + 'box-shadow:0 6px 18px rgba(0,0,0,.22)}'
      + 'body.wms-nav-hidden #wms-nav-show{display:block}'
      + 'body.wms-nav-hidden #navshowbtn{display:none !important}';
    (document.head || document.documentElement).appendChild(css);

    var hide = document.createElement('button');
    hide.id = 'wms-nav-hide';
    hide.type = 'button';
    hide.title = 'Скрыть список модулей';
    hide.textContent = '◀ Скрыть меню';
    aside.appendChild(hide);

    var show = document.createElement('button');
    show.id = 'wms-nav-show';
    show.type = 'button';
    show.title = 'Показать список модулей';
    show.textContent = '▶ Меню';
    document.body.appendChild(show);

    function apply(hidden) {
      document.body.classList.toggle('wms-nav-hidden', !!hidden);
      try {
        localStorage.setItem('wms_nav_hidden', hidden ? '1' : '0');
      } catch (e) {}
    }
    hide.onclick = function () { apply(true); };
    show.onclick = function () { apply(false); };
    var saved = '0';
    try { saved = localStorage.getItem('wms_nav_hidden') || '0'; } catch (e) {}
    if (saved === '1') apply(true);
  }
  function boot() {
    ensureNav();
    ensureNavToggle();
    if (window.WMS_USER) paintUser(window.WMS_USER);
    if (window.WMS_USER || window.WMS_PERMS) devMount(null);
    if (window.WMS_PERMS) applyPerms(window.WMS_PERMS);
    refresh();
    setInterval(refresh, 60000);
    window.addEventListener('focus', refresh);
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'visible') refresh();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
