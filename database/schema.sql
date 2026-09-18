-- laundry.co.ke — database schema
-- Shared core tables (planning/00-portfolio/shared-database-schema.md) +
-- platform-specific extension tables (planning/01-laundry-co-ke/database-schema.md).
-- MySQL/MariaDB dialect, per shared-architecture.md's stack assumption.

-- ============================================================
-- SHARED CORE TABLES — identical shape across all five platforms
-- ============================================================

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    phone_number VARCHAR(20) NOT NULL UNIQUE,
    email VARCHAR(255) NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    national_id_number VARCHAR(20) NULL,
    account_type ENUM('customer', 'provider', 'admin') NOT NULL,
    status ENUM('active', 'suspended', 'banned', 'pending_verification') NOT NULL DEFAULT 'pending_verification',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE permissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(150) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
    role_id BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    FOREIGN KEY (role_id) REFERENCES roles(id),
    FOREIGN KEY (permission_id) REFERENCES permissions(id)
) ENGINE=InnoDB;

CREATE TABLE user_roles (
    user_id BIGINT UNSIGNED NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, role_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB;

CREATE TABLE payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    booking_id BIGINT UNSIGNED NULL,
    order_id BIGINT UNSIGNED NULL,
    type ENUM('charge', 'payout', 'refund', 'commission') NOT NULL,
    method ENUM('mpesa_stk', 'mpesa_c2b', 'mpesa_b2c', 'card') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'KES',
    external_reference VARCHAR(100) NULL,
    status ENUM('pending', 'completed', 'failed', 'reversed') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_payments_external_reference (external_reference)
) ENGINE=InnoDB;

-- Idempotency log for M-Pesa Daraja callbacks — see shared-architecture.md.
CREATE TABLE payment_callbacks_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    checkout_request_id VARCHAR(100) NOT NULL UNIQUE,
    raw_payload JSON NOT NULL,
    processed_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE escrow_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_id BIGINT UNSIGNED NOT NULL,
    booking_id BIGINT UNSIGNED NOT NULL,
    held_amount DECIMAL(12,2) NOT NULL,
    retention_percentage DECIMAL(5,2) NOT NULL DEFAULT 0,
    release_condition ENUM('auto_timeout', 'customer_confirmation', 'admin_release', 'dispute_resolution') NOT NULL,
    release_at TIMESTAMP NULL,
    released_at TIMESTAMP NULL,
    status ENUM('held', 'released', 'partially_released', 'refunded') NOT NULL DEFAULT 'held',
    FOREIGN KEY (payment_id) REFERENCES payments(id)
) ENGINE=InnoDB;

CREATE TABLE commission_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    platform ENUM('laundry', 'rider', 'construction', 'solar', 'event') NOT NULL DEFAULT 'laundry',
    category VARCHAR(100) NOT NULL,
    commission_type ENUM('percentage', 'flat_fee', 'tiered') NOT NULL,
    value DECIMAL(10,2) NOT NULL,
    min_transaction_value DECIMAL(12,2) NULL,
    max_transaction_value DECIMAL(12,2) NULL,
    effective_from TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    effective_to TIMESTAMP NULL
) ENGINE=InnoDB;

-- Default commission rate: 18% (midpoint of prd.md's proposed 15-20% range,
-- flagged in open-questions.md as needing stakeholder confirmation).
-- src/Core/Escrow.php falls back to this same 18% if this row is ever removed.
INSERT INTO commission_rules (platform, category, commission_type, value, effective_from)
VALUES ('laundry', 'laundry_booking', 'percentage', 18.00, CURRENT_TIMESTAMP);

