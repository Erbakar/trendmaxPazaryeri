<?php
declare(strict_types=1);
use App\Models\ApiToken;
use App\Services\Support\{AttributeTemplateService,SupportStatusService};
use PHPUnit\Framework\TestCase;

final class SupportBridgeTest extends TestCase
{
    private function definitions(): array
    {
        return array_column([
            ['id'=>348,'name'=>'Renk','required'=>true,'allowCustom'=>false,'values'=>[['id'=>1001,'name'=>'Lacivert']]],
            ['id'=>1192,'name'=>'Menşei','required'=>true,'allowCustom'=>false,'values'=>[['id'=>10617344,'name'=>'TR']]],
            ['id'=>47,'name'=>'Model Kodu','required'=>false,'allowCustom'=>true,'values'=>[]],
        ],null,'id');
    }
    public function testExportCarriesSourceNamesNotLocalIds(): void
    {
        $row=['marketplace_category_id'=>'2386','marketplace_category_name'=>'Oyuncak','category_name'=>'Bebek Oyuncakları',
            'attribute_mapping_json'=>json_encode(['defaults'=>['1192'=>'10617344'],'sources'=>['348'=>'9'],'value_map'=>['348'=>['lacivertbeyaz'=>'1001']],'products'=>['id:5'=>['348'=>'1001']]])];
        $t=AttributeTemplateService::toTemplate($row,[9=>'Renk']);
        self::assertSame(['348'=>'Renk'],(array)$t['sources']);
        self::assertSame(['1192'=>'10617344'],(array)$t['defaults']);
        self::assertArrayNotHasKey('products',$t); // product specific choices never travel
        self::assertNull(AttributeTemplateService::toTemplate(['marketplace_category_id'=>'1','attribute_mapping_json'=>'{}'],[]));
    }
    public function testImportResolvesSourceByNameAndRejectsForeignOptions(): void
    {
        $clean=AttributeTemplateService::validate(['defaults'=>['1192'=>'10617344','47'=>'X-1'],'sources'=>['348'=>'renk','1192'=>'Ülke'],'value_map'=>['348'=>['Lacivert-Beyaz'=>'1001']]],$this->definitions(),['renk'=>'12']);
        self::assertSame(['348'=>'12'],$clean['sources']);
        self::assertSame(['348'=>['lacivertbeyaz'=>'1001']],$clean['value_map']);
        self::assertCount(1,$clean['warnings']);
        $this->expectException(\InvalidArgumentException::class);
        AttributeTemplateService::validate(['defaults'=>['1192'=>'1001']],$this->definitions(),[]);
    }
    public function testUnknownAttributeRejectsTemplate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AttributeTemplateService::validate(['sources'=>['9999'=>'Renk']],$this->definitions(),['renk'=>'12']);
    }
    public function testAlertsDescribeProblemsWithoutSecrets(): void
    {
        $alerts=SupportStatusService::alerts([
            'subscription'=>['status'=>'active','days_left'=>3],
            'marketplaces'=>['items'=>[['name'=>'Trendyol','connected'=>true,'failed_syncs_24h'=>2],['name'=>'N11','connected'=>false,'failed_syncs_24h'=>0]]],
            'jobs'=>['dead_7d'=>0,'oldest_pending_at'=>date('Y-m-d H:i:s',time()-3600)],
            'attribute_issues'=>['products_blocked'=>7,'categories_blocked'=>2,'refreshed_at'=>'2026-11-07 10:00:00'],
            'catalog_audit'=>['rejections_7d'=>4,'enabled'=>true,'heartbeat'=>date('Y-m-d H:i:s'),'last_run'=>['status'=>'complete']],
        ]);
        $codes=array_column($alerts,'code');
        foreach(['subscription_ending','marketplace_sync_failed','marketplace_not_connected','queue_stalled','attribute_mapping_missing','publish_rejected'] as $code) self::assertContains($code,$codes);
        $messages=array_column($alerts,'message','code');
        self::assertSame('7 üründe zorunlu özellik eşleştirmesi bulunamadı (2 kategori).',$messages['attribute_mapping_missing']);
        self::assertNotContains('catalog_audit_stalled',$codes);
    }
    public function testSupportPermissionsAreExplicitAndWildcardAware(): void
    {
        self::assertArrayHasKey('support:read',ApiToken::availablePermissions());
        self::assertArrayHasKey('support:mappings',ApiToken::availablePermissions());
        $model=(new \ReflectionClass(ApiToken::class))->newInstanceWithoutConstructor();
        self::assertTrue($model->hasPermission(['permissions'=>'["support:read"]'],'support:read'));
        self::assertFalse($model->hasPermission(['permissions'=>'["support:read"]'],'support:mappings'));
        self::assertFalse($model->hasPermission(['permissions'=>'["products:*"]'],'support:read'));
    }
}
