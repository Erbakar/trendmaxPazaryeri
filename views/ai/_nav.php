<?php
/**
 * AI Suite ortak alt-nav şeridi. View içinde:
 *   <?php $aiActive = '/ai/advisor'; include BASE_PATH . '/views/ai/_nav.php'; ?>
 * _subnav.php entitlement/RBAC filtreli; /ai/* öğeleri gate'siz (hepsi görünür).
 */
$subnav = ['active' => $aiActive ?? '', 'items' => [
    ['u' => '/ai/advisor',    'l' => 'Satış Danışmanı'],
    ['u' => '/ai/trends',     'l' => 'Trend Merkezi'],
    ['u' => '/ai/competitor', 'l' => 'Rakip Analizi'],
    ['u' => '/ai/bulk',       'l' => 'Toplu Doldurma'],
    ['u' => '/ai/credits',    'l' => 'Kredi & Kullanım'],
]];
include BASE_PATH . '/views/layouts/_subnav.php';
