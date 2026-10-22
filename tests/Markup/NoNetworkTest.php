<?php

declare(strict_types=1);

namespace Papercut\Tests\Markup;

use Papercut\Document;
use Papercut\Export\HtmlOptions;
use Papercut\Export\PdfOptions;
use Papercut\Markup\Markup;
use Papercut\Markup\MarkupOptions;
use Papercut\Markup\StyleMode;
use Papercut\Page;
use Papercut\Tests\Support\TestImages;
use Papercut\Tests\Support\TrapStreamWrapper;
use PHPUnit\Framework\TestCase;

/** Spec §8 Security: converting and exporting markup never opens a URL, whatever its src or href says. */
final class NoNetworkTest extends TestCase
{
    private const string HOSTILE = '<p><img src="http://example.com/a.png" alt="a"><img src="https://example.com/b.png">'
        . '<img src="file:///etc/hosts"><img src="/etc/hosts"><img src="data:image/png;base64,iVBORw0KGgo="></p>'
        . '<iframe src="https://example.com/embed"></iframe><video src="file:///etc/hosts"></video>'
        . '<p><a href="file:///etc/hosts">f</a> <a href="https://example.com">h</a></p>';

    public function test_the_trap_catches_a_url(): void
    {
        $refused = TrapStreamWrapper::during(self::roots(), function (): void {
            try {
                file_get_contents('https://example.com/x');
            } catch (\RuntimeException) {
            }
        });
        self::assertSame(['https://example.com/x'], $refused);
        self::assertContains('https', stream_get_wrappers());

        $paths = TrapStreamWrapper::during(self::roots(), function (): void {
            foreach (['file:///etc/hosts', '/etc/hosts'] as $path) {
                try {
                    file_get_contents($path);
                } catch (\RuntimeException) {
                }
            }
            self::assertNotFalse(file_get_contents(__FILE__));
        });
        self::assertSame(['/etc/hosts', '/etc/hosts'], $paths);
    }

    /**
     * Where Papercut may legitimately read and write while it exports: the
     * monorepo (autoloader, bundled fonts) and the temporary directory.
     *
     * @return list<string>
     */
    private static function roots(): array
    {
        return [dirname(__DIR__, 4), sys_get_temp_dir()];
    }

    public function test_samples_never_open_a_stream_with_or_without_a_resolver(): void
    {
        /** @var array<string, string|list<string>|null> $samples */
        $samples = require __DIR__ . '/../fixtures/markup/samples.php';
        $inputs = [self::HOSTILE];
        foreach ($samples as $sample) {
            array_push($inputs, ...(is_array($sample) ? $sample : [$sample ?? '']));
        }
        $png = TestImages::png(200, 100);
        $resolvers = ['none' => null, 'resolver' => static fn (string $src): string => $png];

        foreach ($resolvers as $name => $resolver) {
            foreach ([StyleMode::Theme, StyleMode::Original] as $mode) {
                $options = new MarkupOptions(styles: $mode, images: $resolver);
                $refused = TrapStreamWrapper::during(self::roots(), function () use ($inputs, $options): void {
                    foreach ($inputs as $html) {
                        $doc = Document::make(Page::letter()->body(Markup::html($html, $options)));
                        self::assertNotSame('', $doc->toPdf(new PdfOptions(onWarning: static fn (string $m) => null, cacheDir: false))->bytes());
                        self::assertNotSame('', $doc->toHtml(new HtmlOptions(onWarning: static fn (string $m) => null))->bytes());
                    }
                });
                self::assertSame([], $refused, "$name {$mode->value}");
            }
        }
    }
}
