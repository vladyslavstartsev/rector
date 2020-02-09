<?php

declare(strict_types=1);

namespace Rector\NetteTesterToPHPUnit;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Cast\Bool_;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Type\BooleanType;
use Prophecy\Doubler\Generator\Node\MethodNode;
use Rector\BetterPhpDocParser\PhpDocInfo\PhpDocInfo;
use Rector\Core\PhpParser\Node\Commander\NodeAddingCommander;
use Rector\Core\PhpParser\Node\Commander\NodeRemovingCommander;
use Rector\Core\PhpParser\Node\Resolver\NodeNameResolver;
use Rector\Core\PhpParser\Node\Value\ValueResolver;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\NodeTypeResolver\NodeTypeResolver;
use Rector\NodeTypeResolver\TypeAnalyzer\StringTypeAnalyzer;

final class AssertManipulator
{
    /**
     * @see https://github.com/nette/tester/blob/master/src/Framework/Assert.php
     * ↓
     * @see https://github.com/sebastianbergmann/phpunit/blob/master/src/Framework/Assert.php
     * @var string[]
     */
    private $assertMethodsRemap = [
        'same' => 'assertSame',
        'notSame' => 'assertNotSame',
        'equal' => 'assertEqual',
        'notEqual' => 'assertNotEqual',
        'true' => 'assertTrue',
        'false' => 'assertFalse',
        'null' => 'assertNull',
        'notNull' => 'assertNotNull',
        'count' => 'assertCount',
        'match' => 'assertStringMatchesFormat',
        'matchFile' => 'assertStringMatchesFormatFile',
        'nan' => 'assertIsNumeric',
    ];

    /**
     * @var NodeNameResolver
     */
    private $nodeNameResolver;

    /**
     * @var NodeTypeResolver
     */
    private $nodeTypeResolver;

    /**
     * @var ValueResolver
     */
    private $valueResolver;

    /**
     * @var NodeAddingCommander
     */
    private $nodeAddingCommander;

    /**
     * @var NodeRemovingCommander
     */
    private $nodeRemovingCommander;

    /**
     * @var StringTypeAnalyzer
     */
    private $stringTypeAnalyzer;

    public function __construct(
        NodeNameResolver $nodeNameResolver,
        NodeTypeResolver $nodeTypeResolver,
        ValueResolver $valueResolver,
        NodeAddingCommander $nodeAddingCommander,
        NodeRemovingCommander $nodeRemovingCommander,
        StringTypeAnalyzer $stringTypeAnalyzer
    ) {
        $this->nodeNameResolver = $nodeNameResolver;
        $this->nodeTypeResolver = $nodeTypeResolver;
        $this->valueResolver = $valueResolver;
        $this->nodeAddingCommander = $nodeAddingCommander;
        $this->nodeRemovingCommander = $nodeRemovingCommander;
        $this->stringTypeAnalyzer = $stringTypeAnalyzer;
    }

    /**
     * @return StaticCall|MethodCall
     */
    public function processStaticCall(StaticCall $staticCall): Node
    {
        if ($this->nodeNameResolver->isNames($staticCall, ['truthy', 'falsey'])) {
            return $this->processTruthyOrFalseyCall($staticCall);
        }

        if ($this->nodeNameResolver->isNames($staticCall, ['contains', 'notContains'])) {
            $this->processContainsCall($staticCall);
        } elseif ($this->nodeNameResolver->isNames($staticCall, ['exception', 'throws'])) {
            $this->processExceptionCall($staticCall);
        } elseif ($this->nodeNameResolver->isName($staticCall, 'type')) {
            $this->processTypeCall($staticCall);
        } elseif ($this->nodeNameResolver->isName($staticCall, 'noError')) {
            $this->processNoErrorCall($staticCall);
        } else {
            $this->renameAssertMethod($staticCall);
        }

        // self or class, depending on the context
        // prefer $this->assertSame() as more conventional and explicit in class-context
        if (! $this->sholdBeStaticCall($staticCall)) {
            $methodCall = new MethodCall(new Variable('this'), $staticCall->name);
            $methodCall->args = $staticCall->args;
            $methodCall->setAttributes($staticCall->getAttributes());
            $methodCall->setAttribute(AttributeKey::ORIGINAL_NODE, null);

            return $methodCall;
        }

        $staticCall->class = new FullyQualified('PHPUnit\Framework\Assert');

        return $staticCall;
    }

    private function processContainsCall(StaticCall $staticCall): void
    {
        if ($this->stringTypeAnalyzer->isStringOrUnionStringOnlyType($staticCall->args[1]->value)) {
            $name = $this->nodeNameResolver->isName(
                $staticCall,
                'contains'
            ) ? 'assertStringContainsString' : 'assertStringNotContainsString';
        } else {
            $name = $this->nodeNameResolver->isName($staticCall, 'contains') ? 'assertContains' : 'assertNotContains';
        }

        $staticCall->name = new Identifier($name);
    }

