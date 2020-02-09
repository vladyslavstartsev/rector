<?php

declare(strict_types=1);

namespace Rector\Core\PhpParser\Node\Manipulator;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Nop;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;
use PHPStan\Type\Type;
use Rector\Core\Exception\ShouldNotHappenException;
use Rector\Core\PhpParser\Node\Commander\NodeRemovingCommander;
use Rector\Core\PhpParser\Node\NodeFactory;
use Rector\Core\PhpParser\Node\Resolver\NodeNameResolver;
use Rector\Core\PhpParser\NodeTraverser\CallableNodeTraverser;
use Rector\Core\PhpParser\Printer\BetterStandardPrinter;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\NodeTypeResolver\NodeTypeResolver;

final class ClassManipulator
{
    /**
     * @var NodeNameResolver
     */
    private $nodeNameResolver;

    /**
     * @var NodeFactory
     */
    private $nodeFactory;

    /**
     * @var ChildAndParentClassManipulator
     */
    private $childAndParentClassManipulator;

    /**
     * @var CallableNodeTraverser
     */
    private $callableNodeTraverser;

    /**
     * @var NodeRemovingCommander
     */
    private $nodeRemovingCommander;

    /**
     * @var BetterStandardPrinter
     */
    private $betterStandardPrinter;

    /**
     * @var NodeTypeResolver
     */
    private $nodeTypeResolver;

    public function __construct(
        NodeNameResolver $nodeNameResolver,
        NodeFactory $nodeFactory,
        ChildAndParentClassManipulator $childAndParentClassManipulator,
        CallableNodeTraverser $callableNodeTraverser,
        NodeRemovingCommander $nodeRemovingCommander,
        BetterStandardPrinter $betterStandardPrinter,
        NodeTypeResolver $nodeTypeResolver
    ) {
        $this->nodeFactory = $nodeFactory;
        $this->nodeNameResolver = $nodeNameResolver;
        $this->childAndParentClassManipulator = $childAndParentClassManipulator;
        $this->callableNodeTraverser = $callableNodeTraverser;
        $this->nodeRemovingCommander = $nodeRemovingCommander;
        $this->betterStandardPrinter = $betterStandardPrinter;
        $this->nodeTypeResolver = $nodeTypeResolver;
    }

    public function addConstructorDependency(Class_ $classNode, string $name, ?Type $type): void
    {
        // add property
        // @todo should be factory
        $this->addPropertyToClass($classNode, $name, $type);

        // pass via constructor
        $this->addSimplePropertyAssignToClass($classNode, $name, $type);
    }

    /**
     * @param ClassMethod|Property|ClassMethod $stmt
     */
    public function addAsFirstMethod(Class_ $class, Stmt $stmt): void
    {
        if ($this->tryInsertBeforeFirstMethod($class, $stmt)) {
            return;
        }

        if ($this->tryInsertAfterLastProperty($class, $stmt)) {
            return;
        }

        $class->stmts[] = $stmt;
    }

    public function addAsFirstTrait(Class_ $class, string $traitName): void
    {
        $trait = new TraitUse([new FullyQualified($traitName)]);

        $this->addStatementToClassBeforeTypes($class, $trait, TraitUse::class, Property::class);
    }

    /**
     * @param Stmt[] $nodes
     * @return Stmt[] $nodes
     */
    public function insertBeforeAndFollowWithNewline(array $nodes, Stmt $stmt, int $key): array
    {
        $nodes = $this->insertBefore($nodes, $stmt, $key);
        return $this->insertBefore($nodes, new Nop(), $key);
    }

    /**
     * @param Stmt[] $nodes
     * @return Stmt[] $nodes
     */
    public function insertBefore(array $nodes, Stmt $stmt, int $key): array
    {
        array_splice($nodes, $key, 0, [$stmt]);

        return $nodes;
    }

    public function addPropertyToClass(Class_ $classNode, string $name, ?Type $type): void
    {
        if ($this->hasClassProperty($classNode, $name)) {
            return;
        }

        $propertyNode = $this->nodeFactory->createPrivatePropertyFromNameAndType($name, $type);
        $this->addAsFirstMethod($classNode, $propertyNode);
    }

    public function addSimplePropertyAssignToClass(Class_ $classNode, string $name, ?Type $type): void
    {
        $propertyAssignNode = $this->nodeFactory->createPropertyAssignment($name);
        $this->addConstructorDependencyWithCustomAssign($classNode, $name, $type, $propertyAssignNode);
    }

