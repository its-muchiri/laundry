<?php

namespace Laundry\Controllers;

use DateTimeImmutable;
use Laundry\Config\Database;
use Laundry\Core\Auth;
use Laundry\Core\Request;
use Laundry\Core\Response;
use PDO;
use Throwable;

/**
 * Maps to the "Bookings" and "Availability" groups in
 * planning/01-laundry-co-ke/api-endpoints.md. Implements the matching
 * rules from planning/01-laundry-co-ke/user-flows.md step 6 and
 * database-schema.md's machine_capacity/operator_service_areas tables:
 * nearest operator (by service-area radius) with capacity for the
 * requested slot, excluding suspended operators. MVP simplification
 * (documented per MVP_STATUS.md's "single-operator matching" scope): the
 * match is synchronous at booking-creation time rather than an async
 * offer/accept/decline window — there is no operator app to accept/decline
 * from yet, so the nearest-with-capacity operator is auto-assigned.
 */
final class BookingController
{
    /**
     * KES per garment by service tier — a platform-set fixed price card.
     * Resolves open-questions.md #3 (operator pricing autonomy) pragmatically
     * for MVP: operators have no pricing-setting UI yet, so the platform
     * quotes a flat per-item rate. Revisit once operator pricing autonomy
     * is decided.
     */
    private const SERVICE_TIER_RATES = [
        'wash_fold' => 150,
        'dry_clean' => 300,
        'express' => 250,
    ];

