<?php
/*
Plugin Name:	   Order Editing for Easy Digital Downloads
Plugin URI:        https://wpzone.co/
Description:       Add order editing controls to the Orders backend in Easy Digital Downloads 3
Version:           0.1.0
Author:            WP Zone
License:           GPLv3+
License URI:       http://www.gnu.org/licenses/gpl.html
*/

/*
Order Editing for Easy Digital Downloads
Copyright (C) 2024  WP Zone

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <https://www.gnu.org/licenses/>.


This plugin includes code based on Easy Digital Downloads.
Copyright (c) Sandhills Development, LLC
Released under the GNU General Public License version 2 or later.

This plugin includes code based on WordPress. WordPress licensing and
copyright information is included in ./license.txt.
*/

class WpzEddOrderEditing {
	
	const VERSION = '0.1.0';
	private $pluginUrl;
	
	function __construct() {
		$this->pluginUrl = plugin_dir_url(__FILE__);
		add_action('edd_view_order_details_main_before', [$this, 'beforeOrderDetails']);
		add_action('edd_view_order_details_main_after', [$this, 'afterOrderDetails']);
		add_action('edd_updated_edited_purchase', [$this, 'onEditOrder']);
	}
	
	function beforeOrderDetails() {
		ob_start();
	}
	
	function afterOrderDetails($orderId) {
		global $wp_scripts;
		if (isset($wp_scripts->registered['edd-admin-orders']->extra['data'])) {
			
			$output = preg_replace('#\\<div.+class\\="edd-order-overview-actions__locked".*\\>.*\\</div\\>#Us', '', ob_get_clean());
			$tmplStart = strpos($output, '"tmpl-edd-admin-order-actions"');
			$tmplStart = strpos($output, '>', $tmplStart) + 1;
			
			$dataBefore = self::parseJsData($wp_scripts->registered['edd-admin-orders']->extra['data']);
			
			if (isset($_GET['view'])) {
				$viewBefore = sanitize_text_field($_GET['view']);
			}
			$_GET['view'] = 'add-order';
			
			$wp_scripts->registered['edd-admin-orders']->extra['data'] = '';
			
			$order = edd_get_order($orderId);
			
			ob_start();
			edd_order_details_overview( $order );
			ob_clean();
			require(EDD_PLUGIN_DIR.'includes/admin/views/tmpl-order-actions.php');
			$orderActions = ob_get_clean();
			
			if (isset($viewBefore)) {
				$_GET['view'] = $viewBefore;
			} else {
				unset($_GET['view']);
			}
			
			echo(substr($output, 0, $tmplStart).$orderActions.substr($output, $tmplStart));
			
			$dataAfter = self::parseJsData($wp_scripts->registered['edd-admin-orders']->extra['data']);
			
			$wp_scripts->registered['edd-admin-orders']->extra['data'] = $dataBefore[1].wp_json_encode(array_merge(
				$dataBefore[0],
				array_intersect_key(
					$dataAfter[0],
					[
						'isAdding' => true,
						'hasTax' => true
					]
				)
			));
			
			foreach ([
						'item' => __('Add Download', 'wpz-edd-order-editing'),
						'discount' => __('Add Discount', 'wpz-edd-order-editing'),
						'adjustment' => __('Add Adjustment', 'wpz-edd-order-editing'),
					] as $itemId => $dialogTitle) {
				echo('<div id="edd-admin-order-add-'.esc_attr($itemId).'-dialog" title="'.esc_attr($dialogTitle).'" style="display:none;"><div id="edd-admin-order-add-'.esc_attr($itemId).'-dialog-content"></div></div>');
			}
			
			foreach ([
						'customer' => __('Please select an existing customer or create a new customer.', 'wpz-edd-order-editing'),
						'no-items' => __('Please add an item to this order.', 'wpz-edd-order-editing')
					] as $errorId => $errorText) {
				
					echo('<div class="notice notice-error inline" id="edd-add-order-'.esc_attr($errorId).'-error" style="display: none;"><p><strong>'.esc_html__( 'Error', 'wpz-edd-order-editing' ).':</strong>'.esc_html($errorText).'</p></div>');
			}
		}
	}
	
