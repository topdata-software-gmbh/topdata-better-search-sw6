<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Service;

use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

class SearchBackendRegistry
{
    /**
     * @var array<string, SearchBackendInterface>
     */
    private array $backends = [];

    /**
     * @param iterable<SearchBackendInterface> $backends
     */
    public function __construct(
        #[TaggedIterator('tdbs.search_backend')] iterable $backends
    ) {
        foreach ($backends as $backend) {
            $this->backends[$backend->getName()] = $backend;
        }
    }

    public function getBackend(string $name): ?SearchBackendInterface
    {
        return $this->backends[$name] ?? null;
    }

    /**
     * @return SearchBackendInterface[]
     */
    public function getActiveBackends(): array
    {
        return array_values($this->backends);
    }
}
