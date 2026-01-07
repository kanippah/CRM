# Voice AI Platform MVP Specification

## Project Overview

A multi-tenant SaaS platform that provides AI voice agent services with integrated calendar booking, automated notifications, and usage-based billing. Built with Flask (Python) and PostgreSQL.

**Target Users:**
- **Super Admin (Platform Owner):** Manages all customers, sets global configurations
- **Customer Admin:** Business owners who subscribe to the platform
- **Customer Users:** Staff members with varying access levels
- **End Users:** Callers interacting with the AI (no portal access)

---

## Technology Stack

| Component | Technology |
|-----------|------------|
| Backend | Flask (Python 3.11+) |
| Database | PostgreSQL |
| Voice AI | Retell AI |
| SMS/Email | ClickSend (Email-to-SMS gateway) |
| Authentication | Session-based with role permissions |
| Frontend | Vanilla JavaScript + Bootstrap 5 |

---

## User Roles & Permissions

### Super Admin (Platform Owner)
- Full access to all features
- Manage all customers
- Set global default rates
- View platform-wide analytics
- Access all customer data

### Customer Admin
- Manage their organization's users
- View all calls, recordings, transcripts
- Download recordings
- Manage calendar/appointments
- View billing, invoices, balance
- Configure their webhook URL
- Set user permissions

### Customer User (Staff)
- View calls assigned to them or all (based on permission)
- View transcripts
- Play recordings
- Download recordings (if permitted)
- View calendar/appointments
- Cannot access billing

### Customer Viewer (Read-Only)
- View calls and transcripts only
- Play recordings (no download)
- View calendar
- No edit permissions

---

## Core Modules

### 1. Customer Management

#### 1.1 Customer Onboarding
- Customer registration with company details
- Unique customer ID and API key generation
- Webhook URL configuration
- Initial balance/credit setup

#### 1.2 Customer Settings
| Setting | Description | Default |
|---------|-------------|---------|
| `rate_per_minute` | Cost per minute of call | $1.00 |
| `rate_per_sms` | Cost per SMS sent | $0.05 |
| `rate_per_email` | Cost per email sent | $0.01 |
| `monthly_fee` | Monthly subscription fee | $0.00 |
| `low_balance_alert` | Alert threshold | $10.00 |
| `webhook_url` | Customer's webhook endpoint | null |

**Note:** All times are displayed in the user's local browser timezone automatically. No timezone configuration needed.

#### 1.3 Customer Users
- Add/remove users
- Assign roles (Admin, User, Viewer)
- Set permissions:
  - `can_download_recordings` (boolean)
  - `can_view_all_calls` (boolean)
  - `can_manage_calendar` (boolean)

---

### 2. Call Management

#### 2.1 Retell AI Webhook (Inbound)
**Endpoint:** `POST /api/v1/webhook/retell/{customer_api_key}`

Each customer gets a unique webhook URL to configure in Retell AI.

**Webhook Payload Processing:**
```json
{
    "call_id": "string",
    "agent_id": "string",
    "call_type": "inbound",
    "from_number": "+1234567890",
    "to_number": "+0987654321",
    "start_time": "ISO8601",
    "end_time": "ISO8601",
    "duration_seconds": 300,
    "recording_url": "https://...",
    "transcript": "...",
    "call_summary": "...",
    "call_analysis": {},
    "call_score": 85,
    "improvement_recommendations": [],
    "custom_data": {}
}
```

**Processing Steps:**
1. Validate customer API key
2. Check customer balance
3. Calculate call cost: `duration_minutes * rate_per_minute`
4. Deduct from customer balance
5. Store call record
6. Trigger customer's webhook (if configured)
7. Return success/failure

