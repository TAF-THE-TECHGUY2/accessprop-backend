<?php

namespace App\Jobs;

use App\Models\SigningEnvelope;
use App\Services\DocuSign\Client as DocuSignClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DownloadCompletedDocumentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(public int $signingEnvelopeId) {}

    public function handle(DocuSignClient $client): void
    {
        $envelope = SigningEnvelope::find($this->signingEnvelopeId);

        if (! $envelope) {
            Log::warning('DownloadCompletedDocumentJob: envelope not found', [
                'signing_envelope_id' => $this->signingEnvelopeId,
            ]);

            return;
        }

        if ($envelope->status !== SigningEnvelope::STATUS_COMPLETED) {
            Log::info('DownloadCompletedDocumentJob: envelope not completed, skipping', [
                'envelope_id' => $envelope->docusign_envelope_id,
                'status' => $envelope->status,
            ]);

            return;
        }

        if ($envelope->signed_document_path) {
            return;
        }

        $tempFile = $client->envelopes()->getDocument(
            $client->accountId(),
            'combined',
            $envelope->docusign_envelope_id
        );

        $contents = file_get_contents($tempFile->getPathname());

        if ($contents === false || strlen($contents) === 0) {
            throw new \RuntimeException(
                "Empty signed document received from DocuSign for envelope {$envelope->docusign_envelope_id}"
            );
        }

        $disk = (string) config('docusign.storage.disk', 's3');
        $prefix = trim((string) config('docusign.storage.path_prefix', 'signed-agreements'), '/');
        $path = sprintf(
            '%s/%s/%s.pdf',
            $prefix,
            $envelope->investor?->code ?? 'unknown-investor',
            $envelope->docusign_envelope_id
        );

        Storage::disk($disk)->put($path, $contents, [
            'ContentType' => 'application/pdf',
            'visibility' => 'private',
        ]);

        $envelope->update([
            'signed_document_disk' => $disk,
            'signed_document_path' => $path,
        ]);

        Log::info('Signed agreement archived', [
            'envelope_id' => $envelope->docusign_envelope_id,
            'disk' => $disk,
            'path' => $path,
            'size_bytes' => strlen($contents),
        ]);
    }
}
