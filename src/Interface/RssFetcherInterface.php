<?php

namespace App\Interface;

use DateTimeInterface;

interface RssFetcherInterface
{
    /**
     * @param string[] $feeds
     * @param int|null $limit null = pas de limite (ou utilise un plafond élevé)
     * @return array<int, array{
     *   title: string,
     *   link: string,
     *   description?: string|null,
     *   published_at?: string|DateTimeInterface|int|null,
     *   source?: string
     * }>
     */
    public function fetchMany(array $feeds, ?int $limit = null): array;
}