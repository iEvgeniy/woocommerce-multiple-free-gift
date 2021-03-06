<?php
/**
 * Product Helper class: Fetch and analyze products
 *
 * @static
 * @package  woocommerce-multiple-free-gift
 * @subpackage lib/helpers
 * @author Ankit Pokhrel <info@ankitpokhrel.com.np, @ankitpokhrel>
 * @version 0.0.0
 */
class WFG_Product_Helper
{
	/**
	 * Fetch products based on given conditions
	 *
	 * @since  0.0.0
	 * @access public
	 * @static
	 *
	 * @param  array  $options Query params
	 * @return object|null
	 */
	public static function get_products( $options = array(), $limit = 15 )
	{
		$args = array(
				'post_type' => 'product',
				'post_status' => 'publish',
				'posts_per_page' => $limit,
				'cache_results' => false,
				'no_found_rows' => true
			);

		//merge default and user options
		$args = array_merge($args, $options);

		$products = new WP_Query( $args );
		wp_reset_postdata();

		return $products;
	}

	/**
	 * Fetch all product categories
	 *
	 * @since  0.0.0
	 * @access public
	 * @static
	 *
	 * @return array
	 */
	public static function get_product_categories()
	{
		$args = array(
			'taxonomy'     => 'product_cat',
			'orderby'      => 'name',
			'show_count'   => 0,
			'pad_counts'   => 0,
			'hierarchical' => 1,
			'title_li'     => '',
			'hide_empty'   => 0
		);

		return get_categories( $args );
	}

	/**
	 * Fetch items added to the cart.
	 *
	 * @since  0.0.0
	 * @access public
	 * @static
	 *
	 * @return array Items in cart
	 */
	public static function get_cart_products()
	{
		global $woocommerce;
		$cart_items = $woocommerce->cart->get_cart();

		$added_products = array();
		$added_products['count'] = count($cart_items);
		if( !empty($cart_items) ) {
			foreach( $cart_items as $cart_item ) {
				$added_products['ids'][] = $cart_item['product_id'];
				$added_products['objects'][] = $cart_item['data'];
			}
		}

		return $added_products;
	}

	/**
	 * Fetch gift items added to the cart.
	 *
	 * @since  0.0.0
	 * @access public
	 * @static
	 *
	 * @return array Gift items in cart
	 */
	public static function get_gift_products_in_cart()
	{
		$free_items = array();
		$cart_items = WC()->cart->cart_contents;
		if( empty($cart_items) ) {
			return $free_items;
		}

		foreach( $cart_items as $key => $content ) {
			$is_gift_product = !empty( $content['variation_id'] ) && (bool) get_post_meta( $content['variation_id'], '_wfg_gift_product' );
			if(  $is_gift_product ) {
				$free_items[] = $content['product_id'];
			}
		}

		return $free_items;
	}

	/**
	 * Fetch required product details for given product.
	 *
	 * @since  0.0.0
	 * @access public
	 * @static
	 *
	 * @param  integer $product_id Product to get details of
	 *
	 * @return object
	 */
	public static function get_product_details( $product_id )
	{
		$options = array( 'p' => $product_id );
		$product_details = self::get_products( $options );

		$wfg_product_details = array();
		if( !empty($product_details) && !empty($product_details->posts) ) {
			$wfg_product_details['detail'] = $product_details->post;
			$product_image = wp_get_attachment_image_src( get_post_thumbnail_id( $product_details->post->ID ), 'thumbnail' );
			$wfg_product_details['image'] = isset($product_image[0]) ? $product_image[0] : false;
		}

		return (object) $wfg_product_details;
	}

	/**
	 * Create variation product for given item.
	 *
	 * @since  0.0.0
	 * @access public
	 * @static
	 *
	 * @param  integer $product_id Product to create variation of
	 *
	 * @return integer Product variation id
	 */
	public static function create_gift_variation( $product_id )
	{
		//check if product variation already exists
		$product_variation = get_posts( array(
								'post_parent' => $product_id,
								'post_title' => 'wfg_gift_product',
								'post_type' => 'product_variation',
								'posts_per_page' => 1
							)
					);

		if( !empty($product_variation) ) {
			//make price zero and mark it as wfg_product
			update_post_meta( $product_variation[0]->ID, '_price', 0);
			update_post_meta( $product_variation[0]->ID, '_regular_price', 0);
			update_post_meta( $product_variation[0]->ID, '_wfg_gift_product', 1);

			return $product_variation[0]->ID;
		}

		//if product variation doesn't exist, add one
		$admin = get_users( 'orderby=nicename&role=administrator&number=1' );
		$variation = array(
			'post_author' => $admin[0]->ID,
			'post_status' => 'publish',
			'post_name' => 'product-' . $product_id . '-variation',
			'post_parent' => $product_id,
			'post_title' => 'wfg_gift_product',
			'post_type' => 'product_variation',
			'comment_status' => 'closed',
			'ping_status' => 'closed'
		);

		$post_id = wp_insert_post( $variation );
		update_post_meta( $post_id, '_price', 0);
		update_post_meta( $post_id, '_regular_price', 0);
		update_post_meta( $post_id, '_wfg_gift_product', 1);

		return $post_id;
	}

