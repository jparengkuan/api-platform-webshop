<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Category;
use App\Exception\CategoryInUseException;
use App\Repository\CategoryRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Deletes a category only when no product belongs to it anymore.
 *
 * @implements ProcessorInterface<Category, null>
 */
final readonly class CategoryRemoveProcessor implements ProcessorInterface
{
    /**
     * Creates the processor, decorating API Platform's Doctrine remove processor.
     *
     * @param ProcessorInterface<Category, null> $removeProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.remove_processor')]
        private ProcessorInterface $removeProcessor,
        private CategoryRepository $categories,
    ) {
    }

    /**
     * Refuses to delete a category that still has products, instead of failing on the foreign key.
     *
     * @param Category $data
     *
     * @throws CategoryInUseException when products still belong to the category
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        if ($this->categories->hasProducts($data)) {
            throw new CategoryInUseException((string) $data->getName());
        }

        return $this->removeProcessor->process($data, $operation, $uriVariables, $context);
    }
}
