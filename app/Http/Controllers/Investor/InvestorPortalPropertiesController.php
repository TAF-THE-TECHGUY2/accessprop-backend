<?php

namespace App\Http\Controllers\Investor;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The fund's real estate, read from the Node/Mongo API behind the marketing
 * site.
 *
 * This app is not the source of record for properties and keeps no copy of
 * them: there is no fund_properties table, and nothing here writes.
 *
 * It takes two upstream calls to answer this, because neither alone is enough:
 *
 *   /api/pages/slug/{slug}  a PROPERTY_COLUMNS section listing, in display
 *                           order, each strategy label ("Buy & Hold"), the
 *                           slug of the property filed under it, and a cover
 *                           image chosen for this page.
 *   /api/properties         every property the firm owns, with the attributes.
 *                           It carries no fund field, so it cannot be filtered
 *                           on its own — the page section is what ties a
 *                           property to a fund.
 *
 * The two are joined on slug. A property the page references but the list does
 * not contain is dropped rather than rendered half-empty.
 *
 * Note the name: `fund_holdings` in this codebase is an investor's *unit
 * position*, a different thing entirely. Everything here says "properties".
 */
class InvestorPortalPropertiesController extends Controller
{
    public function index(Request $request, string $fundCode): JsonResponse
    {
        // Matches the guard on the sibling holding endpoints: the fund code
        // comes straight off the URL, and an investor has no business reading
        // the composition of a fund they do not hold — it would also let them
        // enumerate which funds exist.
        $request->user()->holdings()
            ->whereHas('fund', fn ($q) => $q->where('code', $fundCode))
            ->firstOrFail();

        if (! config('services.properties.base_url')) {
            // Not configured. An empty list with a reason beats a 500: the
            // panel renders its explanation and the page stays usable.
            return response()->json([
                'data' => [],
                'unavailable' => 'not_configured',
            ]);
        }

        $properties = Cache::remember(
            "fund-properties:{$fundCode}",
            config('services.properties.cache_ttl'),
            fn () => $this->fetch($fundCode)
        );

        if ($properties === null) {
            // Don't hold a failure for the full TTL — one blip upstream should
            // not blank the panel for five minutes after it recovers.
            Cache::forget("fund-properties:{$fundCode}");

            return response()->json([
                'data' => [],
                'unavailable' => 'source_unreachable',
            ]);
        }

        return response()->json(['data' => $properties]);
    }

    /**
     * Fetch both documents and join them. Returns null on any failure so the
     * caller can degrade instead of throwing — a property feed being down is
     * not a reason to take the whole fund page with it.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function fetch(string $fundCode): ?array
    {
        $page = $this->get(str_replace(
            '{slug}',
            rawurlencode($this->pageSlug($fundCode)),
            (string) config('services.properties.page_endpoint')
        ));

        if (! is_array($page)) {
            return null;
        }

        $columns = $this->propertyColumns($page);

        // The page exists but files no properties under this fund. That is a
        // real, legitimate answer — an empty list, not a failure.
        if ($columns === []) {
            return [];
        }

        $list = $this->get((string) config('services.properties.list_endpoint'));

        if (! is_array($list)) {
            return null;
        }

        // Tolerate both a bare array and a {data: [...]} envelope.
        $rows = $list['data'] ?? $list;

        if (! is_array($rows)) {
            return null;
        }

        $bySlug = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['slug'])) {
                $bySlug[$row['slug']] = $row;
            }
        }

        $properties = [];
        foreach ($columns as $column) {
            $row = $bySlug[$column['slug']] ?? null;

            // Referenced but missing upstream. Skipping beats rendering a card
            // that is a strategy label and six em dashes.
            if ($row === null) {
                Log::info('Property referenced by a fund page is absent from the feed', [
                    'fund' => $fundCode,
                    'slug' => $column['slug'],
                ]);

                continue;
            }

            $properties[] = $this->normalise($row, $column);
        }

        return $properties;
    }

    /**
     * The strategy columns, in the order the page lists them.
     *
     * `items` is the current shape; older documents carry the same information
     * as a `mapping` object keyed by label, which loses the ordering, so
     * `columns` supplies it.
     *
     * @param  array<string, mixed>  $page
     * @return array<int, array{label: string, slug: string, image: string|null}>
     */
    private function propertyColumns(array $page): array
    {
        $section = null;
        foreach ($page['sections'] ?? [] as $candidate) {
            if (($candidate['type'] ?? null) === 'PROPERTY_COLUMNS') {
                $section = $candidate['data'] ?? [];
                break;
            }
        }

        if (! is_array($section)) {
            return [];
        }

        $columns = [];

        foreach ($section['items'] ?? [] as $item) {
            $label = $item['label'] ?? null;

            foreach ($item['properties'] ?? [] as $property) {
                if (! isset($property['slug'])) {
                    continue;
                }

                $columns[] = [
                    'label' => $label,
                    'slug' => $property['slug'],
                    'image' => $property['image'] ?? null,
                ];
            }
        }

        if ($columns !== []) {
            return $columns;
        }

        foreach ($section['columns'] ?? [] as $label) {
            $entry = $section['mapping'][$label] ?? null;

            if (is_array($entry) && isset($entry['slug'])) {
                $columns[] = [
                    'label' => $label,
                    'slug' => $entry['slug'],
                    'image' => $entry['image'] ?? null,
                ];
            }
        }

        return $columns;
    }

