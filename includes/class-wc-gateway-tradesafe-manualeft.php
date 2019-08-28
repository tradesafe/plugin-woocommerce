<?php

/**
 * Class WC_Gateway_TradeSafe_Manualeft
 */
class WC_Gateway_TradeSafe_Manualeft extends WC_Gateway_TradeSafe_Base {
	/**
	 * WC_Gateway_TradeSafe_Manualeft constructor.
	 */
	public function __construct() {
		$this->id                 = 'tradesafe_manualeft';
		$this->icon               = WP_PLUGIN_URL . '/' . plugin_basename( dirname( dirname( __FILE__ ) ) ) . '/assets/images/icon.png';
		$this->has_fields         = true;
		$this->method_title       = __( 'TradeSafe Escrow', 'woocommerce-tradesafe-gateway' );
		$this->method_description = __( 'Redirects user to TradeSafe to make a Manual EFT through online banking', 'woocommerce-tradesafe-gateway' );
		$this->order_button_text  = __( 'Proceed to TradeSafe', 'woocommerce-tradesafe-gateway' );
		$this->supports           = array(
			'products',
//			'refunds',
		);

		// Supported Countries
		$this->countries = [
			'ZA'
		];

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->is_available() ? 'yes' : 'no';

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array(
			$this,
			'process_admin_options'
		) );
	}

	/**
	 * Config Form
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable/Disable', 'woocommerce-tradesafe-gateway' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Manual EFT Payment', 'woocommerce-tradesafe-gateway' ),
				'default' => 'no'
			),
			'title'       => array(
				'title'       => __( 'Title', 'woocommerce-tradesafe-gateway' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce-tradesafe-gateway' ),
				'default'     => __( 'Manual EFT', 'woocommerce-tradesafe-gateway' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Description', 'woocommerce-tradesafe-gateway' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce-tradesafe-gateway' ),
				'default'     => __( 'Make payment using online banking.', 'woocommerce-tradesafe-gateway' ),
				'desc_tip'    => true,
			)
		);
	}
}
