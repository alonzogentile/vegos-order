<?php
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);

require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    if (function_exists('check_bitrix_sessid')) {
        if (!check_bitrix_sessid()) { throw new \RuntimeException('Bad sessid'); }
    }

    if (!\Bitrix\Main\Loader::includeModule('sale') || !\Bitrix\Main\Loader::includeModule('catalog') || !\Bitrix\Main\Loader::includeModule('iblock')) {
        throw new \RuntimeException('Modules sale/catalog/iblock are required');
    }

    $request = \Bitrix\Main\Context::getCurrent()->getRequest();
    $raw = (string)$request->getPost('payload');
    $payload = [];
    if ($raw) {
        $payload = \Bitrix\Main\Web\Json::decode($raw);
    }

    $deliveryPrice = (float)$request->getPost('deliveryPrice');

    $regionId = (int)($payload['regionId'] ?? 0);
    if ($regionId <= 0) { $regionId = 266; }

    $shop       = (int)($payload['shop'] ?? 0);
    $terminalId = (int)($payload['terminalId'] ?? 0);
    $map = [ 266 => ['shop'=>6,'terminalId'=>71] ];
    if ($shop <= 0 && isset($map[$regionId]))       { $shop = (int)$map[$regionId]['shop']; }
    if ($terminalId <= 0 && isset($map[$regionId])) { $terminalId = (int)$map[$regionId]['terminalId']; }
    if ($shop <= 0)       { $shop = 6; }
    if ($terminalId <= 0) { $terminalId = 71; }

    $cardNumber    = trim((string)($payload['cardNumber'] ?? ''));
    $applyPreview  = (isset($payload['check_off_bonus']) && (int)$payload['check_off_bonus'] === 1);
    $balanceScore  = (float)($payload['balance_score'] ?? 0);
    if (preg_match('~^\d{6}$~', $cardNumber)) { $cardNumber = '0067833'.$cardNumber; }

    $siteId = \Bitrix\Main\Context::getCurrent()->getSite();
    $basket = \Bitrix\Sale\Basket::loadItemsForFUser(\Bitrix\Sale\Fuser::getId(), $siteId);
    $sumItems = (float)$basket->getPrice();

    $positions = [];
    $orderN = 1;
    foreach ($basket as $item) {
        $productId = (int)$item->getProductId();
        $q = (float)$item->getQuantity();
        $price = (float)$item->getPrice();
        $barcode = '';
        $dbProp = \CIBlockElement::GetProperty((int)$item->getField('IBLOCK_ID'), $productId, ['sort'=>'asc'], ['CODE'=>'CML2_BAR_CODE']);
        if ($arProp = $dbProp->Fetch()) { $barcode = (string)$arProp['VALUE']; }
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

    if ($sumItems <= 0 || empty($positions)) {
        echo \Bitrix\Main\Web\Json::encode([
            'ok' => true,
            'mode' => 'preview',
            'message' => 'Пустая корзина или нет штрих-кодов',
            'maxWriteoff' => 0,
            'writeoff' => 0,
            'sumItems' => 0,
            'total' => $deliveryPrice,
        ]);
        return;
    }

    $wsdl = 'http://192.168.44.8:8090/SET-ProcessingDiscount/ProcessingPurchaseWS?wsdl';
    $location = 'http://192.168.44.8:8090/SET-ProcessingDiscount/ProcessingPurchaseWS';

    $amountToWriteoff = $applyPreview ? max(0.0, (float)$balanceScore) : 0.0;

    $purchase = [
        'saletime'    => date('Y-m-d\TH:i:s'),
        'shop'        => $shop,
        'terminalId'  => $terminalId,
        'amount'      => $sumItems,
        'position'    => array_map(function($p){ return (object)$p; }, $positions),
        'paymentType' => (object)['paymentType'=>'CashPaymentEntity','externalCode'=>'CashPaymentEntity','amount'=>$sumItems],
        'discountCard'=> (object)array_filter([
            'cardNumber' => $cardNumber,
            'amountToWriteoff' => $applyPreview ? $amountToWriteoff : null,
        ], function($v){ return $v !== null; }),
    ];

    $soapPayload = ['purchase' => (object)$purchase, 'check' => true];
    $client = new \SoapClient($wsdl, ['trace'=>true,'exceptions'=>true,'location'=>$location,'cache_wsdl'=>WSDL_CACHE_NONE,'connection_timeout'=>5]);
    $resp = $client->doProcessPurchase((object)$soapPayload);
    if (!$resp || !isset($resp->return)) throw new \RuntimeException('Empty SOAP response');
    $soapReturn = $resp->return;

    // parse discounts and bonuses similar to previous implementation
    $posMap = [];
    foreach ($positions as $p) { $ord = isset($p['order']) ? (int)$p['order'] : 0; $posMap[$ord] = isset($p['goodsCode']) ? (string)$p['goodsCode'] : ''; }

    $perItem = [];
    if (!empty($soapReturn->advertise) && !empty($soapReturn->advertise->discounts)) {
        $discounts = $soapReturn->advertise->discounts; if (!is_array($discounts)) $discounts = [$discounts];
        foreach ($discounts as $d) { $pid = (int)($d->positionId ?? $d->posID ?? 0); $amount = (float)($d->amount ?? 0); if ($pid>0 && $amount!=0.0) { if (!isset($perItem[$pid])) $perItem[$pid]=[]; $perItem[$pid]['discount']=($perItem[$pid]['discount'] ?? 0) + $amount; } }
    }

    $bonusPositions = null;
    if (!empty($soapReturn->advertise->bonus->bonusPosition)) { $bonusPositions = $soapReturn->advertise->bonus->bonusPosition; }
    elseif (!empty($soapReturn->advertise->bonuses->bonusPosition)) { $bonusPositions = $soapReturn->advertise->bonuses->bonusPosition; }
    if ($bonusPositions) { if (!is_array($bonusPositions)) $bonusPositions = [$bonusPositions]; foreach ($bonusPositions as $bp) { $pid=(int)($bp->posID ?? $bp->positionId ?? 0); $val=(float)($bp->bonusValue ?? $bp->amount ?? 0); if ($pid>0 && $val!=0.0) { if (!isset($perItem[$pid])) $perItem[$pid]=[]; $perItem[$pid]['bonus']=($perItem[$pid]['bonus'] ?? 0)+$val; } } }

    $totalAccrual = 0.0; $perItemAccrual = [];
    if (!empty($bonusPositions)) { foreach ($bonusPositions as $bp) { $pid=(int)($bp->posID ?? $bp->positionId ?? 0); $val=(float)($bp->bonusValue ?? $bp->amount ?? 0); if ($pid>0 && $val>0) { $totalAccrual += $val; $perItemAccrual[]=['positionId'=>$pid,'goodsCode'=>(string)($posMap[$pid] ?? ''),'bonus'=>round($val,2)]; } } }

    $outPerItem = [];
    foreach ($perItem as $pid => $vals) {
        $outPerItem[] = ['positionId'=>(int)$pid,'goodsCode'=>(string)($posMap[$pid] ?? ''),'discount'=>isset($vals['discount'])?round((float)$vals['discount'],2):0.0,'bonus'=>isset($vals['bonus'])?round((float)$vals['bonus'],2):0.0];
    }

    $amountToWriteoffFromSet = 0.0;
    if (!empty($soapReturn->discountCard) && isset($soapReturn->discountCard->amountToWriteoff)) { $amountToWriteoffFromSet = (float)$soapReturn->discountCard->amountToWriteoff; }

    $maxByPercent = round($sumItems * 0.49, 2);
    $requested = $applyPreview ? max(0.0, $balanceScore) : 0.0;
    $writeoff = $applyPreview ? min($amountToWriteoffFromSet, $requested, $maxByPercent) : 0.0;

    $total = max($sumItems - $writeoff, 0) + $deliveryPrice;

    $out = [
        'ok'=>true,
        'mode'=>'preview',
        'message'=>'Preview calculated',
        'maxWriteoff' => round($amountToWriteoffFromSet,2),
        'writeoff' => round($writeoff,2),
        'sumItems' => $sumItems,
        'total' => round($total,2),
        'perItemDiscount' => $outPerItem,
        'accrual' => ['totalBonus'=>round($totalAccrual,2),'perItem'=>$perItemAccrual],
        'soapEcho' => ['amountToWriteoff'=>$amountToWriteoffFromSet,'shop'=>$shop,'terminalId'=>$terminalId],
    ];

    echo \Bitrix\Main\Web\Json::encode($out);

} catch (\SoapFault $f) {
    http_response_code(502);
    echo \Bitrix\Main\Web\Json::encode(['ok'=>false,'error'=>'SOAP Fault: '.$f->getMessage()]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo \Bitrix\Main\Web\Json::encode(['ok'=>false,'error'=>$e->getMessage()]);
}
