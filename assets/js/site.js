/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */

(function () {
  'use strict';
  var doc = document, body = doc.body;
  var attribution = {};
  try { attribution = JSON.parse(localStorage.getItem('photocms_attribution') || '{}') || {}; } catch (ignore) {}
  var query = new URLSearchParams(location.search), marketing = {}, hasMarketing = false;
  query.forEach(function (value, key) {
    var name = key.toLowerCase();
    if (name.indexOf('utm_') === 0 || ['yclid', 'ysclid', 'gclid', 'gbraid', 'wbraid', 'fbclid', 'mc_cid', 'mc_eid', 'igshid', 'from'].indexOf(name) >= 0) {
      marketing[name] = value.slice(0, 300);
      hasMarketing = true;
    }
  });
  var externalReferrer = '';
  if (doc.referrer) {
    try { if (new URL(doc.referrer).origin !== location.origin) externalReferrer = doc.referrer.slice(0, 1000); } catch (ignore) {}
  }
  if (hasMarketing || externalReferrer) {
    attribution.params = Object.assign({}, attribution.params || {}, marketing);
    attribution.landing = location.pathname + location.search;
    attribution.referrer = externalReferrer || attribution.referrer || '';
    attribution.at = new Date().toISOString();
    try { localStorage.setItem('photocms_attribution', JSON.stringify(attribution)); } catch (ignore) {}
  }
  doc.querySelectorAll('input[data-attribution]').forEach(function (input) {
    input.value = JSON.stringify(attribution);
  });


  var burger = doc.querySelector('[data-burger]');

  var mainCol = doc.querySelector('.main');
  var toTop = doc.querySelector('.to-top');
  var adminLink = doc.querySelector('.admin-link');
  function navInert(on) {
    [mainCol, toTop, adminLink].forEach(function (el) {
      if (!el) return;
      if (on) el.setAttribute('inert', ''); else el.removeAttribute('inert');
    });
  }
  function navClose() {
    body.classList.remove('nav-open');
    if (burger) burger.setAttribute('aria-expanded', 'false');
    navInert(false);
  }
  if (burger) {
    burger.addEventListener('click', function () {
      var open = body.classList.toggle('nav-open');
      burger.setAttribute('aria-expanded', open ? 'true' : 'false');
      navInert(open);
    });
    doc.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && body.classList.contains('nav-open')) { navClose(); burger.focus(); }
    });

    doc.addEventListener('click', function (e) {
      if (!body.classList.contains('nav-open')) return;
      if (e.target.closest('.nav a')) { navClose(); return; }
      if (!e.target.closest('.sidebar')) navClose();
    });
    window.addEventListener('resize', function () { if (window.innerWidth > 900) navClose(); });
  }


  function relayout(m) {
    var want = window.innerWidth <= 700 ? parseInt(m.dataset.colsM, 10) : parseInt(m.dataset.cols, 10);

    if (!(want >= 1)) return;
    if (m.children.length === want) return;
    var items = Array.prototype.slice.call(m.querySelectorAll('.ph')).sort(function (a, b) {
      return parseInt(a.dataset.i, 10) - parseInt(b.dataset.i, 10);
    });
    var cols = [], heights = [], i;
    m.innerHTML = '';
    for (i = 0; i < want; i++) {
      var c = doc.createElement('div'); c.className = 'mcol'; m.appendChild(c); cols.push(c); heights.push(0);
    }
    items.forEach(function (it) {

      var m = /(\d+)\s*\/\s*(\d+)/.exec(it.style.getPropertyValue('--ar'));
      var ar = m && +m[1] ? +m[2] / +m[1] : 1;
      var k = heights.indexOf(Math.min.apply(null, heights));
      cols[k].appendChild(it); heights[k] += ar;
    });
  }
  var masonries = doc.querySelectorAll('.masonry');
  function relayoutAll() { masonries.forEach(relayout); }
  relayoutAll();
  masonries.forEach(function (m) { m.classList.add('ready'); });
  var rt; window.addEventListener('resize', function () {
    clearTimeout(rt);
    rt = setTimeout(function () {

      masonries.forEach(function (m) { m.classList.add('no-fade'); });
      relayoutAll();
    }, 150);
  });


  var lb, lbImg, lbCount, lbCap, lbSpin, lbDl, group = [], cur = 0;
  var pushed = false, ignorePop = false;

  var DL = body.hasAttribute('data-lb-dl');

  if (body.hasAttribute('data-no-save')) {
    var isPhoto = function (t) { return t.closest && t.closest('.ph,.lb,.slides-item'); };
    doc.addEventListener('contextmenu', function (e) { if (isPhoto(e.target)) e.preventDefault(); });
    doc.addEventListener('dragstart', function (e) { if (isPhoto(e.target)) e.preventDefault(); });
  }
  function build() {
    lb = doc.createElement('div'); lb.className = 'lb'; lb.setAttribute('role', 'dialog'); lb.setAttribute('aria-modal', 'true');
    lb.setAttribute('aria-label', 'Просмотр фотографии');
    lb.innerHTML = '<img alt=""><button class="lb-close" aria-label="Закрыть">' + svg('M18 6 6 18M6 6l12 12') + '</button>' +
      '<button class="lb-prev" aria-label="Назад">' + svg('m15 18-6-6 6-6') + '</button>' +
      '<button class="lb-next" aria-label="Вперёд">' + svg('m9 18 6-6-6-6') + '</button>' +
      (DL ? '<a class="lb-dl" href="#" download aria-label="Скачать фото" title="Скачать фото">' + svg('M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3') + '</a>' : '') +

      '<div class="lb-count" aria-live="polite"></div><div class="lb-cap"></div><div class="lb-spin" hidden></div>';
    body.appendChild(lb);
    lbImg = lb.querySelector('img'); lbCount = lb.querySelector('.lb-count'); lbCap = lb.querySelector('.lb-cap');
    lbSpin = lb.querySelector('.lb-spin');
    lbDl = lb.querySelector('.lb-dl');
    if (lbDl) lbDl.addEventListener('click', function (e) { e.stopPropagation(); });
    lb.addEventListener('click', function (e) { if (e.target === lb || e.target.closest('.lb-close')) close(); });
    lb.querySelector('.lb-prev').addEventListener('click', function (e) { e.stopPropagation(); go(-1); });
    lb.querySelector('.lb-next').addEventListener('click', function (e) { e.stopPropagation(); go(1); });

    var tx = 0, ty = 0, tt = 0, multi = false;
    lb.addEventListener('touchstart', function (e) {
      multi = e.touches.length > 1;
      if (multi) { tx = 0; return; }
      tx = e.changedTouches[0].clientX; ty = e.changedTouches[0].clientY; tt = Date.now();
    }, { passive: true });
    lb.addEventListener('touchmove', function (e) {
      if (e.touches.length > 1) { multi = true; tx = 0; }
    }, { passive: true });
    lb.addEventListener('touchend', function (e) {
      if (multi || !tx) { tx = 0; multi = false; return; }
      var dx = e.changedTouches[0].clientX - tx, dy = e.changedTouches[0].clientY - ty;
      var adx = Math.abs(dx), ady = Math.abs(dy), dt = Date.now() - tt;
      tx = 0;

      if (adx > 50 && adx > 2 * ady) { go(dx < 0 ? 1 : -1); return; }

      if (dy > 90 && ady > 1.5 * adx) { close(); return; }

      if (adx < 10 && ady < 10 && dt < 300) {
        var t = e.target;
        if (t && t.closest && t.closest('button, a')) return;
        lb.classList.toggle('lb-ui-off');
      }
    }, { passive: true });
  }
  function svg(d) { return '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="' + d + '"/></svg>'; }

  function dlName(a, n) {
    var m = /\.([a-z0-9]{2,5})$/i.exec((a.getAttribute('href') || '').split('?')[0]);
    var ext = m ? '.' + m[1].toLowerCase() : '.jpg';
    var base = (a.dataset.alt || '').trim().toLowerCase()
      .replace(/\s+/g, '-').replace(/[^\p{L}\p{N}-]+/gu, '').replace(/^-+|-+$/g, '').slice(0, 60);
    return (base || 'photo-' + (n + 1)) + ext;
  }
  function preload(i) {
    var n = group[(i + group.length) % group.length];
    if (!n) return;
    var p = new Image();
    if (n.dataset.srcset) { p.srcset = n.dataset.srcset; p.sizes = '100vw'; }
    p.src = n.getAttribute('href');
  }

  var pfQueue = [], pfTimer = null, pfGen = 0;
  var pfConn = navigator.connection || {};
  var pfCheap = !pfConn.saveData && ['slow-2g', '2g'].indexOf(pfConn.effectiveType || '') === -1;
  function prefetchStart() {
    prefetchStop();
    if (!pfCheap || group.length < 3) return;
    var i;
    for (i = 1; i < group.length && pfQueue.length < 40; i++) pfQueue.push(cur + i);
    for (i = 1; i < group.length && pfQueue.length < 50; i++) pfQueue.push(cur - i);
    prefetchNext(pfGen);
  }
  function prefetchNext(gen) {
    if (gen !== pfGen || !pfQueue.length || !lb || !lb.classList.contains('on')) return;
    var n = group[((pfQueue.shift() % group.length) + group.length) % group.length];
    if (!n) { prefetchNext(gen); return; }
    var p = new Image();

    p.onload = p.onerror = function () {
      if (gen === pfGen) pfTimer = setTimeout(function () { prefetchNext(gen); }, 120);
    };
    if (n.dataset.srcset) { p.srcset = n.dataset.srcset; p.sizes = '100vw'; }
    p.src = n.getAttribute('href');
  }
  function prefetchStop() { pfGen++; pfQueue = []; clearTimeout(pfTimer); }
  var lbShow = 0;
  function show(i) {
    var showId = ++lbShow;
    cur = (i + group.length) % group.length;
    var a = group[cur];
    lbSpin.hidden = false;
    lbImg.onload = null; lbImg.onerror = null;

    var thumb = a.querySelector('img');
    var low = thumb ? (thumb.currentSrc || thumb.src) : '';
    var done = false;
    var bigOk = function () {
      if (done || showId !== lbShow) return;
      done = true;
      lbImg.classList.remove('lb-low');
      lbImg.srcset = a.dataset.srcset || ''; lbImg.sizes = '100vw';
      lbImg.src = a.getAttribute('href');
      lbImg.style.opacity = 1; lbSpin.hidden = true;
    };
    if (low && low !== a.getAttribute('href')) {
      lbImg.classList.add('lb-low');
      lbImg.removeAttribute('srcset'); lbImg.removeAttribute('sizes');
      lbImg.style.opacity = 1;
      lbImg.src = low;
    } else {
      lbImg.classList.remove('lb-low');
      lbImg.style.opacity = 0;
    }
    var big = new Image();
    big.onload = bigOk;
    big.onerror = function () {
      if (done || showId !== lbShow) return;
      done = true;
      lbImg.style.opacity = 1; lbSpin.hidden = true;
      if (!low && lbCap) lbCap.textContent = 'Не удалось загрузить фото';

    };
    if (a.dataset.srcset) { big.srcset = a.dataset.srcset; big.sizes = '100vw'; }
    big.src = a.getAttribute('href');
    if (big.complete && big.naturalWidth) bigOk();
    lbImg.alt = a.dataset.alt || '';

    if (lbCap) lbCap.textContent = a.dataset.caption || '';
    lbCount.textContent = (cur + 1) + ' / ' + group.length;
    if (lbDl) { lbDl.setAttribute('href', a.getAttribute('href')); lbDl.setAttribute('download', dlName(a, cur)); }
    preload(cur + 1); preload(cur - 1);
    prefetchStart();
  }
  var opener = null;

  function setPageInert(value) {
    var pageShell = doc.querySelector('.layout');
    if (!pageShell) return;
    if (value) pageShell.setAttribute('inert', ''); else pageShell.removeAttribute('inert');
  }
  function open(a) {
    if (!lb) build();
    opener = a;
    var root = a.closest('.post-body') || a.closest('.js-gallery') || doc;
    group = Array.prototype.slice.call(root.querySelectorAll('a.js-lb')).sort(function (x, y) {
      var xg = x.closest('.js-gallery') || x, yg = y.closest('.js-gallery') || y;
      if (xg !== yg) return (xg.compareDocumentPosition(yg) & Node.DOCUMENT_POSITION_FOLLOWING) ? -1 : 1;
      return parseInt(x.dataset.i, 10) - parseInt(y.dataset.i, 10);
    });
    lb.classList.remove('lb-ui-off');
    lb.style.display = 'flex'; body.classList.add('lb-open');
    requestAnimationFrame(function () { lb.classList.add('on'); });
    show(group.indexOf(a));
    setPageInert(true);
    lb.querySelector('.lb-close').focus();
    var single = group.length < 2;
    lb.querySelector('.lb-prev').hidden = single; lb.querySelector('.lb-next').hidden = single; lbCount.hidden = single;

    if (window.history && history.pushState) { history.pushState({ lb: 1 }, ''); pushed = true; }
  }
  function close(fromPop) {
    if (!lb || !lb.classList.contains('on')) return;
    lbShow++;
    prefetchStop();
    lb.classList.remove('on'); body.classList.remove('lb-open');
    setPageInert(false);
    setTimeout(function () { if (!lb.classList.contains('on')) { lb.style.display = 'none'; lbImg.removeAttribute('src'); lbImg.removeAttribute('srcset'); lbImg.classList.remove('lb-low'); } }, 180);
    if (opener) { try { opener.focus({ preventScroll: true }); } catch (e) {} opener = null; }
    if (pushed && !fromPop) { ignorePop = true; history.back(); }
    pushed = false;
  }
  if (window.history && history.pushState) {
    window.addEventListener('popstate', function () {
      if (lb && lb.classList.contains('on')) { pushed = false; close(true); }
      else if (ignorePop) ignorePop = false;
    });
  }
  function go(d) { show(cur + d); }
  doc.addEventListener('click', function (e) {
    var a = e.target.closest('a.js-lb');
    if (a && !e.metaKey && !e.ctrlKey && !e.shiftKey) { e.preventDefault(); open(a); }
  });
  doc.addEventListener('keydown', function (e) {
    if (!lb || !lb.classList.contains('on')) return;
    if (e.key === 'Escape') close(); else if (e.key === 'ArrowRight') go(1); else if (e.key === 'ArrowLeft') go(-1);
    else if (e.key === 'Tab') {
      var btns = Array.prototype.slice.call(lb.querySelectorAll('button:not([hidden]),a.lb-dl'));
      if (!btns.length) return;
      var i = btns.indexOf(doc.activeElement);
      if (e.shiftKey && i <= 0) { e.preventDefault(); btns[btns.length - 1].focus(); }
      else if (!e.shiftKey && i === btns.length - 1) { e.preventDefault(); btns[0].focus(); }
    }
  });


  doc.querySelectorAll('[data-strip]').forEach(function (wrap) {
    var track = wrap.querySelector('[data-strip-track]');
    var prev = wrap.querySelector('[data-strip-prev]');
    var next = wrap.querySelector('[data-strip-next]');
    if (!track) return;
    var reduced = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
    function step() {
      var slide = track.querySelector('.ph');
      var gap = parseFloat(getComputedStyle(track).gap) || 0;
      return slide ? slide.getBoundingClientRect().width + gap : track.clientWidth * 0.8;
    }
    function goStep(dir) { track.scrollBy({ left: dir * step(), behavior: reduced ? 'auto' : 'smooth' }); }
    if (prev) prev.addEventListener('click', function () { goStep(-1); });
    if (next) next.addEventListener('click', function () { goStep(1); });

    function sync() {
      if (!prev || !next) return;
      var max = track.scrollWidth - track.clientWidth - 2;
      prev.disabled = track.scrollLeft <= 2;
      next.disabled = track.scrollLeft >= max;
    }
    track.addEventListener('scroll', sync, { passive: true });
    window.addEventListener('resize', sync);
    sync();

    var downX = 0, downLeft = 0, dragDist = 0, dragging = false;
    track.addEventListener('pointerdown', function (e) {
      if (e.pointerType !== 'mouse' || e.button !== 0) return;
      dragging = true; dragDist = 0; downX = e.clientX; downLeft = track.scrollLeft;
      track.classList.add('is-drag');
      e.preventDefault();
    });
    window.addEventListener('pointermove', function (e) {
      if (!dragging) return;
      var dx = e.clientX - downX;
      dragDist = Math.max(dragDist, Math.abs(dx));
      track.scrollLeft = downLeft - dx;
    });
    window.addEventListener('pointerup', function () {
      if (!dragging) return;
      dragging = false;
      track.classList.remove('is-drag');
    });
    track.addEventListener('click', function (e) {
      if (dragDist > 6) { e.preventDefault(); e.stopPropagation(); dragDist = 0; }
    }, true);


    var apDir = wrap.getAttribute('data-autoplay');
    var apToggle = wrap.querySelector('[data-strip-toggle]');
    if (apToggle && reduced) apToggle.hidden = true;
    if (apDir && !reduced) {
      var apMs = Math.max(3, parseInt(wrap.getAttribute('data-autoplay-speed'), 10) || 5) * 1000;
      var apTimer = null, apResume = null, apInView = false, apHold = false;
      var apPaused = false, apHover = false, apFocus = false;
      var apBack = apDir === 'back';
      function apMax() { return track.scrollWidth - track.clientWidth - 2; }
      function apFocusHold() { return apFocus && doc.activeElement !== apToggle; }
      function apTick() {
        if (apPaused || apHover || apFocusHold() || apHold || !apInView || document.hidden || apMax() <= 2) return;
        if (!apBack && track.scrollLeft >= apMax()) { track.scrollTo({ left: 0, behavior: 'auto' }); return; }
        if (apBack && track.scrollLeft <= 2) { track.scrollTo({ left: track.scrollWidth, behavior: 'auto' }); return; }
        goStep(apBack ? -1 : 1);
      }

      function hold() {
        apHold = true;
        clearTimeout(apResume);
        apResume = setTimeout(function () { apHold = false; }, 4000);
      }
      function apSyncToggle() {
        if (!apToggle) return;
        apToggle.setAttribute('aria-label', apPaused ? 'Возобновить автопрокрутку' : 'Приостановить автопрокрутку');
        apToggle.innerHTML = svg(apPaused ? 'm9 18 6-6-6-6' : 'M9 6v12M15 6v12');
      }
      if (apToggle) {
        apToggle.addEventListener('click', function () {
          apPaused = !apPaused;
          apHold = false;
          clearTimeout(apResume);
          apSyncToggle();
        });
      }
      wrap.addEventListener('pointerdown', hold);
      wrap.addEventListener('wheel', hold, { passive: true });
      wrap.addEventListener('mouseenter', function () { apHover = true; });
      wrap.addEventListener('mouseleave', function () { apHover = false; });
      wrap.addEventListener('focusin', function () { apFocus = true; });
      wrap.addEventListener('focusout', function (e) {
        if (!wrap.contains(e.relatedTarget)) apFocus = false;
      });
      if (window.IntersectionObserver) {
        new IntersectionObserver(function (entries) {
          apInView = entries[0].isIntersecting;
        }, { threshold: 0.25 }).observe(wrap);
      } else {
        apInView = true;
      }
      apTimer = setInterval(apTick, apMs);
    }
  });


  doc.querySelectorAll('[data-slides]').forEach(function (box) {
    var items = [].slice.call(box.querySelectorAll('.slides-item'));
    if (items.length < 2) return;
    var sPrev = box.querySelector('[data-slides-prev]');
    var sNext = box.querySelector('[data-slides-next]');
    var reduced = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
    var ms = Math.max(3, parseInt(box.getAttribute('data-speed'), 10) || 5) * 1000;
    var cur = 0, timer = null, inView = true;
    function show(n) {
      n = (n + items.length) % items.length;
      if (n === cur) return;
      var old = items[cur], now = items[n];

      old.classList.add('was');
      now.classList.add('on');
      setTimeout(function () {

        if (items[cur] === old) { old.classList.remove('was'); return; }
        old.classList.remove('on', 'was');
      }, 900);
      cur = n;
    }
    function restart() {
      clearInterval(timer);
      timer = setInterval(function () { if (inView && !document.hidden) show(cur + 1); }, ms);
    }
    if (sPrev) sPrev.addEventListener('click', function () { show(cur - 1); restart(); });
    if (sNext) sNext.addEventListener('click', function () { show(cur + 1); restart(); });
    if (reduced) return;
    if (window.IntersectionObserver) {
      inView = false;
      new IntersectionObserver(function (entries) {
        inView = entries[0].isIntersecting;
      }, { threshold: 0.25 }).observe(box);
    }

    box.addEventListener('mouseenter', function () { clearInterval(timer); });
    box.addEventListener('mouseleave', restart);
    box.addEventListener('focusin', function () { clearInterval(timer); });
    box.addEventListener('focusout', restart);
    restart();
  });


  var lbRoots = new Set();
  doc.querySelectorAll('a.js-lb').forEach(function (a) {
    lbRoots.add(a.closest('.post-body') || a.closest('.js-gallery'));
  });
  lbRoots.forEach(function (r) {
    if (!r) return;
    var l = [].slice.call(r.querySelectorAll('a.js-lb')).sort(function (x, y) {
      var xg = x.closest('.js-gallery') || x, yg = y.closest('.js-gallery') || y;
      if (xg !== yg) return (xg.compareDocumentPosition(yg) & Node.DOCUMENT_POSITION_FOLLOWING) ? -1 : 1;
      return parseInt(x.dataset.i, 10) - parseInt(y.dataset.i, 10);
    });
    if (l.length < 2) return;
    l.forEach(function (a, k) {
      a.setAttribute('aria-label', (a.dataset.alt || 'Фото') + ', ' + (k + 1) + ' из ' + l.length);
    });
  });


  var top = doc.querySelector('[data-top]');
  if (top) {
    var topSync = function () { top.hidden = window.scrollY < 800; };
    window.addEventListener('scroll', topSync, { passive: true });
    topSync();
    window.addEventListener('pageshow', topSync);

    top.addEventListener('click', function () {
      window.scrollTo({ top: 0 });
      var m = doc.getElementById('main');
      if (m) m.focus({ preventScroll: true });
    });
  }

  doc.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-copy]');
    if (!btn) return;
    var text = btn.getAttribute('data-copy');

    var note = function (msg) {
      var old = btn.textContent;
      btn.textContent = msg;
      btn.classList.add('copied');
      setTimeout(function () { btn.textContent = old; btn.classList.remove('copied'); }, 1600);
    };
    var done = function () { note('Скопировано'); };
    var fail = function () { note('Не скопировано'); };

    var legacy = function () {
      var inp = doc.createElement('input');
      inp.value = text;
      doc.body.appendChild(inp);
      inp.select();
      var okCmd = false;
      try { okCmd = doc.execCommand('copy'); } catch (err) {}
      inp.remove();
      return okCmd;
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {

      navigator.clipboard.writeText(text).then(done, function () { if (legacy()) done(); else fail(); });
    } else {
      if (legacy()) done(); else fail();
    }
  });
})();
