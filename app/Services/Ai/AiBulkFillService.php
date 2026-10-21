<?php
/**
 * OzyConnect Panel — AiBulkFillService (Modül #2: AI Toplu Ürün Doldurma)
 *
 * Seçili ürünleri AiContentService ile (ai_bulk kredisiyle) doldurur ve kaydeder.
 * Controller, büyük seçimleri parçalara bölüp (chunk) tekrar tekrar çağırır → timeout yok,
 * canlı ilerleme. Kredi yetersizse parti durur.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\Product;

final class AiBulkFillService
{
    private const MODULE = 'ai_bulk';

    public function __construct(
        private readonly AiKernel $kernel,
        private readonly AiContentService $content,
        private readonly Product $products,
    ) {}

    public function available(): bool
    {
        return $this->kernel->available(self::MODULE, self::MODULE);
    }

    /**
     * Verilen ürün id'lerini sırayla doldur (genelde 3-5'lik chunk). Kredi biterse durur.
     * @param int[] $ids
     * @return array{results:array<int,array<string,mixed>>,stopped:bool}
     */
    public function fillBatch(array $ids): array
    {
        $results = [];
        $stopped = false;

        foreach ($ids as $rawId) {
            $id = (int) $rawId;
            $p = $this->products->find($id);
            if ($p === false) { $results[] = ['id' => $id, 'status' => 'skip', 'msg' => 'bulunamadı']; continue; }
            $name = trim((string) ($p['name'] ?? ''));
            if ($name === '') { $results[] = ['id' => $id, 'status' => 'skip', 'msg' => 'ad boş']; continue; }

            try {
                $c = $this->content->generate($name, null, null, [], ['type' => 'product', 'id' => $id], self::MODULE, self::MODULE);
                $this->products->update($id, [
                    'description'       => $c['description']       !== '' ? $c['description']       : (string) ($p['description'] ?? ''),
                    'short_description' => $c['short_description'] !== '' ? $c['short_description'] : (string) ($p['short_description'] ?? ''),
                    'seo_title'         => $c['seo_title']         !== '' ? $c['seo_title']         : (string) ($p['seo_title'] ?? ''),
                    'seo_description'   => $c['seo_description']   !== '' ? $c['seo_description']   : (string) ($p['seo_description'] ?? ''),
                    'technical_specs'   => $c['technical_specs']   !== '' ? $c['technical_specs']   : (string) ($p['technical_specs'] ?? ''),
                    'ai_generated_at'   => date('Y-m-d H:i:s'),
                ]);
                $results[] = ['id' => $id, 'status' => 'ok', 'name' => $name];
            } catch (InsufficientCreditsException) {
                $results[] = ['id' => $id, 'status' => 'nocredit', 'msg' => 'yetersiz kredi'];
                $stopped = true;
                break;
            } catch (AiNotEntitledException) {
                $results[] = ['id' => $id, 'status' => 'noentitle', 'msg' => 'modül yetkisi yok'];
                $stopped = true;
                break;
            } catch (\Throwable $e) {
                $results[] = ['id' => $id, 'status' => 'error', 'msg' => mb_substr($e->getMessage(), 0, 120)];
            }
        }

        return ['results' => $results, 'stopped' => $stopped];
    }
}
