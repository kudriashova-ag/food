<?php

namespace App\Services\Menu;

/** Підсумок застосування шаблону або копіювання тижня. */
final readonly class MenuApplyResult
{
    public function __construct(
        public int $created = 0,
        public int $updated = 0,
        public int $skipped = 0,
    ) {}

    public function with(int $created = 0, int $updated = 0, int $skipped = 0): self
    {
        return new self(
            $this->created + $created,
            $this->updated + $updated,
            $this->skipped + $skipped,
        );
    }

    public function summary(): string
    {
        $parts = ["створено днів: {$this->created}", "оновлено: {$this->updated}"];

        if ($this->skipped > 0) {
            $parts[] = "пропущено (меню вже було): {$this->skipped}";
        }

        return ucfirst(implode(', ', $parts)).'.';
    }
}
