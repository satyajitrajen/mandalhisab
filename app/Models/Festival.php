<?php

namespace App\Models;

use App\Enums\FestivalStatus;
use App\Traits\HasPrefixedId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Festival extends Model
{
    use HasFactory, HasPrefixedId;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'mandal_id',
        'name',
        'year',
        'start_date',
        'end_date',
        'status',
        'budget_goal',
        'description',
        'opening_balance',
    ];

    protected $casts = [
        'status' => FestivalStatus::class,
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'budget_goal' => 'decimal:2',
        'opening_balance' => 'decimal:2',
        'year' => 'integer',
    ];

    public function getIdPrefix(): string
    {
        return 'fst_';
    }

    public function mandal(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Mandal::class);
    }

    public function varganiEntries(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(VarganiEntry::class);
    }

    public function expenseEntries(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ExpenseEntry::class);
    }

    public function receiptBooks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ReceiptBook::class);
    }

    public function cashHandovers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CashHandover::class);
    }

    public function bankAccounts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BankAccount::class);
    }

    public function fundTransfers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FundTransfer::class);
    }

    public function otherIncomes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OtherIncome::class);
    }

    public function moneyTrailEntries(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MoneyTrailEntry::class);
    }

    public function finalHisabAudits(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FinalHisabAudit::class);
    }
}
