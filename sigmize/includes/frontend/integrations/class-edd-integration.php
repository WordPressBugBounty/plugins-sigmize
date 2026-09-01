<?php

/**
 * Easy Digital Downloads Integration
 *
 * @package Sigmize
 */

namespace Sigmize\Frontend\Integrations;

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Easy Digital Downloads Integration Class
 *
 * Handles tracking of EDD-specific events like purchases, cart actions, and refunds.
 */
class EddIntegration extends AbstractIntegration
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
        return 'easy_digital_downloads';
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
                'value' => 'purchase_completed',
                'label' => 'Purchase Completed'
            )
        );
    }

    /**
     * Check if Easy Digital Downloads is active
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

        $this->is_plugin_active = class_exists('Easy_Digital_Downloads') || function_exists('EDD');
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
            add_action('edd_post_add_to_cart', array($this, 'handle_add_to_cart'), 10, 3);
        }

        // Purchase Completed event
        if (in_array('purchase_completed', $required_events, true)) {
            add_action('edd_complete_purchase', array($this, 'handle_purchase_completed'), 10, 1);
        }
    }

    /**
     * Handle EDD Add to Cart event
     *
     * @since 0.0.1
     *
     * @param int   $download_id Download ID
     * @param array $options Download options
     * @param array $items Cart items
     * @return void
     */
    public function handle_add_to_cart($download_id, $options, $items)
    {
        try {
            $this->track_event('add_to_cart', $download_id, $options, $items);
        } catch (\Exception $e) {
            // Silently continue on error
            return;
        }
    }

    /**
     * Handle EDD Purchase Completed event
     *
     * @since 0.0.1
     *
     * @param int $payment_id Payment ID
     * @return void
     */
    public function handle_purchase_completed($payment_id)
    {
        try {
            $payment = new \EDD_Payment($payment_id);
            if ($payment && $payment->ID) {
                
                //Check parent payment property
                if ($payment->parent_payment > 0) {
                    return;
                }
        
            }            
            $this->track_event('purchase_completed', $payment_id);
        } catch (\Exception $e) {
            // Silently continue on error to avoid breaking checkout flow
            return;
        }
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
                if (count($raw_data) >= 3) {
                    $download_id = $raw_data[0];
                    $items = $raw_data[2];

                    // Get common data for all products
                    $currency = strtoupper(edd_get_currency());
                    $value_id = ''; // No order ID for add_to_cart events

                    // Create individual event data for each cart item/product
                    $event_data_array = array();

                    // Format products to match purchase_completed structure
                    if (is_array($items)) {
                        foreach ($items as $item) {
                            $item_price = 0;
                            $item_id = isset($item['id']) ? (int) $item['id'] : $download_id;
                            $quantity = isset($item['quantity']) ? (int) $item['quantity'] : 1;

                            // Get price based on price_id if available
                            if (isset($item['options']['price_id'])) {
                                $item_price = edd_get_price_option_amount($item_id, $item['options']['price_id']);
                            } else {
                                $item_price = edd_get_download_price($item_id);
                            }

                            $price_id = isset($item['options']['price_id']) ? (int) $item['options']['price_id'] : 0;

                            // Individual product value (price * quantity)
                            $line_item_value = (float) $item_price * $quantity;

                            // Create event data for this specific product with flat structure
                            $event_data_array[] = array(
                                'value'            => $line_item_value,
                                'value_id'         => $value_id,
                                'currency'         => $currency,
                                'discount'         => 0.0, // No discount info available for add_to_cart
                                'gateway'          => '', // No gateway info available for add_to_cart
                                'product_id'       => $item_id,
                                'product_name'     => edd_get_download_name($item_id, $price_id),
                                'product_price_id' => $price_id,
                                'product_quantity' => $quantity,
                                'product_price'    => (float) $item_price,
                            );
                        }
                    }

                    // Return array of event data (one per product/cart item)
                    $event_data = $event_data_array;
                }
                break;

            case 'purchase_completed':
                if (count($raw_data) >= 1) {
                    $payment_id = $raw_data[0];

                    // Get payment object for additional data
                    $payment = new \EDD_Payment($payment_id);

                    // Get common data for all products
                    $currency = strtoupper($payment->currency);
                    $gateway = sanitize_text_field($payment->gateway);
                    $value_id = $payment_id;

                    // Create individual event data for each cart item/product
                    $event_data_array = array();

                    if (!empty($payment->cart_details) && is_array($payment->cart_details)) {
                        foreach ($payment->cart_details as $item) {
                            $item_id = (int) $item['id'];
                            $price_id = (int) (isset($item['item_number']['options']['price_id']) ? $item['item_number']['options']['price_id'] : 0);
                            $quantity = (int) $item['quantity'];
                            $item_price = (float) ($item['item_price'] ?? 0);
                            $item_discount = (float) ($item['discount'] ?? 0);

                            // Individual product value (price * quantity) net of discount
                            $line_item_value = ($item_price * $quantity) - $item_discount;
                            $line_item_discount = $item_discount;

                            // Create event data for this specific product with flat structure
                            $event_data_array[] = array(
                                'value'            => $line_item_value,
                                'value_id'         => $value_id,
                                'currency'         => $currency,
                                'discount'         => $line_item_discount,
                                'gateway'          => $gateway,
                                'product_id'       => $item_id,
                                'product_name'     => edd_get_download_name($item_id, $price_id),
                                'product_price_id' => $price_id,
                                'product_quantity' => $quantity,
                                'product_price'    => $item_price,
                            );
                        }
                    }

                    // Return array of event data (one per product/cart item)
                    $event_data = $event_data_array;
                }
                break;

            default:
                break;
        }

        return $event_data;
    }
}
