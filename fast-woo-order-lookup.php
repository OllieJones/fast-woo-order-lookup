<?php /** @noinspection PhpUnusedParameterInspection */

/**
 * Fast Woo Order Lookup
 *
 * @package       fast-woo-order-lookup
 * @author        OllieJones
 * @license       gplv2
 *
 * @wordpress-plugin
 * Plugin Name:   Fast Woo Order Lookup
 * Plugin URI:    https://plumislandmedia.net/wordpress-plugins/fast-woo-order-lookup/
 * Description:   Look up orders faster in large WooCommerce stores with many orders.
 * Version:       1.1.8
 * Requires PHP: 5.6
 * Requires at least: 5.8
 * Tested up to: 6.8
 * WC requires at least: 4.0
 * WC tested up to: 9.1.4
 * Requires Plugins: woocommerce
 * Author:        Ollie Jones
 * Author URI:    https://github.com/OllieJones
 * Text Domain:   fast-woo-order-lookup
 * Domain Path:   /languages
 * License:       GPLv2
 * License URI:   https://www.gnu.org/licenses/gpl-2.0.html
 *
 * You should have received a copy of the GNU General Public License
 * along with Fast Woo Order Lookup. If not, see <https://www.gnu.org/licenses/gpl-2.0.html/>.
 */

/** @noinspection SqlNoDataSourceInspection */
/** @noinspection SqlDialectInspection */

namespace Fast_Woo_Order_Lookup;

use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStoreMeta;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const FAST_WOO_ORDER_LOOKUP_METAKEY_CACHE = 'fast_woo_order_lookup_metakey_cache';

class FastWooOrderLookup {

	private static $instance = null;

	private $textdex;
	private $filtering = false;

	private $term;
	private $trigram_clause;
	private $orders_to_update = array();

	public static function declare_hpos_compatible() {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}

	public static function woocommerce_changing_order( $order_id, $order ) {
		$instance                                = self::getInstance();
		$instance->orders_to_update[ $order_id ] = 1;
	}

	public static function woocommerce_deleting_order( $order_id ) {
		$instance                                = self::getInstance();
		$instance->orders_to_update[ $order_id ] = 1;
	}

	public static function woocommerce_order_object_updated_props( $order, $props ) {
		$instance                                = self::getInstance();
		$order_id                                = $order->get_id();
		$instance->orders_to_update[ $order_id ] = 1;
	}

	public static function update_post_meta( $meta_id, $object_id, $meta_key, $_meta_value ) {
		$instance = self::getInstance();
		if ( $instance->textdex->is_order_meta_key( $meta_key ) ) {
			$instance->orders_to_update[ $object_id ] = 1;
		}
	}

	public static function textdex_batch_action() {
		$instance = self::getInstance();
		$instance->textdex->load_batch();
	}

	/** Singleton constructor interface.
	 *
	 * @return FastWooOrderLookup
	 */
	public static function getInstance() {
		if ( self::$instance == null ) {
			self::$instance = new FastWooOrderLookup();
		}

		return self::$instance;
	}

	/**
	 * Configure this plugin to intercept metadata searches for WooCommerce orders.
	 */
	private function __construct() {
		require_once( plugin_dir_path( __FILE__ ) . 'code/class-textdex.php' );
		$this->textdex = new Textdex();
		$this->textdex->activate();
		$this->textdex->load_textdex();

		if ( $this->textdex->is_ready() ) {
			/* Query manipulation */
			add_filter( 'woocommerce_shop_order_search_fields', array( $this, 'filter_search_fields' ) );
			add_filter( 'woocommerce_shop_subscription_search_fields', array( $this, 'filter_search_fields' ) );
			add_filter( 'woocommerce_shop_order_search_results', array( $this, 'filter_search_results' ), 10, 3 );
			add_filter( 'woocommerce_shop_subscription_search_results', array(
				$this,
				'filter_search_results'
			), 10, 3 );
			add_filter( 'woocommerce_order_query_args', array( $this, 'woocommerce_order_query_args' ) );
			add_filter( 'woocommerce_order_query', array( $this, 'woocommerce_order_query' ), 10, 2 );
		}
		add_filter( 'postmeta_form_keys', array( $this, 'postmeta_get_order_custom_field_names' ), 10, 2 );

		require_once( plugin_dir_path( __FILE__ ) . 'code/class-textdex.php' );
		$this->textdex = new Textdex();
		$this->textdex->activate();
		$this->textdex->load_textdex();

		add_action( 'admin_notices', array( $this, 'show_status' ), 10, 0 );
		add_action( 'shutdown', array( $this, 'update_textdex' ), 1, 0 );
		add_filter( 'plugin_row_meta', array( $this, 'filter_plugin_row_meta' ), 10, 2 );
	}