    public function create(Request $request): void
    {
        $user = Auth::requireUser($request);
        if (!$user) {
            return;
        }

        $serviceTier = (string) $request->input('service_tier', '');
        if (!isset(self::SERVICE_TIER_RATES[$serviceTier])) {
            Response::error('Invalid service_tier', 422, ['allowed' => array_keys(self::SERVICE_TIER_RATES)]);
            return;
        }

        $pickupAddress = trim((string) $request->input('pickup_address', ''));
        if ($pickupAddress === '') {
            Response::error('pickup_address is required', 422);
            return;
        }

        $lat = $request->input('pickup_lat');
        $lng = $request->input('pickup_lng');
        if (!is_numeric($lat) || !is_numeric($lng)) {
            Response::error(
                'pickup_lat and pickup_lng are required — share your location so we can match you to a nearby operator',
                422
            );
            return;
        }
        $lat = (float) $lat;
        $lng = (float) $lng;

        $slotStart = self::parseDateTime($request->input('scheduled_pickup_slot_start'));
        $slotEnd = self::parseDateTime($request->input('scheduled_pickup_slot_end'));
        if ($slotStart === null || $slotEnd === null || $slotEnd <= $slotStart) {
            Response::error('A valid pickup slot (start before end) is required', 422);
            return;
        }

        $itemCount = max(1, (int) $request->input('estimated_item_count', 5));
        $weightInput = $request->input('estimated_weight_kg');
        // 0.4kg/garment is a rough household-laundry average, used only when
        // the customer hasn't given an explicit weight estimate — the real
        // weight is recorded by the operator at pickup (confirmWeight()).
        $weightKg = is_numeric($weightInput) && (float) $weightInput > 0
            ? (float) $weightInput
            : round($itemCount * 0.4, 2);
        $estimatedPrice = self::SERVICE_TIER_RATES[$serviceTier] * $itemCount;

        $paymentTiming = $request->input('payment_timing', 'prepaid');
        $paymentTiming = in_array($paymentTiming, ['prepaid', 'pay_on_delivery'], true) ? $paymentTiming : 'prepaid';

        $deliveryAddress = trim((string) $request->input('delivery_address', '')) ?: $pickupAddress;

        $db = Database::connection();
        $db->beginTransaction();

        try {
            $match = $this->matchOperator(
                $db,
                $lat,
                $lng,
                $slotStart->format('Y-m-d'),
                $slotStart->format('H:i:s'),
                $slotEnd->format('H:i:s'),
                $weightKg
            );

            $stmt = $db->prepare(
                'INSERT INTO laundry_bookings
                    (customer_id, operator_id, status, service_tier, pickup_address, pickup_lat, pickup_lng,
                     delivery_address, scheduled_pickup_slot_start, scheduled_pickup_slot_end,
                     estimated_item_count, estimated_weight_kg, special_instructions,
                     estimated_price, payment_timing, created_at, updated_at)
                 VALUES (:customer_id, :operator_id, :status, :service_tier, :pickup_address, :pickup_lat, :pickup_lng,
                     :delivery_address, :slot_start, :slot_end, :item_count, :weight_kg, :instructions,
                     :estimated_price, :payment_timing, NOW(), NOW())'
            );
            $stmt->execute([
                'customer_id' => $user['id'],
                'operator_id' => $match['operator_id'] ?? null,
                'status' => $match ? 'matched' : 'requested',
                'service_tier' => $serviceTier,
                'pickup_address' => $pickupAddress,
                'pickup_lat' => $lat,
                'pickup_lng' => $lng,
                'delivery_address' => $deliveryAddress,
                'slot_start' => $slotStart->format('Y-m-d H:i:s'),
                'slot_end' => $slotEnd->format('Y-m-d H:i:s'),
                'item_count' => $itemCount,
                'weight_kg' => $weightKg,
                'instructions' => $request->input('special_instructions'),
                'estimated_price' => $estimatedPrice,
                'payment_timing' => $paymentTiming,
            ]);
            $bookingId = (int) $db->lastInsertId();

            if ($match !== null) {
                $capStmt = $db->prepare(
                    'UPDATE machine_capacity SET booked_kg = booked_kg + :weight, booked_orders = booked_orders + 1 WHERE id = :id'
                );
                $capStmt->execute(['weight' => $weightKg, 'id' => $match['capacity_id']]);
            }

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        if ($match === null) {
            Response::json([
                'id' => $bookingId,
                'status' => 'requested',
                'operator_id' => null,
                'estimated_price' => $estimatedPrice,
                'message' => 'No operator is currently available for this address and time slot. '
                    . 'Your booking has been saved — try a different slot or nearby address.',
            ], 201);
            return;
        }

        Response::json([
            'id' => $bookingId,
            'status' => 'matched',
            'operator_id' => $match['operator_id'],
            'estimated_price' => $estimatedPrice,
        ], 201);
    }

    /**
     * Finds the nearest operator (by operator_service_areas radius) with
     * open machine_capacity for the requested date/slot, excluding
     * suspended operators, then locks and re-checks that capacity row
     * (FOR UPDATE, inside the caller's transaction) before committing to it
     * — guards against two concurrent bookings over-filling the same slot.
     * Only 'radius' service areas are matchable for MVP; 'named_neighborhoods'
     * areas need address→neighborhood resolution that isn't built yet (no
     * geocoding provider is wired in — see MVP_STATUS.md Known issues).
     *
     * @return array{operator_id:int,capacity_id:int}|null
     */
    private function matchOperator(
        PDO $db,
        float $lat,
        float $lng,
        string $date,
        string $slotStartTime,
        string $slotEndTime,
        float $weightKg
    ): ?array {
        $sql = "
            SELECT
                osa.operator_id,
                osa.radius_km,
                mc.id AS capacity_id,
                (6371 * acos(
                    LEAST(1, GREATEST(-1,
                        cos(radians(:lat1)) * cos(radians(osa.center_lat)) * cos(radians(osa.center_lng) - radians(:lng1))
                        + sin(radians(:lat2)) * sin(radians(osa.center_lat))
                    ))
                )) AS distance_km,
                COALESCE(oqs.average_rating, 0) AS quality_rating
            FROM operator_service_areas osa
            INNER JOIN users u ON u.id = osa.operator_id AND u.account_type = 'provider' AND u.status = 'active'
            INNER JOIN machine_capacity mc ON mc.operator_id = osa.operator_id
                AND mc.date = :slot_date
                AND mc.slot_start <= :slot_start_time
                AND mc.slot_end >= :slot_end_time
                AND mc.is_active = true
            LEFT JOIN operator_quality_scores oqs ON oqs.operator_id = osa.operator_id
            WHERE osa.area_type = 'radius'
                AND (mc.max_orders IS NULL OR mc.booked_orders < mc.max_orders)
                AND (mc.booked_kg + :weight1) <= mc.max_kg_capacity
                AND COALESCE(oqs.current_status, 'good_standing') <> 'suspended'
            ORDER BY distance_km ASC, quality_rating DESC
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            'lat1' => $lat,
            'lng1' => $lng,
            'lat2' => $lat,
            'slot_date' => $date,
            'slot_start_time' => $slotStartTime,
            'slot_end_time' => $slotEndTime,
            'weight1' => $weightKg,
        ]);

        $candidates = array_values(array_filter(
            $stmt->fetchAll(),
            static fn (array $row): bool => (float) $row['distance_km'] <= (float) $row['radius_km']
        ));

