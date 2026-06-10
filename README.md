# Nitisveta Yandex Pickup Delivery

Модуль добавляет выбор пункта выдачи Яндекс Доставки в checkout для метода доставки `flat_rate:10`.
Если такого метода нет в зоне доставки WooCommerce, модуль сам добавляет способ доставки `Яндекс Доставка до ПВЗ`.

## Подключение данных ПВЗ

Есть два источника данных:

1. Локальный JSON: `data/pickup-points.json`.
2. Официальный API Яндекс Доставки по России: `pickup-points/list`.

```php
define('YANDEX_DELIVERY_API_TOKEN', 'token-from-yandex');
define('YANDEX_MAPS_API_KEY', 'maps-api-key');
```

Можно также создать файл `wp-content/mu-plugins/yandex-delivery-api/config.php`:

```php
<?php

return [
    'token' => 'token-from-yandex',
    'use_test_env' => false,
];
```

Стоимость добавленного способа доставки по умолчанию `0`. Чтобы задать фиксированную цену:

```php
define('YANDEX_DELIVERY_PICKUP_COST', 350);
```

По умолчанию модуль ходит в production:

```text
https://b2b-authproxy.taxi.yandex.net/api/b2b/platform
```

Для тестового окружения:

```php
define('YANDEX_DELIVERY_USE_TEST_ENV', true);
```

По умолчанию загружаются ПВЗ Яндекс Маркета и партнеров (`market_l4g`). Если нужны еще 5Post:

```php
define('YANDEX_DELIVERY_PICKUP_OPERATOR_IDS', 'market_l4g,5post');
```

Если потребуется временно подключить свой proxy/API, можно указать:

```php
define('YANDEX_DELIVERY_PICKUP_POINTS_URL', 'https://example.com/path/to/pickup-points');
```

Свой API должен возвращать JSON со списком пунктов. Модуль понимает поля `points`, `pickup_points`, `offices`, `items`, `data`, `result.items` и похожие структуры. Для каждого пункта нужны `id`, `address`, `lat`, `lon`; необязательные поля: `name`, `city`, `country`, `schedule`.

Пример локального JSON:

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