CREATE TABLE kyc_documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    document_type ENUM('national_id', 'kra_pin', 'business_registration', 'insurance_certificate', 'professional_certification', 'proof_of_address') NOT NULL,
    file_reference VARCHAR(500) NOT NULL,
    verification_status ENUM('pending', 'verified', 'rejected', 'expired') NOT NULL DEFAULT 'pending',
    verified_by BIGINT UNSIGNED NULL,
    verified_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (verified_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE reviews (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id BIGINT UNSIGNED NOT NULL,
    reviewer_id BIGINT UNSIGNED NOT NULL,
    reviewee_id BIGINT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    comment TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (reviewer_id) REFERENCES users(id),
    FOREIGN KEY (reviewee_id) REFERENCES users(id),
    UNIQUE KEY uq_reviews_booking_reviewer (booking_id, reviewer_id)
) ENGINE=InnoDB;

-- category values for this platform: item_damaged, item_lost, poor_quality, price_disagreement, other
CREATE TABLE disputes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id BIGINT UNSIGNED NOT NULL,
    raised_by BIGINT UNSIGNED NOT NULL,
    category VARCHAR(100) NOT NULL,
    description TEXT NOT NULL,
    evidence_urls JSON NULL,
    status ENUM('open', 'under_review', 'resolved_refund', 'resolved_partial', 'resolved_no_action', 'escalated') NOT NULL DEFAULT 'open',
    resolved_by BIGINT UNSIGNED NULL,
    resolution_notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL,
    FOREIGN KEY (raised_by) REFERENCES users(id),
    FOREIGN KEY (resolved_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    before_state JSON NULL,
    after_state JSON NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ============================================================
-- PLATFORM-SPECIFIC TABLES — laundry.co.ke
-- (planning/01-laundry-co-ke/database-schema.md)
-- ============================================================

CREATE TABLE laundry_bookings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id BIGINT UNSIGNED NOT NULL,
    operator_id BIGINT UNSIGNED NULL,
    status ENUM('requested', 'matched', 'picked_up', 'washing', 'ready', 'out_for_delivery', 'delivered', 'cancelled', 'disputed') NOT NULL DEFAULT 'requested',
    service_tier ENUM('wash_fold', 'dry_clean', 'express') NOT NULL,
    pickup_address VARCHAR(500) NOT NULL,
    pickup_lat DECIMAL(10,7) NOT NULL,
    pickup_lng DECIMAL(10,7) NOT NULL,
    delivery_address VARCHAR(500) NOT NULL,
    scheduled_pickup_slot_start TIMESTAMP NOT NULL,
    scheduled_pickup_slot_end TIMESTAMP NOT NULL,
    estimated_item_count INT NULL,
    estimated_weight_kg DECIMAL(6,2) NULL,
    actual_weight_kg DECIMAL(6,2) NULL,
    special_instructions TEXT NULL,
    estimated_price DECIMAL(10,2) NOT NULL,
    final_price DECIMAL(10,2) NULL,
    payment_id BIGINT UNSIGNED NULL,
    payment_timing ENUM('prepaid', 'pay_on_delivery') NOT NULL DEFAULT 'prepaid',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES users(id),
    FOREIGN KEY (operator_id) REFERENCES users(id),
    FOREIGN KEY (payment_id) REFERENCES payments(id)
) ENGINE=InnoDB;

CREATE TABLE machine_capacity (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    operator_id BIGINT UNSIGNED NOT NULL,
    date DATE NOT NULL,
    slot_start TIME NOT NULL,
    slot_end TIME NOT NULL,
    max_kg_capacity DECIMAL(6,2) NOT NULL,
    booked_kg DECIMAL(6,2) NOT NULL DEFAULT 0,
    max_orders INT NULL,
    booked_orders INT NOT NULL DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    FOREIGN KEY (operator_id) REFERENCES users(id),
    INDEX idx_machine_capacity_operator_date (operator_id, date)
) ENGINE=InnoDB;

CREATE TABLE operator_service_areas (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    operator_id BIGINT UNSIGNED NOT NULL,
    area_type ENUM('radius', 'named_neighborhoods') NOT NULL,
    center_lat DECIMAL(10,7) NULL,
    center_lng DECIMAL(10,7) NULL,
    radius_km DECIMAL(5,2) NULL,
    neighborhood_names JSON NULL,
    FOREIGN KEY (operator_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id BIGINT UNSIGNED NOT NULL,
    plan_type ENUM('monthly_fixed_count', 'monthly_unlimited_capped') NOT NULL,
    pickups_included INT NULL,
    weight_cap_kg DECIMAL(6,2) NULL,
    pickups_used_this_cycle INT NOT NULL DEFAULT 0,
    weight_used_this_cycle_kg DECIMAL(6,2) NOT NULL DEFAULT 0,
    monthly_price DECIMAL(10,2) NOT NULL,
    status ENUM('active', 'paused', 'cancelled') NOT NULL DEFAULT 'active',
    current_cycle_start DATE NOT NULL,
    current_cycle_end DATE NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE loyalty_points_ledger (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id BIGINT UNSIGNED NOT NULL,
    booking_id BIGINT UNSIGNED NULL,
    points_change INT NOT NULL,
    reason VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES users(id),
    FOREIGN KEY (booking_id) REFERENCES laundry_bookings(id)
) ENGINE=InnoDB;

CREATE TABLE preferred_operators (
    customer_id BIGINT UNSIGNED NOT NULL,
    operator_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (customer_id, operator_id),
    FOREIGN KEY (customer_id) REFERENCES users(id),
    FOREIGN KEY (operator_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE operator_quality_scores (
    operator_id BIGINT UNSIGNED PRIMARY KEY,
    average_rating DECIMAL(3,2) NOT NULL DEFAULT 0,
    completed_orders_count INT NOT NULL DEFAULT 0,
    dispute_count_90d INT NOT NULL DEFAULT 0,
    current_status ENUM('good_standing', 'warning', 'suspended') NOT NULL DEFAULT 'good_standing',
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (operator_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- disputes.booking_id and reviews.booking_id both point at laundry_bookings.id;
-- added as separate ALTERs so the shared-table CREATEs above stay platform-agnostic.
ALTER TABLE disputes ADD CONSTRAINT fk_disputes_booking FOREIGN KEY (booking_id) REFERENCES laundry_bookings(id);
ALTER TABLE reviews ADD CONSTRAINT fk_reviews_booking FOREIGN KEY (booking_id) REFERENCES laundry_bookings(id);
ALTER TABLE escrow_transactions ADD CONSTRAINT fk_escrow_booking FOREIGN KEY (booking_id) REFERENCES laundry_bookings(id);

-- ============================================================
-- E-COMMERCE STORE — shared shape across all five platforms
-- (see planning/00-portfolio/shared-architecture.md's e-commerce checkout
-- reusing the shared Payments Core; catalog rows themselves are
-- platform-specific data, per this platform's prd.md store scope)
-- ============================================================

CREATE TABLE store_products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    seller_id BIGINT UNSIGNED NULL, -- NULL = platform-owned inventory; set = marketplace seller (see open-questions.md)
    category VARCHAR(100) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    price DECIMAL(10,2) NOT NULL,
    stock_quantity INT NOT NULL DEFAULT 0,
    image_urls JSON NULL,
    status ENUM('active', 'out_of_stock', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (seller_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE store_orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id BIGINT UNSIGNED NOT NULL,
    payment_id BIGINT UNSIGNED NULL,
    status ENUM('pending', 'paid', 'fulfilled', 'cancelled') NOT NULL DEFAULT 'pending',
    total_amount DECIMAL(10,2) NOT NULL,
    delivery_address VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES users(id),
    FOREIGN KEY (payment_id) REFERENCES payments(id)
) ENGINE=InnoDB;

CREATE TABLE store_order_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES store_orders(id),
    FOREIGN KEY (product_id) REFERENCES store_products(id)
) ENGINE=InnoDB;

ALTER TABLE payments ADD CONSTRAINT fk_payments_store_order FOREIGN KEY (order_id) REFERENCES store_orders(id);

-- Store catalog seed — see prd.md's E-Commerce Store Scope. Photo URLs are
-- verified-resolving Unsplash CDN URLs (checked live, not invented IDs).
INSERT INTO store_products (category, name, description, price, stock_quantity, image_urls, status) VALUES
('detergents', 'Fresh Bloom Liquid Laundry Detergent — 2L', 'Concentrated liquid detergent for everyday wash & fold loads.', 850.00, 200, '["https://images.unsplash.com/photo-1550963295-019d8a8a61c5?auto=format&fit=crop&w=800&q=80"]', 'active'),
('detergents', 'Sunshine Laundry Bar Soap — Pack of 3', 'Multi-purpose bar soap for hand-washing and stain pre-treatment.', 350.00, 300, '["https://images.unsplash.com/photo-1542038335240-86aea625b913?auto=format&fit=crop&w=800&q=80"]', 'active'),
('equipment', 'Clothes Pegs — Pack of 24', 'Sturdy pegs for line-drying laundry.', 250.00, 300, '["https://images.unsplash.com/photo-1582479429421-321775166674?auto=format&fit=crop&w=800&q=80"]', 'active'),
('packaging', 'Heavy-Duty Laundry Bag — Large', 'Durable drawstring laundry bag for pickup and delivery.', 600.00, 150, '["https://images.unsplash.com/photo-1582735689369-4fe89db7114c?auto=format&fit=crop&w=800&q=80"]', 'active'),
('stain_removal', 'Pro Stain Remover Spray — 500ml', 'Fast-acting spray for oil, grass, and food stains before washing.', 480.00, 180, '["https://images.unsplash.com/photo-1563453392212-326f5e854473?auto=format&fit=crop&w=800&q=80"]', 'active'),
('equipment', 'Foldable Clothes Drying Rack', 'Space-saving rack for air-drying delicate garments.', 3200.00, 40, '["https://images.unsplash.com/photo-1760727772969-cb5cd59c6f30?auto=format&fit=crop&w=800&q=80"]', 'active'),
('equipment', 'Compact Steam Iron', 'Steam iron for pressing shirts and uniforms — home and operator finishing.', 4500.00, 35, '["https://images.unsplash.com/photo-1540544093-b0880061e1a5?auto=format&fit=crop&w=800&q=80"]', 'active'),
('detergents', 'Bulk Commercial Detergent — 20L Operator Pack', 'Bulk-size detergent for laundromats and commercial laundry operators.', 8500.00, 20, '["https://images.unsplash.com/photo-1757233285714-4de702bb8a56?auto=format&fit=crop&w=800&q=80"]', 'active');
