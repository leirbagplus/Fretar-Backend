<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'payment_id';

    protected $fillable = [
        'shipping_id',
        'payment_method_id',
        'stripe_payment_intent_id',
        'value',
        'payment_status',
        'paid_at',
    ];

    protected $hidden = [
        'stripe_payment_intent_id',
    ];

    protected function casts(): array
    {
        return [
            'payment_status' => PaymentStatus::class,
            'value' => 'decimal:2',
            'created_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function shipping(): BelongsTo
    {
        return $this->belongsTo(Shipping::class, 'shipping_id', 'shipping_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id', 'payment_method_id');
    }
}