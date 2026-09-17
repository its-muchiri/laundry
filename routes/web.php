<?php

/**
 * Server-rendered page routes (see src/Views/ and src/Core/View.php) —
 * distinct from routes/api.php's JSON API. Covers only the primary
 * customer journey per planning/01-laundry-co-ke/user-flows.md §1;
 * operator dashboards and the admin dispute console are not built here.
 *
 * @var \Laundry\Core\Router $router
 */

use Laundry\Controllers\PageController;

$page = new PageController();

$router->get('/', [$page, 'home']);
$router->get('/book', [$page, 'bookForm']);
$router->get('/bookings/{id}', [$page, 'bookingStatus']);
