
# 📚 BIBLIOTHECA
### **Advanced Library Management System **

---

### 📖 **Overview**
**Bibliotheca** is a sophisticated, web-based management solution designed to streamline library operations. Built with a focus on minimalist aesthetics and high-performance backend logic, it bridges the gap between traditional record-keeping and modern digital demands.

---

### ✨ **Key Features**
* 🔐 **Secure Authentication** – Multi-layered security featuring **Bcrypt** password hashing and token-based session validation.
* 🛠️ **Full CRUD Suite** – Centralized control for inventory management—Add, View, Edit, and Archive book records seamlessly.
* 🌍 **Open Library API** – Dynamic book discovery that automatically fetches high-quality metadata and covers using ISBNs.
* 📊 **Admin Dashboard** – Real-time analytics for monitoring total books, active loans, and overdue returns.
* 📱 **Responsive UI** – A fluid, mobile-first design that ensures a premium experience on desktop, tablet, or smartphone.
* 🌗 **Theme Engine** – Built-in Light and Dark mode support for optimal user comfort.

---

### 🛠️ **Tech Stack**

| Layer | Technology |
| :--- | :--- |
| **Backend** | PHP 8.x (Powered by **PDO**) |
| **Database** | MySQL / MariaDB (Relational) |
| **Frontend** | Vanilla JavaScript (ES6+), HTML5, CSS3 |
| **Styling** | Bootstrap 5 & Custom CSS Variables |
| **Security** | SHA-256 Session Hashing & Bcrypt |

---

### 📂 **Database Design**
The system utilizes a relational schema designed for high integrity and zero redundancy:
* **Users & Members** – Role-based access control (**Admin** vs. **Member**).
* **Books** – Real-time inventory tracking with categorized metadata.
* **Borrowings** – A junction table managing loan periods, status, and due dates.
* **Sessions** – Server-side token management for persistent and secure logins.

---

### ⚙️ **Setup and Installation**

#### **1. Database Initialization**
Import the provided schema into your PHPMyAdmin or MySQL console to set up the `library_db`.
```sql
/* Execute this in your SQL console */
SOURCE path/to/database.sql;
```

#### **2. Deployment**
Place the project folder into your local server's root directory (e.g., XAMPP's `htdocs`).
```bash
# Move folder to htdocs (Linux/Mac)
mv bibliotheca /opt/lampp/htdocs/

# For Windows: 
# Copy folder to C:\xampp\htdocs\
```

#### **3. Access the Application**
Open your preferred browser and navigate to:
> **URL:** `http://localhost/bibliotheca`

---

### 🛡️ **Security Standards**
* **SQL Injection Prevention:** All database transactions use **PDO Prepared Statements**.
* **Cryptographic Salting:** Zero plain-text storage; all passwords undergo rigorous **Bcrypt encryption**.
* **Session Integrity:** Uses SHA-256 hashing for session tokens to prevent unauthorized hijacking.

---
**Developed with a focus on Efficiency, Security, and Modern Design.** 🚀
