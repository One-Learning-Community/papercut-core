<?php

declare(strict_types=1);

namespace Papercut\Tests\Paint;

use Papercut\Backend\ImageHandle;
use Papercut\Paint\{Clip, DocumentMeta, DrawImage, Line, Link, Opacity, PageDisplayList, Rect, TextRun};
use Papercut\Tests\Support\FakeFont;
use Papercut\Value\Color;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OpsTest extends TestCase
{
    public function test_ops_hold_their_values(): void
    {
        $run = new TextRun(10, 20, 'Hi', new FakeFont(), 12, Color::black());
        self::assertSame('Hi', $run->text);
        self::assertSame(0.0, $run->letterSpacing);

        $rect = new Rect(0, 0, 100, 50, fill: Color::white(), radius: 4);
        self::assertNull($rect->stroke);
        self::assertSame(4.0, $rect->radius);

        $line = new Line(0, 0, 10, 0, Color::black(), dashed: true);
        self::assertTrue($line->dashed);

        $clip = new Clip(0, 0, 10, 10, [$rect, new Opacity(0.5, [$line])]);
        self::assertCount(2, $clip->ops);

        $page = new PageDisplayList(595, 842, [$run, $clip]);
        self::assertCount(2, $page->ops);

        $meta = new DocumentMeta(title: 'T');
        self::assertSame('T', $meta->title);
        self::assertNull($meta->createdAt);
    }

    public function test_rect_rejects_negative_size(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Rect(0, 0, -1, 10);
    }

    public function test_opacity_rejects_out_of_range(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Opacity(1.5, []);
    }

    public function test_page_rejects_zero_size(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PageDisplayList(0, 842, []);
    }

    public function test_text_run_rejects_non_positive_size(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TextRun(0, 0, 'x', new FakeFont(), 0, Color::black());
    }

    /** @return iterable<string, array{\Closure(): object}> */
    public static function nonFiniteOps(): iterable
    {
        yield 'rect nan' => [static fn () => new Rect(NAN, 0, 1, 1)];
        yield 'rect inf width' => [static fn () => new Rect(0, 0, INF, 1)];
        yield 'text run nan' => [static fn () => new TextRun(NAN, 0, 'x', new FakeFont(), 12, Color::black())];
        yield 'text run inf size' => [static fn () => new TextRun(0, 0, 'x', new FakeFont(), INF, Color::black())];
        yield 'text run nan spacing' => [static fn () => new TextRun(0, 0, 'x', new FakeFont(), 12, Color::black(), NAN)];
        yield 'opacity nan' => [static fn () => new Opacity(NAN, [])];
        yield 'page nan' => [static fn () => new PageDisplayList(NAN, 842, [])];
        yield 'page inf' => [static fn () => new PageDisplayList(595, INF, [])];
        yield 'line nan' => [static fn () => new Line(0, 0, NAN, 0, Color::black())];
        yield 'clip inf' => [static fn () => new Clip(0, 0, INF, 1, [])];
        yield 'link nan' => [static fn () => new Link(NAN, 0, 1, 1, 'u')];
        yield 'image nan' => [static fn () => new DrawImage(0, NAN, 1, 1, self::image())];
    }

    /** @param \Closure(): object $make */
    #[DataProvider('nonFiniteOps')]
    public function test_non_finite_numbers_are_rejected(\Closure $make): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-finite');
        $make();
    }

    public function test_boundary_values_are_valid(): void
    {
        self::assertSame(0.0, (new Opacity(0.0, []))->alpha);
        self::assertSame(1.0, (new Opacity(1.0, []))->alpha);
        self::assertSame(0.0, (new Rect(0, 0, 0, 0))->width);
    }

    public function test_rect_rejects_negative_stroke_width_and_radius(): void
    {
        foreach ([fn () => new Rect(0, 0, 1, 1, strokeWidth: -1), fn () => new Rect(0, 0, 1, 1, radius: -1)] as $make) {
            try {
                $make();
                self::fail('Expected InvalidArgumentException');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_line_image_and_link_hold_values(): void
    {
        self::assertSame(2.0, (new Line(0, 0, 10, 0, Color::black(), 2))->width);
        self::assertSame('img', (new DrawImage(0, 0, 10, 10, self::image()))->image->id());
        self::assertSame('https://x.test', (new Link(0, 0, 10, 10, 'https://x.test'))->uri);
    }

    /** @return iterable<string, array{string}> */
    public static function badUris(): iterable
    {
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'file' => ['file:///etc/passwd'];
        yield 'data' => ['data:text/html,x'];
        yield 'relative' => ['docs/a.pdf'];
        yield 'empty' => [''];
        yield 'ftp' => ['ftp://x.test'];
    }

    #[DataProvider('badUris')]
    public function test_link_rejects_disallowed_uri(string $uri): void
    {
        $this->expectException(\Papercut\Exception\InvalidValue::class);
        new Link(0, 0, 1, 1, $uri);
    }

    /** @return iterable<string, array{string}> */
    public static function goodUris(): iterable
    {
        yield 'http' => ['http://x.test'];
        yield 'https upper' => ['HTTPS://x.test'];
        yield 'mailto' => ['mailto:a@x.test'];
        yield 'tel' => ['tel:+123'];
        yield 'fragment' => ['#top'];
        yield 'leading space' => ['  https://x.test'];
    }

    #[DataProvider('goodUris')]
    public function test_link_accepts_allowed_uri(string $uri): void
    {
        self::assertSame(ltrim($uri), (new Link(0, 0, 1, 1, $uri))->uri);
    }

    private static function image(): ImageHandle
    {
        return new class implements ImageHandle {
            public function id(): string { return 'img'; }
            public function widthPx(): int { return 1; }
            public function heightPx(): int { return 1; }
        };
    }

    public function test_fake_font_metrics(): void
    {
        $f = new FakeFont();
        self::assertSame(12.0, $f->advance('ab', 12));
        self::assertSame(14.0, $f->advance('ab', 12, 1.0));
        self::assertSame(2, $f->advanceCalls);
        self::assertFalse($f->covers('漢'));
    }

    public function test_link_rejection_explains_the_allowed_schemes(): void
    {
        try {
            new Link(0, 0, 1, 1, 'ftp://x.test');
            self::fail('accepted');
        } catch (\Papercut\Exception\InvalidValue $e) {
            self::assertSame('Invalid link "ftp://x.test": only http, https, mailto, tel and #fragment links are allowed.', $e->getMessage());
        }
    }
}
