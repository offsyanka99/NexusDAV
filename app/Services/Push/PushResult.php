<?php

namespace App\Services\Push;

/**
 * What became of one push message (docs/design/webdav-push.md, "Retry and
 * removal"): sent; gone (404 or 410, the subscription expired at the push
 * service); retry (429, 5xx, a timeout or a connection error); failed
 * (any other answer, not retried); or refused by the SSRF guard.
 */
final class PushResult
{
    public const SENT = 'sent';

    public const GONE = 'gone';

    public const RETRY = 'retry';

    public const FAILED = 'failed';

    public const REFUSED = 'refused';

    private function __construct(
        public readonly string $kind,
        public readonly ?int $status,
        public readonly string $reason,
    ) {}

    public static function fromStatus(int $status, string $reason = ''): self
    {
        $kind = match (true) {
            $status >= 200 && $status < 300 => self::SENT,
            $status === 404 || $status === 410 => self::GONE,
            $status === 429 || $status >= 500 => self::RETRY,
            default => self::FAILED,
        };

        return new self($kind, $status, self::clean($reason));
    }

    public static function retry(string $reason): self
    {
        return new self(self::RETRY, null, self::clean($reason));
    }

    public static function failed(string $reason): self
    {
        return new self(self::FAILED, null, self::clean($reason));
    }

    public static function refused(string $reason): self
    {
        return new self(self::REFUSED, null, self::clean($reason));
    }

    /**
     * For `last_failure` and the log: the status and a short reason, never
     * the URL.
     */
    public function summary(): string
    {
        $text = trim(($this->status === null ? '' : $this->status.' ').$this->reason);

        return mb_substr($text === '' ? 'No answer' : $text, 0, 255);
    }

    /**
     * The reason phrase comes from the push service: printable text only.
     */
    private static function clean(string $reason): string
    {
        return mb_substr(trim((string) preg_replace('/[^\x20-\x7E]+/', ' ', $reason)), 0, 120);
    }
}
