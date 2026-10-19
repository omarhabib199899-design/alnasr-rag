<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Minimal controller for the in-app notification bell (layouts.notification-bell)
 * — marks notifications read, nothing else. No index/list page of its own;
 * the bell's dropdown is the only UI, each notification just deep-links to
 * its own report page (e.g. /reports/sfda-market-changes).
 */
class NotificationController extends Controller
{
    public function open(Request $request, string $id): RedirectResponse
    {
        $notification = $request->user()->notifications()->where('id', $id)->first();

        if ($notification === null) {
            abort(404);
        }

        $notification->markAsRead();

        return redirect($notification->data['url'] ?? route('dashboard'));
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }
}
