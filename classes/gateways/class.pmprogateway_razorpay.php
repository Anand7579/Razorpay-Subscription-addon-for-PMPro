<?php
// Require the default PMPro Gateway Class.
require_once PMPRO_DIR . '/classes/gateways/class.pmprogateway.php';

add_action('admin_menu', 'pmpro_razorpay_add_admin_menu');

function pmpro_razorpay_add_admin_menu() {
    add_submenu_page(
        'pmpro-dashboard',
        'Razorpay Plan IDs',
        'Razorpay Plan IDs',
        'manage_options',
        'pmpro_razorpay_plan_ids',
        'pmpro_razorpay_plan_ids_page'
    );
}

add_action('admin_init', 'pmpro_razorpay_settings_init');

function pmpro_razorpay_settings_init() {
    register_setting('pmpro_razorpay_plan_ids_group', 'razorpay_plan_ids');

    add_settings_section(
        'pmpro_razorpay_plan_ids_section',
        'Razorpay Plan IDs for Membership Levels',
        'pmpro_razorpay_plan_ids_section_callback',
        'pmpro_razorpay_plan_ids_group'
    );

    foreach (pmpro_getAllLevels(true, true) as $level) {
        add_settings_field(
            'razorpay_plan_id_' . $level->id,
            'Plan ID for ' . $level->name,
            'pmpro_razorpay_plan_id_field_callback',
            'pmpro_razorpay_plan_ids_group',
            'pmpro_razorpay_plan_ids_section',
            array(
                'level_id' => $level->id
            )
        );
    }
}

function pmpro_razorpay_plan_ids_section_callback() {
    echo '<p>Enter Razorpay Plan IDs for each PMPro membership level below.</p>';
}

function pmpro_razorpay_plan_id_field_callback($args) {
    $options = get_option('razorpay_plan_ids');
    $level_id = $args['level_id'];
    $plan_id = isset($options['razorpay_plan_id_' . $level_id]) ? $options['razorpay_plan_id_' . $level_id] : '';

    echo '<input type="text" id="razorpay_plan_id_' . $level_id . '" name="razorpay_plan_ids[razorpay_plan_id_' . $level_id . ']" value="' . esc_attr($plan_id) . '" />';
}

function pmpro_razorpay_plan_ids_page() {
    ?>
    <div class="wrap">
        <h1>Razorpay Plan IDs</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('pmpro_razorpay_plan_ids_group');
            do_settings_sections('pmpro_razorpay_plan_ids_group');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}


// Load classes init method.
add_action( 'init', array( 'PMProGateway_Razorpay', 'init' ) );

class PMProGateway_Razorpay extends PMProGateway {

    function __construct( $gateway = null ) {
        return parent::__construct( $gateway );
    }

    /**
     * Run on WP init
     *
     * @since 1.8
     */
    static function init() {
        // Make sure Razorpay is a gateway option.
        add_filter( 'pmpro_gateways', array( 'PMProGateway_Razorpay', 'pmpro_gateways' ) );

        // Add fields to payment settings.
        add_filter( 'pmpro_payment_options', array( 'PMProGateway_Razorpay', 'pmpro_payment_options' ) );

        add_filter( 'pmpro_payment_option_fields', array( 'PMProGateway_Razorpay', 'pmpro_payment_option_fields' ), 10, 2 );

        if ( get_option( 'pmpro_gateway' ) == 'razorpay' ) {
            // Customize billing and payment information display.
            add_filter( 'pmpro_include_billing_address_fields', '__return_true' );
            add_filter( 'pmpro_include_payment_information_fields', '__return_false' );
            add_filter( 'pmpro_billing_show_payment_method', '__return_false' );
            add_action( 'pmpro_billing_before_submit_button', array( 'PMProGateway_Razorpay', 'pmpro_billing_before_submit_button' ) );
            //add_action( 'pmpro_cancel_before_submit', array( new PMProGateway_Razorpay(), 'razorpay_cancel_subscription' ), 10, 1 );
        }

        add_filter( 'pmpro_required_billing_fields', array( 'PMProGateway_Razorpay', 'pmpro_required_billing_fields' ) );
        //add_filter( 'pmpro_checkout_before_submit_button', array( 'PMProGateway_Razorpay', 'pmpro_checkout_before_submit_button' ) );
        add_filter( 'pmpro_checkout_before_change_membership_level', array( 'PMProGateway_Razorpay', 'pmpro_checkout_before_change_membership_level' ), 10, 2 );

        // Handle Razorpay Instant Notifications (ITN).
        //add_action( 'wp_ajax_pmpro_razorpay_itn_handler', array( 'PMProGateway_Razorpay', 'pmpro_razorpay_itn_handler' ) );
        //add_action( 'wp_ajax_nopriv_pmpro_razorpay_itn_handler', array( 'PMProGateway_Razorpay', 'pmpro_razorpay_itn_handler' ) );
    }

