<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Notification;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController
{
    use ApiResponse;

    /**
     * List notifications for the current user.
     *
     * Middleware: auth:sanctum (or jwt.auth)
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'unreadOnly' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Notification::where('user_id', auth()->id());

        if (! empty($validated['unreadOnly'])) {
            $query->where('is_read', false);
        }

        $page = $validated['page'] ?? 1;
        $limit = $validated['limit'] ?? 20;

        $notifications = $query->orderBy('created_at', 'desc')
            ->paginate($limit, ['*'], 'page', $page);

        $notifications->getCollection()->transform(function (Notification $n) {
            return [
                'id' => $n->id,
                'title' => $n->title,
                'body' => $n->body,
                'type' => $n->type instanceof \BackedEnum ? $n->type->value : ($n->type ?? null),
                'referenceId' => $n->reference_id,
                'isRead' => $n->is_read,
                'createdAt' => $n->created_at?->toIso8601String(),
            ];
        });

        return $this->paginated($notifications, 'Notification list retrieved');
    }

    /**
     * Mark a single notification as read.
     *
     * Middleware: auth:sanctum (or jwt.auth)
     */
    public function markRead($notification): JsonResponse
    {
        $model = Notification::where('user_id', auth()->id())->find($notification);

        if (! $model) {
            return $this->error('NOT_FOUND', 'Notification not found', 404);
        }

        $model->update(['is_read' => true]);

        return $this->success([], 'Notification marked as read');
    }

    /**
     * Mark all notifications as read for the current user.
     *
     * Middleware: auth:sanctum (or jwt.auth)
     */
    public function markAllRead(): JsonResponse
    {
        $count = Notification::where('user_id', auth()->id())
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return $this->success(['count' => $count], 'All notifications marked as read');
    }
}
