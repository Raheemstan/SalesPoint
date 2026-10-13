<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    /** @use HasFactory<\Database\Factories\SupplierFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'phone',
        'email',
        'address',
        'is_active',
        'opening_balance',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function purchases()
    {
        return $this->hasMany(Purchase::class);
    }

    public function payments()
    {
        return $this->hasMany(SupplierPayment::class)->latest('paid_at');
    }

    public function returns()
    {
        return $this->hasMany(PurchaseReturn::class)->latest();
    }

    /** Items we normally buy from this supplier (drives the reorder list). */
    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Can this supplier be deleted outright? Once there is a purchase against
     * them the history has to stay, so they are deactivated instead.
     */
    public function isDeletable(): bool
    {
        return !$this->purchases()->withTrashed()->exists();
    }
}
