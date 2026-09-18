<?php

/**
 * Route table for laundry.co.ke. Mirrors planning/01-laundry-co-ke/api-endpoints.md.
 * Only the core resource groups are wired here; add the remaining
 * platform-specific routes (subscriptions, loyalty, store) following the
 * same pattern as the platform's features are built out.
 *
 * @var \Laundry\Core\Router $router
 */

use Laundry\Controllers\AuthController;
use Laundry\Controllers\BookingController;
use Laundry\Controllers\DisputeController;
use Laundry\Controllers\LoyaltyController;
use Laundry\Controllers\OnboardingController;
use Laundry\Controllers\OperatorController;
use Laundry\Controllers\PaymentController;
use Laundry\Controllers\ReviewController;
use Laundry\Controllers\StoreController;
use Laundry\Controllers\SubscriptionController;

$auth = new AuthController();
$booking = new BookingController();
$payment = new PaymentController();
$review = new ReviewController();
$dispute = new DisputeController();
$store = new StoreController();
$onboarding = new OnboardingController();
$operator = new OperatorController();
$subscription = new SubscriptionController();
$loyalty = new LoyaltyController();

// Auth (shared identity/auth module — see planning/00-portfolio/shared-architecture.md)
$router->post('/api/v1/auth/register', [$auth, 'register']);
$router->post('/api/v1/auth/login', [$auth, 'login']);
$router->post('/api/v1/auth/logout', [$auth, 'logout']);
$router->get('/api/v1/auth/me', [$auth, 'me']);

// Bookings
$router->post('/api/v1/bookings', [$booking, 'create']);
$router->get('/api/v1/bookings', [$booking, 'index']);
$router->get('/api/v1/bookings/{id}', [$booking, 'show']);
$router->patch('/api/v1/bookings/{id}/status', [$booking, 'updateStatus']);
$router->patch('/api/v1/bookings/{id}/confirm-weight', [$booking, 'confirmWeight']);
$router->post('/api/v1/bookings/{id}/confirm-receipt', [$booking, 'confirmReceipt']);
$router->post('/api/v1/bookings/{id}/cancel', [$booking, 'cancel']);

// Availability
$router->get('/api/v1/availability/slots', [$booking, 'availableSlots']);
$router->post('/api/v1/operators/me/capacity', [$operator, 'setCapacity']);
$router->get('/api/v1/operators/me/capacity', [$operator, 'getCapacity']);
$router->post('/api/v1/operators/me/service-area', [$operator, 'setServiceArea']);
$router->get('/api/v1/operators/{id}', [$operator, 'profile']);

// Payments
$router->post('/api/v1/payments/mpesa/stk-push', [$payment, 'stkPush']);
$router->post('/api/v1/payments/mpesa/callback', [$payment, 'mpesaCallback']);
$router->post('/api/v1/payments/card', [$payment, 'card']);
$router->get('/api/v1/operators/me/earnings', [$payment, 'myEarnings']);

// Reviews
$router->post('/api/v1/bookings/{id}/review', [$review, 'store']);
$router->get('/api/v1/operators/{id}/reviews', [$review, 'forOperator']);

// Disputes
$router->post('/api/v1/bookings/{id}/disputes', [$dispute, 'store']);
$router->get('/api/v1/disputes', [$dispute, 'index']);
$router->patch('/api/v1/disputes/{id}/resolve', [$dispute, 'resolve']);

// Operator onboarding (KYC)
$router->post('/api/v1/operators/onboard', [$onboarding, 'submit']);

// Store
$router->get('/api/v1/store/products', [$store, 'listProducts']);
$router->post('/api/v1/store/orders', [$store, 'createOrder']);
$router->get('/api/v1/store/orders', [$store, 'myOrders']);
$router->get('/api/v1/store/orders/{id}', [$store, 'showOrder']);
$router->post('/api/v1/store/orders/{id}/cancel', [$store, 'cancelOrder']);

// Subscriptions (V2 retention — see build-sequencing-roadmap.md)
$router->get('/api/v1/subscriptions/plans', [$subscription, 'listPlans']);
$router->post('/api/v1/subscriptions', [$subscription, 'subscribe']);
$router->get('/api/v1/subscriptions/me', [$subscription, 'myStatus']);
$router->delete('/api/v1/subscriptions/me', [$subscription, 'cancel']);

// Loyalty (V2 retention)
$router->get('/api/v1/loyalty/me', [$loyalty, 'balance']);
$router->post('/api/v1/loyalty/redeem', [$loyalty, 'redeem']);

// Preferred operator (V2 retention)
$router->post('/api/v1/operators/{id}/prefer', [$operator, 'prefer']);
