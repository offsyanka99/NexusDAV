<?php

namespace App\Services\Holidays;

use Collator;
use InvalidArgumentException;
use Locale;
use Yasumi\Yasumi;

/**
 * The countries and regions a holiday calendar can follow, from Yasumi's
 * providers (ISO 3166 codes such as `CA` and `CA-ON`). Yasumi neither names
 * them nor says which language a country uses, so this class does: country
 * names come from PHP intl in the admin's language, region names are panel
 * text, and each country has the language its holidays are named in by
 * default.
 */
final class HolidayCatalog
{
    /**
     * Yasumi providers that are not public holidays of a place.
     */
    private const EXCLUDED = ['US-NYSE'];

    /**
     * Yasumi locales. A region can name its holidays in another language
     * than its country (Quebec, the French and Italian Swiss cantons).
     */
    private const LANGUAGES = [
        'AD' => 'ca_AD', 'AR' => 'es_AR', 'AT' => 'de_AT', 'AU' => 'en_AU', 'BA' => 'bs_Latn_BA',
        'BE' => 'nl_BE', 'BG' => 'bg_BG', 'BR' => 'pt_BR', 'CA' => 'en_CA', 'CH' => 'de_CH',
        'CO' => 'es_CO', 'CZ' => 'cs_CZ', 'DE' => 'de_DE', 'DK' => 'da_DK', 'EE' => 'et_EE',
        'ES' => 'es_ES', 'FI' => 'fi_FI', 'FR' => 'fr_FR', 'GB' => 'en_GB', 'GE' => 'ka_GE',
        'GR' => 'el_GR', 'HR' => 'hr_HR', 'HU' => 'hu_HU', 'IE' => 'en_IE', 'IR' => 'fa_IR',
        'IT' => 'it_IT', 'JP' => 'ja_JP', 'KE' => 'en_KE', 'KR' => 'ko_KR', 'LT' => 'lt_LT',
        'LU' => 'fr_LU', 'LV' => 'lv_LV', 'MX' => 'es_MX', 'NL' => 'nl_NL', 'NO' => 'nb_NO',
        'NZ' => 'en_NZ', 'PL' => 'pl_PL', 'PT' => 'pt_PT', 'RO' => 'ro_RO', 'RU' => 'ru_RU',
        'SE' => 'sv_SE', 'SI' => 'sl_SI', 'SK' => 'sk_SK', 'SM' => 'it_SM', 'TR' => 'tr_TR',
        'UA' => 'uk_UA', 'US' => 'en_US', 'VE' => 'es_VE', 'ZA' => 'en_ZA',
        'CA-QC' => 'fr_CA', 'CH-FR' => 'fr_CH', 'CH-GE' => 'fr_CH', 'CH-JU' => 'fr_CH',
        'CH-NE' => 'fr_CH', 'CH-VD' => 'fr_CH', 'CH-VS' => 'fr_CH', 'CH-TI' => 'it_CH',
    ];

    /**
     * @var array<string, string>|null
     */
    private ?array $providers = null;

    /**
     * Country codes and their names in the given language, sorted by name.
     *
     * @return array<string, string>
     */
    public function countries(?string $displayLocale = null): array
    {
        $displayLocale ??= app()->getLocale();
        $countries = [];

        foreach (array_keys($this->providers()) as $code) {
            if (! str_contains($code, '-')) {
                $countries[$code] = $this->countryName($code, $displayLocale);
            }
        }

        return $this->sorted($countries, $displayLocale);
    }

    /**
     * The regions of a country, sorted by name. Empty for a country without.
     *
     * @return array<string, string>
     */
    public function regions(string $country): array
    {
        $names = $this->regionNames();
        $regions = [];

        foreach (array_keys($this->providers()) as $code) {
            if (str_starts_with($code, $country.'-')) {
                $regions[$code] = $names[$code] ?? $code;
            }
        }

        return $this->sorted($regions, app()->getLocale());
    }

