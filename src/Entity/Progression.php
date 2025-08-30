<?php

namespace App\Entity;

use App\Enum\ChallengeStatus;
use App\Repository\ProgressionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

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

    /**
     * @var Collection<int, Reminder>
     */
    #[ORM\OneToMany(targetEntity: Reminder::class, mappedBy: 'progression', orphanRemoval: true)]
    private Collection $reminders;

    #[ORM\Column(options: ['default' => 0])]
    private ?int $pointsAwarded = 0;

    #[ORM\Column(options: ['default' => 0])]
    #[Assert\GreaterThanOrEqual(0)]
    private ?int $repetitionIndex = 0;

    /**
     * @var Collection<int, ProgressionEvent>
     */
    #[ORM\OneToMany(targetEntity: ProgressionEvent::class, mappedBy: 'progression')]
    private Collection $progressionEvents;

    public function __construct()
    {
        $this->reminders = new ArrayCollection();
        $this->progressionEvents = new ArrayCollection();
    }

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

    /**
     * @return Collection<int, Reminder>
     */
    public function getReminders(): Collection
    {
        return $this->reminders;
    }

    public function addReminder(Reminder $reminder): static
    {
        if (!$this->reminders->contains($reminder)) {
            $this->reminders->add($reminder);
            $reminder->setProgression($this);
        }

        return $this;
    }

    public function removeReminder(Reminder $reminder): static
    {
        if ($this->reminders->removeElement($reminder)) {
            // set the owning side to null (unless already changed)
            if ($reminder->getProgression() === $this) {
                $reminder->setProgression(null);
            }
        }

        return $this;
    }

    public function getActiveReminder(): ?Reminder
    {
        foreach ($this->reminders as $r) {
            if ($r->isActive()) {
                return $r;
            }
        }
        return null;
    }

    public function getPointsAwarded(): ?int
    {
        return $this->pointsAwarded;
    }

    public function setPointsAwarded(int $pointsAwarded): static
    {
        $this->pointsAwarded = $pointsAwarded;

        return $this;
    }

    public function getRepetitionIndex(): ?int
    {
        return $this->repetitionIndex;
    }

    public function setRepetitionIndex(int $repetitionIndex): static
    {
        $this->repetitionIndex = $repetitionIndex;

        return $this;
    }

    /**
     * @return Collection<int, ProgressionEvent>
     */
    public function getProgressionEvents(): Collection
    {
        return $this->progressionEvents;
    }

    public function addProgressionEvent(ProgressionEvent $progressionEvent): static
    {
        if (!$this->progressionEvents->contains($progressionEvent)) {
            $this->progressionEvents->add($progressionEvent);
            $progressionEvent->setProgression($this);
        }

        return $this;
    }

    public function removeProgressionEvent(ProgressionEvent $progressionEvent): static
    {
        if ($this->progressionEvents->removeElement($progressionEvent)) {
            // set the owning side to null (unless already changed)
            if ($progressionEvent->getProgression() === $this) {
                $progressionEvent->setProgression(null);
            }
        }

        return $this;
    }
}
