<?php
declare(strict_types=1);

use App\Models\CategoryMarketplaceMapping;
use App\Services\Catalog\{AttributeInference,AttributeInferenceService};
use PHPUnit\Framework\TestCase;

final class AttributeInferenceTest extends TestCase
{
    private function colour(): array
    {
        return ['id' => 348, 'name' => 'Web Color', 'required' => true, 'values' => [
            ['id' => 1, 'name' => 'Kırmızı'], ['id' => 2, 'name' => 'Mavi'], ['id' => 3, 'name' => 'Koyu Mavi'],
            ['id' => 4, 'name' => 'Çok Renkli'], ['id' => 5, 'name' => 'Belirtilmemiş']]];
    }

    public function testOptionNamedInProductNameIsTaken(): void
    {
        $hit = AttributeInference::suggest($this->colour(), ['Uzaktan Kumandalı KIRMIZI Araba'], false);
        $this->assertSame(['value' => '1', 'option' => 'Kırmızı', 'basis' => 'text'], $hit);
    }

    public function testLongerPhraseWinsOverItsPart(): void
    {
        $this->assertSame('3', AttributeInference::suggest($this->colour(), ['Koyu Mavi Kovalı Set'], false)['value']);
    }

    public function testPartOfAWordIsNotAMatch(): void
    {
        // "Mavili" and "Kırmızıbiber" are other words; nothing is guessed.
        $this->assertNull(AttributeInference::suggest($this->colour(), ['Mavili Kırmızıbiber Oyuncağı'], false));
    }

    public function testSeveralColoursBecomeTheMixedOptionNeverOneOfThem(): void
    {
        $hit = AttributeInference::suggest($this->colour(), ['Kırmızı Mavi Top'], false);
        $this->assertSame(['value' => '4', 'option' => 'Çok Renkli', 'basis' => 'text_multi'], $hit);
    }

    public function testSilentNameIsSkippedUnlessNeutralIsAllowedAndThenSaysUnknown(): void
    {
        $this->assertNull(AttributeInference::suggest($this->colour(), ['Ahşap Yapboz 24 Parça'], false));
        $hit = AttributeInference::suggest($this->colour(), ['Ahşap Yapboz 24 Parça'], true);
        $this->assertSame(['value' => '5', 'option' => 'Belirtilmemiş', 'basis' => 'neutral'], $hit);
    }

    public function testNoNeutralOptionMeansNoValue(): void
    {
        $origin = ['id' => 9, 'name' => 'Menşei', 'values' => [['id' => 1, 'name' => 'TR'], ['id' => 2, 'name' => 'CN']]];
        $this->assertNull(AttributeInference::suggest($origin, ['Ahşap Yapboz'], true));
    }

    public function testBareNumbersShortCodesAndGenericWordsAreNeverMatched(): void
    {
        $age = ['id' => 7, 'name' => 'Yaş', 'values' => [['id' => 1, 'name' => '12'], ['id' => 2, 'name' => 'M'], ['id' => 3, 'name' => 'Var'], ['id' => 4, 'name' => '3 Yaş']]];
        $this->assertNull(AttributeInference::suggest($age, ['12 Parça M Beden Var'], false));
        $this->assertSame('4', AttributeInference::suggest($age, ['3 Yaş Üzeri 12 Parça'], false)['value']);
    }

    public function testUnmatchedXmlValueIsReadBeforeTheName(): void
    {
        $hit = AttributeInference::suggest($this->colour(), ['Lacivert-Kırmızı', 'Mavi Kamyon'], false);
        $this->assertSame('1', $hit['value']);
    }

