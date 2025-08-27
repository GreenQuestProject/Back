<?php

namespace App\Entity;

use App\Repository\ReminderRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ReminderRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_progression_active', columns: ['progression_id', 'active'])]
class Reminder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $scheduledAtUtc = null;

    #[ORM\Column(length: 16)]
    private ?string $recurrence = 'NONE'; // NONE|DAILY|WEEKLY

    #[ORM\Column(length: 64)]
    private ?string $timezone = null; // ex: Europe/Paris

    #[ORM\Column]
    private ?bool $active = true;

    #[ORM\ManyToOne(inversedBy: 'reminders')]
    #[ORM\JoinColumn(nullable: false, onDelete: "CASCADE")]
    private ?Progression $progression = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getScheduledAtUtc(): ?\DateTimeImmutable
    {
        return $this->scheduledAtUtc;
    }

    public function setScheduledAtUtc(\DateTimeImmutable $scheduledAtUtc): static
    {
        $this->scheduledAtUtc = $scheduledAtUtc;

        return $this;
    }

    public function getRecurrence(): ?string
    {
        return $this->recurrence;
    }

    public function setRecurrence(string $recurrence): static
    {
        $this->recurrence = $recurrence;

        return $this;
    }

    public function getTimezone(): ?string
    {
        return $this->timezone;
    }

    public function setTimezone(string $timezone): static
    {
        $this->timezone = $timezone;

        return $this;
    }

    public function isActive(): ?bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getProgression(): ?Progression
    {
        return $this->progression;
    }

    public function setProgression(?Progression $progression): static
    {
        $this->progression = $progression;

        return $this;
    }
}
