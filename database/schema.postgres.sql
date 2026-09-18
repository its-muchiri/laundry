-- laundry.co.ke — PostgreSQL schema (Neon, used on Vercel).
-- Mirrors schema.sql (MySQL/MariaDB, used for local development) exactly in
-- shape; only dialect differs. See rider-co-ke's schema.postgres.sql header
-- for the full list of translation rules, and
-- planning/00-portfolio/ui-implementation-plan.md for why this file exists.

-- ============================================================
-- SHARED CORE TABLES — identical shape across all five platforms
-- ============================================================

CREATE TABLE users (
    id BIGSERIAL PRIMARY KEY,
    phone_number VARCHAR(20) NOT NULL UNIQUE,
    email VARCHAR(255) NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    national_id_number VARCHAR(20) NULL,
    account_type VARCHAR(20) NOT NULL CHECK (account_type IN ('customer', 'provider', 'admin')),
    status VARCHAR(30) NOT NULL DEFAULT 'pending_verification' CHECK (status IN ('active', 'suspended', 'banned', 'pending_verification')),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE roles (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE
);

CREATE TABLE permissions (
    id BIGSERIAL PRIMARY KEY,
    "key" VARCHAR(150) NOT NULL UNIQUE
);

CREATE TABLE role_permissions (
    role_id BIGINT NOT NULL REFERENCES roles(id),
    permission_id BIGINT NOT NULL REFERENCES permissions(id),
    PRIMARY KEY (role_id, permission_id)
);

CREATE TABLE user_roles (
    user_id BIGINT NOT NULL REFERENCES users(id),
    role_id BIGINT NOT NULL REFERENCES roles(id),
    PRIMARY KEY (user_id, role_id)
);

CREATE TABLE payments (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id),
    booking_id BIGINT NULL,
    order_id BIGINT NULL,
    type VARCHAR(20) NOT NULL CHECK (type IN ('charge', 'payout', 'refund', 'commission')),
    method VARCHAR(20) NOT NULL CHECK (method IN ('mpesa_stk', 'mpesa_c2b', 'mpesa_b2c', 'card')),
    amount DECIMAL(12,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'KES',
    external_reference VARCHAR(100) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'completed', 'failed', 'reversed')),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_payments_external_reference ON payments(external_reference);

CREATE TABLE payment_callbacks_log (
    id BIGSERIAL PRIMARY KEY,
    checkout_request_id VARCHAR(100) NOT NULL UNIQUE,
    raw_payload JSON NOT NULL,
    processed_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE escrow_transactions (
    id BIGSERIAL PRIMARY KEY,
    payment_id BIGINT NOT NULL REFERENCES payments(id),
    booking_id BIGINT NOT NULL,
    held_amount DECIMAL(12,2) NOT NULL,
    retention_percentage DECIMAL(5,2) NOT NULL DEFAULT 0,
    release_condition VARCHAR(30) NOT NULL CHECK (release_condition IN ('auto_timeout', 'customer_confirmation', 'admin_release', 'dispute_resolution')),
    release_at TIMESTAMP NULL,
    released_at TIMESTAMP NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'held' CHECK (status IN ('held', 'released', 'partially_released', 'refunded'))
);

CREATE TABLE commission_rules (
    id BIGSERIAL PRIMARY KEY,
    platform VARCHAR(20) NOT NULL DEFAULT 'laundry' CHECK (platform IN ('laundry', 'rider', 'construction', 'solar', 'event')),
    category VARCHAR(100) NOT NULL,
    commission_type VARCHAR(20) NOT NULL CHECK (commission_type IN ('percentage', 'flat_fee', 'tiered')),
    value DECIMAL(10,2) NOT NULL,
    min_transaction_value DECIMAL(12,2) NULL,
    max_transaction_value DECIMAL(12,2) NULL,
    effective_from TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    effective_to TIMESTAMP NULL
);

-- Default commission rate: 18% (midpoint of prd.md's proposed 15-20% range,
-- flagged in open-questions.md as needing stakeholder confirmation).
-- src/Core/Escrow.php falls back to this same 18% if this row is ever removed.
INSERT INTO commission_rules (platform, category, commission_type, value, effective_from)
VALUES ('laundry', 'laundry_booking', 'percentage', 18.00, CURRENT_TIMESTAMP);

CREATE TABLE kyc_documents (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id),
    document_type VARCHAR(30) NOT NULL CHECK (document_type IN ('national_id', 'kra_pin', 'business_registration', 'insurance_certificate', 'professional_certification', 'proof_of_address')),
    file_reference VARCHAR(500) NOT NULL,
    verification_status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (verification_status IN ('pending', 'verified', 'rejected', 'expired')),
    verified_by BIGINT NULL REFERENCES users(id),
    verified_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL
);

