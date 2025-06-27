CREATE DATABASE c2c_marketplace;


CREATE TABLE c2c_marketplace.users (
	id int PRIMARY KEY AUTO_INCREMENT,
    name varchar(255) NOT NULL,
    password varchar(255) NOT NULL,
    email varchar(255) NOT NULL UNIQUE,
    role ENUM('seller', 'buyer')
);

CREATE TABLE c2c_marketplace.category(
	id int PRIMARY KEY AUTO_INCREMENT,
    name varchar(255) NOT NULL
);

CREATE TABLE c2c_marketplace.products (
	id int PRIMARY KEY AUTO_INCREMENT,
    name varchar(255) NOT NULL,
    description varchar(255) NOT NULL,
    price float NOT NULL DEFAULT 0,
    image_url varchar(255),
    user_id int NOT NULL,
    category_id int NOT NULL UNIQUE,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (category_id) REFERENCES category(id)
);

CREATE TABLE c2c_marketplace.orders (
	id int PRIMARY KEY AUTO_INCREMENT,
    user_id int NOT NULL,
    product_id int,
    order_date date NOT NULL,
    shippingAddress varchar(255),
    order_status varchar(255),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (product_id) REFERENCES products(id),
    total_price float NOT NULL 
);

CREATE TABLE c2c_marketplace.payments (
	id int PRIMARY KEY AUTO_INCREMENT,
    order_id int NOT NULL,
    amount float NOT NULL,
    payment_date date NOT NULL,
    method ENUM('eft', 'credit', 'debit'),
    FOREIGN  KEY (order_id) REFERENCES orders(id)
);