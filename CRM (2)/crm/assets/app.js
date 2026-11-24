(function () {
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const csrf = () => ($('meta[name=csrf]') || {}).content || ($('input[name=csrf]') || {}).value || '';

  const toast = (msg, err) => {
    const t = $('#toast');
    if (!t) return;
    t.textContent = msg;
    t.className = 'show' + (err ? ' err' : '');
    clearTimeout(t._h);
    t._h = setTimeout(() => (t.className = ''), 1800);
  };

  async function copyText(text) {
    try {
      if (navigator.clipboard && window.isSecureContext) { await navigator.clipboard.writeText(text); return true; }
    } catch (e) {}
    const ta = document.createElement('textarea');
    ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    let ok = false;
    try { ok = document.execCommand('copy'); } catch (e) {}
    ta.remove();
    return ok;
  }

  const closeAll = (except) => {
    $$('.spick.open, .dd.open').forEach((el) => { if (el !== except) el.classList.remove('open'); });
  };

  document.addEventListener('click', async (e) => {
    // Копирование
    const cp = e.target.closest('[data-copy]');
    if (cp) {
      e.preventDefault();
      if (await copyText(cp.dataset.copy)) {
        cp.classList.add('done');
        toast('Скопировано: ' + cp.dataset.copy);
        setTimeout(() => cp.classList.remove('done'), 1200);
      }
      return;
    }
    // Подтверждение
    const c = e.target.closest('[data-confirm]');
    if (c && !confirm(c.dataset.confirm)) { e.preventDefault(); return; }

    // Выпадающее меню (Мессенджер / Ещё)
    const ddBtn = e.target.closest('.dd-btn');
    if (ddBtn) {
      const dd = ddBtn.parentElement;
      closeAll(dd);
      dd.classList.toggle('open');
      return;
    }

    // Выбор статуса: открыть
    const sBtn = e.target.closest('.spick-btn');
    if (sBtn) {
      const sp = sBtn.parentElement;
      closeAll(sp);
      const rect = sBtn.getBoundingClientRect();
      const menu = $('.spick-menu', sp);
      const h = Math.min(330, menu.scrollHeight || 330);
      const up = window.innerHeight - rect.bottom < h + 10 && rect.top > h + 10;
      menu.style.left = rect.left + 'px';
      menu.style.width = Math.max(rect.width, 200) + 'px';
      menu.style.top = up ? 'auto' : rect.bottom + 4 + 'px';
      menu.style.bottom = up ? window.innerHeight - rect.top + 4 + 'px' : 'auto';
      sp.classList.toggle('open');
      return;
    }
    // Выбор статуса: опция
    const opt = e.target.closest('.spick-opt');
    if (opt) {
      const sp = opt.closest('.spick');
      const id = opt.dataset.id;
      const prevHtml = $('.spick-btn', sp).innerHTML;
      $('input[type=hidden]', sp).value = id;
      $('.spick-btn', sp).innerHTML = opt.innerHTML + '<i class="caret"></i>';
      $$('.spick-opt', sp).forEach((o) => o.classList.toggle('on', o === opt));
      sp.classList.remove('open');
      if (sp.dataset.client) {
        sp.classList.add('saving');
        try {
          const body = new URLSearchParams({ csrf: csrf(), client_id: sp.dataset.client, status_id: id });
          const r = await fetch('api_status.php', { method: 'POST', body });
          const j = await r.json();
          if (!j.ok) throw 0;
          toast('Статус: ' + j.name);
        } catch (err) {
          $('.spick-btn', sp).innerHTML = prevHtml;
          toast('Не удалось сменить статус', true);
        }
        sp.classList.remove('saving');
      }
      return;
    }
    if (!e.target.closest('.spick, .dd')) closeAll();
  });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeAll(); });
  window.addEventListener('scroll', (e) => {
    if (e.target.closest && e.target.closest('.spick-menu')) return; // прокрутка самого списка
    $$('.spick.open').forEach((s) => s.classList.remove('open'));
  }, true);
  window.addEventListener('resize', () => closeAll());

  // Вкладки
  const tabs = $$('.tabs a');
  const showTab = (hash) => {
    const link = tabs.find((a) => a.getAttribute('href') === hash) || tabs[0];
    if (!link) return;
    tabs.forEach((a) => a.classList.toggle('on', a === link));
    $$('.tab-pane').forEach((p) => p.classList.toggle('on', '#' + p.id === link.getAttribute('href')));
  };
  tabs.forEach((a) => a.addEventListener('click', (e) => {
    e.preventDefault();
    history.replaceState(null, '', a.getAttribute('href'));
    showTab(a.getAttribute('href'));
  }));
  if (tabs.length) showTab(location.hash);

  // Массовый выбор
  const all = $('#chkAll');
  const bar = $('.bulkbar');
  const upd = () => {
    const n = $$('.chk:checked').length;
    if (bar) { bar.hidden = n === 0; $('#selCount').textContent = n; }
  };
  if (all) all.addEventListener('change', () => { $$('.chk').forEach((c) => (c.checked = all.checked)); upd(); });
  $$('.chk').forEach((c) => c.addEventListener('change', upd));

  // ГЕО по номеру при вводе
  const ph = $('#phoneInput');
  if (ph) {
    let h;
    ph.addEventListener('input', () => {
      clearTimeout(h);
      h = setTimeout(async () => {
        const f = $('#geoFlag'), n = $('#geoName');
        if (ph.value.replace(/\D/g, '').length < 7) { if (f) f.textContent = '🌐'; if (n) n.textContent = ''; return; }
        try {
          const j = await (await fetch('api_geo.php?phone=' + encodeURIComponent(ph.value))).json();
          if (f) f.textContent = j.flag;
          if (n) n.textContent = j.name;
        } catch (e) {}
      }, 250);
    });
  }

  // Комментарии: прокрутка вниз, Enter — отправить, выбор для удаления
  const list = $('#cmList');
  if (list) list.scrollTop = list.scrollHeight;
  $$('.cm-form textarea').forEach((t) => {
    t.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); if (t.value.trim()) t.form.submit(); }
    });
    t.addEventListener('input', () => { t.style.height = 'auto'; t.style.height = Math.min(t.scrollHeight + 2, 180) + 'px'; });
  });
  const delBtn = $('#cmDelBtn');
  $$('.cm-chk').forEach((c) => c.addEventListener('change', () => {
    if (delBtn) delBtn.hidden = !$$('.cm-chk:checked').length;
  }));

  // Генератор пароля
  $$('.gen-pw').forEach((b) => b.addEventListener('click', () => {
    const chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    const arr = new Uint32Array(10);
    crypto.getRandomValues(arr);
    const inp = b.parentElement.querySelector('input');
    inp.value = Array.from(arr, (x) => chars[x % chars.length]).join('');
    inp.select();
  }));

  // Поле «Тимлидер» только для роли «Менеджер»
  $$('form').forEach((f) => {
    const rs = $('.role-sel', f), tl = $('.tl-field', f);
    if (!rs || !tl) return;
    const sync = () => (tl.style.display = rs.value === 'manager' ? '' : 'none');
    rs.addEventListener('change', sync);
    sync();
  });
  // ---------- Перезвон: быстрые кнопки ----------
  const cbInput = $('#cbInput');
  const pad = (n) => String(n).padStart(2, '0');
  const toLocal = (d) => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  $$('[data-cb]').forEach((b) => b.addEventListener('click', () => {
    if (!cbInput) return;
    const v = b.dataset.cb;
    if (v === 'clear') { cbInput.value = ''; return; }
    const d = new Date();
    if (v === 'tomorrow') { d.setDate(d.getDate() + 1); d.setHours(10, 0, 0, 0); }
    else { d.setMinutes(d.getMinutes() + parseInt(v, 10)); d.setSeconds(0); }
    cbInput.value = toLocal(d);
  }));

  // ---------- Колокольчик ----------
  const bell = $('#bell');
  if (bell) {
    const cnt = $('#bellCnt'), menu = $('#bellMenu');
    const esc = (t) => String(t).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    let seen;
    try { seen = new Set(JSON.parse(localStorage.getItem('cb_seen') || '[]')); } catch (e) { seen = new Set(); }
    const saveSeen = () => { try { localStorage.setItem('cb_seen', JSON.stringify([...seen].slice(-300))); } catch (e) {} };

    const render = (d) => {
      let h = '';
      if (d.callbacks.length) {
        h += '<div class="bell-h">⏰ Пора перезвонить <b>' + d.due + '</b></div>';
        d.callbacks.forEach((c) => { h += '<a class="bell-it due" href="client.php?id=' + c.id + '"><b>' + esc(c.name) + '</b><small>' + esc(c.phone) + ' · ' + c.at + '</small></a>'; });
        if (d.due > d.callbacks.length) h += '<a class="bell-more" href="callbacks.php">Все перезвоны →</a>';
      }
      h += '<div class="bell-h">Уведомления</div>';
      if (!d.notes.length) h += '<div class="bell-empty muted">Пока пусто</div>';
      d.notes.forEach((n) => {
        h += '<a class="bell-it ' + (n.unread ? 'unread' : '') + '" href="' + (n.client ? 'client.php?id=' + n.client : 'index.php') + '"><span>' + esc(n.body) + '</span><small>' + n.at + '</small></a>';
      });
      menu.innerHTML = h;
    };

    const poll = async (alertNew) => {
      try {
        const d = await (await fetch('api_notify.php', { cache: 'no-store' })).json();
        const total = d.due + d.unread;
        cnt.hidden = !total; cnt.textContent = total;
        const side = $('#sideDue'); if (side) { side.hidden = !d.due; side.textContent = d.due; }
        if (alertNew) {
          d.callbacks.forEach((c) => {
            const key = c.id + '@' + c.at;
            if (seen.has(key)) return;
            seen.add(key);
            toast('⏰ Перезвонить: ' + c.name + ' ' + c.phone);
            if ('Notification' in window && Notification.permission === 'granted') {
              const n = new Notification('Пора перезвонить', { body: c.name + ' · ' + c.phone });
              n.onclick = () => { window.focus(); location.href = 'client.php?id=' + c.id; };
            }
          });
          saveSeen();
        }
        return d;
      } catch (e) { return null; }
    };

    $('.bell-btn', bell).addEventListener('click', async () => {
      const open = !bell.classList.contains('open');
      closeAll(bell);
      bell.classList.toggle('open', open);
      if (!open) return;
      if ('Notification' in window && Notification.permission === 'default') Notification.requestPermission();
      const d = await poll(false);
      if (d) {
        render(d);
        if (d.unread) {
          fetch('api_notify.php', { method: 'POST', body: new URLSearchParams({ csrf: csrf() }) });
          cnt.textContent = d.due; cnt.hidden = !d.due;
        }
      }
    });
    poll(true);
    setInterval(() => poll(true), 60000);
  }
})();