	public function show_status() {

		$error = $this->textdex->get_load_error();
		if ( $error ) {
			$ms1 = __( 'Fast Woo Order Lookup indexing failed.', 'fast-woo-order-lookup' );
			$ms2 = __( 'This happens with some WooCommerce plugins the author did not anticipate.', 'fast-woo-order-lookup' );
			$ms3 = __( 'Please create a', 'fast-woo-order-lookup' );
			$ms4 = __( 'support topic', 'fast-woo-order-lookup' );
			$ms5 = __( 'and paste your', 'fast-woo-order-lookup' );
			$ms6 = __( 'Site Health - Info', 'fast-woo-order-lookup' );
			$ms7 = __( 'contents into it. Then', 'fast-woo-order-lookup' );
			$ms8 = __( 'deactivate', 'fast-woo-order-lookup' );
			$ms9 = __( 'the plugin.', 'fast-woo-order-lookup' );


			?>
            <div class="notice notice-error">
                <p>
					<?php echo esc_html( $ms1 ); ?>
					<?php echo esc_html( $ms2 ); ?>
					<?php echo esc_html( $ms3 ); ?>
                    <a href="https://wordpress.org/support/plugin/fast-woo-order-lookup/"
                       target="_blank"><?php echo esc_html( $ms4 ); ?></a>
					<?php echo esc_html( $ms5 ); ?>
                    <a href="/wp-admin/site-health.php?tab=debug" target="_blank"><?php echo esc_html( $ms6 ); ?></a>
					<?php echo esc_html( $ms7 ); ?>
                    <a href=/wp-admin/plugins.php"><?php echo esc_html( $ms8 ); ?></a>
					<?php echo esc_html( $ms9 ); ?>
                </p></div>
			<?php
		} else if ( ! $this->textdex->is_ready( 10 ) ) {
			$sa1  = __( 'See the', 'fast-woo-order-lookup' );
			$sa2  = __( 'Scheduled Actions status page', 'fast-woo-order-lookup' );
			$sa3  = __( 'for details.', 'fast-woo-order-lookup' );
			$frac = $this->textdex->fraction_complete();
			if ( $frac < 0.01 ) {
				$ms1 = __( 'Fast Woo Order Lookup indexing begins soon.', 'fast-woo-order-lookup' );
				$ms2 = '';
			} else {
				$percent = number_format( 100 * $this->textdex->fraction_complete(), 1 );
				$ms1     = __( 'Fast Woo Order Lookup indexing still in progress.', 'fast-woo-order-lookup' );
				/* translators: 1: percent 12.3 */
				$ms2 = __( '%1$s%% complete.', 'fast-woo-order-lookup' );
				$ms2 = sprintf( $ms2, $percent );
			}
			?>
            <div class="notice notice-info">
                <p>
					<?php echo esc_html( $ms1 ); ?>
					<?php echo esc_html( $ms2 ); ?>
					<?php echo esc_html( $sa1 ); ?>
                    <a href="admin.php?page=wc-status&tab=action-scheduler&s=fast_woo_order_lookup_textdex_action&status=pending"><?php echo esc_html( $sa2 ); ?></a>
					<?php echo esc_html( $sa3 ); ?>
                </p></div>
			<?php
		}
	}

	/**
	 * 'shutdown' action handler to update trigram indexes when orders change.
	 *
	 * @return void
	 */
	public function update_textdex() {
		if ( count( $this->orders_to_update ) > 0 ) {
			$this->update_meta_keys( array_keys( $this->orders_to_update ) );
			$this->textdex->update( array_keys( $this->orders_to_update ) );
		}
	}


