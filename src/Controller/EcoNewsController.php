<?php

namespace App\Controller;

use App\Interface\RssFetcherInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class EcoNewsController extends AbstractController
{
    #[Route('/api/eco-news', name: 'api_eco_news', methods: ['GET'])]
    public function __invoke(
        Request $request,
        RssFetcherInterface $rss,
        CacheInterface $cache
    ): JsonResponse {

        $page    = max(1, (int) $request->query->get('page', 1));
        $perPage = min(100, max(1, (int) $request->query->get('per_page', 20)));
        $q       = trim((string) $request->query->get('q', ''));                // recherche plein texte
        $sort    = (string) $request->query->get('sort', 'date');              // date|title|source
        $order   = strtolower((string) $request->query->get('order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $sourcesFilter = array_values(array_filter(array_map('trim', explode(',', (string) $request->query->get('sources', '')))));


        $sourcesPath = $this->getParameter('kernel.project_dir') . '/publicgit reset --soft HEAD~1/eco_news_sources.json';
        $json = @file_get_contents($sourcesPath);
        if ($json === false) {
            return $this->json(['error' => 'Impossible de lire le fichier des sources.'], 500);
        }

        $decoded = json_decode($json, true);
        $feeds = $decoded['feeds'] ?? [];
        if (!\is_array($feeds) || empty($feeds)) {
            return $this->json(['error' => 'Aucune source RSS valide trouvée dans le JSON.'], 500);
        }

        $fileMtime = @filemtime($sourcesPath) ?: time();


        $cacheKey = sprintf(
            'eco_news_v1_%s_%s_%s_%s',
            md5(json_encode([
                'feeds'   => $feeds,
                'q'       => $q,
                'sort'    => $sort,
                'order'   => $order,
                'sources' => $sourcesFilter,
                'mtime'   => $fileMtime,
            ], JSON_UNESCAPED_UNICODE)),
            $page,
            $perPage,
            (int)($request->query->get('nocache', 0)) // simple bypass si besoin
        );

        $payload = $cache->get($cacheKey, function (ItemInterface $item) use (
            $rss, $feeds, $q, $sort, $order, $sourcesFilter, $page, $perPage, $request
        ) {
            $item->expiresAfter(600);

            $items = $rss->fetchMany($feeds, null);


            if (!empty($sourcesFilter)) {
                $items = array_filter($items, function(array $it) use ($sourcesFilter) {
                    $src = strtolower((string)($it['source'] ?? ''));
                    foreach ($sourcesFilter as $f) {
                        $f = strtolower($f);
                        if ($src === $f || ($src !== '' && str_contains($src, $f))) {
                            return true;
                        }
                    }

                    if (!empty($it['link'])) {
                        $host = parse_url((string)$it['link'], PHP_URL_HOST);
                        if ($host) {
                            $host = strtolower($host);
                            foreach ($sourcesFilter as $f) {
                                $f = strtolower($f);
                                if ($host === $f || str_contains($host, $f)) {
                                    return true;
                                }
                            }
                        }
                    }
                    return false;
                });
            }


            if ($q !== '') {
                $needle = mb_strtolower($q);
                $items = array_filter($items, function(array $it) use ($needle) {
                    $hay = mb_strtolower(trim(($it['title'] ?? '') . ' ' . ($it['description'] ?? '')));
                    return $needle === '' ? true : (mb_strpos($hay, $needle) !== false);
                });
            }

            $normalizeDate = function($v): int {
                if ($v instanceof \DateTimeInterface) return (int)$v->getTimestamp();
                if (is_numeric($v)) return (int)$v;
                $ts = $v ? strtotime((string)$v) : false;
                return $ts ? (int)$ts : 0;
            };


            usort($items, function(array $a, array $b) use ($sort, $order, $normalizeDate) {
                $dir = $order === 'asc' ? 1 : -1;
                switch ($sort) {
                    case 'title':
                        return $dir * strcmp(mb_strtolower($a['title'] ?? ''), mb_strtolower($b['title'] ?? ''));
                    case 'source':
                        return $dir * strcmp(mb_strtolower($a['source'] ?? ''), mb_strtolower($b['source'] ?? ''));
                    case 'date':
                    default:
                        return $dir * ($normalizeDate($a['published_at'] ?? 0) <=> $normalizeDate($b['published_at'] ?? 0));
                }
            });


            $maxSameSource = (int) $request->query->get('max_same_source', 1);
            if ($sort === 'date') { // on ne "mélange" que si on trie par date
                $items = $this->antiClumpBySource($items, max(1, $maxSameSource));
            }


            $total  = count($items);
            $offset = ($page - 1) * $perPage;
            $paged  = array_slice(array_values($items), $offset, $perPage);


            return [
                'data' => array_map(function(array $it) {
                    return [
                        'source'       => (string)($it['source'] ?? ''),
                        'title'        => (string)($it['title'] ?? ''),
                        'link'         => (string)($it['link'] ?? ''),
                        'description'  => isset($it['description']) ? (string)$it['description'] : null,
                        'image_url'    => isset($it['image_url']) ? (string)$it['image_url'] : null,
                        'published_at' => is_scalar($it['published_at'] ?? null)
                            ? (string)$it['published_at']
                            : (($it['published_at'] ?? null) instanceof \DateTimeInterface
                                ? $it['published_at']->format(\DateTime::ATOM)
                                : null),
                    ];
                }, $paged),
                'meta' => [
                    'page'       => $page,
                    'per_page'   => $perPage,
                    'total'      => $total,
                    'page_count' => (int) ceil(max(1, $total) / $perPage),
                    'sort'       => $sort,
                    'order'      => $order,
                    'q'          => $q,
                    'sources'    => $sourcesFilter,
                ],
            ];
        });

        return new JsonResponse($payload);
    }

    /**
     * Répartit les items pour éviter des blocs de la même source.
     * - Conserve l'ordre global autant que possible (priorité aux plus récents).
     * - Si plus de $maxRun items consécutifs partagent la même source,
     *   on cherche le prochain item d'une autre source et on le remonte.
     *
     * Complexité O(n^2) au pire, mais n est petit (après filtres) → ok en pratique.
     *
     * @param array<int,array{source?:string,published_at?:mixed}> $items
     * @return array<int,array>
     */
    private function antiClumpBySource(array $items, int $maxRun = 1): array
    {
        $n = count($items);
        if ($n <= 2 || $maxRun < 1) return $items;

        $runSource = null;
        $runLen = 0;

        for ($i = 0; $i < $n; $i++) {
            $src = strtolower((string)($items[$i]['source'] ?? ''));

            if ($src === $runSource) {
                $runLen++;
            } else {
                $runSource = $src;
                $runLen = 1;
            }

            if ($runLen <= $maxRun) {
                continue;
            }

            $swapIdx = -1;
            for ($j = $i + 1; $j < $n; $j++) {
                $s2 = strtolower((string)($items[$j]['source'] ?? ''));
                if ($s2 !== $runSource) { $swapIdx = $j; break; }
            }

            if ($swapIdx === -1) {
                // Rien à faire, toutes les suivantes sont la même source
                // On stoppe : la passe a fait le mieux possible.
                break;
            }

            // On remonte l'élément trouvé à la position courante $i
            $tmp = $items[$swapIdx];
            for ($k = $swapIdx; $k > $i; $k--) {
                $items[$k] = $items[$k - 1];
            }
            $items[$i] = $tmp;

            // On redémarre le run avec la nouvelle source à la position i
            $runSource = strtolower((string)($items[$i]['source'] ?? ''));
            $runLen = 1;
        }

        return $items;
    }

}
