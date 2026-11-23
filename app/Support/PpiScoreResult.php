<?php

namespace App\Support;

final readonly class PpiScoreResult
{
    /** @param list<PpiScoredAnswer> $answers */
    public function __construct(
        public int $score,
        public array $answers,
    ) {}
}
