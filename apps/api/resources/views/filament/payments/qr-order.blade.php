<div class="mx-auto flex max-w-md flex-col items-center gap-4 text-center" data-testid="active-order-qr">
    <img
        src="{{ $qrImage }}"
        alt="Mercado Pago payment QR"
        class="h-auto w-full max-w-80 rounded-xl bg-white p-3"
    >
    <div>
        <p class="text-lg font-semibold">{{ $currency }} {{ number_format($amountMinor / 100, 2) }}</p>
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Expires {{ $expiresAt?->timezone(config('app.timezone'))->format('M j, Y · H:i') ?? 'when Mercado Pago closes the order' }}.
            Money is posted only after signed notification and authoritative lookup.
        </p>
    </div>
</div>
