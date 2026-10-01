<?php

namespace PW\PWSMS\Gateways;

if (!defined('ABSPATH')) {
    exit('Direct access is not allowed.');
}

class CloudTartIranPayamakFarazSMS extends Gateway
{
    private $base_url = 'https://api.iranpayamak.com';

    public static function id(): string
    {
        return 'cloudtart_iranpayamak_farazsms';
    }

    public static function name(): string
    {
        return __('IranPayamak - FarazSMS', 'cloudtart-support');
    }

    public function send()
    {
        $this->failed_numbers = [];

        $api_key = trim((string) PWSMS()->get_option('cloudtart_iranpayamak_apikey'));
        $sender = trim((string) $this->senderNumber);
        if ($sender === '') {
            $sender = '90008361';
        }
        $message_content = trim((string) $this->message);

        // سازگاری با تنظیمات قدیمی‌تر: در صورت خالی بودن فیلد API، از username استفاده کن.
        if (empty($api_key)) {
            $api_key = trim((string) $this->username);
        }

        if (empty($api_key)) {
            return __('The IranPayamak API key is not set.', 'cloudtart-support');
        }

        if (empty($sender)) {
            return __('The SMS sender line number is not set.', 'cloudtart-support');
        }

        $pattern_payload = $this->extract_pattern_payload($message_content);
        if (is_array($pattern_payload) && isset($pattern_payload['error'])) {
            return (string) $pattern_payload['error'];
        }

        foreach ((array) $this->mobile as $recipient) {
            $recipient = $this->normalize_mobile($recipient);
            if ($recipient === '') {
                $this->failed_numbers['(empty)'] = __('The recipient number is invalid.', 'cloudtart-support');
                continue;
            }

            if ($pattern_payload !== false) {
                $result = $this->send_pattern($api_key, $sender, $recipient, $pattern_payload['code'], $pattern_payload['attributes']);
            } else {
                $result = $this->send_simple($api_key, $sender, $recipient, $message_content);
            }

            if ($result !== true) {
                $this->failed_numbers[$recipient] = $result;
            }
        }

        if (!empty($this->failed_numbers)) {
            $grouped = [];
            foreach ($this->failed_numbers as $number => $message) {
                if (!isset($grouped[$message])) {
                    $grouped[$message] = [];
                }
                $grouped[$message][] = $number;
            }

            return implode(', ', array_map(
                function ($message, $numbers) {
                    return implode(',', $numbers) . ': ' . $message;
                },
                array_keys($grouped),
                $grouped
            ));
        }

        return true;
    }

    private function send_simple($api_key, $sender, $recipient, $message)
    {
        $params = [
            'recipient'     => $recipient,
            'message'       => $message,
            'line_number'   => $sender,
            'number_format' => 'english',
        ];

        return $this->request('/ws/v1/sms/simple', $api_key, $params);
    }

    private function send_pattern($api_key, $sender, $recipient, $pattern_code, array $attributes)
    {
        $params = [
            'code'          => $pattern_code,
            'attributes'    => $attributes,
            'recipient'     => $recipient,
            'line_number'   => $sender,
            'number_format' => 'english',
        ];

        return $this->request('/ws/v1/sms/pattern', $api_key, $params);
    }

    private function request($endpoint, $api_key, array $params)
    {
        $response = wp_remote_post($this->base_url . $endpoint, [
            'timeout' => 30,
            'headers' => [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
                'Api-Key'      => $api_key,
            ],
            'body'    => wp_json_encode($params),
        ]);

        if (is_wp_error($response)) {
            return $response->get_error_message();
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if (empty($status_code) || (int) $status_code < 200 || (int) $status_code >= 300) {
            return !empty($body) ? $body : __('Did not receive a valid response from the IranPayamak web service.', 'cloudtart-support');
        }

        $result = json_decode($body, true);
        if (!is_array($result)) {
            return __('The IranPayamak response format is invalid.', 'cloudtart-support');
        }

        if (isset($result['status']) && $result['status'] === 'success') {
            return true;
        }

        if (isset($result['message']) && is_string($result['message']) && $result['message'] !== '') {
            return $result['message'];
        }

        if (isset($result['message']['recipient'][0])) {
            return (string) $result['message']['recipient'][0];
        }

        return __('Unknown error while sending the SMS via IranPayamak.', 'cloudtart-support');
    }

    private function normalize_mobile($mobile)
    {
        $mobile = trim((string) $mobile);
        $digits = preg_replace('/\D+/', '', $mobile);

        if ($digits === '') {
            return '';
        }

        if (preg_match('/^09[0-9]{9}$/', $digits)) {
            return $digits;
        }

        if (preg_match('/^00989([0-9]{9})$/', $digits, $m)) {
            return '09' . $m[1];
        }

        if (preg_match('/^989([0-9]{9})$/', $digits, $m)) {
            return '09' . $m[1];
        }

        if (preg_match('/^9([0-9]{9})$/', $digits, $m)) {
            return '09' . $m[1];
        }

        // برای شماره‌های غیرایرانی، حداقل علامت + و کاراکترهای غیرعددی حذف می‌شود.
        return $digits;
    }

    private function extract_pattern_payload($message_content)
    {
        $normalized = trim((string) $message_content);
        $normalized = str_ireplace('pcode', 'patterncode', $normalized);
        if (stripos($normalized, 'patterncode') !== 0) {
            return false;
        }

        $normalized = str_replace(["\r\n", "\n"], ';', $normalized);
        $parts = array_values(array_filter(array_map('trim', explode(';', $normalized)), 'strlen'));
        if (empty($parts)) {
            return ['error' => __('The pattern message structure is invalid.', 'cloudtart-support')];
        }

        $first = explode(':', $parts[0], 2);
        if (count($first) !== 2) {
            return ['error' => __('The first pattern line must follow the format `pcode:YOUR_PATTERN_CODE`.', 'cloudtart-support')];
        }

        $pattern_code = trim($first[1]);
        if ($pattern_code === '') {
            return ['error' => __('No pattern code was provided on the first line.', 'cloudtart-support')];
        }

        $attributes = [];
        for ($i = 1; $i < count($parts); $i++) {
            $line = trim($parts[$i]);
            $line = str_replace(['=>', '='], ':', $line);
            $line = str_replace(['：', '﹕'], ':', $line);

            $pair = explode(':', $line, 2);
            if (count($pair) !== 2) {
                continue;
            }

            $key = trim($pair[0]);
            $val = trim($pair[1]);
            if ($key !== '') {
                $attributes[$key] = $val;
            }
        }

        if (empty($attributes)) {
            return ['error' => __('At least one pattern variable must be provided in `key:value` format.', 'cloudtart-support')];
        }

        return [
            'code' => $pattern_code,
            'attributes' => $attributes,
        ];
    }
}
