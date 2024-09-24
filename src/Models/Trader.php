<?php

namespace Nidavellir\Trading\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Nidavellir\Trading\Concerns\Models\HasTraderFeatures;

/**
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string $remember_token
 * @property string $previous_logged_in_at
 * @property string $last_logged_in_at
 * @property int $api_system_id
 * @property string $binance_api_key
 * @property string $binance_secret_key
 */
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
        'binance_secret_key',
    ];

    protected $casts = [
        'binance_api_key' => 'encrypted',
        'binance_secret_key' => 'encrypted',
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

    public function apiRequestLogs()
    {
        return $this->morphMany(ApiRequestsLog::class, 'loggable');
    }

    public function positions()
    {
        return $this->hasMany(Position::class);
    }

    public function exchange()
    {
        return $this->belongsTo(ApiSystem::class, 'api_system_id');
    }

    public function canBeDeleted()
    {
        return true;
    }
}
