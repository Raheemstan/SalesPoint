<?php

namespace App\Services;

use App\Mail\ReceiptMail;
use App\Models\AuditLog;
use App\Models\Sale;
use DomainException;
use Illuminate\Support\Facades\Mail;

/**
 * Sending a receipt to the customer instead of printing it.
 *
 * Two routes out, both of which work without an account anywhere:
 *
 *  - email, through whatever mailer the install is configured with;
 *  - WhatsApp, as a wa.me link the browser opens. There is no API key here and
 *    none is needed: wa.me is a plain URL that hands a pre-written message to
 *    whichever WhatsApp the user already has open.
 *
 * Nothing in here formats money by hand - money() does, so a receipt shared
 * from a shop in another country reads in that shop's currency.
 */
class ReceiptShareService
{
    /**
     * Email a receipt.
     *
     * @param  string|null  $to  defaults to the customer's address on the sale
     *
     * @throws DomainException when there is no address to send to
     */
    public function email(Sale $sale, ?string $to = null): string
    {
        $address = $to ?: $sale->customer?->email;

        if (!$address || !filter_var($address, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('This sale has no customer email address, so there is nowhere to send the receipt.');
        }

        $sale->loadMissing(['items.product', 'user', 'customer']);

        $mailable = new ReceiptMail($sale);

        // Queue it when there is a worker to pick it up, send it inline when
        // there is not. Shops running without a queue worker would otherwise
        // see receipts simply never arrive.
        if (config('queue.default') === 'sync') {
            Mail::to($address)->send($mailable);
        } else {
            Mail::to($address)->queue($mailable);
        }

        AuditLog::record('Emailed Receipt', 'sales', $sale->id);

        return $address;
    }

    /**
     * A wa.me link that opens WhatsApp with the receipt already typed.
     *
     * @param  string|null  $phone  defaults to the customer's number on the sale
     */
    public function whatsappUrl(Sale $sale, ?string $phone = null): string
    {
        $number = $this->normalisePhone($phone ?: $sale->customer?->phone);

        // wa.me works without a number too: WhatsApp then asks who to send it
        // to, which is what a walk-in customer needs.
        return 'https://wa.me/' . $number . '?text=' . rawurlencode($this->summary($sale));
    }

    /**
     * The receipt as plain text, for a message body.
     *
     * Deliberately short: a WhatsApp message is read on a phone, and the full
     * itemised receipt is on the printed copy.
     */
    public function summary(Sale $sale): string
    {
        $sale->loadMissing(['items.product']);

        $lines = [];
        $lines[] = setting('store_name', config('app.name'));

        if ($phone = setting('store_phone')) {
            $lines[] = $phone;
        }

        $lines[] = '';
        $lines[] = 'Receipt ' . $sale->invoice_number;
        $lines[] = ($sale->created_at ?? now())->format('d M Y, H:i');
        $lines[] = '';

        foreach ($sale->items as $item) {
            $name = $item->product->name ?? ('Item #' . $item->product_id);
            $lines[] = $item->quantity . ' x ' . $name . '  ' . money($item->total ?? 0);
        }

        $lines[] = '';

        if ((float) $sale->discount_amount > 0) {
            $lines[] = 'Discount: ' . money($sale->discount_amount);
        }

        if ((float) $sale->tax_amount > 0) {
            $lines[] = 'Tax: ' . money($sale->tax_amount);
        }

        $lines[] = 'Total: ' . money($sale->grand_total);
        $lines[] = 'Paid by ' . ucfirst((string) $sale->payment_method);

        if ($footer = setting('receipt_footer')) {
            $lines[] = '';
            $lines[] = $footer;
        }

        return implode("\n", $lines);
    }

    /**
     * Digits only, with the shop's country code put in front of a local number.
     *
     * wa.me needs a full international number and no "+". A shop that leaves
     * `whatsapp_country_code` empty gets whatever was typed, stripped of
     * punctuation, which is right for numbers already stored in full.
     */
    public function normalisePhone(?string $phone): string
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';

        if ($digits === '') {
            return '';
        }

        $code = preg_replace('/\D/', '', (string) setting('whatsapp_country_code', '')) ?? '';

        // A leading 0 is the national trunk prefix: 080... in Nigeria, 07... in
        // the UK. It is dropped when a country code replaces it.
        if ($code !== '' && str_starts_with($digits, '0')) {
            return $code . ltrim($digits, '0');
        }

        if ($code !== '' && !str_starts_with($digits, $code) && strlen($digits) <= 11) {
            return $code . $digits;
        }

        return $digits;
    }
}
