<?php
$envFile = __DIR__ . '/.env';
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    putenv(trim($k) . '=' . trim($v));
}
require __DIR__ . '/vendor/autoload.php';

use Laundry\Config\Database;

$db = Database::connection();

// store_orders.payment_id and payments.order_id reference each other, so break
// the link before deleting either side.
$db->exec('UPDATE store_orders SET payment_id = NULL');
$db->exec('DELETE FROM payments WHERE order_id IS NOT NULL');
$db->exec('DELETE FROM store_order_items');
$db->exec('DELETE FROM store_orders');
$db->exec('DELETE FROM store_products');

$products = [
    ['detergents', 'Fresh Bloom Liquid Laundry Detergent — 2L', 'Concentrated liquid detergent for everyday wash & fold loads.', 850.00, 200, 'https://images.unsplash.com/photo-1550963295-019d8a8a61c5?auto=format&fit=crop&w=800&q=80'],
    ['detergents', 'Sunshine Laundry Bar Soap — Pack of 3', 'Multi-purpose bar soap for hand-washing and stain pre-treatment.', 350.00, 300, 'https://images.unsplash.com/photo-1542038335240-86aea625b913?auto=format&fit=crop&w=800&q=80'],
    ['equipment', 'Clothes Pegs — Pack of 24', 'Sturdy pegs for line-drying laundry.', 250.00, 300, 'https://images.unsplash.com/photo-1582479429421-321775166674?auto=format&fit=crop&w=800&q=80'],
    ['packaging', 'Heavy-Duty Laundry Bag — Large', 'Durable drawstring laundry bag for pickup and delivery.', 600.00, 150, 'https://images.unsplash.com/photo-1582735689369-4fe89db7114c?auto=format&fit=crop&w=800&q=80'],
    ['stain_removal', 'Pro Stain Remover Spray — 500ml', 'Fast-acting spray for oil, grass, and food stains before washing.', 480.00, 180, 'https://images.unsplash.com/photo-1563453392212-326f5e854473?auto=format&fit=crop&w=800&q=80'],
    ['equipment', 'Foldable Clothes Drying Rack', 'Space-saving rack for air-drying delicate garments.', 3200.00, 40, 'https://images.unsplash.com/photo-1760727772969-cb5cd59c6f30?auto=format&fit=crop&w=800&q=80'],
    ['equipment', 'Compact Steam Iron', 'Steam iron for pressing shirts and uniforms — home and operator finishing.', 4500.00, 35, 'https://images.unsplash.com/photo-1540544093-b0880061e1a5?auto=format&fit=crop&w=800&q=80'],
    ['detergents', 'Bulk Commercial Detergent — 20L Operator Pack', 'Bulk-size detergent for laundromats and commercial laundry operators.', 8500.00, 20, 'https://images.unsplash.com/photo-1757233285714-4de702bb8a56?auto=format&fit=crop&w=800&q=80'],
];

$stmt = $db->prepare(
    'INSERT INTO store_products (category, name, description, price, stock_quantity, image_urls, status)
     VALUES (:category, :name, :description, :price, :stock, :image_urls, \'active\')'
);

foreach ($products as [$category, $name, $description, $price, $stock, $image]) {
    $stmt->execute([
        'category' => $category,
        'name' => $name,
        'description' => $description,
        'price' => $price,
        'stock' => $stock,
        'image_urls' => json_encode([$image]),
    ]);
}

echo 'inserted=' . $db->query('SELECT COUNT(*) c FROM store_products')->fetch()['c'] . PHP_EOL;