#### 2.2 Call Records
**Stored Data:**
- Call ID (from Retell)
- Customer ID
- Caller phone number
- Call direction (inbound/outbound)
- Duration (seconds/minutes)
- Cost charged
- Recording URL
- Transcript (full text)
- Summary (AI-generated)
- Call score (0-100)
- Analysis JSON (sentiment, topics, etc.)
- Improvement recommendations (array)
- Status (completed, failed, no-answer)
- Created at timestamp

#### 2.3 Call Viewing (Customer Portal)
**List View:**
- Paginated call history
- Filters: date range, caller number, status
- Search: by phone number, transcript content
- Columns: Date, Caller, Duration, Cost, Score, Status
- Quick actions: Play, View Details

**Detail View:**
- Full transcript with timestamps
- Audio player (streaming from Retell URL)
- Download button (role-based)
- Call summary
- Call score with visual indicator
- Improvement recommendations
- Analysis breakdown (sentiment, topics)
- Linked appointment (if any)

#### 2.4 Recording Access
- **Play:** All authenticated users
- **Download:** Only users with `can_download_recordings` permission
- Recordings streamed from Retell (not stored locally)
- Signed URLs for security

---

### 3. Calendar & Appointments

#### 3.1 Retell Custom Functions
**Check Availability Endpoint:**
`POST /api/v1/function/availability/{customer_api_key}`

Request:
```json
{
    "date": "2026-01-15",
    "duration_minutes": 30
}
```

Response:
```json
{
    "available_slots": [
        {"start": "09:00", "end": "09:30"},
        {"start": "10:00", "end": "10:30"},
        {"start": "14:00", "end": "14:30"}
    ]
}
```

**Book Appointment Endpoint:**
`POST /api/v1/function/book/{customer_api_key}`

Request:
```json
{
    "date": "2026-01-15",
    "time": "10:00",
    "duration_minutes": 30,
    "caller_name": "John Doe",
    "caller_phone": "+1234567890",
    "caller_email": "john@example.com",
    "notes": "Consultation about services"
}
```

Response:
```json
{
    "success": true,
    "appointment_id": "apt_123",
    "message": "Appointment booked for January 15, 2026 at 10:00 AM"
}
```

#### 3.2 Appointment Records
**Stored Data:**
- Appointment ID
- Customer ID
- Call ID (linked)
- Guest name, phone, email
- Start time, end time
- Duration
- Status (scheduled, completed, cancelled, no-show)
- Notes
- Created at, Updated at

#### 3.3 Calendar Views
**Monthly View:**
- Color-coded appointments
- Click to view details
- Add new appointment button

**Weekly View:**
- Time-slot grid
- Drag to reschedule (admin only)

**List View:**
- Upcoming appointments
- Filter by status
- Search by guest name/phone

#### 3.4 Appointment Actions
- View details
- Edit (reschedule)
- Cancel (with reason)
- Mark as completed/no-show
- Resend confirmation

---

### 4. Notifications (ClickSend)

#### 4.1 Email Notifications
**Triggers:**
- Appointment booked: Confirmation email
- Appointment rescheduled: Update email
- Appointment cancelled: Cancellation email
- Appointment reminder (configurable: 24h, 1h before)

**Email Template Variables:**
- `{guest_name}`, `{appointment_date}`, `{appointment_time}`
- `{company_name}`, `{company_phone}`
- `{cancellation_link}`, `{reschedule_link}`

#### 4.2 SMS Notifications
**Triggers:** Same as email

**SMS Template:**
```
Hi {first_name}! Your appointment with {company_name} is confirmed for {date} at {time}. Check your email for details. Reply STOP to opt out.
```

**Billing:**
- SMS cost deducted from customer balance
- Rate configurable per customer
- Logged in notifications table

#### 4.3 Notification Logs
**Stored Data:**
- Notification ID
- Customer ID
- Appointment ID (if applicable)
- Type (email/sms)
- Recipient (email/phone)
- Template used
- Status (sent, failed, pending)
- Cost charged
- Sent at timestamp
- Error message (if failed)

---

### 5. Billing & Invoices