        foreach (array_slice($candidates, 0, 5) as $candidate) {
            $lockStmt = $db->prepare(
                'SELECT booked_kg, max_kg_capacity, booked_orders, max_orders FROM machine_capacity WHERE id = :id FOR UPDATE'
            );
            $lockStmt->execute(['id' => $candidate['capacity_id']]);
            $capacity = $lockStmt->fetch();

            if (!$capacity) {
                continue;
            }

            $fitsWeight = ((float) $capacity['booked_kg'] + $weightKg) <= (float) $capacity['max_kg_capacity'];
            $fitsOrders = $capacity['max_orders'] === null || (int) $capacity['booked_orders'] < (int) $capacity['max_orders'];

            if ($fitsWeight && $fitsOrders) {
                return [
                    'operator_id' => (int) $candidate['operator_id'],
                    'capacity_id' => (int) $candidate['capacity_id'],
                ];
            }
        }

        return null;
    }

    private static function parseDateTime(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable $e) {
            return null;
        }
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
        $user = Auth::requireUser($request);
        if (!$user) {
            return;
        }

        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM laundry_bookings WHERE customer_id = :customer_id ORDER BY created_at DESC');
        $stmt->execute(['customer_id' => $user['id']]);

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
        if (!Auth::requireUser($request)) {
            return;
        }

        // TODO: call into the shared Escrow Engine to release payment to the
        // operator minus commission. See shared-architecture.md.
        $db = Database::connection();
        $stmt = $db->prepare('UPDATE laundry_bookings SET status = \'delivered\', updated_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $request->params['id']]);

        Response::json(['id' => (int) $request->params['id'], 'status' => 'delivered', 'escrow' => 'release_pending']);
    }

    public function cancel(Request $request): void
    {
        if (!Auth::requireUser($request)) {
            return;
        }

        // TODO: apply cancellation-fee policy — see open-questions.md #6.
        $db = Database::connection();
        $stmt = $db->prepare('UPDATE laundry_bookings SET status = \'cancelled\', updated_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $request->params['id']]);

        Response::json(['id' => (int) $request->params['id'], 'status' => 'cancelled']);
    }

    public function availableSlots(Request $request): void
    {
        $lat = $request->query['lat'] ?? null;
        $lng = $request->query['lng'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lng)) {
            Response::error('lat and lng query parameters are required — share your location to see slots near you', 422);
            return;
        }
        $lat = (float) $lat;
        $lng = (float) $lng;

        $db = Database::connection();
        $sql = "
            SELECT DISTINCT mc.date, mc.slot_start, mc.slot_end
            FROM machine_capacity mc
            INNER JOIN operator_service_areas osa ON osa.operator_id = mc.operator_id AND osa.area_type = 'radius'
            INNER JOIN users u ON u.id = mc.operator_id AND u.account_type = 'provider' AND u.status = 'active'
            LEFT JOIN operator_quality_scores oqs ON oqs.operator_id = mc.operator_id
            WHERE mc.is_active = true
                AND mc.date >= CURRENT_DATE
                AND (mc.max_orders IS NULL OR mc.booked_orders < mc.max_orders)
                AND mc.booked_kg < mc.max_kg_capacity
                AND COALESCE(oqs.current_status, 'good_standing') <> 'suspended'
                AND (6371 * acos(
                    LEAST(1, GREATEST(-1,
                        cos(radians(:lat1)) * cos(radians(osa.center_lat)) * cos(radians(osa.center_lng) - radians(:lng1))
                        + sin(radians(:lat2)) * sin(radians(osa.center_lat))
                    ))
                )) <= osa.radius_km
            ORDER BY mc.date, mc.slot_start
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute(['lat1' => $lat, 'lng1' => $lng, 'lat2' => $lat]);

        $slots = array_map(static function (array $row): array {
            $start = new DateTimeImmutable($row['date'] . ' ' . $row['slot_start']);
            $end = new DateTimeImmutable($row['date'] . ' ' . $row['slot_end']);

            return [
                'id' => $start->format('Y-m-d\TH:i:s') . '|' . $end->format('Y-m-d\TH:i:s'),
                'label' => $start->format('D, j M') . ' - ' . $start->format('g:i A') . ' to ' . $end->format('g:i A'),
                'available' => true,
            ];
        }, $stmt->fetchAll());

        Response::json(['slots' => $slots]);
    }
}
