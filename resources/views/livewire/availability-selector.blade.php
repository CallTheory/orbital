<div class="orbital-availability" wire:key="availability-{{ auth()->id() }}">
    {{-- Options come from AvailabilityReason rows + the built-in
         `available` sentinel. Dot color is whatever color the
         admin picked for the currently-selected reason. --}}
    <label class="orbital-availability-label" for="orbital-availability-select">
        <span class="orbital-availability-dot" style="background: {{ $currentDotColor }};"></span>
        <select
            id="orbital-availability-select"
            class="orbital-availability-select"
            wire:model.live="status"
        >
            @foreach ($options as $value => $option)
                <option value="{{ $value }}">{{ $option['label'] }}</option>
            @endforeach
        </select>
    </label>
</div>
