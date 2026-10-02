<?php

namespace App\Models;

use App\Traits\HasPrefixedId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
        // Make sure the row exists before locking it: lockForUpdate() can't lock
        // a missing row, so two concurrent first receipts would otherwise both
        // try to create it. insertOrIgnore is a no-op once the row exists
        // (unique festival_id).
        if (! self::where('festival_id', $festivalId)->exists()) {
            self::insertOrIgnore([
                'id' => (new self)->getIdPrefix().Str::random(12),
                'festival_id' => $festivalId,
                'next_number' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $sequence = self::lockForUpdate()
            ->where('festival_id', $festivalId)
            ->firstOrFail();

        $number = (int) $sequence->next_number;
        $sequence->increment('next_number');

        return $number;
    }
}
