<?php
// #Execute Payment Sample
// This is the second step required to complete
// PayPal checkout. Once user completes the payment, paypal
// redirects the browser to "redirectUrl" provided in the request.
// This sample will show you how to execute the payment
// that has been approved by
// the buyer by logging into paypal site.
// You can optionally update transaction
// information by passing in one or more transactions.
// API used: POST '/v1/payments/payment/<payment-id>/execute'.

require_once '../vendor/autoload.php';
require_once('paypal.config.php');
require_once("../Order.php");

use PayPal\Api\Payment;
use PayPal\Api\PaymentExecution;
use PayPal\Exception\PayPalConnectionException;


if(!isset($_GET['success'], $_GET['paymentId'], $_GET['PayerID'])){
    die();
}

if((bool)$_GET['success']=== 'false'){

    echo '交易失败!';
    die();
}

$paymentID = $_GET['paymentId'];
$payerId = $_GET['PayerID'];

$payment = Payment::get($paymentID, $apiContext);

$execute = new PaymentExecution();
$execute->setPayerId($payerId);

try{
    $result = $payment->execute($execute, $apiContext);
}catch(Exception $e){
    die($e);
}

$obj = json_decode($payment);

$out_trade_no = $obj->transactions[0]->invoice_number;

try {
    Order::finish($out_trade_no, $paymentID);
    echo Order::finish_view($out_trade_no);
} catch (Exception $error) {
    echo '错误: ' . $error->getMessage();
}