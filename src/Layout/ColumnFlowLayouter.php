<?php

declare(strict_types=1);

namespace Papercut\Layout;

use Papercut\Exception\{InvalidStructure, LayoutOverflow};
use Papercut\{Node, OverflowMode};
use Papercut\Paint\Line;
use Papercut\Style\ComputedStyle;
use Papercut\Tree\{ColumnFlowPlacement, ColumnFlows};
use Papercut\Value\{Auto, ColumnSpec};
use Papercut\View;

/**
 * A ColumnFlow: its children fill column one, then the next, and carry on in column one of the
 * next page. The flow itself has no box; its children are laid out by BlockLayouter in each
 * column, so a break token is the content's own token and every page restarts at column one.
 *
 * @internal Part of Papercut's engine, not its public API. It may change in any release.
 */
final class ColumnFlowLayouter implements Layouter
{
    public function layout(Node $node, ComputedStyle $style, Constraints $c, ?BreakToken $token, LayoutEngine $engine): LayoutResult
    {
        $spec = self::spec($node);
        $ctx = $engine->context();
        $placement = ColumnFlows::placement($ctx->ancestors(), $ctx->region);
        if ($placement->isBlocked()) {
            if (!$spec->fromMarkup) {
                throw ColumnFlows::misplaced($placement, $ctx->path());
            }
            $ctx->approximated('columns ' . self::where($placement));
            return $engine->blockLayouter()->layout($node, $style, $c, $token, $engine);
        }
        if (self::hasBox($style)) {
            throw new InvalidStructure(sprintf(
                'A ColumnFlow can\'t have a border, background, padding or size (at "%s"). Put it inside a View with that style.',
                $ctx->path(),
            ));
        }

        if (!$spec->fits($c->width)) {
            $message = sprintf('The column gaps (%.2fpt) leave no room for columns in %.2fpt', array_sum($spec->gaps), $c->width);
            if ($ctx->quiet) {
                $ctx->holdBack();
            } elseif ($ctx->overflow === OverflowMode::Throw) {
                throw LayoutOverflow::tooWide($ctx->path(), $message);
            } else {
                $ctx->warnOnce($message . '; laid out as one column.');
            }

            return $engine->blockLayouter()->layout($node, $style, $c, $token, $engine);
        }

        return $this->page($node, $style, $spec, $c, $token, $engine);
    }

    public function intrinsic(Node $node, ComputedStyle $style, LayoutEngine $engine): array
    {
        $spec = self::spec($node);
        $one = $engine->blockLayouter()->intrinsic($node, $style, $engine);
        $ctx = $engine->context();
        if ($spec->fromMarkup && ColumnFlows::placement($ctx->ancestors(), $ctx->region)->isBlocked()) {
            return $one;
        }
        $gaps = array_sum($spec->gaps);

        return ['min' => $spec->count * $one['min'] + $gaps, 'max' => $spec->count * $one['max'] + $gaps];
    }

    private const BALANCE_TOLERANCE = 0.5;
    private const BALANCE_ITERATIONS = 24;

    /**
     * One page of the flow: the columns in order, from $token. A page that completes the content
     * is balanced; a page that ends Partial (full, or cut short by a break) is left as it fell.
     */
    private function page(Node $node, ComputedStyle $style, ColumnSpec $spec, Constraints $c, ?BreakToken $token, LayoutEngine $engine): LayoutResult
    {
        $first = $this->columnsAt($node, $style, $spec, $c, $c->height, $c->fresh, $token, $engine);
        if ($first['status'] === Status::DoesNotFit) {
            return LayoutResult::doesNotFit();
        }
        if ($first['status'] === Status::Partial) {
            return LayoutResult::partial(self::assemble($spec, $c->width, $first['columns']), $first['token'] ?? new BreakToken());
        }

        // Search for the shortest column height at which the content still completes. Trials are muted
        // and never fresh, so they neither report nor clip or split what a real layout would keep whole.
        $ctx = $engine->context();
        $hi = self::tallest($first['columns']);
        $lo = $hi / $spec->count;
        $found = false;
        for ($n = 0; $n < self::BALANCE_ITERATIONS && $hi - $lo > self::BALANCE_TOLERANCE; $n++) {
            $mid = ($lo + $hi) / 2;
            $t = $ctx->trial(fn (): array => $this->columnsAt($node, $style, $spec, $c, $mid, false, $token, $engine));
            if ($t['status'] === Status::Complete) {
                $found = true;
                $hi = min($mid, self::tallest($t['columns']));
            } else {
                $lo = $mid;
            }
        }
        if (!$found) {
            return LayoutResult::complete(self::assemble($spec, $c->width, $first['columns']));
        }

        // One real layout at the chosen height reports what it should, once.
        $final = $this->columnsAt($node, $style, $spec, $c, $hi, $c->fresh, $token, $engine);
        if ($final['status'] !== Status::Complete) {
            return LayoutResult::complete(self::assemble($spec, $c->width, $first['columns']));
        }

        return LayoutResult::complete(self::assemble($spec, $c->width, $final['columns']));
    }

