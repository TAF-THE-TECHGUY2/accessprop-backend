<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\SigningEnvelope;
use App\Models\SigningLink;
use App\Services\DocuSign\AgreementNotifier;
use App\Services\DocuSign\EnvelopeBuilder;
use App\Services\DocuSign\SigningNotYetOpen;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Opens a signer's DocuSign session from the link in the app's email, with no
 * sign-in: the link's long random token is the credential. It works until that
 * signer has signed or the agreement closes, and only in their turn.
 */
class SigningLinkController extends Controller
{
    public function __construct(private EnvelopeBuilder $builder) {}

    public function open(string $token): JsonResponse
    {
        $link = SigningLink::findByToken($token);

        if (! $link || ! $link->envelope) {
            return response()->json(['status' => 'invalid', 'message' => 'This signing link is not valid. Please use the most recent email you received.'], 404);
        }

        $envelope = $link->envelope;

        if ($envelope->status === SigningEnvelope::STATUS_COMPLETED || $link->signed_at !== null) {
            return response()->json(['status' => 'signed', 'message' => 'These documents have already been signed. Thank you.']);
        }

        if (in_array($envelope->status, [SigningEnvelope::STATUS_VOIDED, SigningEnvelope::STATUS_DECLINED], true)) {
            return response()->json(['status' => 'closed', 'message' => 'These documents are no longer open for signing. If you expected to sign, please contact us.'], 410);
        }

        try {
            return response()->json(['status' => 'ready', 'url' => $this->builder->signingUrl($envelope, $link->role, $link->returnUrl())]);
        } catch (SigningNotYetOpen $e) {
            return response()->json(['status' => 'waiting', 'message' => $e->getMessage().' We will email you as soon as it is your turn.'], 409);
        } catch (Throwable $e) {
            Log::warning('Signing link could not be opened', ['envelope' => $envelope->docusign_envelope_id, 'error' => $e->getMessage()]);

            return response()->json(['status' => 'error', 'message' => 'Signing is not available right now. Please try again in a few minutes.'], 502);
        }
    }

    /**
     * A signer is back from DocuSign: check it now, so the status updates and
     * the next signer is emailed without waiting for a webhook or the
     * scheduled follow-up.
     */
    public function returned(string $token, AgreementNotifier $notifier): JsonResponse
    {
        $link = SigningLink::findByToken($token);

        if (! $link || ! $link->envelope) {
            return response()->json(['status' => 'invalid'], 404);
        }

        try {
            // Once a minute per agreement at most, however often the page loads.
            if (Cache::add('agreement-returned:'.$link->envelope->id, true, 60)) {
                $notifier->followUp($link->envelope);
            }
        } catch (Throwable $e) {
            Log::warning('Follow-up on signer return failed', ['envelope' => $link->envelope->docusign_envelope_id, 'error' => $e->getMessage()]);
        }

        return response()->json([
            'status' => $link->envelope->fresh()->status,
            'role' => $link->role,
            'signed' => $link->fresh()->signed_at !== null,
        ]);
    }
}
