<?php

/************************************************************
 * 1. Константы
 ************************************************************/
define('BX24_WEBHOOK_URL', 'https://yourdomain.bitrix24.ru/rest/1/yourwebhookkey/'); // todo: указать свой вебхук
define('YANDEX_GEOCODER_KEY', '93632bf9-8dcf-4324-aa05-d21900cf8b56'); // todo: мой тестовый ключ
define('USE_OSRM_ENDPOINT', 'https://router.project-osrm.org');

define('B24_ENTITY_TYPE_ID', 1036);                 // ID смарт-процесса "Доставка"
define('B24_STAGE_ID', 'DT1036_15:PREPARATION');    // Стадия "Курьер назначен"

// Координаты склада
define('WAREHOUSE_LAT', 51.663691);
define('WAREHOUSE_LON', 39.173841);

// Константы для "Генерируем PDF"
define('PDF_ENTITY_TYPE_ID', 1046);                     // ID смарт-процесса "Генерируем PDF"
define('PDF_DELIVERIES_FIELD', 'UF_CRM_9_1738847204');  // Поле-связка "Доставки"
define('PDF_TEMPLATE_ID', 43);                          // Шаблон Document Generator

// Таймаут
define('SET_TIMEOUT', 60);

/************************************************************
 * 2. Получаем заказы из "Доставка" (фильтр по стадии "Курьер назначен")
 ************************************************************/
$params = [
    'entityTypeId' => B24_ENTITY_TYPE_ID,
    'select' => ['*'],
    'filter' => ['stageId' => B24_STAGE_ID],
];

$resultB24 = callB24('crm.item.list', $params);
$allItems = $resultB24['result']['items'] ?? [];

if (!$allItems) {
    echo "Нет заказов в стадии " . B24_STAGE_ID . PHP_EOL;
    exit;
}

/************************************************************
 * 3. Группируем заказы по курьеру (assignedById)
 ************************************************************/
$byCourier = [];
foreach ($allItems as $item) {
    $courierId = $item['assignedById'] ?? 0;
    if (!$courierId) {
        continue;
    }
    $byCourier[$courierId][] = $item;
}

if (!$byCourier) {
    echo "Нет заказов с заполненным курьером." . PHP_EOL;
    exit;
}

/************************************************************
 * 4. Для каждого курьера:
 *    - Запрашиваем имя курьера
 *    - Геокодируем адреса
 *    - Добавляем склад
 *    - Вызываем OSRM (если >= 2 адреса)
 *    - Создаём запись в смарт-процессе "Генерируем PDF"
 *    - Генерируем PDF
 ************************************************************/
