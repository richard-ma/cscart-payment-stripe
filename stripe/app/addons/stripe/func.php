<?php

use Tygh\Registry;
use Tygh\Settings;
use Tygh\Http;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

function fn_stripe_install()
{
    db_query("INSERT INTO `?:payment_processors` (`processor`, `processor_script`, `processor_template`, `admin_template`, `callback`, `type`, `addon`) VALUES ('Stripe', 'stripe.php', 'views/orders/components/payments/cc_stripe.tpl', 'stripe.tpl', 'N', 'P', 'stripe');");
}

function fn_stripe_uninstall()
{
    db_query("DELETE FROM ?:payment_descriptions WHERE payment_id IN (SELECT payment_id FROM ?:payments WHERE processor_id IN (SELECT processor_id FROM ?:payment_processors WHERE processor_script IN ('stripe.php')))");
    db_query("DELETE FROM ?:payments WHERE processor_id IN (SELECT processor_id FROM ?:payment_processors WHERE processor_script IN ('stripe.php'))");
    db_query("DELETE FROM ?:payment_processors WHERE processor_script IN ('stripe.php')");
}
