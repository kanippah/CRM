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
- **Authentication:** Passwordless magic link authentication with role-based access (Admin/Sales).
- **User Management:** Passwordless invitation system for admin to invite users by email and role. Includes user deactivation/activation and deletion of pending invitations.
- **Contact Management:** Includes duplicate detection, supports contact types (Individual/Company), and allows reassignment. Sales users can return contacts to the leads pool.
- **Call Tracking:** Allows logging calls with outcomes, duration, and associated contacts. Supports call updates for chronological note-taking.
- **Project Pipeline:** Visual Kanban board with 5 stages (Lead, Qualified, Proposal, Negotiation, Won) supporting drag-and-drop updates.
- **Lead Management:** Supports global (admin-created) and personal (sales user-created/grabbed) leads with bidirectional lead-contact conversion and source tracking. Features pagination and industry filtering.
- **Industry Management:** Admin-only system for managing and assigning industries to leads and contacts.
- **Settings:** Admin-only section for default country codes, industry management, data export/import (JSON), and database reset.
- **AI Calls Monitoring:** Receives and displays post-call analysis from Retell AI voice agents via a webhook. Includes a dedicated monitoring page with filtering, search, and detailed call views (transcript, score, recommendations).
- **Calendar System:** Provides monthly and weekly views, supports event creation, and color-codes events (AI calls, bookings, schedules). Automatically integrates AI calls and Cal.com bookings.
- **Cal.com Integration:** Receives booking events via webhook for various statuses (created, confirmed, cancelled, rescheduled). Automatically matches bookings to leads/contacts and integrates them into the calendar.
- **ClickSend SMS Integration:** Automatically sends SMS booking confirmations using ClickSend's Email-to-SMS gateway for Cal.com bookings with phone numbers.
- **Global Leads Permission:** Allows admins to grant specific sales users the ability to create global leads.
- **Outscraper Lead Generation:** Receives lead data via webhook from Outscraper.com (Google Maps scraping) or through manual file upload (Excel/CSV). Supports intelligent field mapping, multiple phone/email storage, deduplication via Google Place ID, staging/preview before approval, and import tracking.

**System Design Choices:**
- **Single-file PHP:** All application logic resides within `public/index.php`.
- **Vanilla JavaScript:** Client-side interactivity is handled using vanilla JavaScript.
- **CSS:** Styling employs custom CSS properties for theming flexibility.
- **Deployment:** Docker support is included via a `Dockerfile` for containerized deployments, running on PHP 8.2 CLI with PostgreSQL extensions.
- **Database Schema:** Utilizes PostgreSQL with tables for Users, Contacts, Calls, Projects, Leads, Industries, Retell Calls, Calendar Events, and Outscraper Imports. Includes an auto-migration system.
- **API Endpoints:** A comprehensive set of API endpoints for CRUD operations and specific functionalities, including webhook receivers for Retell AI, Cal.com, and Outscraper.

## Documentation
- **Full MVP Specification:** See `docs/MVP_CRM_SPECIFICATION.md` for comprehensive documentation including database schema, all 50+ API endpoints, webhook specifications, business logic flows, and deployment instructions.

## External Dependencies
- **PostgreSQL:** The core database for all CRM data, configured via environment variables (`PGHOST`, `PGPORT`, `PGDATABASE`, `PGUSER`, `PGPASSWORD`).
- **SMTP Service:** Integrated for sending emails (invitations, magic links) via environment variables (`SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`).
- **Retell AI:** Voice agent integration via webhook for post-call analysis, received at `?api=retell.webhook`.
- **ClickSend:** SMS notifications via email-to-SMS gateway for Cal.com booking confirmations.
- **Cal.com:** Booking platform integration via webhook for event lifecycle tracking, received at `?api=cal.webhook`.
- **Outscraper:** Lead generation integration via webhook for automated Google Maps business data import, received at `?api=outscraper.webhook`.