<?php

/**
 * SureCart Integration
 *
 * @package Sigmize
 */

namespace Sigmize\Frontend\Integrations;

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

/**
 * SureCart Integration Class
 * 
 * Handles tracking of SureCart-specific events like purchases and payments.
 */
class SurecartIntegration extends AbstractIntegration
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
        return 'surecart';
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
                'value' => 'purchase_created',
                'label' => 'Purchase Created'
            )
        );
    }

    /**
     * Check if SureCart is active
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

        $this->is_plugin_active = class_exists('\SureCart\SureCart') || defined('SURECART_PLUGIN_FILE');
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

        // Purchase Created event - this is the main purchase event in SureCart
        if (in_array('purchase_created', $required_events, true)) {
            add_action('surecart/purchase_created', array($this, 'handle_purchase_created'), 10, 1);
        }
    }

    /**
     * Handle SureCart Purchase Created event
     *
     * @since 0.0.1
     *
     * @param object $purchase Purchase object
     * @return void
     */
    public function handle_purchase_created($purchase)
    {
        // Input validation
        if (empty($purchase) || (!is_object($purchase) && !is_array($purchase))) {
            return;
        }

        $purchase_id = isset($purchase->id) ? $purchase->id : null;
        if ($purchase_id) {
            try {
                // Try to fetch full purchase data with all relationships
                if (class_exists('\SureCart\Models\Purchase') && method_exists('\SureCart\Models\Purchase', 'find')) {
                    $full_purchase = \SureCart\Models\Purchase::with([
                        'product',
                        'price',
                        'line_items',
                        'line_items.price',
                        'customer',
                        'initial_order',
                        'initial_order.line_items',
                        'initial_order.line_items.price'
                    ])->find($purchase_id);

                    if ($full_purchase) {
                        $purchase = $full_purchase;
                    }
                }

                // If still no detailed data, try fetching via Order model
                if ((!isset($purchase->line_items) || empty($purchase->line_items)) &&
                    isset($purchase->initial_order) &&
                    class_exists('\SureCart\Models\Order')
                ) {

                    $order_id = is_string($purchase->initial_order) ? $purchase->initial_order : $purchase->initial_order->id;

                    $order = \SureCart\Models\Order::with([
                        'line_items',
                        'line_items.price',
                        'customer'
                    ])->find($order_id);

                    if ($order && !empty($order->line_items)) {
                        // Merge order line items into purchase object
                        $purchase->line_items = $order->line_items;
                        $purchase->total_amount = $order->total_amount ?? $purchase->total_amount;
                        $purchase->currency = $order->currency ?? $purchase->currency;
                    }
                }
            } catch (\Exception $e) {
                // Silently continue with minimal data rather than failing
            }


            if (isset($purchase->line_items) && isset($purchase->line_items->data)) {
                $this->track_event('purchase_created', $purchase);
            }
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
            case 'purchase_created':
                if (count($raw_data) >= 1) {
                    $purchase = $raw_data[0];

                    // Get common data for all products
                    $currency = 'USD';
                    if (isset($purchase->currency)) {
                        $currency = $purchase->currency;
                    } elseif (isset($purchase->line_items->data[0]->price->currency)) {
                        $currency = $purchase->line_items->data[0]->price->currency;
                    }
                    $currency = strtoupper(sanitize_text_field($currency));

                    $gateway = isset($purchase->payment_method) ? sanitize_text_field($purchase->payment_method) : (isset($purchase->payment_processor) ? sanitize_text_field($purchase->payment_processor) : 'surecart');
                    $value_id = $purchase->initial_order->id ?? ($purchase->id ?? '');

                    // Create individual event data for each line item/product
                    $event_data_array = array();

                    if (isset($purchase->line_items->data) && is_array($purchase->line_items->data) && !empty($purchase->line_items->data)) {
                        foreach ($purchase->line_items->data as $line_item) {
                            // Get product details for this line item
                            $product_name = 'Product';
                            if (isset($purchase->product->name)) {
                                $product_name = $purchase->product->name;
                            }

                            // Handle variants
                            if (isset($line_item->variant_options) && is_array($line_item->variant_options) && !empty($line_item->variant_options)) {
                                if (isset($line_item->variant_option_names) && is_array($line_item->variant_option_names)) {
                                    $variant_details = array();
                                    for ($i = 0; $i < count($line_item->variant_options); $i++) {
                                        if (isset($line_item->variant_option_names[$i])) {
                                            $variant_details[] = $line_item->variant_option_names[$i] . ': ' . $line_item->variant_options[$i];
                                        }
                                    }
                                    $product_name .= ' (' . implode(', ', $variant_details) . ')';
                                } else {
                                    $variant_name = implode(', ', $line_item->variant_options);
                                    $product_name .= ' (' . $variant_name . ')';
                                }
                            }

                            // Individual product value and discount
                            $line_item_value = (float) (($line_item->total_amount ?? 0) / 100);
                            $line_item_discount = (float) (($line_item->discount_amount ?? 0) / 100);

                            // Create event data for this specific product with flat structure
                            $event_data_array[] = array(
                                'value'            => $line_item_value,
                                'value_id'         => $value_id,
                                'currency'         => $currency,
                                'discount'         => $line_item_discount,
                                'gateway'          => $gateway,
                                'product_id'       => isset($purchase->product->id) ? $purchase->product->id : ($line_item->price->product ?? ''),
                                'product_name'     => $product_name,
                                'product_price_id' => $line_item->price->id ?? '',
                                'product_quantity' => (int) ($line_item->quantity ?? 1),
                                'product_price'    => (float) (($line_item->price->amount ?? 0) / 100),
                            );
                        }
                    }

                    // Return array of event data (one per product/line item)
                    $event_data = $event_data_array;
                }
                break;

            default:
                break;
        }

        return $event_data;
    }
}
