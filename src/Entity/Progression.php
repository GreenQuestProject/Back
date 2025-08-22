<?php

namespace App\Entity;

use App\Enum\ChallengeStatus;
use App\Repository\ProgressionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: ProgressionRepository::class)]
class Progression
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'progressions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(["getAll"])]
    private ?User $user = null;

    #[ORM\ManyToOne(inversedBy: 'progressions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(["getAll"])]
    private ?Challenge $challenge = null;

    #[ORM\Column(type: 'string', enumType: ChallengeStatus::class)]
    #[Groups(["getAll"])]
    private ChallengeStatus $status = ChallengeStatus::PENDING;

    #[ORM\Column(type: 'boolean')]
    #[Groups(["getAll"])]
    private bool $isAccomplished = false;

    #[ORM\Column(type: 'datetime', nullable: true)]
    #[Groups(["getAll"])]
    private ?\DateTimeInterface $startedAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    #[Groups(["getAll"])]
    private ?\DateTimeInterface $completedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getChallenge(): ?Challenge
    {
        return $this->challenge;
    }

    public function setChallenge(?Challenge $challenge): static
    {
        $this->challenge = $challenge;

        return $this;
    }

    public function getStatus(): ChallengeStatus
    {
        return $this->status;
    }

    public function setStatus(ChallengeStatus $status): self
    {
        $this->status = $status;

        if ($status === ChallengeStatus::COMPLETED) {
            $this->isAccomplished = true;
            $this->completedAt = new \DateTimeImmutable();
        } else {
            $this->isAccomplished = false;
            $this->completedAt = null;
        }

        return $this;
    }

    public function isAccomplished(): bool
    {
        return $this->isAccomplished;
    }

    public function getStartedAt(): ?\DateTimeInterface
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeInterface $startedAt): self
    {
        $this->startedAt = $startedAt;
        return $this;
    }

    public function getCompletedAt(): ?\DateTimeInterface
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeInterface $completedAt): self
    {
        $this->completedAt = $completedAt;
        return $this;
    }
}
