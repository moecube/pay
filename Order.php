<?php

require_once 'vendor/autoload.php';

/**
 * Created by PhpStorm.
 * User: zh99998
 * Date: 2017/2/23
 * Time: 下午6:43
 */
class Order
{
    /** @var $db PDO */
    public static $db;

    public static function expire(): void
    {
        self::$db->exec("WITH expired AS (UPDATE orders SET status = 'expired' WHERE status = 'new' AND created_at + INTERVAL '1 day' < NOW() RETURNING id) UPDATE keys set order_id = NULL FROM expired WHERE order_id = expired.id");
    }

    public static function price(string $app_id, string $currency): float
    {
        // PHP是世界上最好的语言 参数模板不能用
        $query = self::$db->prepare("SELECT price_$currency::NUMERIC FROM apps WHERE id = :app_id");
        $query->execute(['app_id' => $app_id]);
        return $query->fetchColumn();
    }
    
    public static function create($id, $app_id, $user_id, $payment, $currency, $total)
    {
        self::$db->beginTransaction();
        $query = self::$db->prepare("INSERT INTO orders (id, user_id, status, payment, currency, total) VALUES (:id, :user_id, 'new', :payment, :currency, :total) RETURNING *");
        $query->execute(['id' => $id, 'user_id' => $user_id, 'payment' => $payment, 'currency' => $currency, 'total' => $total]);
        $order = $query->fetch(PDO::FETCH_ASSOC);
        $query = self::$db->prepare("UPDATE keys k1 SET order_id = :id FROM (SELECT id FROM keys WHERE app_id = :app_id AND user_id IS NULL AND order_id IS NULL LIMIT 1 FOR UPDATE SKIP LOCKED) k2 WHERE k1.id = k2.id");
        $query->execute(['id' => $id, 'app_id' => $app_id]);
        if ($query->rowCount()) {
            self::$db->commit();
            return $order;
        } else {
            self::$db->rollBack();
            return NULL;
        }
    }

    public static function finish($id, $transaction_id): void
    {
        self::$db->beginTransaction();

        $query = self::$db->prepare("SELECT *, total::NUMERIC AS total, orders.id AS id, orders.user_id as user_id FROM orders LEFT JOIN keys ON orders.id = keys.order_id WHERE orders.id = :id");
        $query->execute(['id' => $id]);
        $order = $query->fetch(PDO::FETCH_ASSOC);

        if ($order['status'] == 'finished') {
            self::$db->rollBack();
            return;
        }
        error_log(json_encode($order));

        $query = self::$db->prepare("UPDATE orders SET status = 'finished', transaction_id = :transaction_id WHERE id = :id");
        $query->execute(['id' => $id, 'transaction_id' => $transaction_id]);
        $query = self::$db->prepare("UPDATE keys SET user_id = :user_id WHERE order_id = :id");
        $query->execute(['id' => $id, 'user_id' => $order['user_id']]);
        self::$db->commit();

        // PHP是世界上最好的语言 参数模板不能用
        self::$db->exec("NOTIFY belongings, '$order[user_id]'");

        $mail = new PHPMailer;

        $mail->isSMTP();                                      // Set mailer to use SMTP
        $mail->Host = getenv("SMTP_HOST");  // Specify main and backup SMTP servers
        $mail->SMTPAuth = true;                               // Enable SMTP authentication
        $mail->Username = getenv("SMTP_USERNAME");                 // SMTP username
        $mail->Password = getenv("SMTP_PASSWORD");                           // SMTP password
        $mail->SMTPSecure = getenv("SMTP_SECURE");                            // Enable TLS encryption, `ssl` also accepted
        $mail->Port = getenv("SMTP_PORT");                                    // TCP port to connect to

        $mail->setFrom('info@mycard.moe', 'MyCard');
        $mail->addAddress($order['user_id']);     // Add a recipient
        $mail->isHTML(true);                                  // Set email format to HTML

        $mail->Subject = '萌卡订单收据';

        $formatter = new NumberFormatter("zh_CN", NumberFormatter::CURRENCY);
        $order['total'] = $formatter->formatCurrency(floatval($order['total']), "CNY");
        $formatter = new IntlDateFormatter('zh_CN', IntlDateFormatter::MEDIUM, IntlDateFormatter::LONG, 'Asia/Shanghai');
        $order['updated_at'] = $formatter->format(strtotime($order['updated_at']));

        $mail->Body = <<<TEMPLATE
<div style="padding-top:30px;padding-left:50px;padding-right:50px;line-height:14pt;font-size: 12px;min-width:300px;max-width:590px">
    <div style="border-bottom: 1px solid lightgray; height: 45px;">
        <a href="https://mycard.moe" style="line-height: 14pt;">
            <img style="height: 35px;" src="https://ygobbs.com/uploads/local/logo.png"> </a>
    </div>

    <h2>非常感谢。</h2>
    <p>您在萌卡上购买了 $order[app_id]</p>
    <dl>
        <dt style="float:left">订单号：</dt>
        <dd>$order[id]</dd>
        <dt style="float:left">订购时间：</dt>
        <dd>$order[updated_at]</dd>
    </dl>
    <table style="width: 100%; font-size: 12px;">
        <thead>
        <tr>
            <th style="text-align: left; border-bottom: 1px solid lightgray; margin: 5px 0;">商品</th>
            <th style="text-align: right; border-bottom: 1px solid lightgray; margin: 5px 0;">价格</th>
        </tr>
        </thead>
        <tbody>
        <tr style="border-bottom: 1px solid lightgray">
            <td style="text-align: left; border-bottom: 1px solid lightgray; margin: 5px 0;">$order[app_id]</td>
            <td style="text-align: right; border-bottom: 1px solid lightgray; margin: 5px 0;">$order[total]</td>
        </tr>
        <tr style="border-bottom: 1px solid lightgray">
            <td style="text-align: right; border-bottom: 1px solid lightgray; margin: 5px 0;" colspan="2">总计: $order[total]</td>
        </tr>
        <tr>
            <th style="text-align: left; margin: 5px 0;">付款方式</th>
            <td style="text-align: right; margin: 5px 0;">支付宝: $order[total]</td>
        </tr>
        </tbody>
    </table>

    <p>有问题吗？请联系 <a href="mailto:support@mycard.moe">support@mycard.moe</a> 。 </p>
    <p>请勿回复此邮件。<br> © 2017 MyCard | 保留所有权利。 </p>
</div>
TEMPLATE;

        $mail->AltBody = <<<TEMPLATE
[image: MyCard] <https://ygobbs.com/uploads/local/logo.png>
非常感谢。
您在萌卡上购买了 $order[app_id]。

*订单号：* $order[id]
*订购时间：* $order[updated_at]
商品 价格
$order[app_id] $order[total]
总计: $order[total]
付款方式：
支付宝: $order[total]
有问题吗？请联系 support@mycard.moe 。
请勿回复此邮件。
© 2017 MyCard | 保留所有权利。
TEMPLATE;

        $mail->CharSet = "utf-8";

        if (!$mail->send()) {
            error_log($mail->ErrorInfo);
        }
    }