#### 5.1 Balance System
**Prepaid Credits Model:**
- Customers maintain a credit balance
- Usage deducted in real-time
- Low balance alerts via email

**Balance Transactions:**
- Credit: Manual top-up, payment received
- Debit: Call cost, SMS cost, email cost, monthly fee

#### 5.2 Transaction Log
| Field | Description |
|-------|-------------|
| `id` | Transaction ID |
| `customer_id` | Customer reference |
| `type` | credit/debit |
| `category` | call, sms, email, subscription, top_up |
| `amount` | Positive for credit, negative for debit |
| `balance_after` | Balance after transaction |
| `reference_id` | Call ID, Appointment ID, etc. |
| `description` | Human-readable description |
| `created_at` | Timestamp |

#### 5.3 Invoices
**Auto-Generated Monthly:**
- Invoice number (sequential per customer)
- Billing period (month/year)
- Line items:
  - Monthly subscription fee
  - Total call minutes x rate
  - Total SMS x rate
  - Total emails x rate
- Subtotal, Tax (configurable), Total
- Status: draft, sent, paid, overdue

**Invoice PDF:**
- Company branding
- Itemized usage breakdown
- Payment instructions
- Downloadable from portal

#### 5.4 Customer Billing Portal
**Dashboard:**
- Current balance (prominent display)
- Low balance warning (if applicable)
- Quick top-up button
- Usage this month (calls, SMS, emails)

**Transaction History:**
- Paginated list
- Filter by type, date range
- Export to CSV

**Invoices:**
- List all invoices
- Download PDF
- View status

---

### 6. Webhooks (Customer-Side)

#### 6.1 Customer Webhook Configuration
Each customer can configure a webhook URL to receive events.

**Settings:**
- Webhook URL
- Secret key (for signature verification)
- Events to subscribe to

#### 6.2 Webhook Events
| Event | Trigger |
|-------|---------|
| `call.completed` | Call finished and processed |
| `call.failed` | Call processing failed |
| `appointment.created` | New appointment booked |
| `appointment.updated` | Appointment rescheduled |
| `appointment.cancelled` | Appointment cancelled |
| `balance.low` | Balance below threshold |
| `balance.depleted` | Balance reached zero |

#### 6.3 Webhook Payload Format
```json
{
    "event": "call.completed",
    "timestamp": "2026-01-15T10:30:00Z",
    "data": {
        "call_id": "call_123",
        "duration_minutes": 5,
        "cost": 5.00,
        "caller_phone": "+1234567890",
        "recording_url": "https://...",
        "transcript": "...",
        "summary": "..."
    }
}
```

**Headers:**
- `X-Webhook-Signature`: HMAC SHA256 signature
- `X-Webhook-Event`: Event name
- `X-Webhook-Timestamp`: ISO8601 timestamp

#### 6.4 Webhook Logs
- All webhook deliveries logged
- Retry on failure (3 attempts)
- View delivery status in portal

---

### 7. Admin Portal (Super Admin)

#### 7.1 Dashboard
- Total customers
- Total calls today/this month
- Revenue today/this month
- Active calls (if real-time)
- System health indicators

#### 7.2 Customer Management
- List all customers
- Search/filter
- View customer details
- Edit customer settings
- Add credits manually
- Suspend/activate accounts
- Impersonate (login as customer)

#### 7.3 Global Settings
- Default rates (per minute, SMS, email)
- Default monthly fee
- ClickSend configuration
- Email templates
- SMS templates

#### 7.4 Reports
- Revenue by customer
- Usage by customer
- Call volume trends
- Top customers

---

## Database Schema

### Core Tables

