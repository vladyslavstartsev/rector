<?php
declare(strict_types=1);

namespace Rector\Laravel\NodeFactory;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Expression;
use PHPStan\PhpDocParser\Ast\PhpDoc\VarTagValueNode;
use Rector\AttributeAwarePhpDoc\Ast\Type\AttributeAwareFullyQualifiedIdentifierTypeNode;
use Rector\BetterPhpDocParser\PhpDocInfo\PhpDocInfoFactory;
use Rector\Laravel\ValueObject\ServiceNameTypeAndVariableName;

final class AppAssignFactory
{
    /**
     * @var PhpDocInfoFactory
     */
    private $phpDocInfoFactory;

    public function __construct(PhpDocInfoFactory $phpDocInfoFactory)
    {
        $this->phpDocInfoFactory = $phpDocInfoFactory;
    }

    public function createAssignExpression(
        ServiceNameTypeAndVariableName $serviceNameTypeAndVariableName,
        Expr $expr
    ): Expression {
        $variable = new Variable($serviceNameTypeAndVariableName->getVariableName());
        $assign = new Assign($variable, $expr);
        $expression = new Expression($assign);

        $this->decorateWithVarAnnotation($expression, $serviceNameTypeAndVariableName);

        return $expression;
    }

    private function decorateWithVarAnnotation(
        Expression $expression,
        ServiceNameTypeAndVariableName $serviceNameTypeAndVariableName
    ): void {
        $phpDocInfo = $this->phpDocInfoFactory->createEmpty($expression);

        $attributeAwareFullyQualifiedIdentifierTypeNode = new AttributeAwareFullyQualifiedIdentifierTypeNode(
            $serviceNameTypeAndVariableName->getType()
        );
        $varTagValueNode = new VarTagValueNode(
            $attributeAwareFullyQualifiedIdentifierTypeNode,
            '$' . $serviceNameTypeAndVariableName->getVariableName(),
            ''
        );

        $phpDocInfo->addTagValueNode($varTagValueNode);
        $phpDocInfo->makeSingleLined();
    }
}
