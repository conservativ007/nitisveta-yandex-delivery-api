<?php
/**
 * Plugin Name: Nitisveta Yandex Pickup Delivery
 * Description: Adds Yandex pickup point selection to WooCommerce checkout.
 * Version: 0.1.0
 * Author: Nitisveta
 */

defined('ABSPATH') || exit;

final class Nitisveta_Yandex_Delivery_Api
{
    private const VERSION = '0.1.0';
    private const SHIPPING_METHOD_ID = 'flat_rate:10';
    private const PICKUP_RATE_ID = 'nitisveta_yandex_pickup';
    private const META_PREFIX = '_nitisveta_yandex_delivery_';
    private const API_BASE_PROD = 'https://b2b-authproxy.taxi.yandex.net/api/b2b/platform';
    private const API_BASE_TEST = 'https://b2b.taxi.tst.yandex.net/api/b2b/platform';
    private const SOURCE_PICKUP_ADDRESS = 'Дальневосточный просп., 12, корп. 2, Санкт-Петербург';
    private const SESSION_SELECTED_POINT = 'nitisveta_yandex_delivery_selected_point';
    private const SESSION_CALCULATED_PRICE = 'nitisveta_yandex_delivery_calculated_price';
    private const LOG_SOURCE = 'nitisveta-yandex-delivery';

    public static function init(): void
    {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_checkout_assets'], 30);
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
        add_filter('woocommerce_package_rates', [__CLASS__, 'add_pickup_shipping_rate'], 20, 2);
        add_filter('woocommerce_package_rates', [__CLASS__, 'hide_shipping_rates_until_city_selected'], 9999, 2);
        add_filter('woocommerce_cart_shipping_method_full_label', [__CLASS__, 'format_pickup_shipping_label'], 20, 2);
        add_action('woocommerce_checkout_after_customer_details', [__CLASS__, 'render_pickup_mount']);
        add_action('woocommerce_after_checkout_validation', [__CLASS__, 'validate_checkout'], 10, 2);
        add_action('woocommerce_checkout_create_order', [__CLASS__, 'save_order_meta'], 10, 2);
        add_action('woocommerce_admin_order_data_after_shipping_address', [__CLASS__, 'render_admin_order_meta']);
        add_action('woocommerce_email_after_order_table', [__CLASS__, 'render_email_order_meta'], 20, 4);
    }

    public static function enqueue_checkout_assets(): void
    {
        if (!function_exists('is_checkout') || !is_checkout() || is_order_received_page()) {
            return;
        }

        $base_url = self::plugin_url();
        $base_dir = self::plugin_dir();

        wp_enqueue_style(
            'nitisveta-yandex-pickup-checkout',
            $base_url . 'assets/css/checkout-pickup-map.css',
            [],
            self::asset_version($base_dir . 'assets/css/checkout-pickup-map.css')
        );

        wp_enqueue_script(
            'nitisveta-yandex-pickup-checkout',
            $base_url . 'assets/js/checkout-pickup-map.js',
            ['jquery'],
            self::asset_version($base_dir . 'assets/js/checkout-pickup-map.js'),
            true
        );

        wp_localize_script('nitisveta-yandex-pickup-checkout', 'nitisvetaYandexDelivery', [
            'restUrl' => esc_url_raw(rest_url('nitisveta-yandex-delivery/v1/pickup-points')),
            'selectedPointRestUrl' => esc_url_raw(rest_url('nitisveta-yandex-delivery/v1/selected-pickup-point')),
            'restNonce' => wp_create_nonce('wp_rest'),
            'shippingMethodIds' => [self::PICKUP_RATE_ID, self::SHIPPING_METHOD_ID],
            'shippingMethodId' => self::PICKUP_RATE_ID,
            'mapApiKey' => self::get_map_api_key(),
            'labels' => [
                'title' => 'Пункт выдачи Яндекс Доставки',
                'choose' => 'Выбрать пункт',
                'selected' => 'Выбран пункт выдачи',
                'change' => 'Изменить',
                'loading' => 'Загружаем пункты выдачи...',
                'empty' => 'Пункты выдачи для этого города пока не найдены',
                'error' => 'Не удалось загрузить пункты выдачи',
                'required' => 'Выберите пункт выдачи Яндекс Доставки',
                'search' => 'Найти пункт по адресу',
            ],
        ]);
    }

    public static function register_rest_routes(): void
    {
        register_rest_route('nitisveta-yandex-delivery/v1', '/pickup-points', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [__CLASS__, 'get_pickup_points'],
            'permission_callback' => '__return_true',
            'args' => [
                'city' => [
                    'sanitize_callback' => 'sanitize_text_field',
                    'default' => '',
                ],
                'country' => [
                    'sanitize_callback' => 'sanitize_text_field',
                    'default' => 'RU',
                ],
                'bounds' => [
                    'sanitize_callback' => 'sanitize_text_field',
                    'default' => '',
                ],
            ],
        ]);

