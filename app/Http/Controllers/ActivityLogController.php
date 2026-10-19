<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Manager request (2026-09-30, voice note): see who logged into the
 * system and what they did (edits/actions) — two separate feeds, both
 * filterable by user, most recent first.
 */
class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $userId = $request->query('user_id');

        $logins = DB::table('login_logs')
            ->leftJoin('users', 'users.id', '=', 'login_logs.user_id')
            ->when($userId, fn ($q) => $q->where('login_logs.user_id', $userId))
            ->select('login_logs.*', 'users.name as user_name')
            ->orderByDesc('login_logs.created_at')
            ->paginate(25, ['*'], 'logins_page')
            ->withQueryString();

        $actions = DB::table('activity_logs')
            ->leftJoin('users', 'users.id', '=', 'activity_logs.user_id')
            ->when($userId, fn ($q) => $q->where('activity_logs.user_id', $userId))
            ->select('activity_logs.*', 'users.name as user_name')
            ->orderByDesc('activity_logs.created_at')
            ->paginate(25, ['*'], 'actions_page')
            ->withQueryString();

        return view('activity-log.index', [
            'logins' => $logins,
            'actions' => $actions,
            'users' => User::orderBy('name')->get(['id', 'name']),
            'selectedUserId' => $userId,
        ]);
    }
}
