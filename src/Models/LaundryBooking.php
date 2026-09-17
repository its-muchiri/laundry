<?php

namespace Laundry\Models;

use Laundry\Config\Database;

/**
 * Data-access class for laundry_bookings. Controllers currently query
 * directly via PDO for brevity in this scaffold; extract shared queries
 * here as the platform grows past the initial stubs.
 */
final class LaundryBooking
{
    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM laundry_bookings WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function forCustomer(int $customerId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM laundry_bookings WHERE customer_id = :customer_id ORDER BY created_at DESC'
        );
        $stmt->execute(['customer_id' => $customerId]);

        return $stmt->fetchAll();
    }
}
