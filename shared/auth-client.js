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
      var bell = el.querySelector('#bellbtn');
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
      var w = document.getElementById('wms-auth-widget');
      if (!w) {
        w = document.createElement('div');
        w.id = 'wms-auth-widget';
        w.style.cssText = 'position:fixed;top:10px;right:14px;z-index:2147483000;background:#fff;border:1px solid #EAEAEA;border-radius:12px;box-shadow:0 6px 18px rgba(0,0,0,.12);padding:7px 10px;display:flex;gap:8px;align-items:center;font:13px Arial,Helvetica,sans-serif;color:#1C2331';
        document.body.appendChild(w);
      }
      while (w.firstChild) w.removeChild(w.firstChild);
      var ava2 = document.createElement('span');
      ava2.className = 'ava';
      ava2.style.cssText = 'width:30px;height:30px;border-radius:50%;background:#EAEAEA;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:11px';
      ava2.textContent = initials(user.fio || user.login);
      w.appendChild(ava2);
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
    var tsd = nav.querySelector('a[href$="tsd/app.html"]');
    if (!tsd && levels.tsd !== 'none') {
      tsd = document.createElement('a');
      tsd.className = 'mi';
      tsd.href = '../tsd/app.html';
      tsd.textContent = '📱 ТСД';
    }
    if (a && tsd) nav.insertBefore(a, tsd);
    else if (a) nav.appendChild(a);
    if (tsd) nav.appendChild(tsd);
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

  function boot() {
    ensureNav();
    if (window.WMS_USER) paintUser(window.WMS_USER);
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
