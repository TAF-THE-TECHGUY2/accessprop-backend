<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Sign-in tokens used to live forever. */
class TokenExpiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_token_stops_working_after_a_working_day(): void
    {
        $admin = User::factory()->create(['password' => Hash::make('pw')]);
        $admin->forceFill(['two_factor_confirmed_at' => null, 'two_factor_secret' => null])->save();

        $token = $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'pw'])->json('token');

        $this->withToken($token)->getJson('/api/admin/me')->assertOk();

        $this->travel(13)->hours();
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/admin/me')->assertUnauthorized();
    }
}