    /**
     * The columns laid out at $height (null = unbounded), from $token.
     *
     * @return array{status: Status, columns: list<array{x: float, fragment: Fragment}>, token: ?BreakToken}
     */
    private function columnsAt(Node $node, ComputedStyle $style, ColumnSpec $spec, Constraints $c, ?float $height, bool $fresh, ?BreakToken $token, LayoutEngine $engine): array
    {
        $boxes = $spec->columns($c->width);
        $last = count($boxes) - 1;
        $placed = [];
        $next = $token;

        foreach ($boxes as $i => $box) {
            $res = $engine->blockLayouter()->layout($node, $style, new Constraints($box['width'], $height, $fresh), $next, $engine);
            if ($res->status === Status::DoesNotFit || $res->fragment === null) {
                return $i === 0
                    ? ['status' => Status::DoesNotFit, 'columns' => [], 'token' => null]
                    : ['status' => Status::Partial, 'columns' => $placed, 'token' => $next ?? new BreakToken()];
            }
            $placed[] = ['x' => $box['x'], 'fragment' => $res->fragment];
            if ($res->status === Status::Complete || $res->token === null) {
                return ['status' => Status::Complete, 'columns' => $placed, 'token' => null];
            }
            $next = $res->token;
            if ($next->forced === ForcedBreak::Page || $i === $last) {
                // A column break in the last column is a page break.
                if ($next->forced === ForcedBreak::Column) {
                    $next = new BreakToken($next->index, $next->child, $next->parallel, $next->headerPending, ForcedBreak::Page);
                }
                return ['status' => Status::Partial, 'columns' => $placed, 'token' => $next];
            }
        }

        throw new \LogicException('A column flow has at least one column.');
    }

    /** @param list<array{x: float, fragment: Fragment}> $placed */
    private static function tallest(array $placed): float
    {
        $h = 0.0;
        foreach ($placed as $p) {
            $h = max($h, $p['fragment']->height);
        }

        return $h;
    }

    /**
     * The rule is drawn only between two columns that both have content on this page.
     *
     * @param list<array{x: float, fragment: Fragment}> $placed
     */
    private static function assemble(ColumnSpec $spec, float $width, array $placed): Fragment
    {
        $height = self::tallest($placed);
        $f = new Fragment($width, $height);
        foreach ($placed as ['x' => $x, 'fragment' => $column]) {
            $f->addChild($x, 0.0, $column);
        }
        if ($spec->rule !== null) {
            $boxes = $spec->columns($width);
            foreach ($spec->gaps as $i => $gap) {
                if (!isset($placed[$i + 1]) || $placed[$i]['fragment']->height <= 0.0 || $placed[$i + 1]['fragment']->height <= 0.0) {
                    continue;
                }
                $centre = $boxes[$i]['x'] + $boxes[$i]['width'] + $gap / 2;
                $f->addOp(new Line($centre, 0.0, $centre, $height, $spec->rule->color, $spec->rule->width));
            }
        }

        return $f;
    }

    private static function spec(Node $node): ColumnSpec
    {
        return ($node instanceof View ? $node->columnSpec() : null)
            ?? throw new \LogicException('ColumnFlowLayouter lays out column flows only.');
    }

    private static function hasBox(ComputedStyle $s): bool
    {
        $b = $s->borderWidth;
        $p = $s->padding;
        return $b->top->points !== 0.0 || $b->right->points !== 0.0 || $b->bottom->points !== 0.0 || $b->left->points !== 0.0
            || $p->top->points !== 0.0 || $p->right->points !== 0.0 || $p->bottom->points !== 0.0 || $p->left->points !== 0.0
            || $s->background !== null
            || !$s->width instanceof Auto || !$s->height instanceof Auto
            || $s->minWidth !== null || $s->maxWidth !== null || $s->minHeight !== null || $s->maxHeight !== null;
    }

    /** @internal */
    public static function where(ColumnFlowPlacement $p): string
    {
        return match ($p) {
            ColumnFlowPlacement::InCell => 'in a table cell',
            ColumnFlowPlacement::InRow => 'in a row',
            ColumnFlowPlacement::InFlow => 'in another column flow',
            ColumnFlowPlacement::OutsideBody => 'in a header, footer, background or overlay',
            ColumnFlowPlacement::OnPage, ColumnFlowPlacement::InBox => 'here',
        };
    }
}
