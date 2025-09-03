<?php

namespace App\Entity;

use App\Enum\ChallengeCategory;
use App\Repository\ChallengeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ChallengeRepository::class)]
class Challenge
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(["getAll"])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: "Le nom ne doit pas être vide")]
    #[Assert\NotNull(message: "Le nom ne doit pas être vide")]
    #[Groups(["getAll"])]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(["getAll"])]
    private ?string $description = null;

    #[ORM\Column(type: 'string', enumType: ChallengeCategory::class)]
    #[Groups(["getAll"])]
    private ChallengeCategory $category = ChallengeCategory::NONE;

    /**
     * @var Collection<int, Progression>
     */
    #[ORM\OneToMany(targetEntity: Progression::class, mappedBy: 'challenge', cascade: ['remove'], orphanRemoval: true)]
    private Collection $progressions;

    #[ORM\Column(type: 'smallint', options: ['default' => 1])]
    #[Assert\Range(min: 1, max: 5)]
    #[Groups(["getAll"])]
    private ?int $difficulty = 1;

    #[ORM\Column(options: ['default' => 50])]
    #[Assert\Positive]
    #[Groups(["getAll"])]
    private ?int $basePoints = 50;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 3, options: ['default' => 0])]
    #[Groups(["getAll"])]
    private ?string $co2EstimateKg = '0';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 3, options: ['default' => 0])]
    #[Groups(["getAll"])]
    private ?string $waterEstimateL = '0';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 3, options: ['default' => 0])]
    #[Groups(["getAll"])]
    private ?string $wasteEstimateKg = '0';

    #[ORM\Column(options: ['default' => true])]
    #[Groups(["getAll"])]
    private ?bool $isRepeatable = true;

    public function __construct()
    {
        $this->progressions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getCategory(): ChallengeCategory
    {
        return $this->category;
    }

    public function setCategory(ChallengeCategory $category): self
    {
        $this->category = $category;
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
            $progression->setChallenge($this);
        }

        return $this;
    }

    public function removeProgression(Progression $progression): static
    {
        if ($this->progressions->removeElement($progression)) {
            if ($progression->getChallenge() === $this) {
                $progression->setChallenge(null);
            }
        }

        return $this;
    }

    public function getDifficulty(): ?int
    {
        return $this->difficulty;
    }

    public function setDifficulty(int $difficulty): static
    {
        $this->difficulty = $difficulty;

        return $this;
    }

    public function getBasePoints(): ?int
    {
        return $this->basePoints;
    }

    public function setBasePoints(int $basePoints): static
    {
        $this->basePoints = $basePoints;

        return $this;
    }

    public function getCo2EstimateKg(): ?string
    {
        return $this->co2EstimateKg;
    }

    public function setCo2EstimateKg(string $co2EstimateKg): static
    {
        $this->co2EstimateKg = $co2EstimateKg;

        return $this;
    }

    public function getWaterEstimateL(): ?string
    {
        return $this->waterEstimateL;
    }

    public function setWaterEstimateL(string $waterEstimateL): static
    {
        $this->waterEstimateL = $waterEstimateL;

        return $this;
    }

    public function getWasteEstimateKg(): ?string
    {
        return $this->wasteEstimateKg;
    }

    public function setWasteEstimateKg(string $wasteEstimateKg): static
    {
        $this->wasteEstimateKg = $wasteEstimateKg;

        return $this;
    }

    public function isRepeatable(): ?bool
    {
        return $this->isRepeatable;
    }

    public function setIsRepeatable(bool $isRepeatable): static
    {
        $this->isRepeatable = $isRepeatable;

        return $this;
    }

    public function getCo2EstimateKgFloat(): float
    {
        return (float)$this->co2EstimateKg;
    }

    public function getWaterEstimateLFloat(): float
    {
        return (float)$this->waterEstimateL;
    }

    public function getWasteEstimateKgFloat(): float
    {
        return (float)$this->wasteEstimateKg;
    }


}
