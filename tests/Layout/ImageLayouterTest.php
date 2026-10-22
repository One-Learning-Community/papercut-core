<?php

declare(strict_types=1);

namespace Papercut\Tests\Layout;

use Papercut\Image;
use Papercut\Layout\Status;
use Papercut\Paint\{Clip, DrawImage};
use Papercut\Style\ImageFit;
use Papercut\Tests\Support\LayoutHarness;
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class ImageLayouterTest extends TestCase
{
    // FakeBackend images are 100x50 px => 75x37.5 pt intrinsic
    private function draw(?\Papercut\Layout\Fragment $f): DrawImage
    {
        foreach ($f?->ops() ?? [] as $op) {
            if ($op instanceof DrawImage) { return $op; }
            if ($op instanceof Clip && $op->ops[0] instanceof DrawImage) { return $op->ops[0]; }
        }
        self::fail('no image op');
    }

    public function test_intrinsic_size(): void
    {
        $f = (new LayoutHarness())->layout(Image::bytes('x'), 500)->fragment;
        self::assertSame([75.0, 37.5], [$f?->width, $f?->height]);
    }

    public function test_scales_down_to_available_width(): void
    {
        $f = (new LayoutHarness())->layout(Image::bytes('x'), 30)->fragment;
        self::assertSame([30.0, 15.0], [$f?->width, $f?->height]);
    }

    public function test_one_dimension_keeps_aspect(): void
    {
        $f = (new LayoutHarness())->layout(Image::bytes('x')->height(pt(10)), 500)->fragment;
        self::assertSame([20.0, 10.0], [$f?->width, $f?->height]);
    }

    public function test_contain_and_cover(): void
    {
        $h = new LayoutHarness();
        $contain = $this->draw($h->layout(Image::bytes('x')->width(pt(40))->height(pt(40)), 500)->fragment);
        self::assertSame([0.0, 10.0, 40.0, 20.0], [$contain->x, $contain->y, $contain->width, $contain->height]);
        $f = $h->layout(Image::bytes('x')->width(pt(40))->height(pt(40))->fit(ImageFit::Cover), 500)->fragment;
        self::assertInstanceOf(Clip::class, $f?->ops()[0]);
        $cover = $this->draw($f);
        self::assertSame([-20.0, 0.0, 80.0, 40.0], [$cover->x, $cover->y, $cover->width, $cover->height]);
    }

    public function test_fill_stretches(): void
    {
        $f = (new LayoutHarness())->layout(Image::bytes('x')->width(pt(40))->height(pt(40))->fit(ImageFit::Fill), 500)->fragment;
        $d = $this->draw($f);
        self::assertSame([0.0, 0.0, 40.0, 40.0], [$d->x, $d->y, $d->width, $d->height]);
    }

    public function test_does_not_fit_below_other_content(): void
    {
        self::assertSame(Status::DoesNotFit, (new LayoutHarness())->layout(Image::bytes('x'), 500, 10, null, false)->status);
    }

    public function test_scales_down_to_an_empty_page_keeping_aspect(): void
    {
        $res = (new LayoutHarness())->layout(Image::bytes('x'), 500, 10, null, true);
        self::assertSame(Status::Complete, $res->status);
        self::assertSame([20.0, 10.0], [$res->fragment?->width, $res->fragment?->height]);
        $d = $this->draw($res->fragment);
        self::assertSame([0.0, 0.0, 20.0, 10.0], [$d->x, $d->y, $d->width, $d->height]);
    }

    public function test_explicit_width_still_scales_down_to_an_empty_page(): void
    {
        $f = (new LayoutHarness())->layout(Image::bytes('x')->width(pt(60)), 500, 10, null, true)->fragment;
        self::assertSame([20.0, 10.0], [$f?->width, $f?->height]);
    }

    public function test_scaling_to_the_page_keeps_padding_and_border(): void
    {
        $f = (new LayoutHarness())->layout(Image::bytes('x')->padding(pt(2)), 500, 14, null, true)->fragment;
        self::assertSame([24.0, 14.0], [$f?->width, $f?->height]);
    }

    public function test_explicit_height_taller_than_an_empty_page_does_not_fit(): void
    {
        self::assertSame(Status::DoesNotFit, (new LayoutHarness())->layout(Image::bytes('x')->height(pt(40)), 500, 10, null, true)->status);
    }

    public function test_intrinsic_widths(): void
    {
        $h = new LayoutHarness();
        $root = \Papercut\Style\ComputedStyle::root(\Papercut\Style\Theme::default());
        self::assertSame(['min' => 75.0, 'max' => 75.0], $h->engine->intrinsic(Image::bytes('x'), $root));
        self::assertSame(['min' => 40.0, 'max' => 40.0], $h->engine->intrinsic(Image::bytes('x')->width(pt(40)), $root));
    }

    // --- Task 13 ---------------------------------------------------------

    public function test_explicit_width_wider_than_the_container_scales_down(): void
    {
        $h = new LayoutHarness();
        $f = $h->layout(Image::bytes('x')->width(pt(300)), 100)->fragment;
        self::assertSame([100.0, 50.0], [$f?->width, $f?->height]);
        // both given: both scale by the same factor
        $both = $h->layout(Image::bytes('x')->width(pt(300))->height(pt(60)), 100)->fragment;
        self::assertSame([100.0, 20.0], [$both?->width, $both?->height]);
    }

    public function test_min_and_max_sizes_clamp_keeping_the_aspect_ratio(): void
    {
        $h = new LayoutHarness();
        $max = $h->layout(Image::bytes('x')->maxWidth(pt(30)), 500)->fragment;
        self::assertSame([30.0, 15.0], [$max?->width, $max?->height]);
        $min = $h->layout(Image::bytes('x')->minWidth(pt(150)), 500)->fragment;
        self::assertSame([150.0, 75.0], [$min?->width, $min?->height]);
        $maxH = $h->layout(Image::bytes('x')->maxHeight(pt(10)), 500)->fragment;
        self::assertSame([20.0, 10.0], [$maxH?->width, $maxH?->height]);
        $minH = $h->layout(Image::bytes('x')->width(pt(20))->minHeight(pt(20)), 500)->fragment;
        self::assertSame([40.0, 20.0], [$minH?->width, $minH?->height]);
        // both explicit: clamping one dimension does not touch the other
        $fixed = $h->layout(Image::bytes('x')->width(pt(40))->height(pt(40))->maxWidth(pt(30)), 500)->fragment;
        self::assertSame([30.0, 40.0], [$fixed?->width, $fixed?->height]);
    }

    public function test_padding_with_an_explicit_size(): void
    {
        $h = new LayoutHarness();
        $f = $h->layout(Image::bytes('x')->width(pt(40))->height(pt(40))->padding(pt(5)), 500)->fragment;
        self::assertSame([40.0, 40.0], [$f?->width, $f?->height]);
        $d = $this->draw($f);
        self::assertSame([5.0, 12.5, 30.0, 15.0], [$d->x, $d->y, $d->width, $d->height]);
        // only the width given: the derived height follows the content box, then scales to the container
        $w = $h->layout(Image::bytes('x')->width(pt(300))->padding(pt(10)), 100)->fragment;
        self::assertSame([100.0, 60.0], [$w?->width, $w?->height]);
    }

    public function test_percent_height_without_a_definite_height_is_auto(): void
    {
        $f = (new LayoutHarness())->layout(Image::bytes('x')->height(\Papercut\percent(50)), 500)->fragment;
        self::assertSame([75.0, 37.5], [$f?->width, $f?->height]);
    }

    public function test_intrinsic_includes_padding_and_border(): void
    {
        $h = new LayoutHarness();
        $root = \Papercut\Style\ComputedStyle::root(\Papercut\Style\Theme::default());
        $s = $h->engine->intrinsic(Image::bytes('x')->padding(pt(5))->border(pt(1)), $root);
        self::assertSame(['min' => 87.0, 'max' => 87.0], $s);
    }

    // --- Final review ---

    public function test_max_width_still_holds_after_min_height_grows_the_image(): void
    {
        // minHeight(50) would widen the image to 100pt; maxWidth wins and the aspect ratio is given up
        $f = (new LayoutHarness())->layout(Image::bytes('x')->maxWidth(pt(30))->minHeight(pt(50)), 500)->fragment;
        self::assertSame([30.0, 50.0], [$f?->width, $f?->height]);
        // the height clamp runs last and keeps the aspect: a conflicting minWidth loses to maxHeight
        $g = (new LayoutHarness())->layout(Image::bytes('x')->maxHeight(pt(20))->minWidth(pt(100)), 500)->fragment;
        self::assertSame([40.0, 20.0], [$g?->width, $g?->height]);
    }
}
