<?php 
/*
Plugin Name: Custom WooCommerce Subscriptions Edit
Plugin URI:  https://tenodwordpressa.pl
Description: Nadpisuje ilość okresów rozliczeniowych oraz ilość miesięcy dostępu w subskrypcjach WooCommerce, dla produktów subskrypcyjnych.
Version:     1.0
Author:      Webly Mate Sp. z o.o.
Author URI:  https://tenodwordpressa.pl
License:     GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

function towp_custom_wc_subscription_edit_activate() {
    if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Subscriptions' ) || ! class_exists( 'ACF' ) ) { 
        add_action( 'admin_notices', 'towp_custom_wc_subscription_missing_dependencies_notice' );
    }
}

function towp_custom_wc_subscription_missing_dependencies_notice() {
    ?>
    <div class="notice notice-error">
        <p><?php echo 'Ta wtyczka wymaga WooCommerce, WooCommerce Subscriptions oraz Advanced Custom Fields (ACF) do działania.'; ?></p>
    </div>
    <?php
}
register_activation_hook( __FILE__, 'towp_custom_wc_subscription_edit_activate' );


//kluczowa logika wtyczki
function towp_customize_subscription_periods_and_expiration($subscription) {
    // Upewnij się, że ACF jest zainstalowany i aktywny
    if ( ! function_exists( 'get_field' ) ) {
        error_log( 'ACF jest wymagany do prawidłowego działania towp_customize_subscription_periods_and_expiration' );
        return;
    }

    if ( ! $subscription || is_wp_error( $subscription ) ) {
        error_log( 'Nieprawidłowy obiekt subskrypcji w towp_customize_subscription_periods_and_expiration' );
        return;
    }

    foreach ($subscription->get_items() as $item) {
        $product_id = $item->get_product_id(); 		
        // Pobieranie wartości pól ACF
        $billing_periods = get_field('number_of_billing_periods', $product_id);
        $expiration_months = get_field('number_of_periods_to_expire', $product_id);
		//dodanie liczenia płatności z liczbą 1
		

        if (empty($billing_periods) || empty($expiration_months) || !is_numeric($billing_periods) || !is_numeric($expiration_months)) {
            continue;
        }

        // Ustawienie domyślnego okresu rozliczeniowego i interwału
        $subscription->update_meta_data('_billing_period', 'month');
        $subscription->update_meta_data('_billing_interval', 1);

        // Ustawienie pól acf dla subskrypcji
        update_post_meta($subscription->get_id(), 'number_of_billing_periods', $billing_periods);
        update_post_meta($subscription->get_id(), 'number_of_periods_to_expire', $expiration_months);
        update_post_meta($subscription->get_id(), 'towp_payment_count', 1);
        $next_payment_date = '';
        if ($billing_periods == "1") {
            $next_payment_date = NULL;
        } elseif ($billing_periods == "2") {
            $next_payment_date = date('Y-m-d H:i:s', strtotime("+1 month", strtotime($subscription->get_date('start'))));
        } else {
            $next_payment_date = date('Y-m-d H:i:s', strtotime("+$billing_periods months", strtotime($subscription->get_date('start'))));
        }

        // Oblicz datę zakończenia subskrypcji
        $end_date = date('Y-m-d H:i:s', strtotime("+$expiration_months months", strtotime($subscription->get_date('start'))));

        // Upewnij się, że data zakończenia jest po dacie następnej płatności
        if (empty($next_payment_date) || strtotime($end_date) > strtotime($next_payment_date)) {
            $subscription->update_dates(array(
                'next_payment' => $next_payment_date,
                'end' => $end_date
            ));
        } else {
            $adjusted_end_date = date('Y-m-d H:i:s', strtotime("+1 day", strtotime($next_payment_date)));
            $subscription->update_dates(array(
                'next_payment' => $next_payment_date,
                'end' => $adjusted_end_date
            ));
        }

        if (!$subscription->save()) {
            error_log('Nie udało się zapisać subskrypcji ID: ' . $subscription->get_id());
        }
    }
}
add_action('woocommerce_checkout_subscription_created', 'towp_customize_subscription_periods_and_expiration');

// Śledzenie liczby wykonanych płatności
function towp_track_subscription_payments($subscription_id) {
    // Sprawdzenie, czy ID subskrypcji jest poprawne
    if (!$subscription_id) {
        error_log('Nieprawidłowe ID subskrypcji - track_subscription_payment');
        return;
    }

    $subscription = wcs_get_subscription($subscription_id);
    if (!$subscription || is_wp_error($subscription)) {
        error_log('Nie udało się pobrać id subskrypcji: ' . $subscription_id);
        return;
    }
	$payment_count = get_field('towp_payment_count', $subscription_id);
	//$payment_count = get_post_meta($subscription_id,'towp_payment_count',true);  
	$note = $payment_count;
    $subscription->add_order_note($note);
	// Pobierz aktualną wartość 'towp_payment_count' z ACF		
        
        $payment_count+=1;
		// Zaktualizuj pole 'towp_payment_count' w ACF		
        update_post_meta($subscription->get_id(), 'towp_payment_count', $payment_count);
	$note2 = $payment_count;
    $subscription->add_order_note($note2);
    $billing_periods = get_post_meta($subscription_id, 'number_of_billing_periods', true);
    if (!empty($billing_periods) && is_numeric($billing_periods)) {      
        if ($payment_count >= intval($billing_periods)) {
            $subscription->update_dates(array('next_payment' => null));  
        }
    }
	$subscription->save();
}
add_action('woocommerce_subscription_renewal_payment_complete', 'towp_track_subscription_payments', 10, 1);

//
// MOJE KONTO
// Dodanie ekstra informacji o okresie subskrypcji 
function towp_subscription_period_display( $subscription ) {
    if ( is_a( $subscription, 'WC_Subscription' ) ) {
        $start_date = $subscription->get_date('start');
        $end_date = $subscription->get_date('end');
        return '<p><small>Okres subskrypcji: od ' . date('d-m-Y', strtotime($start_date)) . ' do </strong>' . date('d-m-Y', strtotime($end_date)) . '</strong></small></p>';
    }
    return '';
}

// Dodanie informacji do zakładki Moje Konto -> Subskrypcje
add_action( 'woocommerce_subscription_before_actions', 'towp_add_subscription_period_my_account' );

function towp_add_subscription_period_my_account( $subscription ) {
    echo towp_subscription_period_display( $subscription );
}
add_filter( 'woocommerce_subscription_table_headers', 'towp_add_subscription_duration_header' );


//dodanie własnych interwałów do woocommerce subscriptions
function towp_extend_subscription_period_intervals( $intervals ) {   
    $intervals[16] = sprintf( __( 'every %s', 'my-text-domain' ), wcs_append_numeral_suffix( 16 ) );
    return $intervals;
}
add_filter( 'woocommerce_subscription_period_interval_strings', 'towp_extend_subscription_period_intervals' );

//dev debug of subscription
function towp_var_dump_subscription( $subscription ) {
    // Upewnij się, że jesteś na odpowiedniej stronie i masz odpowiednie uprawnienia
    if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
        echo '<pre>';
        var_dump( $subscription );
        echo '</pre>';
    }
}
//add_action( 'woocommerce_subscription_details_table', 'towp_var_dump_subscription' ); 