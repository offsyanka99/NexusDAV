<?php

namespace Tests\Feature;

use App\Models\AppPassword;
use App\Services\AppPasswordService;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class AppPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_app_password_lookup_hash_revoke_expiry_and_last_used_interval(): void
    {
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
        $this->assertSame($publicId, $service->tokenPublicId($token));
        $this->assertNull($service->tokenPublicId('correct-horse-battery'));

        $failure = 'unset';
        $this->assertNotNull($service->verify($token, $failure));
        $this->assertNull($failure);
        $failure = 'unset';
        $this->assertNull($service->verify($publicId.AppPasswordService::SEPARATOR.'not-the-secret', $failure));
        $this->assertSame(AppPasswordService::FAILURE_MISMATCH, $failure);
        $failure = 'unset';
        $this->assertNull($service->verify('correct-horse-battery', $failure));
        $this->assertSame(AppPasswordService::FAILURE_SHAPE, $failure);
        $failure = 'unset';
        $this->assertNull($service->verify('missing-public'.AppPasswordService::SEPARATOR.'secret', $failure));
        $this->assertSame(AppPasswordService::FAILURE_MISSING, $failure);

        $stored = (array) DB::table('app_passwords')->where('id', $appPassword->getKey())->first();
        $encoded = json_encode($stored);

        $this->assertIsString($encoded);
        $this->assertStringNotContainsString($secret, $encoded);
        $this->assertStringNotContainsString($token, $encoded);

        $service->revoke($appPassword);

        $revoked = $appPassword->fresh();

        $this->assertInstanceOf(AppPassword::class, $revoked);
        $this->assertNotNull($revoked->revoked_at);
        $failure = 'unset';
        $this->assertNull($service->verify($token, $failure));
        $this->assertSame(AppPasswordService::FAILURE_REVOKED, $failure);
        $this->assertNotNull($service->findByPublicId($publicId));
        $this->assertSame(1, AppPassword::query()->count());

        $expired = $service->issue($user, 'Old phone', now()->subMinute());

        $this->assertNotNull($expired['app_password']->expires_at);
        $failure = 'unset';
        $this->assertNull($service->verify($expired['token'], $failure));
        $this->assertSame(AppPasswordService::FAILURE_EXPIRED, $failure);
        $this->assertNotNull($service->findByPublicId(explode(AppPasswordService::SEPARATOR, $expired['token'], 2)[0]));

        $revokedAndExpired = $service->issue($user, 'Old revoked phone', now()->subMinute());
        $service->revoke($revokedAndExpired['app_password']);
        $failure = 'unset';
        $this->assertNull($service->verify($revokedAndExpired['token'], $failure));
        $this->assertSame(AppPasswordService::FAILURE_REVOKED, $failure);

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

    public function test_app_password_name_is_measured_in_characters(): void
    {
        $user = app(UserService::class)->create([
            'username' => 'ada',
            'display_name' => 'Ada',
            'email' => 'ada@example.com',
        ]);
        $service = app(AppPasswordService::class);
        $name = str_repeat('名', 100);

        $issued = $service->issue($user, $name);

        $this->assertSame($name, $issued['app_password']->name);

        try {
            $service->issue($user, '   ');
            $this->fail('A blank app password name was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('App password name must be between 1 and 255 characters.', $exception->getMessage());
        }

        try {
            $service->issue($user, str_repeat('名', 256));
            $this->fail('An app password name longer than 255 characters was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('App password name must be between 1 and 255 characters.', $exception->getMessage());
        }
    }
}
