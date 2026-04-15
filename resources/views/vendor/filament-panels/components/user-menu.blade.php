{{--
    Orbital override of Filament's user-menu.blade.php.

    Stock Filament renders two content sections (before + after
    the theme switcher). Our user menu needs three:

        1. Profile + Security       (items with sort < 0)
        2. Theme switcher           (built-in)
        3. Panel switchers          (sort >= 0, NOT logout)
        4. ─── separator ───        (extra empty dropdown.list gives us the CSS divider)
        5. Sign out                 (logout sort=PHP_INT_MAX, in its own list)

    The only change from the stock view is splitting the
    `itemsAfterThemeSwitcher` section into two separate
    dropdown.list blocks — one for regular items (everything
    except `logout`) and one for logout alone. Filament's CSS
    draws a divider border between consecutive dropdown.list
    siblings, so the split gives us the visual separator above
    Sign out without any custom HTML.

    Keep this file in sync with vendor/filament/filament/resources
    /views/components/user-menu.blade.php on Filament upgrades —
    the split is the only meaningful difference.
--}}
@props([
    'position' => null,
])

@php
    use Filament\Actions\Action;
    use Filament\Enums\UserMenuPosition;
    use Illuminate\Support\Arr;

    $user = filament()->auth()->user();

    $items = $this->getUserMenuItems();

    $itemsBeforeAndAfterThemeSwitcher = collect($items)
        ->groupBy(fn (Action $item): bool => $item->getSort() < 0, preserveKeys: true)
        ->all();
    $itemsBeforeThemeSwitcher = $itemsBeforeAndAfterThemeSwitcher[true] ?? collect();
    $itemsAfterThemeSwitcher = $itemsBeforeAndAfterThemeSwitcher[false] ?? collect();

    $hasProfileHeader = $itemsBeforeThemeSwitcher->has('profile') &&
        blank(($item = Arr::first($itemsBeforeThemeSwitcher))->getUrl()) &&
        (! $item->hasAction());

    if ($itemsBeforeThemeSwitcher->has('profile')) {
        $itemsBeforeThemeSwitcher = $itemsBeforeThemeSwitcher->prepend($itemsBeforeThemeSwitcher->pull('profile'), 'profile');
    }

    // Split the after-theme-switcher items into two lists so
    // Sign out gets its own dropdown.list block with a divider
    // above it. `itemsMiddleSection` = panel switches and any
    // other non-logout items; `itemsLogoutSection` = just logout.
    $itemsLogoutSection = collect();
    if ($itemsAfterThemeSwitcher->has('logout')) {
        $itemsLogoutSection = collect(['logout' => $itemsAfterThemeSwitcher['logout']]);
        $itemsAfterThemeSwitcher = $itemsAfterThemeSwitcher->except('logout');
    }
    $itemsMiddleSection = $itemsAfterThemeSwitcher;

    $position ??= filament()->getUserMenuPosition();

    $isSidebarCollapsibleOnDesktop = filament()->isSidebarCollapsibleOnDesktop();
@endphp

{{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::USER_MENU_BEFORE) }}

<x-filament::dropdown
    :placement="($position === UserMenuPosition::Topbar) ? 'bottom-end' : 'top-end'"
    :teleport="$position === UserMenuPosition::Topbar"
    :attributes="
        \Filament\Support\prepare_inherited_attributes($attributes)
            ->class(['fi-user-menu'])
    "
>
    <x-slot name="trigger">
        @if ($position === UserMenuPosition::Topbar)
            <button
                aria-label="{{ __('filament-panels::layout.actions.open_user_menu.label') }}"
                type="button"
                class="fi-user-menu-trigger"
            >
                <x-filament-panels::avatar.user :user="$user" loading="lazy" />
            </button>
        @else
            <button
                aria-label="{{ __('filament-panels::layout.actions.open_user_menu.label') }}"
                type="button"
                class="fi-user-menu-trigger"
            >
                <x-filament-panels::avatar.user :user="$user" loading="lazy" />

                <span
                    @if ($isSidebarCollapsibleOnDesktop)
                        x-show="$store.sidebar.isOpen"
                    @endif
                    class="fi-user-menu-trigger-text"
                >
                    {{ filament()->getUserName($user) }}
                </span>

                {{
                    \Filament\Support\generate_icon_html(\Filament\Support\Icons\Heroicon::ChevronUp, alias: \Filament\View\PanelsIconAlias::USER_MENU_TOGGLE_BUTTON, attributes: new \Illuminate\View\ComponentAttributeBag([
                        'x-show' => $isSidebarCollapsibleOnDesktop ? '$store.sidebar.isOpen' : null,
                    ]))
                }}
            </button>
        @endif
    </x-slot>

    @if ($hasProfileHeader)
        @php
            $item = $itemsBeforeThemeSwitcher['profile'];
            $itemColor = $item->getColor();
            $itemIcon = $item->getIcon();

            unset($itemsBeforeThemeSwitcher['profile']);
        @endphp

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::USER_MENU_PROFILE_BEFORE) }}

        <x-filament::dropdown.header :color="$itemColor" :icon="$itemIcon">
            {{ $item->getLabel() }}
        </x-filament::dropdown.header>

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::USER_MENU_PROFILE_AFTER) }}
    @endif

    {{-- Section 1: Profile + Security + anything else with sort < 0 --}}
    @if ($itemsBeforeThemeSwitcher->isNotEmpty())
        <x-filament::dropdown.list>
            @foreach ($itemsBeforeThemeSwitcher as $key => $item)
                @if ($key === 'profile')
                    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::USER_MENU_PROFILE_BEFORE) }}

                    {{ $item }}

                    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::USER_MENU_PROFILE_AFTER) }}
                @else
                    {{ $item }}
                @endif
            @endforeach
        </x-filament::dropdown.list>
    @endif

    {{-- Section 2: Theme switcher --}}
    @if (filament()->hasDarkMode() && (! filament()->hasDarkModeForced()))
        <x-filament::dropdown.list>
            <x-filament-panels::theme-switcher />
        </x-filament::dropdown.list>
    @endif

    {{-- Section 3: Panel switchers and any other non-logout items --}}
    @if ($itemsMiddleSection->isNotEmpty())
        <x-filament::dropdown.list>
            @foreach ($itemsMiddleSection as $key => $item)
                {{ $item }}
            @endforeach
        </x-filament::dropdown.list>
    @endif

    {{-- Section 4: Sign out (always rendered last, alone in its
         own dropdown.list so the CSS divider above it visually
         separates it from the section above) --}}
    @if ($itemsLogoutSection->isNotEmpty())
        <x-filament::dropdown.list>
            @foreach ($itemsLogoutSection as $key => $item)
                {{ $item }}
            @endforeach
        </x-filament::dropdown.list>
    @endif
</x-filament::dropdown>

{{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::USER_MENU_AFTER) }}