```sql
-- Customers (Tenants)
CREATE TABLE customers (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    company VARCHAR(255),
    email VARCHAR(255) UNIQUE NOT NULL,
    phone VARCHAR(50),
    api_key VARCHAR(64) UNIQUE NOT NULL,
    webhook_url TEXT,
    webhook_secret VARCHAR(64),
    status VARCHAR(20) DEFAULT 'active',
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

-- Customer Settings
CREATE TABLE customer_settings (
    id SERIAL PRIMARY KEY,
    customer_id INTEGER REFERENCES customers(id) ON DELETE CASCADE,
    rate_per_minute DECIMAL(10,4) DEFAULT 1.00,
    rate_per_sms DECIMAL(10,4) DEFAULT 0.05,
    rate_per_email DECIMAL(10,4) DEFAULT 0.01,
    monthly_fee DECIMAL(10,2) DEFAULT 0.00,
    low_balance_alert DECIMAL(10,2) DEFAULT 10.00,
    sms_enabled BOOLEAN DEFAULT TRUE,
    email_enabled BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE(customer_id)
);

-- Customer Users
CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    customer_id INTEGER REFERENCES customers(id) ON DELETE CASCADE,
    email VARCHAR(255) NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL,
    can_download_recordings BOOLEAN DEFAULT FALSE,
    can_view_all_calls BOOLEAN DEFAULT FALSE,
    can_manage_calendar BOOLEAN DEFAULT FALSE,
    status VARCHAR(20) DEFAULT 'active',
    last_login TIMESTAMPTZ,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE(customer_id, email)
);

-- Magic Links (Passwordless Auth)
CREATE TABLE magic_links (
    id SERIAL PRIMARY KEY,
    user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
    token VARCHAR(64) UNIQUE NOT NULL,
    expires_at TIMESTAMPTZ NOT NULL,
    used_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Balance & Transactions
CREATE TABLE balances (
    id SERIAL PRIMARY KEY,
    customer_id INTEGER REFERENCES customers(id) ON DELETE CASCADE,
    current_balance DECIMAL(12,2) DEFAULT 0.00,
    updated_at TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE(customer_id)
);

CREATE TABLE transactions (
    id SERIAL PRIMARY KEY,
    customer_id INTEGER REFERENCES customers(id) ON DELETE CASCADE,
    type VARCHAR(10) NOT NULL,
    category VARCHAR(50) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    balance_after DECIMAL(12,2) NOT NULL,
    reference_type VARCHAR(50),
    reference_id INTEGER,
    description TEXT,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Calls
CREATE TABLE calls (
    id SERIAL PRIMARY KEY,
    customer_id INTEGER REFERENCES customers(id) ON DELETE CASCADE,
    retell_call_id VARCHAR(255) UNIQUE NOT NULL,
    agent_id VARCHAR(255),
    direction VARCHAR(20) DEFAULT 'inbound',
    from_number VARCHAR(50),
    to_number VARCHAR(50),
    start_time TIMESTAMPTZ,
    end_time TIMESTAMPTZ,
    duration_seconds INTEGER DEFAULT 0,
    duration_minutes DECIMAL(10,2) DEFAULT 0,
    cost DECIMAL(10,2) DEFAULT 0,
    recording_url TEXT,
    transcript TEXT,
    summary TEXT,
    call_score INTEGER,
    analysis JSONB,
    improvement_recommendations JSONB,
    status VARCHAR(20) DEFAULT 'completed',
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Appointments
CREATE TABLE appointments (
    id SERIAL PRIMARY KEY,
    customer_id INTEGER REFERENCES customers(id) ON DELETE CASCADE,
    call_id INTEGER REFERENCES calls(id) ON DELETE SET NULL,
    guest_name VARCHAR(255),
    guest_phone VARCHAR(50),
    guest_email VARCHAR(255),
    start_time TIMESTAMPTZ NOT NULL,
    end_time TIMESTAMPTZ NOT NULL,
    duration_minutes INTEGER DEFAULT 30,
    status VARCHAR(20) DEFAULT 'scheduled',
    notes TEXT,
    cancellation_reason TEXT,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

-- Availability Slots (Business Hours)
CREATE TABLE availability_slots (
    id SERIAL PRIMARY KEY,
    customer_id INTEGER REFERENCES customers(id) ON DELETE CASCADE,
    day_of_week INTEGER NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    is_available BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Blocked Dates (Holidays, Time Off)
CREATE TABLE blocked_dates (
    id SERIAL PRIMARY KEY,
    customer_id INTEGER REFERENCES customers(id) ON DELETE CASCADE,
    blocked_date DATE NOT NULL,
    reason VARCHAR(255),
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Notifications
CREATE TABLE notifications (
    id SERIAL PRIMARY KEY,
    customer_id INTEGER REFERENCES customers(id) ON DELETE CASCADE,
    appointment_id INTEGER REFERENCES appointments(id) ON DELETE SET NULL,
    type VARCHAR(10) NOT NULL,
    recipient VARCHAR(255) NOT NULL,
    template VARCHAR(50),
    content TEXT,
    status VARCHAR(20) DEFAULT 'pending',
    cost DECIMAL(10,4) DEFAULT 0,
    error_message TEXT,
    sent_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Invoices
CREATE TABLE invoices (
    id SERIAL PRIMARY KEY,
    customer_id INTEGER REFERENCES customers(id) ON DELETE CASCADE,
    invoice_number VARCHAR(50) UNIQUE NOT NULL,
    billing_period_start DATE NOT NULL,
    billing_period_end DATE NOT NULL,
    subtotal DECIMAL(12,2) DEFAULT 0,
    tax_rate DECIMAL(5,2) DEFAULT 0,
    tax_amount DECIMAL(12,2) DEFAULT 0,
    total DECIMAL(12,2) DEFAULT 0,
    status VARCHAR(20) DEFAULT 'draft',
    due_date DATE,
    paid_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE invoice_items (
    id SERIAL PRIMARY KEY,
    invoice_id INTEGER REFERENCES invoices(id) ON DELETE CASCADE,
    description VARCHAR(255) NOT NULL,
    quantity DECIMAL(10,2) DEFAULT 1,
    unit_price DECIMAL(10,4) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Webhook Logs
CREATE TABLE webhook_logs (
    id SERIAL PRIMARY KEY,
    customer_id INTEGER REFERENCES customers(id) ON DELETE CASCADE,
    event VARCHAR(50) NOT NULL,
    payload JSONB,
    status VARCHAR(20) DEFAULT 'pending',
    attempts INTEGER DEFAULT 0,
    last_attempt_at TIMESTAMPTZ,
    response_code INTEGER,
    response_body TEXT,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Global Settings (Super Admin)
CREATE TABLE global_settings (
    id SERIAL PRIMARY KEY,
    key VARCHAR(100) UNIQUE NOT NULL,
    value TEXT,
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

-- Indexes for performance
CREATE INDEX idx_calls_customer ON calls(customer_id);
CREATE INDEX idx_calls_created ON calls(created_at);
CREATE INDEX idx_calls_from_number ON calls(from_number);
CREATE INDEX idx_appointments_customer ON appointments(customer_id);
CREATE INDEX idx_appointments_start ON appointments(start_time);
CREATE INDEX idx_transactions_customer ON transactions(customer_id);
CREATE INDEX idx_transactions_created ON transactions(created_at);
CREATE INDEX idx_notifications_customer ON notifications(customer_id);
CREATE INDEX idx_webhook_logs_customer ON webhook_logs(customer_id);
```

