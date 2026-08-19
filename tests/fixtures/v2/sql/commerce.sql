CREATE TABLE customers (
    id BIGINT PRIMARY KEY,
    email VARCHAR(190) NOT NULL
);

CREATE TABLE orders (
    id BIGINT PRIMARY KEY,
    customer_id BIGINT NOT NULL,
    total DECIMAL(12, 2) NOT NULL,
    FOREIGN KEY (customer_id) REFERENCES customers (id)
);

CREATE VIEW customer_totals AS SELECT customer_id, SUM(total) FROM orders GROUP BY customer_id;
