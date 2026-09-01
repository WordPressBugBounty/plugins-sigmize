<?php

/**
 * Woocommerce Integration
 *
 * @package Sigmize
 */

namespace Sigmize\Frontend\Integrations;

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Woocommerce Integration Class
 * 
 * Handles tracking of Woocommerce-specific events like purchases and payments.
 */
class WoocommerceIntegration extends AbstractIntegration
{

    /**
     * Get integration type identifier
     *
     * @since 0.0.1
     *
     * @return string
     */
    protected function get_integration_type()
    {
        return 'woocommerce';
    }

    /**
     * Get supported events for this integration
     *
     * @since 0.0.1
     *
     * @return array Array of supported events with labels
     */
    protected function get_supported_events()
    {
        return array(
            array(
                'value' => 'add_to_cart',
                'label' => 'Add to Cart'
            ),
            array(
                'value' => 'order_completed',
                'label' => 'Order Completed'
            )
        );
    }


    /**
     * Check if Woocommerce is active
     *
     * @since 0.0.1
     *
     * @return bool
     */
    public function is_plugin_active()
    {
        if ($this->is_plugin_active !== null) {
            return $this->is_plugin_active;
        }

        $this->is_plugin_active = class_exists('WooCommerce') || defined('WC_PLUGIN_FILE');
        return $this->is_plugin_active;
    }

    /**
     * Initialize hooks for specific events
     *
     * @since 0.0.1
     *
     * @param array $required_events Array of event triggers that need hooks
     * @return void
     */
    public function initialize_hooks($required_events = array())
    {
        // Only register hooks for events that are actually needed by active experiments

        // Add to Cart event
        if (in_array('add_to_cart', $required_events, true)) {
            add_action('woocommerce_add_to_cart', array($this, 'handle_add_to_cart'), 10, 6);
        }

        // Order Created event (fires after order is created)
        if (in_array('order_completed', $required_events, true)) {
            add_action('woocommerce_payment_complete', array($this, 'handle_order_completed'), 10, 1);

            // Runs for COD (and also covers status changes for online just in case)
            add_action('woocommerce_order_status_changed', function ($order_id, $old, $new, $order) {
                if ($order->get_payment_method() === 'cod' && in_array($new, ['processing'], true)) {
                    $this->handle_order_completed($order_id);
                }
            }, 10, 4);
        }
    }

    /**
     * Handle WooCommerce Add to Cart event
     *
     * @since 0.0.1
     *
     * @param string $cart_item_key Unique cart item key
     * @param int    $product_id    Product ID added to cart
     * @param int    $quantity      Quantity added
     * @param int    $variation_id  Variation ID if applicable
     * @param array  $variation     Variation attributes
     * @param array  $cart_item_data Additional cart item data
     * @return void
     */
    public function handle_add_to_cart($cart_item_key = null, $product_id = null, $quantity = null, $variation_id = null, $variation = null, $cart_item_data = null)
    {
        try {
            // Basic validation - ensure required parameters exist and have expected types
            if (empty($cart_item_key) || !is_string($cart_item_key)) {
                return; // Invalid or missing cart item key
            }
            if (empty($product_id) || !is_numeric($product_id)) {
                return; // Invalid or missing product id
            }
            if (empty($quantity) || !is_numeric($quantity)) {
                return; // Invalid or missing quantity
            }
            // variation_id can be null or numeric
            if (!is_null($variation_id) && !is_numeric($variation_id)) {
                return; // invalid variation id type
            }
            // variation can be array or null
            if (!is_null($variation) && !is_array($variation)) {
                return; // invalid variation type
            }
            // cart_item_data can be array or null
            if (!is_null($cart_item_data) && !is_array($cart_item_data)) {
                return; // invalid cart item data type
            }

            // Prepare basic event data after validation
            $event_data = [
                'cart_item_key' => $cart_item_key,
                'product_id' => (int) $product_id,
                'quantity' => (int) $quantity,
                'variation_id' => $variation_id ? (int) $variation_id : null,
                'variation' => $variation ?: [],
                'cart_item_data' => $cart_item_data ?: [],
                'timestamp' => current_time('mysql'),
            ];

            // Fetch the full product object safely
            $product = wc_get_product($product_id);
            if ($product) {
                $event_data['product'] = [
                    'id' => $product->get_id(),
                    'name' => $product->get_name(),
                    'sku' => $product->get_sku(),
                    'price' => $product->get_price(),
                    'type' => $product->get_type(),
                    'is_virtual' => $product->is_virtual(),
                    'is_downloadable' => $product->is_downloadable(),
                    'stock_quantity' => $product->get_stock_quantity(),
                ];
            }

            // Fetch variation details if available
            if ($variation_id) {
                $variation_product = wc_get_product($variation_id);
                if ($variation_product) {
                    $event_data['variation_product'] = [
                        'id' => $variation_product->get_id(),
                        'attributes' => $variation_product->get_attributes(),
                        'price' => $variation_product->get_price(),
                    ];
                }
            }

            // Get cart totals if cart exists
            $cart = WC()->cart;
            if ($cart) {
                $event_data['cart_totals'] = [
                    'total' => $cart->get_total(),
                    'subtotal' => $cart->get_subtotal(),
                    'items_count' => $cart->get_cart_contents_count(),
                ];
            }

            $this->track_event('add_to_cart', $event_data);
        } catch (\Exception $e) {
            // Silently continue on error
            return;
        }
    }