        register_rest_route('nitisveta-yandex-delivery/v1', '/selected-pickup-point', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [__CLASS__, 'set_selected_pickup_point'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function get_pickup_points(WP_REST_Request $request): WP_REST_Response
    {
        $city = trim((string) $request->get_param('city'));
        $country = trim((string) $request->get_param('country'));
        $bounds = trim((string) $request->get_param('bounds'));

        if ($city === '' || self::lower($city) === 'город') {
            return rest_ensure_response([
                'points' => [],
                'count' => 0,
                'message' => 'city_required',
            ]);
        }

        $points = self::load_local_pickup_points($city, $country);

        if (!$points) {
            $remote = self::load_remote_pickup_points($city, $country, $bounds);
            $points = is_wp_error($remote) ? [] : $remote;
        }

        return rest_ensure_response([
            'points' => array_values($points),
            'count' => count($points),
        ]);
    }

    public static function render_pickup_mount(): void
    {
        if (!function_exists('is_checkout') || !is_checkout() || is_order_received_page()) {
            return;
        }

        echo '<div id="nitisveta-yandex-pickup-mount" class="nitisveta-yandex-pickup-mount"></div>';
    }

    public static function set_selected_pickup_point(WP_REST_Request $request): WP_REST_Response
    {
        $point = [
            'id' => sanitize_text_field((string) $request->get_param('id')),
            'name' => sanitize_text_field((string) $request->get_param('name')),
            'address' => sanitize_text_field((string) $request->get_param('address')),
            'lat' => sanitize_text_field((string) $request->get_param('lat')),
            'lon' => sanitize_text_field((string) $request->get_param('lon')),
        ];

        if ($point['id'] === '') {
            self::set_wc_session(self::SESSION_SELECTED_POINT, null);
            self::set_wc_session(self::SESSION_CALCULATED_PRICE, null);
            self::log('Selected pickup point cleared.');

            return rest_ensure_response(['stored' => false]);
        }

        self::set_wc_session(self::SESSION_SELECTED_POINT, $point);
        self::set_wc_session(self::SESSION_CALCULATED_PRICE, null);
        self::log('Selected pickup point stored.', $point);

        return rest_ensure_response([
            'stored' => true,
            'point' => $point,
        ]);
    }

    public static function add_pickup_shipping_rate(array $rates, array $package): array
    {
        if (!self::should_add_pickup_rate($rates, $package)) {
            return $rates;
        }

        $rates[self::PICKUP_RATE_ID] = new WC_Shipping_Rate(
            self::PICKUP_RATE_ID,
            'Яндекс Доставка до ПВЗ',
            self::get_pickup_rate_cost($package),
            [],
            self::PICKUP_RATE_ID
        );

        return $rates;
    }

    public static function hide_shipping_rates_until_city_selected(array $rates, array $package): array
    {
        if (!self::is_checkout_rate_request()) {
            return $rates;
        }

        if (self::is_checkout_city_selected($package)) {
            return $rates;
        }

        self::log('Shipping rates hidden: checkout city is not selected.');

        return [];
    }

    public static function format_pickup_shipping_label(string $label, WC_Shipping_Rate $method): string
    {
        if ((string) $method->get_id() !== self::PICKUP_RATE_ID) {
            return $label;
        }

        return 'Яндекс Доставка до ПВЗ: ' . wc_price((float) $method->get_cost());
    }

    public static function validate_checkout(array $data, WP_Error $errors): void
    {
        if (!self::is_yandex_shipping_selected()) {
            return;
        }

        $point_id = isset($_POST['yandex_delivery_pickup_point_id'])
            ? sanitize_text_field(wp_unslash($_POST['yandex_delivery_pickup_point_id']))
            : '';

        if ($point_id === '') {
            $errors->add('yandex_delivery_pickup_point_required', 'Выберите пункт выдачи Яндекс Доставки.');
        }
    }

    public static function save_order_meta(WC_Order $order, array $data): void
    {
        if (!self::is_yandex_shipping_selected()) {
            return;
        }

        $fields = [
            'pickup_point_id' => 'yandex_delivery_pickup_point_id',
            'pickup_point_name' => 'yandex_delivery_pickup_point_name',
            'pickup_point_address' => 'yandex_delivery_pickup_point_address',
            'pickup_point_lat' => 'yandex_delivery_pickup_point_lat',
            'pickup_point_lon' => 'yandex_delivery_pickup_point_lon',
        ];

        foreach ($fields as $meta_key => $post_key) {
            if (!isset($_POST[$post_key])) {
                continue;
            }

            $value = sanitize_text_field(wp_unslash($_POST[$post_key]));
            if ($value !== '') {
                $order->update_meta_data(self::META_PREFIX . $meta_key, $value);
            }
        }
    }

    public static function render_admin_order_meta(WC_Order $order): void
    {
        $point = self::get_order_pickup_point($order);
        if (!$point['id']) {
            return;
        }

        echo '<div class="address">';
        echo '<p><strong>Яндекс ПВЗ:</strong><br>';
        echo esc_html($point['name'] ?: $point['id']) . '<br>';
        echo esc_html($point['address']);
        echo '</p></div>';
    }

    public static function render_email_order_meta(WC_Order $order, bool $sent_to_admin, bool $plain_text, WC_Email $email): void
    {
        $point = self::get_order_pickup_point($order);
        if (!$point['id']) {
            return;
        }

        if ($plain_text) {
            echo "\nЯндекс ПВЗ: " . ($point['name'] ?: $point['id']) . "\n" . $point['address'] . "\n";
            return;
        }

        echo '<p><strong>Яндекс ПВЗ:</strong><br>';
        echo esc_html($point['name'] ?: $point['id']) . '<br>';
        echo esc_html($point['address']) . '</p>';
    }

    private static function load_local_pickup_points(string $city, string $country): array
    {
        $file = self::plugin_dir() . 'data/pickup-points.json';
        if (!file_exists($file)) {
            return [];
        }

        $raw = file_get_contents($file);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $points = self::normalize_pickup_points($decoded);
        return self::filter_points($points, $city, $country);
    }

    private static function load_remote_pickup_points(string $city, string $country, string $bounds)
    {
        $url = self::get_pickup_points_url();
        $token = self::get_api_token();

        if ($url) {
            $response = wp_remote_get(add_query_arg(array_filter([
                'city' => $city,
                'country' => $country,
                'bounds' => $bounds,
            ]), $url), [
                'timeout' => 15,
                'headers' => array_filter([
                    'Accept' => 'application/json',
                    'Authorization' => $token ? 'Bearer ' . $token : '',
                ]),
            ]);
        } else {
            if (!$token) {
                return [];
            }

            $body = self::build_yandex_pickup_points_body($city);
            $response = self::yandex_api_request('/pickup-points/list', $body);
        }

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('yandex_delivery_bad_response', 'Yandex Delivery pickup points API returned HTTP ' . $code);
        }

        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($decoded)) {
            return new WP_Error('yandex_delivery_bad_json', 'Yandex Delivery pickup points API returned invalid JSON.');
        }

        return self::filter_points(self::normalize_pickup_points($decoded), $city, $country);
    }

