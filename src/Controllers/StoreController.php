<?php

namespace Laundry\Controllers;

use Laundry\Config\Database;
use Laundry\Core\Auth;
use Laundry\Core\Request;
use Laundry\Core\Response;
use RuntimeException;
use Throwable;

/**
 * E-commerce store — detergents, packaging, and supplies (see
 * planning/01-laundry-co-ke/prd.md's store scope). Checkout reuses the
 * shared Payments Core (PaymentController::stkPush accepts a
 * store_order_id); this controller manages the catalog and order records
 * themselves.
 *
 * Store inventory is platform-owned (seller_id NULL, see
 * open-questions.md), so store payments are a direct sale to the platform
 * — there is no operator to pay out, and therefore no escrow hold/release
 * as there is for bookings.
 */
final class StoreController
{
    private const MAX_QUANTITY_PER_LINE = 50;

    /**
     * A pending STK Push prompt expires on the customer's handset within a
     * couple of minutes and Daraja calls back shortly after; a `pending`
     * payment older than this is treated as abandoned so it can't block
     * cancelling an order forever.
     */
    public const PENDING_PAYMENT_WINDOW_MINUTES = 5;

    public function listProducts(Request $request): void
    {
        $db = Database::connection();
        $category = $request->query['category'] ?? null;

        // out_of_stock items stay listed (rendered as unavailable) so
        // customers see they exist; inactive ones are hidden entirely.
        $sql = 'SELECT id, category, name, description, price, stock_quantity, image_urls, status
                FROM store_products WHERE status IN (\'active\', \'out_of_stock\')';
        $params = [];
        if ($category) {
            $sql .= ' AND category = :category';
            $params['category'] = $category;
        }
        $sql .= ' ORDER BY category, name';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        $products = array_map(static function (array $row): array {
            $images = is_string($row['image_urls']) ? json_decode($row['image_urls'], true) : $row['image_urls'];
            $row['images'] = is_array($images) ? $images : [];
            unset($row['image_urls']);
            $row['price'] = (float) $row['price'];
            $row['stock_quantity'] = (int) $row['stock_quantity'];
            $row['in_stock'] = $row['status'] === 'active' && $row['stock_quantity'] > 0;
            return $row;
        }, $stmt->fetchAll());

        Response::json($products);
    }

    public function createOrder(Request $request): void
    {
        $user = Auth::requireUser($request);
        if (!$user) {
            return;
        }

        $address = trim((string) $request->input('delivery_address', ''));
        if (mb_strlen($address) < 5) {
            Response::error('A delivery address is required', 422);
            return;
        }

        $rawItems = $request->input('items', []); // [{ product_id, quantity }]
        if (!is_array($rawItems) || empty($rawItems)) {
            Response::error('Order must include at least one item', 422);
            return;
        }

        // Merge duplicate lines and validate quantities up front. Sorting by
        // product id gives every concurrent checkout the same row-lock order,
        // which prevents deadlocks between orders sharing products.
        $quantities = [];
        foreach ($rawItems as $item) {
            $productId = is_array($item) ? filter_var($item['product_id'] ?? null, FILTER_VALIDATE_INT) : false;
            $quantity = is_array($item) ? filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT) : false;
            if ($productId === false || $productId <= 0 || $quantity === false || $quantity < 1) {
                Response::error('Each item needs a valid product_id and a quantity of at least 1', 422);
                return;
            }
            $quantities[$productId] = ($quantities[$productId] ?? 0) + $quantity;
        }
        ksort($quantities);

        foreach ($quantities as $productId => $quantity) {
            if ($quantity > self::MAX_QUANTITY_PER_LINE) {
                Response::error("You can order at most " . self::MAX_QUANTITY_PER_LINE . " of one product at a time (product {$productId})", 422);
                return;
            }
        }

        $db = Database::connection();
        $db->beginTransaction();
        try {
            $total = 0.0;
            $priced = [];
            $lockStmt = $db->prepare('SELECT id, name, price, stock_quantity, status FROM store_products WHERE id = :id FOR UPDATE');
            foreach ($quantities as $productId => $quantity) {
                $lockStmt->execute(['id' => $productId]);
                $product = $lockStmt->fetch();

                if (!$product || $product['status'] === 'inactive') {
                    throw new RuntimeException("Product {$productId} is no longer available");
                }
                if ($product['status'] !== 'active' || (int) $product['stock_quantity'] < $quantity) {
                    throw new RuntimeException("Not enough stock for \"{$product['name']}\" (requested {$quantity}, {$product['stock_quantity']} left)");
                }

                $unitPrice = (float) $product['price'];
                $total += $unitPrice * $quantity;
                $priced[] = ['product_id' => (int) $product['id'], 'quantity' => $quantity, 'unit_price' => $unitPrice, 'remaining' => (int) $product['stock_quantity'] - $quantity];
            }

            $stmt = $db->prepare(
                'INSERT INTO store_orders (customer_id, status, total_amount, delivery_address, created_at, updated_at)
                 VALUES (:customer_id, \'pending\', :total, :address, NOW(), NOW())'
            );
            $stmt->execute(['customer_id' => $user['id'], 'total' => $total, 'address' => $address]);
            $orderId = (int) $db->lastInsertId();

            $itemStmt = $db->prepare(
                'INSERT INTO store_order_items (order_id, product_id, quantity, unit_price) VALUES (:order_id, :product_id, :quantity, :unit_price)'
            );
            // Rows are locked FOR UPDATE above, so writing the computed
            // remaining stock is race-free — and avoids a self-referencing
            // SET expression, which MySQL and Postgres evaluate differently
            // (MySQL sees the already-updated column value).
            $stockStmt = $db->prepare(
                'UPDATE store_products
                 SET stock_quantity = :remaining,
                     status = :status,
                     updated_at = NOW()
                 WHERE id = :id'
            );

            foreach ($priced as $line) {
                $itemStmt->execute(['order_id' => $orderId, 'product_id' => $line['product_id'], 'quantity' => $line['quantity'], 'unit_price' => $line['unit_price']]);
                $stockStmt->execute([
                    'remaining' => $line['remaining'],
                    'status' => $line['remaining'] <= 0 ? 'out_of_stock' : 'active',
                    'id' => $line['product_id'],
                ]);
            }

            $db->commit();
            Response::json(['id' => $orderId, 'total_amount' => $total, 'status' => 'pending'], 201);
        } catch (RuntimeException $e) {
            $db->rollBack();
            Response::error($e->getMessage(), 422);
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function myOrders(Request $request): void
    {
        $user = Auth::requireUser($request);
        if (!$user) {
            return;
        }

        $stmt = Database::connection()->prepare(
            'SELECT id, status, total_amount, delivery_address, created_at FROM store_orders WHERE customer_id = :id ORDER BY id DESC'
        );
        $stmt->execute(['id' => $user['id']]);

        Response::json($stmt->fetchAll());
    }

    public function showOrder(Request $request): void
    {
        $user = Auth::requireUser($request);
        if (!$user) {
            return;
        }

        $db = Database::connection();
        $order = self::findOrder($db, (int) $request->params['id']);
        if (!$order) {
            Response::notFound('Order not found');
            return;
        }
        if ((int) $order['customer_id'] !== (int) $user['id'] && $user['account_type'] !== 'admin') {
            Response::forbidden('This order does not belong to you');
            return;
        }

        $items = $db->prepare(
            'SELECT i.product_id, p.name, i.quantity, i.unit_price
             FROM store_order_items i JOIN store_products p ON p.id = i.product_id
             WHERE i.order_id = :id ORDER BY i.id'
        );
        $items->execute(['id' => $order['id']]);
        $order['items'] = $items->fetchAll();

        $payment = $db->prepare('SELECT status, amount, external_reference FROM payments WHERE order_id = :id ORDER BY id DESC LIMIT 1');
        $payment->execute(['id' => $order['id']]);
        $order['payment'] = $payment->fetch() ?: null;

        Response::json($order);
    }

    /**
     * Cancels an unpaid order and returns its stock to the shelf. Paid
     * orders can't be cancelled here — that needs a refund, which goes
     * through the dispute path rather than a one-click cancel.
     */
    public function cancelOrder(Request $request): void
    {
        $user = Auth::requireUser($request);
        if (!$user) {
            return;
        }

        $db = Database::connection();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('SELECT * FROM store_orders WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => (int) $request->params['id']]);
            $order = $stmt->fetch();

            if (!$order) {
                $db->rollBack();
                Response::notFound('Order not found');
                return;
            }
            if ((int) $order['customer_id'] !== (int) $user['id']) {
                $db->rollBack();
                Response::forbidden('This order does not belong to you');
                return;
            }
            if ($order['status'] === 'cancelled') {
                $db->rollBack();
                Response::json(['id' => (int) $order['id'], 'status' => 'cancelled']);
                return;
            }
            if ($order['status'] !== 'pending') {
                $db->rollBack();
                Response::error('Only unpaid orders can be cancelled — contact support for a refund on a paid order.', 409);
                return;
            }

            // Refuse while an M-Pesa prompt may still be live: the customer
            // could enter their PIN after we've restocked and cancelled,
            // leaving a payment against a cancelled order.
            $pending = $db->prepare(
                'SELECT COUNT(*) FROM payments
                 WHERE order_id = :id AND status = \'pending\' AND created_at > ' . self::cutoffSql()
            );
            $pending->execute(['id' => $order['id']]);
            if ((int) $pending->fetchColumn() > 0) {
                $db->rollBack();
                Response::error('An M-Pesa payment is still in progress for this order — wait a few minutes for it to finish or time out, then try again.', 409);
                return;
            }

            $items = $db->prepare('SELECT product_id, quantity FROM store_order_items WHERE order_id = :id ORDER BY product_id');
            $items->execute(['id' => $order['id']]);
            $restock = $db->prepare(
                'UPDATE store_products
                 SET stock_quantity = stock_quantity + :quantity,
                     status = CASE WHEN status = \'out_of_stock\' THEN \'active\' ELSE status END,
                     updated_at = NOW()
                 WHERE id = :id'
            );
            foreach ($items->fetchAll() as $line) {
                $restock->execute(['quantity' => $line['quantity'], 'id' => $line['product_id']]);
            }

            $db->prepare('UPDATE store_orders SET status = \'cancelled\', updated_at = NOW() WHERE id = :id')
                ->execute(['id' => $order['id']]);
            // Any abandoned pending payment rows for this order are now moot.
            $db->prepare('UPDATE payments SET status = \'failed\', updated_at = NOW() WHERE order_id = :id AND status = \'pending\'')
                ->execute(['id' => $order['id']]);

            $db->commit();
            Response::json(['id' => (int) $order['id'], 'status' => 'cancelled']);
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /** SQL expression for "now minus the pending-payment window", portable across MySQL/Postgres. */
    public static function cutoffSql(): string
    {
        $minutes = self::PENDING_PAYMENT_WINDOW_MINUTES;

        return Database::driver() === 'pgsql'
            ? "(NOW() - INTERVAL '{$minutes} minutes')"
            : "(NOW() - INTERVAL {$minutes} MINUTE)";
    }

    /** @return array<string,mixed>|null */
    private static function findOrder(\PDO $db, int $id): ?array
    {
        $stmt = $db->prepare('SELECT * FROM store_orders WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }
}
