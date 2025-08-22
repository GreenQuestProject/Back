<?php

namespace App\DataFixtures;

use App\Entity\Challenge;
use App\Entity\Progression;
use App\Entity\User;
use App\Enum\ChallengeCategory;
use App\Enum\ChallengeStatus;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;
use Faker\Generator;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Yaml\Yaml;

class AppFixturesTest extends Fixture implements FixtureGroupInterface
{
    private Generator $faker;
    public function __construct(
        private UserPasswordHasherInterface $hasher,
        private KernelInterface $kernel
    ) {
        $this->faker = Factory::create('fr_FR');
        // $this->faker->seed(1234); // décommenter si tu veux des données reproductibles
    }



    public function load(ObjectManager $manager): void
    {
        // --- Utilisateurs (exemple) ---
        $users = [];

        $public = (new User())
            ->setUsername('public')->setRoles(['ROLE_PUBLIC'])
            ->setEmail('public@example.org')
            ->setPassword($this->hasher->hashPassword(new User(), 'public'));
        $manager->persist($public);

        $admin = (new User())
            ->setUsername('admin')->setRoles(['ROLE_ADMIN'])
            ->setEmail('admin@example.org')
            ->setPassword($this->hasher->hashPassword(new User(), 'password'));
        $manager->persist($admin);

        for ($i = 0; $i < 10; $i++) {
            $u = new User();
            $u->setUsername($this->faker->unique()->userName());
            $u->setRoles(['ROLE_USER']);
            $u->setEmail($this->faker->unique()->safeEmail());
            $u->setPassword($this->hasher->hashPassword($u, 'password'));
            $manager->persist($u);
            $users[] = $u;
        }

        // --- Lecture YAML ---
        $path = $this->kernel->getProjectDir() . '/fixtures/challenges.fr.yaml';
        $cfg  = Yaml::parseFile($path);

        $counts        = $cfg['counts'] ?? [];
        $templatesByCat= $cfg['categories'] ?? [];
        $weights       = $cfg['status_weights'] ?? [
            'pending'     => 30,
            'in_progress' => 35,
            'completed'   => 25,
            'failed'      => 10,
        ];

        // --- Génération des défis ---
        $challenges = [];
        foreach (ChallengeCategory::cases() as $case) {
            $catValue = $case->value;
            $tpls = $templatesByCat[$catValue] ?? [];

            // par défaut 5 (sauf NONE = 1)
            $count = $counts[$catValue] ?? ($case === ChallengeCategory::NONE ? 1 : 5);

            for ($i = 0; $i < $count; $i++) {
                $tpl = $tpls ? $this->faker->randomElement($tpls) : [
                    'name' => 'Défi #{n}',
                    'desc' => 'Objectif personnel sur {days} jours.',
                ];

                $vars = $this->vars();
                $c = new Challenge();
                $c->setCategory($case);
                $c->setName($this->fill($tpl['name'], $vars));
                $c->setDescription($this->fill($tpl['desc'], $vars));
                $manager->persist($c);
                $challenges[] = $c;
            }
        }

        // --- Progressions : pondération + dates cohérentes ---
        foreach ($users as $user) {
            $userChallenges = $this->faker->randomElements(
                $challenges,
                $this->faker->numberBetween(3, 7)
            );

            foreach ($userChallenges as $challenge) {
                $p = new Progression();
                $p->setUser($user);
                $p->setChallenge($challenge);

                $statusKey = $this->pickWeighted($weights);
                $status    = ChallengeStatus::from($statusKey);
                $p->setStatus($status);

                switch ($status) {
                    case ChallengeStatus::PENDING:
                        break;
                    case ChallengeStatus::IN_PROGRESS:
                        $started = $this->faker->dateTimeBetween('-20 days', '-1 day');
                        $p->setStartedAt($started);
                        break;
                    case ChallengeStatus::COMPLETED:
                        $started = $this->faker->dateTimeBetween('-45 days', '-15 days');
                        $ended   = $this->faker->dateTimeBetween($started, 'now');
                        $p->setStartedAt($started);
                        $p->setCompletedAt($ended);
                        break;
                    case ChallengeStatus::FAILED:
                        $started = $this->faker->dateTimeBetween('-30 days', '-5 days');
                        $p->setStartedAt($started);
                        break;
                }

                $manager->persist($p);
            }
        }

        $manager->flush();
    }

    /** Placeholders -> valeurs (ex: {days}) */
    private function fill(string $s, array $vars): string
    {
        foreach ($vars as $k => $v) {
            $s = str_replace('{'.$k.'}', (string)$v, $s);
        }
        return $s;
    }

    /** Variables pour remplir les templates YAML */
    private function vars(): array
    {
        return [
            'days'     => $this->faker->randomElement([3,5,7,10,14]),
            'km'       => $this->faker->numberBetween(2, 15),
            'co2'      => $this->faker->randomFloat(1, 0.3, 6.0),
            'ideas'    => $this->faker->numberBetween(3, 8),
            'count'    => $this->faker->numberBetween(2, 6),
            'minutes'  => $this->faker->numberBetween(10, 40),
            'grams'    => $this->faker->numberBetween(200, 800),
            'distance' => $this->faker->numberBetween(50, 800),
            'steps'    => $this->faker->randomElement([6000, 8000, 10000, 12000]),
            'break'    => $this->faker->randomElement([5, 7, 10]),
            'liters'   => $this->faker->randomElement([1.5, 2.0, 2.5]),
            'hours'    => $this->faker->randomElement([1, 2, 3]),
            'bags'     => $this->faker->numberBetween(1, 6),
            'items'    => $this->faker->numberBetween(5, 20),
            'people'   => $this->faker->numberBetween(3, 12),
            'books'    => $this->faker->numberBetween(1, 3),
            'sentences'=> $this->faker->numberBetween(3, 6),
            'actions'  => $this->faker->numberBetween(5, 12),
            'weeks'    => $this->faker->randomElement([2, 3, 4]),
            'hour'     => $this->faker->randomElement([22, 23]),
            'screen'   => $this->faker->randomElement([20, 30, 45, 60]),
        ];
    }

    /** Choix pondéré d’une clé (ex: status)
     * @throws \Exception
     */
    private function pickWeighted(array $weights): string
    {
        $sum = array_sum($weights);
        $r = random_int(1, max(1, $sum));
        $acc = 0;
        foreach ($weights as $key => $w) {
            $acc += $w;
            if ($r <= $acc) return (string)$key;
        }
        return (string)array_key_first($weights);
    }

    public static function getGroups(): array
    {
        return ['test'];
    }
}
