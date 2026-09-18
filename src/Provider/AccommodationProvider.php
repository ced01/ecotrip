<?php
namespace App\Provider;
use App\Accommodation\AccommodationQuery;
interface AccommodationProvider
{
    /** @return array{status: string, items: list<array<string, mixed>>, page: array{limit: int, offset: int, total: int}, sources: list<array<string, mixed>>, warnings: list<string>} OpenAPI AccommodationsResponse */
    public function search(AccommodationQuery $query): array;
}
