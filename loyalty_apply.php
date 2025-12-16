<?php
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);

require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php';

// Using fully-qualified names below to keep static analysis happy

header('Content-Type: application/json; charset=UTF-8');

try {
    if (function_exists('check_bitrix_sessid')) {
        if (!check_bitrix_sessid()) { throw new \RuntimeException('Bad sessid'); }
    }

    if (!\Bitrix\Main\Loader::includeModule('sale') || !\Bitrix\Main\Loader::includeModule('catalog') || !\Bitrix\Main\Loader::includeModule('iblock')) {
        throw new \RuntimeException('Modules sale/catalog/iblock are required');
    }

    $request = \Bitrix\Main\Context::getCurrent()->getRequest();
    $payload = \Bitrix\Main\Web\Json::decode((string)$request->getPost('payload'));

    // payload ожидается с фронта: параметры применения (карта, флаг списания и т.д.)
    // (раньше мы временно перенаправляли на другой endpoint; теперь обрабатываем здесь)
    $deliveryPrice = (float)$request->getPost('deliveryPrice');

    // --- 1) Регион → shop/terminalId (заглушка Улан-Удэ = 266)
    $regionId = (int)($payload['regionId'] ?? 0);
    if ($regionId <= 0) { $regionId = 266; }

    $shop       = (int)($payload['shop'] ?? 0);
    $terminalId = (int)($payload['terminalId'] ?? 0);

    $map = [
        266 => ['shop' => 6, 'terminalId' => 71], // Улан-Удэ (заглушка)
        // при появлении реальных — добавьте сюда
    ];
    if ($shop <= 0 && isset($map[$regionId]))       { $shop = (int)$map[$regionId]['shop']; }
    if ($terminalId <= 0 && isset($map[$regionId])) { $terminalId = (int)$map[$regionId]['terminalId']; }
    if ($shop <= 0)       { $shop = 6; }
    if ($terminalId <= 0) { $terminalId = 71; }

    // --- 2) Карта, флаг списания, «сколько хочет списать»
    $cardNumber     = trim((string)($payload['cardNumber'] ?? ''));
    $checkOffBonus  = (int)($payload['check_off_bonus'] ?? 0) === 1;
    $balanceScore   = (float)($payload['balance_score'] ?? 0);

    // 6-значные — дополняем префиксом
    if (preg_match('~^\d{6}$~', $cardNumber)) {
        $cardNumber = '0067833'.$cardNumber;
    }

    // --- 3) Корзина (D7): позиции и сумма товаров без доставки
    $siteId = \Bitrix\Main\Context::getCurrent()->getSite();
    $basket = \Bitrix\Sale\Basket::loadItemsForFUser(\Bitrix\Sale\Fuser::getId(), $siteId);
    $sumItems = (float)$basket->getPrice(); // сумма товаров без доставки (ORDER_PRICE) :contentReference[oaicite:4]{index=4}

    $positions = [];
    $orderN = 1;

    foreach ($basket as $item) {
        /** @var \Bitrix\Sale\BasketItem $item */
        $productId = (int)$item->getProductId();
        $q = (float)$item->getQuantity();
        $price = (float)$item->getPrice();

        // штрих-код CML2_BAR_CODE
        $barcode = '';
    $dbProp = \CIBlockElement::GetProperty((int)$item->getField('IBLOCK_ID'), $productId, ['sort'=>'asc'], ['CODE'=>'CML2_BAR_CODE']);
        if ($arProp = $dbProp->Fetch()) {
            $barcode = (string)$arProp['VALUE'];
        } // :contentReference[oaicite:5]{index=5}

        // если нет штрих-кода — можно падать/пропускать; сейчас — пропускаем пустые
        if ($barcode === '') { continue; }

        $positions[] = [
            'discountable' => true,
            'cost'         => $price,
            'count'        => $q,
            'amount'       => $price * $q,
            'goodsCode'    => $barcode,
            'departNumber' => 1,
            'order'        => $orderN++,
        ];
    }

    // подстраховка
    if ($sumItems <= 0 || empty($positions)) {
    echo \Bitrix\Main\Web\Json::encode([
            'ok' => true,
            'message' => 'Пустая корзина или нет штрих-кодов',
            'writeoff' => 0,
            'sumItems' => 0,
            'total' => $deliveryPrice,
        ]);
        return;
    }

    // --- 4) SOAP: doProcessPurchase
    // ВАЖНО: проверьте URL сервиса/локейшн, как в старой корзине:
    $wsdl = 'http://213.87.101.147:8090/SET-ProcessingDiscount/ProcessingPurchaseWS?wsdl';
    $location = 'http://213.87.101.147:8090/SET-ProcessingDiscount/ProcessingPurchaseWS';

    // --- 3.5) Сколько просим списать у SET — только если флаг списания включён
    $amountToWriteoff = 0.0;
    if ($checkOffBonus) {
        $amountToWriteoff = max(0.0, (float)$balanceScore);
    }

    // Готовим структуру запроса «как раньше"
    $purchase = [
        'saletime'    => date('Y-m-d\TH:i:s'),
        'shop'        => $shop,
        'terminalId'  => $terminalId,
        'amount'      => $sumItems,
        'position'    => array_map(function($p){ return (object)$p; }, $positions),
        'paymentType' => (object)[
            'paymentType' => 'CashPaymentEntity',   // по умолчанию
            'externalCode'=> 'CashPaymentEntity',
            'amount'      => $sumItems
        ],
        'discountCard'=> (object)array_filter([
            'cardNumber'       => $cardNumber,
            // передаём amountToWriteoff только если флаг включён
            'amountToWriteoff' => $checkOffBonus ? $amountToWriteoff : null,
        ], function($v){ return $v !== null; }),
    ];

    $soapPayload = [
        'purchase' => (object)$purchase,
        'check'    => true, // предварительный расчёт
    ];

    // Вызов с обработкой ошибок
    $client = new \SoapClient($wsdl, [
        'trace'       => true,
        'exceptions'  => true,
        'location'    => $location,
        'cache_wsdl'  => WSDL_CACHE_NONE,
        'connection_timeout' => 5,
    ]); // :contentReference[oaicite:6]{index=6}

    $resp = $client->doProcessPurchase((object)$soapPayload);
    if (!$resp || !isset($resp->return)) {
        throw new \RuntimeException('Empty SOAP response');
    }
    $soapReturn = $resp->return;

    // --- дополнительно: разбор скидок/бонусов по позициям для фронта (per-item)
    $posMap = []; // order -> goodsCode
    foreach ($positions as $p) {
        $ord = isset($p['order']) ? (int)$p['order'] : 0;
        $posMap[$ord] = isset($p['goodsCode']) ? (string)$p['goodsCode'] : '';
    }

    $perItem = []; // pid => ['discount'=>..., 'bonus'=>...]

    // СКИДКИ
    if (!empty($soapReturn->advertise) && !empty($soapReturn->advertise->discounts)) {
        $discounts = $soapReturn->advertise->discounts;
        if (!is_array($discounts)) { $discounts = [$discounts]; }
        foreach ($discounts as $d) {
            $pid = (int)($d->positionId ?? $d->posID ?? 0);
            $amount = (float)($d->amount ?? 0);
            if ($pid > 0 && $amount != 0.0) {
                if (!isset($perItem[$pid])) $perItem[$pid] = [];
                $perItem[$pid]['discount'] = ($perItem[$pid]['discount'] ?? 0) + $amount;
            }
        }
    }

    // БОНУСЫ: возможные вложения
    $bonusPositions = null;
    if (!empty($soapReturn->advertise->bonus->bonusPosition)) {
        $bonusPositions = $soapReturn->advertise->bonus->bonusPosition;
    } elseif (!empty($soapReturn->advertise->bonuses->bonusPosition)) {
        $bonusPositions = $soapReturn->advertise->bonuses->bonusPosition;
    }
    if ($bonusPositions) {
        if (!is_array($bonusPositions)) { $bonusPositions = [$bonusPositions]; }
        foreach ($bonusPositions as $bp) {
            $pid = (int)($bp->posID ?? $bp->positionId ?? 0);
            $val = (float)($bp->bonusValue ?? $bp->amount ?? 0);
            if ($pid > 0 && $val != 0.0) {
                if (!isset($perItem[$pid])) $perItem[$pid] = [];
                $perItem[$pid]['bonus'] = ($perItem[$pid]['bonus'] ?? 0) + $val;
            }
        }
    }

    // --- Собираем блок начислений (accrual) для фронта: totalBonus и perItem
    $totalAccrual = 0.0;
    $perItemAccrual = [];
    if (!empty($bonusPositions)) {
        foreach ($bonusPositions as $bp) {
            $pid = (int)($bp->posID ?? $bp->positionId ?? 0);
            $val = (float)($bp->bonusValue ?? $bp->amount ?? 0);
            if ($pid > 0 && $val > 0) {
                $totalAccrual += $val;
                $perItemAccrual[] = [
                    'positionId' => $pid,
                    'goodsCode'  => (string)($posMap[$pid] ?? ''),
                    'bonus'      => round($val, 2),
                ];
            }
        }
    }

    $outPerItem = [];
    foreach ($perItem as $pid => $vals) {
        $outPerItem[] = [
            'positionId' => (int)$pid,
            'goodsCode'  => (string)($posMap[$pid] ?? ''),
            'discount'   => isset($vals['discount']) ? round((float)$vals['discount'], 2) : 0.0,
            'bonus'      => isset($vals['bonus']) ? round((float)$vals['bonus'], 2) : 0.0,
        ];
    }

    // Что вернул SET
    $amountToWriteoff = 0.0;
    if (!empty($soapReturn->discountCard) && isset($soapReturn->discountCard->amountToWriteoff)) {
        $amountToWriteoff = (float)$soapReturn->discountCard->amountToWriteoff;
    }

    // --- 5) Финальный writeoff: применяем тумблер + лимиты
    $maxByPercent = round($sumItems * 0.49, 2); // лимит 49%
    $requested    = $checkOffBonus ? max(0.0, $balanceScore) : 0.0;
    $writeoff     = $checkOffBonus ? min($amountToWriteoff, $requested, $maxByPercent) : 0.0;

    // --- 6) «Итого» = товары − списание + доставка
    $total = max($sumItems - $writeoff, 0) + $deliveryPrice;

    $out = [
        'ok'          => true,
        'message'     => $checkOffBonus ? 'Бонусы применены' : 'Режим накопления (списание отключено)',
        'writeoff'    => $writeoff,
        'sumItems'    => $sumItems,
        'total'       => $total,
        'perItem'     => $outPerItem,
        // начисления отдельно — для фронта
        'accrual'     => [
            'totalBonus' => round($totalAccrual, 2),
            'perItem'    => $perItemAccrual,
        ],
        // для отладки:
        'soapEcho'    => [
            'amountToWriteoff' => $amountToWriteoff,
            'shop'             => $shop,
            'terminalId'       => $terminalId
        ],
    ];

    echo \Bitrix\Main\Web\Json::encode($out);
} catch (\SoapFault $f) { // SOAP-ошибка читаемо в json :contentReference[oaicite:7]{index=7}
    http_response_code(502);
    echo \Bitrix\Main\Web\Json::encode(['ok'=>false,'error'=>'SOAP Fault: '.$f->getMessage()]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo \Bitrix\Main\Web\Json::encode(['ok'=>false,'error'=>$e->getMessage()]);
}
