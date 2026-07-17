<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Contracts;

/**
 * A gateway that renders its own invoices, and hands back the renderer that does it.
 *
 * Deliberately NOT part of GatewayProvider, and deliberately not a Capability either —
 * the same reasoning as Contracts\RegistersWebhooks. Rendering is an optional
 * sub-mechanism of downloadInvoice(), not an operation of its own: `downloadInvoice`
 * is already gated by Capability::Invoices, and each provider knows how it produces
 * its own invoice. Not implementing this interface IS the declaration that a gateway
 * does not render locally; `instanceof` cannot drift from that the way a second
 * capability flag could.
 *
 * Gateway\ManagesLocalInvoices is the one call site: a gateway that mixes it in but
 * does not implement this interface refuses download/store with
 * UnsupportedOperationException.
 */
interface RendersInvoices
{
    /**
     * The renderer this gateway uses for its local invoices.
     */
    public function invoiceRenderer(): InvoiceRenderer;
}
