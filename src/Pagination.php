<?php
declare(strict_types=1);

namespace FloCMS\Core;

/**
 * Pagination metadata shared by Model::pagingArray() and Database::paginate().
 */
final class Pagination
{
    /**
     * @return array{items_count: int, total_pages: int, cur_page: int, per_page: int,
     *               next_page: int, prev_page: int, has_next: bool, has_prev: bool,
     *               last_page: int, last_pages: list<int>}
     */
    public static function meta(mixed $page, mixed $perPage, mixed $total): array
    {
        $page = max(1, (int) $page);
        $perPage = max(1, (int) $perPage);
        $total = max(0, (int) $total);
        $totalPages = (int) ceil($total / $perPage);

        $hasNext = $page < $totalPages;
        $hasPrev = $page > 1;

        $lastPages = [];

        if ($totalPages > 6) {
            $lastPages = [
                $totalPages - 3,
                $totalPages - 2,
                $totalPages - 1,
            ];
        }

        return [
            'items_count' => $total,
            'total_pages' => $totalPages,
            'cur_page' => $page,
            'per_page' => $perPage,
            'next_page' => $hasNext ? $page + 1 : 0,
            'prev_page' => $hasPrev ? $page - 1 : 0,
            'has_next' => $hasNext,
            'has_prev' => $hasPrev,
            'last_page' => $totalPages,
            'last_pages' => $lastPages,
        ];
    }
}
