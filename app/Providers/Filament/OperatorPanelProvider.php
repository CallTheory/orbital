<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Auth\EditProfile;
use App\Filament\AvatarProviders\LocalAvatarProvider;
use App\Filament\Pages\Security;
use App\Http\Middleware\PanelRedirect;
use App\Http\Middleware\SetPermissionsTeamContext;
use App\Models\LogoutReason;
use App\Models\UserLogoutEvent;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Operator workspace — softphone, intake flow viewer, call queue status.
 *
 * Same Filament shell as the admin panel (brand, logo, user menu dropdown,
 * theme) but with a different palette, its own nav, and a persistent
 * softphone component injected via render hook so it follows the
 * operator across every page in the panel.
 *
 * Access: any user with a team-less platform role (super_admin,
 * operator, supervisor, or anything else the platform operator has
 * defined). Tenant users are redirected away by PanelRedirect.
 */
class OperatorPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('operator')
            ->path('operator')
            ->spa()
            ->maxContentWidth(\Filament\Support\Enums\Width::Full)
            ->profile(page: EditProfile::class, isSimple: false)
            ->brandName(fn () => (string) config('orbital.platform_name', 'Orbital'))
            ->brandLogo(fn () => \App\Support\Branding::platformLogoLightUrl())
            ->darkModeBrandLogo(fn () => \App\Support\Branding::platformLogoDarkUrl())
            ->brandLogoHeight('2rem')
            ->favicon(fn () => \App\Support\Branding::platformFaviconUrl())
            ->colors([
                'primary' => Color::Emerald,
                'danger' => Color::Red,
                'warning' => Color::Amber,
                'success' => Color::Emerald,
            ])
            // Offline-first avatar provider — see AdminPanelProvider.
            ->defaultAvatarProvider(LocalAvatarProvider::class)
            // Database notifications + 5s polling — matches the
            // admin panel. An operator who's the target of a
            // drain notice (or any other Filament notification
            // we sendToDatabase) gets a toast within 5 seconds
            // and a badge on the bell icon.
            ->databaseNotifications()
            ->databaseNotificationsPolling('5s')
            ->navigationGroups([
                'Workspace',
                'Inbox',
                'Activity',
            ])
            ->discoverPages(in: app_path('Filament/Operator/Pages'), for: 'App\\Filament\\Operator\\Pages')
            ->pages([
                // Shared security page — 2FA, password, sessions.
                // Lives in App\Filament\Pages so all three panels
                // share one implementation.
                Security::class,
            ])
            ->userMenuItems([
                // Layout mirrors AdminPanelProvider — see that file
                // for the full section layout explanation. Security
                // sits in the top section with Profile; panel
                // switches go in the middle section after the theme
                // switcher; Sign out is alone in its own section at
                // the bottom, separated from the panel switches by
                // our custom user-menu view override.
                MenuItem::make()
                    ->label('Security')
                    ->url(fn () => route('filament.operator.pages.security'))
                    ->icon('heroicon-o-shield-check')
                    ->sort(-10),
                MenuItem::make()
                    ->label('Admin Panel')
                    ->url(fn () => url('/admin'))
                    ->icon('heroicon-o-cog-6-tooth')
                    ->sort(10)
                    ->visible(fn () => auth()->user()?->isSuperAdmin() ?? false),
                MenuItem::make()
                    ->label('Customer Portal')
                    ->url(fn () => url('/portal'))
                    ->icon('heroicon-o-globe-alt')
                    ->sort(12)
                    ->visible(fn () => auth()->user()?->belongsToAnyTenant() ?? false),
                // Replaces Filament's default Sign out link with a
                // modal-backed Action that asks the operator to pick
                // a LogoutReason before the session ends. Writes a
                // UserLogoutEvent audit row with the chosen reason +
                // a snapshot of its label (so later renames/deletes
                // on the LogoutReason don't rewrite history), then
                // logs the user out and redirects to the panel login.
                //
                // Operator panel only — the admin and portal panels
                // still use the stock logout link because those
                // sessions aren't the "on the floor" sessions this
                // audit log is trying to capture.
                //
                // Degrades gracefully when the logout_reasons table
                // is empty (admin deleted everything, or an upgrade
                // dropped seed data): the schema closure swaps the
                // required Select for a Placeholder explaining the
                // situation, so the operator can still submit and
                // sign out instead of being trapped by a required
                // field with no options. The UserLogoutEvent row
                // still lands with `reason_label_snapshot` set to
                // '(no reasons configured)' so the audit log records
                // WHY the reason is missing.
                'logout' => Action::make('logout')
                    ->label('Sign out')
                    ->icon('heroicon-m-arrow-right-on-rectangle')
                    ->modalHeading('Sign out')
                    ->modalDescription(fn (): ?string => LogoutReason::query()->where('is_active', true)->exists()
                        ? 'Pick a reason so the supervisor log knows why you\'re off the floor.'
                        : null)
                    ->modalSubmitActionLabel('Sign out')
                    ->modalIcon('heroicon-o-arrow-left-on-rectangle')
                    // Narrow modal — `sm` is tighter than the default
                    // `md` and fits the two short fields without the
                    // whole dialog taking up half the screen.
                    ->modalWidth('sm')
                    ->schema(function (): array {
                        $reasons = LogoutReason::query()
                            ->where('is_active', true)
                            ->orderBy('sort_order')
                            ->orderBy('label')
                            ->pluck('label', 'id')
                            ->all();

                        // No reasons configured: hide the select + its
                        // surrounding verbiage entirely and just show
                        // the note field. The action handler still
                        // writes a UserLogoutEvent with a sentinel
                        // label so the audit trail records why the
                        // reason is missing.
                        if (empty($reasons)) {
                            return [
                                Textarea::make('note')
                                    ->label('Note (optional)')
                                    ->rows(2)
                                    ->maxLength(500),
                            ];
                        }

                        return [
                            Select::make('logout_reason_id')
                                ->label('Reason')
                                ->options($reasons)
                                ->required()
                                ->native(false),
                            Textarea::make('note')
                                ->label('Note (optional)')
                                ->rows(2)
                                ->maxLength(500),
                        ];
                    })
                    ->action(function (array $data) {
                        $user = auth()->user();
                        if ($user) {
                            $reason = LogoutReason::find($data['logout_reason_id'] ?? null);
                            UserLogoutEvent::create([
                                'user_id' => $user->id,
                                'logout_reason_id' => $reason?->id,
                                // When no reasons were configured the
                                // select didn't render, so $reason is
                                // null and we stamp a sentinel label
                                // so the audit log distinguishes this
                                // case from "operator picked a reason
                                // that was later deleted".
                                'reason_label_snapshot' => $reason?->label ?? '(no reasons configured)',
                                'logged_out_at' => now(),
                            ]);
                        }

                        auth()->guard('web')->logout();
                        session()->invalidate();
                        session()->regenerateToken();

                        return redirect()->to(Filament::getPanel('operator')->getLoginUrl() ?? '/operator/login');
                    })
                    ->sort(PHP_INT_MAX),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                SetPermissionsTeamContext::class,
                // See AdminPanelProvider for why PanelRedirect lives in
                // general middleware rather than authMiddleware.
                PanelRedirect::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => view('filament.partials.panel-styles')->render(),
            )
            // Top-of-page status bar — same component the admin
            // panel uses. Operators see the platform-wide health
            // strip so they know the second a component like
            // LiveKit or Asterisk is in trouble. Gated on
            // `hasAnyPlatformRole()` so the login page (which
            // borrows admin panel context) doesn't get the bar.
            ->renderHook(
                PanelsRenderHook::BODY_START,
                fn (): string => auth()->user()?->hasAnyPlatformRole()
                    ? Blade::render('@livewire(\App\Livewire\SystemStatusBar::class)')
                    : '',
            )
            // Availability selector in the topbar — sits directly
            // left of the user menu avatar so it's out from under
            // the Filament toast notification stack (which renders
            // in the top-right corner and otherwise overlapped the
            // pill, blocking clicks until the toast dismissed).
            ->renderHook(
                PanelsRenderHook::USER_MENU_BEFORE,
                fn (): string => auth()->user()?->hasAnyPlatformRole()
                    ? Blade::render('@livewire(\App\Livewire\AvailabilitySelector::class)')
                    : '',
            )
            // Per-user drain listener: subscribes to the private
            // `operator.drain.{userId}` channel and pops a Filament
            // toast when the user's Asterisk node is draining. Lives
            // on the operator panel only — admins don't run softphones
            // so the nudge doesn't apply to them.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => auth()->check()
                    ? Blade::render('@livewire(\App\Livewire\AsteriskDrainNotice::class)')
                    : '',
            )
            // Persistent softphone on every operator page — same pattern
            // the old desktop.blade used, now injected via a render hook
            // so it's available on every panel page without duplicating
            // the layout.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => Blade::render('@livewire(\'softphone\')'),
            );
    }
}
