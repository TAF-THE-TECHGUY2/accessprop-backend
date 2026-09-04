<?php

namespace App\Http\Controllers\Investor;

use App\Http\Controllers\Controller;
use App\Mail\SecureMessageReceivedMail;
use App\Models\Investor;
use App\Models\MessageThread;
use App\Models\PortalDocument;
use App\Models\Setting;
use App\Models\ThreadMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Secure messaging, investor side.
 *
 * Investors can open a thread and reply on one. Everything is scoped to the
 * authenticated investor by the query itself rather than by checking an id
 * after loading — a thread belonging to someone else must be indistinguishable
 * from one that does not exist.
 */
class InvestorPortalMessageController extends Controller
{
    private const CATEGORIES = ['general', 'call_request', 'documents'];

    public function index(Request $request): JsonResponse
    {
        $threads = $request->user()->threads()->with('messages')->get();

        return response()->json([
            'data' => $threads->map(fn (MessageThread $t) => $this->summary($t)),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $thread = $this->findForInvestor($request, $id);

        // Opening a thread marks the team's messages read. Doing it here rather
        // than on a separate endpoint means the unread count cannot drift from
        // what the investor has actually been shown.
        $thread->messages()
            ->where('author_type', 'admin')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json($this->detail($thread->fresh(['messages.document'])));
    }

    public function store(Request $request): JsonResponse
    {
        $investor = $request->user();

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
            'category' => ['nullable', 'string', 'in:'.implode(',', self::CATEGORIES)],
            'portalDocumentId' => ['nullable', 'integer'],
        ]);

        $documentId = $this->resolveDocumentId($investor, $data['portalDocumentId'] ?? null);

        $thread = DB::transaction(function () use ($investor, $data, $documentId) {
            $thread = MessageThread::create([
                'investor_id' => $investor->id,
                'subject' => $data['subject'],
                'category' => $data['category'] ?? 'general',
                'opened_by' => 'investor',
                'last_message_at' => now(),
            ]);

            $this->appendMessage($thread, $investor, $data['body'], $documentId);

            return $thread;
        });

        $this->notifyTeam($thread->fresh(['investor', 'messages']));

        return response()->json($this->detail($thread->fresh(['messages.document'])), 201);
    }

    public function storeMessage(Request $request, int $id): JsonResponse
    {
        $investor = $request->user();
        $thread = $this->findForInvestor($request, $id);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'portalDocumentId' => ['nullable', 'integer'],
        ]);

        $documentId = $this->resolveDocumentId($investor, $data['portalDocumentId'] ?? null);

        DB::transaction(function () use ($thread, $investor, $data, $documentId) {
            $this->appendMessage($thread, $investor, $data['body'], $documentId);

            // A reply reopens a resolved thread. The team closes threads; an
            // investor with a follow-up must never be left with nowhere to put
            // it.
            $thread->update([
                'last_message_at' => now(),
                'resolved_at' => null,
                'resolved_by' => null,
            ]);
        });

        $this->notifyTeam($thread->fresh(['investor', 'messages']));

        return response()->json($this->detail($thread->fresh(['messages.document'])));
    }

    private function appendMessage(
        MessageThread $thread,
        Investor $investor,
        string $body,
        ?int $documentId,
    ): ThreadMessage {
        return ThreadMessage::create([
            'thread_id' => $thread->id,
            'author_type' => 'investor',
            'author_id' => $investor->id,
            // Kept rather than joined: this is correspondence about money, and
            // the author has to still render if the account is ever removed.
            'author_name' => $investor->name,
            'body' => $body,
            'portal_document_id' => $documentId,
        ]);
    }

    /**
     * Only a document this investor can already see may be referenced.
     *
     * Without this an investor could cite any id and have the subject line of
     * someone else's document read back to them from the thread.
     */
    private function resolveDocumentId(Investor $investor, ?int $documentId): ?int
    {
        if ($documentId === null) {
            return null;
        }

        $fundIds = app(InvestorPortalDocumentsController::class)
            ->accessibleFundIds($investor);

        $exists = PortalDocument::query()
            ->visibleTo($investor, $fundIds)
            ->whereKey($documentId)
            ->exists();

        return $exists ? $documentId : null;
    }

    private function notifyTeam(MessageThread $thread): void
    {
        $to = Setting::singleton()->support_email;

        if (! $to) {
            return;
        }

        $message = $thread->messages->last();

        // A failed notification must not fail the send: the message is already
        // committed and visible to the team in the console. Losing the email is
        // recoverable; losing what the investor wrote is not.
        try {
            Mail::to($to)->send(new SecureMessageReceivedMail($thread, $message));
        } catch (\Throwable $e) {
            Log::warning('Could not notify the team of a secure message.', [
                'thread_id' => $thread->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function findForInvestor(Request $request, int $id): MessageThread
    {
        return $request->user()->threads()->whereKey($id)->firstOrFail();
    }

    private function summary(MessageThread $thread): array
    {
        $last = $thread->messages->last();

        return [
            'id' => $thread->id,
            'subject' => $thread->subject,
            'category' => $thread->category,
            'state' => $thread->state(),
            'openedBy' => $thread->opened_by,
            'lastMessageAt' => optional($thread->last_message_at)->toIso8601String(),
            'createdAt' => $thread->created_at->toIso8601String(),
            'messageCount' => $thread->messages->count(),
            'unread' => $thread->unreadFor('investor'),
            'preview' => $last?->body,
            'lastAuthorType' => $last?->author_type,
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