    private static function build_yandex_pickup_points_body(string $city): array
    {
        $body = [
            'type' => 'pickup_point',
            'payment_methods' => ['already_paid'],
            'operator_ids' => self::get_pickup_operator_ids(),
            'is_not_branded_partner_station' => true,
        ];

        $geo_id = self::detect_geo_id($city);
        if ($geo_id !== null) {
            $body['geo_id'] = $geo_id;
        }

        return $body;
    }

    private static function detect_geo_id(string $city): ?int
    {
        if ($city === '' || self::lower($city) === 'город') {
            return null;
        }

        $cache_key = 'nitisveta_yandex_delivery_geo_' . md5(self::lower($city));
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return (int) $cached;
        }

        $response = self::yandex_api_request('/location/detect', [
            'location' => $city,
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($decoded) || empty($decoded['variants'][0]['geo_id'])) {
            return null;
        }

        $geo_id = (int) $decoded['variants'][0]['geo_id'];
        set_transient($cache_key, $geo_id, DAY_IN_SECONDS * 30);

        return $geo_id;
    }

    private static function yandex_api_request(string $path, array $body)
    {
        $token = self::get_api_token();
        if (!$token) {
            return new WP_Error('yandex_delivery_no_token', 'Yandex Delivery API token is not configured.');
        }

        return wp_remote_post(self::get_api_base_url() . $path, [
            'timeout' => 20,
            'headers' => [
                'Accept' => 'application/json',
                'Accept-Language' => 'ru',
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ]);
    }

    private static function normalize_pickup_points(array $payload): array
    {
        $items = self::extract_pickup_items($payload);

        $points = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $lat = $item['lat'] ?? $item['latitude'] ?? $item['gps_lat'] ?? $item['coordinates']['lat'] ?? null;
            $lon = $item['lon'] ?? $item['lng'] ?? $item['longitude'] ?? $item['gps_lon'] ?? $item['coordinates']['lon'] ?? $item['coordinates']['lng'] ?? null;

            if (isset($item['coordinates']) && is_array($item['coordinates']) && isset($item['coordinates'][0], $item['coordinates'][1])) {
                $lon = $item['coordinates'][0];
                $lat = $item['coordinates'][1];
            }

            if (isset($item['position']) && is_array($item['position'])) {
                $lat = $item['position']['latitude'] ?? $lat;
                $lon = $item['position']['longitude'] ?? $lon;
            }

            $address_data = $item['address'] ?? [];
            $address = $address_data ?: ($item['full_address'] ?? $item['address_full'] ?? $item['location']['address'] ?? '');
            if (is_array($address)) {
                $address = $address['full_address'] ?? $address['full'] ?? $address['fullname'] ?? $address['address'] ?? '';
            }

            $city = $item['city'] ?? $item['locality'] ?? '';
            $country = $item['country'] ?? '';

            if (is_array($address_data)) {
                $city = $city ?: ($address_data['locality'] ?? '');
                $country = $country ?: ($address_data['country'] ?? '');
            }

            $id = $item['id'] ?? $item['code'] ?? $item['pickup_point_id'] ?? $item['partner_id'] ?? '';

            if ($id === '' || $address === '' || $lat === null || $lon === null) {
                continue;
            }

            $points[] = [
                'id' => (string) $id,
                'name' => (string) ($item['name'] ?? $item['title'] ?? 'Пункт выдачи Яндекс'),
                'address' => (string) $address,
                'city' => (string) $city,
                'country' => (string) $country,
                'lat' => (float) $lat,
                'lon' => (float) $lon,
                'schedule' => self::format_schedule($item['schedule'] ?? $item['work_time'] ?? $item['working_hours'] ?? ''),
                'operator_id' => (string) ($item['operator_id'] ?? ''),
                'available_for_dropoff' => isset($item['available_for_dropoff']) ? (bool) $item['available_for_dropoff'] : null,
            ];
        }

        return $points;
    }