    public function exists(string $region): bool
    {
        return isset($this->providers()[$region]);
    }

    /**
     * The Yasumi provider, for example `Canada/Ontario`.
     */
    public function provider(string $region): string
    {
        return $this->providers()[$region] ?? throw new InvalidArgumentException('That country or region has no holiday data.');
    }

    public function country(string $region): string
    {
        return explode('-', $region, 2)[0];
    }

    /**
     * The language the holidays of the region are named in by default.
     */
    public function defaultLocale(string $region): string
    {
        return self::LANGUAGES[$region] ?? self::LANGUAGES[$this->country($region)] ?? 'en_US';
    }

    public function isLocale(string $locale): bool
    {
        return in_array($locale, Yasumi::getAvailableLocales(), true);
    }

    /**
     * "Canada" or "Canada (Ontario)", in the admin's language.
     */
    public function label(string $region, ?string $displayLocale = null): string
    {
        $displayLocale ??= app()->getLocale();
        $country = $this->countryName($this->country($region), $displayLocale);

        if (! str_contains($region, '-')) {
            return $country;
        }

        return $country.' ('.($this->regionNames()[$region] ?? $region).')';
    }

    public function countryName(string $code, string $displayLocale): string
    {
        $name = Locale::getDisplayRegion('und_'.$code, $displayLocale);

        return $name === '' || $name === $code ? $code : $name;
    }

    /**
     * Every region code that has a name, for the test that keeps this list
     * complete.
     *
     * @return list<string>
     */
    public function namedRegions(): array
    {
        return array_keys($this->regionNames());
    }

    /**
     * Every country and region code.
     *
     * @return list<string>
     */
    public function codes(): array
    {
        return array_keys($this->providers());
    }

    /**
     * @return array<string, string>
     */
    private function providers(): array
    {
        if ($this->providers === null) {
            $providers = array_diff_key(Yasumi::getProviders(), array_flip(self::EXCLUDED));
            ksort($providers);
            $this->providers = $providers;
        }

        return $this->providers;
    }

    /**
     * @param  array<string, string>  $names
     * @return array<string, string>
     */
    private function sorted(array $names, string $locale): array
    {
        $collator = new Collator($locale);
        uasort($names, static fn (string $a, string $b): int => (int) $collator->compare($a, $b));

        return $names;
    }

