-- ============================================================
-- CubeShop 电商系统 数据库建表脚本
-- 版本: v1.0 (MVP)
-- 数据库: PostgreSQL 14+
-- 日期: 2026-09-15
-- 说明: 包含系统权限、商品、库存、购物车、订单、支付、退款等核心表
-- ============================================================

-- 可选：创建 schema
-- CREATE SCHEMA IF NOT EXISTS cubeshop;
-- SET search_path TO cubeshop;

BEGIN;

-- ------------------------------------------------------------
-- 1. 系统用户
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sys_user (
    id              BIGSERIAL PRIMARY KEY,
    username        VARCHAR(64)  NOT NULL,
    email           VARCHAR(128),
    phone           VARCHAR(20),
    password        VARCHAR(255) NOT NULL,
    nickname        VARCHAR(64),
    avatar          VARCHAR(512),
    status          SMALLINT     NOT NULL DEFAULT 1,  -- 1=正常 0=禁用
    last_login_at   TIMESTAMP,
    last_login_ip   VARCHAR(45),
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at      TIMESTAMP
);

CREATE UNIQUE INDEX uk_sys_user_username ON sys_user (username) WHERE deleted_at IS NULL;
CREATE UNIQUE INDEX uk_sys_user_email    ON sys_user (email)    WHERE email IS NOT NULL AND deleted_at IS NULL;
CREATE UNIQUE INDEX uk_sys_user_phone    ON sys_user (phone)    WHERE phone IS NOT NULL AND deleted_at IS NULL;
CREATE INDEX idx_sys_user_status ON sys_user (status);

COMMENT ON TABLE  sys_user IS '系统用户（买家+管理员）';
COMMENT ON COLUMN sys_user.status IS '1=正常 0=禁用';

-- ------------------------------------------------------------
-- 2. 角色与权限（兼容 spatie/laravel-permission）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS roles (
    id          BIGSERIAL PRIMARY KEY,
    name        VARCHAR(125) NOT NULL,
    guard_name  VARCHAR(125) NOT NULL,
    created_at  TIMESTAMP,
    updated_at  TIMESTAMP,
    UNIQUE (name, guard_name)
);

CREATE TABLE IF NOT EXISTS permissions (
    id          BIGSERIAL PRIMARY KEY,
    name        VARCHAR(125) NOT NULL,
    guard_name  VARCHAR(125) NOT NULL,
    created_at  TIMESTAMP,
    updated_at  TIMESTAMP,
    UNIQUE (name, guard_name)
);

CREATE TABLE IF NOT EXISTS model_has_permissions (
    permission_id BIGINT NOT NULL REFERENCES permissions(id) ON DELETE CASCADE,
    model_type    VARCHAR(255) NOT NULL,
    model_id      BIGINT NOT NULL,
    PRIMARY KEY (permission_id, model_id, model_type)
);
CREATE INDEX idx_model_has_permissions_model ON model_has_permissions (model_id, model_type);

CREATE TABLE IF NOT EXISTS model_has_roles (
    role_id    BIGINT NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    model_type VARCHAR(255) NOT NULL,
    model_id   BIGINT NOT NULL,
    PRIMARY KEY (role_id, model_id, model_type)
);
CREATE INDEX idx_model_has_roles_model ON model_has_roles (model_id, model_type);

CREATE TABLE IF NOT EXISTS role_has_permissions (
    permission_id BIGINT NOT NULL REFERENCES permissions(id) ON DELETE CASCADE,
    role_id       BIGINT NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    PRIMARY KEY (permission_id, role_id)
);

