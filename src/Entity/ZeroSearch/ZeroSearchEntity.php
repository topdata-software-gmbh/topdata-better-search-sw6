<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Entity\ZeroSearch;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class ZeroSearchEntity extends Entity
{
    use EntityIdTrait;

    protected string $term;
    protected int $count;
    protected ?\DateTimeInterface $lastSearchedAt = null;

    public function getTerm(): string
    {
        return $this->term;
    }

    public function setTerm(string $term): void
    {
        $this->term = $term;
    }

    public function getCount(): int
    {
        return $this->count;
    }

    public function setCount(int $count): void
    {
        $this->count = $count;
    }

    public function getLastSearchedAt(): ?\DateTimeInterface
    {
        return $this->lastSearchedAt;
    }

    public function setLastSearchedAt(?\DateTimeInterface $lastSearchedAt): void
    {
        $this->lastSearchedAt = $lastSearchedAt;
    }
}
