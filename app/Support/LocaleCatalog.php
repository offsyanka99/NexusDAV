<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * The admin panel languages: every lang/<code>.json with a "_language_name".
 * It is read once per request. SettingsService::apply() runs it at every boot,
 * including DAV requests, so a broken file is skipped and logged rather than
 * taking the server down; LocalizationTest fails on it in CI.
 */
class LocaleCatalog
{
    public const FALLBACK = 'en';

    /**
     * @var array<string, string>|null
     */
    private ?array $languages = null;

    public function __construct(private ?string $directory = null) {}

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        if ($this->languages !== null) {
            return $this->languages;
        }

        $languages = [];

        foreach (File::glob(($this->directory ?? lang_path()).'/*.json') as $path) {
            $translations = json_decode((string) File::get($path), true);
            $name = is_array($translations) ? ($translations['_language_name'] ?? null) : null;

            if (! is_string($name) || $name === '') {
                Log::warning('Language file skipped: it is not valid JSON or has no _language_name', ['file' => basename($path)]);

                continue;
            }

            $languages[pathinfo($path, PATHINFO_FILENAME)] = $name;
        }

        asort($languages);

        return $this->languages = $languages;
    }

    public function supports(mixed $locale): bool
    {
        return is_string($locale) && $locale !== '' && array_key_exists($locale, $this->all());
    }

    public function default(): string
    {
        $configured = config('nexusdav.default_locale', self::FALLBACK);

        return $this->supports($configured) ? $configured : self::FALLBACK;
    }
}
