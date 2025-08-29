<?php
/*
Plugin Name: WooCommerce iPay Tokly Gateway
Description: Інтеграція WooCommerce з платіжним шлюзом iPay Tokly (PaymentCreate)
Version: 0.9
Author: Roman Mokrii
*/

if (!defined('ABSPATH')) exit;

define('IPAY_API_VERSION', '1.40');
define('IPAY_SANDBOX_CARDS', [
    'success' => ['3333333333333331', '3333333333332705', '3333333333333000'],
    'success_under_100' => ['3333333333333430', '3333333333331509'],
    'fail' => ['3333333333333349', '3333333333336409'],
    'preauth' => ['3333333333333356']
]);

add_action('plugins_loaded', 'wc_ipay_tokly_init', 11);

function wc_ipay_tokly_init() {
    if (!class_exists('WC_Payment_Gateway')) return;

    class WC_Gateway_iPay_Tokly extends WC_Payment_Gateway {

        public function __construct() {
            $this->id = 'ipay_tokly';
            $this->method_title = 'iPay Tokly';
            $this->has_fields = false;

            $this->init_form_fields();
            $this->init_settings();
            $this->title = $this->get_option('title', 'iPay Tokly');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        }

        public function init_form_fields() {
            $sandbox_info = 'Test cards:<br>' .
                          'Success: ' . IPAY_SANDBOX_CARDS['success'][0] . '<br>' .
                          'Success under 100 UAH: ' . IPAY_SANDBOX_CARDS['success_under_100'][0] . '<br>' .
                          'Fail: ' . IPAY_SANDBOX_CARDS['fail'][0] . '<br>' .
                          'Preauth: ' . IPAY_SANDBOX_CARDS['preauth'][0];

            $this->form_fields = array(
                'enabled' => array(
                    'title' => 'Enable/Disable',
                    'type' => 'checkbox',
                    'label' => 'Enable iPay Tokly',
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => 'Title',
                    'type' => 'text',
                    'default' => 'iPay Tokly',
                ),
                'mch_id' => array(
                    'title' => 'Merchant ID',
                    'type' => 'text',
                ),
                'sign_key' => array(
                    'title' => 'Sign Key',
                    'type' => 'text',
                ),
                'return_url' => array(
                    'title' => 'Return URL',
                    'type' => 'text',
                    'description' => 'URL куди повертає клієнта після оплати (публічний HTTPS)',
                ),
                'url_good' => array(
                    'title' => 'Success URL',
                    'type' => 'text',
                    'description' => 'URL для успішної оплати (якщо пусто — використається Return URL)',
                    'default' => '',
                ),
                'url_bad' => array(
                    'title' => 'Fail URL',
                    'type' => 'text',
                    'description' => 'URL для неуспішної оплати (якщо пусто — використається Return URL)',
                    'default' => '',
                ),
                'lang' => array(
                    'title' => 'Language',
                    'type' => 'text',
                    'default' => 'ua',
                    'description' => 'Мова сторінок Tokly (ua, ru, en)',
                ),
                'lifetime' => array(
                    'title' => 'Lifetime',
                    'type' => 'number',
                    'default' => '3600',
                    'description' => 'Час життя платежу. Можна в секундах (наприклад 3600) або в годинах (1). Код автоматично перетворює великі значення у години.',
                ),
                'sandbox' => array(
                    'title' => 'Sandbox Mode',
                    'type' => 'checkbox',
                    'label' => 'Enable Sandbox Mode',
                    'default' => 'no',
                    'description' => $sandbox_info
                ),
                'debug' => array(
                    'title' => 'Debug Mode',
                    'type' => 'checkbox',
                    'label' => 'Enable debug output',
                    'default' => 'no',
                ),
            );
        }

        public function process_payment($order_id) {
            $order = wc_get_order($order_id);
            $debug_mode = 'yes' === $this->get_option('debug');
            $sandbox_mode = 'yes' === $this->get_option('sandbox');

            // перевірка налаштувань
            $mch = trim($this->get_option('mch_id'));
            $sign_key = trim($this->get_option('sign_key'));
            $return_url = trim($this->get_option('return_url'));
            if (empty($mch) || empty($sign_key) || empty($return_url)) {
                wc_add_notice('iPay: налаштування merchant (mch_id, sign_key, return_url) не заповнені', 'error');
                return;
            }

            // генерація salt та sign
            $salt = sha1(microtime(true));
            $sign = hash_hmac('sha512', $salt, $sign_key);

            // сума в копійках (ціле число)
            $amount_decimal = (float) $order->get_total();
            $amount_kop = intval(round($amount_decimal * 100, 0));

            // конвертація lifetime: якщо користувач ввів велике число (секунди), конвертуємо в години
            $lifetime_setting = $this->get_option('lifetime');
            $lifetime_val = floatval($lifetime_setting);
            if ($lifetime_val <= 0) $lifetime_val = 3600; // значення за замовчуванням в секундах
            // якщо схоже на секунди (>=3600) конвертуємо в години
            if ($lifetime_val >= 3600) {
                $lifetime_hours = $lifetime_val / 3600.0;
            } else {
                // вважаємо що користувач вже вказав години (наприклад, 1)
                $lifetime_hours = $lifetime_val;
            }
            // форматуємо години з розумною точністю
            $lifetime_hours_str = rtrim(rtrim(number_format($lifetime_hours, 4, '.', ''), '0'), '.');

            $lang = $this->get_option('lang') ?: 'ua';
            $url_good = $this->get_option('url_good') ?: $return_url;
            $url_bad = $this->get_option('url_bad') ?: $return_url;

            $desc = 'Order #' . $order->get_id();

            // Створення XML з кореневим елементом <payment> (формат для PaymentCreate)
            $xml  = '<payment>';
            $xml .= '<auth>';
            $xml .= '<mch_id>' . htmlspecialchars($mch) . '</mch_id>';
            $xml .= '<salt>' . htmlspecialchars($salt) . '</salt>';
            $xml .= '<sign>' . htmlspecialchars($sign) . '</sign>';
            $xml .= '</auth>';

            // URL адреси
            $xml .= '<urls>';
            $xml .= '<good>' . htmlspecialchars($url_good) . '</good>';
            $xml .= '<bad>'  . htmlspecialchars($url_bad)  . '</bad>';
            $xml .= '</urls>';

            // середовище sandbox
            if ($sandbox_mode) {
                $xml .= '<environment>sandbox</environment>';
            }

            // транзакції -> одна транзакція -> сума в копійках
            $xml .= '<transactions>';
            $xml .= '<transaction>';
            $xml .= '<amount>' . $amount_kop . '</amount>';
            $xml .= '<currency>' . htmlspecialchars(get_woocommerce_currency()) . '</currency>';
            $xml .= '<desc>' . htmlspecialchars($desc) . '</desc>';
            // додаткова інформація — ID замовлення для кореляції в callback
            $info = wp_json_encode(['order_id' => (int)$order->get_id()]);
            $xml .= '<info>' . htmlspecialchars($info) . '</info>';
            $xml .= '</transaction>';
            $xml .= '</transactions>';

            // час життя та мова
            $xml .= '<lifetime>' . $lifetime_hours_str . '</lifetime>';
            $xml .= '<lang>' . htmlspecialchars($lang) . '</lang>';

            // return_url
            $xml .= '<return_url>' . htmlspecialchars($return_url) . '</return_url>';

            $xml .= '</payment>';

            if ($debug_mode) {
                wc_add_notice('iPay Request (payment XML): <pre>' . htmlspecialchars($xml) . '</pre>', 'notice');
                if ($sandbox_mode) {
                    wc_add_notice('Sandbox Mode Active - use test cards: ' . implode(', ', IPAY_SANDBOX_CARDS['success']), 'notice');
                }
            }

            $response = wp_remote_post('https://tokly.ipay.ua/api302', array(
                'method' => 'POST',
                'body' => array('data' => $xml),
                'headers' => array(
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Api-Version' => IPAY_API_VERSION
                ),
                'timeout' => 30,
            ));

            if (is_wp_error($response)) {
                wc_add_notice('iPay API Error: ' . $response->get_error_message(), 'error');
                return;
            }

            $body = wp_remote_retrieve_body($response);

            if ($debug_mode) {
                wc_add_notice('iPay Response raw: <pre>' . htmlspecialchars($body) . '</pre>', 'notice');
            }

            // Перевірка простого рядка помилки типу error: 155
            $trimmed = trim($body);
            if (stripos($trimmed, 'error:') === 0) {
                wc_add_notice('iPay Помилка: ' . $trimmed, 'error');
                return;
            }

            libxml_use_internal_errors(true);
            $xml_resp = @simplexml_load_string($body);
            if (!$xml_resp) {
                if ($debug_mode) {
                    $errs = libxml_get_errors();
                    $msg = '';
                    foreach ($errs as $e) {
                        $msg .= trim($e->message) . ' at line ' . $e->line . "\n";
                    }
                    libxml_clear_errors();
                    wc_add_notice('iPay Error: invalid XML response. Parser errors: <pre>' . htmlspecialchars($msg) . '</pre>', 'error');
                } else {
                    wc_add_notice('iPay Error: Invalid XML response', 'error');
                }
                return;
            }

            // Спроба знайти URL оплати в різних можливих місцях відповіді
            $payment_url = '';
            if (isset($xml_resp->url)) {
                $payment_url = (string)$xml_resp->url;
            } elseif (isset($xml_resp->payment) && isset($xml_resp->payment->url)) {
                $payment_url = (string)$xml_resp->payment->url;
            } elseif (isset($xml_resp->response) && isset($xml_resp->response->url)) {
                $payment_url = (string)$xml_resp->response->url;
            } elseif (isset($xml_resp->body) && isset($xml_resp->body->payment_url)) {
                $payment_url = (string)$xml_resp->body->payment_url;
            }

            if (empty($payment_url)) {
                // витягуємо помилку якщо є
                $err = '';
                if (isset($xml_resp->error)) $err = (string)$xml_resp->error;
                if (!$err) {
                    foreach ($xml_resp->children() as $c) {
                        if ($c->getName() === 'error') { $err = (string)$c; break; }
                    }
                }
                $msg = $err ?: 'No payment URL received from iPay';
                wc_add_notice('iPay Error: ' . $msg, 'error');
                return;
            }

            // успіх — позначаємо замовлення та редіректимо
            $order->update_status('on-hold', 'Очікування оплати через iPay Tokly');
            return array(
                'result' => 'success',
                'redirect' => $payment_url,
            );
        }
    }

    function add_ipay_tokly_gateway($methods) {
        $methods[] = 'WC_Gateway_iPay_Tokly';
        return $methods;
    }
    add_filter('woocommerce_payment_gateways', 'add_ipay_tokly_gateway');
}
