<?php

namespace App\Services\Push;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Minishlink\WebPush\VAPID;
use RuntimeException;

/**
 * The server's VAPID key pair and the push topic key, made on first use and
 * kept in `settings`, encrypted with APP_KEY. A value that no longer
 * decrypts (a new APP_KEY) is made again: clients register again within a
 * day, as DAVx5 renews daily.
 */
final class PushKeys
{
    private const VAPID = 'push.vapid';

    private const TOPIC = 'push.topic_key';

    /**
     * @var array{publicKey: string, privateKey: string}|null
     */
    private ?array $vapid = null;

    private ?string $topicKey = null;

    /**
     * Uncompressed P-256 point, base64url without padding.
     */
    public function vapidPublicKey(): string
    {
        return $this->vapid()['publicKey'];
    }

    public function vapidPrivateKey(): string
    {
        return $this->vapid()['privateKey'];
    }

    public function topicKey(): string
    {
        if ($this->topicKey !== null) {
            return $this->topicKey;
        }

        $value = $this->load(self::TOPIC, static fn (): array => ['key' => base64_encode(random_bytes(32))]);
        $key = base64_decode((string) ($value['key'] ?? ''), true);

        if (! is_string($key) || strlen($key) !== 32) {
            throw new RuntimeException('The push topic key is not valid.');
        }

        return $this->topicKey = $key;
    }

    /**
     * @return array{publicKey: string, privateKey: string}
     */
    private function vapid(): array
    {
        if ($this->vapid !== null) {
            return $this->vapid;
        }

        $value = $this->load(self::VAPID, static fn (): array => VAPID::createVapidKeys());

        if (! is_string($value['publicKey'] ?? null) || ! is_string($value['privateKey'] ?? null)) {
            throw new RuntimeException('The VAPID key pair is not valid.');
        }

        return $this->vapid = ['publicKey' => $value['publicKey'], 'privateKey' => $value['privateKey']];
    }

    /**
     * Two first requests at once both make a value; the first insert wins
     * and both read it back.
     *
     * @param  callable(): array<string, mixed>  $make
     * @return array<string, mixed>
     */
    private function load(string $key, callable $make): array
    {
        $value = $this->read($key);

        if ($value !== null) {
            return $value;
        }

        $now = now();
        DB::table('settings')->insertOrIgnore([
            'key' => $key,
            'value' => json_encode(Crypt::encryptString(json_encode($make(), JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $value = $this->read($key);

        if ($value === null) {
            throw new RuntimeException('The push key '.$key.' could not be stored.');
        }

        return $value;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read(string $key): ?array
    {
        $stored = DB::table('settings')->where('key', $key)->value('value');

        if (! is_string($stored)) {
            return null;
        }

        $cipher = json_decode($stored, true);

        try {
            if (! is_string($cipher)) {
                throw new DecryptException('The stored value is not a string.');
            }

            $value = json_decode(Crypt::decryptString($cipher), true, 4, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            PushConfig::log('warning', 'A stored push key could not be read and is made again', ['key' => $key]);
            DB::table('settings')->where('key', $key)->where('value', $stored)->delete();

            return null;
        }

        return is_array($value) ? $value : null;
    }
}
