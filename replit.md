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
- **Navigation:** Utilizes a sidebar navigation for ease of access with page persistence on browser refresh.
- **Responsiveness:** Designed to be responsive across various devices with platform-wide text size at 80% for better content density.
- **Kanban:** Implements a drag-and-drop Kanban board for project visualization, with color-coded, fixed-width cards featuring text truncation.

**Technical Implementations:**
- **Authentication:** Passwordless magic link authentication with role-based access (Admin/Sales). Users enter only their email to receive a secure login link valid for 10 minutes. No passwords are stored or required. Magic links are single-use and automatically deleted after verification.
- **User Management:** Passwordless invitation system where admin invites users by email and role only. Invited users receive a magic link (valid 24 hours) and only need to provide their full name to complete registration - no password required. Features include user deactivation/activation without deletion. Admin can delete pending invitations that haven't been accepted. Admin user editing (email, name, role) does not involve passwords - pure passwordless authentication throughout.
- **Contact Management:** Includes duplicate detection based on normalized phone numbers and case-insensitive company names. Supports contact types (Individual/Company). Admin view includes "Assigned To" column showing contact ownership, reassignment capability, and clickable contact names for detailed view (includes call history and projects). Industry field allows categorization with custom industry management. All contacts automatically assigned to creating user. Sales users can return contacts to leads pool for re-nurturing.
- **Call Tracking:** Allows logging calls with outcomes, duration, and associated contacts. Features call updates for chronological note-taking. Latest update displayed in notes column with 50-character truncation and tooltip for full text. Sales users can add updates and edit calls but cannot delete (admin-only). Clickable contact names to view full call history.
- **Project Pipeline:** Visual Kanban board with 5 stages (Lead, Qualified, Proposal, Negotiation, Won) supporting drag-and-drop stage updates.
- **Lead Management:** Supports both global (admin-created) and personal (sales user-created/grabbed) leads. Features bidirectional lead-contact conversion with automatic data parsing and source tracking. Includes pagination (20 leads per page) and industry filter for efficient browsing. Admin view includes industry column, clickable lead names for detailed interaction view, and all lead actions (Edit/Assign/Delete) accessible through view modal. Sales users can convert leads to contacts and contacts back to leads. Page state, tab selection, industry filter, and pagination position persist on browser refresh.
- **Industry Management:** Admin-only industry management system with 16 default categories (Technology, Healthcare, Finance, Manufacturing, Retail, Education, Real Estate, Construction, Transportation, Hospitality, Media, Legal, Insurance, Energy, Agriculture, Non-Profit). Admins can add custom industries and delete unused ones. Industries can be assigned to both leads and contacts.
- **Settings:** Admin-only section for setting default country codes, managing industries, data export/import (JSON with leads and industry data), and database reset functionality.
- **Database Schema:** Utilizes PostgreSQL with specific tables for Users, Password Resets, Invitations, Contacts, Calls, Call Updates, Projects, Settings, Leads, Industries, and Interactions. Tables include necessary indexes for performance. Auto-migration system ensures schema updates on app startup.
- **API Endpoints:** A comprehensive set of API endpoints for all CRUD operations and specific functionalities like authentication, session management, lead conversion, project stage updates, contact reassignment, invitation management, and industry management.

**System Design Choices:**
- **Single-file PHP:** All application logic resides within `public/index.php` for simplified deployment and management.
- **Vanilla JavaScript:** Client-side interactivity is handled using vanilla JavaScript, avoiding external frameworks.
- **CSS:** Styling employs custom CSS properties for theming flexibility.
- **Deployment:** Docker support is included via a `Dockerfile` for containerized deployments (e.g., Coolify), running on PHP 8.2 CLI with PostgreSQL extensions.

## External Dependencies
- **PostgreSQL:** The core database for all CRM data, utilizing Replit-managed PostgreSQL via environment variables (`PGHOST`, `PGPORT`, `PGDATABASE`, `PGUSER`, `PGPASSWORD`).
- **SMTP Service:** Integrated for sending emails related to user invitations and magic link authentication, specifically configured for `help@koaditech.com` via `mail.koaditech.com:465`.