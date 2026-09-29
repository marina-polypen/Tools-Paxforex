<?php
/**
 * Forex calculators component.
 * Shortcode: [paxforex_calculators]
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TPF_Calculators {

	/** @var TPF_Plugin */
	private $plugin;

	/** @var bool */
	private $enqueued = false;

	const TOOLS = array( 'pip', 'margin', 'profit', 'position', 'converter' );

	public function __construct( $plugin ) {
		$this->plugin = $plugin;

		add_action( 'init', array( $this, 'register_shortcodes' ) );
		add_action( 'wp_ajax_tpf_rates', array( $this, 'ajax_rates' ) );
		add_action( 'wp_ajax_nopriv_tpf_rates', array( $this, 'ajax_rates' ) );
	}

	public function register_shortcodes() {
		add_shortcode( 'paxforex_calculators', array( $this, 'shortcode' ) );
		add_shortcode( 'forex_calculators', array( $this, 'shortcode' ) );
	}

	/* ---------------------------------------------------------------------
	 * Instruments
	 * ------------------------------------------------------------------ */

	public static function default_instruments_string() {
		return 'EURUSD, GBPUSD, USDJPY, USDCHF, USDCAD, AUDUSD, NZDUSD, EURJPY, EURGBP, '
			. 'GBPJPY, AUDJPY, CADJPY, CHFJPY, EURAUD, EURCHF, GBPCHF, NZDJPY, '
			. 'USDSGD, USDZAR, USDTRY, USDMXN, USDPLN, USDSEK, USDNOK, XAUUSD, XAGUSD';
	}

	public static function sanitize_instruments( $value ) {
		$parts = preg_split( '/[\s,;]+/', (string) $value );
		$out   = array();
		foreach ( (array) $parts as $p ) {
			$meta = self::meta( $p );
			if ( $meta && ! in_array( $meta['symbol'], $out, true ) ) {
				$out[] = $meta['symbol'];
			}
		}
		if ( empty( $out ) ) {
			return self::default_instruments_string();
		}
		return implode( ', ', $out );
	}

	/**
	 * Contract specification for a symbol.
	 *
	 * @return array|null symbol, base, quote, contract, pip, digits
	 */
	public static function meta( $symbol ) {
		$symbol = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $symbol ) );
		if ( 6 !== strlen( $symbol ) ) {
			return null;
		}

		$base  = substr( $symbol, 0, 3 );
		$quote = substr( $symbol, 3, 3 );
		$known = TPF_Rates::supported();

		if ( ! in_array( $base, $known, true ) || ! in_array( $quote, $known, true ) || $base === $quote ) {
			return null;
		}

		if ( 'XAU' === $base ) {
			$contract = 100;
			$pip      = 0.01;
			$digits   = 2;
		} elseif ( 'XAG' === $base ) {
			$contract = 5000;
			$pip      = 0.001;
			$digits   = 3;
		} else {
			$contract = 100000;
			$pip      = ( 'JPY' === $quote ) ? 0.01 : 0.0001;
			$digits   = ( 'JPY' === $quote ) ? 3 : 5;
		}

		return array(
			'symbol'   => $symbol,
			'base'     => $base,
			'quote'    => $quote,
			'contract' => $contract,
			'pip'      => $pip,
			'digits'   => $digits,
		);
	}

	private function instrument_list( $raw ) {
		$list = array();
		foreach ( preg_split( '/[\s,;]+/', (string) $raw ) as $sym ) {
			$meta = self::meta( $sym );
			if ( $meta && ! isset( $list[ $meta['symbol'] ] ) ) {
				$list[ $meta['symbol'] ] = $meta;
			}
		}
		if ( empty( $list ) ) {
			foreach ( preg_split( '/[\s,;]+/', self::default_instruments_string() ) as $sym ) {
				$meta = self::meta( $sym );
				if ( $meta ) {
					$list[ $meta['symbol'] ] = $meta;
				}
			}
		}
		return $list;
	}

	public function ajax_rates() {
		check_ajax_referer( 'tpf', 'nonce' );

		$force = isset( $_GET['force'] ) && '1' === $_GET['force'];
		if ( $force && get_transient( 'tpf_force_lock' ) ) {
			$force = false;
		}
		if ( $force ) {
			set_transient( 'tpf_force_lock', 1, MINUTE_IN_SECONDS );
		}

		wp_send_json_success( TPF_Rates::instance( $this->plugin )->get( $force ) );
	}

	/* ---------------------------------------------------------------------
	 * Shortcode
	 * ------------------------------------------------------------------ */

	public function shortcode( $atts ) {
		$s = $this->plugin->settings();

		$atts = shortcode_atts(
			array(
				'tools'       => implode( ',', self::TOOLS ),
				'instruments' => $s['instruments'],
				'instrument'  => '',
				'currency'    => $s['base_currency'],
				'leverage'    => $s['leverage'],
				'lots'        => '1',
				'theme'       => $s['theme'],
				'lang'        => $s['lang'],
				'title'       => '',
			),
			$atts,
			'paxforex_calculators'
		);

		if ( ! $this->enqueued ) {
			$this->enqueued = true;
			wp_enqueue_style( 'tpf-calculators' );
			wp_enqueue_script( 'tpf-calculators' );
			$this->plugin->localize( 'tpf-calculators' );
		}

		$lang   = ( 'ru' === $atts['lang'] ) ? 'ru' : 'en';
		$L      = self::labels( $lang );
		$rates  = TPF_Rates::instance( $this->plugin )->get();
		$instr  = $this->instrument_list( $atts['instruments'] );
		$syms   = array_keys( $instr );

		$tools = array_values(
			array_intersect(
				array_map( 'trim', explode( ',', strtolower( $atts['tools'] ) ) ),
				self::TOOLS
			)
		);
		if ( empty( $tools ) ) {
			$tools = self::TOOLS;
		}

		$selected = strtoupper( preg_replace( '/[^A-Za-z]/', '', $atts['instrument'] ) );
		if ( ! isset( $instr[ $selected ] ) ) {
			$selected = $syms[0];
		}

		$currency = strtoupper( preg_replace( '/[^A-Za-z]/', '', $atts['currency'] ) );
		$fiat     = array_values( array_diff( TPF_Rates::supported(), array( 'XAU', 'XAG' ) ) );
		if ( ! in_array( $currency, $fiat, true ) ) {
			$currency = 'USD';
		}

		$leverage = max( 1, (int) $atts['leverage'] );
		$lots     = max( 0.01, (float) str_replace( ',', '.', $atts['lots'] ) );

		$uid = 'tpf-calc-' . wp_generate_password( 6, false, false );

		$config = array(
			'instruments' => $instr,
			'rates'       => $rates,
			'currencies'  => $fiat,
			'defaults'    => array(
				'instrument' => $selected,
				'currency'   => $currency,
				'leverage'   => $leverage,
				'lots'       => $lots,
			),
			'tools'       => $tools,
			'lang'        => $lang,
			'labels'      => $L,
		);

		ob_start();
		?>
		<div id="<?php echo esc_attr( $uid ); ?>"
			class="tpf tpf--<?php echo esc_attr( 'dark' === $atts['theme'] ? 'dark' : 'light' ); ?> tpf-calc"
			data-tpf-calc="1">

			<?php if ( $atts['title'] ) : ?>
				<h2 class="tpf__title"><?php echo esc_html( $atts['title'] ); ?></h2>
			<?php endif; ?>

			<div class="tpf__toolbar">
				<div class="tpf__tabs" role="tablist">
					<?php foreach ( $tools as $i => $tool ) : ?>
						<button type="button" class="tpf__tab" role="tab"
							data-tab="<?php echo esc_attr( $tool ); ?>"
							aria-pressed="<?php echo 0 === $i ? 'true' : 'false'; ?>">
							<?php echo esc_html( $L[ 'tab_' . $tool ] ); ?>
						</button>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="tpf-calc__panels">
				<?php foreach ( $tools as $i => $tool ) : ?>
					<section class="tpf-calc__panel" data-panel="<?php echo esc_attr( $tool ); ?>"<?php echo 0 === $i ? '' : ' hidden'; ?>>
						<div class="tpf-calc__form">
							<?php $this->render_fields( $tool, $L, $instr, $selected, $fiat, $currency, $leverage, $lots ); ?>
						</div>
						<div class="tpf-calc__result">
							<span class="tpf-calc__result-label"><?php echo esc_html( $L[ 'out_' . $tool ] ); ?></span>
							<strong class="tpf-calc__result-value" data-out>—</strong>
							<ul class="tpf-calc__details" data-details></ul>
						</div>
					</section>
				<?php endforeach; ?>
			</div>

			<div class="tpf__footer">
				<span class="tpf__meta" data-tpf-meta>
					<?php echo esc_html( $L['quotes'] . ': ' . ( $rates['date'] ? $rates['date'] : 'n/a' ) ); ?>
				</span>
				<button type="button" class="tpf__btn tpf__btn--small" data-refresh><?php echo esc_html( $L['refresh'] ); ?></button>
			</div>

			<script type="application/json" data-tpf-config><?php
				echo wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE );
			?></script>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * Form markup
	 * ------------------------------------------------------------------ */

	private function render_fields( $tool, $L, $instr, $selected, $fiat, $currency, $leverage, $lots ) {
		switch ( $tool ) {
			case 'pip':
				$this->f_instrument( $L, $instr, $selected );
				$this->f_number( $L['volume'], 'lots', $lots, '0.01', '0.01' );
				$this->f_currency( $L, $fiat, $currency );
				break;

			case 'margin':
				$this->f_instrument( $L, $instr, $selected );
				$this->f_number( $L['volume'], 'lots', $lots, '0.01', '0.01' );
				$this->f_leverage( $L, $leverage );
				$this->f_currency( $L, $fiat, $currency );
				break;

			case 'profit':
				$this->f_instrument( $L, $instr, $selected );
				$this->f_direction( $L );
				$this->f_number( $L['volume'], 'lots', $lots, '0.01', '0.01' );
				$this->f_number( $L['open_price'], 'open', '', 'any', '0' );
				$this->f_number( $L['close_price'], 'close', '', 'any', '0' );
				$this->f_currency( $L, $fiat, $currency );
				break;

			case 'position':
				$this->f_instrument( $L, $instr, $selected );
				$this->f_currency( $L, $fiat, $currency );
				$this->f_number( $L['balance'], 'balance', '10000', '1', '0' );
				$this->f_number( $L['risk'], 'risk', '2', '0.1', '0.1' );
				$this->f_number( $L['stop_loss'], 'sl', '50', '1', '1' );
				break;

			case 'converter':
				$this->f_number( $L['amount'], 'amount', '1000', 'any', '0' );
				$this->f_select( $L['from'], 'from', $this->currency_options( $fiat, 'USD' ) );
				$this->f_select( $L['to'], 'to', $this->currency_options( $fiat, $currency && 'USD' !== $currency ? $currency : 'EUR' ) );
				break;
		}
	}

	private function currency_options( $fiat, $selected ) {
		$out = array();
		foreach ( $fiat as $c ) {
			$out[ $c ] = $c;
		}
		return array( $out, $selected );
	}

	private function f_instrument( $L, $instr, $selected ) {
		$opts = array();
		foreach ( $instr as $sym => $meta ) {
			$opts[ $sym ] = substr( $sym, 0, 3 ) . '/' . substr( $sym, 3, 3 );
		}
		$this->f_select( $L['instrument'], 'instrument', array( $opts, $selected ), $L['price'] );
	}

	private function f_currency( $L, $fiat, $currency ) {
		$this->f_select( $L['account_currency'], 'currency', $this->currency_options( $fiat, $currency ) );
	}

	private function f_leverage( $L, $leverage ) {
		$opts = array();
		foreach ( array( 1, 5, 10, 20, 30, 50, 100, 200, 300, 400, 500, 1000 ) as $l ) {
			$opts[ $l ] = '1:' . $l;
		}
		if ( ! isset( $opts[ $leverage ] ) ) {
			$opts[ $leverage ] = '1:' . $leverage;
			ksort( $opts, SORT_NUMERIC );
		}
		$this->f_select( $L['leverage'], 'leverage', array( $opts, $leverage ) );
	}

	private function f_direction( $L ) {
		$this->f_select( $L['direction'], 'dir', array( array( 'buy' => $L['buy'], 'sell' => $L['sell'] ), 'buy' ) );
	}

	private function f_select( $label, $name, $options, $hint = '' ) {
		list( $opts, $selected ) = $options;
		$id = 'tpf-' . $name . '-' . wp_generate_password( 4, false, false );
		echo '<label class="tpf-calc__field">';
		echo '<span class="tpf-calc__label">' . esc_html( $label ) . '</span>';
		echo '<select class="tpf__select" id="' . esc_attr( $id ) . '" data-f="' . esc_attr( $name ) . '">';
		foreach ( $opts as $value => $text ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( (string) $value, (string) $selected, false ) . '>' . esc_html( $text ) . '</option>';
		}
		echo '</select>';
		if ( $hint ) {
			echo '<span class="tpf-calc__hint" data-hint="price">' . esc_html( $hint ) . ': —</span>';
		}
		echo '</label>';
	}

	private function f_number( $label, $name, $value, $step = 'any', $min = '' ) {
		$id = 'tpf-' . $name . '-' . wp_generate_password( 4, false, false );
		echo '<label class="tpf-calc__field">';
		echo '<span class="tpf-calc__label">' . esc_html( $label ) . '</span>';
		echo '<input class="tpf__input" type="number" inputmode="decimal" id="' . esc_attr( $id ) . '"'
			. ' data-f="' . esc_attr( $name ) . '"'
			. ' value="' . esc_attr( $value ) . '"'
			. ' step="' . esc_attr( $step ) . '"'
			. ( '' !== $min ? ' min="' . esc_attr( $min ) . '"' : '' )
			. '>';
		echo '</label>';
	}

	/* ---------------------------------------------------------------------
	 * Labels
	 * ------------------------------------------------------------------ */

	public static function labels( $lang ) {
		if ( 'ru' === $lang ) {
			return array(
				'tab_pip' => 'Стоимость пункта', 'tab_margin' => 'Маржа', 'tab_profit' => 'Прибыль/убыток',
				'tab_position' => 'Размер позиции', 'tab_converter' => 'Конвертер валют',
				'out_pip' => 'Стоимость пункта', 'out_margin' => 'Необходимая маржа', 'out_profit' => 'Результат сделки',
				'out_position' => 'Объём позиции', 'out_converter' => 'Итого',
				'instrument' => 'Инструмент', 'volume' => 'Объём, лоты', 'account_currency' => 'Валюта счёта',
				'leverage' => 'Кредитное плечо', 'direction' => 'Направление', 'buy' => 'Покупка', 'sell' => 'Продажа',
				'open_price' => 'Цена открытия', 'close_price' => 'Цена закрытия', 'balance' => 'Баланс счёта',
				'risk' => 'Риск, %', 'stop_loss' => 'Стоп-лосс, пункты', 'amount' => 'Сумма',
				'from' => 'Из', 'to' => 'В', 'price' => 'Текущая цена',
				'contract' => 'Размер контракта', 'pip_size' => 'Шаг пункта', 'pip_value' => 'Стоимость пункта',
				'position_value' => 'Объём в валюте базы', 'rate' => 'Курс', 'pips' => 'Пункты',
				'risk_amount' => 'Сумма риска', 'units' => 'Единиц базовой валюты',
				'quotes' => 'Котировки на', 'refresh' => 'Обновить', 'no_rate' => 'Нет котировки',
				'lots' => 'лот.', 'per_lot' => 'за 1 лот',
			);
		}

		return array(
			'tab_pip' => 'Pip value', 'tab_margin' => 'Margin', 'tab_profit' => 'Profit / Loss',
			'tab_position' => 'Position size', 'tab_converter' => 'Currency converter',
			'out_pip' => 'Value of one pip', 'out_margin' => 'Required margin', 'out_profit' => 'Trade result',
			'out_position' => 'Position size', 'out_converter' => 'Total',
			'instrument' => 'Instrument', 'volume' => 'Volume, lots', 'account_currency' => 'Account currency',
			'leverage' => 'Leverage', 'direction' => 'Direction', 'buy' => 'Buy', 'sell' => 'Sell',
			'open_price' => 'Open price', 'close_price' => 'Close price', 'balance' => 'Account balance',
			'risk' => 'Risk, %', 'stop_loss' => 'Stop loss, pips', 'amount' => 'Amount',
			'from' => 'From', 'to' => 'To', 'price' => 'Current price',
			'contract' => 'Contract size', 'pip_size' => 'Pip size', 'pip_value' => 'Pip value',
			'position_value' => 'Notional in base currency', 'rate' => 'Rate', 'pips' => 'Pips',
			'risk_amount' => 'Risk amount', 'units' => 'Units of base currency',
			'quotes' => 'Quotes as of', 'refresh' => 'Refresh', 'no_rate' => 'No quote available',
			'lots' => 'lots', 'per_lot' => 'per 1 lot',
		);
	}
}
