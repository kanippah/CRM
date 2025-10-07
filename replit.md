# Koadi Technology CRM

## Overview
A comprehensive single-file CRM system built with PHP and PostgreSQL for managing contacts, leads, calls, projects, and settings. The system features role-based access control (Admin/Sales), contact management with duplicate detection, call tracking, project pipeline with Kanban visualization, data export/import, and light/dark mode theming.

## Recent Changes
- **October 7, 2025**: Enhanced Authentication & User Invites
  - **Remember Me**: Auto-login with secure 30-day tokens
  - **Forgot Password**: Email-based password reset with 1-hour expiration
  - **Password Security**: Minimum password length increased to 8 characters
  - **Email Invites**: Admin can send email invites when creating new users
  - **SMTP Integration**: Email sending via help@koaditech.com (mail.koaditech.com:465)
  - **Background Image**: Fixed login page background with reduced opacity (15%)
  - **SSL Certificate**: Deployed to https://crm.koaditech.com with valid SSL
  - **Branding Update**: Login page title changed to "Koadi Tech CRM"

- **October 6, 2025**: Comprehensive CRM system enhancement
  - Added Dashboard with statistics and recent activity
  - Implemented Contacts module with duplicate detection by phone/company
  - Created Calls tracking with outcome and duration logging
  - **Added Call Updates**: Track update history with notes for each call
  - Built Projects module with Kanban board (5 stages: Lead, Qualified, Proposal, Negotiation, Won)
  - **Kanban Card Fixes**: Fixed width cards with text truncation/ellipsis on all fields
  - Added Settings page with default country selector, export/import, and database reset
  - Integrated 20 country codes for phone numbers
  - **Sales Lead Creation**: Sales users can now create leads directly in "My Leads"
  - All features available to both admin and sales users
  - Maintained original Leads module functionality
  - Integrated with Replit PostgreSQL database
  - Implemented authentication system (admin/sales roles)
  - Integrated Koadi Technology branding (logo, favicon, colors)
  - Added light/dark mode toggle
  - **Docker Support**: Added Dockerfile and .dockerignore for Coolify/Docker deployments

## Features

### Dashboard
- Overview statistics: total contacts, calls in last 7 days, open projects
- Recent contacts table (last 5)
- Recent calls table (last 5)
- Real-time data from database

### Contact Management (All Users)
- Create, edit, delete contacts
- Contact types: Individual or Company
- Fields: Name, Company, Email, Phone (with country code), Source, Notes
- **Duplicate Detection**: Automatically warns when creating duplicate contacts by:
  - Phone number (normalized, ignoring formatting)
  - Company name (case-insensitive)
- Search across name, company, email, phone
- Quick actions: Log call, Create project from contact

### Call Tracking (All Users)
- Log calls with contact linking
- Fields: Contact, When (date/time), Outcome, Duration (minutes), Notes
- Outcomes: Attempted, Answered, Voicemail, No Answer, Busy, Wrong Number
- Search calls by contact, outcome, or notes
- Complete call history per contact
- **Call Updates**: Add timestamped notes to calls for follow-up tracking
  - View all updates with user name and timestamp
  - Add new updates via "Add Update" button
  - Update history displayed in chronological order

### Project Pipeline (All Users)
- **Kanban Board**: Visual pipeline with drag-and-drop between stages
  - 5 Stages: Lead → Qualified → Proposal → Negotiation → Won
  - Drag projects between stages to update status
  - Color-coded cards with contact, value
- **Project Details**: Name, Contact, Value ($), Stage, Next Date, Notes
- Table view with all project details
- Create projects directly from contacts

### Settings (All Users)
- **Default Country Code**: Set preferred country for phone numbers (20+ countries)
- **Export/Import** (Admin only): 
  - Export all contacts, calls, projects, settings to JSON
  - Import JSON data (with transaction rollback on failure)
- **Database Reset** (Admin only): Clear all data (contacts, calls, projects, settings)

### Lead Management (All Users)
- **Global Leads**: Available for all sales users to grab (Admin created)
- **Personal Leads**: Assigned to specific sales user after grabbing or creating
- **Sales Lead Creation**: Sales users can create leads directly in "My Leads"
  - New leads created by sales are automatically assigned to them
  - Leads are private and only visible to the creating user
  - Admin-created leads go to global pool
