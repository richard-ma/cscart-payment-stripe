<?php
/***************************************************************************
*                                                                          *
*   (c) 2004 Vladimir V. Kalynyak, Alexey V. Vinokurov, Ilya M. Shalnev    *
*                                                                          *
* This  is  commercial  software,  only  users  who have purchased a valid *
* license  and  accept  to the terms of the  License Agreement can install *
* and use this program.                                                    *
*                                                                          *
****************************************************************************
* PLEASE READ THE FULL TEXT  OF THE SOFTWARE  LICENSE   AGREEMENT  IN  THE *
* "copyright.txt" FILE PROVIDED WITH THIS DISTRIBUTION PACKAGE.            *
****************************************************************************/

use Tygh\Registry;
use Tygh\Storage;
use Tygh\Session;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

fn_enable_checkout_mode();

fn_define('ORDERS_TIMEOUT', 60);

// Cart is empty, create it
if (empty($_SESSION['cart'])) {
    fn_clear_cart($_SESSION['cart']);
}

$cart = & $_SESSION['cart'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    fn_restore_processed_user_password($_REQUEST['user_data'], $_POST['user_data']);

    //
    // Add product to cart
    //
    if ($mode == 'add') {
        if (empty($auth['user_id']) && Registry::get('settings.General.allow_anonymous_shopping') != 'allow_shopping') {
            return array(CONTROLLER_STATUS_REDIRECT, 'auth.login_form?return_url=' . urlencode($_REQUEST['return_url']));
        }

        // Add to cart button was pressed for single product on advanced list
        if (!empty($dispatch_extra)) {
            if (empty($_REQUEST['product_data'][$dispatch_extra]['amount'])) {
                $_REQUEST['product_data'][$dispatch_extra]['amount'] = 1;
            }
            foreach ($_REQUEST['product_data'] as $key => $data) {
                if ($key != $dispatch_extra && $key != 'custom_files') {
                    unset($_REQUEST['product_data'][$key]);
                }
            }
        }

        $prev_cart_products = empty($cart['products']) ? array() : $cart['products'];

        fn_add_product_to_cart($_REQUEST['product_data'], $cart, $auth);
        fn_save_cart_content($cart, $auth['user_id']);

        $previous_state = md5(serialize($cart['products']));
        $cart['change_cart_products'] = true;
        fn_calculate_cart_content($cart, $auth, 'S', true, 'F', true);

        if (md5(serialize($cart['products'])) != $previous_state && empty($cart['skip_notification'])) {
            $product_cnt = 0;
            $added_products = array();
            foreach ($cart['products'] as $key => $data) {
                if (empty($prev_cart_products[$key]) || !empty($prev_cart_products[$key]) && $prev_cart_products[$key]['amount'] != $data['amount']) {
                    $added_products[$key] = $data;
                    $added_products[$key]['product_option_data'] = fn_get_selected_product_options_info($data['product_options']);
                    if (!empty($prev_cart_products[$key])) {
                        $added_products[$key]['amount'] = $data['amount'] - $prev_cart_products[$key]['amount'];
                    }
                    $product_cnt += $added_products[$key]['amount'];
                }
            }

            if (!empty($added_products)) {
                Tygh::$app['view']->assign('added_products', $added_products);
                if (Registry::get('config.tweaks.disable_dhtml') && Registry::get('config.tweaks.redirect_to_cart')) {
                    Tygh::$app['view']->assign('continue_url', (!empty($_REQUEST['redirect_url']) && empty($_REQUEST['appearance']['details_page'])) ? $_REQUEST['redirect_url'] : $_SESSION['continue_url']);
                }

                $msg = Tygh::$app['view']->fetch('views/checkout/components/product_notification.tpl');
                fn_set_notification('I', __($product_cnt > 1 ? 'products_added_to_cart' : 'product_added_to_cart'), $msg, 'I');
                $cart['recalculate'] = true;
            } else {
                fn_set_notification('N', __('notice'), __('product_in_cart'));
            }
        }

        unset($cart['skip_notification']);

        if (Registry::get('config.tweaks.disable_dhtml') && Registry::get('config.tweaks.redirect_to_cart') && !defined('AJAX_REQUEST')) {
            if (!empty($_REQUEST['redirect_url']) && empty($_REQUEST['appearance']['details_page'])) {
                $_SESSION['continue_url'] = fn_url_remove_service_params($_REQUEST['redirect_url']);
            }
            unset($_REQUEST['redirect_url']);
        }

        return array(CONTROLLER_STATUS_OK, 'checkout.cart');
    }

    //
    // Update products quantity in the cart
    //
    if ($mode == 'update') {

        if (!empty($_REQUEST['cart_products'])) {
            foreach ($_REQUEST['cart_products'] as $_key => $_data) {
                if (empty($_data['amount']) && !isset($cart['products'][$_key]['extra']['parent'])) {
                    fn_delete_cart_product($cart, $_key);
                }
            }
            fn_add_product_to_cart($_REQUEST['cart_products'], $cart, $auth, true);
            fn_save_cart_content($cart, $auth['user_id']);
        }

        unset($cart['product_groups']);

        fn_set_notification('N', __('notice'), __('text_products_updated_successfully'));

        // Recalculate cart when updating the products
        if (!empty($cart['chosen_shipping'])) {
            $cart['calculate_shipping'] = true;
        }
        $cart['recalculate'] = true;

        return array(CONTROLLER_STATUS_OK, 'checkout.' . $_REQUEST['redirect_mode']);

    }

    //
    // Estimate shipping cost
    //
    if ($mode == 'shipping_estimation') {

        fn_define('ESTIMATION', true);

        $customer_location = empty($_REQUEST['customer_location']) ? array() : $_REQUEST['customer_location'];
        foreach ($customer_location as $k => $v) {
            $cart['user_data']['s_' . $k] = $v;
        }
        $_SESSION['customer_loc'] = $customer_location;

        $cart['recalculate'] = true;

        $cart['chosen_shipping'] = array();

        if (!empty($_REQUEST['shipping_ids'])) {
            fn_checkout_update_shipping($cart, $_REQUEST['shipping_ids']);
        }

        $cart['calculate_shipping'] = true;
        list ($cart_products, $product_groups) = fn_calculate_cart_content($cart, $auth, 'A', true, 'F', true);
        if (Registry::get('settings.Checkout.display_shipping_step') != 'Y' && fn_allowed_for('ULTIMATE')) {
            Tygh::$app['view']->assign('show_only_first_shipping', true);
        }

        Tygh::$app['view']->assign('product_groups', $cart['product_groups']);
        Tygh::$app['view']->assign('cart', $cart);
        Tygh::$app['view']->assign('cart_products', array_reverse($cart_products, true));
        Tygh::$app['view']->assign('location', empty($_REQUEST['location']) ? 'cart' : $_REQUEST['location']);
        Tygh::$app['view']->assign('additional_id', empty($_REQUEST['additional_id']) ? '' : $_REQUEST['additional_id']);

        if (defined('AJAX_REQUEST')) {
            if (fn_is_empty($cart_products) && fn_is_empty($cart['product_groups'])) {
                Tygh::$app['ajax']->assignHtml('shipping_estimation_sidebox' . (empty($_REQUEST['additional_id']) ? '' : '_' . $_REQUEST['additional_id']), __('no_rates_for_empty_cart'));
            } else {
                Tygh::$app['view']->display(empty($_REQUEST['location']) ? 'views/checkout/components/checkout_totals.tpl' : 'views/checkout/components/shipping_estimation.tpl');
            }
            exit;
        }

        $redirect_mode = !empty($_REQUEST['current_mode']) ? $_REQUEST['current_mode'] : 'cart';

        return array(CONTROLLER_STATUS_OK, 'checkout.' . $redirect_mode . '?show_shippings=Y');
    }

    if ($mode == 'update_shipping') {
        if (!empty($_REQUEST['shipping_ids'])) {
            fn_checkout_update_shipping($cart, $_REQUEST['shipping_ids']);
        }

        return array(CONTROLLER_STATUS_OK, 'checkout.' . $_REQUEST['redirect_mode']);
    }

    // Apply Discount Coupon
    if ($mode == 'apply_coupon') {
        fn_trusted_vars('coupon_code');

        unset($_SESSION['promotion_notices']);
        $cart['pending_coupon'] = strtolower(trim($_REQUEST['coupon_code']));
        $cart['recalculate'] = true;

        if (!empty($cart['chosen_shipping'])) {
            $cart['calculate_shipping'] = true;
        }

        return array(CONTROLLER_STATUS_OK);
    }

    if ($mode == 'add_profile') {

        if (fn_image_verification('register', $_REQUEST) == false) {
            fn_save_post_data('user_data');

            return array(CONTROLLER_STATUS_REDIRECT, 'checkout.checkout?login_type=register');
        }

        if (list($user_id, $profile_id) = fn_update_user(0, $_REQUEST['user_data'], $auth, false, true)) {
            $profile_fields = fn_get_profile_fields('O');

            db_query("DELETE FROM ?:user_session_products WHERE session_id = ?s AND type = ?s AND user_type = ?s", Session::getId(), 'C', 'U');
            fn_save_cart_content($cart, $user_id);

            fn_login_user($user_id);

            $step = 'step_two';
            if (empty($profile_fields['B']) && empty($profile_fields['S'])) {
                $step = 'step_three';
            }

            $suffix = '?edit_step=' . $step;
        } else {
            fn_save_post_data('user_data');
            $suffix = '?login_type=register';
        }

        return array(CONTROLLER_STATUS_OK, 'checkout.checkout' .  $suffix);
    }

    if ($mode == 'customer_info') {
        $redirect_params = array();
        if (!empty($_REQUEST['guest_checkout'])) {
            $redirect_params['guest_checkout'] = 1;
        }

        if (Registry::get('settings.Checkout.disable_anonymous_checkout') == 'Y' && empty($cart['user_data']['email']) && fn_image_verification('checkout', $_REQUEST) == false) {
            fn_save_post_data('user_data');

            return array(CONTROLLER_STATUS_REDIRECT, 'checkout.checkout?login_type=guest');
        }

        $profile_fields = fn_get_profile_fields('O');
        $user_profile = array();

        if (!empty($_REQUEST['user_data'])) {
            if (empty($auth['user_id']) && !empty($_REQUEST['user_data']['email'])) {
                $email_exists = fn_is_user_exists(0, $_REQUEST['user_data']);

                if (!empty($email_exists)) {
                    fn_set_notification('E', __('error'), __('error_user_exists'));
                    fn_save_post_data('user_data');

                    return array(CONTROLLER_STATUS_REDIRECT, 'checkout.checkout');
                }
            }

            $user_data = $_REQUEST['user_data'];

            unset($user_data['user_type']);
            if (!empty($cart['user_data'])) {
                $cart['user_data'] = fn_array_merge($cart['user_data'], $user_data);
            } else {
                $cart['user_data'] = $user_data;
            }

            // Fill shipping info with billing if needed
            if (empty($_REQUEST['ship_to_another'])) {
                fn_fill_address($cart['user_data'], $profile_fields);
            }

            // Add descriptions for countries and states
            fn_add_user_data_descriptions($cart['user_data']);

            // Update profile info (if user is logged in)
            $cart['profile_registration_attempt'] = false;
            $cart['ship_to_another'] = !empty($_REQUEST['ship_to_another']);

            if (!empty($auth['user_id'])) {
                // Check email
                $email_exists = fn_is_user_exists($auth['user_id'], $cart['user_data']);

                if (!empty($email_exists)) {
                    fn_set_notification('E', __('error'), __('error_user_exists'));
                    $cart['user_data']['email'] = '';
                } else {
                    fn_update_user($auth['user_id'], $cart['user_data'], $auth, !empty($_REQUEST['ship_to_another']), false);
                }

            } elseif (Registry::get('settings.Checkout.disable_anonymous_checkout') == 'Y' || !empty($user_data['password1'])) {
                $cart['profile_registration_attempt'] = true;
                $user_profile = fn_update_user(0, $cart['user_data'], $auth, $cart['ship_to_another'], true);
                if ($user_profile === false) {
                    unset($cart['user_data']['email'], $cart['user_data']['user_login']);
                } else {
                    list($user_id, $cart['profile_id']) = $user_profile;
                    fn_login_user($user_id);
                }
            } else {
                $profile_fields = fn_get_profile_fields('O', $auth);
                if (count($profile_fields['C']) > 1) {
                    return array(CONTROLLER_STATUS_REDIRECT, 'checkout.checkout?edit_step=step_one');
                }
            }
        }

        $cart['recalculate'] = true;

        fn_save_cart_content($cart, $auth['user_id']);

        $redirect_params['edit_step'] = 'step_two';
        if (empty($profile_fields['B']) && empty($profile_fields['S'])) {
            $redirect_params['edit_step'] = 'step_three';
        }

        return array(CONTROLLER_STATUS_REDIRECT, 'checkout.checkout?' . http_build_query($redirect_params));
    }

    if ($mode == 'place_order') {

        if (!empty($_REQUEST['update_steps'])) {
            $_REQUEST['update_step'] = 'step_four';
            fn_checkout_update_steps($cart, $auth, $_REQUEST);
        }

        $status = fn_checkout_place_order($cart, $auth, $_REQUEST);

        if ($status == PLACE_ORDER_STATUS_TO_CART) {
            return array(CONTROLLER_STATUS_REDIRECT, 'checkout.cart');
        } elseif ($status == PLACE_ORDER_STATUS_DENIED) {
            return array(CONTROLLER_STATUS_DENIED);
        }

    }

    if ($mode == 'update_steps') {

        $redirect_params = fn_checkout_update_steps($cart, $auth, $_REQUEST);

        return array(CONTROLLER_STATUS_REDIRECT, 'checkout.checkout?' . http_build_query($redirect_params));

    }

    if ($mode == 'create_profile') {

        if (!empty($_REQUEST['order_id']) && !empty($auth['order_ids']) && in_array($_REQUEST['order_id'], $auth['order_ids'])) {

            $order_info = fn_get_order_info($_REQUEST['order_id']);
            $user_data = $_REQUEST['user_data'];

            fn_fill_user_fields($user_data);

            foreach ($user_data as $k => $v) {
                if (isset($order_info[$k])) {
                    $user_data[$k] = $order_info[$k];
                }
            }

            if ($res = fn_update_user(0, $user_data, $auth, true, true)) {
                list($user_id) = $res;
                fn_login_user($user_id);

                return array(CONTROLLER_STATUS_REDIRECT, 'profiles.success_add');
            } else {
                return array(CONTROLLER_STATUS_REDIRECT, 'checkout.complete?order_id=' . $_REQUEST['order_id']);
            }
        } else {
            return array(CONTROLLER_STATUS_DENIED);
        }
    }

    return array(CONTROLLER_STATUS_OK, 'checkout.cart');
}