    public function testFreeTextColourTakesTheColourWordAndOtherFreeTextIsLeftAlone(): void
    {
        $renk = ['id' => 47, 'name' => 'Renk', 'allowCustom' => true];
        $this->assertSame('Yeşil', AttributeInference::suggest($renk, ['YEŞİL Traktör'], false)['value']);
        $this->assertSame('Çok Renkli', AttributeInference::suggest($renk, ['Sarı Siyah Arı'], false)['value']);
        $this->assertNull(AttributeInference::suggest($renk, ['Traktör'], false));
        $this->assertSame('Belirtilmemiş', AttributeInference::suggest($renk, ['Traktör'], true)['value']);
        $this->assertNull(AttributeInference::suggest(['id' => 8, 'name' => 'Model', 'allowCustom' => true], ['Kırmızı Traktör'], true));
    }

    public function testGenderNeutralOption(): void
    {
        $gender = ['id' => 6, 'name' => 'Cinsiyet', 'values' => [['id' => 1, 'name' => 'Erkek'], ['id' => 2, 'name' => 'Kız'], ['id' => 3, 'name' => 'Unisex']]];
        $this->assertSame('2', AttributeInference::suggest($gender, ['Kız Çocuk Bebek Arabası'], true)['value']);
        $this->assertSame('3', AttributeInference::suggest($gender, ['Bebek Arabası'], true)['value']);
    }

    public function testPlanWritesOnlyBlockedProductsAndCountsWhatBecomesReady(): void
    {
        $attributes = [$this->colour(), ['id' => 9, 'name' => 'Menşei', 'required' => true, 'values' => [['id' => 1, 'name' => 'TR']]]];
        $mapping = ['attribute_mapping_json' => json_encode(['defaults' => ['9' => '1'], 'products' => ['id:3' => ['348' => '2']]])];
        $products = [['id' => 1, 'sku' => 'A', 'name' => 'Kırmızı Araba'], ['id' => 2, 'sku' => 'B', 'name' => 'Araba'], ['id' => 3, 'sku' => 'C', 'name' => 'Kırmızı Tır']];
        $plan = AttributeInferenceService::plan($mapping, $attributes, $products, [], false);
        $this->assertSame(['id:1' => ['348' => '1']], $plan['values']); // 2 says nothing, 3 already has the user's value
        $this->assertSame(2, $plan['blocked']);
        $this->assertSame(1, $plan['ready']);
        $this->assertSame(['text' => 1, 'neutral' => 0, 'none' => 1], array_intersect_key($plan['attributes']['348'], ['text' => 0, 'neutral' => 0, 'none' => 0]));
    }

    public function testPlanDoesNotCallAProductReadyWhileAnotherAttributeStillBlocksIt(): void
    {
        $attributes = [$this->colour(), ['id' => 9, 'name' => 'Menşei', 'required' => true, 'values' => [['id' => 1, 'name' => 'TR']]]];
        $plan = AttributeInferenceService::plan(['attribute_mapping_json' => '{}'], $attributes, [['id' => 1, 'sku' => 'A', 'name' => 'Kırmızı Araba']], [], true);
        $this->assertSame(['id:1' => ['348' => '1']], $plan['values']);
        $this->assertSame(0, $plan['ready']);
    }

    public function testInferredValuesNeverOverwriteHandEnteredOnesAndCanBeUndone(): void
    {
        [$products, $inferred] = CategoryMarketplaceMapping::mergeInferred(['id:1' => ['348' => '2']], [], ['id:1' => ['348' => '1', '6' => '3'], 'id:2' => ['348' => '4']]);
        $this->assertSame(['id:1' => ['348' => '2', '6' => '3'], 'id:2' => ['348' => '4']], $products);
        $this->assertSame(['id:1' => ['6' => '3'], 'id:2' => ['348' => '4']], $inferred);
        // A second run may refine its own earlier value.
        [$products, $inferred] = CategoryMarketplaceMapping::mergeInferred($products, $inferred, ['id:2' => ['348' => '5']]);
        $this->assertSame('5', $products['id:2']['348']);
        // The user then edits one inferred value by hand: undo keeps it.
        $products['id:1']['6'] = '1';
        [$after, $removed] = CategoryMarketplaceMapping::withoutInferred($products, $inferred);
        $this->assertSame(['id:1' => ['348' => '2', '6' => '1']], $after);
        $this->assertSame(1, $removed);
    }
}