    /**
     * Add Razorpay to the list of allowed gateways.
     *
     * @return array
     */
    static function pmpro_gateways( $gateways ) {
        if ( empty( $gateways['razorpay'] ) ) {
            $gateways['razorpay'] = __( 'Razorpay', 'pmpro-razorpay' );
        }

        return $gateways;
    }

    /**
     * Get a list of payment options that the this gateway needs/supports.
     *
     * @since 1.8
     */
    static function getGatewayOptions() {
        $options = array(
            'razorpay_key_id',
            'razorpay_key_secret',
            'currency',
            'use_ssl',
            'tax_state',
            'tax_rate',
            'razorpay_debug',
        );

        return $options;
    }

    /**
     * Set payment options for payment settings page.
     *
     * @since 1.8
     */
    static function pmpro_payment_options( $options ) {
        // Get Razorpay options.
        $razorpay_options = self::getGatewayOptions();

        // Merge with others.
        $options = array_merge( $razorpay_options, $options );

        return $options;
    }

    /**
     * Display fields for this gateway's options.
     *
     * @since 1.8
     */
    static function pmpro_payment_option_fields( $values, $gateway ) { ?>
        <tr class="gateway gateway_razorpay"
            <?php if ( $gateway != 'razorpay' ) { ?>
                style="display: none;"
            <?php } ?>>
            <th scope="row" valign="top">
                <label for="razorpay_key_id"><?php _e( 'Razorpay Key ID', 'pmpro-razorpay' ); ?>:</label>
            </th>
            <td>
                <input type="text" id="razorpay_key_id" name="razorpay_key_id" value="<?php echo esc_attr( $values['razorpay_key_id'] ); ?>" />
            </td>
        </tr>
        <tr class="gateway gateway_razorpay"
            <?php if ( $gateway != 'razorpay' ) { ?>
                style="display: none;"
            <?php } ?>>
            <th scope="row" valign="top">
                <label for="razorpay_key_secret"><?php _e( 'Razorpay Key Secret', 'pmpro-razorpay' ); ?>:</label>
            </th>
            <td>
                <input type="text" id="razorpay_key_secret" name="razorpay_key_secret" value="<?php echo esc_attr( $values['razorpay_key_secret'] ); ?>" />
            </td>
        </tr>
        <tr class="gateway gateway_razorpay"
            <?php if ( $gateway != 'razorpay' ) { ?>
                style="display: none;"
            <?php } ?>>
            <th scope="row" valign="top">
                <label for="razorpay_debug"><?php _e( 'Razorpay Debug Mode', 'pmpro-razorpay' ); ?>:</label>
            </th>
            <td>
                <select name="razorpay_debug">
                    <option value="1"
                        <?php if ( isset( $values['razorpay_debug'] ) && $values['razorpay_debug'] ) { ?>
                            selected="selected"
                        <?php } ?>>
                        <?php _e( 'On', 'pmpro-razorpay' ); ?>
                    </option>
                    <option value="0"
                        <?php if ( isset( $values['razorpay_debug'] ) && ! $values['razorpay_debug'] ) { ?>
                            selected="selected"
                        <?php } ?>>
                        <?php _e( 'Off', 'pmpro-razorpay' ); ?>
                    </option>
                </select>
            </td>
        </tr>
        <script>
            // Trigger the payment gateway dropdown to make sure fields show up correctly.
            jQuery( document ).ready( function() {
                pmpro_changeGateway( jQuery( '#gateway' ).val() );
            } );
        </script>
    <?php }

