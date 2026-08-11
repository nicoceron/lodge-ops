<x-filament-widgets::widget>
    <div class="space-y-6">
        <x-filament::section
            heading="Lodge command center"
            description="{{ $dashboard['date'] }} · {{ $timezone }} · the next actions that can affect a guest stay."
        >
            <div class="flex flex-wrap gap-3">
                <x-filament::button :href="$urls['reservations']" tag="a" icon="heroicon-m-plus">
                    New reservation
                </x-filament::button>
                <x-filament::button :href="$urls['calendar']" tag="a" color="gray" icon="heroicon-m-calendar-days">
                    Master calendar
                </x-filament::button>
                <x-filament::button :href="$urls['operations']" tag="a" color="gray" icon="heroicon-m-rectangle-group">
                    Operations board
                </x-filament::button>
                <x-filament::button :href="$urls['tasks']" tag="a" color="gray" icon="heroicon-m-clipboard-document-check">
                    All tasks
                </x-filament::button>
            </div>
        </x-filament::section>

        <div class="grid gap-6 xl:grid-cols-3">
            <x-filament::section
                heading="Next 7 days readiness"
                description="Guest, room, guide, payment and kitchen preparation across confirmed arrivals."
            >
                <div class="mb-5 flex items-end justify-between gap-4">
                    <div>
                        <div class="text-3xl font-bold">{{ $dashboard['readiness']['percent'] }}%</div>
                        <div class="text-sm text-gray-500">{{ $dashboard['readiness']['complete'] }} of {{ $dashboard['readiness']['total'] }} checks complete</div>
                    </div>
                    <x-filament::badge :color="$dashboard['needs_attention'] > 0 ? 'warning' : 'success'">
                        {{ $dashboard['needs_attention'] }} need attention
                    </x-filament::badge>
                </div>
                <progress class="mb-5 h-2 w-full accent-primary-500" max="100" value="{{ $dashboard['readiness']['percent'] }}">
                    {{ $dashboard['readiness']['percent'] }}%
                </progress>
                <div class="space-y-3">
                    @foreach ($dashboard['readiness']['items'] as $item)
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm font-medium">{{ $item['label'] }}</span>
                            <x-filament::badge :color="$item['complete'] === $item['total'] ? 'success' : 'warning'">
                                {{ $item['complete'] }}/{{ $item['total'] }}
                            </x-filament::badge>
                        </div>
                    @endforeach
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
                                · {{ count($arrival['room_names']) ? collect($arrival['room_names'])->join(', ') : 'Room needed' }}
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
        </div>
    </div>
</x-filament-widgets::widget>
