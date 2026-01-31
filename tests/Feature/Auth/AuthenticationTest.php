<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered()
    {
        $this->markTestSkipped('UI rendering is not used in this app.');
    }

    public function test_users_can_authenticate_using_the_login_screen()
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password()
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }

    public function test_login_is_rate_limited_after_too_many_attempts()
    {
        Event::fake([Lockout::class]);

        $user = User::factory()->create();
        $payload = [
            'email' => $user->email,
            'password' => 'wrong-password',
        ];
        $server = ['REMOTE_ADDR' => '10.0.0.1'];

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->from('/login')->withServerVariables($server)->post('/login', $payload);
        }

        $response = $this->from('/login')->withServerVariables($server)->post('/login', $payload);

        $response->assertSessionHasErrors('email');
        Event::assertDispatched(Lockout::class);

        $throttleKey = Str::transliterate(Str::lower($user->email).'|10.0.0.1');
        RateLimiter::clear($throttleKey);
    }
}
