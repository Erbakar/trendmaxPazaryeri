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

    public function testEmptyOriginBecomesTurkiyeOnlyWhenTheStoreSaysSo(): void
    {
        $origin = ['id' => 9, 'name' => 'Menşei', 'required' => true, 'values' => [['id' => 1, 'name' => 'CN'], ['id' => 2, 'name' => 'TR']]];
        $this->assertNull(AttributeInference::suggest($origin, ['Ahşap Yapboz'], true, false));
        $this->assertSame(['value' => '2', 'option' => 'TR', 'basis' => 'default'], AttributeInference::suggest($origin, ['Ahşap Yapboz'], false, true));
        // Other attributes are unaffected, and a list without Türkiye gets nothing.
        $this->assertNull(AttributeInference::suggest($this->colour(), ['Ahşap Yapboz'], false, true));
        $this->assertNull(AttributeInference::suggest(['id' => 9, 'name' => 'Menşei', 'values' => [['id' => 1, 'name' => 'CN']]], ['x'], false, true));
    }

    public function testPlanWithOriginMakesTheProductReady(): void
    {
        $attributes = [$this->colour(), ['id' => 9, 'name' => 'Menşei', 'required' => true, 'values' => [['id' => 1, 'name' => 'TR']]]];
        $plan = AttributeInferenceService::plan(['attribute_mapping_json' => '{}'], $attributes, [['id' => 1, 'sku' => 'A', 'name' => 'Kırmızı Araba']], [], false, true);
        $this->assertSame(['id:1' => ['348' => '1', '9' => '1']], $plan['values']);
        $this->assertSame(1, $plan['ready']);
        $this->assertSame(1, $plan['attributes']['9']['neutral']);
    }

    public function testBrandIsReadFromTheProductName(): void
    {
        $index = \App\Services\Catalog\BrandInference::index([1 => 'Pilsan', 2 => 'Dede', 3 => 'Dede Toys', 4 => 'Diğer', 5 => 'LC', 6 => 'Fisher Price']);
        $from = static fn(string $name) => \App\Services\Catalog\BrandInference::fromName($name, $index);
        $this->assertSame(1, $from('PİLSAN Akülü Araba 12V'));
        $this->assertSame(3, $from('Dede Toys Mutfak Seti'));          // longer brand wins over its part
        $this->assertSame(6, $from('Fisher-Price Eğitici Köpekçik'));  // punctuation in the title
        $this->assertNull($from('Pilsanlı Diğer LC Oyuncak'));         // part of a word, placeholder, short code
        $this->assertSame(2, $from('Dede Pilsan Uyumlu Yedek Teker')); // two brands: the one the title starts with
        $this->assertNull($from('Uyumlu Teker Pilsan Dede'));          // two brands, neither leads
    }

    public function testOptionIndexResolvesExactlyLikeTheListSearchAndNeverLeaksBetweenLists(): void
    {
        $r = \App\Services\Marketplace\TrendyolAttributeResolver::class;
        $a = ['id' => 9, 'name' => 'Menşei', 'values' => [['id' => 1, 'name' => 'TR'], ['id' => 2, 'name' => 'CN']]];
        $b = ['id' => 9, 'name' => 'Menşei', 'values' => [['id' => 1, 'name' => 'CN'], ['id' => 3, 'name' => 'TR']]]; // same id, other list
        [$ia, $ib] = $r::withOptionIndex([$a, $b]);
        foreach ([[$a, $ia], [$b, $ib]] as [$plain, $indexed]) foreach (['1', '2', '3', 'x'] as $value) {
            $run = static function (array $attribute) use ($r, $value) { try { return $r::value($attribute, $value); } catch (\InvalidArgumentException $e) { return $e->getMessage(); } };
            $this->assertSame($run($plain), $run($indexed), "value $value");
        }
        $this->assertSame(['attributeId' => 9, 'attributeValueId' => 3], $r::value($ib, '3'));
        // Prepared inference data belongs to its own list as well.
        $this->assertSame('1', AttributeInference::suggest($a, ['x'], false, true, AttributeInference::prepare($a))['value']);
        $this->assertSame('3', AttributeInference::suggest($b, ['x'], false, true, AttributeInference::prepare($b))['value']);
    }

    public function testPlanWorksOnAProductsOwnOverrideMapping(): void
    {
        // An override keeps the product's values under products['id:N'], exactly like a category mapping.
        $attributes = [$this->colour(), ['id' => 1192, 'name' => 'Menşei', 'required' => true, 'values' => [['id' => 10617344, 'name' => 'TR']]]];
        $override = ['_override_id' => 7, 'product_id' => 5, 'marketplace_category_id' => '766',
            'attribute_mapping_json' => json_encode(['defaults' => new stdClass(), 'sources' => new stdClass(), 'products' => ['id:5' => ['348' => '2']]])];
        $plan = AttributeInferenceService::plan($override, $attributes, [['id' => 5, 'sku' => 'A', 'name' => 'Kırmızı Kılıf']], [], true, true);
        $this->assertSame(['id:5' => ['1192' => '10617344']], $plan['values']); // colour was chosen before and stays
        $this->assertSame(1, $plan['ready']);
    }
}
