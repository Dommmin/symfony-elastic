<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'products', indexes: [
    new ORM\Index(name: 'idx_name', columns: ['name']),
    new ORM\Index(name: 'idx_name_price', columns: ['name', 'price']),
    new ORM\UniqueConstraint(name: 'uniq_name', columns: ['name']),
])]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string')]
    private string $name;

    #[ORM\Column(type: 'float')]
    private float $price;

    #[ORM\Column(type: 'text')]
    private string $description;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getPrice(): float
    {
        return $this->price;
    }

    public function setPrice(float $price): void
    {
        $this->price = $price;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function setDescription(string $description): void
    {
        $this->description = $description;
    }

    public function getDescription(): string
    {
        return $this->description;
    }
}
