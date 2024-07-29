<?php
/*
Plugin Name: PMPro Customizations
Plugin URI: https://www.paidmembershipspro.com/wp/pmpro-customizations/
Description: Customizations for my Paid Memberships Pro Setup
Version: .1
Author: Paid Memberships Pro
Author URI: https://www.paidmembershipspro.com
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}


function pmprocustom_scripts(){
    wp_enqueue_script('lancorunwind-jquery-script', 'https://cdnjs.cloudflare.com/ajax/libs/jquery/3.4.1/jquery.min.js');
}
add_action('wp_enqueue_scripts', 'pmprocustom_scripts');

// Existing custom template path code
function my_pmpro_pages_custom_template_path( $templates, $page_name ) {		
    $templates[] = plugin_dir_path(__FILE__) . 'templates/' . $page_name . '.php';	
    return $templates;
}
add_filter( 'pmpro_pages_custom_template_path', 'my_pmpro_pages_custom_template_path', 10, 2 );

// Custom preheader function
function my_custom_pmpro_preheader() {
    // Custom logic for preheader
    // Example: Remove confirm email and country fields from checkout

    function my_custom_pmpro_required_billing_fields($fields) {
        // Remove confirm email field
        unset($fields['bconfirmemail']);
        // Remove country field
        unset($fields['bcountry']);
        return $fields;
    }
    add_filter('pmpro_required_billing_fields', 'my_custom_pmpro_required_billing_fields');
}


// Function to cancel subscription
add_action('wp_ajax_cancel_subscription', 'cancel_subscription');
add_action('wp_ajax_nopriv_cancel_subscription', 'cancel_subscription');

function cancel_subscription() {
    wp_verify_nonce('razorpay_nonce', 'security');

    $subscription_id = sanitize_text_field($_POST['subscription_id']);

    $order_data = PMProGateway_Razorpay::getOrderBySubscriptionTransactionID($subscription_id);

    if ($order_data) {
        $cancelled = PMProGateway_Razorpay::cancel_previous_subscription($order_data);
        if ($cancelled) {
            echo json_encode(array('success' => true));
        } else {
            echo json_encode(array('success' => false, 'message' => 'Failed to cancel subscription.'));
        }
    } else {
        echo json_encode(array('success' => false, 'message' => 'Order not found.'));
    }

    wp_die();
}

add_action('wp_ajax_update_order_status', 'update_order_status');
add_action('wp_ajax_nopriv_update_order_status', 'update_order_status');

function update_order_status() {
    wp_verify_nonce('update_order_status_nonce', 'security');

    $order_id = intval($_POST['order_id']);
    $subscription_id = sanitize_text_field($_POST['subscription_id']);
    $payment_amount = floatval($_POST['payment_amount']);
    
    $order = new MemberOrder($order_id);


    if ($order) {
        $order->payment_transaction_id = $subscription_id;
        $order->payment_type = 'Razorpay';
        $order->payment_amount = $payment_amount;
        $order->status = 'success';
        $order->saveOrder();

        pmpro_changeMembershipLevel($order->membership_id, $order->user_id);
    } else {
        wp_send_json_error('Order not found.');
    }
    wp_die();
}


function my_set_pmpro_default_country( $default_country ) {
    // Set country code to "IN" for india.
    $default_country = "IN";
    return $default_country;
}   
add_filter( 'pmpro_default_country', 'my_set_pmpro_default_country' );

add_action('init', 'my_custom_pmpro_preheader');


//Razorpay payment integration

define( 'PMPRO_RAZORPAY_DIR', plugin_dir_path( __FILE__ ) );

// Load payment gateway class after all plugins are loaded to make sure PMPro stuff is available
function pmpro_razorpay_plugins_loaded() {

    //load_plugin_textdomain( 'pmpro-razorpay', false, basename( __DIR__ ) . '/languages' );

    // Make sure PMPro is loaded
    if ( ! defined( 'PMPRO_DIR' ) ) {
        return;
    }

    require_once( PMPRO_RAZORPAY_DIR . '/classes/gateways/class.pmprogateway_razorpay.php' );
}
add_action( 'plugins_loaded', 'pmpro_razorpay_plugins_loaded' );

// Register activation hook
register_activation_hook( __FILE__, 'pmpro_razorpay_admin_notice_activation_hook' );

/**
 * Runs only when the plugin is activated.
 *
 * @since 1.0
 */
