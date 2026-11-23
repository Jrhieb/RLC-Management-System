<?php

namespace App\Exceptions;

use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class DuplicatePpiHouseholdException extends RuntimeException
{
    public function render(Request $request): Response
    {
        return response()->json(
            ['message' => 'An application for this household has already been received.'],
            422,
            ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'],
        );
    }
}