//
// Delete discount coupon
//
if ($mode == 'delete_coupon') {
    fn_trusted_vars('coupon_code');
    unset($cart['coupons'][$_REQUEST['coupon_code']], $cart['pending_coupon']);
    $cart['recalculate'] = true;

    if (!empty($cart['chosen_shipping'])) {
        $cart['calculate_shipping'] = true;
    }

    return array(CONTROLLER_STATUS_OK);
}

if (empty($mode)) {
    $redirect_mode = empty($_REQUEST['redirect_mode']) ? 'checkout' : $_REQUEST['redirect_mode'];

    return array(CONTROLLER_STATUS_REDIRECT, 'checkout.' . $redirect_mode);
}

$payment_methods = fn_prepare_checkout_payment_methods($cart, $auth);
if (((fn_cart_is_empty($cart) && !isset($force_redirection)) || empty($payment_methods)) && !in_array($mode, array('clear', 'delete', 'cart', 'update', 'apply_coupon', 'shipping_estimation', 'update_shipping', 'complete'))) {
    if (empty($payment_methods)) {
        fn_set_notification('W', __('notice'),  __('cannot_proccess_checkout_without_payment_methods'), 'K', 'no_payment_notification');
    } else {
        fn_set_notification('W', __('cart_is_empty'),  __('cannot_proccess_checkout'), 'K', 'cannot_proccess_checkout');
    }
    $force_redirection = 'checkout.cart';
    if (defined('AJAX_REQUEST')) {
        Tygh::$app['ajax']->assign('force_redirection', fn_url($force_redirection));
        exit;
    } else {
        return array(CONTROLLER_STATUS_REDIRECT, $force_redirection);
    }
}

