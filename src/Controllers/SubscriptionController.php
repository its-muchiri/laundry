<?php

namespace Laundry\Controllers;

use Laundry\Config\Database;
use Laundry\Core\Request;
use Laundry\Core\Response;

/**
 * Subscription plans — V2 retention mechanic (see
 * planning/01-laundry-co-ke/prd.md and build-sequencing-roadmap.md's V2
 * milestone for this platform). Plan catalog is hardcoded here as a
 * starting point; move to a `subscription_plans` table if plans need to
 * be admin-configurable rather than fixed at deploy time.
 */
final class SubscriptionController
{
    private const PLANS = [
        ['id' => 'fixed_4', 'plan_type' => 'monthly_fixed_count', 'pickups_included' => 4, 'monthly_price' => 1500],
        ['id' => 'fixed_8', 'plan_type' => 'monthly_fixed_count', 'pickups_included' => 8, 'monthly_price' => 2800],
        ['id' => 'unlimited_20kg', 'plan_type' => 'monthly_unlimited_capped', 'weight_cap_kg' => 20, 'monthly_price' => 3500],
    ];

    public function listPlans(Request $request): void
    {
        Response::json(self::PLANS);
    }

    public function subscribe(Request $request): void
    {
        $planId = $request->input('plan_id');
        $plan = null;
        foreach (self::PLANS as $candidate) {
            if ($candidate['id'] === $planId) {
                $plan = $candidate;
                break;
            }
        }

        if (!$plan) {
            Response::error('Unknown plan_id', 422, ['known_plans' => array_column(self::PLANS, 'id')]);
            return;
        }

        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO subscriptions
                (customer_id, plan_type, pickups_included, weight_cap_kg, monthly_price, status,
                 current_cycle_start, current_cycle_end, created_at)
             VALUES (:customer_id, :plan_type, :pickups_included, :weight_cap_kg, :price, \'active\',
                 CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 MONTH), NOW())'
        );
        $stmt->execute([
            'customer_id' => $request->user['id'] ?? null,
            'plan_type' => $plan['plan_type'],
            'pickups_included' => $plan['pickups_included'] ?? null,
            'weight_cap_kg' => $plan['weight_cap_kg'] ?? null,
            'price' => $plan['monthly_price'],
        ]);

        Response::json(['id' => (int) $db->lastInsertId(), 'status' => 'active'], 201);
    }

    public function myStatus(Request $request): void
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT * FROM subscriptions WHERE customer_id = :customer_id AND status = \'active\' ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute(['customer_id' => $request->user['id'] ?? null]);
        $subscription = $stmt->fetch();

        if (!$subscription) {
            Response::json(null);
            return;
        }

        Response::json($subscription);
    }

    public function cancel(Request $request): void
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'UPDATE subscriptions SET status = \'cancelled\' WHERE customer_id = :customer_id AND status = \'active\''
        );
        $stmt->execute(['customer_id' => $request->user['id'] ?? null]);

        Response::json(['status' => 'cancelled']);
    }
}
