<?php

namespace App\Exceptions;

use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class InvalidPpiStatusTransitionException extends RuntimeException
{
    public function render(Request $request): Response
    {
        return response()->json(
            ['message' => 'The requested status change is not allowed.'],
            409,
            ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'],
        );
    }
}
