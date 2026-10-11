<?php

declare(strict_types=1);

namespace Vertex\Tests\Unit;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

/**
 * Static guard for a bug class that bit this project twice: a `function () use (...)` closure that reads a variable it
 * never imported. PHP only emits a warning (and `??` hides even that), so the failure shows up as wrong behaviour.
 * This scans every PHP file under app/ and fails on any closure that READS a variable which is not a parameter, an
 * imported `use` variable, `$this`, or assigned somewhere inside the closure itself.
 */
final class ClosureScopeTest extends TestCase
{
    public function testNoClosureReadsAVariableItDidNotImport(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new NodeFinder();
        $problems = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(VERTEX_ROOT . '/app'));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $stmts = $parser->parse((string) file_get_contents($file->getPathname())) ?? [];
            /** @var list<Node\Expr\Closure> $closures */
            $closures = $finder->findInstanceOf($stmts, Node\Expr\Closure::class);
            foreach ($closures as $closure) {
                $declared = ['this' => true, 'GLOBALS' => true, '_SERVER' => true, '_GET' => true, '_POST' => true, '_SESSION' => true, '_FILES' => true, '_COOKIE' => true];
                foreach ($closure->params as $p) {
                    if ($p->var instanceof Node\Expr\Variable && is_string($p->var->name)) {
                        $declared[$p->var->name] = true;
                    }
                }
                foreach ($closure->uses as $u) {
                    $declared[(string) $u->var->name] = true;
                }
                $stmtsInside = $closure->stmts;

                // Variables that get a value inside the closure (assignment, foreach, catch, list(), static, global, by-ref args).
                foreach ($finder->findInstanceOf($stmtsInside, Node\Expr\Assign::class) as $a) {
                    $this->collectTargets($a->var, $declared);
                }
                foreach ($finder->findInstanceOf($stmtsInside, Node\Expr\AssignOp::class) as $a) {
                    $this->collectTargets($a->var, $declared);
                }
                foreach ($finder->findInstanceOf($stmtsInside, Node\Stmt\Foreach_::class) as $f) {
                    $this->collectTargets($f->valueVar, $declared);
                    if ($f->keyVar !== null) {
                        $this->collectTargets($f->keyVar, $declared);
                    }
                }
                foreach ($finder->findInstanceOf($stmtsInside, Node\Stmt\Catch_::class) as $c) {
                    if ($c->var !== null && is_string($c->var->name)) {
                        $declared[$c->var->name] = true;
                    }
                }
                foreach ($finder->findInstanceOf($stmtsInside, Node\Stmt\StaticVar::class) as $s) {
                    $declared[(string) $s->var->name] = true;
                }
                foreach ($finder->findInstanceOf($stmtsInside, Node\Expr\FuncCall::class) as $call) {
                    // by-reference output parameters of the few functions we use (preg_match(..., $m), etc.)
                    if ($call->name instanceof Node\Name && in_array(strtolower($call->name->toString()), ['preg_match', 'preg_match_all', 'sort', 'usort', 'array_push', 'array_pop', 'array_shift'], true)) {
                        foreach ($call->args as $arg) {
                            if ($arg instanceof Node\Arg && $arg->value instanceof Node\Expr\Variable && is_string($arg->value->name)) {
                                $declared[$arg->value->name] = true;
                            }
                        }
                    }
                }
                // nested closures/arrow functions have their own scope but may read our variables; skip descending by only
                // checking variables located directly in this closure's statements (nested closure bodies are checked on their own).
                foreach ($finder->findInstanceOf($stmtsInside, Node\Expr\Variable::class) as $v) {
                    if (is_string($v->name) && !isset($declared[$v->name]) && !$this->insideNestedScope($v, $stmtsInside, $finder)) {
                        $problems[$file->getPathname() . ':' . $v->getStartLine()] = "\${$v->name}";
                    }
                }
            }
        }
        $lines = [];
        foreach ($problems as $where => $var) {
            $lines[] = str_replace(VERTEX_ROOT . '/', '', $where) . " reads {$var} without importing it";
        }
        $this->assertSame([], $lines, "Closures reading variables they did not import:\n" . implode("\n", $lines));
    }

    /** @param array<string,true> $declared */
    private function collectTargets(Node $target, array &$declared): void
    {
        if ($target instanceof Node\Expr\Variable && is_string($target->name)) {
            $declared[$target->name] = true;
        } elseif ($target instanceof Node\Expr\ArrayDimFetch || $target instanceof Node\Expr\PropertyFetch) {
            $this->collectTargets($target->var, $declared);
        } elseif ($target instanceof Node\Expr\List_ || $target instanceof Node\Expr\Array_) {
            foreach ($target->items as $item) {
                if ($item !== null) {
                    $this->collectTargets($item->value, $declared);
                }
            }
        }
    }

    /** @param array<Node\Stmt> $stmts */
    private function insideNestedScope(Node\Expr\Variable $needle, array $stmts, NodeFinder $finder): bool
    {
        foreach ($finder->find($stmts, static fn (Node $n): bool => $n instanceof Node\Expr\Closure || $n instanceof Node\Expr\ArrowFunction) as $scope) {
            if ($needle->getStartFilePos() >= $scope->getStartFilePos() && $needle->getEndFilePos() <= $scope->getEndFilePos()) {
                return true;
            }
        }

        return false;
    }
}
