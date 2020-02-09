<?php

declare(strict_types=1);

namespace Rector\Core\Rector;

use Nette\Utils\Strings;
use PhpParser\BuilderFactory;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeVisitorAbstract;
use PHPStan\Analyser\Scope;
use Rector\BetterPhpDocParser\Printer\PhpDocInfoPrinter;
use Rector\CodingStyle\Rector\Namespace_\ImportFullyQualifiedNamesRector;
use Rector\Core\Commander\CommanderCollector;
use Rector\Core\Contract\Rector\PhpRectorInterface;
use Rector\Core\Exclusion\ExclusionManager;
use Rector\Core\NodeContainer\NodeFinder\ClassLikeParsedNodesFinder;
use Rector\Core\NodeContainer\NodeFinder\FunctionLikeParsedNodesFinder;
use Rector\NodeTypeResolver\FileSystem\CurrentFileInfoProvider;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\NodeTypeResolver\PhpDoc\NodeAnalyzer\DocBlockManipulator;
use Rector\NodeTypeResolver\StaticTypeMapper;
use Rector\Core\Php\PhpVersionProvider;
use Rector\Core\Rector\AbstractRector\AbstractRectorTrait;
use Rector\Core\Rector\AbstractRector\NodeCommandersTrait;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symplify\SmartFileSystem\SmartFileInfo;

abstract class AbstractRector extends NodeVisitorAbstract implements PhpRectorInterface
{
    use AbstractRectorTrait;

    /**
     * @var BuilderFactory
     */
    protected $builderFactory;

    /**
     * @var SymfonyStyle
     */
    private $symfonyStyle;

    /**
     * @var PhpVersionProvider
     */
    protected $phpVersionProvider;

    /**
     * @var ExclusionManager
     */
    private $exclusionManager;

    /**
     * @var CommanderCollector
     */
    private $commanderCollector;

    /**
     * @var CurrentFileInfoProvider
     */
    private $currentFileInfoProvider;

    /**
     * @var PhpDocInfoPrinter
     */
    protected $phpDocInfoPrinter;

    /**
     * @var DocBlockManipulator
     */
    protected $docBlockManipulator;

    /**
     * @var StaticTypeMapper
     */
    protected $staticTypeMapper;

    /**
     * Run once in the every end of one processed file
     */
    protected function tearDown(): void
    {
    }

    /**
     * @required
     */
    public function autowireAbstractRectorDependencies(
        SymfonyStyle $symfonyStyle,
        PhpVersionProvider $phpVersionProvider,
        BuilderFactory $builderFactory,
        ExclusionManager $exclusionManager,
        CommanderCollector $commanderCollector,
        CurrentFileInfoProvider $currentFileInfoProvider,
        PhpDocInfoPrinter $phpDocInfoPrinter,
        DocBlockManipulator $docBlockManipulator,
        StaticTypeMapper $staticTypeMapper
    ): void {
        $this->symfonyStyle = $symfonyStyle;
        $this->phpVersionProvider = $phpVersionProvider;
        $this->builderFactory = $builderFactory;
        $this->exclusionManager = $exclusionManager;
        $this->commanderCollector = $commanderCollector;
        $this->currentFileInfoProvider = $currentFileInfoProvider;
        $this->phpDocInfoPrinter = $phpDocInfoPrinter;
        $this->docBlockManipulator = $docBlockManipulator;
        $this->staticTypeMapper = $staticTypeMapper;
    }

    /**
     * @return int|Node|null
     */
    final public function enterNode(Node $node)
    {
        if (! $this->isMatchingNodeType(get_class($node))) {
            return null;
        }

        // show current Rector class on --debug
        if ($this->symfonyStyle->isDebug()) {
            // indented on purpose to improve log nesting under [refactoring]
            $this->symfonyStyle->writeln('    [applying] ' . static::class);
        }

        // already removed
        if ($this->isNodeRemoved($node)) {
            return null;
        }

        if ($this->exclusionManager->isNodeSkippedByRector($this, $node)) {
            return null;
        }

        $originalNode = $node->getAttribute(AttributeKey::ORIGINAL_NODE) ?? clone $node;
        $originalNodeWithAttributes = clone $node;
        $node = $this->refactor($node);

        // nothing to change → continue
        if ($node === null) {
            return null;
        }

        // changed!
        if ($this->hasNodeChanged($originalNode, $node)) {
            $this->mirrorAttributes($originalNodeWithAttributes, $node);
            $this->updateAttributes($node);
            $this->keepFileInfoAttribute($node, $originalNode);
            $this->notifyNodeChangeFileInfo($node);
        }

        // if stmt ("$value;") was replaced by expr ("$value"), add the ending ";" (Expression) to prevent breaking the code
        if ($originalNode instanceof Stmt && $node instanceof Expr) {
            return new Expression($node);
        }

        return $node;
    }

    /**
     * @see NodeCommandersTrait
     *
     * @param Node[] $nodes
     * @return Node[]
     */
    public function afterTraverse(array $nodes): array
    {
        // setup for commanders
        foreach ($nodes as $node) {
            $fileInfo = $node->getAttribute(AttributeKey::FILE_INFO);
            if ($fileInfo instanceof SmartFileInfo) {
                $this->currentFileInfoProvider->setCurrentFileInfo($fileInfo);
                break;
            }
        }

        foreach ($this->commanderCollector->provide() as $commander) {
            if (! $commander->isActive()) {
                continue;
            }

            $nodes = $commander->traverseNodes($nodes);
        }

        $this->tearDown();

        return $nodes;
    }

