<?php
/**
 * OzyConnect Panel — Pazaryeri Yapılandırması
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
    'oziygo' => [
        'display_name' => 'OziyGO',
        'base_url'     => '',
        'is_active'    => true,
        'color'        => '#1967F2',
        'icon'         => 'OG',
        'api' => [
            'rate_limit_per_second' => 5,
            'batch_limit'           => 250,
            'timeout_seconds'       => 30,
            'max_retries'           => 2,
            'retry_base_delay_ms'   => 500,
            'page_size'             => 100,
            'order_history_days'    => 365,
        ],
        'status_mapping' => [
            'pending'    => 'beklemede',
            'confirmed'  => 'yeni',
            'processing' => 'hazirlaniyor',
            'shipped'    => 'kargoda',
            'delivered'  => 'teslim_edildi',
            'cancelled'  => 'iptal',
            'returned'   => 'iade',
        ],
        'endpoints' => [
            'test'                => 'test',
            'products'            => 'products',
            'product'             => 'product',
            'categories'          => 'categories',
            'brands'              => 'brands',
            'orders'              => 'orders',
            'order'               => 'order',
            'update_stock'        => 'update_stock',
            'update_price'        => 'update_price',
            'update_order_status' => 'update_order_status',
        ],
    ],

    // ─── Trendyol ──────────────────────────────────────────
    'trendyol' => [
        'display_name'  => 'Trendyol',
        'base_url'      => 'https://apigw.trendyol.com/integration',
        'is_active'     => true,
        'color'         => '#f27a1a',
        'icon'          => '🟠',

        'api' => [
            'rate_limit_per_second' => 10,
            'batch_limit'           => 1000,
            'timeout_seconds'       => 30,
            'max_retries'           => 3,
            'retry_base_delay_ms'   => 1000,
            'page_size'             => 200,
            'order_history_days'    => 30,
            'order_query_window_days' => 14,
            'return_history_days'   => 730,
            'question_history_days' => 365,
            'finance_history_days'  => 365,
        ],

        'status_mapping' => [
            'Awaiting'          => 'beklemede',
            'Created'           => 'yeni',
            'ReadyToShip'       => 'hazirlaniyor',
            'Picking'           => 'hazirlaniyor',
            'Invoiced'          => 'faturalandi',
            'Shipped'           => 'kargoda',
            'AtCollectionPoint' => 'teslim_noktasinda',
            'Delivered'         => 'teslim_edildi',
            'Cancelled'         => 'iptal',
            'UnSupplied'        => 'iptal',
            'UnDelivered'       => 'teslim_edilemedi',
            'Returned'          => 'iade',
            'UnPacked'          => 'bolundu',
            'Repack'            => 'yeniden_paketlendi',
            // Bilinmeyen statüler için fallback
            'Processing'        => 'hazirlaniyor',
            'Pending'           => 'beklemede',
        ],

        'endpoints' => [
            'orders'              => '/order/sellers/{supplierId}/orders',
            'order_detail'        => '/order/sellers/{supplierId}/orders/{orderId}',
            'shipment_packages'   => '/order/sellers/{supplierId}/shipment-packages/{shipmentPackageId}',
            'products'            => '/product/sellers/{supplierId}/products',
            'price_and_inventory' => '/inventory/sellers/{supplierId}/products/price-and-inventory',
            'categories'          => '/product/product-categories',
            'category_attributes' => '/product/product-categories/{categoryId}/attributes',
            'brands'              => '/product/brands',
            'brands_by_name'      => '/product/brands/by-name',
            'claims'              => '/order/sellers/{supplierId}/claims',
            'claim_detail'        => '/order/sellers/{supplierId}/claims/{claimId}',
            'claim_approve'       => '/order/sellers/{supplierId}/claims/{claimId}/items/approve',
            'claim_issue'         => '/order/sellers/{supplierId}/claims/{claimId}/issue',
            'claim_issue_reasons' => '/order/claim-issue-reasons',
            'common_label_create' => '/sellers/{supplierId}/common-label/{cargoTrackingNumber}',
            'common_label_get'    => '/sellers/{supplierId}/common-label/{cargoTrackingNumber}',
            'invoice_link'        => '/sellers/{supplierId}/seller-invoice-links',
            'invoice_file'        => '/sellers/{supplierId}/seller-invoice-file',
            'update_package'      => '/order/sellers/{supplierId}/shipment-packages/{shipmentPackageId}',
            'update_tracking'     => '/order/sellers/{supplierId}/shipment-packages/{shipmentPackageId}/update-tracking-number',
        ],
    ],

    // ─── Hepsiburada (Aktif — REST API) ─────────────────────────────
    //
    // environment: 'sit' = Test ortamı, 'production' = Canlı ortam
    // SIT ortamından canlıya geçerken sadece environment'ı 'production' yapın.
    // URL'lerdeki "-sit" otomatik eklenir/kaldırılır.
    //
    'hepsiburada' => [
        'display_name' => 'Hepsiburada',
        'base_url'     => 'https://mpop.hepsiburada.com',
        'is_active'    => true,
        'color'        => '#ff6000',
        'icon'         => '🟡',

        // 'sit' = Test ortamı | 'production' = Canlı ortam
        'environment' => 'production',
        'developer_username' => 'oziyweb_dev',

        // Canlı ortam URL'leri (environment=production olduğunda kullanılır)
        'base_urls_production' => [
            'catalog'  => 'https://mpop.hepsiburada.com',
            'listing'  => 'https://listing-external.hepsiburada.com',
            'order'    => 'https://oms-external.hepsiburada.com',
            'claim'    => 'https://oms-external.hepsiburada.com',
            'question' => 'https://customer-question-external.hepsiburada.com',
            'finance'  => 'https://oms-external.hepsiburada.com',
        ],

        // SIT (Test) ortamı URL'leri (environment=sit olduğunda kullanılır)
        'base_urls_sit' => [
            'catalog'  => 'https://mpop-sit.hepsiburada.com',
            'listing'  => 'https://listing-external-sit.hepsiburada.com',
            'order'    => 'https://oms-external-sit.hepsiburada.com',
            'claim'    => 'https://oms-external-sit.hepsiburada.com',
            'question' => 'https://customer-question-external-sit.hepsiburada.com',
            'finance'  => 'https://oms-external-sit.hepsiburada.com',
        ],

        // Aktif base_urls (environment'a göre otomatik seçilir, constructor'da override edilir)
        'base_urls' => [
            'catalog'  => 'https://mpop.hepsiburada.com',
            'listing'  => 'https://listing-external.hepsiburada.com',
            'order'    => 'https://oms-external.hepsiburada.com',
            'claim'    => 'https://oms-external.hepsiburada.com',
            'question' => 'https://customer-question-external.hepsiburada.com',
            'finance'  => 'https://oms-external.hepsiburada.com',
        ],

        'api' => [
            'rate_limit_per_second' => 5,
            'batch_limit'           => 4000,     // HB maksimum 4000 SKU/istek
            'timeout_seconds'       => 60,
            'max_retries'           => 3,
            'retry_base_delay_ms'   => 1000,
            'page_size'             => 100,
            'order_history_days'    => 365,
            'return_history_days'   => 365,
            'question_history_days' => 365,
            'finance_history_days'  => 365,
        ],

        'status_mapping' => [
            'Open'          => 'yeni',
            'Packaged'      => 'hazirlaniyor',
            'Shipped'       => 'kargoda',
            'InTransit'     => 'kargoda',
            'Delivered'     => 'teslim_edildi',
            'Cancelled'     => 'iptal',
            'UnDelivered'   => 'teslim_edilemedi',
            'UnPacked'      => 'bolundu',
        ],

        'endpoints' => [
            // ══════════════════════════════════════════════
            // KATALOG / ÜRÜN
            // ══════════════════════════════════════════════
            'products_all'             => '/product/api/products/all-products-of-merchant/{merchantId}',
            'product_upload'           => '/product/api/products/import',
            'product_fast_upload'      => '/product/api/products/fast-listing-product',
            'product_status'           => '/product/api/products/status/{merchantId}',
            'product_tracking'         => '/product/api/products/tracking/{trackingId}',
            'product_tracking_list'    => '/product/api/products/tracking-list',
            'product_by_status'        => '/product/api/products/{merchantId}/products/{status}',
            'product_prematch_approve' => '/product/api/products/prematch-approve',
            'product_prematch_reject'  => '/product/api/products/prematch-reject',
            'product_delete'           => '/product/api/products/{merchantId}/delete',
            'product_delete_status'    => '/product/api/products/{merchantId}/delete/{trackingId}',
            'product_update'           => '/product/api/products/ticket-import',

            // ══════════════════════════════════════════════
            // KATEGORİ & ÖZELLİKLER
            // ══════════════════════════════════════════════
            'category_list'            => '/product/api/categories/get-all-categories',
            'categories'               => '/product/api/categories/{categoryId}/attributes',
            'attribute_values'         => '/product/api/categories/{categoryId}/attributes/{attributeId}/values',

            // ══════════════════════════════════════════════
            // LİSTELEME (Fiyat / Stok / Envanter)
            // ══════════════════════════════════════════════
            'listings'                 => '/listings/merchantid/{merchantId}',
            'listing_by_sku'           => '/listings/merchantid/{merchantId}',   // ?merchantSku= veya ?hepsiburadaSku=

            // Toplu güncelleme (envanter = fiyat+stok+dispatch birlikte)
            'listing_inventory'        => '/listings/inventory-uploads',
            'listing_inventory_check'  => '/listings/inventory-uploads/{uploadId}',

            // Ayrı ayrı güncelleme
            'listing_stock'            => '/listings/merchantid/{merchantId}/stock-uploads',
            'listing_stock_check'      => '/listings/merchantid/{merchantId}/stock-uploads/{uploadId}',
            'listing_price'            => '/listings/merchantid/{merchantId}/price-uploads',
            'listing_price_check'      => '/listings/merchantid/{merchantId}/price-uploads/{uploadId}',
            'listing_delivery'         => '/listings/delivery-uploads',
            'listing_delivery_check'   => '/listings/delivery-uploads/{uploadId}',
            'listing_extra_info'       => '/listings/extra-info-uploads',
            'listing_extra_info_check' => '/listings/extra-info-uploads/{uploadId}',

            // Tekil güncelleme (tek SKU)
            'listing_single_update'    => '/listings/tekil-update',

            // Aktivasyon / Deaktivasyon / Silme
            'listing_activate'         => '/listings/merchantid/{merchantId}/sku/{sku}/activate',
            'listing_deactivate'       => '/listings/merchantid/{merchantId}/sku/{sku}/deactivate',
            'listing_delete'           => '/listings/{hbSku}',

            // Kilit kaldırma
            'listing_unlock'           => '/listings/unlock',

            // Buybox sorgulama
            'listing_buybox'           => '/listings/buybox',

            // Komisyon sorgulama
            'listing_commissions'      => '/listings/commissions',

            // ══════════════════════════════════════════════
            // SİPARİŞ
            // ══════════════════════════════════════════════
            'orders'                   => '/orders/merchantid/{merchantId}',
            'orders_cancelled'         => '/orders/merchantid/{merchantId}/cancelled',
            'orders_payment_awaiting'  => '/orders/merchantid/{merchantId}/paymentawaiting',
            'order_detail'             => '/orders/merchantid/{merchantId}/ordernumber/{orderNumber}',

            // ══════════════════════════════════════════════
            // PAKET İŞLEMLERİ
            // ══════════════════════════════════════════════
            'packages'                 => '/packages/merchantid/{merchantId}',
            'packages_shipped'         => '/packages/merchantid/{merchantId}/shipped',
            'packages_delivered'       => '/packages/merchantid/{merchantId}/delivered',
            'packages_missing_invoice' => '/packages/merchantid/{merchantId}/missing-invoice',
            'packages_unpacked'        => '/packages/merchantid/{merchantId}/status/unpacked',
            'packages_undelivered'     => '/packages/merchantid/{merchantId}/undelivered',
            'package_create'           => '/packages/merchantid/{merchantId}',
            'package_deliver'          => '/packages/merchantid/{merchantId}/packagenumber/{packageNumber}/deliver',
            'package_intransit'        => '/packages/merchantid/{merchantId}/packagenumber/{packageNumber}/intransit',
            'package_undeliver'        => '/packages/merchantid/{merchantId}/packagenumber/{packageNumber}/undeliver',
            'package_unpack'           => '/packages/merchantid/{merchantId}/packagenumber/{packageNumber}/unpack',
            'package_split'            => '/packages/merchantid/{merchantId}/packagenumber/{packageNumber}/split',
            'package_warehouse'        => '/packages/merchantid/{merchantId}/packagenumber/{packageNumber}/warehouse',
            'package_labels'           => '/packages/merchantid/{merchantId}/packagenumber/{packageNumber}/labels',
            'package_invoice'          => '/packages/merchantid/{merchantId}/packagenumber/{packageNumber}/invoice',
            'package_cargo_info'       => '/packages/merchantid/{merchantId}/packagenumber/{packageNumber}',
            'package_changeable_cargo' => '/packages/merchantid/{merchantId}/packagenumber/{packageNumber}/changablecargocompanies',
            'package_change_cargo'     => '/packages/merchantid/{merchantId}/packagenumber/{packageNumber}/changecargocompany',

            // ══════════════════════════════════════════════
            // SATIR İŞLEMLERİ (Sipariş Kalemleri)
            // ══════════════════════════════════════════════
            'cancel_line'              => '/lineitems/merchantid/{merchantId}/id/{lineId}/cancelbymerchant',
            'line_cargo_change'        => '/lineitems/merchantid/{merchantId}/orderlineid/{id}/cargocompany',
            'line_labor_cost'          => '/lineitems/merchantid/{merchantId}/orderlineid/{id}/laborcost',
            'packageable_items'        => '/lineitems/merchantid/{merchantId}/packageablewith/lineitemid/{lineItemId}',
            'line_changeable_cargo'    => '/delivery/changeablecargocompanies/merchantid/{merchantId}/orderlineid/{id}',

            // ══════════════════════════════════════════════
            // TALEP (İADE)
            // ══════════════════════════════════════════════
            'claims'                   => '/claims/merchantid/{merchantId}',
            'claims_by_status'         => '/claims/merchantid/{merchantId}/status/{status}',
            'claim_accept'             => '/claims/number/{claimNumber}/accept',
            'claim_reject'             => '/claims/number/{claimNumber}/reject',

            // ══════════════════════════════════════════════
            // SATICIYA SOR (Soru / Cevap)
            // ══════════════════════════════════════════════
            'questions'                => '/merchantid/{merchantId}/questions',
            'question_detail'          => '/merchantid/{merchantId}/questions/{questionId}',
            'question_answer'          => '/merchantid/{merchantId}/questions/{questionId}/answers',
            'question_report'          => '/merchantid/{merchantId}/questions/{questionId}/report',
            'question_counts'          => '/merchantid/{merchantId}/questions/counts',

            // ══════════════════════════════════════════════
            // MUHASEBE (FİNANS)
            // ══════════════════════════════════════════════
            'transactions'             => '/transactions/merchantid/{merchantId}',

            // ══════════════════════════════════════════════
            // PROFİL / KARGO FİRMALARI / TESLİMAT PROFİLİ
            // ══════════════════════════════════════════════
            'cargo_firms'              => '/cargofirms/{merchantId}',
            'profile_create'           => '/profile/createByMerchantId',
            'profile_update'           => '/profile/updateByMerchantId',
            'profile_list'             => '/profiles/{merchantId}',
            'shipping_profiles'        => '/listings/shipping-profiles',

            // ══════════════════════════════════════════════
            // TEST (SIT ortamı)
            // ══════════════════════════════════════════════
            'test_order_create'        => '/orders/merchantid/{merchantId}',
            'test_claim_create'        => '/claims/merchantid/{merchantId}',
        ],
    ],

    // ─── n11 (Aktif — REST API) ──────────────────────────
    'n11' => [
        'display_name' => 'n11',
        'base_url'     => 'https://api.n11.com',
        'is_active'    => true,
        'color'        => '#7b2d8b',
        'icon'         => '🟣',

        'api' => [
            'rate_limit_per_second' => 5,
            'batch_limit'           => 1000,
            'timeout_seconds'       => 30,
            'max_retries'           => 3,
            'retry_base_delay_ms'   => 1000,
            'page_size'             => 100,
            'order_history_days'    => 365,
            'return_history_days'   => 365,
        ],

        'status_mapping' => [
            'Created'    => 'yeni',
            'Picking'    => 'hazirlaniyor',
            'Shipped'    => 'kargoda',
            'Delivered'  => 'teslim_edildi',
            'Cancelled'  => 'iptal',
            'UnPacked'   => 'bolundu',
            'UnSupplied' => 'iptal',
        ],

        'endpoints' => [
            'orders'              => '/rest/delivery/v1/shipmentPackages',
            'order_update'        => '/rest/order/v1/update',
            'split_packages'      => '/rest/delivery/v1/splitCombinePackage',
            'split_by_qty'        => '/rest/delivery/v1/splitPackageByQuantity',
            'labor_costs'         => '/rest/order/v1/labor-costs',
            'product_create'      => '/ms/product/tasks/product-create',
            'price_stock_update'  => '/ms/product/tasks/price-stock-update',
            'product_update'      => '/ms/product/tasks/product-update',
            'task_details'        => '/ms/product/task-details/page-query',
            'product_query'       => '/ms/product-query',
            'categories'          => '/cdn/categories',
            'category_attributes' => '/cdn/category/{categoryId}/attribute',
        ],
    ],

    // ─── Amazon TR (SP-API — OAuth2 / LWA) ────────────────
    'amazon' => [
        'display_name' => 'Amazon TR',
        'base_url'     => 'https://sellingpartnerapi-eu.amazon.com',
        'token_url'    => 'https://api.amazon.com/auth/o2/token',
        'auth_url'     => 'https://sellercentral.amazon.com.tr/apps/authorize/consent',
        'is_active'    => true,
        'color'        => '#ff9900',
        'icon'         => '🟡',

        'marketplace_id' => 'A33AVAJ2PDY3EV', // amazon.com.tr

        'api' => [
            'rate_limit_per_second' => 2,
            'batch_limit'           => 20,
            'timeout_seconds'       => 30,
            'max_retries'           => 3,
            'retry_base_delay_ms'   => 1000,
            'page_size'             => 100,
            'order_history_days'    => 365,
            'return_history_days'   => 365,
        ],

        'status_mapping' => [
            'Pending'             => 'beklemede',
            'Unshipped'           => 'yeni',
            'PartiallyShipped'    => 'kargoda',
            'Shipped'             => 'kargoda',
            'InvoiceUnconfirmed'  => 'faturalandi',
            'Canceled'            => 'iptal',
            'Unfulfillable'       => 'iptal',
            'PendingAvailability' => 'beklemede',
        ],

        'endpoints' => [
            // Siparişler (Orders API v0)
            'orders'              => '/orders/v0/orders',
            'order_detail'        => '/orders/v0/orders/{orderId}',
            'order_items'         => '/orders/v0/orders/{orderId}/orderItems',
            'order_confirm_ship'  => '/orders/v0/orders/{orderId}/shipmentConfirmation',

            // Ürünler (Catalog Items API v2022-04-01)
            'catalog_item'        => '/catalog/2022-04-01/items/{asin}',
            'catalog_search'      => '/catalog/2022-04-01/items',

            // Listings (Listings Items API v2021-08-01)
            'listing_get'         => '/listings/2021-08-01/items/{sellerId}/{sku}',
            'listing_put'         => '/listings/2021-08-01/items/{sellerId}/{sku}',
            'listing_patch'       => '/listings/2021-08-01/items/{sellerId}/{sku}',
            'listing_delete'      => '/listings/2021-08-01/items/{sellerId}/{sku}',
            'listing_search'      => '/listings/2021-08-01/items/{sellerId}',

            // Fiyat (Product Pricing API v0)
            'pricing'             => '/products/pricing/v0/price',
            'competitive_pricing' => '/products/pricing/v0/competitivePrice',

            // Stok (FBA Inventory API v1)
            'inventory_summaries' => '/fba/inventory/v1/summaries',

            // Raporlar (Reports API v2021-06-30)
            'reports'             => '/reports/2021-06-30/reports',
            'report_detail'       => '/reports/2021-06-30/reports/{reportId}',
            'report_document'     => '/reports/2021-06-30/documents/{reportDocumentId}',

            // Feed'ler (Feeds API v2021-06-30 — fiyat/stok güncelleme)
            'feeds'               => '/feeds/2021-06-30/feeds',
            'feed_document'       => '/feeds/2021-06-30/documents',
            'feed_detail'         => '/feeds/2021-06-30/feeds/{feedId}',
            'feed_result_doc'     => '/feeds/2021-06-30/documents/{feedDocumentId}',

            // Finans (Finances API v2024-06-19)
            'transactions'        => '/finances/2024-06-19/transactions',

            // Bildirimler
            'notifications'       => '/notifications/v1/subscriptions',

            // Seller bilgisi
            'sellers'             => '/sellers/v1/marketplaceParticipations',

            // Kategori / Ürün tipi (Product Type Definitions v2020-09-01)
            'product_types'       => '/definitions/2020-09-01/productTypes',
            'product_type_def'    => '/definitions/2020-09-01/productTypes/{productType}',

            // Tokens (Restricted Data Token)
            'tokens'              => '/tokens/2021-03-01/restrictedDataToken',
        ],
    ],

    // ─── Pazarama (Aktif — OAuth2 API) ──────────────────────
    'pazarama' => [
        'display_name' => 'Pazarama',
        'base_url'     => 'https://isortagimapi.pazarama.com',
        'token_url'    => 'https://isortagimgiris.pazarama.com/connect/token',
        'is_active'    => true,
        'color'        => '#00b0ff',
        'icon'         => '🔵',

        'api' => [
            'rate_limit_per_second' => 1,
            'batch_limit'           => 100,
            'stock_batch_limit'     => 3000,
            'price_batch_limit'     => 3000,
            'timeout_seconds'       => 30,
            'max_retries'           => 3,
            'retry_base_delay_ms'   => 1000,
            'page_size'             => 500,
            'order_history_days'    => 365,
            'order_query_window_days' => 29,
            'return_history_days'   => 365,
            'question_history_days' => 365,
        ],

        'status_mapping' => [
            3  => 'yeni',              // Sipariş Alındı
            5  => 'kargoda',           // Kargoya Verildi
            6  => 'iptal',             // İptal
            7  => 'iade',              // İade Başlatıldı
            8  => 'iade',              // İade Onaylandı
            9  => 'iade',              // İade Reddedildi
            10 => 'iade',              // İade Edildi
            11 => 'teslim_edildi',     // Teslim Edildi
            12 => 'hazirlaniyor',      // Hazırlanıyor
            13 => 'iptal',             // Tedarik Edilemedi
            14 => 'teslim_edilemedi',  // Teslim Edilemedi
            16 => 'teslim_noktasinda', // Mağazada
            18 => 'iptal',             // İptal Süreci
            19 => 'teslim_noktasinda', // Teslimat Noktasında
        ],

        'endpoints' => [
            // Ürün
            'product_create'        => '/product/api/product',
            'product_batch_status'  => '/product/api/product/{batchId}',
            'price_update'          => '/product/updatePrice-v2',
            'stock_update'          => '/product/updateStock-v2',
            'price_data_status'     => '/product/getDataIdForPriceOrStock/{dataId}',
            'products'              => '/product/products',
            'product_detail'        => '/product/getProductDetail',
            'product_commercial'    => '/product/getProductCommercialInfo',
            'product_status_open'   => '/product/openProduct',
            'product_status_close'  => '/product/closeProducts',
            'product_status_bulk'   => '/product/api/product/status',
            'product_status_result' => '/product/api/product/status/{batchId}',
            'vat_update'            => '/product/api/product/vat',
            'catalog_search'        => '/product/catalogSearch',
            'quick_product_add'     => '/product/quickProductAdd',
            'delivery_types'        => '/product/getDeliveryTypes',

            // Kategori & Marka
            'categories'            => '/category/getCategoryTree',
            'category_attributes'   => '/category/getCategoryWithAttributes',
            'brands'                => '/brand/getBrands',

            // Sipariş
            'orders'                => '/order/getOrdersForApi',
            'order_update'          => '/order/updateOrderStatus',
            'order_update_list'     => '/order/updateOrderStatusList',
            'order_cargo_tracking'  => '/order/updateCargoTracking',
            'order_split'           => '/order/splitOrderForRefund',
            'order_package'         => '/order/getPackage',
            'order_labor_cost'      => '/order/laborCost',

            // İade
            'refunds'               => '/order/getRefund',
            'refund_update'         => '/order/updateRefund',
            'refund_revision'       => '/order/refundRevision',
            'refund_review'         => '/order/reviewSend',

            // İptal
            'cancel_management'     => '/order/getCancellationManagement',
            'cancel_query'          => '/order/cancellationQuery',

            // Fatura
            'invoice_link'          => '/order/invoiceLink',
            'invoice_item'          => '/order/invoiceItem',

            // Finans
            'finance_accounting'    => '/finance/getReport',

            // Soru & Cevap
            'questions'             => '/qAndA/getQandA',
            'question_answer'       => '/qAndA/answerQuestion',
        ],
    ],

    // ─── PTTAVM (Aktif — REST API) ──────────────────────
    'pttavm' => [
        'display_name' => 'PttAVM',
        'base_url'     => 'https://integration-api.pttavm.com',
        'is_active'    => true,
        'color'        => '#ffc107',
        'icon'         => '📮',
        'integrator_name' => 'OzyConnect',
        'api_key'      => ($_ENV['PTTAVM_API_KEY'] ?? getenv('PTTAVM_API_KEY') ?: ''),

        'api' => [
            'rate_limit_per_second' => 2,
            'batch_limit'           => 1000,
            'timeout_seconds'       => 60,
            'max_retries'           => 3,
            'retry_base_delay_ms'   => 2000,
            'page_size'             => 100,
            'order_history_days'    => 365,
            'order_query_window_days' => 39,
            'return_history_days'   => 365,
        ],

        'status_mapping' => [
            'kargo_yapilmasi_bekleniyor' => 'yeni',
            'havale_onayi_bekleniyor'    => 'beklemede',
            'onay_surecinde'             => 'beklemede',
            'gondericisine_teslim_edildi' => 'kargoda',
            'gonderilmis'                => 'kargoda',
            'gonderilmiş'                => 'kargoda',
            'tamamlandi'                 => 'teslim_edildi',
            'iptal'                      => 'iptal',
            'iade'                       => 'iade',
            'odeme_gecersiz'             => 'iptal',
        ],
    ],

    // ─── ÇiçekSepeti (Aktif — REST API) ───────────────────
    'ciceksepeti' => [
        'display_name' => 'ÇiçekSepeti',
        'base_url'     => 'https://apis.ciceksepeti.com/api/v1',
        'is_active'    => true,
        'color'        => '#e91e63',
        'icon'         => '🌸',

        'api' => [
            'rate_limit_per_second' => 1,
            'batch_limit'           => 100,
            'timeout_seconds'       => 30,
            'max_retries'           => 3,
            'retry_base_delay_ms'   => 5000,
            'page_size'             => 100,
            'order_history_days'    => 365,
            'order_query_window_days' => 14,
            'return_history_days'   => 365,
        ],

        'status_mapping' => [
            1  => 'yeni',              // Yeni Sipariş
            2  => 'hazirlaniyor',      // Hazırlanıyor
            5  => 'kargoda',           // Kargoya Verildi
            7  => 'teslim_edildi',     // Teslim Edildi
            11 => 'hazirlaniyor',      // Kargoya Verilecek
        ],

        'endpoints' => [
            'products'            => '/Products',
            'price_and_stock'     => '/Products/price-and-stock',
            'batch_status'        => '/Products/batch-status/{batchId}',
            'orders'              => '/Order/GetOrders',
            'invoice'             => '/Branch/SendInvoiceMail',
            'categories'          => '/Category',
            'cargo_own'           => '/Cargo/SendWithOwnCargo',
            'cargo_ciceksepeti'   => '/Cargo/SendWithCiceksepeti',
            'canceled_orders'     => '/CanceledOrder/GetCanceledOrders',
            'canceled_approve'    => '/CanceledOrder/ApproveOrRejectCanceledOrder',
        ],
    ],

    // ─── İdefix (Aktif — REST API) ──────────────────────
    'idefix' => [
        'display_name' => 'İdefix',
        'base_url'     => 'https://merchantapi.idefix.com',
        'is_active'    => true,
        'color'        => '#1a1a2e',
        'icon'         => '📚',

        'base_urls' => [
            'pim' => 'https://merchantapi.idefix.com/pim',
            'oms' => 'https://merchantapi.idefix.com/oms',
        ],

        'api' => [
            'rate_limit_per_second' => 2,
            'batch_limit'           => 100,
            'timeout_seconds'       => 30,
            'max_retries'           => 3,
            'retry_base_delay_ms'   => 2000,
            'page_size'             => 20,
            'order_history_days'    => 365,
            'return_history_days'   => 365,
            'question_history_days' => 365,
        ],

        'status_mapping' => [
            'created'              => 'yeni',
            'shipment_ready'       => 'yeni',
            'shipment_picking'     => 'hazirlaniyor',
            'shipment_invoiced'    => 'faturalandi',
            'shipment_in_cargo'    => 'kargoda',
            'shipment_delivered'   => 'teslim_edildi',
            'shipment_undeliver'   => 'teslim_edilemedi',
            'shipment_cancelled'   => 'iptal',
            'shipment_unsupplied'  => 'iptal',
            'shipment_split'       => 'bolundu',
            'shipment_approved'    => 'teslim_edildi',
        ],

        'endpoints' => [
            // ── PIM (Ürün) ──
            'categories'            => '/pim/product-category',
            'category_attributes'   => '/pim/product-category/{categoryId}/attribute',
            'brands'                => '/pim/brand',
            'brand_search'          => '/pim/brand/search/{name}',
            'brand_by_id'           => '/pim/brand/{brandId}',
            'products'              => '/pim/pool/{vendorId}/list',
            'product_create'        => '/pim/catalog/{vendorId}/product-create',
            'product_batch_status'  => '/pim/catalog/{vendorId}/batch-result/{batchRequestId}',
            'product_fast_add'      => '/pim/catalog/{vendorId}/fast-product-add',
            'product_fast_status'   => '/pim/catalog/{vendorId}/fast-product-result/{batchRequestId}',
            'inventory_upload'      => '/pim/catalog/{vendorId}/inventory-upload',
            'inventory_result'      => '/pim/catalog/{vendorId}/inventory-result/{batchRequestId}',
            'inventory_current'     => '/pim/catalog/{vendorId}/inventory-current',
            'product_approve'       => '/pim/catalog/{vendorId}/merchant-approve',
            'product_reject'        => '/pim/catalog/{vendorId}/merchant-reject',
            'cargo_companies'       => '/pim/cargo-company',
            'cargo_profiles'        => '/pim/cargo-profile',
            'addresses'             => '/pim/address',

            // ── OMS (Sipariş) ──
            'orders'                => '/oms/{vendorId}/list',
            'order_status'          => '/oms/{vendorId}/{shipmentId}/update-shipment-status',
            'order_tracking'        => '/oms/{vendorId}/{shipmentId}/update-tracking-number',
            'order_alt_tracking'    => '/oms/{vendorId}/{shipmentId}/alternative-tracking-number',
            'order_delivered'       => '/oms/{vendorId}/{shipmentId}/delivered',
            'order_split'           => '/oms/{vendorId}/{shipmentId}/split',
            'order_unsupply'        => '/oms/{vendorId}/{shipmentId}/noship',
            'order_unsupply_reasons'=> '/oms/{vendorId}/noship-reasons',
            'order_invoice_link'    => '/oms/{vendorId}/{shipmentId}/invoice-link',
            'order_desi'            => '/oms/{vendorId}/{shipmentId}/desi',
            'order_cargo_change'    => '/oms/{vendorId}/{shipmentId}/change-cargo-provider',
            'order_labor_cost'      => '/oms/{vendorId}/{shipmentId}/labor-cost',

            // ── İade ──
            'claims'                => '/oms/{vendorId}/claim-list',
            'claim_reasons'         => '/oms/{vendorId}/claim-request-reasons',
            'claim_approve'         => '/oms/{vendorId}/{claimId}/approve-claim',
            'claim_reject'          => '/oms/{vendorId}/{claimId}/reject-claim',
            'claim_reject_reasons'  => '/oms/{vendorId}/claim-reject-reasons',
            'claim_delivered'       => '/oms/{vendorId}/{claimId}/claim-delivered',
            'claim_create'          => '/oms/{vendorId}/{shipmentId}/create-claim',
            'cancel_reasons'        => '/oms/{vendorId}/cancel-reasons',

            // ── Soru & Cevap ──
            'questions'             => '/oms/{vendorId}/questions',
            'product_questions'     => '/oms/{vendorId}/product-questions',
            'question_answer'       => '/oms/{vendorId}/questions/{questionId}/answer',
        ],
    ],
];