// Cart Items
if ($mode == 'cart') {

    list ($cart_products, $product_groups) = fn_calculate_cart_content($cart, $auth, Registry::get('settings.General.estimate_shipping_cost') == 'Y' ? 'A' : 'S', true, 'F', true);

    fn_gather_additional_products_data($cart_products, array('get_icon' => true, 'get_detailed' => true, 'get_options' => true, 'get_discounts' => false));

    fn_add_breadcrumb(__('cart_contents'));

    fn_update_payment_surcharge($cart, $auth);

    $cart_products = array_reverse($cart_products, true);
    Tygh::$app['view']->assign('cart_products', $cart_products);
    Tygh::$app['view']->assign('product_groups', $cart['product_groups']);

    if (fn_allowed_for('MULTIVENDOR')) {
        Tygh::$app['view']->assign('take_surcharge_from_vendor', fn_take_payment_surcharge_from_vendor($cart['products']));
    }

    // Check if any outside checkout is enbaled
    if (fn_cart_is_empty($cart) != true) {
        $checkout_buttons = fn_get_checkout_payment_buttons($cart, $cart_products, $auth);
        if (!empty($checkout_buttons)) {
            Tygh::$app['view']->assign('checkout_add_buttons', $checkout_buttons, false);
        } elseif (empty($payment_methods) && !fn_notification_exists('extra', 'no_payment_notification')) {
            fn_set_notification('W', __('notice'),  __('cannot_proccess_checkout_without_payment_methods'));
        }
    }

// All checkout steps
} elseif ($mode == 'checkout') {
    if (
        Registry::get('settings.General.min_order_amount_type') == 'only_products'
        && Registry::get('settings.General.min_order_amount') > $cart['subtotal']
    ) {
        Tygh::$app['view']->assign('value', Registry::get('settings.General.min_order_amount'));
        $min_amount = Tygh::$app['view']->fetch('common/price.tpl');
        fn_set_notification('W', __('notice'), __('text_min_products_amount_required') . ' ' . $min_amount);

        return array(CONTROLLER_STATUS_REDIRECT, 'checkout.cart');
    }

    fn_add_breadcrumb(__('checkout'));

    $profile_fields = fn_get_profile_fields('O');

    // Display steps
    $display_steps = array(
        'step_one'   => true,
        'step_two'   => true,
        'step_three' => true,
        'step_four'  => true,
    );
    if (Registry::get('settings.Checkout.configure_sign_in_step') == 'hide') {
        // Need to check profile fields
        $required_fields = fn_get_profile_fields('O', array(), CART_LANGUAGE, array(
            'get_checkout_required' => true
        ));
        if (empty($required_fields['C']) && Registry::get('settings.Checkout.disable_anonymous_checkout') != 'Y') {
            $display_steps['step_one'] = false;
        }
    }
    if (empty($profile_fields['B']) && empty($profile_fields['S'])) {
        $display_steps['step_two'] = false;
    }
    if (Registry::get('settings.Checkout.display_shipping_step') != 'Y' && fn_allowed_for('ULTIMATE')) {
        $display_steps['step_three'] = false;
    }
    if (Registry::get('settings.Checkout.display_payment_step') != 'Y') {
        $display_steps['step_four'] = false;
    }
    Tygh::$app['view']->assign('display_steps', $display_steps);

    // Array notifying that one or another step is completed.
    $completed_steps = array(
        'step_one'   => false,
        'step_two'   => false,
        'step_three' => false,
        'step_four'  => false,
    );

    // Set edit step
    $recheck_edit_step = false;
    if (!empty($_REQUEST['edit_step'])) {
        $cart['edit_step'] = $_REQUEST['edit_step'];
    } elseif (empty($cart['edit_step'])) {
        $recheck_edit_step = true;
        $cart['edit_step'] = 'step_one';
        if (!$display_steps['step_one']) {
            $cart['edit_step'] = 'step_two';
        }
    } else {
        if ($cart['edit_step'] == 'step_one' && !$display_steps['step_one']) {
            $cart['edit_step'] = 'step_two';
        } elseif ($cart['edit_step'] == 'step_three' && !$display_steps['step_three']) {
            $cart['edit_step'] = 'step_four';
            if (!$display_steps['step_four']) {
                $cart['edit_step'] = 'step_two';
            }
        } elseif ($cart['edit_step'] == 'step_four' && !$display_steps['step_four']) {
            $cart['edit_step'] = 'step_three';
            if (!$display_steps['step_three']) {
                $cart['edit_step'] = 'step_two';
            }
        }
    }

    // Final step
    $final_step = 'step_four';
    if (!$display_steps['step_four']) {
        $final_step = 'step_three';
        if (!$display_steps['step_three']) {
            $final_step = 'step_two';
        }
    }
    Tygh::$app['view']->assign('final_step', $final_step);

    $cart['user_data'] = !empty($cart['user_data']) ? $cart['user_data'] : array();

    if (!empty($_REQUEST['shipping_ids'])) {
        fn_checkout_update_shipping($cart, $_REQUEST['shipping_ids']);
    }

    if (!empty($_REQUEST['payment_id'])) {
        $cart['payment_id'] = $_REQUEST['payment_id'];
    } elseif (empty($cart['payment_id'])) {
        $params = array(
            'usergroup_ids' => $auth['usergroup_ids'],
        );
        $payments = fn_get_payments($params);
        $first_method = reset($payments);
        $cart['payment_id'] = $first_method['payment_id'];
    }

    if (isset($cart['payment_id'])) {
        $cart['payment_method_data'] = fn_get_payment_method_data($cart['payment_id']);
    }

    if (!empty($auth['user_id'])) {

        //if the error occurred during registration, but despite this, the registration was performed, then the variable should be cleared.
        unset($_SESSION['failed_registration']);

        if (!empty($_REQUEST['profile_id'])) {
            $cart['profile_id'] = $_REQUEST['profile_id'];

        } elseif (!empty($_REQUEST['profile']) && $_REQUEST['profile'] == 'new') {
            $cart['profile_id'] = 0;

        } elseif (empty($cart['profile_id'])) {
            $cart['profile_id'] = db_get_field("SELECT profile_id FROM ?:user_profiles WHERE user_id = ?i AND profile_type='P'", $auth['user_id']);
        }

        // Here check the previous and the current checksum of user_data - if they are different, recalculate the cart.
        $current_state = fn_crc32(serialize($cart['user_data']));

        $cart['user_data'] = fn_get_user_info($auth['user_id'], empty($_REQUEST['profile']), $cart['profile_id']);

        if ($current_state != fn_crc32(serialize($cart['user_data']))) {
            $cart['recalculate'] = true;
        }

    } else {

        $_user_data = fn_restore_post_data('user_data');
        if (!empty($_user_data)) {
            $_SESSION['failed_registration'] = true;
        } else {
            unset($_SESSION['failed_registration']);
        }

        fn_add_user_data_descriptions($cart['user_data']);

        if (!empty($_REQUEST['action'])) {
            Tygh::$app['view']->assign('checkout_type', $_REQUEST['action']);
        }
    }

    fn_get_default_credit_card($cart, !empty($_user_data) ? $_user_data : $cart['user_data']);

    if (!empty($cart['extra_payment_info'])) {
        $cart['payment_info'] = empty($cart['payment_info']) ? array() : $cart['payment_info'];
        $cart['payment_info'] = array_merge($cart['payment_info'], $cart['extra_payment_info']);
    }

    Tygh::$app['view']->assign('user_data', !empty($_user_data) ? $_user_data : $cart['user_data']);
    $contact_info_population = fn_check_profile_fields_population($cart['user_data'], 'E', $profile_fields);
    Tygh::$app['view']->assign('contact_info_population', $contact_info_population);

    $contact_fields_filled = fn_check_profile_fields_population($cart['user_data'], 'C', $profile_fields);
    Tygh::$app['view']->assign('contact_fields_filled', $contact_fields_filled);

    $guest_checkout = !empty($_REQUEST['guest_checkout']) || !$display_steps['step_one'];

    // Check fields population on first and second steps
    if (($contact_info_population || $guest_checkout) && empty($_SESSION['failed_registration'])) {
        if (!fn_check_profile_fields_population($cart['user_data'], 'C', $profile_fields)) {
            $recheck_edit_step = false;
            if ($cart['edit_step'] != 'step_one') {
                fn_set_notification('W', __('notice'), __('text_fill_the_mandatory_fields'));

                return array(CONTROLLER_STATUS_REDIRECT, "checkout.checkout?edit_step=step_one");
            }
        }

        $completed_steps['step_one'] = true;

        // All mandatory Billing address data exist.
        $billing_population = fn_check_profile_fields_population($cart['user_data'], 'B', $profile_fields);
        Tygh::$app['view']->assign('billing_population', $billing_population);

        if ($billing_population == true || empty($profile_fields['B'])) {
            // All mandatory Shipping address data exist.
            $shipping_population = fn_check_profile_fields_population($cart['user_data'], 'S', $profile_fields);
            Tygh::$app['view']->assign('shipping_population', $shipping_population);

            if ($shipping_population == true || empty($profile_fields['S'])) {
                $completed_steps['step_two'] = true;
            }
        }
    } elseif ($guest_checkout && !empty($_SESSION['failed_registration'])) {
        $completed_steps['step_one'] = true;
    }

    // Define the variable only if the profiles have not been changed and settings.General.user_multiple_profiles == Y.
    if (fn_need_shipping_recalculation($cart) == false && (!empty($cart['product_groups']) && (Registry::get('settings.General.user_multiple_profiles') != "Y" || (Registry::get('settings.General.user_multiple_profiles') == "Y" && ((isset($user_data['profile_id']) && empty($user_data['profile_id'])) || (!empty($user_data['profile_id']) && $user_data['profile_id'] == $cart['profile_id'])))) || (empty($cart['product_groups']) && Registry::get('settings.General.user_multiple_profiles') == "Y" && isset($user_data['profile_id']) && empty($user_data['profile_id'])))) {
        define('CACHED_SHIPPING_RATES', true);
    }

    if ($cart['edit_step'] == 'step_three' || $cart['edit_step'] == 'step_four' || $completed_steps['step_two']) {
        $cart['calculate_shipping'] = true;
    }

    if (!empty($_REQUEST['active_tab'])) {
        $active_tab = $_REQUEST['active_tab'];
        Tygh::$app['view']->assign('active_tab', $active_tab);
    }

    if (floatval($cart['total']) == 0 || !isset($cart['payment_id'])) {
        $cart['payment_id'] = 0;
    }

    $shipping_calculation_type = (Registry::get('settings.General.estimate_shipping_cost') == 'Y' || $completed_steps['step_two']) ? 'A' : 'S';

    list($cart_products, $product_groups) = fn_calculate_cart_content($cart, $auth, $shipping_calculation_type, true, 'F');

    $payment_methods = fn_prepare_checkout_payment_methods($cart, $auth);
    if (!empty($payment_methods)) {
        $first_methods_group = reset($payment_methods);
        $first_method = reset($first_methods_group);

        $checkout_buttons = fn_get_checkout_payment_buttons($cart, $cart_products, $auth);

        if (!empty($checkout_buttons)) {
            Tygh::$app['view']->assign('checkout_buttons', $checkout_buttons, false);
        }
    } else {
        $first_method = false;
    }

    // Edit step postprocessing
    if ($recheck_edit_step) {
        if ($cart['edit_step'] == 'step_one' && $completed_steps['step_one']) {
            $cart['edit_step'] = 'step_two';
        }
    }
    if ($cart['edit_step'] == 'step_two' && $completed_steps['step_two'] && empty($_REQUEST['from_step'])) {
        if ($display_steps['step_three']) {
            $cart['edit_step'] = 'step_three';
        } elseif ($display_steps['step_four']) {
            $cart['edit_step'] = 'step_four';
        }
    }

    // Backward compatibility
    $_SESSION['edit_step'] = $cart['edit_step'];
    // \Backward compatibility

    // Next step
    $next_step = !empty($_REQUEST['next_step']) ? $_REQUEST['next_step'] : '';
    if (empty($next_step)) {
        if (!empty($_REQUEST['from_step']) && in_array($cart['edit_step'], array('step_one', 'step_two'))) {
            $next_step = $_REQUEST['from_step'];
        } elseif ($cart['edit_step'] == 'step_one') {
            $next_step = 'step_two';
        } elseif ($cart['edit_step'] == 'step_two') {
            $next_step = 'step_three';
            if (fn_allowed_for('ULTIMATE') && !$display_steps['step_three']) {
                $next_step = 'step_four';
            }
        } elseif ($cart['edit_step'] == 'step_three') {
            $next_step = 'step_four';
        }
    }
    Tygh::$app['view']->assign('next_step', $next_step);

    if ($cart['edit_step'] == $final_step && $first_method && empty($cart['payment_id']) && floatval($cart['total']) != 0) {
        $cart['payment_id'] = $first_method['payment_id'];
        // recalculate cart after payment method update
        list($cart_products, $product_groups) = fn_calculate_cart_content($cart, $auth, $shipping_calculation_type, true, 'F');
    }

    // if address step is completed, check if shipping step is completed
    if ($completed_steps['step_two']) {
        $completed_steps['step_three'] = true;
    }

    // If shipping step is completed, assume that payment step is completed too
    if ($completed_steps['step_three']) {
        $completed_steps['step_four'] = true;
    }

    if ((!empty($cart['shipping_failed']) || !empty($cart['company_shipping_failed'])) && $completed_steps['step_three']) {
        $completed_steps['step_four'] = false;

        if (defined('AJAX_REQUEST')) {
            fn_set_notification('W', __('warning'), __('text_no_shipping_methods'));
        }
    }

    // If shipping methods changed and shipping step is completed, display notification
    $shipping_hash = fn_get_shipping_hash($cart['product_groups']);

    if (!empty($_SESSION['shipping_hash']) && $_SESSION['shipping_hash'] != $shipping_hash && $completed_steps['step_three'] && $cart['shipping_required']) {
        $_SESSION['chosen_shipping'] = array();
        fn_set_notification('W', __('important'), __('text_shipping_rates_changed'));

        if ($cart['edit_step'] == 'step_four') {
            return array(CONTROLLER_STATUS_REDIRECT, 'checkout.checkout?edit_step=step_three');
        }
    }

    $_SESSION['shipping_hash'] = $shipping_hash;

    fn_gather_additional_products_data($cart_products, array('get_icon' => true, 'get_detailed' => true, 'get_options' => true, 'get_discounts' => false));

    if (floatval($cart['total']) == 0) {
        $cart['payment_id'] = 0;
    }

    fn_set_hook('checkout_select_default_payment_method', $cart, $payment_methods, $completed_steps);

    if (!empty($cart['payment_id'])) {
        $payment_info = fn_get_payment_method_data($cart['payment_id']);
        Tygh::$app['view']->assign('payment_info', $payment_info);

        list($is_processor_script, $processor_data) = fn_check_processor_script($cart['payment_id']);
        Tygh::$app['view']->assign('processor_data', $processor_data);

        if (!empty($payment_info['processor_params']['iframe_mode']) && $payment_info['processor_params']['iframe_mode'] == 'Y') {
            Tygh::$app['view']->assign('iframe_mode', true);
        }
    }

    Tygh::$app['view']->assign('payment_methods', $payment_methods);

    $cart['payment_surcharge'] = 0;
    if (!empty($cart['payment_id']) && !empty($payment_info)) {
        fn_update_payment_surcharge($cart, $auth);
    }

    if (fn_allowed_for('MULTIVENDOR')) {
        Tygh::$app['view']->assign('take_surcharge_from_vendor', fn_take_payment_surcharge_from_vendor($cart['products']));
    }

    Tygh::$app['view']->assign('usergroups', fn_get_usergroups(array('type' => 'C', 'status' => 'A'), CART_LANGUAGE));
    Tygh::$app['view']->assign('countries', fn_get_simple_countries(true, CART_LANGUAGE));
    Tygh::$app['view']->assign('states', fn_get_all_states());

    $cart['ship_to_another'] = fn_check_shipping_billing($cart['user_data'], $profile_fields);

    Tygh::$app['view']->assign('profile_fields', $profile_fields);

    if (Registry::get('settings.General.user_multiple_profiles') == 'Y') {
        $user_profiles = fn_get_user_profiles($auth['user_id']);
        Tygh::$app['view']->assign('user_profiles', $user_profiles);
    }

    fn_checkout_summary($cart);

    Tygh::$app['view']->assign('use_ajax', 'true');
    Tygh::$app['view']->assign('completed_steps', $completed_steps);
    Tygh::$app['view']->assign('location', 'checkout');

    Tygh::$app['view']->assign('cart', $cart);
    Tygh::$app['view']->assign('cart_products', array_reverse($cart_products, true));
    Tygh::$app['view']->assign('product_groups', $cart['product_groups']);

    if (!empty($cart['failed_order_id']) || !empty($cart['processed_order_id'])) {
        $_ids = !empty($cart['failed_order_id']) ? $cart['failed_order_id'] : $cart['processed_order_id'];
        $_order_id = reset($_ids);

        $_payment_info = db_get_field("SELECT data FROM ?:order_data WHERE order_id = ?i AND type = 'P'", $_order_id);
        $_payment_info = !empty($_payment_info) ? unserialize(fn_decrypt_text($_payment_info)) : array();

        if (!empty($cart['failed_order_id'])) {
            $_msg = !empty($_payment_info['reason_text']) ? $_payment_info['reason_text'] : '';
            $_msg .= empty($_msg) ? __('text_order_placed_error') : '';
            fn_set_notification('O', '', $_msg);
            $cart['processed_order_id'] = $cart['failed_order_id'];
            unset($cart['failed_order_id']);
        }

        unset($_payment_info['card_number'], $_payment_info['cvv2']);
        $cart['payment_info'] = $_payment_info;
        if (!empty($cart['extra_payment_info'])) {
            $cart['payment_info'] = array_merge($cart['payment_info'], $cart['extra_payment_info']);
        }
    }

// Delete product from the cart
} elseif ($mode == 'delete' && isset($_REQUEST['cart_id'])) {

    fn_delete_cart_product($cart, $_REQUEST['cart_id']);

    if (fn_cart_is_empty($cart) == true) {
        fn_clear_cart($cart);
    }

    fn_save_cart_content($cart, $auth['user_id']);

    $cart['recalculate'] = true;
    fn_calculate_cart_content($cart, $auth, 'A', true, 'F', true);

    if (defined('AJAX_REQUEST')) {
        fn_set_notification('N', __('notice'), __('text_product_has_been_deleted'));
    }

    $redirect_mode = empty($_REQUEST['redirect_mode']) ? 'cart' : $_REQUEST['redirect_mode'];

    return array(CONTROLLER_STATUS_REDIRECT, 'checkout.' . $redirect_mode);

} elseif ($mode == 'get_custom_file' && isset($_REQUEST['cart_id']) && isset($_REQUEST['option_id']) && isset($_REQUEST['file'])) {
    if (isset($cart['products'][$_REQUEST['cart_id']]['extra']['custom_files'][$_REQUEST['option_id']][$_REQUEST['file']])) {
        $file = $cart['products'][$_REQUEST['cart_id']]['extra']['custom_files'][$_REQUEST['option_id']][$_REQUEST['file']];

        Storage::instance('custom_files')->get($file['path'], $file['name']);
    }

} elseif ($mode == 'delete_file' && isset($_REQUEST['cart_id'])) {

    if (isset($cart['products'][$_REQUEST['cart_id']]['extra']['custom_files'][$_REQUEST['option_id']][$_REQUEST['file']])) {
        // Delete saved custom file
        $product = $cart['products'][$_REQUEST['cart_id']];
        $option_id = $_REQUEST['option_id'];
        $file_id = $_REQUEST['file'];

        $file = $product['extra']['custom_files'][$option_id][$file_id];

        Storage::instance('custom_files')->delete($file['path']);
        Storage::instance('custom_files')->delete($file['path'] . '_thumb');

        unset($product['extra']['custom_files'][$option_id][$file_id]);

        if (!empty($product['extra']['custom_files'][$option_id])) {
            $product['product_options'][$option_id] = md5(serialize($product['extra']['custom_files'][$option_id]));
        } else {
            unset($product['product_options'][$option_id]);
        }
        $product['extra']['product_options'] = empty($product['product_options']) ? array() : $product['product_options'];

        $cart['products'][$_REQUEST['cart_id']] = $product;
    }

    fn_save_cart_content($cart, $auth['user_id']);

    $cart['recalculate'] = true;

    if (defined('AJAX_REQUEST')) {
        fn_set_notification('N', __('notice'), __('text_product_file_has_been_deleted'));
        if (Registry::get('runtime.action') == 'from_status') {
            fn_calculate_cart_content($cart, $auth, 'S', true, 'F', true);
        }
    }

    return array(CONTROLLER_STATUS_REDIRECT, 'checkout.' . $_REQUEST['redirect_mode']);

//Clear cart
} elseif ($mode == 'clear') {

    fn_clear_cart($cart);
    fn_save_cart_content($cart, $auth['user_id']);

    return array(CONTROLLER_STATUS_REDIRECT, 'checkout.cart');

//Purge undeliverable products
} elseif ($mode == 'purge_undeliverable') {

    fn_purge_undeliverable_products($cart);
    fn_set_notification('N', __('notice'), __('notice_undeliverable_products_removed'));

    return array(CONTROLLER_STATUS_REDIRECT, 'checkout.checkout');

} elseif ($mode == 'complete') {

    if (!empty($_REQUEST['order_id'])) {
        if (empty($auth['user_id'])) {
            if (empty($auth['order_ids'])) {
                return array(CONTROLLER_STATUS_REDIRECT, 'auth.login_form?return_url=' . urlencode(Registry::get('config.current_url')));
            } else {
                $allowed_id = in_array($_REQUEST['order_id'], $auth['order_ids']);
            }
        } else {
            $allowed_id = db_get_field("SELECT user_id FROM ?:orders WHERE user_id = ?i AND order_id = ?i", $auth['user_id'], $_REQUEST['order_id']);
        }

        fn_set_hook('is_order_allowed', $_REQUEST['order_id'], $allowed_id);

        if (empty($allowed_id)) { // Access denied

            return array(CONTROLLER_STATUS_DENIED);
        }

        $order_info = fn_get_order_info($_REQUEST['order_id']);

        if (!empty($order_info['is_parent_order']) && $order_info['is_parent_order'] == 'Y') {
            $order_info['child_ids'] = implode(',', db_get_fields("SELECT order_id FROM ?:orders WHERE parent_order_id = ?i", $_REQUEST['order_id']));
        }
        if (!empty($order_info)) {
            Tygh::$app['view']->assign('order_info', $order_info);
        }
    }
    fn_add_breadcrumb(__('landing_header'));

} elseif ($mode == 'process_payment') {
    if (fn_allow_place_order($cart, $auth) == true) {
        $order_info = $cart;
        $order_info['products'] = $cart['products'];
        $order_info = fn_array_merge($order_info, $cart['user_data']);
        $order_info['order_id'] = $order_id = TIME . "_" . (!empty($auth['user_id']) ? $auth['user_id'] : 0);
        unset($order_info['user_data']);

        list($is_processor_script, $processor_data) = fn_check_processor_script($order_info['payment_id']);
        if ($is_processor_script) {
            set_time_limit(300);
            fn_define('IFRAME_MODE', true);

            include(Registry::get('config.dir.payments') . $processor_data['processor_script']);

            fn_finish_payment($order_id, $pp_response, array());
            fn_order_placement_routines('route', $order_id);
        }
    }
}

if (fn_cart_is_empty($cart) && !isset($force_redirection) && !in_array($mode, array('clear', 'delete', 'cart', 'update', 'apply_coupon', 'shipping_estimation', 'update_shipping', 'complete'))) {
    fn_set_notification('W', __('cart_is_empty'),  __('cannot_proccess_checkout', 'K', 'cannot_proccess_checkout'));

    return array(CONTROLLER_STATUS_REDIRECT, 'checkout.cart');
}

if (!empty($profile_fields)) {
    Tygh::$app['view']->assign('profile_fields', $profile_fields);
}

Tygh::$app['view']->assign('cart', $cart);
Tygh::$app['view']->assign('continue_url', empty($_SESSION['continue_url']) ? '' : $_SESSION['continue_url']);
Tygh::$app['view']->assign('mode', $mode);
Tygh::$app['view']->assign('payment_methods', $payment_methods);

// Remember mode for the check shipping rates
$_SESSION['checkout_mode'] = $mode;
