<?php

/**
 * Simple Paytrail library
 * 
 * https://github.com/Ciantic/php-paytrail-without-sdk
 * 
 * @license MIT
 * @author Jari Pennanen
 */

class PaytrailStampException extends PaytrailException
{
}
class PaytrailException extends Exception
{
    protected $data;

    public function getData()
    {
        return $this->data;
    }

    public function __construct(string $message, int $code = 0, $data = null)
    {
        parent::__construct($message, $code);
        $this->data = $data;
    }
}
/**
 * Generate HMAC signature
 * 
 * @param string $secret merchant secret, e.g. SAIPPUAKAUPPIAS
 * @param array $params usually $_GET 
 * @param string $body JSON body
 * @return string|false 
 */
function paytrail_hmac(string $secret, array $params, string $body = '')
{
    $keys = array_filter(array_keys($params), function ($key) {
        return preg_match('/^checkout-/', $key);
    });
    sort($keys, SORT_STRING);
    $rows = array_map(
        function ($key) use ($params) {
            return $key . ":" . $params[$key];
        },
        $keys
    );
    $str = join("\n", [...$rows, $body]);
    return hash_hmac('sha256', $str, $secret);
}

/**
 * Sanitizes the payload given to `paytrail_pay`
 * 
 * Not very tested 
 * 
 * @param object $pdata Unsanitized value
 * @return void
 */
function paytrail_sanitize_pay(object &$pdata)
{
    if (empty($pdata->stamp)) {
        $pdata->stamp = uniqid("order");
    }
    if (empty($pdata->reference)) {
        $pdata->reference = $pdata->stamp;
    }
    $pdata->amount = intval($pdata->amount);

    // Addresses
    if (empty($pdata->invoicingAddress->streetAddress)) {
        unset($pdata->invoicingAddress);
    }
    if (empty($pdata->deliveryAddress->streetAddress)) {
        unset($pdata->deliveryAddress);
    }

    // Product items
    if (!empty($pdata->items)) {
        foreach (array_reverse(array_keys($pdata->items)) as $n) {
            if (empty($pdata->items[$n]->productCode)) {
                unset($pdata->items[$n]);
            } else {
                $pdata->items[$n]->unitPrice = intval($pdata->items[$n]->unitPrice);
                $pdata->items[$n]->units = (int) $pdata->items[$n]->units;
                $pdata->items[$n]->vatPercentage = (int) $pdata->items[$n]->vatPercentage;
                foreach ($pdata->items[$n] as $key => $value) {
                    if (empty($value)) {
                        unset($pdata->items[$n]->$key);
                    }
                }
            }
        }
    }

    // Remove empty properties
    foreach ($pdata as $key => $value) {
        if (is_object($value)) {
            foreach ($value as $k => $v) {
                if (empty($v)) {
                    unset($pdata->$key->$k);
                }
            }
        }
        if (empty($value)) {
            unset($pdata->$key);
        }
    }
}

/**
 * Initiate the payment on paytrail, returns payment url 
 * 
 * @param object $payload See https://docs.paytrail.com/#/?id=create-payment
 * @param string $merchantId Numeric id as string e.g. 375917
 * @param string $secretKey Secret, e.g. SAIPPUAKAUPPIAS
 * @return object Returns the paytrail object for payment
 * @throws Exception 
 */
function paytrail_pay(object $payload, string $merchantId, string $secretKey)
{
    $ch = curl_init();
    $body = json_encode($payload);
    $headers = array(
        // 'checkout-transaction-id' => '12345', // for existing transactions only
        'checkout-account' =>  $merchantId,
        'checkout-algorithm' => 'sha256',
        'checkout-method' => 'POST',
        'checkout-nonce' => uniqid(),
        'checkout-timestamp' => date(DATE_ISO8601),
        'content-type' => 'application/json; charset=utf-8',
        'platform-name' =>  'dingle dong',
    );
    $headers["signature"] = paytrail_hmac($secretKey, $headers, $body);

    curl_setopt($ch, CURLOPT_URL, "https://services.paytrail.com/payments");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_map(
        function ($key) use ($headers) {
            return $key . ":" . $headers[$key];
        },
        array_keys($headers)
    ));
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $server_output = curl_exec($ch);
    curl_close($ch);

    $json = json_decode($server_output);
    if ($json && isset($json->status) && $json->status === "error") {
        if (
            isset($json->meta) &&
            isset($json->meta[0]) &&
            $json->meta[0] === "instance.stamp or instance.item.stamp already exists for merchant."
        ) {
            throw new PaytrailStampException("Stamp already exists", 0, $json);
        }
        throw new PaytrailException($json->message, 0, $json);
    }
    return $json;
}
