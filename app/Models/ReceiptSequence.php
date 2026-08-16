<?php

namespace App\Models;

use App\Traits\HasPrefixedId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ReceiptSequence extends Model
{
    use HasFactory, HasPrefixedId;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'festival_id',
        'next_number',
    ];

    protected $casts = [
        'next_number' => 'integer',
    ];

    public function getIdPrefix(): string
    {
        return 'seq_';
    }

    public function festival(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Festival::class);
    }

    /**
     * Atomically reserve and return the next receipt number for a festival.
     * Must be called inside a transaction (the caller is responsible).
     */
    public static function nextForFestival(string $festivalId): int
    {
        $sequence = self::lockForUpdate()
            ->where('festival_id', $festivalId)
            ->first();

        if (! $sequence) {
            $sequence = self::create([
                'festival_id' => $festivalId,
                'next_number' => 1,
            ]);
        }

        $number = (int) $sequence->next_number;
        $sequence->increment('next_number');

        return $number;
    }
}