---

## API Endpoints

### Authentication
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/auth/login` | Request magic link |
| GET | `/api/auth/verify/{token}` | Verify magic link |
| POST | `/api/auth/logout` | End session |
| GET | `/api/auth/me` | Get current user |

### Retell Webhooks (Per Customer)
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/v1/webhook/retell/{api_key}` | Receive call data |
| POST | `/api/v1/function/availability/{api_key}` | Check availability |
| POST | `/api/v1/function/book/{api_key}` | Book appointment |
| POST | `/api/v1/function/lookup/{api_key}` | Lookup caller info |

### Calls
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/calls` | List calls (paginated) |
| GET | `/api/calls/{id}` | Get call details |
| GET | `/api/calls/{id}/recording` | Stream recording |
| GET | `/api/calls/{id}/download` | Download recording |
| GET | `/api/calls/search` | Search transcripts |

### Appointments
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/appointments` | List appointments |
| POST | `/api/appointments` | Create appointment |
| GET | `/api/appointments/{id}` | Get appointment |
| PUT | `/api/appointments/{id}` | Update appointment |
| DELETE | `/api/appointments/{id}` | Cancel appointment |
| POST | `/api/appointments/{id}/resend` | Resend confirmation |

### Calendar
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/calendar` | Get events for date range |
| GET | `/api/availability` | Get available slots |
| PUT | `/api/availability` | Update business hours |
| POST | `/api/blocked-dates` | Block a date |
| DELETE | `/api/blocked-dates/{id}` | Unblock date |

### Billing
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/billing/balance` | Get current balance |
| GET | `/api/billing/transactions` | List transactions |
| GET | `/api/billing/usage` | Get usage summary |
| GET | `/api/billing/invoices` | List invoices |
| GET | `/api/billing/invoices/{id}` | Get invoice details |
| GET | `/api/billing/invoices/{id}/pdf` | Download invoice PDF |

