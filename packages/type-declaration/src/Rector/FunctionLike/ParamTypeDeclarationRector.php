<?php

declare(strict_types=1);

namespace Rector\TypeDeclaration\Rector\FunctionLike;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PHPStan\Type\Type;
use Rector\BetterPhpDocParser\PhpDocInfo\PhpDocInfo;
use Rector\Core\RectorDefinition\CodeSample;
use Rector\Core\RectorDefinition\RectorDefinition;
use Rector\Core\ValueObject\PhpVersionFeature;
use Rector\NodeTypeResolver\Node\AttributeKey;

/**
 * @see \Rector\TypeDeclaration\Tests\Rector\FunctionLike\ParamTypeDeclarationRector\ParamTypeDeclarationRectorTest
 */
final class ParamTypeDeclarationRector extends AbstractTypeDeclarationRector
{
    public function getDefinition(): RectorDefinition
    {
        return new RectorDefinition('Change @param types to type declarations if not a BC-break', [
            new CodeSample(
                <<<'PHP'
<?php

class ParentClass
{
    /**
     * @param int $number
     */
    public function keep($number)
    {
    }
}

final class ChildClass extends ParentClass
{
    /**
     * @param int $number
     */
    public function keep($number)
    {
    }

    /**
     * @param int $number
     */
    public function change($number)
    {
    }
}
PHP
                ,
                <<<'PHP'
<?php

class ParentClass
{
    /**
     * @param int $number
     */
    public function keep($number)
    {
    }
}

final class ChildClass extends ParentClass
{
    /**
     * @param int $number
     */
    public function keep($number)
    {
    }

    /**
     * @param int $number
     */
    public function change(int $number)
    {
    }
}
PHP
            ),
        ]);
    }

    /**
     * @param ClassMethod|Function_ $node
     */
    public function refactor(Node $node): ?Node
    {
        if (! $this->isAtLeastPhpVersion(PhpVersionFeature::SCALAR_TYPES)) {
            return null;
        }

        if (empty($node->params)) {
            return null;
        }

        /** @var PhpDocInfo $phpDocInfo */
        $phpDocInfo = $node->getAttribute(AttributeKey::PHP_DOC_INFO);

        $paramWithTypes = $phpDocInfo->getParamTypesByName();
        // no tags, nothing to complete here
        if ($paramWithTypes === []) {
            return null;
        }

        foreach ($node->params as $position => $paramNode) {
            // skip variadics
            if ($paramNode->variadic) {
                continue;
            }

            // already set → skip
            $hasNewType = false;
            if ($paramNode->type !== null) {
                $hasNewType = $paramNode->type->getAttribute(self::HAS_NEW_INHERITED_TYPE, false);
                if (! $hasNewType) {
                    continue;
                }
            }

            $paramNodeName = '$' . $this->getName($paramNode->var);

            // no info about it
            if (! isset($paramWithTypes[$paramNodeName])) {
                continue;
            }

            $paramType = $paramWithTypes[$paramNodeName];
            $paramTypeNode = $this->staticTypeMapper->mapPHPStanTypeToPhpParserNode($paramType, 'param');
            if ($paramTypeNode === null) {
                continue;
            }

            $position = (int) $position;
            if ($node instanceof ClassMethod && $this->vendorLockResolver->isParamChangeVendorLockedIn(
                $node,
                $position
            )) {
                continue;
            }

            if ($hasNewType) {
                // should override - is it subtype?
                $possibleOverrideNewReturnType = $paramTypeNode;
                if ($possibleOverrideNewReturnType !== null) {
                    if ($paramNode->type === null) {
                        $paramNode->type = $paramTypeNode;
                    } elseif ($this->phpParserTypeAnalyzer->isSubtypeOf(
                        $possibleOverrideNewReturnType,
                        $paramNode->type
                    )) {
                        // allow override
                        $paramNode->type = $paramTypeNode;
                    }
                }
            } else {
                $paramNode->type = $paramTypeNode;

                $paramNodeType = $paramNode->type instanceof NullableType ? $paramNode->type->type : $paramNode->type;
                // "resource" is valid phpdoc type, but it's not implemented in PHP
                if ($paramNodeType instanceof Name && reset($paramNodeType->parts) === 'resource') {
                    $paramNode->type = null;

                    continue;
                }
            }

            $this->populateChildren($node, $position, $paramType);
        }

        return $node;
    }

    /**
     * Add typehint to all children
     * @param ClassMethod|Function_ $node
     */
    private function populateChildren(Node $node, int $position, Type $paramType): void
    {
        if (! $node instanceof ClassMethod) {
            return;
        }

        /** @var string $className */
        $className = $node->getAttribute(AttributeKey::CLASS_NAME);
        // anonymous class
        if ($className === null) {
            return;
        }

        $childrenClassLikes = $this->classLikeParsedNodesFinder->findClassesAndInterfacesByType($className);

        // update their methods as well
        foreach ($childrenClassLikes as $childClassLike) {
            if ($childClassLike instanceof Class_) {
                $usedTraits = $this->classLikeParsedNodesFinder->findUsedTraitsInClass($childClassLike);

                foreach ($usedTraits as $trait) {
                    $this->addParamTypeToMethod($trait, $position, $node, $paramType);
                }
            }

            $this->addParamTypeToMethod($childClassLike, $position, $node, $paramType);
        }
    }

    private function addParamTypeToMethod(
        ClassLike $classLike,
        int $position,
        ClassMethod $classMethod,
        Type $paramType
    ): void {
        $methodName = $this->getName($classMethod);

        $currentClassMethod = $classLike->getMethod($methodName);
        if ($currentClassMethod === null) {
            return;
        }

        if (! isset($currentClassMethod->params[$position])) {
            return;
        }

        $paramNode = $currentClassMethod->params[$position];

        // already has a type
        if ($paramNode->type !== null) {
            return;
        }

        $resolvedChildType = $this->resolveChildTypeNode($paramType);
        if ($resolvedChildType === null) {
            return;
        }

        // let the method know it was changed now
        $paramNode->type = $resolvedChildType;
        $paramNode->type->setAttribute(self::HAS_NEW_INHERITED_TYPE, true);

        $this->notifyNodeChangeFileInfo($paramNode);
    }
}