	/**
	 * Filter. immediately before metadata search.
	 *
	 * This only works in legacy mode, not HPOS mode.
	 *
	 * @param array $search_fields
	 *
	 * @return array
	 */
	public function filter_search_fields( $search_fields ) {
		if ( ! $this->textdex->is_ready() ) {
			return $search_fields;
		}
		/* Hook to mung the queries. */
		$this->filtering = true;
		add_filter( 'query', array( $this, 'standard_query' ), 1 );

		return $search_fields;
	}

	/**
	 * Filter. Immediately after metadata search.
	 *
	 * This only works in legacy mode, not HPOS mode.
	 *
	 * @param array $order_ids
	 * @param string $term
	 * @param array $search_fields
	 *
	 * @return array
	 */
	public function filter_search_results( $order_ids, $term, $search_fields ) {

		if ( $this->filtering ) {
			/* Discontinue filtering queries after the metadata search */
			remove_filter( 'query', array( $this, 'standard_query' ), 1 );
			$this->filtering = false;
		}

		return $order_ids;
	}

	/**
	 * Mung the order metadata search queries to include the trigram selection clause.
	 *
	 * This is a MISERABLE hack. There aren't any suitable hooks to do this more elegantly.
	 *
	 * @param string $query Database query.
	 *
	 * @return string
	 */
	public function standard_query( $query ) {

		if ( ! $this->term ) {
			$splits = explode( 'LIKE \'%', $query, 2 );
			if ( 2 !== count( $splits ) ) {
				return $query;
			}
			$splits = explode( '%\'', $splits[1] );
			if ( 2 !== count( $splits ) ) {
				return $query;
			}
			$this->term           = $splits[0];
			$this->trigram_clause = $this->textdex->trigram_clause( $this->term );
		}
		$newQuery = $query;
		$munged   = false;
		if ( str_contains( $newQuery, 'woocommerce_order_items as order_item' ) ) {
			$newQuery = str_replace( 'WHERE order_item_name LIKE', 'WHERE order_id IN (' . $this->trigram_clause . ') AND order_item_name LIKE', $newQuery );
			$munged   = true;
		} else if ( str_contains( $newQuery, 'SELECT DISTINCT os.order_id FROM wp_wc_order_stats os' ) ) {
			/* empty, intentionally */
		} else {
			$newQuery = str_replace( 'postmeta p1 WHERE ', 'postmeta p1 WHERE post_id IN (' . $this->trigram_clause . ') AND ', $newQuery );
			$munged   = true;
		}
		$munged && $this->textdex->capture_query( $newQuery, 'search', false );

		return $newQuery;
	}

	/**
	 * HPOS filter. Fix up the args.
	 *
	 * We use this to know we're about to get some queries we need to mung.
	 *
	 * @param $args
	 *
	 * @return mixed
	 */
	public function woocommerce_order_query_args( $args ) {
		$its_search          = array_key_exists( 's', $args ) && is_string( $args['s'] ) && strlen( $args['s'] ) > 0;
		$its_order_id_search = array_key_exists( 'search_filter', $args ) && 'order_id' === $args ['search_filter'];
		if ( $its_search && ! $its_order_id_search && $this->textdex->is_ready() ) {
			$this->term           = $args['s'];
			$this->trigram_clause = $this->textdex->trigram_clause( $this->term );

			/* Hook to mung the queries. */
			$this->filtering = true;
			add_filter( 'query', array( $this, 'hpos_query' ), 1 );
		}

		return $args;
	}

	/**
	 *  HPOS filter. Fix up results.
	 *
	 * We use this to know we're done munging queries.
	 *
	 * @param $results
	 * @param $args
	 *
	 * @return mixed
	 */
	public function woocommerce_order_query( $results, $args ) {
		if ( $this->filtering ) {
			/* Discontinue filtering queries after the search */
			remove_filter( 'query', array( $this, 'hpos_query' ), 1 );
			$this->filtering = false;
		}

		return $results;
	}

