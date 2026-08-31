<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

final class PhpAstEvidenceVisitor extends NodeVisitorAbstract
{
    /** @var array<int, array{node: Node, key: string, qualified: string}> */
    private array $classes = [];
    /** @var array<int, array{node: Node, key: string}> */
    private array $callables = [];

    public function __construct(
        private readonly array $file,
        private readonly SymbolGraph $graph,
        private readonly string $moduleKey,
    ) {
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Node\Stmt\ClassLike) $this->enterClassLike($node);
        if ($node instanceof Node\Stmt\Function_) $this->enterFunction($node);
        if ($node instanceof Node\Stmt\ClassMethod) $this->enterMethod($node);
        if ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) $this->enterClosure($node);
        if ($node instanceof Node\Stmt\Property) $this->addProperties($node);
        if ($node instanceof Node\Stmt\ClassConst) $this->addConstants($node);
        if ($node instanceof Node\Stmt\EnumCase) $this->addEnumCase($node);
        if ($node instanceof Node\Stmt\Use_) $this->addImports($node);
        if ($node instanceof Node\Stmt\GroupUse) $this->addGroupImports($node);
        if ($node instanceof Node\Stmt\TraitUse) $this->addTraitUses($node);
        if ($node instanceof Node\Attribute) $this->addAttribute($node);
        if ($node instanceof Node\Expr\New_) $this->addTargetRelationship('instantiates', $node->class, $node);
        if ($node instanceof Node\Expr\FuncCall) $this->addFunctionCall($node);
        if ($node instanceof Node\Expr\StaticCall) $this->addStaticCall($node);
        if ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall) $this->addMethodCall($node);
        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        if (($node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction)
            && $this->callables !== [] && $this->callables[array_key_last($this->callables)]['node'] === $node) {
            array_pop($this->callables);
        }
        if ($node instanceof Node\Stmt\ClassLike && $this->classes !== [] && $this->classes[array_key_last($this->classes)]['node'] === $node) {
            array_pop($this->classes);
        }
        return null;
    }

    private function enterClassLike(Node\Stmt\ClassLike $node): void
    {
        if ($node->name === null) return;
        $type = match (true) {
            $node instanceof Node\Stmt\Interface_ => 'interface',
            $node instanceof Node\Stmt\Trait_ => 'trait',
            $node instanceof Node\Stmt\Enum_ => 'enum',
            default => 'class',
        };
        $qualified = isset($node->namespacedName) ? $node->namespacedName->toString() : $node->name->toString();
        $key = $this->graph->addSymbol($this->symbol($node, $type, $node->name->toString(), $qualified, $this->moduleKey, 'public', true, [
            'abstract' => method_exists($node, 'isAbstract') && $node->isAbstract(),
            'final' => method_exists($node, 'isFinal') && $node->isFinal(),
            'readonly' => method_exists($node, 'isReadonly') && $node->isReadonly(),
        ]));
        $this->classes[] = ['node' => $node, 'key' => $key, 'qualified' => $qualified];

        if ($node instanceof Node\Stmt\Class_ && $node->extends !== null) $this->relationship($key, 'extends', $this->name($node->extends), $node);
        if ($node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Enum_) {
            foreach ($node->implements as $interface) $this->relationship($key, 'implements', $this->name($interface), $node);
        }
        if ($node instanceof Node\Stmt\Interface_) {
            foreach ($node->extends as $interface) $this->relationship($key, 'extends', $this->name($interface), $node);
        }
    }

    private function enterFunction(Node\Stmt\Function_ $node): void
    {
        $name = $node->name->toString();
        $qualified = isset($node->namespacedName) ? $node->namespacedName->toString() : $name;
        $key = $this->graph->addSymbol($this->symbol($node, 'function', $name, $qualified, $this->moduleKey, 'public', true, $this->callableMetadata($node)));
        $this->callables[] = ['node' => $node, 'key' => $key];
    }

    private function enterMethod(Node\Stmt\ClassMethod $node): void
    {
        $class = $this->currentClass();
        if ($class === null) return;
        $name = $node->name->toString();
        $visibility = $node->isPrivate() ? 'private' : ($node->isProtected() ? 'protected' : 'public');
        $metadata = $this->callableMetadata($node) + ['static' => $node->isStatic(), 'abstract' => $node->isAbstract(), 'final' => $node->isFinal()];
        $key = $this->graph->addSymbol($this->symbol($node, 'method', $name, $class['qualified'] . '::' . $name, $class['key'], $visibility, false, $metadata));
        $this->callables[] = ['node' => $node, 'key' => $key];
    }

    private function enterClosure(Node $node): void
    {
        $line = max(1, $node->getStartLine());
        $name = ($node instanceof Node\Expr\ArrowFunction ? 'arrow' : 'closure') . '@' . $line;
        $parent = $this->sourceKey();
        $key = $this->graph->addSymbol($this->symbol($node, 'closure', $name, $this->file['path'] . ':' . $name, $parent, 'private', false, $this->callableMetadata($node)));
        $this->callables[] = ['node' => $node, 'key' => $key];
    }

    private function addProperties(Node\Stmt\Property $node): void
    {
        $class = $this->currentClass();
        if ($class === null) return;
        $visibility = $node->isPrivate() ? 'private' : ($node->isProtected() ? 'protected' : 'public');
        foreach ($node->props as $property) {
            $name = '$' . $property->name->toString();
            $this->graph->addSymbol($this->symbol($property, 'property', $name, $class['qualified'] . '::' . $name, $class['key'], $visibility, false, [
                'static' => $node->isStatic(), 'readonly' => $node->isReadonly(), 'type' => $this->typeName($node->type),
            ]));
        }
    }

    private function addConstants(Node\Stmt\ClassConst $node): void
    {
        $class = $this->currentClass();
        if ($class === null) return;
        $visibility = $node->isPrivate() ? 'private' : ($node->isProtected() ? 'protected' : 'public');
        foreach ($node->consts as $constant) {
            $name = $constant->name->toString();
            $this->graph->addSymbol($this->symbol($constant, 'constant', $name, $class['qualified'] . '::' . $name, $class['key'], $visibility, false));
        }
    }

    private function addEnumCase(Node\Stmt\EnumCase $node): void
    {
        $class = $this->currentClass();
        if ($class === null) return;
        $name = $node->name->toString();
        $this->graph->addSymbol($this->symbol($node, 'enum_case', $name, $class['qualified'] . '::' . $name, $class['key'], 'public', false));
    }

    private function addImports(Node\Stmt\Use_ $node): void
    {
        foreach ($node->uses as $use) $this->relationship($this->moduleKey, 'imports', $this->name($use->name), $use);
    }

    private function addGroupImports(Node\Stmt\GroupUse $node): void
    {
        $prefix = $this->name($node->prefix);
        foreach ($node->uses as $use) $this->relationship($this->moduleKey, 'imports', $prefix . '\\' . $this->name($use->name), $use);
    }

    private function addTraitUses(Node\Stmt\TraitUse $node): void
    {
        $class = $this->currentClass();
        if ($class === null) return;
        foreach ($node->traits as $trait) $this->relationship($class['key'], 'uses_trait', $this->name($trait), $trait);
    }

    private function addAttribute(Node\Attribute $node): void
    {
        $this->relationship($this->sourceKey(), 'uses_attribute', $this->name($node->name), $node);
    }

    private function addFunctionCall(Node\Expr\FuncCall $node): void
    {
        if (!$node->name instanceof Node\Name) return;
        $name = $this->name($node->name);
        $this->relationship($this->sourceKey(), 'calls', $name, $node);
        if (in_array(strtolower($name), ['getenv', 'env'], true) && isset($node->args[0]) && $node->args[0]->value instanceof Node\Scalar\String_) {
            $environmentName = $node->args[0]->value->value;
            if (preg_match('/^[A-Z][A-Z0-9_]+$/', $environmentName) === 1) {
                $key = $this->graph->addSymbol($this->symbol($node, 'environment_variable', $environmentName, 'env:' . $environmentName, $this->moduleKey, 'private', false));
                $this->graph->addRelationship($this->relationshipData($this->sourceKey(), 'reads_env', $environmentName, $node, $key));
            }
        }
    }

    private function addStaticCall(Node\Expr\StaticCall $node): void
    {
        if (!$node->class instanceof Node\Name || !$node->name instanceof Node\Identifier) return;
        $class = $this->name($node->class);
        $method = $node->name->toString();
        $this->relationship($this->sourceKey(), 'calls', $class . '::' . $method, $node);
        if (strtolower($class) === 'route' && in_array(strtolower($method), ['get', 'post', 'put', 'patch', 'delete', 'options', 'any'], true)
            && isset($node->args[0]) && $node->args[0]->value instanceof Node\Scalar\String_) {
            $this->graph->addRoute([
                'path' => $this->file['path'], 'framework' => 'Laravel', 'method' => strtoupper($method),
                'route_path' => $node->args[0]->value->value, 'line' => $node->getStartLine(), 'confidence' => 'high',
                'metadata' => ['ast' => true],
            ]);
        }
    }

    private function addMethodCall(Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall $node): void
    {
        if (!$node->name instanceof Node\Identifier) return;
        $this->relationship($this->sourceKey(), 'calls', $node->name->toString(), $node);
    }

    private function addTargetRelationship(string $type, Node $target, Node $evidence): void
    {
        if ($target instanceof Node\Name) $this->relationship($this->sourceKey(), $type, $this->name($target), $evidence);
    }

    private function relationship(?string $sourceKey, string $type, string $targetName, Node $node): void
    {
        if ($targetName === '') return;
        $this->graph->addRelationship($this->relationshipData($sourceKey, $type, $targetName, $node));
    }

    /** @return array<string, mixed> */
    private function relationshipData(?string $sourceKey, string $type, string $targetName, Node $node, ?string $targetKey = null): array
    {
        return [
            'source_key' => $sourceKey,
            'target_key' => $targetKey,
            'target_name' => $targetName,
            'external_name' => $targetKey === null ? $targetName : null,
            'type' => $type,
            'confidence' => 'high',
            'evidence_path' => $this->file['path'],
            'line' => max(1, $node->getStartLine()),
            'line_end' => max(1, $node->getEndLine()),
            'metadata' => ['ast_node' => $node->getType()],
        ];
    }

    /** @return array<string, mixed> */
    private function symbol(Node $node, string $type, string $name, string $qualified, ?string $parentKey, string $visibility, bool $exported, array $metadata = []): array
    {
        return [
            'path' => $this->file['path'], 'language' => 'PHP', 'type' => $type, 'name' => $name,
            'qualified_name' => $qualified, 'parent_key' => $parentKey, 'visibility' => $visibility,
            'exported' => $exported, 'start_line' => max(1, $node->getStartLine()),
            'end_line' => max(1, $node->getEndLine()), 'confidence' => 'high',
            'metadata' => ['ast_node' => $node->getType()] + $metadata,
        ];
    }

    /** @return array<string, mixed> */
    private function callableMetadata(Node $node): array
    {
        $params = property_exists($node, 'params') && is_array($node->params) ? $node->params : [];
        return [
            'parameter_count' => count($params),
            'parameter_types' => array_values(array_filter(array_map(fn (Node\Param $param): ?string => $this->typeName($param->type), $params))),
            'return_type' => property_exists($node, 'returnType') ? $this->typeName($node->returnType) : null,
        ];
    }

    private function typeName(Node|string|null $type): ?string
    {
        if ($type === null) return null;
        if (is_string($type)) return $type;
        if ($type instanceof Node\Name || $type instanceof Node\Identifier) return $this->name($type);
        if ($type instanceof Node\NullableType) return '?' . ($this->typeName($type->type) ?? 'mixed');
        if ($type instanceof Node\UnionType) return implode('|', array_filter(array_map(fn ($item): ?string => $this->typeName($item), $type->types)));
        if ($type instanceof Node\IntersectionType) return implode('&', array_filter(array_map(fn ($item): ?string => $this->typeName($item), $type->types)));
        return $type->getType();
    }

    private function name(Node $node): string
    {
        if ($node instanceof Node\Name) {
            $resolved = $node->getAttribute('resolvedName');
            if ($resolved instanceof Node\Name) return $resolved->toString();
            return $node->toString();
        }
        if ($node instanceof Node\Identifier || $node instanceof Node\VarLikeIdentifier) return $node->toString();
        return '';
    }

    /** @return array{node: Node, key: string, qualified: string}|null */
    private function currentClass(): ?array
    {
        return $this->classes === [] ? null : $this->classes[array_key_last($this->classes)];
    }

    private function sourceKey(): string
    {
        if ($this->callables !== []) return $this->callables[array_key_last($this->callables)]['key'];
        $class = $this->currentClass();
        return $class['key'] ?? $this->moduleKey;
    }
}
