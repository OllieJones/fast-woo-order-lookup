=== Fast Woo Order Lookup ===
Author URI: https://github.com/OllieJones
Plugin URI: https://plumislandmedia.net/wordpress-plugins/fast-woo-order-lookup/
Donate link: 
Contributors:  OllieJones
Tags: woocommerce, search, orders, database, performance
Requires at least: 5.9
Tested up to: 6.6.1
Requires PHP: 5.6
Stable tag: 1.0.2
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

= Even with this plugin installed my site displays individual orders very slowly. What do I do? =

The query to populate the dropdown for custom field names (meta keys) on the order page is very slow if your site has many -- hundreds of thousands of -- orders. Read [this](https://www.plumislandmedia.net/wordpress/performance/woocommerce-key-improvement/#wp_wc_orders_meta) for details.  Version 1.0.0 of this plugin includes a cache to avoid repeating that query.

You can add a key to work around this problem.

`wp db query "ALTER TABLE wp_wc_orders_meta ADD KEY slow_ordermeta_workaround(meta_key)"`

== Installation ==

Follow the usual procedure for installing a plugin from the wordpress.org plugin repository.

== Upgrade Notice ==

Tnis plugin is now compatible with WooCommerce's updgrades to 9.0.0 and 8.9.3. And, it keeps a cache of custom field names for orders to avoid the very slow load time for order pages.

When you install this upgrade, the plugin repeats the indexing process to add some new fields.

== Changelog ==

= 1.0.2 August 5, 2024 =

* Load the meta_names cache during activation; don't let it expire.

= 1.0.0 July 3, 2024 =

* Allow searching on order number and transaction id fields.
* Keep a cache of the meta_names for order custom fields to avoid repeating a very slow query.

= 0.5.0 July 1, 2024 =

* Improved compatibility with WooCommerce 9.0.0+.

* Add advice to readme.txt suggesting a new key on `wp_wc_orders_meta` for very large sites.

= 0.4.1 June 15, 2024 =

* Make the patch for slow order-page viewing compatible with WooCommerce 8.9.3.
* Fix a presentation defect in the table-generation notify message.

= 0.4.0 May 10, 2024 =

Patch the query to look up distinct public meta_key values.

= 0.3.0 April 25, 2024 =

Use JOINs rather than IN to get better performance. Shorten the table and column names.

= 0.2.6 April 15, 2024 =

Notice, localization, phpcs:ignore

= 0.2.5 April 13, 2024 =

Background loading. Correct handling of HPOS variant queries (from the dropdown).

= 0.2.4 April 6, 2024 =

Ingest wp_wc_order_addresses info when creating trigram table, and handle pre-HPOS sites correctly.

= 0.2.2 April 1, 2024 =

Perform trigram inserts in batches.

= 0.2.1 March 26, 2024 =

Keep up with changes to orders.

= 0.1.4 March 23, 2024

Use trigrams, support both traditional and HPOS orders.

= 0.1.3 March 21, 2024

Build a text index table and use it.

= 0.1.2 November 24, 2023

Add support for speeding Subscriptions searches.

= 0.1.1 November 19, 2023

Birthday of Fast Woo Order Lookup. And, the birthday (in 1988) of the author's daughter Catharine.
