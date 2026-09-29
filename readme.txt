=== Tools Paxforex ===
Contributors: alexv
Tags: forex, economic calendar, calculator, trading, shortcode
Requires at least: 5.6
Tested up to: 6.8
Requires PHP: 7.2
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Two trader tools for WordPress: a Forex economic calendar and a set of Forex calculators. Each has its own shortcode.

== Description ==

**Tools Paxforex** bundles two independent components. Insert either one, or both, anywhere shortcodes are supported.

= 1. Economic calendar — `[paxforex_economic_calendar]` =

* Columns: time, currency with flag, impact, event, actual, forecast, previous.
* Tabs: Yesterday / Today / Tomorrow / This week.
* Filters by impact (high / medium / low) and by currency, plus a search box.
* Time zone selector — site time, the visitor's own time, or any named zone; times are recalculated instantly and the choice is remembered in the browser.
* Next upcoming event is highlighted with a countdown; actual above/below forecast is colour-coded.
* Auto-refresh over AJAX without reloading the page.
* Renders server-side, so the table is visible even with JavaScript disabled.

Data source (configurable): the public weekly ForexFactory JSON feed
`https://nfs.faireconomy.media/ff_calendar_thisweek.json`
Any feed with the same fields works: `title`, `country`, `date`, `impact`, `forecast`, `previous`, `actual`.

= 2. Forex calculators — `[paxforex_calculators]` =

Five calculators in a tabbed widget, all recalculating as you type:

* **Pip value** — value of one pip for the chosen instrument, volume and account currency.
* **Margin** — margin required for a position at a given leverage.
* **Profit / Loss** — trade result in account currency and pips, for buy or sell.
* **Position size** — lot size derived from balance, risk percentage and stop loss.
* **Currency converter** — conversion between all supported currencies.

Instruments: any pair built from the supported currencies (majors, crosses, exotics) plus metals `XAUUSD` (100 oz per lot) and `XAGUSD` (5,000 oz per lot). Contract size, pip size and price precision are derived automatically for each symbol.

Quotes come from a free public endpoint (`@fawazahmed0/currency-api`, with `frankfurter.dev` as a fallback) and are cached on the server. All arithmetic happens in the browser, so the calculators respond instantly.

= Shared =

* Light and dark themes, fully responsive (the calendar turns into cards on phones).
* English and Russian interface.
* Everything is cached in transients; if a source is unreachable, the last successful download is served.
* No external CSS/JS libraries, no jQuery, no tracking.

== Installation ==

1. Upload the ZIP under Plugins → Add New → Upload Plugin.
2. Activate the plugin.
3. Add `[paxforex_economic_calendar]` and/or `[paxforex_calculators]` to a page or post.
4. Optional: adjust defaults under the "Tools Paxforex" menu in wp-admin.

== Shortcode reference ==

= [paxforex_economic_calendar] =

* `view` — today | tomorrow | yesterday | week (default: today)
* `impact` — all, or a list: high,medium,low (default: all)
* `currencies` — USD,EUR,GBP… (default: all)
* `timezone` — site | local | Europe/London… (default: site)
* `theme` — light | dark
* `lang` — en | ru
* `title` — heading above the table
* `filters` / `search` — yes | no
* `refresh` — auto-refresh interval in seconds, 0 to disable (default: 300)
* `height` — e.g. 600px, enables scrolling with a sticky header
* `limit` — maximum number of rows, 0 for unlimited

Example:
`[paxforex_economic_calendar view="week" impact="high,medium" currencies="USD,EUR" timezone="Europe/London" height="600px" title="Forex Economic Calendar"]`

= [paxforex_calculators] =

* `tools` — pip, margin, profit, position, converter (any subset; order is respected)
* `instruments` — EURUSD,GBPUSD,XAUUSD… (default: from settings)
* `instrument` — preselected symbol
* `currency` — account currency (default: from settings)
* `leverage` — e.g. 200
* `lots` — default volume
* `theme` — light | dark
* `lang` — en | ru
* `title` — heading above the widget

Example:
`[paxforex_calculators tools="pip,margin,position" instrument="XAUUSD" currency="EUR" leverage="200" theme="dark"]`

Legacy aliases `[economic_calendar]`, `[fx_economic_calendar]` and `[forex_calculators]` are also registered.

== Frequently Asked Questions ==

= Why are the Actual values empty? =
Actual figures appear in the feed only after a release. Upcoming events have none.

= How often is data refreshed? =
The calendar feed is requested at most once every N minutes (30 by default), quotes once every 60 minutes. Both intervals are configurable, and a manual "Refresh" button is available in each widget.

= Are the quotes real-time? =
No. They are daily reference rates, sufficient for sizing and margin estimates. In the Profit / Loss calculator the open and close prices are prefilled from the last quote and can be typed over with your broker's actual prices.

= Is the Actual colour coding always "good/bad"? =
No. Green simply means above forecast and red below. For inverted indicators such as unemployment, a higher reading is economically worse; the feed carries no direction flag.

== Changelog ==

= 1.1.0 =
* Renamed to Tools Paxforex, English interface by default.
* New component: Forex calculators (pip value, margin, profit/loss, position size, currency converter) with the `[paxforex_calculators]` shortcode.
* Shared design system and single admin screen for both components.

= 1.0.0 =
* First release: economic calendar.
