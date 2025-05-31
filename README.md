# Skill Solution - Empowering Workforce Through Smart Skill Management

## Overview

**Skill Solution** is a comprehensive, modular ERP platform tailored for all environments to efficiently manage skill development, employee performance, and internal training programs. Designed with scalability and automation in mind, it enables HR and operations teams to streamline departmental processes, track skill matrices, assign roles and responsibilities, and generate insightful performance reports helping organizations build a future-ready workforce.

## Features

- 🔹 Department & Employee Management  
- 🔹 Skill Matrix Mapping  
- 🔹 Role-Based Access Control  
- 🔹 Task Assignment & Monitoring  
- 🔹 Training Module with Evaluation  
- 🔹 Reporting & Analytics Dashboard  
- 🔹 Attendance and Leave Management  
- 🔹 Integration Ready (APIs/Webhooks)

## Tech Stack

- **Backend:** PHP (Laravel / Custom MVC)
- **Frontend:** Next.js
- **Database:** MariaDB
- **Authentication:** JWT / Laravel Auth
- **Deployment:** Apache

## Installation

1. Clone the repository:
    ```bash
    git clone https://github.com/rajeshrafaliyatriz/hp_erp.git
    ```

2. Navigate to the project directory:
    ```bash
    cd hp_erp
    ```

3. Install dependencies:
    ```bash
    composer install
    ```

4. Copy the `.env` file and configure:
    ```bash
    cp .env.example .env
    php artisan key:generate
    ```

5. Set up the database in `.env` and run migrations:
    ```bash
    php artisan migrate
    ```

6. Start the server:
    ```bash
    php artisan serve
    ```

## Folder Structure

- `app/` – Core application logic
- `routes/` – Web and API routes
- `frontend/` – Next.js (UI)
- `public/` – Public assets
- `database/` – Migrations and seeders

## Usage

- Login to the dashboard
- Add departments and roles
- Register employees
- Assign skills and training modules
- Monitor progress and export reports

## Contribution

We welcome contributions! Please fork the repo, make your changes, and submit a pull request. Follow PSR standards and comment your code clearly.

## License

This project is licensed under the [MIT License](LICENSE).

## Contact

For support or business inquiries, please contact:
**Email:** support@triz.co.in
**Website:** [https://scholarclone.com](https://scholarclone.com)