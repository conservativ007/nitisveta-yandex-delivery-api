# Nitisveta Yandex Pickup Delivery

MU-plugin for WooCommerce checkout delivery via Yandex pickup points.

## What It Does

- Adds the shipping method `Яндекс Доставка до ПВЗ`.
- Lets the customer choose a Yandex pickup point in a modal map.
- Shows the selected pickup point address inside the shipping card.
- Saves selected pickup point data to the WooCommerce order meta.
- Calculates the shipping price through Yandex Delivery `pricing-calculator` when possible.
- Falls back to a fixed price if automatic pricing is unavailable.

The plugin is loaded by:

```text
wp-content/mu-plugins/nitisveta-yandex-delivery-api.php
```

The plugin directory is:

```text
wp-content/mu-plugins/nitisveta-yandex-delivery-api
```

## Configuration

Recommended configuration in `wp-config.php`:

```php
define('YANDEX_DELIVERY_API_TOKEN', 'token-from-yandex');
define('YANDEX_MAPS_API_KEY', 'maps-api-key');
```

Optional local config file:

```text
wp-content/mu-plugins/nitisveta-yandex-delivery-api/config.php
```

```php
<?php

return [
    'token' => 'token-from-yandex',
    'use_test_env' => false,
];
```

## Source Pickup Point

The default source pickup point address is:

```text
Дальневосточный просп., 12, корп. 2, Санкт-Петербург
```

The plugin tries to find the matching Yandex pickup point automatically and caches its `platform_station_id`.

For a stable production setup, it is better to provide the exact source pickup point id:

```php
define('YANDEX_DELIVERY_SOURCE_PICKUP_POINT_ID', 'source-platform-station-id');
```

You can override the source address:

```php
define('YANDEX_DELIVERY_SOURCE_PICKUP_ADDRESS', 'Дальневосточный просп., 12, корп. 2, Санкт-Петербург');
```

## Pricing

Automatic pricing uses:

```text
POST /api/b2b/platform/pricing-calculator
```

with tariff:

```text
self_pickup
```

If automatic pricing fails, the plugin uses a fixed fallback price. Default fallback is `0`.

Set fallback price:

```php
define('YANDEX_DELIVERY_PICKUP_COST', 350);
```

## API Environment

Production base URL:

```text
https://b2b-authproxy.taxi.yandex.net/api/b2b/platform
```

Enable test environment:

```php
define('YANDEX_DELIVERY_USE_TEST_ENV', true);
```

Override API base URL:

```php
define('YANDEX_DELIVERY_API_BASE_URL', 'https://example.com/api/b2b/platform');
```

## Pickup Point Providers

Default operator:

```text
market_l4g
```

Add 5Post:

```php
define('YANDEX_DELIVERY_PICKUP_OPERATOR_IDS', 'market_l4g,5post');
```

## Custom Pickup Point Source

By default the plugin loads pickup points from official Yandex API:

```text
POST /api/b2b/platform/pickup-points/list
```

For temporary testing with your own proxy/API:

```php
define('YANDEX_DELIVERY_PICKUP_POINTS_URL', 'https://example.com/path/to/pickup-points');
```

Custom API response should contain pickup points with:

- `id`
- `address`
- `lat`
- `lon`

Optional fields:

- `name`
- `city`
- `country`
- `schedule`

Example local JSON in `data/pickup-points.json`:

```json
[
  {
    "id": "yandex-msk-1",
    "name": "Яндекс ПВЗ",
    "address": "Москва, Тверская улица, 1",
    "city": "Москва",
    "country": "RU",
    "lat": 55.755864,
    "lon": 37.617698,
    "schedule": "Ежедневно 10:00-22:00"
  }
]
```

## Logs

WooCommerce log source:

```text
nitisveta-yandex-delivery
```

Typical path:

```text
wp-content/uploads/wc-logs/nitisveta-yandex-delivery-*.log
```

Logs include:

- selected destination pickup point
- source pickup point lookup
- `pricing-calculator` request payload
- Yandex response code and response body
- calculated price or fallback usage