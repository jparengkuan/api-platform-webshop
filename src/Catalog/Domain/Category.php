<?php

namespace App\Catalog\Domain;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Catalog\Domain\Exception\CategoryInUseException;
use App\Catalog\Infrastructure\ApiPlatform\State\CategoryRemoveProcessor;
use App\Catalog\Infrastructure\Doctrine\CategoryRepository;
use App\Shared\Infrastructure\ApiPlatform\CacheHeaders;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A product category, e.g. "Kleding". Every product belongs to exactly one category.
 */
#[ORM\Entity(repositoryClass: CategoryRepository::class)]
#[UniqueEntity(fields: ['name'], message: 'A category with this name already exists.')]
#[UniqueEntity(fields: ['slug'], message: 'A category with this slug already exists.')]
#[ApiResource(
    operations: [
        new GetCollection(cacheHeaders: CacheHeaders::PUBLIC_CATALOG),
        new Get(cacheHeaders: CacheHeaders::PUBLIC_CATALOG),
        new Post(security: "is_granted('ROLE_ADMIN')"),
        new Patch(security: "is_granted('ROLE_ADMIN')"),
        new Delete(
            security: "is_granted('ROLE_ADMIN')",
            processor: CategoryRemoveProcessor::class,
            exceptionToStatus: [CategoryInUseException::class => 409],
        ),
    ],
    normalizationContext: ['groups' => ['category:read']],
    denormalizationContext: ['groups' => ['category:write']],
    order: ['name' => 'ASC'],
    paginationEnabled: false,
)]
class Category
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ApiProperty(identifier: true)]
    #[Groups(['category:read', 'product:read'])]
    private Uuid $id;

    #[ORM\Column(length: 100, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    #[Groups(['category:read', 'category:write', 'product:read'])]
    private ?string $name = null;

    /** URL-friendly identifier, e.g. "mokken-flessen"; derived from the name unless set explicitly. */
    #[ORM\Column(length: 100, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    #[Assert\Regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', message: 'Use lowercase letters, digits and single dashes.')]
    #[Groups(['category:read', 'category:write', 'product:read'])]
    private ?string $slug = null;

    /**
     * Creates a category with a time-ordered UUID.
     */
    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    /**
     * Returns the unique identifier of this category.
     */
    public function getId(): Uuid
    {
        return $this->id;
    }

    /**
     * Returns the display name.
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Sets the display name; also derives the slug when none has been set yet.
     */
    public function setName(string $name): static
    {
        $this->name = $name;
        $this->slug ??= new AsciiSlugger()->slug($name)->lower()->toString();

        return $this;
    }

    /**
     * Returns the URL-friendly identifier.
     */
    public function getSlug(): ?string
    {
        return $this->slug;
    }

    /**
     * Overrides the slug derived from the name.
     */
    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }
}
