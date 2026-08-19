CREATE TABLE accounts (
    id BIGINT PRIMARY KEY,
    name VARCHAR(140) NOT NULL,
    owner_id BIGINT NOT NULL,
    FOREIGN KEY (owner_id) REFERENCES users (id)
);