function pmpro_razorpay_admin_notice_activation_hook() {
    // Create transient data
    set_transient( 'pmpro-razorpay-admin-notice', true, 5 );
}

/**
 * Admin Notice on Activation.
 *
 * @since 1.0
 */
function pmpro_razorpay_admin_notice() {
    // Check transient, if available display notice
    if ( get_transient( 'pmpro-razorpay-admin-notice' ) ) {
        ?>
        <div class="updated notice is-dismissible">
            <p><?php printf( __( 'Thank you for activating. <a href="%s">Visit the payment settings page</a> to configure the Razorpay Gateway.', 'pmpro-razorpay' ), esc_url( get_admin_url( null, 'admin.php?page=pmpro-paymentsettings' ) ) ); ?></p>
        </div>
        <?php
        // Delete transient, only display this notice once
        delete_transient( 'pmpro-razorpay-admin-notice' );
    }
}
add_action( 'admin_notices', 'pmpro_razorpay_admin_notice' );

/**
 * Show an admin warning notice if there is a level setup that is incorrect.
 *
 * @since 1.0
 */
function pmpro_razorpay_check_level_compat() {
    // Only show the notice on either the levels page or payment settings page
    if ( ! isset( $_REQUEST['page'] ) || $_REQUEST['page'] !== 'pmpro-membershiplevels' ) {
        return;
    }

    $level = isset( $_REQUEST['edit'] ) ? intval( $_REQUEST['edit'] ) : '';

    // Don't check if level is not set
    if ( empty( $level ) ) {
        return;
    }

    // Add compatibility checks specific to Razorpay if needed
    // Example: Check for custom trials compatibility

    // Example notice for custom trials not supported
    ?>
    <div class="notice notice-error fade">
        <p><?php esc_html_e( "Razorpay currently doesn't support custom trials. Please update your membership levels accordingly.", 'pmpro-razorpay' ); ?></p>
    </div>
    <?php
}
add_action( 'admin_notices', 'pmpro_razorpay_check_level_compat' );

/**
 * Fix PMPro Razorpay showing SSL error in admin menus when set up correctly.
 *
 * @since 1.0
 */
function pmpro_razorpay_pmpro_is_ready( $pmpro_is_ready ) {
    global $pmpro_gateway_ready, $pmpro_pages_ready;

    if ( empty( $pmpro_gateway_ready ) && 'razorpay' === get_option( 'pmpro_gateway' ) ) {
        // Perform additional checks if Razorpay is the active gateway
        if ( get_option( 'pmpro_razorpay_key_id' ) && get_option( 'pmpro_razorpay_key_secret' ) ) {
            $pmpro_gateway_ready = true;
        }
    }

    return ( $pmpro_gateway_ready && $pmpro_pages_ready );
}
add_filter( 'pmpro_is_ready', 'pmpro_razorpay_pmpro_is_ready' );

/**
 * Check if there are billing compatibility issues for levels and Razorpay.
 *
 * @since 1.0
 */
function pmpro_razorpay_check_billing_compat( $level = NULL ){
    if( !function_exists( 'pmpro_init' ) ){
        return;
    }
   $gateway = get_option("pmpro_gateway");
   if( $gateway == "razorpay" ){
       global $wpdb;

       //check ALL the levels
       if( empty( $level ) ){
           $sqlQuery = "SELECT * FROM $wpdb->pmpro_membership_levels ORDER BY id ASC";
           $levels = $wpdb->get_results($sqlQuery, OBJECT);
           
           if( !empty( $levels ) ){
               foreach( $levels as $level ){
                   if( !pmpro_razorpay_check_billing_compat( $level->id ) ){
                       return false;
                   }
               }
           }
       } else {
           if( is_numeric( $level ) && $level > 0 ){
               $level = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $wpdb->pmpro_membership_levels WHERE id = %d LIMIT 1" , $level ) );
               if( pmpro_isLevelTrial( $level ) ){
                   return false;
               }
           }        
       }
   }
   return true;
}

/**
 * Show a warning if custom trial is selected during level setup.
 *
 * @since 1.0
 */
