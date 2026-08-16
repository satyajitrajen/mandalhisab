<?php

namespace App\Models;

use App\Traits\HasPrefixedId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FestivalBalance extends Model
{
    use HasFactory, HasPrefixedId;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'festival_id',
        'cash_treasurer',
        'cash_collectors',
        'bank',
        'upi',
        'version',
    ];

    protected $casts = [
        'cash_treasurer' => 'decimal:2',
        'cash_collectors' => 'decimal:2',
        'bank' => 'decimal:2',
        'upi' => 'decimal:2',
        'version' => 'integer',
    ];

    public function getIdPrefix(): string
    {
        return 'bal_';
    }

    public function festival(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Festival::class);
    }

    /**
     * Get or create the balance record for a festival.
     */
    public static function forFestival(string $festivalId): self
    {
        return self::firstOrCreate(
            ['festival_id' => $festivalId],
            [
                'cash_treasurer' => 0,
                'cash_collectors' => 0,
                'bank' => 0,
                'upi' => 0,
                'version' => 0,
            ]
        );
    }

    /**
     * Atomically add an amount to a bucket (negative to subtract).
     */
    public function addToBucket(string $bucket, float $amount): void
    {
        $allowed = ['cash_treasurer', 'cash_collectors', 'bank', 'upi'];

        if (! in_array($bucket, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid bucket: {$bucket}");
        }

        $this->increment($bucket, $amount);
    }

    /**
     * Read the current value of a bucket.
     */
    public function getBucket(string $bucket): float
    {
        $allowed = ['cash_treasurer', 'cash_collectors', 'bank', 'upi'];

        if (! in_array($bucket, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid bucket: {$bucket}");
        }

        return (float) $this->{$bucket};
    }
}
