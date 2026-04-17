<x-filament-panels::page>
    <x-filament-actions::modals />
    @if ($this->activeTeamId)
        <div style="display: grid; grid-template-columns: 7fr 3fr; gap: 1.5rem; align-items: start;">
            <div>
                @if ($this->takingMessage)
                    <x-filament::section heading="New Message" icon="heroicon-o-pencil-square">
                        <form wire:submit="saveMessage">
                            <div style="display: flex; flex-direction: column; gap: 1rem;">
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                                    <div>
                                        <label style="display: block; font-size: 0.75rem; font-weight: 500; color: var(--gray-500); margin-bottom: 0.25rem;">
                                            Caller Name <span style="color: var(--danger-500);">*</span>
                                        </label>
                                        <x-filament::input.wrapper>
                                            <x-filament::input
                                                type="text"
                                                wire:model="msgCallerName"
                                                placeholder="Full name"
                                                required
                                            />
                                        </x-filament::input.wrapper>
                                        @error('msgCallerName')
                                            <div style="font-size: 0.75rem; color: var(--danger-500); margin-top: 0.25rem;">{{ $message }}</div>
                                        @enderror
                                    </div>
                                    <div>
                                        <label style="display: block; font-size: 0.75rem; font-weight: 500; color: var(--gray-500); margin-bottom: 0.25rem;">
                                            Caller Phone
                                        </label>
                                        <x-filament::input.wrapper>
                                            <x-filament::input
                                                type="tel"
                                                wire:model="msgCallerPhone"
                                                placeholder="Callback number"
                                            />
                                        </x-filament::input.wrapper>
                                    </div>
                                </div>

                                <div>
                                    <label style="display: block; font-size: 0.75rem; font-weight: 500; color: var(--gray-500); margin-bottom: 0.25rem;">
                                        Message / Reason for Call <span style="color: var(--danger-500);">*</span>
                                    </label>
                                    <x-filament::input.wrapper>
                                        <textarea
                                            wire:model="msgReason"
                                            rows="4"
                                            required
                                            placeholder="What are they calling about?"
                                            style="width: 100%; box-sizing: border-box; resize: vertical; border: none; background: transparent; padding: 0.5rem 0.75rem; font-size: 0.875rem; font-family: inherit; color: inherit; outline: none;"
                                        ></textarea>
                                    </x-filament::input.wrapper>
                                    @error('msgReason')
                                        <div style="font-size: 0.75rem; color: var(--danger-500); margin-top: 0.25rem;">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div>
                                    <label style="display: block; font-size: 0.75rem; font-weight: 500; color: var(--gray-500); margin-bottom: 0.25rem;">
                                        Urgency
                                    </label>
                                    <x-filament::input.wrapper>
                                        <x-filament::input.select wire:model="msgUrgency">
                                            <option value="normal">Normal</option>
                                            <option value="urgent">Urgent</option>
                                        </x-filament::input.select>
                                    </x-filament::input.wrapper>
                                </div>

                                <div style="display: flex; gap: 0.5rem; justify-content: flex-end; border-top: 1px solid rgba(128,128,128,0.15); padding-top: 1rem;">
                                    <x-filament::button type="button" color="gray" wire:click="cancelMessage">
                                        Cancel
                                    </x-filament::button>
                                    <x-filament::button type="submit" color="success">
                                        Save Message
                                    </x-filament::button>
                                </div>
                            </div>
                        </form>
                    </x-filament::section>
                @else
                    {{ $this->table }}
                @endif
            </div>

            <x-filament::section
                heading="Intake Flow"
                icon="heroicon-o-list-bullet"
                compact
            >
                @livewire('compiled-flow-viewer', ['teamId' => $this->activeTeamId])
            </x-filament::section>
        </div>
    @else
        <x-filament::section>
            <div style="display: flex; gap: 1.5rem; align-items: stretch; padding: 1.5rem 0;">
                @if (! empty($this->recentAccounts))
                    <div style="flex: 0 0 20%; border-right: 1px solid rgba(128,128,128,0.15); padding-right: 1.5rem; display: flex; flex-direction: column; gap: 0.375rem;">
                        <div style="font-size: 0.625rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--gray-500); margin-bottom: 0.25rem;">
                            Recent
                        </div>
                        @foreach ($this->recentAccounts as $account)
                            <x-filament::button
                                size="sm"
                                color="gray"
                                wire:click="fetchRecentAccount({{ $account['id'] }})"
                                style="justify-content: flex-start; width: 100%;"
                            >
                                {{ $account['name'] }} (#{{ $account['account_number'] }})
                            </x-filament::button>
                        @endforeach
                    </div>
                @endif

                <div style="flex: 1; display: flex; align-items: center; justify-content: center;">
                    <div style="text-align: center; padding: 2rem 0;">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" style="width: 4rem; height: 4rem; color: var(--gray-300); margin: 0 auto 1rem;">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3H21m-3.75 3H21" />
                        </svg>
                        <div style="font-size: 1rem; font-weight: 600; margin-bottom: 0.5rem;">No account loaded</div>
                        <div style="font-size: 0.875rem; color: var(--gray-500); max-width: 24rem; margin: 0 auto;">
                            Click <strong>Fetch Account</strong> above to open a tenant's workspace, or an incoming call will load one automatically.
                        </div>
                    </div>
                </div>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
