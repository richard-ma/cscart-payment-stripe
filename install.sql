/* 安装 */
INSERT INTO `cscart_payment_processors` (`processor`, `processor_script`, `processor_template`, `admin_template`, `callback`, `type`, `addon`) VALUES ('Stripe', 'stripe.php', 'views/orders/components/payments/cc.tpl', 'stripe.tpl', 'N', 'P', '');

/*删除payment description*/
DELETE FROM `cscart_payment_descriptions` WHERE `payment_id` in (SELECT `payment_id` FROM `cscart_payments` WHERE `processor_id` in (SELECT `processor_id` FROM `cscart_payment_processors` WHERE `addon` = 'stripe'));
DELETE FROM `cscart_payments` WHERE `processor_id` in (SELECT `processor_id` FROM `cscart_payment_processors` WHERE `addon` = 'stripe');
DELETE FROM `cscart_payment_processors` WHERE `addon` = 'stripe';
