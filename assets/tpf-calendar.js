/* Tools Paxforex — economic calendar */
( function () {
	'use strict';

	var CFG = window.TPF_CFG || {};

	var ZONES = [
		'UTC',
		'Europe/London', 'Europe/Berlin', 'Europe/Kyiv', 'Europe/Moscow',
		'Asia/Tbilisi', 'Asia/Dubai', 'Asia/Shanghai', 'Asia/Tokyo',
		'America/New_York', 'America/Chicago', 'America/Los_Angeles',
		'Australia/Sydney'
	];

	var IMPACTS = [ 'high', 'medium', 'low', 'holiday' ];
	var VIEWS = [ 'yesterday', 'today', 'tomorrow', 'week' ];

	var FLAGS = {
		USD: 'US', EUR: 'EU', GBP: 'GB', JPY: 'JP', CHF: 'CH', CAD: 'CA', AUD: 'AU',
		NZD: 'NZ', CNY: 'CN', HKD: 'HK', SGD: 'SG', SEK: 'SE', NOK: 'NO', DKK: 'DK',
		PLN: 'PL', TRY: 'TR', ZAR: 'ZA', MXN: 'MX', BRL: 'BR', INR: 'IN', RUB: 'RU',
		KRW: 'KR', ILS: 'IL', CZK: 'CZ', HUF: 'HU'
	};

	/* ---------------- helpers ---------------- */

	function esc( s ) {
		return String( s == null ? '' : s )
			.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
	}

	function flag( cur ) {
		var cc = FLAGS[ cur ];
		if ( ! cc ) { return '🏳'; }
		return String.fromCodePoint( 127397 + cc.charCodeAt( 0 ), 127397 + cc.charCodeAt( 1 ) );
	}

	function pad( n ) { return ( n < 10 ? '0' : '' ) + n; }

	function store( key, val ) {
		try {
			if ( val === undefined ) {
				var raw = window.localStorage.getItem( 'tpf_cal_' + key );
				return raw ? JSON.parse( raw ) : null;
			}
			window.localStorage.setItem( 'tpf_cal_' + key, JSON.stringify( val ) );
		} catch ( e ) { /* private mode — ignore */ }
		return null;
	}

	/** Parse a number out of strings like "1.4%", "-2.3K", "245B". */
	function num( str ) {
		if ( ! str ) { return null; }
		var m = String( str ).replace( /,/g, '' ).match( /-?\d+(\.\d+)?/ );
		if ( ! m ) { return null; }
		var v = parseFloat( m[ 0 ] );
		if ( /k/i.test( str ) ) { v *= 1e3; }
		else if ( /m/i.test( str ) ) { v *= 1e6; }
		else if ( /b/i.test( str ) ) { v *= 1e9; }
		else if ( /t/i.test( str ) ) { v *= 1e12; }
		return v;
	}

	/* ---------------- time zones ---------------- */

	function Zone( def ) {
		this.set( def );
		this.cache = {};
	}

	Zone.prototype.set = function ( def ) {
		this.kind = def.kind;
		this.name = def.name || '';
		this.fixed = typeof def.minutes === 'number' ? def.minutes : 0;
		this.cache = {};
	};

	/** Zone offset in minutes for a given timestamp. */
	Zone.prototype.offset = function ( ts ) {
		if ( 'local' === this.kind ) {
			return -new Date( ts * 1000 ).getTimezoneOffset();
		}
		if ( 'offset' === this.kind ) {
			return this.fixed;
		}
		var bucket = Math.floor( ts / 3600 );
		if ( this.cache[ bucket ] !== undefined ) { return this.cache[ bucket ]; }

		var off = 0;
		try {
			var d = new Date( ts * 1000 );
			var dtf = new Intl.DateTimeFormat( 'en-US', {
				timeZone: this.name, hour12: false,
				year: 'numeric', month: '2-digit', day: '2-digit',
				hour: '2-digit', minute: '2-digit', second: '2-digit'
			} );
			var p = {};
			dtf.formatToParts( d ).forEach( function ( x ) { p[ x.type ] = x.value; } );
			var asUTC = Date.UTC( +p.year, p.month - 1, +p.day, '24' === p.hour ? 0 : +p.hour, +p.minute, +p.second );
			off = Math.round( ( asUTC - d.getTime() ) / 60000 );
		} catch ( e ) { off = 0; }

		this.cache[ bucket ] = off;
		return off;
	};

	/** Timestamp broken into date parts in the selected zone. */
	Zone.prototype.parts = function ( ts ) {
		var d = new Date( ( ts + this.offset( ts ) * 60 ) * 1000 );
		return {
			y: d.getUTCFullYear(), m: d.getUTCMonth() + 1, d: d.getUTCDate(),
			H: d.getUTCHours(), M: d.getUTCMinutes(), wd: d.getUTCDay()
		};
	};

	Zone.prototype.dayKey = function ( ts ) {
		var p = this.parts( ts );
		return p.y + '-' + pad( p.m ) + '-' + pad( p.d );
	};

	Zone.prototype.time = function ( ts ) {
		var p = this.parts( ts );
		return pad( p.H ) + ':' + pad( p.M );
	};

	Zone.prototype.label = function () {
		if ( 'local' === this.kind ) {
			var g = '';
			try { g = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch ( e ) {}
			return g || 'local';
		}
		if ( 'offset' === this.kind ) {
			var s = this.fixed < 0 ? '-' : '+', a = Math.abs( this.fixed );
			return 'UTC' + s + pad( Math.floor( a / 60 ) ) + ':' + pad( a % 60 );
		}
		return this.name;
	};

	/* ---------------- widget ---------------- */

	function Calendar( root ) {
		this.root = root;
		this.cfg = JSON.parse( root.querySelector( '[data-tpf-config]' ).textContent );
		this.events = JSON.parse( root.querySelector( '[data-tpf-data]' ).textContent );
		this.L = this.cfg.labels;
		this.ru = 'en' !== this.cfg.lang;

		var saved = store( 'prefs' ) || {};

		this.state = {
			view: this.cfg.view,
			impacts: saved.impacts && saved.impacts.length ? saved.impacts.slice() : this.cfg.impacts.slice(),
			currencies: this.cfg.currencies.slice(),
			query: '',
			updated: this.cfg.updated
		};

		var z = this.cfg.tz;
		var def = { kind: 'offset', minutes: 0 };
		if ( 'local' === z.offset ) { def = { kind: 'local' }; }
		else if ( z.name ) { def = { kind: 'iana', name: z.name }; }
		else if ( typeof z.offset === 'number' ) { def = { kind: 'offset', minutes: z.offset }; }

		if ( saved.zone ) {
			def = 'local' === saved.zone ? { kind: 'local' } : { kind: 'iana', name: saved.zone };
		}
		this.zone = new Zone( def );
		this.savedZone = saved.zone || null;

		this.body = root.querySelector( '[data-tpf-body]' );
		this.meta = root.querySelector( '[data-tpf-meta]' );

		this.buildToolbar();
		this.render();

		var self = this;
		this.tick = setInterval( function () { self.render(); }, 60000 );

		if ( this.cfg.refresh > 0 ) {
			this.timer = setInterval( function () { self.refresh( false ); }, this.cfg.refresh * 1000 );
		}
	}

	Calendar.prototype.allCurrencies = function () {
		var seen = {}, out = [];
		this.events.forEach( function ( e ) {
			if ( e.c && ! seen[ e.c ] ) { seen[ e.c ] = 1; out.push( e.c ); }
		} );
		return out.sort();
	};

	/* ---------------- toolbar ---------------- */

	Calendar.prototype.buildToolbar = function () {
		var bar = this.root.querySelector( '[data-tpf-toolbar]' );
		if ( ! bar || ! this.cfg.filters ) { return; }

		var self = this, L = this.L, html = '';

		html += '<div class="tpf__tabs" role="group">';
		VIEWS.forEach( function ( v ) {
			html += '<button type="button" class="tpf__tab" data-view="' + v + '" aria-pressed="' +
				( self.state.view === v ? 'true' : 'false' ) + '">' + esc( L[ v ] ) + '</button>';
		} );
		html += '</div>';

		html += '<div class="tpf__chips">';
		IMPACTS.forEach( function ( i ) {
			if ( 'holiday' === i ) { return; }
			html += '<button type="button" class="tpf__chip tpf__chip--' + i + '" data-impact="' + i + '" aria-pressed="' +
				( self.state.impacts.indexOf( i ) > -1 ? 'true' : 'false' ) + '">' + esc( L[ i ] ) + '</button>';
		} );
		html += '</div>';

		html += '<div class="tpf__chips">';
		this.allCurrencies().forEach( function ( c ) {
			var on = ! self.state.currencies.length || self.state.currencies.indexOf( c ) > -1;
			html += '<button type="button" class="tpf__chip" data-cur="' + esc( c ) + '" aria-pressed="' +
				( on ? 'true' : 'false' ) + '">' + esc( c ) + '</button>';
		} );
		html += '</div>';

		html += '<span class="tpf__spacer"></span>';

		if ( this.cfg.search ) {
			html += '<input type="search" class="tpf__search" data-search placeholder="' + esc( L.search ) + '" aria-label="' + esc( L.search ) + '">';
		}

		html += '<select class="tpf__select" data-zone aria-label="' + esc( L.tz ) + '">';
		html += '<option value="local">' + esc( L.tz_local ) + '</option>';
		if ( 'iana' === this.zone.kind && ZONES.indexOf( this.zone.name ) === -1 ) {
			html += '<option value="' + esc( this.zone.name ) + '">' + esc( this.zone.name ) + '</option>';
		}
		if ( 'offset' === this.zone.kind ) {
			html += '<option value="__fixed">' + esc( this.zone.label() ) + '</option>';
		}
		ZONES.forEach( function ( z ) {
			html += '<option value="' + z + '">' + z.replace( '_', ' ' ) + '</option>';
		} );
		html += '</select>';

		html += '<button type="button" class="tpf__btn" data-refresh>' + esc( L.refresh ) + '</button>';

		bar.innerHTML = html;

		// preselect the active zone
		var sel = bar.querySelector( '[data-zone]' );
		sel.value = 'local' === this.zone.kind ? 'local' : ( 'offset' === this.zone.kind ? '__fixed' : this.zone.name );

		bar.addEventListener( 'click', function ( ev ) {
			var t = ev.target.closest( 'button' );
			if ( ! t ) { return; }

			if ( t.dataset.view ) {
				self.state.view = t.dataset.view;
				bar.querySelectorAll( '[data-view]' ).forEach( function ( b ) {
					b.setAttribute( 'aria-pressed', b === t ? 'true' : 'false' );
				} );
				self.render();
			} else if ( t.dataset.impact ) {
				var i = t.dataset.impact, k = self.state.impacts.indexOf( i );
				if ( k > -1 ) { self.state.impacts.splice( k, 1 ); } else { self.state.impacts.push( i ); }
				t.setAttribute( 'aria-pressed', k > -1 ? 'false' : 'true' );
				self.persist();
				self.render();
			} else if ( t.dataset.cur ) {
				var all = self.allCurrencies();
				var cur = self.state.currencies.length ? self.state.currencies.slice() : all.slice();
				var j = cur.indexOf( t.dataset.cur );
				if ( j > -1 ) { cur.splice( j, 1 ); } else { cur.push( t.dataset.cur ); }
				self.state.currencies = cur.length === all.length ? [] : cur;
				var active = self.state.currencies;
				bar.querySelectorAll( '[data-cur]' ).forEach( function ( b ) {
					var on = ! active.length || active.indexOf( b.dataset.cur ) > -1;
					b.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
				} );
				self.render();
			} else if ( 'refresh' in t.dataset ) {
				self.refresh( true, t );
			}
		} );

		bar.addEventListener( 'change', function ( ev ) {
			if ( ! ev.target.matches( '[data-zone]' ) ) { return; }
			var v = ev.target.value;
			if ( 'local' === v ) {
				self.zone.set( { kind: 'local' } );
				self.savedZone = 'local';
			} else if ( '__fixed' !== v ) {
				self.zone.set( { kind: 'iana', name: v } );
				self.savedZone = v;
			}
			self.persist();
			self.render();
		} );

		if ( this.cfg.search ) {
			var input = bar.querySelector( '[data-search]' ), t = null;
			input.addEventListener( 'input', function () {
				clearTimeout( t );
				t = setTimeout( function () {
					self.state.query = input.value.trim().toLowerCase();
					self.render();
				}, 200 );
			} );
		}
	};

	Calendar.prototype.persist = function () {
		store( 'prefs', { impacts: this.state.impacts, zone: this.savedZone } );
	};

	/* ---------------- rendering ---------------- */

	Calendar.prototype.filtered = function () {
		var s = this.state, zone = this.zone, now = Math.floor( Date.now() / 1000 );
		var target = null;

		if ( 'week' !== s.view ) {
			var shift = 'tomorrow' === s.view ? 86400 : ( 'yesterday' === s.view ? -86400 : 0 );
			target = zone.dayKey( now + shift );
		}

		var out = this.events.filter( function ( e ) {
			if ( s.impacts.indexOf( e.i ) === -1 ) { return false; }
			if ( s.currencies.length && s.currencies.indexOf( e.c ) === -1 ) { return false; }
			if ( s.query && e.e.toLowerCase().indexOf( s.query ) === -1 ) { return false; }
			if ( target && zone.dayKey( e.t ) !== target ) { return false; }
			return true;
		} );

		return this.cfg.limit ? out.slice( 0, this.cfg.limit ) : out;
	};

	Calendar.prototype.dayTitle = function ( ts, todayKey ) {
		var p = this.zone.parts( ts );
		var title = this.L.weekdays[ p.wd ] + ', ' + pad( p.d ) + ' ' + this.L.months[ p.m - 1 ];
		if ( this.zone.dayKey( ts ) === todayKey ) { title += ' · ' + this.L.today; }
		return title;
	};

	Calendar.prototype.countdown = function ( sec ) {
		if ( sec <= 60 ) { return this.L.now; }
		var h = Math.floor( sec / 3600 ), m = Math.floor( ( sec % 3600 ) / 60 );
		var u = this.ru ? [ 'ч', 'м' ] : [ 'h', 'm' ];
		return this.L.in + ' ' + ( h ? h + u[ 0 ] + ' ' : '' ) + m + u[ 1 ];
	};

	Calendar.prototype.render = function () {
		var L = this.L, zone = this.zone, now = Math.floor( Date.now() / 1000 );
		var list = this.filtered();

		if ( ! list.length ) {
			this.body.innerHTML = '<p class="tpf__empty">' + esc( L.empty ) + '</p>';
			this.renderMeta();
			return;
		}

		var todayKey = zone.dayKey( now );
		var html = '<table class="tpf-cal__table"><thead><tr>' +
			'<th class="tpf-col-time">' + esc( L.time ) + '</th>' +
			'<th class="tpf-col-cur">' + esc( L.currency ) + '</th>' +
			'<th class="tpf-col-imp">' + esc( L.impact ) + '</th>' +
			'<th class="tpf-col-event">' + esc( L.event ) + '</th>' +
			'<th class="tpf-col-num">' + esc( L.actual ) + '</th>' +
			'<th class="tpf-col-num">' + esc( L.forecast ) + '</th>' +
			'<th class="tpf-col-num">' + esc( L.previous ) + '</th>' +
			'</tr></thead><tbody>';

		var lastDay = null, nextMarked = false, self = this;

		list.forEach( function ( e ) {
			var key = zone.dayKey( e.t );
			if ( key !== lastDay ) {
				lastDay = key;
				html += '<tr class="tpf-cal__daysep"><td colspan="7">' + esc( self.dayTitle( e.t, todayKey ) ) + '</td></tr>';
			}

			var past = e.t < now;
			var isNext = ! past && ! nextMarked;
			if ( isNext ) { nextMarked = true; }

			var a = num( e.a ), f = num( e.f ), cls = '';
			if ( a !== null && f !== null ) { cls = a > f ? ' tpf-actual--up' : ( a < f ? ' tpf-actual--down' : '' ); }

			html += '<tr class="tpf-cal__row tpf-cal__row--' + e.i + ( past ? ' tpf-cal__row--past' : '' ) + ( isNext ? ' tpf-cal__row--next' : '' ) + '">' +
				'<td class="tpf-col-time">' + zone.time( e.t ) + '</td>' +
				'<td class="tpf-col-cur"><span class="tpf-flag">' + flag( e.c ) + '</span><span class="tpf-cur">' + esc( e.c ) + '</span></td>' +
				'<td class="tpf-col-imp"><span class="tpf-imp tpf-imp--' + e.i + '" title="' + esc( L[ e.i ] || '' ) + '"><i></i><i></i><i></i></span></td>' +
				'<td class="tpf-col-event">' + esc( e.e ) +
					( isNext ? '<span class="tpf-cal__countdown">' + esc( self.countdown( e.t - now ) ) + '</span>' : '' ) + '</td>' +
				'<td class="tpf-col-num tpf-actual' + cls + '" data-label="' + esc( L.actual ) + '">' + esc( e.a || '—' ) + '</td>' +
				'<td class="tpf-col-num tpf-forecast" data-label="' + esc( L.forecast ) + '">' + esc( e.f || '—' ) + '</td>' +
				'<td class="tpf-col-num tpf-prev" data-label="' + esc( L.previous ) + '">' + esc( e.p || '—' ) + '</td>' +
				'</tr>';
		} );

		html += '</tbody></table>';
		this.body.innerHTML = html;
		this.renderMeta();
	};

	Calendar.prototype.renderMeta = function () {
		if ( ! this.meta ) { return; }
		var upd = this.zone.time( this.state.updated );
		this.meta.textContent = this.L.tz + ': ' + this.zone.label() + ' · ' + this.L.updated + ': ' + upd;
	};

	/* ---------------- data refresh ---------------- */

	Calendar.prototype.refresh = function ( force, btn ) {
		if ( ! CFG.ajax ) { return; }
		var self = this;
		if ( btn ) { btn.dataset.busy = '1'; }

		var url = CFG.ajax + '?action=tpf_events&nonce=' + encodeURIComponent( CFG.nonce ) + ( force ? '&force=1' : '' );

		fetch( url, { credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( json ) {
				if ( json && json.success && json.data && json.data.events ) {
					self.events = json.data.events;
					self.state.updated = json.data.updated;
					self.render();
				}
			} )
			.catch( function () { /* ignore network errors */ } )
			.then( function () { if ( btn ) { delete btn.dataset.busy; } } );
	};

	/* ---------------- bootstrap ---------------- */

	function init() {
		document.querySelectorAll( '[data-tpf-cal]' ).forEach( function ( el ) {
			if ( el.dataset.tpfCalReady ) { return; }
			el.dataset.tpfCalReady = '1';
			try { new Calendar( el ); } catch ( e ) { if ( window.console ) { console.error( 'TPF calendar:', e ); } }
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
