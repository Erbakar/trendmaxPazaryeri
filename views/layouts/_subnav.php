<?php
/**
 * Paylaşılan ikincil sekme şeridi (sayfa alt-navı) — entitlement + RBAC filtreli.
 *
 * layouts/main.php üst-menü filtresiyle AYNI kural: satın alınmamış (entitlement-gated)
 * modül veya yetkisiz öğe gizlenir. Böylece admin'in mağazaya tanımlamadığı modüllerin
 * sekmesi alt-navda görünmez. Görünür öğe ≤1 ise şerit hiç çizilmez (tek sekme anlamsız).
 *
 * Kullanım (view içinde, main layout):
 *   <?php $subnav = ['active' => '/returns', 'items' => [
 *       ['u' => '/orders', 'l' => 'Siparişler'],
 *       ['u' => '/returns', 'l' => 'İadeler'],
 *       ['u' => '/questions', 'l' => 'Sorular'],
 *   ]]; include BASE_PATH . '/views/layouts/_subnav.php'; ?>
 */
$canAny    = $data['_canAny']    ?? static fn(array $p): bool => true;
$canModule = $data['_canModule'] ?? static fn(string $m): bool => true;

// URL → (RBAC izinleri, entitlement modülü) — layouts/main.php $navPermissions/$navModules ve
// AuthorizationService ROUTE_PERMISSION_MAP/ROUTE_MODULE_MAP ile tutarlı.
// Modül değeri liste ise modüllerden biri yeterli (AuthorizationService ile aynı kural).
$subnavPerms = [
    '/orders'     => ['orders.view'],
    '/returns'    => ['returns.view'],
    '/questions'  => ['orders.view'],
    '/products'   => ['products.view'],
    '/categories' => ['categories.view'],
    '/categories/mapping' => ['categories.view'],
    '/brands'     => ['brands.view'],
    '/brands/mapping' => ['brands.view'],
    '/mapping/auto' => ['categories.view', 'brands.view'],
    '/price-rules' => ['products.view'],
    '/enrichment' => ['products.view'],
    '/feeds'      => [],   // main.php ile hizalı: /feeds ayrı izin istemez
    '/xml-profilleri' => ['products.view', 'products.create'],
    '/import'     => ['products.view', 'products.create'],
    '/marketplaces' => ['marketplaces.view'],
    '/sync'       => ['sync.trigger'],
    '/finance'    => ['finance.view'],
    '/invoices'   => ['invoices.view'],
];
$subnavModules = [
    '/orders'     => 'orders',
    '/returns'    => 'returns',
    '/questions'  => 'questions',
    '/products'   => 'products',
    '/categories' => 'categories',
    '/categories/mapping' => 'categories',
    '/brands'     => 'brands',
    '/brands/mapping' => 'brands',
    '/mapping/auto' => ['categories', 'brands'],
    '/price-rules' => 'products',
    '/enrichment' => 'products',
    '/feeds'      => 'feeds',
    '/xml-profilleri' => 'products',
    '/import'     => 'import',
    '/sync'       => 'sync',
    '/finance'    => 'finance',
    '/invoices'   => 'invoices',
];
// Kimlik içeren sayfalar: desen → [izinler, modül]. (Pazaryeri yetkisi sayfanın kendisinde denetlenir.)
$subnavPatterns = [
    '~^/marketplaces/\d+/listing-match$~' => [['products.view'], 'products'],
];

$subnavRule = static function (string $u) use ($subnavPerms, $subnavModules, $subnavPatterns): array {
    if (isset($subnavPerms[$u]) || isset($subnavModules[$u])) {
        return [$subnavPerms[$u] ?? [], $subnavModules[$u] ?? null];
    }
    foreach ($subnavPatterns as $pattern => $rule) {
        if (preg_match($pattern, $u) === 1) {
            return $rule;
        }
    }
    return [[], null];
};

$subnavActive = (string) ($subnav['active'] ?? '');
$subnavItems  = array_values(array_filter(
    $subnav['items'] ?? [],
    static function (array $it) use ($canAny, $canModule, $subnavRule): bool {
        [$perms, $module] = $subnavRule((string) ($it['u'] ?? ''));
        if (!$canAny($perms)) {
            return false;
        }
        if ($module === null) {
            return true;
        }
        foreach ((array) $module as $m) {
            if ($canModule((string) $m)) {
                return true;
            }
        }
        return false;
    }
));

if (count($subnavItems) > 1): ?>
<div class="flex items-center gap-1 mb-6 border-b border-slate-200/70 dark:border-white/[.06] overflow-x-auto">
  <?php foreach ($subnavItems as $it): $a = ((string) $it['u'] === $subnavActive); ?>
  <a href="<?= htmlspecialchars((string) $it['u']) ?>" class="<?= $a
        ? 'relative px-3 py-2.5 text-[13.5px] font-semibold text-slate-900 dark:text-white whitespace-nowrap'
        : 't px-3 py-2.5 text-[13.5px] font-medium text-slate-500 hover:text-slate-900 dark:hover:text-white whitespace-nowrap' ?>"><?= htmlspecialchars((string) $it['l']) ?><?php if ($a): ?><span class="absolute left-3 right-3 -bottom-px h-[2px] rounded-full bg-brand"></span><?php endif; ?></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>
