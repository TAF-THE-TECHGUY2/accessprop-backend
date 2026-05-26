<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
        ]);

        $query = EmailLog::query()->orderByDesc('sent_at');

        if (! empty($filters['search'])) {
            $term = '%'.strtolower($filters['search']).'%';
            $query->where(function ($q) use ($term) {
                $q->whereRaw('LOWER(recipient) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(subject) LIKE ?', [$term]);
            });
        }
        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $logs = $query->get()->map(fn (EmailLog $log) => [
            'id' => $log->code,
            'recipient' => $log->recipient,
            'type' => $log->type,
            'subject' => $log->subject,
            'status' => $log->status,
            'sentAt' => optional($log->sent_at)->toIso8601String(),
        ]);

        return response()->json($logs);
    }
}
