<?php
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);

require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    if (function_exists('check_bitrix_sessid')) {
        if (!check_bitrix_sessid()) { throw new \RuntimeException('Bad sessid'); }
    }

    if (!\Bitrix\Main\Loader::includeModule('sale')) {
        throw new \RuntimeException('Module sale required');
    }

    $request = \Bitrix\Main\Context::getCurrent()->getRequest();
    $payload = \Bitrix\Main\Web\Json::decode((string)$request->getPost('payload'));

    $cardNumber = trim((string)($payload['cardNumber'] ?? ''));
    $balanceScore = (float)($payload['balance_score'] ?? 0);
    $wantWriteoff = max(0.0, $balanceScore);
    if (preg_match('~^\d{6}$~', $cardNumber)) { $cardNumber = '0067833'.$cardNumber; }

    // --- Recalculate or validate before commit
    // For brevity, we'll call the same SOAP calculate (check=false or accept operation)
    $wsdl = 'http://192.168.44.8:8090/SET-ProcessingDiscount/ProcessingPurchaseWS?wsdl';
    $location = 'http://192.168.44.8:8090/SET-ProcessingDiscount/ProcessingPurchaseWS';

    // Build minimal purchase similar to preview (positions etc.)
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
        $positions[] = ['discountable'=>true,'cost'=>$price,'count'=>$q,'amount'=>$price*$q,'goodsCode'=>$barcode,'departNumber'=>1,'order'=>$orderN++];
    }

    if (empty($positions)) {
        throw new \RuntimeException('Empty positions for commit');
    }

    $purchase = [
        'saletime' => date('Y-m-d\TH:i:s'),
        'shop' => (int)($payload['shop'] ?? 6),
        'terminalId' => (int)($payload['terminalId'] ?? 71),
        'amount' => $sumItems,
        'position' => array_map(function($p){ return (object)$p; }, $positions),
        'discountCard' => (object)[ 'cardNumber' => $cardNumber, 'amountToWriteoff' => $wantWriteoff ],
    ];

    $soapPayload = ['purchase' => (object)$purchase, 'check' => false];
    $client = new \SoapClient($wsdl, ['trace'=>true,'exceptions'=>true,'location'=>$location,'cache_wsdl'=>WSDL_CACHE_NONE,'connection_timeout'=>5]);

    // Try to accept/redeem — this should return transaction id depending on API
    $resp = $client->doProcessPurchase((object)$soapPayload);
    if (!$resp || !isset($resp->return)) throw new \RuntimeException('Empty SOAP response (commit)');
    $soapReturn = $resp->return;

    // Expecting transaction id in response (adjust field names to your API)
    $txId = $soapReturn->transactionId ?? null;

    // Create Bitrix order here (simplified: rely on existing component flow or custom create)
    // For safety we don't duplicate full order creation logic in this endpoint; instead return success to caller
    // Caller (frontend) may run normal order submit afterwards.

    echo \Bitrix\Main\Web\Json::encode(['ok'=>true,'txId'=>$txId,'soap'=>$soapReturn]);

} catch (\SoapFault $f) {
    http_response_code(502);
    echo \Bitrix\Main\Web\Json::encode(['ok'=>false,'error'=>'SOAP Fault: '.$f->getMessage()]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo \Bitrix\Main\Web\Json::encode(['ok'=>false,'error'=>$e->getMessage()]);
}
