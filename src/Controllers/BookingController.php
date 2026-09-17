<?php

namespace Laundry\Controllers;

use Laundry\Config\Database;
use Laundry\Core\Request;
use Laundry\Core\Response;

/**
 * Maps to the "Bookings" and "Availability" groups in
 * planning/01-laundry-co-ke/api-endpoints.md. Matching-by-capacity logic
 * (POST /bookings) and the machine_capacity lookup (GET /availability/slots)
 * are stubbed — see planning/00-portfolio/shared-architecture.md's Booking
 * & Availability Engine for what this should eventually call into.
 */
final class BookingController
{
    public function create(Request $request): void
    {
        $db = Database::connection();

        // TODO: resolve nearest available operator via machine_capacity +
        // operator_service_areas (see database/schema.sql), per the
        // matching rules in planning/01-laundry-co-ke/user-flows.md step 6.
        $stmt = $db->prepare(
            'INSERT INTO laundry_bookings
                (customer_id, status, service_tier, pickup_address, pickup_lat, pickup_lng,
                 delivery_address, scheduled_pickup_slot_start, scheduled_pickup_slot_end,
                 estimated_item_count, estimated_weight_kg, special_instructions,
                 estimated_price, payment_timing, created_at, updated_at)
             VALUES (:customer_id, "requested", :service_tier, :pickup_address, :pickup_lat, :pickup_lng,
                 :delivery_address, :slot_start, :slot_end, :item_count, :weight_kg, :instructions,
                 :estimated_price, :payment_timing, NOW(), NOW())'
        );

        $stmt->execute([
            'customer_id' => $request->user['id'] ?? null,
            'service_tier' => $request->input('service_tier'),
            'pickup_address' => $request->input('pickup_address'),
            'pickup_lat' => $request->input('pickup_lat'),
            'pickup_lng' => $request->input('pickup_lng'),
            'delivery_address' => $request->input('delivery_address', $request->input('pickup_address')),
            'slot_start' => $request->input('scheduled_pickup_slot_start'),
            'slot_end' => $request->input('scheduled_pickup_slot_end'),
            'item_count' => $request->input('estimated_item_count'),
            'weight_kg' => $request->input('estimated_weight_kg'),
            'instructions' => $request->input('special_instructions'),
            'estimated_price' => $request->input('estimated_price', 0),
            'payment_timing' => $request->input('payment_timing', 'prepaid'),
        ]);

        Response::json(['id' => (int) $db->lastInsertId(), 'status' => 'requested'], 201);
    }

    public function show(Request $request): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM laundry_bookings WHERE id = :id');
        $stmt->execute(['id' => $request->params['id']]);
        $booking = $stmt->fetch();

        if (!$booking) {
            Response::notFound('Booking not found');
            return;
        }

        Response::json($booking);
    }

    public function index(Request $request): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM laundry_bookings WHERE customer_id = :customer_id ORDER BY created_at DESC');
        $stmt->execute(['customer_id' => $request->user['id'] ?? null]);

        Response::json($stmt->fetchAll());
    }

    public function updateStatus(Request $request): void
    {
        $allowed = ['picked_up', 'washing', 'ready', 'out_for_delivery', 'delivered'];
        $status = $request->input('status');

        if (!in_array($status, $allowed, true)) {
            Response::error('Invalid status transition', 422, ['allowed' => $allowed]);
            return;
        }

        $db = Database::connection();
        $stmt = $db->prepare('UPDATE laundry_bookings SET status = :status, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $request->params['id']]);

        Response::json(['id' => (int) $request->params['id'], 'status' => $status]);
    }

    public function confirmWeight(Request $request): void
    {
        // TODO: recalculate final_price from actual_weight_kg per the
        // operator-pricing model — blocked on the pricing-autonomy open
        // question in planning/01-laundry-co-ke/open-questions.md #3.
        $db = Database::connection();
        $stmt = $db->prepare(
            'UPDATE laundry_bookings SET actual_weight_kg = :weight, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['weight' => $request->input('actual_weight_kg'), 'id' => $request->params['id']]);

        Response::json(['id' => (int) $request->params['id'], 'actual_weight_kg' => $request->input('actual_weight_kg')]);
    }

    public function confirmReceipt(Request $request): void
    {
        // TODO: call into the shared Escrow Engine to release payment to the
        // operator minus commission. See shared-architecture.md.
        $db = Database::connection();
        $stmt = $db->prepare('UPDATE laundry_bookings SET status = "delivered", updated_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $request->params['id']]);

        Response::json(['id' => (int) $request->params['id'], 'status' => 'delivered', 'escrow' => 'release_pending']);
    }

    public function cancel(Request $request): void
    {
        // TODO: apply cancellation-fee policy — see open-questions.md #6.
        $db = Database::connection();
        $stmt = $db->prepare('UPDATE laundry_bookings SET status = "cancelled", updated_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $request->params['id']]);

        Response::json(['id' => (int) $request->params['id'], 'status' => 'cancelled']);
    }

    public function availableSlots(Request $request): void
    {
        // TODO: query machine_capacity joined with operator_service_areas
        // for operators covering $request->query['lat']/['lng'].
        Response::json(['slots' => []]);
    }
}
