<?php

namespace Laundry\Controllers;

use Laundry\Config\Database;
use Laundry\Core\Auth;
use Laundry\Core\Request;
use Laundry\Core\Response;
use PDO;
use Throwable;

/**
 * Reviews live in the shared `reviews` table (see
 * planning/00-portfolio/shared-database-schema.md) with booking_id
 * pointing at laundry_bookings.id. api-endpoints.md lists the review
 * endpoint's auth as "Customer, Operator" — read as mutual review
 * (each side of a completed booking may rate the other), since
 * open-questions.md #5 flags mutual review as undecided but the API
 * contract already commits to both roles being allowed to call this
 * endpoint.
 */
final class ReviewController
{
    public function store(Request $request): void
    {
        $user = Auth::requireUser($request);
        if (!$user) {
            return;
        }

        $bookingId = (int) $request->params['id'];
        $rating = $request->input('rating');
        if (!is_numeric($rating) || (int) $rating < 1 || (int) $rating > 5) {
            Response::error('rating must be an integer between 1 and 5', 422);
            return;
        }
        $rating = (int) $rating;
        $comment = $request->input('comment');
        $comment = is_string($comment) && trim($comment) !== '' ? trim($comment) : null;

        $db = Database::connection();
        $db->beginTransaction();

        try {
            $stmt = $db->prepare('SELECT * FROM laundry_bookings WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $bookingId]);
            $booking = $stmt->fetch();

            if (!$booking) {
                $db->rollBack();
                Response::notFound('Booking not found');
                return;
            }

            $isCustomer = (int) $booking['customer_id'] === (int) $user['id'];
            $isOperator = $booking['operator_id'] !== null && (int) $booking['operator_id'] === (int) $user['id'];
            if (!$isCustomer && !$isOperator) {
                $db->rollBack();
                Response::forbidden('You are not a party to this booking');
                return;
            }

            if ($booking['status'] !== 'delivered') {
                $db->rollBack();
                Response::error('This booking can only be reviewed after delivery is confirmed', 422);
                return;
            }

            $revieweeId = $isCustomer ? $booking['operator_id'] : $booking['customer_id'];
            if ($revieweeId === null) {
                $db->rollBack();
                Response::error('This booking has no counterparty to review', 422);
                return;
            }

            $dupStmt = $db->prepare(
                'SELECT id FROM reviews WHERE booking_id = :booking_id AND reviewer_id = :reviewer_id'
            );
            $dupStmt->execute(['booking_id' => $bookingId, 'reviewer_id' => $user['id']]);
            if ($dupStmt->fetch()) {
                $db->rollBack();
                Response::error('You have already reviewed this booking', 409);
                return;
            }

            $insert = $db->prepare(
                'INSERT INTO reviews (booking_id, reviewer_id, reviewee_id, rating, comment, created_at)
                 VALUES (:booking_id, :reviewer_id, :reviewee_id, :rating, :comment, NOW())'
            );
            $insert->execute([
                'booking_id' => $bookingId,
                'reviewer_id' => $user['id'],
                'reviewee_id' => $revieweeId,
                'rating' => $rating,
                'comment' => $comment,
            ]);
            $reviewId = (int) $db->lastInsertId();

            if ($isCustomer) {
                self::recomputeOperatorQualityScore($db, (int) $revieweeId);
            }

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        Response::json(['id' => $reviewId, 'booking_id' => $bookingId, 'rating' => $rating], 201);
    }

    /**
     * Recomputes operator_quality_scores.average_rating from the reviews
     * table directly rather than incrementally, so it stays correct even
     * if rows are ever corrected/removed by an admin later.
     */
    private static function recomputeOperatorQualityScore(PDO $db, int $operatorId): void
    {
        $stmt = $db->prepare(
            'SELECT AVG(r.rating) AS avg_rating, COUNT(*) AS review_count
             FROM reviews r
             WHERE r.reviewee_id = :operator_id'
        );
        $stmt->execute(['operator_id' => $operatorId]);
        $row = $stmt->fetch();
        $avgRating = round((float) ($row['avg_rating'] ?? 0), 2);

        $completedStmt = $db->prepare(
            "SELECT COUNT(*) AS c FROM laundry_bookings WHERE operator_id = :operator_id AND status = 'delivered'"
        );
        $completedStmt->execute(['operator_id' => $operatorId]);
        $completedCount = (int) $completedStmt->fetch()['c'];

        $upsert = Database::driver() === 'pgsql'
            ? 'INSERT INTO operator_quality_scores (operator_id, average_rating, completed_orders_count, updated_at)
               VALUES (:operator_id, :avg_rating, :completed_count, NOW())
               ON CONFLICT (operator_id) DO UPDATE
               SET average_rating = :avg_rating2, completed_orders_count = :completed_count2, updated_at = NOW()'
            : 'INSERT INTO operator_quality_scores (operator_id, average_rating, completed_orders_count, updated_at)
               VALUES (:operator_id, :avg_rating, :completed_count, NOW())
               ON DUPLICATE KEY UPDATE average_rating = :avg_rating2, completed_orders_count = :completed_count2, updated_at = NOW()';

        $upsertStmt = $db->prepare($upsert);
        $upsertStmt->execute([
            'operator_id' => $operatorId,
            'avg_rating' => $avgRating,
            'completed_count' => $completedCount,
            'avg_rating2' => $avgRating,
            'completed_count2' => $completedCount,
        ]);
    }

    public function forOperator(Request $request): void
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT r.* FROM reviews r
             JOIN laundry_bookings b ON b.id = r.booking_id
             WHERE b.operator_id = :operator_id AND r.reviewee_id = b.operator_id
             ORDER BY r.created_at DESC'
        );
        $stmt->execute(['operator_id' => $request->params['id']]);

        Response::json($stmt->fetchAll());
    }
}