foreach ($byCourier as $courierId => $itemsOfCourier) {


    /********************************************************
     * 4.1. Пытаемся получить имя курьера
     ********************************************************/
    $courierName = getUserFio($courierId);
    if (!$courierName) {
        $courierName = "ID:{$courierId} (неизвестен)";
    }

    echo PHP_EOL . "=== КУРЬЕР: {$courierName} ===" . PHP_EOL;

    /********************************************************
     * 4.2. Собираем ID доставок + геокодируем адреса
     ********************************************************/
    $coordsData = [];  // адреса для маршрута
    $deliveryIds = [];  // все ID доставок
    $orderNumbers = []; // порядковый номер заказов

    foreach ($itemsOfCourier as $index => $item) {
        $deliveryId = $item['id'];
        $addressRaw = $item['ufCrm7_1738327594'] ?? '';
        $title = $item['title'] ?? '';

        $deliveryIds[] = $deliveryId;
        $orderNumbers[] = ($index + 1); // порядковый номер

        // Пустой адрес пропускаем
        if (!$addressRaw) {
            continue;
        }

        // Всегда добавляем "Воронеж, "
        $fullAddress = 'Воронеж, ' . $addressRaw;

        // Геокодируем
        $geo = geocodeAddress($fullAddress);
        if ($geo) {
            $coordsData[] = [
                'orderId' => $deliveryId,
                'title' => $title,
                'address' => $fullAddress,
                'lat' => $geo[0],
                'lon' => $geo[1],
            ];
        }
    }

    if (empty($deliveryIds)) {
        echo "У курьера {$courierName} нет ни одного заказа." . PHP_EOL;
        continue;
    }

    /********************************************************
     * 4.3. Готовим точки (склад + все геокодированные адреса)
     ********************************************************/
    $allPoints = [];
    // Склад
    $allPoints[] = [
        'orderId' => 'WAREHOUSE',
        'title' => 'Склад',
        'address' => 'Склад: Пирогова, 15к2',
        'lat' => WAREHOUSE_LAT,
        'lon' => WAREHOUSE_LON,
    ];
    foreach ($coordsData as $row) {
        $allPoints[] = $row;
    }

    // Массивы для OSRM-упорядоченных данных
    $sortedOrderNumbers = [];
    $sortedOrderTitles = [];
    $sortedAddresses = [];

    /********************************************************
     * 4.4. Если адресов >= 2, вызываем OSRM, иначе пропускаем
     ********************************************************/
    $countAddresses = count($coordsData);
    if ($countAddresses >= 2) {
        // Есть смысл строить "оптимальный" маршрут
        $tripData = callOsrmTrip($allPoints);

        // todo: Вывод для отладки
//        echo "Результат OSRM (сырой JSON) для курьера {$courierName}:" . PHP_EOL;
//        var_dump($tripData);

        echo PHP_EOL . "=== Маршрут для курьера {$courierName}: ===" . PHP_EOL;
        // Вызываем printOptimizedRoute, которая и выводит порядок
        printOptimizedRoute($tripData, $allPoints, $sortedOrderNumbers, $sortedOrderTitles, $sortedAddresses);

    } elseif ($countAddresses === 1) {
        echo PHP_EOL .  "У курьера {$courierName} всего один адрес." . PHP_EOL;

        // Если ровно один адрес, сохраняем в исходном порядке
        $row = $coordsData[0];
        $sortedOrderNumbers[] = 1;
        $sortedOrderTitles[] = $row['title'];
        $sortedAddresses[] = $row['address'];

    } else {
        echo "У курьера {$courierName} нет геокодированных адресов." . PHP_EOL;
    }

    /********************************************************
     * 4.5. Создаём запись в смарт-процессе "Генерируем PDF"
     ********************************************************/
    $pdfTitle = "Курьер {$courierName} - " . date("Y-m-d H:i:s");
    $addPdfParams = [
        'entityTypeId' => PDF_ENTITY_TYPE_ID,
        'fields' => [
            'TITLE' => $pdfTitle,
            // Прикрепим все доставочные ID
            PDF_DELIVERIES_FIELD => $deliveryIds
        ],
    ];
    $addPdfResult = callB24('crm.item.add', $addPdfParams);

    if (!empty($addPdfResult['error'])) {
        echo "Ошибка создания записи в в смарт-процессе \"Генерируем PDF\": "
            . $addPdfResult['error_description'] . PHP_EOL;
        continue;
    }
    $newPdfItemId = $addPdfResult['result']['item']['id'] ?? 0;
    if (!$newPdfItemId) {
        echo "Не удалось получить ID новой записи в 'Генерируем PDF'" . PHP_EOL;
        continue;
    }
    echo "Создана запись в смарт-процессе 'Генерируем PDF' ID={$newPdfItemId}" . PHP_EOL;

    /********************************************************
     * 4.6. Вызываем Document Generator для смарт-процесса "Генерируем PDF"
     ********************************************************/

    echo PHP_EOL . "=== Итоговый список маршрутов ===" . PHP_EOL;

    $orderLines = [];

    foreach ($sortedOrderNumbers as $idx => $num) {
        $t = $sortedOrderTitles[$idx] ?? '';
        $a = $sortedAddresses[$idx] ?? '';

        $line = "{$num} — Заказ №{$t} — {$a}";
        echo $line . PHP_EOL;

        $orderLines[] = $line;
    }

    $orderLinesString = implode("\n", $orderLines);

    // Передаём финальную строку заказов и имя курьера
    $docParams = [
        'templateId' => PDF_TEMPLATE_ID,
        'entityTypeId' => PDF_ENTITY_TYPE_ID,
        'entityId' => $newPdfItemId,
        'title' => "PDF для курьера {$courierName}",
        'values' => [
            'COURIER_NAME' => $courierName,
            'ORDER_LINES' => $orderLinesString
        ]
    ];

    $docResult = callB24('crm.documentgenerator.document.add', $docParams);

    if (!empty($docResult['error'])) {
        echo PHP_EOL . "❌ Ошибка генерации PDF для курьера {$courierName}: " . $docResult['error_description'] . PHP_EOL;
    } else {
        $documentId = $docResult['result']['document']['id'] ?? null;

        if (!$documentId) {
            echo PHP_EOL . "❌ Ошибка: Не удалось получить ID документа" . PHP_EOL;
            return;
        }

        echo PHP_EOL . "✅ Создан PDF-документ для курьера {$courierName}, ID: {$documentId}" . PHP_EOL;

        // Получаем ссылку на скачивание PDF
        $docInfo = callB24('crm.documentgenerator.document.get', [
            'id' => $documentId
        ]);

        $pdfUrl = $docInfo['result']['document']['downloadUrl'] ?? null;

        if ($pdfUrl) {
            echo "🔗 <a href='{$pdfUrl}' target='_blank'>Скачать PDF</a>" . PHP_EOL;
        } else {
            echo "❌ Ошибка: Не удалось получить ссылку на PDF" . PHP_EOL;
        }
    }
}

/************************************************************
 * Функция: получаем ФИО пользователя (NAME + LAST_NAME)
 ************************************************************/