    /**
     * Handle WooCommerce Order Created event
     *
     * @since 0.0.1
     *
     * @param int|WC_Order $order Order ID or Order object
     * @return void
     */
    public function handle_order_completed($order_id)
    {
        try {
            // Validate input
            if (!is_numeric($order_id)) {
                return;
            }

            $order = wc_get_order($order_id);
            if (!$order) {
                return;
            }

            // Skip tracking for subscription renewal / switch / resubscribe orders.
            if ($this->is_recurring_subscription_order($order)) {
                return;
            }

            // Basic order info
            $currency = $order->get_currency();
            $gateway = $order->get_payment_method();
            $value_id = $order->get_id();

            // Prepare event data array for items
            $event_data_array = array();

            foreach ($order->get_items() as $item) {
                $product = $item->get_product();
                $product_name = $product ? $product->get_name() : '';
                $product_price = $item->get_total() / max(1, $item->get_quantity()); // unit price

                // Discount calculation
                // subtotal - total gives the per-line discount (excluding taxes/fees)
                $discount = $item->get_subtotal() - $item->get_total();

                $event_data_array[] = array(
                    'value'            => $item->get_total(),
                    'value_id'         => $value_id,
                    'currency'         => $currency,
                    'discount'         => $discount,
                    'gateway'          => $gateway,
                    'product_id'       => $item->get_product_id(),
                    'product_name'     => $product_name,
                    'product_price_id' => $item->get_variation_id(), // 0 when the line item is not a variation.
                    'product_quantity' => $item->get_quantity(),
                    'product_price'    => $product_price,
                );
            }

            // order info
            $order_data = array(
                'order_id'       => $order->get_id(),
                'order_total'    => $order->get_total(),
                'currency'       => $currency,
                'payment_method' => $gateway,
            );

            $event_data = [
                'order_data' => $order_data,
                'products'   => $event_data_array,
            ];

            // Call tracking method with minimal clean data
            $this->track_event('order_completed', $event_data);
        } catch (\Exception $e) {
            // Silently continue on error to avoid breaking checkout flow
            return;
        }
    }

