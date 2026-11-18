/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */

(function () {
  'use strict';
  var A = window.ADMIN || {};
  function $(s, r) { return (r || document).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  function post(url, data) {
    var fd = data instanceof FormData ? data : (function () { var f = new FormData(); Object.keys(data).forEach(function (k) { f.append(k, data[k]); }); return f; })();
    return fetch(url, { method: 'POST', body: fd, headers: { 'X-CSRF-Token': A.csrf }, credentials: 'same-origin' })
      .then(function (r) {

        if (r.status === 419 || r.status === 403) return { ok: false, error: 'Сессия устарела — откройте админку в новой вкладке, войдите заново и повторите.' };
        return r.json().catch(function () { return { ok: false, error: 'Ошибка сервера (' + r.status + ')' }; });
      })

      .catch(function () { return { ok: false, error: 'Нет соединения с сервером — проверьте интернет и повторите.' }; });
  }


  var anavBtn = $('.anav-btn');
  if (anavBtn) {
    function anavClose() {
      document.body.classList.remove('anav-open');
      anavBtn.setAttribute('aria-expanded', 'false');
    }
    anavBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      var open = document.body.classList.toggle('anav-open');
      anavBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    document.addEventListener('click', function (e) {
      if (!document.body.classList.contains('anav-open')) return;
      if (e.target.closest('#anav a')) { anavClose(); return; }
      if (!e.target.closest('.top')) anavClose();
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') anavClose(); });
  }


  $$('[data-tab]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var t = btn.dataset.tab;
      $$('[data-tab]').forEach(function (b) { b.classList.toggle('on', b === btn); });
      $$('[data-pane]').forEach(function (p) { p.hidden = p.dataset.pane !== t; });
      var f = $('#tabField'); if (f) f.value = t;
    });
  });



  $$('select[data-jsonld-preset]').forEach(function (sel) {
    sel.addEventListener('change', function () {
      var box = sel.closest('.f');
      var dataEl = box ? $('[data-jsonld-presets]', box) : null;
      var form = sel.closest('form');
      var ta = form ? $('textarea[name="jsonld_extra"]', form) : null;
      if (!dataEl || !ta || !sel.value) { return; }
      var presets = {};
      try { presets = JSON.parse(dataEl.textContent); } catch (err) { return; }
      var preset = presets[sel.value];
      if (!preset) { return; }
      var cur = ta.value.trim();
      if (cur === '') {
        ta.value = JSON.stringify(preset, null, 2);
      } else {
        try {
          var parsed = JSON.parse(cur);
          var arr = Object.prototype.toString.call(parsed) === '[object Array]' ? parsed : [parsed];
          arr.push(preset);
          ta.value = JSON.stringify(arr, null, 2);
        } catch (err) {
          ta.value = JSON.stringify(preset, null, 2);
        }
      }
      ta.focus();
      sel.value = '';
    });
  });


  document.addEventListener('submit', function (e) {
    var m = e.target.getAttribute && e.target.getAttribute('data-confirm');
    if (m && !window.confirm(m)) { e.preventDefault(); return; }
    $$('[data-rte]', e.target).forEach(syncRte);
  });
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-confirm-btn]');
    if (b && !window.confirm(b.getAttribute('data-confirm-btn'))) e.preventDefault();
  });


  function syncRte(w) {
    var ed = $('.rte', w), src = $('.rte-src', w);
    if (!src.hidden) { return; }
    src.value = ed.innerHTML;
  }
  $$('[data-rte]').forEach(function (w) {
    var ed = $('.rte', w), src = $('.rte-src', w);

    ed.innerHTML = src.value;

    try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (err) {}
    w.addEventListener('mousedown', function (e) { if (e.target.closest('[data-cmd]')) e.preventDefault(); });
    w.addEventListener('click', function (e) {
      var b = e.target.closest('[data-cmd]'); if (!b) return;
      var c = b.dataset.cmd;
      if (c === 'html') {
        if (src.hidden) { src.value = ed.innerHTML; src.hidden = false; ed.hidden = true; }
        else { ed.innerHTML = src.value; src.hidden = true; ed.hidden = false; }
        return;
      }
      if (!src.hidden) return;
      ed.focus();
      if (c === 'h1' || c === 'h2' || c === 'h3' || c === 'blockquote') {
        var cur = (document.queryCommandValue('formatBlock') || '').toLowerCase();
        document.execCommand('formatBlock', false, cur === c ? '<p>' : '<' + c + '>');
      } else if (c === 'createLink') {
        var sel = window.getSelection();
        var u = window.prompt('Адрес ссылки (https://…, tel:…, mailto:…):', 'https://');
        if (!u) return;
        if (!sel || sel.isCollapsed || !String(sel)) {

          var lt = window.prompt('Текст ссылки:', '');
          if (!lt) return;
          document.execCommand('insertHTML', false, '<a href="' + esc(u) + '">' + esc(lt) + '</a>');
        } else {
          document.execCommand('createLink', false, u);
        }
      } else {
        document.execCommand(c, false, null);
      }
    });
    ed.addEventListener('paste', function (e) {
      e.preventDefault();
      var t = (e.clipboardData || window.clipboardData).getData('text/plain');

      var paras = t.replace(/\r\n?/g, '\n').split(/\n{2,}/).filter(function (p) { return p.trim() !== ''; });
      if (paras.length > 1) {
        document.execCommand('insertHTML', false, paras.map(function (p) { return '<p>' + esc(p).replace(/\n/g, '<br>') + '</p>'; }).join(''));
      } else {
        document.execCommand('insertText', false, t);
      }
    });
  });



  var uploadsActive = 0;

  document.addEventListener('dragover', function (e) { if (e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types, 'Files') >= 0) e.preventDefault(); });
  document.addEventListener('drop', function (e) { if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) e.preventDefault(); });

  function uploadFiles(files, onEach, onStatus) {
    files = Array.prototype.slice.call(files).filter(function (f) { return /^image\//.test(f.type) || /\.(jpe?g|png|webp|gif)$/i.test(f.name); });
    var done = 0, failed = [], notes = [], started = 0;
    if (!files.length) { onStatus && onStatus('Нет подходящих изображений (JPG, PNG, WebP, GIF).', true, 0); return Promise.resolve(); }
    uploadsActive++;

    var PAR = 3;
    function finish() {
      var msg = failed.length ? 'Загружено ' + done + ' из ' + files.length + '. Ошибки:<br>' + failed.map(function (x) { return '<span class="bad">' + esc(x) + '</span>'; }).join('<br>') : 'Готово: ' + done + ' фото';
      if (notes.length) { msg += '<br>' + notes.map(function (x) { return esc(x); }).join('<br>'); }
      onStatus && onStatus(msg, true, failed.length);
    }
    function worker() {
      if (started >= files.length) return Promise.resolve();
      var i = started++;
      onStatus && onStatus('Загрузка ' + (Math.min(started, files.length)) + ' из ' + files.length + ' (до ' + PAR + ' параллельно)…', false, 0);
      var fd = new FormData(); fd.append('file', files[i]);
      return post(A.uploadUrl, fd).then(function (r) {
        if (r.ok) { done++; if (r.note) { notes.push(files[i].name + ': ' + r.note); } onEach && onEach(r.image); } else { failed.push(files[i].name + ' — ' + (r.error || 'ошибка')); }
      }).catch(function () { failed.push(files[i].name + ' — сбой сети'); }).then(worker);
    }
    var workers = [];
    for (var w = 0; w < Math.min(PAR, files.length); w++) { workers.push(worker()); }
    var p = Promise.all(workers).then(finish);
    p.then(function () { uploadsActive--; }, function () { uploadsActive--; });
    return p;
  }


  var pk = null;
  function pickerOpen(opts) {
    if (!pk) buildPicker();
    pk.multi = !!opts.multi; pk.cb = opts.cb; pk.sel = []; pk.next = 0;
    pk.req = (pk.req || 0) + 1; pk.busy = false;
    pk.used = opts.used || [];
    pk.input.multiple = pk.multi;
    pk.grid.innerHTML = ''; pk.status.textContent = ''; pk.more.hidden = true;
    if (pk.q) { pk.q.value = ''; }

    if (pk.albSel) { pk.albSel.value = String(pk.album || 0); }
    pk.done.hidden = !pk.multi; updateDone();
    pk.el.hidden = false; modalLock();
    loadMore();
  }

  var modalsOpen = 0;
  function modalLock() { modalsOpen++; document.body.style.overflow = 'hidden'; }
  function modalUnlock() { modalsOpen = Math.max(0, modalsOpen - 1); if (!modalsOpen) document.body.style.overflow = ''; }

  function pickerClose() { pk.el.hidden = true; modalUnlock(); }
  function updateDone() { pk.done.textContent = 'Добавить выбранные (' + pk.sel.length + ')'; pk.done.disabled = !pk.sel.length; }
  function addItem(img, prepend, select) {
    var d = document.createElement('div'); d.className = 'pk-item'; d.dataset.id = img.id;
    d.dataset.search = ((img.name || '') + ' ' + (img.alt || '')).toLowerCase();
    d.tabIndex = 0; d.setAttribute('role', 'button');
    if (pk.used && pk.used.indexOf(img.id) >= 0) { d.classList.add('used'); d.title = 'Уже добавлено'; }

    if (pk.sel && pk.sel.some(function (s) { return s.id === img.id; })) { d.classList.add('on'); }
    d.innerHTML = '<img src="' + esc(img.thumb) + '" alt="" loading="lazy">'; d._img = img;
    if (prepend) pk.grid.insertBefore(d, pk.grid.firstChild); else pk.grid.appendChild(d);
    if (select) toggle(d);
  }
  function toggle(d) {
    if (d.classList.contains('used')) return;
    if (!pk.multi) { pickerClose(); pk.cb([d._img]); return; }
    var i = pk.sel.findIndex(function (s) { return s.id === d._img.id; });
    if (i >= 0) { pk.sel.splice(i, 1); d.classList.remove('on'); } else { pk.sel.push(d._img); d.classList.add('on'); }
    updateDone();
  }
  function loadMore() {
    if (pk.busy) return;
    pk.busy = true;
    pk.status.textContent = '';
    var album = pk.album, req = pk.req = (pk.req || 0) + 1;

    var url = album ? A.mediaUrl + '&album=' + album + '&off=' + pk.next
                    : A.mediaUrl + '&before=' + pk.next;
    fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) {
      if (req !== pk.req || album !== pk.album) return;
      (r.items || []).forEach(function (i) { addItem(i, false, false); });
      pk.next = r.next; pk.more.hidden = !r.more;
      if (!r.total) pk.status.textContent = album ? 'В этом альбоме пока нет фото.' : 'В медиатеке пока пусто — загрузите фото.';
    }).catch(function () {
      if (req !== pk.req || album !== pk.album) return;

      pk.status.innerHTML = 'Не удалось загрузить список фото. <button type="button" class="btn sm" data-retry>Повторить</button>';
    }).then(function () {
      if (req === pk.req) pk.busy = false;
    });
  }
  function buildPicker() {
    var el = document.createElement('div'); el.className = 'pk'; el.hidden = true;
    el.innerHTML = '<div class="pk-panel" role="dialog" aria-modal="true" aria-label="Выбор фотографии"><div class="pk-head"><strong>Выберите фото</strong>' +
      '<select class="pk-alb" title="Фото одного альбома — удобно, когда съёмок много"></select>' +
      '<input type="search" class="pk-q" placeholder="Поиск: имя файла или alt" data-filter=".pk-grid .pk-item" title="Фильтр по уже загруженному списку — нажмите «Показать ещё», чтобы подгрузить более старые фото"><span style="flex:1"></span>' +
      '<button type="button" class="btn primary" data-up>+ Загрузить с компьютера</button><input type="file" accept="image/*" multiple hidden>' +
      '<button type="button" class="btn" data-x>Закрыть</button></div>' +
      '<div class="pk-body"><div class="upl-status"></div><div class="pk-grid"></div><p><button type="button" class="btn" data-more hidden>Показать ещё</button></p></div>' +
      '<div class="pk-foot"><span style="flex:1"></span><button type="button" class="btn primary" data-done>Добавить</button></div></div>';
    document.body.appendChild(el);
    pk = { el: el, grid: $('.pk-grid', el), status: $('.upl-status', el), more: $('[data-more]', el), done: $('[data-done]', el), input: $('input[type=file]', el), q: $('.pk-q', el), used: [], album: 0 };

    pk.albSel = $('.pk-alb', el);
    var albums = A.albums || [];
    if (albums.length) {
      pk.albSel.innerHTML = '<option value="0">Все фото</option>' + albums.map(function (a) {
        return '<option value="' + a.id + '">Альбом: ' + esc(a.title) + ' (' + a.photos + ')</option>';
      }).join('');
      pk.albSel.addEventListener('change', function () {
        pk.album = parseInt(pk.albSel.value, 10) || 0;
        pk.req = (pk.req || 0) + 1; pk.busy = false;
        pk.next = 0; pk.grid.innerHTML = '';
        loadMore();
      });
    } else {
      pk.albSel.hidden = true;
    }
    el.addEventListener('click', function (e) {
      if (e.target === el || e.target.closest('[data-x]')) pickerClose();
      else if (e.target.closest('[data-retry]')) loadMore();
      else if (e.target.closest('[data-up]')) pk.input.click();
      else if (e.target.closest('[data-more]')) loadMore();
      else if (e.target.closest('[data-done]')) { var s = pk.sel.slice(); pickerClose(); pk.cb(s); }
      else { var it = e.target.closest('.pk-item'); if (it) toggle(it); }
    });

    el.addEventListener('keydown', function (e) {
      if ((e.key === 'Enter' || e.key === ' ') && e.target.classList && e.target.classList.contains('pk-item')) { e.preventDefault(); toggle(e.target); }
    });
    pk.input.addEventListener('change', function () {

      var files = pk.multi ? pk.input.files : Array.prototype.slice.call(pk.input.files).slice(0, 1);
      uploadFiles(files, function (img) { addItem(img, true, true); }, function (t) { pk.status.innerHTML = t; });
      pk.input.value = '';
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !pk.el.hidden) pickerClose(); });
  }



  function bindImgPick(box) {
    var input = $('input[type=hidden]', box), prev = $('.imgpick-prev', box);
    $('[data-pick]', box).addEventListener('click', function () {
      pickerOpen({ multi: false, cb: function (imgs) { input.value = imgs[0].id; prev.innerHTML = '<img src="' + esc(imgs[0].thumb) + '" alt="">'; markDirty(); } });
    });
    $('[data-clear]', box).addEventListener('click', function () { input.value = ''; prev.innerHTML = '<span>нет</span>'; markDirty(); });
  }
  $$('.imgpick').forEach(bindImgPick);



  var undoState = null;
  function undoRemove(gi, grid, refresh) {
    if (undoState) { clearTimeout(undoState.t); undoState.toast.remove(); undoState = null; }
    var next = gi.nextElementSibling;
    gi.remove();
    refresh();
    var toast = document.createElement('div');
    toast.className = 'gi-undo';
    toast.innerHTML = '<span>Фото убрано из галереи</span><button type="button">Отменить</button>';
    document.body.appendChild(toast);
    var t = setTimeout(function () { toast.remove(); if (undoState && undoState.toast === toast) undoState = null; }, 6000);
    undoState = { toast: toast, t: t };
    toast.querySelector('button').addEventListener('click', function () {
      clearTimeout(t);
      if (next && next.parentNode === grid) grid.insertBefore(gi, next); else grid.appendChild(gi);
      refresh();
      toast.remove();
      if (undoState && undoState.toast === toast) undoState = null;
    });
  }
  $$('.galed').forEach(function (ed) {
    var key = ed.dataset.key, grid = $('.galed-grid', ed), count = $('.galed-count', ed), drag = null;
    function refresh() { var n = $$('.gi', grid).length; count.textContent = n ? n + ' фото' : 'Фото пока нет'; }
    function add(img) {
      if ($('.gi[data-id="' + img.id + '"]', grid)) return;
      var d = document.createElement('div'); d.className = 'gi'; d.draggable = true; d.dataset.id = img.id;
      d.innerHTML = '<img src="' + esc(img.thumb) + '" alt="">' +
        '<div class="gi-mv"><button type="button" data-mv="-1" title="Раньше" aria-label="Переместить раньше">◀</button>' +
        '<button type="button" data-mv="1" title="Позже" aria-label="Переместить позже">▶</button></div>' +
        '<button type="button" class="gi-del" title="Убрать из галереи">×</button>' +
        '<input type="hidden" name="gal[' + esc(key) + '][]" value="' + esc(String(img.id)) + '">';
      grid.appendChild(d);
      markDirty();
    }
    refresh();
    $('[data-add-photos]', ed).addEventListener('click', function () {

      var usedIds = $$('.gi', grid).map(function (gi) { return parseInt(gi.dataset.id, 10) || 0; });
      pickerOpen({ multi: true, used: usedIds, cb: function (imgs) { imgs.forEach(add); refresh(); } });
    });
    grid.addEventListener('click', function (e) {
      var mv = e.target.closest('[data-mv]');
      if (mv) {
        var giMv = mv.closest('.gi');
        var sib = mv.dataset.mv === '-1' ? giMv.previousElementSibling : giMv.nextElementSibling;
        if (sib) {
          if (mv.dataset.mv === '-1') grid.insertBefore(giMv, sib); else grid.insertBefore(sib, giMv);
          markDirty();
        }
        return;
      }
      var b = e.target.closest('.gi-del');
      if (b) {
        var gi = b.closest('.gi');
        undoRemove(gi, grid, refresh);
        markDirty();
      }
    });
    grid.addEventListener('dragstart', function (e) {
      var gi = e.target.closest && e.target.closest('.gi'); if (!gi) return;
      drag = gi; gi.classList.add('drag'); grid.classList.add('dnd');
      e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', '');
    });
    grid.addEventListener('dragend', function () { if (drag) { drag.classList.remove('drag'); markDirty(); } grid.classList.remove('dnd'); drag = null; });
    grid.addEventListener('dragover', function (e) {
      if (!drag) return; e.preventDefault();
      var after = null, items = $$('.gi:not(.drag)', grid);
      for (var i = 0; i < items.length; i++) {
        var r = items[i].getBoundingClientRect();
        if (e.clientY < r.top || (e.clientY <= r.bottom && e.clientX < r.left + r.width / 2)) { after = items[i]; break; }
      }
      if (after) grid.insertBefore(drag, after); else grid.appendChild(drag);
    });

    ed.addEventListener('dragover', function (e) { if (!drag && e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types, 'Files') >= 0) { e.preventDefault(); ed.classList.add('over'); } });
    ed.addEventListener('dragleave', function (e) { if (e.target === ed) ed.classList.remove('over'); });
    ed.addEventListener('drop', function (e) {
      if (drag || !e.dataTransfer.files.length) return; e.preventDefault(); ed.classList.remove('over');
      uploadFiles(e.dataTransfer.files, function (img) { add(img); refresh(); }, function (t, fin) { count.innerHTML = t; if (fin) setTimeout(refresh, 4000); });
    });
  });


  $$('form').forEach(function (f) {
    if (!f.querySelector('.galed')) return;
    f.addEventListener('submit', function () {
      $$('.galed', f).forEach(function (ed) {
        var key = ed.dataset.key;
        var ids = $$('.gi', ed).map(function (gi) { return parseInt(gi.dataset.id, 10) || 0; }).filter(function (id) { return id > 0; });
        var h = $('input.gal-json', ed);
        if (!h) {
          h = document.createElement('input');
          h.type = 'hidden'; h.className = 'gal-json'; h.name = 'gal_json[' + key + ']';
          ed.appendChild(h);
        }
        h.value = JSON.stringify(ids);
        $$('input[name^="gal["]', ed).forEach(function (i) { i.disabled = true; });
      });
    });
  });


  $$('select[name$="[mode]"]').forEach(function (sel) {
    var card = sel.closest('.sec-card');
    if (!card) return;
    var apply = function () {
      var children = sel.value === 'children';
      var pagesEl = $('[data-listing-pages]', card), childrenEl = $('[data-listing-children]', card);
      if (pagesEl) pagesEl.hidden = children;
      if (childrenEl) childrenEl.hidden = !children;
    };
    sel.addEventListener('change', apply);
    apply();
  });


  $$('select[name$="[layout]"]').forEach(function (sel) {
    var card = sel.closest('.sec-card');
    if (!card) return;
    var stripRow = $('[data-strip-only]', card), slidesRow = $('[data-slides-only]', card);
    if (!stripRow && !slidesRow) return;
    var apply = function () {
      if (stripRow) stripRow.hidden = sel.value !== 'strip';
      if (slidesRow) slidesRow.hidden = sel.value !== 'slides';
    };
    sel.addEventListener('change', apply);
    apply();
  });


  var menuTbl = $('.menu-tbl');
  if (menuTbl) {
    menuTbl.addEventListener('click', function (e) {
      var b = e.target.closest('[data-mv]');
      if (!b) return;
      var tr = b.closest('tr');
      var sib = b.dataset.mv === '-1' ? tr.previousElementSibling : tr.nextElementSibling;
      if (!sib) return;

      var pa = $('input[name$="[position]"]', tr), pb = $('input[name$="[position]"]', sib);
      if (pa && pb) { var tv = pa.value; pa.value = pb.value; pb.value = tv; }
      if (b.dataset.mv === '-1') tr.parentNode.insertBefore(tr, sib); else tr.parentNode.insertBefore(sib, tr);
      markDirty();
    });
    menuTbl.closest('form').addEventListener('submit', function (e) {
      var victims = $$('input[name$="[delete]"]:checked', menuTbl).filter(function (c) { return parseInt(c.dataset.children || '0', 10) > 0; });
      if (victims.length) {
        var n = victims.reduce(function (s, c) { return s + parseInt(c.dataset.children, 10); }, 0);
        if (!window.confirm('У удаляемых пунктов есть подпункты (' + n + '): они поднимутся на верхний уровень. Продолжить?')) {
          e.preventDefault();
        }
      }
    });

    var newRow = $('tr.new-row', menuTbl);
    if (newRow) {
      newRow.scrollIntoView({ block: 'center' });
      var t = $('input[name$="[title]"]', newRow);
      if (t) { t.focus(); t.select(); }
    }
  }


  document.addEventListener('click', function (e) {
    var add = e.target.closest('[data-add-btn]');
    if (add) {
      var rows = $('.btnrows', add.closest('.card')), pfx = rows.dataset.prefix, n = pfx + '[btn][n' + Date.now() + ']';
      var icons; try { icons = JSON.parse(rows.dataset.icons); } catch (err) { icons = {}; }
      var opts = Object.keys(icons).map(function (k) { return '<option value="' + esc(k) + '">' + esc(icons[k]) + '</option>'; }).join('');
      var d = document.createElement('div'); d.className = 'btnrow';
      d.innerHTML = '<input name="' + n + '[label]" placeholder="Текст кнопки"><input name="' + n + '[url]" placeholder="Ссылка: https://… или tel:+7…">' +
        '<select name="' + n + '[icon]">' + opts + '</select><label class="chk"><input type="checkbox" name="' + n + '[newtab]" value="1"> в новой вкладке</label>' +
        '<button type="button" class="ico danger" data-del-btn title="Убрать">✕</button>';
      rows.appendChild(d);
      markDirty();
    }
    var del = e.target.closest('[data-del-btn]');
    if (del) { del.closest('.btnrow').remove(); markDirty(); }
  });


  document.addEventListener('click', function (e) {
    var add = e.target.closest('[data-add-faq]');
    if (add) {
      var rows = $('.faqrows', add.closest('.card')), pfx = rows.dataset.prefix, n = pfx + '[qa][n' + Date.now() + ']';
      var d = document.createElement('div'); d.className = 'faqrow';
      d.innerHTML = '<div class="row"><input name="' + n + '[q]" placeholder="Вопрос" maxlength="300" style="flex:1">' +
        '<button type="button" class="ico" data-faq-mv="-1" title="Выше" aria-label="Переместить выше">↑</button>' +
        '<button type="button" class="ico" data-faq-mv="1" title="Ниже" aria-label="Переместить ниже">↓</button>' +
        '<button type="button" class="ico danger" data-del-faq title="Убрать">✕</button></div>' +
        '<textarea name="' + n + '[a]" rows="2" placeholder="Ответ" maxlength="2000" style="width:100%"></textarea>';
      rows.appendChild(d);
      var inp = $('input', d);
      if (inp) inp.focus();
      markDirty();
    }
    var delFaq = e.target.closest('[data-del-faq]');
    if (delFaq) { delFaq.closest('.faqrow').remove(); markDirty(); }
    var mv = e.target.closest('[data-faq-mv]');
    if (mv) {
      var row = mv.closest('.faqrow');
      var sib = mv.dataset.faqMv === '-1' ? row.previousElementSibling : row.nextElementSibling;
      if (sib && sib.classList.contains('faqrow')) {
        if (mv.dataset.faqMv === '-1') row.parentNode.insertBefore(row, sib); else row.parentNode.insertBefore(sib, row);
        markDirty();
      }
    }
  });


  document.addEventListener('click', function (e) {
    var add = e.target.closest('[data-add-price]');
    if (add) {
      var rows = $('.pricerows', add.closest('.card')), pfx = rows.dataset.prefix, n = pfx + '[price][n' + Date.now() + ']';
      var d = document.createElement('div'); d.className = 'pricerow';
      d.innerHTML = '<div class="row"><input name="' + n + '[name]" placeholder="Название: «Свадебный день»" maxlength="120" style="flex:1">' +
        '<input name="' + n + '[price_from]" placeholder="Цена от" inputmode="numeric" maxlength="9" style="width:110px">' +
        '<input name="' + n + '[price_to]" placeholder="до" inputmode="numeric" maxlength="9" style="width:110px">' +
        '<input name="' + n + '[duration]" placeholder="Длительность: 8 часов" maxlength="60" style="width:170px">' +
        '<button type="button" class="ico" data-price-mv="-1" title="Выше" aria-label="Переместить выше">↑</button>' +
        '<button type="button" class="ico" data-price-mv="1" title="Ниже" aria-label="Переместить ниже">↓</button>' +
        '<button type="button" class="ico danger" data-del-price title="Убрать">✕</button></div>' +
        '<textarea name="' + n + '[desc]" rows="2" placeholder="Что входит: часы, число фото, сроки…" maxlength="600" style="width:100%"></textarea>' +
        '<input name="' + n + '[url]" placeholder="Ссылка карточки (необязательно): /contacts или https://…" maxlength="500" style="width:100%">' +

        '<div class="f"><span class="fl">Фото карточки (необязательно)</span><div class="imgpick">' +
        '<input type="hidden" name="' + n + '[image_id]" value="">' +
        '<div class="imgpick-prev"><span>нет</span></div>' +
        '<div class="imgpick-btns"><button type="button" class="btn" data-pick>Выбрать…</button> <button type="button" class="btn ghost" data-clear>Убрать</button></div>' +
        '</div><small>Кадр 4:5 сверху карточки, кадрирование по фокусной точке.</small></div>';
      rows.appendChild(d);
      var box = $('.imgpick', d);
      if (box) bindImgPick(box);
      var inp = $('input', d);
      if (inp) inp.focus();
      markDirty();
    }
    var delP = e.target.closest('[data-del-price]');
    if (delP) { delP.closest('.pricerow').remove(); markDirty(); }
    var mvP = e.target.closest('[data-price-mv]');
    if (mvP) {
      var prow = mvP.closest('.pricerow');
      var psib = mvP.dataset.priceMv === '-1' ? prow.previousElementSibling : prow.nextElementSibling;
      if (psib && psib.classList.contains('pricerow')) {
        if (mvP.dataset.priceMv === '-1') prow.parentNode.insertBefore(prow, psib); else prow.parentNode.insertBefore(psib, prow);
        markDirty();
      }
    }
  });


  $$('[data-count]').forEach(function (inp) {
    var max = parseInt(inp.dataset.count, 10), c = document.createElement('span'); c.className = 'counter';
    var cmin = parseInt(inp.dataset.countMin || '0', 10);
    inp.parentNode.insertBefore(c, inp);
    function upd() { var n = inp.value.length; c.textContent = cmin ? n + ' / ' + cmin + '–' + max : n + ' / ' + max;
      c.classList.toggle('over', n > max); c.classList.toggle('low', cmin > 0 && n > 0 && n < cmin); }
    inp.addEventListener('input', upd); upd();
  });
  var serpT = $('#serpTitle'), serpD = $('#serpDesc'), defs = $('#serpDefaults');
  if (serpT && defs) {
    var D; try { D = JSON.parse(defs.textContent); } catch (err) { D = {}; }
    var it = $('[name=seo_title]'), id = $('[name=seo_description]');
    function cut(s, n) { return s.length > n ? s.slice(0, n - 1).replace(/[\s,.;:-]+$/, '') + '…' : s; }
    function serp() { serpT.textContent = cut(it.value.trim() || D.title || D.pageTitle, 62); serpD.textContent = cut(id.value.trim() || D.desc || 'Описание не задано — поисковик возьмёт случайный фрагмент страницы.', 165); }
    it.addEventListener('input', serp); id.addEventListener('input', serp); serp();
  }


  var lib = $('#mediaGrid');
  if (lib) {
    var drop = $('#libDrop'), status = $('#uplStatus'), fileInput = document.createElement('input');
    fileInput.type = 'file'; fileInput.multiple = true; fileInput.accept = 'image/*';
    function startUpload(files) {
      uploadFiles(files, null, function (t, fin, failedCount) {
        status.innerHTML = t;
        if (!fin) return;
        if (failedCount > 0) {

          var a = document.createElement('a');
          a.className = 'btn';
          a.style.marginLeft = '8px';
          a.href = location.pathname + location.search;
          a.textContent = 'Обновить список';
          status.appendChild(a);
        } else {
          setTimeout(function () { location.href = location.pathname + location.search; }, 1200);
        }
      });
    }
    $('[data-upload-lib]').addEventListener('click', function () { fileInput.click(); });
    fileInput.addEventListener('change', function () { startUpload(fileInput.files); });
    drop.addEventListener('dragover', function (e) { e.preventDefault(); drop.classList.add('over'); });
    drop.addEventListener('dragleave', function () { drop.classList.remove('over'); });
    drop.addEventListener('drop', function (e) { e.preventDefault(); drop.classList.remove('over'); startUpload(e.dataTransfer.files); });
    lib.addEventListener('change', function (e) {
      var inp = e.target.closest('.mi-alt'); if (!inp) return;
      var mi = inp.closest('.mi');
      var old = $('.mi-err', mi); if (old) old.remove();
      post(A.altUrl, { id: mi.dataset.id, alt: inp.value }).then(function (r) {
        inp.style.outline = r.ok ? '2px solid #2a8a3e' : '2px solid #c0392b';
        if (r.ok) { setTimeout(function () { inp.style.outline = ''; }, 900); return; }

        var d = document.createElement('div'); d.className = 'mi-err';
        d.textContent = 'Alt не сохранён: ' + (r.error || 'ошибка') + ' Исправьте поле ещё раз — отправится повторно.';
        mi.appendChild(d);
      });
    });
    lib.addEventListener('click', function (e) {
      var b = e.target.closest('[data-del]'); if (!b) return;
      var mi = b.closest('.mi'), uses = parseInt(mi.dataset.uses, 10);
      if (!window.confirm(uses ? 'Фото используется на сайте (мест: ' + uses + ': галереи, страницы, заметки). После удаления оно пропадёт отовсюду. Удалить безвозвратно?' : 'Удалить фото безвозвратно?')) return;

      (function del(force) {
        var d = { id: mi.dataset.id };
        if (force) d.force = '1';
        post(A.delUrl, d).then(function (r) {
          if (r.ok) {
            var hadAlt = $('.mi-alt', mi) && $('.mi-alt', mi).value.trim() !== '';
            mi.remove();

            var tot = $('#mediaTotal');
            if (tot) { var n = parseInt(tot.textContent.replace(/\D/g, ''), 10) - 1; tot.textContent = '(' + Math.max(0, n) + ')'; }
            var nal = $('#noaltLink');
            if (nal && !hadAlt && /Без alt:/.test(nal.textContent)) {
              var m = parseInt(nal.textContent.replace(/\D/g, ''), 10) - 1;
              nal.textContent = 'Без alt: ' + Math.max(0, m);
            }
            return;
          }
          if (r.in_use && !force) {
            if (window.confirm(r.error + ' Удалить всё равно?')) del(true);
            return;
          }
          window.alert(r.error || 'Не удалось удалить фото.');
        });
      })(uses > 0);
    });


    var fxModal = null;
    function fxClose() {
      if (fxModal) { fxModal.remove(); fxModal = null; }
      document.removeEventListener('keydown', fxEsc);
    }
    function fxEsc(e) { if (e.key === 'Escape') fxClose(); }
    lib.addEventListener('click', function (e) {
      var b = e.target.closest('[data-focus]'); if (!b) return;
      var mi = b.closest('.mi');
      fxClose();
      var fx = parseFloat(mi.dataset.fx || '0.5'), fy = parseFloat(mi.dataset.fy || '0.5');
      fxModal = document.createElement('div');
      fxModal.className = 'fx-modal';
      fxModal.innerHTML = '<div class="fx-box" role="dialog" aria-label="Точка кадра">' +
        '<div class="fx-title">Точка кадра: кликните по главному объекту (лицо, пара). ' +
        'Обложки и миниатюры будут кадрироваться вокруг неё, а не вокруг центра.</div>' +
        '<div class="fx-stage"><img src="' + esc(mi.dataset.large) + '" alt=""><span class="fx-dot"></span></div>' +
        '<div class="fx-bar"><span class="fx-status muted"></span><span class="grow"></span>' +
        '<button type="button" class="btn sm" data-fx-reset>По центру</button> ' +
        '<button type="button" class="btn sm primary" data-fx-close>Готово</button></div></div>';
      document.body.appendChild(fxModal);
      var stage = $('.fx-stage', fxModal), dot = $('.fx-dot', fxModal), status = $('.fx-status', fxModal);
      var imgEl = $('img', stage);
      function place(nx, ny) {
        fx = Math.max(0, Math.min(1, nx)); fy = Math.max(0, Math.min(1, ny));
        dot.style.left = (fx * 100) + '%'; dot.style.top = (fy * 100) + '%';
      }
      function save() {
        status.textContent = 'Сохраняю…';
        post(A.focusUrl, { id: mi.dataset.id, fx: fx.toFixed(3), fy: fy.toFixed(3) }).then(function (r) {
          if (r.ok) {
            status.textContent = 'Сохранено: ' + Math.round(fx * 100) + '%, ' + Math.round(fy * 100) + '%';
            mi.dataset.fx = fx; mi.dataset.fy = fy;

            var th = $('img', mi);
            var op = (Math.abs(fx - 0.5) < 0.004 && Math.abs(fy - 0.5) < 0.004) ? '' :
              'object-position:' + (Math.round(fx * 1000) / 10) + '% ' + (Math.round(fy * 1000) / 10) + '%';
            if (op) { th.style.cssText += ';' + op; } else { th.style.removeProperty('object-position'); }
          } else {
            status.textContent = 'Не сохранилось: ' + (r.error || 'ошибка');
          }
        });
      }
      place(fx, fy);
      stage.addEventListener('click', function (ev) {
        var r = imgEl.getBoundingClientRect();
        if (r.width < 2 || r.height < 2) return;
        place((ev.clientX - r.left) / r.width, (ev.clientY - r.top) / r.height);
        save();
      });
      $('[data-fx-reset]', fxModal).addEventListener('click', function () { place(0.5, 0.5); save(); });
      $('[data-fx-close]', fxModal).addEventListener('click', fxClose);
      fxModal.addEventListener('click', function (ev) { if (ev.target === fxModal) fxClose(); });
      document.addEventListener('keydown', fxEsc);
    });
  }


  var editForms = $$('form').filter(function (f) { return f.querySelector('.bar.sticky'); });
  var dirty = false, submitting = false;
  editForms.forEach(function (f) {
    f.addEventListener('input', function () { dirty = true; });
    f.addEventListener('change', function () { dirty = true; });
  });
  document.addEventListener('submit', function (e) {
    if (editForms.indexOf(e.target) >= 0 && !e.defaultPrevented) submitting = true;
  });
  window.addEventListener('beforeunload', function (e) {
    if ((dirty || uploadsActive > 0) && !submitting) { e.preventDefault(); e.returnValue = ''; }
  });

  function markDirty() { dirty = true; }
  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && !e.shiftKey && !e.altKey && (e.key === 's' || e.key === 'S' || e.key === 'ы' || e.key === 'Ы') && editForms[0]) {
      e.preventDefault();
      var btn = $('.bar.sticky button.primary', editForms[0]);
      if (btn && typeof editForms[0].requestSubmit === 'function') {
        $$('[data-rte]', editForms[0]).forEach(syncRte);
        editForms[0].requestSubmit(btn);
      } else if (btn) { btn.click(); }
    }
  });


  function normDraft(s) { return String(s == null ? '' : s).replace(/\r\n/g, '\n').trim(); }
  function setupDraft(el, read, write, after) {
    var key = 'draft:' + el.dataset.draftKey;
    var serverUpd = el.dataset.draftUpdated || '';
    var store = {
      get: function () { try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch (e) { return null; } },
      set: function (v) { try { localStorage.setItem(key, JSON.stringify({ v: v, t: Date.now(), u: serverUpd })); } catch (e) {} },
      del: function () { try { localStorage.removeItem(key); } catch (e) {} }
    };
    var saved = store.get();
    if (saved && normDraft(saved.v) === normDraft(read())) { store.del(); saved = null; }
    if (saved && saved.v) {

      var stale = serverUpd && saved.u && String(saved.u) !== String(serverUpd);
      var bar = document.createElement('div'); bar.className = 'flash draft';
      var when = new Date(saved.t).toLocaleString('ru-RU', { day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' });
      bar.innerHTML = 'В браузере найден несохранённый текст от ' + esc(when)
        + (stale ? '. <strong>Внимание:</strong> после него запись уже сохраняли (возможно, с другого устройства) — восстановление заменит более свежую версию.' : '')
        + '. <button type="button" class="btn sm" data-restore>Восстановить</button> <button type="button" class="btn sm ghost" data-drop>Отбросить</button>';
      var wrapEl = $('main.wrap'); if (wrapEl) wrapEl.insertBefore(bar, wrapEl.firstChild);
      bar.addEventListener('click', function (e) {
        if (e.target.closest('[data-restore]')) { write(saved.v); dirty = true; bar.remove(); if (after) after(); }
        else if (e.target.closest('[data-drop]')) { store.del(); bar.remove(); }
      });
    }
    var tmr;
    return function () { clearTimeout(tmr); tmr = setTimeout(function () { store.set(read()); }, 700); };
  }


  $$('textarea[data-draft-key]').forEach(function (ta) {
    var wrapRte = ta.closest('[data-rte]');
    if (wrapRte) {
      var ed = $('.rte', wrapRte);

      var readRte = function () { return ta.hidden ? ed.innerHTML : ta.value; };
      var writeRte = function (v) { ed.innerHTML = v; ta.value = v; };
      var saveRte = setupDraft(ta, readRte, writeRte);
      ed.addEventListener('input', saveRte);
      ta.addEventListener('input', saveRte);
    } else {
      var saveTa = setupDraft(ta, function () { return ta.value; }, function (v) { ta.value = v; });
      ta.addEventListener('input', saveTa);
    }
  });


  if (A.pingUrl) {
    var sessWarned = false;
    setInterval(function () {
      fetch(A.pingUrl, { credentials: 'same-origin' }).then(function (r) {
        if (r.status === 401 && !sessWarned) {
          sessWarned = true;
          var bar = document.createElement('div');
          bar.className = 'flash err';
          bar.setAttribute('role', 'alert');
          bar.innerHTML = '<b>Сессия истекла.</b> <a href="' + A.base + '?p=login" target="_blank" rel="noopener">Войдите в новой вкладке</a>, затем вернитесь сюда и отправьте форму ещё раз — черновики текстов сохранены в браузере.';
          var wrapEl = $('main.wrap'); if (wrapEl) wrapEl.insertBefore(bar, wrapEl.firstChild);
        }
      }).catch(function () {   });
    }, 5 * 60 * 1000);
  }


  var pubBoxes = $$('input[name=is_published]');
  if (pubBoxes.length > 1) {
    pubBoxes.forEach(function (p) {
      p.addEventListener('change', function () {
        pubBoxes.forEach(function (q) { if (q !== p) q.checked = p.checked; });
      });
    });
  }


  var todoBtn = $('[data-todo-toggle]');
  if (todoBtn) {
    var todoLabel = todoBtn.textContent;
    todoBtn.addEventListener('click', function () {
      var more = $$('.todo-more');
      var show = more[0] && more[0].hidden;
      more.forEach(function (li) { li.hidden = !show; });
      todoBtn.textContent = show ? 'Свернуть' : todoLabel;
    });
  }


  $$('.flash.ok').forEach(function (f) {
    var fade = function () { f.classList.add('fade'); setTimeout(function () { f.remove(); }, 600); };
    var t = setTimeout(fade, 8000);
    f.addEventListener('mouseenter', function () { clearTimeout(t); });
    f.addEventListener('mouseleave', function () { t = setTimeout(fade, 3000); });
  });


  var form = $('form[data-scroll]');
  if (form && form.dataset.scroll) { var t = document.getElementById(form.dataset.scroll); if (t) t.scrollIntoView({ block: 'center' }); }


  document.addEventListener('input', function (e) {
    var inp = e.target && e.target.closest ? e.target.closest('[data-filter]') : null;
    if (!inp) return;
    var q = inp.value.trim().toLowerCase();
    $$(inp.dataset.filter).forEach(function (el) {
      var hay = el.dataset.search !== undefined ? el.dataset.search : el.textContent.toLowerCase();
      el.style.display = q === '' || hay.indexOf(q) >= 0 ? '' : 'none';
    });
  });


  $$('input[type=password]').forEach(function (inp) {
    if (inp.dataset.passWired) return;
    inp.dataset.passWired = '1';
    var wrap = document.createElement('span'); wrap.className = 'pass';
    inp.parentNode.insertBefore(wrap, inp);
    wrap.appendChild(inp);
    var b = document.createElement('button');
    b.type = 'button'; b.className = 'btn sm'; b.textContent = 'Показать';
    b.setAttribute('aria-label', 'Показать или скрыть пароль');
    b.addEventListener('click', function () {
      var show = inp.type === 'password';
      inp.type = show ? 'text' : 'password';
      b.textContent = show ? 'Скрыть' : 'Показать';
      inp.focus();
    });
    wrap.appendChild(b);
  });


  var pvFormBtn = $('[data-preview-form]');
  if (pvFormBtn) {
    pvFormBtn.addEventListener('click', function () {
      var f = document.getElementById(pvFormBtn.getAttribute('data-preview-form'));
      if (!f) return;
      var oldAction = f.getAttribute('action'), oldTarget = f.getAttribute('target');
      f.setAttribute('action', pvFormBtn.dataset.preview);
      f.setAttribute('target', '_blank');
      if (f.requestSubmit) { f.requestSubmit(); } else { f.submit(); }

      submitting = false;

      if (oldAction === null) { f.removeAttribute('action'); } else { f.setAttribute('action', oldAction); }
      if (oldTarget === null) { f.removeAttribute('target'); } else { f.setAttribute('target', oldTarget); }
    });
  }


  var mediaGrid = $('#mediaGrid');
  if (mediaGrid) {
    var delSelBtn = $('#delSel');
    var selCount = function () { return $$('.mi-cb:checked', mediaGrid).length; };
    var syncDelSel = function () {
      var n = selCount();
      if (delSelBtn) { delSelBtn.hidden = n === 0; delSelBtn.textContent = 'Удалить выбранные (' + n + ')'; }
      $$('.mi', mediaGrid).forEach(function (mi) { mi.classList.toggle('sel', !!$('.mi-cb:checked', mi)); });
    };
    mediaGrid.addEventListener('change', function (e) { if (e.target.classList && e.target.classList.contains('mi-cb')) syncDelSel(); });
    if (delSelBtn) {
      delSelBtn.addEventListener('click', function () {
        var boxes = $$('.mi-cb:checked', mediaGrid);
        if (!boxes.length) return;
        var used = boxes.filter(function (b) { return parseInt(b.closest('.mi').dataset.uses, 10) > 0; }).length;
        var msg = 'Удалить выбранные фото: ' + boxes.length + '? Это нельзя отменить.';
        if (used) msg += '\n\n' + used + ' из них используются на сайте (галереи, страницы, заметки) — после удаления они пропадут отовсюду.';
        msg += '\nВнешние ссылки на файлы дадут 404.';
        if (!window.confirm(msg)) return;
        delSelBtn.disabled = true;
        var okN = 0, errs = [];

        (function next(i) {
          if (i >= boxes.length) {
            delSelBtn.disabled = false; syncDelSel();
            var st = $('#uplStatus');
            var html = 'Удалено: ' + okN + ' из ' + boxes.length + '.';
            if (errs.length) html += '<br>Ошибки:<br>' + errs.map(function (x) { return '<span class="bad">' + esc(x) + '</span>'; }).join('<br>');
            html += ' <a href="' + location.pathname + location.search + '" class="btn" style="margin-left:8px">Обновить список</a>';
            if (st) st.innerHTML = html; else window.alert(html.replace(/<[^>]+>/g, ' '));
            return;
          }
          var mi = boxes[i].closest('.mi'), id = mi.dataset.id;
          post(A.delUrl, { id: id, force: '1' }).then(function (r) {
            if (r.ok) { okN++; mi.remove(); }
            else { errs.push('фото #' + id + ' — ' + (r.error || 'ошибка')); boxes[i].checked = false; }
          }).catch(function () { errs.push('фото #' + id + ' — сбой сети'); boxes[i].checked = false; })
            .then(function () { next(i + 1); });
        })(0);
      });
    }
  }


  var warmBtn = $('[data-warm-thumbs]');
  if (warmBtn) {
    warmBtn.addEventListener('click', function () {
      var st = $('[data-warm-status]');
      warmBtn.disabled = true;
      var errs = 0;
      (function step(offset) {
        if (st) st.textContent = ' Прогрев: ' + offset + '…';
        post(A.warmUrl, { offset: offset }).then(function (r) {
          if (!r.ok) {
            warmBtn.disabled = false;
            if (st) st.textContent = ' ' + (r.error || 'Ошибка сервера.');
            return;
          }
          errs += r.errors || 0;
          if (r.done) {
            warmBtn.disabled = false;
            if (st) st.textContent = ' Готово: ' + r.processed + ' из ' + r.total + (errs ? ' (с ошибками: ' + errs + ' — см. журнал)' : '') + '.';
            return;
          }

          if (!(r.processed > offset)) {
            warmBtn.disabled = false;
            if (st) st.textContent = ' Остановлено: сервер вернул ответ без сдвига (' + r.processed + ').';
            return;
          }
          step(r.processed);
        });
      })(0);
    });
  }

  $$('[data-auto-submit]').forEach(function (field) {
    field.addEventListener('change', function () { field.form.submit(); });
  });
})();
