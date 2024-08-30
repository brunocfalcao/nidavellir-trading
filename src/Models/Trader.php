<?php

namespace Nidavellir\Trading\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Nidavellir\Trading\Concerns\Models\HasTraderFeatures;

class Trader extends Authenticatable
{
    use HasFactory,
        HasTraderFeatures,
        Notifiable,
        SoftDeletes;

    protected $guarded = [];

    protected $hidden = [
        'password',
        'remember_token',
        'binance_api_key',
        'binance_secret_key'
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'binance_api_key' => 'encrypted',
            'binance_secret_key' => 'encrypted',
        ];
    }

    public function positions()
    {
        return $this->hasMany(Position::class);
    }

    public function exchange()
    {
        return $this->belongsTo(Exchange::class);
    }

    public function canBeDeleted()
    {
        return true;
    }
}