### Users (Customer Admin)
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/users` | List users |
| POST | `/api/users` | Invite user |
| GET | `/api/users/{id}` | Get user |
| PUT | `/api/users/{id}` | Update user |
| DELETE | `/api/users/{id}` | Remove user |

### Settings (Customer Admin)
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/settings` | Get customer settings |
| PUT | `/api/settings` | Update settings |
| GET | `/api/settings/webhook` | Get webhook config |
| PUT | `/api/settings/webhook` | Update webhook config |
| POST | `/api/settings/webhook/test` | Test webhook |

### Admin (Super Admin Only)
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/admin/customers` | List all customers |
| POST | `/api/admin/customers` | Create customer |
| GET | `/api/admin/customers/{id}` | Get customer |
| PUT | `/api/admin/customers/{id}` | Update customer |
| POST | `/api/admin/customers/{id}/credit` | Add credit |
| PUT | `/api/admin/customers/{id}/suspend` | Suspend customer |
| PUT | `/api/admin/customers/{id}/activate` | Activate customer |
| GET | `/api/admin/dashboard` | Platform stats |
| GET | `/api/admin/settings` | Global settings |
| PUT | `/api/admin/settings` | Update global settings |
| GET | `/api/admin/reports/revenue` | Revenue report |
| GET | `/api/admin/reports/usage` | Usage report |

---

## Project Structure

```
voice-ai-platform/
├── app/
│   ├── __init__.py
│   ├── config.py
│   ├── models/
│   │   ├── __init__.py
│   │   ├── customer.py
│   │   ├── user.py
│   │   ├── call.py
│   │   ├── appointment.py
│   │   ├── billing.py
│   │   └── notification.py
│   ├── routes/
│   │   ├── __init__.py
│   │   ├── auth.py
│   │   ├── calls.py
│   │   ├── appointments.py
│   │   ├── calendar.py
│   │   ├── billing.py
│   │   ├── users.py
│   │   ├── settings.py
│   │   ├── webhooks.py
│   │   └── admin.py
│   ├── services/
│   │   ├── __init__.py
│   │   ├── retell.py
│   │   ├── clicksend.py
│   │   ├── billing.py
│   │   ├── calendar.py
│   │   └── webhook.py
│   ├── templates/
│   │   ├── base.html
│   │   ├── auth/
│   │   ├── dashboard/
│   │   ├── calls/
│   │   ├── appointments/
│   │   ├── billing/
│   │   └── admin/
│   └── static/
│       ├── css/
│       ├── js/
│       └── img/
├── migrations/
├── tests/
├── requirements.txt
├── run.py
└── README.md
```

---

## Timezone Handling

**Principle:** All times displayed in the user's local browser timezone.

**Implementation:**
- **Database Storage:** All timestamps stored in UTC (TIMESTAMPTZ)
- **API Responses:** Return timestamps in ISO8601 UTC format (e.g., `2026-01-15T14:30:00Z`)
- **Frontend Display:** JavaScript converts UTC to local browser timezone using `Intl.DateTimeFormat` or `toLocaleString()`
- **User Input:** When users select dates/times (e.g., booking), convert from local to UTC before sending to server

**Examples:**
```javascript
// Display UTC timestamp in local timezone
const utcTime = "2026-01-15T14:30:00Z";
const localDisplay = new Date(utcTime).toLocaleString();

