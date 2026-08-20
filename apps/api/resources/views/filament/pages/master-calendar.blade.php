<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 pb-4 dark:border-white/10">
                <div class="flex flex-wrap items-center gap-2">
                    <x-filament::button type="button" wire:click="previousRange" wire:loading.attr="disabled" color="gray" size="sm" icon="heroicon-m-chevron-left">
                        Previous
                    </x-filament::button>
                    <x-filament::button type="button" wire:click="goToToday" wire:loading.attr="disabled" color="gray" size="sm">
                        Today
                    </x-filament::button>
                    <x-filament::button type="button" wire:click="nextRange" wire:loading.attr="disabled" color="gray" size="sm" icon-position="after" icon="heroicon-m-chevron-right">
                        Next
                    </x-filament::button>
                </div>
                <div class="flex items-center gap-1 rounded-lg bg-gray-100 p-1 dark:bg-white/5" aria-label="Calendar range">
                    @foreach ([7 => 'Week', 14 => '2 weeks', 30 => '30 days'] as $rangeOption => $label)
                        <button
                            type="button"
                            wire:click="setRange({{ $rangeOption }})"
                            wire:loading.attr="disabled"
                            @class([
                                'rounded-md px-3 py-1.5 text-xs font-semibold transition',
                                'bg-white text-primary-700 shadow-sm dark:bg-white/10 dark:text-primary-300' => $rangeDays === $rangeOption,
                                'text-gray-600 hover:text-gray-950 dark:text-gray-400 dark:hover:text-white' => $rangeDays !== $rangeOption,
                            ])
                        >{{ $label }}</button>
                    @endforeach
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <label class="space-y-1 text-sm font-medium">
                    <span>From</span>
                    <x-filament::input.wrapper>
                        <x-filament::input type="date" wire:model.live="start" />
                    </x-filament::input.wrapper>
                </label>
                <label class="space-y-1 text-sm font-medium">
                    <span>Reservation state</span>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="reservationState">
                            <option value="all">All states</option>
                            @foreach (\App\Enums\ReservationStatus::cases() as $status)
                                <option value="{{ $status->value }}">{{ str($status->value)->headline() }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>
                <label class="space-y-1 text-sm font-medium">
                    <span>Program</span>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="programId">
                            <option value="">All programs</option>
                            @foreach ($programOptions as $programOption)
                                <option value="{{ $programOption->id }}">{{ $programOption->name }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>
                <label class="space-y-1 text-sm font-medium">
                    <span>Stay boundary</span>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="boundary">
                            <option value="all">Any overlap</option>
                            <option value="arrivals">Arrivals</option>
                            <option value="departures">Departures</option>
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>
                <label class="space-y-1 text-sm font-medium">
                    <span>Attention</span>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="attention">
                            <option value="all">All records</option>
                            <option value="unassigned">Unassigned stays</option>
                            <option value="conflicted">Hard conflicts</option>
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>
                <label class="space-y-1 text-sm font-medium">
                    <span>Housekeeping</span>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="housekeeping">
                            <option value="all">All readiness</option>
                            <option value="clean">Clean</option>
                            <option value="dirty">Dirty</option>
                            <option value="in_progress">In progress</option>
                            <option value="inspected">Inspected</option>
                            <option value="out_of_service">Out of service</option>
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>
                <label class="space-y-1 text-sm font-medium">
                    <span>Through</span>
                    <x-filament::input.wrapper>
                        <x-filament::input type="date" wire:model.live="end" />
                    </x-filament::input.wrapper>
                </label>
                <label class="space-y-1 text-sm font-medium">
                    <span>Lens</span>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="lens">
                            <option value="all">Everything</option>
                            <option value="stays">Reservations</option>
                            <option value="activities">Activities</option>
                            <option value="tasks">Tasks</option>
                            <option value="blocks">Resource blocks</option>
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>
                <label class="space-y-1 text-sm font-medium">
                    <span>Kind</span>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="kind">
                            <option value="all">All kinds</option>
                            <option value="place">Places</option>
                            <option value="asset">Assets</option>
                            <option value="crew">Crew</option>
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>
                @if ($properties->isNotEmpty())
                    <label class="space-y-1 text-sm font-medium">
                        <span>Property</span>
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model.live="propertyId">
                                <option value="">All properties</option>
                                @foreach ($properties as $property)
                                    <option value="{{ $property->id }}">{{ $property->name }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </label>
                @endif
            </div>
        </x-filament::section>

        @if ($buyouts->isNotEmpty())
            <x-filament::section
                heading="Full lodge buyout active"
                description="Do not overlap another reservation or shared resource allocation with these protected windows."
                icon="heroicon-o-shield-exclamation"
                icon-color="danger"
            >
                <div class="grid gap-3 lg:grid-cols-2">
                    @foreach ($buyouts as $buyout)
                        <div class="rounded-xl border border-danger-300 bg-danger-50 p-4 dark:border-danger-500/40 dark:bg-danger-950/20">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <div class="font-semibold text-danger-800 dark:text-danger-200">{{ $buyout['title'] }}</div>
                                    <div class="mt-1 text-sm text-danger-700 dark:text-danger-300">{{ $buyout['reference'] }} · {{ $buyout['property'] }}</div>
                                </div>
                                <x-filament::badge color="danger">Exclusive</x-filament::badge>
                            </div>
                            <div class="mt-3 text-sm text-danger-700 dark:text-danger-300">
                                {{ $buyout['starts_at']->timezone($timezone)->format('M j, Y · H:i') }}
                                – {{ $buyout['ends_at']->timezone($timezone)->format('M j, Y · H:i') }}
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        @if ($programs->isNotEmpty())
            <x-filament::section heading="Program legend" description="Closed programs and simple stays keep their configured color everywhere on the schedule.">
                <div class="flex flex-wrap gap-3">
                    @foreach ($programs as $program)
                        <div class="flex items-center gap-2 rounded-full border border-gray-200 px-3 py-1.5 text-sm dark:border-white/10">
                            <span class="size-3 rounded-full" style="background-color: {{ $program['color'] }}"></span>
                            <span class="font-medium">{{ $program['name'] }}</span>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        <div class="grid gap-4 md:grid-cols-3">
            <x-filament::section heading="Allocation health">
                <div class="text-3xl font-bold {{ $allocationSummary['hard_conflicts'] ? 'text-danger-600' : 'text-success-600' }}">{{ $allocationSummary['hard_conflicts'] }}</div>
                <div class="mt-1 text-sm text-gray-500">Hard conflicts</div>
            </x-filament::section>
            <x-filament::section heading="Unassigned reservations">
                <div class="text-3xl font-bold">{{ $allocationSummary['unassigned_reservations'] }}</div>
                <div class="mt-1 text-sm text-gray-500">Reservations without resource allocations</div>
            </x-filament::section>
            <x-filament::section heading="Resource suggestions">
                <div class="text-3xl font-bold">{{ $allocationSummary['suggestions'] }}</div>
                <div class="mt-1 text-sm text-gray-500">Stays requiring planning attention</div>
            </x-filament::section>
        </div>

        @if ($attentionRows->isNotEmpty())
            <x-filament::section
                heading="Shared-resource attention workbench"
                description="Required and conflicted guide, horse, boat, and vehicle assignments. Recommendations are availability-ranked and every mutation rechecks capacity under lock."
            >
                <div class="space-y-3">
                    @foreach ($attentionRows as $row)
                        <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10" wire:key="attention-{{ $row['reservation_id'] }}-{{ $row['category_id'] }}">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <div class="font-semibold">{{ $row['reference'] }} · {{ $row['category'] }}</div>
                                    <div class="mt-1 text-sm text-gray-500">Required {{ $row['required'] }} · assigned {{ $row['assigned'] }}</div>
                                </div>
                                <x-filament::badge :color="$row['conflicted'] ? 'danger' : 'warning'">
                                    {{ $row['conflicted'] ? 'Conflicted' : 'Unassigned' }}
                                </x-filament::badge>
                            </div>
                            <ol class="mt-3 space-y-1 text-sm text-gray-600 dark:text-gray-300">
                                @foreach ($row['reasons'] as $reason)<li>{{ $reason }}</li>@endforeach
                            </ol>
                            <div class="mt-4 flex flex-wrap gap-2">
                                @foreach ($row['suggestions'] as $suggestion)
                                    <button
                                        type="button"
                                        class="rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white hover:bg-primary-500"
                                        wire:click="assignAttention('{{ $row['reservation_id'] }}', '{{ $row['category_id'] }}', '{{ $suggestion['id'] }}', '{{ $row['allocation_id'] ?? '' }}')"
                                    >
                                        {{ $row['resource_id'] ? 'Move to' : 'Assign' }} {{ $suggestion['name'] }}
                                    </button>
                                @endforeach
                                @if ($row['swap'] && $row['allocation_id'])
                                    <x-filament::button
                                        size="sm"
                                        color="warning"
                                        wire:click="swapAttention('{{ $row['reservation_id'] }}', '{{ $row['allocation_id'] }}', '{{ $row['swap']['resource_id'] }}', '{{ $row['swap']['allocation_id'] }}')"
                                    >Swap assignments</x-filament::button>
                                @endif
                                @if ($row['allocation_id'])
                                    <x-filament::button
                                        size="sm"
                                        color="danger"
                                        wire:click="releaseAttention('{{ $row['reservation_id'] }}', '{{ $row['allocation_id'] }}')"
                                        wire:confirm="Release this assignment?"
                                    >Release</x-filament::button>
                                @endif
                            </div>
                            @if ($row['suggestions'] === [])
                                <div class="mt-3 text-sm text-danger-600">No conflict-free matching resource is currently available.</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        @if ($resourceGroups->isNotEmpty())
            <x-filament::section
                heading="Resource planner"
                description="A live occupancy board grouped by place, asset, and crew. Category names come from this property’s catalog."
            >
                <div class="hidden overflow-x-auto rounded-xl border border-gray-200 lg:block dark:border-white/10">
                    <div
                        class="grid"
                        role="grid"
                        aria-label="Resource allocation calendar"
                        style="grid-template-columns: minmax(13rem, 16rem) repeat({{ $days->count() }}, minmax(7rem, 1fr)); min-width: {{ max(960, 240 + ($days->count() * 112)) }}px"
                    >
                        <div class="sticky left-0 z-20 border-b border-r border-gray-200 bg-gray-50 p-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:border-white/10 dark:bg-gray-900 dark:text-gray-400">
                            Resource
                        </div>
                        @foreach ($days as $day)
                            <div @class([
                                'border-b border-r border-gray-200 p-3 text-center last:border-r-0 dark:border-white/10',
                                'bg-primary-50 dark:bg-primary-950/20' => $day['date']->toDateString() === $today,
                                'bg-gray-50 dark:bg-gray-900' => $day['date']->toDateString() !== $today,
                            ])>
                                <div class="text-[0.65rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $day['date']->format('D') }}</div>
                                <div class="mt-0.5 text-sm font-bold">{{ $day['date']->format('M j') }}</div>
                            </div>
                        @endforeach

                        @foreach ($resourceGroups as $group)
                            <div class="sticky left-0 z-10 border-b border-r border-gray-200 bg-gray-50 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:border-white/10 dark:bg-gray-900 dark:text-gray-400">
                                {{ $group['label'] }}
                            </div>
                            <div class="border-b border-gray-200 bg-gray-50 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:border-white/10 dark:bg-gray-900 dark:text-gray-400" style="grid-column: 2 / -1">
                                {{ $group['rows']->count() }} {{ \Illuminate\Support\Str::plural('lane', $group['rows']->count()) }}
                            </div>
                            @foreach ($group['rows'] as $row)
                            <div class="sticky left-0 z-10 border-b border-r border-gray-200 bg-white p-3 dark:border-white/10 dark:bg-gray-950">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <div class="truncate text-sm font-semibold">{{ $row['name'] }}</div>
                                        <div class="mt-0.5 text-xs text-gray-500">{{ $row['code'] }} · capacity {{ $row['capacity'] }}</div>
                                    </div>
                                    <x-filament::badge :color="$row['is_buyout'] ? 'danger' : 'gray'" size="sm">{{ $row['category'] }}</x-filament::badge>
                                </div>
                            </div>
                            @foreach ($row['days'] as $resourceDay)
                                <div @class([
                                    'min-h-20 border-b border-r border-gray-200 p-1.5 last:border-r-0 dark:border-white/10',
                                    'bg-primary-50/50 dark:bg-primary-950/10' => $resourceDay['date']->toDateString() === $today,
                                ])>
                                    <div class="space-y-1">
                                        @foreach ($resourceDay['items'] as $item)
                                            @php($itemClasses = 'block truncate rounded-md border-l-4 bg-gray-50 px-2 py-1.5 text-[0.7rem] leading-tight transition hover:bg-gray-100 dark:bg-white/5 dark:hover:bg-white/10')
                                            @if ($item['url'])
                                                <a
                                                    href="{{ $item['url'] }}"
                                                    wire:navigate
                                                    class="{{ $itemClasses }}"
                                                    style="border-left-color: {{ $item['color'] }}"
                                                    title="{{ $item['label'] }} · {{ str($item['status'])->headline() }}"
                                                >
                                                    <span class="font-semibold">{{ $item['label'] }}</span>
                                                    @if ($item['quantity'] > 1)<span class="text-gray-500"> ×{{ $item['quantity'] }}</span>@endif
                                                </a>
                                            @else
                                                <div class="{{ $itemClasses }}" style="border-left-color: {{ $item['color'] }}" title="{{ $item['label'] }}">
                                                    <span class="font-semibold">{{ $item['label'] }}</span>
                                                </div>
                                            @endif
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        @endforeach
                        @endforeach
                    </div>
                </div>
                <div class="rounded-xl border border-gray-200 p-4 text-sm text-gray-600 lg:hidden dark:border-white/10 dark:text-gray-300">
                    The compact day agenda below is optimized for this screen. Open the planner on a wider display for the full resource timeline.
                </div>
            </x-filament::section>
        @endif

        <x-filament::section heading="Calendar overview" description="A property-local day agenda for the selected lens. Multi-day stays appear on every day they occupy.">
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                @foreach ($days as $day)
                    <div class="min-h-48 rounded-xl border border-gray-200 p-3 dark:border-white/10">
                        <div class="mb-3 flex items-center justify-between gap-2 border-b border-gray-100 pb-2 dark:border-white/5">
                            <div><div class="text-xs uppercase text-gray-500">{{ $day['date']->format('D') }}</div><div class="font-bold">{{ $day['date']->format('M j') }}</div></div>
                            <x-filament::badge color="gray">{{ $day['events']->count() }}</x-filament::badge>
                        </div>
                        <div class="space-y-2">
                            @forelse ($day['events'] as $event)
                                <div class="rounded-lg border-l-4 bg-gray-50 p-2 text-xs dark:bg-white/5" style="border-left-color: {{ $event['color'] ?? '#D97706' }}">
                                    <div class="font-semibold">
                                        @if ($event['url'])<a class="text-primary-600 hover:underline dark:text-primary-400" href="{{ $event['url'] }}" wire:navigate>{{ $event['title'] }}</a>@else{{ $event['title'] }}@endif
                                    </div>
                                    <div class="mt-1 text-gray-500">{{ $event['starts_at']->timezone($timezone)->format('H:i') }} · {{ $event['type'] }}</div>
                                </div>
                            @empty
                                <div class="py-6 text-center text-xs text-gray-400">No scheduled work</div>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        @if ($resources->isNotEmpty())
            <x-filament::section heading="Resource utilization" description="Allocated capacity inside the selected calendar range.">
                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    @foreach ($resources as $resource)
                        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                            <div class="flex items-center justify-between gap-2"><span class="font-semibold">{{ $resource['name'] }}</span><x-filament::badge color="gray">{{ $resource['category'] }}</x-filament::badge></div>
                            <progress class="mt-3 h-2 w-full accent-primary-500" max="100" value="{{ $resource['utilization_percent'] }}">{{ $resource['utilization_percent'] }}%</progress>
                            <div class="mt-1 text-xs text-gray-500">{{ $resource['utilization_percent'] }}% utilized · capacity {{ $resource['capacity'] }}</div>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        <x-filament::section heading="Schedule" description="Reservations, activities, resource blocks, and due tasks in {{ $timezone }}.">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <tr>
                            <th class="px-3 py-3">When</th>
                            <th class="px-3 py-3">Type</th>
                            <th class="px-3 py-3">Item</th>
                            <th class="px-3 py-3">Property</th>
                            <th class="px-3 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @forelse ($events as $event)
                            <tr>
                                <td class="whitespace-nowrap px-3 py-3">
                                    <div class="font-medium">{{ $event['starts_at']->timezone($timezone)->format('M j, Y · H:i') }}</div>
                                    @if (!$event['starts_at']->equalTo($event['ends_at']))
                                        <div class="text-xs text-gray-500">to {{ $event['ends_at']->timezone($timezone)->format('M j · H:i') }}</div>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-gray-600 dark:text-gray-300">
                                    <div class="flex items-center gap-2">
                                        @if ($event['color'])<span class="size-2.5 rounded-full" style="background-color: {{ $event['color'] }}"></span>@endif
                                        <span>{{ $event['type'] }}</span>
                                    </div>
                                </td>
                                <td class="px-3 py-3">
                                    @if ($event['url'])
                                        <a href="{{ $event['url'] }}" wire:navigate class="font-semibold text-primary-600 hover:underline dark:text-primary-400">{{ $event['title'] }}</a>
                                    @else
                                        <span class="font-semibold">{{ $event['title'] }}</span>
                                    @endif
                                    @if ($event['reference'])
                                        <div class="text-xs text-gray-500">{{ $event['reference'] }}</div>
                                    @endif
                                </td>
                                <td class="px-3 py-3">{{ $event['property'] }}</td>
                                <td class="px-3 py-3"><x-filament::badge>{{ str($event['status'])->headline() }}</x-filament::badge></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-3 py-12 text-center text-gray-500">No scheduled work in this range.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