	/**
	 * Mung the order metadata search queries to include the trigram selection clause, in the HPOS queries
	 *
	 * This is a MISERABLE hack. There aren't any suitable hooks to do this more elegantly.
	 *
	 * Two queries use this. They're the same except the second
	 * counts the resultset, without the LIMIT clause, of the first,
	 * which is Oracle MySQL's replacement for SQL_CALC_FOUND_ROWS.
	 *
	 * @param string $query Database query.
	 *
	 * @return string
	 */
	public function hpos_query( $query ) {
		global $wpdb;
		$orders     = $wpdb->prefix . 'wc_orders';
		$orderitems = $wpdb->prefix . 'woocommerce_order_items';

		/* Skip modifying the FULLTEXT search option choice. */
		if ( str_contains( $query, 'IN BOOLEAN MODE' ) ) {
			return $query;
		}
		if ( str_contains( $query, "$orderitems AS search_query_items ON search_query_items.order_id = $orders.id WHERE 1=1 AND" ) ||
		     str_contains( $query, "SELECT $orders.id FROM $orders  WHERE 1=1 AND" ) ||
		     str_contains( $query, "SELECT COUNT(*) FROM $orders  WHERE 1=1 AND" ) ||
		     str_contains( $query, "SELECT COUNT(DISTINCT $orders.id) FROM  $orders  WHERE 1=1 AND" )
		) {
			$query = str_replace( 'WHERE 1=1 AND', "WHERE 1=1 AND  $orders.id IN (" . $this->trigram_clause . ") AND ", $query );
			$this->textdex->capture_query( $query, 'search', false );

		}

		return $query;
	}

	/** Filter for custom order fields.
	 *
	 * Here we implement necessary monkeypatching for the query,
	 * and a cache for the keys.
	 *
	 *   https://github.com/woocommerce/woocommerce/issues/47212
	 *
	 * @param array|null $keys
	 * @param Automattic\WooCommerce\Admin\Overrides\Order $order
	 *
	 * @return array|null
	 */
	public function postmeta_get_order_custom_field_names( $keys, $order ) {
		if ( ! @is_a( $order, WC_Order::class ) ) {
			return $keys;
		}

		require_once( plugin_dir_path( __FILE__ ) . 'code/class-custom-fields.php' );
		$cust = new Custom_Fields();

		return $cust->get_order_custom_field_names();
	}

	private function update_meta_keys( array $orders ) {
		$cached_keys = get_transient( FAST_WOO_ORDER_LOOKUP_METAKEY_CACHE );
		if ( ! is_array( $cached_keys ) ) {
			return;
		}
		$changed = false;
		foreach ( $orders as $order_id ) {
			$order = wc_get_order( $order_id );
			$metas = wc_get_container()->get( OrdersTableDataStoreMeta::class )->read_meta( $order );
			foreach ( $metas as $meta ) {
				$meta_key = $meta->meta_key;
				if ( ! str_starts_with( $meta_key, '_' ) ) {
					if ( ! in_array( $meta_key, $cached_keys ) ) {
						$changed        = true;
						$cached_keys [] = $meta_key;
					}
				}
			}
		}
		if ( $changed ) {
			set_transient( FAST_WOO_ORDER_LOOKUP_METAKEY_CACHE, $cached_keys );
		}
	}
	/**
	 * Filters the array of row meta for each plugin in the Plugins list table.
	 *
	 * @param array<int, string> $plugin_meta An array of the plugin's metadata.
	 * @param string             $plugin_file Path to the plugin file relative to the plugins directory.
	 * @return array<int, string> Updated array of the plugin's metadata.
	 */
	public function filter_plugin_row_meta( array $plugin_meta, $plugin_file ) {
		if ( FAST_WOO_ORDER_LOOKUP_PLUGIN_BASE !== $plugin_file ) {
			return $plugin_meta;
		}

		$plugin_meta[] = sprintf(
			'<a href="%1$s"><span class="dashicons dashicons-star-filled" aria-hidden="true" style="font-size:14px;line-height:1.3"></span>%2$s</a>',
			'https://github.com/sponsors/OllieJones',
			esc_html_x( 'Sponsor', 'verb', 'index-wp-users-for-speed' )
		);

		return $plugin_meta;
	}


}

