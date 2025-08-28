<?php

namespace App\Entity;

use App\Repository\PushSubscriptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PushSubscriptionRepository::class)]
class PushSubscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: "CASCADE")]
    private ?User $user = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $endpoint = null;

    #[ORM\Column(length: 255)]
    private ?string $p256dh = null;

    #[ORM\Column(length: 255)]
    private ?string $auth = null;

    #[ORM\Column(length: 32)]
    private ?string $encoding = null;

    #[ORM\Column]
    private ?bool $active = true;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(length: 64, unique: true)]
    private ?string $endpointHash = null;

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

    public function getEndpoint(): ?string
    {
        return $this->endpoint;
    }

    public function setEndpoint(string $endpoint): static
    {
        $this->endpoint = $endpoint;
        $this->endpointHash = hash('sha256', $endpoint);
        return $this;
    }

    public function getP256dh(): ?string
    {
        return $this->p256dh;
    }

    public function setP256dh(string $p256dh): static
    {
        $this->p256dh = $p256dh;

        return $this;
    }

    public function getAuth(): ?string
    {
        return $this->auth;
    }

    public function setAuth(string $auth): static
    {
        $this->auth = $auth;

        return $this;
    }

    public function getEncoding(): ?string
    {
        return $this->encoding;
    }

    public function setEncoding(string $encoding): static
    {
        $this->encoding = $encoding;

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

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getEndpointHash(): ?string
    {
        return $this->endpointHash;
    }

    public function setEndpointHash(string $endpointHash): static
    {
        $this->endpointHash = $endpointHash;

        return $this;
    }
}
