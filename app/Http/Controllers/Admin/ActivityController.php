<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClientActivity;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ActivityController extends Controller
{
    public function index(Request $request): View
    {
        $userId = (string) $request->query('user_id', '');
        $type = trim((string) $request->query('type', ''));
        $provider = trim((string) $request->query('provider', ''));

        $activities = ClientActivity::query()
            ->with(['user:id,name,email', 'actor:id,name,email', 'website:id,domain'])
            ->when($userId !== '', fn ($q) => $q->where('user_id', $userId))
            ->when($type !== '', fn ($q) => $q->where('type', $type))
            ->when($provider !== '', fn ($q) => $q->where('provider', $provider))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return view('admin.activities.index', [
            'activities' => $activities,
            'users' => User::query()->select('id', 'name', 'email')->orderBy('name')->limit(200)->get(),
            'filters' => compact('userId', 'type', 'provider'),
        ]);
    }
}
