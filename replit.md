# Koadi Technology CRM

## Overview
A comprehensive single-file CRM system built with PHP and PostgreSQL for managing sales leads and user interactions. The system features role-based access control (Admin/Sales), lead management with global and personal pools, interaction history tracking, and light/dark mode theming.

## Recent Changes
- **October 6, 2025**: Initial CRM system created
  - Integrated with Replit PostgreSQL database
  - Implemented authentication system (admin/sales roles)
  - Built lead management with global/personal lead pools
  - Added lead grabbing functionality for sales users
  - Implemented CSV lead import for admins
  - Created interaction history tracking
  - Integrated Koadi Technology branding (logo, favicon, colors)
  - Added light/dark mode toggle

## Features

### Authentication
- Custom authentication system (not using Replit Auth)
- Role-based access: Admin and Sales users
- Session-based login/logout

### Admin Features
- User Management (Create, Edit, Delete sales users)
- Lead Management (Create, Edit, Delete leads)
- CSV Import for bulk lead upload
- View all leads (global and assigned)
- Full access to all lead details

### Sales Features
- View global lead pool (limited details)
- Grab leads from global pool to personal leads
- View full details of personal leads only
- Add interaction history (calls, emails, meetings, notes)
- Search leads by name, phone, address, company

### Lead Management
- **Global Leads**: Available for all sales users to grab
- **Personal Leads**: Assigned to specific sales user after grabbing
- Leads display as tables (not cards)
- Search functionality across name, phone, location, company
- Interaction history tracking

### Design
- Light/Dark mode toggle
- Koadi Technology branding colors (Orange #FF8C42, Blue #0066CC, Yellow #FFC72C)
- Company logo and favicon integrated
- Responsive design

## Database Schema

### Users Table
- id, username, password (hashed), full_name, role (admin/sales), created_at

### Leads Table
- id, name, phone, email, company, address, status (global/assigned), assigned_to, created_at, updated_at

### Interactions Table
- id, lead_id, user_id, type (call/email/meeting/note), notes, created_at

## Environment Configuration
Uses Replit PostgreSQL environment variables:
- PGHOST, PGPORT, PGDATABASE, PGUSER, PGPASSWORD

## Default Credentials
- Username: `admin`
- Password: `admin123`

## Technical Stack
- Single-file PHP application (public/index.php)
- PostgreSQL database (Replit managed)
- Vanilla JavaScript (no frameworks)
- CSS with custom properties for theming
- Session-based authentication

## File Structure
```
/
├── public/
│   ├── index.php (Main CRM application)
│   ├── logo.png (Koadi Technology logo)
│   └── favicon.png (Koadi Technology favicon)
├── .gitignore
└── replit.md
```

## Usage Instructions

### For Admins
1. Login with admin credentials
2. Navigate to "Users" to manage sales team
3. Navigate to "Leads" to:
   - Add new leads manually
   - Import leads via CSV
   - View all leads
   - Edit or delete leads

### For Sales Users
1. Login with sales credentials
2. View "Global Pool" tab to see available leads (limited info)
3. Click "Grab" to claim a lead
4. View "My Leads" tab to see personal leads
5. Click "View" on personal leads to:
   - See full lead details
   - Add interaction history (calls, emails, notes)
   - Track all previous interactions

## CSV Import Format
```
Name, Phone, Email, Company, Address
John Doe, +1234567890, john@example.com, Acme Corp, 123 Main St
Jane Smith, +0987654321, jane@example.com, Tech Inc, 456 Oak Ave
```
