<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\WorkflowInstance;
use App\Support\ActiveMembershipLocator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = $this->scopedNotifications($request);
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'in:all,read,unread'],
        ]);
        $status = $validated['status'] ?? 'all';
        $query->when($status === 'read', fn (Builder $query) => $query->whereNotNull('read_at'))
            ->when($status === 'unread', fn (Builder $query) => $query->whereNull('read_at'));
        $page = $query->latest()->orderByDesc('id')->paginate(50);
        $items = $page->getCollection()->map(fn (DatabaseNotification $notification) => [
            'id' => $notification->id,
            'type' => $notification->type,
            'data' => $notification->data,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ]);

        return response()->json(['data' => [
            'items' => $items,
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
        ]]);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $row = $this->scopedNotifications($request)->whereKey($notification)->firstOrFail();
        $row->markAsRead();

        return response()->json(['data' => ['id' => $row->id, 'read_at' => $row->read_at?->toIso8601String()]]);
    }

    public function markOrganizationRead(Request $request, string $organization, string $notification): JsonResponse
    {
        return $this->markRead($request, $notification);
    }

    private function scopedNotifications(Request $request): Builder
    {
        $locator = app(ActiveMembershipLocator::class);
        if ($request->route('organization')) {
            $organization = $locator->organizationFrom($request);
        } else {
            $uuid = $request->user()->currentOrganization?->uuid;
            abort_unless(is_string($uuid), 404);
            $organization = $locator->organization($request->user(), $uuid);
        }

        // Resolve legacy payloads through their instance rather than trusting display text.
        $notifications = $request->user()->notifications()->getQuery();
        $instances = WorkflowInstance::query()->where('organization_id', $organization->id);

        if ($notifications->getConnection()->getDriverName() === 'pgsql') {
            return $notifications->whereIn(DB::raw("(data::jsonb ->> 'instance_uuid')"), $instances->selectRaw('uuid::text'));
        }

        return $notifications->whereIn('data->instance_uuid', $instances->select('uuid'));
    }
}
