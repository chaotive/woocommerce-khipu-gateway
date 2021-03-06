<?php

if (!defined('ABSPATH')) {
    exit;
} // Exit if accessed directly

/**
 * Plugin Name: WooCommerce khipubacs
 * Plugin URI: https://khipu.com
 * Description: khipu powered direct transfer payment gateway for woocommerce
 * Version: 2.5.2
 * Author: khipu
 * Author URI: https://khipu.com
 */

add_action('plugins_loaded', 'woocommerce_khipubacs_init', 0);

function woocommerce_khipubacs_showWooCommerceNeeded()
{
    woocommerce_khipubacs_showMessage("Debes instalar y activar el plugin WooCommerce. El plugin de khipu se deshabilitará hasta que esto este corregido.",
        true);
}

function woocommerce_khipubacs_orderReceivedHasSpaces()
{
    woocommerce_khipu_showMessage("El 'endpoint' de Pedido recibido tiene espacios, debe ser una palabra sin espacios, para corregirlo anda a WooCommerce->Ajustes->Finalizar compra y corrige el valor en el campo 'Pedido recibido'. El plugin de khipu se deshabilitará hasta que esto este corregido.",
        true);
}

function woocommerce_khipubacs_showMessage($message, $errormsg = false)
{
    if ($errormsg) {
        echo '<div id="message" class="error">';
    } else {
        echo '<div id="message" class="updated fade">';
    }

    echo "<p><strong>$message</strong></p></div>";
}

