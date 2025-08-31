<?php
declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Interface\RssFetcherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class EcoNewsControllerTest extends WebTestCase
{
    private string $sourcesPath;
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $passwordHasher;
    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->passwordHasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        // Nettoyage si besoin (sécurité en cas de test planté précédemment)
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();

        // Créer un user
        $user = (new User())
            ->setUsername('user')
            ->setEmail('user@user')
            ->setRoles(['ROLE_USER']);
        $user->setPassword($this->passwordHasher->hashPassword($user, 'password'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->entityManager->clear();

        // Crée/écrase le fichier de sources JSON
        $projectDir = static::getContainer()->getParameter('kernel.project_dir');

        $sourcesParam = static::getContainer()->hasParameter('app.eco_news_sources_path')
            ? static::getContainer()->getParameter('app.eco_news_sources_path')
            : $projectDir . '/var/tests/eco_news_sources.json';

        $this->sourcesPath = $sourcesParam;

        @mkdir(\dirname($this->sourcesPath), 0777, true);
        file_put_contents(
            $this->sourcesPath,
            json_encode(['feeds' => [
                'https://fake-rss.local/one.xml',
                'https://fake-rss.local/two.xml',
            ]], JSON_UNESCAPED_SLASHES)
        );

        // Stub du fetcher (pas de HTTP réel)
        static::getContainer()->set(RssFetcherInterface::class, new class implements RssFetcherInterface {
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
        });
    }

    protected function tearDown(): void
    {
        @unlink($this->sourcesPath);
        parent::tearDown();
    }

    private function getJwtToken(string $username, string $password): string
    {
        $this->client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'username' => $username,
            'password' => $password
        ]));
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);

        $response = json_decode($this->client->getResponse()->getContent(), true);
        return $response['token'] ?? '';
    }

    public function test_list_default_pagination_and_sort(): void
    {
        $jwtToken = $this->getJwtToken('user', 'password');
        $this->assertNotEmpty($jwtToken);

        $this->client->request('GET', '/api/eco-news',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_Authorization' => "Bearer $jwtToken"]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $json = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertSame(1, $json['meta']['page']);
        $this->assertSame(20, $json['meta']['per_page']);
        $this->assertSame(3, $json['meta']['total']);
        $this->assertSame('date', $json['meta']['sort']);
        $this->assertSame('desc', $json['meta']['order']);

        $this->assertSame('Eau : seuils d’alerte', $json['data'][0]['title']);
        $this->assertSame('Zéro artificialisation nette', $json['data'][1]['title']);
        $this->assertSame('Climat : rapport', $json['data'][2]['title']);
    }

    public function test_search_and_filter_and_sort_by_title_asc(): void
    {
        $jwtToken = $this->getJwtToken('user', 'password');
        $this->assertNotEmpty($jwtToken);

        $this->client->request('GET', '/api/eco-news?q=climat&sources=reporterre.net&sort=title&order=asc',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_Authorization' => "Bearer $jwtToken"]);

        $this->assertResponseIsSuccessful();
        $json = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertSame(1, $json['meta']['total']);
        $this->assertSame('Climat : rapport', $json['data'][0]['title']);
        $this->assertSame('reporterre.net', $json['data'][0]['source']);
    }

    public function test_pagination_page_2_per_page_1(): void
    {
        $jwtToken = $this->getJwtToken('user', 'password');
        $this->assertNotEmpty($jwtToken);

        $this->client->request('GET', '/api/eco-news?per_page=1&page=2',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_Authorization' => "Bearer $jwtToken"]);

        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $json = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertSame(3, $json['meta']['total']);
        $this->assertSame(1, $json['meta']['per_page']);
        $this->assertSame(2, $json['meta']['page']);
        $this->assertCount(1, $json['data']);
        $this->assertSame('Zéro artificialisation nette', $json['data'][0]['title']);
    }
}
