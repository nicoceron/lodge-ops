<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DirectBookingPaymentInstruction extends TenantModel
{
    /** @return BelongsTo<DirectBookingPaymentCapability, $this> */
    public function capability(): BelongsTo
    {
        return $this->belongsTo(DirectBookingPaymentCapability::class, 'direct_booking_payment_capability_id');
    }

    /** @return BelongsTo<DirectBookingPublication, $this> */
    public function publication(): BelongsTo
    {
        return $this->belongsTo(DirectBookingPublication::class);
    }
}