    public function addConstructorDependencyWithCustomAssign(
        Class_ $classNode,
        string $name,
        ?Type $type,
        Assign $assign
    ): void {
        $constructorMethod = $classNode->getMethod('__construct');
        /** @var ClassMethod $constructorMethod */
        if ($constructorMethod !== null) {
            $this->addParameterAndAssignToMethod($constructorMethod, $name, $type, $assign);
            return;
        }

        $constructorMethod = $this->nodeFactory->createPublicMethod('__construct');

        $this->addParameterAndAssignToMethod($constructorMethod, $name, $type, $assign);

        $this->childAndParentClassManipulator->completeParentConstructor($classNode, $constructorMethod);

        $this->addAsFirstMethod($classNode, $constructorMethod);

        $this->childAndParentClassManipulator->completeChildConstructors($classNode, $constructorMethod);
    }

    /**
     * @param Class_|Trait_ $classLike
     * @return Name[]
     */
    public function getUsedTraits(ClassLike $classLike): array
    {
        $usedTraits = [];
        foreach ($classLike->getTraitUses() as $stmt) {
            foreach ($stmt->traits as $trait) {
                $traitName = $this->nodeNameResolver->getName($trait);
                if ($traitName !== null) {
                    $usedTraits[$traitName] = $trait;
                }
            }
        }

        return $usedTraits;
    }

    public function getProperty(ClassLike $classLike, string $name): ?Property
    {
        foreach ($classLike->getProperties() as $property) {
            if (count($property->props) > 1) {
                // usually full property is needed to have all the docs values
                throw new ShouldNotHappenException();
            }

            if ($this->nodeNameResolver->isName($property, $name)) {
                return $property;
            }
        }

        return null;
    }

    public function hasParentMethodOrInterface(string $class, string $method): bool
    {
        if (! class_exists($class)) {
            return false;
        }

        $parentClass = $class;
        while ($parentClass = get_parent_class($parentClass)) {
            if (method_exists($parentClass, $method)) {
                return true;
            }
        }

        $implementedInterfaces = class_implements($class);
        foreach ($implementedInterfaces as $implementedInterface) {
            if (method_exists($implementedInterface, $method)) {
                return true;
            }
        }

        return false;
    }

    public function hasClassMethod(Class_ $classNode, string $methodName): bool
    {
        $methodNames = $this->getClassMethodNames($classNode);

        return in_array($methodName, $methodNames, true);
    }

    public function removeProperty(Class_ $class, string $propertyName): void
    {
        $this->removeProperties($class, [$propertyName]);
    }

    public function findMethodParamByName(ClassMethod $classMethod, string $name): ?Param
    {
        foreach ($classMethod->params as $param) {
            if (! $this->nodeNameResolver->isName($param, $name)) {
                continue;
            }

            return $param;
        }

        return null;
    }

    /**
     * @return string[]
     */
    public function getPrivatePropertyNames(Class_ $class): array
    {
        $privatePropertyNames = [];
        foreach ($class->getProperties() as $property) {
            if (! $property->isPrivate()) {
                continue;
            }

            /** @var string $propertyName */
            $propertyName = $this->nodeNameResolver->getName($property);
            $privatePropertyNames[] = $propertyName;
        }

        return $privatePropertyNames;
    }

    /**
     * @return string[]
     */
    public function getPublicMethodNames(Class_ $class): array
    {
        $publicMethodNames = [];
        foreach ($class->getMethods() as $method) {
            if ($method->isAbstract()) {
                continue;
            }

            if (! $method->isPublic()) {
                continue;
            }

            /** @var string $methodName */
            $methodName = $this->nodeNameResolver->getName($method);
            $publicMethodNames[] = $methodName;
        }

        return $publicMethodNames;
    }

    /**
     * @param string[] $propertyNames
     */
    public function removeProperties(Class_ $class, array $propertyNames): void
    {
        $this->callableNodeTraverser->traverseNodesWithCallable($class, function (Node $node) use ($propertyNames) {
            if (! $node instanceof Property) {
                return null;
            }

            if (! $this->nodeNameResolver->isNames($node, $propertyNames)) {
                return null;
            }

            $this->nodeRemovingCommander->addNode($node);
        });
    }

