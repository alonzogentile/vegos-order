<?php
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);

require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Web\Json;

header('Content-Type: application/json; charset=UTF-8');

try {
    if (!check_bitrix_sessid()) { throw new \RuntimeException('Bad sessid'); }

    global $USER;
    if (!$USER->IsAuthorized()) {
        throw new \RuntimeException('Auth required');
    }

    // Подтягиваем готовый код, который формирует $ABOUT_BONUS_USER
    $bonusFile = $_SERVER['DOCUMENT_ROOT'].'/local/php/card/bonus.php';
    if (!file_exists($bonusFile)) {
        throw new \RuntimeException('bonus.php not found: /local/php/card/bonus.php');
    }
    require_once $bonusFile;

    if (empty($ABOUT_BONUS_USER)) {
        echo Json::encode(['ok' => true, 'cards' => []]);
        return;
    }

    // build map of account balances by category/id (if provided)
    $balances = [];
    if (!empty($ABOUT_BONUS_USER['accounts']) && is_array($ABOUT_BONUS_USER['accounts'])) {
        foreach ($ABOUT_BONUS_USER['accounts'] as $acc) {
            // try several keys to be robust
            $accKey = null;
            if (!empty($acc['accountCategory']['id'])) $accKey = (int)$acc['accountCategory']['id'];
            elseif (!empty($acc['id'])) $accKey = (int)$acc['id'];
            if ($accKey !== null) {
                $balances[$accKey] = isset($acc['activeBalance']) ? ((float)$acc['activeBalance'] / 100.0) : 0.0;
            }
        }
    }

    $cards = [];
    if (!empty($ABOUT_BONUS_USER['cards']) && is_array($ABOUT_BONUS_USER['cards'])) {
        foreach ($ABOUT_BONUS_USER['cards'] as $card) {
            if (($card['status'] ?? '') === 'BLOCKED') { continue; }
            $num = (string)($card['number'] ?? '');
            if ($num === '') { continue; }

            $mask = preg_replace('~(\d{4})(\d{3}).*(\d{3})$~', '$1 $2***$3', $num);

            // find associated account/category id
            $accId = null;
            if (!empty($card['category']['id'])) $accId = (int)$card['category']['id'];
            elseif (!empty($card['accountCategory']['id'])) $accId = (int)$card['accountCategory']['id'];

            $balance = 0.0;
            if ($accId !== null && isset($balances[$accId])) {
                $balance = $balances[$accId];
            }

            $cards[] = [
                'cardNumber' => $num,
                'mask'       => $mask ?: $num,
                'holder'     => $card['category']['name'] ?? 'Карта лояльности',
                'balance'    => round($balance, 2),
            ];
        }
    }

    echo Json::encode(['ok' => true, 'cards' => $cards]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo Json::encode(['ok' => false, 'error' => $e->getMessage()]);
}
