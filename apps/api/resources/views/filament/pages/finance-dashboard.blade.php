<x-filament-panels::page>
    @php($money = app(\App\Services\MoneyFormatter::class))
    <div class="space-y-6">
        <x-filament::section heading="Reporting period" description="Choose the property-local date range and the currency used only for the explicitly rated consolidated view.">
            <div class="grid gap-4 md:grid-cols-3">
                <label class="grid gap-2 text-sm font-medium">
                    <span>From</span>
                    <input type="date" wire:model.live.debounce.400ms="start" class="rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-white/10 dark:bg-white/5" />
                </label>
                <label class="grid gap-2 text-sm font-medium">
                    <span>Through</span>
                    <input type="date" wire:model.live.debounce.400ms="end" class="rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-white/10 dark:bg-white/5" />
                </label>
                <label class="grid gap-2 text-sm font-medium">
                    <span>Reporting currency</span>
                    <select wire:model.live="displayCurrency" class="rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-white/10 dark:bg-white/5">
                        @foreach ($currencyOptions as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
            <p class="mt-4 text-sm text-gray-500">{{ $range['start'] }} through {{ $range['end'] }} · {{ $timezone }}</p>
        </x-filament::section>

        <x-filament::section heading="{{ $period }} · native {{ $currency }}" description="Tenant-native figures remain separate from other currencies and are never silently converted.">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ([
                    'Booked revenue' => $summary['booked'],
                    'Cash collected' => $summary['collected'],
                    'Receivables' => $summary['receivables'],
                    'Loaded costs' => $summary['costs'],
                    'Commission accruals' => $summary['commissions'],
                    'Gross margin' => $summary['margin'],
                ] as $label => $amount)
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                        <div class="text-sm text-gray-500">{{ $label }}</div>
                        <div class="mt-1 text-2xl font-bold">{{ $money->formatMinor($amount, $currency, $locale) }}</div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        <div class="grid gap-6 xl:grid-cols-2">
            <x-filament::section heading="Native currency totals" description="Auditable source totals before any FX policy is applied.">
                <div class="space-y-3">
                    @forelse ($rawTotals as $rawCurrency => $totals)
                        <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                            <div class="font-semibold">{{ $rawCurrency }}</div>
                            <dl class="mt-2 grid grid-cols-2 gap-2 text-sm">
                                <div><dt class="text-gray-500">Booked</dt><dd>{{ $money->formatMinor($totals['booked_revenue_minor'], $rawCurrency, $locale) }}</dd></div>
                                <div><dt class="text-gray-500">Collected</dt><dd>{{ $money->formatMinor($totals['cash_collected_minor'], $rawCurrency, $locale) }}</dd></div>
                                <div><dt class="text-gray-500">Costs</dt><dd>{{ $money->formatMinor($totals['loaded_costs_minor'], $rawCurrency, $locale) }}</dd></div>
                                <div><dt class="text-gray-500">Margin</dt><dd>{{ $money->formatMinor($totals['margin_minor'], $rawCurrency, $locale) }}</dd></div>
                            </dl>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">No financial activity in this range.</p>
                    @endforelse
                </div>
            </x-filament::section>

            <x-filament::section heading="Consolidated {{ $displayCurrency }}" description="Every non-native amount requires an effective-dated rate snapshot. Missing rates suppress the consolidated totals rather than guessing.">
                @if ($conversion['complete'])
                    <div class="grid grid-cols-2 gap-4">
                        @foreach (['Booked' => 'booked_revenue_minor', 'Collected' => 'cash_collected_minor', 'Costs' => 'loaded_costs_minor', 'Margin' => 'margin_minor'] as $label => $metric)
                            <div><div class="text-sm text-gray-500">{{ $label }}</div><div class="text-xl font-bold">{{ $money->formatMinor($consolidatedTotals[$metric], $displayCurrency, $locale) }}</div></div>
                        @endforeach
                    </div>
                    <p class="mt-4 text-xs text-gray-500">{{ count($conversion['rates']) }} effective rate snapshot(s) applied.</p>
                @else
                    <x-filament::badge color="warning">Consolidation unavailable</x-filament::badge>
                    <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">Add the missing effective-dated rate book entries. Native totals above remain authoritative.</p>
                    <ul class="mt-3 space-y-1 text-sm text-gray-500">
                        @foreach ($conversion['missing_rates'] as $missing)
                            <li>{{ $missing['from_currency'] }} → {{ $missing['to_currency'] }} · {{ $missing['effective_at'] }}</li>
                        @endforeach
                    </ul>
                @endif
            </x-filament::section>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <x-filament::section heading="Deposits">
                <div class="grid grid-cols-2 gap-4">
                    <div><div class="text-sm text-gray-500">Due</div><div class="text-3xl font-bold">{{ $deposits['due'] }}</div></div>
                    <div><div class="text-sm text-gray-500">Overdue</div><div class="text-3xl font-bold {{ $deposits['overdue'] ? 'text-danger-600' : '' }}">{{ $deposits['overdue'] }}</div></div>
                </div>
            </x-filament::section>
            <x-filament::section heading="Collection rate">
                @php($collection = $summary['booked'] > 0 ? round(($summary['collected'] / $summary['booked']) * 100, 1) : 0)
                <div class="text-3xl font-bold">{{ $collection }}%</div>
                <div class="mt-2 text-sm text-gray-500">Cash processed this month versus booked arrivals this month.</div>
            </x-filament::section>
        </div>

        <x-filament::section heading="Revenue trend" description="Booked native-currency revenue for arrivals across the trailing seven months.">
            @php($peakRevenue = max(1, collect($finance['revenue_series'])->max('value_minor')))
            <div class="grid gap-3 md:grid-cols-4 xl:grid-cols-7">
                @foreach ($finance['revenue_series'] as $month)
                    <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                        <div class="text-xs text-gray-500">{{ $month['label'] }}</div>
                        <div class="mt-1 font-semibold">{{ number_format($month['value_minor'] / 100, 0) }}</div>
                        <progress class="mt-2 h-2 w-full accent-primary-500" max="{{ $peakRevenue }}" value="{{ $month['value_minor'] }}">{{ $month['value_minor'] }}</progress>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        <div class="grid gap-6 xl:grid-cols-2">
            <x-filament::section heading="Program performance" description="Revenue less loaded costs and commission accruals.">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-white/10"><tr><th class="py-3">Program</th><th class="py-3 text-right">Bookings</th><th class="py-3 text-right">Revenue</th><th class="py-3 text-right">Margin</th></tr></thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @forelse ($finance['programs'] as $program)
                                <tr><td class="py-3 font-medium">{{ $program['program'] }}</td><td class="py-3 text-right">{{ $program['bookings'] }}</td><td class="py-3 text-right">{{ number_format($program['revenue_minor'] / 100, 2) }}</td><td class="py-3 text-right font-semibold">{{ number_format($program['margin_minor'] / 100, 2) }}</td></tr>
                            @empty
                                <tr><td colspan="4" class="py-8 text-center text-gray-500">No program revenue in this period.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>

            <x-filament::section heading="Channel performance" description="Collection and commission economics by reservation source.">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-white/10"><tr><th class="py-3">Channel</th><th class="py-3 text-right">Bookings</th><th class="py-3 text-right">Net revenue</th><th class="py-3 text-right">Collected</th></tr></thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @forelse ($finance['channels'] as $channel)
                                <tr><td class="py-3 font-medium">{{ $channel['channel'] }}</td><td class="py-3 text-right">{{ $channel['bookings'] }}</td><td class="py-3 text-right">{{ number_format($channel['net_revenue_minor'] / 100, 2) }}</td><td class="py-3 text-right">{{ $channel['collection_percent'] }}%</td></tr>
                            @empty
                                <tr><td colspan="4" class="py-8 text-center text-gray-500">No channel revenue in this period.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        </div>

        <x-filament::section heading="Reconciliation">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div class="font-semibold">{{ $finance['reconciliation']['is_balanced'] ? 'Reconciliation balanced' : 'Reconciliation requires review' }}</div>
                    <div class="text-sm text-gray-500">Booked revenue − loaded costs − commission accruals, in {{ $currency }} only.</div>
                </div>
                <x-filament::badge :color="$finance['reconciliation']['is_balanced'] ? 'success' : 'danger'">
                    Difference {{ number_format($finance['reconciliation']['difference_minor'] / 100, 2) }}
                </x-filament::badge>
            </div>
        </x-filament::section>

        <x-filament::section heading="Recent folios">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-white/10"><tr><th class="px-3 py-3">Reservation</th><th class="px-3 py-3">Property</th><th class="px-3 py-3">Total</th><th class="px-3 py-3">Collected</th><th class="px-3 py-3">Balance</th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @forelse ($reservations as $reservation)
                            @php($paid = (int) $reservation->payments->where('status', \App\Enums\PaymentStatus::Succeeded)->where('currency', $currency)->sum('amount_minor'))
                            <tr>
                                <td class="px-3 py-3 font-semibold">
                                    @if (\App\Filament\Resources\Reservations\ReservationResource::canView($reservation))
                                        <a class="text-primary-600 hover:underline dark:text-primary-400" href="{{ \App\Filament\Resources\Reservations\ReservationResource::getUrl('view', ['record' => $reservation]) }}" wire:navigate>{{ $reservation->confirmation_number }}</a>
                                    @else
                                        {{ $reservation->confirmation_number }}
                                    @endif
                                </td>
                                <td class="px-3 py-3">{{ $reservation->property->name }}</td>
                                <td class="px-3 py-3">{{ number_format($reservation->total_minor / 100, 2) }}</td>
                                <td class="px-3 py-3">{{ number_format($paid / 100, 2) }}</td>
                                <td class="px-3 py-3">{{ number_format(max(0, $reservation->total_minor - $paid) / 100, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-3 py-10 text-center text-gray-500">No bookable stays begin this month.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
