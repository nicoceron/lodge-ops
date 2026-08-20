<div class="space-y-6 text-sm">
    <section>
        <h3 class="font-semibold">Immutable quote history</h3>
        <div class="mt-2 space-y-5">
            @foreach ($history['quote_history'] as $quote)
                <div class="space-y-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <div class="font-medium">Quote {{ $quote['quote_id'] }} · {{ $quote['status'] }}</div>
                    <div>{{ $quote['currency'] }} subtotal {{ $quote['subtotal_minor'] }} · discount {{ $quote['discount_minor'] }} · tax {{ $quote['tax_minor'] }} · total {{ $quote['total_minor'] }}</div>
                    @foreach ($quote['lines'] as $line)
                        <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                            <div class="font-medium">{{ $line['description'] }} · {{ $line['type'] }}</div>
                            <div>Basis: {{ $line['basis'] }} · quantity: {{ $line['quantity_thousandths'] }} thousandths · unit: {{ $line['unit_amount_minor'] }}</div>
                            <div>Net {{ $line['net_amount_minor'] }} · tax {{ $line['tax_amount_minor'] }} · gross {{ $line['gross_amount_minor'] }}</div>
                            <div>Running total {{ $line['pre_total_minor'] }} → {{ $line['post_total_minor'] }} · rounding {{ $line['rounding_mode'] }}</div>
                            <div>{{ $line['explanation'] }}</div>
                            @if (data_get($line, 'rule_facts.rate_rule_id'))
                                <div>Rate rule {{ data_get($line, 'rule_facts.rate_rule_id') }} · version {{ data_get($line, 'rule_facts.rate_rule_version') }} · plan version {{ data_get($line, 'rule_facts.rate_plan_version') }}</div>
                            @elseif (data_get($line, 'rule_facts.tax_rule_id'))
                                <div>Tax input {{ data_get($line, 'rule_facts.tax_rule_id') }} · version {{ data_get($line, 'rule_facts.tax_rule_version') }}</div>
                            @elseif (data_get($line, 'rule_facts.promotion_id'))
                                <div>Promotion {{ data_get($line, 'rule_facts.promotion_id') }} · version {{ data_get($line, 'rule_facts.promotion_version') }}</div>
                            @endif
                        </div>
                    @endforeach
                    <div class="font-medium">Deposit and cancellation facts</div>
                    <pre class="whitespace-pre-wrap rounded-lg border border-gray-200 p-3 text-xs dark:border-gray-700">{{ json_encode(['deposit' => $quote['deposit_policy'], 'cancellation' => $quote['cancellation_policy']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                </div>
            @endforeach
        </div>
    </section>

    <section>
        <h3 class="font-semibold">Promotion usage history</h3>
        <div class="mt-2 space-y-3">
            @forelse ($history['promotion_usage_history'] as $usage)
                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <div class="font-medium">{{ $usage['promotion_name'] }} · version {{ $usage['promotion_version'] }} · {{ $usage['state'] }}</div>
                    <div>{{ $usage['currency'] }} {{ $usage['discount_minor'] }} · quote {{ $usage['booking_quote_id'] }}</div>
                    @foreach ($usage['events'] as $event)
                        <div>{{ $event['occurred_at'] }} · {{ $event['type'] }} · {{ json_encode($event['facts'], JSON_UNESCAPED_SLASHES) }}</div>
                    @endforeach
                </div>
            @empty
                <div>No promotion usage facts.</div>
            @endforelse
        </div>
    </section>

    <section>
        <h3 class="font-semibold">Voucher redemption history</h3>
        <div class="mt-2 space-y-3">
            @forelse ($history['voucher_redemption_history'] as $redemption)
                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <div class="font-medium">{{ $redemption['voucher_label'] }} · promotion version {{ $redemption['promotion_version'] }} · {{ $redemption['state'] }}</div>
                    <div>{{ $redemption['currency'] }} {{ $redemption['discount_minor'] }} · quote {{ $redemption['booking_quote_id'] }}</div>
                    @foreach ($redemption['events'] as $event)
                        <div>{{ $event['occurred_at'] }} · {{ $event['type'] }} · {{ $event['policy_reason'] }} · {{ json_encode($event['facts'], JSON_UNESCAPED_SLASHES) }}</div>
                    @endforeach
                </div>
            @empty
                <div>No voucher redemption facts.</div>
            @endforelse
        </div>
    </section>
</div>
