<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;

/**
 * Shared custom profile page for every panel (admin, operator,
 * portal). Drops the password + password-confirmation + current
 * password fields from Filament's default profile — password
 * change lives on the dedicated Security page (/{panel}/security)
 * alongside two-factor and session management.
 *
 * The profile page keeps its purpose simple: "update your name
 * and email." Everything security-related is one place.
 */
class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
                Select::make('timezone')
                    ->label('Timezone')
                    ->options(self::timezoneOptions())
                    ->searchable()
                    ->placeholder('Use system default ('.config('app.timezone').')')
                    ->helperText('All dates and times across the UI will render in this zone. Leave empty to fall back to the platform default.'),
                Select::make('locale')
                    ->label('Language')
                    ->options(self::localeOptions())
                    ->searchable()
                    ->placeholder('Use system default ('.config('app.locale').')')
                    ->helperText('Preferred UI language. Leave empty to fall back to the platform default.'),
            ]);
    }

    /**
     * Timezone list for the select. Common zones in the US, Canada,
     * and Europe get human-friendly labels with their abbreviation
     * so users who don't know PHP zone identifiers can recognize
     * "Eastern Time (ET)" faster than "America/New_York". Every
     * other PHP zone still gets appended at the bottom under a
     * separator so users in less common zones aren't locked out.
     *
     * @return array<string, string>
     */
    private static function timezoneOptions(): array
    {
        // Friendly labels first. Ordered by common US → Canada →
        // Europe → Asia/Pacific grouping, rather than alphabetical,
        // so a US user scanning the list sees their zone right at
        // the top.
        $friendly = [
            // United States
            'America/New_York' => 'Eastern Time — ET (New York)',
            'America/Chicago' => 'Central Time — CT (Chicago)',
            'America/Denver' => 'Mountain Time — MT (Denver)',
            'America/Phoenix' => 'Mountain Time, no DST — MST (Arizona)',
            'America/Los_Angeles' => 'Pacific Time — PT (Los Angeles)',
            'America/Anchorage' => 'Alaska Time — AKT (Anchorage)',
            'Pacific/Honolulu' => 'Hawaii Time — HAT (Honolulu)',

            // Canada (zones not already covered by the US list)
            'America/Halifax' => 'Atlantic Time — AT (Halifax)',
            'America/St_Johns' => 'Newfoundland Time — NT (St. John\'s)',
            'America/Toronto' => 'Eastern Time — ET (Toronto)',
            'America/Winnipeg' => 'Central Time — CT (Winnipeg)',
            'America/Edmonton' => 'Mountain Time — MT (Edmonton)',
            'America/Vancouver' => 'Pacific Time — PT (Vancouver)',

            // Europe
            'Europe/London' => 'British Time — GMT / BST (London)',
            'Europe/Dublin' => 'Irish Time — GMT / IST (Dublin)',
            'Europe/Lisbon' => 'Western European — WET / WEST (Lisbon)',
            'Europe/Paris' => 'Central European — CET / CEST (Paris)',
            'Europe/Berlin' => 'Central European — CET / CEST (Berlin)',
            'Europe/Madrid' => 'Central European — CET / CEST (Madrid)',
            'Europe/Rome' => 'Central European — CET / CEST (Rome)',
            'Europe/Amsterdam' => 'Central European — CET / CEST (Amsterdam)',
            'Europe/Brussels' => 'Central European — CET / CEST (Brussels)',
            'Europe/Zurich' => 'Central European — CET / CEST (Zurich)',
            'Europe/Vienna' => 'Central European — CET / CEST (Vienna)',
            'Europe/Stockholm' => 'Central European — CET / CEST (Stockholm)',
            'Europe/Copenhagen' => 'Central European — CET / CEST (Copenhagen)',
            'Europe/Oslo' => 'Central European — CET / CEST (Oslo)',
            'Europe/Warsaw' => 'Central European — CET / CEST (Warsaw)',
            'Europe/Helsinki' => 'Eastern European — EET / EEST (Helsinki)',
            'Europe/Athens' => 'Eastern European — EET / EEST (Athens)',
            'Europe/Bucharest' => 'Eastern European — EET / EEST (Bucharest)',
            'Europe/Istanbul' => 'Turkey Time — TRT (Istanbul)',

            // UTC itself
            'UTC' => 'Coordinated Universal Time — UTC',
        ];

        // Every remaining PHP-known zone under a "Other" marker so
        // users in Australia, Asia, South America, etc. still have
        // access to their zone without a huge hand-maintained list.
        $all = \DateTimeZone::listIdentifiers();
        $rest = [];
        foreach ($all as $tz) {
            if (! array_key_exists($tz, $friendly)) {
                $rest[$tz] = $tz;
            }
        }

        return $friendly + $rest;
    }

    /**
     * Supported UI locales. Hand-curated because adding a language
     * also means adding translation files — this list grows when
     * a translation set lands, not when a user asks. See TODO.md
     * for the full i18n pass that actually wires these up; today
     * they're stored on the user record but every UI label is
     * still hard-coded English.
     *
     * @return array<string, string>
     */
    private static function localeOptions(): array
    {
        return [
            'en' => 'English',
            'es' => 'Español (Spanish)',
            'fr' => 'Français (French)',
            'fr_CA' => 'Français canadien (Québec French)',
            'de' => 'Deutsch (German)',
            'nl' => 'Nederlands (Dutch)',
            'pt' => 'Português (Portuguese)',
            'it' => 'Italiano (Italian)',
            'da' => 'Dansk (Danish)',
            'sv' => 'Svenska (Swedish)',
            'no' => 'Norsk (Norwegian)',
            'fi' => 'Suomi (Finnish)',
        ];
    }

    /**
     * Surface a "Manage security" header action that jumps to the
     * shared Security page for the current panel (2FA / password /
     * sessions). Uses the panel id so the same class mounted on
     * admin / operator / portal routes each to its own /{panel}/security.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('security')
                ->label('Manage security')
                ->icon('heroicon-o-shield-check')
                ->color('gray')
                ->url(fn () => route('filament.'.Filament::getCurrentPanel()->getId().'.pages.security')),
        ];
    }
}
