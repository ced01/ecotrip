<?php
namespace App\Provider;
interface PlaceProvider
{
    /** @return array{items: list<array<string, mixed>>, page: array{limit: int, offset: int, total: int}, sources: list<array<string, mixed>>} OpenAPI PlacesResponse */
    public function search(string $query, int $limit = 20, int $offset = 0): array;
}
