<?php

namespace App\Http\Controllers;

use App\Models\Website;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Signed entry points for the WordPress HQ plugin. Opens in a new browser
 * tab (not an iframe) so session cookies work across origins.
 */
class WordPressEmbedController extends Controller
{
    public function reports(Request $request): RedirectResponse
    {
        $websiteId = (string) $request->query('website', '');
        abort_unless($websiteId !== '', 404);

        $website = Website::query()->with('user')->findOrFail($websiteId);
        $user = $website->user;
        abort_unless($user !== null, 404);

        Auth::login($user);
        $request->session()->regenerate();
        session(['current_website_id' => $websiteId]);

        $view = (string) $request->query('view', 'insights');
        $params = [];
        if ($view === 'email') {
            $params['view'] = 'email';
        } else {
            $insight = (string) $request->query('insight', 'cannibalization');
            if ($insight !== '') {
                $params['insight'] = $insight;
            }
        }

        return redirect()->route('reports.index', $params);
    }

    /**
     * Signed deep-link to a completed page audit report on EBQ.io.
     */
    public function pageAudit(Request $request): RedirectResponse
    {
        $websiteId = (string) $request->query('website', '');
        $reportId = (string) $request->query('report', '');
        abort_unless($websiteId !== '' && $reportId !== '', 404);

        $website = Website::query()->with('user')->findOrFail($websiteId);
        $user = $website->user;
        abort_unless($user !== null, 404);

        Auth::login($user);
        $request->session()->regenerate();
        session(['current_website_id' => $websiteId]);

        return redirect()->route('page-audits.show', $reportId);
    }
}
