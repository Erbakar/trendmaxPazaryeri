<?php
/** AI Suite ayarları — sağlayıcı/anahtar/model/fiyat/kredi maliyeti. group='ai_suite'. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$prov = $provider ?? 'gemini';
$actionLabels = [
    'ai_content' => 'İçerik Asistanı', 'ai_bulk' => 'Toplu Doldurma', 'ai_vision' => 'Görsel Analizi',
    'ai_optimize' => 'Pazaryeri Optimizasyonu', 'ai_competitor' => 'Rakip Analizi',
    'ai_advisor' => 'Satış Danışmanı', 'ai_trends' => 'Trend Merkezi',
];
boss_page_header('AI Suite', [['l' => 'AI Suite']]);
?>
<p class="text-muted fs-2 mb-3">Sağlayıcı-bağımsız yapay zekâ. En ucuz modeli seçin; fiyatlar değişince buradan değiştirin — kodda sabit ücret yok. Google Gemini Flash genelde en ucuzdur. <a href="<?= url('/admin/ai-suite/credits') ?>">Mağaza kredi yönetimi →</a></p>

<form method="post" action="<?= url('/admin/ai-suite') ?>">
  <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">
  <div class="row g-3 align-items-start">

    <!-- ── Genel ── -->
    <div class="col-12 col-lg-6">
      <div class="card mb-3">
        <div class="card-header d-flex align-items-center justify-content-between">
          <h3 class="card-title"><i class="ti ti-robot me-1 text-primary"></i>Genel</h3>
          <label class="form-check form-switch m-0">
            <input class="form-check-input" type="checkbox" name="s[ai_enabled]" value="1" <?= !empty($enabled) ? 'checked' : '' ?>>
            <span class="form-check-label fs-3">AI Suite Etkin</span>
          </label>
        </div>
        <div class="card-body">
          <div class="mb-2"><label class="form-label">Aktif Sağlayıcı</label>
            <select name="s[ai_provider]" class="form-select">
              <option value="gemini" <?= $prov === 'gemini' ? 'selected' : '' ?>>Google Gemini (en ucuz)</option>
              <option value="openai" <?= $prov === 'openai' ? 'selected' : '' ?>>OpenAI-uyumlu (OpenAI / DeepSeek / OpenRouter…)</option>
              <option value="claude" <?= $prov === 'claude' ? 'selected' : '' ?>>Anthropic Claude</option>
            </select>
            <div class="fs-1 text-muted mt-1">OpenAI-uyumlu seçenekte base URL ile DeepSeek/OpenRouter gibi ucuz sağlayıcılar kullanılabilir.</div>
          </div>
          <div class="mb-1"><label class="form-label">OpenAI-uyumlu Base URL <span class="text-muted">(opsiyonel)</span></label>
            <input type="text" name="s[ai_openai_base_url]" value="<?= $e($baseUrl ?? '') ?>" class="form-control font-monospace" placeholder="https://api.openai.com/v1 · https://api.deepseek.com/v1">
          </div>
        </div>
      </div>
    </div>

    <!-- ── API Anahtarları ── -->
    <div class="col-12 col-lg-6">
      <div class="card mb-3">
        <div class="card-header"><h3 class="card-title"><i class="ti ti-key me-1 text-primary"></i>API Anahtarları (şifreli saklanır)</h3></div>
        <div class="card-body">
          <?php foreach (['gemini' => 'Google Gemini', 'openai' => 'OpenAI-uyumlu', 'claude' => 'Anthropic Claude'] as $pk => $pl): ?>
          <div class="mb-2"><label class="form-label"><?= $e($pl) ?> API Anahtarı</label>
            <input type="password" name="s[ai_key_<?= $pk ?>]" value="" class="form-control font-monospace" autocomplete="off"
                   placeholder="<?= !empty($hasKey[$pk]) ? $e($maskValue) . ' (kayıtlı — değiştirmek için yeni gir)' : 'anahtarı yapıştır' ?>">
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- ── Modeller ── -->
    <div class="col-12 col-lg-6">
      <div class="card mb-3">
        <div class="card-header"><h3 class="card-title"><i class="ti ti-cpu me-1 text-primary"></i>Modeller</h3></div>
        <div class="card-body">
          <div class="mb-2"><label class="form-label">Varsayılan Model</label>
            <input type="text" name="s[ai_model_default]" value="<?= $e($modelDef ?? '') ?>" class="form-control font-monospace" placeholder="gemini-2.0-flash · gpt-4o-mini · claude-haiku-4-5">
            <div class="fs-1 text-muted mt-1">Boş bırakılırsa sağlayıcının yerleşik ucuz modeli kullanılır.</div>
          </div>
          <div class="mb-2"><label class="form-label">Görsel (Vision) Model <span class="text-muted">(opsiyonel)</span></label>
            <input type="text" name="s[ai_model_vision]" value="<?= $e($modelVis ?? '') ?>" class="form-control font-monospace" placeholder="boş = varsayılan">
          </div>
          <div class="mb-1"><label class="form-label">Danışman/Analiz Model <span class="text-muted">(opsiyonel, daha güçlü)</span></label>
            <input type="text" name="s[ai_model_advisor]" value="<?= $e($modelAdv ?? '') ?>" class="form-control font-monospace" placeholder="boş = varsayılan">
          </div>
        </div>
      </div>
    </div>

    <!-- ── Kredi Maliyetleri ── -->
    <div class="col-12 col-lg-6">
      <div class="card mb-3">
        <div class="card-header"><h3 class="card-title"><i class="ti ti-coin me-1 text-primary"></i>İşlem Başı Kredi Maliyeti</h3></div>
        <div class="card-body">
          <div class="row g-2">
            <?php foreach ($actionLabels as $ak => $al): ?>
            <div class="col-6"><label class="form-label fs-2"><?= $e($al) ?></label>
              <input type="number" min="0" name="s[cost][<?= $ak ?>]" value="<?= (int) ($costs[$ak] ?? 0) ?>" class="form-control">
            </div>
            <?php endforeach; ?>
          </div>
          <div class="fs-1 text-muted mt-2">Her AI işleminin müşteriden düşeceği kredi. Token maliyetinin üzerinde tutun (marj).</div>
        </div>
      </div>
    </div>

    <!-- ── Kredi Paketi Satış Fiyatları ── -->
    <div class="col-12 col-lg-6">
      <div class="card mb-3">
        <div class="card-header"><h3 class="card-title"><i class="ti ti-shopping-cart me-1 text-primary"></i>Kredi Paketi Satış Fiyatları (₺)</h3></div>
        <div class="card-body">
          <div class="row g-2">
            <?php foreach (($packages ?? []) as $pk): ?>
            <div class="col-4">
              <label class="form-label fs-2"><?= $e(number_format((int) $pk['credits'], 0, ',', '.')) ?> kredi</label>
              <input type="number" min="0" step="0.01" name="s[credit_price][<?= $e($pk['key']) ?>]" value="<?= $e(number_format((float) $pk['price'], 2, '.', '')) ?>" class="form-control">
            </div>
            <?php endforeach; ?>
          </div>
          <div class="fs-1 text-muted mt-2">Merchant <code>/ai/credits</code> sayfasından bu fiyatlarla kredi paketi satın alır. Kredi miktarları sabittir (5.000 / 20.000 / 100.000).</div>
        </div>
      </div>
    </div>

    <!-- ── Model Fiyatları (gelişmiş) ── -->
    <div class="col-12 col-lg-6">
      <div class="card mb-3">
        <div class="card-header"><h3 class="card-title"><i class="ti ti-receipt me-1 text-primary"></i>Model Token Fiyatları (gelişmiş — marj analizi için)</h3></div>
        <div class="card-body">
          <label class="form-label">JSON: <code>{"model":{"in":USD/1M,"out":USD/1M}}</code></label>
          <textarea name="s[ai_model_prices]" rows="4" class="form-control font-monospace" placeholder='{"gemini-2.0-flash":{"in":0.10,"out":0.40}}'><?= $e($prices ?? '') ?></textarea>
          <div class="fs-1 text-muted mt-1">Boş bırakılırsa yerleşik varsayılan fiyatlar kullanılır. Yalnız <code>ai_usage_logs</code> maliyet kaydını etkiler.</div>
        </div>
      </div>
    </div>

  </div>

  <div class="mt-2 mb-4 d-flex gap-2">
    <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>Kaydet</button>
    <button type="submit" formaction="<?= url('/admin/ai-suite/test') ?>" class="btn btn-outline-secondary"><i class="ti ti-plug-connected me-1"></i>Bağlantı Testi</button>
  </div>
</form>
