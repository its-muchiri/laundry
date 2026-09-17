<?php

namespace Laundry\Controllers;

use Laundry\Config\Database;
use Laundry\Core\Request;
use Laundry\Core\Response;

/**
 * M-Pesa Daraja + card payment endpoints. This is a per-platform thin
 * wrapper — the actual Daraja client, signature verification, and
 * idempotency handling belong in the shared Payments Core module (see
 * planning/00-portfolio/shared-architecture.md's M-Pesa callback
 * idempotency section) and should be extracted to a shared package once
 * a second platform needs it, rather than duplicated per platform.
 */
final class PaymentController
{
    public function stkPush(Request $request): void
    {
        // TODO: call the Daraja STK Push API using MPESA_* env vars,
        // store a pending `payments` row keyed by CheckoutRequestID.
        Response::json(['status' => 'stk_push_initiated', 'checkout_request_id' => null], 202);
    }

    public function card(Request $request): void
    {
        // TODO: call the configured card gateway (Pesapal/Flutterwave —
        // see .env.example) and record a `payments` row on success.
        Response::json(['status' => 'card_charge_initiated'], 202);
    }

    public function mpesaCallback(Request $request): void
    {
        // TODO: verify the callback per Daraja's contract, and treat a
        // repeated CheckoutRequestID as a no-op success — see
        // shared-architecture.md's "M-Pesa Callback Idempotency" section.
        // Record every inbound callback in a payment_callbacks_log table
        // (see shared-database-schema.md) before processing.
        Response::json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

    public function myEarnings(Request $request): void
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT * FROM payments WHERE user_id = :user_id AND type = \'payout\' ORDER BY created_at DESC'
        );
        $stmt->execute(['user_id' => $request->user['id'] ?? null]);

        Response::json($stmt->fetchAll());
    }
}
