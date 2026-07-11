<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BugReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BugReportController extends Controller
{
    public function index(Request $request): View
    {
        $status = (string) $request->query('status', '');

        $reports = BugReport::query()
            ->with('user:id,name,email')
            ->when(in_array($status, [BugReport::STATUS_NEW, BugReport::STATUS_RESOLVED], true),
                fn ($q) => $q->where('status', $status))
            ->latest()
            ->paginate(30)
            ->withQueryString();

        return view('admin.bug-reports.index', [
            'reports' => $reports,
            'status' => $status,
            'newCount' => BugReport::where('status', BugReport::STATUS_NEW)->count(),
        ]);
    }

    /** Screenshots live on the private local disk — admin-only serving. */
    public function screenshot(BugReport $bugReport): BinaryFileResponse
    {
        abort_unless(
            $bugReport->screenshot_path !== null
            && Storage::disk('local')->exists($bugReport->screenshot_path),
            404,
        );

        return response()->file(Storage::disk('local')->path($bugReport->screenshot_path));
    }

    /**
     * Mark resolved with a customer-facing resolution note; the reporter is
     * emailed the note (BugReportResolved). Re-posting on a resolved report
     * REOPENS it (note kept for history, no email).
     */
    public function resolve(Request $request, BugReport $bugReport): RedirectResponse
    {
        if ($bugReport->status === BugReport::STATUS_RESOLVED) {
            $bugReport->update(['status' => BugReport::STATUS_NEW, 'resolved_at' => null]);

            return back();
        }

        $validated = $request->validate([
            'resolution_note' => ['required', 'string', 'max:5000'],
        ]);

        $bugReport->update([
            'status' => BugReport::STATUS_RESOLVED,
            'resolution_note' => $validated['resolution_note'],
            'resolved_at' => now(),
        ]);

        try {
            $email = $bugReport->user?->email;
            if ($email) {
                \Illuminate\Support\Facades\Mail::to($email)->send(new \App\Mail\BugReportResolved($bugReport));
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("BugReportController: resolved-mail failed for {$bugReport->id}: {$e->getMessage()}");

            return back()->with('status', 'Marked resolved, but the notification email failed to send.');
        }

        return back()->with('status', 'Marked resolved — the reporter has been notified.');
    }
}
