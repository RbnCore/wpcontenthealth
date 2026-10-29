/* İçerik Sağlığı yönetim arayüzü. Yalnız eklentinin kendi ekranında yüklenir; ContentHealthCfg {root, nonce, home, i18n} sunucudan gelir. */
(function () {
	'use strict';
	var d = document, cfg = window.ContentHealthCfg || {}, T = cfg.i18n || {};
	var wrap = d.getElementById('ch');
	if (!wrap) { return; }
	var u = window.RbnUi || {};
	var $ = u.$ || function (s, r) { return (r || d).querySelector(s); };
	var $$ = u.$$ || function (s, r) { return Array.prototype.slice.call((r || d).querySelectorAll(s)); };
	var live = d.getElementById('live');
	var say = function (t) { if (live) { live.textContent = ''; setTimeout(function () { live.textContent = t; }, 30); } };
	var el = u.el || function (tag, cls, txt) { var e = d.createElement(tag); if (cls) { e.className = cls; } if (txt != null) { e.textContent = txt; } return e; };

	/* REST */
	function api(path, opt) {
		opt = opt || {};
		var init = { method: opt.method || 'GET', credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce, 'Accept': 'application/json' } };
		if (opt.body) { init.method = 'POST'; init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(opt.body); }
		var url = cfg.root + path;
		if (cfg.root.indexOf('?') > -1) { url = cfg.root + path.replace('?', '&'); }
		// REST answers in the site language unless asked otherwise; _locale=user makes it use the language of the signed-in user.
		url += (url.indexOf('?') > -1 ? '&' : '?') + '_locale=user';
		return fetch(url, init).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (j) {
				if (!r.ok) { var e = new Error(j && j.message ? j.message : String(r.status)); e.status = r.status; throw e; }
				return j;
			});
		});
	}
	function flash(msg, undoKey, tone) {
		say(msg);
		if (window.RbnUi && window.RbnUi.toast) {
			var opts = { tone: tone || 'success' };
			if (undoKey) {
				opts.action = {
					label: T.undo || 'Undo',
					onClick: function () {
						location.href = cfg.home + '&tab=undo';
					}
				};
			}
			window.RbnUi.toast(msg, opts);
			return;
		}
		var f = d.getElementById('flash');
		if (!f) { return; }
		f.textContent = msg; f.hidden = false;
		if (undoKey) {
			f.appendChild(d.createTextNode(' '));
			var a = el('a', '', T.undoRecord + ' ' + undoKey.slice(0, 8));
			a.href = cfg.home + '&tab=undo'; f.appendChild(a);
		}
	}
	function err(e) {
		var msg = e && e.status ? (T.undoFail + ' (' + e.message + ')') : T.netError;
		flash(msg, null, 'danger');
	}

	/* Dialog: showModal, Esc ve odak dönüşü tarayıcıda yerleşik; odağı açana geri veririz */
	function openDlg(id, from) {
		var dl = d.getElementById(id); if (!dl || dl.open) { return; }
		dl.__from = from || d.activeElement; dl.showModal();
	}
	d.addEventListener('click', function (e) {
		var o = e.target.closest('[data-open]');
		if (o) { openDlg(o.getAttribute('data-open'), o); }
		var c = e.target.closest('[data-close]');
		if (c) { var dl = c.closest('dialog'); if (dl) { dl.close(); } }
	});
	$$('dialog').forEach(function (dl) {
		dl.addEventListener('close', function () { var f = dl.__from; if (f && d.contains(f)) { f.focus(); } });
		dl.addEventListener('click', function (e) { if (e.target === dl) { dl.close(); } });
	});

	/* Seçim + toplu çubuk */
	var tbl = d.getElementById('tbl'), bulk = d.getElementById('bulk');
	function boxes() { return $$('tbody input[type=checkbox]', tbl); }
	function sync() {
		if (!tbl) { return; }
		var b = boxes(), n = b.filter(function (x) { return x.checked; }).length;
		b.forEach(function (x) { var tr = x.closest('tr'); if (tr) { tr.classList.toggle('is-sel', x.checked); } });
		var all = $('[data-select-all]', tbl);
		if (all) { all.checked = n > 0 && n === b.length; all.indeterminate = n > 0 && n < b.length; }
		if (bulk && !tbl.hasAttribute('data-alt')) { $$('[data-n]', bulk).forEach(function (x) { x.textContent = n; }); bulk.classList.toggle('is-on', n > 0); bulk.hidden = n === 0; }
		if (tbl.hasAttribute('data-alt') && bulk) { $$('[data-n]', bulk).forEach(function (x) { x.textContent = n; }); }
	}
	if (tbl) {
		tbl.addEventListener('change', function (e) {
			if (e.target.matches('[data-select-all]')) { boxes().forEach(function (x) { x.checked = e.target.checked; }); }
			sync(); say(boxes().filter(function (x) { return x.checked; }).length + ' ' + (tbl.hasAttribute('data-alt') ? T.images : T.selected));
		});
		sync();
	}
	var clr = $('[data-bulk-clear]');
	if (clr) { clr.addEventListener('click', function () { boxes().forEach(function (x) { x.checked = false; }); sync(); }); }

	/* Diff görünümü (satır içi / yan yana) */
	d.addEventListener('change', function (e) {
		if (!e.target.matches('input[name=diffmode]')) { return; }
		var sp = e.target.value === 'split';
		$$('[data-diff]').forEach(function (df) {
			df.classList.toggle('ch-diff--split', sp);
			var a = $('[data-pane=inline]', df), b = $('[data-pane=split]', df);
			if (a) { a.hidden = sp; } if (b) { b.hidden = !sp; }
		});
	});

	/* Tarama: başlat, izle, duraklat, iptal */
	var scanTimer = null, scanOnEnd = null;
	function scanUI(run) {
		var box = d.getElementById('scanbox'), idle = d.getElementById('scanidle'); if (!box) { return; }
		var p = run && run.percent != null ? run.percent : 0, bar = d.getElementById('scan-bar');
		box.hidden = false; if (idle) { idle.hidden = true; }
		if (bar) { bar.style.setProperty('--w', p + '%'); bar.setAttribute('aria-valuenow', p); }
		var pc = d.getElementById('scan-pct'); if (pc) { pc.textContent = p + '%'; }
		var m = d.getElementById('scan-msg'); if (m) { m.textContent = (run && run.status === 'paused') ? T.paused : ((run && run.stage) || T.scanning); }
		var sub = d.getElementById('scan-sub'); if (sub && run) { sub.textContent = (run.done || 0) + ' / ' + (run.total || 0) + ' ' + T.items; }
		var pb = $('[data-scan-pause] span', d); if (pb) { pb.textContent = run && run.status === 'paused' ? T.resume : T.pause; }
	}
	function scanStep(action, extra) {
		return api('scan', { body: Object.assign({ action: action }, extra || {}) }).then(function (res) {
			var run = res.run;
			if (!run) { return null; }
			scanUI(run);
			if (run.status === 'finished') { scanEnd(res); return run; }
			if (run.status === 'cancelled' || run.status === 'failed') { scanFail(run); return run; }
			if (run.status === 'paused') { return run; }
			scanTimer = setTimeout(function () { scanStep('step').catch(scanNet); }, 250);
			return run;
		});
	}
	function scanNet() {
		var m = d.getElementById('scan-msg'); if (m) { m.textContent = T.netError; }
		say(T.netError);
		var box = d.getElementById('scanbox');
		if (box && !$('[data-scan-retry]', box)) {
			var b = el('button', 'rbn-btn rbn-btn--sm', T.retry); b.type = 'button'; b.setAttribute('data-scan-retry', '');
			b.addEventListener('click', function () { b.remove(); scanStep('step').catch(scanNet); });
			box.appendChild(b);
		}
	}
	function scanFail(run) {
		var m = d.getElementById('scan-msg'); if (m) { m.textContent = run.status === 'cancelled' ? T.cancelled : T.scanFail; }
		say(run.status === 'cancelled' ? T.cancelled : T.scanFail);
		setTimeout(function () { if (run.status === 'cancelled') { location.reload(); } }, 800);
	}
	function scanEnd(res) {
		say(T.scanDone);
		if (scanOnEnd) { scanOnEnd(res); scanOnEnd = null; return; }
		location.href = cfg.home;
	}
	function scanStart(onEnd) {
		scanOnEnd = onEnd || null;
		var box = d.getElementById('scanbox'); if (box) { box.hidden = false; }
		var m = d.getElementById('scan-msg'); if (m) { m.textContent = T.starting; }
		return scanStep('start').catch(function (e) {
			if (e && e.status) { var mm = d.getElementById('scan-msg'); if (mm) { mm.textContent = e.message; } say(e.message); } else { scanNet(); }
		});
	}
	$$('[data-scan]').forEach(function (b) {
		b.addEventListener('click', function () {
			if (!d.getElementById('scanbox')) { location.href = cfg.home + '&tara=1'; return; }
			scanStart();
		});
	});
	var pz = $('[data-scan-pause]'), cz = $('[data-scan-cancel]');
	if (pz) { pz.addEventListener('click', function () { clearTimeout(scanTimer); var span = $('span', pz); scanStep(span && span.textContent === T.resume ? 'resume' : 'pause').catch(scanNet); }); }
	if (cz) { cz.addEventListener('click', function () { clearTimeout(scanTimer); scanStep('cancel').catch(scanNet); }); }
	(function resume() {
		var box = d.getElementById('scanbox'); if (!box) { return; }
		var q = new URLSearchParams(location.search);
		if (q.get('tara') === '1' && wrap.getAttribute('data-screen') !== 'ilk') { scanStart(); return; }
		if (!box.hidden && wrap.getAttribute('data-screen') !== 'ilk') { api('scan', { body: { action: 'step' } }).then(function (r) { if (r.run && (r.run.status === 'running' || r.run.status === 'queued')) { scanUI(r.run); scanTimer = setTimeout(function () { scanStep('step').catch(scanNet); }, 250); } }).catch(function () {}); }
	})();

	/* Bulgu çekmecesi */
	var detId = 0, detData = null;
	function loadDetail(id, from) {
		detId = id; detData = null;
		var dl = d.getElementById('dlg-detay'); if (!dl) { return; }
		$('[data-d-title]', dl).textContent = '…'; $('[data-d-body]', dl).textContent = '';
		$('[data-d-badge]', dl).textContent = '';
		openDlg('dlg-detay', from);
		api('issues/' + id + '/detail').then(function (r) {
			detData = r;
			$('[data-d-title]', dl).textContent = r.title;
			$('[data-d-badge]', dl).innerHTML = r.badge || '';
			$('[data-d-body]', dl).innerHTML = r.html || '';
			var ed = $('[data-d-edit]', dl); if (r.edit) { ed.href = r.edit; ed.hidden = false; } else { ed.hidden = true; }
			var fx = $('[data-d-fix]', dl); fx.hidden = !r.fixable || r.status !== 'open';
			var ig = $('[data-ignore-detail]', dl); ig.hidden = r.status !== 'open';
		}).catch(function () { $('[data-d-body]', dl).textContent = T.loadFail; });
	}
	d.addEventListener('click', function (e) {
		var b = e.target.closest('[data-detail]');
		if (b) { loadDetail(parseInt(b.getAttribute('data-detail'), 10), b); }
	});
	if (tbl && tbl.getAttribute('data-autoopen')) { var ao = parseInt(tbl.getAttribute('data-autoopen'), 10); if (ao) { setTimeout(function () { loadDetail(ao, $('[data-detail="' + ao + '"]')); }, 0); } }
	var igd = $('[data-ignore-detail]');
	if (igd) {
		igd.addEventListener('click', function () {
			var f = d.getElementById('bulkform'); if (!f || !detId) { return; }
			var i = el('input'); i.type = 'hidden'; i.name = 'one'; i.value = detId; f.appendChild(i); f.submit();
		});
	}
	var fixBtn = $('[data-d-fix]');
	if (fixBtn) {
		fixBtn.addEventListener('click', function () {
			if (!detData) { return; }
			var title = T.applyOneTitle || 'This fix will change 1 item';
			var msg = (detData.title ? (detData.title + '\n\n') : '') + (T.applyOneConfirm || 'A dry run was done; the change will be applied and written to the undo log.');
			var applyFix = function () {
				fixBtn.disabled = true;
				var keep = $('[data-keep-mod]', d.getElementById('dlg-detay'));
				api('fix', { body: { rule: detData.rule, ids: [detData.object], dry_run: false, keep_modified: keep ? keep.checked : undefined } }).then(function (r) {
					fixBtn.disabled = false;
					var dlDetay = d.getElementById('dlg-detay');
					if (dlDetay && dlDetay.close) { dlDetay.close(); }
					if (r.changed) {
						flash(r.changed + ' ' + T.fixed + '.', r.batch);
						var tr = $('tr[data-id="' + detId + '"]');
						if (tr) { tr.style.opacity = '.55'; }
					} else { flash(T.noChange); }
				}).catch(function (e) { fixBtn.disabled = false; err(e); });
			};
			window.RbnUi.confirm({
				title: title,
				message: msg,
				confirmLabel: T.apply || 'Apply the fix',
				confirmText: T.apply || 'Apply the fix',
				cancelLabel: T.cancel || 'Cancel',
				cancelText: T.cancel || 'Cancel',
				tone: 'primary'
			}).then(function (ok) {
				if (ok) { applyFix(); }
			});
		});
	}

	/* Toplu düzeltme sihirbazı (Bulgular) */
	var wz = $('[data-wiz]');
	if (wz && tbl) {
		var wStep = 1, groups = {}, skipped = 0, wBatch = '', dryRes = [];
		var nx = $('[data-wiz-next]', wz), pv = $('[data-wiz-prev]', wz), ok = d.getElementById('wiz-ok'), cancel = $('[data-wiz-cancel]', wz);
		var labels = [T.step1, T.step2, T.step3, T.close];
		var rnd = function () { var a = new Uint8Array(8); crypto.getRandomValues(a); return Array.prototype.map.call(a, function (x) { return ('0' + x.toString(16)).slice(-2); }).join(''); };
		function show(n) {
			wStep = n;
			$$('[data-step]', wz).forEach(function (s) { s.hidden = s.getAttribute('data-step') != n; });
			$$('.ch-stepper li', wz).forEach(function (li, i) { li.classList.toggle('is-done', i < n - 1); if (i === n - 1) { li.setAttribute('aria-current', 'step'); } else { li.removeAttribute('aria-current'); } });
			pv.hidden = n === 1 || n === 4; nx.textContent = labels[n - 1]; nx.disabled = n === 3 && !ok.checked; nx.hidden = false;
			cancel.hidden = n === 4;
			var h = $('[data-step="' + n + '"] h3', wz), s = $('[data-step="' + n + '"]', wz);
			if (h) { say(h.textContent); } if (s) { s.focus({ preventScroll: true }); }
		}
		function collect() {
			groups = {}; skipped = 0;
			boxes().filter(function (x) { return x.checked; }).forEach(function (x) {
				var tr = x.closest('tr'), fix = tr.getAttribute('data-fix'), rule = tr.getAttribute('data-rule');
				if (!fix || fix === 'term_merge_skip' || fix === 'fix_term_merge') { skipped++; return; }
				(groups[rule] = groups[rule] || { rule: rule, label: tr.children[2].firstChild.textContent, fix: fix, ids: [] }).ids.push(parseInt(tr.getAttribute('data-obj'), 10));
			});
		}
		var wzOpen = $('[data-wiz-open]');
		if (wzOpen) {
			wzOpen.addEventListener('click', function () {
				collect(); dryRes = []; wBatch = ''; ok.checked = false;
				var body = $('[data-wiz-rules]', wz); body.textContent = '';
				var total = 0;
				Object.keys(groups).forEach(function (k) {
					var g = groups[k], tr = el('tr'); total += g.ids.length;
					tr.appendChild(el('th', '', g.label)); tr.lastChild.scope = 'row';
					tr.appendChild(el('td', 'rbn-num', String(g.ids.length)));
					tr.appendChild(el('td', '', g.fix));
					body.appendChild(tr);
				});
				$('[data-wiz-intro]', wz).textContent = total + ' ' + T.selected;
				var sn = $('[data-wiz-skipnote]', wz); sn.textContent = skipped ? skipped + ' ' + T.noChange : '';
				nx.disabled = total === 0; pv.disabled = false; $('#wiz-run').hidden = true;
				show(1);
			});
		}
		function dryAll() {
			nx.disabled = true; nx.textContent = T.dryRunning;
			var keep = $('[data-wiz-keep]', wz).checked;
			var jobs = Object.keys(groups).map(function (k) { return api('fix', { body: { rule: k, ids: groups[k].ids, dry_run: true, keep_modified: keep } }); });
			return Promise.all(jobs).then(function (rs) {
				dryRes = rs;
				var st = $('[data-wiz-stats]', wz), sm = $('[data-wiz-samples]', wz); st.textContent = ''; sm.textContent = '';
				var ch = 0, sk = 0, er = 0;
				rs.forEach(function (r) { ch += r.changed; sk += r.skipped; er += r.errors; });
				[[T.willFix, ch, T.posts], [T.willSkip, sk, T.posts], [T.errorsK, er, '']].forEach(function (p) {
					var li = el('li', 'rbn-stat'), v = el('p', 'rbn-stat__v', String(p[1]));
					li.appendChild(el('p', 'rbn-stat__k', p[0]));
					if (p[2]) { v.appendChild(el('small', '', p[2])); }
					li.appendChild(v); st.appendChild(li);
				});
				var row = function (kind, text) {
					var r = el('div', 'ch-diff__r ch-diff__r--' + kind);
					r.appendChild(el('span', 'ch-diff__n')); r.appendChild(el('span', 'ch-diff__s', kind === 'del' ? '−' : '+'));
					var c = el('span', 'ch-diff__c'); c.appendChild(el(kind === 'del' ? 'del' : 'ins', '', text)); r.appendChild(c); return r;
				};
				rs.forEach(function (r) {
					r.items.slice(0, 3).forEach(function (it) {
						if (!it.changes || !it.changes[0]) { return; }
						var c = it.changes[0], box = el('div', 'ch-diff'), hd = el('div', 'ch-diff__hd'), pane = el('div', 'ch-diff__pane');
						hd.appendChild(el('b', '', T.sample + ' · #' + it.object_id)); hd.appendChild(el('code', 'rbn-muted', c.field)); box.appendChild(hd);
						(c.removed || []).slice(0, 2).forEach(function (t) { pane.appendChild(row('del', t)); });
						(c.added || []).slice(0, 2).forEach(function (t) { pane.appendChild(row('add', t)); });
						box.appendChild(pane); sm.appendChild(box);
					});
				});
				$('[data-wiz-oktext]', wz).textContent = ch + ' ' + T.posts + ' · ' + ch + ' ' + T.fixed;
				show(2);
				if (ch === 0) { nx.disabled = true; say(T.noChange); }
			}).catch(function (e) { nx.disabled = false; nx.textContent = labels[0]; err(e); });
		}
		function applyAll() {
			var run = d.getElementById('wiz-run'), bar = d.getElementById('wiz-bar'), keys = Object.keys(groups), done = 0;
			run.hidden = false; nx.disabled = true; pv.disabled = true; wBatch = rnd();
			var keep = $('[data-wiz-keep]', wz).checked, res = [];
			var seq = keys.reduce(function (p, k) {
				return p.then(function () {
					return api('fix', { body: { rule: k, ids: groups[k].ids, dry_run: false, keep_modified: keep, batch: wBatch } }).then(function (r) {
						res.push(r); done++;
						var pct = Math.round(100 * done / keys.length);
						bar.style.setProperty('--w', pct + '%'); bar.setAttribute('aria-valuenow', pct);
						d.getElementById('wiz-pct').textContent = pct + '%'; d.getElementById('wiz-msg').textContent = T.applying;
					});
				});
			}, Promise.resolve());
			return seq.then(function () {
				var out = $('[data-wiz-result]', wz); out.textContent = '';
				var ch = 0, sk = 0, er = 0;
				res.forEach(function (r) { ch += r.changed; sk += r.skipped; er += r.errors; });
				var n = el('div', 'rbn-note rbn-note--' + (er ? 'warn' : 'ok')); n.setAttribute('role', 'status');
				var p = el('p'); p.appendChild(el('strong', '', ch + ' ' + T.fixed)); p.appendChild(d.createTextNode(' · ' + sk + ' ' + T.skipped + ' · ' + er + ' ' + T.errors + ' · ' + T.undoRecord + ' '));
				var a = el('a', '', wBatch.slice(0, 8)); a.href = cfg.home + '&tab=undo'; p.appendChild(a); n.appendChild(p); out.appendChild(n);
				pv.disabled = false; show(4); run.hidden = true;
				boxes().forEach(function (x) { if (x.checked && x.closest('tr').getAttribute('data-fix')) { x.closest('tr').style.opacity = '.55'; x.checked = false; } });
				sync();
			}).catch(function (e) { pv.disabled = false; nx.disabled = false; err(e); });
		}
		nx.addEventListener('click', function () {
			if (wStep === 1) { dryAll(); return; }
			if (wStep === 2) { show(3); return; }
			if (wStep === 3) { applyAll(); return; }
			wz.close(); location.reload();
		});
		pv.addEventListener('click', function () { show(wStep - 1); });
		ok.addEventListener('change', function () { nx.disabled = !ok.checked; if (!ok.checked) { say(T.wizOk); } });
		wz.addEventListener('close', function () { var r = d.getElementById('wiz-run'); if (r) { r.hidden = true; } });
	}

	/* Geri alma günlüğü */
	d.addEventListener('click', function (e) {
		var b = e.target.closest('[data-undo]');
		if (b) {
			var undoKey = b.getAttribute('data-undo');
			var no = b.getAttribute('data-undo-no'), n = b.getAttribute('data-undo-n'), u = b.getAttribute('data-undo-unit');
			var title = (T.undoAction || 'Undo: operation') + ' #' + no + ' (' + n + ' ' + u + ')';
			var msg = T.undoConfirm || 'The affected items return to their state before the fix. Manual changes made in between are not overwritten; changed items are skipped.';
			var doUndo = function () {
				b.disabled = true;
				api('undo', { body: { key: undoKey, dry_run: false } }).then(function (r) {
					b.disabled = false;
					flash(r.reverted + ' ' + T.undone + (r.skipped ? ' · ' + r.skipped + ' ' + T.skipped : ''));
					setTimeout(function () { location.reload(); }, 900);
				}).catch(function (errObj) { b.disabled = false; err(errObj); });
			};
			window.RbnUi.confirm({
				title: title,
				message: msg,
				confirmLabel: T.undo || 'Undo',
				confirmText: T.undo || 'Undo',
				cancelLabel: T.cancel || 'Cancel',
				cancelText: T.cancel || 'Cancel',
				tone: 'danger'
			}).then(function (ok) {
				if (ok) { doUndo(); }
			});
		}
		var dt = e.target.closest('[data-undo-detail]');
		if (dt) {
			var row = d.getElementById(dt.getAttribute('aria-controls')), open = dt.getAttribute('aria-expanded') !== 'true';
			dt.setAttribute('aria-expanded', String(open)); if (row) { row.hidden = !open; }
		}
	});

	/* Etiket Doktoru */
	var mergeCluster = null;
	$$('[data-cluster]').forEach(function (c) {
		var s = $('[data-merge-sum]', c);
		function info() {
			var terms = $$('.ch-term', c), tot = 0, name = '', id = 0;
			terms.forEach(function (t) { var i = $('input', t); tot += parseInt(i.getAttribute('data-count'), 10) || 0; if (i.checked) { name = i.getAttribute('data-name'); id = parseInt(i.value, 10); } });
			return { terms: terms, tot: tot, name: name, id: id };
		}
		function upd() { var x = info(); s.textContent = x.tot + ' ' + T.posts + ' “' + x.name + '” · ' + (x.terms.length - 1) + ' ' + T.deleted; }
		c.addEventListener('change', upd); upd();
		$('[data-merge-from]', c).addEventListener('click', function () {
			var x = info();
			var rs = $$('.ch-term input', c), canon = 0, others = [], otherNames = [];
			rs.forEach(function (i) {
				if (i.checked) {
					canon = parseInt(i.value, 10);
				} else {
					others.push(parseInt(i.value, 10));
					otherNames.push(i.getAttribute('data-name'));
				}
			});
			var title = x.terms.length + ' → “' + x.name + '”';
			var msg = (T.dryDone || 'Dry run: nothing has changed yet.') + '\n' +
				otherNames.map(function (name) { return '→ ' + name; }).join(', ');
			var doMerge = function () {
				var btn = $('[data-merge-from]', c);
				if (btn) { btn.disabled = true; }
				api('fix', { body: { rule: 'term_duplicate', ids: others, canonical: canon, dry_run: false } }).then(function (r) {
					if (btn) { btn.disabled = false; }
					flash(T.merged + ': ' + r.changed + ' ' + T.deleted, r.batch);
					setTimeout(function () { location.reload(); }, 900);
				}).catch(function (errObj) { if (btn) { btn.disabled = false; } err(errObj); });
			};
			window.RbnUi.confirm({
				title: title,
				message: msg,
				confirmLabel: T.merge || 'Merge',
				confirmText: T.merge || 'Merge',
				cancelLabel: T.cancel || 'Cancel',
				cancelText: T.cancel || 'Cancel',
				tone: 'primary'
			}).then(function (ok) {
				if (ok) { doMerge(); }
			});
		});
		$('[data-cluster-ignore]', c).addEventListener('click', function () {
			var ids = (c.getAttribute('data-issues') || '').split(',').filter(Boolean).map(Number);
			api('issues/status', { body: { ids: ids, status: 'ignored' } }).then(function () { c.style.opacity = '.55'; $$('button, input', c).forEach(function (x) { x.disabled = true; }); say(T.skipped); }).catch(err);
		});
	});
	d.addEventListener('click', function (e) {
		var b = e.target.closest('[data-terms-fix]'); if (!b) { return; }
		var ruleId = b.getAttribute('data-terms-fix');
		b.disabled = true;
		api('issues?rule=' + encodeURIComponent(ruleId) + '&status=open&per_page=500').then(function (r) {
			var ids = (r.rows || r.issues || r.items || []).map(function (i) { return i.object; });
			if (!ids.length) {
				b.disabled = false;
				flash(T.noChange || 'No changes to make');
				return null;
			}
			return api('fix', { body: { rule: ruleId, ids: ids, dry_run: true } }).then(function (dry) {
				return { ids: ids, dry: dry };
			});
		}).then(function (res) {
			b.disabled = false;
			if (!res) { return; }
			var ids = res.ids, dry = res.dry;
			var title = dry.changed + ' ' + (T.fixed || 'fixed');
			var msg = (dry.skipped ? (dry.skipped + ' ' + T.skipped + '\n\n') : '') +
				(T.undoNotice || 'Changes are written to the undo log; they can be undone.');
			var doFix = function () {
				b.disabled = true;
				api('fix', { body: { rule: ruleId, ids: ids, dry_run: false } }).then(function (r) {
					b.disabled = false;
					flash(r.changed + ' ' + (T.fixed || 'fixed'), r.batch);
					setTimeout(function () { location.reload(); }, 900);
				}).catch(function (errObj) { b.disabled = false; err(errObj); });
			};
			window.RbnUi.confirm({
				title: title,
				message: msg,
				confirmLabel: T.apply || 'Apply',
				confirmText: T.apply || 'Apply',
				cancelLabel: T.cancel || 'Cancel',
				cancelText: T.cancel || 'Cancel',
				tone: 'danger'
			}).then(function (ok) {
				if (ok) { doFix(); }
			});
		}).catch(function (errObj) { b.disabled = false; err(errObj); });
	});

	/* Alt Metin: şablon önizleme + doldurma */
	var tpl = $('[data-tpl]');
	if (tpl) {
		var site = tpl.getAttribute('data-site') || '';
		var render = function () {
			$$('[data-prev-i]').forEach(function (s) {
				s.textContent = (tpl.value || '').replace(/\{(?:title|başlık)\}/g, s.getAttribute('data-title')).replace(/\{(?:file_name|dosya_adı)\}/g, s.getAttribute('data-file')).replace(/\{(?:site_name|site_adı)\}/g, site).replace(/\s+/g, ' ').trim() || '—';
			});
		};
		tpl.addEventListener('input', render);
		$$('[data-tok]').forEach(function (b) { b.addEventListener('click', function () { tpl.value += (tpl.value && !/\s$/.test(tpl.value) ? ' ' : '') + b.getAttribute('data-tok'); tpl.focus(); render(); say(b.getAttribute('data-tok')); }); });
		var af = $('[data-alt-fill]');
		if (af) {
			af.addEventListener('click', function () {
				var n = boxes().filter(function (x) { return x.checked; }).length;
				$('[data-fill-title]').textContent = n + ' ' + T.posts + ' · ' + T.filled;
				var ul = $('[data-fill-list]'); ul.textContent = ''; ul.appendChild(el('li', '', tpl.value));
			});
		}
		var cf = $('[data-confirm-fill]');
		if (cf) {
			cf.addEventListener('click', function () {
				var ids = boxes().filter(function (x) { return x.checked; }).map(function (x) { return parseInt(x.value, 10); });
				if (!ids.length) { return; }
				cf.disabled = true;
				api('fix', { body: { rule: 'images_missing_alt', ids: ids, dry_run: false, alt_template: tpl.value, keep_modified: $('[data-fill-keep]').checked } }).then(function (r) {
					cf.disabled = false; cf.closest('dialog').close(); flash(T.filled + ': ' + r.changed + ' ' + T.posts, r.batch); setTimeout(function () { location.reload(); }, 900);
				}).catch(function (e) { cf.disabled = false; err(e); });
			});
		}
		render();
	}

	/* Ayarlar: kirli izleme, desen listesi ve deseni dene */
	var form = d.getElementById('ayar');
	if (form) {
		/* Kaydet çubuğunu AdminKit yapışkan çubuğu verir (uzun sayfada altta sabit; kirli izleme + sayfadan ayrılma uyarısı kitte). Burada yalnız programatik değişiklikler forma bildirilir. */
		var rv = $('[data-revert]', form), subNow = $('[data-sub-now]', form);
		var dirty = function () { form.dispatchEvent(new Event('change', { bubbles: true })); };
		form.addEventListener('submit', function () { var u = new URL(location.href).searchParams.get('sub'); if (u && subNow) { subNow.value = u; } });
		rv.addEventListener('click', function () { location.reload(); });
		function store(box) { $('[data-pats-store]', box).value = $$('.ch-pats code', box).map(function (c) { return c.textContent; }).join('\n'); }
		$$('[data-pats]').forEach(function (box) {
			var inp = $('[data-pat-new]', box);
			function add() {
				var v = inp.value.trim(); if (!v) { inp.focus(); return; }
				api('pattern-test', { body: { pattern: v, sample: '' } }).then(function (r) {
					if (!r.ok) { inp.setCustomValidity(T.patBad); inp.reportValidity(); say(T.patBad + ' ' + (r.error || '')); return; }
					inp.setCustomValidity('');
					var li = el('li', 'ch-pat'), c = el('code', '', v), b = el('button'); b.type = 'button'; b.setAttribute('data-pat-del', ''); b.setAttribute('aria-label', T.patDel + ': ' + v.slice(0, 40));
					b.innerHTML = '<svg class="rbn-ico" aria-hidden="true"><use href="#i-trash"/></svg>';
					li.appendChild(c); li.appendChild(b); $('.ch-pats', box).appendChild(li); inp.value = ''; store(box); dirty();
				}).catch(function () { say(T.netError); });
			}
			$('[data-pat-add]', box).addEventListener('click', add);
			inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); add(); } });
			box.addEventListener('click', function (e) { var b = e.target.closest('[data-pat-del]'); if (b) { b.closest('li').remove(); store(box); dirty(); } });
		});
		var pt = $('[data-pat-test]');
		if (pt) {
			pt.addEventListener('click', function () {
				var out = d.getElementById('pt-out'), pat = d.getElementById('pt-pat').value, lines = d.getElementById('pt-in').value.split('\n'), hits = [];
				if (!pat) { out.textContent = T.patNone; return; }
				Promise.all(lines.map(function (l, i) { return api('pattern-test', { body: { pattern: pat, sample: l } }).then(function (r) { if (!r.ok) { throw new Error(r.error || T.patBad); } if (r.match) { hits.push([i + 1, r.match]); } }); })).then(function () {
					out.textContent = '';
					if (!hits.length) { out.appendChild(el('p', '', T.patNone)); say(T.patNone); return; }
					out.appendChild(el('p', '', hits.length + ' ' + T.patMatches));
					hits.forEach(function (h) { var p = el('p', 'ch-snippet'), m = el('mark', '', h[1]); p.appendChild(d.createTextNode(h[0] + ': ')); p.appendChild(m); out.appendChild(p); });
					say(hits.length + ' ' + T.patMatches);
				}).catch(function (e) { out.textContent = T.patBad + ' ' + (e.message || ''); say(T.patBad); });
			});
		}
	}

	/* İlk açılış */
	var fw = $('[data-wiz-first]');
	if (fw) {
		var fs = 1, fn = $('[data-f-next]', fw), fp = $('[data-f-prev]', fw);
		var fshow = function (n, quiet) {
			fs = n;
			$$('[data-fstep]', fw).forEach(function (s) { s.hidden = s.getAttribute('data-fstep') != n; });
			$$('.ch-stepper li', fw).forEach(function (li, i) { li.classList.toggle('is-done', i < n - 1); if (i === n - 1) { li.setAttribute('aria-current', 'step'); } else { li.removeAttribute('aria-current'); } });
			fp.hidden = n === 1;
			fn.textContent = n < 3 ? T.next : T.startScan;
			var h = $('[data-fstep="' + n + '"] h3', fw), s = $('[data-fstep="' + n + '"]', fw);
			if (quiet) { return; }
			if (h) { say(h.textContent); } if (s) { s.focus({ preventScroll: true }); }
		};
		fn.addEventListener('click', function () {
			if (fs < 3) { fshow(fs + 1); return; }
			fn.disabled = true; fp.hidden = true;
			var media = d.getElementById('f-m').checked, tax = d.getElementById('f-t').checked, sik = ($('input[name=sik]:checked', fw) || {}).value || 'daily';
			var s = { frequency: sik, exclude_post_types: media ? [] : ['attachment'] };
			if (!tax) { s.term_taxonomies = []; }
			api('onboarding', { body: { settings: s, done: true } }).then(function () {
				return scanStart(function (res) {
					$('#scanbox').hidden = true;
					var done = d.getElementById('scandone'); done.hidden = false;
					var sum = $('[data-first-sum]', done), sv = res && res.summary && res.summary.severity;
					if (sum && sv) { sum.textContent = (sv.critical + sv.warning + sv.info) + ' ' + T.openIssues; }
					fn.hidden = true; say(T.scanDone);
				});
			}).catch(function (e) { fn.disabled = false; err(e); });
		});
		fp.addEventListener('click', function () { fshow(fs - 1); });
		fshow(1, true);
	}
})();