    private static function format_schedule($schedule): string
    {
        if (is_string($schedule)) {
            return $schedule;
        }

        if (!is_array($schedule)) {
            return '';
        }

        $restrictions = $schedule['restrictions'] ?? [];
        if (!is_array($restrictions)) {
            return '';
        }

        $items = [];
        foreach ($restrictions as $restriction) {
            if (!is_array($restriction)) {
                continue;
            }

            $from = self::format_day_time($restriction['time_from'] ?? null);
            $to = self::format_day_time($restriction['time_to'] ?? null);

            if ($from !== '' && $to !== '') {
                $items[] = $from . '-' . $to;
            }
        }

        return implode(', ', array_unique($items));
    }

    private static function format_day_time($time): string
    {
        if (!is_array($time)) {
            return '';
        }

        if (!isset($time['hours'], $time['minutes'])) {
            return '';
        }

        return sprintf('%02d:%02d', (int) $time['hours'], (int) $time['minutes']);
    }

    private static function filter_points(array $points, string $city, string $country): array
    {
        if ($city === '' && $country === '') {
            return $points;
        }

        return array_values(array_filter($points, static function (array $point) use ($city, $country): bool {
            $city_ok = $city === '' || $point['city'] === '' || self::lower($point['city']) === self::lower($city);
            $country_ok = $country === '' || $point['country'] === '' || self::country_matches($point['country'], $country);

            return $city_ok && $country_ok;
        }));
    }

    private static function extract_pickup_items(array $payload): array
    {
        $items = $payload;
        $keys = ['points', 'pickup_points', 'offices', 'items', 'data', 'result'];

        for ($i = 0; $i < 4; $i++) {
            $next = null;

            foreach ($keys as $key) {
                if (isset($items[$key]) && is_array($items[$key])) {
                    $next = $items[$key];
                    break;
                }
            }

            if ($next === null) {
                break;
            }

            $items = $next;
        }

        return $items;
    }

