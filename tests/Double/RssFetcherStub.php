<?php
declare(strict_types=1);

namespace App\Tests\Double;

use App\Interface\RssFetcherInterface;

final class RssFetcherStub implements RssFetcherInterface
{
    public function fetchMany(array $feeds, ?int $limitPerFeed = null): array
    {
        return [
            [
                'source' => 'reporterre.net',
                'title' => 'Zéro artificialisation nette',
                'link' => 'https://reporterre.net/a',
                'description' => 'Planification écologique',
                'image_url' => null,
                'published_at' => '2025-08-25T09:00:00+00:00',
            ],
            [
                'source' => 'actu-environnement.com',
                'title' => 'Eau : seuils d’alerte',
                'link' => 'https://www.actu-environnement.com/b',
                'description' => 'Sécheresse',
                'image_url' => null,
                'published_at' => '2025-08-26T08:00:00+00:00',
            ],
            [
                'source' => 'reporterre.net',
                'title' => 'Climat : rapport',
                'link' => 'https://reporterre.net/c',
                'description' => 'GIEC',
                'image_url' => null,
                'published_at' => '2025-08-24T07:00:00+00:00',
            ],
        ];
    }
}
