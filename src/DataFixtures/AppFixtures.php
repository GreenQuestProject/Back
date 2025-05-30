<?php

namespace App\DataFixtures;

use App\Entity\Challenge;
use App\Entity\Progression;
use App\Enum\ChallengeCategory;
use App\Enum\ChallengeStatus;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;
use App\Entity\User;
use Faker\Generator;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    /**
     * @var Generator
     */
    private Generator $faker;

    /**
     * Password Hasher
     *
     * @var UserPasswordHasherInterface
     */
    private $userPasswordHasher;

    public function __construct(UserPasswordHasherInterface $userPasswordHasher){
        $this->faker = Factory::create('fr_FR');
        $this->userPasswordHasher = $userPasswordHasher;
    }

    public function load(ObjectManager $manager): void
    {
        // Public
        $publicUser = new User();
        $publicUser->setUsername("public");
        $publicUser->setRoles(["ROLE_PUBLIC"]);
        $publicUser->setEmail("public@public");
        $publicUser->setPassword($this->userPasswordHasher->hashPassword($publicUser, "public"));
        $manager->persist($publicUser);

        // Authentifiés
        $users = [];
        for ($i = 0; $i <  5; $i++) {
            $userUser = new User();
            $password = $this->faker->password(2, 6);
            $username = $this->faker->userName();
            $userUser->setUsername($username);
            $userUser->setRoles(["ROLE_USER"]);
            $userUser->setEmail($username . "@". $password);
            $userUser->setPassword($this->userPasswordHasher->hashPassword($userUser, $password));
            $manager->persist($userUser);
            $users[] = $userUser;
        }

        // Admins
        $adminUser = new User();
        $adminUser->setUsername("admin");
        $adminUser->setRoles(["ROLE_ADMIN"]);
        $adminUser->setEmail("admin@password");
        $adminUser->setPassword($this->userPasswordHasher->hashPassword($adminUser, "password"));
        $manager->persist($adminUser);

        $categories = ChallengeCategory::cases();
        $challenges = [];
        for ($i = 0; $i < 10; $i++) {
            $challenge = new Challenge();
            $challenge->setName($this->faker->sentence(3));
            $challenge->setDescription($this->faker->paragraph());
            $challenge->setCategory($this->faker->randomElement($categories));
            $manager->persist($challenge);
            $challenges[] = $challenge;
        }

        foreach ($users as $user) {
            $userChallenges = $this->faker->randomElements($challenges, rand(2, 5));

            foreach ($userChallenges as $challenge) {
                $progression = new Progression();
                $progression->setUser($user);
                $progression->setChallenge($challenge);

                // Statut aléatoire
                $status = $this->faker->randomElement(ChallengeStatus::cases());
                $progression->setStatus($status);

                // Dates réalistes
                if (in_array($status, [ChallengeStatus::IN_PROGRESS, ChallengeStatus::COMPLETED, ChallengeStatus::FAILED])) {
                    $startedAt = $this->faker->dateTimeBetween('-2 months', 'now');
                    $progression->setStartedAt($startedAt);

                    if ($status === ChallengeStatus::COMPLETED) {
                        $completedAt = $this->faker->dateTimeBetween($startedAt, 'now');
                        $progression->setCompletedAt($completedAt);
                    }
                }

                $manager->persist($progression);
            }
        }

        $manager->flush();
    }
}
