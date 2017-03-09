<?php
/**
* 	配置账号信息
*/
define('clientId', getenv("PAYPAL_CLIENT_ID"));
define('clientSecret', getenv("PAYPAL_CLIENT_SECRET"));
define('return_url', getenv("PAYPAL_RETURN_URL"));
define('cancel_url', getenv("PAYPAL_CANCEL_URL"));

$apiContext = new \PayPal\Rest\ApiContext(
    new \PayPal\Auth\OAuthTokenCredential(
			clientId, clientSecret
    )
);

class PayPalConfig
{
	const clientId = clientId;
	const clientSecret = clientSecret;
	const return_url = return_url;
  const cancel_url = cancel_url;
}