<?php


class WC_Gateway_TradeSafe extends WC_Payment_Gateway
{
    /**
     * Version
     *
     * @var string
     */
    public $version;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->id = 'tradesafe';
        $this->method_title = __('TradeSafe', 'woocommerce-gateway-tradesafe');
        $this->method_description = __('TradeSafe Escrow', 'woocommerce-gateway-tradesafe');
        $this->icon = WP_PLUGIN_URL . '/' . plugin_basename(dirname(dirname(__FILE__))) . '/assets/images/icon.svg';

        $this->version = WC_GATEWAY_TRADESAFE_VERSION;
        $this->available_countries = array('ZA');
        $this->available_currencies = (array)apply_filters('woocommerce_gateway_tradesafe_available_currencies', array('ZAR'));

        // Supported functionality
        $this->supports = array(
            'products',
        );

        $this->init_form_fields();
        $this->init_settings();

        // Setup default merchant data.
        $this->has_fields = true;
        $this->enabled = $this->is_valid_for_use() ? 'yes' : 'no'; // Check if the base currency supports this gateway.
        $this->production = $this->get_option('production');
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->client_id = $this->get_option('client_id');
        $this->client_secret = $this->get_option('client_secret');
        $this->client_callback = $this->get_option('client_callback');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_receipt_tradesafe', array($this, 'receipt_page'));
        add_action('admin_notices', array($this, 'admin_notices'));
    }

    /**
     * is_valid_for_use()
     *
     * Check if this gateway is enabled and available in the base currency being traded with.
     *
     * @return bool
     * @since 1.0.0
     */
    public function is_valid_for_use()
    {
        global $wp;

        $is_available = false;
        $is_available_currency = in_array(get_woocommerce_currency(), $this->available_currencies);

        if ($is_available_currency
            && get_option('tradesafe_client_id')
            && get_option('tradesafe_client_secret')) {
            $is_available = true;
        }

        if ("yes" === $this->get_option('production')) {
            $is_available = false;
        }

        if ("no" === $this->get_option('enabled') || null === $this->get_option('enabled')) {
            $is_available = false;
        }

        if (!is_admin()) {
            $user = wp_get_current_user();

            if ('' === get_user_meta($user->ID, 'tradesafe_token_id', true)) {
                $is_available = false;
            }
        }

        return $is_available;
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'woocommerce-gateway-tradesafe'),
                'label' => __('Enable TradeSafe', 'woocommerce-gateway-tradesafe'),
                'type' => 'checkbox',
                'description' => __('This controls whether or not this gateway is enabled within WooCommerce.', 'woocommerce-gateway-tradesafe'),
                'default' => 'yes',
                'desc_tip' => true,
            ),
            'title' => array(
                'title' => __('Title', 'woocommerce-gateway-tradesafe'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'woocommerce-gateway-tradesafe'),
                'default' => $this->method_title,
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Description', 'woocommerce-gateway-tradesafe'),
                'type' => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'woocommerce-gateway-tradesafe'),
                'default' => $this->method_description,
                'desc_tip' => true,
            ),
        );
    }

    public function admin_options()
    {
        ?>
        <h2><?php _e('TradeSafe', 'woocommerce-gateway-tradesafe'); ?></h2>
        <table class="form-table">
            <?php $this->generate_settings_html(); ?>
        </table> <?php
    }

    public function process_payment($order_id)
    {
        global $woocommerce;

        $order = new WC_Order($order_id);

        $client = woocommerce_tradesafe_api();

        if (is_null($client)) {
            return null;
        }

        if (!$order->meta_exists('tradesafe_transaction_id')) {
            $user = wp_get_current_user();

            $profile = $client->getProfile();

            $itemList = [];
            $vendors = [];
            foreach ($order->get_items() as $item) {
                // Get product owner
                $product = get_post($item['product_id']);

                if (get_option('tradesafe_transaction_marketplace', 0) === 1 && !has_dokan()) {
                    if (!isset($vendors[$product->post_author])) {
                        $vendors[$product->post_author]['total'] = 0;
                    }

                    $vendors[$product->post_author]['total'] += $item->get_total();
                }

                // Add item to list for description
                $itemList[] = $item->get_name() . ': ' . $order->get_formatted_line_subtotal($item);
            }

            $allocations[] = [
                'title' => 'Order ' . $order->get_id(),
                'description' => wp_strip_all_tags(implode(',', $itemList)), // Itemized List?
                'value' => $order->get_total(),
                'daysToDeliver' => 14,
                'daysToInspect' => 7,
            ];

            $parties[] = [
                'role' => 'BUYER',
                'token' => get_user_meta($user->ID, 'tradesafe_token_id', true)
            ];

            $parties[] = [
                'role' => 'SELLER',
                'token' => $profile['token']
            ];

            if (has_dokan()) {
                $sub_orders = get_children(
                    [
                        'post_parent' => dokan_get_prop($order, 'id'),
                        'post_type' => 'shop_order',
                        'post_status' => [
                            'wc-pending',
                            'wc-completed',
                            'wc-processing',
                            'wc-on-hold',
                            'wc-cancelled',
                        ],
                    ]
                );

                if (!$sub_orders) {
                    $payout_fee_allocation = get_option('tradesafe_payout_fee', 'SELLER');

                    $payout_fee = 0;

                    if ($payout_fee_allocation === 'VENDOR') {
                        $payout_fee = 20;
                    }

                    $parties[] = [
                        'role' => 'BENEFICIARY_MERCHANT',
                        'token' => get_user_meta($order->get_meta('_dokan_vendor_id', true), 'tradesafe_token_id', true),
                        'fee' => dokan()->commission->get_earning_by_order($order) - $payout_fee,
                        'feeType' => 'FLAT',
                        'feeAllocation' => 'SELLER',
                    ];
                } else {
                    $sub_order_count = count($sub_orders);

                    foreach ($sub_orders as $sub_order_post) {
                        $sub_order = wc_get_order($sub_order_post->ID);

                        $payout_fee_allocation = get_option('tradesafe_payout_fee', 'SELLER');

                        $payout_fee = 0;

                        if ($payout_fee_allocation === 'VENDOR') {
                            $payout_fee = 10 + (20 / $sub_order_count);
                        }

                        $parties[] = [
                            'role' => 'BENEFICIARY_MERCHANT',
                            'token' => get_user_meta($sub_order->get_meta('_dokan_vendor_id', true), 'tradesafe_token_id', true),
                            'fee' => dokan()->commission->get_earning_by_order($sub_order) - $payout_fee,
                            'feeType' => 'FLAT',
                            'feeAllocation' => 'SELLER',
                        ];
                    }
                }
            } else {
                foreach ($vendors as $vendorId => $vendor) {
                    $parties[] = [
                        'role' => 'BENEFICIARY_MERCHANT',
                        'token' => get_user_meta($vendorId, 'tradesafe_token_id', true),
                        'fee' => $vendor['total'],
                        'feeType' => 'FLAT',
                        'feeAllocation' => 'SELLER',
                    ];
                }
            }

            $transaction = $client->createTransaction([
                'title' => 'Order ' . $order->get_id(),
                'description' => wp_strip_all_tags(implode(',', $itemList)),
                'industry' => get_option('tradesafe_transaction_industry'),
                'feeAllocation' => get_option('tradesafe_fee_allocation'),
                'reference' => $order->get_order_key() . '-' . time()
            ], $allocations, $parties);

            $order->add_meta_data('tradesafe_transaction_id', $transaction['id'], true);
            $transaction_id = $transaction['id'];
        } else {
            $transaction_id = $order->get_meta('tradesafe_transaction_id', true);
        }

        // Mark as pending
        $order->update_status('pending', __('Awaiting payment.', 'woocommerce-gateway-tradesafe'));

        // Remove cart
        $woocommerce->cart->empty_cart();

        $redirects = [
            'success' => $order->get_view_order_url(),
            'failure' => wc_get_endpoint_url('orders', '', get_permalink(get_option('woocommerce_myaccount_page_id'))),
            'cancel' => wc_get_endpoint_url('orders', '', get_permalink(get_option('woocommerce_myaccount_page_id'))),
        ];

        // Return redirect
        return array(
            'result' => 'success',
            'redirect' => $client->getTransactionDepositLink($transaction_id),
        );
    }

    public static function admin_notices()
    {
        //
    }
}