function woocommerce_khipubacs_init()
{

    require __DIR__ . '/vendor/autoload.php';

    if (!class_exists('WC_Payment_Gateway')) {
        add_action('admin_notices', 'woocommerce_khipubacs_showWooCommerceNeeded');
        return;
    }

    $orderReceived =
        isset(WC()->query->query_vars['order-received']) ? WC()->query->query_vars['order-received'] : 'order-received';
    if (strpos($orderReceived, ' ') !== false) {
        add_action('admin_notices', 'woocommerce_khipubacs_orderReceivedHasSpaces');
        return;
    }


    class WC_Gateway_khipubacs extends WC_Payment_Gateway
    {

        var $notify_url;

        /**
         * Constructor for the gateway.
         *
         */
        public function __construct()
        {
            $this->id = 'khipubacs';
            $this->icon = 'https://bi.khipu.com/150x50/capsule/transfer/transparent/'.$this->get_option('receiver_id');
            $this->has_fields = false;
            $this->method_title = __('Trasferencia normal', 'woocommerce');
            $this->notify_url = add_query_arg('wc-api', 'WC_Gateway_' . $this->id, home_url('/'));

            // Load the settings and init variables.
            $this->init_form_fields();
            $this->init_settings();
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->receiver_id = $this->get_option('receiver_id');
            $this->secret = $this->get_option('secret');

            // Actions
            add_action('valid-' . $this->id . '-ipn-request', array($this, 'successful_request'));
            add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
            add_action('woocommerce_update_options_payment_gateways_' . $this->id,
                array($this, 'process_admin_options'));

            // Payment listener/API hook
            add_action('woocommerce_api_wc_gateway_' . $this->id, array($this, 'check_ipn_response'));

            if (!$this->is_valid_for_use()) {
                $this->enabled = false;
            }
        }


        /**
         * Check if this gateway is enabled and available in the user's country
         */
        function is_valid_for_use()
        {
            if (!in_array(get_woocommerce_currency(),
                apply_filters('woocommerce_' . $this->id . '_supported_currencies', array('CLP')))
            ) {
                return false;
            }
            return true;
        }

        /**
         * Admin Panel Options
         */
        public function admin_options()
        {
            ?>
            <h3><?php _e('khipu', 'woocommerce'); ?></h3>
            <p><?php _e('khipu informa al pagador de la transferencia que debe realizar y luego concilia y notifica al comercio del pago realizado.',
                    'woocommerce'); ?></p>

            <?php if ($this->is_valid_for_use()) : ?>
            <table class="form-table">
                <?php
                // Generate the HTML For the settings form.
                $this->generate_settings_html();
                ?>
            </table><!--/.form-table-->

        <?php else : ?>
            <div class="inline error">
                <p>
                    <strong><?php _e('Gateway Disabled',
                            'woocommerce'); ?></strong>: <?php _e('khipu does not support your store currency.',
                        'woocommerce'); ?>
                </p>
            </div>
            <?php
        endif;
        }


        /**
         * Initialise Gateway Settings Form Fields
         */
        function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Enable khipu', 'woocommerce'),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => __('Title', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('This controls the title which the user sees during checkout.',
                        'woocommerce'),
                    'default' => __('Transferencia normal', 'woocommerce'),
                    'desc_tip' => true
                ),
                'description' => array(
                    'title' => __('Description', 'woocommerce'),
                    'type' => 'textarea',
                    'description' => __('Payment method description that the customer will see on your checkout.',
                        'woocommerce'),
                    'default' => __('Debes ingresar el rut de la cuenta corriente o vista con'
                        . 'que pagarás, luego se te entregarán todos los datos'
                        . 'para que puedas realizar la transferencia desde el'
                        . 'portal web o móvil de tu banco. Debes tener cuidado, el'
                        . 'monto a transferir debe ser el que se te ha informado'
                        . 'que realices, así el pago se procesará con éxito.')
                ),
                'receiver_id' => array(
                    'title' => __('Id de cobrador', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('Ingrese su Id de cobrador. Se obtiene en https://khipu.com/merchant/profile',
                        'woocommerce'),
                    'default' => '',
                    'desc_tip' => true
                ),
                'secret' => array(
                    'title' => __('Llave', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('Ingrese su llave secreta. Se obtiene en https://khipu.com/merchant/profile',
                        'woocommerce'),
                    'default' => '',
                    'desc_tip' => true
                )
            );

        }


        function comm_error($exception = null)
        {
            if(!$exception) {
                $msg = __('Error de comunicación con khipu, por favor intente nuevamente más tarde.');
            } else {
                $msg = "<h2>Error de comunicación con khipu</h2><ul><li><strong>Código</strong>:" . $exception->getStatus() ."</li>";
                $msg .= "<li><strong>Mensaje</strong>: " . $exception->getMessage() . "</li>";
                if(method_exists($exception, 'getErrors')) {
                    $msg .= "<li>Errores<ul>";
                    foreach($exception->getErrors() as $errorItem) {
                        $msg .= "<li><strong>" . $errorItem->getField() ."</strong>: " . $errorItem->getMessage() . "</li>";
                    }
                    $msg .= "</ul></li>";
                }
                $msg .= "</ul>";
            }
            return "<div class='woocommerce-error'>$msg</div>";
        }

        /**
         * Create the payment on khipu and try to start the app.
         */
        function generate_khipubacs_submit_button($order_id)
        {

            $order = new WC_Order($order_id);

            $item_names = array();

            if (sizeof($order->get_items()) > 0) {
                foreach ($order->get_items() as $item) {
                    if ($item['qty']) {
                        $item_names[] = $item['name'] . ' x ' . $item['qty'];
                    }
                }
            }

            $configuration = new Khipu\Configuration();
            $configuration->setSecret($this->secret);
            $configuration->setReceiverId($this->receiver_id);
            $configuration->setPlatform('woocommerce-khipu', '2.5.2');

            $client = new Khipu\ApiClient($configuration);
            $payments = new Khipu\Client\PaymentsApi($client);

            $options = array(
                'transaction_id' => ltrim($order->get_order_number(), '#')
                , 'custom' => serialize(array($order_id, $order->get_order_key()))
                , 'body' => implode(', ', $item_names)
                , 'return_url' => $this->get_return_url($order)
                , 'notify_url' => $this->notify_url
                , 'notify_api_version' => '1.3'
                , 'payer_email' => $order->get_billing_email()
            );

            try {
                $createPaymentResponse = $payments->paymentsPost(
                    'Orden ' . $order->get_order_number() . ' - ' . get_bloginfo('name')
                    , $order->get_currency()
                    , number_format($order->get_total(), absint(get_option('woocommerce_price_num_decimals', 2 )), '.', '')
                    , $options
                );
            } catch(\Khipu\ApiException $e) {
                return $this->comm_error($e->getResponseObject());
            }

            wp_redirect($createPaymentResponse->getTransferUrl());
            return;

        }

        /**
         * Process the payment and return the result
         */
        function process_payment($order_id)
        {
            $order = new WC_Order($order_id);
            return array('result' => 'success', 'redirect' => $order->get_checkout_payment_url(true));
        }

        /**
         * Output for the order received page.
         */
        function receipt_page($order)
        {
            echo $this->generate_khipubacs_submit_button($order);
        }

        /**
         * Get order from Khipu IPN
         **/
        function get_order_from_notification()
        {
            if ($_POST['api_version'] == '1.3') {
                $configuration = new Khipu\Configuration();
                $configuration->setSecret($this->secret);
                $configuration->setReceiverId($this->receiver_id);
                $configuration->setPlatform('woocommerce-khipu', '2.5.2');

                $client = new Khipu\ApiClient($configuration);
                $payments = new Khipu\Client\PaymentsApi($client);

                $paymentsResponse =  $payments->paymentsGet($_POST['notification_token']);

                $order = $this->get_khipubacs_order($paymentsResponse->getCustom(), $paymentsResponse->getTransactionId());

                if($paymentsResponse->getStatus() == 'done' && $paymentsResponse->getAmount() == floatval(number_format($order->get_total(), absint(get_option('woocommerce_price_num_decimals', 2 )), '.', ''))
                    && $paymentsResponse->getCurrency() == $order->get_currency()) {
                    return $order;
                }
            }
        }


        /**
         * Check for Khipu IPN Response
         */
        function check_ipn_response()
        {
            @ob_clean();

            if (empty($_POST) || empty($_POST['api_version'])) {
                wp_die("khipu notification validation invalid");
            }

            $order = $this->get_order_from_notification();

            if ($order) {
                header('HTTP/1.1 200 OK');
                do_action("valid-khipubacs-ipn-request", $order);
                return;
            }

        }

        /**
         * Successful Payment
         */
        function successful_request($order)
        {
            if ($order->get_status() == 'completed') {
                exit;
            }
            $order->add_order_note(__('Pago con khipubacs verificado', 'woocommerce'));
            $order->payment_complete();
        }


        /**
         * get_khipu_order function.
         */
        function get_khipubacs_order($custom, $transaction_id)
        {
            $custom = maybe_unserialize($custom);

            // Backwards comp for IPN requests
            if (is_numeric($custom)) {
                $order_id = (int)$custom;
                $order_key = $transaction_id;
            } elseif (is_string($custom)) {
                $order_id = (int)str_replace($this->invoice_prefix, '', $custom);
                $order_key = $custom;
            } else {
                list($order_id, $order_key) = $custom;
            }

            $order = new WC_Order($order_id);

            if (!isset($order->id)) {
                $order_id = woocommerce_get_order_id_by_order_key($order_key);
                $order = new WC_Order($order_id);
            }

            return $order;
        }

    }

    /**
     * Add the Gateway to WooCommerce
     **/
    function woocommerce_add_khipubacs_gateway($methods)
    {
        $methods[] = 'WC_Gateway_khipubacs';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_khipubacs_gateway');

    add_filter('woocommerce_currencies', 'woocommerce_add_khipubacs_currencies' );

    function woocommerce_add_khipubacs_currencies( $currencies ) {
        $currencies['CLP'] = __( 'Peso Chileno', 'woocommerce' );
        $currencies['BOB'] = __( 'Peso Boliviano', 'woocommerce' );
        return $currencies;
    }

    add_filter('woocommerce_currency_symbol', 'woocommerce_add_khipubacs_currencies_symbol', 10, 2);

    function woocommerce_add_khipubacs_currencies_symbol( $currency_symbol, $currency ) {
        switch( $currency ) {
            case 'CLP': $currency_symbol = '$'; break;
            case 'BOB': $currency_symbol = 'Bs'; break;
        }
        return $currency_symbol;
    }

}