	/**
	 * Add free gift item to cart.
	 *
	 * @since  0.0.0
	 * @access public
	 * @static
	 *
	 * @param integer $parent_product_id Main product id
	 * @param integer $product_id        Product variation id
	 *
	 * @return  void
	 */
	public static function add_free_product_to_cart( $parent_product_id, $product_id )
	{
		$found = false;
		//check if product is already in cart
		if ( count( WC()->cart->get_cart() ) > 0 ) {
			foreach ( WC()->cart->get_cart() as $cart_item_key => $values ) {
				$_product = $values['data'];
				if ( $_product->id == $product_id ) {
					$found = true;
				}
			}

			// if product not found, add it
			if ( !$found ) {
				WC()->cart->add_to_cart(
						$product_id,
						1,
						$parent_product_id,
						array( WFG_Common_Helper::translate('Type') => WFG_Common_Helper::translate('Free Item') )
				);
			}
		}
	}

	/**
	 * Check if gift item added is valid.
	 *
	 * @since  0.0.0
	 * @access public
	 * @static
	 *
	 * @param  array $items_added Items added as free gift
	 *
	 * @return boolean
	 */
	public static function crosscheck_gift_items( $items_added, $gift_items )
	{
		foreach( $items_added as $item ) {
			if( !in_array( $item, $gift_items ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Count total product excluding gift items
	 *
	 * @since  0.0.0
	 * @access public
	 * @static
	 *
	 * @return integer
	 */
	public static function get_main_product_count()
	{
		$count = 0;
		foreach( WC()->cart->cart_contents as $key => $content ) {
			$is_gift_product = !empty( $content['variation_id'] ) && (bool) get_post_meta( $content['variation_id'], '_wfg_gift_product' );
			if(  !$is_gift_product ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Count total quantity excluding gift items
	 *
	 * @since  0.0.0
	 * @access public
	 * @static
	 *
	 * @return integer
	 */
	public static function get_main_product_quantity_count()
	{
		$count = 0;
		foreach( WC()->cart->cart_contents as $key => $content ) {
			$is_gift_product = !empty( $content['variation_id'] ) && (bool) get_post_meta( $content['variation_id'], '_wfg_gift_product' );
			if(  !$is_gift_product ) {
				$count += (int) $content['quantity'];
			}
		}

		return $count;
	}

	/**
	 * Category wise product count
	 *
	 * @since  0.0.0
	 * @access public
	 * @static
	 *
	 * @return integer
	 */
	public static function get_category_products_count()
	{
		$products = array();
		foreach( WC()->cart->cart_contents as $key => $content ) {
			$is_gift_product = !empty( $content['variation_id'] ) && (bool) get_post_meta( $content['variation_id'], '_wfg_gift_product' );
			if(  !$is_gift_product ) {
				$terms = get_the_terms( $content['product_id'], 'product_cat' );
				if( !empty($terms) ) {
					foreach( $terms as $term ) {
						if( isset($products[ $term->term_id ]) ) {
							$products[ $term->term_id ] += 1;
						} else {
							$products[ $term->term_id ] = 1;
						}
					}
				}
			}
		}

		return $products;
	}

	/**
	 * Return max from category products count
	 *
	 * @since  0.0.0
	 * @access public
	 * @static
	 *
	 * @return integer
	 */
	public static function get_max_category_products_count()
	{
		$products = self::get_category_products_count();

		return !empty($products) ? max($products) : 0;
	}

	/**
	 * Category wise quantity count
	 *
	 * @since  0.0.0
	 * @access public
	 * @static
	 *
	 * @return integer
	 */
	public static function get_category_quantity_count()
	{
		$products = array();
		foreach( WC()->cart->cart_contents as $key => $content ) {
			$is_gift_product = !empty( $content['variation_id'] ) && (bool) get_post_meta( $content['variation_id'], '_wfg_gift_product' );
			if(  !$is_gift_product ) {
				$terms = get_the_terms( $content['product_id'], 'product_cat' );
				if( !empty($terms) ) {
					foreach( $terms as $term ) {
						if( isset($products[ $term->term_id ]) ) {
							$products[ $term->term_id ] += $content['quantity'];
						} else {
							$products[ $term->term_id ] = $content['quantity'];
						}
					}
				}
			}
		}

		return $products;
	}

	/**
	 * Return max from category quantity count
	 *
	 * @since  0.0.0
	 * @access public
	 * @static
	 *
	 * @return integer
	 */
	public static function get_max_category_quantity_count()
	{
		$products = self::get_category_quantity_count();

		return !empty($products) ? max($products) : 0;
	}

}
