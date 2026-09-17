<?php

namespace Laundry\Controllers;

use Laundry\Config\Database;
use Laundry\Core\Auth;
use Laundry\Core\Request;
use Laundry\Core\Response;

/**
 * Reviews live in the shared `reviews` table (see
 * planning/00-portfolio/shared-database-schema.md) with booking_id
 * pointing at laundry_bookings.id.
 */
final class ReviewController
{
    public function store(Request $request): void
    {
        $user = Auth::requireUser($request);
        if (!$user) {
            return;
        }

        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO reviews (booking_id, reviewer_id, reviewee_id, rating, comment, created_at)
             VALUES (:booking_id, :reviewer_id, :reviewee_id, :rating, :comment, NOW())'
        );
        $stmt->execute([
            'booking_id' => $request->params['id'],
            'reviewer_id' => $user['id'],
            'reviewee_id' => $request->input('reviewee_id'),
            'rating' => $request->input('rating'),
            'comment' => $request->input('comment'),
        ]);

        // TODO: recompute operator_quality_scores.average_rating (see database/schema.sql).
        Response::json(['id' => (int) $db->lastInsertId()], 201);
    }

    public function forOperator(Request $request): void
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT r.* FROM reviews r
             JOIN laundry_bookings b ON b.id = r.booking_id
             WHERE b.operator_id = :operator_id
             ORDER BY r.created_at DESC'
        );
        $stmt->execute(['operator_id' => $request->params['id']]);

        Response::json($stmt->fetchAll());
    }
}
