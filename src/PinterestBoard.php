<?php
declare(strict_types=1);

namespace vielhuber\photobutler;

final readonly class PinterestBoard
{
    /**
     * Keep provider identifiers as strings rather than lossy browser numbers.
     */
    public function __construct(public string $id, public string $name, public int $pinCount) {}
}
