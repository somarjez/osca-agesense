<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $logs = ActivityLog::with('user')
            ->when($request->action,  fn($q) => $q->where('action', $request->action))
            ->when($request->search,  fn($q) => $q->where('description', 'like', '%' . $request->search . '%'))
            ->latest()
            ->paginate(50)
            ->withQueryString();

        $actions = ActivityLog::select('action')->distinct()->orderBy('action')->pluck('action');

        return view('activity_log.index', compact('logs', 'actions'));
    }
}