    protected function getNextExpression(Node $node): ?Node
    {
        $currentExpression = $node->getAttribute(AttributeKey::CURRENT_STATEMENT);
        if (! $currentExpression instanceof Expression) {
            return null;
        }

        return $currentExpression->getAttribute(AttributeKey::NEXT_NODE);
    }

    /**
     * @param Expr[]|null[] $nodes
     * @param mixed[] $expectedValues
     */
    protected function areValues(array $nodes, array $expectedValues): bool
    {
        foreach ($nodes as $i => $node) {
            if ($node !== null && $this->isValue($node, $expectedValues[$i])) {
                continue;
            }

            return false;
        }

        return true;
    }

    protected function isAtLeastPhpVersion(string $version): bool
    {
        return $this->phpVersionProvider->isAtLeast($version);
    }

    private function isMatchingNodeType(string $nodeClass): bool
    {
        foreach ($this->getNodeTypes() as $nodeType) {
            if (is_a($nodeClass, $nodeType, true)) {
                return true;
            }
        }

        return false;
    }

    private function keepFileInfoAttribute(Node $node, Node $originalNode): void
    {
        if ($node->getAttribute(AttributeKey::FILE_INFO) instanceof SmartFileInfo) {
            return;
        }

        if ($originalNode->getAttribute(AttributeKey::FILE_INFO) !== null) {
            $node->setAttribute(AttributeKey::FILE_INFO, $originalNode->getAttribute(AttributeKey::FILE_INFO));
        } elseif ($originalNode->getAttribute(AttributeKey::PARENT_NODE) !== null) {
            /** @var Node $parentOriginalNode */
            $parentOriginalNode = $originalNode->getAttribute(AttributeKey::PARENT_NODE);
            $node->setAttribute(AttributeKey::FILE_INFO, $parentOriginalNode->getAttribute(AttributeKey::FILE_INFO));
        }
    }

    private function mirrorAttributes(Node $oldNode, Node $newNode): void
    {
        $attributesToMirror = [
            AttributeKey::PARENT_NODE,
            AttributeKey::CLASS_NODE,
            AttributeKey::CLASS_NAME,
            AttributeKey::FILE_INFO,
            AttributeKey::METHOD_NODE,
            AttributeKey::USE_NODES,
            AttributeKey::SCOPE,
            AttributeKey::METHOD_NAME,
            AttributeKey::NAMESPACE_NAME,
            AttributeKey::NAMESPACE_NODE,
            AttributeKey::RESOLVED_NAME,
        ];

        foreach ($oldNode->getAttributes() as $attributeName => $oldNodeAttributeValue) {
            if (! in_array($attributeName, $attributesToMirror, true)) {
                continue;
            }

            $newNode->setAttribute($attributeName, $oldNodeAttributeValue);
        }
    }

    protected function isAnonymousClass(Node $node): bool
    {
        if (! $node instanceof Class_) {
            return false;
        }

        $className = $this->nodeNameResolver->getName($node);

        return $className === null || Strings::contains($className, 'AnonymousClass');
    }

    private function updateAttributes(Node $node): void
    {
        // update Resolved name attribute if name is changed
        if ($node instanceof Name) {
            $node->setAttribute(AttributeKey::RESOLVED_NAME, $node->toString());
        }
    }

    private function hasNodeChanged(Node $originalNode, Node $node): bool
    {
        if ($this->isNameIdentical($node, $originalNode)) {
            return false;
        }

        return ! $this->areNodesEqual($originalNode, $node);
    }

    protected function createCountedValueName(string $countedValueName, ?Scope $scope): string
    {
        if ($scope === null) {
            return $countedValueName;
        }

        // make sure variable name is unique
        if (! $scope->hasVariableType($countedValueName)->yes()) {
            return $countedValueName;
        }

        // we need to add number suffix until the variable is unique
        $i = 2;
        $countedValueNamePart = $countedValueName;
        while ($scope->hasVariableType($countedValueName)->yes()) {
            $countedValueName = $countedValueNamePart . $i;
            ++$i;
        }

        return $countedValueName;
    }

    /**
     * @param Node\Stmt[] $stmts
     */
    protected function unwrapStmts(array $stmts, Node $node): void
    {
        foreach ($stmts as $key => $ifStmt) {
            if ($key === 0) {
                // move comment from if to first element to keep it
                $ifStmt->setAttribute(AttributeKey::PHP_DOC_INFO, $node->getAttribute(AttributeKey::PHP_DOC_INFO));
            }

            $this->addNodeAfterNode($ifStmt, $node);
        }
    }

    private function isNameIdentical(Node $node, Node $originalNode): bool
    {
        if (static::class !== ImportFullyQualifiedNamesRector::class) {
            return false;
        }

        if (! $originalNode instanceof Name) {
            return false;
        }

        // names are the same
        return $this->areNodesEqual($originalNode->getAttribute('originalName'), $node);
    }
}
