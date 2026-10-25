CREATE DATABASE IF NOT EXISTS orders_app CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE orders_app;

CREATE TABLE orders (
                        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        order_number VARCHAR(50)  NOT NULL UNIQUE,
                        organization VARCHAR(255) NOT NULL,
                        agent        VARCHAR(255) NOT NULL,
                        status       VARCHAR(100) NOT NULL DEFAULT 'Новый',
                        ms_id        CHAR(36)     NULL,
                        created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;