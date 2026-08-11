<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section heading="Kitchen planning" description="Plan meals by property-local date. Guest identity is never shown in this workspace.">
            <div class="grid gap-4 md:grid-cols-4">
                <label class="block text-sm font-medium">
                    <span class="mb-1 block">Property</span>
                    <select wire:model.live="propertyId" class="fi-input block w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">
                        @foreach ($properties as $option)
                            <option value="{{ $option->id }}">{{ $option->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block text-sm font-medium">
                    <span class="mb-1 block">Planning start</span>
                    <input type="date" wire:model.live="start" class="fi-input block w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5" />
                </label>
                <label class="block text-sm font-medium">
                    <span class="mb-1 block">Planning end</span>
                    <input type="date" wire:model.live="end" class="fi-input block w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5" />
                </label>
                <div class="flex items-end text-sm text-gray-500">
                    <div>
                        <div class="font-medium text-gray-950 dark:text-white">{{ $timezone }}</div>
                        <div>{{ $startDate->format('F j, Y') }} – {{ $endDate->format('F j, Y') }}</div>
                    </div>
                </div>
            </div>
        </x-filament::section>

        <div class="grid gap-4 md:grid-cols-3">
            <x-filament::section>
                <div class="text-sm text-gray-500">Reservations in plan</div>
                <div class="mt-1 text-3xl font-bold">{{ $reservationCount }}</div>
            </x-filament::section>
            <x-filament::section>
                <div class="text-sm text-gray-500">Guests in plan</div>
                <div class="mt-1 text-3xl font-bold">{{ $guestCount }}</div>
            </x-filament::section>
            <x-filament::section>
                <div class="text-sm text-gray-500">Dietary flags</div>
                <div class="mt-1 text-3xl font-bold">{{ count($restrictions) }}</div>
            </x-filament::section>
        </div>

        <div class="grid gap-6 xl:grid-cols-3">
            <x-filament::section heading="Dietary summary" description="Aggregated restrictions only; no guest names or profiles are included.">
                <div class="space-y-2">
                    @forelse ($restrictions as $restriction)
                        <div class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 px-3 py-2 dark:border-white/10">
                            <span>{{ $restriction['label'] }}</span>
                            <x-filament::badge :color="$restriction['serious'] ? 'danger' : 'gray'">{{ $restriction['count'] }}</x-filament::badge>
                        </div>
                    @empty
                        <div class="py-6 text-center text-sm text-gray-500">No dietary restrictions in this plan.</div>
                    @endforelse
                </div>
            </x-filament::section>

            <x-filament::section heading="Planned stays" class="xl:col-span-2">
                <div class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse ($reservations as $reservation)
                        <div class="flex items-start justify-between gap-4 py-3">
                            <div>
                                <div class="font-semibold">{{ $reservation['reference'] }}</div>
                                <div class="text-sm text-gray-500">
                                    {{ $reservation['starts_at']->format('M j · H:i') }} – {{ $reservation['ends_at']->format('M j · H:i') }} · {{ $reservation['party'] }} guests
                                </div>
                                @if (count($reservation['dietary']))
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        @foreach ($reservation['dietary'] as $dietary)
                                            <x-filament::badge color="warning">{{ $dietary }}</x-filament::badge>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="py-8 text-center text-gray-500">No confirmed or checked-in stays in this plan.</div>
                    @endforelse
                </div>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
