<?php

namespace Tests\Feature;

use App\Models\AppPassword;
use App\Services\AppPasswordService;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AppPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_app_password_lookup_hash_revoke_expiry_and_last_used_interval(): void
    {
        config(['nexusdav.app_password_last_used_interval' => 60]);

        $user = app(UserService::class)->create([
            'username' => 'ada',
            'display_name' => 'Ada',
            'email' => 'ada@example.com',
        ]);
        $service = app(AppPasswordService::class);
        $issued = $service->issue($user, 'Pixel 7');
        $token = $issued['token'];
        $appPassword = $issued['app_password'];
        [$publicId, $secret] = explode(AppPasswordService::SEPARATOR, $token, 2);

        $this->assertNotSame('', $publicId);
        $this->assertLessThanOrEqual(32, strlen($publicId));
        $this->assertGreaterThanOrEqual(43, strlen($secret));
        $this->assertSame($publicId.AppPasswordService::SEPARATOR.$secret, $token);

        $found = $service->findByPublicId($publicId);

        $this->assertNotNull($found);
        $this->assertTrue($found->is($appPassword));
        $this->assertSame($service->hashSecret($secret), $found->token_hash);
        $this->assertSame(64, strlen($found->token_hash));
        $this->assertDoesNotMatchRegularExpression('/^\$argon2id\$/', $found->token_hash);
        $this->assertNull($found->expires_at);
        $this->assertNotNull($service->verify($token));
        $this->assertNull($service->verify($publicId.AppPasswordService::SEPARATOR.'not-the-secret'));

        $stored = (array) DB::table('app_passwords')->where('id', $appPassword->getKey())->first();
        $encoded = json_encode($stored);

        $this->assertIsString($encoded);
        $this->assertStringNotContainsString($secret, $encoded);
        $this->assertStringNotContainsString($token, $encoded);

        $service->revoke($appPassword);

        $revoked = $appPassword->fresh();

        $this->assertInstanceOf(AppPassword::class, $revoked);
        $this->assertNotNull($revoked->revoked_at);
        $this->assertNull($service->verify($token));
        $this->assertNotNull($service->findByPublicId($publicId));
        $this->assertSame(1, AppPassword::query()->count());

        $expired = $service->issue($user, 'Old phone', now()->subMinute());

        $this->assertNotNull($expired['app_password']->expires_at);
        $this->assertNull($service->verify($expired['token']));
        $this->assertNotNull($service->findByPublicId(explode(AppPasswordService::SEPARATOR, $expired['token'], 2)[0]));

        $future = $service->issue($user, 'New phone', now()->addDay());

        $this->assertNotNull($service->verify($future['token']));

        $fresh = $future['app_password']->fresh();

        $this->assertNotNull($fresh);
        $this->assertNull($fresh->last_used_at);

        $service->markUsed($fresh);
        $usedAt = $fresh->fresh()?->last_used_at;

        $this->assertNotNull($usedAt);

        $withinInterval = now()->subSeconds(5);
        $fresh->forceFill(['last_used_at' => $withinInterval])->save();
        $service->markUsed($fresh->fresh() ?? $fresh);

        $this->assertSame(
            $withinInterval->toDateTimeString(),
            $fresh->fresh()?->last_used_at?->toDateTimeString(),
        );

        $this->travel(61)->seconds();
        $service->markUsed($fresh->fresh() ?? $fresh);

        $this->assertTrue($fresh->fresh()?->last_used_at?->greaterThan($withinInterval));
    }
}
