<?php

require_once 'vendor/autoload.php';

require_once 'Order.php';
require_once("alipay/alipay.config.php");
require_once("alipay/lib/alipay_submit.class.php");

use Ramsey\Uuid\Uuid;

global $alipay_config;

$currency_payments = [
    "cny" => ["alipay", "wechat"],
    "usd" => ["paypal"]
];

if (!(
    isset($_POST['app_id'], $_POST['user_id'], $_POST['payment'], $_POST['currency']) &&
    array_key_exists($_POST['currency'], $currency_payments) &&
    in_array($_POST['payment'], $currency_payments[$_POST['currency']])
)
) {
    http_response_code(400);
    die();
}

$app_id = $_POST['app_id'];
$user_id = $_POST['user_id'];
$payment = $_POST['payment'];
$currency = $_POST['currency'];
$id = Uuid::uuid1()->toString();

// 查询价格
$total = Order::price($app_id, $currency);

$parameter = [
    "service" => $alipay_config['service'],
    "partner" => $alipay_config['partner'],
    "seller_id" => $alipay_config['seller_id'],
    "payment_type" => $alipay_config['payment_type'],
    "notify_url" => $alipay_config['notify_url'],
    "return_url" => $alipay_config['return_url'],
    "_input_charset" => trim(strtolower($alipay_config['input_charset'])),
    "out_trade_no" => $id,
    "subject" => $app_id,
    "total_fee" => $total,
    //"show_url"	=> $show_url,
    "app_pay" => "Y",//启用此参数能唤起钱包APP支付宝
    //"body"	=> "永远消失的幻想乡",
    //其他业务参数根据在线开发文档，添加参数.文档地址:https://doc.open.alipay.com/doc2/detail.htm?spm=a219a.7629140.0.0.2Z6TSk&treeId=60&articleId=103693&docType=1
    //如"参数名"	=> "参数值"   注：上一个参数末尾需要“,”逗号。
];

$alipaySubmit = new AlipaySubmit($alipay_config);
$url = $alipaySubmit->alipay_gateway_new . $alipaySubmit->buildRequestParaToString($parameter);

// 订单过期
Order::expire();

// 检查是否已经购买
if (Order::keys_has($user_id, $app_id)) {
    http_response_code(403);
}

// 创建订单
$order = Order::create($id, $app_id, $user_id, $payment, $currency, $total);
if ($order) {
    header('Content-Type: application/json');
    echo json_encode(['order' => $order, 'url' => $url]);
} else {
    http_response_code(409);
};
