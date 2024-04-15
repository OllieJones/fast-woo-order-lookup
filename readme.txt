=== Fast Results ===
Author URI: https://github.com/OllieJones
Plugin URI: https://plumislandmedia.net/wordpress-plugins/fast-woo-order-lookup/
Donate link: 
Contributors:  OllieJones
Tags: woocommerce, search, orders, database, performance
Requires at least: 5.9
Tested up to: 6.5
Requires PHP: 5.6
Stable tag: 0.2.6
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

Thanks to Jetbrains for the use of their software development tools, especially [PhpStorm](https://www.jetbrains.com/phpstorm/). It's hard to imagine how a plugin like this one could be developed without PhpStorm's tools for exploring epic code bases like WordPress's.

== Frequently Asked Questions ==

= What's the background for this? =

See this [WooCommerce issue](https://github.com/woocommerce/woocommerce/issues/32826) for an example of the performance problem store owners have. See this [Subscriptions issue](https://github.com/Automattic/woocommerce-subscriptions-core/issues/183) for another example.

= What's the fix? =

Build a trigram lookup table, maintain it, and use it for the queries.

= The lookup table seems to be out of date. I can't find recent orders. What do I do? =

1. Let the author know by creating an issue at https://github.com/OllieJones/fast-woo-order-lookup/issues
2. Deactivate, then activate the plugin. This rebuilds the lookup table.

== Installation ==

1. Go to `Plugins` in the Admin menu
2. Click on the button `Add new`
3. Click on Upload Plugin
4. Find `fast-woo-order-lookup.zip` and upload it

== Upgrade Notice ==

An earlier version of this plugin did not use trigram-related full text search, and therefore did not find things users expected to find. Please upgrade.

== Changelog ==

= 0.2.6 April 15, 2024 =

Notice, localization, phpcs:ignore

= 0.2.5 April 13, 2024 =

Background loading. Correct handling of HPOS variant queries (from the dropdown).

= 0.2.4 April 6, 2024 =

Ingest wp_wc_order_addresses info when creating trigram table, and handle pre-HPOS sites correctly.

= 0.2.2 April 1, 2024 =

Do trigram inserts in batches.

= 0.2.1 March 26, 2024 =

Keep up with changes to orders.

= 0.1.4 March 23, 2024

Use trigrams, support both trad and HPOS orders.

= 0.1.3 March 21, 2024

Build a text index table and use it.

= 0.1.2 November 24, 2023

Add support for speeding Subscriptions searches.

= 0.1.1 November 19, 2023
Birthday of Fast Woo Order Lookup. And, the birthday (in 1988) of the author's daughter Catharine.
