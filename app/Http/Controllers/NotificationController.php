<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = $request->user()->notifications()->latest()->paginate(20)->through(function ($n) {
            return [
                'id' => $n->id,
                'title' => $n->data['title'] ?? 'Notification',
                'message' => $n->data['message'] ?? '',
                'url' => $n->data['url'] ?? null,
                'read_at' => optional($n->read_at)->toDateTimeString(),
                'created_at' => $n->created_at->toDateTimeString(),
            ];
        });

        return successResponse('Notifications fetched.', ['notifications' => $notifications]);
    }

    public function read(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return successResponse('Notification read.', ['url' => $notification->data['url'] ?? null]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return successResponse('All notifications marked as read.');
    }
}
