<?php
declare(strict_types=1);
use App\Models\CategoryMarketplaceMapping as M;
use App\Services\Catalog\AttributeLibrary as L;
use PHPUnit\Framework\TestCase;

final class AttributeLibraryTest extends TestCase
{
    public function testLibraryFillsUndecidedAndNeverTouchesStoresOwnChoices(): void
    {
        $own=['defaults'=>['1192'=>'TR-own'],'sources'=>['348'=>'12'],'value_map'=>['348'=>['mavi'=>'7']]];
        [$c,$changed]=M::mergeLibraryTemplate($own,['1192'=>'CN','47'=>'X'],['348'=>'99'],['348'=>['mavi'=>'8','lacivert beyaz'=>'1001']]);
        self::assertSame('TR-own',$c['defaults']['1192']);   // store's own fixed value
        self::assertSame('12',$c['sources']['348']);           // store's own source
        self::assertSame('7',$c['value_map']['348']['mavi']);  // store's own XML value choice
        self::assertSame('X',$c['defaults']['47']);
        self::assertSame('1001',$c['value_map']['348']['lacivert beyaz']);
        self::assertSame(['defaults'=>1,'sources'=>0,'value_map'=>1],$changed);
        self::assertTrue(M::libraryMarks($c)['defaults']['47']);
        self::assertArrayNotHasKey('1192',M::libraryMarks($c)['defaults']);
    }
    public function testAFixMadeOnceReplacesAndWithdrawsLibraryOwnedValuesEverywhere(): void
    {
        [$c]=M::mergeLibraryTemplate([],['47'=>'X'],[],['348'=>['lacivert beyaz'=>'1001','mavi'=>'5']]);
        // Another store corrects one value and withdraws another.
        [$c,$changed]=M::mergeLibraryTemplate($c,['47'=>'Y'],[],['348'=>['lacivert beyaz'=>'1002']]);
        self::assertSame('Y',$c['defaults']['47']);
        self::assertSame(['lacivert beyaz'=>'1002'],$c['value_map']['348']);
        self::assertSame(['defaults'=>1,'sources'=>0,'value_map'=>2],$changed);
        // Identical template → nothing to write (keeps content approvals valid).
        [,$again]=M::mergeLibraryTemplate($c,['47'=>'Y'],[],['348'=>['lacivert beyaz'=>'1002']]);
        self::assertSame(0,array_sum($again));
    }
    public function testUserEditMakesALibraryValueTheStoresOwn(): void
    {
        [$c]=M::mergeLibraryTemplate([],['47'=>'X','14'=>'501'],[],[]);
        $c=M::keepLibraryMarks($c,['47'=>'Z','14'=>'501'],[],[]);     // user changed 47, kept 14
        self::assertArrayNotHasKey('47',M::libraryMarks($c)['defaults']);
        self::assertTrue(M::libraryMarks($c)['defaults']['14']);
        $c['defaults']=['47'=>'Z','14'=>'501'];
        [$c]=M::mergeLibraryTemplate($c,['47'=>'X','14'=>'502'],[],[]);
        self::assertSame('Z',$c['defaults']['47']);  // own now
        self::assertSame('502',$c['defaults']['14']); // still library-owned → follows the fix
    }
    public function testPublishOverlaysAndOnlyDeliberateRemovalsWithdraw(): void
    {
        $library=['defaults'=>['47'=>'X','14'=>'501'],'sources'=>['348'=>'Renk'],'value_map'=>['348'=>['mavi'=>'5','yesil'=>'6']]];
        $mine=['defaults'=>['47'=>'Y'],'sources'=>[],'value_map'=>['348'=>['mavi'=>'9']]];
        $out=L::overlay($library,$mine,['defaults'=>['14'],'value_map'=>['348'=>['yesil']]]);
        self::assertSame(['47'=>'Y'],$out['defaults']);           // 14 removed on purpose, 47 corrected
        self::assertSame(['348'=>'Renk'],$out['sources']);        // a store that never set it cannot wipe it
        self::assertSame(['348'=>['mavi'=>'9']],$out['value_map']);
        $removed=L::removedLibraryEntries(['library'=>['defaults'=>['14'=>true,'47'=>true],'value_map'=>['348'=>['yesil'=>true]]]],['47'=>'Y'],[],['348'=>[]]);
        self::assertSame(['defaults'=>['14'],'sources'=>[],'value_map'=>['348'=>['yesil']]],$removed);
    }
}
