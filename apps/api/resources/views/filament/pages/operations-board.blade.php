<x-filament-panels::page>
    <div class="space-y-6">
        <div class="grid gap-4 md:grid-cols-3">
            <x-filament::section>
                <div class="text-sm text-gray-500">Open work</div>
                <div class="mt-1 text-3xl font-bold">{{ $tasks->count() }}</div>
            </x-filament::section>
            <x-filament::section>
                <div class="text-sm text-gray-500">Overdue</div>
                <div class="mt-1 text-3xl font-bold {{ $overdue ? 'text-danger-600' : '' }}">{{ $overdue }}</div>
            </x-filament::section>
            <x-filament::section>
                <div class="text-sm text-gray-500">Movement today</div>
                <div class="mt-1 text-3xl font-bold">{{ $arrivals->count() + $departures->count() }}</div>
            </x-filament::section>
        </div>

        <div class="grid gap-6 xl:grid-cols-2">
            <x-filament::section heading="Arrivals · {{ $date->format('M j') }}">
                <div class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse ($arrivals as $arrival)
                        <div class="flex items-center justify-between gap-4 py-3">
                            <div>
                                <div class="font-semibold">{{ $arrival['guest'] ?? $arrival['reference'] }}</div>
                                <div class="text-sm text-gray-500">{{ $arrival['property'] }} · {{ $arrival['party'] }} guests</div>
                            </div>
                            <div class="text-sm">{{ $arrival['starts_at']->timezone($timezone)->format('H:i') }}</div>
                        </div>
                    @empty
                        <div class="py-8 text-center text-gray-500">No arrivals today.</div>
                    @endforelse
                </div>
            </x-filament::section>

            <x-filament::section heading="Departures · {{ $date->format('M j') }}">
                <div class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse ($departures as $departure)
                        <div class="flex items-center justify-between gap-4 py-3">
                            <div>
                                <div class="font-semibold">{{ $departure['guest'] ?? $departure['reference'] }}</div>
                                <div class="text-sm text-gray-500">{{ $departure['property'] }} · {{ $departure['party'] }} guests</div>
                            </div>
                            <div class="text-sm">{{ $departure['ends_at']->timezone($timezone)->format('H:i') }}</div>
                        </div>
                    @empty
                        <div class="py-8 text-center text-gray-500">No departures today.</div>
                    @endforelse
                </div>
            </x-filament::section>
        </div>

        @if ($operations['kitchen']['available'] || $operations['housekeeping']['available'] || count($operations['guide_assignments']))
            <div class="grid gap-6 xl:grid-cols-3">
                @if ($operations['kitchen']['available'])
                    <x-filament::section heading="Kitchen preparation" description="Dietary details are aggregated without exposing guest identity to kitchen-only roles.">
                        <div class="mb-4 text-3xl font-bold">{{ $operations['kitchen']['guest_count'] }} guests</div>
                        <div class="space-y-2">
                            @forelse ($operations['kitchen']['restrictions'] as $restriction)
                                <div class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 px-3 py-2 dark:border-white/10">
                                    <span>{{ $restriction['label'] }}</span>
                                    <x-filament::badge :color="$restriction['serious'] ? 'danger' : 'gray'">{{ $restriction['count'] }}</x-filament::badge>
                                </div>
                            @empty
                                <div class="text-sm text-gray-500">No dietary restrictions recorded for active stays.</div>
                            @endforelse
                        </div>
                    </x-filament::section>
                @endif

                @if ($operations['housekeeping']['available'])
                    <x-filament::section heading="Housekeeping movement">
                        <div class="grid grid-cols-3 gap-3 text-center">
                            <div><div class="text-2xl font-bold">{{ $operations['housekeeping']['arrivals'] }}</div><div class="text-xs text-gray-500">Arrivals</div></div>
                            <div><div class="text-2xl font-bold">{{ $operations['housekeeping']['turnovers'] }}</div><div class="text-xs text-gray-500">Turnovers</div></div>
                            <div><div class="text-2xl font-bold">{{ $operations['housekeeping']['stayovers'] }}</div><div class="text-xs text-gray-500">Stayovers</div></div>
                        </div>
                        @if ($operations['housekeeping']['focus'])<div class="mt-4 rounded-lg bg-warning-50 p-3 text-sm dark:bg-white/5"><strong>Priority:</strong> {{ $operations['housekeeping']['focus'] }}</div>@endif
                    </x-filament::section>
                @endif

                @if (count($operations['guide_assignments']))
                    <x-filament::section heading="Guide assignments" description="Tomorrow's confirmed and action-needed activities.">
                        <div class="space-y-3">
                            @foreach ($operations['guide_assignments'] as $assignment)
                                <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                                    <div class="font-semibold">{{ $assignment['program'] }}</div>
                                    <div class="text-sm text-gray-500">{{ $assignment['guide'] ?? 'Guide needed' }} · {{ $assignment['party_size'] }} guests</div>
                                    <div class="mt-1 text-xs">{{ \Carbon\CarbonImmutable::parse($assignment['starts_at'])->timezone($timezone)->format('M j · H:i') }}</div>
                                </div>
                            @endforeach
                        </div>
                    </x-filament::section>
                @endif
            </div>
        @endif

        <x-filament::section heading="Live work queue" description="Priority-ordered work for the active tenant and your assigned operational role.">
            <div class="grid gap-4 lg:grid-cols-2 2xl:grid-cols-3">
                @forelse ($tasks as $task)
                    <a href="{{ \App\Filament\Resources\OperationalTasks\OperationalTaskResource::getUrl('view', ['record' => $task]) }}" wire:navigate class="rounded-xl border border-gray-200 p-4 transition hover:border-primary-500 dark:border-white/10">
                        <div class="flex items-start justify-between gap-3">
                            <div class="font-semibold">{{ $task->title }}</div>
                            <x-filament::badge :color="in_array($task->priority, ['urgent', 'high'], true) ? 'danger' : 'gray'">{{ str($task->priority)->headline() }}</x-filament::badge>
                        </div>
                        <div class="mt-2 text-sm text-gray-500">{{ $task->property->name }} · {{ $task->assignee?->name ?? 'Unassigned' }}</div>
                        <div class="mt-3 text-xs {{ $task->due_at?->isPast() ? 'text-danger-600' : 'text-gray-500' }}">
                            {{ $task->due_at ? 'Due '.$task->due_at->timezone($timezone)->format('M j · H:i') : 'No deadline' }}
                        </div>
                    </a>
                @empty
                    <div class="col-span-full py-10 text-center text-gray-500">The work queue is clear.</div>
                @endforelse
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
