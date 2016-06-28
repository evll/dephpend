<?php

declare (strict_types = 1);

namespace Mihaeu\PhpDependencies;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall as MethodCallNode;
use PhpParser\Node\Expr\StaticCall as StaticCallNode;
use PhpParser\Node\Name as NameNode;
use PhpParser\Node\Name\FullyQualified as FullyQualifiedNameNode;
use PhpParser\Node\Expr\New_ as NewNode;
use PhpParser\Node\Stmt\Class_ as ClassNode;
use PhpParser\Node\Stmt\ClassMethod as ClassMethodNode;
use PhpParser\Node\Stmt\Use_ as UseNode;
use PhpParser\NodeVisitorAbstract;

class DependencyInspectionVisitor extends NodeVisitorAbstract
{
    /** @var DependencyCollection */
    private $dependencies;

    /** @var DependencyCollection */
    private $tempDependencies;

    /** @var Clazz */
    private $currentClass = null;

    /** @var Clazz */
    private $temporaryClass;

    public function __construct()
    {
        $this->dependencies = new DependencyCollection();
        $this->tempDependencies = new DependencyCollection();

        $this->temporaryClass = new Clazz('temporary class');
    }

    /**
     * This is called before any actual work is being done. The order in which
     * the file will be traversed is not always as expected. We therefore
     * might encounter a dependency before we actually know which class we are
     * in. To get around this issue we will set the current node to temp
     * and will update it later when we are done traversing.
     *
     * @param Node[] $nodes
     *
     * @return null|\PhpParser\Node[]|void
     */
    public function beforeTraverse(array $nodes)
    {
        $this->currentClass = $this->temporaryClass;
    }

    /**
     * As described in beforeTraverse we are going to update the class we are
     * currently parsing for all dependencies. If we are not in class context
     * we won't add the dependencies.
     *
     * @param array $nodes
     *
     * @return null|\PhpParser\Node[]|void
     */
    public function afterTraverse(array $nodes)
    {
        // not in class context
        if ($this->currentClass->equals($this->temporaryClass)) {
            $this->tempDependencies = new DependencyCollection();
        }

        // by now the class should have been parsed so replace the
        // temporary class with the parsed class name
        $this->tempDependencies->each(function (Dependency $dependency) {
            $this->dependencies = $this->dependencies->add(new Dependency(
                $this->currentClass,
                $dependency->to()
            ));
        });
        $this->tempDependencies = new DependencyCollection();
    }

    /**
     * {@inheritdoc}
     */
    public function enterNode(Node $node)
    {
        if ($node instanceof ClassNode) {
            $this->setCurrentClass($node);
            $this->addSubclassDependency($node);
            $this->addInterfaceDependency($node);
        }

        if ($node instanceof NewNode
            && $node->class instanceof FullyQualifiedNameNode) {
            $this->addInstantiationDependency($node);
        } elseif ($node instanceof ClassMethodNode) {
            $this->addInjectedDependencies($node);
        } elseif ($node instanceof UseNode) {
            $this->addUseDependency($node);
        } elseif ($node instanceof MethodCallNode
            && $node->var instanceof StaticCallNode
            && $node->var->class instanceof NameNode) {
            $this->addStaticDependency($node);
        }
    }

    /**
     * @return DependencyCollection
     */
    public function dependencies() : DependencyCollection
    {
        return $this->dependencies;
    }

    /**
     * @param array $parts
     *
     * @return string
     */
    private function toFullyQualifiedName(array $parts) : string
    {
        return implode('.', $parts);
    }

    /**
     * @param ClassNode $node
     */
    private function setCurrentClass(ClassNode $node)
    {
        $this->currentClass = new Clazz($this->toFullyQualifiedName($node->namespacedName->parts));
    }

    /**
     * @param ClassNode $node
     */
    private function addSubclassDependency(ClassNode $node)
    {
        if ($node->extends !== null) {
            $this->tempDependencies = $this->tempDependencies->add(new Dependency(
                $this->currentClass,
                new Clazz($this->toFullyQualifiedName($node->extends->parts))
            ));
        }
    }

    /**
     * @param ClassNode $node
     */
    private function addInterfaceDependency(ClassNode $node)
    {
        foreach ($node->implements as $interfaceNode) {
            $this->tempDependencies = $this->tempDependencies->add(new Dependency(
                $this->currentClass,
                new Clazz($this->toFullyQualifiedName($interfaceNode->parts))
            ));
        }
    }

    /**
     * @param NewNode $node
     */
    private function addInstantiationDependency(NewNode $node)
    {
        $this->tempDependencies = $this->tempDependencies->add(new Dependency(
            $this->currentClass,
            new Clazz($this->toFullyQualifiedName($node->class->parts))
        ));
    }

    /**
     * @param ClassMethodNode $node
     */
    private function addInjectedDependencies(ClassMethodNode $node)
    {
        foreach ($node->params as $param) {
            /* @var \PhpParser\Node\Param */
            if (isset($param->type, $param->type->parts)) {
                $this->tempDependencies = $this->tempDependencies->add(new Dependency(
                    $this->currentClass,
                    new Clazz($this->toFullyQualifiedName($param->type->parts))
                ));
            }
        }
    }

    /**
     * @param UseNode $node
     */
    private function addUseDependency(UseNode $node)
    {
        $this->tempDependencies = $this->tempDependencies->add(new Dependency(
            $this->currentClass,
            new Clazz($this->toFullyQualifiedName($node->uses[0]->name->parts))
        ));
    }

    /**
     * @param MethodCallNode $node
     */
    private function addStaticDependency(MethodCallNode $node)
    {
        $this->tempDependencies = $this->tempDependencies->add(new Dependency(
            $this->currentClass,
            new Clazz($this->toFullyQualifiedName($node->var->class->parts))
        ));
    }
}