    /**
     * Remove required billing fields.
     *
     * @since 1.8
     */
    static function pmpro_required_billing_fields( $fields ) {
        //Unset unnecessary billing fields for Razorpay.
        unset( $fields['bfirstname'] );
        unset( $fields['blastname'] );
        unset( $fields['baddress1'] );
        unset( $fields['bcity'] );
        unset( $fields['bstate'] );
        unset( $fields['bzipcode'] );
        unset( $fields['bphone'] );
        unset( $fields['bemail'] );
        unset( $fields['bcountry'] );
        unset( $fields['CardType'] );
        unset( $fields['AccountNumber'] );
        unset( $fields['ExpirationMonth'] );
        unset( $fields['ExpirationYear'] );
        unset( $fields['CVV'] );

        return $fields;
    }

    /**
     * Display information before PMPro's checkout button.
     *
     * @since 1.8
     */
    static function pmpro_checkout_before_submit_button() {
        global $gateway, $pmpro_requirebilling;

        // Bail if gateway isn't Razorpay.
        if ( $gateway != 'razorpay' ) {
            return;
        }

        ?>
        <div id="pmpro_razorpay_before_checkout" style="text-align:center;">
            <span id="pmpro_razorpay_checkout"
                <?php if ( $gateway != 'razorpay' || ! $pmpro_requirebilling ) { ?>
                    style="display: none;"
                <?php } ?>>
                <?php echo '<strong>' . __( 'NOTE:', 'pmpro-razorpay' ) . '</strong> ' . __( 'Please complete your payment using Razorpay.', 'pmpro-razorpay' ); ?>
            </span>
        </div>
        <?php
    }

