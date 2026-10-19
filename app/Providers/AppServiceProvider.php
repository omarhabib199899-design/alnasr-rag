<?php

namespace App\Providers;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Manager request (2026-09-30): track who logs in/out and failed
        // attempts — feeds the Activity Log page (login_logs table).
        Event::listen(function (Login $event) {
            DB::table('login_logs')->insert([
                'user_id' => $event->user->id,
                'email' => $event->user->email,
                'event' => 'login',
                'ip_address' => request()->ip(),
                'user_agent' => substr((string) request()->userAgent(), 0, 255),
                'created_at' => now(),
            ]);
        });

        Event::listen(function (Logout $event) {
            DB::table('login_logs')->insert([
                'user_id' => $event->user?->id,
                'email' => $event->user?->email ?? 'unknown',
                'event' => 'logout',
                'ip_address' => request()->ip(),
                'user_agent' => substr((string) request()->userAgent(), 0, 255),
                'created_at' => now(),
            ]);
        });

        Event::listen(function (Failed $event) {
            DB::table('login_logs')->insert([
                'user_id' => $event->user?->id,
                'email' => $event->credentials['email'] ?? ($event->user->email ?? 'unknown'),
                'event' => 'failed',
                'ip_address' => request()->ip(),
                'user_agent' => substr((string) request()->userAgent(), 0, 255),
                'created_at' => now(),
            ]);
        });
    }
}
