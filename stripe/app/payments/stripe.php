<?php

use Tygh\Registry;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

$token = $_POST['stripeToken'];

if (!empty($token)) {
    $pp_response["reason_text"] = '';

    $order_id = ($order_info['repaid']) ? ($order_id . '_' . $order_info['repaid']) : $order_id;
    $payment_id = db_get_field("SELECT ?:orders.payment_id FROM ?:orders WHERE ?:orders.order_id = ?i", $order_id);
    $processor_data = fn_get_payment_method_data($payment_id);

    $order_amount = $order_info['total'] * 100;
    $pre_order = substr($_SERVER["HTTP_HOST"], 0, 2);
    $web_orderid = $pre_order.$order_id;
    $key = $processor_data['processor_params']['key'];
    @require_once(Registry::get('config.dir.payments') . 'stripe/init.php');

    \Stripe\Stripe::setApiKey($key);

    $charge = \Stripe\Charge::create([
        'amount' => $order_amount,
        'currency' => 'usd',
        'description' => 'Order No.: ' . $order_id,
        'source' => $token,
        'receipt_email' => $order_info['email'],
        'metadata' => ['order_id' => $web_orderid]
    ]);

    if (isset($charge['id'])) {
        $message = "Payment complete.";
        $charge_status = $charge['status'];
    }

    if ($charge_status == "successed") {
        $pp_response['order_status'] = 'P';
        $pp_response['reason_text'] = $message;
        $pp_response['transaction_id'] = $charge['id'];
    } else {
        $message = "Status:" . $charge['status'] . ", Failure Code: " . $charge['failure_code'] . ", Message: " . $charge['failure_message'];
        $pp_response['order_status'] = 'F';
        $pp_response['reason_text'] = $message;
        $pp_response['transaction_id'] = $charge['id'];
    }

    fn_finish_payment($order_id, $pp_response, false);
    fn_order_placement_routines('route', $order_id);

    exit;
}
