<?php

namespace App\Entity;

use App\Repository\UserRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
#[UniqueEntity('email', message: "Cet email est déjà pris, essayez-en un autre")]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank(message: "L’email ne doit pas être vide")]
    #[Assert\NotNull(message: "L’email ne doit pas être vide")]
    #[Groups(["getAll"])]
    private ?string $email = null;

    /**
     * @var list<string> The user roles
     */
    #[ORM\Column]
    private array $roles = [];

    /**
     * @var string The hashed password
     */
    #[ORM\Column]
    #[Assert\NotBlank(message: "The password must not be empty")]
    #[Assert\NotNull(message: "The password must not be empty")]
    private ?string $password = null;

    #[ORM\Column(length: 255, unique: true)]
    #[Assert\NotBlank(message: "Le mot de passe ne doit pas être vide")]
    #[Assert\NotNull(message: "Le mot de passe ne doit pas être vide")]
    #[Groups(["getAll"])]
    private ?string $username = null;

    /**
     * @var Collection<int, Progression>
     */
    #[ORM\OneToMany(targetEntity: Progression::class, mappedBy: 'user', cascade: ['remove'], orphanRemoval: true)]
    private Collection $progressions;

    #[ORM\OneToOne(mappedBy: 'user', cascade: ['persist', 'remove'])]
    private ?NotificationPreference $notificationPreference = null;

    /**
     * @var Collection<int, XpLedger>
     */
    #[ORM\OneToMany(targetEntity: XpLedger::class, mappedBy: 'user')]
    private Collection $xpLedgers;

    /**
     * @var Collection<int, BadgeUnlock>
     */
    #[ORM\OneToMany(targetEntity: BadgeUnlock::class, mappedBy: 'user')]
    private Collection $badgeUnlocks;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?DateTimeImmutable $createdAt = null;


    public function __construct()
    {
        $this->progressions = new ArrayCollection();
        $this->xpLedgers = new ArrayCollection();
        $this->badgeUnlocks = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string)$this->username;
    }

    /**
     * @return list<string>
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
        // $this->plainPassword = null;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(string $username): static
    {
        $this->username = $username;

        return $this;
    }

    /**
     * @return Collection<int, Progression>
     */
    public function getProgressions(): Collection
    {
        return $this->progressions;
    }

    public function addProgression(Progression $progression): static
    {
        if (!$this->progressions->contains($progression)) {
            $this->progressions->add($progression);
            $progression->setUser($this);
        }

        return $this;
    }

    public function removeProgression(Progression $progression): static
    {
        if ($this->progressions->removeElement($progression)) {
            if ($progression->getUser() === $this) {
                $progression->setUser(null);
            }
        }

        return $this;
    }

    public function getNotificationPreference(): ?NotificationPreference
    {
        return $this->notificationPreference;
    }

    public function setNotificationPreference(NotificationPreference $notificationPreference): static
    {
        if ($notificationPreference->getUser() !== $this) {
            $notificationPreference->setUser($this);
        }

        $this->notificationPreference = $notificationPreference;

        return $this;
    }

    /**
     * @return Collection<int, XpLedger>
     */
    public function getXpLedgers(): Collection
    {
        return $this->xpLedgers;
    }

    public function addXpLedger(XpLedger $xpLedger): static
    {
        if (!$this->xpLedgers->contains($xpLedger)) {
            $this->xpLedgers->add($xpLedger);
            $xpLedger->setUser($this);
        }

        return $this;
    }

    public function removeXpLedger(XpLedger $xpLedger): static
    {
        if ($this->xpLedgers->removeElement($xpLedger)) {
            if ($xpLedger->getUser() === $this) {
                $xpLedger->setUser(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, BadgeUnlock>
     */
    public function getBadgeUnlocks(): Collection
    {
        return $this->badgeUnlocks;
    }

    public function addBadgeUnlock(BadgeUnlock $badgeUnlock): static
    {
        if (!$this->badgeUnlocks->contains($badgeUnlock)) {
            $this->badgeUnlocks->add($badgeUnlock);
            $badgeUnlock->setUser($this);
        }

        return $this;
    }

    public function removeBadgeUnlock(BadgeUnlock $badgeUnlock): static
    {
        if ($this->badgeUnlocks->removeElement($badgeUnlock)) {
            if ($badgeUnlock->getUser() === $this) {
                $badgeUnlock->setUser(null);
            }
        }

        return $this;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }
}
