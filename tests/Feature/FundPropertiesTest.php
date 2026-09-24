<?php

namespace Tests\Feature;

use App\Models\Fund;
use App\Models\FundHolding;
use App\Models\Investor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The fund's real estate, proxied from the Node/Mongo API behind the marketing
 * site.
 *
 * The fixtures below are trimmed copies of real responses, so the field names
 * and their quirks are the genuine article: `parking` arrives as a string while
 * `beds` is an int, `status` means "published" and `holdingStatus` means
 * "Leased", the city carries stray padding, and images are relative paths.
 *
 * What is worth testing here is the join — two upstream documents, neither
 * sufficient alone — and the degradation, since a third-party feed that is
 * slow, down or half-populated must not take the fund page with it.
 */
class FundPropertiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.properties.base_url', 'https://properties.test');
        config()->set('services.properties.page_endpoint', '/api/pages/slug/{slug}');
        config()->set('services.properties.list_endpoint', '/api/properties');
        config()->set('services.properties.page_slugs', '');
        config()->set('services.properties.key', null);
        Cache::flush();
    }

    public function test_it_joins_the_fund_page_to_the_property_feed(): void
    {
        $this->fakeUpstream();
        $this->actingAsHolder();

        $response = $this->getJson('/api/investor/portal/holdings/aref-i/properties')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // Order comes from the page, not the feed: the feed lists Ledge Street
        // first, the page files Chestnut Hill under the first column.
        $response->assertJsonPath('data.0.strategy', 'Buy & Hold');
        $response->assertJsonPath('data.0.address', '374 Chestnut Hill Avenue #3, Boston MA 02135');
        $response->assertJsonPath('data.1.strategy', 'New Construction');
        $response->assertJsonPath('data.1.address', '9 Ledge Street, Melrose MA 02176');
    }

    public function test_it_reads_the_tenancy_status_not_the_publish_flag(): void
    {
        $this->fakeUpstream();
        $this->actingAsHolder();

        // Upstream `status` is "active", meaning published. Printing that on the
        // card would tell an investor a building's tenancy is "active", which
        // is not a tenancy at all.
        $this->getJson('/api/investor/portal/holdings/aref-i/properties')
            ->assertJsonPath('data.0.status', 'Leased');
    }

    public function test_it_prefers_the_properties_own_hero_image_and_makes_it_absolute(): void
    {
        $this->fakeUpstream();
        $this->actingAsHolder();

        // The fund page's column image is a path copied when the page was last
        // edited and goes stale when images are re-uploaded — in production it
        // currently 404s for this very property. heroImage is maintained with
        // the record. Relative paths cannot resolve from the portal's origin.
        $this->getJson('/api/investor/portal/holdings/aref-i/properties')
            ->assertJsonPath('data.0.photoUrl', 'https://properties.test/uploads/hero-chestnut.png');
    }

    public function test_it_falls_back_to_the_pages_column_image(): void
    {
        $this->fakeUpstream(properties: [$this->chestnutHill(heroImage: null)]);
        $this->actingAsHolder();

        $this->getJson('/api/investor/portal/holdings/aref-i/properties')
            ->assertJsonPath('data.0.photoUrl', 'https://properties.test/uploads/column-cover.png');
    }

    public function test_an_absolute_upstream_image_is_left_alone(): void
    {
        $this->fakeUpstream(properties: [
            $this->chestnutHill(heroImage: 'https://cdn.example.com/a.jpg'),
        ]);
        $this->actingAsHolder();

        $this->getJson('/api/investor/portal/holdings/aref-i/properties')
            ->assertJsonPath('data.0.photoUrl', 'https://cdn.example.com/a.jpg');
    }

    public function test_it_composes_the_address_from_parts_and_trims_upstream_padding(): void
    {
        $this->fakeUpstream();
        $this->actingAsHolder();

        // Upstream titles are inconsistent — one omits the city, another reads
        // "Boston (Brighton)" — and `city` arrives padded with spaces.
        $this->getJson('/api/investor/portal/holdings/aref-i/properties')
            ->assertJsonPath('data.1.address', '9 Ledge Street, Melrose MA 02176');
    }

    public function test_it_normalises_the_attributes(): void
    {
        $this->fakeUpstream();
        $this->actingAsHolder();

        $response = $this->getJson('/api/investor/portal/holdings/aref-i/properties');

        $response->assertJsonPath('data.1.type', 'Single Family');
        $response->assertJsonPath('data.1.bedrooms', 3);
        $response->assertJsonPath('data.1.bathrooms', 2.5);
        // `parking` is a string upstream; left as one it defeats the portal's
        // number formatting.
        $response->assertJsonPath('data.1.parking', 3);
        $response->assertJsonPath('data.1.squareFeet', 1528);
        $response->assertJsonPath('data.1.lotSize', 4053);
        $response->assertJsonPath('data.1.acquiredAt', '1/2025');

        // Zero is an answer, not a blank: the portal prints null as "—", which
        // would read as "not recorded" when the truth is "none".
        $response->assertJsonPath('data.0.parking', 0);
        $response->assertJsonPath('data.0.lotSize', 0);
    }

    public function test_it_skips_a_property_the_page_references_but_the_feed_lacks(): void
    {
        $this->fakeUpstream(properties: [$this->chestnutHill()]);
        $this->actingAsHolder();

        // Better an absent card than a strategy label over six em dashes.
        $this->getJson('/api/investor/portal/holdings/aref-i/properties')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.strategy', 'Buy & Hold');
    }

    public function test_a_page_with_no_property_columns_is_an_empty_list_not_a_failure(): void
    {
        Http::fake([
            'properties.test/api/pages/*' => Http::response([
                'slug' => 'aref_i',
                'sections' => [['type' => 'HERO', 'data' => []]],
            ]),
        ]);
        $this->actingAsHolder();

        $this->getJson('/api/investor/portal/holdings/aref-i/properties')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('unavailable', null);

        // No columns means nothing to look up — the feed should not be called.
        Http::assertSentCount(1);
    }

    public function test_it_reads_the_legacy_mapping_shape(): void
    {
        Http::fake([
            'properties.test/api/pages/*' => Http::response([
                'sections' => [[
                    'type' => 'PROPERTY_COLUMNS',
                    'data' => [
                        // Older documents carry no `items`; `columns` supplies
                        // the ordering that `mapping` alone would lose.
                        'columns' => ['Buy & Hold'],
                        'mapping' => [
                            'Buy & Hold' => ['slug' => 'chestnut-hill', 'image' => '/uploads/column-cover.png'],
                        ],
                    ],
                ]],
            ]),
            'properties.test/api/properties' => Http::response([$this->chestnutHill()]),
        ]);
        $this->actingAsHolder();

        $this->getJson('/api/investor/portal/holdings/aref-i/properties')
            ->assertOk()
            ->assertJsonPath('data.0.strategy', 'Buy & Hold');
    }

    public function test_an_investor_cannot_read_properties_of_a_fund_they_do_not_hold(): void
    {
        Http::fake();
        $this->actingAsHolder();

        Fund::create(['code' => 'other-i', 'name' => 'Someone Elses Fund', 'status' => 'active']);

        $this->getJson('/api/investor/portal/holdings/other-i/properties')
            ->assertNotFound();

        // The guard must stop us before the outbound call, or the composition
        // of another fund leaks through our own API's traffic.
        Http::assertNothingSent();
    }

    public function test_it_requires_authentication(): void
    {
        Http::fake();

        $this->getJson('/api/investor/portal/holdings/aref-i/properties')
            ->assertUnauthorized();
    }

    public function test_an_unreachable_source_degrades_instead_of_failing_the_page(): void
    {
        Http::fake([
            'properties.test/*' => Http::response('gateway timeout', 504),
        ]);
        $this->actingAsHolder();

        $this->getJson('/api/investor/portal/holdings/aref-i/properties')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('unavailable', 'source_unreachable');
    }

    public function test_a_feed_failure_after_a_good_page_still_degrades(): void
    {
        Http::fake([
            'properties.test/api/pages/*' => Http::response($this->page()),
            'properties.test/api/properties' => Http::response('boom', 500),
        ]);
        $this->actingAsHolder();

        // Half an answer is worse than none: the strategies are known but no
        // attributes are, and a column of empty cards misleads.
        $this->getJson('/api/investor/portal/holdings/aref-i/properties')
            ->assertOk()
            ->assertJsonPath('unavailable', 'source_unreachable');
    }

    public function test_a_failed_fetch_is_not_cached(): void
    {
        Http::fake([
            'properties.test/api/pages/*' => Http::sequence()
                ->push('boom', 500)
                ->push($this->page(), 200),
            'properties.test/api/properties' => Http::response([$this->chestnutHill()]),
        ]);
        $this->actingAsHolder();

        $this->getJson('/api/investor/portal/holdings/aref-i/properties')
            ->assertJsonPath('unavailable', 'source_unreachable');

        // Caching the failure would blank the panel for the full TTL, long
        // after the source had recovered.
        $this->getJson('/api/investor/portal/holdings/aref-i/properties')
            ->assertOk()
            ->assertJsonPath('data.0.strategy', 'Buy & Hold');
    }

    public function test_a_successful_fetch_is_cached(): void
    {
        $this->fakeUpstream();
        $this->actingAsHolder();

        $this->getJson('/api/investor/portal/holdings/aref-i/properties')->assertOk();
        $this->getJson('/api/investor/portal/holdings/aref-i/properties')->assertOk();

        // Two calls for the first request, none for the second. The fund page
        // must not hit a third party on every render.
        Http::assertSentCount(2);
    }

    public function test_it_reports_when_the_feed_is_not_configured(): void
    {
        config()->set('services.properties.base_url', null);
        Http::fake();
        $this->actingAsHolder();

        $this->getJson('/api/investor/portal/holdings/aref-i/properties')
            ->assertOk()
            ->assertJsonPath('unavailable', 'not_configured');

        Http::assertNothingSent();
    }

    public function test_it_underscores_the_fund_code_to_find_the_page(): void
    {
        $this->fakeUpstream();
        $this->actingAsHolder();

        $this->getJson('/api/investor/portal/holdings/aref-i/properties')->assertOk();

        // Fund codes are hyphenated, marketing page slugs underscored.
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/pages/slug/aref_i'));
    }

    public function test_a_configured_slug_overrides_the_default_transform(): void
    {
        config()->set('services.properties.page_slugs', 'aref-i:custom_page');
        $this->fakeUpstream();
        $this->actingAsHolder();

        $this->getJson('/api/investor/portal/holdings/aref-i/properties')->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/pages/slug/custom_page'));
    }

    /**
     * Fake both upstream documents. Defaults mirror the real fund page: two
     * strategy columns pointing at two of the properties in the feed.
     */
    private function fakeUpstream(
        ?array $properties = null,
        ?string $columnImage = '/uploads/column-cover.png',
    ): void {
        Http::fake([
            'properties.test/api/pages/*' => Http::response($this->page($columnImage)),
            'properties.test/api/properties' => Http::response(
                $properties ?? [$this->ledgeStreet(), $this->chestnutHill()]
            ),
        ]);
    }

    private function page(?string $columnImage = '/uploads/column-cover.png'): array
    {
        return [
            'slug' => 'aref_i',
            'sections' => [
                ['type' => 'HERO', 'data' => []],
                [
                    'type' => 'PROPERTY_COLUMNS',
                    'data' => [
                        'columns' => ['Buy & Hold', 'New Construction'],
                        'items' => [
                            [
                                'label' => 'Buy & Hold',
                                'properties' => [
                                    ['slug' => 'chestnut-hill', 'image' => $columnImage],
                                ],
                            ],
                            [
                                'label' => 'New Construction',
                                'properties' => [
                                    ['slug' => 'ledge-street', 'image' => $columnImage],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function chestnutHill(?string $heroImage = '/uploads/hero-chestnut.png'): array
    {
        return [
            '_id' => '696838b7c1acbd33b3a4aab6',
            'slug' => 'chestnut-hill',
            'title' => '374 Chestnut Hill Avenue #3, Boston (Brighton) MA 02135',
            'address' => '374 Chestnut Hill Avenue #3',
            'city' => 'Boston',
            'state' => 'MA',
            'zip' => '02135',
            'type' => 'Condo',
            'beds' => 1,
            'baths' => 1,
            'parking' => '0',
            'sqft' => 568,
            'lotSqft' => 0,
            'status' => 'active',
            'holdingStatus' => 'Leased',
            'acquiredLabel' => '8/2023',
            'heroImage' => $heroImage,
        ];
    }

    private function ledgeStreet(): array
    {
        return [
            '_id' => '696838b7c1acbd33b3a4aab8',
            'slug' => 'ledge-street',
            // Real record: the title omits the city entirely.
            'title' => '9 Ledge Street',
            'address' => '9 Ledge Street',
            // Real record: padded.
            'city' => 'Melrose                       ',
            'state' => 'MA',
            'zip' => '02176',
            'type' => 'Single Family',
            'beds' => 3,
            'baths' => 2.5,
            'parking' => '3',
            'sqft' => 1528,
            'lotSqft' => 4053,
            'status' => 'active',
            'holdingStatus' => 'Leased',
            'acquiredLabel' => '1/2025',
            'heroImage' => '/uploads/hero-ledge.jpg',
        ];
    }

    private function actingAsHolder(): Investor
    {
        $fund = Fund::create([
            'code' => 'aref-i',
            'name' => 'Access Real Estate Fund I',
            'status' => 'active',
        ]);

        $investor = Investor::create([
            'code' => 'inv-9001',
            'name' => 'Properties Investor',
            'email' => 'properties@example.com',
            'password' => 'secret-password',
            'country' => 'South Africa',
            'joined_at' => now(),
            'accreditation_status' => 'accredited',
            'kyc_status' => 'approved',
            'investment_status' => 'active',
            'dashboard_status' => 'active',
            'address_line1' => '1 Main Road',
            'address_city' => 'Johannesburg',
            'address_state' => 'Gauteng',
            'address_postal_code' => '2000',
            'address_country' => 'South Africa',
            'personal_investor_type' => 'Individual',
            'personal_residency' => 'South African Resident',
            'investment_fund_name' => 'Access Real Estate Fund I',
            'investment_wallet_status' => 'Active',
            'investment_expected_yield' => '8%',
        ]);

        FundHolding::create([
            'investor_id' => $investor->id,
            'fund_id' => $fund->id,
            'units' => 7547.169811,
            'amount_invested' => 100000,
            'average_unit_price' => 13.25,
            'first_invested_at' => now(),
        ]);

        Sanctum::actingAs($investor->refresh());

        return $investor;
    }
}