    private static function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
    }

    private static function country_matches(string $point_country, string $checkout_country): bool
    {
        $point_country = self::lower($point_country);
        $checkout_country = strtoupper($checkout_country);

        if ($checkout_country === 'RU') {
            return in_array($point_country, ['ru', 'russia', 'россия'], true);
        }

        return strtoupper($point_country) === $checkout_country;
    }

    private static function is_yandex_shipping_selected(): bool
    {
        $methods = isset($_POST['shipping_method']) ? (array) wp_unslash($_POST['shipping_method']) : [];

        foreach ($methods as $method) {
            if (self::is_yandex_shipping_method(sanitize_text_field($method))) {
                return true;
            }
        }

        return false;
    }

    private static function is_yandex_shipping_method(string $method_id): bool
    {
        return in_array($method_id, [self::SHIPPING_METHOD_ID, self::PICKUP_RATE_ID], true);
    }

    private static function should_add_pickup_rate(array $rates, array $package): bool
    {
        if (!function_exists('WC') || !WC()->cart || !WC()->cart->needs_shipping()) {
            return false;
        }

        if (self::is_checkout_rate_request() && !self::is_checkout_city_selected($package)) {
            return false;
        }

        foreach ($rates as $rate) {
            if (!is_object($rate) || !method_exists($rate, 'get_id')) {
                continue;
            }

            if (self::is_yandex_shipping_method((string) $rate->get_id())) {
                return false;
            }
        }

        $country = $package['destination']['country'] ?? '';
        return $country === '' || strtoupper((string) $country) === 'RU';
    }

    private static function is_checkout_rate_request(): bool
    {
        if (function_exists('is_checkout') && is_checkout()) {
            return true;
        }

        $wc_ajax = isset($_GET['wc-ajax']) ? sanitize_key(wp_unslash($_GET['wc-ajax'])) : '';
        if ($wc_ajax === 'update_order_review') {
            return true;
        }

        return isset($_POST['post_data']);
    }

    private static function is_checkout_city_selected(array $package = []): bool
    {
        $city = self::get_checkout_city_value($package);

        return $city !== '' && self::lower($city) !== 'город';
    }

    private static function get_checkout_city_value(array $package = []): string
    {
        $candidates = [];

        if (isset($_POST['billing_city'])) {
            $candidates[] = sanitize_text_field(wp_unslash($_POST['billing_city']));
        }

        if (isset($_POST['post_data'])) {
            parse_str((string) wp_unslash($_POST['post_data']), $post_data);
            if (isset($post_data['billing_city'])) {
                $candidates[] = sanitize_text_field((string) $post_data['billing_city']);
            }
        }

        if (!empty($package['destination']['city'])) {
            $candidates[] = sanitize_text_field((string) $package['destination']['city']);
        }

        if (function_exists('WC') && WC()->customer) {
            $candidates[] = sanitize_text_field((string) WC()->customer->get_billing_city());
            $candidates[] = sanitize_text_field((string) WC()->customer->get_shipping_city());
        }

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '' && self::lower($candidate) !== 'город') {
                return $candidate;
            }
        }

        return '';
    }
    private static function get_pickup_rate_cost(array $package = []): float
    {
        $calculated = self::calculate_pickup_rate_cost($package);
        if ($calculated !== null) {
            return $calculated;
        }

        if (defined('YANDEX_DELIVERY_PICKUP_COST')) {
            return (float) YANDEX_DELIVERY_PICKUP_COST;
        }

        return (float) get_option('nitisveta_yandex_delivery_pickup_cost', 0);
    }

    private static function calculate_pickup_rate_cost(array $package): ?float
    {
        $selected_point = self::get_selected_pickup_point();
        if (empty($selected_point['id'])) {
            self::log('Pricing skipped: destination pickup point is not selected.');
            return null;
        }

        $cache_key_data = [
            'source' => self::get_source_pickup_point_id(),
            'destination' => $selected_point['id'],
            'cart_hash' => function_exists('WC') && WC()->cart ? WC()->cart->get_cart_hash() : '',
        ];

        if (empty($cache_key_data['source'])) {
            self::log('Pricing skipped: source pickup point was not found.', [
                'source_address' => defined('YANDEX_DELIVERY_SOURCE_PICKUP_ADDRESS') ? (string) YANDEX_DELIVERY_SOURCE_PICKUP_ADDRESS : self::SOURCE_PICKUP_ADDRESS,
            ]);
            return null;
        }

        $session_price = self::get_wc_session(self::SESSION_CALCULATED_PRICE);
        if (
            is_array($session_price)
            && ($session_price['key'] ?? '') === md5(wp_json_encode($cache_key_data))
            && isset($session_price['cost'])
        ) {
            self::log('Pricing from WooCommerce session cache.', [
                'cost' => $session_price['cost'],
                'source' => $cache_key_data['source'],
                'destination' => $cache_key_data['destination'],
            ]);
            return (float) $session_price['cost'];
        }

        self::log('Pricing request started.', $cache_key_data);

        $pricing = self::request_pricing_calculator(
            (string) $cache_key_data['source'],
            (string) $cache_key_data['destination'],
            $package
        );

        if ($pricing === null) {
            self::log('Pricing failed, fallback cost will be used.');
            return null;
        }

        self::set_wc_session(self::SESSION_CALCULATED_PRICE, [
            'key' => md5(wp_json_encode($cache_key_data)),
            'cost' => $pricing,
        ]);

        self::log('Pricing calculated.', [
            'cost' => $pricing,
            'source' => $cache_key_data['source'],
            'destination' => $cache_key_data['destination'],
        ]);

        return $pricing;
    }

    private static function request_pricing_calculator(string $source_id, string $destination_id, array $package): ?float
    {
        $cart_data = self::get_cart_pricing_data($package);

        $body = [
            'source' => [
                'platform_station_id' => $source_id,
            ],
            'destination' => [
                'platform_station_id' => $destination_id,
            ],
            'tariff' => 'self_pickup',
            'total_weight' => $cart_data['total_weight'],
            'total_assessed_price' => $cart_data['total_assessed_price'],
            'client_price' => 0,
            'payment_method' => 'already_paid',
            'places' => $cart_data['places'],
        ];

        self::log('pricing-calculator payload.', $body);

        $response = self::yandex_api_request('/pricing-calculator', $body);

        if (is_wp_error($response)) {
            self::log('pricing-calculator WP error.', [
                'code' => $response->get_error_code(),
                'message' => $response->get_error_message(),
            ]);
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        self::log('pricing-calculator response.', [
            'http_code' => $code,
            'body' => self::truncate_log_value($response_body),
        ]);

        if ($code < 200 || $code >= 300) {
            return null;
        }

        $decoded = json_decode($response_body, true);
        if (!is_array($decoded) || empty($decoded['pricing_total'])) {
            self::log('pricing-calculator response has no pricing_total.', $decoded ?: []);
            return null;
        }

        if (preg_match('/([\d]+(?:[.,]\d+)?)/', (string) $decoded['pricing_total'], $matches)) {
            return (float) str_replace(',', '.', $matches[1]);
        }

        return null;
    }

    private static function get_cart_pricing_data(array $package): array
    {
        $weight = 0;
        $max_length = 10;
        $max_width = 10;
        $max_height = 10;
        $assessed_price = 0;

        foreach (($package['contents'] ?? []) as $item) {
            $product = $item['data'] ?? null;
            $quantity = max(1, (int) ($item['quantity'] ?? 1));

            if (!$product || !is_object($product)) {
                continue;
            }

            $weight += max(100, (int) round((float) wc_get_weight((float) $product->get_weight(), 'g') * $quantity));
            $max_length = max($max_length, (int) ceil((float) wc_get_dimension((float) $product->get_length(), 'cm')));
            $max_width = max($max_width, (int) ceil((float) wc_get_dimension((float) $product->get_width(), 'cm')));
            $max_height = max($max_height, (int) ceil((float) wc_get_dimension((float) $product->get_height(), 'cm')));
            $assessed_price += (float) $product->get_price() * $quantity;
        }

        if ($weight <= 0) {
            $weight = 100;
        }

        $data = [
            'total_weight' => $weight,
            'total_assessed_price' => (int) round($assessed_price * 100),
            'places' => [
                [
                    'physical_dims' => [
                        'weight_gross' => $weight,
                        'dx' => $max_length,
                        'dy' => $max_height,
                        'dz' => $max_width,
                    ],
                ],
            ],
        ];

        self::log('Cart pricing data prepared.', $data);

        return $data;
    }

    private static function get_source_pickup_point_id(): ?string
    {
        if (defined('YANDEX_DELIVERY_SOURCE_PICKUP_POINT_ID')) {
            self::log('Source pickup point id from constant.', [
                'source_id' => (string) YANDEX_DELIVERY_SOURCE_PICKUP_POINT_ID,
            ]);
            return (string) YANDEX_DELIVERY_SOURCE_PICKUP_POINT_ID;
        }

        $cached = get_transient('nitisveta_yandex_delivery_source_pickup_id');
        if ($cached !== false) {
            self::log('Source pickup point id from transient cache.', [
                'source_id' => (string) $cached,
            ]);
            return (string) $cached;
        }

        $source_address = defined('YANDEX_DELIVERY_SOURCE_PICKUP_ADDRESS')
            ? (string) YANDEX_DELIVERY_SOURCE_PICKUP_ADDRESS
            : self::SOURCE_PICKUP_ADDRESS;

        $geo_id = self::detect_geo_id($source_address);
        if ($geo_id === null) {
            self::log('Source geo_id was not detected.', [
                'source_address' => $source_address,
            ]);
            return null;
        }

        self::log('Source geo_id detected.', [
            'source_address' => $source_address,
            'geo_id' => $geo_id,
        ]);

        $response = self::yandex_api_request('/pickup-points/list', [
            'geo_id' => $geo_id,
            'type' => 'pickup_point',
            'operator_ids' => self::get_pickup_operator_ids(),
            'available_for_dropoff' => true,
            'is_not_branded_partner_station' => true,
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            self::log('Source pickup-points/list failed.', [
                'is_wp_error' => is_wp_error($response),
                'http_code' => is_wp_error($response) ? null : wp_remote_retrieve_response_code($response),
                'body' => is_wp_error($response) ? $response->get_error_message() : self::truncate_log_value(wp_remote_retrieve_body($response)),
            ]);
            return null;
        }

        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($decoded)) {
            return null;
        }

        $points = self::normalize_pickup_points($decoded);
        $source = self::find_best_source_pickup_point($points, $source_address);

        self::log('Source pickup candidates loaded.', [
            'count' => count($points),
            'matched_source' => $source,
        ]);

        if (!$source) {
            return null;
        }

        set_transient('nitisveta_yandex_delivery_source_pickup_id', $source['id'], DAY_IN_SECONDS * 30);

        return $source['id'];
    }

    private static function find_best_source_pickup_point(array $points, string $source_address): ?array
    {
        $needle = self::normalize_address_for_match($source_address);
        $best = null;
        $best_score = 0;

        foreach ($points as $point) {
            if (empty($point['available_for_dropoff'])) {
                continue;
            }

            $haystack = self::normalize_address_for_match($point['address'] ?? '');
            $score = 0;

            foreach (array_filter(explode(' ', $needle)) as $token) {
                $token_length = function_exists('mb_strlen') ? mb_strlen($token) : strlen($token);
                if ($token_length < 2) {
                    continue;
                }

                if (strpos($haystack, $token) !== false) {
                    $score++;
                }
            }

            if ($score > $best_score) {
                $best = $point;
                $best_score = $score;
            }
        }

        return $best_score >= 3 ? $best : null;
    }

    private static function normalize_address_for_match(string $address): string
    {
        $address = self::lower($address);
        $address = str_replace(['ё', '.', ',', 'корпус', 'корп'], ['е', ' ', ' ', 'к', 'к'], $address);
        $address = preg_replace('/[^a-zа-я0-9]+/u', ' ', $address);

        return trim((string) $address);
    }

    private static function get_wc_session(string $key)
    {
        self::ensure_wc_session();

        if (!function_exists('WC') || !WC()->session) {
            return null;
        }

        return WC()->session->get($key);
    }

    private static function set_wc_session(string $key, $value): void
    {
        self::ensure_wc_session();

        if (!function_exists('WC') || !WC()->session) {
            return;
        }

        WC()->session->set_customer_session_cookie(true);

        if ($value === null) {
            WC()->session->__unset($key);
            return;
        }

        WC()->session->set($key, $value);
    }

    private static function ensure_wc_session(): void
    {
        if (!function_exists('WC') || !WC()) {
            return;
        }

        if (WC()->session) {
            return;
        }

        if (class_exists('WC_Session_Handler')) {
            WC()->session = new WC_Session_Handler();
            WC()->session->init();
        }
    }

    private static function get_selected_pickup_point(): array
    {
        $session_point = self::get_wc_session(self::SESSION_SELECTED_POINT);
        if (is_array($session_point) && !empty($session_point['id'])) {
            return $session_point;
        }

        $posted_point = self::get_posted_pickup_point();
        if (!empty($posted_point['id'])) {
            self::set_wc_session(self::SESSION_SELECTED_POINT, $posted_point);
            self::log('Selected pickup point restored from checkout POST.', $posted_point);

            return $posted_point;
        }

        return [];
    }

    private static function get_posted_pickup_point(): array
    {
        $id = isset($_POST['yandex_delivery_pickup_point_id'])
            ? sanitize_text_field(wp_unslash($_POST['yandex_delivery_pickup_point_id']))
            : '';

        if ($id === '') {
            return [];
        }

        return [
            'id' => $id,
            'name' => isset($_POST['yandex_delivery_pickup_point_name'])
                ? sanitize_text_field(wp_unslash($_POST['yandex_delivery_pickup_point_name']))
                : '',
            'address' => isset($_POST['yandex_delivery_pickup_point_address'])
                ? sanitize_text_field(wp_unslash($_POST['yandex_delivery_pickup_point_address']))
                : '',
            'lat' => isset($_POST['yandex_delivery_pickup_point_lat'])
                ? sanitize_text_field(wp_unslash($_POST['yandex_delivery_pickup_point_lat']))
                : '',
            'lon' => isset($_POST['yandex_delivery_pickup_point_lon'])
                ? sanitize_text_field(wp_unslash($_POST['yandex_delivery_pickup_point_lon']))
                : '',
        ];
    }

    private static function log(string $message, array $context = []): void
    {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->debug($message . (empty($context) ? '' : ' ' . wp_json_encode($context, JSON_UNESCAPED_UNICODE)), [
                'source' => self::LOG_SOURCE,
            ]);
        }
    }

    private static function truncate_log_value(string $value): string
    {
        return mb_substr($value, 0, 2000);
    }

    private static function get_order_pickup_point(WC_Order $order): array
    {
        return [
            'id' => (string) $order->get_meta(self::META_PREFIX . 'pickup_point_id'),
            'name' => (string) $order->get_meta(self::META_PREFIX . 'pickup_point_name'),
            'address' => (string) $order->get_meta(self::META_PREFIX . 'pickup_point_address'),
        ];
    }

    private static function get_api_token(): string
    {
        if (defined('YANDEX_DELIVERY_API_TOKEN')) {
            return (string) YANDEX_DELIVERY_API_TOKEN;
        }

        $config = self::get_local_config();
        if (!empty($config['token'])) {
            return (string) $config['token'];
        }

        $ygo_config = self::get_yandex_go_plugin_config();
        if (!empty($ygo_config['token'])) {
            return (string) $ygo_config['token'];
        }

        $token = (string) get_option('nitisveta_yandex_delivery_api_token', '');
        if ($token !== '') {
            return $token;
        }

        $ygo_settings = get_option('woocommerce_yandex-go-delivery_settings', []);
        return is_array($ygo_settings) ? (string) ($ygo_settings['token'] ?? '') : '';
    }

    private static function get_pickup_points_url(): string
    {
        if (defined('YANDEX_DELIVERY_PICKUP_POINTS_URL')) {
            return esc_url_raw((string) YANDEX_DELIVERY_PICKUP_POINTS_URL);
        }

        return esc_url_raw((string) get_option('nitisveta_yandex_delivery_pickup_points_url', ''));
    }

    private static function get_api_base_url(): string
    {
        if (defined('YANDEX_DELIVERY_API_BASE_URL')) {
            return untrailingslashit(esc_url_raw((string) YANDEX_DELIVERY_API_BASE_URL));
        }

        if (defined('YANDEX_DELIVERY_USE_TEST_ENV') && YANDEX_DELIVERY_USE_TEST_ENV) {
            return self::API_BASE_TEST;
        }

        $config = self::get_local_config();
        if (!empty($config['use_test_env'])) {
            return self::API_BASE_TEST;
        }

        $ygo_config = self::get_yandex_go_plugin_config();
        if (!empty($ygo_config['use_test_env'])) {
            return self::API_BASE_TEST;
        }

        return self::API_BASE_PROD;
    }

    private static function get_pickup_operator_ids(): array
    {
        if (defined('YANDEX_DELIVERY_PICKUP_OPERATOR_IDS')) {
            $ids = YANDEX_DELIVERY_PICKUP_OPERATOR_IDS;
            if (is_string($ids)) {
                $ids = array_map('trim', explode(',', $ids));
            }

            if (is_array($ids)) {
                return array_values(array_filter(array_map('sanitize_text_field', $ids)));
            }
        }

        return ['market_l4g'];
    }

    private static function get_local_config(): array
    {
        static $config = null;

        if ($config !== null) {
            return $config;
        }

        $path = self::plugin_dir() . 'config.php';
        if (!file_exists($path)) {
            $config = [];
            return $config;
        }

        $loaded = require $path;
        $config = is_array($loaded) ? $loaded : [];

        return $config;
    }

    private static function get_yandex_go_plugin_config(): array
    {
        static $config = null;

        if ($config !== null) {
            return $config;
        }

        $path = WP_PLUGIN_DIR . '/yandex-go-delivery/config/config.php';
        if (!file_exists($path)) {
            $config = [];
            return $config;
        }

        $loaded = require $path;
        $config = is_array($loaded) ? $loaded : [];

        return $config;
    }

    private static function get_map_api_key(): string
    {
        $key = '';

        if (defined('YANDEX_MAPS_API_KEY')) {
            $key = (string) YANDEX_MAPS_API_KEY;
        } else {
            $key = (string) get_option('nitisveta_yandex_maps_api_key', '');
        }

        $key = trim($key);
        if ($key === '' || self::looks_like_invalid_maps_api_key($key)) {
            return '';
        }

        return $key;
    }

    private static function looks_like_invalid_maps_api_key(string $key): bool
    {
        $lower = self::lower($key);

        if (strpos($lower, 'ключ') !== false || strpos($lower, 'token') !== false) {
            return true;
        }

        if (strpos($key, 'y0__') === 0) {
            return true;
        }

        return false;
    }

    private static function plugin_dir(): string
    {
        return trailingslashit(__DIR__);
    }

    private static function plugin_url(): string
    {
        return content_url('mu-plugins/nitisveta-yandex-delivery-api/');
    }

    private static function asset_version(string $path): string
    {
        return file_exists($path) ? (string) filemtime($path) : self::VERSION;
    }
}

Nitisveta_Yandex_Delivery_Api::init();
