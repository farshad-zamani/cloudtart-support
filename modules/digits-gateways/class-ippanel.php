<?php

if (!defined('ABSPATH')) exit;

if (!class_exists('Digits_IranPayamak_API')) {

class Digits_IranPayamak_API
{
    private $apikey;
    private $sender;
    private $patterncode;
    private $patternvar;

    private $base_url = 'https://api.iranpayamak.com';

    public function __construct($arg, array $options = array())
    {
        $this->apikey      = isset($arg['apikey']) ? trim($arg['apikey']) : '';
        $this->sender      = isset($arg['sender']) ? trim($arg['sender']) : '';
        if ($this->sender === '') {
            $this->sender = '90008361';
        }
        $this->patterncode = isset($arg['patterncode']) ? trim($arg['patterncode']) : '';

        $patternvar_raw = isset($arg['patternvars']) ? trim($arg['patternvars']) : '';
        $patternvar_raw = preg_replace('/\s+/', '', $patternvar_raw);

        if (preg_match('/^([^:=]+)/', $patternvar_raw, $matches)) {
            $this->patternvar = trim($matches[1]);
        } else {
            $this->patternvar = $patternvar_raw;
        }
    }

    /*-----------------------------------------------------
        استانداردسازی شماره موبایل برای API جدید
    -----------------------------------------------------*/
    private function normalize_mobile($mobile)
    {
        $mobile = trim($mobile);
        $mobile = preg_replace('/[^0-9+]/', '', $mobile);

        if (preg_match('/^09[0-9]{9}$/', $mobile)) {
            return $mobile;
        }

        if (preg_match('/^989([0-9]{9})$/', $mobile, $m)) {
            return '09' . $m[1];
        }

        if (preg_match('/^\+989([0-9]{9})$/', $mobile, $m)) {
            return '09' . $m[1];
        }

        if (preg_match('/^9([0-9]{9})$/', $mobile, $m)) {
            return '09' . $m[1];
        }

        return $mobile;
    }


    /*-----------------------------------------------------
        ارسال پیامک ساده
    -----------------------------------------------------*/
    public function send(array $sms)
    {
        if (!is_callable('curl_init')) {
            return json_encode([
                'meta' => [
                    'status'  => false,
                    'message' => 'CURL is not available'
                ]
            ]);
        }

        $sms['to'] = $this->normalize_mobile($sms['to']);

        $params = [
            "recipient"     => $sms['to'],
            "message"       => $sms['message'],
            "line_number"   => $this->sender,
            "number_format" => "english"
        ];

        return $this->execute_curl('/ws/v1/sms/simple', $params);
    }


    /*-----------------------------------------------------
        ارسال پیامک الگو (Pattern)
    -----------------------------------------------------*/
    public function sendPattern(array $sms)
    {
        if (empty($this->patterncode)) {
            return json_encode([
                'meta' => [
                    'status'  => false,
                    'message' => 'Pattern code not configured'
                ]
            ]);
        }

        $sms['to'] = $this->normalize_mobile($sms['to']);

        $attributes = [
            $this->patternvar => $sms['otp']
        ];

        $params = [
            "code"           => $this->patterncode,
            "attributes"     => $attributes,
            "recipient"      => $sms['to'],
            "line_number"    => $this->sender,
            "number_format"  => "english"
        ];

        return $this->execute_curl('/ws/v1/sms/pattern', $params);
    }


    /*-----------------------------------------------------
        اجرای CURL و تبدیل پاسخ برای Digits Loader
    -----------------------------------------------------*/
    private function execute_curl($endpoint, $params)
    {
        if (empty($this->apikey)) {
            return json_encode([
                'meta' => [
                    'status'  => false,
                    'message' => 'API Key not set'
                ]
            ]);
        }

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL            => $this->base_url . $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => "POST",
            CURLOPT_POSTFIELDS     => json_encode($params),
            CURLOPT_HTTPHEADER     => [
                "Accept: application/json",
                "Content-Type: application/json",
                "Api-Key: " . $this->apikey
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);

        $response = curl_exec($curl);
        $error    = curl_error($curl);

        if (PHP_VERSION_ID < 80000) {
            curl_close($curl); // از PHP 8 خودکار آزاد می‌شود؛ curl_close در PHP 8.5 منسوخ است
        }

        /*-------------------------------------------------
            خطای CURL
        -------------------------------------------------*/
        if (!empty($error)) {
            return json_encode([
                'meta' => [
                    'status'  => false,
                    'message' => $error
                ]
            ]);
        }

        $result = json_decode($response, true);

        if (!is_array($result)) {
            return json_encode([
                'meta' => [
                    'status'  => false,
                    'message' => 'Invalid response from gateway'
                ]
            ]);
        }

        /*-------------------------------------------------
            موفقیت در API جدید FarazSMS = status = "success"
        -------------------------------------------------*/
        if (isset($result['status']) && $result['status'] === 'success') {

            // استخراج ID پیام
            $msg_id = null;

            if (isset($result['data']['id'])) {
                $msg_id = $result['data']['id'];
            } elseif (isset($result['data']['data']['id'])) {
                $msg_id = $result['data']['data']['id'];
            }

            // فرمت مورد انتظار Loader (خط‌های 217–227 فایل loader)
            return json_encode([
                'meta' => [
                    'status'  => true,
                    'message' => 'Message sent successfully'
                ],
                'data' => [
                    'message_outbox_ids' => $msg_id ? [ $msg_id ] : []
                ]
            ]);
        }

        /*-------------------------------------------------
            سایر خطاهای API جدید
        -------------------------------------------------*/
        $error_message = 'Unknown error';

        if (isset($result['message']) && $result['message']) {
            if (is_string($result['message'])) {
                $error_message = $result['message'];
            } elseif (isset($result['message']['recipient'][0])) {
                $error_message = $result['message']['recipient'][0];
            }
        }

        return json_encode([
            'meta' => [
                'status'  => false,
                'message' => $error_message
            ]
        ]);
    }
}

if (!class_exists('Digits_Ippanel_API')) {
    class_alias('Digits_IranPayamak_API', 'Digits_Ippanel_API');
}

}