	function onEditOrder($orderId) {
		
		$order = edd_get_order($orderId);
		$existingOrderItems = $order->get_items();
		$existingAdjustments = $order->get_adjustments();
		
		// Amounts
		$order_subtotal = floatval( $_POST['subtotal'] );
		$order_tax      = floatval( $_POST['tax'] );
		$order_discount = floatval( $_POST['discount'] );
		$order_total    = floatval( $_POST['total'] );
		
		edd_update_order(
			$orderId,
			array(
				'subtotal'     => $order_subtotal,
				'tax'          => $order_tax,
				'discount'     => $order_discount,
				'total'        => $order_total,
			)
		);
		
		
		/** Insert order items ****************************************************/
		if ( ! empty( $_POST['downloads'] ) ) {

			foreach ( array_values( $_POST['downloads'] ) as $cart_key => $download ) {
				$d = edd_get_download( absint( $download['id'] ) );

				// Skip if download no longer exists
				if ( empty( $d ) ) {
					continue;
				}

				// Quantity.
				$quantity = isset( $download['quantity'] )
					? absint( $download['quantity'] )
					: 1;

				// Price ID.
				$price_id = isset( $download['price_id'] ) && is_numeric( $download['price_id'] )
					? absint( $download['price_id'] )
					: null;

				// Amounts.
				$amount = isset( $download[ 'amount' ] )
					? floatval( $download[ 'amount' ] )
					: 0.00;

				$subtotal = isset( $download[ 'subtotal' ] )
					? floatval( $download[ 'subtotal' ] )
					: 0.00;

				$discount = isset( $download[ 'discount' ] )
					? floatval( $download[ 'discount' ] )
					: 0.00;

				$tax = isset( $download[ 'tax' ] )
					? floatval( $download[ 'tax' ] )
					: 0.00;

				$total = isset( $download[ 'total' ] )
					? floatval( $download[ 'total' ] )
					: 0.00;
				
				unset($existingOrderItemId);
				foreach ($existingOrderItems as $k => $item) {
					if ($item->product_id == $download['id'] && $item->price_id == $price_id) {
						$existingOrderItemId = $item->id;
						unset($existingOrderItems[$k]);
						break;
					}
				}
				
				if (isset($existingOrderItemId)) {
					edd_update_order_item( $existingOrderItemId, array(
						'quantity'     => $quantity,
						'amount'       => $amount,
						'subtotal'     => $subtotal,
						'discount'     => $discount,
						'tax'          => $tax,
						'total'        => $total,
						'cart_index'   => sanitize_text_field( $cart_key )
					) );
				} else {
					edd_add_order_item( array(
						'order_id'     => $orderId,
						'product_id'   => absint( $download['id'] ),
						'product_name' => edd_get_download_name( absint( $download['id'] ), absint( $price_id ) ),
						'price_id'     => $price_id,
						'cart_index'   => sanitize_text_field( $cart_key ),
						'type'         => 'download',
						'status'       => 'complete',
						'quantity'     => $quantity,
						'amount'       => $amount,
						'subtotal'     => $subtotal,
						'discount'     => $discount,
						'tax'          => $tax,
						'total'        => $total,
					) );
				}
			}
		}

		/** Insert adjustments ****************************************************/

		// Adjustments.
		if ( isset( $_POST['adjustments'] ) ) {

			foreach ( $_POST['adjustments'] as $index => $adjustment ) {
				if ( 'order_item' === $adjustment['object_type'] ) {
					continue;
				}

				$type_key = ! empty( $adjustment['description'] )
					? sanitize_text_field( strtolower( sanitize_title( $adjustment['description'] ) ) )
					: sanitize_text_field( $index );

				$adjustment_subtotal = floatval( $adjustment['subtotal'] );
				$adjustment_tax      = floatval( $adjustment['tax'] );
				$adjustment_total    = floatval( $adjustment['total'] );
				
				foreach ($existingAdjustments as $k => $existingAdjustment) {
					if ($existingAdjustment->id && $existingAdjustment->type == $adjustment['type']) {
						edd_update_order_adjustment( $existingAdjustment->id, array(
							'type_key'    => $type_key,
							'description' => sanitize_text_field( $adjustment['description'] ),
							'subtotal'    => $adjustment_subtotal,
							'tax'         => $adjustment_tax,
							'total'       => $adjustment_total,
						) );
						unset($existingAdjustments[$k]);
						continue 2;
					}
				}

				edd_add_order_adjustment( array(
					'object_id'   => $orderId,
					'object_type' => 'order',
					'type'        => sanitize_text_field( $adjustment['type'] ),
					'type_key'    => $type_key,
					'description' => sanitize_text_field( $adjustment['description'] ),
					'subtotal'    => $adjustment_subtotal,
					'tax'         => $adjustment_tax,
					'total'       => $adjustment_total,
				) );
			}
		}

		// Discounts.
		if ( isset( $_POST['discounts'] ) ) {

			foreach ( $_POST['discounts'] as $discount ) {
				$d = edd_get_discount( absint( $discount['type_id'] ) );

				if ( empty( $d ) ) {
					continue;
				}

				$discount_subtotal = floatval( $discount['subtotal'] );
				$discount_total    = floatval( $discount['total'] );
				
				foreach ($existingAdjustments as $k => $existingAdjustment) {
					if ($existingAdjustment->id && $existingAdjustment->type == 'discount' && $existingAdjustment->type_id == $discount['type_id']) {
						edd_update_order_adjustment( $existingAdjustment->id, array(
							'description' => sanitize_text_field( $discount['code'] ),
							'subtotal'    => $discount_subtotal,
							'total'       => $discount_total,
						) );
						unset($existingAdjustments[$k]);
						continue 2;
					}
				}

				// Store discount.
				edd_add_order_adjustment( array(
					'object_id'   => $orderId,
					'object_type' => 'order',
					'type_id'     => intval( $discount['type_id'] ),
					'type'        => 'discount',
					'description' => sanitize_text_field( $discount['code'] ),
					'subtotal'    => $discount_subtotal,
					'total'       => $discount_total,
				) );
			}
		}
		
		foreach ($existingOrderItems as $item) {
			if ($item->id) {
				edd_delete_order_item($item->id);
			}
		}
		
		foreach ($existingAdjustments as $adjustment) {
			if ($adjustment->id) {
				edd_delete_order_adjustment($adjustment->id);
			}
		}


	}
	
	private static function parseJsData($data) {
		return [
			json_decode(trim(strstr($data, '='), '=; '), true),
			strstr($data, '=', true).'='
		];
	}
	
	
	
}

new WpzEddOrderEditing();