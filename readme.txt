=== Fast Results ===
Author URI: https://github.com/OllieJones
Plugin URI: https://plumislandmedia.net/wordpress-plugins/fast-woo-order-lookup/
Donate link: 
Contributors:  Ollie Jones
Tags: woocommerce, search, orders, database, performance
Requires at least: 5.9
Tested up to: 6.4
Requires PHP: 5.6
Stable tag: 0.1.1
License: GPLv2
Github Plugin URI: https://github.com/OllieJones/fast-woo-order-lookup
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Searches for orders faster on sites with many order.

== Description ==

WooCommerce's Order page allows store owners to search for orders by customer name, email, and other attributes. By default, it does a general substring search. For example, if you put OllieJones into the search box, it will search with `LIKE '%OllieJones%'` using the leading wildcard `%`. That's astonishingly slow on sites with many orders.

This plugin changes the search operation to do an anchored substring search, with `LIKE 'OllieJones%'` without the leading wildcard `%`. That's much faster as it can exploit an index in the database.

The downside to using this: Searches don't cast such a wide net. For example, if you search for `'Jones'` it won't find metadata containing `'OllieJones'`.

Thanks to Jetbrains for the use of their software development tools, especially [PhpStorm](https://www.jetbrains.com/phpstorm/). It's hard to imagine how a plugin like this one could be developed without PhpStorm's tools for exploring epic code bases like WordPress's.

== Frequently Asked Questions ==

= What's the background for this? =

See this [WooCommerce issue](https://github.com/woocommerce/woocommerce/issues/32826) for an example of the performance problem store owners have.

= What's the fix? =

Judicious query munging with the `query` filter.  Nasty, but there aren't any suitable hooks for doing things more elegantly.

= How can I make this even faster? =

Add a covering index to WooCommerce's high-performance order store table called `wp_woocommerce_order_items`, like this.

    `ALTER TABLE wp_woocommerce_order_items ADD KEY items_id (order_item_name(128), order_id);`

== Installation ==

1. Go to `Plugins` in the Admin menu
2. Click on the button `Add new`
3. Click on Upload Plugin
4. Find `fast-woo-order-lookup.zip` and upload it
4. Click on `Activate plugin`


== Changelog ==

= 0.1.1 November 19, 2023
Birthday of Fast Woo Order Lookup. And, the birthday (in 1988) of the author's daughter Catharine.
