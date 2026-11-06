/* ТСД: вход по логину/паролю сотрудника и ФИО в шапке на всех экранах.
 * Подключается на app.html, prihod.html, otbor.html, sort.html.
 */
(function () {
  'use strict';
  var ME = null;
  try { ME = JSON.parse(localStorage.getItem('tsd_me') || 'null'); } catch (e) { ME = null; }
  var ROLE_KEY = 'tsd_role';

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]);
    });
  }

  function mapRole(r) {
    var s = String(r || '');
    if (/водител|driver/i.test(s)) return 'driver';
    if (/кладов|storekeeper|админ|менедж|прием|разработ/i.test(s)) return 'storekeeper';
    return 'storekeeper';
  }

  function setWho() {
    var els = document.querySelectorAll('#who, .who');
    for (var i = 0; i < els.length; i++) {
      els[i].textContent = (ME && ME.fio) ? ('· ' + ME.fio) : '';
      els[i].title = (ME && ME.login) ? ('Логин: ' + ME.login) : '';
    }
  }

  function ensureLogout() {
    var header = document.querySelector('header');
    if (!header || document.getElementById('tsd-out')) return;
    var b = document.createElement('button');
    b.id = 'tsd-out';
    b.type = 'button';
    b.textContent = 'Выйти';
    b.style.cssText = 'width:auto;margin-left:8px;padding:6px 10px;font-size:12px;background:#2A3446;color:#fff;border:1px solid #3A4658;border-radius:8px;cursor:pointer';
    b.onclick = function () {
      try { localStorage.removeItem('tsd_me'); localStorage.removeItem('tsd_role'); } catch (e) {}
      fetch('login.php?action=logout', { method: 'POST', credentials: 'same-origin' }).catch(function () {});
      location.reload();
    };
    (header.querySelector('.row') || header.querySelector('.t') || header).appendChild(b);
  }

  function showGate() {
    if (document.getElementById('tsd-gate')) return;
    var wrap = document.createElement('div');
    wrap.id = 'tsd-gate';
    wrap.style.cssText = 'position:fixed;inset:0;z-index:2147483000;background:#0F1620;color:#fff;display:flex;align-items:center;justify-content:center;font:14px Arial,Helvetica,sans-serif;padding:16px';
    wrap.innerHTML = '<div style="width:360px;max-width:96vw;background:#1C2331;border:1px solid #2A3446;border-radius:14px;padding:22px">'
      + '<div style="font-size:12px;color:#8A92A6;letter-spacing:.06em">МОДУЛЬ ТСД</div>'
      + '<h2 style="margin:4px 0 14px;font-size:22px">Вход сотрудника</h2>'
      + '<label style="display:block;font-size:12px;color:#B9C0CE;margin:10px 0 4px">Логин</label>'
      + '<input id="tsd-g-login" autocomplete="username" style="width:100%;box-sizing:border-box;padding:11px;border-radius:10px;border:1px solid #3A4658;background:#0F1620;color:#fff;font-size:15px">'
      + '<label style="display:block;font-size:12px;color:#B9C0CE;margin:10px 0 4px">Пароль</label>'
      + '<input id="tsd-g-pass" type="password" autocomplete="current-password" style="width:100%;box-sizing:border-box;padding:11px;border-radius:10px;border:1px solid #3A4658;background:#0F1620;color:#fff;font-size:15px">'
      + '<button id="tsd-g-go" style="width:100%;margin-top:16px;padding:12px;border:0;border-radius:10px;background:#6432FF;color:#fff;font-size:15px;font-weight:700;cursor:pointer">Войти</button>'
      + '<div id="tsd-g-err" style="color:#FF8A80;font-size:13px;margin-top:10px;min-height:18px"></div>'
      + '<div style="color:#8A92A6;font-size:12px;margin-top:8px">Логин и пароль выдаёт администратор в модуле «Сотрудники».</div>'
      + '</div>';
    document.body.appendChild(wrap);
    var li = document.getElementById('tsd-g-login');
    var lp = document.getElementById('tsd-g-pass');
    var err = document.getElementById('tsd-g-err');
    var btn = document.getElementById('tsd-g-go');
    setTimeout(function () { li.focus(); }, 60);

    function submit() {
      var login = (li.value || '').trim();
      var pass = lp.value || '';
      if (!login || !pass) { err.textContent = 'Укажите логин и пароль'; return; }
      btn.disabled = true;
      btn.textContent = 'Проверка…';
      fetch('login.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ login: login, password: pass })
      }).then(function (r) {
        return r.json().then(function (j) { return { ok: r.ok, j: j }; });
      }).then(function (res) {
        if (!res.ok || !res.j || !res.j.ok) {
          err.textContent = (res.j && res.j.error) ? res.j.error : 'Не удалось войти';
          btn.disabled = false;
          btn.textContent = 'Войти';
          return;
        }
        ME = { id: res.j.id, fio: res.j.fio, role: res.j.role, login: res.j.login };
        try {
          localStorage.setItem('tsd_me', JSON.stringify(ME));
          localStorage.setItem(ROLE_KEY, mapRole(res.j.role));
        } catch (e) {}
        location.reload();
      }).catch(function (e) {
        err.textContent = 'Нет связи с сервером';
        btn.disabled = false;
        btn.textContent = 'Войти';
      });
    }
    btn.onclick = submit;
    lp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); submit(); } });
    li.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); lp.focus(); } });
  }

  function init() {
    if (ME && ME.id) {
      if (!ME.login) {
        // Старые данные без логина — требуем повторный вход по учётной записи.
        try { localStorage.removeItem('tsd_me'); localStorage.removeItem(ROLE_KEY); } catch (e) {}
        ME = null;
        showGate();
        return;
      }
      // Роль из учётной записи (водитель/кладовщик) — как в меню ТСД.
      try { localStorage.setItem(ROLE_KEY, mapRole(ME.role)); } catch (e) {}
      setWho();
      ensureLogout();
      return;
    }
    showGate();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
