<?php
/**
 * OzyConnect Panel — E-Fatura/E-Arşiv Yapılandırması
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2025 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 * @version    1.0.0
 * @since      2025-01-01
 * @link       https://www.oziyweb.com.tr
 */
declare(strict_types=1);

return [
    // ─── Fatura Genel ──────────────────────────────────────
    'series'          => env('INVOICE_SERIES', 'OMR'),
    'start_no'        => (int) env('INVOICE_START_NO', 1),
    'auto_create'     => (bool) env('INVOICE_AUTO_CREATE', false),
    'currency'        => 'TRY',

    // ─── UBL-TR Standart ───────────────────────────────────
    'ubl_version'       => '2.1',
    'customization_id'  => 'TR1.2',
    'profile_efatura'   => 'TICARIFATURA',
    'profile_earsiv'    => 'EARSIVFATURA',

    // ─── KDV Oranları (Türkiye 2024) ──────────────────────
    'tax_rates' => [
        20 => 'Standart KDV (%20)',
        10 => 'İndirimli KDV (%10)',
        1  => 'Temel Gıda KDV (%1)',
    ],

    // ─── Provider (şimdilik pasif) ─────────────────────────
    'provider'        => env('INVOICE_PROVIDER', 'none'),  // none, logo, parasut, edm, izibiz
    'provider_config' => [
        'logo' => [
            'api_url'  => '',
            'username' => '',
            'password' => '',
        ],
        'parasut' => [
            'client_id'     => '',
            'client_secret' => '',
            'company_id'    => '',
        ],
        'edm' => [
            'api_url'  => '',
            'username' => '',
            'password' => '',
        ],
        'izibiz' => [
            'api_url'  => '',
            'username' => '',
            'password' => '',
        ],
    ],

    // ─── Satıcı Bilgileri (settings tablosundan da okunur) ─
    'seller' => [
        'company_name'   => '',
        'tax_no'         => '',
        'tax_office'     => '',
        'mersis_no'      => '',
        'address'        => '',
        'city'           => '',
        'district'       => '',
        'country'        => 'Türkiye',
        'country_code'   => 'TR',
        'phone'          => '',
        'email'          => '',
    ],
];
