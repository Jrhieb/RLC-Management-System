<?php

namespace App\Http\Controllers;

use App\Models\Beneficiary;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class MyCaseloadController extends Controller
{
    public function index(Request $request): View|Response
    {
        Gate::authorize('viewMyCaseload', Beneficiary::class);
        $search = $request->query('search', '');
        $search = is_string($search) ? trim($search) : '';
        $status = $request->query('status', '');
        $status = in_array($status, ['active', 'inactive'], true) ? $status : '';
        $registryFilters = array_filter(['search' => $search, 'status' => $status], fn ($value) => $value !== '');

        // The owner always comes from authentication. Ignore caseworker, user,
        // caseworker_id, tab and archived parameters, including array values.
        $beneficiaries = $request->user()->assignedBeneficiaries()->with('caseworker:id,name,email')
            ->visibleTo($request->user())
            ->matchingSearch($search)->when($status !== '', fn ($query) => $query->where('status', $status))
            ->alphabetical()->paginate(10)->appends($registryFilters);

        $view = view('beneficiaries.index', [
            'isMyCaseload' => true, 'beneficiaries' => $beneficiaries, 'search' => $search, 'status' => $status,
            'registryFilters' => $registryFilters, 'caseworkerFilter' => '', 'caseloadName' => null,
            'caseworkers' => collect(), 'registryCaseworkers' => collect(), 'tab' => 'beneficiaries', 'unassignedCount' => 0,
        ]);

        if ($request->header('X-Registry-Fragment') === 'results') {
            return response($view->fragments(['registry-caseload', 'registry-results']))->withHeaders([
                'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return $view;
    }
}
