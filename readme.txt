=== Fast Woo Order Lookup ===
Author URI: https://github.com/OllieJones
Plugin URI: https://plumislandmedia.net/wordpress-plugins/fast-woo-order-lookup/
Donate link: 
Contributors:  OllieJones
Tags: woocommerce, search, orders, database, performance
Requires at least: 5.9
Tested up to: 6.7
Requires PHP: 5.6
WC requires at least: 4.0
WC tested up to: 9.1.4
Stable tag: 1.1.2
Requires Plugins: woocommerce
License: GPLv2
Text Domain: fast-woo-order-lookup
Github Plugin URI: https://github.com/OllieJones/fast-woo-order-lookup
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Searches for orders faster on WooCommerce stores with many orders.

== Description ==

WooCommerce's Order and Subscription pages allow store owners to search for orders and subscriptions by customer name, email, and other attributes. By default, it does a general substring search. For example, if you put OllieJones into the search box, it will search with `LIKE '%OllieJones%'` using the leading wildcard `%`. That's astonishingly slow on sites with many orders.

Upon activation this plugin runs a background process to create a special-purpose index table, a table of trigrams, to speed up that search. Then it uses those trigrams to search for orders.

The downside: the trigram table takes database space and takes time to generate.

The orders page itself contains a very slow query (to be fixed in Woocommerce 9.0.0) to look up meta_keys. This fixes that query's performance too.

<h4>Credits</h4>
Thanks to Leho Kraav for bringing this problem to my attention.

Thanks to Sebastian Sommer and Maxime Michaud for using early versions of the plugin on large stores, and to Maxime Michaud for creating the transation into French.

Thanks to Jetbrains for the use of their software development tools, especially [PhpStorm](https://www.jetbrains.com/phpstorm/). It's hard to imagine how a plugin like this one could be developed without PhpStorm's tools for exploring epic code bases like WordPress's.

<h4>How can I learn more about making my WordPress site more efficient?</h4>

We offer several plugins to help with your site's database efficiency. You can [read about them here](https://www.plumislandmedia.net/wordpress/performance/optimizing-wordpress-database-servers/).

== Frequently Asked Questions ==

= What's the background for this? =

See this [WooCommerce issue](https://github.com/woocommerce/woocommerce/issues/32826) for an example of the performance problem store owners have. See this [Subscriptions issue](https://github.com/Automattic/woocommerce-subscriptions-core/issues/183) for another example.

= What's the fix? =

    Build a [trigram lookup table](https://www.plumislandmedia.net/wordpress-plugins/fast-woo-order-lookup/#how-does-it-work-trigrams), maintain it, and use it for the queries.

= How much space does the trigram lookup table take? =

It takes about 5-10KiB per order, as MariaDB / MySQL database storage, counting both data and indexes. So, if your site has a million orders, the table will take something like 5-10 GiB.

= How long does it take to generate trigram lookup table? =

When you activate the plugin, it starts generating the table in the background. Everything continues as normal while the plugin is generating the table.

Generating the table seems to take about ten seconds (in the background) for every thousand orders.

= Does it work with High Performance Order Storage (HPOS)? =

**Yes**.

= Does it work with pre-HPOS order storage? =

**Yes**.

= The lookup table seems to be out of date. I can't find recent orders. What do I do? =

1. Let the author know by creating an issue at https://github.com/OllieJones/fast-woo-order-lookup/issues
2. Deactivate, then activate the plugin. This rebuilds the lookup table.

= What is this wp_fwol table created by the plugin? =

This table, named with an abbreviation for "Fast Woo Order Lookup", contains the trigram lookups. It has a terse name to keep queries short. It is dropped automatically if you deactivate the plugin.

= My store only has a few hundred orders. Do I need this plugin ? =

This plugin addresses database performance problems that only show themselves on stores with many tens of thousands of orders. If your store is smaller than that you probably don't need it.

Wise owners of rapidly growing stores return regularly to examine their site performance. If your site is still small, it's better to wait until you actually need performance-enhancing plugins and other features. Installing them "just in case" is ineffective.

== Installation ==

Follow the usual procedure for installing a plugin from the wordpress.org plugin repository.

== Upgrade Notice ==

Support for WooCommerce Sequential Order Numbers Pro. Faster initial indexing. WooCommerce's updgrades to 9.0.0 and 8.9.3. Custom field name cache for orders.

== Changelog ==

= 1.1.2 October 7, 2024 =

Handle tables and colums with character sets other than $wpdb->charset.

= 1.1.1 August 12, 2024 =

* Limit batch runtime to 25 seconds. Include a cronjob shell script to purge stale ActionScheduler actions.

= 1.1.0 August 11, 2024 =

* Some MariaDB / MySQL versions implicitly cast integers to latin1 strings causing problems. Explicit casting fixes the issue.
