<?php

namespace App\Http\Controllers;

use App\Jobs\SyncZohoConnectionJob;
use App\Models\ZohoConnection;
use App\Services\Zoho\ZohoOAuthClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class ZohoConnectionController extends Controller
{
    public function __construct(
        private readonly ZohoOAuthClient $oauth,
    ) {}

    public function connect(Request $request): RedirectResponse
    {
        $state = Str::random(40);
        $request->session()->put('zoho_oauth_state', $state);

        return redirect()->away($this->oauth->getAuthorizationUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $state = $request->session()->pull('zoho_oauth_state');

        if (! $state || $request->query('state') !== $state) {
            return redirect()->route('profile.edit')->with('zoho_status', 'Zoho connection failed: invalid state.');
        }

        if ($request->query('error') || ! $request->query('code')) {
            return redirect()->route('profile.edit')->with('zoho_status', 'Zoho connection was not authorized.');
        }

        try {
            $tokens = $this->oauth->exchangeCodeForToken($request->query('code'));

            $connection = ZohoConnection::updateOrCreate(
                ['user_id' => $request->user()->id],
                [
                    'api_domain' => $tokens['api_domain'],
                    'access_token' => $tokens['access_token'],
                    'refresh_token' => $tokens['refresh_token'],
                    'access_token_expires_at' => now()->addSeconds($tokens['expires_in'] ?? 3600),
                    'status' => 'connected',
                    'last_error' => null,
                    'zoho_organization_id' => null,
                ]
            );
        } catch (Throwable $e) {
            return redirect()->route('profile.edit')->with('zoho_status', 'Zoho connection failed: '.$e->getMessage());
        }

        SyncZohoConnectionJob::dispatch($connection);

        return redirect()->route('profile.edit')->with('zoho_status', 'Zoho connected — the first sync is running in the background and may take a while for a large account.');
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $request->user()->zohoConnection?->delete();

        return redirect()->route('profile.edit')->with('zoho_status', 'Zoho account disconnected.');
    }

    public function syncNow(Request $request): RedirectResponse
    {
        $connection = $request->user()->zohoConnection;

        if (! $connection) {
            return redirect()->route('profile.edit')->with('zoho_status', 'No Zoho account connected yet.');
        }

        SyncZohoConnectionJob::dispatch($connection);

        return redirect()->route('profile.edit')->with('zoho_status', 'Sync started in the background.');
    }
}
