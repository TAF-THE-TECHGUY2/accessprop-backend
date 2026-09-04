<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MessageThread;
use App\Models\ThreadMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Secure messaging, team side.
 *
 * Any admin can read and reply to any thread — there is no assignment model,
 * because a small operations team covering a one-business-day promise is worse
 * served by a thread nobody else can answer while its owner is away. The reply
 * records who wrote it, so accountability comes from the record rather than
 * from a lock.
 */
class AdminMessageThreadController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $threads = MessageThread::query()
            ->with(['investor', 'messages'])
            ->orderByDesc('last_message_at')
            ->get();

        // Threads waiting on the team first, then most recent. Sorted here
        // rather than in SQL because the waiting state is derived from the last
        // message's author, not stored.
        $sorted = $threads->sortBy([
            fn (MessageThread $t) => $t->state() === 'awaiting_team' ? 0 : 1,
            fn (MessageThread $t) => -($t->last_message_at?->timestamp ?? 0),
        ])->values();

        return response()->json([
            'data' => $sorted->map(fn (MessageThread $t) => $this->summary($t)),
            'awaitingTeam' => $threads->filter(fn (MessageThread $t) => $t->state() === 'awaiting_team')->count(),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $thread = MessageThread::with(['investor', 'messages.document'])->findOrFail($id);

        $thread->messages()
            ->where('author_type', 'investor')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json($this->detail($thread->fresh(['investor', 'messages.document'])));
    }

    public function storeMessage(Request $request, int $id): JsonResponse
    {
        $thread = MessageThread::findOrFail($id);
        $admin = $request->user();

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'resolve' => ['nullable', 'boolean'],
        ]);

        DB::transaction(function () use ($thread, $admin, $data) {
            ThreadMessage::create([
                'thread_id' => $thread->id,
                'author_type' => 'admin',
                'author_id' => $admin->id,
                // Kept rather than joined, so the author still renders after
                // the staff account is removed.
                'author_name' => $admin->name ?: 'Access Properties',
                'body' => $data['body'],
            ]);

            $thread->update([
                'last_message_at' => now(),
                'resolved_at' => ! empty($data['resolve']) ? now() : $thread->resolved_at,
                'resolved_by' => ! empty($data['resolve']) ? $admin->id : $thread->resolved_by,
            ]);
        });

        return response()->json($this->detail($thread->fresh(['investor', 'messages.document'])));
    }

    public function resolve(Request $request, int $id): JsonResponse
    {
        $thread = MessageThread::findOrFail($id);

        $thread->update([
            'resolved_at' => now(),
            'resolved_by' => $request->user()->id,
        ]);

        return response()->json($this->detail($thread->fresh(['investor', 'messages.document'])));
    }

    public function reopen(Request $request, int $id): JsonResponse
    {
        $thread = MessageThread::findOrFail($id);

        $thread->update(['resolved_at' => null, 'resolved_by' => null]);

        return response()->json($this->detail($thread->fresh(['investor', 'messages.document'])));
    }

    private function summary(MessageThread $thread): array
    {
        $last = $thread->messages->last();

        return [
            'id' => $thread->id,
            'subject' => $thread->subject,
            'category' => $thread->category,
            'state' => $thread->state(),
            'investorCode' => $thread->investor?->code,
            'investorName' => $thread->investor?->name,
            'investorEmail' => $thread->investor?->email,
            'lastMessageAt' => optional($thread->last_message_at)->toIso8601String(),
            'messageCount' => $thread->messages->count(),
            'unread' => $thread->unreadFor('admin'),
            'preview' => $last?->body,
        ];
    }

    private function detail(MessageThread $thread): array
    {
        return $this->summary($thread) + [
            'messages' => $thread->messages->map(fn (ThreadMessage $m) => [
                'id' => $m->id,
                'authorType' => $m->author_type,
                'authorName' => $m->author_name,
                'body' => $m->body,
                'createdAt' => $m->created_at->toIso8601String(),
                'readAt' => optional($m->read_at)->toIso8601String(),
                'document' => $m->document ? [
                    'id' => $m->document->id,
                    'title' => $m->document->title,
                ] : null,
            ])->values(),
        ];
    }
}