    public static function finish_view($id)
    {
        $query = self::$db->prepare("SELECT *, total::NUMERIC AS total, orders.id AS id FROM orders LEFT JOIN keys ON orders.id = keys.order_id WHERE orders.id = :id");
        $query->execute(['id' => $id]);
        $order = $query->fetch(PDO::FETCH_ASSOC);

        

        $formatter = new NumberFormatter("zh_CN", NumberFormatter::CURRENCY);
        $order['total'] = $formatter->formatCurrency(floatval($order['total']), "CNY");
        $formatter = new IntlDateFormatter('zh_CN', IntlDateFormatter::MEDIUM, IntlDateFormatter::LONG, 'Asia/Shanghai');
        $order['updated_at'] = $formatter->format(strtotime($order['updated_at']));

        return <<<TEMPLATE
        <div style="padding-top:30px;padding-left:50px;padding-right:50px;line-height:14pt;font-size: 12px;min-width:300px;max-width:590px">
    <div style="border-bottom: 1px solid lightgray; height: 45px;">
        <a href="https://mycard.moe" style="line-height: 14pt;">
            <img style="height: 35px;" src="https://ygobbs.com/uploads/local/logo.png"> </a>
    </div>

    <h2>非常感谢。</h2>
    <p>您在萌卡上购买了 $order[app_id]</p>
    <dl>
        <dt style="float:left">订单号：</dt>
        <dd>$order[id]</dd>
        <dt style="float:left">订购时间：</dt>
        <dd>$order[updated_at]</dd>
    </dl>
    <table style="width: 100%; font-size: 12px;">
        <thead>
        <tr>
            <th style="text-align: left; border-bottom: 1px solid lightgray; margin: 5px 0;">商品</th>
            <th style="text-align: right; border-bottom: 1px solid lightgray; margin: 5px 0;">价格</th>
        </tr>
        </thead>
        <tbody>
        <tr style="border-bottom: 1px solid lightgray">
            <td style="text-align: left; border-bottom: 1px solid lightgray; margin: 5px 0;">$order[app_id]</td>
            <td style="text-align: right; border-bottom: 1px solid lightgray; margin: 5px 0;">$order[total]</td>
        </tr>
        <tr style="border-bottom: 1px solid lightgray">
            <td style="text-align: right; border-bottom: 1px solid lightgray; margin: 5px 0;" colspan="2">总计: $order[total]</td>
        </tr>
        <tr>
            <th style="text-align: left; margin: 5px 0;">付款方式</th>
            <td style="text-align: right; margin: 5px 0;">$order[payment]: $order[total]</td>
        </tr>
        </tbody>
    </table>

    <p>有问题吗？请联系 <a href="mailto:support@mycard.moe">support@mycard.moe</a> 。 </p>
    <p>© 2017 MyCard | 保留所有权利。 </p>
</div>
TEMPLATE;

    }

    public static function keys($user_id)
    {
        $query = self::$db->prepare("SELECT * FROM keys WHERE user_id = :user_id");
        $query->execute(['user_id' => $user_id]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function keys_has($user_id, $app_id)
    {
        $query = self::$db->prepare("SELECT id FROM keys WHERE user_id = :user_id AND app_id = :app_id LIMIT 1");
        $query->execute(['user_id' => $user_id, 'app_id' => $app_id]);
        return $query->rowCount() > 0;
    }
}

Order::$db = new PDO(getenv("DATABASE"));
Order::$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