// Convert local input to UTC for API
const localInput = new Date("2026-01-15 09:30");
const utcForApi = localInput.toISOString();
```

**Benefits:**
- Users see times in their own timezone automatically
- No timezone configuration needed per user
- Consistent storage format in database
- Works correctly across different timezones

---

## Security Considerations

1. **Authentication:** Passwordless magic links (10-minute expiry)
2. **API Keys:** Unique per customer, used for webhook URLs
3. **Webhook Signatures:** HMAC SHA256 verification for Retell and customer webhooks
4. **Role-Based Access:** Permissions checked on every request
5. **Rate Limiting:** Prevent abuse on public endpoints
6. **HTTPS Only:** All traffic encrypted
7. **SQL Injection:** Parameterized queries via SQLAlchemy ORM
8. **XSS Prevention:** Template escaping, CSP headers
9. **Recording Access:** Permission-based download, streaming only for playback

---

## Environment Variables

```env
# Flask
FLASK_APP=run.py
FLASK_ENV=production
SECRET_KEY=your-secret-key

# Database
DATABASE_URL=postgresql://user:pass@host:5432/dbname

# ClickSend (Email-to-SMS)
SMTP_HOST=smtp.example.com
SMTP_PORT=465
SMTP_USER=your-email@example.com
SMTP_PASS=your-password

# Retell (for signature verification)
RETELL_API_KEY=your-retell-api-key

# Platform
PLATFORM_NAME=Voice AI Platform
PLATFORM_URL=https://your-domain.com
```

---

## Webhook URL Format for Customers

Each customer receives a unique webhook URL to configure in Retell AI:

**Call Webhook:**
```
https://your-platform.com/api/v1/webhook/retell/{customer_api_key}
```

**Custom Functions:**
```
https://your-platform.com/api/v1/function/availability/{customer_api_key}
https://your-platform.com/api/v1/function/book/{customer_api_key}
https://your-platform.com/api/v1/function/lookup/{customer_api_key}
```

Example for customer with API key `abc123xyz`:
```
https://your-platform.com/api/v1/webhook/retell/abc123xyz
```

---

## Success Metrics

- Customer onboarding time < 5 minutes
- Webhook response time < 500ms
- 99.9% uptime for webhook endpoints
- Zero balance discrepancies
- All notifications delivered < 30 seconds
- Invoice generation < 5 seconds

---

## Future Enhancements (Post-MVP)

1. **Stripe Integration** - Automated payments & top-ups
2. **Real-time Dashboard** - WebSocket updates for live calls
3. **Call Analytics** - Trends, sentiment analysis, insights
4. **Multi-language Support** - i18n for international customers
5. **White-label Option** - Custom branding per customer
6. **Customer API** - Let customers build their own integrations
7. **Mobile App** - iOS/Android for notifications
8. **Outbound Calls** - Retell outbound campaign support
9. **Call Recordings Storage** - Optional local storage with retention policies
10. **Two-Factor Authentication** - Enhanced security option
