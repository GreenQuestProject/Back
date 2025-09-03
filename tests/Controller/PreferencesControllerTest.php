<?php

namespace App\Tests\Controller;

use App\Entity\NotificationPreference;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PreferencesControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private KernelBrowser $client;
    private UserPasswordHasherInterface $passwordHasher;

    public function testGetCreatesPreferenceIfMissingAndReturnsFlag(): void
    {
        $jwtToken = $this->getJwtToken('user', 'password');
        $this->assertNotEmpty($jwtToken);

        $prefBefore = $this->entityManager->getRepository(NotificationPreference::class)
            ->findAll();
        $this->assertCount(0, $prefBefore, 'La préférence ne doit pas exister avant le GET');

        $this->client->request(
            'GET',
            '/api/preferences',
            [],
            [],
            ['HTTP_Authorization' => "Bearer $jwtToken"]
        );

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/json');

        $json = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('newChallenge', $json);
        $this->assertFalse($json['newChallenge']);

        $this->entityManager->clear();
        $prefs = $this->entityManager->getRepository(NotificationPreference::class)->findAll();
        $this->assertCount(1, $prefs);
        $this->assertFalse($prefs[0]->isNewChallenge());
    }

    private function getJwtToken(string $username, string $password): string
    {
        $this->client->request(
            'POST',
            '/api/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['username' => $username, 'password' => $password])
        );
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);

        $response = json_decode($this->client->getResponse()->getContent(), true);
        return $response['token'] ?? '';
    }

    public function testPostUpdatesPreferenceToTrue(): void
    {
        $jwtToken = $this->getJwtToken('user', 'password');
        $this->assertNotEmpty($jwtToken);

        $this->client->request(
            'GET',
            '/api/preferences',
            [],
            [],
            ['HTTP_Authorization' => "Bearer $jwtToken"]
        );
        $this->assertResponseIsSuccessful();

        $this->client->request(
            'POST',
            '/api/preferences',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_Authorization' => "Bearer $jwtToken"],
            json_encode(['newChallenge' => true])
        );

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/json');

        $json = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('ok', $json);
        $this->assertArrayHasKey('newChallenge', $json);
        $this->assertTrue($json['ok']);
        $this->assertTrue($json['newChallenge']);

        $this->entityManager->clear();
        $prefs = $this->entityManager->getRepository(NotificationPreference::class)->findAll();
        $this->assertCount(1, $prefs);
        $this->assertTrue($prefs[0]->isNewChallenge());
    }

    public function testPostWithoutBodyKeepsCurrentState(): void
    {
        $jwtToken = $this->getJwtToken('user', 'password');
        $this->assertNotEmpty($jwtToken);

        $this->client->request(
            'GET',
            '/api/preferences',
            [],
            [],
            ['HTTP_Authorization' => "Bearer $jwtToken"]
        );
        $this->assertResponseIsSuccessful();

        $this->client->request(
            'POST',
            '/api/preferences',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_Authorization' => "Bearer $jwtToken"],
            json_encode(['newChallenge' => true])
        );
        $this->assertResponseIsSuccessful();

        $this->client->request(
            'POST',
            '/api/preferences',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_Authorization' => "Bearer $jwtToken"],
            ''
        );
        $this->assertResponseIsSuccessful();

        $json = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($json);
        $this->assertTrue($json['newChallenge']);

        $this->entityManager->clear();
        $prefs = $this->entityManager->getRepository(NotificationPreference::class)->findAll();
        $this->assertCount(1, $prefs);
        $this->assertTrue($prefs[0]->isNewChallenge(), 'Sans newChallenge dans le body, l’état ne doit pas changer');
    }

    protected function setUp(): void
    {
        $this->client = PreferencesControllerTest::createClient();
        $this->entityManager = PreferencesControllerTest::getContainer()->get(EntityManagerInterface::class);
        $this->passwordHasher = PreferencesControllerTest::getContainer()->get(UserPasswordHasherInterface::class);

        $this->entityManager->createQuery('DELETE FROM App\Entity\User u')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\NotificationPreference np')->execute();

        $user = (new User())
            ->setUsername('user')
            ->setEmail('user@user')
            ->setRoles(['ROLE_USER']);
        $user->setPassword($this->passwordHasher->hashPassword($user, 'password'));
        $this->entityManager->persist($user);

        $this->entityManager->flush();
    }
}
