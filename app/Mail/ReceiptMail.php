<?php

namespace App\Mail;

use App\Models\Sale;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A receipt, emailed to the customer.
 *
 * Queueable rather than ShouldQueue: ReceiptShareService decides whether to
 * queue based on whether this install actually has a worker running, and a
 * Mailable that always queues would silently swallow receipts on the shops
 * that do not.
 */
class ReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Sale $sale)
    {
    }

    public function envelope(): Envelope
    {
        $shop = setting('store_name') ?: config('app.name');

        return new Envelope(
            subject: $shop . ' - receipt ' . $this->sale->invoice_number,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.receipt',
            with: [
                'sale' => $this->sale->loadMissing(['items.product', 'customer']),
                'shop' => [
                    'name' => setting('store_name') ?: config('app.name'),
                    'address' => setting('store_address'),
                    'phone' => setting('store_phone'),
                    'email' => setting('store_email'),
                    'footer' => setting('receipt_footer'),
                    'taxNumber' => setting('receipt_show_tax_number') === '1' ? setting('receipt_tax_number') : null,
                ],
            ],
        );
    }
}
