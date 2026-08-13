<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $logs = ActivityLog::with('user')
            ->when($request->action, fn ($q) => $q->where('action', $request->action))
            // Case-insensitive (LIKE alone is case-sensitive on Postgres,
            // production's DB engine — see .env — though not on local MySQL's
            // default collation, which is why this went unnoticed until now).
            ->when($request->search, fn ($q) => $q->whereRaw('LOWER(description) LIKE ?', ['%'.strtolower($request->search).'%']))
            ->latest()
            ->paginate(50)
            ->withQueryString();

        $actions = ActivityLog::select('action')->distinct()->orderBy('action')->pluck('action');

        return view('activity_log.index', compact('logs', 'actions'));
    }

    public function bulkDestroy(Request $request)
    {
        $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'min:1'],
        ]);

        $count = count($request->ids);

        Log::warning('Activity log entries deleted', [
            'user_id' => auth()->id(),
            'action' => 'bulk_destroy',
            'count' => $count,
            'ip' => $request->ip(),
        ]);

        ActivityLog::whereIn('id', $request->ids)->delete();

        $noun = $count === 1 ? 'entry' : 'entries';

        return back()->with('success', "{$count} log {$noun} deleted.");
    }

    public function clear(Request $request)
    {
        $count = ActivityLog::count();

        Log::warning('Activity log entries deleted', [
            'user_id' => auth()->id(),
            'action' => 'clear',
            'count' => $count,
            'ip' => $request->ip(),
        ]);

        // Use delete() rather than truncate() — truncate issues an implicit
        // commit in MySQL which breaks DatabaseTransactions in tests.
        ActivityLog::query()->delete();

        return redirect()->route('activity-log.index')
            ->with('success', 'All activity log entries have been cleared.');
    }
}