-- ------------------------------------------------------------
-- 3. 操作日志
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sys_operation_log (
    id          BIGSERIAL PRIMARY KEY,
    user_id     BIGINT,
    module      VARCHAR(64),
    action      VARCHAR(64),
    target_type VARCHAR(64),
    target_id   BIGINT,
    content     TEXT,
    ip          VARCHAR(45),
    user_agent  VARCHAR(512),
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_sys_operation_log_user    ON sys_operation_log (user_id);
CREATE INDEX idx_sys_operation_log_created ON sys_operation_log (created_at);

COMMENT ON TABLE sys_operation_log IS '管理员/系统操作日志';

-- ------------------------------------------------------------
-- 4. 系统配置
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS system_configs (
    id           BIGSERIAL PRIMARY KEY,
    config_key   VARCHAR(128) NOT NULL UNIQUE,
    config_value TEXT,
    description  VARCHAR(255),
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

COMMENT ON TABLE system_configs IS '系统业务配置（运费、超时时间、库存预警等）';

-- ------------------------------------------------------------
-- 5. 用户收货地址
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_addresses (
    id             BIGSERIAL PRIMARY KEY,
    user_id        BIGINT       NOT NULL REFERENCES sys_user(id),
    contact_name   VARCHAR(64)  NOT NULL,
    contact_phone  VARCHAR(20)  NOT NULL,
    province       VARCHAR(64),
    city           VARCHAR(64),
    district       VARCHAR(64),
    detail_address VARCHAR(255) NOT NULL,
    is_default     BOOLEAN      NOT NULL DEFAULT FALSE,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at     TIMESTAMP
);

CREATE INDEX idx_user_addresses_user ON user_addresses (user_id);

COMMENT ON TABLE user_addresses IS '用户收货地址';

-- ------------------------------------------------------------
-- 6. 商品分类
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS categories (
    id         BIGSERIAL PRIMARY KEY,
    parent_id  BIGINT       NOT NULL DEFAULT 0,
    name       VARCHAR(128) NOT NULL,
    sort       INT          NOT NULL DEFAULT 0,
    status     SMALLINT     NOT NULL DEFAULT 1,  -- 1=启用 0=禁用
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP
);

CREATE INDEX idx_categories_parent ON categories (parent_id);
CREATE INDEX idx_categories_status ON categories (status);

COMMENT ON TABLE categories IS '商品分类（支持一级/二级）';

-- ------------------------------------------------------------
-- 7. 商品主表
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS products (
    id          BIGSERIAL PRIMARY KEY,
    category_id BIGINT,
    title       VARCHAR(255)   NOT NULL,
    subtitle    VARCHAR(255),
    main_image  VARCHAR(512),
    description TEXT,
    price       DECIMAL(12,2)  NOT NULL DEFAULT 0,  -- 展示价
    status      SMALLINT       NOT NULL DEFAULT 0,  -- 0=下架 1=上架
    sales_count INT            NOT NULL DEFAULT 0,
    sort        INT            NOT NULL DEFAULT 0,
    created_at  TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at  TIMESTAMP,
    CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id)
);

CREATE INDEX idx_products_category ON products (category_id);
CREATE INDEX idx_products_status   ON products (status);
CREATE INDEX idx_products_title    ON products (title);

COMMENT ON TABLE  products IS '商品主表';
COMMENT ON COLUMN products.status IS '0=下架 1=上架';

-- ------------------------------------------------------------
-- 8. 商品 SKU
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS product_skus (
    id         BIGSERIAL PRIMARY KEY,
    product_id BIGINT         NOT NULL REFERENCES products(id),
    sku_code   VARCHAR(64),
    specs      JSONB,                      -- {"颜色":"红","尺码":"L"}
    price      DECIMAL(12,2)  NOT NULL,
    status     SMALLINT       NOT NULL DEFAULT 1,  -- 1=启用 0=禁用
    created_at TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP
);

CREATE UNIQUE INDEX uk_product_skus_code ON product_skus (sku_code) WHERE sku_code IS NOT NULL AND deleted_at IS NULL;
CREATE INDEX idx_product_skus_product ON product_skus (product_id);

COMMENT ON TABLE product_skus IS '商品 SKU（规格）';

-- ------------------------------------------------------------
-- 9. 商品图片
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS product_images (
    id         BIGSERIAL PRIMARY KEY,
    product_id BIGINT       NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    url        VARCHAR(512) NOT NULL,
    sort       INT          NOT NULL DEFAULT 0,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_product_images_product ON product_images (product_id);

COMMENT ON TABLE product_images IS '商品多图';

-- ------------------------------------------------------------
-- 10. 库存（按 SKU）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS inventories (
    id           BIGSERIAL PRIMARY KEY,
    sku_id       BIGINT    NOT NULL UNIQUE REFERENCES product_skus(id),
    stock        INT       NOT NULL DEFAULT 0,   -- 可售库存
    locked_stock INT       NOT NULL DEFAULT 0,   -- 锁定库存（待支付）
    version      INT       NOT NULL DEFAULT 0,   -- 乐观锁
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

COMMENT ON TABLE  inventories IS 'SKU 库存';
COMMENT ON COLUMN inventories.stock IS '可售库存';
COMMENT ON COLUMN inventories.locked_stock IS '下单未支付锁定数量';
COMMENT ON COLUMN inventories.version IS '乐观锁版本号';

-- ------------------------------------------------------------
-- 11. 库存流水
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS inventory_logs (
    id            BIGSERIAL PRIMARY KEY,
    sku_id        BIGINT      NOT NULL,
    change_type   VARCHAR(32) NOT NULL,  -- lock/unlock/deduct/increase/adjust
    change_qty    INT         NOT NULL,
    before_stock  INT,
    after_stock   INT,
    before_locked INT,
    after_locked  INT,
    biz_type      VARCHAR(32),           -- order/refund/cancel/adjust
    biz_id        BIGINT,
    remark        VARCHAR(255),
    operator_id   BIGINT,
    created_at    TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_inventory_logs_sku     ON inventory_logs (sku_id, created_at);
CREATE INDEX idx_inventory_logs_biz     ON inventory_logs (biz_type, biz_id);

COMMENT ON TABLE inventory_logs IS '库存变更流水';

-- ------------------------------------------------------------
-- 12. 购物车
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cart_items (
    id         BIGSERIAL PRIMARY KEY,
    user_id    BIGINT    NOT NULL REFERENCES sys_user(id),
    sku_id     BIGINT    NOT NULL REFERENCES product_skus(id),
    quantity   INT       NOT NULL DEFAULT 1 CHECK (quantity > 0),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, sku_id)
);

CREATE INDEX idx_cart_items_user ON cart_items (user_id);

COMMENT ON TABLE cart_items IS '购物车明细';

-- ------------------------------------------------------------
-- 13. 订单主表
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
    id               BIGSERIAL PRIMARY KEY,
    order_no         VARCHAR(32)    NOT NULL,
    user_id          BIGINT         NOT NULL REFERENCES sys_user(id),
    status           VARCHAR(32)    NOT NULL,  -- pending_payment/paid/shipped/completed/cancelled/refunding/refunded
    total_amount     DECIMAL(12,2)  NOT NULL DEFAULT 0,
    freight_amount   DECIMAL(12,2)  NOT NULL DEFAULT 0,
    pay_amount       DECIMAL(12,2)  NOT NULL DEFAULT 0,
    address_snapshot JSONB          NOT NULL,  -- 收货地址快照
    remark           VARCHAR(255),
    paid_at          TIMESTAMP,
    shipped_at       TIMESTAMP,
    completed_at     TIMESTAMP,
    cancelled_at     TIMESTAMP,
    cancel_reason    VARCHAR(255),
    created_at       TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX uk_orders_order_no ON orders (order_no);
CREATE INDEX idx_orders_user_status    ON orders (user_id, status);
CREATE INDEX idx_orders_status_created ON orders (status, created_at);

COMMENT ON TABLE  orders IS '订单主表';
COMMENT ON COLUMN orders.status IS 'pending_payment/paid/shipped/completed/cancelled/refunding/refunded';
COMMENT ON COLUMN orders.address_snapshot IS '下单时收货地址快照 JSON';

-- ------------------------------------------------------------
-- 14. 订单明细（含商品快照）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS order_items (
    id            BIGSERIAL PRIMARY KEY,
    order_id      BIGINT         NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    product_id    BIGINT,
    sku_id        BIGINT,
    product_title VARCHAR(255)   NOT NULL,
    sku_specs     JSONB,
    sku_image     VARCHAR(512),
    price         DECIMAL(12,2)  NOT NULL,
    quantity      INT            NOT NULL CHECK (quantity > 0),
    total_amount  DECIMAL(12,2)  NOT NULL,
    created_at    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_order_items_order ON order_items (order_id);

COMMENT ON TABLE order_items IS '订单明细（保存下单时商品快照）';

-- ------------------------------------------------------------
-- 15. 支付记录
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payments (
    id               BIGSERIAL PRIMARY KEY,
    payment_no       VARCHAR(64)    NOT NULL,
    order_id         BIGINT         NOT NULL REFERENCES orders(id),
    order_no         VARCHAR(32)    NOT NULL,
    user_id          BIGINT         NOT NULL,
    channel          VARCHAR(32)    NOT NULL,  -- wechat/alipay
    amount           DECIMAL(12,2)  NOT NULL,
    status           VARCHAR(32)    NOT NULL,  -- pending/success/failed/closed
    channel_trade_no VARCHAR(128),
    paid_at          TIMESTAMP,
    created_at       TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX uk_payments_payment_no ON payments (payment_no);
CREATE INDEX idx_payments_order            ON payments (order_id);
CREATE INDEX idx_payments_status           ON payments (status);

COMMENT ON TABLE payments IS '支付单';

-- ------------------------------------------------------------
-- 16. 支付日志
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payment_logs (
    id            BIGSERIAL PRIMARY KEY,
    payment_id    BIGINT,
    payment_no    VARCHAR(64),
    event         VARCHAR(64),          -- create/callback/notify
    request_data  JSONB,
    response_data JSONB,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_payment_logs_payment ON payment_logs (payment_id);
CREATE INDEX idx_payment_logs_no      ON payment_logs (payment_no);

COMMENT ON TABLE payment_logs IS '支付请求与回调日志';

-- ------------------------------------------------------------
-- 17. 退款申请
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS refunds (
    id            BIGSERIAL PRIMARY KEY,
    refund_no     VARCHAR(64)    NOT NULL,
    order_id      BIGINT         NOT NULL REFERENCES orders(id),
    order_no      VARCHAR(32)    NOT NULL,
    user_id       BIGINT         NOT NULL,
    amount        DECIMAL(12,2)  NOT NULL,
    reason        VARCHAR(255),
    status        VARCHAR(32)    NOT NULL,  -- pending/approved/rejected/success/failed
    admin_remark  VARCHAR(255),
    processed_by  BIGINT,
    processed_at  TIMESTAMP,
    created_at    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX uk_refunds_refund_no ON refunds (refund_no);
CREATE INDEX idx_refunds_order           ON refunds (order_id);
CREATE INDEX idx_refunds_status          ON refunds (status);

COMMENT ON TABLE refunds IS '退款申请与处理';

-- ------------------------------------------------------------
-- 18. 预置系统配置（可选种子数据）
-- ------------------------------------------------------------
INSERT INTO system_configs (config_key, config_value, description) VALUES
    ('order.timeout_minutes', '30', '未支付订单超时自动取消时间（分钟）'),
    ('order.freight_default', '10.00', '默认运费（元）'),
    ('inventory.warning_threshold', '10', '库存预警阈值')
ON CONFLICT (config_key) DO NOTHING;

COMMIT;

-- ============================================================
-- 脚本结束
-- 使用方式: psql -U <user> -d <database> -f CubeShop_schema.sql
-- ============================================================
