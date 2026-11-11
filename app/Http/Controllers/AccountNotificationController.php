<?php

namespace App\Http\Controllers;

use App\Models\AccountNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AccountNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = $this->owned($request);
        $items = (clone $query)->latest('created_at')->latest('id')->limit(20)->get()->map(fn ($notification) => [
            'id' => $notification->id, 'title' => $notification->title(), 'message' => $notification->message(),
            'url' => $notification->destination(), 'read_url' => route('account.notifications.read', $notification->id, absolute: false),
            'time' => $notification->created_at->timezone('Asia/Manila')->format('m/d/Y g:i A').' PHT',
            'read_at' => $notification->read_at?->toIso8601String(),
        ]);

        return $this->response(['items' => $items, 'unread_count' => $query->whereNull('read_at')->count()]);
    }

    public function count(Request $request): JsonResponse
    {
        return $this->response(['unread_count' => $this->owned($request)->whereNull('read_at')->count()]);
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        $query = $this->owned($request);
        $record = (clone $query)->whereKey($notification)->firstOrFail();
        (clone $query)->whereKey($record->id)->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);
        $record->refresh();

        return $this->response(['read_at' => $record->read_at->toIso8601String(), 'unread_count' => $query->whereNull('read_at')->count()]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $this->owned($request)->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);

        return $this->response(['unread_count' => $this->owned($request)->whereNull('read_at')->count()]);
    }

    private function owned(Request $request): Builder
    {
        Gate::authorize('viewAccountNotifications', User::class);

        return AccountNotification::forRecipient($request->user());
    }

    private function response(array $data): JsonResponse
    {
        return response()->json($data)->withHeaders(['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