    /**
     * Determine whether an order is a subscription renewal, switch or resubscribe order.
     *
     * Checks two independent signals so detection is reliable across storage engines
     * (HPOS/legacy) and gateways that mutate the order during payment:
     *  1. Order-side relation meta via WooCommerce Subscriptions helpers (fast path).
     *  2. Subscription-side related-order cache for the order's customer, which is
     *     stored separately from the order and survives gateways that re-save the
     *     order and drop its relation meta before this hook fires. This is the same
     *     source the WooCommerce admin uses to label an order a "Renewal Order".
     *
     * @since 1.1.1
     *
     * @param \WC_Order $order The order being processed.
     * @return bool True if the order is a renewal/switch/resubscribe order.
     */
    private function is_recurring_subscription_order($order)
    {
        // Nothing to check if WooCommerce Subscriptions is not active.
        if (!function_exists('wcs_order_contains_renewal')) {
            return false;
        }

        // 1) Order-side checks (correct when the order's relation meta is intact).
        $order_side_checks = array(
            'wcs_order_contains_renewal',
            'wcs_order_contains_switch',
            'wcs_order_contains_resubscribe',
        );
        foreach ($order_side_checks as $check) {
            if (function_exists($check) && $check($order)) {
                return true;
            }
        }

        // 2) Subscription-side fallback for when the order's relation meta is missing
        //    at hook time (e.g. a gateway re-saved the order during payment).
        if (!function_exists('wcs_get_users_subscriptions')) {
            return false;
        }

        $customer_id = $order->get_customer_id();
        if (!$customer_id) {
            return false;
        }

        $order_id      = $order->get_id();
        $subscriptions = wcs_get_users_subscriptions($customer_id);

        foreach ($subscriptions as $subscription) {
            foreach (array('renewal', 'switch', 'resubscribe') as $relation_type) {
                $related_ids = $subscription->get_related_orders('ids', $relation_type);
                if (in_array($order_id, array_map('absint', (array) $related_ids), true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Format event data for tracking
     *
     * @since 0.0.1
     *
     * @param string $event_trigger The event trigger that fired
     * @param array  $raw_data Raw event data from the hook
     * @return array Formatted event data
     */
    protected function format_event_data($event_trigger, $raw_data = array())
    {
        $event_data = array();

        switch ($event_trigger) {
            case 'add_to_cart':

                // Confirm that $raw_data is a non-empty array
                if (!empty($raw_data) && is_array($raw_data)) {
                    // Get first element (cart item)
                    $first_key = array_key_first($raw_data);

                    if (is_array($raw_data[$first_key])) {
                        $item = $raw_data[$first_key];

                        // Check if 'product' key exists inside
                        if (isset($item['product']) && is_array($item['product'])) {
                            // Extract product info with fallbacks
                            $item_id = isset($item['product']['id']) ? (int) $item['product']['id'] : 0;
                            $quantity = isset($item['quantity']) ? (int) $item['quantity'] : 1;
                            $price = isset($item['product']['price']) ? (float) $item['product']['price'] : 0.0;
                            $currency = isset($item['currency']) ? strtoupper($item['currency']) : strtoupper(get_woocommerce_currency());
                            $value_id = $item['cart_item_key'] ?? '';

                            // The product price id is variation_id or zero if not set
                            $price_id = isset($item['variation_id']) && $item['variation_id'] ? (int) $item['variation_id'] : 0;

                            // Product name
                            $product_name = $item['product']['name'] ?? '';

                            // No discount or gateway info at add_to_cart stage - set default
                            $discount = 0.0;
                            $gateway = '';

                            // Calculate total value for product line
                            $line_item_value = $price * $quantity;

                            // Create flat event data array as per EDD structure
                            $event_data = [
                                [
                                    'value'            => $line_item_value,
                                    'value_id'         => $value_id,
                                    'currency'         => $currency,
                                    'discount'         => $discount,
                                    'gateway'          => $gateway,
                                    'product_id'       => $item_id,
                                    'product_name'     => $product_name,
                                    'product_price_id' => $price_id,
                                    'product_quantity' => $quantity,
                                    'product_price'    => $price,
                                ]
                            ];
                        }
                    }
                }

                break;

            case 'order_completed':

                // Validate input data
                if (empty($raw_data) || !is_array($raw_data) || !isset($raw_data[0])) {
                    break;
                }

                // Access the nested event data structure
                $event_input = $raw_data[0];

                // Validate the event data structure
                if (!is_array($event_input) || !isset($event_input['products']) || !is_array($event_input['products'])) {
                    break;
                }

                // Extract products data directly - it's already in the correct format
                $event_data_array = $event_input['products'];

                // Validate that we have products and each product has required fields
                $valid_products = array();
                foreach ($event_data_array as $product_data) {
                    if (
                        is_array($product_data) &&
                        isset(
                            $product_data['value'],
                            $product_data['value_id'],
                            $product_data['currency'],
                            $product_data['gateway'],
                            $product_data['product_id'],
                            $product_data['product_name'],
                            $product_data['product_quantity'],
                            $product_data['product_price']
                        )
                    ) {
                        $valid_products[] = $product_data;
                    }
                }

                if (empty($valid_products)) {
                    break;
                }

                $event_data = $valid_products;
                break;

            default:
                break;
        }

        return $event_data;
    }

    /**
     * Get WooCommerce-specific context information
     *
     * @since 0.0.1
     *
     * @return array
     */
    public function get_context_info()
    {
        $context = array(
            'woocommerce_version' => defined('WC_VERSION') ? WC_VERSION : 'unknown',
            'woocommerce_active' => $this->is_plugin_active(),
            'woocommerce_classes_available' => class_exists('WooCommerce'),
        );

        return $context;
    }
}
