<?php

declare(strict_types=1);

namespace Papercut;

use Papercut\Style\Theme;

/** A Component whose rendering depends on the theme of the page it lands on; render() renders without one. */
interface ThemedComponent extends Component
{
    public function renderFor(Theme $theme): Node|Component;
}
