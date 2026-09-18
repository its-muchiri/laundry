<?php

/**
 * Server-rendered page routes (see src/Views/ and src/Core/View.php) —
 * distinct from routes/api.php's JSON API. Covers only the primary
 * customer journey per planning/01-laundry-co-ke/user-flows.md §1;
 * operator dashboards and the admin dispute console are not built here.
 *
 * @var \Laundry\Core\Router $router
 */

use Laundry\Controllers\AuthController;
use Laundry\Controllers\PageController;

$page = new PageController();
$auth = new AuthController();

$router->get('/', [$page, 'home']);
$router->get('/book', [$page, 'bookForm']);
$router->get('/bookings/{id}', [$page, 'bookingStatus']);

$router->get('/signup', [$auth, 'showSignup']);
$router->post('/signup', [$auth, 'register']);
$router->get('/login', [$auth, 'showLogin']);
$router->post('/login', [$auth, 'login']);
$router->post('/logout', [$auth, 'logout']);
