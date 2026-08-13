<x-filament-widgets::widget wire:poll.30s wire:loading.class="opacity-70">
    <div class="relative space-y-6">
        <div
            wire:loading.flex
            class="absolute right-0 top-0 z-10 hidden items-center gap-2 rounded-lg bg-gray-50 px-2.5 py-1.5 text-xs text-gray-500 shadow-sm dark:bg-white/5"
        >
            <x-filament::loading-indicator class="h-4 w-4" />
            Updating
        </div>

        <x-filament::section
            heading="Quick actions"
            description="{{ $dashboard['date'] }} · {{ $timezone }} · open the workflow you need without hunting through navigation."
        >
            <div class="flex flex-wrap gap-3">
                <x-filament::button :href="$urls['reservations']" tag="a" wire:navigate icon="heroicon-m-plus">
                    New reservation
                </x-filament::button>
                <x-filament::button :href="$urls['calendar']" tag="a" wire:navigate color="gray" icon="heroicon-m-calendar-days">
                    Master calendar
                </x-filament::button>
                @if ($canAccessOperationsBoard)
                    <x-filament::button :href="$urls['operations']" tag="a" wire:navigate color="gray" icon="heroicon-m-rectangle-group">
                        Operations board
                    </x-filament::button>
                @endif
                @if ($canAccessTasks)
                    <x-filament::button :href="$urls['tasks']" tag="a" wire:navigate color="gray" icon="heroicon-m-clipboard-document-check">
                        All tasks
                    </x-filament::button>
                @endif
            </div>
        </x-filament::section>

        <div @class(['grid gap-6', 'xl:grid-cols-3' => $canAccessTasks, 'xl:grid-cols-2' => ! $canAccessTasks])>
            <x-filament::section
                heading="Stays needing attention"
                description="Specific upcoming reservations blocked by missing guest, requested resource, payment, or kitchen details."
            >
                <div class="space-y-3">
                    @forelse ($dashboard['attention_stays'] as $stay)
                        <a href="{{ $urls['reservations'] }}" wire:navigate class="block rounded-xl border border-gray-200 p-3 transition hover:border-warning-500 dark:border-white/10">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="font-semibold">{{ $stay['guest_name'] ?? $stay['confirmation_number'] }}</div>
                                    <div class="mt-1 text-xs text-gray-500">
                                        Arrives {{ \Carbon\CarbonImmutable::parse($stay['starts_at'])->timezone($timezone)->format('M j · H:i') }}
                                    </div>
                                </div>
                                <x-filament::badge color="warning">Review</x-filament::badge>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                @foreach ($stay['reasons'] as $reason)
                                    <x-filament::badge color="warning">{{ $reason }}</x-filament::badge>
                                @endforeach
                            </div>
                        </a>
                    @empty
                        <div class="py-8 text-center">
                            <div class="font-medium">Upcoming stays are ready</div>
                            <div class="mt-1 text-sm text-gray-500">No guest, resource, payment, or kitchen blockers in the next seven days.</div>
                        </div>
                    @endforelse
                </div>
            </x-filament::section>

            <x-filament::section
                heading="Today's arrivals"
                description="Ready parties and blocked details before check-in."
            >
                <div class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse ($dashboard['arrival_parties'] as $arrival)
                        <div class="py-3 first:pt-0 last:pb-0">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="font-semibold">{{ $arrival['guest_name'] ?? $arrival['confirmation_number'] }}</div>
                                    <div class="mt-1 text-sm text-gray-500">
                                        {{ $arrival['party_size'] }} guests · {{ $arrival['nights'] }} nights
                                    </div>
                                </div>
                                <x-filament::badge :color="\App\Filament\Support\LodgeOpsPresentation::statusColor($arrival['readiness'])">
                                    {{ str($arrival['readiness'])->headline() }}
                                </x-filament::badge>
                            </div>
                            <div class="mt-2 text-xs text-gray-500">
                                {{ \Carbon\CarbonImmutable::parse($arrival['starts_at'])->timezone($timezone)->format('H:i') }}
                                · {{ count($arrival['stay_place_names']) ? collect($arrival['stay_place_names'])->join(', ') : 'Stay place needed' }}
                            </div>
                        </div>
                    @empty
                        <div class="py-8 text-center">
                            <div class="font-medium">No arrivals today</div>
                            <div class="mt-1 text-sm text-gray-500">Review the calendar for the next arrival window.</div>
                        </div>
                    @endforelse
                </div>
            </x-filament::section>

            @if ($canAccessTasks)
                <x-filament::section
                    heading="Action queue"
                    description="The highest-priority open work for this lodge."
                >
                    <div class="space-y-3">
                        @forelse ($dashboard['tasks'] as $task)
                            <a href="{{ $urls['tasks'] }}" wire:navigate class="block rounded-xl border border-gray-200 p-3 transition hover:border-primary-500 dark:border-white/10">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="font-semibold">{{ $task['title'] }}</div>
                                    <x-filament::badge :color="\App\Filament\Support\LodgeOpsPresentation::priorityColor($task['priority'])">
                                        {{ str($task['priority'])->headline() }}
                                    </x-filament::badge>
                                </div>
                                <div class="mt-2 text-xs text-gray-500">
                                    {{ $task['assignee']['name'] ?? 'Unassigned' }}
                                    @if ($task['due_at'])
                                        · due {{ \Carbon\CarbonImmutable::parse($task['due_at'])->timezone($timezone)->format('M j · H:i') }}
                                    @endif
                                </div>
                            </a>
                        @empty
                            <div class="py-8 text-center">
                                <div class="font-medium">The action queue is clear</div>
                                <div class="mt-1 text-sm text-gray-500">No open operational tasks need attention.</div>
                            </div>
                        @endforelse
                    </div>
                </x-filament::section>
            @endif
        </div>
    </div>
</x-filament-widgets::widget>
