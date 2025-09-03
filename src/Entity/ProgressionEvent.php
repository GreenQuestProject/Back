<?php

namespace App\Entity;

use App\Repository\ProgressionEventRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ProgressionEventRepository::class)]
class ProgressionEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'progressionEvents')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Progression $progression = null;

    #[ORM\Column(length: 12)]
    #[Assert\Choice(['viewed', 'started', 'step', 'done', 'abandoned'])]
    private ?string $eventType = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $meta = null;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private ?DateTimeImmutable $occurredAt = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getEventType(): ?string
    {
        return $this->eventType;
    }

    public function setEventType(string $eventType): static
    {
        $this->eventType = $eventType;

        return $this;
    }

    public function getMeta(): ?array
    {
        return $this->meta;
    }

    public function setMeta(?array $meta): static
    {
        $this->meta = $meta;

        return $this;
    }

    public function getOccurredAt(): ?DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function setOccurredAt(DateTimeImmutable $occurredAt): static
    {
        $this->occurredAt = $occurredAt;

        return $this;
    }
}
