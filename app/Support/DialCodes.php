<?php

namespace App\Support;

/**
 * Country dial codes for the signup phone field. Curated broad list (flag +
 * name + dial code), enough to cover the vast majority of users. Used by the
 * phone-input partial and validated in registration.
 */
class DialCodes
{
    /**
     * @return list<array{iso:string, name:string, dial:string, flag:string}>
     */
    public static function all(): array
    {
        $rows = [
            ['US', 'United States', '+1'], ['GB', 'United Kingdom', '+44'], ['CA', 'Canada', '+1'],
            ['AU', 'Australia', '+61'], ['IN', 'India', '+91'], ['PK', 'Pakistan', '+92'],
            ['AE', 'United Arab Emirates', '+971'], ['SA', 'Saudi Arabia', '+966'], ['DE', 'Germany', '+49'],
            ['FR', 'France', '+33'], ['ES', 'Spain', '+34'], ['IT', 'Italy', '+39'], ['NL', 'Netherlands', '+31'],
            ['BE', 'Belgium', '+32'], ['CH', 'Switzerland', '+41'], ['AT', 'Austria', '+43'], ['SE', 'Sweden', '+46'],
            ['NO', 'Norway', '+47'], ['DK', 'Denmark', '+45'], ['FI', 'Finland', '+358'], ['IE', 'Ireland', '+353'],
            ['PT', 'Portugal', '+351'], ['PL', 'Poland', '+48'], ['CZ', 'Czechia', '+420'], ['GR', 'Greece', '+30'],
            ['RO', 'Romania', '+40'], ['HU', 'Hungary', '+36'], ['UA', 'Ukraine', '+380'], ['RU', 'Russia', '+7'],
            ['TR', 'Turkey', '+90'], ['IL', 'Israel', '+972'], ['EG', 'Egypt', '+20'], ['ZA', 'South Africa', '+27'],
            ['NG', 'Nigeria', '+234'], ['KE', 'Kenya', '+254'], ['GH', 'Ghana', '+233'], ['MA', 'Morocco', '+212'],
            ['DZ', 'Algeria', '+213'], ['TN', 'Tunisia', '+216'], ['QA', 'Qatar', '+974'], ['KW', 'Kuwait', '+965'],
            ['BH', 'Bahrain', '+973'], ['OM', 'Oman', '+968'], ['JO', 'Jordan', '+962'], ['LB', 'Lebanon', '+961'],
            ['IQ', 'Iraq', '+964'], ['CN', 'China', '+86'], ['JP', 'Japan', '+81'], ['KR', 'South Korea', '+82'],
            ['HK', 'Hong Kong', '+852'], ['TW', 'Taiwan', '+886'], ['SG', 'Singapore', '+65'], ['MY', 'Malaysia', '+60'],
            ['ID', 'Indonesia', '+62'], ['TH', 'Thailand', '+66'], ['VN', 'Vietnam', '+84'], ['PH', 'Philippines', '+63'],
            ['BD', 'Bangladesh', '+880'], ['LK', 'Sri Lanka', '+94'], ['NP', 'Nepal', '+977'], ['NZ', 'New Zealand', '+64'],
            ['BR', 'Brazil', '+55'], ['MX', 'Mexico', '+52'], ['AR', 'Argentina', '+54'], ['CL', 'Chile', '+56'],
            ['CO', 'Colombia', '+57'], ['PE', 'Peru', '+51'], ['VE', 'Venezuela', '+58'], ['EC', 'Ecuador', '+593'],
            ['UY', 'Uruguay', '+598'], ['BO', 'Bolivia', '+591'], ['PY', 'Paraguay', '+595'], ['CR', 'Costa Rica', '+506'],
            ['PA', 'Panama', '+507'], ['DO', 'Dominican Republic', '+1'], ['GT', 'Guatemala', '+502'],
        ];

        return array_map(fn ($r) => [
            'iso' => $r[0],
            'name' => $r[1],
            'dial' => $r[2],
            'flag' => self::flag($r[0]),
        ], $rows);
    }

    /**
     * The set of valid dial codes, for validation.
     *
     * @return list<string>
     */
    public static function validCodes(): array
    {
        return array_values(array_unique(array_map(fn ($r) => $r['dial'], self::all())));
    }

    /** Regional-indicator flag emoji from an ISO-3166 alpha-2 code. */
    private static function flag(string $iso): string
    {
        $iso = strtoupper($iso);
        if (strlen($iso) !== 2) {
            return '';
        }

        return mb_convert_encoding('&#'.(127397 + ord($iso[0])).';', 'UTF-8', 'HTML-ENTITIES')
            .mb_convert_encoding('&#'.(127397 + ord($iso[1])).';', 'UTF-8', 'HTML-ENTITIES');
    }
}
