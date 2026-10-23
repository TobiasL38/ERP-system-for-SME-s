# SME ERP — Starter Application

A local web application starter for the Zetech University ERP research project. It is built incrementally around the requirements in the proposal: centralized business records, inventory visibility, role-based access and a normalized MySQL database.

## Implemented in this first working increment

- Sign in and sign out using hashed passwords, PHP sessions and CSRF tokens.
- Role-based access for administrator, manager, sales, inventory, procurement, accountant and HR users.
- Dashboard with product, low-stock, customer and supplier counts.
- Product catalogue: create, search, edit and archive products.
- Inventory: receive stock, issue stock, set an actual counted balance and view an audit trail.
- Customer directory: create, search, edit and archive records.
- Supplier directory: create, search, edit and archive records.
- Administrator user management, account activation/deactivation and a profile page for changing passwords.
- MySQL tables prepared for later sales, purchasing, finance and HR sprints.

The sales-order, purchase-order, finance, HR and reporting workflows are **not implemented yet**; their database tables are starter structures, not completed modules.

## Technology

- HTML5, CSS3 and JavaScript
- PHP 8.1+ (PDO, sessions and password hashing)
- MySQL 8+ or a compatible MariaDB version
- XAMPP and Visual Studio Code

## Run it in XAMPP on Windows

1. Install and open XAMPP. Start **Apache** and **MySQL**.
2. Copy the entire `sme-erp` folder into `C:\xampp\htdocs\`.
3. Open `http://localhost/phpmyadmin` in your browser.
4. Choose **Import**, select `sme-erp/database/schema.sql`, and run the import. The script creates the `sme_erp` database and the initial admin account.
5. Check `sme-erp/config/database.php`. The default credentials (`root` with an empty password) match a common local XAMPP setup. Update them if yours differs.
6. Open `http://localhost/sme-erp/login.php`.
7. Sign in with the initial local account below, then open **Profile** and change the password.

### Initial local account

- Username: `admin`
- Password: `Admin@123`

This is a public, predictable development credential. Do not deploy the app or enter real business data until it has been changed. Do not expose a XAMPP development server to the public internet.

## Suggested first test

1. Sign in and change the password.
2. Open **Products** and add an item such as SKU `PRD-001`, name `Sample notebook`, selling price `120`, cost `70`, reorder level `10`.
3. Open **Stock movements** and record `IN` quantity `25`.
4. Record an `OUT` movement of `3` and check that the new stock balance is `22`.
5. Try to issue more stock than is available; the application should reject it.
6. Add a customer and a supplier, sign out, and sign in again.
7. As administrator, create a second user with an inventory or sales role to verify access restrictions.

## Project structure

```text
sme-erp/
├── assets/app.css
├── config/database.php
├── database/schema.sql
├── includes/bootstrap.php
├── includes/header.php
├── includes/footer.php
├── index.php
├── login.php
├── logout.php
├── profile.php
├── products.php
├── stock.php
├── customers.php
├── suppliers.php
├── users.php
└── README.md
```

## Development backlog

1. **Sprint 1 — foundation:** authentication, roles, database, product and stock records (current increment).
2. **Sprint 2 — sales:** sales order forms, line items, stock validation, invoice generation and payment recording.
3. **Sprint 3 — procurement:** purchase orders and receiving stock through an auditable workflow.
4. **Sprint 4 — finance:** chart of accounts, balanced journal postings, receivables and reports.
5. **Sprint 5 — HR:** employee profiles, attendance and leave requests; payroll only after its requirements and calculations are defined.
6. **Sprint 6 — reports and evaluation:** filtered exports, operational KPIs, usability tests, security checks and performance tests.

## Important limitations

This is a local-development starter, not a production-ready accounting or payroll system. It needs further testing, audit and approval rules, backup procedures, rate limiting, deployment hardening (HTTPS and secure secrets), and formal user acceptance testing before use with real businesses. Do not treat the prepared finance/HR tables as an implementation of statutory accounting, Kenyan tax or payroll rules.
