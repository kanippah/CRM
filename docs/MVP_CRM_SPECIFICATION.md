# Koadi Technology CRM - Comprehensive MVP Specification

**Version:** 1.0  
**Last Updated:** January 9, 2026  
**Total Codebase:** ~6,300 lines (single-file PHP application)

---

## Table of Contents

1. [System Overview](#1-system-overview)
2. [User Roles & Permissions](#2-user-roles--permissions)
3. [Database Schema](#3-database-schema)
4. [Authentication System](#4-authentication-system)
5. [Core Features](#5-core-features)
6. [API Endpoints Reference](#6-api-endpoints-reference)
7. [External Integrations](#7-external-integrations)
8. [Webhook Specifications](#8-webhook-specifications)
9. [UI/UX Components](#9-uiux-components)
10. [Business Logic & Workflows](#10-business-logic--workflows)
11. [Settings & Configuration](#11-settings--configuration)
12. [Environment Variables](#12-environment-variables)
13. [Deployment](#13-deployment)

---

## 1. System Overview

### 1.1 Purpose
The Koadi Technology CRM is a comprehensive customer relationship management system designed to streamline sales operations, track customer interactions, and manage the complete sales pipeline from lead generation to project completion.

### 1.2 Target Users
- **Sales Teams:** Manage personal leads, contacts, calls, and projects
- **Administrators:** Full system access, user management, data import/export
- **Business Owners:** Overview of sales pipeline and team performance

### 1.3 Core Value Proposition
- **Single-file deployment:** Entire application in one PHP file for easy setup
- **Passwordless authentication:** Secure magic link login, no passwords to manage
- **Integrated AI voice calls:** Retell AI integration for automated call tracking
- **Calendar booking:** Cal.com integration with SMS confirmations
- **Multi-role access control:** Granular permissions for admins and sales users

### 1.4 Technical Stack
| Component | Technology |
|-----------|------------|
| Backend | PHP 8.2+ (CLI Server) |
| Database | PostgreSQL |
| Frontend | Vanilla JavaScript (SPA) |
| Styling | Custom CSS with CSS Variables |
| Email | SMTP (SSL/TLS on port 465) |
| SMS | ClickSend Email-to-SMS Gateway |

---

## 2. User Roles & Permissions

### 2.1 Role Types

| Role | Description |
|------|-------------|
| **Admin** | Full system access, user management, settings |
| **Sales** | Limited access to leads, contacts, own data |

### 2.2 Permission Matrix

| Feature | Admin | Sales | Notes |
|---------|-------|-------|-------|
| View Dashboard | Yes | Yes | - |
| View All Leads | Yes | Global only (masked) | Phone/email masked for unassigned |
| Create Global Leads | Yes | If granted | `can_manage_global_leads` flag |
| Create Personal Leads | Yes | Yes | Auto-assigned to creator |
| Grab Global Leads | Yes | Yes | Converts to personal lead |
| Delete Leads | Yes | No | Admin only |
| View All Contacts | Yes | Yes | - |
| Create Contacts | Yes | Yes | Auto-assigned to creator |
| Delete Contacts | Yes | Yes | - |
| Reassign Contacts | Yes | No | Admin only |
| Return Contact to Lead | Yes | Own only | Converts back to lead |
| View Calls | Yes | Yes | - |
| Create/Edit Calls | Yes | Yes | - |
| Delete Calls | Yes | No | Admin only |
| View Projects | Yes | Yes | - |
| Create/Edit Projects | Yes | Yes | - |
| Delete Projects | Yes | Yes | - |
| Drag/Drop Pipeline | Yes | Yes | - |
| View AI Calls | Yes | Yes | - |
| View Calendar | Yes | Yes | Non-admins see limited events |
| Create Calendar Events | Yes | Yes | - |
| Delete Calendar Events | Yes | Own only | - |
| User Management | Yes | No | - |
| Settings | Yes | No | - |
| Export Data | Yes | No | - |
| Import Data | Yes | No | - |
| Reset Database | Yes | No | - |
| Manage Industries | Yes | No | - |

### 2.3 Special Permissions

#### `can_manage_global_leads` (Boolean)
- **Admins:** Always `TRUE` (enforced by system)
- **Sales Users:** Configurable by admin (default `FALSE`)
- **Effect:** Allows sales users to create leads that go to the global pool instead of personal leads

---

## 3. Database Schema

### 3.1 Entity Relationship Overview

```
users ─────────────────┬─────────────────────────────────────────────────┐
  │                    │                                                  │
  ▼                    ▼                                                  ▼
leads ◄──────────► contacts ◄──────────► calls ◄──────────► call_updates
  │                    │                    │
  │                    ▼                    │
  │                 projects                │
  │                    │                    │
  ▼                    ▼                    ▼
interactions    calendar_events ◄─────── retell_calls
                       ▲
                       │
               (Cal.com bookings)
```

### 3.2 Table Definitions

#### `users`
| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | SERIAL | PRIMARY KEY | Auto-increment ID |
| `username` | TEXT | UNIQUE NOT NULL | Username (derived from email) |
| `email` | TEXT | - | User email address |
| `password` | TEXT | - | Empty (passwordless auth) |
| `full_name` | TEXT | NOT NULL | Display name |
| `role` | TEXT | NOT NULL DEFAULT 'sales' | 'admin' or 'sales' |
| `status` | TEXT | DEFAULT 'active' | 'active' or 'inactive' |
| `remember_token` | TEXT | - | Hashed remember me token |
| `can_manage_global_leads` | BOOLEAN | DEFAULT FALSE | Permission flag |
| `created_at` | TIMESTAMPTZ | DEFAULT now() | Creation timestamp |

#### `leads`
| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | SERIAL | PRIMARY KEY | Auto-increment ID |
| `name` | TEXT | NOT NULL | Lead name |
| `phone` | TEXT | - | Phone number (raw format) |
| `email` | TEXT | - | Email address |
| `company` | TEXT | - | Company name |
| `address` | TEXT | - | Physical address (single line) |
| `industry` | TEXT | - | Industry category |
| `status` | TEXT | DEFAULT 'global' | 'global' or 'assigned' |
| `assigned_to` | INTEGER | FK → users(id) ON DELETE SET NULL | Assigned user |
| `google_place_id` | TEXT | - | Google Maps Place ID (Outscraper) |
| `contact_name` | TEXT | - | Contact person name (Outscraper) |
| `contact_title` | TEXT | - | Contact position/title (Outscraper) |
| `rating` | NUMERIC(3,2) | - | Google rating 0-5 (Outscraper) |
| `reviews_count` | INTEGER | - | Number of reviews (Outscraper) |
| `website` | TEXT | - | Company website URL |
| `social_links` | JSONB | - | Social media links (Outscraper) |
| `additional_phones` | JSONB | - | Extra phone numbers (Outscraper) |
| `additional_emails` | JSONB | - | Extra email addresses (Outscraper) |
| `source` | TEXT | - | Lead source (e.g., 'outscraper') |
| `created_at` | TIMESTAMPTZ | DEFAULT now() | Creation timestamp |
| `updated_at` | TIMESTAMPTZ | DEFAULT now() | Last update timestamp |

**Indexes:**
- `idx_leads_status` on `status`
- `idx_leads_assigned` on `assigned_to`
- `idx_leads_google_place_id` on `google_place_id`
- `idx_leads_source` on `source`

#### `interactions`
| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | SERIAL | PRIMARY KEY | Auto-increment ID |
| `lead_id` | INTEGER | FK → leads(id) ON DELETE CASCADE | Related lead |
| `user_id` | INTEGER | FK → users(id) ON DELETE SET NULL | User who created |
| `type` | TEXT | NOT NULL | 'note', 'grabbed', etc. |
| `notes` | TEXT | - | Interaction details |
| `created_at` | TIMESTAMPTZ | DEFAULT now() | Creation timestamp |

#### `contacts`
| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | SERIAL | PRIMARY KEY | Auto-increment ID |
| `type` | TEXT | - | 'Individual' or 'Company' |
| `company` | TEXT | - | Company name |
| `name` | TEXT | - | Contact name |
| `email` | TEXT | - | Email address |
| `phone_country` | TEXT | - | Country code (e.g., '+1') |
| `phone_number` | TEXT | - | Phone number without country |
| `source` | TEXT | - | Lead source |
| `notes` | TEXT | - | Notes |
| `industry` | TEXT | - | Industry category |
| `assigned_to` | INTEGER | FK → users(id) ON DELETE SET NULL | Assigned user |
| `created_at` | TIMESTAMPTZ | DEFAULT now() | Creation timestamp |
| `updated_at` | TIMESTAMPTZ | DEFAULT now() | Last update timestamp |

**Indexes:**
- `idx_contacts_assigned` on `assigned_to`
- `idx_contacts_company` on `LOWER(BTRIM(COALESCE(company,'')))`
- `idx_contacts_phone_unique` UNIQUE on normalized phone

#### `calls`
| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | SERIAL | PRIMARY KEY | Auto-increment ID |
| `contact_id` | INTEGER | FK → contacts(id) ON DELETE CASCADE | Related contact |
| `when_at` | TIMESTAMPTZ | NOT NULL | Call date/time |
| `outcome` | TEXT | - | Call outcome |
| `duration_min` | INTEGER | - | Duration in minutes |
| `notes` | TEXT | - | Call notes |
| `assigned_to` | INTEGER | FK → users(id) ON DELETE SET NULL | Assigned user |
| `created_at` | TIMESTAMPTZ | DEFAULT now() | Creation timestamp |
| `updated_at` | TIMESTAMPTZ | DEFAULT now() | Last update timestamp |

**Indexes:**
- `idx_calls_assigned` on `assigned_to`

#### `call_updates`
| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | SERIAL | PRIMARY KEY | Auto-increment ID |
| `call_id` | INTEGER | FK → calls(id) ON DELETE CASCADE | Related call |
| `user_id` | INTEGER | FK → users(id) ON DELETE SET NULL | User who created |
| `notes` | TEXT | - | Update notes |
| `created_at` | TIMESTAMPTZ | DEFAULT now() | Creation timestamp |

**Indexes:**
- `idx_call_updates_call` on `call_id`

#### `projects`
| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | SERIAL | PRIMARY KEY | Auto-increment ID |
| `contact_id` | INTEGER | FK → contacts(id) ON DELETE CASCADE | Related contact |
| `name` | TEXT | - | Project name |
| `value` | NUMERIC | - | Project value ($) |
| `stage` | TEXT | - | Pipeline stage |
| `next_date` | DATE | - | Next follow-up date |
| `notes` | TEXT | - | Project notes |
| `assigned_to` | INTEGER | FK → users(id) ON DELETE SET NULL | Assigned user |
| `created_at` | TIMESTAMPTZ | DEFAULT now() | Creation timestamp |
| `updated_at` | TIMESTAMPTZ | DEFAULT now() | Last update timestamp |

**Indexes:**
- `idx_projects_assigned` on `assigned_to`

#### `settings`
| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `key` | TEXT | PRIMARY KEY | Setting key |
| `value` | TEXT | - | Setting value |

#### `magic_links`
| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | SERIAL | PRIMARY KEY | Auto-increment ID |
| `email` | TEXT | NOT NULL | Target email |
| `token` | TEXT | NOT NULL | Hashed token |
| `type` | TEXT | NOT NULL | 'login' or 'invite' |
| `role` | TEXT | - | Role for invites |
| `expires_at` | TIMESTAMPTZ | NOT NULL | Expiration time |
| `created_at` | TIMESTAMPTZ | DEFAULT now() | Creation timestamp |

**Indexes:**
- `idx_magic_links_email` on `email`
- `idx_magic_links_expires` on `expires_at`

#### `password_resets`
| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | SERIAL | PRIMARY KEY | Auto-increment ID |
| `email` | TEXT | NOT NULL | Target email |
| `token` | TEXT | NOT NULL | Hashed token |
| `expires_at` | TIMESTAMPTZ | NOT NULL | Expiration time |
| `created_at` | TIMESTAMPTZ | DEFAULT now() | Creation timestamp |

#### `industries`
| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | SERIAL | PRIMARY KEY | Auto-increment ID |
| `name` | TEXT | UNIQUE NOT NULL | Industry name |
| `created_at` | TIMESTAMPTZ | DEFAULT NOW() | Creation timestamp |

**Default Industries:**
Technology, Healthcare, Finance, Manufacturing, Retail, Real Estate, Construction, Education, Hospitality, Legal Services, Marketing & Advertising, Transportation, Food & Beverage, Entertainment, Telecommunications, Energy, Agriculture, Insurance, Consulting, Other

#### `retell_calls`
| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | SERIAL | PRIMARY KEY | Auto-increment ID |
| `retell_call_id` | TEXT | UNIQUE NOT NULL | Retell's call ID |
| `agent_id` | TEXT | - | AI agent ID |
| `call_type` | TEXT | - | Call type |
| `direction` | TEXT | - | 'inbound' or 'outbound' |
| `from_number` | TEXT | - | Caller number |
| `to_number` | TEXT | - | Called number |
| `call_status` | TEXT | - | Call status |
| `disconnection_reason` | TEXT | - | Why call ended |
| `start_timestamp` | BIGINT | - | Start time (ms) |
| `end_timestamp` | BIGINT | - | End time (ms) |
| `duration_seconds` | INTEGER | - | Call duration |
| `transcript` | TEXT | - | Full transcript |
| `transcript_object` | JSONB | - | Structured transcript |
| `analysis_results` | JSONB | - | AI analysis |
| `call_summary` | TEXT | - | Call summary |
| `improvement_recommendations` | TEXT | - | Suggestions |
| `call_score` | INTEGER | - | Quality score (0-100) |
| `metadata` | JSONB | - | Additional metadata |
| `raw_payload` | JSONB | - | Full webhook payload |
| `recording_url` | TEXT | - | Call recording URL |
| `lead_id` | INTEGER | FK → leads(id) ON DELETE SET NULL | Matched lead |
| `contact_id` | INTEGER | FK → contacts(id) ON DELETE SET NULL | Matched contact |
| `created_at` | TIMESTAMPTZ | DEFAULT now() | Creation timestamp |
| `updated_at` | TIMESTAMPTZ | DEFAULT now() | Last update timestamp |

**Indexes:**
- `idx_retell_calls_retell_id` on `retell_call_id`
- `idx_retell_calls_from` on `from_number`
- `idx_retell_calls_to` on `to_number`
- `idx_retell_calls_status` on `call_status`
- `idx_retell_calls_created` on `created_at`

#### `calendar_events`
| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | SERIAL | PRIMARY KEY | Auto-increment ID |
| `title` | TEXT | NOT NULL | Event title |
| `description` | TEXT | - | Event description |
| `event_type` | TEXT | NOT NULL DEFAULT 'booking' | 'booking', 'call', 'schedule', 'meeting' |
| `start_time` | TIMESTAMPTZ | NOT NULL | Event start |
| `end_time` | TIMESTAMPTZ | - | Event end |
| `all_day` | BOOLEAN | DEFAULT false | All-day event |
| `location` | TEXT | - | Location/URL |
| `status` | TEXT | DEFAULT 'scheduled' | 'scheduled', 'confirmed', 'cancelled', 'rescheduled', 'completed' |
| `booking_uid` | TEXT | - | Cal.com booking UID |
| `related_entity_type` | TEXT | - | Related entity type |
| `related_entity_id` | INTEGER | - | Related entity ID |
| `retell_call_id` | INTEGER | FK → retell_calls(id) ON DELETE SET NULL | Related AI call |
| `lead_id` | INTEGER | FK → leads(id) ON DELETE SET NULL | Related lead |
| `contact_id` | INTEGER | FK → contacts(id) ON DELETE SET NULL | Related contact |
| `created_by` | INTEGER | FK → users(id) ON DELETE SET NULL | Creator |
| `assigned_to` | INTEGER | FK → users(id) ON DELETE SET NULL | Assignee |
| `color` | TEXT | - | Event color (#hex) |
| `created_at` | TIMESTAMPTZ | DEFAULT now() | Creation timestamp |
| `updated_at` | TIMESTAMPTZ | DEFAULT now() | Last update timestamp |

**Indexes:**
- `idx_calendar_events_start` on `start_time`
- `idx_calendar_events_type` on `event_type`
- `idx_calendar_events_assigned` on `assigned_to`
- `idx_calendar_events_created_by` on `created_by`

#### `outscraper_imports`
| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | SERIAL | PRIMARY KEY | Auto-increment ID |
| `task_id` | TEXT | - | Outscraper task ID |
| `query` | TEXT | - | Search query used |
| `total_records` | INTEGER | DEFAULT 0 | Total records received |
| `imported_count` | INTEGER | DEFAULT 0 | Successfully imported |
| `skipped_count` | INTEGER | DEFAULT 0 | Skipped (empty name) |
| `duplicate_count` | INTEGER | DEFAULT 0 | Duplicates found |
| `error_count` | INTEGER | DEFAULT 0 | Errors during import |
| `status` | TEXT | DEFAULT 'processing' | 'processing', 'completed' |
| `error_details` | JSONB | - | Error details array |
| `created_at` | TIMESTAMPTZ | DEFAULT now() | Import timestamp |

**Indexes:**
- `idx_outscraper_imports_created` on `created_at`

---

## 4. Authentication System

### 4.1 Magic Link Login Flow

```
┌─────────────┐    ┌─────────────┐    ┌─────────────┐    ┌─────────────┐
│   User      │    │   CRM API   │    │   Email     │    │   User      │
│  (Login)    │    │             │    │   Server    │    │  (Verify)   │
└──────┬──────┘    └──────┬──────┘    └──────┬──────┘    └──────┬──────┘
       │                  │                  │                  │
       │ POST ?api=login  │                  │                  │
       │ {email: "..."}   │                  │                  │
       │─────────────────►│                  │                  │
       │                  │                  │                  │
       │                  │ Generate token   │                  │
       │                  │ (64 hex chars)   │                  │
       │                  │                  │                  │
       │                  │ Store hashed in  │                  │
       │                  │ magic_links      │                  │
       │                  │ (expires: 10min) │                  │
       │                  │                  │                  │
       │                  │ Send email       │                  │
       │                  │─────────────────►│                  │
       │                  │                  │                  │
       │ {"ok": true}     │                  │ Email delivered  │
       │◄─────────────────│                  │─────────────────►│
       │                  │                  │                  │
       │                  │                  │                  │
       │                  │ POST ?api=verify_magic_link        │
       │                  │ {token: "...", type: "login"}      │
       │                  │◄────────────────────────────────────│
       │                  │                  │                  │
       │                  │ Verify token     │                  │
       │                  │ Create session   │                  │
       │                  │ Delete link      │                  │
       │                  │                  │                  │
       │                  │ {"ok": true, "user": {...}}        │
       │                  │────────────────────────────────────►│
       │                  │                  │                  │
```

### 4.2 Token Details

| Token Type | Length | Expiration | Storage |
|------------|--------|------------|---------|
| Login Magic Link | 64 hex chars | 10 minutes | Hashed in `magic_links` |
| Invite Magic Link | 64 hex chars | 24 hours | Hashed in `magic_links` |
| Remember Token | 64 hex chars | Indefinite | Hashed in `users.remember_token` |
| Password Reset | 64 hex chars | 1 hour | Hashed in `password_resets` |

### 4.3 Session Data

When authenticated, the following data is stored in `$_SESSION`:

```php
$_SESSION['user_id']              // User ID
$_SESSION['username']             // Username
$_SESSION['email']                // Email address
$_SESSION['full_name']            // Display name
$_SESSION['role']                 // 'admin' or 'sales'
$_SESSION['can_manage_global_leads'] // Boolean permission
```

### 4.4 Remember Me

When login is successful with "Remember Me":
1. Generate 64-char random token
2. Hash token and store in `users.remember_token`
3. Set cookie `remember_token` with raw token (secure, httponly)
4. On subsequent visits, verify cookie against stored hash
5. Recreate session if valid

---

## 5. Core Features

### 5.1 Dashboard
- **Total Contacts:** Count of all contacts
- **Calls (7 days):** Calls made in the last week
- **Open Projects:** Projects not in "Won" stage
- **Recent Contacts:** Last 5 contacts added
- **Recent Calls:** Last 5 calls with outcomes

### 5.2 Lead Management

#### Lead Status Types
| Status | Description |
|--------|-------------|
| `global` | Available for any sales user to grab |
| `assigned` | Owned by a specific user |

#### Lead Operations
- **Create Lead:** Creates global (admin/permitted) or personal (sales) lead
- **Edit Lead:** Owner/admin can edit lead details
- **Delete Lead:** Admin only
- **Grab Lead:** Sales users claim global leads
- **Convert to Contact:** Moves lead to contacts table
- **View Interactions:** History of all activity on lead

#### Data Masking (Sales Users)
For global leads not owned by the user:
- Phone: Shows first 3 chars + `***`
- Email: Shows `***`
- Address: Shows `***`

### 5.3 Contact Management

#### Contact Types
- **Individual:** Personal contact
- **Company:** Business entity

#### Duplicate Detection
On save, checks for duplicates by:
1. Normalized phone number (all non-digits removed)
2. Case-insensitive company name match

Returns `duplicate_of` field if match found.

#### Contact Operations
- Create, edit, delete contacts
- Reassign to different user (admin only)
- Return to lead pool (owner/admin)
- View associated calls and projects

### 5.4 Call Tracking

#### Call Outcomes
- Attempted
- Connected
- Voicemail
- Callback
- No Answer
- Not Interested
- Follow Up

#### Call Updates
Chronological notes added to calls over time. Latest update shown in call list with 50-char truncation and tooltip.

### 5.5 Project Pipeline (Kanban)

#### Pipeline Stages
| Stage | Order | Description |
|-------|-------|-------------|
| Lead | 1 | Initial stage |
| Qualified | 2 | Lead is qualified |
| Proposal | 3 | Proposal sent |
| Negotiation | 4 | In negotiation |
| Won | 5 | Deal closed |

#### Kanban Features
- Drag-and-drop between stages
- Color-coded cards
- Fixed-width cards with text truncation
- Project value display
- Next follow-up date

### 5.6 Calendar

#### Event Types
| Type | Color | Source |
|------|-------|--------|
| booking | Blue (#0066CC) | Cal.com |
| call | Orange (#FF8C42) | Retell AI |
| schedule | Green | Manual |
| meeting | Default | Manual |

#### Views
- **Monthly:** Calendar grid with event dots
- **Weekly:** Detailed hourly view

#### Event Statuses
- scheduled
- confirmed
- cancelled (red)
- rescheduled
- completed

### 5.7 AI Calls Monitoring

#### Features
- List all Retell AI calls with pagination
- Filter by direction (inbound/outbound)
- Search by phone, transcript, name
- Date range filtering

#### Call Detail View
- Full transcript display
- Call score (color-coded: green ≥70, yellow ≥40, red <40)
- AI-generated summary
- Improvement recommendations
- Recording playback (if available)
- Matched lead/contact info

---

## 6. API Endpoints Reference

### 6.1 Authentication Endpoints

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `?api=login` | POST | No | Request magic link |
| `?api=logout` | POST | Yes | End session |
| `?api=session` | GET | No | Check session/remember token |
| `?api=verify_magic_link` | POST | No | Verify magic link token |
| `?api=send_invite` | POST | Admin | Send user invitation |
| `?api=accept_invite` | POST | No | Accept invitation |

#### `?api=login`
**Request:**
```json
{
  "email": "user@example.com"
}
```
**Response:**
```json
{
  "ok": true,
  "message": "If an account exists with this email, a login link has been sent."
}
```

#### `?api=session`
**Response (authenticated):**
```json
{
  "user": {
    "id": 1,
    "username": "admin",
    "full_name": "Administrator",
    "role": "admin",
    "can_manage_global_leads": true
  }
}
```

### 6.2 User Management Endpoints

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `?api=users.list` | GET | Admin | List users and pending invites |
| `?api=users.save` | POST | Admin | Update user |
| `?api=users.delete&id=X` | DELETE | Admin | Delete user |
| `?api=users.toggle_status` | POST | Admin | Activate/deactivate user |
| `?api=invitations.delete&id=X` | DELETE | Admin | Delete pending invite |

### 6.3 Lead Endpoints

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `?api=leads.list` | GET | Yes | List leads with filters |
| `?api=leads.save` | POST | Yes | Create/update lead |
| `?api=leads.delete&id=X` | DELETE | Admin | Delete lead |
| `?api=leads.grab` | POST | Yes | Claim global lead |
| `?api=leads.import` | POST | Admin | Bulk import leads |
| `?api=leads.convert` | POST | Yes | Convert lead to contact |

#### `?api=leads.list` Query Parameters
| Parameter | Type | Description |
|-----------|------|-------------|
| `q` | string | Search query |
| `type` | string | 'all', 'global', 'personal', 'assigned' |
| `industry` | string | Industry filter |
| `page` | int | Page number (default: 1) |
| `limit` | int | Items per page (default: 20, max: 100) |

### 6.4 Contact Endpoints

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `?api=contacts.list` | GET | Yes | List contacts |
| `?api=contacts.save` | POST | Yes | Create/update contact |
| `?api=contacts.delete&id=X` | DELETE | Yes | Delete contact |
| `?api=contacts.reassign` | POST | Admin | Reassign to user |
| `?api=contacts.returnToLead` | POST | Yes | Convert back to lead |

### 6.5 Call Endpoints

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `?api=calls.list` | GET | Yes | List calls |
| `?api=calls.save` | POST | Yes | Create/update call |
| `?api=calls.delete&id=X` | DELETE | Yes | Delete call |
| `?api=calls.reassign` | POST | Admin | Reassign to user |
| `?api=call_updates.list&call_id=X` | GET | Yes | List call updates |
| `?api=call_updates.save` | POST | Yes | Add call update |

### 6.6 Project Endpoints

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `?api=projects.list` | GET | Yes | List projects |
| `?api=projects.save` | POST | Yes | Create/update project |
| `?api=projects.delete&id=X` | DELETE | Yes | Delete project |
| `?api=projects.stage` | POST | Yes | Update project stage |
| `?api=projects.reassign` | POST | Admin | Reassign to user |

### 6.7 Industry Endpoints

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `?api=industries.list` | GET | Yes | List industries |
| `?api=industries.save` | POST | Admin | Create/update industry |
| `?api=industries.delete&id=X` | DELETE | Admin | Delete industry |

### 6.8 Calendar Endpoints

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `?api=calendar.list` | GET | Yes | List events in range |
| `?api=calendar.save` | POST | Yes | Create/update event |
| `?api=calendar.delete&id=X` | DELETE | Yes | Delete event |

### 6.9 AI Calls Endpoints

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `?api=retell_calls.list` | GET | Yes | List AI calls |
| `?api=retell_calls.get&id=X` | GET | Yes | Get call details |

### 6.10 Settings Endpoints

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `?api=settings.get&key=X` | GET | Yes | Get setting value |
| `?api=settings.set` | POST | Yes | Set setting value |
| `?api=settings.exists&key=X` | GET | Yes | Check if setting exists |

### 6.11 Data Management Endpoints

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `?api=export` | GET | Admin | Export all data as JSON |
| `?api=import` | POST | Admin | Import data from JSON |
| `?api=reset` | POST | Admin | Reset database tables |
| `?api=stats` | GET | Yes | Get dashboard statistics |
| `?api=countries` | GET | Yes | Get country code list |
| `?api=interactions.list&lead_id=X` | GET | Yes | List lead interactions |
| `?api=interactions.save` | POST | Yes | Save interaction |

### 6.12 Webhook Endpoints

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `?api=retell.webhook` | POST | No* | Retell AI webhook |
| `?api=cal.webhook` | POST | No* | Cal.com webhook |
| `?api=outscraper.webhook` | POST | No* | Outscraper lead import webhook |
| `?api=outscraper_imports.list` | GET | Admin | List Outscraper import history |

*Webhooks use signature verification instead of session auth

---

## 7. External Integrations

### 7.1 Retell AI (Voice Agent)

#### Purpose
Receive post-call analysis from Retell AI voice agents, including transcripts, summaries, and quality scores.

#### Configuration
- Store Retell API key in Settings (`retell_api_key`)
- Configure webhook URL in Retell dashboard: `https://your-domain.com/?api=retell.webhook`

#### Automatic Features
- Phone number matching to leads/contacts
- Calendar event creation for each call
- Call quality scoring and recommendations

### 7.2 Cal.com (Booking)

#### Purpose
Receive booking lifecycle events from Cal.com, automatically creating and updating calendar events.

#### Supported Events
| Event | Action |
|-------|--------|
| `BOOKING_CREATED` | Create calendar event, send SMS |
| `BOOKING_CONFIRMED` | Update status to confirmed |
| `BOOKING_CANCELLED` | Update status to cancelled, color to red |
| `BOOKING_RESCHEDULED` | Update times and status |

#### Automatic Features
- Email matching to leads/contacts
- SMS confirmation via ClickSend
- Rich event descriptions with attendee info

#### Configuration
1. In Cal.com dashboard → Settings → Webhooks
2. Add webhook URL: `https://your-domain.com/?api=cal.webhook`
3. Select triggers: Created, Confirmed, Cancelled, Rescheduled
4. Optionally configure secret in CRM settings (`cal_webhook_secret`)

### 7.3 ClickSend (SMS)

#### Purpose
Send SMS booking confirmations when Cal.com bookings are created.

#### How It Works
Uses ClickSend's Email-to-SMS gateway:
1. Format: `{phone}@sms.clicksend.com`
2. Uses existing SMTP credentials
3. Plain text email = SMS message

#### SMS Format
```
Hi {FirstName}! Your appointment with Koadi Technology is confirmed for {Date} at {Time}. Check your email for the meeting link. Questions? Reply here.
```

#### Setup Requirements
1. Create ClickSend account
2. Go to SMS → Email SMS → Add Allowed Addresses
3. Add your SMTP_USER email address
4. Ensure Cal.com collects phone numbers

#### Phone Normalization
- Strips all non-digits
- Adds country code `+1` if 10 digits (US/Canada)

---

## 8. Webhook Specifications

### 8.1 Retell AI Webhook

**Endpoint:** `POST ?api=retell.webhook`

**Headers:**
- `Content-Type: application/json`
- `x-retell-signature: {HMAC-SHA256 signature}`

**Signature Verification:**
```php
$signature = hash_hmac('sha256', $rawPayload, $apiKey);
```

**Payload Structure:**
```json
{
  "event": "call_analyzed",
  "call": {
    "call_id": "retell_xxx",
    "agent_id": "agent_xxx",
    "call_type": "web",
    "direction": "inbound",
    "from_number": "+15551234567",
    "to_number": "+15559876543",
    "call_status": "ended",
    "disconnection_reason": "user_hangup",
    "start_timestamp": 1704067200000,
    "end_timestamp": 1704067500000,
    "transcript": "Full conversation text...",
    "transcript_object": [...],
    "recording_url": "https://...",
    "call_analysis": {
      "call_summary": "Summary of the call...",
      "custom_analysis_data": {
        "improvement_recommendations": "...",
        "call_score": 85
      }
    }
  }
}
```

**Processed Events:**
- `call_analyzed`
- `call_ended`

### 8.2 Cal.com Webhook

**Endpoint:** `POST ?api=cal.webhook`

**Headers:**
- `Content-Type: application/json`
- `x-cal-signature-256: {HMAC-SHA256 signature}` (optional)

**Payload Structure (BOOKING_CREATED):**
```json
{
  "triggerEvent": "BOOKING_CREATED",
  "payload": {
    "uid": "booking_xxx",
    "title": "30 Minute Meeting",
    "startTime": "2026-01-15T10:00:00Z",
    "endTime": "2026-01-15T10:30:00Z",
    "location": "integrations:daily",
    "attendees": [{
      "name": "John Doe",
      "email": "john@example.com",
      "timeZone": "America/New_York",
      "phoneNumber": "+15551234567"
    }],
    "organizer": {
      "name": "Jane Smith",
      "email": "jane@company.com"
    },
    "responses": {
      "What is this meeting about?": {
        "label": "Purpose",
        "value": "Discuss project requirements"
      }
    },
    "additionalNotes": "Please bring the proposal",
    "metadata": {
      "videoCallUrl": "https://cal.com/video/xxx"
    }
  }
}
```

### 8.3 Outscraper Webhook (Lead Generation)

**Endpoint:** `POST ?api=outscraper.webhook`

**Headers:**
- `Content-Type: application/json`
- `x-outscraper-signature: {HMAC-SHA256 signature}` (optional, recommended)

**Purpose:** Automatically imports leads from Outscraper.com when scraping tasks complete. Supports Google Maps business data with intelligent field mapping and deduplication.

**Payload Structure:**
```json
{
  "id": "task_uuid",
  "status": "finished",
  "query": "real estate agency, new jersey",
  "data": [
    {
      "name": "ABC Real Estate",
      "place_id": "ChIJxxx",
      "phone": "+1-555-123-4567",
      "contact_phone": "+1-555-987-6543",
      "company_phone": "+1-555-111-2222",
      "company_phones": ["+1-555-111-2222", "+1-555-111-3333"],
      "email": "info@abcrealty.com",
      "website": "https://abcrealty.com",
      "address": "123 Main St, Hoboken, NJ 07030, USA",
      "street": "123 Main St",
      "city": "Hoboken",
      "state": "New Jersey",
      "postal_code": "07030",
      "country": "United States",
      "category": "Real estate agency",
      "subtypes": "Real estate agent, Property management",
      "full_name": "John Smith",
      "first_name": "John",
      "last_name": "Smith",
      "title": "Sales Manager",
      "rating": 4.8,
      "reviews": 127,
      "company_linkedin": "https://linkedin.com/company/abc-realty",
      "company_facebook": "https://facebook.com/abcrealty",
      "company_instagram": "https://instagram.com/abcrealty",
      "company_x": "https://x.com/abcrealty",
      "contact_linkedin": "https://linkedin.com/in/johnsmith"
    }
  ]
}
```

**Field Mapping:**

| Outscraper Field | CRM Lead Field | Priority/Notes |
|------------------|----------------|----------------|
| `name` | `name`, `company` | Company name |
| `place_id` | `google_place_id` | Used for deduplication |
| `contact_phone` → `phone` → `company_phone` | `phone` | Smart selection (priority order) |
| All other phones | `additional_phones` (JSON) | Stored for outreach |
| `email` | `email` | Primary email |
| Additional emails | `additional_emails` (JSON) | Stored for outreach |
| `address` or combined fields | `address` | Single line format |
| `category` or `subtypes[0]` | `industry` | Business type |
| `full_name` or `first_name + last_name` | `contact_name` | Contact person |
| `title` | `contact_title` | Position/role |
| `rating` | `rating` | Google rating (0-5) |
| `reviews` | `reviews_count` | Number of reviews |
| `website` or `domain` | `website` | Company website |
| Social links | `social_links` (JSON) | LinkedIn, Facebook, etc. |

**Deduplication:**
- Uses `google_place_id` to prevent duplicate imports
- If a lead with the same `place_id` exists, the record is skipped

**Import Tracking:**
Imports are logged in `outscraper_imports` table with:
- Total records received
- Successfully imported count
- Duplicate count (skipped)
- Error count and details

**Response:**
```json
{
  "ok": true,
  "import_id": 123,
  "total": 50,
  "imported": 45,
  "duplicates": 3,
  "skipped": 1,
  "errors": 1
}
```

**Setup Instructions:**
1. Go to Settings → Outscraper Lead Generation in your CRM
2. Generate or enter a webhook secret
3. Copy the webhook URL: `https://your-domain.com/?api=outscraper.webhook`
4. In Outscraper, configure your scraping task to send results to this webhook
5. Include the webhook secret in the `x-outscraper-signature` header (HMAC-SHA256)

---

## 9. UI/UX Components

### 9.1 Layout Structure

```
┌──────────────────────────────────────────────────────────────────┐
│                         Header Bar                                │
│  [Logo] Koadi Technology CRM     [Theme Toggle] [User Menu]      │
├────────────────┬─────────────────────────────────────────────────┤
│                │                                                  │
│   Sidebar      │              Main Content Area                   │
│                │                                                  │
│   - Dashboard  │   [Page-specific content]                        │
│   - Leads      │                                                  │
│   - Contacts   │                                                  │
│   - Calls      │                                                  │
│   - Pipeline   │                                                  │
│   - Calendar   │                                                  │
│   - AI Calls   │                                                  │
│   - Settings*  │                                                  │
│                │                                                  │
├────────────────┴─────────────────────────────────────────────────┤
│                     Mobile Bottom Navigation                      │
│    [Dashboard] [Leads] [Contacts] [Calls] [Pipeline] [More]      │
└──────────────────────────────────────────────────────────────────┘
```
*Settings visible to admins only

### 9.2 Theming

#### CSS Variables
```css
:root {
  --kt-orange: #FF8C42;
  --kt-blue: #0066CC;
  --kt-yellow: #FFC72C;
  --kt-dark-blue: #003366;
}

[data-theme="light"] {
  --bg: #f8f9fa;
  --panel: #ffffff;
  --text: #212529;
  --muted: #6c757d;
  --border: #dee2e6;
  --brand: var(--kt-blue);
  --accent: var(--kt-orange);
}

[data-theme="dark"] {
  --bg: #0f172a;
  --panel: #1e293b;
  --text: #e2e8f0;
  --muted: #94a3b8;
  --border: #334155;
  --brand: var(--kt-blue);
  --accent: var(--kt-orange);
}
```

### 9.3 Modals

| Modal | Purpose | Fields |
|-------|---------|--------|
| Lead Form | Create/edit leads | Name, Phone, Email, Company, Address, Industry |
| Lead View | View lead details | All info, interactions list, action buttons |
| Contact Form | Create/edit contacts | Type, Company, Name, Email, Country, Phone, Source, Industry, Notes |
| Contact View | View contact details | All info, calls, projects |
| Call Form | Create/edit calls | Contact, Date/Time, Outcome, Duration, Notes |
| Call Updates | Add/view updates | Updates list, new update form |
| Project Form | Create/edit projects | Contact, Name, Value, Stage, Next Date, Notes |
| User Form | Edit user | Email, Name, Role, Global Leads Permission |
| Invite Form | Send invitation | Email, Role |
| Calendar Event | Create/edit event | Title, Type, Start, End, Location, Lead/Contact, Notes |
| AI Call Detail | View AI call | Transcript, Score, Summary, Recommendations |

### 9.4 Navigation Persistence

State stored in `localStorage`:
- `crm_current_page` - Current page/view
- `crm_lead_tab` - Active lead tab (global/personal/all)
- `crm_lead_industry` - Selected industry filter
- `crm_lead_page` - Current pagination page
- `crm_leads_per_page` - Items per page preference
- `crm_theme` - Light/dark mode

---

## 10. Business Logic & Workflows

### 10.1 Lead Lifecycle

```
                    ┌───────────────┐
                    │  Create Lead  │
                    └───────┬───────┘
                            │
              ┌─────────────┴─────────────┐
              │                           │
        ┌─────▼─────┐             ┌───────▼───────┐
        │  Global   │             │   Personal    │
        │   Lead    │             │     Lead      │
        └─────┬─────┘             └───────┬───────┘
              │                           │
              │ ◄─── Grab ────────────────┤
              │                           │
              ▼                           ▼
        ┌─────────────────────────────────────┐
        │           Assigned Lead             │
        └─────────────────┬───────────────────┘
                          │
                          │ Convert
                          ▼
                    ┌───────────┐
                    │  Contact  │
                    └─────┬─────┘
                          │
                          │ Return to Lead
                          ▼
                    ┌───────────┐
                    │   Lead    │
                    │ (Personal)│
                    └───────────┘
```

### 10.2 Contact → Project Pipeline

```
┌──────────┐     ┌──────────┐     ┌──────────┐
│ Contact  │────►│  Create  │────►│ Project  │
│          │     │ Project  │     │  (Lead)  │
└──────────┘     └──────────┘     └────┬─────┘
                                       │
                    Drag & Drop Stages │
                                       ▼
┌──────────┬──────────┬──────────┬──────────┬──────────┐
│   Lead   │Qualified │ Proposal │Negotiat- │   Won    │
│          │          │          │   ion    │          │
└──────────┴──────────┴──────────┴──────────┴──────────┘
```

### 10.3 Booking Flow (Cal.com)

```
┌──────────────┐     ┌──────────────┐     ┌──────────────┐
│   Cal.com    │     │   Webhook    │     │   Calendar   │
│   Booking    │────►│   Handler    │────►│    Event     │
└──────────────┘     └──────┬───────┘     └──────────────┘
                            │
                            │ Auto-match by email
                            ▼
                    ┌───────────────┐
                    │ Lead/Contact  │
                    │    Linked     │
                    └───────────────┘
                            │
                            │ If phone provided
                            ▼
                    ┌───────────────┐
                    │ SMS Confirm-  │
                    │    ation      │
                    └───────────────┘
```

### 10.4 AI Call Flow (Retell)

```
┌──────────────┐     ┌──────────────┐     ┌──────────────┐
│  Retell AI   │     │   Webhook    │     │ retell_calls │
│    Call      │────►│   Handler    │────►│    Table     │
└──────────────┘     └──────┬───────┘     └──────────────┘
                            │
                            │ Auto-match by phone
                            ▼
                    ┌───────────────┐
                    │ Lead/Contact  │
                    │    Linked     │
                    └───────────────┘
                            │
                            │ Auto-create
                            ▼
                    ┌───────────────┐
                    │   Calendar    │
                    │    Event      │
                    └───────────────┘
```

---

## 11. Settings & Configuration

### 11.1 Available Settings

| Key | Description | Default |
|-----|-------------|---------|
| `default_country` | Default phone country code | (empty) |
| `retell_api_key` | Retell AI API key for webhook verification | (empty) |
| `cal_webhook_secret` | Cal.com webhook secret | (empty) |

### 11.2 Settings UI (Admin Only)

- **Default Country Code:** Dropdown of available countries
- **Industry Management:** Add/delete industry categories
- **Export Data:** Download all data as JSON
- **Import Data:** Upload JSON to restore
- **Reset Database:** Clear contacts, calls, projects, settings
- **User Management:** Invite, edit, deactivate, delete users

---

## 12. Environment Variables

### 12.1 Required Variables

| Variable | Description | Example |
|----------|-------------|---------|
| `PGHOST` | PostgreSQL host | `localhost` |
| `PGPORT` | PostgreSQL port | `5432` |
| `PGDATABASE` | Database name | `koadi_crm` |
| `PGUSER` | Database user | `postgres` |
| `PGPASSWORD` | Database password | `secret` |

### 12.2 Optional Variables

| Variable | Description | Example |
|----------|-------------|---------|
| `SMTP_HOST` | SMTP server hostname | `mail.example.com` |
| `SMTP_PORT` | SMTP server port | `465` |
| `SMTP_USER` | SMTP username/email | `noreply@example.com` |
| `SMTP_PASS` | SMTP password | `smtp_password` |
| `ADMIN_EMAIL` | Initial admin email | `admin@example.com` |
| `APP_ENV` | Environment mode | `development` or `production` |

### 12.3 Development Mode Detection

Development mode is auto-detected when:
- `APP_ENV=development`
- Host is `localhost` or `127.0.0.x`
- Host contains `.replit.app`

In development mode:
- Emails are logged instead of sent
- SMS messages are logged instead of sent
- Magic links appear in console logs

---

## 13. Deployment

### 13.1 File Structure

```
project/
├── public/
│   ├── index.php       # Main application (single file)
│   ├── router.php      # PHP built-in server router
│   ├── logo.png        # Koadi Technology logo
│   ├── favicon.png     # Browser favicon
│   └── background.jpg  # Login background image
├── docs/
│   └── MVP_CRM_SPECIFICATION.md  # This document
├── replit.md           # Project documentation
└── Dockerfile          # Docker deployment config
```

### 13.2 Running Locally

```bash
php -S 0.0.0.0:5000 -t public public/router.php
```

### 13.3 Docker Deployment

```dockerfile
FROM php:8.2-cli
RUN docker-php-ext-install pdo pdo_pgsql
COPY public /var/www/html
WORKDIR /var/www/html
EXPOSE 5000
CMD ["php", "-S", "0.0.0.0:5000", "-t", ".", "router.php"]
```

### 13.4 Production Considerations

1. **Use HTTPS:** Required for secure cookies
2. **Set SMTP credentials:** Enable email sending
3. **Configure webhooks:** Add URLs in Retell/Cal.com dashboards
4. **Add ClickSend:** Allow SMTP email in ClickSend dashboard
5. **Backup database:** Regular PostgreSQL backups
6. **Monitor logs:** Check PHP error logs for issues

---

## Appendix A: Country Codes

| Code | Country |
|------|---------|
| +1 | United States/Canada |
| +27 | South Africa |
| +33 | France |
| +34 | Spain |
| +39 | Italy |
| +44 | United Kingdom |
| +49 | Germany |
| +52 | Mexico |
| +55 | Brazil |
| +61 | Australia |
| +81 | Japan |
| +86 | China |
| +91 | India |
| +233 | Ghana |
| +234 | Nigeria |
| +966 | Saudi Arabia |
| +971 | UAE |
| +973 | Bahrain |
| +974 | Qatar |

---

## Appendix B: Color Codes

| Purpose | Color | Hex |
|---------|-------|-----|
| Koadi Orange | Primary accent | #FF8C42 |
| Koadi Blue | Brand color | #0066CC |
| Koadi Yellow | Highlight | #FFC72C |
| Koadi Dark Blue | Hover state | #003366 |
| AI Calls | Calendar events | #FF8C42 |
| Bookings | Calendar events | #0066CC |
| Cancelled | Status indicator | #dc2626 |
| High Score | Call score ≥70 | Green |
| Medium Score | Call score 40-69 | Yellow |
| Low Score | Call score <40 | Red |

---

*End of Koadi Technology CRM MVP Specification*