- CSV Import for bulk lead upload (Admin only)
- Interaction history tracking
- Search functionality across name, phone, location, company

### User Management (Admin Only)
- Create, edit, delete sales users
- Manage user credentials and roles

### Authentication
- Email-based authentication system with password reset
- **Remember Me**: Secure 30-day auto-login with HTTP-only cookies
- **Forgot Password**: Email-based password reset flow with 1-hour token expiration
- Role-based access: Admin and Sales users
- Session-based login/logout with remember token support

### Design
- Light/Dark mode toggle
- Koadi Technology branding colors (Orange #FF8C42, Blue #0066CC, Yellow #FFC72C)
- Company logo and favicon integrated
- Responsive design with sidebar navigation
- Kanban board with drag-and-drop visual feedback

## Database Schema

### Users Table
- id, username, email, password (hashed), full_name, role (admin/sales), remember_token, created_at

### Password Resets Table
- id, email, token (hashed), expires_at, created_at

### Contacts Table
- id, type (Individual/Company), company, name, email
- phone_country (country code), phone_number
- source, notes, created_at, updated_at
- Indexes: phone search, company search

### Calls Table
- id, contact_id (FK to contacts)
- when_at (timestamp), outcome, duration_min, notes
- created_at, updated_at
- Indexes: contact_id, when_at

### Call Updates Table
- id, call_id (FK to calls)
- user_id (FK to users), notes
- created_at
- Indexes: call_id

### Projects Table
- id, contact_id (FK to contacts)
- name, value (decimal), stage (Lead/Qualified/Proposal/Negotiation/Won)
- next_date, notes, created_at, updated_at
- Indexes: contact_id, stage

### Settings Table
- key (unique), value
- Stores: defaultCountry and other configuration

### Leads Table (Original)
- id, name, phone, email, company, address
- status (global/assigned), assigned_to
- created_at, updated_at

### Interactions Table (Original)
- id, lead_id, user_id, type (call/email/meeting/note/grabbed)
- notes, created_at

## API Endpoints

### Authentication
- `api=session` - Get current session
- `api=login` - Login (POST)
- `api=logout` - Logout (POST)

### Dashboard
- `api=stats` - Get dashboard statistics

### Contacts
- `api=contacts.list&q=search` - List/search contacts
- `api=contacts.save` - Create/update contact (POST)
- `api=contacts.delete&id=X` - Delete contact (DELETE)

### Calls
- `api=calls.list&q=search` - List/search calls
- `api=calls.save` - Create/update call (POST)
- `api=calls.delete&id=X` - Delete call (DELETE)
- `api=call_updates.list&call_id=X` - List updates for a call
- `api=call_updates.save` - Add update to call (POST)

### Projects
- `api=projects.list` - List all projects
- `api=projects.save` - Create/update project (POST)
- `api=projects.delete&id=X` - Delete project (DELETE)
- `api=projects.stage` - Update project stage (POST, for drag-drop)

### Settings
- `api=settings.get&key=X` - Get setting value
- `api=settings.set` - Set setting value (POST)
- `api=export` - Export all data to JSON (Admin only)
- `api=import` - Import JSON data (Admin only, POST)
- `api=reset` - Reset database (Admin only, POST)

### Countries
- `api=countries` - Get list of 20 country codes

### Original Leads/Users
- `api=leads.list&q=&type=` - List leads
- `api=leads.save` - Save lead (POST)
- `api=leads.delete&id=X` - Delete lead (DELETE)
- `api=leads.grab` - Grab lead (POST)
- `api=leads.import` - Import leads CSV (POST, Admin)
- `api=interactions.list&lead_id=X` - List interactions
- `api=interactions.save` - Save interaction (POST)
- `api=users.list` - List users (Admin)
- `api=users.save` - Save user (POST, Admin)
- `api=users.delete&id=X` - Delete user (DELETE, Admin)

## Environment Configuration
Uses Replit PostgreSQL environment variables:
- PGHOST, PGPORT, PGDATABASE, PGUSER, PGPASSWORD

## Default Credentials
- Username: `admin`
- Password: `admin123`

## Technical Stack
- Single-file PHP application (public/index.php - ~2000 lines)
- PostgreSQL database (Replit managed)
- Vanilla JavaScript (no frameworks)
- CSS with custom properties for theming
- Session-based authentication
- Drag-and-drop API for Kanban board

## File Structure
```
/
├── public/
│   ├── index.php (Main CRM application - all features in single file)
│   ├── logo.png (Koadi Technology logo)
│   └── favicon.png (Koadi Technology favicon)
├── Dockerfile (Docker deployment configuration)
├── .dockerignore (Docker build exclusions)
├── .gitignore
└── replit.md
```

## Deployment

### Docker/Coolify Deployment
The CRM includes a Dockerfile for easy deployment to Docker, Coolify, or other container platforms:
- Uses PHP 8.2 CLI with PostgreSQL extensions (pdo, pdo_pgsql)
- Runs on port 5000 using PHP's built-in server
- Configure PostgreSQL connection via environment variables:
  - `PGHOST`, `PGPORT`, `PGDATABASE`, `PGUSER`, `PGPASSWORD`

## Usage Instructions

### Dashboard
1. Login to see overview of CRM activity
2. View statistics: total contacts, recent calls, open projects
3. Click on items to navigate to detailed views

### Contact Management
1. Navigate to "Contacts"
2. Click "+ New Contact" to add contacts
3. Fill in details (name required, phone with country code optional)
4. System warns if duplicate phone/company detected
5. Search contacts in real-time
6. Quick actions: Log call, Create project, Edit, Delete

### Call Tracking
1. Navigate to "Calls"
2. Click "+ Log Call" or use "Call" button from contact
3. Select contact, set date/time, choose outcome, add duration and notes
4. View complete call history
5. Search calls by contact, outcome, or notes

### Project Pipeline
1. Navigate to "Projects"
2. View Kanban board with 5 stages
3. **Drag projects** between stages to update pipeline
4. Click "+ New Project" to create projects
5. Link projects to contacts
6. Set value, next date, and notes
7. View all projects in table below Kanban

### Settings
1. Navigate to "Settings"
2. **Default Country**: Select preferred country code for phone numbers
3. **Export** (Admin): Download all CRM data as JSON
4. **Import** (Admin): Upload JSON to restore data
5. **Reset** (Admin): Clear all contacts, calls, projects, settings

### Lead Management (Original)
1. Navigate to "Leads"
2. **For Sales Users**:
   - View "Global Pool" tab to see available leads (limited info)
   - Click "Grab" to claim a lead
   - View "My Leads" tab to see personal leads
   - Add interaction history (calls, emails, notes)
3. **For Admins**:
   - Add new leads manually
   - Import leads via CSV
   - View all leads
   - Edit or delete leads

### User Management (Admin Only)
1. Navigate to "Users"
2. Create new sales users
3. Edit user credentials
4. Delete users (cannot delete self)

## CSV Lead Import Format
```
Name, Phone, Email, Company, Address
John Doe, +1234567890, john@example.com, Acme Corp, 123 Main St
Jane Smith, +0987654321, jane@example.com, Tech Inc, 456 Oak Ave
```

## Supported Country Codes (20)
United States (+1), Canada (+1), United Kingdom (+44), Australia (+61), 
Nigeria (+234), Ghana (+233), South Africa (+27), India (+91), 
Germany (+49), France (+33), Spain (+34), Italy (+39), 
Japan (+81), China (+86), UAE (+971), Bahrain (+973), 
Qatar (+974), Saudi Arabia (+966), Brazil (+55), Mexico (+52)

## Key Features Summary
✅ Dashboard with real-time statistics
✅ Contact management with duplicate detection
✅ Call tracking with outcomes
✅ Project pipeline with Kanban drag-and-drop
✅ Settings with export/import
✅ Original Leads and Users modules retained
✅ All features available to sales users (except user management)
✅ Single-file PHP architecture
✅ PostgreSQL with proper indexes
✅ Responsive design with light/dark mode
