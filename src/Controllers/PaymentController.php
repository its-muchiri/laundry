<?php

namespace Laundry\Controllers;

use Laundry\Config\Database;
use Laundry\Core\Auth;
use Laundry\Core\Escrow;
use Laundry\Core\Mpesa;
use Laundry\Core\Request;
use Laundry\Core\Response;
use PDOException;
use Throwable;

/**
 * M-Pesa Daraja + card payment endpoints. This is a per-platform thin
 * wrapper — the actual Daraja client lives in src/Core/Mpesa.php and the
 * escrow hold/release logic in src/Core/Escrow.php; both should be
 * extracted to a shared package once a second platform needs them,
 * rather than duplicated per platform. See
 * planning/00-portfolio/shared-architecture.md's M-Pesa callback
 * idempotency section for the idempotency contract mpesaCallback()
 * implements.
 */
final class PaymentController
{
    /**
     * Initiates an M-Pesa STK Push for a booking's estimated (or, once
     * confirmed, final) price. Records a pending `payments` row keyed by
     * Daraja's CheckoutRequestID — mpesaCallback() reconciles it once
     * Safaricom calls back.
     */
    public function stkPush(Request $request): void
    {
        $user = Auth::requireUser($request);
        if (!$user) {
            return;
        }

        $bookingId = (int) $request->input('booking_id', 0);
        if ($bookingId <= 0) {
            Response::error('booking_id is required', 422);
            return;
        }

        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM laundry_bookings WHERE id = :id');
        $stmt->execute(['id' => $bookingId]);
        $booking = $stmt->fetch();

        if (!$booking) {
            Response::notFound('Booking not found');
            return;
        }
        if ((int) $booking['customer_id'] !== (int) $user['id']) {
            Response::forbidden('This booking does not belong to you');
            return;
        }
        if ($booking['payment_id'] !== null) {
            $existing = self::fetchPayment($db, (int) $booking['payment_id']);
            if ($existing && $existing['status'] === 'completed') {
                Response::error('This booking has already been paid', 409);
                return;
            }
        }

        $phone = trim((string) $request->input('phone', $user['phone_number'] ?? ''));
        if ($phone === '') {
            Response::error('A phone number is required to send the M-Pesa prompt', 422);
            return;
        }
        $phone = Mpesa::normalizePhone($phone);
        if (!preg_match('/^254(7|1)\d{8}$/', $phone)) {
            Response::error('Enter a valid Kenyan phone number (e.g. 07XXXXXXXX)', 422);
            return;
        }

        $amount = (float) ($booking['final_price'] ?? $booking['estimated_price']);
        if ($amount <= 0) {
            Response::error('This booking has no payable amount', 422);
            return;
        }

        if (!Mpesa::isConfigured()) {
            Response::error(
                'M-Pesa is not configured in this environment (MPESA_* env vars are empty) — '
                    . 'STK Push cannot be sent. See .env.example.',
                503
            );
            return;
        }

        try {
            $daraja = Mpesa::stkPush(
                $phone,
                $amount,
                'BOOKING' . $bookingId,
                'laundry.co.ke booking #' . $bookingId
            );
        } catch (Throwable $e) {
            error_log((string) $e);
            Response::error('Could not reach M-Pesa: ' . $e->getMessage(), 502);
            return;
        }

        $checkoutRequestId = $daraja['CheckoutRequestID'] ?? null;
        if (!$checkoutRequestId) {
            Response::error('M-Pesa did not return a CheckoutRequestID', 502);
            return;
        }

        $db->beginTransaction();
        try {
            $insert = $db->prepare(
                'INSERT INTO payments (user_id, booking_id, type, method, amount, currency, external_reference, status, created_at, updated_at)
                 VALUES (:user_id, :booking_id, \'charge\', \'mpesa_stk\', :amount, \'KES\', :external_reference, \'pending\', NOW(), NOW())'
            );
            $insert->execute([
                'user_id' => $user['id'],
                'booking_id' => $bookingId,
                'amount' => $amount,
                'external_reference' => $checkoutRequestId,
            ]);
            $paymentId = (int) $db->lastInsertId();

            $update = $db->prepare('UPDATE laundry_bookings SET payment_id = :payment_id, updated_at = NOW() WHERE id = :id');
            $update->execute(['payment_id' => $paymentId, 'id' => $bookingId]);

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        Response::json([
            'status' => 'stk_push_initiated',
            'checkout_request_id' => $checkoutRequestId,
            'merchant_request_id' => $daraja['MerchantRequestID'] ?? null,
            'customer_message' => $daraja['CustomerMessage'] ?? 'Enter your M-Pesa PIN on your phone to complete payment.',
        ], 202);
    }

    public function card(Request $request): void
    {
        // Not part of laundry.co.ke's MVP cut (shared-architecture.md notes
        // the card gateway matters most for construction.co.ke/solar.co.ke's
        // higher transaction values) — M-Pesa STK Push is the only payment
        // rail this platform's MVP requires. Returning a real 501 rather
        // than a fake "initiated" 202 so callers don't believe a charge
        // happened.
        Response::error('Card payments are not available on laundry.co.ke yet — pay with M-Pesa.', 501);
    }

    /**
     * M-Pesa STK Push callback receiver. Idempotent per
     * shared-architecture.md: every inbound callback is logged to
     * payment_callbacks_log keyed by CheckoutRequestID (UNIQUE) before any
     * processing; a duplicate delivery hits the unique constraint and is
     * treated as a no-op success rather than reprocessed.
     */
    public function mpesaCallback(Request $request): void
    {
        $payload = $request->body;
        if (empty($payload)) {
            $raw = file_get_contents('php://input') ?: '';
            $decoded = json_decode($raw, true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        $callback = $payload['Body']['stkCallback'] ?? null;
        $checkoutRequestId = $callback['CheckoutRequestID'] ?? null;

        if (!is_array($callback) || !$checkoutRequestId) {
            error_log('M-Pesa callback received with no recognizable stkCallback payload: ' . json_encode($payload));
            Response::json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
            return;
        }

        $db = Database::connection();

        try {
            $logStmt = $db->prepare(
                'INSERT INTO payment_callbacks_log (checkout_request_id, raw_payload, created_at) VALUES (:id, :payload, NOW())'
            );
            $logStmt->execute(['id' => $checkoutRequestId, 'payload' => json_encode($payload)]);
        } catch (PDOException $e) {
            // SQLSTATE 23000 (MySQL duplicate entry) / 23505 (Postgres unique
            // violation) — we've already processed this CheckoutRequestID.
            // Per Daraja's integration guidance, ack success without
            // reprocessing rather than erroring.
            if ($e->getCode() === '23000' || $e->getCode() === '23505') {
                Response::json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
                return;
            }
            throw $e;
        }

        $resultCode = (int) ($callback['ResultCode'] ?? 1);

        $paymentStmt = $db->prepare('SELECT * FROM payments WHERE external_reference = :ref AND method = \'mpesa_stk\' LIMIT 1');
        $paymentStmt->execute(['ref' => $checkoutRequestId]);
        $payment = $paymentStmt->fetch();

        if (!$payment) {
            error_log("M-Pesa callback for unknown CheckoutRequestID {$checkoutRequestId}");
            $this->markCallbackProcessed($db, $checkoutRequestId);
            Response::json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
            return;
        }

        $db->beginTransaction();
        try {
            if ($resultCode === 0) {
                $metadata = self::flattenCallbackMetadata($callback);

                $update = $db->prepare(
                    'UPDATE payments SET status = \'completed\', external_reference = :ref, updated_at = NOW() WHERE id = :id'
                );
                $update->execute([
                    'ref' => (string) ($metadata['MpesaReceiptNumber'] ?? $checkoutRequestId),
                    'id' => $payment['id'],
                ]);

                if ($payment['booking_id']) {
                    Escrow::hold($db, (int) $payment['id'], (int) $payment['booking_id'], (float) $payment['amount']);
                }
            } else {
                $update = $db->prepare('UPDATE payments SET status = \'failed\', updated_at = NOW() WHERE id = :id');
                $update->execute(['id' => $payment['id']]);
            }

            $this->markCallbackProcessed($db, $checkoutRequestId);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            error_log((string) $e);
        }

        // Daraja expects this exact ack envelope regardless of our internal
        // outcome — a non-success ack just makes Safaricom retry delivery.
        Response::json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

    public function myEarnings(Request $request): void
    {
        $user = Auth::requireUser($request);
        if (!$user) {
            return;
        }

        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT * FROM payments WHERE user_id = :user_id AND type IN (\'payout\', \'commission\') ORDER BY created_at DESC'
        );
        $stmt->execute(['user_id' => $user['id']]);

        Response::json($stmt->fetchAll());
    }

    private function markCallbackProcessed(\PDO $db, string $checkoutRequestId): void
    {
        $stmt = $db->prepare('UPDATE payment_callbacks_log SET processed_at = NOW() WHERE checkout_request_id = :id');
        $stmt->execute(['id' => $checkoutRequestId]);
    }

    /** @return array<string,mixed> */
    private static function flattenCallbackMetadata(array $callback): array
    {
        $items = $callback['CallbackMetadata']['Item'] ?? [];
        $flat = [];
        foreach ($items as $item) {
            if (isset($item['Name'])) {
                $flat[$item['Name']] = $item['Value'] ?? null;
            }
        }
        return $flat;
    }

    /** @return array<string,mixed>|null */
    private static function fetchPayment(\PDO $db, int $id): ?array
    {
        $stmt = $db->prepare('SELECT * FROM payments WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }
}
