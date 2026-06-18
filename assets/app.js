// phpMongoAdmin — minimal client behaviour
(function () {
  var meta = document.querySelector('meta[name="csrf"]');
  var CSRF = meta ? meta.content : '';

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  // Confirm before destructive POSTs.
  document.addEventListener('submit', function (e) {
    var form = e.target.closest('form.confirm');
    if (form && !window.confirm(form.dataset.confirm || 'Are you sure?')) {
      e.preventDefault();
    }
  });

  // Allow Tab to indent inside code textareas.
  document.querySelectorAll('textarea.code, .find-form textarea').forEach(function (ta) {
    ta.addEventListener('keydown', function (e) {
      if (e.key === 'Tab') {
        e.preventDefault();
        var s = this.selectionStart, en = this.selectionEnd;
        this.value = this.value.slice(0, s) + '  ' + this.value.slice(en);
        this.selectionStart = this.selectionEnd = s + 2;
      }
    });
  });

  // Live JSON validity hint on document editors.
  document.querySelectorAll('textarea[name="document"], textarea[name="filter"]').forEach(function (ta) {
    var note = document.createElement('div');
    note.style.cssText = 'font-size:11px;margin-top:4px;';
    ta.parentNode.appendChild(note);
    function check() {
      var v = ta.value.trim();
      if (v === '' || v === '{}') { note.textContent = ''; return; }
      try { JSON.parse(v); note.textContent = '\u2713 valid JSON'; note.style.color = '#2f6b2f'; }
      catch (err) { note.textContent = '\u2717 ' + err.message; note.style.color = '#b23b3b'; }
    }
    ta.addEventListener('input', check); check();
  });

  // Export "select all" toggle.
  var all = document.getElementById('exp-all');
  if (all) {
    all.addEventListener('change', function () {
      document.querySelectorAll('.exp-coll').forEach(function (cb) { cb.checked = all.checked; });
    });
  }

  /* ---------------- inline cell editing ---------------- */

  document.addEventListener('dblclick', function (e) {
    var td = e.target.closest('td.editable');
    if (td && !td.querySelector('.cell-editor')) startEdit(td);
  });

  function startEdit(td) {
    var ta = document.createElement('textarea');
    ta.className = 'cell-editor';
    ta.value = td.dataset.json || '';
    td.dataset.prevHtml = td.innerHTML;
    td.innerHTML = '';
    td.appendChild(ta);
    ta.focus();
    ta.select();

    var settled = false;
    function save()   { if (!settled) { settled = true; commit(td, ta.value); } }
    function cancel() { if (!settled) { settled = true; td.innerHTML = td.dataset.prevHtml; } }

    ta.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter' && !ev.shiftKey) { ev.preventDefault(); save(); }
      else if (ev.key === 'Escape') { ev.preventDefault(); cancel(); }
    });
    ta.addEventListener('blur', save);
  }

  function commit(td, value) {
    var body = new URLSearchParams({
      do: 'update_field', csrf: CSRF,
      db: td.dataset.db, collection: td.dataset.coll,
      id: td.dataset.id, field: td.dataset.field, value: value
    });
    td.classList.add('saving');
    fetch('?', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch' },
      body: body.toString()
    })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'HTTP ' + r.status }; }); })
      .then(function (d) {
        td.classList.remove('saving');
        if (d.ok) {
          td.dataset.json = d.json;
          td.innerHTML = '<span class="cell">' + escapeHtml(d.preview) + '</span>';
          flash(td, 'cell-ok');
        } else {
          td.innerHTML = td.dataset.prevHtml;
          flash(td, 'cell-err');
          window.alert('Update failed: ' + (d.error || 'unknown error'));
        }
      })
      .catch(function () {
        td.classList.remove('saving');
        td.innerHTML = td.dataset.prevHtml;
        window.alert('Network error while saving.');
      });
  }

  function flash(td, cls) {
    td.classList.add(cls);
    setTimeout(function () { td.classList.remove(cls); }, 900);
  }

  /* ---------------- row selection / with-selected ---------------- */

  var bar = document.querySelector('.withsel');
  if (bar) {
    var checkAll = document.getElementById('check-all');
    var countEl = bar.querySelector('.sel-count b');

    function rowChecks() { return [].slice.call(document.querySelectorAll('.rowcheck')); }
    function selectedIds() { return rowChecks().filter(function (c) { return c.checked; }).map(function (c) { return c.dataset.id; }); }
    function refresh() { if (countEl) countEl.textContent = selectedIds().length; }

    if (checkAll) checkAll.addEventListener('change', function () {
      rowChecks().forEach(function (c) { c.checked = checkAll.checked; });
      refresh();
    });
    document.addEventListener('change', function (e) {
      if (e.target.classList && e.target.classList.contains('rowcheck')) refresh();
    });

    function buildForm(method, fields, ids) {
      var f = document.createElement('form');
      f.method = method; f.action = '?'; f.style.display = 'none';
      function add(n, v) { var i = document.createElement('input'); i.type = 'hidden'; i.name = n; i.value = v; f.appendChild(i); }
      Object.keys(fields).forEach(function (k) { add(k, fields[k]); });
      ids.forEach(function (id) { add('ids[]', id); });
      document.body.appendChild(f); f.submit();
    }

    bar.addEventListener('click', function (e) {
      var btn = e.target.closest('button[data-op]');
      if (!btn) return;
      var op = btn.dataset.op;
      var db = bar.dataset.db, coll = bar.dataset.coll, csrf = bar.dataset.csrf;

      if (op === 'copy') { // reveal target inputs
        var ct = bar.querySelector('.copy-target');
        if (ct) ct.hidden = !ct.hidden;
        return;
      }
      var ids = selectedIds();
      if (!ids.length) { window.alert(bar.dataset.none || 'No documents selected.'); return; }

      if (op === 'delete') {
        if (!window.confirm('Delete ' + ids.length + ' selected document(s)?')) return;
        buildForm('post', { do: 'bulk_delete', db: db, collection: coll, csrf: csrf }, ids);
      } else if (op === 'export') {
        var fmt = (bar.querySelector('.sel-format') || {}).value || 'json';
        buildForm('post', { do: 'export', db: db, 'collections[]': coll, format: fmt, csrf: csrf }, ids);
      } else if (op === 'edit') {
        buildForm('get', { db: db, collection: coll, action: 'editmulti' }, ids);
      } else if (op === 'copy-go') {
        var tdb = (bar.querySelector('.ct-db') || {}).value || db;
        var tcoll = (bar.querySelector('.ct-coll') || {}).value || coll;
        buildForm('post', { do: 'bulk_copy', db: db, collection: coll, targetDb: tdb, targetColl: tcoll, csrf: csrf }, ids);
      }
    });
  }

  /* ---------------- sidebar accordion + create-collection ---------------- */

  function setCaret(li) {
    var t = li.querySelector('.db-toggle');
    if (t) t.textContent = li.classList.contains('open') ? '▾' : '▸';
  }

  document.querySelectorAll('#navi .db .db-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var li = btn.closest('.db');
      var cols = li.querySelector('.cols');
      if (li.classList.contains('open')) {
        li.classList.remove('open'); setCaret(li); return;
      }
      li.classList.add('open'); setCaret(li);
      if (cols && cols.children.length === 0) {          // load once
        cols.innerHTML = '<li class="muted">…</li>';
        var db = li.dataset.db;
        fetch('?do=nav_cols&db=' + encodeURIComponent(db), { headers: { 'X-Requested-With': 'fetch' } })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (!data.ok) { cols.innerHTML = '<li class="muted">' + escapeHtml(data.error || 'error') + '</li>'; return; }
            if (!data.collections.length) { cols.innerHTML = '<li class="muted">(empty)</li>'; return; }
            cols.innerHTML = data.collections.map(function (c) {
              var href = '?db=' + encodeURIComponent(db) + '&collection=' + encodeURIComponent(c.name) + '&action=browse';
              return '<li><a class="col" href="' + href + '">▸ ' + escapeHtml(c.name) + '</a></li>';
            }).join('');
          })
          .catch(function () { cols.innerHTML = '<li class="muted">load failed</li>'; });
      }
    });
  });

  // "+" reveals the inline create-collection form for that database; toggles to "−".
  document.querySelectorAll('#navi .db .db-add').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var form = btn.closest('.db').querySelector('.navi-create');
      if (!form) return;
      form.hidden = !form.hidden;
      btn.textContent = form.hidden ? '+' : '\u2212';   // + / minus
      if (!form.hidden) { var i = form.querySelector('input[name="name"]'); if (i) i.focus(); }
    });
  });

  // Show capped size/max fields only when "Capped" is selected (sidebar + Operations forms).
  document.addEventListener('change', function (e) {
    if (e.target.classList && e.target.classList.contains('cc-type')) {
      var form = e.target.closest('form');
      var on = e.target.value === 'capped';
      form.querySelectorAll('.cc-capped, .cc-capped-hint').forEach(function (el) { el.hidden = !on; });
    }
  });

  /* ---------------- Insert: add another field row ---------------- */
  document.addEventListener('click', function (e) {
    if (!e.target.classList || !e.target.classList.contains('if-add')) return;
    var body = e.target.closest('.insert-fields').querySelector('.if-rows');
    var tr = document.createElement('tr');
    tr.className = 'if-row';
    tr.innerHTML = '<td><input name="keys[]" placeholder="field"></td><td><input name="vals[]" placeholder="value" spellcheck="false"></td>';
    body.appendChild(tr);
    var i = tr.querySelector('input'); if (i) i.focus();
  });

  /* ---------------- Find by fields: add another condition row ---------------- */
  document.addEventListener('click', function (e) {
    if (!e.target.classList || !e.target.classList.contains('ff-add')) return;
    var form = e.target.closest('.find-fields');
    if (!form) return;
    var body = form.querySelector('.ff-rows');
    var proto = body && body.querySelector('.ff-row');
    if (!proto) return;
    var tr = proto.cloneNode(true);   // clone keeps the operator <select> options
    tr.querySelectorAll('input').forEach(function (inp) { inp.value = ''; });
    var sel = tr.querySelector('select'); if (sel) sel.selectedIndex = 0;
    body.appendChild(tr);
    var first = tr.querySelector('input'); if (first) first.focus();
  });

  /* ---------------- database page: bulk collection actions ---------------- */

  function pmaSubmit(method, fields, arrName, values) {
    var f = document.createElement('form');
    f.method = method; f.action = '?'; f.style.display = 'none';
    function add(n, v) { var i = document.createElement('input'); i.type = 'hidden'; i.name = n; i.value = v; f.appendChild(i); }
    Object.keys(fields).forEach(function (k) { add(k, fields[k]); });
    (values || []).forEach(function (v) { add(arrName, v); });
    document.body.appendChild(f); f.submit();
  }

  var csel = document.querySelector('.collsel');
  if (csel) {
    var db = csel.dataset.db, csrf = csel.dataset.csrf;
    var countEl = csel.querySelector('.sel-count b');
    function collChecks() { return [].slice.call(document.querySelectorAll('.collcheck')); }
    function selectedColls() { return collChecks().filter(function (c) { return c.checked; }).map(function (c) { return c.dataset.name; }); }
    function refreshColl() { if (countEl) countEl.textContent = selectedColls().length; }

    var allBox = document.getElementById('coll-check-all');
    if (allBox) allBox.addEventListener('change', function () {
      collChecks().forEach(function (c) { c.checked = allBox.checked; }); refreshColl();
    });
    document.addEventListener('change', function (e) {
      if (e.target.classList && e.target.classList.contains('collcheck')) refreshColl();
    });

    function tplFor(op) {
      var t = csel.parentNode.querySelector('.bulk-tpl[data-for="' + op + '"]');
      return t ? t.innerHTML : '';
    }

    function modal(title, bodyHtml, onOk) {
      var ov = document.createElement('div');
      ov.className = 'pma-modal-ov';
      ov.innerHTML = '<div class="pma-modal"><h3></h3><div class="pma-body"></div>'
        + '<div class="pma-foot"><button type="button" class="btn-secondary pm-cancel"></button> '
        + '<button type="button" class="pm-ok"></button></div></div>';
      ov.querySelector('h3').textContent = title;
      ov.querySelector('.pma-body').innerHTML = bodyHtml;
      ov.querySelector('.pm-cancel').textContent = csel.dataset.cancel || 'Cancel';
      ov.querySelector('.pm-ok').textContent = csel.dataset.confirm || 'OK';
      function close() { if (ov.parentNode) document.body.removeChild(ov); }
      ov.querySelector('.pm-cancel').addEventListener('click', close);
      ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
      ov.querySelector('.pm-ok').addEventListener('click', function () { if (onOk(ov) !== false) close(); });
      document.body.appendChild(ov);
      var f = ov.querySelector('input, select'); if (f) f.focus();
    }

    function val(ov, name) { var el = ov.querySelector('[name="' + name + '"]'); return el ? el.value : ''; }

    function runBulk(op, names) {
      if (!names.length) { window.alert(csel.dataset.none || 'No collections selected.'); return; }
      var label = '';
      var sel = csel.querySelector('.bulk-op');
      if (sel) { var o = sel.querySelector('option[value="' + op + '"]'); label = o ? o.textContent : op; }

      if (op === 'empty' || op === 'drop') {
        if (!window.confirm(label + ' — ' + names.length + ' collection(s)?')) return;
        pmaSubmit('post', { do: op === 'drop' ? 'bulk_drop' : 'bulk_empty', db: db, csrf: csrf }, 'collections[]', names);
        return;
      }
      if (op === 'print') {
        pmaSubmit('get', { db: db, action: 'print' }, 'collections[]', names);
        return;
      }
      // parameterised actions -> modal
      modal(label, tplFor(op), function (ov) {
        if (op === 'copy') {
          pmaSubmit('post', { do: 'bulk_copy_collections', db: db, csrf: csrf, targetDb: val(ov, 'targetDb') }, 'collections[]', names);
        } else if (op === 'copy_prefix') {
          pmaSubmit('post', { do: 'bulk_copy_prefix', db: db, csrf: csrf, targetDb: val(ov, 'targetDb'), prefix: val(ov, 'prefix') }, 'collections[]', names);
        } else if (op === 'add_prefix') {
          if (!val(ov, 'prefix')) { window.alert('Enter a prefix.'); return false; }
          pmaSubmit('post', { do: 'bulk_add_prefix', db: db, csrf: csrf, prefix: val(ov, 'prefix') }, 'collections[]', names);
        } else if (op === 'replace_prefix') {
          pmaSubmit('post', { do: 'bulk_replace_prefix', db: db, csrf: csrf, fromPrefix: val(ov, 'fromPrefix'), toPrefix: val(ov, 'toPrefix') }, 'collections[]', names);
        } else if (op === 'export') {
          pmaSubmit('post', { do: 'export', db: db, csrf: csrf, format: val(ov, 'format') || 'json' }, 'collections[]', names);
        }
      });
    }

    var goBtn = csel.querySelector('[data-go]');
    if (goBtn) goBtn.addEventListener('click', function () {
      var op = (csel.querySelector('.bulk-op') || {}).value || '';
      if (!op) return;
      runBulk(op, selectedColls());
    });

    // inline per-row "Copy" -> copy modal for that single collection
    document.querySelectorAll('.coll-copy').forEach(function (btn) {
      btn.addEventListener('click', function () { runBulk('copy', [btn.dataset.name]); });
    });
  }

  /* ---------------- sub-tabs (Insert / Find panels) ---------------- */
  document.addEventListener('click', function (e) {
    var tab = e.target.closest('.subtab');
    if (!tab) return;
    var bar = tab.closest('.subtabs');
    var name = tab.dataset.tab;
    bar.querySelectorAll('.subtab').forEach(function (b) { b.classList.toggle('on', b === tab); });
    // panels are siblings that follow the bar
    var node = bar.nextElementSibling;
    while (node) {
      if (node.classList && node.classList.contains('subpanel')) {
        node.hidden = (node.dataset.panel !== name);
      }
      node = node.nextElementSibling;
    }
  });

  /* ---------------- mobile: sidebar (navi) drawer toggle ---------------- */
  var navToggle = document.getElementById('navi-toggle');
  if (navToggle) {
    navToggle.addEventListener('click', function () {
      var open = document.body.classList.toggle('navi-open');
      navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    var navi = document.getElementById('navi');
    if (navi) navi.addEventListener('click', function (e) {
      // tapping a database/collection link closes the drawer on small screens
      if (e.target.closest && e.target.closest('a')
          && window.matchMedia && window.matchMedia('(max-width: 760px)').matches) {
        document.body.classList.remove('navi-open');
        navToggle.setAttribute('aria-expanded', 'false');
      }
    });
  }
})();
