<?php
declare(strict_types=1);

use App\Services\Marketplace\HepsiburadaService;
use PHPUnit\Framework\TestCase;

/** A new Hepsiburada store has no listings yet: HB answers 404 ListingNotFoundException, which is not an error. */
final class HepsiburadaEmptyListingsTest extends TestCase
{
    public function testListingNotFoundOn404MeansNoListings(): void
    {
        $body = '{"message":"Exception of type \'ListingService.Business.Exceptions.ListingNotFoundException\' was thrown."}';
        self::assertTrue(HepsiburadaService::isEmptyListingsResponse(404, $body));
        self::assertTrue(HepsiburadaService::isEmptyListingsResponse(404, 'API hatası (404): ' . $body));
    }

    public function testOtherFailuresStayErrors(): void
    {
        self::assertFalse(HepsiburadaService::isEmptyListingsResponse(404, '{"message":"Not Found"}'));
        self::assertFalse(HepsiburadaService::isEmptyListingsResponse(401, 'ListingNotFoundException'));
        self::assertFalse(HepsiburadaService::isEmptyListingsResponse(500, 'ListingNotFoundException'));
    }
}
