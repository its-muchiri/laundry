<?php

namespace Laundry\Controllers;

use Laundry\Config\Database;
use Laundry\Core\Request;
use Laundry\Core\Response;

/**
 * Loyalty points — V2 retention mechanic (see planning/01-laundry-co-ke/prd.md).
 * Points are earned via loyalty_points_ledger inserts elsewhere (e.g., on
 * booking completion — not yet wired into BookingController::confirmReceipt,
 * left as a TODO there to avoid guessing an earn rate that should be a
 * product decision).
 */
final class LoyaltyController
{
    public function balance(Request $request): void
    {
        $db = Database::connection();
        $customerId = $request->user['id'] ?? null;

        $stmt = $db->prepare('SELECT COALESCE(SUM(points_change), 0) AS balance FROM loyalty_points_ledger WHERE customer_id = :customer_id');
        $stmt->execute(['customer_id' => $customerId]);
        $balance = (int) $stmt->fetchColumn();

        $historyStmt = $db->prepare('SELECT * FROM loyalty_points_ledger WHERE customer_id = :customer_id ORDER BY created_at DESC LIMIT 50');
        $historyStmt->execute(['customer_id' => $customerId]);

        Response::json(['balance' => $balance, 'history' => $historyStmt->fetchAll()]);
    }

    public function redeem(Request $request): void
    {
        $db = Database::connection();
        $customerId = $request->user['id'] ?? null;
        $pointsToRedeem = (int) $request->input('points');

        $stmt = $db->prepare('SELECT COALESCE(SUM(points_change), 0) FROM loyalty_points_ledger WHERE customer_id = :customer_id');
        $stmt->execute(['customer_id' => $customerId]);
        $balance = (int) $stmt->fetchColumn();

        if ($pointsToRedeem <= 0 || $pointsToRedeem > $balance) {
            Response::error('Invalid redemption amount', 422, ['current_balance' => $balance]);
            return;
        }

        $stmt = $db->prepare(
            'INSERT INTO loyalty_points_ledger (customer_id, booking_id, points_change, reason, created_at)
             VALUES (:customer_id, :booking_id, :points, "redeemed_for_discount", NOW())'
        );
        $stmt->execute([
            'customer_id' => $customerId,
            'booking_id' => $request->input('booking_id'),
            'points' => -$pointsToRedeem,
        ]);

        Response::json(['redeemed' => $pointsToRedeem, 'new_balance' => $balance - $pointsToRedeem]);
    }
}
