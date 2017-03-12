<?php

require_once 'vendor/autoload.php';

require_once 'Order.php';
require_once("alipay/alipay.config.php");
require_once("alipay/lib/alipay_submit.class.php");

require_once('wxpay/lib/WxPay.Api.php');
require_once('wxpay/example/WxPay.NativePay.php');
require_once('wxpay/example/log.php');

use PayPal\Api\Amount;
use PayPal\Api\Details;
use PayPal\Api\Item;
use PayPal\Api\ItemList;
use PayPal\Api\Payer;
use PayPal\Api\Payment;
use PayPal\Api\RedirectUrls;
use PayPal\Api\Transaction;
use PayPal\Exception\PayPalConnectionException;

require_once('paypal/paypal.config.php');

use Ramsey\Uuid\Uuid;

date_default_timezone_set('PRC'); // 时区设置

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


function CreateOrder ($id, $app_id, $user_id, $payment, $currency, $total) {
    // 订单过期
    Order::expire();

    // 检查是否已经购买
    if (Order::keys_has($user_id, $app_id)) {
        http_response_code(403);
    }

    // 创建订单
    $order = Order::create($id, $app_id, $user_id, $payment, $currency, $total);


    return $order;
}

// 支付宝
if ($payment === "alipay") {
    $alipay_parameter = [
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
    $url = $alipaySubmit->alipay_gateway_new . $alipaySubmit->buildRequestParaToString($alipay_parameter);

    $order = CreateOrder($id, $app_id, $user_id, $payment, $currency, $total);

    if ($order) {
        header('Content-Type: application/json');
        echo json_encode(['order' => $order, 'url' => $url]);
    } else {
        http_response_code(409);
    };
    
} 
// 微信
else if ($payment === "wechat") {
    // 模式二
    $notify = new NativePay();

    $order = CreateOrder($id, $app_id, $user_id, $payment, $currency, $total);  
    
    $id = str_replace("-", "", $id);


    $wxpay_parameter = new WxPayUnifiedOrder();
    $wxpay_parameter->setBody($app_id);
    $wxpay_parameter->SetOut_trade_no($id);
    $wxpay_parameter->SetTotal_fee($total * 100);
    $wxpay_parameter->SetTime_start(date("YmdHis"));
    // $wxpay_parameter->SetTime_expire(date("YmdHis", time() + 600));
    $wxpay_parameter->SetNotify_url(WxPayConfig::NOTIFY_URL);
    // $wxpay_parameter->SetNotify_url("http://paysdk.weixin.qq.com/example/notify.php");
    $wxpay_parameter->SetTrade_type("NATIVE");
    $wxpay_parameter->SetProduct_id($app_id);

    $result = $notify->GetPayUrl($wxpay_parameter);
    $url2 = $result["code_url"];


    if ($order) {
        echo json_encode(['url' => $url2]);
    } else {
        http_response_code(409);
    };  
    
}
// paypal
else if ($payment === "paypal") {
    $paypal_parameter['intent']    = 'sale';
    $paypal_parameter['title']     = $app_id;
    $paypal_parameter['body']      = 'body';
    $paypal_parameter['currency']  = 'USD';
    $paypal_parameter['price']     = $total;
    $paypal_parameter['shipping']  = 0;
    $paypal_parameter['tax']       = 0;

    $paypal_parameter['success']    = PayPalConfig::return_url;
    $paypal_parameter['cancel']     = PayPalConfig::cancel_url;

    // $apiContext = new ApiContext(
    //     new OAuthTokenCredential(
    //         PayPalConfig::clientId, PayPalConfig::clientSecret
    //     )
    // );

    $order = CreateOrder($id, $app_id, $user_id, $payment, $currency, $total);

    if ($order) {
        $payer = new Payer();
        $payer->setPaymentMethod("paypal");

        // ### Itemized information
        // (Optional) Lets you specify item wise
        // information
        $item1 = new Item();
        $item1->setName($paypal_parameter['title'])
            ->setCurrency($paypal_parameter['currency'])
            ->setQuantity(1)
            ->setPrice($paypal_parameter['price']);

        $itemList = new ItemList();
        $itemList->setItems([$item1]);

        // ### Additional payment details
        // Use this optional field to set additional
        // payment information such as tax, shipping
        // charges etc.
        $details = new Details();
        $details->setShipping($paypal_parameter['shipping'])
            ->setTax($paypal_parameter['tax'])
            ->setSubtotal($paypal_parameter['price']);

        // ### Amount
        // Lets you specify a payment amount.
        // You can also specify additional details
        // such as shipping, tax.
        $amount = new Amount();
        $amount->setCurrency($paypal_parameter['currency'])
            ->setTotal($paypal_parameter['price'] + $paypal_parameter['shipping'] + $paypal_parameter['tax'])
            ->setDetails($details);

        // ### Transaction
        // A transaction defines the contract of a
        // payment - what is the payment for and who
        // is fulfilling it. 
        $transaction = new Transaction();
        $transaction->setAmount($amount)
            ->setItemList($itemList)
            ->setDescription($paypal_parameter['body'])
            ->setInvoiceNumber($order["id"]);

        // ### Redirect urls
        // Set the urls that the buyer must be redirected to after 
        // payment approval/ cancellation.

        $redirectUrls = new RedirectUrls();
        $redirectUrls->setReturnUrl($paypal_parameter['success'])
            ->setCancelUrl($paypal_parameter['cancel']);
        
        

        // ### Payment
        // A Payment Resource; create one using
        // the above types and intent set to 'sale'
        $payment = new Payment();
        $payment->setIntent($paypal_parameter['intent'] )
            ->setPayer($payer)
            ->setRedirectUrls($redirectUrls)
            ->setTransactions([$transaction]);

        // $apiContext->setConfig(
        //     array(
        //         'mode' => 'sandbox',
        //     )
        // );

        try {
            $payment->create($apiContext);
        } catch (Exception $e) {
            http_response_code(400);
            die();
        }

        $approvalUrl = $payment->getApprovalLink();
        error_log($approvalUrl);

        header('Content-Type: application/json');
        echo json_encode(['url' => $approvalUrl]);
    } else {
        http_response_code(409);
    };
}
