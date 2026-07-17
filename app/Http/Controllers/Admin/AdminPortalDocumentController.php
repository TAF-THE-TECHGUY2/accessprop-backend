<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Fund;
use App\Models\PortalDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminPortalDocumentController extends Controller
{
    public function store(Request $request, string $code): JsonResponse
    {
        $fund = Fund::where('code', $code)->firstOrFail();

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'in:legal,operational,tax,financial'],
            'subcategory' => ['nullable', 'string', 'max:255'],
            'documentDatedAt' => ['nullable', 'date'],
            'file' => ['required', 'file', 'mimes:pdf', 'max:20480'],
        ]);

        $file = $data['file'];
        $filename = Str::uuid().'.pdf';
        $path = $file->storeAs("portal-documents/{$fund->code}", $filename, 'local');

        $document = PortalDocument::create([
            'scope' => 'fund',
            'fund_id' => $fund->id,
            'category' => $data['category'],
            'subcategory' => $data['subcategory'] ?? null,
            'title' => $data['title'],
            'file_url' => $path,
            'file_size_bytes' => $file->getSize(),
            'mime_type' => $file->getMimeType() ?: 'application/pdf',
            'uploaded_by' => $request->user()?->id,
            'document_dated_at' => $data['documentDatedAt'] ?? null,
        ]);

        return response()->json(['data' => $this->serialize($document)], 201);
    }

    public function download(string $code, int $id): RedirectResponse|StreamedResponse
    {
        $document = $this->findForFund($code, $id);

        if ($this->isExternalUrl($document->file_url)) {
            return redirect()->away($document->file_url);
        }

        abort_unless(Storage::disk('local')->exists($document->file_url), 404, 'Document file not found.');

        return Storage::disk('local')->download(
            $document->file_url,
            $this->downloadFilename($document),
            ['Content-Type' => $document->mime_type ?: 'application/pdf'],
        );
    }

    public function destroy(string $code, int $id): JsonResponse
    {
        $document = $this->findForFund($code, $id);

        if (! $this->isExternalUrl($document->file_url)) {
            Storage::disk('local')->delete($document->file_url);
        }

        $document->delete();

        return response()->json(['message' => 'Document deleted.']);
    }

    private function findForFund(string $code, int $id): PortalDocument
    {
        return PortalDocument::query()
            ->whereKey($id)
            ->where('scope', 'fund')
            ->whereHas('fund', fn ($query) => $query->where('code', $code))
            ->firstOrFail();
    }

    private function serialize(PortalDocument $document): array
    {
        return [
            'id' => $document->id,
            'title' => $document->title,
            'category' => $document->category,
            'subcategory' => $document->subcategory,
            'sizeBytes' => $document->file_size_bytes,
            'mimeType' => $document->mime_type,
            'documentDatedAt' => optional($document->document_dated_at)->toDateString(),
            'createdAt' => optional($document->created_at)->toIso8601String(),
        ];
    }

    private function isExternalUrl(string $value): bool
    {
        return Str::startsWith($value, ['http://', 'https://']);
    }

    private function downloadFilename(PortalDocument $document): string
    {
        $name = Str::slug(pathinfo($document->title, PATHINFO_FILENAME));

        return ($name ?: 'offering-document').'.pdf';
    }
}
