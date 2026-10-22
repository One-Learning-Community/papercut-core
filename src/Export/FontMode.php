<?php

declare(strict_types=1);

namespace Papercut\Export;

enum FontMode: string
{
    case None = 'none';
    case Embed = 'embed';
    case Link = 'link';
    /**
     * Link fonts from the ordered `HtmlOptions::fontSources` stack (see Papercut\Html\FontSource, GoogleFonts, LocalFonts).
     *
     * Privacy: with a third-party host the visitor's browser fetches the fonts and sends the host its IP address
     * (LG München I, 3 O 17493/20). Opt-in; use LocalFonts, Embed or Link to keep requests on your own side.
     */
    case Sources = 'sources';
}