    /**
     * @return array<string, string>
     */
    private function regionNames(): array
    {
        return [
            'AT-1' => __('Burgenland'),
            'AT-2' => __('Carinthia'),
            'AT-3' => __('Lower Austria'),
            'AT-4' => __('Upper Austria'),
            'AT-5' => __('Salzburg'),
            'AT-6' => __('Styria'),
            'AT-7' => __('Tyrol'),
            'AT-8' => __('Vorarlberg'),
            'AT-9' => __('Vienna'),
            'AU-ACT' => __('Australian Capital Territory'),
            'AU-NSW' => __('New South Wales'),
            'AU-NT' => __('Northern Territory'),
            'AU-QLD' => __('Queensland'),
            'AU-QLD-BRI' => __('Queensland (Brisbane)'),
            'AU-SA' => __('South Australia'),
            'AU-TAS' => __('Tasmania'),
            'AU-TAS-CN' => __('Tasmania (Central North)'),
            'AU-TAS-FI' => __('Tasmania (Flinders Island)'),
            'AU-TAS-KI' => __('Tasmania (King Island)'),
            'AU-TAS-NE' => __('Tasmania (North-East)'),
            'AU-TAS-NW' => __('Tasmania (North-West)'),
            'AU-TAS-NW-CH' => __('Tasmania (Circular Head)'),
            'AU-TAS-SOU' => __('Tasmania (South)'),
            'AU-TAS-SOU-SE' => __('Tasmania (South-East)'),
            'AU-VIC' => __('Victoria'),
            'AU-WA' => __('Western Australia'),
            'CA-AB' => __('Alberta'),
            'CA-BC' => __('British Columbia'),
            'CA-MB' => __('Manitoba'),
            'CA-NB' => __('New Brunswick'),
            'CA-NL' => __('Newfoundland and Labrador'),
            'CA-NS' => __('Nova Scotia'),
            'CA-NT' => __('Northwest Territories'),
            'CA-NU' => __('Nunavut'),
            'CA-ON' => __('Ontario'),
            'CA-PE' => __('Prince Edward Island'),
            'CA-QC' => __('Quebec'),
            'CA-SK' => __('Saskatchewan'),
            'CA-YT' => __('Yukon'),
            'CH-AG' => __('Aargau'),
            'CH-AI' => __('Appenzell Innerrhoden'),
            'CH-AR' => __('Appenzell Ausserrhoden'),
            'CH-BE' => __('Bern'),
            'CH-BL' => __('Basel-Landschaft'),
            'CH-BS' => __('Basel-Stadt'),
            'CH-FR' => __('Fribourg'),
            'CH-GE' => __('Geneva'),
            'CH-GL' => __('Glarus'),
            'CH-GR' => __('Grisons'),
            'CH-JU' => __('Jura'),
            'CH-LU' => __('Lucerne'),
            'CH-NE' => __('Neuchâtel'),
            'CH-NW' => __('Nidwalden'),
            'CH-OW' => __('Obwalden'),
            'CH-SG' => __('St. Gallen'),
            'CH-SH' => __('Schaffhausen'),
            'CH-SO' => __('Solothurn'),
            'CH-SZ' => __('Schwyz'),
            'CH-TG' => __('Thurgau'),
            'CH-TI' => __('Ticino'),
            'CH-UR' => __('Uri'),
            'CH-VD' => __('Vaud'),
            'CH-VS' => __('Valais'),
            'CH-ZG' => __('Zug'),
            'CH-ZH' => __('Zurich'),
            'DE-BB' => __('Brandenburg'),
            'DE-BE' => __('Berlin'),
            'DE-BW' => __('Baden-Württemberg'),
            'DE-BY' => __('Bavaria'),
            'DE-HB' => __('Bremen'),
            'DE-HE' => __('Hesse'),
            'DE-HH' => __('Hamburg'),
            'DE-MV' => __('Mecklenburg-Western Pomerania'),
            'DE-NI' => __('Lower Saxony'),
            'DE-NW' => __('North Rhine-Westphalia'),
            'DE-RP' => __('Rhineland-Palatinate'),
            'DE-SH' => __('Schleswig-Holstein'),
            'DE-SL' => __('Saarland'),
            'DE-SN' => __('Saxony'),
            'DE-ST' => __('Saxony-Anhalt'),
            'DE-TH' => __('Thuringia'),
            'ES-AN' => __('Andalusia'),
            'ES-AR' => __('Aragon'),
            'ES-AS' => __('Asturias'),
            'ES-CB' => __('Cantabria'),
            'ES-CE' => __('Ceuta'),
            'ES-CL' => __('Castile and León'),
            'ES-CM' => __('Castilla–La Mancha'),
            'ES-CN' => __('Canary Islands'),
            'ES-CT' => __('Catalonia'),
            'ES-EX' => __('Extremadura'),
            'ES-GA' => __('Galicia'),
            'ES-IB' => __('Balearic Islands'),
            'ES-MC' => __('Region of Murcia'),
            'ES-MD' => __('Community of Madrid'),
            'ES-ML' => __('Melilla'),
            'ES-NC' => __('Navarre'),
            'ES-PV' => __('Basque Country'),
            'ES-RI' => __('La Rioja'),
            'ES-VC' => __('Valencian Community'),
            'FR-57' => __('Moselle'),
            'FR-67' => __('Bas-Rhin'),
            'FR-68' => __('Haut-Rhin'),
            'GB-ENG' => __('England'),
            'GB-NIR' => __('Northern Ireland'),
            'GB-SCT' => __('Scotland'),
            'GB-WLS' => __('Wales'),
        ];
    }
}
