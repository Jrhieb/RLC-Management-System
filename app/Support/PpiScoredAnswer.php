<?php

namespace App\Support;

final readonly class PpiScoredAnswer
{
    public function __construct(
        public int $questionId,
        public int $optionId,
        public int $points,
    ) {}
}
