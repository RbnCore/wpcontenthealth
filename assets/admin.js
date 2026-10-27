( function () {
	'use strict';
	var cfg = window.ContentHealthCfg || {};
	var t = cfg.i18n || {};

	function api( path, method, body ) {
		var opt = { method: method || 'GET', credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' } };
		if ( body ) { opt.body = JSON.stringify( body ); }
		return fetch( cfg.root + path, opt ).then( function ( r ) {
			return r.json().then( function ( j ) {
				if ( ! r.ok ) { throw new Error( ( j && j.message ) || t.error ); }
				return j;
			} );
		} );
	}

	function el( tag, text, cls ) {
		var n = document.createElement( tag );
		if ( text ) { n.textContent = text; }
		if ( cls ) { n.className = cls; }
		return n;
	}

	/* ---- Tarama ---- */
	var btn = document.getElementById( 'ch-scan-btn' );
	if ( btn ) {
		var cancel = document.getElementById( 'ch-cancel-btn' );
		var msg = document.getElementById( 'ch-scan-msg' );
		var bar = document.getElementById( 'ch-bar' );
		var box = document.querySelector( '.ch-progress' );
		var running = false;

		var show = function ( run ) {
			if ( ! run ) { return; }
			var pct = run.total ? Math.min( 100, Math.round( ( run.done / run.total ) * 100 ) ) : 0;
			box.hidden = false;
			box.setAttribute( 'aria-valuenow', String( pct ) );
			bar.style.width = pct + '%';
			msg.textContent = t.scanning + ' ' + run.done + ' / ' + run.total + ( run.stage ? ' — ' + run.stage : '' );
		};
		var loop = function ( first ) {
			var p = first ? api( 'scan', 'POST', { action: 'start' } ) : api( 'scan', 'POST', { action: 'step' } );
			p.then( function ( j ) {
				show( j.run );
				if ( j.run && 'running' === j.run.status && running ) {
					loop( false );
				} else {
					running = false;
					btn.disabled = false;
					cancel.hidden = true;
					if ( j.run && 'finished' === j.run.status ) {
						msg.textContent = t.done;
						window.location.reload();
					}
				}
			} ).catch( function ( e ) {
				running = false;
				btn.disabled = false;
				cancel.hidden = true;
				msg.textContent = e.message;
			} );
		};
		btn.addEventListener( 'click', function () {
			running = true;
			btn.disabled = true;
			cancel.hidden = false;
			loop( true );
		} );
		cancel.addEventListener( 'click', function () {
			running = false;
			api( 'scan', 'POST', { action: 'cancel' } ).then( function () {
				btn.disabled = false;
				cancel.hidden = true;
				msg.textContent = '';
				box.hidden = true;
			} );
		} );
	}

	/* ---- Düzeltme panelleri ---- */
	function render( out, res, dry ) {
		out.textContent = '';
		var head = el( 'p', null, 'ch-sum' );
		head.textContent = res.changed + ' ' + ( dry ? t.changed : t.applied ) + ' · ' + res.skipped + ' ' + t.skipped + ' · ' + res.errors + ' ' + t.errors;
		out.appendChild( head );
		( res.items || [] ).slice( 0, 15 ).forEach( function ( it ) {
			var d = el( 'div', null, 'ch-diff' );
			d.appendChild( el( 'strong', '#' + it.object_id + ( it.error ? ' — ' + it.error : '' ) ) );
			( it.changes || [] ).forEach( function ( c ) {
				( c.removed || [] ).slice( 0, 3 ).forEach( function ( l ) { d.appendChild( el( 'div', '− ' + l, 'ch-del' ) ); } );
				( c.added || [] ).slice( 0, 3 ).forEach( function ( l ) { d.appendChild( el( 'div', '+ ' + l, 'ch-add' ) ); } );
			} );
			out.appendChild( d );
		} );
		if ( ! dry && res.batch ) {
			out.appendChild( el( 'p', t.undo + ': ' + res.batch ) );
		}
	}
	document.querySelectorAll( '.ch-panel' ).forEach( function ( panel ) {
		var rule = panel.getAttribute( 'data-rule' );
		var opts = {};
		try { opts = JSON.parse( panel.getAttribute( 'data-opts' ) || '{}' ) || {}; } catch ( e ) { opts = {}; }
		var out = panel.querySelector( '.ch-result' );
		var dry = panel.querySelector( '.ch-dry' );
		var apply = panel.querySelector( '.ch-apply' );
		if ( ! dry ) { return; }
		var call = function ( isDry ) {
			var body = Object.assign( { rule: rule, dry_run: isDry }, opts );
			return api( 'fix', 'POST', body ).then( function ( res ) {
				render( out, res, isDry );
				apply.disabled = ! isDry || ! res.changed;
			} ).catch( function ( e ) { out.textContent = e.message; } );
		};
		dry.addEventListener( 'click', function () { call( true ); } );
		apply.addEventListener( 'click', function () {
			if ( window.confirm( t.confirm ) ) { call( false ); }
		} );
	} );

	/* ---- Geri alma ---- */
	document.querySelectorAll( '.ch-undo' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			b.disabled = true;
			api( 'undo', 'POST', { key: b.getAttribute( 'data-key' ), dry_run: false } ).then( function ( r ) {
				document.getElementById( 'ch-undo-result' ).textContent = t.undone + ': ' + r.reverted + ', ' + t.skipped + ': ' + r.skipped;
				window.setTimeout( function () { window.location.reload(); }, 800 );
			} ).catch( function ( e ) { document.getElementById( 'ch-undo-result' ).textContent = e.message; b.disabled = false; } );
		} );
	} );
}() );
