<?php

namespace App\Http\Controllers;

use App\Models\ReportShare;
use App\Models\Website;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Mint / revoke public share links for the currently selected website's report.
 * Authed + ownership-gated. The token resolves at the public /r/{token} route.
 */
class ReportShareController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $user = Auth::user();
        $websiteId = session('current_website_id');
        abort_unless($websiteId !== null && $websiteId !== '' && $user?->canViewWebsiteId($websiteId), 403);

        $website = Website::findOrFail($websiteId);

        // Reuse an existing active share so re-clicking doesn't spawn duplicates.
        $share = ReportShare::active()->where('website_id', $website->id)->first()
            ?? ReportShare::create([
                'website_id' => $website->id,
                'created_by' => $user->id,
                'token' => ReportShare::newToken(),
            ]);

        return back()->with('report_share_url', route('report.public', $share->token));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = Auth::user();
        $websiteId = session('current_website_id');
        abort_unless($websiteId !== null && $websiteId !== '' && $user?->canViewWebsiteId($websiteId), 403);

        ReportShare::where('website_id', $websiteId)->whereNull('revoked_at')->update(['revoked_at' => now()]);

        return back()->with('report_share_revoked', true);
    }
}
