<?php

declare(strict_types=1);

namespace Rector\DeadCode;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\PropertyProperty;
use Rector\Core\Exception\ShouldNotHappenException;
use Rector\Core\PhpParser\Node\Commander\NodeRemovingCommander;
use Rector\Core\PhpParser\Node\Manipulator\PropertyManipulator;
use Rector\Core\PhpParser\Node\Resolver\NodeNameResolver;
use Rector\NodeTypeResolver\Node\AttributeKey;

final class NodeRemover
{
    /**
     * @var NodeRemovingCommander
     */
    private $nodeRemovingCommander;

    /**
     * @var PropertyManipulator
     */
    private $propertyManipulator;

    /**
     * @var NodeNameResolver
     */
    private $nodeNameResolver;

    public function __construct(
        NodeRemovingCommander $nodeRemovingCommander,
        PropertyManipulator $propertyManipulator,
        NodeNameResolver $nodeNameResolver
    ) {
        $this->nodeRemovingCommander = $nodeRemovingCommander;
        $this->propertyManipulator = $propertyManipulator;
        $this->nodeNameResolver = $nodeNameResolver;
    }

    /**
     * @param string[] $classMethodNamesToSkip
     */
    public function removePropertyAndUsages(
        PropertyProperty $propertyProperty,
        array $classMethodNamesToSkip = []
    ): void {
        $shouldKeepProperty = false;

        $propertyFetches = $this->propertyManipulator->getAllPropertyFetch($propertyProperty);
        foreach ($propertyFetches as $propertyFetch) {
            if ($this->shouldSkipPropertyForClassMethod($propertyFetch, $classMethodNamesToSkip)) {
                $shouldKeepProperty = true;
                continue;
            }

            $assign = $this->resolveAssign($propertyFetch);

            $this->removeAssignNode($assign);
        }

        if ($shouldKeepProperty) {
            return;
        }

        /** @var Property $property */
        $property = $propertyProperty->getAttribute(AttributeKey::PARENT_NODE);

        $this->nodeRemovingCommander->addNode($propertyProperty);

        foreach ($property->props as $prop) {
            if (! $this->nodeRemovingCommander->isNodeRemoved($prop)) {
                // if the property has at least one node left -> return
                return;
            }
        }

        $this->nodeRemovingCommander->addNode($property);
    }

    /**
     * @param StaticPropertyFetch|PropertyFetch $expr
     * @param string[] $classMethodNamesToSkip
     */
    private function shouldSkipPropertyForClassMethod(Expr $expr, array $classMethodNamesToSkip): bool
    {
        /** @var ClassMethod|null $classMethodNode */
        $classMethodNode = $expr->getAttribute(AttributeKey::METHOD_NODE);
        if ($classMethodNode === null) {
            return false;
        }

        $classMethodName = $this->nodeNameResolver->getName($classMethodNode);
        if ($classMethodName === null) {
            return false;
        }

        return in_array($classMethodName, $classMethodNamesToSkip, true);
    }

    private function resolveAssign(PropertyFetch $propertyFetch): Assign
    {
        $assign = $propertyFetch->getAttribute(AttributeKey::PARENT_NODE);
        while ($assign !== null && ! $assign instanceof Assign) {
            $assign = $assign->getAttribute(AttributeKey::PARENT_NODE);
        }

        if (! $assign instanceof Assign) {
            throw new ShouldNotHappenException("Can't handle this situation");
        }

        return $assign;
    }
}