CREATE TABLE reviews (
    id BIGSERIAL PRIMARY KEY,
    booking_id BIGINT NOT NULL,
    reviewer_id BIGINT NOT NULL REFERENCES users(id),
    reviewee_id BIGINT NOT NULL REFERENCES users(id),
    rating SMALLINT NOT NULL,
    comment TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (booking_id, reviewer_id)
);

-- category values for this platform: item_damaged, item_lost, poor_quality, price_disagreement, other
CREATE TABLE disputes (
    id BIGSERIAL PRIMARY KEY,
    booking_id BIGINT NOT NULL,
    raised_by BIGINT NOT NULL REFERENCES users(id),
    category VARCHAR(100) NOT NULL,
    description TEXT NOT NULL,
    evidence_urls JSON NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'under_review', 'resolved_refund', 'resolved_partial', 'resolved_no_action', 'escalated')),
    resolved_by BIGINT NULL REFERENCES users(id),
    resolution_notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL
);

CREATE TABLE audit_log (
    id BIGSERIAL PRIMARY KEY,
    actor_id BIGINT NULL REFERENCES users(id),
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id BIGINT NOT NULL,
    before_state JSON NULL,
    after_state JSON NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- PLATFORM-SPECIFIC TABLES — laundry.co.ke
-- ============================================================

CREATE TABLE laundry_bookings (
    id BIGSERIAL PRIMARY KEY,
    customer_id BIGINT NOT NULL REFERENCES users(id),
    operator_id BIGINT NULL REFERENCES users(id),
    status VARCHAR(20) NOT NULL DEFAULT 'requested' CHECK (status IN ('requested', 'matched', 'picked_up', 'washing', 'ready', 'out_for_delivery', 'delivered', 'cancelled', 'disputed')),
    service_tier VARCHAR(20) NOT NULL CHECK (service_tier IN ('wash_fold', 'dry_clean', 'express')),
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
    payment_id BIGINT NULL REFERENCES payments(id),
    payment_timing VARCHAR(20) NOT NULL DEFAULT 'prepaid' CHECK (payment_timing IN ('prepaid', 'pay_on_delivery')),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE machine_capacity (
    id BIGSERIAL PRIMARY KEY,
    operator_id BIGINT NOT NULL REFERENCES users(id),
    date DATE NOT NULL,
    slot_start TIME NOT NULL,
    slot_end TIME NOT NULL,
    max_kg_capacity DECIMAL(6,2) NOT NULL,
    booked_kg DECIMAL(6,2) NOT NULL DEFAULT 0,
    max_orders INT NULL,
    booked_orders INT NOT NULL DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT TRUE
);
CREATE INDEX idx_machine_capacity_operator_date ON machine_capacity(operator_id, date);

CREATE TABLE operator_service_areas (
    id BIGSERIAL PRIMARY KEY,
    operator_id BIGINT NOT NULL REFERENCES users(id),
    area_type VARCHAR(30) NOT NULL CHECK (area_type IN ('radius', 'named_neighborhoods')),
    center_lat DECIMAL(10,7) NULL,
    center_lng DECIMAL(10,7) NULL,
    radius_km DECIMAL(5,2) NULL,
    neighborhood_names JSON NULL
);

CREATE TABLE subscriptions (
    id BIGSERIAL PRIMARY KEY,
    customer_id BIGINT NOT NULL REFERENCES users(id),
    plan_type VARCHAR(30) NOT NULL CHECK (plan_type IN ('monthly_fixed_count', 'monthly_unlimited_capped')),
    pickups_included INT NULL,
    weight_cap_kg DECIMAL(6,2) NULL,
    pickups_used_this_cycle INT NOT NULL DEFAULT 0,
    weight_used_this_cycle_kg DECIMAL(6,2) NOT NULL DEFAULT 0,
    monthly_price DECIMAL(10,2) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'paused', 'cancelled')),
    current_cycle_start DATE NOT NULL,
    current_cycle_end DATE NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE loyalty_points_ledger (
    id BIGSERIAL PRIMARY KEY,
    customer_id BIGINT NOT NULL REFERENCES users(id),
    booking_id BIGINT NULL REFERENCES laundry_bookings(id),
    points_change INT NOT NULL,
    reason VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE preferred_operators (
    customer_id BIGINT NOT NULL REFERENCES users(id),
    operator_id BIGINT NOT NULL REFERENCES users(id),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (customer_id, operator_id)
);

CREATE TABLE operator_quality_scores (
    operator_id BIGINT PRIMARY KEY REFERENCES users(id),
    average_rating DECIMAL(3,2) NOT NULL DEFAULT 0,
    completed_orders_count INT NOT NULL DEFAULT 0,
    dispute_count_90d INT NOT NULL DEFAULT 0,
    current_status VARCHAR(20) NOT NULL DEFAULT 'good_standing' CHECK (current_status IN ('good_standing', 'warning', 'suspended')),
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE disputes ADD CONSTRAINT fk_disputes_booking FOREIGN KEY (booking_id) REFERENCES laundry_bookings(id);
ALTER TABLE reviews ADD CONSTRAINT fk_reviews_booking FOREIGN KEY (booking_id) REFERENCES laundry_bookings(id);
ALTER TABLE escrow_transactions ADD CONSTRAINT fk_escrow_booking FOREIGN KEY (booking_id) REFERENCES laundry_bookings(id);

-- ============================================================
-- E-COMMERCE STORE — shared shape across all five platforms
-- ============================================================

CREATE TABLE store_products (
    id BIGSERIAL PRIMARY KEY,
    seller_id BIGINT NULL REFERENCES users(id),
    category VARCHAR(100) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    price DECIMAL(10,2) NOT NULL,
    stock_quantity INT NOT NULL DEFAULT 0,
    image_urls JSON NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'out_of_stock', 'inactive')),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE store_orders (
    id BIGSERIAL PRIMARY KEY,
    customer_id BIGINT NOT NULL REFERENCES users(id),
    payment_id BIGINT NULL REFERENCES payments(id),
    status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'paid', 'fulfilled', 'cancelled')),
    total_amount DECIMAL(10,2) NOT NULL,
    delivery_address VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE store_order_items (
    id BIGSERIAL PRIMARY KEY,
    order_id BIGINT NOT NULL REFERENCES store_orders(id),
    product_id BIGINT NOT NULL REFERENCES store_products(id),
    quantity INT NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL
);

ALTER TABLE payments ADD CONSTRAINT fk_payments_store_order FOREIGN KEY (order_id) REFERENCES store_orders(id);

-- Store catalog seed — see prd.md's E-Commerce Store Scope. Photo URLs are
-- verified-resolving Unsplash CDN URLs (checked live, not invented IDs).
INSERT INTO store_products (category, name, description, price, stock_quantity, image_urls, status) VALUES
('detergents', 'Fresh Bloom Liquid Laundry Detergent — 2L', 'Concentrated liquid detergent for everyday wash & fold loads.', 850.00, 200, '["https://images.unsplash.com/photo-1550963295-019d8a8a61c5?auto=format&fit=crop&w=800&q=80"]', 'active'),
('detergents', 'Sunshine Laundry Bar Soap — Pack of 3', 'Multi-purpose bar soap for hand-washing and stain pre-treatment.', 350.00, 300, '["https://images.unsplash.com/photo-1542038335240-86aea625b913?auto=format&fit=crop&w=800&q=80"]', 'active'),
('packaging', 'Garment Cover Bags — Pack of 10', 'Clear garment covers to protect pressed and dry-cleaned items.', 450.00, 150, '["https://images.unsplash.com/photo-1582479429421-321775166674?auto=format&fit=crop&w=800&q=80"]', 'active'),
('packaging', 'Heavy-Duty Laundry Bag — Large', 'Durable drawstring laundry bag for pickup and delivery.', 600.00, 150, '["https://images.unsplash.com/photo-1582735689369-4fe89db7114c?auto=format&fit=crop&w=800&q=80"]', 'active'),
('stain_removal', 'Pro Stain Remover Spray — 500ml', 'Fast-acting spray for oil, grass, and food stains before washing.', 480.00, 180, '["https://images.unsplash.com/photo-1563453392212-326f5e854473?auto=format&fit=crop&w=800&q=80"]', 'active'),
('equipment', 'Foldable Clothes Drying Rack', 'Space-saving rack for air-drying delicate garments.', 3200.00, 40, '["https://images.unsplash.com/photo-1760727772969-cb5cd59c6f30?auto=format&fit=crop&w=800&q=80"]', 'active'),
('equipment', 'Handheld Garment Steamer', 'Quick-touch steamer for de-wrinkling without an ironing board.', 4500.00, 35, '["https://images.unsplash.com/photo-1540544093-b0880061e1a5?auto=format&fit=crop&w=800&q=80"]', 'active'),
('equipment', 'Industrial Detergent Dispenser — Operator Pack', 'Bulk metering dispenser for commercial laundry operators.', 8500.00, 20, '["https://images.unsplash.com/photo-1757233285714-4de702bb8a56?auto=format&fit=crop&w=800&q=80"]', 'active');
