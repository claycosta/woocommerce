<?php
/**
 * WooCommerce API Coupons Class
 *
 * Handles requests to the /coupons endpoint
 *
 * @author      WooThemes
 * @category    API
 * @package     WooCommerce/API
 * @since       2.2
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly


class WC_API_Coupons extends WC_API_Resource {

	/** @var string $base the route base */
	protected $base = '/coupons';

	/**
	 * Register the routes for this class
	 *
	 * GET /coupons
	 * GET /coupons/count
	 * GET /coupons/<id>
	 *
	 * @since 2.2
	 * @param array $routes
	 * @return array
	 */
	public function register_routes( $routes ) {

		# GET /coupons
		$routes[ $this->base ] = array(
			array( array( $this, 'get_coupons' ),     WC_API_Server::READABLE ),
			array( array( $this, 'create_coupon' ),   WC_API_SERVER::CREATABLE | WC_API_Server::ACCEPT_DATA ),
		);

		# GET /coupons/count
		$routes[ $this->base . '/count'] = array(
			array( array( $this, 'get_coupons_count' ), WC_API_Server::READABLE ),
		);

		# GET/PUT/DELETE /coupons/<id>
		$routes[ $this->base . '/(?P<id>\d+)' ] = array(
			array( array( $this, 'get_coupon' ),    WC_API_Server::READABLE ),
			array( array( $this, 'edit_coupon' ),   WC_API_SERVER::EDITABLE | WC_API_SERVER::ACCEPT_DATA ),
			array( array( $this, 'delete_coupon' ), WC_API_SERVER::DELETABLE ),
		);

		# GET /coupons/code/<code>, note that coupon codes can contain spaces, dashes and underscores
		$routes[ $this->base . '/code/(?P<code>\w[\w\s\-]*)' ] = array(
			array( array( $this, 'get_coupon_by_code' ), WC_API_Server::READABLE ),
		);

		return $routes;
	}

	/**
	 * Get all coupons
	 *
	 * @since 2.1
	 * @param string $fields
	 * @param array $filter
	 * @param int $page
	 * @return array
	 */
	public function get_coupons( $fields = null, $filter = array(), $page = 1 ) {

		$filter['page'] = $page;

		$query = $this->query_coupons( $filter );

		$coupons = array();

		foreach( $query->posts as $coupon_id ) {

			if ( ! $this->is_readable( $coupon_id ) )
				continue;

			$coupons[] = current( $this->get_coupon( $coupon_id, $fields ) );
		}

		$this->server->add_pagination_headers( $query );

		return array( 'coupons' => $coupons );
	}

	/**
	 * Get the coupon for the given ID
	 *
	 * @since 2.1
	 * @param int $id the coupon ID
	 * @param string $fields fields to include in response
	 * @return array|WP_Error
	 */
	public function get_coupon( $id, $fields = null ) {
		global $wpdb;

		$id = $this->validate_request( $id, 'shop_coupon', 'read' );

		if ( is_wp_error( $id ) )
			return $id;

		// get the coupon code
		$code = $wpdb->get_var( $wpdb->prepare( "SELECT post_title FROM $wpdb->posts WHERE id = %s AND post_type = 'shop_coupon' AND post_status = 'publish'", $id ) );

		if ( is_null( $code ) )
			return new WP_Error( 'woocommerce_api_invalid_coupon_id', __( 'Invalid coupon ID', 'woocommerce' ), array( 'status' => 404 ) );

		$coupon = new WC_Coupon( $code );

		$coupon_post = get_post( $coupon->id );

		$coupon_data = array(
			'id'                           => $coupon->id,
			'code'                         => $coupon->code,
			'type'                         => $coupon->type,
			'created_at'                   => $this->server->format_datetime( $coupon_post->post_date_gmt ),
			'updated_at'                   => $this->server->format_datetime( $coupon_post->post_modified_gmt ),
			'amount'                       => wc_format_decimal( $coupon->amount, 2 ),
			'individual_use'               => ( 'yes' === $coupon->individual_use ),
			'product_ids'                  => array_map( 'absint', (array) $coupon->product_ids ),
			'exclude_product_ids'          => array_map( 'absint', (array) $coupon->exclude_product_ids ),
			'usage_limit'                  => ( ! empty( $coupon->usage_limit ) ) ? $coupon->usage_limit : null,
			'usage_limit_per_user'         => ( ! empty( $coupon->usage_limit_per_user ) ) ? $coupon->usage_limit_per_user : null,
			'limit_usage_to_x_items'       => (int) $coupon->limit_usage_to_x_items,
			'usage_count'                  => (int) $coupon->usage_count,
			'expiry_date'                  => ( ! empty( $coupon->expiry_date ) ) ? $this->server->format_datetime( $coupon->expiry_date ) : null,
			'apply_before_tax'             => $coupon->apply_before_tax(),
			'enable_free_shipping'         => $coupon->enable_free_shipping(),
			'product_category_ids'         => array_map( 'absint', (array) $coupon->product_categories ),
			'exclude_product_category_ids' => array_map( 'absint', (array) $coupon->exclude_product_categories ),
			'exclude_sale_items'           => $coupon->exclude_sale_items(),
			'minimum_amount'               => wc_format_decimal( $coupon->minimum_amount, 2 ),
			'customer_emails'              => $coupon->customer_email,
		);

		return array( 'coupon' => apply_filters( 'woocommerce_api_coupon_response', $coupon_data, $coupon, $fields, $this->server ) );
	}

	/**
	 * Get the total number of coupons
	 *
	 * @since 2.1
	 * @param array $filter
	 * @return array
	 */
	public function get_coupons_count( $filter = array() ) {

		$query = $this->query_coupons( $filter );

		if ( ! current_user_can( 'read_private_shop_coupons' ) )
			return new WP_Error( 'woocommerce_api_user_cannot_read_coupons_count', __( 'You do not have permission to read the coupons count', 'woocommerce' ), array( 'status' => 401 ) );

		return array( 'count' => (int) $query->found_posts );
	}

	/**
	 * Get the coupon for the given code
	 *
	 * @since 2.1
	 * @param string $code the coupon code
	 * @param string $fields fields to include in response
	 * @return int|WP_Error
	 */
	public function get_coupon_by_code( $code, $fields = null ) {
		global $wpdb;

		$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $wpdb->posts WHERE post_title = %s AND post_type = 'shop_coupon' AND post_status = 'publish'", $code ) );

		if ( is_null( $id ) )
			return new WP_Error( 'woocommerce_api_invalid_coupon_code', __( 'Invalid coupon code', 'woocommerce' ), array( 'status' => 404 ) );

		return $this->get_coupon( $id, $fields );
	}

	/**
	 * Add/Update coupon data.
	 *
	 * @since 2.2
	 * @param string $code the coupon code
	 * @param int $id the coupon ID
	 * @param array $data
	 * @return void
	 */
	protected function update_coupon_data( $code, $id, $data ) {
		// Gets the coupon old data.
		$coupon = new WC_Coupon( $code );

		// Coupon data.
		$type                   = isset( $data['type'] ) ? wc_clean( $data['type'] ) : $coupon->type;
		$amount                 = isset( $data['amount'] ) ? wc_format_decimal( $data['amount'] ) : $coupon->amount;
		$usage_limit            = ( isset( $data['usage_limit'] ) && ! empty( $data['usage_limit'] ) ) ? absint( $data['usage_limit'] ) : $coupon->usage_limit;
		$usage_limit_per_user   = ( isset( $data['usage_limit_per_user'] ) && ! empty( $data['usage_limit_per_user'] ) ) ? absint( $data['usage_limit_per_user'] ) : $coupon->usage_limit_per_user;
		$limit_usage_to_x_items = ( isset( $data['limit_usage_to_x_items'] ) && ! empty( $data['limit_usage_to_x_items'] ) ) ? absint( $data['limit_usage_to_x_items'] ) : $coupon->limit_usage_to_x_items;
		$expiry_date            = isset( $data['expiry_date'] ) ? wc_clean( $data['expiry_date'] ) : $coupon->expiry_date;
		$minimum_amount         = isset( $data['minimum_amount'] ) ? wc_format_decimal( $data['minimum_amount'] ) : $coupon->minimum_amount;
		$customer_email         = ( isset( $data['customer_emails'] ) && is_array( $data['customer_emails'] ) ) ? array_filter( array_map( 'sanitize_email', $data['customer_emails'] ) ) : (array) $coupon->customer_email;

		if ( isset( $data['individual_use'] ) ) {
			$individual_use = ( true == $data['individual_use'] ) ? 'yes' : 'no';
		} else {
			$individual_use = ( ! empty( $coupon->individual_use ) ) ? $coupon->individual_use : 'no';
		}

		if ( isset( $data['apply_before_tax'] ) ) {
			$apply_before_tax = ( true == $data['apply_before_tax'] ) ? 'yes' : 'no';
		} else {
			$apply_before_tax = ( ! empty( $coupon->apply_before_tax ) ) ? $coupon->apply_before_tax : 'no';
		}

		if ( isset( $data['free_shipping'] ) ) {
			$free_shipping = ( true == $data['free_shipping'] ) ? 'yes' : 'no';
		} else {
			$free_shipping = ( ! empty( $coupon->free_shipping ) ) ? $coupon->free_shipping : 'no';
		}

		if ( isset( $data['exclude_sale_items'] ) ) {
			$exclude_sale_items = ( true == $data['exclude_sale_items'] ) ? 'yes' : 'no';
		} else {
			$exclude_sale_items = ( ! empty( $coupon->exclude_sale_items ) ) ? $coupon->exclude_sale_items : 'no';
		}

		if ( isset( $data['product_ids'] ) && is_array( $data['product_ids'] ) ) {
			$product_ids = implode( ',', array_filter( array_map( 'intval', (array) $data['product_ids'] ) ) );
		} else {
			$product_ids = ( is_array( $coupon->product_ids ) ) ? implode( ',', array_filter( array_map( 'intval', (array) $coupon->product_ids ) ) ) : '';
		}

		if ( isset( $data['exclude_product_ids'] ) && is_array( $data['exclude_product_ids'] ) ) {
			$exclude_product_ids = implode( ',', array_filter( array_map( 'intval', (array) $data['exclude_product_ids'] ) ) );
		} else {
			$exclude_product_ids = ( is_array( $coupon->exclude_product_ids ) ) ? implode( ',', array_filter( array_map( 'intval', (array) $coupon->exclude_product_ids ) ) ) : '';
		}

		$product_categories         = isset( $data['product_category_ids'] ) ? array_map( 'intval', $data['product_category_ids'] ) : (array) $coupon->product_categories;
		$exclude_product_categories = isset( $data['exclude_product_category_ids'] ) ? array_map( 'intval', $data['exclude_product_category_ids'] ) : (array) $coupon->exclude_product_categories;

		// Save coupon data.
		update_post_meta( $id, 'discount_type', $type );
		update_post_meta( $id, 'coupon_amount', $amount );
		update_post_meta( $id, 'individual_use', $individual_use );
		update_post_meta( $id, 'product_ids', $product_ids );
		update_post_meta( $id, 'exclude_product_ids', $exclude_product_ids );
		update_post_meta( $id, 'usage_limit', $usage_limit );
		update_post_meta( $id, 'usage_limit_per_user', $usage_limit_per_user );
		update_post_meta( $id, 'limit_usage_to_x_items', $limit_usage_to_x_items );
		update_post_meta( $id, 'expiry_date', $expiry_date );
		update_post_meta( $id, 'apply_before_tax', $apply_before_tax );
		update_post_meta( $id, 'free_shipping', $free_shipping );
		update_post_meta( $id, 'exclude_sale_items', $exclude_sale_items );
		update_post_meta( $id, 'product_categories', $product_categories );
		update_post_meta( $id, 'exclude_product_categories', $exclude_product_categories );
		update_post_meta( $id, 'minimum_amount', $minimum_amount );
		update_post_meta( $id, 'customer_email', $customer_email );

		do_action( 'woocommerce_api_update_coupon_data', $code, $id, $data );
	}

	/**
	 * Create a coupon
	 *
	 * @since 2.2
	 * @param array $data
	 * @return array
	 */
	public function create_coupon( $data ) {
		global $wpdb;

		// Checks with can publish new posts.
		if ( ! current_user_can( 'publish_posts' ) ) {
			return new WP_Error( 'woocommerce_api_user_cannot_create_coupon', __( 'You do not have permission to create this coupon', 'woocommerce' ), array( 'status' => 401 ) );
		}

		// Checks with the code is missing.
		if ( ! isset( $data['code'] ) ) {
			return new WP_Error( 'woocommerce_api_missing_coupon_code', sprintf( __( 'Missing parameter %s', 'woocommerce' ), 'code' ), array( 'status' => 400 ) );
		}

		// Checks with the type is missing.
		if ( ! isset( $data['type'] ) ) {
			return new WP_Error( 'woocommerce_api_missing_coupon_type', sprintf( __( 'Missing parameter %s', 'woocommerce' ), 'type' ), array( 'status' => 400 ) );
		}

		// Checks with the amount is missing.
		if ( ! isset( $data['amount'] ) ) {
			return new WP_Error( 'woocommerce_api_missing_coupon_amount', sprintf( __( 'Missing parameter %s', 'woocommerce' ), 'amount' ), array( 'status' => 400 ) );
		}

		// Validate the coupon type.
		if ( ! in_array( sanitize_text_field( $data['type'] ), array_keys( wc_get_coupon_types() ) ) ) {
			return new WP_Error( 'woocommerce_api_invalid_coupon_type', sprintf( __( 'Invalid coupon type - the coupon type must be: %s', 'woocommerce' ), implode( ', ', array_keys( wc_get_coupon_types() ) ) ), array( 'status' => 400 ) );
		}

		// Sets the coupon code.
		$code = apply_filters( 'woocommerce_coupon_code', $data['code'] );

		// Check for dupe coupons.
		$coupon_found = $wpdb->get_var( $wpdb->prepare( "
			SELECT $wpdb->posts.ID
			FROM $wpdb->posts
			WHERE $wpdb->posts.post_type = 'shop_coupon'
			AND $wpdb->posts.post_status = 'publish'
			AND $wpdb->posts.post_title = '%s'
		 ", $code ) );

		if ( $coupon_found ) {
			return new WP_Error( 'woocommerce_api_coupon_code_already_exists', __( 'Coupon code already exists - customers will use the latest coupon with this code.', 'woocommerce' ), array( 'status' => 400 ) );
		}

		// Attempts to create the new coupon.
		$coupon_data = apply_filters( 'woocommerce_api_coupon_insert_data', array(
			'post_title'   => $code,
			'post_content' => '',
			'post_status'  => 'publish',
			'post_author'  => 1,
			'post_type'    => 'shop_coupon'
		) );
		$id = wp_insert_post( $coupon_data );

		// Checks for an error in the customer creation.
		if ( is_wp_error( $id ) ) {
			return new WP_Error( 'woocommerce_api_user_cannot_create_coupon', $id->get_error_message(), array( 'status' => 500 ) );
		}

		// Adds coupon data.
		$this->update_coupon_data( $code, $id, $data );

		do_action( 'woocommerce_api_create_coupon', $id, $data );

		return $this->get_coupon( $id );
	}

	/**
	 * Edit a coupon
	 *
	 * @since 2.2
	 * @param int $id the coupon ID
	 * @param array $data
	 * @return array
	 */
	public function edit_coupon( $id, $data ) {
		global $wpdb;

		// Validate the coupon ID.
		$id = $this->validate_request( $id, 'shop_coupon', 'edit' );

		// Return the validate error.
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		// Get the coupon code.
		$code = $wpdb->get_var( $wpdb->prepare( "
			SELECT post_title
			FROM $wpdb->posts
			WHERE id = %s
			AND post_type = 'shop_coupon'
			AND post_status = 'publish'
		 ", $id ) );

		// Edit coupon code.
		if ( isset( $data['code'] ) ) {
			$code = apply_filters( 'woocommerce_coupon_code', $data['code'] );

			// Check for dupe coupons.
			$coupon_found = $wpdb->get_var( $wpdb->prepare( "
				SELECT $wpdb->posts.ID
				FROM $wpdb->posts
				WHERE $wpdb->posts.post_type = 'shop_coupon'
				AND $wpdb->posts.post_status = 'publish'
				AND $wpdb->posts.post_title = '%s'
				AND $wpdb->posts.ID != %s
			 ", $code, $id ) );

			if ( $coupon_found ) {
				return new WP_Error( 'woocommerce_api_coupon_code_already_exists', __( 'Coupon code already exists - customers will use the latest coupon with this code.', 'woocommerce' ), array( 'status' => 400 ) );
			}

			wp_update_post( array( 'ID' => $id, 'post_title' => $code ) );
		}

		// Validate the coupon type.
		if ( isset( $data['type'] ) && ! in_array( sanitize_text_field( $data['type'] ), array_keys( wc_get_coupon_types() ) ) ) {
			return new WP_Error( 'woocommerce_api_invalid_coupon_type', sprintf( __( 'Invalid coupon type - the coupon type must be: %s', 'woocommerce' ), implode( ', ', array_keys( wc_get_coupon_types() ) ) ), array( 'status' => 400 ) );
		}

		// Adds coupon data.
		$this->update_coupon_data( $code, $id, $data );

		do_action( 'woocommerce_api_edit_coupon', $id, $data );

		return $this->get_coupon( $id );
	}

	/**
	 * Delete a coupon
	 *
	 * @since 2.2
	 * @param int $id the coupon ID
	 * @param bool $force true to permanently delete coupon, false to move to trash
	 * @return array
	 */
	public function delete_coupon( $id, $force = false ) {

		$id = $this->validate_request( $id, 'shop_coupon', 'delete' );

		// Return the validate error.
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		return $this->delete( $id, 'shop_coupon', ( 'true' === $force ) );
	}

	/**
	 * Helper method to get coupon post objects
	 *
	 * @since 2.1
	 * @param array $args request arguments for filtering query
	 * @return WP_Query
	 */
	private function query_coupons( $args ) {

		// set base query arguments
		$query_args = array(
			'fields'      => 'ids',
			'post_type'   => 'shop_coupon',
			'post_status' => 'publish',
		);

		$query_args = $this->merge_query_args( $query_args, $args );

		return new WP_Query( $query_args );
	}

}
