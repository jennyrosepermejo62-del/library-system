-- ============================================
-- Library Management System v2.0
-- Fixed: real bcrypt hashes for all users
-- ============================================

CREATE DATABASE IF NOT EXISTS library_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE library_db;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) UNIQUE NOT NULL,
    email VARCHAR(200) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'member') NOT NULL DEFAULT 'member',
    member_id INT NULL,
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash VARCHAR(64) NOT NULL UNIQUE,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token (token_hash),
    INDEX idx_expires (expires_at)
);

CREATE TABLE IF NOT EXISTS books (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(500) NOT NULL,
    author VARCHAR(300) NOT NULL,
    isbn VARCHAR(20),
    cover_url VARCHAR(500),
    open_library_key VARCHAR(100),
    category VARCHAR(100),
    year_published YEAR,
    description TEXT,
    first_page_excerpt TEXT,
    total_pages INT NULL,
    language VARCHAR(50) DEFAULT 'English',
    publisher VARCHAR(200),
    status ENUM('available', 'borrowed', 'reserved') DEFAULT 'available',
    times_borrowed INT DEFAULT 0,
    date_added TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    date_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    email VARCHAR(200) UNIQUE NOT NULL,
    phone VARCHAR(30),
    address TEXT,
    membership_type ENUM('standard', 'premium', 'student') DEFAULT 'standard',
    status ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
    date_registered TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS borrowings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    book_id INT NOT NULL,
    member_id INT NOT NULL,
    borrow_date DATE NOT NULL DEFAULT (CURDATE()),
    due_date DATE NOT NULL,
    return_date DATE NULL,
    status ENUM('active', 'returned', 'overdue') DEFAULT 'active',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS book_reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    book_id INT NOT NULL,
    member_id INT NOT NULL,
    rating TINYINT NOT NULL CHECK (rating BETWEEN 1 AND 5),
    review_text TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_member_book (book_id, member_id),
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS reading_list (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    book_id INT NOT NULL,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wish (member_id, book_id),
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
);

-- ─── SEED DATA ───────────────────────────────

-- Admin: password = Admin@1234
INSERT INTO users (username, email, password_hash, role) VALUES
('admin', 'admin@bibliotheca.com', '$2y$10$mJr8OPmABohNq2cxx1YYPuUG0uQBJ.1xrvu/e90aP64T.wAxmJLYq', 'admin');

-- Members
INSERT INTO members (name, email, phone, membership_type, status) VALUES
('Maria Santos',   'maria.santos@email.com',   '09171234567', 'premium',  'active'),
('Juan dela Cruz', 'juan.delacruz@email.com',  '09281234567', 'standard', 'active'),
('Ana Reyes',      'ana.reyes@email.com',       '09391234567', 'student',  'active');

-- Member users: password = Member@1234
INSERT INTO users (username, email, password_hash, role, member_id) VALUES
('maria.santos',   'maria.santos@email.com',  '$2y$10$NmcpDToRBTRTcw1KLeSqte1tyv4jNBlaLa7cMlS2.Sh2jKTCYNBkq', 'member', 1),
('juan.delacruz',  'juan.delacruz@email.com', '$2y$10$NmcpDToRBTRTcw1KLeSqte1tyv4jNBlaLa7cMlS2.Sh2jKTCYNBkq', 'member', 2),
('ana.reyes',      'ana.reyes@email.com',     '$2y$10$NmcpDToRBTRTcw1KLeSqte1tyv4jNBlaLa7cMlS2.Sh2jKTCYNBkq', 'member', 3);

-- Books
INSERT INTO books (title, author, isbn, cover_url, open_library_key, category, year_published, description, first_page_excerpt, status, times_borrowed) VALUES
('The Great Gatsby', 'F. Scott Fitzgerald', '9780743273565',
 'https://covers.openlibrary.org/b/isbn/9780743273565-M.jpg', '/works/OL468431W', 'Fiction', 1925,
 'A story of the mysteriously wealthy Jay Gatsby and his love for the beautiful Daisy Buchanan.',
 'In my younger and more vulnerable years my father gave me some advice that I''ve been turning over in my mind ever since. "Whenever you feel like criticizing anyone," he told me, "just remember that all the people in this world haven''t had the advantages that you''ve had."',
 'available', 3),
('To Kill a Mockingbird', 'Harper Lee', '9780061935466',
 'https://covers.openlibrary.org/b/isbn/9780061935466-M.jpg', '/works/OL45883W', 'Fiction', 1960,
 'The unforgettable novel of a childhood in a sleepy Southern town and the crisis of conscience that rocked it.',
 'When he was nearly thirteen, my brother Jem got his arm badly broken at the elbow. When it healed, and Jem''s fears of never being able to play football were assuaged, he was seldom self-conscious about his injury.',
 'available', 7),
('1984', 'George Orwell', '9780451524935',
 'https://covers.openlibrary.org/b/isbn/9780451524935-M.jpg', '/works/OL1168007W', 'Dystopian', 1949,
 'A haunting tale set in a totalitarian society ruled by Big Brother.',
 'It was a bright cold day in April, and the clocks were striking thirteen. Winston Smith, his chin nuzzled into his breast in an effort to escape the vile wind, slipped quickly through the glass doors of Victory Mansions.',
 'borrowed', 12),
('The Hobbit', 'J.R.R. Tolkien', '9780261103344',
 'https://covers.openlibrary.org/b/isbn/9780261103344-M.jpg', '/works/OL262576W', 'Fantasy', 1937,
 'A fantasy novel and children''s book by English author J. R. R. Tolkien.',
 'In a hole in the ground there lived a hobbit. Not a nasty, dirty, wet hole, filled with the ends of worms and an oozy smell, nor yet a dry, bare, sandy hole with nothing in it to sit down on or to eat: it was a hobbit-hole, and that means comfort.',
 'available', 9),
('Pride and Prejudice', 'Jane Austen', '9780141439518',
 'https://covers.openlibrary.org/b/isbn/9780141439518-M.jpg', '/works/OL1394865W', 'Romance', 1813,
 'A romantic novel of manners written by Jane Austen.',
 'It is a truth universally acknowledged, that a single man in possession of a good fortune, must be in want of a wife.',
 'available', 5);

INSERT INTO borrowings (book_id, member_id, borrow_date, due_date, status) VALUES
(3, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 14 DAY), 'active');

INSERT INTO book_reviews (book_id, member_id, rating, review_text) VALUES
(1, 2, 5, 'An absolute masterpiece. Fitzgerald''s prose is like poetry.'),
(2, 1, 5, 'Life-changing read. A timeless story about justice and humanity.'),
(4, 3, 4, 'Magical from page one. Tolkien built an entire world from scratch.');
