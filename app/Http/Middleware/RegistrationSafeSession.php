<?php

namespace App\Http\Middleware;

use Illuminate\Session\Middleware\StartSession;

class RegistrationSafeSession extends StartSession
{
    protected function storeCurrentUrl($request, $session)
    {
        if ($request->routeIs('registration.verify')) {
            // Never persist a bearer link as the session's previous URL or redirect back to it.
            $session->setPreviousUrl(route('register'));
            $session->setPreviousRoute('register');

            return;
        }

        parent::storeCurrentUrl($request, $session);
    }
}
