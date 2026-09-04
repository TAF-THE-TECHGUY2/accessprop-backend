<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MessageThread extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'resolved_at' => 'datetime',
        'last_message_at' => 'datetime',
    ];

    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ThreadMessage::class, 'thread_id')->orderBy('created_at');
    }

    /**
     * Who the thread is waiting on, derived from whoever wrote last.
     *
     * Not stored: a status column has to be updated by every write path, and
     * the one that forgets leaves a thread claiming to be answered when it is
     * not. The messages are the record; this reads them.
     */
    public function state(): string
    {
        if ($this->resolved_at !== null) {
            return 'resolved';
        }

        // reorder() before latest(): messages() already applies an ascending
        // orderBy, and latest() only appends a second term — the same trap that
        // made Fund::currentUnitPrice() return the oldest price.
        $last = $this->relationLoaded('messages')
            ? $this->messages->last()
            : $this->messages()->reorder()->latest('created_at')->first();

        if ($last === null) {
            return 'awaiting_team';
        }

        return $last->author_type === 'investor' ? 'awaiting_team' : 'awaiting_investor';
    }

    /** Messages the given side has not read yet. */
    public function unreadFor(string $side): int
    {
        $from = $side === 'investor' ? 'admin' : 'investor';

        return $this->messages()
            ->where('author_type', $from)
            ->whereNull('read_at')
            ->count();
    }
}
