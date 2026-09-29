<?php
/**
 * Plugin Name:       Tools Paxforex
 * Description:       Trader tools for WordPress: a Forex economic calendar and a set of Forex calculators (pip value, margin, profit/loss, position size, currency converter). Each component has its own shortcode.
 * Version:           1.1.0
 * Requires at least: 5.6
 * Requires PHP:      7.2
 * Author:            MarinaP
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tools-paxforex
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TPF_VERSION', '1.1.0' );
define( 'TPF_FILE', __FILE__ );
define( 'TPF_DIR', plugin_dir_path( __FILE__ ) );
define( 'TPF_URL', plugin_dir_url( __FILE__ ) );

require_once TPF_DIR . 'includes/class-tpf-rates.php';
require_once TPF_DIR . 'includes/class-tpf-calendar.php';
require_once TPF_DIR . 'includes/class-tpf-calculators.php';

final class TPF_Plugin {

	const OPT = 'tpf_settings';

	/** @var TPF_Plugin */
	private static $instance = null;

	/** @var TPF_Calendar */
	public $calendar;

	/** @var TPF_Calculators */
	public $calculators;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->calendar    = new TPF_Calendar( $this );
		$this->calculators = new TPF_Calculators( $this );

		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_action( 'admin_post_tpf_purge', array( $this, 'handle_purge' ) );

		add_filter( 'plugin_action_links_' . plugin_basename( TPF_FILE ), array( $this, 'action_links' ) );
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------ */

	public static function defaults() {
		return array(
			// Calendar.
			'feed_url'        => TPF_Calendar::DEFAULT_FEED,
			'cache_minutes'   => 30,
			'timezone'        => 'site',
			// Calculators.
			'rates_minutes'   => 60,
			'base_currency'   => 'USD',
			'leverage'        => 100,
			'instruments'     => TPF_Calculators::default_instruments_string(),
			// Shared.
			'theme'           => 'light',
			'lang'            => 'en',
		);
	}

	public function settings() {
		$saved = get_option( self::OPT, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public function get( $key ) {
		$s = $this->settings();
		return isset( $s[ $key ] ) ? $s[ $key ] : null;
	}

	/* ---------------------------------------------------------------------
	 * Assets
	 * ------------------------------------------------------------------ */

	public function register_assets() {
		wp_register_style( 'tpf-common', TPF_URL . 'assets/tpf-common.css', array(), TPF_VERSION );

		wp_register_style( 'tpf-calendar', TPF_URL . 'assets/tpf-calendar.css', array( 'tpf-common' ), TPF_VERSION );
		wp_register_script( 'tpf-calendar', TPF_URL . 'assets/tpf-calendar.js', array(), TPF_VERSION, true );

		wp_register_style( 'tpf-calculators', TPF_URL . 'assets/tpf-calculators.css', array( 'tpf-common' ), TPF_VERSION );
		wp_register_script( 'tpf-calculators', TPF_URL . 'assets/tpf-calculators.js', array(), TPF_VERSION, true );
	}

	/**
	 * Shared JS config, printed once.
	 */
	public function localize( $handle ) {
		wp_localize_script(
			$handle,
			'TPF_CFG',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'tpf' ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Admin
	 * ------------------------------------------------------------------ */

	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=tpf' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">Settings</a>' );
		return $links;
	}

	public function admin_menu() {
		add_menu_page(
			'Tools Paxforex',
			'Tools Paxforex',
			'manage_options',
			'tpf',
			array( $this, 'render_settings_page' ),
			'dashicons-chart-line',
			58
		);
	}

	public function admin_init() {
		register_setting(
			'tpf_group',
			self::OPT,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);
	}

	public function sanitize_settings( $input ) {
		$d   = self::defaults();
		$out = array();

		$out['feed_url']      = esc_url_raw( isset( $input['feed_url'] ) ? $input['feed_url'] : $d['feed_url'] );
		$out['cache_minutes'] = max( 5, min( 1440, (int) ( isset( $input['cache_minutes'] ) ? $input['cache_minutes'] : $d['cache_minutes'] ) ) );
		$out['timezone']      = sanitize_text_field( isset( $input['timezone'] ) ? $input['timezone'] : $d['timezone'] );

		$out['rates_minutes'] = max( 15, min( 1440, (int) ( isset( $input['rates_minutes'] ) ? $input['rates_minutes'] : $d['rates_minutes'] ) ) );
		$out['base_currency'] = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) ( isset( $input['base_currency'] ) ? $input['base_currency'] : $d['base_currency'] ) ) );
		$out['leverage']      = max( 1, min( 5000, (int) ( isset( $input['leverage'] ) ? $input['leverage'] : $d['leverage'] ) ) );
		$out['instruments']   = TPF_Calculators::sanitize_instruments( isset( $input['instruments'] ) ? $input['instruments'] : $d['instruments'] );

		$out['theme'] = ( isset( $input['theme'] ) && 'dark' === $input['theme'] ) ? 'dark' : 'light';
		$out['lang']  = ( isset( $input['lang'] ) && 'ru' === $input['lang'] ) ? 'ru' : 'en';

		delete_transient( TPF_Calendar::CACHE_KEY );
		delete_transient( TPF_Rates::CACHE_KEY );

		return $out;
	}

	public function handle_purge() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}
		check_admin_referer( 'tpf_purge' );

		delete_transient( TPF_Calendar::CACHE_KEY );
		delete_transient( TPF_Rates::CACHE_KEY );
		delete_transient( 'tpf_force_lock' );

		$this->calendar->get_events( true );
		TPF_Rates::instance( $this )->get( true );

		wp_safe_redirect( add_query_arg( 'tpf_purged', '1', admin_url( 'admin.php?page=tpf' ) ) );
		exit;
	}

	public function render_settings_page() {
		$s      = $this->settings();
		$events = get_transient( TPF_Calendar::CACHE_KEY );
		$rates  = get_transient( TPF_Rates::CACHE_KEY );
		?>
		<div class="wrap">
			<h1>Tools Paxforex</h1>
			<p class="description">Two shortcodes: <code>[paxforex_economic_calendar]</code> and <code>[paxforex_calculators]</code>.</p>

			<?php if ( isset( $_GET['tpf_purged'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p>Cache cleared and data reloaded.</p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'tpf_group' ); ?>

				<h2 class="title">Economic calendar</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="tpf_feed">Data feed URL</label></th>
						<td>
							<input type="url" id="tpf_feed" class="regular-text code"
								name="<?php echo esc_attr( self::OPT ); ?>[feed_url]"
								value="<?php echo esc_attr( $s['feed_url'] ); ?>">
							<p class="description">JSON feed in ForexFactory format: <code>title, country, date, impact, forecast, previous, actual</code>.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tpf_cache">Cache, minutes</label></th>
						<td><input type="number" id="tpf_cache" min="5" max="1440" class="small-text"
							name="<?php echo esc_attr( self::OPT ); ?>[cache_minutes]"
							value="<?php echo esc_attr( $s['cache_minutes'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="tpf_tz">Default time zone</label></th>
						<td>
							<input type="text" id="tpf_tz" class="regular-text code"
								name="<?php echo esc_attr( self::OPT ); ?>[timezone]"
								value="<?php echo esc_attr( $s['timezone'] ); ?>">
							<p class="description"><code>site</code> — WordPress time zone, <code>local</code> — visitor's own time, or a zone name such as <code>America/New_York</code>.</p>
						</td>
					</tr>
				</table>

				<h2 class="title">Calculators</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="tpf_base">Default account currency</label></th>
						<td><input type="text" id="tpf_base" class="small-text code" maxlength="3"
							name="<?php echo esc_attr( self::OPT ); ?>[base_currency]"
							value="<?php echo esc_attr( $s['base_currency'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="tpf_lev">Default leverage (1:X)</label></th>
						<td><input type="number" id="tpf_lev" min="1" max="5000" class="small-text"
							name="<?php echo esc_attr( self::OPT ); ?>[leverage]"
							value="<?php echo esc_attr( $s['leverage'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="tpf_rates">Quotes cache, minutes</label></th>
						<td><input type="number" id="tpf_rates" min="15" max="1440" class="small-text"
							name="<?php echo esc_attr( self::OPT ); ?>[rates_minutes]"
							value="<?php echo esc_attr( $s['rates_minutes'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="tpf_instr">Instruments</label></th>
						<td>
							<textarea id="tpf_instr" class="large-text code" rows="4"
								name="<?php echo esc_attr( self::OPT ); ?>[instruments]"><?php echo esc_textarea( $s['instruments'] ); ?></textarea>
							<p class="description">Comma-separated symbols, e.g. <code>EURUSD, GBPJPY, XAUUSD</code>. Any pair of supported currencies works; metals: <code>XAUUSD</code>, <code>XAGUSD</code>.</p>
						</td>
					</tr>
				</table>

				<h2 class="title">Appearance</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Theme</th>
						<td>
							<select name="<?php echo esc_attr( self::OPT ); ?>[theme]">
								<option value="light" <?php selected( $s['theme'], 'light' ); ?>>Light</option>
								<option value="dark" <?php selected( $s['theme'], 'dark' ); ?>>Dark</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">Interface language</th>
						<td>
							<select name="<?php echo esc_attr( self::OPT ); ?>[lang]">
								<option value="en" <?php selected( $s['lang'], 'en' ); ?>>English</option>
								<option value="ru" <?php selected( $s['lang'], 'ru' ); ?>>Russian</option>
							</select>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr>
			<h2>Cache status</h2>
			<p>
				Calendar events cached: <strong><?php echo is_array( $events ) ? count( $events ) : 0; ?></strong><br>
				Quotes cached: <strong><?php echo ( is_array( $rates ) && ! empty( $rates['rates'] ) ) ? count( $rates['rates'] ) : 0; ?></strong>
				<?php if ( is_array( $rates ) && ! empty( $rates['date'] ) ) : ?>
					(as of <?php echo esc_html( $rates['date'] ); ?>)
				<?php endif; ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="tpf_purge">
				<?php wp_nonce_field( 'tpf_purge' ); ?>
				<?php submit_button( 'Clear cache and reload', 'secondary', 'submit', false ); ?>
			</form>

			<hr>
			<h2>Shortcode reference</h2>

			<h3><code>[paxforex_economic_calendar]</code></h3>
			<table class="widefat striped" style="max-width:900px">
				<thead><tr><th>Attribute</th><th>Values</th><th>Default</th></tr></thead>
				<tbody>
					<tr><td><code>view</code></td><td>today, tomorrow, yesterday, week</td><td>today</td></tr>
					<tr><td><code>impact</code></td><td>all or a list: high,medium,low</td><td>all</td></tr>
					<tr><td><code>currencies</code></td><td>USD,EUR,GBP…</td><td>all</td></tr>
					<tr><td><code>timezone</code></td><td>site, local, Europe/London…</td><td>site</td></tr>
					<tr><td><code>theme</code> / <code>lang</code></td><td>light, dark / en, ru</td><td>from settings</td></tr>
					<tr><td><code>title</code></td><td>heading above the table</td><td>—</td></tr>
					<tr><td><code>filters</code> / <code>search</code></td><td>yes, no</td><td>yes</td></tr>
					<tr><td><code>refresh</code></td><td>auto-refresh seconds, 0 to disable</td><td>300</td></tr>
					<tr><td><code>height</code></td><td>e.g. 600px — enables scrolling</td><td>—</td></tr>
					<tr><td><code>limit</code></td><td>max rows, 0 = unlimited</td><td>0</td></tr>
				</tbody>
			</table>

			<h3><code>[paxforex_calculators]</code></h3>
			<table class="widefat striped" style="max-width:900px">
				<thead><tr><th>Attribute</th><th>Values</th><th>Default</th></tr></thead>
				<tbody>
					<tr><td><code>tools</code></td><td>pip, margin, profit, position, converter — any subset, order matters</td><td>all five</td></tr>
					<tr><td><code>instruments</code></td><td>EURUSD,GBPUSD,XAUUSD…</td><td>from settings</td></tr>
					<tr><td><code>instrument</code></td><td>preselected symbol</td><td>first in the list</td></tr>
					<tr><td><code>currency</code></td><td>account currency, e.g. EUR</td><td>from settings</td></tr>
					<tr><td><code>leverage</code></td><td>e.g. 200</td><td>from settings</td></tr>
					<tr><td><code>lots</code></td><td>default volume</td><td>1</td></tr>
					<tr><td><code>theme</code> / <code>lang</code></td><td>light, dark / en, ru</td><td>from settings</td></tr>
					<tr><td><code>title</code></td><td>heading above the widget</td><td>—</td></tr>
				</tbody>
			</table>

			<p><strong>Examples:</strong></p>
			<p><code>[paxforex_economic_calendar view="week" impact="high,medium" currencies="USD,EUR" timezone="Europe/London" height="600px"]</code></p>
			<p><code>[paxforex_calculators tools="pip,margin,position" instrument="XAUUSD" currency="EUR" leverage="200" theme="dark"]</code></p>
		</div>
		<?php
	}
}

TPF_Plugin::instance();

register_deactivation_hook(
	TPF_FILE,
	function () {
		delete_transient( TPF_Calendar::CACHE_KEY );
		delete_transient( TPF_Rates::CACHE_KEY );
		delete_transient( 'tpf_force_lock' );
	}
);