    /**
     * Map an upstream property, plus its column on the fund page, onto the
     * shape the portal renders.
     *
     * @param  array<string, mixed>  $row
     * @param  array{label: string|null, slug: string, image: string|null}  $column
     * @return array<string, mixed>
     */
    private function normalise(array $row, array $column): array
    {
        return [
            'id' => $row['_id'] ?? $row['slug'] ?? null,
            'address' => $this->address($row),
            // The strategy is a property of the *fund page*, not the property:
            // the same building could be filed differently on another fund.
            'strategy' => $column['label'],
            // `status` upstream is the publish flag ("active"), not the
            // tenancy. `holdingStatus` is the one that means "Leased".
            'status' => $row['holdingStatus'] ?? null,
            // Already a display string ("8/2023"), deliberately not a date:
            // upstream records the month, and parsing it to a date would
            // invent a day.
            'acquiredAt' => $row['acquiredLabel'] ?? null,
            // heroImage first, the fund page's column override second.
            //
            // The override looks like the more specific choice, but it is a
            // copy of a path taken when the page was last edited, and it goes
            // stale when the property's images are re-uploaded: the live page
            // currently points Chestnut Hill at an image that 404s, while the
            // property's own heroImage resolves. heroImage is maintained with
            // the record, so it is the one that can be trusted to exist.
            'photoUrl' => $this->absolute($row['heroImage'] ?? $column['image'] ?? null),
            'type' => $row['type'] ?? null,
            'bedrooms' => $this->numeric($row['beds'] ?? null),
            'bathrooms' => $this->numeric($row['baths'] ?? null),
            'parking' => $this->numeric($row['parking'] ?? null),
            'squareFeet' => $this->numeric($row['sqft'] ?? null),
            'lotSize' => $this->numeric($row['lotSqft'] ?? null),
        ];
    }

    /**
     * Compose the one-line address the design prints.
     *
     * Built from the parts rather than taken from `title`, which is
     * inconsistent upstream — one record's title omits the city entirely,
     * another's reads "Boston (Brighton)". The city field carries stray
     * padding, so every part is trimmed.
     *
     * @param  array<string, mixed>  $row
     */
    private function address(array $row): ?string
    {
        $street = trim((string) ($row['address'] ?? ''));
        $city = trim((string) ($row['city'] ?? ''));
        $state = trim((string) ($row['state'] ?? ''));
        $zip = trim((string) ($row['zip'] ?? ''));

        $locality = trim(implode(' ', array_filter([$city, $state, $zip])));
        $line = trim(implode(', ', array_filter([$street, $locality])));

        // Fall back to the title rather than printing nothing at all.
        return $line !== '' ? $line : (trim((string) ($row['title'] ?? '')) ?: null);
    }

    /**
     * Upstream image paths are relative ("/uploads/x.jpg"), so they need the
     * API's own host to resolve from the portal.
     */
    private function absolute(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return rtrim((string) config('services.properties.base_url'), '/')
            .'/'.ltrim($path, '/');
    }

    /**
     * Fetch and decode one upstream document, or null on any failure.
     */
    private function get(string $endpoint): mixed
    {
        $url = rtrim((string) config('services.properties.base_url'), '/').$endpoint;

        try {
            $request = Http::timeout((int) config('services.properties.timeout'))
                ->acceptJson();

            if ($key = config('services.properties.key')) {
                $request = $request->withToken($key);
            }

            $response = $request->get($url);

            if ($response->failed()) {
                Log::warning('Property feed returned an error', [
                    'endpoint' => $endpoint,
                    'status' => $response->status(),
                ]);

                return null;
            }

            return $response->json();
        } catch (Throwable $e) {
            Log::warning('Property feed unreachable', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The marketing page slug for a fund code. Codes are hyphenated ("aref-i"),
     * page slugs underscored ("aref_i"); the config map overrides anything that
     * does not follow that rule.
     */
    private function pageSlug(string $fundCode): string
    {
        foreach (explode(',', (string) config('services.properties.page_slugs')) as $pair) {
            [$code, $slug] = array_pad(explode(':', trim($pair), 2), 2, null);

            if ($slug !== null && strcasecmp(trim((string) $code), $fundCode) === 0) {
                return trim($slug);
            }
        }

        return str_replace('-', '_', strtolower($fundCode));
    }

    /**
     * Upstream sends numbers inconsistently — `parking` is a string ("3") while
     * `beds` is an int. Cast so the portal can format them, and keep a genuine
     * zero: a condo with no parking is a fact worth printing, not a blank.
     */
    private function numeric(mixed $value): int|float|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return $value;
        }

        $clean = str_replace([',', ' '], '', (string) $value);

        if (! is_numeric($clean)) {
            return null;
        }

        return str_contains($clean, '.') ? (float) $clean : (int) $clean;
    }
}
