<?php
/**
 * Economic calendar component.
 * Shortcode: [paxforex_economic_calendar]
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TPF_Calendar {

	const CACHE_KEY    = 'tpf_events_v1';
	const BACKUP_KEY   = 'tpf_events_backup_v1';
	const DEFAULT_FEED = 'https://nfs.faireconomy.media/ff_calendar_thisweek.json';

	/** @var TPF_Plugin */
	private $plugin;

	/** @var bool */
	private $enqueued = false;

	public function __construct( $plugin ) {
		$this->plugin = $plugin;

		add_action( 'init', array( $this, 'register_shortcodes' ) );
		add_action( 'wp_ajax_tpf_events', array( $this, 'ajax_events' ) );
		add_action( 'wp_ajax_nopriv_tpf_events', array( $this, 'ajax_events' ) );
	}

	public function register_shortcodes() {
		add_shortcode( 'paxforex_economic_calendar', array( $this, 'shortcode' ) );
		add_shortcode( 'economic_calendar', array( $this, 'shortcode' ) );
		add_shortcode( 'fx_economic_calendar', array( $this, 'shortcode' ) ); // legacy alias
	}

	/* ---------------------------------------------------------------------
	 * Data
	 * ------------------------------------------------------------------ */

	public function get_events( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$url = $this->plugin->get( 'feed_url' );
		if ( ! $url ) {
			$url = self::DEFAULT_FEED;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 15,
				'user-agent' => 'WordPress/Tools-Paxforex-' . TPF_VERSION . '; ' . home_url( '/' ),
				'headers'    => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return $this->stale_backup();
		}

		$raw = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $raw ) ) {
			return $this->stale_backup();
		}

		$events = $this->normalize( $raw );
		if ( empty( $events ) ) {
			return $this->stale_backup();
		}

		$ttl = max( 5, (int) $this->plugin->get( 'cache_minutes' ) ) * MINUTE_IN_SECONDS;
		set_transient( self::CACHE_KEY, $events, $ttl );
		update_option( self::BACKUP_KEY, $events, false );

		return $events;
	}

	private function stale_backup() {
		$backup = get_option( self::BACKUP_KEY, array() );
		if ( is_array( $backup ) && ! empty( $backup ) ) {
			set_transient( self::CACHE_KEY, $backup, 5 * MINUTE_IN_SECONDS );
			return $backup;
		}
		return array();
	}

	/**
	 * t — UTC timestamp, c — currency, i — impact, e — event,
	 * a — actual, f — forecast, p — previous.
	 */
	private function normalize( array $raw ) {
		$events = array();

		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) || empty( $row['title'] ) || empty( $row['date'] ) ) {
				continue;
			}

			$ts = strtotime( $row['date'] );
			if ( ! $ts ) {
				continue;
			}

			$impact = isset( $row['impact'] ) ? strtolower( trim( $row['impact'] ) ) : 'low';
			if ( ! in_array( $impact, array( 'low', 'medium', 'high' ), true ) ) {
				$impact = 'holiday';
			}

			$events[] = array(
				't' => (int) $ts,
				'c' => strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) ( isset( $row['country'] ) ? $row['country'] : '' ) ) ),
				'i' => $impact,
				'e' => wp_strip_all_tags( (string) $row['title'] ),
				'a' => isset( $row['actual'] ) ? wp_strip_all_tags( (string) $row['actual'] ) : '',
				'f' => isset( $row['forecast'] ) ? wp_strip_all_tags( (string) $row['forecast'] ) : '',
				'p' => isset( $row['previous'] ) ? wp_strip_all_tags( (string) $row['previous'] ) : '',
			);
		}

		usort(
			$events,
			function ( $a, $b ) {
				return $a['t'] === $b['t'] ? 0 : ( $a['t'] < $b['t'] ? -1 : 1 );
			}
		);

		return $events;
	}

	public function ajax_events() {
		check_ajax_referer( 'tpf', 'nonce' );

		$force = isset( $_GET['force'] ) && '1' === $_GET['force'];
		if ( $force && get_transient( 'tpf_force_lock' ) ) {
			$force = false;
		}
		if ( $force ) {
			set_transient( 'tpf_force_lock', 1, MINUTE_IN_SECONDS );
		}

		wp_send_json_success(
			array(
				'events'  => $this->get_events( $force ),
				'updated' => time(),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Shortcode
	 * ------------------------------------------------------------------ */

	public function shortcode( $atts ) {
		$s = $this->plugin->settings();

		$atts = shortcode_atts(
			array(
				'view'       => 'today',
				'impact'     => 'all',
				'currencies' => '',
				'timezone'   => $s['timezone'],
				'theme'      => $s['theme'],
				'lang'       => $s['lang'],
				'title'      => '',
				'filters'    => 'yes',
				'search'     => 'yes',
				'refresh'    => '300',
				'height'     => '',
				'limit'      => '0',
			),
			$atts,
			'paxforex_economic_calendar'
		);

		if ( ! $this->enqueued ) {
			$this->enqueued = true;
			wp_enqueue_style( 'tpf-calendar' );
			wp_enqueue_script( 'tpf-calendar' );
			$this->plugin->localize( 'tpf-calendar' );
		}

		$lang   = ( 'ru' === $atts['lang'] ) ? 'ru' : 'en';
		$labels = self::labels( $lang );
		$events = $this->get_events();

		$view = in_array( $atts['view'], array( 'yesterday', 'today', 'tomorrow', 'week' ), true ) ? $atts['view'] : 'today';

		$impacts = 'all' === $atts['impact']
			? array( 'high', 'medium', 'low', 'holiday' )
			: array_values(
				array_intersect(
					array_map( 'trim', explode( ',', strtolower( $atts['impact'] ) ) ),
					array( 'high', 'medium', 'low', 'holiday' )
				)
			);
		if ( empty( $impacts ) ) {
			$impacts = array( 'high', 'medium', 'low', 'holiday' );
		}

		$currencies = array_filter(
			array_map(
				function ( $c ) {
					return strtoupper( trim( $c ) );
				},
				explode( ',', $atts['currencies'] )
			)
		);

		$tz      = TPF_Calendar::resolve_tz( $atts['timezone'] );
		$tz_name = $tz->getName();
		$tz_off  = null;
		if ( false === strpos( $tz_name, '/' ) && 'UTC' !== $tz_name ) {
			$tz_off  = (int) round( $tz->getOffset( new DateTime( 'now', new DateTimeZone( 'UTC' ) ) ) / 60 );
			$tz_name = '';
		}

		$uid = 'tpf-cal-' . wp_generate_password( 6, false, false );

		$config = array(
			'view'       => $view,
			'impacts'    => $impacts,
			'currencies' => array_values( $currencies ),
			'tz'         => array(
				'name'   => 'local' === $atts['timezone'] ? '' : $tz_name,
				'offset' => 'local' === $atts['timezone'] ? 'local' : $tz_off,
				'label'  => 'local' === $atts['timezone'] ? $labels['tz_local'] : ( $tz_name ? $tz_name : self::offset_label( $tz_off ) ),
			),
			'filters'    => 'no' !== $atts['filters'],
			'search'     => 'no' !== $atts['search'],
			'refresh'    => max( 0, (int) $atts['refresh'] ),
			'limit'      => max( 0, (int) $atts['limit'] ),
			'lang'       => $lang,
			'labels'     => $labels,
			'updated'    => time(),
		);

		$style = $atts['height'] ? ' style="--tpf-max-height:' . esc_attr( $atts['height'] ) . '"' : '';

		ob_start();
		?>
		<div id="<?php echo esc_attr( $uid ); ?>"
			class="tpf tpf--<?php echo esc_attr( 'dark' === $atts['theme'] ? 'dark' : 'light' ); ?> tpf-cal<?php echo $atts['height'] ? ' tpf-cal--scroll' : ''; ?>"
			data-tpf-cal="1"<?php echo $style; // phpcs:ignore WordPress.Security.EscapeOutput ?>>

			<?php if ( $atts['title'] ) : ?>
				<h2 class="tpf__title"><?php echo esc_html( $atts['title'] ); ?></h2>
			<?php endif; ?>

			<div class="tpf__toolbar" data-tpf-toolbar></div>

			<div class="tpf-cal__body" data-tpf-body>
				<?php echo $this->render_static( $events, $view, $impacts, $currencies, $tz, $labels, $config['limit'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</div>

			<div class="tpf__footer">
				<span class="tpf__meta" data-tpf-meta><?php echo esc_html( $labels['tz'] . ': ' . $config['tz']['label'] ); ?></span>
				<span class="tpf__credit"><?php echo esc_html( $labels['source'] ); ?></span>
			</div>

			<script type="application/json" data-tpf-config><?php
				echo wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE );
			?></script>
			<script type="application/json" data-tpf-data><?php
				echo wp_json_encode( $events, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE );
			?></script>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Server-side table so the calendar also works without JavaScript.
	 */
	private function render_static( $events, $view, $impacts, $currencies, DateTimeZone $tz, $labels, $limit ) {
		$now   = new DateTime( 'now', $tz );
		$today = $now->format( 'Y-m-d' );

		$target = null;
		if ( 'today' === $view ) {
			$target = $today;
		} elseif ( 'tomorrow' === $view ) {
			$target = ( new DateTime( 'now', $tz ) )->modify( '+1 day' )->format( 'Y-m-d' );
		} elseif ( 'yesterday' === $view ) {
			$target = ( new DateTime( 'now', $tz ) )->modify( '-1 day' )->format( 'Y-m-d' );
		}

		$rows  = array();
		$count = 0;
		foreach ( $events as $ev ) {
			if ( ! in_array( $ev['i'], $impacts, true ) ) {
				continue;
			}
			if ( $currencies && ! in_array( $ev['c'], $currencies, true ) ) {
				continue;
			}
			$dt = new DateTime( '@' . $ev['t'] );
			$dt->setTimezone( $tz );
			$day = $dt->format( 'Y-m-d' );
			if ( $target && $day !== $target ) {
				continue;
			}
			if ( $limit && $count >= $limit ) {
				break;
			}
			$count++;
			$rows[ $day ][] = array( $ev, $dt );
		}

		ob_start();

		if ( empty( $rows ) ) {
			echo '<p class="tpf__empty">' . esc_html( $labels['empty'] ) . '</p>';
			return ob_get_clean();
		}

		echo '<table class="tpf-cal__table"><thead><tr>';
		echo '<th class="tpf-col-time">' . esc_html( $labels['time'] ) . '</th>';
		echo '<th class="tpf-col-cur">' . esc_html( $labels['currency'] ) . '</th>';
		echo '<th class="tpf-col-imp">' . esc_html( $labels['impact'] ) . '</th>';
		echo '<th class="tpf-col-event">' . esc_html( $labels['event'] ) . '</th>';
		echo '<th class="tpf-col-num">' . esc_html( $labels['actual'] ) . '</th>';
		echo '<th class="tpf-col-num">' . esc_html( $labels['forecast'] ) . '</th>';
		echo '<th class="tpf-col-num">' . esc_html( $labels['previous'] ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $day => $items ) {
			$first = $items[0][1];
			echo '<tr class="tpf-cal__daysep"><td colspan="7">' . esc_html(
				self::format_day( $first, $labels ) . ( $day === $today ? ' · ' . $labels['today'] : '' )
			) . '</td></tr>';

			foreach ( $items as $item ) {
				list( $ev, $dt ) = $item;
				echo '<tr class="tpf-cal__row tpf-cal__row--' . esc_attr( $ev['i'] ) . '">';
				echo '<td class="tpf-col-time">' . esc_html( $dt->format( 'H:i' ) ) . '</td>';
				echo '<td class="tpf-col-cur"><span class="tpf-flag">' . esc_html( self::flag( $ev['c'] ) ) . '</span><span class="tpf-cur">' . esc_html( $ev['c'] ) . '</span></td>';
				echo '<td class="tpf-col-imp"><span class="tpf-imp tpf-imp--' . esc_attr( $ev['i'] ) . '" title="' . esc_attr( $labels[ $ev['i'] ] ) . '"><i></i><i></i><i></i></span></td>';
				echo '<td class="tpf-col-event">' . esc_html( $ev['e'] ) . '</td>';
				echo '<td class="tpf-col-num tpf-actual" data-label="' . esc_attr( $labels['actual'] ) . '">' . esc_html( '' !== $ev['a'] ? $ev['a'] : '—' ) . '</td>';
				echo '<td class="tpf-col-num tpf-forecast" data-label="' . esc_attr( $labels['forecast'] ) . '">' . esc_html( '' !== $ev['f'] ? $ev['f'] : '—' ) . '</td>';
				echo '<td class="tpf-col-num tpf-prev" data-label="' . esc_attr( $labels['previous'] ) . '">' . esc_html( '' !== $ev['p'] ? $ev['p'] : '—' ) . '</td>';
				echo '</tr>';
			}
		}

		echo '</tbody></table>';

		return ob_get_clean();
	}

	private static function format_day( DateTime $dt, $labels ) {
		$idx = (int) $dt->format( 'w' );
		return $labels['weekdays'][ $idx ] . ', ' . $dt->format( 'd' ) . ' ' . $labels['months'][ (int) $dt->format( 'n' ) - 1 ];
	}

	public static function resolve_tz( $tz_attr ) {
		if ( '' === $tz_attr || 'site' === $tz_attr || 'local' === $tz_attr ) {
			return wp_timezone();
		}
		try {
			return new DateTimeZone( $tz_attr );
		} catch ( Exception $e ) {
			return wp_timezone();
		}
	}

	private static function offset_label( $minutes ) {
		if ( null === $minutes ) {
			return 'UTC';
		}
		$sign = $minutes < 0 ? '-' : '+';
		$abs  = abs( (int) $minutes );
		return sprintf( 'UTC%s%02d:%02d', $sign, floor( $abs / 60 ), $abs % 60 );
	}

	/**
	 * Emoji flag from a currency code — no external images.
	 */
	public static function flag( $currency ) {
		$map = array(
			'USD' => 'US', 'EUR' => 'EU', 'GBP' => 'GB', 'JPY' => 'JP', 'CHF' => 'CH',
			'CAD' => 'CA', 'AUD' => 'AU', 'NZD' => 'NZ', 'CNY' => 'CN', 'HKD' => 'HK',
			'SGD' => 'SG', 'SEK' => 'SE', 'NOK' => 'NO', 'DKK' => 'DK', 'PLN' => 'PL',
			'TRY' => 'TR', 'ZAR' => 'ZA', 'MXN' => 'MX', 'BRL' => 'BR', 'INR' => 'IN',
			'RUB' => 'RU', 'KRW' => 'KR', 'ILS' => 'IL', 'CZK' => 'CZ', 'HUF' => 'HU',
		);
		if ( ! isset( $map[ $currency ] ) ) {
			return '🏳';
		}
		$out = '';
		foreach ( str_split( $map[ $currency ] ) as $ch ) {
			$code = 127397 + ord( $ch );
			$out .= function_exists( 'mb_chr' ) ? mb_chr( $code, 'UTF-8' ) : html_entity_decode( '&#' . $code . ';', ENT_NOQUOTES, 'UTF-8' );
		}
		return $out;
	}

	public static function labels( $lang ) {
		if ( 'ru' === $lang ) {
			return array(
				'yesterday' => 'Вчера', 'today' => 'Сегодня', 'tomorrow' => 'Завтра', 'week' => 'Неделя',
				'time' => 'Время', 'currency' => 'Валюта', 'impact' => 'Важность', 'event' => 'Событие',
				'actual' => 'Факт', 'forecast' => 'Прогноз', 'previous' => 'Пред.',
				'high' => 'Высокая', 'medium' => 'Средняя', 'low' => 'Низкая', 'holiday' => 'Праздник',
				'all' => 'Все', 'refresh' => 'Обновить', 'search' => 'Поиск события…',
				'tz' => 'Часовой пояс', 'tz_local' => 'Ваше локальное время',
				'empty' => 'Нет событий по заданным фильтрам.',
				'updated' => 'Обновлено', 'in' => 'через', 'now' => 'сейчас',
				'source' => 'Данные: фид ForexFactory',
				'weekdays' => array( 'Воскресенье', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота' ),
				'months'   => array( 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря' ),
			);
		}

		return array(
			'yesterday' => 'Yesterday', 'today' => 'Today', 'tomorrow' => 'Tomorrow', 'week' => 'This week',
			'time' => 'Time', 'currency' => 'Currency', 'impact' => 'Impact', 'event' => 'Event',
			'actual' => 'Actual', 'forecast' => 'Forecast', 'previous' => 'Previous',
			'high' => 'High', 'medium' => 'Medium', 'low' => 'Low', 'holiday' => 'Holiday',
			'all' => 'All', 'refresh' => 'Refresh', 'search' => 'Search event…',
			'tz' => 'Time zone', 'tz_local' => 'Your local time',
			'empty' => 'No events match the selected filters.',
			'updated' => 'Updated', 'in' => 'in', 'now' => 'now',
			'source' => 'Data: ForexFactory feed',
			'weekdays' => array( 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' ),
			'months'   => array( 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December' ),
		);
	}
}
