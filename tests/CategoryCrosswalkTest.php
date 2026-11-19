<?php
declare(strict_types=1);

use App\Services\Mapping\CategoryCrosswalkService;
use PHPUnit\Framework\TestCase;

final class CategoryCrosswalkTest extends TestCase
{
    public function testLeafIsDroppedFromThePathUsedForScoring(): void
    {
        self::assertSame(
            'Elektronik > Elektronik Aksesuarlar > Cep Telefonu Aksesuarları',
            CategoryCrosswalkService::pathWithoutLeaf('Elektronik > Elektronik Aksesuarlar > Cep Telefonu Aksesuarları > Kapak & Kılıf', 'Kapak & Kılıf')
        );
    }

    public function testPathWithoutTheLeafStaysAsItIs(): void
    {
        self::assertSame('Aksesuar > Takı', CategoryCrosswalkService::pathWithoutLeaf('Aksesuar > Takı', 'Kolye'));
        self::assertSame('', CategoryCrosswalkService::pathWithoutLeaf('Kolye', 'kolye'));
    }
}
