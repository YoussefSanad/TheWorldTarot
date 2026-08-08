<?php

namespace App\Enums;

/**
 * How an order for a product is completed.
 *
 * Internal, never exposed through the API. A customer sees a product, not the
 * mechanics of how it reaches them.
 *
 * Deliberately separate from ProductType, because the same kind of product can
 * be fulfilled in different ways. The One Card Reading is fulfilled by hand
 * today and becomes AI fulfilled later, with no change to what is being sold.
 * Adding a case needs no migration, since the column is a string.
 */
enum Fulfillment: string
{
    // Written by hand and emailed as a PDF. Every reading today.
    case ManualPdf = 'manual_pdf';

    // Stamps access onto the customer rather than delivering anything.
    case AccessGrant = 'access_grant';
}
