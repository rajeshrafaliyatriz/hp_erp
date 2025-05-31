# hp_erp Skill Solution Corporate

## Overview

**hp_erp Skill Solution Corporate** is a modular ERP system designed to manage skill development, employee management, corporate training, and reporting for organizations. It enables companies to streamline their internal training workflows, maintain records of employee skills, and monitor performance.

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
- **Frontend:** Blade / Bootstrap / Vue.js (if used)
- **Database:** MySQL / MariaDB
- **Authentication:** JWT / Laravel Auth
- **Deployment:** Apache / NGINX with Ubuntu

## Installation

1. Clone the repository:
    ```bash
    git clone https://github.com/rajeshrafaliyatriz/hp_erp.git
    ```

2. Navigate to the project directory:
    ```bash
    cd hp_erp-skill-solution-corporate
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
- `resources/views/` – Blade templates (UI)
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