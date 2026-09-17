<?php

namespace Laundry\Controllers;

use Laundry\Config\Database;
use Laundry\Core\Request;
use Laundry\Core\View;

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
        $db = Database::connection();
        $stmt = $db->query(
            'SELECT u.id, u.full_name, q.average_rating, q.completed_orders_count
             FROM users u
             LEFT JOIN operator_quality_scores q ON q.operator_id = u.id
             WHERE u.account_type = "provider" AND u.status = "active"
             ORDER BY q.average_rating DESC
             LIMIT 6'
        );
        $operators = $stmt->fetchAll();

        View::render('home', ['title' => 'Fresh laundry, picked up and delivered', 'operators' => $operators]);
    }

    public function bookForm(Request $request): void
    {
        View::render('book', ['title' => 'Book a pickup']);
    }

    public function bookingStatus(Request $request): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM laundry_bookings WHERE id = :id');
        $stmt->execute(['id' => $request->params['id']]);
        $booking = $stmt->fetch();

        View::render('booking-status', ['title' => 'Booking status', 'booking' => $booking, 'bookingId' => (int) $request->params['id']]);
    }
}
