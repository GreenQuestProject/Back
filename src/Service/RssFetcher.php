<?php

namespace App\Service;

use App\Interface\RssFetcherInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\ResponseInterface;

class RssFetcher implements RssFetcherInterface
{
    private HttpClientInterface $http;

    public function __construct(?HttpClientInterface $http = null)
    {
        $this->http = $http ?? HttpClient::create([
            'timeout' => 8.0,
            'headers' => [
                'Accept' => 'application/rss+xml, application/atom+xml, application/xml;q=0.9, text/xml;q=0.8',
                'User-Agent' => 'EcoNewsBot/1.0 (+https://example.local)',
                'Accept-Encoding' => 'gzip, deflate',
            ],
        ]);
    }

    /**
     * @param string[] $feeds
     * @param int|null $limit null = pas de limite
     * @return array<int, array{
     *   source: string,
     *   title: string,
     *   link: string,
     *   description: ?string,
     *   image_url: ?string,
     *   published_at: ?string
     * }>
     * @throws TransportExceptionInterface
     */
    public function fetchMany(array $feeds, ?int $limit = null): array
    {
        $feeds = array_values(array_filter(array_map('trim', $feeds)));
        if (!$feeds) {
            return [];
        }


        /** @var array<string,ResponseInterface> $responses */
        $responses = [];
        foreach ($feeds as $url) {
            try {
                $responses[$url] = $this->http->request('GET', $url);
            } catch (\Throwable) {

            }
        }

        $all = [];


        foreach ($this->http->stream($responses, 10.0) as $response => $chunk) {
            if (!$chunk->isLast()) {
                // Si un chunk signale une erreur de transport, on oublie ce flux
                if ($chunk instanceof \Symfony\Component\HttpClient\Chunk\ErrorChunk) {
                    try { $response->getInfo(); } catch (\Throwable) {}

                    $key = $response->getInfo('url') ?? spl_object_id($response);
                    unset($responses[$key]);
                }
                continue;
            }

            try {

                $status = $response->getStatusCode(); // peut throw Client/ServerException

                if ($status < 200 || $status >= 300) {
                    // par sécurité (devrait être inutile vu le test ci-dessus)
                    continue;
                }

                // Récupère le contenu SANS lever d’exception (on sait déjà que c’est 2xx)
                $content = $response->getContent(false);

                $url = $response->getInfo('url') ?? '';
                $xml = @simplexml_load_string($content);
                if (!$xml instanceof \SimpleXMLElement) {
                    // flux invalide => skip
                    continue;
                }

                $feedHost = parse_url($url, PHP_URL_HOST) ?? '';

                // ——— collecte “par feed” ———
                $itemsForFeed = [];
                if (isset($xml->channel->item)) {
                    foreach ($xml->channel->item as $item) {
                        $itemsForFeed[] = $this->mapRssItem($xml, $item, $feedHost);
                    }
                } elseif (isset($xml->entry)) {
                    foreach ($xml->entry as $entry) {
                        $itemsForFeed[] = $this->mapAtomEntry($entry, $feedHost);
                    }
                } else {
                    foreach ($xml->xpath('//item') as $item) {
                        if ($item instanceof \SimpleXMLElement) {
                            $itemsForFeed[] = $this->mapRssItem($xml, $item, $feedHost);
                        }
                    }
                }

                // Tri DESC par date puis limite éventuelle
                usort($itemsForFeed, static function (array $a, array $b): int {
                    $da = $a['published_at'] ?? null;
                    $db = $b['published_at'] ?? null;
                    if ($da === $db) return 0;
                    if ($da === null) return 1;
                    if ($db === null) return -1;
                    return strcmp($db, $da);
                });
                if ($limit !== null) {
                    $itemsForFeed = array_slice($itemsForFeed, 0, $limit);
                }

                array_push($all, ...$itemsForFeed);
            } catch (\Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface $e) {
                // 4xx/5xx => on skippe silencieusement ce flux
                // (le fait d’avoir appelé getStatusCode() a “désarmé” le __destruct)
            } catch (\Throwable $e) {
                // erreur de parsing/transport => skip
            } finally {
                // Toujours retirer la réponse du pool
                $key = $response->getInfo('url') ?? spl_object_id($response);
                unset($responses[$key]);
            }
        }

        foreach ($responses as $res) {
            try { $res->getStatusCode(); } catch (\Throwable) {}
        }

        $seen = [];
        $dedup = [];
        foreach ($all as $it) {
            $link = $this->canonicalLink($it['link'] ?? '');
            if ($link === '') {
                // on tolère mais on ne déduplique pas sans lien
                $dedup[] = $it;
                continue;
            }
            if (isset($seen[$link])) {
                continue;
            }
            $seen[$link] = true;
            $it['link'] = $link;
            $dedup[] = $it;
        }

        usort($dedup, static function (array $a, array $b): int {
            $da = $a['published_at'] ?? null;
            $db = $b['published_at'] ?? null;
            if ($da === $db) return 0;
            if ($da === null) return 1;
            if ($db === null) return -1;
            return strcmp($db, $da); // DESC
        });

        return $dedup;
    }

    private function mapRssItem(\SimpleXMLElement $xml, \SimpleXMLElement $item, string $fallbackHost): array
    {
        $title = $this->sxText($item->title) ?? '(Sans titre)';
        $link  = $this->rssLink($item) ?? '';
        $desc  = $this->sxText($item->description) ?? $this->sxText($item->children('http://purl.org/rss/1.0/modules/content/')->encoded);
        $img   = $this->rssImage($xml, $item);
        $date  = $this->formatDate(
            $this->sxText($item->pubDate)
            ?? $this->sxText($item->children('http://purl.org/dc/elements/1.1/')->date)
            ?? $this->sxText($item->children('http://purl.org/dc/elements/1.1/')->created)
        );

        $hostFromLink = $link ? (parse_url($link, PHP_URL_HOST) ?? '') : '';
        return [
            'source'       => $hostFromLink !== '' ? $hostFromLink : $fallbackHost,
            'title'        => $title,
            'link'         => $link,
            'description'  => $desc,
            'image_url'    => $img,
            'published_at' => $date,
        ];
    }

