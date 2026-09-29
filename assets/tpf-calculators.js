/* Tools Paxforex — Forex calculators */
( function () {
	'use strict';

	var CFG = window.TPF_CFG || {};

	function esc( s ) {
		return String( s == null ? '' : s )
			.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
	}

	function toNumber( v ) {
		if ( v === '' || v === null || v === undefined ) { return null; }
		var n = parseFloat( String( v ).replace( ',', '.' ) );
		return isFinite( n ) ? n : null;
	}

	/* ---------------- widget ---------------- */

	function Calc( root ) {
		this.root = root;
		this.cfg = JSON.parse( root.querySelector( '[data-tpf-config]' ).textContent );
		this.L = this.cfg.labels;
		this.instruments = this.cfg.instruments;
		this.rates = this.cfg.rates && this.cfg.rates.rates ? this.cfg.rates.rates : { USD: 1 };
		this.quoteDate = this.cfg.rates ? this.cfg.rates.date : '';
		this.locale = 'ru' === this.cfg.lang ? 'ru-RU' : 'en-US';

		this.panels = [].slice.call( root.querySelectorAll( '[data-panel]' ) );
		this.bind();
		this.recalcAll();
	}

	/* ---------------- quotes ---------------- */

	Calc.prototype.rate = function ( from, to ) {
		if ( from === to ) { return 1; }
		var a = this.rates[ from ], b = this.rates[ to ];
		if ( ! a || ! b ) { return null; }
		return b / a;
	};

	Calc.prototype.price = function ( symbol ) {
		var m = this.instruments[ symbol ];
		if ( ! m ) { return null; }
		return this.rate( m.base, m.quote );
	};

	/* ---------------- formatting ---------------- */

	Calc.prototype.money = function ( value, currency ) {
		if ( value === null || ! isFinite( value ) ) { return '—'; }
		try {
			return new Intl.NumberFormat( this.locale, {
				style: 'currency', currency: currency,
				minimumFractionDigits: 2, maximumFractionDigits: 2
			} ).format( value );
		} catch ( e ) {
			return this.number( value, 2 ) + ' ' + currency;
		}
	};

	Calc.prototype.number = function ( value, digits ) {
		if ( value === null || ! isFinite( value ) ) { return '—'; }
		return new Intl.NumberFormat( this.locale, {
			minimumFractionDigits: digits, maximumFractionDigits: digits
		} ).format( value );
	};

	/* ---------------- events ---------------- */

	Calc.prototype.bind = function () {
		var self = this, root = this.root;

		root.addEventListener( 'click', function ( ev ) {
			var tab = ev.target.closest( '[data-tab]' );
			if ( tab ) {
				root.querySelectorAll( '[data-tab]' ).forEach( function ( b ) {
					b.setAttribute( 'aria-pressed', b === tab ? 'true' : 'false' );
				} );
				self.panels.forEach( function ( p ) {
					p.hidden = p.dataset.panel !== tab.dataset.tab;
				} );
				return;
			}
			if ( ev.target.closest( '[data-refresh]' ) ) {
				self.refresh( ev.target.closest( '[data-refresh]' ) );
			}
		} );

		function onChange( ev ) {
			var panel = ev.target.closest( '[data-panel]' );
			if ( ! panel || ! ev.target.matches( '[data-f]' ) ) { return; }
			if ( 'instrument' === ev.target.dataset.f ) { self.syncPrices( panel, true ); }
			self.calc( panel );
		}

		root.addEventListener( 'input', onChange );
		root.addEventListener( 'change', onChange );
	};

	Calc.prototype.fields = function ( panel ) {
		var out = {};
		panel.querySelectorAll( '[data-f]' ).forEach( function ( el ) {
			out[ el.dataset.f ] = el.value;
		} );
		return out;
	};

	/** Fill open/close price inputs and the "current price" hint. */
	Calc.prototype.syncPrices = function ( panel, force ) {
		var f = this.fields( panel );
		if ( ! f.instrument ) { return; }

		var m = this.instruments[ f.instrument ];
		var p = this.price( f.instrument );
		var hint = panel.querySelector( '[data-hint="price"]' );

		if ( hint ) {
			hint.textContent = this.L.price + ': ' + ( null === p ? '—' : this.number( p, m.digits ) );
		}

		[ 'open', 'close' ].forEach( function ( name ) {
			var el = panel.querySelector( '[data-f="' + name + '"]' );
			if ( ! el || null === p ) { return; }
			if ( force || '' === el.value ) {
				el.value = p.toFixed( m.digits );
				el.step = m.pip / 10;
			}
		} );
	};

	Calc.prototype.recalcAll = function () {
		var self = this;
		this.panels.forEach( function ( p ) {
			self.syncPrices( p, false );
			self.calc( p );
		} );
	};

	/* ---------------- calculations ---------------- */

	Calc.prototype.calc = function ( panel ) {
		var out = panel.querySelector( '[data-out]' );
		var details = panel.querySelector( '[data-details]' );
		var f = this.fields( panel );
		var res;

		switch ( panel.dataset.panel ) {
			case 'pip': res = this.calcPip( f ); break;
			case 'margin': res = this.calcMargin( f ); break;
			case 'profit': res = this.calcProfit( f ); break;
			case 'position': res = this.calcPosition( f ); break;
			case 'converter': res = this.calcConverter( f ); break;
			default: res = null;
		}

		if ( ! res ) {
			out.textContent = '—';
			out.className = 'tpf-calc__result-value tpf-calc__result-value--muted';
			details.innerHTML = '';
			return;
		}

		out.textContent = res.value;
		out.className = 'tpf-calc__result-value' + ( res.tone ? ' tpf-calc__result-value--' + res.tone : '' );
		details.innerHTML = ( res.details || [] ).map( function ( d ) {
			return '<li><span>' + esc( d[ 0 ] ) + '</span><span>' + esc( d[ 1 ] ) + '</span></li>';
		} ).join( '' );
	};

	Calc.prototype.noRate = function () {
		return { value: this.L.no_rate, tone: 'muted', details: [] };
	};

	Calc.prototype.calcPip = function ( f ) {
		var m = this.instruments[ f.instrument ], lots = toNumber( f.lots );
		if ( ! m || null === lots ) { return null; }

		var r = this.rate( m.quote, f.currency );
		if ( null === r ) { return this.noRate(); }

		var perLot = m.pip * m.contract * r;
		var details = [
			[ this.L.pip_value + ' (' + this.L.per_lot + ')', this.money( perLot, f.currency ) ],
			[ this.L.contract, this.number( m.contract * lots, 0 ) + ' ' + m.base ],
			[ this.L.pip_size, String( m.pip ) ]
		];
		if ( m.quote !== f.currency ) {
			details.push( [ this.L.rate + ' ' + m.quote + '/' + f.currency, this.number( r, 5 ) ] );
		}

		return { value: this.money( perLot * lots, f.currency ), details: details };
	};

	Calc.prototype.calcMargin = function ( f ) {
		var m = this.instruments[ f.instrument ];
		var lots = toNumber( f.lots ), lev = toNumber( f.leverage );
		if ( ! m || null === lots || ! lev ) { return null; }

		var rBase = this.rate( m.base, f.currency );
		var price = this.price( f.instrument );
		if ( null === rBase ) { return this.noRate(); }

		var notional = m.contract * lots;

		return {
			value: this.money( ( notional / lev ) * rBase, f.currency ),
			details: [
				[ this.L.position_value, this.number( notional, 0 ) + ' ' + m.base ],
				[ this.L.position_value + ' (' + f.currency + ')', this.money( notional * rBase, f.currency ) ],
				[ this.L.leverage, '1:' + lev ],
				[ this.L.price, null === price ? '—' : this.number( price, m.digits ) ]
			]
		};
	};

	Calc.prototype.calcProfit = function ( f ) {
		var m = this.instruments[ f.instrument ], lots = toNumber( f.lots );
		var open = toNumber( f.open ), close = toNumber( f.close );
		if ( ! m || null === lots || null === open || null === close ) { return null; }

		var r = this.rate( m.quote, f.currency );
		if ( null === r ) { return this.noRate(); }

		var diff = 'sell' === f.dir ? open - close : close - open;
		var pips = diff / m.pip;
		var gross = diff * m.contract * lots;
		var pl = gross * r;

		return {
			value: ( pl > 0 ? '+' : '' ) + this.money( pl, f.currency ),
			tone: pl > 0 ? 'up' : ( pl < 0 ? 'down' : '' ),
			details: [
				[ this.L.pips, ( pips > 0 ? '+' : '' ) + this.number( pips, 1 ) ],
				[ this.L.pip_value, this.money( m.pip * m.contract * lots * r, f.currency ) ],
				[ this.L.direction, 'sell' === f.dir ? this.L.sell : this.L.buy ],
				[ this.L.contract, this.number( m.contract * lots, 0 ) + ' ' + m.base ]
			]
		};
	};

	Calc.prototype.calcPosition = function ( f ) {
		var m = this.instruments[ f.instrument ];
		var balance = toNumber( f.balance ), risk = toNumber( f.risk ), sl = toNumber( f.sl );
		if ( ! m || null === balance || null === risk || ! sl ) { return null; }

		var r = this.rate( m.quote, f.currency );
		if ( null === r ) { return this.noRate(); }

		var riskAmount = balance * risk / 100;
		var pipPerLot = m.pip * m.contract * r;
		var lots = riskAmount / ( sl * pipPerLot );

		return {
			value: this.number( lots, 2 ) + ' ' + this.L.lots,
			details: [
				[ this.L.risk_amount, this.money( riskAmount, f.currency ) ],
				[ this.L.pip_value + ' (' + this.L.per_lot + ')', this.money( pipPerLot, f.currency ) ],
				[ this.L.units, this.number( lots * m.contract, 0 ) + ' ' + m.base ],
				[ this.L.stop_loss, this.number( sl, 0 ) ]
			]
		};
	};

	Calc.prototype.calcConverter = function ( f ) {
		var amount = toNumber( f.amount );
		if ( null === amount || ! f.from || ! f.to ) { return null; }

		var r = this.rate( f.from, f.to );
		if ( null === r ) { return this.noRate(); }

		return {
			value: this.money( amount * r, f.to ),
			details: [
				[ '1 ' + f.from, this.number( r, 5 ) + ' ' + f.to ],
				[ '1 ' + f.to, this.number( 1 / r, 5 ) + ' ' + f.from ],
				[ this.L.quotes, this.quoteDate || '—' ]
			]
		};
	};

	/* ---------------- quotes refresh ---------------- */

	Calc.prototype.refresh = function ( btn ) {
		if ( ! CFG.ajax ) { return; }
		var self = this;
		if ( btn ) { btn.dataset.busy = '1'; }

		fetch( CFG.ajax + '?action=tpf_rates&nonce=' + encodeURIComponent( CFG.nonce ) + '&force=1', { credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( json ) {
				if ( json && json.success && json.data && json.data.rates ) {
					self.rates = json.data.rates;
					self.quoteDate = json.data.date || '';
					var meta = self.root.querySelector( '[data-tpf-meta]' );
					if ( meta ) { meta.textContent = self.L.quotes + ': ' + ( self.quoteDate || 'n/a' ); }
					self.recalcAll();
				}
			} )
			.catch( function () { /* ignore network errors */ } )
			.then( function () { if ( btn ) { delete btn.dataset.busy; } } );
	};

	/* ---------------- bootstrap ---------------- */

	function init() {
		document.querySelectorAll( '[data-tpf-calc]' ).forEach( function ( el ) {
			if ( el.dataset.tpfCalcReady ) { return; }
			el.dataset.tpfCalcReady = '1';
			try { new Calc( el ); } catch ( e ) { if ( window.console ) { console.error( 'TPF calculators:', e ); } }
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
