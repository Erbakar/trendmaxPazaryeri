-- ═══════════════════════════════════════════════════════
-- OzyConnect — Örnek Varyantlı Ürünler (Seed Data)
-- Bu dosya test amaçlı parent-child ürünler oluşturur.
-- Çalıştırmadan önce mevcut veriyi etkilemediğinden emin olun.
-- ═══════════════════════════════════════════════════════

-- ───────────────────────────────────────────
-- ÖRNEK 1: Basic Tişört — 3 Renk × 3 Beden = 9 varyant
-- ───────────────────────────────────────────

-- Parent Ürün
INSERT INTO products (
    name, sku, barcode, description, base_price, stock_qty,
    has_variants, parent_id, is_active, tax_rate,
    main_image, category_id, brand_id, created_at, updated_at
) VALUES (
    'Basic Tişört', 'TISORT-001', '', 'Pamuklu basic tişört — 3 renk, 3 beden',
    199.90, 0,
    1, NULL, 1, 20,
    '', NULL, NULL, NOW(), NOW()
);

SET @parent_tisort = LAST_INSERT_ID();

-- Siyah varyantlar
INSERT INTO products (name, sku, barcode, description, base_price, stock_qty, has_variants, parent_id, is_active, tax_rate, variant_attributes, created_at, updated_at) VALUES
('Basic Tişört - Siyah S',  'TISORT-001-SYH-S',  '8690001001', 'Pamuklu basic tişört', 199.90, 25, 0, @parent_tisort, 1, 20, '{"Renk":"Siyah","Beden":"S"}', NOW(), NOW()),
('Basic Tişört - Siyah M',  'TISORT-001-SYH-M',  '8690001002', 'Pamuklu basic tişört', 199.90, 30, 0, @parent_tisort, 1, 20, '{"Renk":"Siyah","Beden":"M"}', NOW(), NOW()),
('Basic Tişört - Siyah L',  'TISORT-001-SYH-L',  '8690001003', 'Pamuklu basic tişört', 199.90, 20, 0, @parent_tisort, 1, 20, '{"Renk":"Siyah","Beden":"L"}', NOW(), NOW());

-- Beyaz varyantlar
INSERT INTO products (name, sku, barcode, description, base_price, stock_qty, has_variants, parent_id, is_active, tax_rate, variant_attributes, created_at, updated_at) VALUES
('Basic Tişört - Beyaz S',  'TISORT-001-BYZ-S',  '8690001004', 'Pamuklu basic tişört', 199.90, 15, 0, @parent_tisort, 1, 20, '{"Renk":"Beyaz","Beden":"S"}', NOW(), NOW()),
('Basic Tişört - Beyaz M',  'TISORT-001-BYZ-M',  '8690001005', 'Pamuklu basic tişört', 199.90, 35, 0, @parent_tisort, 1, 20, '{"Renk":"Beyaz","Beden":"M"}', NOW(), NOW()),
('Basic Tişört - Beyaz L',  'TISORT-001-BYZ-L',  '8690001006', 'Pamuklu basic tişört', 199.90, 10, 0, @parent_tisort, 1, 20, '{"Renk":"Beyaz","Beden":"L"}', NOW(), NOW());

-- Lacivert varyantlar
INSERT INTO products (name, sku, barcode, description, base_price, stock_qty, has_variants, parent_id, is_active, tax_rate, variant_attributes, created_at, updated_at) VALUES
('Basic Tişört - Lacivert S', 'TISORT-001-LCV-S', '8690001007', 'Pamuklu basic tişört', 199.90, 20, 0, @parent_tisort, 1, 20, '{"Renk":"Lacivert","Beden":"S"}', NOW(), NOW()),
('Basic Tişört - Lacivert M', 'TISORT-001-LCV-M', '8690001008', 'Pamuklu basic tişört', 199.90, 40, 0, @parent_tisort, 1, 20, '{"Renk":"Lacivert","Beden":"M"}', NOW(), NOW()),
('Basic Tişört - Lacivert L', 'TISORT-001-LCV-L', '8690001009', 'Pamuklu basic tişört', 199.90, 18, 0, @parent_tisort, 1, 20, '{"Renk":"Lacivert","Beden":"L"}', NOW(), NOW());


-- ───────────────────────────────────────────
-- ÖRNEK 2: iPhone 15 Silikon Kılıf — 4 Renk = 4 varyant
-- ───────────────────────────────────────────

-- Parent Ürün
INSERT INTO products (
    name, sku, barcode, description, base_price, stock_qty,
    has_variants, parent_id, is_active, tax_rate,
    main_image, category_id, brand_id, created_at, updated_at
) VALUES (
    'iPhone 15 Silikon Kılıf', 'KILIF-001', '', 'iPhone 15 uyumlu silikon kılıf — 4 renk',
    89.90, 0,
    1, NULL, 1, 20,
    '', NULL, NULL, NOW(), NOW()
);

SET @parent_kilif = LAST_INSERT_ID();

INSERT INTO products (name, sku, barcode, description, base_price, stock_qty, has_variants, parent_id, is_active, tax_rate, variant_attributes, created_at, updated_at) VALUES
('iPhone 15 Kılıf - Siyah',    'KILIF-001-SYH', '8690002001', 'iPhone 15 silikon kılıf', 89.90, 50, 0, @parent_kilif, 1, 20, '{"Renk":"Siyah"}',    NOW(), NOW()),
('iPhone 15 Kılıf - Kırmızı',  'KILIF-001-KRM', '8690002002', 'iPhone 15 silikon kılıf', 89.90, 30, 0, @parent_kilif, 1, 20, '{"Renk":"Kırmızı"}',  NOW(), NOW()),
('iPhone 15 Kılıf - Beyaz',    'KILIF-001-BYZ', '8690002003', 'iPhone 15 silikon kılıf', 89.90, 45, 0, @parent_kilif, 1, 20, '{"Renk":"Beyaz"}',    NOW(), NOW()),
('iPhone 15 Kılıf - Lacivert', 'KILIF-001-LCV', '8690002004', 'iPhone 15 silikon kılıf', 89.90, 25, 0, @parent_kilif, 1, 20, '{"Renk":"Lacivert"}', NOW(), NOW());
