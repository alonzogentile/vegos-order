<?php
/**
 * result_modifier.php (исправленный)
 * - Пишем штрих-код (CML2_BAR_CODE) прямо в $arResult['JS_DATA']['GRID']['ROWS'][*]['data']['GOODS_BARCODE']
 * - Дублируем в ['data']['PROPS'][] как свойство позиции
 * - Формируем $arResult['JS_DATA']['LOYALTY_PAYLOAD'] для SetLoyalty
 */

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) { die(); }

use Bitrix\Main\Loader;

Loader::includeModule('iblock');
Loader::includeModule('catalog');
use Bitrix\Catalog\StoreBarcodeTable;
use Bitrix\Iblock\ElementTable;
use Bitrix\Catalog\CatalogIblockTable;

/**
 * Достаёт CML2_BAR_CODE по PRODUCT_ID
 */

/**
 * Возвращает штрих-код CML2_BAR_CODE для элемента каталога или его ТП.
 * 1) Пытаемся взять у самого элемента
 * 2) Если пусто — ищем первое активное ТП и берём штрих-код оттуда
 */
function vm_getCml2Barcode(int $productId): ?string
{
    // 1) Св-во у самого товара
    $prop = CIBlockElement::GetProperty(
        0,             // IBLOCK_ID можно 0 — Битрикс сам найдёт
        $productId,
        ['sort' => 'asc'],
        ['CODE' => 'CML2_BAR_CODE']
    )->Fetch();

    if (!empty($prop['VALUE'])) {
        return (string)$prop['VALUE'];
    }

    // 2) Если это товар с ТП — берём из первого активного ТП
    $offers = CCatalogSKU::getOffersList(
        [$productId],            // ВАЖНО: массив ID!
        0,                       // auto-detect iblock
        ['ACTIVE' => 'Y'],       // Фильтр по ТП
        ['ID', 'NAME', 'IBLOCK_ID'],
        ['CODE' => ['CML2_BAR_CODE']] // ВАЖНО: свойства — пятым аргументом
    );

    if (!empty($offers[$productId])) {
        foreach ($offers[$productId] as $offer) {
            // Свойства ТП лежат в $offer['PROPERTIES']
            if (!empty($offer['PROPERTIES']['CML2_BAR_CODE']['VALUE'])) {
                return (string)$offer['PROPERTIES']['CML2_BAR_CODE']['VALUE'];
            }
        }
    }

    return null;
}

/**
 * Проставляем штрих-коды во все структуры, которые читает фронт
 */
function vm_hydrateBarcodesIntoResult(array &$arResult): void
{
    if (empty($arResult['BASKET_ITEMS'])) {
        return;
    }

    // 1) В сами позиции корзины
    foreach ($arResult['BASKET_ITEMS'] as &$item) {
        $pid = (int)$item['PRODUCT_ID'];
        $barcode = null;

        // если уже пришло из каталога — оставим
        if (!empty($item['PROPERTY_CML2_BAR_CODE_VALUE'])) {
            $barcode = (string)$item['PROPERTY_CML2_BAR_CODE_VALUE'];
        } else {
            $barcode = vm_getCml2Barcode($pid);
            if ($barcode) {
                $item['PROPERTY_CML2_BAR_CODE_VALUE'] = $barcode;
            }
        }
    }
    unset($item);

    // 2) В GRID -> ROWS -> data (то, что у тебя считывает JS)
    if (!empty($arResult['JS_DATA']['GRID']['ROWS'])) {
        foreach ($arResult['JS_DATA']['GRID']['ROWS'] as $rowId => &$row) {
            $data = &$row['data'];
            if (!empty($data['PRODUCT_ID'])) {
                $pid = (int)$data['PRODUCT_ID'];
                $barcode = $data['PROPERTY_CML2_BAR_CODE_VALUE'] ?? vm_getCml2Barcode($pid);
                if ($barcode) {
                    $data['PROPERTY_CML2_BAR_CODE_VALUE'] = $barcode;
                    // Дополнительно — короткий алиас, если фронт ждёт GOODS_BARCODE
                    $data['GOODS_BARCODE'] = $barcode;
                }
            }
            unset($data);
        }
        unset($row);
    }
}

// ВЫЗОВ: обогащаем $arResult прямо в result_modifier
vm_hydrateBarcodesIntoResult($arResult);

// Дублируем баркоды из JS_DATA.GRID.ROWS в BASKET_ITEMS, чтобы старый POST содержал поле PROPERTY_CML2_BAR_CODE_VALUE
if (isset($arResult['BASKET_ITEMS']) && is_array($arResult['BASKET_ITEMS']) && !empty($arResult['JS_DATA']['GRID']['ROWS']) && is_array($arResult['JS_DATA']['GRID']['ROWS'])) {
    foreach ($arResult['BASKET_ITEMS'] as &$bi) {
        $pid = (int)($bi['PRODUCT_ID'] ?? 0);
        foreach ($arResult['JS_DATA']['GRID']['ROWS'] as $row) {
            $rowPid = (int)($row['data']['PRODUCT_ID'] ?? 0);
            if ($rowPid === $pid) {
                $barcode = $row['data']['PROPERTY_CML2_BAR_CODE_VALUE'] ?? '';
                $bi['PROPERTY_CML2_BAR_CODE_VALUE'] = $barcode ?: '';
                $bi['~PROPERTY_CML2_BAR_CODE_VALUE'] = $barcode ?: '';
                break;
            }
        }
    }
    unset($bi);
}

/**
 * Проверяем, что у нас есть GRID.ROWS
 */
