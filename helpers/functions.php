<?php
declare(strict_types=1);

/** Converts a UTC MySQL DATETIME ('Y-m-d H:i:s') to ISO-8601 ('Y-m-d\TH:i:s\Z'). */
function iso_datetime(?string $value): ?string
{
    return $value === null ? null : str_replace(' ', 'T', $value) . 'Z';
}

/** Escapes LIKE wildcards so user input is matched literally. */
function like_escape(string $value): string
{
    return '%' . addcslashes($value, '%_\\') . '%';
}

/** Standard pagination block for list responses. */
function pagination(int $page, int $perPage, int $total): array
{
    return [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => max(1, (int) ceil($total / $perPage)),
    ];
}
