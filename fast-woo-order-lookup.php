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
 * Version:       0.4.0
 * Author:        OllieJones
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

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FastWooOrderLookup {
	private static $instance = null;

	private $textdex;
	private $filtering = false;

	private $term;
	private $trigram_clause;
	private $orders_to_update = array();

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

	/**
	 * Filter: Data store name.
	 *
	 * @param string $store
	 *
	 * @return string
	 */
	public static function woocommerce_order_data_store( $store ) {
		if ( 'WC_Order_Data_Store_CPT' === $store || 'WCS_Subscription_Data_Store_CPT' === $store ) {
            self::getInstance();
		}

		return $store;
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
		/* Query manipulation */
		add_filter( 'woocommerce_shop_order_search_fields', array( $this, 'filter_search_fields' ) );
		add_filter( 'woocommerce_shop_subscription_search_fields', array( $this, 'filter_search_fields' ) );
		add_filter( 'woocommerce_shop_order_search_results', array( $this, 'filter_search_results' ), 10, 3 );
		add_filter( 'woocommerce_shop_subscription_search_results', array( $this, 'filter_search_results' ), 10, 3 );
		add_filter( 'woocommerce_order_query_args', array( $this, 'woocommerce_order_query_args' ) );
		add_filter( 'woocommerce_order_query', array( $this, 'woocommerce_order_query' ), 10, 2 );
		add_filter( 'postmeta_form_keys', array( $this, 'postmeta_form_keys' ), 10, 2 );

        $dir = plugin_dir_path( __FILE__ );
		require_once( $dir . 'code/class-textdex.php' );
		$this->textdex = new Textdex();
		$this->textdex->activate();
		$this->textdex->load_textdex();

		add_action( 'admin_notices', array( $this, 'show_status' ), 10, 0 );
		add_action( 'shutdown', array( $this, 'update_textdex' ), 1, 0 );
	}

	public function show_status() {
		if ( ! $this->textdex->is_ready() ) {
			load_plugin_textdomain( 'fast-woo-order-lookup', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
			$sa1     = __( 'See the', 'fast-woo-order-lookup' );
			$sa2     = __( 'Scheduled Actions status page', 'fast-woo-order-lookup' );
			$sa3     = __( 'for details.', 'fast-woo-order-lookup' );
			$percent = number_format( 100 * $this->textdex->fraction_complete() );
			if ( '0' === $percent ) {
				$ms1 = __( 'Fast Woo Order Lookup indexing begins soon.', 'fast-woo-order-lookup' );
				$ms2 = '';
			} else {
				$ms1 = __( 'Fast Woo Order Lookup indexing still in progress.', 'fast-woo-order-lookup' );
				/* translators: 1: percent complete integer */
				$ms2 = __( '%1$d%% complete.', 'fast-woo-order-lookup' );
				$ms2 = sprintf( $ms2, $percent );
			}
			?>
            <div class="notice notice-info">
                <p>
					<?php echo esc_html( $ms1 ); ?>
					<?php echo esc_html( $ms2 ); ?>
					<?php echo esc_html( $sa1 ); ?>
                    <a href="/wp-admin/admin.php?page=wc-status&tab=action-scheduler&s=fast_woo_order_lookup_textdex_action"><?php echo esc_html( $sa2 ); ?></a>
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
			$this->textdex->update( array_keys( $this->orders_to_update ) );
		}
	}


	/**
	 * Filter. immediately before metadata search.
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
		if ( str_contains( $newQuery, 'woocommerce_order_items as order_item' ) ) {
			$newQuery = str_replace( 'WHERE order_item_name LIKE', 'WHERE order_id IN (' . $this->trigram_clause . ') AND order_item_name LIKE', $newQuery );
		} else if ( str_contains( $newQuery, 'SELECT DISTINCT os.order_id FROM wp_wc_order_stats os' ) ) {
			/* empty, intentionally */
		} else {
			$newQuery = str_replace( 'postmeta p1 WHERE ', 'postmeta p1 WHERE post_id IN (' . $this->trigram_clause . ') AND ', $newQuery );
		}

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

		if ( str_contains( $query, "$orderitems AS search_query_items ON search_query_items.order_id = $orders.id WHERE 1=1 AND" ) ||
		     str_contains( $query, "SELECT $orders.id FROM $orders  WHERE 1=1 AND" ) ||
		     str_contains( $query, "SELECT COUNT(DISTINCT $orders.id) FROM  $orders  WHERE 1=1 AND" )
		) {
			return str_replace( 'WHERE 1=1 AND', "WHERE 1=1 AND  $orders.id IN (" . $this->trigram_clause . ") AND ", $query );
		}

		return $query;
	}

	/** Hook telling us to engage query monkeypatching for
	 *   https://github.com/woocommerce/woocommerce/issues/47212
	 *
	 * @param array|null $keys
	 * @param Automattic\WooCommerce\Admin\Overrides\Order $order
	 *
	 * @return mixed
	 */
	public function postmeta_form_keys( $keys, $order ) {
		if ( is_object( $order ) &&
		     false !== strstr( get_class( $order ), 'WooCommerce' ) ) {
			/* we are in WooCommerce someplace, mung the queries to come */
			$this->filtering = true;
			add_filter( 'query', array( $this, 'postmeta_form_keys_query' ), 1 );
		}
		return $keys;
	}

	/**
     *  Patch the query that looks for non-hidden (don't start with underscore) meta_keys
     *   so it doesn't take too long.
     *
     *  Note that even this query can be sped up by two orders of magnitude by getting rid of the
     *   prefix index.
     *
	 * @param string $query
	 *
	 * @return string
	 */
    public function postmeta_form_keys_query( $query ) {
        if (! $this->filtering) {
            return $query;
        }
        global $wpdb;
        $ordermeta = $wpdb->prefix . 'wc_orders_meta';
        $detect =  "SELECT DISTINCT meta_key FROM $ordermeta WHERE meta_key NOT LIKE '\\\\_%' ORDER BY meta_key ASC";
        $replace = "SELECT DISTINCT meta_key FROM $ordermeta WHERE meta_key NOT LIKE '\\\\_%' AND meta_key NOT BETWEEN '_a' AND '_z' AND meta_key <> '' ORDER BY meta_key ASC";
        if (null !== strstr($query, $detect)) {
            /* we can stop looking at queries as soon as we find ours. */
	        $query = str_replace( $detect, $replace, $query );
	        $this->filtering = false;
	        remove_filter( 'query', array( $this, 'postmeta_form_keys_query' ), 1 );
        }
	    return $query;
    }


}

// Plugin name
const FAST_WOO_ORDER_LOOKUP_NAME        = 'Fast Woo Order Lookup';

// Plugin version
const FAST_WOO_ORDER_LOOKUP_VERSION     = '0.4.0';

// Plugin Root File
const FAST_WOO_ORDER_LOOKUP_PLUGIN_FILE = __FILE__;

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

/* Don't do anything until WooCommerce does ->load( 'order' ). */
add_action( 'woocommerce_order_data_store',
	array( 'Fast_Woo_Order_Lookup\FastWooOrderLookup', 'woocommerce_order_data_store' ) );

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
	$textdex->load_textdex();

}

function deactivate() {

	require_once( plugin_dir_path( __FILE__ ) . 'code/class-textdex.php' );
	$textdex = new Textdex();
	$textdex->deactivate();
}

function uninstall() {

}