// Plugin name
const FAST_WOO_ORDER_LOOKUP_NAME          = 'Fast Woo Order Lookup';

// Plugin version
const FAST_WOO_ORDER_LOOKUP_VERSION       = '1.1.9';

// Plugin Root File
const FAST_WOO_ORDER_LOOKUP_PLUGIN_FILE   = __FILE__;

// Plugin base
define( 'FAST_WOO_ORDER_LOOKUP_PLUGIN_BASE', plugin_basename( FAST_WOO_ORDER_LOOKUP_PLUGIN_FILE ) );

// Plugin slug
define( 'FAST_WOO_ORDER_LOOKUP_SLUG', explode( DIRECTORY_SEPARATOR, FAST_WOO_ORDER_LOOKUP_PLUGIN_BASE )[0] );

// Plugin Folder Path
define( 'FAST_WOO_ORDER_LOOKUP_PLUGIN_DIR', plugin_dir_path( FAST_WOO_ORDER_LOOKUP_PLUGIN_FILE ) );

// Plugin Folder URL
define( 'FAST_WOO_ORDER_LOOKUP_PLUGIN_URL', plugin_dir_url( FAST_WOO_ORDER_LOOKUP_PLUGIN_FILE ) );


register_activation_hook( __FILE__, 'Fast_Woo_Order_Lookup\activate' );
register_deactivation_hook( __FILE__, 'Fast_Woo_Order_Lookup\deactivate' );

add_action( 'before_woocommerce_init', array( 'Fast_Woo_Order_Lookup\FastWooOrderLookup', 'declare_hpos_compatible' ) );

add_action( 'admin_init', function() {
	FastWooOrderLookup::getInstance();
}, 10, 0);

/* Hook anything that changes an order object */
add_action( 'woocommerce_new_order',
	array( 'Fast_Woo_Order_Lookup\FastWooOrderLookup', 'woocommerce_changing_order' ), 10, 2 );
add_action( 'woocommerce_update_order',
	array( 'Fast_Woo_Order_Lookup\FastWooOrderLookup', 'woocommerce_changing_order' ), 10, 2 );
add_action( 'woocommerce_order_object_updated_props',
	array( 'Fast_Woo_Order_Lookup\FastWooOrderLookup', 'woocommerce_order_object_updated_props' ), 10, 2 );
/* Hook changes to order status. */
add_action( 'woocommerce_delete_order',
	array( 'Fast_Woo_Order_Lookup\FastWooOrderLookup', 'woocommerce_deleting_order' ) );
add_action( 'woocommerce_trash_order',
	array( 'Fast_Woo_Order_Lookup\FastWooOrderLookup', 'woocommerce_deleting_order' ) );
add_action( 'woocommerce_untrash_order',
	array( 'Fast_Woo_Order_Lookup\FastWooOrderLookup', 'woocommerce_deleting_order' ) );
add_action( 'woocommerce_cancelled_order',
	array( 'Fast_Woo_Order_Lookup\FastWooOrderLookup', 'woocommerce_deleting_order' ) );
add_action( 'update_post_meta',
	array( 'Fast_Woo_Order_Lookup\FastWooOrderLookup', 'update_post_meta' ), 10, 4 );

/* ActionScheduler action for loading textdex. */
add_action( 'fast_woo_order_lookup_textdex_action',
	array( 'Fast_Woo_Order_Lookup\FastWooOrderLookup', 'textdex_batch_action' ) );

function activate() {
	register_uninstall_hook( __FILE__, 'Fast_Woo_Order_Lookup\uninstall' );
	require_once( plugin_dir_path( __FILE__ ) . 'code/class-textdex.php' );
	$textdex = new Textdex();
	$textdex->activate();
	$textdex->get_order_id_range();
	$textdex->load_textdex();

}

function deactivate() {

	require_once( plugin_dir_path( __FILE__ ) . 'code/class-textdex.php' );
	$textdex = new Textdex();
	$textdex->deactivate();
	delete_transient( FAST_WOO_ORDER_LOOKUP_METAKEY_CACHE );
}

function uninstall() {

}
