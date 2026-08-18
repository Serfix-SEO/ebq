<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContentArticleFeedback;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Admin monitoring for the client "Do you like this article?" verdicts
 * (love | rewrites | wrong) + optional comments. Read-only list + rating
 * filter + at-a-glance counts, so the team sees where clients are unhappy.
 */
class ContentFeedbackController extends Controller
{
    public function index(Request $request): View
    {
        $rating = (string) $request->query('rating', '');

        $query = ContentArticleFeedback::query()
            ->with(['user:id,name,email', 'website:id,domain', 'topic:id,title'])
            ->latest();

        if (in_array($rating, ContentArticleFeedback::RATINGS, true)) {
            $query->where('rating', $rating);
        }

        $counts = ContentArticleFeedback::query()
            ->selectRaw('rating, count(*) as c')
            ->groupBy('rating')
            ->pluck('c', 'rating');

        return view('admin.content-feedback.index', [
            'rows' => $query->paginate(40)->withQueryString(),
            'counts' => $counts,
            'total' => (int) $counts->sum(),
            'rating' => $rating,
        ]);
    }

    /**
     * Dismiss one verdict from the admin home. Deliberately NOT a delete: the
     * feedback list stays complete for trend-reading, this only takes the row
     * off the "needs a look" queue, and records who cleared it.
     */
    public function markSeen(Request $request, ContentArticleFeedback $feedback): RedirectResponse
    {
        if ($feedback->seen_at === null) {
            $feedback->forceFill([
                'seen_at' => now(),
                'seen_by' => (string) Auth::user()?->email,
            ])->save();
        }

        return redirect()->to($request->input('back', route('admin.dashboard')))
            ->with('status', 'feedback-seen');
    }
}
