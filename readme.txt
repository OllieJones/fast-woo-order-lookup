=== Fast Woo Order Lookup ===
Author URI: https://github.com/OllieJones
Plugin URI: https://plumislandmedia.net/wordpress-plugins/fast-woo-order-lookup/
Contributors:  OllieJones
Tags: woocommerce, search, orders, database, performance
Requires at least: 5.9
Tested up to: 6.8.3
Requires PHP: 5.6
WC requires at least: 8.0
WC tested up to: 10.2.1
Stable tag: 1.2.1
Requires Plugins: woocommerce
License: GPLv2
Text Domain: fast-woo-order-lookup
Github Plugin URI: https://github.com/OllieJones/fast-woo-order-lookup
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Donate link: https://github.com/sponsors/OllieJones

Searches for orders faster on WooCommerce stores with many orders.

== Description ==

WooCommerce's Order and Subscription pages allow store owners to search for orders and subscriptions by customer name, email, and other attributes. By default, it does a general substring search. For example, if you put OllieJones into the search box, it will search with `LIKE '%OllieJones%'` using the leading wildcard `%`. That's astonishingly slow on sites with many orders.

Upon activation this plugin uses ActionScheduler to run a background process to create a special-purpose index table, a table of trigrams, to speed up that search. Then it uses those trigrams to search for orders.

The downside: the trigram table takes database space and takes time to generate.

The orders page itself contains a slow query to look up meta_keys. This fixes that query's performance too, using a cache of available values.

<h4>If you have problems</h4>

The WordPress and WooCommerce ecosystems offer many optional features enabled by plugins. And, WooCommerce sites run on many different versions of database server. It is not possible to test this plugin on every imaginable combination. So, you may have problems getting it to work.

Sometimes the process of creating the index table does not complete correctly. And, sometimes you cannot find some orders after the index is created.

If you tell the author about these problems, he will attempt to fix them. Please create a support topic, then visit Site Health, view the Info tab, click the Copy Site Info to Clipboard button, and paste that information into the support topic. And, of course, please describe what is going wrong.

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

= How much space does the lookup table -- `wp_fwol` -- take? =

It takes about 5-10KiB per order, as MariaDB / MySQL database storage, counting both data and indexes. So, if your site has a million orders, the table will take something like 5-10 GiB. The rows of the table are each quite small, just three letters and an order ID. And there are many of those rows for each order.

The table, named with an abbreviation for "Fast Woo Order Lookup", contains the trigram lookups. It has a terse name to keep queries short. It is dropped automatically if you deactivate the plugin.

= Can it get so large this plugin becomes useless or counterproductive? =

**No, unless your database tablespace is too small for it.**

This answer uses the [Big **O**](https://en.wikipedia.org/wiki/Big_O_notation) conceptual way of understanding program performance.

The table is organized by its primary key so this plugin can search for orders with **O**(log n) computational complexity.  That means if searching 100 orders takes two seconds, then searching 1000 takes about three seconds and 10,000 about four. So it will work at large scale.  And without this plugin the complexity of the order search in WooCommerce is a quite unpleasant **O**(n).   Ten times as many orders take ten times as long to search. So, 100 times as many take 100 times as long. Used at large scale that gets nasty. It burns server electricity. Just as importantly, it wastes users' time.

That's true even if you use a nice search plugin like [Relevanssi](https://wordpress.org/plugins/relevanssi/) to help your customers search for products. The author does that. It works. But not on orders.

This plugin improves order search performance by using a better algorithm. It's the InnoDB team we have to thank for this in MariaDB and MySQL. Legendary.  (See, Dr. Knuth? Somebody read your book!)

If your hosting service is such a cheapskate you don't have the tablespace for the table, that might be a reason to avoid this plugin.

= How long does it take to generate trigram lookup table? =

When you activate the plugin, it starts generating the table in the background. Everything continues as normal while the plugin is generating the table.

Generating the table seems to take about ten seconds (in the background) for every thousand orders.

= Does it work with High Performance Order Storage (HPOS)? =

**Yes**. It also works with the performance enhancements in WooCommerce 9.9.0 and beyond. Alas, it is still required to get good performance with order search.

= Does it work with pre-HPOS order storage? =

**Yes**.

= The lookup table seems to be out of date. I can't find recent orders. What do I do? =

1. Let the author know by creating an issue at https://github.com/OllieJones/fast-woo-order-lookup/issues
2. Deactivate, then activate the plugin. This rebuilds the lookup table.

= My store only has a few hundred orders. Do I need this plugin ? =

This plugin addresses database performance problems that only show themselves on stores with many thousands of orders. If your store is smaller than that you probably don't need it.

Wise owners of rapidly growing stores return regularly to examine their site performance. If your site is still small, it's better to wait until you actually need performance-enhancing plugins and other features. Installing them "just in case" is ineffective.

== Installation ==

Follow the usual procedure for installing a plugin from the wordpress.org plugin repository.

When you activate the plugin, it creates the trigram index. As it does so it processes orders in batches of 100 and inserts trigrams in batches of 200. Larger batch sizes make the indexing process more efficient, but they do consume server RAM while running.

You can, if need be, change these batch sizes in your `wp-config.php` file. If you do this, do so *before* you activate the plugin. Here are examples.

`define( 'FAST_WOO_ORDER_LOOKUP_ORDER_BATCH_SIZE', 200 );` changes the order batch size to 200.

`define( 'FAST_WOO_ORDER_LOOKUP_TRIGRAM_BATCH_SIZE', 500 );` changes the trigram batch size to 500
## Upgrade Notice

* This version supports WooCommerce 10.2.1.
* It fixes a defect that caused indexing to malfunction sometimes.
* It is now possible to control the batch sizes for indexing by setting `wp-config.php` constants *before activating* the plugin. See [Installation](#installation).
* It improves indexing performance by skipping gaps in order numbers.

== Upgrade Notice ==

Recent versions correct intermittent problems generating the index and maintaining the postmeta key cache, and handle legacy WooCommerce versions.

Thanks to my loyal users for bringing these problems to my attention!

== Changelog ==

= 1.2.1 = November 7, 2025

Support legacy WooCommerce back to 8.1.

= 1.2.0 = October 17, 2025

Correct an intermittent problem maintaining the postmeta key cache. Props tp slservice33 on w.org.

= 1.1.11 = September 24, 2025 =

WooCommerce 10.2.1, indexing defect fixed, gap skipping when indexing. Props to StefT1 on GitHub.

