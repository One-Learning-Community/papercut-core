<?php

declare(strict_types=1);

namespace Quoin\Exception;

use Quoin\QuoinException;

/** A PDF page could not be drawn: the file is missing, is not a PDF, needs a password, or lacks the page. */
final class PdfUnreadable extends QuoinException
{
}
