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

## Recent Fixes (December 14, 2025)

### 1. Fixed Magic Link Expiration Issue (Coolify)
**Problem:** Magic links were always expired when verified in Coolify production environment.
**Root Cause:** Timezone confusion during timestamp comparison.
**Solution:** Simplified all queries to use `now()` for timestamp comparison:
- Link creation: Uses `now() + INTERVAL '10 minutes'` for login links
- Link creation: Uses `now() + INTERVAL '24 hours'` for invitation links
- Link verification: Simple comparison `expires_at > now()`
- Since `expires_at` is stored as TIMESTAMPTZ (absolute time), PostgreSQL correctly handles comparison regardless of server timezone
- Applies to: Login links (10 min), Invitation links (24 hrs), Password reset links (1 hr)

### 2. Fixed Email Sending in Development
**Problem:** Dev environment was failing SMTP connections to `mail.koaditech.com:465` (no internet).
**Solution:** Added dev mode detection that logs emails instead of sending them:
- Dev detection checks: `APP_ENV=development`, localhost, or 127.0.x.x
- In dev: emails are logged to PHP error log with full content
- In production: SMTP sending works as before
- Allows testing magic link functionality in dev without email infrastructure

## Recent Features (December 30, 2025)

### Retell AI Voice Agent Integration
**Purpose:** Receive and display post-call analysis from Retell AI voice agents.
**Implementation:**
- **Webhook Endpoint:** `?api=retell.webhook` receives POST data from Retell AI after each call
- **Security:** HMAC SHA-256 signature verification using `x-retell-signature` header. Webhook secret configurable in Settings (Admin only). Requests with invalid signatures are rejected with 401.
- **Data Captured:** call_id, agent_id, direction (inbound/outbound), caller phone, duration, transcript, analysis results, call summary, improvement recommendations, call score
- **Automatic Matching:** Incoming calls are automatically matched to existing leads/contacts by phone number
- **Calendar Integration:** Each AI call automatically creates a calendar event

### AI Calls Monitoring Page
**Features:**
- List view of all AI voice agent calls with pagination
- Filter by direction (inbound/outbound) and search
- Call cards show caller number, duration, date, and summary preview
- Detailed view includes: transcript, call score (color-coded), improvement recommendations, analysis results
- Accessible via "AI Calls" in sidebar and bottom mobile navigation

### Calendar System
**Features:**
- Monthly and weekly views with navigation
- Color-coded events: Orange for AI calls, Blue for bookings, Green for schedules
- Click on any day to add a new event
- Event types: Booking, Schedule, Meeting
- AI calls from Retell automatically appear on the calendar
- Events linked to leads and contacts when applicable

### New Database Tables
- **retell_calls:** Stores all Retell AI call data including transcripts, analysis, and recommendations
- **calendar_events:** Stores all calendar events with polymorphic links to leads, contacts, and AI calls

### New API Endpoints
- `retell.webhook` - Webhook receiver for Retell AI (no auth required)
- `retell_calls.list` - List all AI calls with pagination and filters
- `retell_calls.get` - Get single AI call details
- `calendar.list` - List calendar events by date range
- `calendar.save` - Create/update calendar events
- `calendar.delete` - Delete calendar events

### Webhook Setup Instructions
1. In Retell AI dashboard, go to Agent Settings → Webhook
2. Set webhook URL to: `https://your-domain.com/?api=retell.webhook`
3. Configure post-call analysis fields in Retell (call_summary, improvement_recommendations, call_score)
4. Retell will send `call_analyzed` events automatically after each call

## External Dependencies
- **PostgreSQL:** The core database for all CRM data, utilizing Replit-managed PostgreSQL via environment variables (`PGHOST`, `PGPORT`, `PGDATABASE`, `PGUSER`, `PGPASSWORD`). All timestamp comparisons use explicit UTC timezone to prevent expiration mismatches.
- **SMTP Service:** Integrated for sending emails related to user invitations and magic link authentication, specifically configured for `help@koaditech.com` via `mail.koaditech.com:465`. Development mode bypasses SMTP and logs emails instead.
- **Retell AI:** Voice agent integration via webhook for post-call analysis. Calls are received at `?api=retell.webhook` endpoint.