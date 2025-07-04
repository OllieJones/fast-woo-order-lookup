=== Fast Woo Order Lookup ===
Author URI: https://github.com/OllieJones
Plugin URI: https://plumislandmedia.net/wordpress-plugins/fast-woo-order-lookup/
Contributors:  OllieJones
Tags: woocommerce, search, orders, database, performance
Requires at least: 5.9
Tested up to: 6.8.1
Requires PHP: 5.6
WC requires at least: 8.0
WC tested up to: 9.9.5
Stable tag: 1.1.10
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

The orders page itself contains a slow query to look up meta_keys. This fixes that query's performance too.

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

= How much space does the trigram lookup table take? =

It takes about 5-10KiB per order, as MariaDB / MySQL database storage, counting both data and indexes. So, if your site has a million orders, the table will take something like 5-10 GiB.

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

= What is this wp_fwol table created by the plugin? =

This table, named with an abbreviation for "Fast Woo Order Lookup", contains the trigram lookups. It has a terse name to keep queries short. It is dropped automatically if you deactivate the plugin.

= My store only has a few hundred orders. Do I need this plugin ? =

This plugin addresses database performance problems that only show themselves on stores with many tens of thousands of orders. If your store is smaller than that you probably don't need it.

Wise owners of rapidly growing stores return regularly to examine their site performance. If your site is still small, it's better to wait until you actually need performance-enhancing plugins and other features. Installing them "just in case" is ineffective.

== Installation ==

Follow the usual procedure for installing a plugin from the wordpress.org plugin repository.

== Upgrade Notice ==

This version supports WooCommerce 9.9.5. And, it adds diagnostic information to the Info tab of your Site Health page. Including that information when you repoert a problem will help the author correct the problem.

== Changelog ==


= 1.1.10 = July 3, 2025 =

Less aggressive logging, WooCommerce 9.9.5.

= 1.1.8 = May 22, 2025 =

Support 9.9.0.

= 1.1.7 = April 25, 2025 =

Fix memory leak in logging.

= 1.1.6 = April 11, 2025 =

Fix memory leak in logging.

= 1.1.5 April 9, 2025 =

Improve Site Health Info.

= 1.1.4 April 5, 2025 =

Handle a COUNT(*) query in support of pagination.

