<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\RejectsSensitivePaymentData;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

abstract class TenantModel extends Model
{
    use BelongsToTenant, HasFactory, HasUuid, RejectsSensitivePaymentData;

    protected $guarded = ['id', 'tenant_id'];
}
