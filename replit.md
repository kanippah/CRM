# Koadi Technology CRM

## Overview
The Koadi Technology CRM is a comprehensive, single-file CRM system built with PHP and PostgreSQL. Its primary purpose is to streamline customer relationship management for businesses by facilitating the organization and tracking of contacts, leads, calls, and projects. The system supports a multi-role environment (Admin/Sales) with role-based access control. Key capabilities include advanced contact management with duplicate detection, detailed call tracking, a visual project pipeline with Kanban functionality, and robust data management features like export/import. The project aims to provide an efficient, all-in-one solution for sales and administrative teams, enhancing productivity and improving customer interaction management.

## User Preferences
I prefer clear, concise communication. When suggesting code changes, please explain the reasoning and potential impact. I value iterative development, so propose changes in manageable steps. Always ask for confirmation before implementing significant architectural changes or deleting data. Ensure all new features are thoroughly tested and documented. Do not modify the core single-file structure (`public/index.php`) unless absolutely necessary and explicitly approved. Preserve the existing Koadi Technology branding and design elements.

## System Architecture
The CRM is implemented as a single-file PHP application (`public/index.php`) leveraging PostgreSQL as its primary database.

**UI/UX Decisions:**
- **Theming:** Features light/dark mode toggle.
- **Branding:** Integrates Koadi Technology branding elements including logo, favicon, and a specific color palette (Orange #FF8C42, Blue #0066CC, Yellow #FFC72C).
- **Navigation:** Utilizes a sidebar navigation for ease of access.
- **Responsiveness:** Designed to be responsive across various devices.
- **Kanban:** Implements a drag-and-drop Kanban board for project visualization, with color-coded, fixed-width cards featuring text truncation.

**Technical Implementations:**
- **Authentication:** Email-based authentication with role-based access (Admin/Sales), "Remember Me" functionality (30-day tokens), and a secure "Forgot Password" flow (1-hour token expiration). Minimum password length is 8 characters.
- **User Management:** Secure user invitation system where users set their own passwords, and admin never has direct access to them. Features include user deactivation/activation without deletion.
- **Contact Management:** Includes duplicate detection based on normalized phone numbers and case-insensitive company names. Supports contact types (Individual/Company).
- **Call Tracking:** Allows logging calls with outcomes, duration, and associated contacts. Features call updates for chronological note-taking.
- **Project Pipeline:** Visual Kanban board with 5 stages (Lead, Qualified, Proposal, Negotiation, Won) supporting drag-and-drop stage updates.
- **Lead Management:** Supports both global (admin-created) and personal (sales user-created/grabbed) leads. Features one-click lead-to-contact conversion with automatic data parsing and source tracking.
- **Settings:** Admin-only section for setting default country codes, data export/import (JSON), and database reset functionality.
- **Database Schema:** Utilizes PostgreSQL with specific tables for Users, Password Resets, Invitations, Contacts, Calls, Call Updates, Projects, Settings, Leads, and Interactions. Tables include necessary indexes for performance.
- **API Endpoints:** A comprehensive set of API endpoints for all CRUD operations and specific functionalities like authentication, session management, lead conversion, and project stage updates.

**System Design Choices:**
- **Single-file PHP:** All application logic resides within `public/index.php` for simplified deployment and management.
- **Vanilla JavaScript:** Client-side interactivity is handled using vanilla JavaScript, avoiding external frameworks.
- **CSS:** Styling employs custom CSS properties for theming flexibility.
- **Deployment:** Docker support is included via a `Dockerfile` for containerized deployments (e.g., Coolify), running on PHP 8.2 CLI with PostgreSQL extensions.

## External Dependencies
- **PostgreSQL:** The core database for all CRM data, utilizing Replit-managed PostgreSQL via environment variables (`PGHOST`, `PGPORT`, `PGDATABASE`, `PGUSER`, `PGPASSWORD`).
- **SMTP Service:** Integrated for sending emails related to user invitations and password resets, specifically configured for `help@koaditech.com` via `mail.koaditech.com:465`.