    /**
     * Instead of changing membership levels, send users to Razorpay to pay.
     *
     * @since 1.8
     */
    static function pmpro_checkout_before_change_membership_level( $user_id, $morder ) {
        global $discount_code_id, $wpdb;

        // If no order, no need to pay.
        if ( empty( $morder ) ) {
            return;
        }

        // Bail if the current gateway is not set to Razorpay.
        if ( 'razorpay' != $morder->gateway ) {
            return;
        }
        
        $morder->user_id = $user_id;
        $morder->saveOrder();

        // Save discount code use.
        if ( ! empty( $discount_code_id ) ) {
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$wpdb->pmpro_discount_codes_uses} (code_id, user_id, order_id, timestamp) VALUES(%d, %d, %d, %s)",
                    $discount_code_id,
                    $user_id,
                    $morder->id,
                    current_time( 'mysql' )
                )
            );
        }

        // Process checkout and exit.
        $morder->Gateway->sendToRazorpay( $morder );
        exit;
    }

    /**
     * Process ITN from Razorpay.
     *
     * @since 1.8
     */
    static function pmpro_razorpay_itn_handler() {
        global $pmpro_currency, $wpdb;

        // Get and decode the request body as an array.
        $request_body = file_get_contents( 'php://input' );
        $event        = json_decode( $request_body );

        // Check for a valid Razorpay event.
        if ( ! isset( $event->event ) || ! isset( $event->payload ) ) {
            die();
        }

        // Check for a valid Razorpay event.
        $event_id      = $event->id;
        $event         = $event->event;
        $order_id      = $event->payload->payment->entity->order_id;
        $payment_id    = $event->payload->payment->entity->id;
        $payment_status = $event->payload->payment->entity->status;
        $amount        = $event->payload->payment->entity->amount / 100;

        // Get the order object.
        $morder = new MemberOrder( $order_id );

        // Bail if the order doesn't exist.
        if ( empty( $morder ) ) {
            return;
        }

        // Verify that the payment status is successful.
        if ( 'authorized' === $payment_status ) {
            $morder->status = 'pending';
            $morder->payment_transaction_id = $payment_id;
            $morder->payment_type = 'Razorpay';
            $morder->payment_amount = $amount;

            // Set membership level.
            $morder->getMembershipLevel();
            $morder->saveOrder();

            // Update subscription status in wp_pmpro_subscriptions table
            $wpdb->update(
                "{$wpdb->prefix}pmpro_subscriptions",
                array('status' => 'active'),
                array('subscription_transaction_id' => $payment_id),
                array('%s'),
                array('%s')
            );

            // Send email.
            $morder->getMemberEmail();
            $pmproemail = new PMProEmail();
            $pmproemail->sendCheckoutEmail( $morder );

            // Update membership.
            pmpro_changeMembershipLevel( $morder->membership_id, $morder->user_id );

            $morder->status = 'success';
            $morder->saveOrder();
        }

        die();
    }

    static function has_active_subscription($user_id, $level_id) {
        global $wpdb;

        // Convert $user_id to an integer if it's an object
        $user_uid = is_object($user_id) ? $user_id->ID : $user_id;
    
        // Prepare the SQL query with placeholders
        $query = $wpdb->prepare(
            "SELECT id, subscription_transaction_id 
            FROM {$wpdb->prefix}pmpro_subscriptions 
            WHERE user_id = %d 
            AND membership_level_id = %d 
            AND status = 'active'
            ORDER BY startdate DESC",
            $user_uid,
            $level_id
        );
        
        // Execute the query and get the results
        $results = $wpdb->get_results($query);
        return $results;
    }    

    static function cancel_active_subscription($subscription_id) {
        global $wpdb;
    
        $wpdb->update(
            "{$wpdb->prefix}pmpro_subscriptions",
            array(
                'status' => 'cancelled',
                'modified' => current_time('mysql'),
            ),
            array(
                'id' => $subscription_id,
            ),
            array(
                '%s',
                '%s',
            ),
            array(
                '%d',
            )
        );
    }    

    /**
     * Send to Razorpay to create a subscription.
     *
     * @since 1.8
     */
    static function sendToRazorpay( $order ) {
        global $pmpro_currency;

        $order->status = 'pending';
        $order->saveOrder();

        // Load the API key and secret from the settings.
        $razorpay_key_id     = pmpro_getOption( 'razorpay_key_id' );
        $razorpay_key_secret = pmpro_getOption( 'razorpay_key_secret' );

        // Bail if any of the API keys are missing.
        if ( empty( $razorpay_key_id ) || empty( $razorpay_key_secret ) ) {
            wp_die( 'Razorpay API keys not configured.' );
        }

        // Get the subscription amount from the order.
        $subscription_amount = $order->subscription_amount;

        // Set the currency.
        $currency = pmpro_getOption( 'currency' );
        if ( empty( $currency ) ) {
            $currency = 'INR';
        }

        // Get the customer's name, email & phone.
        $user = get_userdata( $order->user_id );
        $user_name = $user->display_name;
        $email = $user->user_email;
        $billing_details = $order->billing;
        $phone = $billing_details->phone;
        $level_id = $order->membership_level->id;

        // Check if the user already has an active subscription for this level
        $existing_subscriptions = self::has_active_subscription($user, $level_id);
        
        if ($existing_subscriptions) {
            $most_recent_subscription = array_shift($existing_subscriptions);
            foreach ($existing_subscriptions as $subscription) {
                $transaction_id = $subscription->subscription_transaction_id;
                // Cancel each existing active subscription
                self::cancel_active_subscription($subscription->id);
            }
        }

        $options = get_option('razorpay_plan_ids');
        $plan_id = isset($options['razorpay_plan_id_' . $level_id]) ? $options['razorpay_plan_id_' . $level_id] : '';

        // Get the old subscription transaction ID.
        $old_subscription_id = self::get_current_user_razorpay_subscription_id();        

        // Prepare Razorpay subscription parameters.
        $razorpay_subscription_args = array(
            'plan_id'   => $plan_id,  // Razorpay plan ID.
            'total_count' => 12,  // Total number of billing cycles.
            'customer_notify' => 1,  // customer notifications (optional).
            'notes' => [
                'name' => $user_name,
                'email' => $email,
                'contact' => $phone,
            ],
            'quantity' => 1,
        );

        // Create a Razorpay subscription and return the subscription ID.
        $api_endpoint = 'https://api.razorpay.com/v1/subscriptions';
        $api_key      = $razorpay_key_id;
        $api_secret   = $razorpay_key_secret;

        // Set the Authorization in the request header.
        $authorization = base64_encode( $api_key . ':' . $api_secret );
        $headers       = array(
            'Authorization' => 'Basic ' . $authorization,
            'Content-Type'  => 'application/json',
        );

        // Make the request to create the Razorpay subscription.
        $response = wp_remote_post(
            $api_endpoint,
            array(
                'headers' => $headers,
                'body'    => json_encode( $razorpay_subscription_args ),
                'timeout' => 60,
            )
        );
        // Check for errors.
        if ( is_wp_error( $response ) ) {
            // Handle WP_Error.
            error_log( 'Razorpay subscription creation error: ' . $response->get_error_message() );
        } else {
            // Decode the response body.
            $body = wp_remote_retrieve_body( $response );
            $data = json_decode( $body );

            if (isset($data->id)) {

            self::$subscription_id = $data->id;

            // Redirect to Razorpay checkout
            $subscription_id = $data->id; // Get the subscription ID from Razorpay response
            
            $razorpay_nonce = wp_create_nonce('razorpay_nonce');
            $update_order_status_nonce = wp_create_nonce('update_order_status_nonce');

            ?>
            <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.4.1/jquery.min.js"></script>
            <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
            <script>
                var razorpay_nonce = "<?php echo $razorpay_nonce; ?>";
                var updateOrderStatusNonce = "<?php echo $update_order_status_nonce; ?>";

                jQuery(document).ready(function($) {
                    var options = {
                        "key": "<?php echo $razorpay_key_id; ?>",
                        "subscription_id": "<?php echo $subscription_id; ?>",
                        "amount": <?php echo $subscription_amount * 100; ?>,
                        "name": "Lancor Unwind",
                        "description": "Membership Subscription",
                        "prefill": {
                            "name": "<?php echo $user_name; ?>",
                            "email": "<?php echo $email; ?>",
                            "contact": "<?php echo $phone; ?>"
                        },
                        "handler": function(response) {
                            if (response.razorpay_payment_id && response.razorpay_subscription_id && response.razorpay_signature) {
                                if ("<?php echo $old_subscription_id; ?>") {
                                    $.post("<?php echo admin_url('admin-ajax.php'); ?>", {
                                        action: "cancel_subscription",
                                        subscription_id: "<?php echo $old_subscription_id; ?>",
                                        security: razorpay_nonce
                                    }).always(function() {
                                        $.post("<?php echo admin_url('admin-ajax.php'); ?>", {
                                            action: "update_order_status",
                                            order_id: <?php echo $order->id; ?>,
                                            subscription_id: "<?php echo $subscription_id; ?>",
                                            payment_amount: "<?php echo $subscription_amount; ?>",
                                            security: updateOrderStatusNonce
                                        }).always(function() {
                                            window.location.href = "<?php //echo esc_url(home_url('/membership-account/')); ?>";
                                        });
                                    });
                                } else {
                                    $.post("<?php echo admin_url('admin-ajax.php'); ?>", {
                                        action: "update_order_status",
                                        order_id: <?php echo $order->id; ?>,
                                        subscription_id: "<?php echo $subscription_id; ?>",
                                        payment_amount: "<?php echo $subscription_amount; ?>",
                                        security: updateOrderStatusNonce
                                    }).always(function() {
                                        window.location.href = "<?php //echo esc_url(home_url('/membership-account/')); ?>";
                                    });
                                }
                            } else {
                                console.error("Payment failed or invalid response.");
                                window.location.href = "<?php echo esc_url(home_url('/payment-failed/')); ?>";
                            }
                        },
                        "theme": {
                            "color": "#F37254"
                        }
                    };
                    var rzp = new Razorpay(options);
                    rzp.open();
                });
            </script>
            <?php

            // Update order status to complete
            // $order->status = 'complete';
            //$order->saveOrder();
            }
            exit;
        }
    }

    public static function getOrderBySubscriptionTransactionID($subscription_id) {
        global $wpdb;

        // Query to fetch the order based on subscription transaction ID
        $query = $wpdb->prepare("
            SELECT *
            FROM {$wpdb->prefix}pmpro_membership_orders
            WHERE payment_transaction_id = %s
            AND status = 'success'
            ORDER BY timestamp DESC
            LIMIT 1
        ", $subscription_id);

        $order_data = $wpdb->get_row($query, ARRAY_A); // Fetch as associative array

        if ($order_data) {
            return $order_data; // Return the associative array
        }

        return false; // Return false if order not found
    }

    // Function to get the current user's Razorpay subscription ID
    static function get_current_user_razorpay_subscription_id() {
        // Get the current user ID
        $user_id = get_current_user_id();

        if (!$user_id) {
            error_log('User not logged in.');
            return false; // User is not logged in
        }
    
        global $wpdb;
    
        // Prepare and execute query to get the latest payment_transaction_id for the current user
        $query = $wpdb->prepare("
            SELECT payment_transaction_id
            FROM {$wpdb->prefix}pmpro_membership_orders
            WHERE user_id = %d
            AND status = 'success'
            ORDER BY timestamp DESC
            LIMIT 1
        ", $user_id);
    
        $old_subscription_id = $wpdb->get_var($query);
    
        return $old_subscription_id;
    }
    

    // Static property to store subscription ID.
    private static $subscription_id;

    // Getter method for subscription ID.
    public static function get_subscription_id() {
        return self::$subscription_id;
    }

    /**
     * Cancel a subscription at the gateway.
     *
     * @param object $order The order object.
     * @param bool $update_status Whether to update the order status to 'cancelled'.
     * @return bool True on success, false on failure.
     */
    public function razorpay_cancel_subscription( &$user_id, $old_level_id ) {

        $order = new MemberOrder();
        $order->getLastMemberOrder($user_id);
        
        if ( ! empty( $order ) ) {       

            if ( $order ) {
                $order->status = 'cancelled';
                $order->saveOrder();
            }

            $subscription_id = self::get_current_user_razorpay_subscription_id();  

            // Prepare API request.
            $api_key = pmpro_getOption( 'razorpay_key_id' );
            $api_secret = pmpro_getOption( 'razorpay_key_secret' );

            $url = 'https://api.razorpay.com/v1/subscriptions/' . $subscription_id . '/cancel';

            // Set request parameters.
            $request_args = array(
                'method'    => 'POST',
                'timeout'   => 60,
                'headers'   => array(
                    'Authorization' => 'Basic ' . base64_encode( $api_key . ':' . $api_secret ),
                    'Content-Type'  => 'application/json',
                ),
                'body'      => json_encode( array( 'cancel_at_cycle_end' => 0 ) ),
            );

            // Make the request to cancel subscription.
            $response = wp_remote_post( $url, $request_args );

            // Check for errors.
            if ( is_wp_error( $response ) ) {
                // Handle WP_Error.
                error_log( 'Razorpay subscription cancellation error: ' . $response->get_error_message() );
                $order->status = 'error';
                $order->errorcode  = 'wp_remote_post_error';
                $order->error      = $response->get_error_message();
                $order->shorterror = 'WP Remote Post Error';
                return false;
            } else {
                // Retrieve response details.
                $response_code = wp_remote_retrieve_response_code( $response );
                $response_message = wp_remote_retrieve_response_message( $response );

                if ( 200 == $response_code ) {
                    // Subscription successfully cancelled.
                    return true;
                } else {
                    // Handle non-200 response.
                    error_log( 'Razorpay subscription cancellation failed. Response code: ' . $response_code . ', message: ' . $response_message );
                    $order->status = 'error';
                    $order->errorcode  = 'razorpay_cancel_subscription_failed';
                    $order->error      = 'Subscription cancellation failed. Response code: ' . $response_code . ', message: ' . $response_message;
                    $order->shorterror = 'Subscription Cancellation Failed';
                    return false;
                }
            }
        } else {
            // Subscription transaction ID not found.
            error_log( 'Razorpay subscription ID not found for order ID: ' . $order->id );
            $order->status = 'error';
            $order->errorcode  = 'razorpay_subscription_id_not_found';
            $order->error      = 'Razorpay subscription ID not found.';
            $order->shorterror = 'Subscription ID Not Found';
            return false;
        }
    }

    public static function cancel_previous_subscription($order_data, $update_status = true) {

        if (!empty($order_data['payment_transaction_id'])) {

            $subscription_id = $order_data['payment_transaction_id'];
            
            if ($order_data) {
                $order_data['status'] = 'cancelled';
            }
    
            // Prepare API request.
            $api_key = pmpro_getOption('razorpay_key_id');
            $api_secret = pmpro_getOption('razorpay_key_secret');
    
            $url = 'https://api.razorpay.com/v1/subscriptions/' . $subscription_id . '/cancel';
    
            // Set request parameters.
            $request_args = array(
                'method'    => 'POST',
                'timeout'   => 60,
                'headers'   => array(
                    'Authorization' => 'Basic ' . base64_encode($api_key . ':' . $api_secret),
                    'Content-Type'  => 'application/json',
                ),
                'body'      => json_encode(array('cancel_at_cycle_end' => 0)),
            );
    
            // Make the request to cancel subscription.
            $response = wp_remote_post($url, $request_args);
    
            // Check for errors.
            if (is_wp_error($response)) {
                error_log('Razorpay subscription cancellation error: ' . $response->get_error_message());
                return false;
            } else {
                $response_code = wp_remote_retrieve_response_code($response);
                $response_message = wp_remote_retrieve_response_message($response);
    
                if (200 == $response_code) {
                    return true;
                } else {
                    error_log('Razorpay subscription cancellation failed. Response code: ' . $response_code . ', message: ' . $response_message);
                    return false;
                }
            }
        } else {
            error_log('Razorpay subscription ID not found in order data.');
            return false;
        }
    }    

    /**
     * Function to handle updates of Subscriptions.
     *
     * @param object $subscription The PMPro Subscription Object.
     * @return string|null Error message returned from gateway.
     */
    public function update_subscription_info( $subscription ) {
        // We need to get the subscription ID from the order with this $subscription_id.

        $subscription_id = self::get_subscription_id();

        if ( ! $subscription_id ) {
            return false;
        }

        // Make an API call to Razorpay to get the subscription details.
        $api_key = pmpro_getOption( 'razorpay_key_id' );
        $api_secret = pmpro_getOption( 'razorpay_key_secret' );

        $url = 'https://api.razorpay.com/v1/subscriptions/' . $subscription_id;

        $response = wp_remote_get(
            $url,
            array(
                'timeout' => 60,
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode( $api_key . ':' . $api_secret ),
                    'Content-Type'  => 'application/json',
                ),
            )
        );

        if ( ! is_wp_error( $response ) ) {
            $response_body = wp_remote_retrieve_body( $response );
            $sub_info = json_decode( $response_body );

            if ( empty( $sub_info->id ) ) {
                return __( 'Razorpay error: No subscription data returned', 'pmpro-razorpay' );
            }

            $update_array = array();

            // Update subscription status.
            $update_array['status'] = ( $sub_info->status === 'active' ) ? 'active' : 'cancelled';

            // Convert the frequency of the subscription back to PMPro format.
            switch ( $sub_info->period ) {
                case 'daily':
                    $update_array['cycle_period'] = 'Day';
                    break;
                case 'weekly':
                    $update_array['cycle_period'] = 'Week';
                    break;
                case 'monthly':
                    $update_array['cycle_period'] = 'Month';
                    break;
                case 'yearly':
                    $update_array['cycle_period'] = 'Year';
                    break;
                default:
                    $update_array['cycle_period'] = 'Month';
            }

            $update_array['next_payment_date'] = sanitize_text_field( $sub_info->current_end );
            $update_array['billing_amount'] = (float) $sub_info->amount_due / 100;

            $subscription->set( $update_array );
        } else {
            return esc_html__( 'There was an error connecting to Razorpay. Please check your connectivity or API details and try again later.', 'pmpro-razorpay' );
        }
    }

    /**
     * Display information before PMPro's submit button.
     *
     * @since 1.8
     */
    static function pmpro_billing_before_submit_button() {
        global $gateway, $pmpro_requirebilling;

        // Bail if gateway isn't Razorpay.
        if ( $gateway != 'razorpay' || ! $pmpro_requirebilling ) {
            return;
        }

        ?>
        <div id="pmpro_razorpay_before_submit" style="text-align:center;">
            <?php _e( 'Please complete your payment using Razorpay.', 'pmpro-razorpay' ); ?>
        </div>
        <?php
    }
}

?>
