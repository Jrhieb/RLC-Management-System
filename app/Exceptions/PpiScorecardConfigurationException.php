<?php

namespace App\Exceptions;

use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class PpiScorecardConfigurationException extends RuntimeException
{
    public function render(Request $request): Response
    {
        return response()->json(
            ['message' => 'PPI scoring is temporarily unavailable.'],
            503,
            ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'],
        );
    }
}
