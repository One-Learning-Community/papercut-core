<?php

declare(strict_types=1);

namespace Papercut\Html;

use Papercut\Backend\Backend;
use Papercut\Export\LoadedImage;
use Papercut\Image;
use Papercut\Paint\Link;

/** Decides what goes in an img src: the caller's URL when it is safe, otherwise the image itself as a data URI. */
final class ImageSource
{
    /**
     * @param (\Closure(Image): ?string)|null $imageUrl
     * @param (\Closure(): void)|null $onRefused called when the callback's URL is unsafe and the image is embedded instead
     * @param (\Closure(Image): LoadedImage)|null $load how to load an image to embed; when given it takes precedence over $backend; defaults to loading through the backend each time
     */
    public static function uri(Image $image, ?\Closure $imageUrl, Backend $backend, ?\Closure $onRefused = null, ?\Closure $load = null): string
    {
        if ($imageUrl !== null) {
            $url = $imageUrl($image);
            if ($url !== null && self::safe($url)) {
                return $url;
            }
            if ($url !== null && $onRefused !== null) {
                $onRefused();
            }
        }

        $loaded = $load !== null ? $load($image) : LoadedImage::load($image, $backend);

        return 'data:' . $loaded->mime . ';base64,' . base64_encode($loaded->bytes);
    }

    /** An allowed link scheme, a data:image/ URI, or a relative path. Control characters and edge whitespace are refused: browsers drop them inside schemes. */
    private static function safe(string $url): bool
    {
        return Urls::clean($url) && (
            Link::allowed($url)
            || preg_match('~^data:image/~i', $url) === 1
            || Urls::relative($url)
        );
    }
}