    private function processExceptionCall(StaticCall $staticCall): void
    {
        $method = 'expectException';

        // expect exception
        if ($this->sholdBeStaticCall($staticCall)) {
            $expectException = new StaticCall(new Name('self'), $method);
        } else {
            $expectException = new MethodCall(new Variable('this'), $method);
        }

        $expectException->args[] = $staticCall->args[1];
        $this->nodeAddingCommander->addNodeAfterNode($expectException, $staticCall);

        // expect message
        if (isset($staticCall->args[2])) {
            $this->refactorExpectException($staticCall);
        }

        // expect code
        if (isset($staticCall->args[3])) {
            $this->refactorExpectExceptionCode($staticCall);
        }

        /** @var Closure $closure */
        $closure = $staticCall->args[0]->value;
        foreach ((array) $closure->stmts as $callableStmt) {
            $this->nodeAddingCommander->addNodeAfterNode($callableStmt, $staticCall);
        }

        $this->nodeRemovingCommander->addNode($staticCall);
    }

    private function processTypeCall(StaticCall $staticCall): void
    {
        $value = $this->valueResolver->getValue($staticCall->args[0]->value);

        $typeToMethod = [
            'list' => 'assertIsArray',
            'array' => 'assertIsArray',
            'bool' => 'assertIsBool',
            'callable' => 'assertIsCallable',
            'float' => 'assertIsFloat',
            'int' => 'assertIsInt',
            'integer' => 'assertIsInt',
            'object' => 'assertIsObject',
            'resource' => 'assertIsResource',
            'string' => 'assertIsString',
            'scalar' => 'assertIsScalar',
        ];

        if (isset($typeToMethod[$value])) {
            $staticCall->name = new Identifier($typeToMethod[$value]);
            unset($staticCall->args[0]);
            $staticCall->args = array_values($staticCall->args);
        } elseif ($value === 'null') {
            $staticCall->name = new Identifier('assertNull');
            unset($staticCall->args[0]);
            $staticCall->args = array_values($staticCall->args);
        } else {
            $staticCall->name = new Identifier('assertInstanceOf');
        }
    }

    private function processNoErrorCall(StaticCall $staticCall): void
    {
        /** @var Closure $closure */
        $closure = $staticCall->args[0]->value;

        foreach ((array) $closure->stmts as $callableStmt) {
            $this->nodeAddingCommander->addNodeAfterNode($callableStmt, $staticCall);
        }

        $this->nodeRemovingCommander->addNode($staticCall);

        /** @var ClassMethod|null $methodNode */
        $methodNode = $staticCall->getAttribute(AttributeKey::METHOD_NODE);
        if ($methodNode === null) {
            return;
        }

        /** @var PhpDocInfo $phpDocInfo */
        $phpDocInfo = $methodNode->getAttribute(AttributeKey::PHP_DOC_INFO);
        $phpDocInfo->addBareTag('@doesNotPerformAssertions');
    }

    /**
     * @return StaticCall|MethodCall
     */
    private function processTruthyOrFalseyCall(StaticCall $staticCall): Expr
    {
        $method = $this->nodeNameResolver->isName($staticCall, 'truthy') ? 'assertTrue' : 'assertFalse';

        if (! $this->sholdBeStaticCall($staticCall)) {
            $call = new MethodCall(new Variable('this'), $method);
            $call->args = $staticCall->args;
            $call->setAttributes($staticCall->getAttributes());
            $call->setAttribute(AttributeKey::ORIGINAL_NODE, null);
        } else {
            $call = $staticCall;
            $call->name = new Identifier($method);
        }

        if (! $this->nodeTypeResolver->isStaticType($staticCall->args[0]->value, BooleanType::class)) {
            $call->args[0]->value = new Bool_($staticCall->args[0]->value);
        }

        return $call;
    }

    private function sholdBeStaticCall(StaticCall $staticCall): bool
    {
        return ! (bool) $staticCall->getAttribute(AttributeKey::CLASS_NODE);
    }

    private function refactorExpectException(StaticCall $staticCall): string
    {
        $method = 'expectExceptionMessage';

        if ($this->sholdBeStaticCall($staticCall)) {
            $expectExceptionMessage = new StaticCall(new Name('self'), $method);
        } else {
            $expectExceptionMessage = new MethodCall(new Variable('this'), $method);
        }

        $expectExceptionMessage->args[] = $staticCall->args[2];
        $this->nodeAddingCommander->addNodeAfterNode($expectExceptionMessage, $staticCall);
        return $method;
    }

    private function refactorExpectExceptionCode(StaticCall $staticCall): void
    {
        $method = 'expectExceptionCode';

        if ($this->sholdBeStaticCall($staticCall)) {
            $expectExceptionCode = new StaticCall(new Name('self'), $method);
        } else {
            $expectExceptionCode = new MethodCall(new Variable('this'), $method);
        }

        $expectExceptionCode->args[] = $staticCall->args[3];
        $this->nodeAddingCommander->addNodeAfterNode($expectExceptionCode, $staticCall);
    }

    private function renameAssertMethod(StaticCall $staticCall): void
    {
        foreach ($this->assertMethodsRemap as $oldMethod => $newMethod) {
            if (! $this->nodeNameResolver->isName($staticCall, $oldMethod)) {
                continue;
            }

            $staticCall->name = new Identifier($newMethod);
        }
    }
}