if (empty($arResult['JS_DATA']['GRID']['ROWS']) || !is_array($arResult['JS_DATA']['GRID']['ROWS'])) {
    // пробрасываем пустой payload для стабильности фронта
    if (!isset($arResult['JS_DATA']['LOYALTY_PAYLOAD'])) {
        $arResult['JS_DATA']['LOYALTY_PAYLOAD'] = [
            'purchase' => [
                'positions' => [],
                'sumItems'  => 0.0,
            ],
        ];
    }
    return;
}


// Пройдёмся по позициям и допишем штрих-коды в нужные поля

// ...existing code...

$positionsForLoyalty = [];
if (isset($arResult['JS_DATA']['GRID']['ROWS']) && is_array($arResult['JS_DATA']['GRID']['ROWS'])) {
    foreach ($arResult['JS_DATA']['GRID']['ROWS'] as &$row) {
        if (!is_array($row) || empty($row['data']) || !is_array($row['data'])) {
            continue;
        }
        $d = &$row['data'];
        $price  = (float)str_replace([' ', '&nbsp;'], '', (string)($d['PRICE'] ?? 0));
        $qty    = (float)str_replace([' ', '&nbsp;'], '', (string)($d['QUANTITY'] ?? 0));
        $amount = isset($d['SUM_NUM'])
            ? (float)str_replace([' ', '&nbsp;'], '', (string)$d['SUM_NUM'])
            : ($price * $qty);
        $positionsForLoyalty[] = [
            'goodsCode'    => (string)($d['PROPERTY_CML2_BAR_CODE_VALUE'] ?? ''),
            'cost'         => $price,
            'count'        => $qty,
            'amount'       => $amount,
            'discountable' => true,
        ];
        unset($d);
    }
    unset($row);
}

/**
 * Считаем sumItems (без доставки)
 * Предпочтительно через D7; если модуль sale недоступен — берём из JS_DATA.TOTAL.ORDER_PRICE
 */
$sumItems = 0.0;
if (Loader::includeModule('sale')) {
    $siteId = \Bitrix\Main\Context::getCurrent()->getSite();
    $basket = \Bitrix\Sale\Basket::loadItemsForFUser(\Bitrix\Sale\Fuser::getId(), $siteId);
    $sumItems = (float)$basket->getPrice();
} else {
    if (!empty($arResult['JS_DATA']['TOTAL']['ORDER_PRICE'])) {
        $sumItems = (float)str_replace([' ', '&nbsp;'], '', (string)$arResult['JS_DATA']['TOTAL']['ORDER_PRICE']);
    } else {
        $sum = 0.0;
        foreach ($positionsForLoyalty as $p) {
            $sum += (float)$p['amount'];
        }
        $sumItems = $sum;
    }
}

/**
 * Прокидываем payload для API лояльности
 */
$arResult['JS_DATA']['LOYALTY_PAYLOAD'] = [
    'purchase' => [
        'positions' => $positionsForLoyalty,
        'sumItems'  => $sumItems,
    ],
];

// --- FIX: гарантированно проставляем штрих-код для SET ---
function vmGetBarcode(int $productId) {
    if (!$productId) return null;

    // Узнаём IBLOCK_ID элемента быстро
    $row = ElementTable::getRow([
        'select' => ['IBLOCK_ID'],
        'filter' => ['=ID' => (int)$productId],
    ]);
    if (!$row || !$row['IBLOCK_ID']) return null;

    $iblockId = (int)$row['IBLOCK_ID'];

    // 1) Пытаемся взять штрих-код прямо у элемента (оффера/товара)
    $prop = CIBlockElement::GetProperty($iblockId, $productId, ['sort'=>'asc'], ['CODE' => 'CML2_BAR_CODE'])->Fetch();
    if (!empty($prop['VALUE'])) {
        return (string)$prop['VALUE'];
    }

    // 2) Если элемент — товар, попробуем найти активный оффер и взять его штрих-код
    $catalog = CatalogIblockTable::getRow(['filter' => ['=IBLOCK_ID' => $iblockId]]);
    if ($catalog && (int)$catalog['SKU_PROPERTY_ID'] > 0) {
        // Элемент — родитель, найдём первый оффер
        $offers = \CCatalogSKU::getOffersList([$productId], $iblockId, ['ACTIVE' => 'Y'], ['ID'], ['CML2_BAR_CODE']);
        if (!empty($offers[$productId])) {
            foreach ($offers[$productId] as $offer) {
                $offerId = (int)($offer['ID'] ?? 0);
                $offerIblock = (int)($offer['IBLOCK_ID'] ?? 0);
                if ($offerId && $offerIblock) {
                    $p = CIBlockElement::GetProperty($offerIblock, $offerId, ['sort'=>'asc'], ['CODE' => 'CML2_BAR_CODE'])->Fetch();
                    if (!empty($p['VALUE'])) {
                        return (string)$p['VALUE'];
                    }
                }
            }
        }
    }

    return null;
}

if (!empty($arResult['BASKET_ITEMS'])) {
    foreach ($arResult['BASKET_ITEMS'] as &$item) {
        // Уже есть — не трогаем
        if (!empty($item['PROPERTY_CML2_BAR_CODE_VALUE'])) continue;

        $barcode = vmGetBarcode((int)$item['PRODUCT_ID']);
        if ($barcode) {
            $item['PROPERTY_CML2_BAR_CODE_VALUE'] = $barcode;
            $item['~PROPERTY_CML2_BAR_CODE_VALUE'] = $barcode;
        }
    }
    unset($item);
}
// --- /FIX ---
