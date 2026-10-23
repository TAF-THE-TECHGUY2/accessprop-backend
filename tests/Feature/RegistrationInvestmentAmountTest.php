<?php

namespace Tests\Feature;

use App\Models\Fund;
use App\Models\Investor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The amount an investor names at signup. The form used to send the $10,000
 * minimum for everyone, and that figure becomes the agreement amount and the
 * default first payment.
 */
class RegistrationInvestmentAmountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Fund::create(['code' => 'aref-i', 'name' => 'Access Real Estate Fund I', 'status' => 'active']);
    }

    private function register(mixed $amount, array $extra = [])
    {
        return $this->postJson('/api/investors/register', $extra + [
            'firstName' => 'Ada',
            'lastName' => 'Lovelace',
            'email' => 'ada@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'addressLine1' => '1 Beacon St',
            'city' => 'Boston',
            'stateProvince' => 'MA',
            'zipPostalCode' => '02108',
            'country' => 'United States',
            'experience' => 'experienced',
            'accreditationStatus' => 'accredited',
            'investmentAmount' => $amount,
        ]);
    }

    public function test_the_named_amount_becomes_the_commitment_and_shows_to_admins(): void
    {
        $this->register(250000)->assertSuccessful();

        $investor = Investor::where('email', 'ada@example.com')->firstOrFail();
        $this->assertEquals(250000, (float) $investor->investment_amount);
        $this->assertEquals(250000, (float) $investor->investment_commitment);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/admin/investors/'.$investor->code)
            ->assertOk()
            ->assertJsonPath('data.statedAmount', 250000)
            ->assertJsonPath('data.investmentInfo.commitment', 250000);
    }

    public function test_below_the_minimum_is_refused(): void
    {
        $this->register(5000)->assertStatus(422)->assertJsonValidationErrors('investmentAmount');
    }

    public function test_an_implausibly_large_figure_is_refused(): void
    {
        $this->register(5_000_000_000)->assertStatus(422)->assertJsonValidationErrors('investmentAmount');
    }

    public function test_a_fund_that_is_not_open_cannot_be_named(): void
    {
        Fund::create(['code' => 'aref-ii', 'name' => 'Access Real Estate Fund II', 'status' => 'draft']);

        $this->register(25000, ['fundCode' => 'aref-ii'])
            ->assertStatus(422)
            ->assertJsonPath('errors.fundCode.0', 'That fund is not open for investment.');

        $this->register(25000, ['fundCode' => 'no-such-fund'])
            ->assertJsonPath('errors.fundCode.0', 'That fund is not open for investment.');

        $this->assertDatabaseMissing('investors', ['email' => 'ada@example.com']);
    }
}
