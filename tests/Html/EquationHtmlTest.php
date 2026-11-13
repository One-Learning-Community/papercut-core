<?php

declare(strict_types=1);

namespace Papercut\Tests\Html;

use Papercut\{Document, Equation, Page, Text};
use Papercut\Export\{FontMode, HtmlOptions};
use Papercut\Html\LocalFonts;
use Papercut\Math\Math;
use Papercut\Value\{Color, Length};
use PHPUnit\Framework\TestCase;

final class EquationHtmlTest extends TestCase
{
    private const RULE = '.papercut math{font-family:"Papercut Math",math}';

    private function html(Equation $e, ?HtmlOptions $o = null): string
    {
        return Document::make(Page::a4()->body($e))->toHtml($o)->bytes();
    }

    private function mathml(string $html): string
    {
        $start = strpos($html, '<math');
        $end = strrpos($html, '</math>');
        self::assertNotFalse($start);
        self::assertNotFalse($end);

        return substr($html, $start, $end + 7 - $start);
    }

    public function test_block_equation_is_mathml(): void
    {
        $html = $this->html(Equation::of('x = 1'));
        self::assertStringContainsString('<math xmlns="http://www.w3.org/1998/Math/MathML" display="block"', $html);
        self::assertMatchesRegularExpression('/<div style="margin:0pt 0pt 6pt 0pt"><math[ >]/', $html);
    }

    public function test_math_font_rule(): void
    {
        $embed = $this->html(Equation::of('x'), new HtmlOptions(fontMode: FontMode::Embed));
        self::assertStringContainsString('@font-face{font-family:"Papercut Math";src:url(data:font/ttf;base64,', $embed);
        self::assertStringContainsString(self::RULE, $embed);

        $link = $this->html(Equation::of('x'), new HtmlOptions(fontMode: FontMode::Link, fontUrl: fn ($f) => '/fonts/' . basename($f->path)));
        self::assertStringContainsString('@font-face{font-family:"Papercut Math";src:url("/fonts/', $link);
        self::assertStringContainsString(self::RULE, $link);

        $none = $this->html(Equation::of('x'));
        self::assertStringNotContainsString('@font-face', $none);
        self::assertStringContainsString(self::RULE, $none);
    }

    public function test_sources_mode_embeds_the_math_face(): void
    {
        $o = new HtmlOptions(fontMode: FontMode::Sources, fontSources: [LocalFonts::link(fn ($f) => '/f.ttf')]);
        $html = $this->html(Equation::of('x'), $o);
        self::assertStringContainsString('@font-face{font-family:"Papercut Math";src:url(data:font/ttf;base64,', $html);
        self::assertStringContainsString(self::RULE, $html);
    }

    public function test_block_math_is_shrunk_and_placed_by_margins(): void
    {
        // A browser centres the content of a full-width display:block math; a fit-content box placed by margins follows the alignment.
        $rule = '.papercut math[display=block]{width:fit-content;max-width:100%}';
        $default = $this->html(Equation::of('x'));
        self::assertStringContainsString($rule, $default);
        self::assertStringContainsString('<math xmlns=', $default);
        self::assertStringNotContainsString('<math style=', $default);

        self::assertStringContainsString('<math style="margin-inline:auto" xmlns=', $this->html(Equation::of('x')->textAlign('center')));
        self::assertStringContainsString('<math style="margin-left:auto" xmlns=', $this->html(Equation::of('x')->textAlign('right')));
        self::assertStringNotContainsString('<math style=', $this->html(Equation::of('x')->textAlign('left')));

        self::assertStringNotContainsString('math[display=block]', Document::make(Page::a4()->body(Text::make('Hi')))->toHtml()->bytes());
    }

    public function test_no_equation_no_math_output(): void
    {
        $html = Document::make(Page::a4()->body(Text::make('Hi')))->toHtml(new HtmlOptions(fontMode: FontMode::Embed))->bytes();
        self::assertStringNotContainsString('Papercut Math', $html);
        self::assertStringNotContainsString('<math', $html);
    }

    public function test_colour_inherits(): void
    {
        $html = $this->html(Equation::of('x')->color(Color::hex('#ff0000'))->size(Length::points(30)));
        self::assertMatchesRegularExpression('/<div style="[^"]*font-size:30pt[^"]*"><math[ >]/', $html);
        self::assertMatchesRegularExpression('/<div style="[^"]*color:#ff0000[^"]*"><math[ >]/', $html);
        // The MathML itself sets no colour or size: it inherits them from the wrapper.
        $m = $this->mathml($html);
        self::assertStringNotContainsString('color', $m);
        self::assertStringNotContainsString('font-size', $m);
    }

    public function test_escapes(): void
    {
        $html = $this->html(Equation::of(Math::text('a < b & c'), '<', '&'));
        $m = $this->mathml($html);
        self::assertStringContainsString('a &lt; b &amp; c', $m);
        self::assertStringNotContainsString('a < b', $m);
        self::assertStringNotContainsString('& c', $m);
        self::assertSame(1, preg_match('/<mo>&lt;<\/mo>/', $m));
        self::assertSame(1, preg_match('/<mo[^>]*>&amp;<\/mo>/', $m));
    }
}
