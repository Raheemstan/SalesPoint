<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    /** @use HasFactory<\Database\Factories\ExpenseFactory> */
    use HasFactory;


    protected $fillable = [
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
