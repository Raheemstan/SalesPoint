<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    /** @use HasFactory<\Database\Factories\ExpenseFactory> */
    use BelongsToBranch, HasFactory, SoftDeletes;


    protected $fillable = [
        'name',
        'user_id',
        'category',
        'description',
        'amount',
        'expense_date'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