function getUserFio($userId)
{
    // Запрашиваем user.get c фильтром по ID
    $resp = callB24('user.get', ['ID' => $userId]);
    if (empty($resp['result'][0])) {
        return ''; // не нашли
    }
    $u = $resp['result'][0];
    $fio = trim($u['NAME'] . ' ' . $u['LAST_NAME']);
    if (!$fio) {
        $fio = $u['LOGIN'] ?? '';
    }
    return $fio ?: '';
}

/************************************************************
 * Функция: выводим порядок точек из OSRM
 ************************************************************/
function printOptimizedRoute(array $tripData, array $allPoints, array &$sortedOrderNumbers, array &$sortedOrderTitles, array &$sortedAddresses)
{
    if (empty($tripData['waypoints']) || !is_array($tripData['waypoints'])) {
        echo "OSRM вернул пустой список waypoints." . PHP_EOL;
        return;
    }

    // Сортируем waypoints (по waypoint_index)
    $waypoints = $tripData['waypoints'];
    usort($waypoints, function ($a, $b) {
        return ($a['waypoint_index'] <=> $b['waypoint_index']);
    });

    $step = 0;
    foreach ($waypoints as $wp) {
        // В 'location' OSRM хранит [longitude, latitude]
        $lon = $wp['location'][0];
        $lat = $wp['location'][1];

        // Ищем, какая точка в $allPoints имеет эти lon/lat
        // (сравнение с небольшим допуском (апостериори: 0.001),
        //  чтобы избежать неточностей плавающей запятой)
        foreach ($allPoints as $p) {
            // В нашем массиве p['lat'], p['lon']
            if (abs($p['lon'] - $lon) < 0.001 && abs($p['lat'] - $lat) < 0.001) {
                // Нашли точку
                if ($p['orderId'] === 'WAREHOUSE') {
                    // Пропускаем склад
                    break;
                } else {
                    $step++;
                    // Можно вывести название также: "{$p['title']}"
                    $routeLine = "{$step} — {$p['address']}";
                    echo $routeLine . PHP_EOL;

                    // Дополнительно записываем для PDF
                    $sortedOrderNumbers[] = $step;
                    $sortedOrderTitles[] = $p['title'];
                    $sortedAddresses[] = $p['address'];
                }
                break;
            }
        }
    }
}

/************************************************************
 * Функция вызова OSRM (с таймаутом 60 сек)
 ************************************************************/
function callOsrmTrip(array $points)
{
    // Собираем координаты в формате "lon,lat"
    $coordsArr = [];
    foreach ($points as $p) {
        $coordsArr[] = $p['lon'] . ',' . $p['lat'];
    }
    $coordsJoined = implode(';', $coordsArr);

    $options = [
        'roundtrip' => 'false', // не возвращаемся к стартовой точке
        'source' => 'first', // фиксируем первую точку как начало
        'destination' => 'any', // можно закончить на любой точке (если нужно закончить на последней, меняем на destination=last)
        'overview' => 'false', // без геометрии маршрута, только порядок
    ];

    $query = http_build_query($options);
    $url = USE_OSRM_ENDPOINT . "/trip/v1/driving/$coordsJoined?$query";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => SET_TIMEOUT,  // 20 сек на ответ OSRM
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    return json_decode($resp, true);
}

/************************************************************
 * Функция для вызова методов Битрикс24 (с таймаутом 60 сек)
 ************************************************************/
function callB24($method, $params = [])
{
    $url = BX24_WEBHOOK_URL . $method;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_TIMEOUT => SET_TIMEOUT,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    return json_decode($resp, true);
}

/************************************************************
 * Функция для получения координат из Яндекс.Геокодера (с таймаутом 60 сек)
 * + проверяем, что "province" == "Воронежская область"
 ************************************************************/
function geocodeAddress($address)
{
    // 1) Отправляем запрос
    $url = 'https://geocode-maps.yandex.ru/1.x/?apikey='
        . YANDEX_GEOCODER_KEY
        . '&format=json&lang=ru_RU&geocode='
        . urlencode($address);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => SET_TIMEOUT,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    // 2) Парсим JSON
    $data = json_decode($response, true);
    if (empty($data['response']['GeoObjectCollection']['featureMember'][0])) {
        return null;
    }

    $geoObject = $data['response']['GeoObjectCollection']['featureMember'][0]['GeoObject'];

    // Проверка на "Воронежская область"
    $components = $geoObject['metaDataProperty']['GeocoderMetaData']['Address']['Components'] ?? [];
    $foundVoronezhRegion = false;
    foreach ($components as $cmp) {
        if (($cmp['kind'] ?? '') === 'province'
            && mb_strtolower($cmp['name'] ?? '') === 'воронежская область'
        ) {
            $foundVoronezhRegion = true;
            break;
        }
    }
    if (!$foundVoronezhRegion) {
        // Если нет нужной области — считаем, что это "неверный результат"
        return null;
    }

    // 4) Извлекаем координаты (в pos идёт "долгота широта")
    $pos = $geoObject['Point']['pos'];
    [$lon, $lat] = explode(' ', $pos);

    return [(float)$lat, (float)$lon];
}