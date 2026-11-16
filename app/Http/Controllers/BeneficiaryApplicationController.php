<?php

namespace App\Http\Controllers;

use App\Models\BeneficiaryApplication;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class BeneficiaryApplicationController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', BeneficiaryApplication::class);
        $applications = BeneficiaryApplication::query()->latest()->paginate(15);

        return response()->view('applications.index', compact('applications'))->header('Cache-Control', 'private, no-store');
    }

    public function show(BeneficiaryApplication $application): Response
    {
        Gate::authorize('view', $application);

        return response()->view('applications.show', compact('application'))->header('Cache-Control', 'private, no-store');
    }
}