function pmpro_razorpay_custom_trial_js_check() {
    $gateway = get_option( 'pmpro_gateway' );

    if ( $gateway !== 'razorpay' ) {
        return;
    }

    // Example warning for custom trials not supported
    $custom_trial_warning = __( sprintf( 'Razorpay does not support custom trials. Please use the %s instead.', "<a href='#' target='_blank'>Subscription Delay Add On</a>" ), 'pmpro-razorpay' ); ?>
    <script>
        jQuery(document).ready(function(){
            var message = "<?php echo $custom_trial_warning; ?>";
            jQuery( '<tr id="razorpay-trial-warning" style="display:none"><th></th><td><em><strong>' + message + '</strong></em></td></tr>' ).insertAfter( '.trial_info' );

            // Show for existing levels
            if ( jQuery('#custom-trial').is(':checked') ) {
                jQuery( '#razorpay-trial-warning' ).show();
            }

            // Toggle if checked or not
            pmpro_razorpay_trial_checked();

            function pmpro_razorpay_trial_checked() {
                jQuery('#custom_trial').change(function(){
                    if ( jQuery(this).prop('checked') ) {
                        jQuery( '#razorpay-trial-warning' ).show();
                    } else {
                        jQuery( '#razorpay-trial-warning' ).hide();
                    }
                });
            }
        });
    </script>
    <?php
}
add_action( 'pmpro_membership_level_after_other_settings', 'pmpro_razorpay_custom_trial_js_check' );

/**
 * Function to add links to the plugin action links.
 *
 * @param array $links Array of links to be shown in plugin action links.
 */
function pmpro_razorpay_plugin_action_links( $links ) {
    $new_links = array();

    if ( current_user_can( 'manage_options' ) ) {
        $new_links[] = '<a href="' . get_admin_url( null, 'admin.php?page=pmpro-paymentsettings' ) . '">' . __( 'Configure Razorpay', 'pmpro-razorpay' ) . '</a>';
    }

    return array_merge( $new_links, $links );
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'pmpro_razorpay_plugin_action_links' );

/**
 * Function to add links to the plugin row meta.
 *
 * @param array  $links Array of links to be shown in plugin meta.
 * @param string $file Filename of the plugin meta is being shown for.
 */
function pmpro_razorpay_plugin_row_meta( $links, $file ) {
    if ( strpos( $file, 'pmpro-razorpay.php' ) !== false ) {
        $new_links = array(
            '<a href="' . esc_url( '#' ) . '" title="' . esc_attr( __( 'View Documentation', 'pmpro-razorpay' ) ) . '">' . __( 'Docs', 'pmpro-razorpay' ) . '</a>',
            '<a href="' . esc_url( '#' ) . '" title="' . esc_attr( __( 'Visit Customer Support Forum', 'pmpro-razorpay' ) ) . '">' . __( 'Support', 'pmpro-razorpay' ) . '</a>',
        );
        $links = array_merge( $links, $new_links );
    }
    return $links;
}
add_filter( 'plugin_row_meta', 'pmpro_razorpay_plugin_row_meta', 10, 2 );

/**
 * Example function to handle Razorpay discount code result.
 * Replace with actual implementation based on Razorpay's API integration.
 *
 * @param string $discount_code     Discount code entered.
 * @param int    $discount_code_id  Discount code ID.
 * @param int    $level_id          Membership level ID.
 * @param object $code_level        Membership level object.
 */
function pmpro_razorpay_discount_code_result( $discount_code, $discount_code_id, $level_id, $code_level ){
		
    global $wpdb;

    //okay, send back new price info
    $sqlQuery = "SELECT l.id, cl.*, l.name, l.description, l.allow_signups FROM $wpdb->pmpro_discount_codes_levels cl LEFT JOIN $wpdb->pmpro_membership_levels l ON cl.level_id = l.id LEFT JOIN $wpdb->pmpro_discount_codes dc ON dc.id = cl.code_id WHERE dc.code = '" . $discount_code . "' AND cl.level_id = '" . $level_id . "' LIMIT 1";
    
    $code_level = $wpdb->get_row($sqlQuery);

    //if the discount code doesn't adjust the level, let's just get the straight level
    if(empty($code_level)){
        $code_level = $wpdb->get_row("SELECT * FROM $wpdb->pmpro_membership_levels WHERE id = '" . $level_id . "' LIMIT 1");
    }

    if( pmpro_isLevelFree( $code_level ) ){ //A valid discount code was returned
        ?>
            jQuery('#pmpro_razorpay_before_checkout').hide();
        <?php
    }

}
add_action( 'pmpro_applydiscountcode_return_js', 'pmpro_razorpay_discount_code_result', 10, 4 );
