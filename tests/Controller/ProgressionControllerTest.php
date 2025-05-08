<?php

namespace App\Tests\Controller;

use App\Entity\Challenge;
use App\Entity\Progression;
use App\Entity\User;
use App\Enum\ChallengeCategory;
use App\Enum\ChallengeStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ProgressionControllerTest extends WebTestCase{
    private EntityManagerInterface $entityManager;
    private KernelBrowser $client;
    private UserPasswordHasherInterface $passwordHasher;
    private User $user;
    private Challenge $challenge;
    protected function setUp(): void
    {
        //self::bootKernel(); // Lance le kernel Symfony
        $this->client = ChallengeControllerTest::createClient();
        $this->entityManager = ChallengeControllerTest::getContainer()->get(EntityManagerInterface::class);
        $this->passwordHasher = ChallengeControllerTest::getContainer()->get(UserPasswordHasherInterface::class);

        // Nettoyage si besoin (sécurité en cas de test planté précédemment)
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Challenge')->execute();

        // Créer un user
        $this->user = (new User())
            ->setUsername('user')
            ->setEmail('user@user')
            ->setRoles(['ROLE_USER']);
        $this->user->setPassword($this->passwordHasher->hashPassword($user, 'password'));
        $this->entityManager->persist($this->user);

        // Créer un challenge
        $this->challenge = (new Challenge())
            ->setName('test')
            ->setCategory(ChallengeCategory::NONE)
            ->setDescription('A cool description');
        $this->entityManager->persist($this->challenge);

        $this->entityManager->flush();
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

    private function createProgression(User $user): Progression
    {
        $progression = new Progression();
        $progression->setUser($this->user)
        ->setChallenge($this->challenge)
        ->setStatus(ChallengeStatus::PENDING)->setStartedAt();

        $this->entityManager->persist($progression);
        $this->entityManager->flush();

        return $progression;
    }

    private function deleteChallenge(Challenge $challenge): void
    {
        $challenge = $this->entityManager->getRepository(Challenge::class)->findOneBy(['name' => $challenge->getName()]);

        $this->entityManager->remove($challenge);
        $this->entityManager->flush();
    }
}
