/* ТСД: не открываем экранную клавиатуру автоматически при переходе между экранами.
 * Сканер (аппаратные клавиши) продолжает работать: inputmode не влияет на keydown.
 * Клавиатура появляется только когда пользователь сам тапнул по полю.
 */
(function () {
  'use strict';

  function isTextField(el) {
    if (!el) return false;
    var tag = (el.tagName || '').toUpperCase();
    if (tag === 'TEXTAREA') return true;
    if (tag !== 'INPUT') return false;
    var t = (el.getAttribute('type') || 'text').toLowerCase();
    return ['text', 'search', 'tel', 'url', 'email', 'password', 'number', ''].indexOf(t) >= 0;
  }

  function rememberMode(el) {
    if (!el || el.dataset.kbMode) return;
    el.dataset.kbMode = el.getAttribute('inputmode') || (el.type === 'number' ? 'decimal' : 'text');
  }

  function disableKb(el) {
    if (!isTextField(el)) return;
    rememberMode(el);
    if (el.dataset.kbUser !== '1') {
      el.setAttribute('inputmode', 'none');
    }
  }

  function enableKb(el) {
    if (!isTextField(el)) return;
    rememberMode(el);
    el.dataset.kbUser = '1';
    el.setAttribute('inputmode', el.dataset.kbMode);
  }

  function scanAll(root) {
    var scope = root && root.querySelectorAll ? root : document;
    var list = scope.querySelectorAll('input,textarea');
    for (var i = 0; i < list.length; i++) disableKb(list[i]);
    if (root && isTextField(root)) disableKb(root);
  }

  // Тап/клик по полю — это действие пользователя: разрешаем клавиатуру.
  document.addEventListener('pointerdown', function (e) { enableKb(e.target); }, true);
  document.addEventListener('touchstart', function (e) { enableKb(e.target); }, true);
  document.addEventListener('mousedown', function (e) { enableKb(e.target); }, true);

  // Новые поля (экраны перерисовываются SPA-логикой) — сразу без авто-клавиатуры.
  var pending = null;
  function scheduleScan() {
    if (pending) return;
    pending = setTimeout(function () { pending = null; scanAll(document); }, 30);
  }
  if (window.MutationObserver) {
    new MutationObserver(function (muts) {
      for (var i = 0; i < muts.length; i++) {
        var m = muts[i];
        if (m.addedNodes && m.addedNodes.length) { scheduleScan(); return; }
        if (m.type === 'attributes') { scheduleScan(); return; }
      }
    }).observe(document.documentElement, { childList: true, subtree: true });
  }

  function boot() {
    scanAll(document);
    var a = document.activeElement;
    if (a && isTextField(a) && a.dataset.kbUser !== '1') {
      try { a.blur(); } catch (e) { }
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
