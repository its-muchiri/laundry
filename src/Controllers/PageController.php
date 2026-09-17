<?php

namespace Laundry\Controllers;

use Laundry\Config\Database;
use Laundry\Core\Request;
use Laundry\Core\View;
use Throwable;

/**
 * Server-rendered pages for the primary customer journey (see
 * planning/01-laundry-co-ke/user-flows.md §1: search → book → pay →
 * track → complete → review). These are real pages against the live
 * database — not a mockup — but only cover the customer's booking path;
 * operator dashboards, admin dispute console, etc. are not built here.
 */
final class PageController
{
    public function home(Request $request): void
    {
        $operators = [];
        $dbError = null;

        try {
            $stmt = Database::connection()->query(
                'SELECT u.id, u.full_name, q.average_rating, q.completed_orders_count
                 FROM users u
                 LEFT JOIN operator_quality_scores q ON q.operator_id = u.id
                 WHERE u.account_type = "provider" AND u.status = "active"
                 ORDER BY q.average_rating DESC
                 LIMIT 6'
            );
            $operators = $stmt->fetchAll();
        } catch (Throwable $e) {
            error_log((string) $e);
            $dbError = 'Live operator data is unavailable in this environment — no database is connected yet.';
        }

        View::render('home', ['title' => 'Fresh laundry, picked up and delivered', 'operators' => $operators, 'dbError' => $dbError]);
    }

    public function bookForm(Request $request): void
    {
        View::render('book', ['title' => 'Book a pickup']);
    }

    public function bookingStatus(Request $request): void
    {
        $booking = null;
        $dbError = null;

        try {
            $stmt = Database::connection()->prepare('SELECT * FROM laundry_bookings WHERE id = :id');
            $stmt->execute(['id' => $request->params['id']]);
            $booking = $stmt->fetch() ?: null;
        } catch (Throwable $e) {
            error_log((string) $e);
            $dbError = 'Live booking data is unavailable in this environment — no database is connected yet.';
        }

        View::render('booking-status', [
            'title' => 'Booking status',
            'booking' => $booking,
            'bookingId' => (int) $request->params['id'],
            'dbError' => $dbError,
        ]);
    }
}