    private function mapAtomEntry(\SimpleXMLElement $entry, string $fallbackHost): array
    {
        $title = $this->sxText($entry->title) ?? '(Sans titre)';
        $link  = $this->atomLink($entry) ?? '';
        $desc  = $this->sxText($entry->summary) ?? $this->sxText($entry->content);
        $img   = $this->atomImage($entry);
        $date  = $this->formatDate(
            $this->sxText($entry->updated)
            ?? $this->sxText($entry->published)
        );

        $hostFromLink = $link ? (parse_url($link, PHP_URL_HOST) ?? '') : '';
        return [
            'source'       => $hostFromLink !== '' ? $hostFromLink : $fallbackHost,
            'title'        => $title,
            'link'         => $link,
            'description'  => $desc,
            'image_url'    => $img,
            'published_at' => $date,
        ];
    }


    private function sxText(?\SimpleXMLElement $node): ?string
    {
        if (!$node) return null;
        $s = trim((string)$node);
        return $s !== '' ? $s : null;
    }

    private function formatDate(?string $raw): ?string
    {
        if (!$raw) return null;
        try {
            return (new \DateTimeImmutable($raw))->format(DATE_ATOM);
        } catch (\Throwable) {
            $ts = @strtotime($raw);
            if ($ts !== false) {
                return (new \DateTimeImmutable("@$ts"))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format(DATE_ATOM);
            }
            return null;
        }
    }

    /** Gère media:thumbnail, media:content, enclosure image/*, image du channel */
    private function rssImage(\SimpleXMLElement $xml, \SimpleXMLElement $item): ?string
    {
        // media:thumbnail / media:content
        $media = $item->children('http://search.yahoo.com/mrss/');
        if (isset($media->thumbnail['url']) && (string)$media->thumbnail['url'] !== '') {
            return (string)$media->thumbnail['url'];
        }
        if (isset($media->content['url']) && (string)$media->content['url'] !== '') {
            return (string)$media->content['url'];
        }
        // enclosure type image/*
        foreach ($item->enclosure ?? [] as $encl) {
            $type = (string)($encl['type'] ?? '');
            $url  = (string)($encl['url'] ?? '');
            if ($url !== '' && str_starts_with($type, 'image/')) return $url;
        }
        // image du channel
        if (isset($xml->channel->image->url)) {
            $u = trim((string)$xml->channel->image->url);
            if ($u !== '') return $u;
        }
        return null;
    }

    /** Essaie de trouver le lien "alternate" dans un entry Atom */
    private function atomLink(\SimpleXMLElement $entry): ?string
    {
        foreach ($entry->link ?? [] as $l) {
            $rel = (string)($l['rel'] ?? 'alternate');
            if ($rel === '' || $rel === 'alternate') {
                $href = (string)($l['href'] ?? '');
                if ($href !== '') return $href;
            }
        }
        return $this->sxText($entry->link);
    }

    /** Image pour Atom (media:thumbnail, link enclosure image/*) */
    private function atomImage(\SimpleXMLElement $entry): ?string
    {
        $media = $entry->children('http://search.yahoo.com/mrss/');
        if (isset($media->thumbnail['url']) && (string)$media->thumbnail['url'] !== '') {
            return (string)$media->thumbnail['url'];
        }
        foreach ($entry->link ?? [] as $l) {
            $rel  = (string)($l['rel'] ?? '');
            $type = (string)($l['type'] ?? '');
            $href = (string)($l['href'] ?? '');
            if ($rel === 'enclosure' && $href !== '' && str_starts_with($type, 'image/')) {
                return $href;
            }
        }
        return null;
    }

    /** Certains liens comportent des trackers : on les normalise légèrement */
    private function canonicalLink(string $link): string
    {
        $link = trim($link);
        if ($link === '') return '';
        // supprimer ancres
        $link = preg_replace('~#.*$~', '', $link) ?? $link;

        $parts = parse_url($link);
        if (!$parts || empty($parts['query'])) {
            return $link;
        }
        parse_str($parts['query'], $qs);
        foreach (array_keys($qs) as $k) {
            if (preg_match('~^utm_|^fbclid$|^gclid$~i', $k)) {
                unset($qs[$k]);
            }
        }
        $newQuery = http_build_query($qs);
        $rebuilt = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
        if (!empty($parts['port']))  $rebuilt .= ':' . $parts['port'];
        if (!empty($parts['path']))  $rebuilt .= $parts['path'];
        if ($newQuery !== '')        $rebuilt .= '?' . $newQuery;
        return $rebuilt;
    }

    /** Certains flux RSS utilisent <link> complexe (CDATA, objets) : essaie d'obtenir du texte */
    private function rssLink(\SimpleXMLElement $item): ?string
    {
        $txt = $this->sxText($item->link);
        if ($txt) return $txt;

        if (isset($item->guid) && ((string)($item->guid['isPermaLink'] ?? 'false')) === 'true') {
            $g = trim((string)$item->guid);
            if ($g !== '') return $g;
        }

        $link = $item->children('http://www.w3.org/2005/Atom')->link ?? null;
        if ($link && isset($link['href'])) {
            $href = (string)$link['href'];
            if ($href !== '') return $href;
        }

        return null;
    }
}
