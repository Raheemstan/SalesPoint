<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One serial / IMEI, against the sale line the unit went out on.
 *
 * A phone shop is asked "when was this handset sold, and to whom?" months later,
 * which is the whole reason for recording these. So they are rows rather than a
 * comma-separated column on the sale line: one row per unit sold, indexed by
 * serial, with (product_id, serial) unique so the same physical unit cannot be
 * sold twice.
 *
 * Serials are stored normalised (trimmed, upper-cased) - see normalise(). A
 * serial is read off a box or a label, so "  imei-123 " and "IMEI-123" are the
 * same unit; and SQLite compares text case-sensitively while MySQL usually does
 * not, so without normalising, "the same serial twice" would mean different
 * things in development and in production.
 */
class SaleItemSerial extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_item_id',
        'product_id',
        'serial',
    ];

    /** The one way a serial is turned into the form that is stored and compared. */
    public static function normalise(?string $serial): string
    {
        return mb_strtoupper(trim((string) $serial));
    }

    public function saleItem()
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function product()
    {
        // withTrashed: a warranty question outlives the catalogue row.
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /** Find a serial as typed: the needle is normalised the same way the column is. */
    public function scopeMatching($query, ?string $serial)
    {
        return $query->where('serial', static::normalise($serial));
    }

    /** A loose search for the lookup screen, where only part of a serial is known. */
    public function scopeLike($query, ?string $serial)
    {
        return $query->where('serial', 'like', '%' . static::normalise($serial) . '%');
    }

    public function scopeForProduct($query, $productId)
    {
        return $query->where('product_id', $productId);
    }
}