    /**
     * @param Stmt[] $stmts
     */
    public function addStmtsToClassMethodIfNotThereYet(Class_ $class, string $methodName, array $stmts): void
    {
        $classMethod = $class->getMethod($methodName);

        if ($classMethod === null) {
            $classMethod = $this->nodeFactory->createPublicMethod($methodName);

            // keep parent constructor call
            if ($this->hasClassParentClassMethod($class, $methodName)) {
                $classMethod->stmts[] = $this->createParentClassMethodCall($methodName);
            }

            $classMethod->stmts = array_merge((array) $classMethod->stmts, $stmts);

            $class->stmts = array_merge((array) $class->stmts, [$classMethod]);
            return;
        }

        $stmts = $this->filterOutExistingStmts($classMethod, $stmts);

        // all stmts are already there → skip
        if ($stmts === []) {
            return;
        }

        $classMethod->stmts = array_merge($stmts, (array) $classMethod->stmts);
    }

    public function findPropertyByType(Class_ $class, string $serviceType): ?Property
    {
        foreach ($class->getProperties() as $property) {
            if (! $this->nodeTypeResolver->isObjectType($property, $serviceType)) {
                continue;
            }

            return $property;
        }

        return null;
    }

    /**
     * @return string[]
     */
    public function getImplementedInterfaceNames(Class_ $class): array
    {
        $implementedInterfaceNames = [];

        foreach ($class->implements as $implement) {
            $interfaceName = $this->nodeNameResolver->getName($implement);
            if (! is_string($interfaceName)) {
                throw new ShouldNotHappenException();
            }

            $implementedInterfaceNames[] = $interfaceName;
        }

        return $implementedInterfaceNames;
    }

    public function hasPropertyName(Class_ $node, string $name): bool
    {
        foreach ($node->getProperties() as $property) {
            foreach ($property->props as $propertyProperty) {
                if (! $this->nodeNameResolver->isName($propertyProperty, $name)) {
                    continue;
                }

                return true;
            }
        }

        return false;
    }

    public function hasClassTrait(Class_ $class, string $desiredTrait): bool
    {
        foreach ($class->getTraitUses() as $traitUse) {
            foreach ($traitUse->traits as $traitTrait) {
                if (! $this->nodeNameResolver->isName($traitTrait, $desiredTrait)) {
                    continue;
                }

                return true;
            }
        }

        return false;
    }

    public function replaceTrait(Class_ $class, string $oldTrait, string $newTrait): void
    {
        foreach ($class->getTraitUses() as $traitUse) {
            foreach ($traitUse->traits as $key => $traitTrait) {
                if (! $this->nodeNameResolver->isName($traitTrait, $oldTrait)) {
                    continue;
                }

                $traitUse->traits[$key] = new FullyQualified($newTrait);
                break;
            }
        }
    }

    /**
     * @param Class_|Interface_ $classLike
     * @return string[]
     */
    public function getClassLikeNodeParentInterfaceNames(ClassLike $classLike)
    {
        if ($classLike instanceof Class_) {
            return $this->getClassImplementedInterfaceNames($classLike);
        }

        if ($classLike instanceof Interface_) {
            return $this->getInterfaceExtendedInterfacesNames($classLike);
        }

        return [];
    }

    public function hasInterface(Class_ $class, string $desiredInterface): bool
    {
        foreach ($class->implements as $implement) {
            if (! $this->nodeNameResolver->isName($implement, $desiredInterface)) {
                continue;
            }

            return true;
        }

        return false;
    }

    public function removeInterface(Class_ $class, string $desiredInterface): void
    {
        foreach ($class->implements as $implement) {
            if (! $this->nodeNameResolver->isName($implement, $desiredInterface)) {
                continue;
            }

            $this->nodeRemovingCommander->addNode($implement);
        }
    }

    /**
     * @param string[] $oldToNewPropertyNames
     */
    public function renamePropertyFetches(Class_ $class, array $oldToNewPropertyNames): void
    {
        $this->callableNodeTraverser->traverseNodesWithCallable($class, function (Node $node) use (
            $oldToNewPropertyNames
        ) {
            if (! $node instanceof PropertyFetch) {
                return null;
            }

            if (! $this->nodeNameResolver->isName($node->var, 'this')) {
                return null;
            }

            foreach ($oldToNewPropertyNames as $oldPropertyName => $newPropertyName) {
                if (! $this->nodeNameResolver->isName($node->name, $oldPropertyName)) {
                    continue;
                }

                $node->name = new Identifier($newPropertyName);
            }

            return null;
        });
    }

