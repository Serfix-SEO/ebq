<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContentArticleFeedback;
use App\Models\ContentRewriteRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Admin monitoring for the client "Do you like this article?" verdicts
 * (love | rewrites | wrong) + optional comments. Read-only list + rating
 * filter + at-a-glance counts, so the team sees where clients are unhappy.
 *
 * The verdict alone was not enough to act on (owner 2026-09-13: "we can see
 * what the customer did but not the prompt he typed"). Two reasons the
 * feedback comment is a lossy proxy for what a client actually asked for:
 *
 *  - The comment is the client's ORIGINAL wording. When they accept the
 *    sharpened version the enhancer offers, the text that reaches the writer
 *    is the ENHANCED prompt, stored on the rewrite request — so the comment
 *    can differ materially from the instruction that ran.
 *  - Feedback is one row per (topic, user) via updateOrCreate, so a second
 *    rewrite overwrites the first one's note. Every prompt ever sent survives
 *    on `content_rewrite_requests`; only the latest survives here.
 *
 * So each verdict is joined to its rewrite requests and the real prompts are
 * shown alongside the note.
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

        $rows = $query->paginate(40)->withQueryString();

        return view('admin.content-feedback.index', [
            'rows' => $rows,
            'rewrites' => $this->rewritesFor($rows->getCollection()),
            'counts' => $counts,
            'total' => (int) $counts->sum(),
            'rating' => $rating,
        ]);
    }

    /**
     * Every rewrite a client actually requested for the verdicts on this page,
     * keyed "topicId:userId" — the same pair the feedback row is unique on.
     * One query for the page rather than one per row.
     *
     * @param  \Illuminate\Support\Collection<int, ContentArticleFeedback>  $feedback
     * @return \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, ContentRewriteRequest>>
     */
    private function rewritesFor($feedback): \Illuminate\Support\Collection
    {
        if ($feedback->isEmpty()) {
            return collect();
        }

        return ContentRewriteRequest::query()
            ->whereIn('topic_id', $feedback->pluck('topic_id')->unique()->all())
            ->whereIn('user_id', $feedback->pluck('user_id')->unique()->all())
            ->orderBy('created_at')
            ->get(['id', 'topic_id', 'user_id', 'prompt', 'status', 'created_at'])
            ->groupBy(fn (ContentRewriteRequest $r) => $r->topic_id.':'.$r->user_id);
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
