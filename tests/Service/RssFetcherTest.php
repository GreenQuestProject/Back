<?php

namespace App\Tests\Service;

use App\Service\RssFetcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Test unitaire du service RssFetcher
 */
final class RssFetcherTest extends TestCase
{
    private const RSS_URL = 'https://example.com/rss.xml';
    private const ATOM_URL = 'https://example.org/atom.xml';

    public function test_fetch_many_parses_and_deduplicates_and_sorts(): void
    {
        $client = $this->makeClient();
        $fetcher = new RssFetcher($client);

        $items = $fetcher->fetchMany([self::RSS_URL, self::ATOM_URL], null);

        $this->assertCount(3, $items);

        $this->assertSame('Entry C', $items[0]['title']);
        $this->assertSame('Article B', $items[1]['title']);
        $this->assertSame('Article A', $items[2]['title']);

        $this->assertSame('https://site.com/a', $items[2]['link']);
        $this->assertSame('https://site.com/b', $items[1]['link']);

        $this->assertArrayHasKey('source', $items[0]);
        $this->assertArrayHasKey('image_url', $items[0]);
        $this->assertNotEmpty($items[0]['published_at']);
    }

    private function makeClient(): MockHttpClient
    {
        $rssXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/">
  <channel>
    <title>Ex RSS</title>
    <item>
      <title>Article A</title>
      <link>https://site.com/a?utm_source=test#frag</link>
      <pubDate>Mon, 01 Jan 2024 10:00:00 +0000</pubDate>
      <media:thumbnail url="https://site.com/a.jpg" />
      <description>Desc A</description>
    </item>
    <item>
      <title>Article B</title>
      <link>https://site.com/b</link>
      <pubDate>Tue, 02 Jan 2024 10:00:00 +0000</pubDate>
      <description>Desc B</description>
    </item>
  </channel>
</rss>
XML;

        $atomXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:media="http://search.yahoo.com/mrss/">
  <title>Ex Atom</title>
  <entry>
    <title>Entry C</title>
    <updated>2024-01-03T12:00:00Z</updated>
    <link rel="alternate" href="https://other.org/c"/>
    <media:thumbnail url="https://other.org/c.jpg" />
    <summary>Sum C</summary>
  </entry>
  <entry>
    <title>Entry D (dup of B)</title>
    <updated>2024-01-02T10:00:00Z</updated>
    <link rel="alternate" href="https://site.com/b?gclid=zzz"/>
    <summary>Duplicate of B via canonical</summary>
  </entry>
</feed>
XML;

        $map = [
            self::RSS_URL => new MockResponse($rssXml, ['response_headers' => ['content-type' => 'application/rss+xml']]),
            self::ATOM_URL => new MockResponse($atomXml, ['response_headers' => ['content-type' => 'application/atom+xml']]),
        ];

        return new MockHttpClient(function (string $method, string $url) use ($map) {
            if (isset($map[$url])) return $map[$url];
            return new MockResponse('', ['http_code' => 404]);
        });
    }

    public function test_limit_per_feed(): void
    {
        $client = $this->makeClient();
        $fetcher = new RssFetcher($client);

        $items = $fetcher->fetchMany([self::RSS_URL, self::ATOM_URL], 1);
        $this->assertCount(2, $items);

        $this->assertSame('Entry C', $items[0]['title']);
        $this->assertSame('Article B', $items[1]['title']);
    }
}
