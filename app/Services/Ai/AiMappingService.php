<?php
/**
 * OzyConnect Panel — AiMappingService
 *
 * Bulanık eşleştirmenin çözemediği kategori/markaları yapay zekâ ile eşleştirir.
 *
 * Maliyet disiplini: YZ TEK TEK değil, PARTİ halinde çağrılır (20 kayıt/çağrı).
 * 400 eşleşmemiş kategori → 20 çağrı ≈ 60 kredi; tek tek çağrılsa 1.200 kredi olurdu.
 *
 * Güvenlik: YZ serbest ID üretemez — yalnız KENDİSİNE VERİLEN adaylardan seçer.
 * Dönen her seçim, adaylar arasında mı diye yeniden doğrulanır (AutoMappingService).
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2026 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 * @version    1.0.0
 * @since      2026-08-26
 * @link       https://www.oziyweb.com.tr
 */
declare(strict_types=1);

namespace App\Services\Ai;

use App\Core\Logger;

final class AiMappingService
{
    /**
     * Mevcut ve satılan modül kullanılır; yeni bir yetki anahtarı açmak
     * mağazaların bugün bu özelliği kullanamaması demek olurdu.
     */
    private const MODULE = 'ai_bulk';
    private const ACTION = 'ai_bulk';

    /** Parti başına kayıt (istek boyutu ve kredi maliyeti dengesi) */
    public const BATCH_SIZE = 20;

    public function __construct(
        private readonly AiKernel $kernel,
        private readonly Logger $logger,
    ) {}

    public function available(): bool
    {
        try {
            return $this->kernel->available(self::MODULE, self::ACTION);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Kullanılamıyorsa gerçek sebep (sağlayıcı yok / modül yok / kredi yok).
     */
    public function unavailableReason(): ?string
    {
        try {
            return $this->kernel->unavailableReason(self::MODULE, self::ACTION);
        } catch (\Throwable $e) {
            return 'Yapay zekâ durumu okunamadı: ' . $e->getMessage();
        }
    }

    public function creditBalance(): int
    {
        try {
            return $this->kernel->creditBalance();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Bir partiyi eşleştir.
     *
     * @param array<int, array<string, mixed>> $batch AutoMappingService önerileri
     * @param string $kind category|brand
     * @return array<int, array{internal_id: int, mp_id: string, score: int}>
     */
    public function match(array $batch, string $kind, int $marketplaceId): array
    {
        if ($batch === []) {
            return [];
        }

        $tur = $kind === 'brand' ? 'marka' : 'kategori';

        $girdi = [];
        foreach (array_slice($batch, 0, self::BATCH_SIZE) as $oneri) {
            $adaylar = [];
            foreach ($oneri['candidates'] as $aday) {
                $adaylar[] = [
                    'id' => (string) $aday['id'],
                    'p'  => mb_substr((string) ($aday['path'] ?? $aday['name']), 0, 120),
                ];
            }
            if ($adaylar === []) {
                continue;
            }

            $girdi[] = [
                'i' => (int) $oneri['internal_id'],
                'n' => mb_substr((string) $oneri['internal_name'], 0, 120),
                'p' => mb_substr((string) ($oneri['internal_path'] ?? ''), 0, 160),
                'c' => $adaylar,
            ];
        }

        if ($girdi === []) {
            return [];
        }

        $system = "Sen bir e-ticaret {$tur} eşleştirme uzmanısın. "
            . "Sana mağazanın kendi {$tur}leri ve her biri için pazaryerinden ADAY listesi verilecek. "
            . "Her kayıt için EN UYGUN adayı seç. "
            . "SADECE verilen adaylardan seç — listede olmayan bir id ASLA üretme. "
            . "Uygun aday yoksa id yerine null yaz. "
            . 'Yalnızca GEÇERLİ JSON dizisi döndür; kod bloğu, açıklama veya başka metin ekleme.';

        $user = "Girdi:\n" . json_encode($girdi, JSON_UNESCAPED_UNICODE)
            . "\n\nÇıktı şeması (dizi):\n"
            . '[{"i": <girdideki i>, "id": "<seçilen aday id ya da null>", "conf": <0-100 güven>}]';

        try {
            $sonuc = $this->kernel->execute(
                self::MODULE,
                self::ACTION,
                fn(AiClient $ai) => $ai->chat(
                    $system,
                    [['role' => 'user', 'content' => $user]],
                    ['max_tokens' => 1200, 'json' => true, 'temperature' => 0.2],
                    'default'
                ),
                ['type' => $kind . '_mapping', 'id' => $marketplaceId]
            );
        } catch (InsufficientCreditsException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->warning('YZ eşleştirme hatası: ' . $e->getMessage(), 'AiMapping');
            throw $e;
        }

        return $this->parse($sonuc->json());
    }

    /**
     * YZ yanıtını normalize et. Model bazen {"results":[...]} sarmalı döndürür.
     *
     * @param array<string, mixed>|null $json
     * @return array<int, array{internal_id: int, mp_id: string, score: int}>
     */
    private function parse(?array $json): array
    {
        if ($json === null) {
            return [];
        }

        // Düz dizi mi, sarmalı mı?
        $liste = $json;
        if (!array_is_list($json)) {
            foreach (['results', 'items', 'data', 'matches'] as $anahtar) {
                if (isset($json[$anahtar]) && is_array($json[$anahtar])) {
                    $liste = $json[$anahtar];
                    break;
                }
            }
        }

        if (!is_array($liste) || !array_is_list($liste)) {
            return [];
        }

        $kararlar = [];
        foreach ($liste as $satir) {
            if (!is_array($satir)) {
                continue;
            }

            $ic = (int) ($satir['i'] ?? $satir['internal_id'] ?? 0);
            $mp = $satir['id'] ?? $satir['mp_id'] ?? null;

            if ($ic <= 0 || $mp === null || $mp === '' || $mp === 'null') {
                continue;
            }

            $kararlar[] = [
                'internal_id' => $ic,
                'mp_id'       => (string) $mp,
                'score'       => max(0, min(100, (int) ($satir['conf'] ?? $satir['score'] ?? 70))),
            ];
        }

        return $kararlar;
    }
}
