<?php
/**
 * Earnings / Sales Stats
 *
 * @package     EDD
 * @subpackage  Classes/Stats
 * @copyright   Copyright (c) 2012, Pippin Williamson
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       1.8
*/


/**
 * EDD_Stats Class
 *
 * This class is for retrieving stats for earnings and sales
 *
 * Stats can be retrieved for date ranges and pre-defined periods
 *
 * @since 1.8
 */
class EDD_Payment_Stats extends EDD_Stats {


	/**
	 * Retrieve sale stats
	 *
	 * @access public
	 * @since 1.8
	 * @param $download_id INT The download product to retrieve stats for. If false, gets stats for all products
	 * @param $status string The sale status to count. Only valid when retrieving global stats
	 * @return float|int
	 */
	public function get_sales( $download_id = 0, $start_date = false, $end_date = false, $status = 'publish' ) {

		$this->setup_dates( $start_date, $end_date );

		// Make sure start date is valid
		if( is_wp_error( $this->start_date ) )
			return $this->start_date;

		// Make sure end date is valid
		if( is_wp_error( $this->end_date ) )
			return $this->end_date;

		if( empty( $download_id ) ) {

			// Global sale stats
			add_filter( 'edd_count_payments_where', array( $this, 'count_where' ) );

			$count = edd_count_payments()->$status;

			remove_filter( 'edd_count_payments_where', array( $this, 'count_where' ) );

		} else {

			// Product specific stats
			global $edd_logs;

			add_filter( 'posts_where', array( $this, 'payments_where' ) );

			$count = $edd_logs->get_log_count( $download_id, 'sale' );

			remove_filter( 'posts_where', array( $this, 'payments_where' ) );

		}

		return $count;

	}


	/**
	 * Retrieve earning stats
	 *
	 * @access public
	 * @since 1.8
	 * @param $download_id INT The download product to retrieve stats for. If false, gets stats for all products
	 * @return float|int
	 */
	public function get_earnings( $download_id = 0, $start_date = false, $end_date = false ) {

		$this->setup_dates( $start_date, $end_date );

		// Make sure start date is valid
		if( is_wp_error( $this->start_date ) )
			return $this->start_date;

		// Make sure end date is valid
		if( is_wp_error( $this->end_date ) )
			return $this->end_date;

		$earnings = 0;

		add_filter( 'posts_where', array( $this, 'payments_where' ) );

		if( empty( $download_id ) ) {

			// Global earning stats

			$args = array(
				'post_type'              => 'edd_payment',
				'nopaging'               => true,
				'meta_key'               => '_edd_payment_mode',
				'meta_value'             => 'live',
				'post_status'            => array( 'publish', 'revoked' ),
				'fields'                 => 'ids',
				'update_post_term_cache' => false,
				'suppress_filters'       => false,
				'start_date'             => $this->start_date, // These dates are not valid query args, but they are used for cache keys
				'end_date'               => $this->end_date
			);

			$args = apply_filters( 'edd_stats_earnings_args', $args );
			$key  = md5( serialize( $args ) );

			$earnings = get_transient( $key );
			if( false === $earnings ) {
				$sales = get_posts( $args );
				$earnings = 0;
				if ( $sales ) {
					foreach ( $sales as $sale ) {
						$amount    = edd_get_payment_amount( $sale );
						$earnings  = $earnings + $amount;
					}
				}
				// Cache the results for one hour
				set_transient( $key, $earnings, 60*60 );
			}

		} else {

			// Download specific earning stats

			global $edd_logs, $wpdb;

			$args = array(
				'post_parent'      => $download_id,
				'nopaging'         => true,
				'log_type'         => 'sale',
				'fields'           => 'ids',
				'suppress_filters' => false,
				'start_date'       => $this->start_date, // These dates are not valid query args, but they are used for cache keys
				'end_date'         => $this->end_date
			);

			$args = apply_filters( 'edd_stats_earnings_args', $args );
			$key  = md5( serialize( $args ) );

			$earnings = get_transient( $key );
			if( false === $earnings ) {

				$log_ids  = $edd_logs->get_connected_logs( $args, 'sale' );
				$earnings = 0;

				if( $log_ids ) {
					$log_ids     = implode( ',', $log_ids );
					$payment_ids = $wpdb->get_col( "SELECT meta_value FROM $wpdb->postmeta WHERE meta_key='_edd_log_payment_id' AND post_id IN ($log_ids);" );

					foreach( $payment_ids as $payment_id ) {
						$items = edd_get_payment_meta_cart_details( $payment_id );
						foreach( $items as $item ) {
							$earnings += $item['price'];
						}
					}
				}

				// Cache the results for one hour
				set_transient( $key, $earnings, 60*60 );
			}
		}

		remove_filter( 'posts_where', array( $this, 'payments_where' ) );

		return round( $earnings, 2 );

	}

	/**
	 * Get the best selling products
	 *
	 * @access public
	 * @since 1.8
	 * @return array
	 */
	public function get_best_selling( $number = 10 ) {

		global $wpdb;

		$downloads = $wpdb->get_results( $wpdb->prepare(
			"SELECT post_id as download_id, max(meta_value) as sales
				FROM $wpdb->postmeta WHERE meta_key='_edd_download_sales' AND meta_value > 0
				GROUP BY meta_value
				DESC LIMIT %d;", $number
		) );

		return $downloads;
	}

}