    private function tryInsertBeforeFirstMethod(Class_ $classNode, Stmt $stmt): bool
    {
        foreach ($classNode->stmts as $key => $classStmt) {
            if ($classStmt instanceof ClassMethod) {
                $classNode->stmts = $this->insertBefore($classNode->stmts, $stmt, $key);
                return true;
            }
        }

        return false;
    }

    private function tryInsertAfterLastProperty(Class_ $classNode, Stmt $stmt): bool
    {
        $previousElement = null;
        foreach ($classNode->stmts as $key => $classStmt) {
            if ($previousElement instanceof Property && ! $classStmt instanceof Property) {
                $classNode->stmts = $this->insertBefore($classNode->stmts, $stmt, $key);

                return true;
            }

            $previousElement = $classStmt;
        }

        return false;
    }

    /**
     * @param string[] ...$types
     */
    private function addStatementToClassBeforeTypes(Class_ $classNode, Stmt $stmt, string ...$types): void
    {
        foreach ($types as $type) {
            foreach ($classNode->stmts as $key => $classStmt) {
                if ($classStmt instanceof $type) {
                    $classNode->stmts = $this->insertBefore($classNode->stmts, $stmt, $key);

                    return;
                }
            }
        }

        $classNode->stmts[] = $stmt;
    }

    private function hasClassProperty(Class_ $classNode, string $name): bool
    {
        foreach ($classNode->getProperties() as $property) {
            if ($this->nodeNameResolver->isName($property, $name)) {
                return true;
            }
        }

        return false;
    }

    private function addParameterAndAssignToMethod(
        ClassMethod $classMethod,
        string $name,
        ?Type $type,
        Assign $assign
    ): void {
        if ($this->hasMethodParameter($classMethod, $name)) {
            return;
        }

        $classMethod->params[] = $this->nodeFactory->createParamFromNameAndType($name, $type);
        $classMethod->stmts[] = new Expression($assign);
    }

    /**
     * @return string[]
     */
    private function getClassMethodNames(Class_ $classNode): array
    {
        $classMethodNames = [];
        foreach ($classNode->getMethods() as $classMethod) {
            $methodName = $this->nodeNameResolver->getName($classMethod);
            if (! is_string($methodName)) {
                throw new ShouldNotHappenException();
            }

            $classMethodNames[] = $methodName;
        }

        return $classMethodNames;
    }

    private function hasClassParentClassMethod(Class_ $class, string $methodName): bool
    {
        $parentClassName = $class->getAttribute(AttributeKey::PARENT_CLASS_NAME);
        if ($parentClassName === null) {
            return false;
        }

        return method_exists($parentClassName, $methodName);
    }

    private function createParentClassMethodCall(string $methodName): Expression
    {
        $staticCall = new StaticCall(new Name('parent'), $methodName);

        return new Expression($staticCall);
    }

    /**
     * @param Stmt[] $stmts
     * @return Stmt[]
     */
    private function filterOutExistingStmts(ClassMethod $classMethod, array $stmts): array
    {
        $this->callableNodeTraverser->traverseNodesWithCallable((array) $classMethod->stmts, function (Node $node) use (
            &$stmts
        ) {
            foreach ($stmts as $key => $assign) {
                if (! $this->betterStandardPrinter->areNodesEqual($node, $assign)) {
                    continue;
                }

                unset($stmts[$key]);
            }

            return null;
        });

        return $stmts;
    }

    /**
     * @return string[]
     */
    private function getClassImplementedInterfaceNames(Class_ $class): array
    {
        $interfaceNames = [];

        foreach ($class->implements as $implementNode) {
            $interfaceName = $this->nodeNameResolver->getName($implementNode);
            if ($interfaceName === null) {
                continue;
            }

            $interfaceNames[] = $interfaceName;
        }

        return $interfaceNames;
    }

    /**
     * @return string[]
     */
    private function getInterfaceExtendedInterfacesNames(Interface_ $interface): array
    {
        $interfaceNames = [];

        foreach ($interface->extends as $extendNode) {
            $interfaceName = $this->nodeNameResolver->getName($extendNode);
            if ($interfaceName === null) {
                continue;
            }

            $interfaceNames[] = $interfaceName;
        }

        return $interfaceNames;
    }

    private function hasMethodParameter(ClassMethod $classMethod, string $name): bool
    {
        foreach ($classMethod->params as $constructorParameter) {
            if ($this->nodeNameResolver->isName($constructorParameter->var, $name)) {
                return true;
            }
        }

        return false;
    }
}
