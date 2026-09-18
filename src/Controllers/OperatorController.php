<?php

namespace Laundry\Controllers;

use Laundry\Config\Database;
use Laundry\Core\Auth;
use Laundry\Core\Request;
use Laundry\Core\Response;

/**
 * Operator capacity and service-area management, plus the public operator
 * profile — maps to the remaining "Availability" and "Platform-Specific
 * Resources" entries in planning/01-laundry-co-ke/api-endpoints.md that
 * were missed in the initial scaffold pass. Without this, machine_capacity
 * has no way to be populated, so BookingController::availableSlots would
 * always return empty — this closes that gap.
 */
final class OperatorController
{
    public function setCapacity(Request $request): void
    {
        $user = Auth::requireUser($request);
        if (!$user) {
            return;
        }

        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO machine_capacity (operator_id, date, slot_start, slot_end, max_kg_capacity, max_orders, is_active)
             VALUES (:operator_id, :date, :slot_start, :slot_end, :max_kg, :max_orders, true)'
        );
        $stmt->execute([
            'operator_id' => $user['id'],
            'date' => $request->input('date'),
            'slot_start' => $request->input('slot_start'),
            'slot_end' => $request->input('slot_end'),
            'max_kg' => $request->input('max_kg_capacity'),
            'max_orders' => $request->input('max_orders'),
        ]);

        Response::json(['id' => (int) $db->lastInsertId()], 201);
    }

    public function getCapacity(Request $request): void
    {
        $user = Auth::requireUser($request);
        if (!$user) {
            return;
        }

        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT * FROM machine_capacity WHERE operator_id = :operator_id AND date >= CURRENT_DATE ORDER BY date, slot_start'
        );
        $stmt->execute(['operator_id' => $user['id']]);

        Response::json($stmt->fetchAll());
    }

    public function setServiceArea(Request $request): void
    {
        $user = Auth::requireUser($request);
        if (!$user) {
            return;
        }

        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO operator_service_areas (operator_id, area_type, center_lat, center_lng, radius_km, neighborhood_names)
             VALUES (:operator_id, :area_type, :center_lat, :center_lng, :radius_km, :neighborhoods)'
        );
        $stmt->execute([
            'operator_id' => $user['id'],
            'area_type' => $request->input('area_type'),
            'center_lat' => $request->input('center_lat'),
            'center_lng' => $request->input('center_lng'),
            'radius_km' => $request->input('radius_km'),
            'neighborhoods' => json_encode($request->input('neighborhood_names', [])),
        ]);

        Response::json(['id' => (int) $db->lastInsertId()], 201);
    }

    public function profile(Request $request): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT id, full_name, status FROM users WHERE id = :id AND account_type = \'provider\'');
        $stmt->execute(['id' => $request->params['id']]);
        $operator = $stmt->fetch();

        if (!$operator) {
            Response::notFound('Operator not found');
            return;
        }

        $qualityStmt = $db->prepare('SELECT * FROM operator_quality_scores WHERE operator_id = :id');
        $qualityStmt->execute(['id' => $request->params['id']]);
        $operator['quality'] = $qualityStmt->fetch() ?: null;

        Response::json($operator);
    }

    public function prefer(Request $request): void
    {
        $user = Auth::requireUser($request);
        if (!$user) {
            return;
        }

        $db = Database::connection();
        // See Database::driver() and planning/00-portfolio/ui-implementation-plan.md
        // for why this branches (Vercel's Marketplace has no MySQL-compatible database).
        $sql = Database::driver() === 'pgsql'
            ? 'INSERT INTO preferred_operators (customer_id, operator_id, created_at) VALUES (:customer_id, :operator_id, NOW())
               ON CONFLICT (customer_id, operator_id) DO NOTHING'
            : 'INSERT INTO preferred_operators (customer_id, operator_id, created_at) VALUES (:customer_id, :operator_id, NOW())
               ON DUPLICATE KEY UPDATE created_at = created_at';
        $stmt = $db->prepare($sql);
        $stmt->execute(['customer_id' => $user['id'], 'operator_id' => $request->params['id']]);

        Response::json(['status' => 'preferred']);
    }
}
