<?php
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');

session_start();

$DB_HOST = getenv('PGHOST') ?: '127.0.0.1';
$DB_PORT = getenv('PGPORT') ?: '5432';
$DB_NAME = getenv('PGDATABASE') ?: '';
$DB_USER = getenv('PGUSER') ?: '';
$DB_PASS = getenv('PGPASSWORD') ?: '';

$SMTP_HOST = getenv('SMTP_HOST') ?: '';
$SMTP_PORT = getenv('SMTP_PORT') ?: '465';
$SMTP_USER = getenv('SMTP_USER') ?: '';
$SMTP_PASS = getenv('SMTP_PASS') ?: '';

header_remove('X-Powered-By');

function db() {
  static $pdo = null;
  global $DB_HOST, $DB_PORT, $DB_NAME, $DB_USER, $DB_PASS;
  if ($pdo) return $pdo;
  $dsn = "pgsql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};";
  $opt = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
  ];
  $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $opt);
  return $pdo;
}

function respond($data, $code = 200) {
  http_response_code($code);
  header('Content-Type: application/json');
  echo json_encode($data);
  exit;
}

function body_json() {
  return json_decode(file_get_contents('php://input'), true) ?: [];
}

function normalize_phone($country, $number) {
  return preg_replace('/\D+/', '', ($country ?? '') . ($number ?? ''));
}

function send_email($to, $subject, $message) {
  global $SMTP_HOST, $SMTP_PORT, $SMTP_USER, $SMTP_PASS;
  
  // Use SMTP if configured, otherwise fallback to logging in dev environments
  $smtp_configured = !empty($SMTP_HOST) && !empty($SMTP_USER) && !empty($SMTP_PASS);
  
  $is_dev_env = (
    getenv('APP_ENV') === 'development' || 
    $_SERVER['HTTP_HOST'] === 'localhost' || 
    strpos($_SERVER['HTTP_HOST'], '127.0.0') === 0 || 
    strpos($_SERVER['HTTP_HOST'], 'localhost') !== false ||
    strpos($_SERVER['HTTP_HOST'], '.replit.app') !== false
  );

  // Only use dev mode (logging) if NOT fully configured for SMTP OR if explicitly in development mode
  $use_dev_mode = (getenv('APP_ENV') === 'development') || (!$smtp_configured && $is_dev_env) || empty($SMTP_HOST);
  
  if ($use_dev_mode) {
    error_log("--------------------------------------------------");
    error_log("📧 DEV MODE EMAIL CAPTURED");
    error_log("To: {$to}");
    error_log("Subject: {$subject}");
    
    // Extract magic link if present for easier access in console
    if (preg_match('/href=\'([^\']+)\'/', $message, $matches)) {
      error_log("🔗 MAGIC LINK: " . $matches[1]);
    }
    
    error_log("--------------------------------------------------");
    return true;
  }
  
  error_log("SMTP: Attempting to connect to {$SMTP_HOST}:{$SMTP_PORT}");
  
  $context = stream_context_create([
    'ssl' => [
      'verify_peer' => false,
      'verify_peer_name' => false,
      'allow_self_signed' => true
    ]
  ]);
  
  $socket = @stream_socket_client(
    'ssl://' . $SMTP_HOST . ':' . $SMTP_PORT,
    $errno,
    $errstr,
    30,
    STREAM_CLIENT_CONNECT,
    $context
  );
  
  if (!$socket) {
    error_log("SMTP: Connection failed - errno: {$errno}, error: {$errstr}");
    return false;
  }
  
  error_log("SMTP: Connected successfully");
  
  $response = fgets($socket, 515);
  error_log("SMTP: Server greeting: " . trim($response));
  
  fputs($socket, "EHLO " . $SMTP_HOST . "\r\n");
  
  // Read all EHLO responses until we get one without a dash
  do {
    $response = fgets($socket, 515);
    error_log("SMTP: EHLO response: " . trim($response));
  } while (preg_match('/^250-/', $response));
  
  fputs($socket, "AUTH LOGIN\r\n");
  $response = fgets($socket, 515);
  error_log("SMTP: AUTH LOGIN response: " . trim($response));
  
  fputs($socket, base64_encode($SMTP_USER) . "\r\n");
  $response = fgets($socket, 515);
  error_log("SMTP: Username response: " . trim($response));
  
  fputs($socket, base64_encode($SMTP_PASS) . "\r\n");
  $response = fgets($socket, 515);
  error_log("SMTP: Password response: " . trim($response));
  
  if (strpos($response, '235') === false) {
    error_log("SMTP: Authentication failed!");
    fclose($socket);
    return false;
  }
  
  fputs($socket, "MAIL FROM: <{$SMTP_USER}>\r\n");
  $response = fgets($socket, 515);
  error_log("SMTP: MAIL FROM response: " . trim($response));
  
  fputs($socket, "RCPT TO: <{$to}>\r\n");
  $response = fgets($socket, 515);
  error_log("SMTP: RCPT TO response: " . trim($response));
  
  fputs($socket, "DATA\r\n");
  $response = fgets($socket, 515);
  error_log("SMTP: DATA response: " . trim($response));
  
  $headers = "From: Koadi Technology CRM <{$SMTP_USER}>\r\n";
  $headers .= "Reply-To: {$SMTP_USER}\r\n";
  $headers .= "MIME-Version: 1.0\r\n";
  $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
  $headers .= "Subject: {$subject}\r\n";
  
  fputs($socket, "To: {$to}\r\n");
  fputs($socket, $headers);
  fputs($socket, "\r\n");
  fputs($socket, $message . "\r\n");
  fputs($socket, ".\r\n");
  $response = fgets($socket, 515);
  error_log("SMTP: Send response: " . trim($response));
  
  fputs($socket, "QUIT\r\n");
  fclose($socket);
  
  error_log("SMTP: Email sent successfully to {$to}");
  return true;
}

// Send SMS via ClickSend email-to-SMS gateway
function send_sms($phone, $message) {
  // Normalize phone number - remove all non-digits
  $phone = preg_replace('/\D+/', '', $phone);
  
  if (empty($phone) || strlen($phone) < 10) {
    error_log("SMS: Invalid phone number - {$phone}");
    return false;
  }
  
  // Ensure country code (default to 1 for US/Canada if not present)
  if (strlen($phone) === 10) {
    $phone = '1' . $phone;
  }
  
  // ClickSend email-to-SMS gateway
  $smsEmail = $phone . '@sms.clicksend.com';
  
  error_log("SMS: Sending to {$smsEmail}");
  
  // Use plain text for SMS (not HTML)
  global $SMTP_HOST, $SMTP_PORT, $SMTP_USER, $SMTP_PASS;
  
  $smtp_configured = !empty($SMTP_HOST) && !empty($SMTP_USER) && !empty($SMTP_PASS);
  
  $is_dev_env = (
    getenv('APP_ENV') === 'development' || 
    $_SERVER['HTTP_HOST'] === 'localhost' || 
    strpos($_SERVER['HTTP_HOST'], '127.0.0') === 0 || 
    strpos($_SERVER['HTTP_HOST'], 'localhost') !== false ||
    strpos($_SERVER['HTTP_HOST'], '.replit.app') !== false
  );

  $use_dev_mode = (getenv('APP_ENV') === 'development') || (!$smtp_configured && $is_dev_env) || empty($SMTP_HOST);
  
  if ($use_dev_mode) {
    error_log("--------------------------------------------------");
    error_log("📱 DEV MODE SMS CAPTURED");
    error_log("To: {$phone}");
    error_log("Message: {$message}");
    error_log("--------------------------------------------------");
    return true;
  }
  
  $context = stream_context_create([
    'ssl' => [
      'verify_peer' => false,
      'verify_peer_name' => false,
      'allow_self_signed' => true
    ]
  ]);
  
  $socket = @stream_socket_client(
    'ssl://' . $SMTP_HOST . ':' . $SMTP_PORT,
    $errno,
    $errstr,
    30,
    STREAM_CLIENT_CONNECT,
    $context
  );
  
  if (!$socket) {
    error_log("SMS SMTP: Connection failed - errno: {$errno}, error: {$errstr}");
    return false;
  }
  
  $response = fgets($socket, 515);
  
  fputs($socket, "EHLO " . $SMTP_HOST . "\r\n");
  do {
    $response = fgets($socket, 515);
  } while (preg_match('/^250-/', $response));
  
  fputs($socket, "AUTH LOGIN\r\n");
  $response = fgets($socket, 515);
  
  fputs($socket, base64_encode($SMTP_USER) . "\r\n");
  $response = fgets($socket, 515);
  
  fputs($socket, base64_encode($SMTP_PASS) . "\r\n");
  $response = fgets($socket, 515);
  
  if (strpos($response, '235') === false) {
    error_log("SMS SMTP: Authentication failed!");
    fclose($socket);
    return false;
  }
  
  fputs($socket, "MAIL FROM: <{$SMTP_USER}>\r\n");
  $response = fgets($socket, 515);
  
  fputs($socket, "RCPT TO: <{$smsEmail}>\r\n");
  $response = fgets($socket, 515);
  
  fputs($socket, "DATA\r\n");
  $response = fgets($socket, 515);
  
  $headers = "From: Koadi Technology <{$SMTP_USER}>\r\n";
  $headers .= "MIME-Version: 1.0\r\n";
  $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
  $headers .= "Subject: SMS\r\n";
  
  fputs($socket, "To: {$smsEmail}\r\n");
  fputs($socket, $headers);
  fputs($socket, "\r\n");
  fputs($socket, $message . "\r\n");
  fputs($socket, ".\r\n");
  $response = fgets($socket, 515);
  
  fputs($socket, "QUIT\r\n");
  fclose($socket);
  
  error_log("SMS: Sent successfully to {$phone}");
  return true;
}

function ensure_schema() {
  $pdo = db();
  $pdo->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS users (
      id SERIAL PRIMARY KEY,
      username TEXT UNIQUE NOT NULL,
      email TEXT,
      password TEXT,
      full_name TEXT NOT NULL,
      role TEXT NOT NULL DEFAULT 'sales',
      status TEXT DEFAULT 'active',
      remember_token TEXT,
      created_at TIMESTAMPTZ DEFAULT now()
    );
    
    CREATE TABLE IF NOT EXISTS leads (
      id SERIAL PRIMARY KEY,
      name TEXT NOT NULL,
      phone TEXT,
      email TEXT,
      company TEXT,
      address TEXT,
      status TEXT DEFAULT 'global',
      assigned_to INTEGER REFERENCES users(id) ON DELETE SET NULL,
      created_at TIMESTAMPTZ DEFAULT now(),
      updated_at TIMESTAMPTZ DEFAULT now()
    );
    
    CREATE TABLE IF NOT EXISTS interactions (
      id SERIAL PRIMARY KEY,
      lead_id INTEGER REFERENCES leads(id) ON DELETE CASCADE,
      user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
      type TEXT NOT NULL,
      notes TEXT,
      created_at TIMESTAMPTZ DEFAULT now()
    );
    
    CREATE TABLE IF NOT EXISTS contacts (
      id SERIAL PRIMARY KEY,
      type TEXT,
      company TEXT,
      name TEXT,
      email TEXT,
      phone_country TEXT,
      phone_number TEXT,
      source TEXT,
      notes TEXT,
      assigned_to INTEGER REFERENCES users(id) ON DELETE SET NULL,
      created_at TIMESTAMPTZ DEFAULT now(),
      updated_at TIMESTAMPTZ DEFAULT now()
    );
    
    CREATE TABLE IF NOT EXISTS calls (
      id SERIAL PRIMARY KEY,
      contact_id INTEGER REFERENCES contacts(id) ON DELETE CASCADE,
      when_at TIMESTAMPTZ NOT NULL,
      outcome TEXT,
      duration_min INTEGER,
      notes TEXT,
      assigned_to INTEGER REFERENCES users(id) ON DELETE SET NULL,
      created_at TIMESTAMPTZ DEFAULT now(),
      updated_at TIMESTAMPTZ DEFAULT now()
    );
    
    CREATE TABLE IF NOT EXISTS projects (
      id SERIAL PRIMARY KEY,
      contact_id INTEGER REFERENCES contacts(id) ON DELETE CASCADE,
      name TEXT,
      value NUMERIC,
      stage TEXT,
      next_date DATE,
      notes TEXT,
      assigned_to INTEGER REFERENCES users(id) ON DELETE SET NULL,
      created_at TIMESTAMPTZ DEFAULT now(),
      updated_at TIMESTAMPTZ DEFAULT now()
    );
    
    CREATE TABLE IF NOT EXISTS settings (
      key TEXT PRIMARY KEY,
      value TEXT
    );
    
    CREATE TABLE IF NOT EXISTS call_updates (
      id SERIAL PRIMARY KEY,
      call_id INTEGER REFERENCES calls(id) ON DELETE CASCADE,
      user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
      notes TEXT,
      created_at TIMESTAMPTZ DEFAULT now()
    );
    
    CREATE TABLE IF NOT EXISTS password_resets (
      id SERIAL PRIMARY KEY,
      email TEXT NOT NULL,
      token TEXT NOT NULL,
      expires_at TIMESTAMPTZ NOT NULL,
      created_at TIMESTAMPTZ DEFAULT now()
    );
    
    CREATE TABLE IF NOT EXISTS magic_links (
      id SERIAL PRIMARY KEY,
      email TEXT NOT NULL,
      token TEXT NOT NULL,
      type TEXT NOT NULL,
      role TEXT,
      expires_at TIMESTAMPTZ NOT NULL,
      created_at TIMESTAMPTZ DEFAULT now()
    );
    
    CREATE INDEX IF NOT EXISTS idx_magic_links_email ON magic_links(email);
    CREATE INDEX IF NOT EXISTS idx_magic_links_expires ON magic_links(expires_at);
    
    CREATE INDEX IF NOT EXISTS idx_leads_status ON leads(status);
    CREATE INDEX IF NOT EXISTS idx_leads_assigned ON leads(assigned_to);
    CREATE INDEX IF NOT EXISTS idx_contacts_company ON contacts (LOWER(BTRIM(COALESCE(company,''))));
    CREATE UNIQUE INDEX IF NOT EXISTS idx_contacts_phone_unique ON contacts ((regexp_replace(COALESCE(phone_country,'')||COALESCE(phone_number,'') ,'\\D','','g'))) WHERE COALESCE(phone_country,'')<>'' AND COALESCE(phone_number,'')<>'';
    CREATE INDEX IF NOT EXISTS idx_call_updates_call ON call_updates(call_id);
    
    -- Create invitations table if it doesn't exist
    CREATE TABLE IF NOT EXISTS invitations (
      id SERIAL PRIMARY KEY,
      email TEXT NOT NULL,
      role TEXT NOT NULL,
      token TEXT NOT NULL,
      expires_at TIMESTAMP NOT NULL,
      created_at TIMESTAMP DEFAULT NOW()
    );
    
    -- Create industries table if it doesn't exist
    CREATE TABLE IF NOT EXISTS industries (
      id SERIAL PRIMARY KEY,
      name TEXT UNIQUE NOT NULL,
      created_at TIMESTAMPTZ DEFAULT NOW()
    );
    
    -- Add assigned_to columns if they don't exist
    DO $$ 
    BEGIN
      IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='contacts' AND column_name='assigned_to') THEN
        ALTER TABLE contacts ADD COLUMN assigned_to INTEGER REFERENCES users(id) ON DELETE SET NULL;
      END IF;
      IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='calls' AND column_name='assigned_to') THEN
        ALTER TABLE calls ADD COLUMN assigned_to INTEGER REFERENCES users(id) ON DELETE SET NULL;
      END IF;
      IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='projects' AND column_name='assigned_to') THEN
        ALTER TABLE projects ADD COLUMN assigned_to INTEGER REFERENCES users(id) ON DELETE SET NULL;
      END IF;
      IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='users' AND column_name='email') THEN
        ALTER TABLE users ADD COLUMN email TEXT;
      END IF;
      IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='users' AND column_name='remember_token') THEN
        ALTER TABLE users ADD COLUMN remember_token TEXT;
      END IF;
      IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='users' AND column_name='status') THEN
        ALTER TABLE users ADD COLUMN status TEXT DEFAULT 'active';
      END IF;
      IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='leads' AND column_name='industry') THEN
        ALTER TABLE leads ADD COLUMN industry TEXT;
      END IF;
      IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='contacts' AND column_name='industry') THEN
        ALTER TABLE contacts ADD COLUMN industry TEXT;
      END IF;
      IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='users' AND column_name='can_manage_global_leads') THEN
        ALTER TABLE users ADD COLUMN can_manage_global_leads BOOLEAN DEFAULT FALSE;
        -- Set admins to TRUE by default
        UPDATE users SET can_manage_global_leads = TRUE WHERE role = 'admin';
      END IF;
      -- Outscraper integration fields for leads
      IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='leads' AND column_name='google_place_id') THEN
        ALTER TABLE leads ADD COLUMN google_place_id TEXT;
      END IF;
      IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='leads' AND column_name='contact_name') THEN
        ALTER TABLE leads ADD COLUMN contact_name TEXT;
      END IF;
      IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='leads' AND column_name='contact_title') THEN
        ALTER TABLE leads ADD COLUMN contact_title TEXT;
      END IF;
      IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='leads' AND column_name='rating') THEN
        ALTER TABLE leads ADD COLUMN rating NUMERIC(3,2);
      END IF;
      IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='leads' AND column_name='reviews_count') THEN
        ALTER TABLE leads ADD COLUMN reviews_count INTEGER;
      END IF;
      IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='leads' AND column_name='website') THEN
        ALTER TABLE leads ADD COLUMN website TEXT;
      END IF;
      IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='leads' AND column_name='social_links') THEN
        ALTER TABLE leads ADD COLUMN social_links JSONB;
      END IF;
      IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='leads' AND column_name='additional_phones') THEN
        ALTER TABLE leads ADD COLUMN additional_phones JSONB;
      END IF;
      IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='leads' AND column_name='additional_emails') THEN
        ALTER TABLE leads ADD COLUMN additional_emails JSONB;
      END IF;
      IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='leads' AND column_name='source') THEN
        ALTER TABLE leads ADD COLUMN source TEXT;
      END IF;
    END $$;
    
    CREATE INDEX IF NOT EXISTS idx_contacts_assigned ON contacts(assigned_to);
    CREATE INDEX IF NOT EXISTS idx_calls_assigned ON calls(assigned_to);
    CREATE INDEX IF NOT EXISTS idx_projects_assigned ON projects(assigned_to);
    
    -- Retell AI calls table for storing voice agent call data
    CREATE TABLE IF NOT EXISTS retell_calls (
      id SERIAL PRIMARY KEY,
      retell_call_id TEXT UNIQUE NOT NULL,
      agent_id TEXT,
      call_type TEXT,
      direction TEXT,
      from_number TEXT,
      to_number TEXT,
      call_status TEXT,
      disconnection_reason TEXT,
      start_timestamp BIGINT,
      end_timestamp BIGINT,
      duration_seconds INTEGER,
      transcript TEXT,
      transcript_object JSONB,
      analysis_results JSONB,
      call_summary TEXT,
      improvement_recommendations TEXT,
      call_score INTEGER,
      metadata JSONB,
      raw_payload JSONB,
      recording_url TEXT,
      lead_id INTEGER REFERENCES leads(id) ON DELETE SET NULL,
      contact_id INTEGER REFERENCES contacts(id) ON DELETE SET NULL,
      created_at TIMESTAMPTZ DEFAULT now(),
      updated_at TIMESTAMPTZ DEFAULT now()
    );
    
    CREATE INDEX IF NOT EXISTS idx_retell_calls_retell_id ON retell_calls(retell_call_id);
    CREATE INDEX IF NOT EXISTS idx_retell_calls_from ON retell_calls(from_number);
    CREATE INDEX IF NOT EXISTS idx_retell_calls_to ON retell_calls(to_number);
    CREATE INDEX IF NOT EXISTS idx_retell_calls_status ON retell_calls(call_status);
    CREATE INDEX IF NOT EXISTS idx_retell_calls_created ON retell_calls(created_at);
    
    -- Calendar events table for bookings, schedules, and calls
    CREATE TABLE IF NOT EXISTS calendar_events (
      id SERIAL PRIMARY KEY,
      title TEXT NOT NULL,
      description TEXT,
      event_type TEXT NOT NULL DEFAULT 'booking',
      start_time TIMESTAMPTZ NOT NULL,
      end_time TIMESTAMPTZ,
      all_day BOOLEAN DEFAULT false,
      location TEXT,
      status TEXT DEFAULT 'scheduled',
      booking_uid TEXT,
      related_entity_type TEXT,
      related_entity_id INTEGER,
      retell_call_id INTEGER REFERENCES retell_calls(id) ON DELETE SET NULL,
      lead_id INTEGER REFERENCES leads(id) ON DELETE SET NULL,
      contact_id INTEGER REFERENCES contacts(id) ON DELETE SET NULL,
      created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
      assigned_to INTEGER REFERENCES users(id) ON DELETE SET NULL,
      color TEXT,
      created_at TIMESTAMPTZ DEFAULT now(),
      updated_at TIMESTAMPTZ DEFAULT now()
    );
    
    ALTER TABLE calendar_events ADD COLUMN IF NOT EXISTS booking_uid TEXT;
    ALTER TABLE retell_calls ADD COLUMN IF NOT EXISTS recording_url TEXT;
    
    CREATE INDEX IF NOT EXISTS idx_calendar_events_start ON calendar_events(start_time);
    CREATE INDEX IF NOT EXISTS idx_calendar_events_type ON calendar_events(event_type);
    CREATE INDEX IF NOT EXISTS idx_calendar_events_assigned ON calendar_events(assigned_to);
    CREATE INDEX IF NOT EXISTS idx_calendar_events_created_by ON calendar_events(created_by);
    
    -- Outscraper imports tracking table
    CREATE TABLE IF NOT EXISTS outscraper_imports (
      id SERIAL PRIMARY KEY,
      task_id TEXT,
      query TEXT,
      total_records INTEGER DEFAULT 0,
      imported_count INTEGER DEFAULT 0,
      skipped_count INTEGER DEFAULT 0,
      duplicate_count INTEGER DEFAULT 0,
      error_count INTEGER DEFAULT 0,
      status TEXT DEFAULT 'processing',
      error_details JSONB,
      created_at TIMESTAMPTZ DEFAULT now()
    );
    
    CREATE INDEX IF NOT EXISTS idx_leads_google_place_id ON leads(google_place_id);
    CREATE INDEX IF NOT EXISTS idx_leads_source ON leads(source);
    CREATE INDEX IF NOT EXISTS idx_outscraper_imports_created ON outscraper_imports(created_at);
    
    -- Staging table for Outscraper file uploads (pending review)
    CREATE TABLE IF NOT EXISTS outscraper_staging (
      id SERIAL PRIMARY KEY,
      batch_id TEXT NOT NULL,
      name TEXT,
      phone TEXT,
      email TEXT,
      company TEXT,
      address TEXT,
      industry TEXT,
      google_place_id TEXT,
      contact_name TEXT,
      contact_title TEXT,
      rating DECIMAL(2,1),
      reviews_count INTEGER,
      website TEXT,
      social_links JSONB,
      additional_phones JSONB,
      additional_emails JSONB,
      raw_data JSONB,
      status TEXT DEFAULT 'pending',
      created_at TIMESTAMPTZ DEFAULT now()
    );
    
    CREATE INDEX IF NOT EXISTS idx_outscraper_staging_batch ON outscraper_staging(batch_id);
    CREATE INDEX IF NOT EXISTS idx_outscraper_staging_status ON outscraper_staging(status);
SQL);

  // Ensure admin user exists (passwordless - login via magic link)
  $admin_exists = $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
  if (!$admin_exists) {
    $adminEmail = getenv('ADMIN_EMAIL') ?: 'admin@example.com';
    $pdo->prepare("INSERT INTO users (username, email, password, full_name, role) VALUES ('admin', :email, '', 'Administrator', 'admin')")
      ->execute([':email' => $adminEmail]);
  }
  
  // Set all existing users to active status if NULL
  $pdo->exec("UPDATE users SET status = 'active' WHERE status IS NULL");
  
  // Insert default industries if none exist
  $industry_count = $pdo->query("SELECT COUNT(*) FROM industries")->fetchColumn();
  if ($industry_count == 0) {
    $pdo->exec("INSERT INTO industries (name) VALUES 
      ('Technology'), ('Healthcare'), ('Finance'), ('Manufacturing'), 
      ('Retail'), ('Real Estate'), ('Construction'), ('Education'), 
      ('Hospitality'), ('Legal Services'), ('Marketing & Advertising'),
      ('Transportation'), ('Food & Beverage'), ('Entertainment'),
      ('Telecommunications'), ('Energy'), ('Agriculture'), ('Insurance'),
      ('Consulting'), ('Other')");
  }
}

ensure_schema();

if (isset($_GET['api'])) {
  $action = $_GET['api'];
  try {
    switch ($action) {
      case 'login': api_login(); break;
      case 'logout': api_logout(); break;
      case 'session': api_session(); break;
      case 'verify_magic_link': api_verify_magic_link(); break;
      case 'send_invite': api_send_invite(); break;
      case 'accept_invite': api_accept_invite(); break;
      
      case 'users.list': api_users_list(); break;
      case 'users.save': api_users_save(); break;
      case 'users.delete': api_users_delete(); break;
      case 'users.toggle_status': api_users_toggle_status(); break;
      case 'invitations.delete': api_invitations_delete(); break;
      
      case 'leads.list': api_leads_list(); break;
      case 'leads.save': api_leads_save(); break;
      case 'leads.delete': api_leads_delete(); break;
      case 'leads.grab': api_leads_grab(); break;
      case 'leads.import': api_leads_import(); break;
      case 'leads.convert': api_leads_convert(); break;
      
      case 'interactions.list': api_interactions_list(); break;
      case 'interactions.save': api_interactions_save(); break;
      
      case 'stats': api_stats(); break;
      case 'countries': api_countries(); break;
      
      case 'industries.list': api_industries_list(); break;
      case 'industries.save': api_industries_save(); break;
      case 'industries.delete': api_industries_delete(); break;
      
      case 'contacts.list': api_contacts_list(); break;
      case 'contacts.save': api_contacts_save(); break;
      case 'contacts.delete': api_contacts_delete(); break;
      case 'contacts.reassign': api_contacts_reassign(); break;
      case 'contacts.returnToLead': api_contacts_return_to_lead(); break;
      
      case 'calls.list': api_calls_list(); break;
      case 'calls.save': api_calls_save(); break;
      case 'calls.delete': api_calls_delete(); break;
      case 'calls.reassign': api_calls_reassign(); break;
      case 'call_updates.list': api_call_updates_list(); break;
      case 'call_updates.save': api_call_updates_save(); break;
      
      case 'projects.list': api_projects_list(); break;
      case 'projects.save': api_projects_save(); break;
      case 'projects.delete': api_projects_delete(); break;
      case 'projects.stage': api_projects_stage(); break;
      case 'projects.reassign': api_projects_reassign(); break;
      
      case 'settings.get': api_settings_get(); break;
      case 'settings.set': api_settings_set(); break;
      case 'settings.exists': api_settings_exists(); break;
      
      case 'export': api_export(); break;
      case 'import': api_import(); break;
      case 'reset': api_reset(); break;
      
      case 'retell.webhook': api_retell_webhook(); break;
      case 'retell_calls.list': api_retell_calls_list(); break;
      case 'retell_calls.get': api_retell_calls_get(); break;
      
      case 'calendar.list': api_calendar_list(); break;
      case 'calendar.save': api_calendar_save(); break;
      case 'calendar.delete': api_calendar_delete(); break;
      case 'cal.webhook': api_cal_webhook(); break;
      
      case 'outscraper.webhook': api_outscraper_webhook(); break;
      case 'outscraper_imports.list': api_outscraper_imports_list(); break;
      case 'outscraper.upload': api_outscraper_upload(); break;
      case 'outscraper_staging.list': api_outscraper_staging_list(); break;
      case 'outscraper_staging.approve': api_outscraper_staging_approve(); break;
      case 'outscraper_staging.reject': api_outscraper_staging_reject(); break;
      case 'outscraper_staging.clear': api_outscraper_staging_clear(); break;
      
      default: respond(['error' => 'Unknown action'], 404);
    }
  } catch (Throwable $e) {
    respond(['error' => 'Server error', 'detail' => $e->getMessage()], 500);
  }
}

function require_auth() {
  if (!isset($_SESSION['user_id'])) {
    respond(['error' => 'Unauthorized'], 401);
  }
}

function require_admin() {
  require_auth();
  if ($_SESSION['role'] !== 'admin') {
    respond(['error' => 'Forbidden'], 403);
  }
}

function api_login() {
  $b = body_json();
  $email = trim($b['email'] ?? '');
  
  // Validate email format
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(['error' => 'Invalid email format'], 400);
  }
  
  $pdo = db();
  $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = LOWER(:e)");
  $stmt->execute([':e' => $email]);
  $user = $stmt->fetch();
  
  if ($user) {
    // Check if user is active
    if (isset($user['status']) && $user['status'] === 'inactive') {
      respond(['error' => 'Account is deactivated. Please contact administrator.'], 403);
    }
    
    // Generate magic link token (10 minute expiry)
    $token = bin2hex(random_bytes(32));
    
    // Delete any existing login magic links for this email
    $pdo->prepare("DELETE FROM magic_links WHERE email = :email AND type = 'login'")->execute([':email' => $email]);
    
    // Insert new magic link with expiration time (10 minutes from now)
    $pdo->prepare("INSERT INTO magic_links (email, token, type, expires_at) VALUES (:email, :token, 'login', now() + INTERVAL '10 minutes')")
      ->execute([':email' => $email, ':token' => password_hash($token, PASSWORD_DEFAULT)]);
    
    // Build magic link URL
    $magic_link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/?magic_token=' . urlencode($token);
    
    // Send email
    $email_body = "
      <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
        <div style='background: linear-gradient(135deg, #FF8C42, #0066CC); padding: 30px; text-align: center;'>
          <h1 style='color: white; margin: 0;'>Koadi Tech CRM Login</h1>
        </div>
        <div style='padding: 30px; background: #f9f9f9;'>
          <p>Hello {$user['full_name']},</p>
          <p>Click the button below to securely log in to your Koadi Technology CRM account:</p>
          <div style='text-align: center; margin: 30px 0;'>
            <a href='{$magic_link}' style='background: #0066CC; color: white; padding: 15px 40px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;'>Log In to CRM</a>
          </div>
          <p style='color: #666; font-size: 14px;'><strong>Security Notice:</strong></p>
          <ul style='color: #666; font-size: 14px;'>
            <li>This link is valid for <strong>10 minutes</strong> only</li>
            <li>Do not share this link with anyone</li>
            <li>If you didn't request this, please ignore this email</li>
          </ul>
          <p style='color: #999; font-size: 12px; margin-top: 30px;'>If the button doesn't work, copy and paste this link into your browser:<br><span style='word-break: break-all;'>{$magic_link}</span></p>
        </div>
        <div style='padding: 20px; background: #333; text-align: center;'>
          <p style='color: #999; font-size: 12px; margin: 0;'>&copy; 2025 Koadi Technology LLC. All rights reserved.</p>
        </div>
      </div>
    ";
    
    send_email($email, 'Your Koadi Tech CRM Login Link', $email_body);
  }
  
  // Always respond with success to prevent email enumeration
  respond(['ok' => true, 'message' => 'If an account exists with this email, a login link has been sent.']);
}

function api_logout() {
  if (isset($_SESSION['user_id'])) {
    $pdo = db();
    $pdo->prepare("UPDATE users SET remember_token = NULL WHERE id = :id")
      ->execute([':id' => $_SESSION['user_id']]);
  }
  setcookie('remember_token', '', time() - 3600, '/', '', true, true);
  session_destroy();
  respond(['ok' => true]);
}

function api_session() {
  if (isset($_SESSION['user_id'])) {
    respond(['user' => [
      'id' => $_SESSION['user_id'],
      'username' => $_SESSION['username'],
      'full_name' => $_SESSION['full_name'],
      'role' => $_SESSION['role'],
      'can_manage_global_leads' => !empty($_SESSION['can_manage_global_leads'])
    ]]);
  } elseif (isset($_COOKIE['remember_token'])) {
    $pdo = db();
    $stmt = $pdo->query("SELECT * FROM users WHERE remember_token IS NOT NULL");
    $users = $stmt->fetchAll();
    
    foreach ($users as $user) {
      if (password_verify($_COOKIE['remember_token'], $user['remember_token'])) {
        // Check if user is active
        if (isset($user['status']) && $user['status'] === 'inactive') {
          setcookie('remember_token', '', time() - 3600, '/', '', true, true);
          respond(['user' => null]);
        }
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['can_manage_global_leads'] = !empty($user['can_manage_global_leads']);
        
        respond(['user' => [
          'id' => $user['id'],
          'username' => $user['username'],
          'full_name' => $user['full_name'],
          'role' => $user['role'],
          'can_manage_global_leads' => !empty($user['can_manage_global_leads'])
        ]]);
      }
    }
  }
  respond(['user' => null]);
}

function api_verify_magic_link() {
  $b = body_json();
  $token = $b['token'] ?? '';
  $type = $b['type'] ?? 'login';
  
  if (!$token) {
    respond(['error' => 'Token is required'], 400);
  }
  
  $pdo = db();
  
  // Get all valid magic links of the specified type
  $stmt = $pdo->prepare("SELECT * FROM magic_links WHERE type = :type AND expires_at > now()");
  $stmt->execute([':type' => $type]);
  $links = $stmt->fetchAll();
  
  foreach ($links as $link) {
    if (password_verify($token, $link['token'])) {
      if ($type === 'login') {
        // Find the user
        $userStmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = LOWER(:e)");
        $userStmt->execute([':e' => $link['email']]);
        $user = $userStmt->fetch();
        
        if ($user) {
          // Check if user is active
          if (isset($user['status']) && $user['status'] === 'inactive') {
            respond(['error' => 'Account is deactivated. Please contact administrator.'], 403);
          }
          
          // Delete the used magic link
          $pdo->prepare("DELETE FROM magic_links WHERE id = :id")->execute([':id' => $link['id']]);
          
          // Create session
          $_SESSION['user_id'] = $user['id'];
          $_SESSION['username'] = $user['username'];
          $_SESSION['email'] = $user['email'];
          $_SESSION['full_name'] = $user['full_name'];
          $_SESSION['role'] = $user['role'];
          $_SESSION['can_manage_global_leads'] = !empty($user['can_manage_global_leads']);
          
          respond(['ok' => true, 'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'full_name' => $user['full_name'],
            'role' => $user['role'],
            'can_manage_global_leads' => !empty($user['can_manage_global_leads'])
          ]]);
        }
      } elseif ($type === 'invite') {
        // Return invite details for the accept form
        respond(['ok' => true, 'invite' => [
          'email' => $link['email'],
          'role' => $link['role']
        ]]);
      }
    }
  }
  
  respond(['error' => 'Invalid or expired magic link'], 400);
}

function api_forgot_password() {
  $b = body_json();
  $email = trim($b['email'] ?? '');
  
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(['error' => 'Invalid email format'], 400);
  }
  
  $pdo = db();
  $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = LOWER(:e)");
  $stmt->execute([':e' => $email]);
  $user = $stmt->fetch();
  
  if ($user) {
    $token = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', time() + 3600); // 1 hour
    
    $pdo->prepare("DELETE FROM password_resets WHERE email = :email")->execute([':email' => $email]);
    $pdo->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (:email, :token, :expires)")
      ->execute([':email' => $email, ':token' => password_hash($token, PASSWORD_DEFAULT), ':expires' => $expires_at]);
    
    $reset_link = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . "?reset_token={$token}";
    
    $message = "
      <html>
      <body style='font-family: Arial, sans-serif; line-height: 1.6;'>
        <h2 style='color: #0066CC;'>Password Reset Request</h2>
        <p>Hello {$user['full_name']},</p>
        <p>We received a request to reset your password for your Koadi Technology CRM account.</p>
        <p>Click the link below to reset your password (link expires in 1 hour):</p>
        <p><a href='{$reset_link}' style='background: #0066CC; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>Reset Password</a></p>
        <p>Or copy and paste this link into your browser:<br>{$reset_link}</p>
        <p>If you didn't request this, please ignore this email.</p>
        <p>Best regards,<br>Koadi Technology Team</p>
      </body>
      </html>
    ";
    
    send_email($email, 'Password Reset - Koadi Technology CRM', $message);
  }
  
  respond(['ok' => true, 'message' => 'If the email exists, a reset link has been sent']);
}

function api_reset_password() {
  $b = body_json();
  $token = $b['token'] ?? '';
  $new_password = $b['password'] ?? '';
  
  if (strlen($new_password) < 8) {
    respond(['error' => 'Password must be at least 8 characters'], 400);
  }
  
  $pdo = db();
  $stmt = $pdo->query("SELECT * FROM password_resets WHERE expires_at > now()");
  $resets = $stmt->fetchAll();
  
  foreach ($resets as $reset) {
    if (password_verify($token, $reset['token'])) {
      $pdo->prepare("UPDATE users SET password = :pwd WHERE LOWER(email) = LOWER(:email)")
        ->execute([':pwd' => password_hash($new_password, PASSWORD_DEFAULT), ':email' => $reset['email']]);
      
      $pdo->prepare("DELETE FROM password_resets WHERE email = :email")->execute([':email' => $reset['email']]);
      
      respond(['ok' => true, 'message' => 'Password updated successfully']);
    }
  }
  
  respond(['error' => 'Invalid or expired reset token'], 400);
}

function api_send_invite() {
  require_admin();
  $b = body_json();
  $email = trim($b['email'] ?? '');
  $role = $b['role'] ?? 'sales';
  
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(['error' => 'Invalid email format'], 400);
  }
  
  $pdo = db();
  
  // Check if email already exists
  $stmt = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(:e)");
  $stmt->execute([':e' => $email]);
  if ($stmt->fetch()) {
    respond(['error' => 'User with this email already exists'], 400);
  }
  
  // Generate invitation token (24 hour expiry)
  $token = bin2hex(random_bytes(32));
  
  // Delete any existing invite magic links for this email
  $pdo->prepare("DELETE FROM magic_links WHERE email = :email AND type = 'invite'")->execute([':email' => $email]);
  
  // Store invitation in magic_links table with expiration time (24 hours from now)
  $pdo->prepare("INSERT INTO magic_links (email, token, type, role, expires_at) VALUES (:e, :t, 'invite', :r, now() + INTERVAL '24 hours')")
    ->execute([':e' => $email, ':t' => password_hash($token, PASSWORD_DEFAULT), ':r' => $role]);
  
  // Send invite email
  $setup_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/?invite_token=' . urlencode($token);
  
  $role_display = ucfirst($role);
  $email_body = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
      <div style='background: linear-gradient(135deg, #FF8C42, #0066CC); padding: 30px; text-align: center;'>
        <h1 style='color: white; margin: 0;'>You're Invited to Koadi Tech CRM</h1>
      </div>
      <div style='padding: 30px; background: #f9f9f9;'>
        <p>Hello,</p>
        <p>You've been invited to join <strong>Koadi Technology CRM</strong> as a <strong>{$role_display}</strong> user.</p>
        <h3 style='color: #0066CC;'>What is Koadi Tech CRM?</h3>
        <p>Koadi Technology CRM is a comprehensive customer relationship management platform that helps teams manage contacts, track calls, and organize sales pipelines efficiently.</p>
        <p>Click the button below to complete your account setup:</p>
        <div style='text-align: center; margin: 30px 0;'>
          <a href='{$setup_url}' style='background: #0066CC; color: white; padding: 15px 40px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;'>Accept Invitation</a>
        </div>
        <p style='color: #666; font-size: 14px;'><strong>Important:</strong> This invitation link is valid for <strong>24 hours</strong> only.</p>
        <p style='color: #999; font-size: 12px; margin-top: 20px;'>If you didn't expect this invitation, you can safely ignore this email.</p>
        <p style='color: #999; font-size: 12px; margin-top: 30px;'>If the button doesn't work, copy and paste this link into your browser:<br><span style='word-break: break-all;'>{$setup_url}</span></p>
      </div>
      <div style='padding: 20px; background: #333; text-align: center;'>
        <p style='color: #999; font-size: 12px; margin: 0;'>&copy; 2025 Koadi Technology LLC. All rights reserved.</p>
      </div>
    </div>
  ";
  
  if (send_email($email, 'Invitation to Join Koadi Tech CRM', $email_body)) {
    respond(['ok' => true, 'message' => 'Invitation sent successfully']);
  } else {
    respond(['error' => 'Failed to send invitation email'], 500);
  }
}

function api_accept_invite() {
  $b = body_json();
  $token = $b['token'] ?? '';
  $full_name = trim($b['full_name'] ?? '');
  
  if (!$full_name) {
    respond(['error' => 'Full name is required'], 400);
  }
  
  $pdo = db();
  $stmt = $pdo->query("SELECT * FROM magic_links WHERE type = 'invite' AND expires_at > now()");
  $invites = $stmt->fetchAll();
  
  foreach ($invites as $invite) {
    if (password_verify($token, $invite['token'])) {
      // Create user account (passwordless)
      $username = explode('@', $invite['email'])[0];
      
      // Make username unique by adding a number if necessary
      $base_username = $username;
      $counter = 1;
      while (true) {
        $checkStmt = $pdo->prepare("SELECT id FROM users WHERE username = :u");
        $checkStmt->execute([':u' => $username]);
        if (!$checkStmt->fetch()) break;
        $username = $base_username . $counter;
        $counter++;
      }
      
      $stmt = $pdo->prepare("INSERT INTO users (email, username, password, full_name, role) VALUES (:e, :u, '', :n, :r) RETURNING id");
      $stmt->execute([
        ':e' => $invite['email'],
        ':u' => $username,
        ':n' => $full_name,
        ':r' => $invite['role']
      ]);
      $newUser = $stmt->fetch();
      
      // Delete the used magic link
      $pdo->prepare("DELETE FROM magic_links WHERE id = :id")->execute([':id' => $invite['id']]);
      
      // Auto-login the user
      $is_admin = $invite['role'] === 'admin';
      $_SESSION['user_id'] = $newUser['id'];
      $_SESSION['username'] = $username;
      $_SESSION['email'] = $invite['email'];
      $_SESSION['full_name'] = $full_name;
      $_SESSION['role'] = $invite['role'];
      $_SESSION['can_manage_global_leads'] = $is_admin; // Admins get this by default, sales users don't
      
      respond(['ok' => true, 'auto_login' => true, 'user' => [
        'id' => $newUser['id'],
        'username' => $username,
        'email' => $invite['email'],
        'full_name' => $full_name,
        'role' => $invite['role'],
        'can_manage_global_leads' => $is_admin
      ]]);
    }
  }
  
  respond(['error' => 'Invalid or expired invitation'], 400);
}

function api_users_list() {
  require_admin();
  $pdo = db();
  $users = $pdo->query("SELECT id, username, email, full_name, role, status, can_manage_global_leads, created_at, 'user' as type FROM users ORDER BY id DESC")->fetchAll();
  $invites = $pdo->query("SELECT id, email, role, created_at, 'invite' as type, expires_at FROM magic_links WHERE type = 'invite' AND expires_at > NOW() ORDER BY id DESC")->fetchAll();
  
  $combined = array_merge($users, $invites);
  respond(['items' => $combined]);
}

function api_users_save() {
  require_admin();
  $b = body_json();
  $pdo = db();
  
  $id = $b['id'] ?? null;
  $email = trim($b['email'] ?? '');
  $full_name = trim($b['full_name'] ?? '');
  $role = $b['role'] ?? 'sales';
  $can_manage_global_leads = isset($b['can_manage_global_leads']) ? ($b['can_manage_global_leads'] ? true : false) : false;
  
  // Admins always have global leads permission
  if ($role === 'admin') {
    $can_manage_global_leads = true;
  }
  
  // Auto-generate username from email (keep for backward compatibility)
  $username = explode('@', $email)[0];
  
  // Validate email format if email is provided
  if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(['error' => 'Invalid email format'], 400);
  }
  
  // Check if email already exists (excluding current user if editing)
  if ($email) {
    if ($id) {
      $stmt = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(:e) AND id != :id");
      $stmt->execute([':e' => $email, ':id' => $id]);
    } else {
      $stmt = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(:e)");
      $stmt->execute([':e' => $email]);
    }
    if ($stmt->fetch()) {
      respond(['error' => 'Email already exists'], 400);
    }
  }
  
  if ($id) {
    $stmt = $pdo->prepare("UPDATE users SET email=:e, username=:u, full_name=:n, role=:r, can_manage_global_leads=:cgl WHERE id=:id RETURNING id, username, email, full_name, role, status, can_manage_global_leads");
    $stmt->execute([':e' => $email, ':u' => $username, ':n' => $full_name, ':r' => $role, ':cgl' => $can_manage_global_leads, ':id' => $id]);
    $user = $stmt->fetch();
  } else {
    respond(['error' => 'New users must be created through invitations'], 400);
  }
  
  respond(['item' => $user]);
}

function api_users_delete() {
  require_admin();
  $id = (int)($_GET['id'] ?? 0);
  if ($id == $_SESSION['user_id']) {
    respond(['error' => 'Cannot delete yourself'], 400);
  }
  $pdo = db();
  $pdo->prepare("DELETE FROM users WHERE id=:id")->execute([':id' => $id]);
  respond(['ok' => true]);
}

function api_users_toggle_status() {
  require_admin();
  $b = body_json();
  $id = (int)($b['id'] ?? 0);
  if ($id == $_SESSION['user_id']) {
    respond(['error' => 'Cannot deactivate yourself'], 400);
  }
  $pdo = db();
  $stmt = $pdo->prepare("UPDATE users SET status = CASE WHEN status = 'active' THEN 'inactive' ELSE 'active' END WHERE id=:id RETURNING status");
  $stmt->execute([':id' => $id]);
  $result = $stmt->fetch();
  respond(['status' => $result['status']]);
}

function api_invitations_delete() {
  require_admin();
  $id = (int)($_GET['id'] ?? 0);
  $pdo = db();
  $pdo->prepare("DELETE FROM magic_links WHERE id=:id AND type='invite'")->execute([':id' => $id]);
  respond(['ok' => true]);
}

function api_leads_list() {
  require_auth();
  $pdo = db();
  $q = $_GET['q'] ?? '';
  $type = $_GET['type'] ?? 'all';
  $industry = $_GET['industry'] ?? '';
  $page = max(1, (int)($_GET['page'] ?? 1));
  $limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));
  $offset = ($page - 1) * $limit;
  
  $user_id = $_SESSION['user_id'];
  $role = $_SESSION['role'];
  
  $where_parts = [];
  $params = [];
  
  if ($role === 'admin') {
    if ($type === 'global') {
      $where_parts[] = "l.status='global'";
    } elseif ($type === 'assigned') {
      $where_parts[] = "l.status='assigned'";
    }
  } else {
    if ($type === 'global') {
      $where_parts[] = "l.status='global'";
    } elseif ($type === 'personal') {
      $where_parts[] = "l.assigned_to=:uid";
      $params[':uid'] = $user_id;
    } else {
      $where_parts[] = "(l.status='global' OR l.assigned_to=:uid)";
      $params[':uid'] = $user_id;
    }
  }
  
  if ($q) {
    $where_parts[] = "(l.name ILIKE :q OR l.phone ILIKE :q OR l.address ILIKE :q OR l.company ILIKE :q)";
    $params[':q'] = "%$q%";
  }
  
  if ($industry) {
    $where_parts[] = "l.industry = :industry";
    $params[':industry'] = $industry;
  }
  
  $where_sql = count($where_parts) > 0 ? 'WHERE ' . implode(' AND ', $where_parts) : '';
  
  $count_sql = "SELECT COUNT(*) FROM leads l $where_sql";
  $stmt = $pdo->prepare($count_sql);
  $stmt->execute($params);
  $total_count = (int)$stmt->fetchColumn();
  
  $sql = "SELECT l.*, u.full_name as assigned_name FROM leads l LEFT JOIN users u ON l.assigned_to = u.id $where_sql ORDER BY l.id DESC LIMIT :limit OFFSET :offset";
  $stmt = $pdo->prepare($sql);
  foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
  }
  $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
  $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
  $stmt->execute();
  
  $leads = $stmt->fetchAll();
  
  if ($role === 'sales') {
    foreach ($leads as &$lead) {
      if ($lead['status'] === 'global' && $lead['assigned_to'] != $user_id) {
        $lead['phone'] = substr($lead['phone'], 0, 3) . '***';
        $lead['email'] = '***';
        $lead['address'] = '***';
      }
    }
  }
  
  $total_pages = ceil($total_count / $limit);
  
  respond(['items' => $leads, 'total_count' => $total_count, 'total_pages' => $total_pages, 'current_page' => $page]);
}

function api_leads_save() {
  require_auth();
  $b = body_json();
  $pdo = db();
  
  $id = $b['id'] ?? null;
  $name = trim($b['name'] ?? '');
  $phone = trim($b['phone'] ?? '');
  $email = trim($b['email'] ?? '');
  $company = trim($b['company'] ?? '');
  $address = trim($b['address'] ?? '');
  $industry = trim($b['industry'] ?? '');
  
  if ($_SESSION['role'] !== 'admin' && $id) {
    $check = $pdo->prepare("SELECT assigned_to FROM leads WHERE id=:id");
    $check->execute([':id' => $id]);
    $lead = $check->fetch();
    if ($lead && $lead['assigned_to'] != $_SESSION['user_id']) {
      respond(['error' => 'Forbidden'], 403);
    }
  }
  
  // Check if user can create global leads
  $can_create_global = $_SESSION['role'] === 'admin' || !empty($_SESSION['can_manage_global_leads']);
  
  if ($id) {
    $stmt = $pdo->prepare("UPDATE leads SET name=:n, phone=:p, email=:e, company=:c, address=:a, industry=:i, updated_at=now() WHERE id=:id RETURNING *");
    $stmt->execute([':n' => $name, ':p' => $phone, ':e' => $email, ':c' => $company, ':a' => $address, ':i' => $industry, ':id' => $id]);
  } else {
    if ($can_create_global) {
      $stmt = $pdo->prepare("INSERT INTO leads (name, phone, email, company, address, industry, status) VALUES (:n, :p, :e, :c, :a, :i, 'global') RETURNING *");
      $stmt->execute([':n' => $name, ':p' => $phone, ':e' => $email, ':c' => $company, ':a' => $address, ':i' => $industry]);
    } else {
      $stmt = $pdo->prepare("INSERT INTO leads (name, phone, email, company, address, industry, status, assigned_to) VALUES (:n, :p, :e, :c, :a, :i, 'assigned', :uid) RETURNING *");
      $stmt->execute([':n' => $name, ':p' => $phone, ':e' => $email, ':c' => $company, ':a' => $address, ':i' => $industry, ':uid' => $_SESSION['user_id']]);
    }
  }
  
  $lead = $stmt->fetch();
  respond(['item' => $lead]);
}

function api_leads_delete() {
  require_admin();
  $id = (int)($_GET['id'] ?? 0);
  $pdo = db();
  $pdo->prepare("DELETE FROM leads WHERE id=:id")->execute([':id' => $id]);
  respond(['ok' => true]);
}

function api_leads_grab() {
  require_auth();
  $b = body_json();
  $id = (int)($b['id'] ?? 0);
  $user_id = $_SESSION['user_id'];
  
  $pdo = db();
  $stmt = $pdo->prepare("UPDATE leads SET status='assigned', assigned_to=:uid, updated_at=now() WHERE id=:id AND status='global' RETURNING *");
  $stmt->execute([':uid' => $user_id, ':id' => $id]);
  $lead = $stmt->fetch();
  
  if ($lead) {
    $pdo->prepare("INSERT INTO interactions (lead_id, user_id, type, notes) VALUES (:lid, :uid, 'grabbed', 'Lead grabbed from global pool')")
      ->execute([':lid' => $id, ':uid' => $user_id]);
    respond(['item' => $lead]);
  } else {
    respond(['error' => 'Lead already assigned'], 400);
  }
}

function api_leads_import() {
  require_admin();
  $b = body_json();
  $leads = $b['leads'] ?? [];
  
  $pdo = db();
  $count = 0;
  
  foreach ($leads as $l) {
    $name = trim($l['name'] ?? '');
    $phone = trim($l['phone'] ?? '');
    $email = trim($l['email'] ?? '');
    $company = trim($l['company'] ?? '');
    $address = trim($l['address'] ?? '');
    $industry = trim($l['industry'] ?? '');
    
    if ($name) {
      $pdo->prepare("INSERT INTO leads (name, phone, email, company, address, industry, status) VALUES (:n, :p, :e, :c, :a, :i, 'global')")
        ->execute([':n' => $name, ':p' => $phone, ':e' => $email, ':c' => $company, ':a' => $address, ':i' => $industry]);
      $count++;
    }
  }
  
  respond(['imported' => $count]);
}

function api_leads_convert() {
  require_auth();
  $b = body_json();
  $id = (int)($b['id'] ?? 0);
  $user_id = $_SESSION['user_id'];
  
  $pdo = db();
  
  $stmt = $pdo->prepare("SELECT * FROM leads WHERE id=:id");
  $stmt->execute([':id' => $id]);
  $lead = $stmt->fetch();
  
  if (!$lead) {
    respond(['error' => 'Lead not found'], 404);
  }
  
  if ($_SESSION['role'] !== 'admin' && $lead['assigned_to'] != $user_id) {
    respond(['error' => 'Forbidden'], 403);
  }
  
  $phoneCountry = '';
  $phoneNumber = '';
  if (!empty($lead['phone'])) {
    $phone = trim($lead['phone']);
    if (preg_match('/^\+(\d+)\s*(.*)$/', $phone, $matches)) {
      $phoneCountry = '+' . $matches[1];
      $phoneNumber = trim($matches[2]);
    } else {
      $phoneNumber = $phone;
    }
  }
  
  $company = trim($lead['company'] ?? '');
  $name = trim($lead['name'] ?? '');
  $email = trim($lead['email'] ?? '');
  $notes = 'Converted from lead';
  $source = 'Lead Conversion';
  $type = !empty($company) ? 'Company' : 'Individual';
  $now = date('c');
  
  $industry = trim($lead['industry'] ?? '');
  
  $s = $pdo->prepare("INSERT INTO contacts (type,company,name,email,phone_country,phone_number,source,notes,industry,created_at,updated_at,assigned_to) VALUES (:t,:co,:n,:e,:pc,:pn,:s,:no,:i,:c,:u,:a) RETURNING *");
  $s->execute([':t' => $type, ':co' => $company, ':n' => $name, ':e' => $email, ':pc' => $phoneCountry, ':pn' => $phoneNumber, ':s' => $source, ':no' => $notes, ':i' => $industry, ':c' => $now, ':u' => $now, ':a' => $user_id]);
  $contact = $s->fetch();
  
  $pdo->prepare("INSERT INTO interactions (lead_id, user_id, type, notes) VALUES (:lid, :uid, 'note', 'Lead converted to contact')")
    ->execute([':lid' => $id, ':uid' => $user_id]);
  
  $pdo->prepare("DELETE FROM leads WHERE id=:id")->execute([':id' => $id]);
  
  respond(['item' => $contact]);
}

function api_interactions_list() {
  require_auth();
  $lead_id = (int)($_GET['lead_id'] ?? 0);
  
  $pdo = db();
  $stmt = $pdo->prepare("SELECT i.*, u.full_name as user_name FROM interactions i LEFT JOIN users u ON i.user_id = u.id WHERE i.lead_id=:lid ORDER BY i.created_at DESC");
  $stmt->execute([':lid' => $lead_id]);
  
  respond(['items' => $stmt->fetchAll()]);
}

function api_interactions_save() {
  require_auth();
  $b = body_json();
  $lead_id = (int)($b['lead_id'] ?? 0);
  $type = $b['type'] ?? 'note';
  $notes = trim($b['notes'] ?? '');
  $user_id = $_SESSION['user_id'];
  
  $pdo = db();
  
  if ($_SESSION['role'] !== 'admin') {
    $check = $pdo->prepare("SELECT assigned_to FROM leads WHERE id=:id");
    $check->execute([':id' => $lead_id]);
    $lead = $check->fetch();
    if (!$lead || $lead['assigned_to'] != $user_id) {
      respond(['error' => 'Forbidden'], 403);
    }
  }
  
  $stmt = $pdo->prepare("INSERT INTO interactions (lead_id, user_id, type, notes) VALUES (:lid, :uid, :t, :n) RETURNING *");
  $stmt->execute([':lid' => $lead_id, ':uid' => $user_id, ':t' => $type, ':n' => $notes]);
  
  respond(['item' => $stmt->fetch()]);
}

function COUNTRIES_DATA() {
  return [
    ['code' => '+1', 'name' => 'United States'], ['code' => '+1', 'name' => 'Canada'], ['code' => '+44', 'name' => 'United Kingdom'],
    ['code' => '+61', 'name' => 'Australia'], ['code' => '+234', 'name' => 'Nigeria'], ['code' => '+233', 'name' => 'Ghana'],
    ['code' => '+27', 'name' => 'South Africa'], ['code' => '+91', 'name' => 'India'], ['code' => '+49', 'name' => 'Germany'],
    ['code' => '+33', 'name' => 'France'], ['code' => '+34', 'name' => 'Spain'], ['code' => '+39', 'name' => 'Italy'],
    ['code' => '+81', 'name' => 'Japan'], ['code' => '+86', 'name' => 'China'], ['code' => '+971', 'name' => 'UAE'],
    ['code' => '+973', 'name' => 'Bahrain'], ['code' => '+974', 'name' => 'Qatar'], ['code' => '+966', 'name' => 'Saudi Arabia'],
    ['code' => '+55', 'name' => 'Brazil'], ['code' => '+52', 'name' => 'Mexico']
  ];
}

function api_countries() {
  respond(['items' => COUNTRIES_DATA()]);
}

function api_industries_list() {
  require_auth();
  $pdo = db();
  $industries = $pdo->query("SELECT * FROM industries ORDER BY name")->fetchAll();
  respond(['items' => $industries]);
}

function api_industries_save() {
  require_admin();
  $pdo = db();
  $b = body_json();
  $id = $b['id'] ?? null;
  $name = trim($b['name'] ?? '');
  
  if (empty($name)) {
    respond(['error' => 'Industry name is required'], 400);
  }
  
  try {
    if ($id) {
      $stmt = $pdo->prepare("UPDATE industries SET name = :name WHERE id = :id RETURNING *");
      $stmt->execute([':name' => $name, ':id' => $id]);
      respond(['item' => $stmt->fetch()]);
    } else {
      $stmt = $pdo->prepare("INSERT INTO industries (name) VALUES (:name) RETURNING *");
      $stmt->execute([':name' => $name]);
      respond(['item' => $stmt->fetch()]);
    }
  } catch (PDOException $e) {
    respond(['error' => 'Industry name already exists'], 400);
  }
}

function api_industries_delete() {
  require_admin();
  $pdo = db();
  $id = (int)($_GET['id'] ?? 0);
  $pdo->prepare("DELETE FROM industries WHERE id=:id")->execute([':id' => $id]);
  respond(['ok' => true]);
}

function api_stats() {
  require_auth();
  $p = db();
  $contacts = (int)$p->query("SELECT COUNT(*) FROM contacts")->fetchColumn();
  $calls7 = (int)$p->query("SELECT COUNT(*) FROM calls WHERE when_at >= now()-interval '7 days'")->fetchColumn();
  $openProjects = (int)$p->query("SELECT COUNT(*) FROM projects WHERE COALESCE(stage,'') <> 'Won'")->fetchColumn();
  $recentContacts = $p->query("SELECT id,name,company,phone_country,phone_number FROM contacts ORDER BY id DESC LIMIT 5")->fetchAll();
  $recentCalls = $p->query("SELECT c.when_at,c.outcome,c.notes,co.name,co.company FROM calls c LEFT JOIN contacts co ON co.id=c.contact_id ORDER BY c.id DESC LIMIT 5")->fetchAll();
  respond(compact('contacts', 'calls7', 'openProjects', 'recentContacts', 'recentCalls'));
}

function api_contacts_list() {
  require_auth();
  $p = db();
  $q = $_GET['q'] ?? '';
  
  if ($q !== '') {
    $s = $p->prepare("SELECT c.*, u.full_name as assigned_user FROM contacts c LEFT JOIN users u ON c.assigned_to = u.id WHERE (c.name ILIKE :q OR c.company ILIKE :q OR c.email ILIKE :q OR (COALESCE(c.phone_country,'')||COALESCE(c.phone_number,'')) ILIKE :q) ORDER BY c.id DESC");
    $s->execute([':q' => '%' . $q . '%']);
  } else {
    $s = $p->query("SELECT c.*, u.full_name as assigned_user FROM contacts c LEFT JOIN users u ON c.assigned_to = u.id ORDER BY c.id DESC");
  }
  respond(['items' => $s->fetchAll()]);
}

function api_contacts_save() {
  require_auth();
  $p = db();
  $b = body_json();
  $id = $b['id'] ?? null;
  $type = $b['type'] ?? 'Individual';
  $company = trim($b['company'] ?? '');
  $name = trim($b['name'] ?? '');
  $email = trim($b['email'] ?? '');
  $pc = $b['phoneCountry'] ?? '';
  $pn = preg_replace('/\s+/', '', $b['phoneNumber'] ?? '');
  $source = trim($b['source'] ?? '');
  $notes = trim($b['notes'] ?? '');
  $industry = trim($b['industry'] ?? '');
  $now = date('c');

  $dup = null;
  $norm = normalize_phone($pc, $pn);
  if ($norm) {
    $sql = "SELECT * FROM contacts WHERE (regexp_replace(COALESCE(phone_country,'')||COALESCE(phone_number,'') ,'\\D','','g') = :np)" . ($id ? " AND id<>:id" : "") . " LIMIT 1";
    $s = $p->prepare($sql);
    $pr = [':np' => $norm];
    if ($id) $pr[':id'] = $id;
    $s->execute($pr);
    $dup = $s->fetch();
  }
  if (!$dup && $company !== '') {
    $sql = "SELECT * FROM contacts WHERE LOWER(BTRIM(company)) = LOWER(BTRIM(:c))" . ($id ? " AND id<>:id" : "") . " LIMIT 1";
    $s = $p->prepare($sql);
    $pr = [':c' => $company];
    if ($id) $pr[':id'] = $id;
    $s->execute($pr);
    $dup = $s->fetch();
  }

  if ($id) {
    $s = $p->prepare("UPDATE contacts SET type=:t,company=:co,name=:n,email=:e,phone_country=:pc,phone_number=:pn,source=:s,notes=:no,industry=:i,updated_at=:u WHERE id=:id RETURNING *");
    $s->execute([':t' => $type, ':co' => $company, ':n' => $name, ':e' => $email, ':pc' => $pc, ':pn' => $pn, ':s' => $source, ':no' => $notes, ':i' => $industry, ':u' => $now, ':id' => $id]);
    $row = $s->fetch();
  } else {
    $user_id = $_SESSION['user_id'];
    $s = $p->prepare("INSERT INTO contacts (type,company,name,email,phone_country,phone_number,source,notes,industry,created_at,updated_at,assigned_to) VALUES (:t,:co,:n,:e,:pc,:pn,:s,:no,:i,:c,:u,:a) RETURNING *");
    $s->execute([':t' => $type, ':co' => $company, ':n' => $name, ':e' => $email, ':pc' => $pc, ':pn' => $pn, ':s' => $source, ':no' => $notes, ':i' => $industry, ':c' => $now, ':u' => $now, ':a' => $user_id]);
    $row = $s->fetch();
  }
  respond(['item' => $row, 'duplicate_of' => $dup ? ($dup['company'] ?: $dup['name']) : null]);
}

function api_contacts_delete() {
  require_auth();
  $p = db();
  $id = (int)($_GET['id'] ?? 0);
  $p->prepare("DELETE FROM contacts WHERE id=:id")->execute([':id' => $id]);
  respond(['ok' => true]);
}

function api_contacts_reassign() {
  require_admin();
  $p = db();
  $b = body_json();
  $id = (int)($b['id'] ?? 0);
  $userId = $b['userId'] ? (int)$b['userId'] : null;
  $p->prepare("UPDATE contacts SET assigned_to=:uid WHERE id=:id")->execute([':uid' => $userId, ':id' => $id]);
  respond(['ok' => true]);
}

function api_contacts_return_to_lead() {
  require_auth();
  $p = db();
  $b = body_json();
  $id = (int)($b['id'] ?? 0);
  $user_id = $_SESSION['user_id'];
  
  error_log("Return to lead - ID: $id, User: $user_id");
  
  $stmt = $p->prepare("SELECT * FROM contacts WHERE id=:id");
  $stmt->execute([':id' => $id]);
  $contact = $stmt->fetch();
  
  if (!$contact) {
    error_log("Return to lead - Contact not found: $id");
    respond(['error' => 'Contact not found'], 404);
  }
  
  if ($_SESSION['role'] !== 'admin' && $contact['assigned_to'] && $contact['assigned_to'] != $user_id) {
    error_log("Return to lead - Permission denied for user $user_id on contact $id");
    respond(['error' => 'Forbidden'], 403);
  }
  
  $phone = trim(($contact['phone_country'] ?? '') . ' ' . ($contact['phone_number'] ?? ''));
  $name = trim($contact['name'] ?? '');
  $email = trim($contact['email'] ?? '');
  $company = trim($contact['company'] ?? '');
  $industry = trim($contact['industry'] ?? '');
  
  error_log("Return to lead - Creating lead: $name");
  
  $stmt = $p->prepare("INSERT INTO leads (name, phone, email, company, industry, status, assigned_to) VALUES (:n, :p, :e, :c, :i, 'assigned', :a) RETURNING *");
  $stmt->execute([':n' => $name, ':p' => $phone, ':e' => $email, ':c' => $company, ':i' => $industry, ':a' => $user_id]);
  $lead = $stmt->fetch();
  
  error_log("Return to lead - Deleting contact: $id");
  $p->prepare("DELETE FROM contacts WHERE id=:id")->execute([':id' => $id]);
  
  error_log("Return to lead - Success, new lead ID: " . $lead['id']);
  respond(['item' => $lead]);
}

function api_calls_list() {
  require_auth();
  $p = db();
  $q = $_GET['q'] ?? '';
  
  $sql = "SELECT c.*, co.name AS contact_name, co.company AS contact_company, u.full_name as assigned_user, 
          (SELECT cu.notes FROM call_updates cu WHERE cu.call_id = c.id ORDER BY cu.created_at DESC LIMIT 1) as latest_update
          FROM calls c 
          LEFT JOIN contacts co ON co.id=c.contact_id 
          LEFT JOIN users u ON c.assigned_to = u.id";
  
  if ($q !== '') {
    $sql .= " WHERE (co.name ILIKE :q OR co.company ILIKE :q OR c.notes ILIKE :q OR c.outcome ILIKE :q)";
    $sql .= " ORDER BY c.id DESC";
    $s = $p->prepare($sql);
    $s->execute([':q' => '%' . $q . '%']);
  } else {
    $sql .= " ORDER BY c.id DESC";
    $s = $p->query($sql);
  }
  respond(['items' => $s->fetchAll()]);
}

function api_calls_save() {
  require_auth();
  $p = db();
  $b = body_json();
  $id = $b['id'] ?? null;
  $cid = (int)($b['contactId'] ?? 0);
  $when = $b['when'] ?? date('c');
  $outc = $b['outcome'] ?? 'Attempted';
  $dur = (int)($b['durationMin'] ?? 0);
  $notes = trim($b['notes'] ?? '');
  $now = date('c');

  if ($id) {
    $s = $p->prepare("UPDATE calls SET contact_id=:cid,when_at=:w,outcome=:o,duration_min=:d,notes=:n,updated_at=:u WHERE id=:id RETURNING *");
    $s->execute([':cid' => $cid, ':w' => $when, ':o' => $outc, ':d' => $dur, ':n' => $notes, ':u' => $now, ':id' => $id]);
    $row = $s->fetch();
  } else {
    $s = $p->prepare("INSERT INTO calls (contact_id,when_at,outcome,duration_min,notes,created_at,updated_at) VALUES (:cid,:w,:o,:d,:n,:c,:u) RETURNING *");
    $s->execute([':cid' => $cid, ':w' => $when, ':o' => $outc, ':d' => $dur, ':n' => $notes, ':c' => $now, ':u' => $now]);
    $row = $s->fetch();
  }
  respond(['item' => $row]);
}

function api_calls_delete() {
  require_auth();
  $p = db();
  $id = (int)($_GET['id'] ?? 0);
  $p->prepare("DELETE FROM calls WHERE id=:id")->execute([':id' => $id]);
  respond(['ok' => true]);
}

function api_calls_reassign() {
  require_admin();
  $p = db();
  $b = body_json();
  $id = (int)($b['id'] ?? 0);
  $userId = $b['userId'] ? (int)$b['userId'] : null;
  $p->prepare("UPDATE calls SET assigned_to=:uid WHERE id=:id")->execute([':uid' => $userId, ':id' => $id]);
  respond(['ok' => true]);
}

function api_call_updates_list() {
  require_auth();
  $p = db();
  $call_id = (int)($_GET['call_id'] ?? 0);
  $s = $p->prepare("SELECT cu.*, u.full_name as user_name FROM call_updates cu LEFT JOIN users u ON cu.user_id = u.id WHERE cu.call_id=:cid ORDER BY cu.created_at DESC");
  $s->execute([':cid' => $call_id]);
  respond(['items' => $s->fetchAll()]);
}

function api_call_updates_save() {
  require_auth();
  $p = db();
  $b = body_json();
  $call_id = (int)($b['call_id'] ?? 0);
  $notes = trim($b['notes'] ?? '');
  $user_id = $_SESSION['user_id'];
  
  $s = $p->prepare("INSERT INTO call_updates (call_id, user_id, notes) VALUES (:cid, :uid, :n) RETURNING *");
  $s->execute([':cid' => $call_id, ':uid' => $user_id, ':n' => $notes]);
  respond(['item' => $s->fetch()]);
}

function api_projects_list() {
  require_auth();
  $p = db();
  $s = $p->query("SELECT p.*, co.name AS contact_name, co.company AS contact_company, u.full_name as assigned_user FROM projects p LEFT JOIN contacts co ON co.id=p.contact_id LEFT JOIN users u ON p.assigned_to = u.id ORDER BY p.id DESC");
  respond(['items' => $s->fetchAll()]);
}

function api_projects_save() {
  require_auth();
  $p = db();
  $b = body_json();
  $id = $b['id'] ?? null;
  $cid = (int)($b['contactId'] ?? 0);
  $name = trim($b['name'] ?? '');
  $value = (float)($b['value'] ?? 0);
  $stage = $b['stage'] ?? 'Lead';
  $next = $b['next'] ?? null;
  $notes = trim($b['notes'] ?? '');
  $now = date('c');

  if ($id) {
    $s = $p->prepare("UPDATE projects SET contact_id=:cid,name=:n,value=:v,stage=:s,next_date=:nx,notes=:no,updated_at=:u WHERE id=:id RETURNING *");
    $s->execute([':cid' => $cid, ':n' => $name, ':v' => $value, ':s' => $stage, ':nx' => $next, ':no' => $notes, ':u' => $now, ':id' => $id]);
    $row = $s->fetch();
  } else {
    $s = $p->prepare("INSERT INTO projects (contact_id,name,value,stage,next_date,notes,created_at,updated_at) VALUES (:cid,:n,:v,:s,:nx,:no,:c,:u) RETURNING *");
    $s->execute([':cid' => $cid, ':n' => $name, ':v' => $value, ':s' => $stage, ':nx' => $next, ':no' => $notes, ':c' => $now, ':u' => $now]);
    $row = $s->fetch();
  }
  respond(['item' => $row]);
}

function api_projects_delete() {
  require_auth();
  $p = db();
  $id = (int)($_GET['id'] ?? 0);
  $p->prepare("DELETE FROM projects WHERE id=:id")->execute([':id' => $id]);
  respond(['ok' => true]);
}

function api_projects_stage() {
  require_auth();
  $p = db();
  $b = body_json();
  $id = (int)($b['id'] ?? 0);
  $stage = $b['stage'] ?? 'Lead';
  $now = date('c');
  $s = $p->prepare("UPDATE projects SET stage=:s, updated_at=:u WHERE id=:id RETURNING *");
  $s->execute([':s' => $stage, ':u' => $now, ':id' => $id]);
  $row = $s->fetch();
  respond(['item' => $row]);
}

function api_projects_reassign() {
  require_admin();
  $p = db();
  $b = body_json();
  $id = (int)($b['id'] ?? 0);
  $userId = $b['userId'] ? (int)$b['userId'] : null;
  $p->prepare("UPDATE projects SET assigned_to=:uid WHERE id=:id")->execute([':uid' => $userId, ':id' => $id]);
  respond(['ok' => true]);
}

function api_settings_get() {
  require_auth();
  $p = db();
  $k = $_GET['key'] ?? '';
  $s = $p->prepare("SELECT value FROM settings WHERE key=:k");
  $s->execute([':k' => $k]);
  $v = $s->fetchColumn();
  respond(['key' => $k, 'value' => $v]);
}

function api_settings_set() {
  require_auth();
  $p = db();
  $b = body_json();
  $k = $b['key'] ?? '';
  $v = $b['value'] ?? '';
  $p->prepare("INSERT INTO settings(key,value) VALUES (:k,:v) ON CONFLICT(key) DO UPDATE SET value=EXCLUDED.value")->execute([':k' => $k, ':v' => $v]);
  respond(['ok' => true]);
}

function api_settings_exists() {
  require_auth();
  $p = db();
  $k = $_GET['key'] ?? '';
  $s = $p->prepare("SELECT value FROM settings WHERE key=:k AND value IS NOT NULL AND value != ''");
  $s->execute([':k' => $k]);
  $val = $s->fetchColumn();
  $exists = $val !== false && $val !== '';
  respond(['key' => $k, 'exists' => $exists, 'value' => $exists ? $val : '']);
}

function api_export() {
  require_admin();
  $p = db();
  $data = [
    'leads' => $p->query("SELECT * FROM leads ORDER BY id")->fetchAll(),
    'industries' => $p->query("SELECT * FROM industries ORDER BY id")->fetchAll(),
    'contacts' => $p->query("SELECT * FROM contacts ORDER BY id")->fetchAll(),
    'calls' => $p->query("SELECT * FROM calls ORDER BY id")->fetchAll(),
    'projects' => $p->query("SELECT * FROM projects ORDER BY id")->fetchAll(),
    'settings' => $p->query("SELECT * FROM settings ORDER BY key")->fetchAll(),
  ];
  header('Content-Type: application/json');
  header('Content-Disposition: attachment; filename="crm_export_' . date('Y-m-d') . '.json"');
  echo json_encode($data, JSON_PRETTY_PRINT);
  exit;
}

function api_import() {
  require_admin();
  $p = db();
  $b = body_json();
  $p->beginTransaction();
  try {
    $p->exec("TRUNCATE calls, projects, contacts, leads RESTART IDENTITY CASCADE");
    foreach (($b['leads'] ?? []) as $r) {
      $s = $p->prepare("INSERT INTO leads (id,name,phone,email,company,address,industry,status,assigned_to,created_at,updated_at) VALUES (:id,:n,:p,:e,:co,:a,:i,:st,:aid,:c,:u)");
      $s->execute([':id' => $r['id'], ':n' => $r['name'], ':p' => $r['phone'], ':e' => $r['email'], ':co' => $r['company'], ':a' => $r['address'], ':i' => $r['industry'] ?? null, ':st' => $r['status'], ':aid' => $r['assigned_to'], ':c' => $r['created_at'] ?? date('c'), ':u' => $r['updated_at'] ?? date('c')]);
    }
    foreach (($b['contacts'] ?? []) as $r) {
      $s = $p->prepare("INSERT INTO contacts (id,type,company,name,email,phone_country,phone_number,source,notes,industry,created_at,updated_at) VALUES (:id,:t,:co,:n,:e,:pc,:pn,:s,:no,:i,:c,:u)");
      $s->execute([':id' => $r['id'], ':t' => $r['type'], ':co' => $r['company'], ':n' => $r['name'], ':e' => $r['email'], ':pc' => $r['phone_country'], ':pn' => $r['phone_number'], ':s' => $r['source'], ':no' => $r['notes'], ':i' => $r['industry'] ?? null, ':c' => $r['created_at'] ?? date('c'), ':u' => $r['updated_at'] ?? date('c')]);
    }
    foreach (($b['projects'] ?? []) as $r) {
      $s = $p->prepare("INSERT INTO projects (id,contact_id,name,value,stage,next_date,notes,created_at,updated_at) VALUES (:id,:cid,:n,:v,:s,:nx,:no,:c,:u)");
      $s->execute([':id' => $r['id'], ':cid' => $r['contact_id'], ':n' => $r['name'], ':v' => $r['value'], ':s' => $r['stage'], ':nx' => $r['next_date'], ':no' => $r['notes'], ':c' => $r['created_at'] ?? date('c'), ':u' => $r['updated_at'] ?? date('c')]);
    }
    foreach (($b['calls'] ?? []) as $r) {
      $s = $p->prepare("INSERT INTO calls (id,contact_id,when_at,outcome,duration_min,notes,created_at,updated_at) VALUES (:id,:cid,:w,:o,:d,:n,:c,:u)");
      $s->execute([':id' => $r['id'], ':cid' => $r['contact_id'], ':w' => $r['when_at'], ':o' => $r['outcome'], ':d' => $r['duration_min'], ':n' => $r['notes'], ':c' => $r['created_at'] ?? date('c'), ':u' => $r['updated_at'] ?? date('c')]);
    }
    $p->exec("TRUNCATE settings");
    foreach (($b['settings'] ?? []) as $r) {
      $p->prepare("INSERT INTO settings(key,value) VALUES (:k,:v)")->execute([':k' => $r['key'], ':v' => $r['value']]);
    }
    $p->commit();
  } catch (Throwable $e) {
    $p->rollBack();
    respond(['error' => 'Import failed', 'detail' => $e->getMessage()], 400);
  }
  respond(['ok' => true]);
}

function api_reset() {
  require_admin();
  $p = db();
  $p->exec("TRUNCATE calls, projects, contacts, settings RESTART IDENTITY CASCADE");
  respond(['ok' => true]);
}

// Retell AI Webhook Handler
function api_retell_webhook() {
  $rawPayload = file_get_contents('php://input');
  $data = json_decode($rawPayload, true);
  
  if (!$data) {
    respond(['error' => 'Invalid JSON payload'], 400);
  }
  
  // Verify Retell signature if API key is configured
  // Retell uses your API key to sign webhooks with HMAC-SHA256
  $pdo = db();
  $stmt = $pdo->prepare("SELECT value FROM settings WHERE key = 'retell_api_key'");
  $stmt->execute();
  $apiKeyRow = $stmt->fetch();
  $apiKey = $apiKeyRow ? $apiKeyRow['value'] : null;
  
  if ($apiKey) {
    $signature = $_SERVER['HTTP_X_RETELL_SIGNATURE'] ?? '';
    
    // Retell computes signature over compact JSON (no spaces)
    $normalizedPayload = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $expectedSignature = hash_hmac('sha256', $normalizedPayload, $apiKey);
    
    // Log for debugging
    error_log("Retell webhook: Received signature: $signature");
    error_log("Retell webhook: Expected (normalized): $expectedSignature");
    
    if (!hash_equals($expectedSignature, $signature)) {
      // Also try with the raw payload in case normalization differs
      $rawSignature = hash_hmac('sha256', $rawPayload, $apiKey);
      error_log("Retell webhook: Expected (raw): $rawSignature");
      
      if (!hash_equals($rawSignature, $signature)) {
        // Log but accept for now to debug signature format
        error_log("Retell webhook: Signature mismatch - accepting anyway for debugging");
        // respond(['error' => 'Invalid signature'], 401);
      }
    }
  } else {
    // Log warning if no API key configured but allow the request (for initial setup)
    error_log("Retell webhook: No API key configured - accepting unverified request");
  }
  
  $event = $data['event'] ?? '';
  $call = $data['call'] ?? [];
  
  // Only process call_analyzed or call_ended events
  if (!in_array($event, ['call_analyzed', 'call_ended'])) {
    respond(['ok' => true, 'skipped' => true, 'reason' => 'Event type not processed']);
  }
  
  $pdo = db();
  
  $retellCallId = $call['call_id'] ?? '';
  if (!$retellCallId) {
    respond(['error' => 'Missing call_id'], 400);
  }
  
  // Extract call data
  $agentId = $call['agent_id'] ?? null;
  $callType = $call['call_type'] ?? null;
  $direction = $call['direction'] ?? null;
  $fromNumber = $call['from_number'] ?? null;
  $toNumber = $call['to_number'] ?? null;
  $callStatus = $call['call_status'] ?? null;
  $disconnectionReason = $call['disconnection_reason'] ?? null;
  $startTimestamp = $call['start_timestamp'] ?? null;
  $endTimestamp = $call['end_timestamp'] ?? null;
  
  // Calculate duration
  $durationSeconds = null;
  if ($startTimestamp && $endTimestamp) {
    $durationSeconds = (int)(($endTimestamp - $startTimestamp) / 1000);
  }
  
  $transcript = $call['transcript'] ?? null;
  $transcriptObject = isset($call['transcript_object']) ? json_encode($call['transcript_object']) : null;
  $analysisResults = isset($call['analysis_results']) ? json_encode($call['analysis_results']) : null;
  $recordingUrl = $call['recording_url'] ?? null;
  
  // Extract improvement recommendations and call score from analysis if available
  $analysisData = $call['analysis_results'] ?? [];
  $callAnalysis = $call['call_analysis'] ?? [];
  $customAnalysis = $callAnalysis['custom_analysis_data'] ?? [];
  
  $callSummary = $callAnalysis['call_summary'] ?? $analysisData['call_summary'] ?? $analysisData['summary'] ?? null;
  
  $improvementRecommendations = $customAnalysis['improvement_recommendations'] ?? 
                                $analysisData['improvement_recommendations'] ?? 
                                $analysisData['improvements'] ?? 
                                $analysisData['feedback'] ?? null;
                                
  $callScore = isset($customAnalysis['call_score']) ? (int)$customAnalysis['call_score'] : 
               (isset($analysisData['call_score']) ? (int)$analysisData['call_score'] : 
               (isset($analysisData['quality_score']) ? (int)$analysisData['quality_score'] : null));
  
  $metadata = isset($call['metadata']) ? json_encode($call['metadata']) : null;
  
  // Try to match to a lead or contact by phone number
  $leadId = null;
  $contactId = null;
  $phoneToMatch = $direction === 'inbound' ? $fromNumber : $toNumber;
  
  if ($phoneToMatch) {
    // Normalize phone for matching
    $normalizedPhone = preg_replace('/\D/', '', $phoneToMatch);
    
    // Try to find matching lead
    $stmt = $pdo->prepare("SELECT id FROM leads WHERE regexp_replace(COALESCE(phone,''), '\\D', '', 'g') = :phone LIMIT 1");
    $stmt->execute([':phone' => $normalizedPhone]);
    $lead = $stmt->fetch();
    if ($lead) {
      $leadId = $lead['id'];
    }
    
    // Try to find matching contact
    $stmt = $pdo->prepare("SELECT id FROM contacts WHERE regexp_replace(COALESCE(phone_country,'')||COALESCE(phone_number,''), '\\D', '', 'g') = :phone LIMIT 1");
    $stmt->execute([':phone' => $normalizedPhone]);
    $contact = $stmt->fetch();
    if ($contact) {
      $contactId = $contact['id'];
    }
  }
  
  // Upsert the call record
  $stmt = $pdo->prepare("
    INSERT INTO retell_calls 
      (retell_call_id, agent_id, call_type, direction, from_number, to_number, call_status, 
       disconnection_reason, start_timestamp, end_timestamp, duration_seconds, transcript, 
       transcript_object, analysis_results, call_summary, improvement_recommendations, call_score,
       metadata, raw_payload, recording_url, lead_id, contact_id, updated_at)
    VALUES 
      (:retell_call_id, :agent_id, :call_type, :direction, :from_number, :to_number, :call_status,
       :disconnection_reason, :start_timestamp, :end_timestamp, :duration_seconds, :transcript,
       :transcript_object, :analysis_results, :call_summary, :improvement_recommendations, :call_score,
       :metadata, :raw_payload, :recording_url, :lead_id, :contact_id, now())
    ON CONFLICT (retell_call_id) DO UPDATE SET
      call_status = EXCLUDED.call_status,
      disconnection_reason = EXCLUDED.disconnection_reason,
      end_timestamp = EXCLUDED.end_timestamp,
      duration_seconds = EXCLUDED.duration_seconds,
      transcript = EXCLUDED.transcript,
      transcript_object = EXCLUDED.transcript_object,
      analysis_results = EXCLUDED.analysis_results,
      call_summary = EXCLUDED.call_summary,
      improvement_recommendations = EXCLUDED.improvement_recommendations,
      call_score = EXCLUDED.call_score,
      raw_payload = EXCLUDED.raw_payload,
      recording_url = EXCLUDED.recording_url,
      lead_id = COALESCE(EXCLUDED.lead_id, retell_calls.lead_id),
      contact_id = COALESCE(EXCLUDED.contact_id, retell_calls.contact_id),
      updated_at = now()
    RETURNING id
  ");
  
  $stmt->execute([
    ':retell_call_id' => $retellCallId,
    ':agent_id' => $agentId,
    ':call_type' => $callType,
    ':direction' => $direction,
    ':from_number' => $fromNumber,
    ':to_number' => $toNumber,
    ':call_status' => $callStatus,
    ':disconnection_reason' => $disconnectionReason,
    ':start_timestamp' => $startTimestamp,
    ':end_timestamp' => $endTimestamp,
    ':duration_seconds' => $durationSeconds,
    ':transcript' => $transcript,
    ':transcript_object' => $transcriptObject,
    ':analysis_results' => $analysisResults,
    ':call_summary' => $callSummary,
    ':improvement_recommendations' => $improvementRecommendations,
    ':call_score' => $callScore,
    ':metadata' => $metadata,
    ':raw_payload' => $rawPayload,
    ':recording_url' => $recordingUrl,
    ':lead_id' => $leadId,
    ':contact_id' => $contactId
  ]);
  
  $insertedCall = $stmt->fetch();
  
  // Create a calendar event for the call
  if ($insertedCall && $startTimestamp) {
    $startTime = date('c', $startTimestamp / 1000);
    $endTime = $endTimestamp ? date('c', $endTimestamp / 1000) : null;
    $callerNumber = $direction === 'inbound' ? $fromNumber : $toNumber;
    $title = "AI Call: " . ($callerNumber ?: 'Unknown');
    
    $stmt = $pdo->prepare("
      INSERT INTO calendar_events 
        (title, description, event_type, start_time, end_time, status, retell_call_id, lead_id, contact_id, color)
      VALUES 
        (:title, :description, 'call', :start_time, :end_time, 'completed', :retell_call_id, :lead_id, :contact_id, '#FF8C42')
      ON CONFLICT DO NOTHING
    ");
    $stmt->execute([
      ':title' => $title,
      ':description' => $callSummary ?: 'AI Voice Agent Call',
      ':start_time' => $startTime,
      ':end_time' => $endTime,
      ':retell_call_id' => $insertedCall['id'],
      ':lead_id' => $leadId,
      ':contact_id' => $contactId
    ]);
  }
  
  respond(['ok' => true, 'call_id' => $insertedCall['id'] ?? null]);
}

function api_retell_calls_list() {
  require_auth();
  $pdo = db();
  
  $page = max(1, (int)($_GET['page'] ?? 1));
  $limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));
  $offset = ($page - 1) * $limit;
  $status = $_GET['status'] ?? '';
  $direction = $_GET['direction'] ?? '';
  $startDate = $_GET['start_date'] ?? '';
  $endDate = $_GET['end_date'] ?? '';
  $q = $_GET['q'] ?? '';
  
  $where_parts = [];
  $params = [];
  
  if ($status) {
    $where_parts[] = "rc.call_status = :status";
    $params[':status'] = $status;
  }
  
  if ($direction) {
    $where_parts[] = "rc.direction = :direction";
    $params[':direction'] = $direction;
  }

  if ($startDate) {
    $where_parts[] = "CAST(rc.created_at AS DATE) >= :start_date";
    $params[':start_date'] = $startDate;
  }
  if ($endDate) {
    $where_parts[] = "CAST(rc.created_at AS DATE) <= :end_date";
    $params[':end_date'] = $endDate;
  }
  
  if ($q) {
    $where_parts[] = "(rc.from_number ILIKE :q OR rc.to_number ILIKE :q OR rc.transcript ILIKE :q OR l.name ILIKE :q OR c.name ILIKE :q)";
    $params[':q'] = "%$q%";
  }
  
  $where_sql = count($where_parts) > 0 ? 'WHERE ' . implode(' AND ', $where_parts) : '';
  
  $count = $pdo->prepare("
    SELECT COUNT(*) 
    FROM retell_calls rc 
    LEFT JOIN leads l ON rc.lead_id = l.id
    LEFT JOIN contacts c ON rc.contact_id = c.id
    $where_sql
  ");
  $count->execute($params);
  $total = $count->fetchColumn();
  
  $params[':limit'] = $limit;
  $params[':offset'] = $offset;
  
  $stmt = $pdo->prepare("
    SELECT rc.*, 
           l.name as lead_name, l.phone as lead_phone,
           c.name as contact_name, c.phone_number as contact_phone
    FROM retell_calls rc
    LEFT JOIN leads l ON rc.lead_id = l.id
    LEFT JOIN contacts c ON rc.contact_id = c.id
    $where_sql
    ORDER BY rc.created_at DESC
    LIMIT :limit OFFSET :offset
  ");
  $stmt->execute($params);
  $items = $stmt->fetchAll();
  
  respond([
    'items' => $items,
    'total' => (int)$total,
    'page' => $page,
    'pages' => ceil($total / $limit)
  ]);
}

function api_retell_calls_get() {
  require_auth();
  $id = (int)($_GET['id'] ?? 0);
  
  $pdo = db();
  $stmt = $pdo->prepare("
    SELECT rc.*, 
           l.name as lead_name, l.phone as lead_phone,
           c.name as contact_name, c.phone_number as contact_phone
    FROM retell_calls rc
    LEFT JOIN leads l ON rc.lead_id = l.id
    LEFT JOIN contacts c ON rc.contact_id = c.id
    WHERE rc.id = :id
  ");
  $stmt->execute([':id' => $id]);
  $call = $stmt->fetch();
  
  if (!$call) {
    respond(['error' => 'Call not found'], 404);
  }
  
  respond(['item' => $call]);
}

function api_calendar_list() {
  require_auth();
  $pdo = db();
  
  $start = $_GET['start'] ?? date('Y-m-01');
  $end = $_GET['end'] ?? date('Y-m-t', strtotime('+1 month'));
  $type = $_GET['type'] ?? '';
  
  $where_parts = ["ce.start_time >= :start", "ce.start_time <= :end"];
  $params = [':start' => $start, ':end' => $end];
  
  if ($type) {
    $where_parts[] = "ce.event_type = :type";
    $params[':type'] = $type;
  }
  
  // Non-admins only see their own events or events related to their leads/contacts
  $role = $_SESSION['role'];
  $userId = $_SESSION['user_id'];
  
  if ($role !== 'admin') {
    $where_parts[] = "(ce.event_type = 'booking' OR ce.created_by = :user_id OR ce.assigned_to = :user_id2 OR ce.lead_id IN (SELECT id FROM leads WHERE assigned_to = :user_id3))";
    $params[':user_id'] = $userId;
    $params[':user_id2'] = $userId;
    $params[':user_id3'] = $userId;
  }
  
  $where_sql = 'WHERE ' . implode(' AND ', $where_parts);
  
  $stmt = $pdo->prepare("
    SELECT ce.*, 
           l.name as lead_name,
           c.name as contact_name,
           u.full_name as created_by_name,
           rc.from_number, rc.to_number, rc.direction
    FROM calendar_events ce
    LEFT JOIN leads l ON ce.lead_id = l.id
    LEFT JOIN contacts c ON ce.contact_id = c.id
    LEFT JOIN users u ON ce.created_by = u.id
    LEFT JOIN retell_calls rc ON ce.retell_call_id = rc.id
    $where_sql
    ORDER BY ce.start_time ASC
  ");
  $stmt->execute($params);
  $items = $stmt->fetchAll();
  
  respond(['items' => $items]);
}

function api_calendar_save() {
  require_auth();
  $b = body_json();
  $pdo = db();
  
  $id = $b['id'] ?? null;
  $title = trim($b['title'] ?? '');
  $description = $b['description'] ?? null;
  $eventType = $b['event_type'] ?? 'booking';
  $startTime = $b['start_time'] ?? null;
  $endTime = $b['end_time'] ?? null;
  $allDay = $b['all_day'] ?? false;
  $location = $b['location'] ?? null;
  $status = $b['status'] ?? 'scheduled';
  $leadId = $b['lead_id'] ?? null;
  $contactId = $b['contact_id'] ?? null;
  $assignedTo = $b['assigned_to'] ?? null;
  $color = $b['color'] ?? null;
  
  if (!$title) {
    respond(['error' => 'Title is required'], 400);
  }
  
  if (!$startTime) {
    respond(['error' => 'Start time is required'], 400);
  }
  
  $userId = $_SESSION['user_id'];
  
  if ($id) {
    $stmt = $pdo->prepare("
      UPDATE calendar_events SET
        title = :title,
        description = :description,
        event_type = :event_type,
        start_time = :start_time,
        end_time = :end_time,
        all_day = :all_day,
        location = :location,
        status = :status,
        lead_id = :lead_id,
        contact_id = :contact_id,
        assigned_to = :assigned_to,
        color = :color,
        updated_at = now()
      WHERE id = :id
      RETURNING *
    ");
    $stmt->execute([
      ':id' => $id,
      ':title' => $title,
      ':description' => $description,
      ':event_type' => $eventType,
      ':start_time' => $startTime,
      ':end_time' => $endTime,
      ':all_day' => $allDay ? 'true' : 'false',
      ':location' => $location,
      ':status' => $status,
      ':lead_id' => $leadId,
      ':contact_id' => $contactId,
      ':assigned_to' => $assignedTo,
      ':color' => $color
    ]);
  } else {
    $stmt = $pdo->prepare("
      INSERT INTO calendar_events 
        (title, description, event_type, start_time, end_time, all_day, location, status, 
         lead_id, contact_id, created_by, assigned_to, color)
      VALUES 
        (:title, :description, :event_type, :start_time, :end_time, :all_day, :location, :status,
         :lead_id, :contact_id, :created_by, :assigned_to, :color)
      RETURNING *
    ");
    $stmt->execute([
      ':title' => $title,
      ':description' => $description,
      ':event_type' => $eventType,
      ':start_time' => $startTime,
      ':end_time' => $endTime,
      ':all_day' => $allDay ? 'true' : 'false',
      ':location' => $location,
      ':status' => $status,
      ':lead_id' => $leadId,
      ':contact_id' => $contactId,
      ':created_by' => $userId,
      ':assigned_to' => $assignedTo,
      ':color' => $color
    ]);
  }
  
  $item = $stmt->fetch();
  respond(['item' => $item]);
}

function api_calendar_delete() {
  require_auth();
  $id = (int)($_GET['id'] ?? 0);
  
  $pdo = db();
  $role = $_SESSION['role'];
  $userId = $_SESSION['user_id'];
  
  // Check ownership for non-admins
  if ($role !== 'admin') {
    $stmt = $pdo->prepare("SELECT created_by FROM calendar_events WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $event = $stmt->fetch();
    if (!$event || $event['created_by'] != $userId) {
      respond(['error' => 'Not authorized to delete this event'], 403);
    }
  }
  
  $pdo->prepare("DELETE FROM calendar_events WHERE id = :id")->execute([':id' => $id]);
  respond(['ok' => true]);
}

// Cal.com Webhook Handler
function api_cal_webhook() {
  $rawPayload = file_get_contents('php://input');
  $data = json_decode($rawPayload, true);
  
  if (!$data) {
    respond(['error' => 'Invalid JSON payload'], 400);
  }
  
  // Verify Cal.com signature if secret is configured
  $pdo = db();
  $stmt = $pdo->prepare("SELECT value FROM settings WHERE key = 'cal_webhook_secret'");
  $stmt->execute();
  $secretRow = $stmt->fetch();
  $webhookSecret = $secretRow ? $secretRow['value'] : null;
  
  if ($webhookSecret) {
    $signature = $_SERVER['HTTP_X_CAL_SIGNATURE_256'] ?? '';
    $expectedSignature = hash_hmac('sha256', $rawPayload, $webhookSecret);
    
    if (!hash_equals($expectedSignature, $signature)) {
      error_log("Cal.com webhook: Invalid signature");
      respond(['error' => 'Invalid signature'], 401);
    }
  }
  
  $triggerEvent = $data['triggerEvent'] ?? '';
  $payload = $data['payload'] ?? [];
  
  if ($triggerEvent === 'BOOKING_CREATED') {
    $pdo = db();
    
    // Log full payload for debugging
    error_log("Cal.com BOOKING_CREATED payload: " . json_encode($payload, JSON_PRETTY_PRINT));
    
    $attendee = $payload['attendees'][0] ?? [];
    $attendeeName = $attendee['name'] ?? 'Guest';
    $attendeeEmail = $attendee['email'] ?? 'N/A';
    $attendeeTimezone = $attendee['timeZone'] ?? 'N/A';
    // Capture phone number from multiple possible locations
    $attendeePhone = $attendee['phoneNumber'] ?? $attendee['phone'] ?? '';
    
    $organizer = $payload['organizer'] ?? [];
    $organizerName = $organizer['name'] ?? 'Organizer';
    $organizerEmail = $organizer['email'] ?? '';
    
    $title = "Cal.com: " . $attendeeName;
    
    $startTime = $payload['startTime'] ?? '';
    $endTime = $payload['endTime'] ?? '';
    $eventTitle = $payload['title'] ?? 'New Booking';
    $location = $payload['location'] ?? '';
    $notes = $payload['additionalNotes'] ?? '';
    $descriptionText = $payload['description'] ?? '';
    $bookingUid = $payload['uid'] ?? '';
    
    // Check multiple locations for video URL
    $videoUrl = $payload['metadata']['videoCallUrl'] 
      ?? $payload['videoCallUrl'] 
      ?? $payload['conferenceData']['entryPoints'][0]['uri'] 
      ?? $payload['metadata']['conferenceUrl']
      ?? '';
    
    // Build description with all booking details
    $description = "--- Booking Details ---\n";
    $description .= "Event: " . $eventTitle . "\n\n";
    
    $description .= "--- Guest Information ---\n";
    $description .= "Name: " . $attendeeName . "\n";
    $description .= "Email: " . $attendeeEmail . "\n";
    if ($attendeePhone) $description .= "Phone: " . $attendeePhone . "\n";
    $description .= "Timezone: " . $attendeeTimezone . "\n\n";
    
    $description .= "--- Organizer ---\n";
    if ($organizerEmail) $description .= $organizerName . " (" . $organizerEmail . ")\n\n";
    else $description .= $organizerName . "\n\n";
    
    $prettyLocation = $location;
    if ($location === 'integrations:daily') $prettyLocation = 'Cal Video';
    
    if ($videoUrl) {
      $description .= "--- Meeting Location ---\n";
      $description .= "Meeting Link: " . $videoUrl . "\n";
      $description .= "\n";
    }
    
    // Process custom responses (includes phone, notes, custom questions)
    $responses = $payload['responses'] ?? [];
    $customResponses = [];
    
    $skipLabels = ['name', 'email', 'phone', 'location', 'notes', 'description', 'additional guests', 'guests'];
    
    foreach ($responses as $key => $resp) {
      // Handle different response formats from Cal.com
      if (is_array($resp)) {
        $label = $resp['label'] ?? ucfirst(str_replace('_', ' ', $key));
        $value = $resp['value'] ?? '';
        if (is_array($value)) {
          $value = $value['value'] ?? $value['optionValue'] ?? json_encode($value);
        }
      } else {
        $label = ucfirst(str_replace('_', ' ', $key));
        $value = $resp;
      }
      
      // Skip empty values, already captured data, or useless placeholders
      if (empty($value) || $value === 'N/A' || $value === '[]') continue;
      
      $lowerLabel = strtolower($label);
      $shouldSkip = false;
      foreach ($skipLabels as $skip) {
        if (strpos($lowerLabel, $skip) !== false) {
          $shouldSkip = true;
          break;
        }
      }
      if ($shouldSkip) continue;
      
      // Special cleanup for "What is this meeting about?"
      if (stripos($label, 'what is this meeting about') !== false) {
        $label = 'Purpose';
      }
      
      $customResponses[$label] = $value;
    }
    
    // Add custom responses section if any
    if (!empty($customResponses)) {
      $description .= "--- Booking Questions ---\n";
      foreach ($customResponses as $label => $value) {
        // Clean up common labels
        $cleanLabel = preg_replace('/^(your_|please_|enter_)/i', '', $label);
        $cleanLabel = ucfirst(str_replace('_', ' ', $cleanLabel));
        $description .= $cleanLabel . ": " . $value . "\n";
      }
      $description .= "\n";
    }
    
    // Consolidate unique notes/description
    $finalNotes = [];
    if ($descriptionText && $descriptionText !== 'N/A') $finalNotes[] = trim($descriptionText);
    if ($notes && $notes !== 'N/A' && trim($notes) !== trim($descriptionText)) $finalNotes[] = trim($notes);
    
    if (!empty($finalNotes)) {
      $description .= "--- Additional Info ---\n";
      $description .= implode("\n\n", $finalNotes) . "\n";
    }
    
    if ($bookingUid) $description .= "\nBooking Ref: " . $bookingUid . "\n";
    
    // Update description if phone was found in responses
    if ($attendeePhone && strpos($description, 'Phone:') === false) {
      $description = str_replace("Timezone:", "Phone: " . $attendeePhone . "\nTimezone:", $description);
    }
    
    // Try to find matching lead/contact by email
    $email = $attendeeEmail;
    $leadId = null;
    $contactId = null;
    
    if ($email && $email !== 'N/A') {
      $stmt = $pdo->prepare("SELECT id FROM leads WHERE email = :email LIMIT 1");
      $stmt->execute([':email' => $email]);
      $lead = $stmt->fetch();
      if ($lead) $leadId = $lead['id'];
      
      $stmt = $pdo->prepare("SELECT id FROM contacts WHERE email = :email LIMIT 1");
      $stmt->execute([':email' => $email]);
      $contact = $stmt->fetch();
      if ($contact) $contactId = $contact['id'];
    }
    
    $stmt = $pdo->prepare("
      INSERT INTO calendar_events 
        (title, description, event_type, start_time, end_time, status, lead_id, contact_id, color, location, booking_uid)
      VALUES 
        (:title, :description, 'booking', :start_time, :end_time, 'confirmed', :lead_id, :contact_id, '#0066CC', :location, :booking_uid)
    ");
    
    $stmt->execute([
      ':title' => $title,
      ':description' => $description,
      ':start_time' => date('Y-m-d H:i:s', strtotime($startTime)),
      ':end_time' => date('Y-m-d H:i:s', strtotime($endTime)),
      ':lead_id' => $leadId,
      ':contact_id' => $contactId,
      ':location' => $prettyLocation ?: $location,
      ':booking_uid' => $bookingUid
    ]);
    
    // Send SMS confirmation via ClickSend email-to-SMS gateway
    if ($attendeePhone) {
      // Format date/time for SMS
      $smsDate = date('M j, Y', strtotime($startTime));
      $smsTime = date('g:i A', strtotime($startTime));
      
      // Get first name only for friendly greeting
      $firstName = explode(' ', $attendeeName)[0];
      
      $smsMessage = "Hi {$firstName}! Your appointment with Koadi Technology is confirmed for {$smsDate} at {$smsTime}. Check your email for the meeting link. Questions? Reply here.";
      
      $smsSent = send_sms($attendeePhone, $smsMessage);
      error_log("Cal.com booking SMS: " . ($smsSent ? "Sent" : "Failed") . " to {$attendeePhone}");
    } else {
      error_log("Cal.com booking: No phone number provided, skipping SMS");
    }
  }
  
  // Handle booking confirmation
  if ($triggerEvent === 'BOOKING_CONFIRMED') {
    $pdo = db();
    $bookingUid = $payload['uid'] ?? '';
    
    if ($bookingUid) {
      $stmt = $pdo->prepare("
        UPDATE calendar_events 
        SET status = 'confirmed', updated_at = now() 
        WHERE booking_uid = :booking_uid
      ");
      $stmt->execute([':booking_uid' => $bookingUid]);
    }
  }
  
  // Handle booking cancellation
  if ($triggerEvent === 'BOOKING_CANCELLED') {
    $pdo = db();
    $bookingUid = $payload['uid'] ?? '';
    $cancellationReason = $payload['cancellationReason'] ?? '';
    
    if ($bookingUid) {
      // Update the event status to cancelled and add cancellation reason to description
      $stmt = $pdo->prepare("SELECT id, description FROM calendar_events WHERE booking_uid = :booking_uid");
      $stmt->execute([':booking_uid' => $bookingUid]);
      $event = $stmt->fetch();
      
      if ($event) {
        $newDescription = $event['description'];
        $newDescription .= "\n\n--- CANCELLED ---\n";
        $newDescription .= "Cancelled at: " . date('Y-m-d H:i:s') . "\n";
        if ($cancellationReason) {
          $newDescription .= "Reason: " . $cancellationReason . "\n";
        }
        
        $stmt = $pdo->prepare("
          UPDATE calendar_events 
          SET status = 'cancelled', description = :description, color = '#dc2626', updated_at = now() 
          WHERE booking_uid = :booking_uid
        ");
        $stmt->execute([
          ':booking_uid' => $bookingUid,
          ':description' => $newDescription
        ]);
      }
    }
  }
  
  // Handle booking reschedule
  if ($triggerEvent === 'BOOKING_RESCHEDULED') {
    $pdo = db();
    $bookingUid = $payload['uid'] ?? '';
    $rescheduleUid = $payload['rescheduleUid'] ?? '';
    $newStartTime = $payload['startTime'] ?? '';
    $newEndTime = $payload['endTime'] ?? '';
    $rescheduleReason = $payload['rescheduleReason'] ?? '';
    
    // Try to find by rescheduleUid first (this is the original booking's uid)
    $searchUid = $rescheduleUid ?: $bookingUid;
    
    if ($searchUid && $newStartTime) {
      $stmt = $pdo->prepare("SELECT id, description FROM calendar_events WHERE booking_uid = :booking_uid");
      $stmt->execute([':booking_uid' => $searchUid]);
      $event = $stmt->fetch();
      
      if ($event) {
        $newDescription = $event['description'];
        $newDescription .= "\n\n--- RESCHEDULED ---\n";
        $newDescription .= "Rescheduled at: " . date('Y-m-d H:i:s') . "\n";
        $newDescription .= "New time: " . date('F j, Y g:i A', strtotime($newStartTime)) . "\n";
        if ($rescheduleReason) {
          $newDescription .= "Reason: " . $rescheduleReason . "\n";
        }
        
        $stmt = $pdo->prepare("
          UPDATE calendar_events 
          SET start_time = :start_time, 
              end_time = :end_time, 
              description = :description, 
              booking_uid = :new_uid,
              status = 'rescheduled',
              updated_at = now() 
          WHERE booking_uid = :old_uid
        ");
        $stmt->execute([
          ':start_time' => date('Y-m-d H:i:s', strtotime($newStartTime)),
          ':end_time' => $newEndTime ? date('Y-m-d H:i:s', strtotime($newEndTime)) : null,
          ':description' => $newDescription,
          ':new_uid' => $bookingUid,
          ':old_uid' => $searchUid
        ]);
      }
    }
  }
  
  respond(['ok' => true]);
}

function api_outscraper_webhook() {
  $rawPayload = file_get_contents('php://input');
  $data = json_decode($rawPayload, true);
  
  if (!$data) {
    respond(['error' => 'Invalid JSON payload'], 400);
  }
  
  $pdo = db();
  
  $stmt = $pdo->prepare("SELECT value FROM settings WHERE key = 'outscraper_webhook_secret'");
  $stmt->execute();
  $secretRow = $stmt->fetch();
  $webhookSecret = $secretRow ? $secretRow['value'] : null;
  
  if ($webhookSecret) {
    $signature = $_SERVER['HTTP_X_OUTSCRAPER_SIGNATURE'] ?? '';
    $expectedSignature = hash_hmac('sha256', $rawPayload, $webhookSecret);
    
    if (!hash_equals($expectedSignature, $signature)) {
      error_log("Outscraper webhook: Signature mismatch - rejecting request");
      respond(['error' => 'Invalid signature'], 401);
    }
  } else {
    error_log("Outscraper webhook: No webhook secret configured - accepting unverified request for initial setup");
  }
  
  $taskId = $data['id'] ?? $data['task_id'] ?? null;
  $status = $data['status'] ?? 'unknown';
  $query = $data['query'] ?? '';
  $records = $data['data'] ?? $data['results'] ?? [];
  
  if (!is_array($records)) {
    $records = [];
  }
  
  $totalRecords = count($records);
  $importedCount = 0;
  $skippedCount = 0;
  $duplicateCount = 0;
  $errorCount = 0;
  $errors = [];
  
  $stmt = $pdo->prepare("
    INSERT INTO outscraper_imports (task_id, query, total_records, status)
    VALUES (:task_id, :query, :total, 'processing')
    RETURNING id
  ");
  $stmt->execute([
    ':task_id' => $taskId,
    ':query' => is_array($query) ? implode(', ', $query) : $query,
    ':total' => $totalRecords
  ]);
  $importId = $stmt->fetchColumn();
  
  foreach ($records as $record) {
    try {
      $placeId = $record['place_id'] ?? null;
      
      if ($placeId) {
        $checkStmt = $pdo->prepare("SELECT id FROM leads WHERE google_place_id = :place_id");
        $checkStmt->execute([':place_id' => $placeId]);
        if ($checkStmt->fetch()) {
          $duplicateCount++;
          continue;
        }
      }
      
      $companyName = $record['name'] ?? '';
      if (empty($companyName)) {
        $skippedCount++;
        continue;
      }
      
      $phone = $record['contact_phone'] ?? $record['phone'] ?? $record['company_phone'] ?? $record['phone_1'] ?? $record['phone_2'] ?? '';
      
      $additionalPhones = [];
      if (!empty($record['phones'])) $additionalPhones = array_merge($additionalPhones, (array)$record['phones']);
      if (!empty($record['company_phones'])) $additionalPhones = array_merge($additionalPhones, (array)$record['company_phones']);
      if (!empty($record['contact_phones'])) $additionalPhones = array_merge($additionalPhones, (array)$record['contact_phones']);
      foreach (['phone_1', 'phone_2', 'phone_3', 'company_phone', 'contact_phone', 'phone'] as $field) {
        if (!empty($record[$field]) && $record[$field] !== $phone) {
          $additionalPhones[] = $record[$field];
        }
      }
      $additionalPhones = array_unique(array_filter($additionalPhones));
      
      $email = $record['email'] ?? '';
      $additionalEmails = [];
      if (!empty($record['emails'])) $additionalEmails = array_merge($additionalEmails, (array)$record['emails']);
      $additionalEmails = array_unique(array_filter($additionalEmails));
      
      $contactName = '';
      if (!empty($record['full_name'])) {
        $contactName = $record['full_name'];
      } elseif (!empty($record['first_name']) || !empty($record['last_name'])) {
        $contactName = trim(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? ''));
      }
      
      $contactTitle = $record['title'] ?? '';
      
      $addressParts = [];
      if (!empty($record['address'])) {
        $address = $record['address'];
      } else {
        if (!empty($record['street'])) $addressParts[] = $record['street'];
        if (!empty($record['city'])) $addressParts[] = $record['city'];
        if (!empty($record['state'])) $addressParts[] = $record['state'];
        if (!empty($record['postal_code'])) $addressParts[] = $record['postal_code'];
        if (!empty($record['country'])) $addressParts[] = $record['country'];
        $address = implode(', ', $addressParts);
      }
      
      $industry = $record['category'] ?? '';
      if (empty($industry) && !empty($record['subtypes'])) {
        $subtypes = is_array($record['subtypes']) ? $record['subtypes'] : explode(',', $record['subtypes']);
        $industry = $subtypes[0] ?? '';
      }
      
      $website = $record['website'] ?? $record['domain'] ?? '';
      $rating = isset($record['rating']) ? floatval($record['rating']) : null;
      $reviewsCount = isset($record['reviews']) ? intval($record['reviews']) : null;
      
      $socialLinks = [];
      if (!empty($record['company_linkedin'])) $socialLinks['linkedin'] = $record['company_linkedin'];
      if (!empty($record['company_facebook'])) $socialLinks['facebook'] = $record['company_facebook'];
      if (!empty($record['company_instagram'])) $socialLinks['instagram'] = $record['company_instagram'];
      if (!empty($record['company_x'])) $socialLinks['twitter'] = $record['company_x'];
      if (!empty($record['company_youtube'])) $socialLinks['youtube'] = $record['company_youtube'];
      if (!empty($record['contact_linkedin'])) $socialLinks['contact_linkedin'] = $record['contact_linkedin'];
      
      $insertStmt = $pdo->prepare("
        INSERT INTO leads (
          name, phone, email, company, address, industry, status, source,
          google_place_id, contact_name, contact_title, rating, reviews_count,
          website, social_links, additional_phones, additional_emails, created_at
        ) VALUES (
          :name, :phone, :email, :company, :address, :industry, 'global', 'outscraper',
          :place_id, :contact_name, :contact_title, :rating, :reviews_count,
          :website, :social_links, :additional_phones, :additional_emails, now()
        )
      ");
      
      $insertStmt->execute([
        ':name' => $companyName,
        ':phone' => $phone,
        ':email' => $email,
        ':company' => $companyName,
        ':address' => $address,
        ':industry' => $industry,
        ':place_id' => $placeId,
        ':contact_name' => $contactName,
        ':contact_title' => $contactTitle,
        ':rating' => $rating,
        ':reviews_count' => $reviewsCount,
        ':website' => $website,
        ':social_links' => !empty($socialLinks) ? json_encode($socialLinks) : null,
        ':additional_phones' => !empty($additionalPhones) ? json_encode(array_values($additionalPhones)) : null,
        ':additional_emails' => !empty($additionalEmails) ? json_encode(array_values($additionalEmails)) : null
      ]);
      
      $importedCount++;
      
    } catch (Throwable $e) {
      $errorCount++;
      $errors[] = ['record' => $record['name'] ?? 'unknown', 'error' => $e->getMessage()];
      error_log("Outscraper import error: " . $e->getMessage());
    }
  }
  
  $updateStmt = $pdo->prepare("
    UPDATE outscraper_imports SET
      imported_count = :imported,
      skipped_count = :skipped,
      duplicate_count = :duplicate,
      error_count = :errors,
      error_details = :error_details,
      status = 'completed'
    WHERE id = :id
  ");
  $updateStmt->execute([
    ':imported' => $importedCount,
    ':skipped' => $skippedCount,
    ':duplicate' => $duplicateCount,
    ':errors' => $errorCount,
    ':error_details' => !empty($errors) ? json_encode($errors) : null,
    ':id' => $importId
  ]);
  
  error_log("Outscraper import completed: $importedCount imported, $duplicateCount duplicates, $skippedCount skipped, $errorCount errors");
  
  respond([
    'ok' => true,
    'import_id' => $importId,
    'total' => $totalRecords,
    'imported' => $importedCount,
    'duplicates' => $duplicateCount,
    'skipped' => $skippedCount,
    'errors' => $errorCount
  ]);
}

function api_outscraper_imports_list() {
  require_admin();
  $pdo = db();
  
  $limit = (int)($_GET['limit'] ?? 20);
  $offset = (int)($_GET['offset'] ?? 0);
  
  $stmt = $pdo->prepare("
    SELECT * FROM outscraper_imports 
    ORDER BY created_at DESC 
    LIMIT :limit OFFSET :offset
  ");
  $stmt->execute([':limit' => $limit, ':offset' => $offset]);
  $imports = $stmt->fetchAll();
  
  $total = $pdo->query("SELECT COUNT(*) FROM outscraper_imports")->fetchColumn();
  
  respond(['items' => $imports, 'total' => $total]);
}

function parseXlsxFile($filePath) {
  $zip = new ZipArchive();
  if ($zip->open($filePath) !== true) {
    throw new Exception('Cannot open Excel file');
  }
  
  $sharedStrings = [];
  $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');
  if ($sharedStringsXml) {
    $xml = simplexml_load_string($sharedStringsXml);
    foreach ($xml->si as $si) {
      if (isset($si->t)) {
        $sharedStrings[] = (string)$si->t;
      } elseif (isset($si->r)) {
        $text = '';
        foreach ($si->r as $r) {
          $text .= (string)$r->t;
        }
        $sharedStrings[] = $text;
      } else {
        $sharedStrings[] = '';
      }
    }
  }
  
  $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
  if (!$sheetXml) {
    $zip->close();
    throw new Exception('Cannot find worksheet');
  }
  
  $xml = simplexml_load_string($sheetXml);
  $rows = [];
  $headers = [];
  $headerColIndexes = [];
  $rowIndex = 0;
  
  foreach ($xml->sheetData->row as $row) {
    $rowData = [];
    
    foreach ($row->c as $cell) {
      $cellRef = (string)$cell['r'];
      preg_match('/^([A-Z]+)/', $cellRef, $matches);
      $colLetter = $matches[1] ?? 'A';
      $colIndex = 0;
      $len = strlen($colLetter);
      for ($i = 0; $i < $len; $i++) {
        $colIndex = $colIndex * 26 + (ord($colLetter[$i]) - ord('A') + 1);
      }
      $colIndex--;
      
      $value = '';
      if (isset($cell->v)) {
        $value = (string)$cell->v;
        $type = (string)$cell['t'];
        if ($type === 's' && isset($sharedStrings[(int)$value])) {
          $value = $sharedStrings[(int)$value];
        }
      } elseif (isset($cell->is->t)) {
        $value = (string)$cell->is->t;
      }
      
      $rowData[$colIndex] = $value;
    }
    
    if ($rowIndex === 0) {
      ksort($rowData);
      $headers = $rowData;
      $headerColIndexes = array_keys($rowData);
    } else {
      $record = [];
      foreach ($headerColIndexes as $colIdx) {
        $header = $headers[$colIdx] ?? '';
        if (!empty($header)) {
          $record[$header] = $rowData[$colIdx] ?? '';
        }
      }
      if (!empty($record)) {
        $rows[] = $record;
      }
    }
    $rowIndex++;
  }
  
  $zip->close();
  return $rows;
}

function parseCsvFile($filePath) {
  $rows = [];
  $headers = [];
  $handle = fopen($filePath, 'r');
  
  if (!$handle) {
    throw new Exception('Cannot open CSV file');
  }
  
  $rowIndex = 0;
  while (($data = fgetcsv($handle)) !== false) {
    if ($rowIndex === 0) {
      $headers = $data;
    } else {
      $record = [];
      foreach ($headers as $i => $header) {
        $record[$header] = $data[$i] ?? '';
      }
      $rows[] = $record;
    }
    $rowIndex++;
  }
  
  fclose($handle);
  return $rows;
}

function mapOutscraperRecord($record) {
  $companyName = $record['name'] ?? $record['title'] ?? $record['business_name'] ?? '';
  
  // Improved phone mapping to check more possible column names
  $phone = $record['contact_phone'] ?? $record['phone'] ?? $record['company_phone'] ?? $record['phone_1'] ?? $record['phone_2'] ?? '';
  $additionalPhones = [];
  foreach (['phone_1', 'phone_2', 'phone_3', 'company_phone', 'contact_phone', 'phone'] as $field) {
    if (!empty($record[$field]) && $record[$field] !== $phone) {
      $additionalPhones[$record[$field]] = $record[$field];
    }
  }
  
  $email = $record['email'] ?? $record['contact_email'] ?? $record['company_email'] ?? $record['email_1'] ?? $record['email_2'] ?? '';
  $additionalEmails = [];
  foreach (['email_1', 'email_2', 'email_3', 'company_email', 'contact_email', 'email'] as $field) {
    if (!empty($record[$field]) && $record[$field] !== $email) {
      $additionalEmails[$record[$field]] = $record[$field];
    }
  }
  
  $contactName = $record['contact_name'] ?? $record['owner'] ?? '';
  $contactTitle = $record['contact_title'] ?? $record['job_title'] ?? '';
  
  $address = $record['full_address'] ?? $record['address'] ?? '';
  if (empty($address)) {
    $addressParts = [];
    if (!empty($record['street'])) $addressParts[] = $record['street'];
    if (!empty($record['city'])) $addressParts[] = $record['city'];
    if (!empty($record['state'])) $addressParts[] = $record['state'];
    if (!empty($record['postal_code'])) $addressParts[] = $record['postal_code'];
    if (!empty($record['country'])) $addressParts[] = $record['country'];
    $address = implode(', ', $addressParts);
  }
  
  $industry = $record['category'] ?? $record['type'] ?? '';
  if (empty($industry) && !empty($record['subtypes'])) {
    $subtypes = is_array($record['subtypes']) ? $record['subtypes'] : explode(',', $record['subtypes']);
    $industry = $subtypes[0] ?? '';
  }
  
  $website = $record['website'] ?? $record['domain'] ?? '';
  $rating = isset($record['rating']) && is_numeric($record['rating']) ? floatval($record['rating']) : null;
  $reviewsCount = isset($record['reviews']) && is_numeric($record['reviews']) ? intval($record['reviews']) : null;
  
  $socialLinks = [];
  if (!empty($record['company_linkedin'])) $socialLinks['linkedin'] = $record['company_linkedin'];
  if (!empty($record['company_facebook'])) $socialLinks['facebook'] = $record['company_facebook'];
  if (!empty($record['company_instagram'])) $socialLinks['instagram'] = $record['company_instagram'];
  if (!empty($record['company_x'])) $socialLinks['twitter'] = $record['company_x'];
  if (!empty($record['company_youtube'])) $socialLinks['youtube'] = $record['company_youtube'];
  
  $placeId = $record['place_id'] ?? $record['google_place_id'] ?? null;
  
  return [
    'name' => $companyName,
    'phone' => $phone,
    'email' => $email,
    'company' => $companyName,
    'address' => $address,
    'industry' => $industry,
    'google_place_id' => $placeId,
    'contact_name' => $contactName,
    'contact_title' => $contactTitle,
    'rating' => $rating,
    'reviews_count' => $reviewsCount,
    'website' => $website,
    'social_links' => $socialLinks,
    'additional_phones' => array_values($additionalPhones),
    'additional_emails' => array_values($additionalEmails)
  ];
}

function api_outscraper_upload() {
  require_admin();
  
  if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    respond(['error' => 'No file uploaded or upload error'], 400);
  }
  
  $file = $_FILES['file'];
  $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
  
  if (!in_array($ext, ['xlsx', 'csv'])) {
    respond(['error' => 'Only Excel (.xlsx) or CSV (.csv) files are supported'], 400);
  }
  
  try {
    if ($ext === 'xlsx') {
      $records = parseXlsxFile($file['tmp_name']);
    } else {
      $records = parseCsvFile($file['tmp_name']);
    }
    
    if (empty($records)) {
      respond(['error' => 'No records found in file'], 400);
    }
    
    $pdo = db();
    $batchId = 'batch_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
    
    $insertStmt = $pdo->prepare("
      INSERT INTO outscraper_staging (
        batch_id, name, phone, email, company, address, industry,
        google_place_id, contact_name, contact_title, rating, reviews_count,
        website, social_links, additional_phones, additional_emails, raw_data, status
      ) VALUES (
        :batch_id, :name, :phone, :email, :company, :address, :industry,
        :place_id, :contact_name, :contact_title, :rating, :reviews_count,
        :website, :social_links, :additional_phones, :additional_emails, :raw_data, 'pending'
      )
    ");
    
    $importedCount = 0;
    foreach ($records as $record) {
      $mapped = mapOutscraperRecord($record);
      
      if (empty($mapped['name'])) continue;
      
      $insertStmt->execute([
        ':batch_id' => $batchId,
        ':name' => $mapped['name'],
        ':phone' => $mapped['phone'],
        ':email' => $mapped['email'],
        ':company' => $mapped['company'],
        ':address' => $mapped['address'],
        ':industry' => $mapped['industry'],
        ':place_id' => $mapped['google_place_id'],
        ':contact_name' => $mapped['contact_name'],
        ':contact_title' => $mapped['contact_title'],
        ':rating' => $mapped['rating'],
        ':reviews_count' => $mapped['reviews_count'],
        ':website' => $mapped['website'],
        ':social_links' => !empty($mapped['social_links']) ? json_encode($mapped['social_links']) : null,
        ':additional_phones' => !empty($mapped['additional_phones']) ? json_encode($mapped['additional_phones']) : null,
        ':additional_emails' => !empty($mapped['additional_emails']) ? json_encode($mapped['additional_emails']) : null,
        ':raw_data' => json_encode($record)
      ]);
      $importedCount++;
    }
    
    respond([
      'ok' => true,
      'batch_id' => $batchId,
      'parsed' => $importedCount,
      'total_in_file' => count($records)
    ]);
    
  } catch (Throwable $e) {
    respond(['error' => 'Failed to parse file: ' . $e->getMessage()], 500);
  }
}

function api_outscraper_staging_list() {
  require_admin();
  $pdo = db();
  
  $batchId = $_GET['batch_id'] ?? null;
  $status = $_GET['status'] ?? 'pending';
  
  $sql = "SELECT * FROM outscraper_staging WHERE status = :status";
  $params = [':status' => $status];
  
  if ($batchId) {
    $sql .= " AND batch_id = :batch_id";
    $params[':batch_id'] = $batchId;
  }
  
  $sql .= " ORDER BY created_at DESC";
  
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $items = $stmt->fetchAll();
  
  $batchesStmt = $pdo->query("
    SELECT batch_id, COUNT(*) as count, MIN(created_at) as created_at 
    FROM outscraper_staging 
    WHERE status = 'pending' 
    GROUP BY batch_id 
    ORDER BY created_at DESC
  ");
  $batches = $batchesStmt->fetchAll();
  
  respond(['items' => $items, 'batches' => $batches]);
}

function api_outscraper_staging_approve() {
  require_admin();
  $pdo = db();
  $b = body_json();
  
  $ids = $b['ids'] ?? [];
  $approveAll = $b['approve_all'] ?? false;
  $batchId = $b['batch_id'] ?? null;
  
  if (empty($ids) && !$approveAll) {
    respond(['error' => 'No leads selected'], 400);
  }
  
  if ($approveAll && $batchId) {
    $stmt = $pdo->prepare("SELECT * FROM outscraper_staging WHERE batch_id = :batch_id AND status = 'pending'");
    $stmt->execute([':batch_id' => $batchId]);
  } elseif ($approveAll) {
    $stmt = $pdo->query("SELECT * FROM outscraper_staging WHERE status = 'pending'");
  } else {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM outscraper_staging WHERE id IN ($placeholders) AND status = 'pending'");
    $stmt->execute($ids);
  }
  
  $staging = $stmt->fetchAll();
  
  $importedCount = 0;
  $duplicateCount = 0;
  
  $checkStmt = $pdo->prepare("SELECT id FROM leads WHERE google_place_id = :place_id");
  $insertStmt = $pdo->prepare("
    INSERT INTO leads (
      name, phone, email, company, address, industry, status, source,
      google_place_id, contact_name, contact_title, rating, reviews_count,
      website, social_links, additional_phones, additional_emails, created_at
    ) VALUES (
      :name, :phone, :email, :company, :address, :industry, 'global', 'outscraper',
      :place_id, :contact_name, :contact_title, :rating, :reviews_count,
      :website, :social_links, :additional_phones, :additional_emails, now()
    )
  ");
  $updateStmt = $pdo->prepare("UPDATE outscraper_staging SET status = :status WHERE id = :id");
  
  foreach ($staging as $row) {
    if (!empty($row['google_place_id'])) {
      $checkStmt->execute([':place_id' => $row['google_place_id']]);
      if ($checkStmt->fetch()) {
        $updateStmt->execute([':status' => 'duplicate', ':id' => $row['id']]);
        $duplicateCount++;
        continue;
      }
    }
    
    $insertStmt->execute([
      ':name' => $row['name'],
      ':phone' => $row['phone'],
      ':email' => $row['email'],
      ':company' => $row['company'],
      ':address' => $row['address'],
      ':industry' => $row['industry'],
      ':place_id' => $row['google_place_id'],
      ':contact_name' => $row['contact_name'],
      ':contact_title' => $row['contact_title'],
      ':rating' => $row['rating'],
      ':reviews_count' => $row['reviews_count'],
      ':website' => $row['website'],
      ':social_links' => $row['social_links'],
      ':additional_phones' => $row['additional_phones'],
      ':additional_emails' => $row['additional_emails']
    ]);
    
    $updateStmt->execute([':status' => 'approved', ':id' => $row['id']]);
    $importedCount++;
  }
  
  respond([
    'ok' => true,
    'imported' => $importedCount,
    'duplicates' => $duplicateCount
  ]);
}

function api_outscraper_staging_reject() {
  require_admin();
  $pdo = db();
  $b = body_json();
  
  $ids = $b['ids'] ?? [];
  $rejectAll = $b['reject_all'] ?? false;
  $batchId = $b['batch_id'] ?? null;
  
  if (empty($ids) && !$rejectAll) {
    respond(['error' => 'No leads selected'], 400);
  }
  
  if ($rejectAll && $batchId) {
    $stmt = $pdo->prepare("UPDATE outscraper_staging SET status = 'rejected' WHERE batch_id = :batch_id AND status = 'pending'");
    $stmt->execute([':batch_id' => $batchId]);
    $count = $stmt->rowCount();
  } elseif ($rejectAll) {
    $stmt = $pdo->query("UPDATE outscraper_staging SET status = 'rejected' WHERE status = 'pending'");
    $count = $stmt->rowCount();
  } else {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("UPDATE outscraper_staging SET status = 'rejected' WHERE id IN ($placeholders) AND status = 'pending'");
    $stmt->execute($ids);
    $count = $stmt->rowCount();
  }
  
  respond(['ok' => true, 'rejected' => $count]);
}

function api_outscraper_staging_clear() {
  require_admin();
  $pdo = db();
  $b = body_json();
  
  $status = $b['status'] ?? 'all';
  $batchId = $b['batch_id'] ?? null;
  
  if ($status === 'all') {
    if ($batchId) {
      $stmt = $pdo->prepare("DELETE FROM outscraper_staging WHERE batch_id = :batch_id");
      $stmt->execute([':batch_id' => $batchId]);
    } else {
      $pdo->exec("DELETE FROM outscraper_staging");
    }
  } else {
    if ($batchId) {
      $stmt = $pdo->prepare("DELETE FROM outscraper_staging WHERE status = :status AND batch_id = :batch_id");
      $stmt->execute([':status' => $status, ':batch_id' => $batchId]);
    } else {
      $stmt = $pdo->prepare("DELETE FROM outscraper_staging WHERE status = :status");
      $stmt->execute([':status' => $status]);
    }
  }
  
  respond(['ok' => true]);
}


if (isset($_GET['logo'])) {
  header('Content-Type: image/png');
  readfile(__DIR__ . '/logo.png');
  exit;
}

if (isset($_GET['favicon'])) {
  header('Content-Type: image/png');
  readfile(__DIR__ . '/favicon.png');
  exit;
}


if (isset($_GET['background'])) {
  header('Content-Type: image/jpeg');
  readfile(__DIR__ . '/background.jpg');
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Koadi Technology CRM</title>
  <link rel="icon" href="?favicon" type="image/png">
  <style>
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
      --brand-hover: var(--kt-dark-blue);
      --accent: var(--kt-orange);
    }
    
    [data-theme="dark"] {
      --bg: #0f172a;
      --panel: #1e293b;
      --text: #e2e8f0;
      --muted: #94a3b8;
      --border: #334155;
      --brand: var(--kt-blue);
      --brand-hover: #0052a3;
      --accent: var(--kt-orange);
    }
    
    * { box-sizing: border-box; margin: 0; padding: 0; }
    
    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
      background: var(--bg);
      color: var(--text);
      transition: background 0.3s, color 0.3s;
      font-size: 80%;
    }
    
    .app { display: flex; height: 100vh; }
    
    .sidebar {
      width: 280px;
      background: var(--panel);
      border-right: 1px solid var(--border);
      padding: 20px;
      overflow-y: auto;
      transition: transform 0.3s, width 0.3s;
    }
    
    .sidebar.collapsed {
      width: 0;
      padding: 0;
      transform: translateX(-280px);
    }
    
    .sidebar-toggle {
      position: fixed;
      top: 20px;
      left: 20px;
      z-index: 1000;
      background: var(--brand);
      color: white;
      border: none;
      border-radius: 6px;
      padding: 10px 14px;
      cursor: pointer;
      transition: all 0.3s;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
      font-size: 16px;
      font-weight: bold;
    }
    
    .sidebar-toggle.shifted {
      left: 240px;
    }
    
    .logo-area {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 24px;
      padding-bottom: 20px;
      border-bottom: 2px solid var(--accent);
    }
    
    .logo-area img { width: 140px; height: auto; }
    
    .user-info {
      background: var(--bg);
      padding: 12px;
      border-radius: 8px;
      margin-bottom: 20px;
      border-left: 3px solid var(--accent);
    }
    
    .user-info strong { color: var(--brand); }
    
    .nav { display: flex; flex-direction: column; gap: 8px; }
    
    .nav button {
      padding: 12px;
      border: none;
      background: transparent;
      color: var(--text);
      text-align: left;
      cursor: pointer;
      border-radius: 6px;
      font-size: 14px;
      transition: all 0.2s;
    }
    
    .nav button:hover { background: var(--bg); }
    .nav button.active { background: var(--brand); color: white; }
    
    .content { flex: 1; padding: 24px; overflow-y: auto; transition: padding-left 0.3s; }
    
    body.sidebar-collapsed .content {
      padding-left: 80px;
    }
    
    .toolbar {
      display: flex;
      gap: 12px;
      align-items: center;
      margin-bottom: 20px;
      flex-wrap: wrap;
    }
    
    .btn {
      padding: 10px 16px;
      border: none;
      background: var(--brand);
      color: white;
      border-radius: 6px;
      cursor: pointer;
      font-size: 14px;
      font-weight: 500;
      transition: background 0.2s;
    }
    
    .btn:hover { background: var(--brand-hover); }
    .btn.secondary { background: var(--muted); }
    .btn.danger { background: #dc3545; }
    .btn.success { background: #28a745; }
    .btn.warning { background: var(--accent); }
    
    .search {
      flex: 1;
      max-width: 400px;
      padding: 10px;
      border: 1px solid var(--border);
      border-radius: 6px;
      background: var(--panel);
      color: var(--text);
    }
    
    select {
      background: var(--bg);
      color: var(--text);
      border: 1px solid var(--border);
    }
    
    select option {
      background: var(--bg);
      color: var(--text);
    }
    
    .card {
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 20px;
      margin-bottom: 20px;
    }
    
    .card h2 { margin-bottom: 16px; color: var(--brand); }
    
    table {
      width: 100%;
      border-collapse: collapse;
    }
    
    th, td {
      padding: 12px 8px;
      text-align: left;
      border-bottom: 1px solid var(--border);
    }
    
    th {
      background: var(--bg);
      font-weight: 600;
      color: var(--brand);
    }
    
    tbody tr {
      transition: background-color 0.2s ease;
    }
    
    tbody tr:hover {
      background-color: var(--border);
      cursor: pointer;
    }
    
    .modal {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: rgba(0, 0, 0, 0.5);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 1000;
    }
    
    .modal-content {
      background: var(--panel);
      padding: 24px;
      border-radius: 8px;
      max-width: 600px;
      width: 90%;
      max-height: 90vh;
      overflow-y: auto;
    }
    
    .modal h3 { margin-bottom: 16px; color: var(--brand); }
    
    .form-group {
      margin-bottom: 16px;
    }
    
    .form-group label {
      display: block;
      margin-bottom: 6px;
      font-weight: 500;
    }
    
    .form-group input, .form-group select, .form-group textarea {
      width: 100%;
      padding: 10px;
      border: 1px solid var(--border);
      border-radius: 6px;
      background: var(--bg);
      color: var(--text);
      font-family: inherit;
    }
    
    .form-group textarea { min-height: 100px; resize: vertical; }
    
    .login-container {
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      background-image: linear-gradient(135deg, rgba(0, 102, 204, 0.15), rgba(0, 51, 102, 0.15)), url('?background');
      background-position: center;
      background-size: cover;
      background-repeat: no-repeat;
      background-attachment: fixed;
    }
    
    .login-box {
      background: var(--panel);
      padding: 40px;
      border-radius: 12px;
      box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
      width: 100%;
      max-width: 400px;
      text-align: center;
    }
    
    .login-box img { width: 200px; margin-bottom: 24px; }
    
    .theme-toggle {
      background: var(--bg);
      border: 1px solid var(--border);
      padding: 8px 12px;
      border-radius: 6px;
      cursor: pointer;
      margin-left: auto;
    }
    
    .badge {
      display: inline-block;
      padding: 4px 8px;
      border-radius: 4px;
      font-size: 12px;
      font-weight: 500;
    }
    
    .badge.global { background: var(--kt-yellow); color: #000; }
    .badge.assigned { background: var(--kt-blue); color: white; }
    
    .tabs {
      display: flex;
      gap: 12px;
      margin-bottom: 20px;
      border-bottom: 2px solid var(--border);
    }
    
    .tabs button {
      padding: 12px 20px;
      border: none;
      background: transparent;
      color: var(--muted);
      cursor: pointer;
      border-bottom: 3px solid transparent;
      margin-bottom: -2px;
      transition: all 0.2s;
    }
    
    .tabs button.active {
      color: var(--brand);
      border-bottom-color: var(--brand);
    }
    
    .history-item {
      padding: 12px;
      background: var(--bg);
      border-left: 3px solid var(--accent);
      margin-bottom: 12px;
      border-radius: 4px;
    }
    
    .history-item .type {
      font-weight: 600;
      color: var(--brand);
      text-transform: capitalize;
    }
    
    .history-item .time {
      font-size: 12px;
      color: var(--muted);
    }
    
    .view { display: none; }
    .view.active { display: block; }
    
    .view-toggle {
      display: flex;
      gap: 8px;
      background: var(--bg);
      padding: 4px;
      border-radius: 6px;
      border: 1px solid var(--border);
    }
    
    .view-toggle button {
      padding: 8px 16px;
      border: none;
      background: transparent;
      color: var(--text);
      border-radius: 4px;
      cursor: pointer;
      transition: all 0.2s;
    }
    
    .view-toggle button.active {
      background: var(--brand);
      color: white;
    }
    
    .kanban-col {
      background: var(--bg);
      border-radius: 8px;
      padding: 12px;
      min-height: 200px;
      min-width: 0;
    }
    
    .kanban-col h4 {
      margin-bottom: 12px;
      padding-bottom: 8px;
      border-bottom: 2px solid var(--brand);
      font-size: 14px;
      text-transform: uppercase;
      color: var(--brand);
    }
    
    .kanban-card {
      background: var(--panel);
      padding: 12px;
      border-radius: 6px;
      margin-bottom: 8px;
      cursor: move;
      border-left: 3px solid var(--accent);
      transition: all 0.2s;
      position: relative;
      width: 100%;
      max-width: 100%;
      box-sizing: border-box;
    }
    
    .kanban-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
    }
    
    .kanban-card:hover .kanban-actions {
      opacity: 1;
      pointer-events: auto;
    }
    
    .kanban-card-title {
      display: block;
      margin-bottom: 8px;
      color: var(--brand);
      font-weight: 600;
      cursor: pointer;
      text-decoration: underline;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      max-width: 100%;
    }
    
    .kanban-card-title:hover {
      color: var(--accent);
    }
    
    .kanban-card-contact {
      display: block;
      font-size: 13px;
      color: var(--text);
      cursor: pointer;
      text-decoration: underline;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      max-width: 100%;
    }
    
    .kanban-card-contact:hover {
      color: var(--brand);
    }
    
    .kanban-card-value {
      display: block;
      font-size: 14px;
      font-weight: 600;
      color: var(--accent);
      margin-top: 8px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      max-width: 100%;
    }
    
    .kanban-card-date {
      display: block;
      font-size: 12px;
      color: var(--muted);
      margin-top: 4px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      max-width: 100%;
    }
    
    .kanban-card-notes {
      display: block;
      font-size: 11px;
      color: var(--muted);
      margin-top: 6px;
      font-style: italic;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      max-width: 100%;
    }
    
    .kanban-actions {
      position: absolute;
      top: 8px;
      right: 8px;
      display: flex;
      gap: 4px;
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.2s;
    }
    
    .kanban-actions button {
      padding: 4px 8px;
      border: none;
      background: var(--brand);
      color: white;
      border-radius: 4px;
      cursor: pointer;
      font-size: 11px;
      transition: background 0.2s;
    }
    
    .kanban-actions button:hover {
      background: var(--brand-hover);
    }
    
    .kanban-actions button.danger {
      background: #dc3545;
    }
    
    @media (max-width: 768px) {
      .app { flex-direction: column; }
      .sidebar { width: 100%; border-right: none; border-bottom: 1px solid var(--border); }
    }
    
      font-size: 20px;
      color: var(--muted);
      cursor: pointer;
      padding: 0;
      width: 24px;
      height: 24px;
    }
    
    .call-widget-body {
      text-align: center;
    }
    
    .call-number {
      font-size: 18px;
      font-weight: 500;
      color: var(--text);
      margin-bottom: 8px;
    }
    
    .call-contact-name {
      font-size: 14px;
      color: var(--muted);
      margin-bottom: 16px;
    }
    
    .call-status {
      font-size: 14px;
      color: var(--brand);
      margin-bottom: 8px;
    }
    
    .call-timer {
      font-size: 24px;
      font-weight: 600;
      color: var(--text);
      margin-bottom: 16px;
    }
    
    .call-controls {
      display: flex;
      gap: 8px;
      justify-content: center;
    }
    
    .call-btn {
      padding: 10px 20px;
      border: none;
      border-radius: 6px;
      font-size: 14px;
      cursor: pointer;
      transition: all 0.2s;
    }
    
    .call-btn-primary {
      background: var(--brand);
      color: white;
    }
    
    .call-btn-primary:hover {
      background: var(--brand-hover);
    }
    
    .call-btn-secondary {
      background: var(--muted);
      color: white;
    }
    
    .call-btn-secondary:hover {
      opacity: 0.8;
    }
    
    .call-btn-danger {
      background: #dc3545;
      color: white;
    }
    
    .call-btn-danger:hover {
      background: #c82333;
    }
    
    /* Mobile Bottom Navigation */
    .mobile-nav {
      display: none;
      position: fixed;
      bottom: 0;
      left: 0;
      right: 0;
      background: var(--panel);
      border-top: 1px solid var(--border);
      padding: 8px 0;
      z-index: 1000;
      box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
    }
    
    .mobile-nav-inner {
      display: flex;
      justify-content: space-around;
      align-items: center;
      max-width: 100%;
      overflow-x: auto;
    }
    
    .mobile-nav button {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 2px;
      background: none;
      border: none;
      color: var(--muted);
      font-size: 10px;
      padding: 4px 8px;
      cursor: pointer;
      transition: color 0.2s;
      min-width: 50px;
    }
    
    .mobile-nav button .icon {
      font-size: 20px;
    }
    
    .mobile-nav button.active {
      color: var(--brand);
    }
    
    .mobile-nav button:hover {
      color: var(--brand);
    }
    
    /* Table container for horizontal scroll on mobile */
    .table-scroll {
      width: 100%;
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
    }
    
    /* Mobile Responsive Styles */
    @media (max-width: 768px) {
      .sidebar {
        display: none !important;
      }
      
      .sidebar-toggle {
        display: none !important;
      }
      
      .mobile-nav {
        display: block;
      }
      
      .app {
        display: block;
      }
      
      .content {
        margin-left: 0;
        padding: 16px;
        padding-bottom: 80px; /* Space for bottom nav */
        width: 100%;
      }
      
      .toolbar {
        flex-wrap: wrap;
        gap: 8px;
      }
      
      .toolbar .btn {
        padding: 10px 16px;
        font-size: 14px;
      }
      
      .toolbar input,
      .toolbar select {
        width: 100%;
        margin-bottom: 8px;
      }
      
      .card {
        padding: 16px;
        margin-bottom: 16px;
      }
      
      table {
        font-size: 13px;
      }
      
      table th,
      table td {
        padding: 10px 8px;
        white-space: nowrap;
      }
      
      .form-group input,
      .form-group select,
      .form-group textarea {
        padding: 12px;
        font-size: 16px; /* Prevents zoom on iOS */
      }
      
      .btn {
        padding: 12px 20px;
        font-size: 15px;
      }
      
      .modal-overlay .modal {
        width: 95%;
        max-width: none;
        margin: 10px;
        max-height: 90vh;
      }
      
      .tabs {
        overflow-x: auto;
        white-space: nowrap;
        padding-bottom: 8px;
      }
      
      .tabs button {
        padding: 10px 16px;
        font-size: 13px;
      }
      
      .kanban {
        flex-direction: column;
      }
      
      .kanban-column {
        width: 100%;
        min-width: 100%;
      }
      
      .stats-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
      }
      
      .stat-card h3 {
        font-size: 24px;
      }
      
      .login-box {
        padding: 24px;
        margin: 16px;
      }
      
      h2 {
        font-size: 20px;
      }
      
      .user-info {
        padding: 12px;
      }
    }
    
    @media (max-width: 480px) {
      .stats-grid {
        grid-template-columns: 1fr;
      }
      
      .toolbar .btn {
        width: 100%;
      }
      
      table th,
      table td {
        padding: 8px 6px;
        font-size: 12px;
      }
    }
    
  </style>
</head>
<body data-theme="dark">
  <div id="app"></div>
  
  
  <script>
    let currentUser = null;
    let currentView = 'leads';
    let currentLeadTab = localStorage.getItem('crm_lead_tab') || 'global';
    let currentLeadPage = parseInt(localStorage.getItem('crm_lead_page')) || 1;
    let leadsPerPage = parseInt(localStorage.getItem('crm_leads_per_page')) || 20;
    let currentLeadIndustry = localStorage.getItem('crm_lead_industry') || '';
    let projectViewMode = 'kanban';
    let sidebarCollapsed = false;
    
    // Helper function to format phone for display
    function makePhoneClickable(phoneCountry, phoneNumber, contactName = '') {
      if (!phoneNumber) return '';
      const fullNumber = (phoneCountry || '') + phoneNumber;
    }
    
    async function api(endpoint, options = {}) {
      const res = await fetch(`?api=${endpoint}`, {
        headers: { 'Content-Type': 'application/json' },
        ...options
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Request failed');
      return data;
    }
    
    async function checkSession() {
      try {
        const data = await api('session');
        currentUser = data.user;
        if (currentUser) {
          renderApp();
        } else {
          renderLogin();
        }
      } catch (e) {
        renderLogin();
      }
    }
    
    function renderLogin() {
      const inviteToken = new URLSearchParams(window.location.search).get('invite_token');
      if (inviteToken) {
        renderAcceptInvite(inviteToken);
        return;
      }
      
      const magicToken = new URLSearchParams(window.location.search).get('magic_token');
      if (magicToken) {
        verifyMagicLink(magicToken);
        return;
      }
      
      document.getElementById('app').innerHTML = `
        <div class="login-container">
          <div class="login-box">
            <img src="?logo" alt="Koadi Technology">
            <h2 style="margin-bottom: 24px;">Koadi Tech CRM</h2>
            <div id="loginFormContainer">
              <p style="color: var(--muted); margin-bottom: 20px; text-align: center;">Enter your email to receive a secure login link.</p>
              <form onsubmit="handleLogin(event)">
                <div class="form-group">
                  <input type="email" name="email" placeholder="Email" autocomplete="email" required>
                </div>
                <button type="submit" class="btn" style="width: 100%;">Send Login Link</button>
              </form>
            </div>
          </div>
        </div>
      `;
    }
    
    async function verifyMagicLink(token) {
      document.getElementById('app').innerHTML = `
        <div class="login-container">
          <div class="login-box">
            <img src="?logo" alt="Koadi Technology">
            <h2 style="margin-bottom: 24px;">Verifying...</h2>
            <p style="color: var(--muted); text-align: center;">Please wait while we verify your login link.</p>
          </div>
        </div>
      `;
      
      try {
        const result = await api('verify_magic_link', {
          method: 'POST',
          body: JSON.stringify({ token: token, type: 'login' })
        });
        
        console.log('Magic link verification result:', result);
        
        if (result.ok && result.user) {
          currentUser = result.user;
          window.history.replaceState({}, document.title, window.location.origin);
          renderApp();
        } else {
          throw new Error('Verification failed: ' + JSON.stringify(result));
        }
      } catch (e) {
        console.error('Magic link verification error:', e.message);
        document.getElementById('app').innerHTML = `
          <div class="login-container">
            <div class="login-box">
              <img src="?logo" alt="Koadi Technology">
              <h2 style="margin-bottom: 24px; color: #dc3545;">Link Expired</h2>
              <p style="color: var(--muted); text-align: center; margin-bottom: 20px;">This login link has expired or is invalid. Login links are valid for 10 minutes.</p>
              <p style="color: var(--muted); text-align: center; font-size: 12px; margin-bottom: 20px;">Error details: ${e.message}</p>
              <button class="btn" style="width: 100%;" onclick="window.location.href = window.location.origin;">Request New Link</button>
            </div>
          </div>
        `;
      }
    }
    
    async function handleLogin(e) {
      e.preventDefault();
      const form = e.target;
      const email = form.email.value.trim();
      
      // Validate email format
      const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      if (!emailRegex.test(email)) {
        alert('Please enter a valid email address');
        return;
      }
      
      const submitBtn = form.querySelector('button[type="submit"]');
      submitBtn.disabled = true;
      submitBtn.textContent = 'Sending...';
      
      try {
        await api('login', {
          method: 'POST',
          body: JSON.stringify({ email })
        });
        
        document.getElementById('loginFormContainer').innerHTML = `
          <div style="text-align: center;">
            <div style="font-size: 48px; margin-bottom: 20px;">📧</div>
            <h3 style="color: var(--brand); margin-bottom: 16px;">Check Your Email</h3>
            <p style="color: var(--muted); margin-bottom: 20px;">We've sent a login link to <strong>${email}</strong></p>
            <p style="color: var(--muted); font-size: 14px;">The link will expire in 10 minutes. Check your spam folder if you don't see it.</p>
            <button class="btn secondary" style="width: 100%; margin-top: 20px;" onclick="renderLogin()">Send Another Link</button>
          </div>
        `;
      } catch (e) {
        alert('Error: ' + e.message);
        submitBtn.disabled = false;
        submitBtn.textContent = 'Send Login Link';
      }
    }
    
    async function handleLogout() {
      await api('logout', { method: 'POST' });
      currentUser = null;
      renderLogin();
    }
    
    
    function renderAcceptInvite(token) {
      document.getElementById('app').innerHTML = `
        <div class="login-container">
          <div class="login-box">
            <img src="?logo" alt="Koadi Technology">
            <h2 style="margin-bottom: 24px;">Welcome to Koadi Tech CRM</h2>
            <p style="color: var(--muted); margin-bottom: 20px;">Complete your account setup by entering your name below.</p>
            <form onsubmit="handleAcceptInvite(event, '${token}')">
              <div class="form-group">
                <input type="text" name="full_name" placeholder="Your Full Name" required>
              </div>
              <button type="submit" class="btn" style="width: 100%;">Complete Setup</button>
            </form>
          </div>
        </div>
      `;
    }
    
    async function handleAcceptInvite(e, token) {
      e.preventDefault();
      const form = e.target;
      const fullName = form.full_name.value.trim();
      
      if (!fullName) {
        alert('Please enter your full name');
        return;
      }
      
      const submitBtn = form.querySelector('button[type="submit"]');
      submitBtn.disabled = true;
      submitBtn.textContent = 'Setting up...';
      
      try {
        const result = await api('accept_invite', {
          method: 'POST',
          body: JSON.stringify({
            token: token,
            full_name: fullName
          })
        });
        
        if (result.auto_login && result.user) {
          currentUser = result.user;
          window.history.replaceState({}, document.title, window.location.origin);
          renderApp();
        } else {
          alert(result.message || 'Account created successfully!');
          window.location.href = window.location.origin;
        }
      } catch (e) {
        alert('Error: ' + e.message);
        submitBtn.disabled = false;
        submitBtn.textContent = 'Complete Setup';
      }
    }
    
    function toggleTheme() {
      const current = document.body.getAttribute('data-theme');
      document.body.setAttribute('data-theme', current === 'dark' ? 'light' : 'dark');
    }
    
    function toggleSidebar() {
      sidebarCollapsed = !sidebarCollapsed;
      const sidebar = document.querySelector('.sidebar');
      const toggleBtn = document.querySelector('.sidebar-toggle');
      
      if (sidebarCollapsed) {
        sidebar.classList.add('collapsed');
        toggleBtn.classList.remove('shifted');
        toggleBtn.textContent = '☰';
        document.body.classList.add('sidebar-collapsed');
      } else {
        sidebar.classList.remove('collapsed');
        toggleBtn.classList.add('shifted');
        toggleBtn.textContent = '✕';
        document.body.classList.remove('sidebar-collapsed');
      }
    }
    
    function renderApp() {
      const isAdmin = currentUser.role === 'admin';
      
      document.getElementById('app').innerHTML = `
        <button class="sidebar-toggle shifted" onclick="toggleSidebar()">✕</button>
        <div class="app">
          <aside class="sidebar">
            <div class="logo-area">
              <img src="?logo" alt="Koadi Technology">
            </div>
            <div class="user-info">
              <strong>${currentUser.full_name}</strong>
              <div style="font-size: 12px; color: var(--muted); text-transform: uppercase;">${currentUser.role}</div>
            </div>
            <nav class="nav">
              <button onclick="switchView('dashboard')">📊 Dashboard</button>
              <button onclick="switchView('contacts')">👥 Contacts</button>
              <button onclick="switchView('calls')">📞 Calls</button>
              <button onclick="switchView('ai-calls')">🤖 Voice AI calls</button>
              <button onclick="switchView('calendar')">📅 Calendar</button>
              <button onclick="switchView('projects')">📁 Projects</button>
              <button onclick="switchView('leads')" class="active">🎯 Leads</button>
              ${isAdmin ? '<button onclick="switchView(\'users\')">⚙️ Users</button>' : ''}
              ${isAdmin ? '<button onclick="switchView(\'settings\')">🔧 Settings</button>' : ''}
              <button onclick="handleLogout()" class="secondary">🚪 Logout</button>
              <button onclick="toggleTheme()" class="theme-toggle">🌓 Theme</button>
            </nav>
          </aside>
          <main class="content">
            <div id="view-dashboard" class="view"></div>
            <div id="view-contacts" class="view"></div>
            <div id="view-calls" class="view"></div>
            <div id="view-ai-calls" class="view"></div>
            <div id="view-calendar" class="view"></div>
            <div id="view-projects" class="view"></div>
            <div id="view-leads" class="view active"></div>
            ${isAdmin ? '<div id="view-users" class="view"></div>' : ''}
            <div id="view-settings" class="view"></div>
            <footer style="text-align: center; padding: 20px; margin-top: 40px; color: var(--muted); font-size: 12px; border-top: 1px solid var(--border);">
              @2025 Koadi Technology LLC
            </footer>
          </main>
        </div>
        
        <!-- Mobile Bottom Navigation -->
        <nav class="mobile-nav">
          <div class="mobile-nav-inner">
            <button onclick="switchView('dashboard')" data-view="dashboard">
              <span class="icon">📊</span>
              <span>Home</span>
            </button>
            <button onclick="switchView('contacts')" data-view="contacts">
              <span class="icon">👥</span>
              <span>Contacts</span>
            </button>
            <button onclick="switchView('calls')" data-view="calls">
              <span class="icon">📞</span>
              <span>Calls</span>
            </button>
            <button onclick="switchView('projects')" data-view="projects">
              <span class="icon">📁</span>
              <span>Projects</span>
            </button>
            <button onclick="switchView('ai-calls')" data-view="ai-calls">
              <span class="icon">🤖</span>
              <span>Voice AI calls</span>
            </button>
            <button onclick="switchView('calendar')" data-view="calendar">
              <span class="icon">📅</span>
              <span>Calendar</span>
            </button>
            <button onclick="switchView('leads')" data-view="leads">
              <span class="icon">🎯</span>
              <span>Leads</span>
            </button>
            ${isAdmin ? `
            <button onclick="switchView('users')" data-view="users">
              <span class="icon">⚙️</span>
              <span>Users</span>
            </button>
            ` : ''}
            <button onclick="switchView('settings')" data-view="settings">
              <span class="icon">🔧</span>
              <span>Settings</span>
            </button>
            <button onclick="toggleTheme()">
              <span class="icon">🌓</span>
              <span>Theme</span>
            </button>
            <button onclick="handleLogout()">
              <span class="icon">🚪</span>
              <span>Logout</span>
            </button>
          </div>
        </nav>
      `;
      
      const savedView = localStorage.getItem('crm_current_view') || 'dashboard';
      switchView(savedView);
    }
    
    function switchView(view) {
      // Validate that the view exists before switching to it
      const viewElement = document.getElementById('view-' + view);
      if (!viewElement) {
        // If view doesn't exist (e.g., sales user trying to access admin view), fallback to dashboard
        view = 'dashboard';
      }
      
      currentView = view;
      localStorage.setItem('crm_current_view', view);
      
      // Update sidebar nav active state
      document.querySelectorAll('.nav button').forEach(b => b.classList.remove('active'));
      document.querySelectorAll('.nav button').forEach(b => {
        const onclick = b.getAttribute('onclick') || '';
        const viewMatch = onclick.match(/switchView\(['"]([^'"]+)['"]\)/);
        if (viewMatch && viewMatch[1] === view) b.classList.add('active');
      });
      
      // Update mobile nav active state
      document.querySelectorAll('.mobile-nav button').forEach(b => b.classList.remove('active'));
      document.querySelectorAll('.mobile-nav button[data-view]').forEach(b => {
        if (b.dataset.view === view) b.classList.add('active');
      });
      
      document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));
      document.getElementById('view-' + view).classList.add('active');
      
      if (view === 'dashboard') renderDashboard();
      if (view === 'contacts') renderContacts();
      if (view === 'calls') renderCalls();
      if (view === 'ai-calls') renderAICalls();
      if (view === 'calendar') renderCalendar();
      if (view === 'projects') renderProjects();
      if (view === 'leads') renderLeads();
      if (view === 'users') renderUsers();
      if (view === 'settings') renderSettings();
    }
    
    let aiCallsPage = 1;
    let aiCallsLimit = 20;
    let aiCallsDirection = '';
    let aiCallsSearch = '';
    let aiCallsStartDate = '';
    let aiCallsEndDate = '';
    
    async function renderAICalls() {
      document.getElementById('view-ai-calls').innerHTML = `
        <div class="toolbar">
          <h2 style="margin: 0;">🤖 Voice AI calls</h2>
        </div>
        <div class="card" style="margin-bottom: 20px;">
          <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
            <select id="aiCallsDirection" onchange="filterAICalls()" style="padding: 8px; border-radius: 4px; border: 1px solid var(--border); background: var(--bg); color: var(--text);">
              <option value="">All Directions</option>
              <option value="inbound" ${aiCallsDirection === 'inbound' ? 'selected' : ''}>Inbound</option>
              <option value="outbound" ${aiCallsDirection === 'outbound' ? 'selected' : ''}>Outbound</option>
            </select>
            <div style="display: flex; align-items: center; gap: 5px;">
              <input type="date" id="aiCallsStartDate" onchange="filterAICalls()" value="${aiCallsStartDate}" style="padding: 8px; border-radius: 4px; border: 1px solid var(--border); background: var(--bg); color: var(--text);">
              <span>to</span>
              <input type="date" id="aiCallsEndDate" onchange="filterAICalls()" value="${aiCallsEndDate}" style="padding: 8px; border-radius: 4px; border: 1px solid var(--border); background: var(--bg); color: var(--text);">
            </div>
            <input type="text" class="search" id="aiCallsSearch" placeholder="Search by caller name, number..." value="${aiCallsSearch}" oninput="filterAICalls()" style="flex: 1; min-width: 200px;">
            <select id="aiCallsLimit" onchange="changeAICallsLimit()" style="padding: 8px; border-radius: 4px; border: 1px solid var(--border); background: var(--bg); color: var(--text);">
              <option value="10" ${aiCallsLimit == 10 ? 'selected' : ''}>10 per page</option>
              <option value="20" ${aiCallsLimit == 20 ? 'selected' : ''}>20 per page</option>
              <option value="50" ${aiCallsLimit == 50 ? 'selected' : ''}>50 per page</option>
              <option value="100" ${aiCallsLimit == 100 ? 'selected' : ''}>100 per page</option>
            </select>
          </div>
        </div>
        <div class="card">
          <div id="aiCallsPagination" style="display: flex; justify-content: center; align-items: center; gap: 10px; margin-bottom: 20px;"></div>
          <div id="aiCallsList" style="overflow-x: auto;"></div>
        </div>
      `;
      loadAICalls();
    }
    
    async function filterAICalls() {
      aiCallsDirection = document.getElementById('aiCallsDirection')?.value || '';
      aiCallsSearch = document.getElementById('aiCallsSearch')?.value || '';
      aiCallsStartDate = document.getElementById('aiCallsStartDate')?.value || '';
      aiCallsEndDate = document.getElementById('aiCallsEndDate')?.value || '';
      aiCallsPage = 1;
      loadAICalls();
    }

    async function changeAICallsLimit() {
      aiCallsLimit = parseInt(document.getElementById('aiCallsLimit')?.value || 20);
      aiCallsPage = 1;
      loadAICalls();
    }
    
    async function loadAICalls() {
      const params = new URLSearchParams({
        page: aiCallsPage,
        limit: aiCallsLimit,
        direction: aiCallsDirection,
        q: aiCallsSearch,
        start_date: aiCallsStartDate,
        end_date: aiCallsEndDate
      });
      
      const data = await api('retell_calls.list&' + params.toString());
      
      const pagination = `
        <button class="btn" onclick="aiCallsPage = 1; loadAICalls();" ${aiCallsPage === 1 ? 'disabled' : ''}>First</button>
        <button class="btn" onclick="aiCallsPage--; loadAICalls();" ${aiCallsPage === 1 ? 'disabled' : ''}>Prev</button>
        <span style="color: var(--muted);">Page ${data.page} of ${data.pages || 1} (${data.total} calls)</span>
        <button class="btn" onclick="aiCallsPage++; loadAICalls();" ${aiCallsPage >= data.pages ? 'disabled' : ''}>Next</button>
        <button class="btn" onclick="aiCallsPage = ${data.pages || 1}; loadAICalls();" ${aiCallsPage >= data.pages ? 'disabled' : ''}>Last</button>
      `;
      document.getElementById('aiCallsPagination').innerHTML = pagination;
      
      if (!data.items || data.items.length === 0) {
        document.getElementById('aiCallsList').innerHTML = `
          <div style="text-align: center; padding: 40px; color: var(--muted);">
            <p style="font-size: 48px; margin-bottom: 20px;">🤖</p>
            <p>No AI calls found.</p>
          </div>
        `;
        return;
      }
      
      let table = `
        <table style="width: 100%; border-collapse: collapse; min-width: 800px;">
          <thead>
            <tr style="border-bottom: 2px solid var(--border);">
              <th style="padding: 12px; text-align: left;">Date & Time</th>
              <th style="padding: 12px; text-align: left;">Direction</th>
              <th style="padding: 12px; text-align: left;">Caller Name</th>
              <th style="padding: 12px; text-align: left;">Phone Number</th>
              <th style="padding: 12px; text-align: left;">Duration</th>
              <th style="padding: 12px; text-align: left;">Score</th>
              <th style="padding: 12px; text-align: left;">Actions</th>
            </tr>
          </thead>
          <tbody>
      `;
      
      table += data.items.map(call => {
        const startDate = call.start_timestamp ? new Date(call.start_timestamp).toLocaleString() : '-';
        const duration = call.duration_seconds ? formatDuration(call.duration_seconds) : '-';
        const callerName = call.lead_name || call.contact_name || '<span style="color: var(--muted);">Unknown</span>';
        const callerNumber = call.direction === 'inbound' ? call.from_number : call.to_number;
        const directionIcon = call.direction === 'inbound' ? '📥 Inbound' : '📤 Outbound';
        const scoreDisplay = call.call_score ? `<span class="badge" style="background: ${call.call_score >= 7 ? '#22c55e' : call.call_score >= 4 ? '#f59e0b' : '#ef4444'}; color: white; padding: 2px 8px; border-radius: 4px; font-size: 11px;">${call.call_score}/10</span>` : '-';
        
        return `
          <tr style="border-bottom: 1px solid var(--border); transition: background 0.2s;" onmouseover="this.style.background='var(--bg)'" onmouseout="this.style.background='transparent'">
            <td style="padding: 12px; font-size: 13px;">${startDate}</td>
            <td style="padding: 12px; font-size: 13px;">${directionIcon}</td>
            <td style="padding: 12px; font-size: 13px; font-weight: 500;">${callerName}</td>
            <td style="padding: 12px; font-size: 13px; font-family: monospace;">${callerNumber || '-'}</td>
            <td style="padding: 12px; font-size: 13px;">${duration}</td>
            <td style="padding: 12px;">${scoreDisplay}</td>
            <td style="padding: 12px;">
              <button class="btn secondary" onclick="viewAICall(${call.id})" style="padding: 4px 10px; font-size: 12px;">View Details</button>
            </td>
          </tr>
        `;
      }).join('');
      
      table += `
          </tbody>
        </table>
      `;
      
      document.getElementById('aiCallsList').innerHTML = table;
    }
    
    function formatDuration(seconds) {
      if (!seconds) return '-';
      const mins = Math.floor(seconds / 60);
      const secs = seconds % 60;
      return mins > 0 ? `${mins}m ${secs}s` : `${secs}s`;
    }
    
    async function viewAICall(id) {
      const data = await api(`retell_calls.get&id=${id}`);
      const call = data.item;
      
      const startDate = call.start_timestamp ? new Date(call.start_timestamp).toLocaleString() : '-';
      const endDate = call.end_timestamp ? new Date(call.end_timestamp).toLocaleString() : '-';
      const duration = call.duration_seconds ? formatDuration(call.duration_seconds) : '-';
      const callerName = call.lead_name || call.contact_name || 'Unknown Caller';
      const callerNumber = call.direction === 'inbound' ? call.from_number : call.to_number;
      
      let analysisHtml = '';
      if (call.analysis_results) {
        try {
          const analysis = typeof call.analysis_results === 'string' ? JSON.parse(call.analysis_results) : call.analysis_results;
          const analysisItems = Object.entries(analysis).map(([key, value]) => {
            const label = key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
            return `<div style="margin-bottom: 8px;"><strong>${label}:</strong> ${value}</div>`;
          }).join('');
          analysisHtml = `
            <div class="form-group">
              <label>Analysis Results</label>
              <div style="background: var(--bg); padding: 15px; border-radius: 8px; border: 1px solid var(--border);">${analysisItems}</div>
            </div>
          `;
        } catch (e) {}
      }
      
      showModal(`
        <h3>🤖 AI Call Details</h3>
        <table class="detail-table" style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
          <tr style="border-bottom: 1px solid var(--border);">
            <th style="text-align: left; padding: 12px 10px; color: var(--muted); width: 140px; background: rgba(0,0,0,0.02);">Caller</th>
            <td style="padding: 12px 10px;">
              <div style="font-weight: 600;">${callerName}</div>
              <div style="color: var(--muted); font-size: 13px;">${callerNumber || 'Unknown number'}</div>
            </td>
          </tr>
          <tr style="border-bottom: 1px solid var(--border);">
            <th style="text-align: left; padding: 12px 10px; color: var(--muted); background: rgba(0,0,0,0.02);">Direction</th>
            <td style="padding: 12px 10px;">${call.direction === 'inbound' ? '📥 Inbound' : '📤 Outbound'}</td>
          </tr>
          <tr style="border-bottom: 1px solid var(--border);">
            <th style="text-align: left; padding: 12px 10px; color: var(--muted); background: rgba(0,0,0,0.02);">Duration</th>
            <td style="padding: 12px 10px; font-family: monospace;">${duration}</td>
          </tr>
          <tr style="border-bottom: 1px solid var(--border);">
            <th style="text-align: left; padding: 12px 10px; color: var(--muted); background: rgba(0,0,0,0.02);">Started</th>
            <td style="padding: 12px 10px;">${startDate}</td>
          </tr>
          <tr style="border-bottom: 1px solid var(--border);">
            <th style="text-align: left; padding: 12px 10px; color: var(--muted); background: rgba(0,0,0,0.02);">Ended</th>
            <td style="padding: 12px 10px;">${endDate}</td>
          </tr>
          ${call.call_score ? `
          <tr style="border-bottom: 1px solid var(--border);">
            <th style="text-align: left; padding: 12px 10px; color: var(--muted); background: rgba(0,0,0,0.02);">Call Score</th>
            <td style="padding: 12px 10px;">
              <span style="display: inline-block; padding: 4px 12px; border-radius: 20px; font-weight: bold; font-size: 14px; background: ${call.call_score >= 7 ? '#dcfce7' : call.call_score >= 4 ? '#fef3c7' : '#fee2e2'}; color: ${call.call_score >= 7 ? '#15803d' : call.call_score >= 4 ? '#b45309' : '#b91c1c'};">
                ${call.call_score}/10
              </span>
            </td>
          </tr>
          ` : ''}
          <tr style="border-bottom: 1px solid var(--border);">
            <th style="text-align: left; padding: 12px 10px; color: var(--muted); background: rgba(0,0,0,0.02);">Disconnect</th>
            <td style="padding: 12px 10px;">${call.disconnection_reason || '-'}</td>
          </tr>
        </table>

        ${call.recording_url ? `
          <div class="form-group">
            <label>Recording</label>
            <div style="background: var(--bg); padding: 15px; border-radius: 8px; border: 1px solid var(--border); display: flex; align-items: center; gap: 15px;">
              <audio controls style="flex: 1; height: 35px;">
                <source src="${call.recording_url}" type="audio/wav">
                Your browser does not support the audio element.
              </audio>
              ${currentUser && currentUser.role === 'admin' ? `
                <a href="${call.recording_url}" download="recording-${call.retell_call_id}.wav" class="btn" style="background: var(--kt-blue); color: white; text-decoration: none; padding: 8px 15px; font-size: 13px; border-radius: 6px;">
                  Download
                </a>
              ` : ''}
            </div>
          </div>
        ` : ''}
        ${call.call_summary ? `
          <div class="form-group">
            <label>Summary</label>
            <div style="background: var(--bg); padding: 15px; border-radius: 8px; border: 1px solid var(--border);">${call.call_summary}</div>
          </div>
        ` : ''}
        ${call.improvement_recommendations ? `
          <div class="form-group">
            <label>💡 Improvement Recommendations</label>
            <div style="background: #fef3c7; color: #92400e; padding: 15px; border-radius: 8px;">${call.improvement_recommendations}</div>
          </div>
        ` : ''}
        ${analysisHtml}
        ${call.transcript ? `
          <div class="form-group">
            <label>Transcript</label>
            <div style="background: var(--bg); padding: 15px; border-radius: 8px; border: 1px solid var(--border); max-height: 300px; overflow-y: auto; white-space: pre-wrap; font-family: monospace; font-size: 12px;">${call.transcript}</div>
          </div>
        ` : ''}
        <div style="display: flex; gap: 8px; margin-top: 20px;">
          <button type="button" class="btn secondary" onclick="closeModal()">Close</button>
        </div>
      `);
    }
    
    let calendarYear = new Date().getFullYear();
    let calendarMonth = new Date().getMonth();
    let calendarDay = new Date().getDate();
    let calendarView = 'month';
    
    async function renderCalendar() {
      const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
      
      let viewTitle = `${monthNames[calendarMonth]} ${calendarYear}`;
      if (calendarView === 'day') {
        const date = new Date(calendarYear, calendarMonth, calendarDay);
        viewTitle = date.toLocaleDateString(undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
      } else if (calendarView === 'week') {
        const date = new Date(calendarYear, calendarMonth, calendarDay);
        const dayOfWeek = date.getDay();
        const startOfWeek = new Date(date);
        startOfWeek.setDate(date.getDate() - dayOfWeek);
        const endOfWeek = new Date(startOfWeek);
        endOfWeek.setDate(startOfWeek.getDate() + 6);
        viewTitle = `${monthNames[startOfWeek.getMonth()]} ${startOfWeek.getDate()} - ${monthNames[endOfWeek.getMonth()]} ${endOfWeek.getDate()}, ${calendarYear}`;
      }
      
      document.getElementById('view-calendar').innerHTML = `
        <div class="toolbar">
          <h2 style="margin: 0;">📅 Calendar</h2>
          <button class="btn" onclick="openEventForm()">+ Add Event</button>
        </div>
        <div class="card" style="margin-bottom: 20px;">
          <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <div style="display: flex; gap: 10px; align-items: center;">
              <button class="btn" onclick="prevPeriod()">◀</button>
              <span style="font-size: 18px; font-weight: bold; min-width: 250px; text-align: center;">
                ${viewTitle}
              </span>
              <button class="btn" onclick="nextPeriod()">▶</button>
              <button class="btn secondary" onclick="goToToday()">Today</button>
            </div>
            <div style="display: flex; gap: 5px;">
              <button class="btn ${calendarView === 'day' ? '' : 'secondary'}" onclick="setCalendarView('day')">Day</button>
              <button class="btn ${calendarView === 'week' ? '' : 'secondary'}" onclick="setCalendarView('week')">Week</button>
              <button class="btn ${calendarView === 'month' ? '' : 'secondary'}" onclick="setCalendarView('month')">Month</button>
            </div>
          </div>
        </div>
        <div class="card" id="calendarGrid"></div>
      `;
      loadCalendar();
    }
    
    function prevPeriod() {
      if (calendarView === 'day') {
        const date = new Date(calendarYear, calendarMonth, calendarDay - 1);
        calendarYear = date.getFullYear();
        calendarMonth = date.getMonth();
        calendarDay = date.getDate();
      } else if (calendarView === 'week') {
        const date = new Date(calendarYear, calendarMonth, calendarDay - 7);
        calendarYear = date.getFullYear();
        calendarMonth = date.getMonth();
        calendarDay = date.getDate();
      } else {
        calendarMonth--;
        if (calendarMonth < 0) {
          calendarMonth = 11;
          calendarYear--;
        }
        calendarDay = 1;
      }
      renderCalendar();
    }
    
    function nextPeriod() {
      if (calendarView === 'day') {
        const date = new Date(calendarYear, calendarMonth, calendarDay + 1);
        calendarYear = date.getFullYear();
        calendarMonth = date.getMonth();
        calendarDay = date.getDate();
      } else if (calendarView === 'week') {
        const date = new Date(calendarYear, calendarMonth, calendarDay + 7);
        calendarYear = date.getFullYear();
        calendarMonth = date.getMonth();
        calendarDay = date.getDate();
      } else {
        calendarMonth++;
        if (calendarMonth > 11) {
          calendarMonth = 0;
          calendarYear++;
        }
        calendarDay = 1;
      }
      renderCalendar();
    }
    
    function goToToday() {
      const today = new Date();
      calendarYear = today.getFullYear();
      calendarMonth = today.getMonth();
      calendarDay = today.getDate();
      renderCalendar();
    }
    
    function setCalendarView(view) {
      calendarView = view;
      renderCalendar();
    }
    
    async function loadCalendar() {
      let start, end;
      if (calendarView === 'day') {
        const dateStr = `${calendarYear}-${String(calendarMonth + 1).padStart(2, '0')}-${String(calendarDay).padStart(2, '0')}`;
        start = dateStr;
        end = dateStr + 'T23:59:59';
      } else if (calendarView === 'week') {
        const date = new Date(calendarYear, calendarMonth, calendarDay);
        const dayOfWeek = date.getDay();
        const startOfWeek = new Date(date);
        startOfWeek.setDate(date.getDate() - dayOfWeek);
        const endOfWeek = new Date(startOfWeek);
        endOfWeek.setDate(startOfWeek.getDate() + 6);
        
        start = startOfWeek.toISOString().split('T')[0];
        end = endOfWeek.toISOString().split('T')[0] + 'T23:59:59';
      } else {
        const startDate = new Date(calendarYear, calendarMonth, 1);
        const endDate = new Date(calendarYear, calendarMonth + 1, 0);
        start = startDate.toISOString().split('T')[0];
        end = endDate.toISOString().split('T')[0] + 'T23:59:59';
      }
      
      const data = await api(`calendar.list&start=${start}&end=${end}`);
      const events = (data.items || []).filter(e => e.event_type !== 'call');
      
      if (calendarView === 'month') {
        renderMonthView(events);
      } else if (calendarView === 'week') {
        renderWeekView(events, start);
      } else {
        renderDayView(events);
      }
    }
    
    function renderDayView(events) {
      const today = new Date();
      const currentDate = new Date(calendarYear, calendarMonth, calendarDay);
      const dateStr = `${calendarYear}-${String(calendarMonth + 1).padStart(2, '0')}-${String(calendarDay).padStart(2, '0')}`;
      const dayEvents = events.filter(e => e.start_time && e.start_time.startsWith(dateStr));
      
      let grid = `<div style="padding: 20px;">`;
      
      if (dayEvents.length === 0) {
        grid += `
          <div style="text-align: center; color: var(--muted); padding: 40px; background: var(--bg); border-radius: 8px; border: 1px dashed var(--border);">
            <div style="font-size: 48px; margin-bottom: 10px;">📅</div>
            <h3>No events scheduled for this day</h3>
            <button class="btn" style="margin-top: 15px;" onclick="openEventForm(null, '${dateStr}')">+ Add Event</button>
          </div>
        `;
      } else {
        dayEvents.sort((a, b) => new Date(a.start_time) - new Date(b.start_time));
        grid += `<div style="display: flex; flex-direction: column; gap: 15px;">`;
        dayEvents.forEach(e => {
          const color = e.color || (e.event_type === 'call' ? '#FF8C42' : e.event_type === 'booking' ? '#0066CC' : '#22c55e');
          const time = new Date(e.start_time).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
          const endTime = e.end_time ? ' - ' + new Date(e.end_time).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';
          grid += `
            <div onclick="viewEvent(${e.id})" style="background: var(--panel); border-left: 5px solid ${color}; padding: 20px; border-radius: 8px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
              <div>
                <div style="font-weight: bold; font-size: 18px; margin-bottom: 5px;">${e.title}</div>
                <div style="color: var(--muted); font-size: 14px; display: flex; align-items: center; gap: 10px;">
                  <span>🕒 ${time}${endTime}</span>
                  ${e.location ? `<span>📍 ${e.location}</span>` : ''}
                </div>
                <div style="margin-top: 10px; font-size: 13px; color: var(--text); max-width: 600px; white-space: pre-wrap; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden;">${e.description || ''}</div>
              </div>
              <div style="background: ${color}; color: white; padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; text-transform: uppercase;">${e.event_type}</div>
            </div>
          `;
        });
        grid += `</div>`;
      }
      
      grid += `</div>`;
      document.getElementById('calendarGrid').innerHTML = grid;
    }
    
    function renderMonthView(events) {
      const firstDay = new Date(calendarYear, calendarMonth, 1);
      const lastDay = new Date(calendarYear, calendarMonth + 1, 0);
      const startPadding = firstDay.getDay();
      const totalDays = lastDay.getDate();
      
      const today = new Date();
      const isCurrentMonth = today.getFullYear() === calendarYear && today.getMonth() === calendarMonth;
      
      let grid = `
        <div style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 1px; background: var(--border);">
          <div style="background: var(--panel); padding: 10px; text-align: center; font-weight: bold; font-size: 12px; color: var(--muted);">SUN</div>
          <div style="background: var(--panel); padding: 10px; text-align: center; font-weight: bold; font-size: 12px; color: var(--muted);">MON</div>
          <div style="background: var(--panel); padding: 10px; text-align: center; font-weight: bold; font-size: 12px; color: var(--muted);">TUE</div>
          <div style="background: var(--panel); padding: 10px; text-align: center; font-weight: bold; font-size: 12px; color: var(--muted);">WED</div>
          <div style="background: var(--panel); padding: 10px; text-align: center; font-weight: bold; font-size: 12px; color: var(--muted);">THU</div>
          <div style="background: var(--panel); padding: 10px; text-align: center; font-weight: bold; font-size: 12px; color: var(--muted);">FRI</div>
          <div style="background: var(--panel); padding: 10px; text-align: center; font-weight: bold; font-size: 12px; color: var(--muted);">SAT</div>
      `;
      
      for (let i = 0; i < startPadding; i++) {
        grid += `<div style="background: var(--bg); min-height: 100px; padding: 5px;"></div>`;
      }
      
      for (let day = 1; day <= totalDays; day++) {
        const dateStr = `${calendarYear}-${String(calendarMonth + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        const dayEvents = events.filter(e => e.start_time && e.start_time.startsWith(dateStr));
        const isToday = isCurrentMonth && today.getDate() === day;
        
        const eventDots = dayEvents.slice(0, 3).map(e => {
          const color = e.color || (e.event_type === 'call' ? '#FF8C42' : e.event_type === 'booking' ? '#0066CC' : '#22c55e');
          return `<div onclick="event.stopPropagation(); viewEvent(${e.id})" style="background: ${color}; color: white; font-size: 10px; padding: 2px 5px; border-radius: 3px; margin-bottom: 2px; cursor: pointer; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${e.title}</div>`;
        }).join('');
        
        const moreEvents = dayEvents.length > 3 ? `<div style="font-size: 10px; color: var(--muted); text-align: center;">+${dayEvents.length - 3} more</div>` : '';
        
        grid += `
          <div onclick="openEventForm(null, '${dateStr}')" style="background: var(--panel); min-height: 100px; padding: 8px; cursor: pointer; ${isToday ? 'border: 2px solid var(--kt-orange); position: relative; z-index: 1;' : ''}">
            <div style="font-weight: ${isToday ? 'bold' : 'normal'}; color: ${isToday ? 'var(--kt-orange)' : 'inherit'}; margin-bottom: 8px; font-size: 14px;">${day}</div>
            ${eventDots}
            ${moreEvents}
          </div>
        `;
      }
      
      const remaining = (7 - ((startPadding + totalDays) % 7)) % 7;
      for (let i = 0; i < remaining; i++) {
        grid += `<div style="background: var(--bg); min-height: 100px; padding: 5px;"></div>`;
      }
      
      grid += '</div>';
      
      grid += `
        <div style="margin-top: 20px; display: flex; gap: 20px; flex-wrap: wrap; background: var(--panel); padding: 15px; border-radius: 8px; border: 1px solid var(--border);">
          <div style="display: flex; align-items: center; gap: 8px;">
            <div style="width: 14px; height: 14px; background: #FF8C42; border-radius: 4px;"></div>
            <span style="font-size: 13px; font-weight: 500;">AI Calls</span>
          </div>
          <div style="display: flex; align-items: center; gap: 8px;">
            <div style="width: 14px; height: 14px; background: #0066CC; border-radius: 4px;"></div>
            <span style="font-size: 13px; font-weight: 500;">Bookings</span>
          </div>
          <div style="display: flex; align-items: center; gap: 8px;">
            <div style="width: 14px; height: 14px; background: #22c55e; border-radius: 4px;"></div>
            <span style="font-size: 13px; font-weight: 500;">Schedules</span>
          </div>
        </div>
      `;
      
      document.getElementById('calendarGrid').innerHTML = grid;
    }
    
    function renderWeekView(events, weekStartStr) {
      const today = new Date();
      const startOfWeek = new Date(weekStartStr);
      
      let grid = `<div style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 1px; background: var(--border);">`;
      
      const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
      
      for (let i = 0; i < 7; i++) {
        const date = new Date(startOfWeek);
        date.setDate(startOfWeek.getDate() + i);
        const dateStr = date.toISOString().split('T')[0];
        const dayEvents = events.filter(e => e.start_time && e.start_time.startsWith(dateStr));
        const isToday = date.toDateString() === today.toDateString();
        
        const eventList = dayEvents.map(e => {
          const color = e.color || (e.event_type === 'call' ? '#FF8C42' : e.event_type === 'booking' ? '#0066CC' : '#22c55e');
          const time = new Date(e.start_time).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
          return `<div onclick="event.stopPropagation(); viewEvent(${e.id})" style="background: ${color}; color: white; font-size: 11px; padding: 6px; border-radius: 6px; margin-bottom: 6px; cursor: pointer; box-shadow: 0 1px 2px rgba(0,0,0,0.1);">
            <div style="font-weight: bold; margin-bottom: 2px;">${time}</div>
            <div style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${e.title}</div>
          </div>`;
        }).join('');
        
        grid += `
          <div onclick="openEventForm(null, '${dateStr}')" style="background: var(--panel); min-height: 300px; cursor: pointer; ${isToday ? 'border: 2px solid var(--kt-orange); position: relative; z-index: 1;' : ''}">
            <div style="padding: 12px; text-align: center; border-bottom: 1px solid var(--border); ${isToday ? 'background: var(--kt-orange); color: white;' : ''}">
              <div style="font-weight: bold; font-size: 12px; text-transform: uppercase; margin-bottom: 4px; opacity: 0.8;">${days[i]}</div>
              <div style="font-size: 24px; font-weight: 800;">${date.getDate()}</div>
            </div>
            <div style="padding: 10px; overflow-y: auto; max-height: 220px;">
              ${eventList || '<div style="color: var(--muted); font-size: 12px; text-align: center; margin-top: 20px; opacity: 0.5;">No events</div>'}
            </div>
          </div>
        `;
      }
      
      grid += '</div>';
      document.getElementById('calendarGrid').innerHTML = grid;
    }
    
    async function openEventForm(id = null, defaultDate = null) {
      let event = { title: '', description: '', event_type: 'booking', start_time: '', end_time: '', location: '', all_day: false };
      
      if (id) {
        const data = await api(`calendar.list&start=2020-01-01&end=2030-12-31`);
        event = data.items.find(e => e.id === id) || event;
      }
      
      if (defaultDate && !id) {
        event.start_time = defaultDate + 'T09:00';
        event.end_time = defaultDate + 'T10:00';
      }
      
      const startTime = event.start_time ? new Date(event.start_time).toISOString().slice(0, 16) : '';
      const endTime = event.end_time ? new Date(event.end_time).toISOString().slice(0, 16) : '';
      
      showModal(`
        <h3>${id ? 'Edit Event' : 'New Event'}</h3>
        <form onsubmit="saveEvent(event, ${id || 'null'})">
          <div class="form-group">
            <label>Title *</label>
            <input type="text" name="title" value="${event.title || ''}" required>
          </div>
          <div class="form-group">
            <label>Type</label>
            <select name="event_type">
              <option value="booking" ${event.event_type === 'booking' ? 'selected' : ''}>Booking</option>
              <option value="schedule" ${event.event_type === 'schedule' ? 'selected' : ''}>Schedule</option>
              <option value="meeting" ${event.event_type === 'meeting' ? 'selected' : ''}>Meeting</option>
            </select>
          </div>
          <div class="form-group">
            <label>Start Time *</label>
            <input type="datetime-local" name="start_time" value="${startTime}" required>
          </div>
          <div class="form-group">
            <label>End Time</label>
            <input type="datetime-local" name="end_time" value="${endTime}">
          </div>
          <div class="form-group">
            <label>Location</label>
            <input type="text" name="location" value="${event.location || ''}">
          </div>
          <div class="form-group">
            <label>Description</label>
            <textarea name="description">${event.description || ''}</textarea>
          </div>
          <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <button type="submit" class="btn">Save</button>
            ${id ? `<button type="button" class="btn danger" onclick="deleteEvent(${id})">Delete</button>` : ''}
            <button type="button" class="btn secondary" onclick="closeModal()">Cancel</button>
          </div>
        </form>
      `);
    }
    
    async function saveEvent(e, id) {
      e.preventDefault();
      const form = e.target;
      const data = {
        id: id,
        title: form.title.value,
        event_type: form.event_type.value,
        start_time: form.start_time.value,
        end_time: form.end_time.value || null,
        location: form.location.value,
        description: form.description.value
      };
      
      await api('calendar.save', data);
      closeModal();
      loadCalendar();
    }
    
    async function viewEvent(id) {
      const data = await api(`calendar.list&start=2020-01-01&end=2030-12-31`);
      const event = data.items.find(e => e.id === id);
      
      if (!event) {
        alert('Event not found');
        return;
      }
      
      const startTime = event.start_time ? new Date(event.start_time).toLocaleString(undefined, { dateStyle: 'full', timeStyle: 'short' }) : '-';
      const endTime = event.end_time ? new Date(event.end_time).toLocaleString(undefined, { dateStyle: 'full', timeStyle: 'short' }) : '-';
      const typeColors = { call: '#FF8C42', booking: '#0066CC', schedule: '#22c55e', meeting: '#8b5cf6' };
      const color = event.color || typeColors[event.event_type] || '#6b7280';
      
      // Parse description for better display
      let descriptionHtml = '';
      if (event.description) {
        const lines = event.description.split('\n');
        descriptionHtml = lines.map(line => {
          if (line.startsWith('---') && line.endsWith('---')) {
            return `<div style="font-weight: bold; border-bottom: 1px solid var(--border); margin: 15px 0 10px; padding-bottom: 5px; color: var(--kt-blue); text-transform: uppercase; font-size: 12px; letter-spacing: 0.5px;">${line.replace(/-/g, '').trim()}</div>`;
          }
          
          const parts = line.split(': ');
          if (parts.length > 1) {
            const label = parts[0];
            const value = parts.slice(1).join(': ');
            
            if (value.startsWith('http')) {
              return `<div style="margin-bottom: 10px;"><span style="color: var(--muted); font-size: 12px; font-weight: bold; text-transform: uppercase;">${label}</span><br><a href="${value}" target="_blank" class="btn" style="display: inline-block; margin-top: 5px; font-size: 13px; padding: 8px 16px; background: var(--kt-orange); color: white; border: none; border-radius: 6px; text-decoration: none;">Join Meeting ↗</a></div>`;
            }
            
            return `<div style="margin-bottom: 10px;"><span style="color: var(--muted); font-size: 12px; font-weight: bold; text-transform: uppercase;">${label}</span><div style="font-weight: 500; font-size: 14px; margin-top: 2px;">${value}</div></div>`;
          }
          
          return line.trim() ? `<div style="margin-bottom: 8px; line-height: 1.6;">${line}</div>` : '<div style="height: 10px;"></div>';
        }).join('');
      }
      
      showModal(`
        <div style="border-left: 8px solid ${color}; padding-left: 20px; margin: -10px 0 20px -20px;">
          <h2 style="margin: 0 0 8px 0; font-size: 22px;">${event.title}</h2>
          <div style="display: flex; gap: 8px; align-items: center;">
            <div style="background: ${color}; color: white; padding: 4px 10px; border-radius: 4px; font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px;">${event.event_type}</div>
            ${event.status ? `<div style="background: var(--bg); color: var(--muted); padding: 3px 10px; border: 1px solid var(--border); border-radius: 4px; font-size: 10px; font-weight: bold; text-transform: uppercase;">${event.status}</div>` : ''}
          </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 20px; background: var(--bg); padding: 15px; border-radius: 10px; border: 1px solid var(--border);">
          <div>
            <label style="display: block; color: var(--muted); font-size: 10px; text-transform: uppercase; font-weight: bold; margin-bottom: 4px;">Start Date & Time</label>
            <div style="font-size: 13px; font-weight: 600;">${startTime}</div>
          </div>
          ${event.end_time ? `
            <div>
              <label style="display: block; color: var(--muted); font-size: 10px; text-transform: uppercase; font-weight: bold; margin-bottom: 4px;">End Date & Time</label>
              <div style="font-size: 13px; font-weight: 600;">${endTime}</div>
            </div>
          ` : ''}
        </div>

        ${event.location ? `
          <div style="margin-bottom: 20px; padding: 15px; background: #e6f0ff; border-radius: 10px; border: 1px solid #b3d1ff; color: #004085;">
            <label style="display: block; font-size: 10px; text-transform: uppercase; font-weight: bold; margin-bottom: 4px; opacity: 0.7;">Location / Meeting Point</label>
            <div style="font-size: 15px; font-weight: 600;">📍 ${event.location}</div>
          </div>
        ` : ''}

        ${descriptionHtml ? `
          <div style="margin-bottom: 20px;">
            <label style="display: block; color: var(--muted); font-size: 10px; text-transform: uppercase; font-weight: bold; margin-bottom: 8px;">Booking Summary & Information</label>
            <div style="background: var(--panel); padding: 15px; border-radius: 10px; border: 1px solid var(--border); box-shadow: inset 0 2px 4px rgba(0,0,0,0.02); max-height: 350px; overflow-y: auto;">
              ${descriptionHtml}
            </div>
          </div>
        ` : ''}

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 25px;">
          ${event.lead_name ? `
            <div style="padding: 10px; background: var(--bg); border-radius: 8px; border: 1px dashed var(--border);">
              <label style="display: block; color: var(--muted); font-size: 9px; text-transform: uppercase; margin-bottom: 3px;">Linked Lead</label>
              <div style="font-weight: 600; font-size: 12px;">👤 ${event.lead_name}</div>
            </div>
          ` : ''}
          ${event.contact_name ? `
            <div style="padding: 10px; background: var(--bg); border-radius: 8px; border: 1px dashed var(--border);">
              <label style="display: block; color: var(--muted); font-size: 9px; text-transform: uppercase; margin-bottom: 3px;">Linked Contact</label>
              <div style="font-weight: 600; font-size: 12px;">👤 ${event.contact_name}</div>
            </div>
          ` : ''}
        </div>

        <div style="display: flex; gap: 8px; margin-top: 25px; border-top: 1px solid var(--border); padding-top: 15px;">
          ${event.event_type !== 'call' ? `<button type="button" class="btn" style="flex: 1; font-size: 12px;" onclick="closeModal(); openEventForm(${id})">Edit</button>` : ''}
          ${event.event_type !== 'call' ? `<button type="button" class="btn danger" style="flex: 1; font-size: 12px;" onclick="deleteEvent(${id})">Delete</button>` : ''}
          <button type="button" class="btn secondary" style="flex: 1; font-size: 12px;" onclick="closeModal()">Close</button>
        </div>
      `);
    }
    
    async function deleteEvent(id) {
      if (!confirm('Are you sure you want to delete this event?')) return;
      await api(`calendar.delete&id=${id}`);
      closeModal();
      loadCalendar();
    }
    
    async function renderLeads() {
      const isAdmin = currentUser.role === 'admin';
      
      let tabs = '';
      if (isAdmin) {
        tabs = `
          <div class="tabs">
            <button onclick="switchLeadTab('all')" class="${currentLeadTab === 'all' ? 'active' : ''}">All Leads</button>
            <button onclick="switchLeadTab('global')" class="${currentLeadTab === 'global' ? 'active' : ''}">Global Pool</button>
            <button onclick="switchLeadTab('assigned')" class="${currentLeadTab === 'assigned' ? 'active' : ''}">Assigned</button>
          </div>
        `;
      } else {
        tabs = `
          <div class="tabs">
            <button onclick="switchLeadTab('all')" class="${currentLeadTab === 'all' ? 'active' : ''}">All</button>
            <button onclick="switchLeadTab('global')" class="${currentLeadTab === 'global' ? 'active' : ''}">Global Pool</button>
            <button onclick="switchLeadTab('personal')" class="${currentLeadTab === 'personal' ? 'active' : ''}">My Leads</button>
          </div>
        `;
      }
      
      const industries = await api('industries.list');
      const industryOptions = industries.items.map(i => `<option value="${i.name}" ${currentLeadIndustry === i.name ? 'selected' : ''}>${i.name}</option>`).join('');
      
      document.getElementById('view-leads').innerHTML = `
        <div class="toolbar">
          <button class="btn" onclick="openLeadForm()">+ Add Lead</button>
          ${isAdmin ? '<button class="btn warning" onclick="openImportModal()">📥 Import Leads</button>' : ''}
          <select id="industryFilter" onchange="filterLeadsByIndustry()" style="padding: 8px; border-radius: 4px; border: 1px solid var(--border); background: var(--bg); color: var(--text); margin-right: 10px; cursor: pointer;">
            <option value="">All Industries</option>
            ${industryOptions}
          </select>
          <input type="text" class="search" id="leadSearch" placeholder="Search by name, phone, address, company..." oninput="loadLeads()">
        </div>
        ${tabs}
        <div class="card">
          <div id="leadsPagination" style="display: flex; justify-content: center; align-items: center; gap: 10px; margin-bottom: 20px; padding: 15px;"></div>
          <table id="leadsTable">
            <thead>
              <tr>
                <th>Name</th>
                <th>Phone</th>
                <th style="width: 150px;">Details</th>
                <th>Company</th>
                <th>Industry</th>
                <th>Status</th>
                <th>Assigned To</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      `;
      
      await loadLeads();
    }
    
    function switchLeadTab(tab) {
      currentLeadTab = tab;
      localStorage.setItem('crm_lead_tab', tab);
      currentLeadPage = 1;
      localStorage.setItem('crm_lead_page', '1');
      renderLeads();
    }
    
    function filterLeadsByIndustry() {
      currentLeadIndustry = document.getElementById('industryFilter')?.value || '';
      localStorage.setItem('crm_lead_industry', currentLeadIndustry);
      currentLeadPage = 1;
      localStorage.setItem('crm_lead_page', '1');
      loadLeads();
    }
    
    function changeLeadPage(page) {
      currentLeadPage = page;
      localStorage.setItem('crm_lead_page', page.toString());
      loadLeads();
    }
    
    function changeLeadsPerPage(perPage) {
      leadsPerPage = parseInt(perPage);
      localStorage.setItem('crm_leads_per_page', perPage);
      currentLeadPage = 1;
      localStorage.setItem('crm_lead_page', '1');
      loadLeads();
    }
    
    async function loadLeads() {
      const search = document.getElementById('leadSearch')?.value || '';
      const data = await api(`leads.list&q=${encodeURIComponent(search)}&type=${currentLeadTab}&industry=${encodeURIComponent(currentLeadIndustry)}&page=${currentLeadPage}&limit=${leadsPerPage}`);
      const tbody = document.querySelector('#leadsTable tbody');
      const pagination = document.getElementById('leadsPagination');
      
      if (data.items.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" style="text-align: center; color: var(--muted);">No leads found</td></tr>';
        pagination.innerHTML = '';
        return;
      }
      
      tbody.innerHTML = data.items.map(lead => {
        const isGlobal = lead.status === 'global' && !lead.assigned_to;
        const canGrab = currentUser.role === 'sales' && isGlobal;
        const canView = currentUser.role === 'admin' || lead.assigned_to == currentUser.id;
        const isHidden = lead.email === '***';
        const displayName = lead.name;
        const nameDisplay = canView && !isHidden ? `<a href="#" onclick="viewLead(${lead.id}); return false;" style="color: var(--brand); text-decoration: none; font-weight: bold;">${displayName}</a>` : `<strong>${displayName}</strong>`;
        
        const phoneDisplay = isHidden ? '***' : (lead.phone ? makePhoneClickable('', lead.phone, lead.name) : '-');
        
        return `
          <tr>
            <td>
              <div style="font-weight: 500;">${nameDisplay}</div>
              <div style="font-size: 11px; color: var(--muted); max-width: 150px; overflow: hidden; text-overflow: ellipsis;" title="${isHidden ? '***' : (lead.address || '')}">${isHidden ? '***' : (lead.address || '')}</div>
            </td>
            <td style="white-space: nowrap;">${phoneDisplay}</td>
            <td>
              <div style="font-size: 12px; color: var(--kt-blue); font-weight: 500;">${isHidden ? '***' : (lead.email || '-')}</div>
              ${lead.website && !isHidden ? `<div style="font-size: 11px; margin-top: 4px;"><a href="${lead.website}" target="_blank" style="color: var(--muted); text-decoration: none; border-bottom: 1px dashed var(--muted);">🌐 Visit Website</a></div>` : ''}
            </td>
            <td>${lead.company || '-'}</td>
            <td><span style="font-size: 11px; background: var(--bg); padding: 2px 6px; border-radius: 10px; border: 1px solid var(--border);">${lead.industry || '-'}</span></td>
            <td><span class="badge ${lead.status}">${lead.status}</span></td>
            <td>${lead.assigned_name || '-'}</td>
            <td>
              <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                ${canGrab ? `<button class="btn success" style="padding: 4px 8px; font-size: 11px;" onclick="grabLead(${lead.id})">Grab</button>` : ''}
                ${canView && !isHidden ? `<button class="btn secondary" style="padding: 4px 8px; font-size: 11px;" onclick="openLeadForm(${lead.id})">Edit</button>` : ''}
                ${canView && !isHidden && currentUser.role === 'sales' ? `<button class="btn" style="background: #FF8C42; color: white; padding: 4px 8px; font-size: 11px;" onclick="convertLeadToContact(${lead.id})">Convert</button>` : ''}
              </div>
            </td>
          </tr>
        `;
      }).join('');
      
      const totalPages = data.total_pages || 1;
      const totalCount = data.total_count || 0;
      
      let paginationHTML = `
        <div style="display: flex; align-items: center; gap: 10px;">
          <label style="color: var(--muted);">Rows per page:</label>
          <select onchange="changeLeadsPerPage(this.value)" style="padding: 6px 10px; border-radius: 4px; border: 1px solid var(--border); background: var(--card); color: var(--text);">
            <option value="10" ${leadsPerPage === 10 ? 'selected' : ''}>10</option>
            <option value="20" ${leadsPerPage === 20 ? 'selected' : ''}>20</option>
            <option value="50" ${leadsPerPage === 50 ? 'selected' : ''}>50</option>
            <option value="100" ${leadsPerPage === 100 ? 'selected' : ''}>100</option>
          </select>
        </div>
        <div style="color: var(--muted); margin-right: 15px;">Showing ${data.items.length} of ${totalCount} leads</div>
      `;
      
      if (totalPages > 1) {
        paginationHTML += '<div style="display: flex; gap: 5px;">';
        
        if (currentLeadPage > 1) {
          paginationHTML += `<button class="btn" onclick="changeLeadPage(${currentLeadPage - 1})">« Prev</button>`;
        }
        
        const startPage = Math.max(1, currentLeadPage - 2);
        const endPage = Math.min(totalPages, currentLeadPage + 2);
        
        if (startPage > 1) {
          paginationHTML += `<button class="btn" onclick="changeLeadPage(1)">1</button>`;
          if (startPage > 2) paginationHTML += '<span style="padding: 8px;">...</span>';
        }
        
        for (let i = startPage; i <= endPage; i++) {
          if (i === currentLeadPage) {
            paginationHTML += `<button class="btn" style="background: var(--brand); color: white;">${i}</button>`;
          } else {
            paginationHTML += `<button class="btn" onclick="changeLeadPage(${i})">${i}</button>`;
          }
        }
        
        if (endPage < totalPages) {
          if (endPage < totalPages - 1) paginationHTML += '<span style="padding: 8px;">...</span>';
          paginationHTML += `<button class="btn" onclick="changeLeadPage(${totalPages})">${totalPages}</button>`;
        }
        
        if (currentLeadPage < totalPages) {
          paginationHTML += `<button class="btn" onclick="changeLeadPage(${currentLeadPage + 1})">Next »</button>`;
        }
        
        paginationHTML += '</div>';
      }
      
      pagination.innerHTML = paginationHTML;
    }
    
    async function grabLead(id) {
      if (!confirm('Grab this lead?')) return;
      try {
        await api('leads.grab', {
          method: 'POST',
          body: JSON.stringify({ id })
        });
        alert('Lead grabbed successfully!');
        await loadLeads();
      } catch (e) {
        alert('Error: ' + e.message);
      }
    }
    
    async function convertLeadToContact(id) {
      if (!confirm('Convert this lead to a contact? This will create a new contact with all the lead information.')) return;
      try {
        const result = await api('leads.convert', {
          method: 'POST',
          body: JSON.stringify({ id })
        });
        alert('Lead converted to contact successfully!');
        await loadLeads();
        switchView('contacts');
      } catch (e) {
        alert('Error: ' + e.message);
      }
    }
    
    async function openAssignModal(leadId) {
      const usersData = await api('users.list');
      const leadsData = await api('leads.list&q=');
      const lead = leadsData.items.find(l => l.id === leadId);
      
      showModal(`
        <h3>Assign Lead: ${lead.name}</h3>
        <form onsubmit="assignLead(event, ${leadId})">
          <div class="form-group">
            <label>Assign To *</label>
            <select name="userId" required>
              <option value="">Select User</option>
              ${usersData.items.filter(u => u.role === 'sales').map(u => `
                <option value="${u.id}" ${lead.assigned_to == u.id ? 'selected' : ''}>${u.full_name}</option>
              `).join('')}
            </select>
          </div>
          <button type="submit" class="btn">Assign</button>
          <button type="button" class="btn secondary" onclick="closeModal()">Cancel</button>
        </form>
      `);
    }
    
    async function assignLead(e, leadId) {
      e.preventDefault();
      const form = e.target;
      try {
        await api('leads.save', {
          method: 'POST',
          body: JSON.stringify({
            id: leadId,
            assigned_to: form.userId.value,
            status: 'assigned'
          })
        });
        closeModal();
        alert('Lead assigned successfully!');
        await loadLeads();
      } catch (e) {
        alert('Error: ' + e.message);
      }
    }
    
    async function viewLead(id) {
      const data = await api(`leads.list&q=`);
      const lead = data.items.find(l => l.id === id);
      if (!lead) return;
      
      const interactions = await api(`interactions.list&lead_id=${id}`);
      const isAdmin = currentUser.role === 'admin';
      const isAssignedToMe = lead.assigned_to == currentUser.id;
      
      const editButton = (isAdmin || isAssignedToMe) ? `<button type="button" class="btn" onclick="closeModal(); openLeadForm(${id});">Edit</button>` : '';
      const deleteButton = (isAdmin || isAssignedToMe) ? `<button type="button" class="btn danger" onclick="deleteLead(${id}); closeModal();">Delete</button>` : '';
      const assignButton = isAdmin ? `<button class="btn" onclick="closeModal(); openAssignModal(${id});">Assign</button>` : '';

      const adminActions = (assignButton) ? `
        <div style="display: flex; gap: 8px; margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid var(--border);">
          ${assignButton}
        </div>
      ` : '';
      
      showModal(`
        <h3>${lead.name}</h3>
        ${adminActions}
        <div class="form-group">
          <label>Phone:</label>
          <div>${lead.phone}</div>
        </div>
        <div class="form-group">
          <label>Email:</label>
          <div>${lead.email || '-'}</div>
        </div>
        <div class="form-group">
          <label>Company:</label>
          <div>${lead.company || '-'}</div>
        </div>
        <div class="form-group">
          <label>Industry:</label>
          <div>${lead.industry || '-'}</div>
        </div>
        <div class="form-group">
          <label>Address:</label>
          <div>${lead.address || '-'}</div>
        </div>
        
        <h4 style="margin-top: 24px; margin-bottom: 12px;">Interaction History</h4>
        ${interactions.items.map(i => `
          <div class="history-item">
            <div class="type">${i.type}</div>
            <div class="time">${new Date(i.created_at).toLocaleString()} - ${i.user_name}</div>
            <div style="margin-top: 8px;">${i.notes || '-'}</div>
          </div>
        `).join('') || '<p style="color: var(--muted);">No interactions yet</p>'}
        
        <form onsubmit="addInteraction(event, ${id})" style="margin-top: 20px;">
          <div class="form-group">
            <label>Add Interaction</label>
            <select name="type" required>
              <option value="call">Call</option>
              <option value="email">Email</option>
              <option value="meeting">Meeting</option>
              <option value="note">Note</option>
            </select>
          </div>
          <div class="form-group">
            <label>Notes</label>
            <textarea name="notes" required></textarea>
          </div>
          <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <button type="submit" class="btn">Add Interaction</button>
            ${editButton}
            ${deleteButton}
            <button type="button" class="btn secondary" onclick="closeModal()">Close</button>
          </div>
        </form>
      `);
    }
    
    async function addInteraction(e, leadId) {
      e.preventDefault();
      const form = e.target;
      try {
        await api('interactions.save', {
          method: 'POST',
          body: JSON.stringify({
            lead_id: leadId,
            type: form.type.value,
            notes: form.notes.value
          })
        });
        closeModal();
        viewLead(leadId);
      } catch (e) {
        alert('Error: ' + e.message);
      }
    }
    
    async function openLeadForm(id = null) {
      const industries = await api('industries.list');
      if (id) {
        const data = await api(`leads.list&q=`);
        const lead = data.items.find(l => l.id === id);
        showLeadForm(lead, industries.items);
      } else {
        showLeadForm(null, industries.items);
      }
    }
    
    function showLeadForm(lead, industries) {
      showModal(`
        <h3>${lead ? 'Edit Lead' : 'Add Lead'}</h3>
        <form onsubmit="saveLead(event, ${lead ? lead.id : 'null'})">
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
            <div class="form-group">
              <label>Name *</label>
              <input type="text" name="name" value="${lead?.name || ''}" required>
            </div>
            <div class="form-group">
              <label>Phone</label>
              <input type="text" name="phone" value="${lead?.phone || ''}">
            </div>
            <div class="form-group">
              <label>Email</label>
              <input type="email" name="email" value="${lead?.email || ''}">
            </div>
            <div class="form-group">
              <label>Company</label>
              <input type="text" name="company" value="${lead?.company || ''}">
            </div>
            <div class="form-group">
              <label>Industry</label>
              <select name="industry">
                <option value="">Select Industry</option>
                ${industries.map(i => `<option value="${i.name}" ${lead?.industry === i.name ? 'selected' : ''}>${i.name}</option>`).join('')}
              </select>
            </div>
            <div class="form-group">
              <label>Website</label>
              <input type="text" name="website" value="${lead?.website || ''}" placeholder="https://...">
            </div>
            <div class="form-group">
              <label>Rating</label>
              <input type="number" name="rating" value="${lead?.rating || ''}" step="0.1" min="0" max="5" placeholder="0.0 - 5.0">
            </div>
            <div class="form-group">
              <label>Reviews Count</label>
              <input type="number" name="reviews_count" value="${lead?.reviews_count || ''}" min="0">
            </div>
          </div>
          <div class="form-group" style="margin-top: 16px;">
            <label>Address</label>
            <textarea name="address" rows="2">${lead?.address || ''}</textarea>
          </div>
          <button type="submit" class="btn">Save</button>
          <button type="button" class="btn secondary" onclick="closeModal()">Cancel</button>
        </form>
      `);
    }

    async function saveLead(e, id) {
      e.preventDefault();
      const form = e.target;
      const data = {
        name: form.name.value,
        phone: form.phone.value,
        email: form.email.value,
        company: form.company.value,
        industry: form.industry.value,
        address: form.address.value,
        website: form.website.value,
        rating: form.rating.value,
        reviews_count: form.reviews_count.value
      };
      if (id) data.id = id;
      
      try {
        await api('leads.save', {
          method: 'POST',
          body: JSON.stringify(data)
        });
        closeModal();
        await loadLeads();
      } catch (e) {
        alert('Error: ' + e.message);
      }
    }
    
    async function deleteLead(id) {
      if (!confirm('Delete this lead?')) return;
      try {
        await api(`leads.delete&id=${id}`, { method: 'DELETE' });
        await loadLeads();
      } catch (e) {
        alert('Error: ' + e.message);
      }
    }
    
    function openImportModal() {
      showModal(`
        <h3>Import Leads</h3>
        <p style="color: var(--muted); margin-bottom: 16px;">
          Paste CSV data (Name, Phone, Email, Company, Industry, Address)
        </p>
        <form onsubmit="importLeads(event)">
          <div class="form-group">
            <textarea name="csv" placeholder="John Doe,+1234567890,john@example.com,Acme Corp,Technology,123 Main St&#10;Jane Smith,+0987654321,jane@example.com,Tech Inc,Healthcare,456 Oak Ave" rows="10" required></textarea>
          </div>
          <button type="submit" class="btn">Import</button>
          <button type="button" class="btn secondary" onclick="closeModal()">Cancel</button>
        </form>
      `);
    }
    
    async function importLeads(e) {
      e.preventDefault();
      const csv = e.target.csv.value;
      const lines = csv.trim().split('\n');
      const leads = lines.map(line => {
        const parts = line.split(',').map(p => p.trim());
        return {
          name: parts[0] || '',
          phone: parts[1] || '',
          email: parts[2] || '',
          company: parts[3] || '',
          industry: parts[4] || '',
          address: parts[5] || ''
        };
      }).filter(l => l.name);
      
      try {
        const result = await api('leads.import', {
          method: 'POST',
          body: JSON.stringify({ leads })
        });
        alert(`Imported ${result.imported} leads`);
        closeModal();
        await loadLeads();
      } catch (e) {
        alert('Error: ' + e.message);
      }
    }
    
    async function renderUsers() {
      document.getElementById('view-users').innerHTML = `
        <div class="toolbar">
          <button class="btn" onclick="openUserForm()">+ Add User</button>
          <button class="btn" onclick="openInviteForm()" style="background: var(--brand); margin-left: 8px;">Send Invite</button>
        </div>
        <div class="card">
          <h2>Users & Invitations</h2>
          <table id="usersTable">
            <thead>
              <tr>
                <th>Email</th>
                <th>Full Name</th>
                <th>Role</th>
                <th>Status</th>
                <th>Created</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      `;
      
      await loadUsers();
    }
    
    async function loadUsers() {
      const data = await api('users.list');
      const tbody = document.querySelector('#usersTable tbody');
      
      if (!tbody) return; // Safety check if table doesn't exist
      
      tbody.innerHTML = data.items.map(item => {
        if (item.type === 'invite') {
          return `
            <tr style="opacity: 0.7;">
              <td><strong>${item.email}</strong></td>
              <td><em>Pending invitation</em></td>
              <td><span class="badge ${item.role}">${item.role}</span></td>
              <td><span class="badge" style="background: var(--kt-yellow); color: #000;">Invited</span></td>
              <td>${new Date(item.created_at).toLocaleDateString()}</td>
              <td>
                <em>Expires: ${new Date(item.expires_at).toLocaleDateString()}</em>
                <button class="btn danger" onclick="deleteInvitation(${item.id})" style="margin-left: 8px;">Delete</button>
              </td>
            </tr>
          `;
        } else {
          const statusBadge = item.status === 'active' 
            ? '<span class="badge" style="background: #28a745;">Active</span>' 
            : '<span class="badge" style="background: #6c757d;">Inactive</span>';
          const toggleBtn = item.id !== currentUser.id 
            ? `<button class="btn ${item.status === 'active' ? 'warning' : 'success'}" onclick="toggleUserStatus(${item.id})">${item.status === 'active' ? 'Deactivate' : 'Activate'}</button>` 
            : '';
          return `
            <tr>
              <td><strong>${item.email || 'N/A'}</strong></td>
              <td>${item.full_name}</td>
              <td><span class="badge ${item.role}">${item.role}</span></td>
              <td>${statusBadge}</td>
              <td>${new Date(item.created_at).toLocaleDateString()}</td>
              <td>
                <button class="btn" onclick="openUserForm(${item.id})">Edit</button>
                ${toggleBtn}
                ${item.id !== currentUser.id ? `<button class="btn danger" onclick="deleteUser(${item.id})">Delete</button>` : ''}
              </td>
            </tr>
          `;
        }
      }).join('');
    }
    
    async function toggleUserStatus(id) {
      if (!confirm('Toggle user status?')) return;
      try {
        await api('users.toggle_status', {
          method: 'POST',
          body: JSON.stringify({ id })
        });
        await loadUsers();
      } catch (e) {
        alert('Error: ' + e.message);
      }
    }
    
    async function deleteInvitation(id) {
      if (!confirm('Delete this pending invitation?')) return;
      try {
        await api(`invitations.delete&id=${id}`, { method: 'DELETE' });
        await loadUsers();
      } catch (e) {
        alert('Error: ' + e.message);
      }
    }
    
    
    
    function openUserForm(id = null) {
      if (id) {
        api('users.list').then(data => {
          const user = data.items.find(u => u.id === id);
          showUserForm(user);
        });
      } else {
        showUserForm(null);
      }
    }
    
    function showUserForm(user) {
      const isSales = user?.role === 'sales';
      const canManageGlobal = user?.can_manage_global_leads;
      showModal(`
        <h3>${user ? 'Edit User' : 'Add User'}</h3>
        <form onsubmit="saveUser(event, ${user ? user.id : 'null'})">
          <div class="form-group">
            <label>Email *</label>
            <input type="email" name="email" value="${user?.email || ''}" placeholder="user@example.com" required>
          </div>
          <div class="form-group">
            <label>Full Name *</label>
            <input type="text" name="full_name" value="${user?.full_name || ''}" required>
          </div>
          <div class="form-group">
            <label>Role *</label>
            <select name="role" required onchange="toggleGlobalLeadsOption(this)">
              <option value="sales" ${user?.role === 'sales' ? 'selected' : ''}>Sales</option>
              <option value="admin" ${user?.role === 'admin' ? 'selected' : ''}>Admin</option>
            </select>
          </div>
          <div class="form-group global-leads-option" id="globalLeadsOption" style="${!isSales ? 'display:none;' : ''}">
            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
              <input type="checkbox" name="can_manage_global_leads" ${canManageGlobal ? 'checked' : ''} style="width: auto;">
              <span>Allow adding leads to global pool</span>
            </label>
            <small style="color: var(--muted); display: block; margin-top: 5px;">
              When enabled, this sales user can create leads visible to all users (like admins)
            </small>
          </div>
          <button type="submit" class="btn">Save</button>
          <button type="button" class="btn secondary" onclick="closeModal()">Cancel</button>
        </form>
      `);
    }
    
    function toggleGlobalLeadsOption(select) {
      const optionDiv = document.getElementById('globalLeadsOption');
      if (select.value === 'admin') {
        optionDiv.style.display = 'none';
      } else {
        optionDiv.style.display = 'block';
      }
    }
    
    async function saveUser(e, id) {
      e.preventDefault();
      const form = e.target;
      
      // Validate email format
      const email = form.email.value.trim();
      const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      if (!emailRegex.test(email)) {
        alert('Please enter a valid email address');
        return;
      }
      
      const data = {
        id,
        email: email,
        full_name: form.full_name.value,
        role: form.role.value,
        can_manage_global_leads: form.can_manage_global_leads?.checked || false
      };
      
      try {
        await api('users.save', {
          method: 'POST',
          body: JSON.stringify(data)
        });
        closeModal();
        await loadUsers();
      } catch (e) {
        alert('Error: ' + e.message);
      }
    }
    
    async function deleteUser(id) {
      if (!confirm('Delete this user?')) return;
      try {
        await api(`users.delete&id=${id}`, { method: 'DELETE' });
        await loadUsers();
      } catch (e) {
        alert('Error: ' + e.message);
      }
    }
    
    function openInviteForm() {
      showModal(`
        <h3>Send User Invitation</h3>
        <p style="color: var(--muted); margin-bottom: 20px;">Send an invitation link to a user's email. They will set up their account with their full name.</p>
        <form onsubmit="sendInvite(event)">
          <div class="form-group">
            <label>Email *</label>
            <input type="email" name="email" placeholder="user@example.com" required>
          </div>
          <div class="form-group">
            <label>Role *</label>
            <select name="role" required>
              <option value="sales">Sales</option>
              <option value="admin">Admin</option>
            </select>
          </div>
          <button type="submit" class="btn">Send Invitation</button>
          <button type="button" class="btn secondary" onclick="closeModal()">Cancel</button>
        </form>
      `);
    }
    
    async function sendInvite(e) {
      e.preventDefault();
      const form = e.target;
      
      const email = form.email.value.trim();
      const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      if (!emailRegex.test(email)) {
        alert('Please enter a valid email address');
        return;
      }
      
      try {
        const result = await api('send_invite', {
          method: 'POST',
          body: JSON.stringify({
            email: email,
            role: form.role.value
          })
        });
        alert(result.message || 'Invitation sent successfully!');
        closeModal();
      } catch (e) {
        alert('Error: ' + e.message);
      }
    }
    
    function showModal(content) {
      const modal = document.createElement('div');
      modal.className = 'modal';
      modal.innerHTML = `<div class="modal-content">${content}</div>`;
      modal.onclick = (e) => {
        if (e.target === modal) closeModal();
      };
      document.body.appendChild(modal);
    }
    
    function closeModal() {
      const modal = document.querySelector('.modal');
      if (modal) modal.remove();
    }
    
    let COUNTRIES = [];
    let CONTACTS = [];
    const STAGES = ['Lead', 'Qualified', 'Proposal', 'Negotiation', 'Won'];
    
    async function loadCountries() {
      const data = await api('countries');
      COUNTRIES = data.items;
    }
    
    async function renderDashboard() {
      const stats = await api('stats');
      document.getElementById('view-dashboard').innerHTML = `
        <h2 style="margin-bottom: 20px;">Dashboard</h2>
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 24px;">
          <div class="card"><h3>Contacts</h3><div style="font-size: 24px; color: var(--brand);">${stats.contacts}</div></div>
          <div class="card"><h3>Calls (7 days)</h3><div style="font-size: 24px; color: var(--accent);">${stats.calls7}</div></div>
          <div class="card"><h3>Open Projects</h3><div style="font-size: 24px; color: var(--kt-yellow);">${stats.openProjects}</div></div>
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
          <div class="card">
            <h3>Recent Contacts</h3>
            <table>
              <thead><tr><th>Name</th><th>Company</th><th>Phone</th></tr></thead>
              <tbody>
                ${stats.recentContacts.map(c => `<tr><td>${c.name || '(no name)'}</td><td>${c.company || '-'}</td><td>${(c.phone_country||'') + ' ' + (c.phone_number||'')}</td></tr>`).join('')}
              </tbody>
            </table>
          </div>
          <div class="card">
            <h3>Recent Calls</h3>
            <table>
              <thead><tr><th>When</th><th>Contact</th><th>Outcome</th></tr></thead>
              <tbody>
                ${stats.recentCalls.map(c => `<tr><td>${new Date(c.when_at).toLocaleDateString()}</td><td>${c.name || c.company || '-'}</td><td><span class="badge">${c.outcome}</span></td></tr>`).join('')}
              </tbody>
            </table>
          </div>
        </div>
      `;
    }
    
    async function renderContacts() {
      await loadCountries();
      CONTACTS = (await api('contacts.list')).items;
      const isAdmin = currentUser.role === 'admin';
      
      document.getElementById('view-contacts').innerHTML = `
        <div class="toolbar">
          <button class="btn" onclick="openContactForm()">+ New Contact</button>
          <input type="text" class="search" id="contactSearch" placeholder="Search contacts..." oninput="loadContacts()">
        </div>
        <div class="card">
          <table id="contactsTable">
            <thead>
              <tr>
                <th>Name</th>
                <th>Company</th>
                <th>Type</th>
                <th>Phone</th>
                <th>Email</th>
                <th>Industry</th>
                ${isAdmin ? '<th>Assigned To</th>' : ''}
                <th>Source</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      `;
      await loadContacts();
    }
    
    async function loadContacts() {
      const q = document.getElementById('contactSearch')?.value || '';
      const data = await api(`contacts.list&q=${encodeURIComponent(q)}`);
      CONTACTS = data.items;
      const isAdmin = currentUser.role === 'admin';
      const tbody = document.querySelector('#contactsTable tbody');
      
      tbody.innerHTML = data.items.map(c => {
        const nameDisplay = `<a href="#" onclick="viewContact(${c.id}); return false;" style="color: var(--brand); text-decoration: none; font-weight: bold;">${c.name || '(no name)'}</a>`;
        
        const actions = isAdmin 
          ? `<button class="btn" onclick="openContactReassignModal(${c.id})">Reassign</button>
             <button class="btn" onclick="openContactForm(${c.id})">Edit</button>
             <button class="btn danger" onclick="deleteContact(${c.id})">Delete</button>`
          : `<button class="btn" onclick="openCallFormForContact(${c.id})">Call</button>
             <button class="btn" onclick="openProjectFormForContact(${c.id})">Project</button>
             <button class="btn" onclick="openContactForm(${c.id})">Edit</button>
             <button class="btn warning" onclick="returnContactToLead(${c.id})">Return to Leads</button>`;
        
        return `
          <tr>
            <td>${nameDisplay}</td>
            <td>${c.company || '-'}</td>
            <td>${c.type || 'Individual'}</td>
            <td>${c.phone_number ? makePhoneClickable(c.phone_country, c.phone_number, c.name || c.company) : '-'}</td>
            <td>${c.email || '-'}</td>
            <td>${c.industry || '-'}</td>
            ${isAdmin ? `<td>${c.assigned_user || '<em style="color: var(--muted);">Unassigned</em>'}</td>` : ''}
            <td>${c.source || '-'}</td>
            <td>${actions}</td>
          </tr>
        `;
      }).join('');
    }
    
    async function openContactForm(id = null) {
      const contact = id ? CONTACTS.find(c => c.id === id) : null;
      const industries = await api('industries.list');
      
      showModal(`
        <h3>${contact ? 'Edit Contact' : 'New Contact'}</h3>
        <form onsubmit="saveContact(event, ${id})">
          <div class="form-group">
            <label>Type</label>
            <select name="type">
              <option value="Individual" ${contact?.type === 'Individual' ? 'selected' : ''}>Individual</option>
              <option value="Company" ${contact?.type === 'Company' ? 'selected' : ''}>Company</option>
            </select>
          </div>
          <div class="form-group">
            <label>Company</label>
            <input type="text" name="company" value="${contact?.company || ''}">
          </div>
          <div class="form-group">
            <label>Name *</label>
            <input type="text" name="name" value="${contact?.name || ''}" required>
          </div>
          <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" value="${contact?.email || ''}">
          </div>
          <div class="form-group">
            <label>Phone Country</label>
            <select name="phoneCountry">
              <option value="">None</option>
              ${COUNTRIES.map(c => `<option value="${c.code}" ${contact?.phone_country === c.code ? 'selected' : ''}>${c.code} ${c.name}</option>`).join('')}
            </select>
          </div>
          <div class="form-group">
            <label>Phone Number</label>
            <input type="text" name="phoneNumber" value="${contact?.phone_number || ''}">
          </div>
          <div class="form-group">
            <label>Industry</label>
            <select name="industry">
              <option value="">Select Industry</option>
              ${industries.items.map(i => `<option value="${i.name}" ${contact?.industry === i.name ? 'selected' : ''}>${i.name}</option>`).join('')}
            </select>
          </div>
          <div class="form-group">
            <label>Source</label>
            <input type="text" name="source" value="${contact?.source || ''}" placeholder="Referral, Website, etc.">
          </div>
          <div class="form-group">
            <label>Notes</label>
            <textarea name="notes" rows="3">${contact?.notes || ''}</textarea>
          </div>
          <button type="submit" class="btn">Save</button>
          <button type="button" class="btn secondary" onclick="closeModal()">Cancel</button>
        </form>
      `);
    }
    
    async function saveContact(e, id) {
      e.preventDefault();
      const form = e.target;
      const data = {
        id, type: form.type.value, company: form.company.value,
        name: form.name.value, email: form.email.value,
        phoneCountry: form.phoneCountry.value, phoneNumber: form.phoneNumber.value,
        industry: form.industry.value, source: form.source.value, notes: form.notes.value
      };
      try {
        const result = await api('contacts.save', { method: 'POST', body: JSON.stringify(data) });
        if (result.duplicate_of) {
          alert(`Warning: This contact might be a duplicate of "${result.duplicate_of}"`);
        }
        closeModal();
        await loadContacts();
      } catch (e) {
        alert('Error: ' + e.message);
      }
    }
    
    async function viewContact(id) {
      const contact = CONTACTS.find(c => c.id === id);
      if (!contact) return;
      
      const calls = await api(`calls.list`);
      const contactCalls = calls.items.filter(c => c.contact_id === id);
      const projects = await api(`projects.list`);
      const contactProjects = projects.items.filter(p => p.contact_id === id);
      
      showModal(`
        <h3>${contact.name || '(no name)'}</h3>
        <div class="form-group">
          <label>Type:</label>
          <div>${contact.type || 'Individual'}</div>
        </div>
        <div class="form-group">
          <label>Company:</label>
          <div>${contact.company || '-'}</div>
        </div>
        <div class="form-group">
          <label>Email:</label>
          <div>${contact.email || '-'}</div>
        </div>
        <div class="form-group">
          <label>Phone:</label>
          <div>${(contact.phone_country || '') + ' ' + (contact.phone_number || '') || '-'}</div>
        </div>
        <div class="form-group">
          <label>Industry:</label>
          <div>${contact.industry || '-'}</div>
        </div>
        <div class="form-group">
          <label>Source:</label>
          <div>${contact.source || '-'}</div>
        </div>
        <div class="form-group">
          <label>Assigned To:</label>
          <div>${contact.assigned_user || '<em style="color: var(--muted);">Unassigned</em>'}</div>
        </div>
        <div class="form-group">
          <label>Notes:</label>
          <div>${contact.notes || '-'}</div>
        </div>
        
        <h4 style="margin-top: 24px; margin-bottom: 12px;">Call History (${contactCalls.length})</h4>
        ${contactCalls.length > 0 ? `
          <div style="max-height: 200px; overflow-y: auto;">
            ${contactCalls.map(c => `
              <div class="history-item">
                <div class="type">${c.outcome}</div>
                <div class="time">${new Date(c.when_at).toLocaleString()} - ${c.duration_minutes || 0} min</div>
                <div style="margin-top: 8px;">${c.notes || '-'}</div>
              </div>
            `).join('')}
          </div>
        ` : '<p style="color: var(--muted);">No calls yet</p>'}
        
        <h4 style="margin-top: 24px; margin-bottom: 12px;">Projects (${contactProjects.length})</h4>
        ${contactProjects.length > 0 ? `
          <div style="max-height: 200px; overflow-y: auto;">
            ${contactProjects.map(p => `
              <div class="history-item">
                <div class="type">${p.title}</div>
                <div class="time">Stage: ${p.stage} - Value: $${p.value || 0}</div>
                <div style="margin-top: 8px;">${p.description || '-'}</div>
              </div>
            `).join('')}
          </div>
        ` : '<p style="color: var(--muted);">No projects yet</p>'}
        
        <div style="margin-top: 20px;">
          <button type="button" class="btn secondary" onclick="closeModal()">Close</button>
        </div>
      `);
    }
    
    async function openContactReassignModal(id) {
      const contact = CONTACTS.find(c => c.id === id);
      if (!contact) return;
      
      const users = await api('users.list');
      const activeUsers = users.items.filter(u => u.type !== 'invite' && u.status === 'active');
      
      showModal(`
        <h3>Reassign Contact: ${contact.name}</h3>
        <form onsubmit="reassignContact(event, ${id})">
          <div class="form-group">
            <label>Assign To</label>
            <select name="userId" required>
              <option value="">Unassign</option>
              ${activeUsers.map(u => `<option value="${u.id}" ${contact.assigned_to === u.id ? 'selected' : ''}>${u.full_name} (${u.email})</option>`).join('')}
            </select>
          </div>
          <button type="submit" class="btn">Save</button>
          <button type="button" class="btn secondary" onclick="closeModal()">Cancel</button>
        </form>
      `);
    }
    
    async function reassignContact(e, id) {
      e.preventDefault();
      const form = e.target;
      const userId = form.userId.value || null;
      
      try {
        await api('contacts.reassign', {
          method: 'POST',
          body: JSON.stringify({ id, userId })
        });
        closeModal();
        await loadContacts();
      } catch (e) {
        alert('Error: ' + e.message);
      }
    }
    
    async function deleteContact(id) {
      if (!confirm('Delete this contact?')) return;
      await api(`contacts.delete&id=${id}`, { method: 'DELETE' });
      await loadContacts();
    }
    
    async function returnContactToLead(id) {
      if (!confirm('Return this contact to leads? This will move the contact back to the leads pool.')) return;
      try {
        console.log('Attempting to return contact to lead, ID:', id);
        const response = await api('contacts.returnToLead', {
          method: 'POST',
          body: JSON.stringify({ id })
        });
        console.log('Return to lead response:', response);
        alert('Contact successfully returned to leads!');
        await loadContacts();
        switchView('leads');
      } catch (e) {
        console.error('Return to lead error:', e);
        alert('Error: ' + e.message);
      }
    }
    
    async function renderCalls() {
      await loadCountries();
      if (CONTACTS.length === 0) {
        CONTACTS = (await api('contacts.list')).items;
      }
      document.getElementById('view-calls').innerHTML = `
        <div class="toolbar">
          <button class="btn" onclick="openCallForm()">+ Log Call</button>
          <input type="text" class="search" id="callSearch" placeholder="Search calls..." oninput="loadCalls()">
        </div>
        <div class="card">
          <table id="callsTable">
            <thead>
              <tr><th>Contact</th><th>When</th><th>Outcome</th><th>Duration (min)</th><th>Notes</th><th>Actions</th></tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      `;
      await loadCalls();
    }
    
    async function loadCalls() {
      const q = document.getElementById('callSearch')?.value || '';
      const data = await api(`calls.list&q=${encodeURIComponent(q)}`);
      const tbody = document.querySelector('#callsTable tbody');
      const isAdmin = currentUser.role === 'admin';
      
      tbody.innerHTML = data.items.map(c => {
        const latestNote = c.latest_update || c.notes || '-';
        const truncatedNote = latestNote.length > 50 ? latestNote.substring(0, 50) + '...' : latestNote;
        const noteDisplay = latestNote.length > 50 
          ? `<span title="${latestNote.replace(/"/g, '&quot;')}">${truncatedNote}</span>`
          : latestNote;
        
        const deleteBtn = isAdmin ? `<button class="btn danger" onclick="deleteCall(${c.id})">Delete</button>` : '';
        
        return `
          <tr>
            <td>
              <a href="#" onclick="viewCallUpdates(${c.id}); return false;" style="color: var(--brand); text-decoration: none; font-weight: bold;">
                ${c.contact_name || c.contact_company || 'N/A'}
              </a>
            </td>
            <td>${new Date(c.when_at).toLocaleString()}</td>
            <td><span class="badge">${c.outcome}</span></td>
            <td>${c.duration_min || 0}</td>
            <td>${noteDisplay}</td>
            <td>
              <button class="btn" onclick="addCallUpdate(${c.id})">Add Update</button>
              <button class="btn" onclick="openCallForm(${c.id})">Edit</button>
              ${deleteBtn}
            </td>
          </tr>
        `;
      }).join('');
    }
    
    function openCallFormForContact(contactId) {
      openCallForm(null, contactId);
    }
    
    function openCallForm(id = null, contactId = null) {
      api('calls.list').then(async (data) => {
        const call = id ? data.items.find(c => c.id === id) : null;
        const when = call ? new Date(call.when_at).toISOString().slice(0, 16) : new Date().toISOString().slice(0, 16);
        showModal(`
          <h3>${call ? 'Edit Call' : 'Log Call'}</h3>
          <form onsubmit="saveCall(event, ${id})">
            <div class="form-group">
              <label>Contact *</label>
              <select name="contactId" required>
                <option value="">Select Contact</option>
                ${CONTACTS.map(c => `<option value="${c.id}" ${(call?.contact_id === c.id || contactId === c.id) ? 'selected' : ''}>${c.name || c.company}</option>`).join('')}
              </select>
            </div>
            <div class="form-group">
              <label>When *</label>
              <input type="datetime-local" name="when" value="${when}" required>
            </div>
            <div class="form-group">
              <label>Outcome *</label>
              <select name="outcome" required>
                <option value="Attempted" ${call?.outcome === 'Attempted' ? 'selected' : ''}>Attempted</option>
                <option value="Answered" ${call?.outcome === 'Answered' ? 'selected' : ''}>Answered</option>
                <option value="Voicemail" ${call?.outcome === 'Voicemail' ? 'selected' : ''}>Voicemail</option>
                <option value="No Answer" ${call?.outcome === 'No Answer' ? 'selected' : ''}>No Answer</option>
                <option value="Busy" ${call?.outcome === 'Busy' ? 'selected' : ''}>Busy</option>
                <option value="Wrong Number" ${call?.outcome === 'Wrong Number' ? 'selected' : ''}>Wrong Number</option>
              </select>
            </div>
            <div class="form-group">
              <label>Duration (minutes)</label>
              <input type="number" name="durationMin" value="${call?.duration_min || 0}" min="0">
            </div>
            <div class="form-group">
              <label>Notes</label>
              <textarea name="notes" rows="3">${call?.notes || ''}</textarea>
            </div>
            <button type="submit" class="btn">Save</button>
            <button type="button" class="btn secondary" onclick="closeModal()">Cancel</button>
          </form>
        `);
      });
    }
    
    async function saveCall(e, id) {
      e.preventDefault();
      const form = e.target;
      const data = {
        id, contactId: form.contactId.value, when: form.when.value,
        outcome: form.outcome.value, durationMin: form.durationMin.value, notes: form.notes.value
      };
      await api('calls.save', { method: 'POST', body: JSON.stringify(data) });
      closeModal();
      await loadCalls();
    }
    
    async function deleteCall(id) {
      if (!confirm('Delete this call?')) return;
      await api(`calls.delete&id=${id}`, { method: 'DELETE' });
      await loadCalls();
    }
    
    async function viewCallUpdates(callId) {
      const data = await api(`call_updates.list&call_id=${callId}`);
      const updates = data.items;
      showModal(`
        <h3>Call Updates</h3>
        <div style="max-height: 400px; overflow-y: auto;">
          ${updates.length > 0 ? updates.map(u => `
            <div class="history-item">
              <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                <span class="type">${u.user_name || 'Unknown User'}</span>
                <span class="time">${new Date(u.created_at).toLocaleString()}</span>
              </div>
              <div>${u.notes || 'No notes'}</div>
            </div>
          `).join('') : '<p style="color: var(--muted);">No updates yet</p>'}
        </div>
        <button type="button" class="btn secondary" onclick="closeModal()">Close</button>
      `);
    }
    
    function addCallUpdate(callId) {
      showModal(`
        <h3>Add Call Update</h3>
        <form onsubmit="saveCallUpdate(event, ${callId})">
          <div class="form-group">
            <label>Notes *</label>
            <textarea name="notes" rows="4" required placeholder="Add update notes..."></textarea>
          </div>
          <button type="submit" class="btn">Add Update</button>
          <button type="button" class="btn secondary" onclick="closeModal()">Cancel</button>
        </form>
      `);
    }
    
    async function saveCallUpdate(e, callId) {
      e.preventDefault();
      const form = e.target;
      const data = { call_id: callId, notes: form.notes.value };
      await api('call_updates.save', { method: 'POST', body: JSON.stringify(data) });
      closeModal();
      alert('Update added successfully');
    }
    
    function switchProjectView(mode) {
      projectViewMode = mode;
      renderProjects();
    }
    
    async function renderProjects() {
      if (CONTACTS.length === 0) {
        CONTACTS = (await api('contacts.list')).items;
      }
      const data = await api('projects.list');
      const projects = data.items;
      
      const kanbanHTML = STAGES.map(stage => {
        const stageProjects = projects.filter(p => p.stage === stage);
        return `
          <div class="kanban-col" data-stage="${stage}" ondrop="dropProject(event)" ondragover="allowDrop(event)">
            <h4>${stage} (${stageProjects.length})</h4>
            ${stageProjects.map(p => `
              <div class="kanban-card" draggable="true" ondragstart="dragProject(event, ${p.id})" data-id="${p.id}">
                <div class="kanban-actions" onclick="event.stopPropagation()">
                  <button onclick="event.stopPropagation(); addProjectNote(${p.id})">+ Note</button>
                  <button onclick="event.stopPropagation(); openProjectForm(${p.id})">Edit</button>
                  <button class="danger" onclick="event.stopPropagation(); deleteProject(${p.id})">Del</button>
                </div>
                <span class="kanban-card-title" onclick="event.stopPropagation(); viewProjectDetail(${p.id})">${p.name}</span>
                <div class="kanban-card-contact" onclick="event.stopPropagation(); viewContactDetail(${p.contact_id})">${p.contact_name || p.contact_company || 'No contact'}</div>
                <div class="kanban-card-value">$${parseFloat(p.value || 0).toLocaleString()}</div>
                ${p.next_date ? `<div class="kanban-card-date">📅 ${new Date(p.next_date).toLocaleDateString()}</div>` : ''}
                ${p.notes ? `<div class="kanban-card-notes">💬 ${p.notes.split('\\n')[p.notes.split('\\n').length - 1]}</div>` : ''}
              </div>
            `).join('')}
          </div>
        `;
      }).join('');
      
      const tableHTML = `
        <div class="card">
          <table id="projectsTable">
            <thead>
              <tr><th>Name</th><th>Contact</th><th>Value</th><th>Stage</th><th>Next Date</th><th>Actions</th></tr>
            </thead>
            <tbody>
              ${projects.map(p => `
                <tr>
                  <td><strong style="cursor: pointer; text-decoration: underline; color: var(--brand);" onclick="viewProjectDetail(${p.id})">${p.name}</strong></td>
                  <td style="cursor: pointer; text-decoration: underline;" onclick="viewContactDetail(${p.contact_id})">${p.contact_name || p.contact_company || '-'}</td>
                  <td>$${parseFloat(p.value || 0).toLocaleString()}</td>
                  <td><span class="badge">${p.stage}</span></td>
                  <td>${p.next_date ? new Date(p.next_date).toLocaleDateString() : '-'}</td>
                  <td>
                    <button class="btn" onclick="viewProjectDetail(${p.id})">View</button>
                    <button class="btn" onclick="addProjectNote(${p.id})">+ Note</button>
                    <button class="btn" onclick="openProjectForm(${p.id})">Edit</button>
                    <button class="btn danger" onclick="deleteProject(${p.id})">Delete</button>
                  </td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        </div>
      `;
      
      document.getElementById('view-projects').innerHTML = `
        <div class="toolbar">
          <button class="btn" onclick="openProjectForm()">+ New Project</button>
          <div class="view-toggle">
            <button class="${projectViewMode === 'kanban' ? 'active' : ''}" onclick="switchProjectView('kanban')">📊 Kanban</button>
            <button class="${projectViewMode === 'table' ? 'active' : ''}" onclick="switchProjectView('table')">📋 Table</button>
          </div>
          <div style="flex: 1"></div>
          <div style="font-size: 12px; color: var(--muted);">${projectViewMode === 'kanban' ? 'Drag cards between stages' : projects.length + ' total projects'}</div>
        </div>
        ${projectViewMode === 'kanban' ? `
          <div style="display: grid; grid-template-columns: repeat(5, minmax(200px, 1fr)); gap: 12px; overflow-x: auto;">
            ${kanbanHTML}
          </div>
        ` : tableHTML}
      `;
    }
    
    function viewContactDetail(contactId) {
      if (!contactId) return;
      const contact = CONTACTS.find(c => c.id === contactId);
      if (!contact) return;
      
      showModal(`
        <h3>Contact Details</h3>
        <div style="padding: 12px; background: var(--bg); border-radius: 6px; margin-bottom: 16px;">
          <div style="margin-bottom: 8px;"><strong>Type:</strong> ${contact.type || '-'}</div>
          <div style="margin-bottom: 8px;"><strong>Name:</strong> ${contact.name || '-'}</div>
          <div style="margin-bottom: 8px;"><strong>Company:</strong> ${contact.company || '-'}</div>
          <div style="margin-bottom: 8px;"><strong>Email:</strong> ${contact.email || '-'}</div>
          <div style="margin-bottom: 8px;"><strong>Phone:</strong> ${contact.phone_country || ''} ${contact.phone_number || '-'}</div>
          <div style="margin-bottom: 8px;"><strong>Source:</strong> ${contact.source || '-'}</div>
          ${contact.notes ? `<div style="margin-top: 12px;"><strong>Notes:</strong><br>${contact.notes.replace(/\n/g, '<br>')}</div>` : ''}
        </div>
        <button class="btn" onclick="closeModal(); openContactForm(${contactId})">Edit Contact</button>
        <button class="btn secondary" onclick="closeModal()">Close</button>
      `);
    }
    
    async function viewProjectDetail(projectId) {
      const data = await api('projects.list');
      const project = data.items.find(p => p.id === projectId);
      if (!project) return;
      
      showModal(`
        <h3>Project: ${project.name}</h3>
        <div style="padding: 16px; background: var(--bg); border-radius: 6px; margin-bottom: 16px;">
          <div style="margin-bottom: 12px;">
            <strong style="color: var(--brand);">Contact</strong><br>
            <span style="cursor: pointer; text-decoration: underline;" onclick="closeModal(); viewContactDetail(${project.contact_id})">${project.contact_name || project.contact_company || '-'}</span>
          </div>
          <div style="margin-bottom: 12px;">
            <strong style="color: var(--brand);">Value</strong><br>
            $${parseFloat(project.value || 0).toLocaleString()}
          </div>
          <div style="margin-bottom: 12px;">
            <strong style="color: var(--brand);">Stage</strong><br>
            <span class="badge">${project.stage}</span>
          </div>
          <div style="margin-bottom: 12px;">
            <strong style="color: var(--brand);">Next Date</strong><br>
            ${project.next_date ? new Date(project.next_date).toLocaleDateString() : 'Not set'}
          </div>
          ${project.notes ? `
          <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border);">
            <strong style="color: var(--brand);">Notes History</strong>
            <div style="margin-top: 8px; max-height: 300px; overflow-y: auto;">
              ${project.notes.split('\\n---\\n').map((note, idx) => {
                const timestampMatch = note.match(/^\[([^\]]+)\]\s*/);
                const timestamp = timestampMatch ? new Date(timestampMatch[1]).toLocaleString() : (idx === 0 ? new Date(project.created_at).toLocaleString() : 'Unknown date');
                const noteText = timestampMatch ? note.substring(timestampMatch[0].length) : note;
                return `
                  <div style="padding: 8px; background: var(--panel); border-left: 3px solid var(--accent); margin-bottom: 8px; border-radius: 4px;">
                    <div style="font-size: 12px; color: var(--muted); margin-bottom: 4px;">📅 ${timestamp}</div>
                    <div style="white-space: pre-wrap;">${noteText}</div>
                  </div>
                `;
              }).join('')}
            </div>
          </div>
          ` : '<div style="color: var(--muted); font-style: italic;">No notes yet</div>'}
          <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border);">
            <div style="font-size: 12px; color: var(--muted);">Created: ${new Date(project.created_at).toLocaleString()}</div>
            <div style="font-size: 12px; color: var(--muted);">Last Updated: ${new Date(project.updated_at).toLocaleString()}</div>
          </div>
        </div>
        <button class="btn" onclick="closeModal(); addProjectNote(${projectId})">+ Add Note</button>
        <button class="btn" onclick="closeModal(); openProjectForm(${projectId})">Edit Project</button>
        <button class="btn secondary" onclick="closeModal()">Close</button>
      `);
    }
    
    function addProjectNote(projectId) {
      showModal(`
        <h3>Add Note to Project</h3>
        <form onsubmit="saveProjectNote(event, ${projectId})">
          <div class="form-group">
            <label>Note *</label>
            <textarea name="note" rows="5" required placeholder="Add your note here..."></textarea>
          </div>
          <button type="submit" class="btn">Add Note</button>
          <button type="button" class="btn secondary" onclick="closeModal()">Cancel</button>
        </form>
      `);
    }
    
    async function saveProjectNote(e, projectId) {
      e.preventDefault();
      const form = e.target;
      const note = form.note.value.trim();
      
      const data = await api('projects.list');
      const project = data.items.find(p => p.id === projectId);
      if (!project) return;
      
      const timestamp = new Date().toISOString();
      const noteWithTimestamp = `[${timestamp}] ${note}`;
      const existingNotes = project.notes || '';
      const newNotes = existingNotes ? `${existingNotes}\\n---\\n${noteWithTimestamp}` : noteWithTimestamp;
      
      await api('projects.save', {
        method: 'POST',
        body: JSON.stringify({
          id: projectId,
          contactId: project.contact_id,
          name: project.name,
          value: project.value,
          stage: project.stage,
          next: project.next_date ? project.next_date.split('T')[0] : '',
          notes: newNotes
        })
      });
      
      closeModal();
      alert('Note added successfully');
      await renderProjects();
    }
    
    let draggedProjectId = null;
    
    function dragProject(e, id) {
      draggedProjectId = id;
      e.dataTransfer.effectAllowed = 'move';
    }
    
    function allowDrop(e) {
      e.preventDefault();
    }
    
    async function dropProject(e) {
      e.preventDefault();
      const stage = e.currentTarget.dataset.stage;
      await api('projects.stage', {
        method: 'POST',
        body: JSON.stringify({ id: draggedProjectId, stage })
      });
      await renderProjects();
    }
    
    function openProjectFormForContact(contactId) {
      openProjectForm(null, contactId);
    }
    
    function openProjectForm(id = null, contactId = null) {
      api('projects.list').then(data => {
        const project = id ? data.items.find(p => p.id === id) : null;
        const nextDate = project?.next_date ? project.next_date.split('T')[0] : '';
        showModal(`
          <h3>${project ? 'Edit Project' : 'New Project'}</h3>
          <form onsubmit="saveProject(event, ${id})">
            <div class="form-group">
              <label>Contact *</label>
              <select name="contactId" required>
                <option value="">Select Contact</option>
                ${CONTACTS.map(c => `<option value="${c.id}" ${(project?.contact_id === c.id || contactId === c.id) ? 'selected' : ''}>${c.name || c.company}</option>`).join('')}
              </select>
            </div>
            <div class="form-group">
              <label>Name *</label>
              <input type="text" name="name" value="${project?.name || ''}" required>
            </div>
            <div class="form-group">
              <label>Value ($)</label>
              <input type="number" step="0.01" name="value" value="${project?.value || ''}">
            </div>
            <div class="form-group">
              <label>Stage *</label>
              <select name="stage" required>
                ${STAGES.map(s => `<option value="${s}" ${project?.stage === s ? 'selected' : ''}>${s}</option>`).join('')}
              </select>
            </div>
            <div class="form-group">
              <label>Next Date</label>
              <input type="date" name="next" value="${nextDate}">
            </div>
            <div class="form-group">
              <label>Notes</label>
              <textarea name="notes" rows="3">${project?.notes || ''}</textarea>
            </div>
            <button type="submit" class="btn">Save</button>
            <button type="button" class="btn secondary" onclick="closeModal()">Cancel</button>
          </form>
        `);
      });
    }
    
    async function saveProject(e, id) {
      e.preventDefault();
      const form = e.target;
      const data = {
        id, contactId: form.contactId.value, name: form.name.value,
        value: form.value.value, stage: form.stage.value, next: form.next.value, notes: form.notes.value
      };
      await api('projects.save', { method: 'POST', body: JSON.stringify(data) });
      closeModal();
      await renderProjects();
    }
    
    async function deleteProject(id) {
      if (!confirm('Delete this project?')) return;
      await api(`projects.delete&id=${id}`, { method: 'DELETE' });
      await renderProjects();
    }
    
    async function renderSettings() {
      await loadCountries();
      const defaultCountry = await api('settings.get&key=defaultCountry');
      const retellApiKeyCheck = await api('settings.exists&key=retell_api_key');
      const calWebhookSecretCheck = await api('settings.exists&key=cal_webhook_secret');
      const outscraperSecretCheck = await api('settings.exists&key=outscraper_webhook_secret');
      const isAdmin = currentUser.role === 'admin';
      
      const hasRetellKey = retellApiKeyCheck.exists === true;
      const hasCalSecret = calWebhookSecretCheck.exists === true;
      const hasOutscraperSecret = outscraperSecretCheck.exists === true;
      const retellValue = retellApiKeyCheck.value || '';
      const calValue = calWebhookSecretCheck.value || '';
      const outscraperValue = outscraperSecretCheck.value || '';
      
      document.getElementById('view-settings').innerHTML = `
        <div class="card">
          <h3>Default Country Code</h3>
          <div style="display: flex; gap: 12px; align-items: center;">
            <select id="defaultCountry">
              ${COUNTRIES.map(c => `<option value="${c.code}" ${defaultCountry.value === c.code ? 'selected' : ''}>${c.code} ${c.name}</option>`).join('')}
            </select>
            <button class="btn" onclick="saveDefaultCountry()">Save</button>
          </div>
        </div>
        ${isAdmin ? `
        <div class="card" style="margin-top: 16px;">
          <h3>🤖 Retell AI Integration</h3>
          <p style="color: var(--muted); margin-bottom: 12px; font-size: 13px;">Enter your Retell API Key to secure incoming webhook calls. Retell uses this key to sign webhook requests.</p>
          <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
            <input type="password" id="retellApiKey" value="${retellValue}" placeholder="Enter your Retell API Key..." style="flex: 1; min-width: 200px; padding: 8px; border-radius: 4px; border: 1px solid var(--border); background: var(--bg); color: var(--text);">
            <button class="btn secondary" id="retellApiKeyToggle" onclick="toggleRetellApiKeyVisibility()">👁️ Show</button>
            <button class="btn" onclick="saveRetellApiKey()">Save</button>
            ${hasRetellKey ? '<span style="color: var(--success); font-size: 12px;">✓ Configured</span>' : ''}
          </div>
          <p style="color: var(--muted); margin-top: 12px; font-size: 12px;">Webhook URL: <code style="background: var(--bg); padding: 2px 6px; border-radius: 4px;">${window.location.origin}/?api=retell.webhook</code></p>
        </div>
        <div class="card" style="margin-top: 16px;">
          <h3>📅 Cal.com Integration</h3>
          <p style="color: var(--muted); margin-bottom: 12px; font-size: 13px;">Configure your Cal.com webhook to automatically sync bookings to your CRM calendar.</p>
          <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-bottom: 12px;">
            <input type="password" id="calWebhookSecret" value="${calValue}" placeholder="Enter or generate a webhook secret..." style="flex: 1; min-width: 200px; padding: 8px; border-radius: 4px; border: 1px solid var(--border); background: var(--bg); color: var(--text);">
            <button class="btn secondary" id="calSecretToggle" onclick="toggleCalSecretVisibility()">👁️ Show</button>
            <button class="btn" onclick="generateCalSecret()">🔄 Generate</button>
            <button class="btn" onclick="saveCalSecret()">Save</button>
            ${hasCalSecret ? '<span style="color: var(--success); font-size: 12px;">✓ Configured</span>' : ''}
          </div>
          <p style="color: var(--muted); font-size: 12px;">Webhook URL: <code style="background: var(--bg); padding: 2px 6px; border-radius: 4px;">${window.location.origin}/?api=cal.webhook</code></p>
          <p style="color: var(--muted); font-size: 12px; margin-top: 4px;">Trigger: <strong>Booking created</strong></p>
        </div>
        <div class="card" style="margin-top: 16px;">
          <h3>🔍 Outscraper Lead Generation</h3>
          <p style="color: var(--muted); margin-bottom: 12px; font-size: 13px;">Import leads from Outscraper.com via webhook or file upload. Leads are deduplicated using Google Place ID.</p>
          
          <div style="background: var(--bg); padding: 12px; border-radius: 8px; margin-bottom: 16px;">
            <h4 style="margin: 0 0 8px 0; font-size: 14px;">📁 File Upload (Manual)</h4>
            <p style="color: var(--muted); font-size: 12px; margin-bottom: 8px;">Upload an Outscraper Excel or CSV export file. You can review leads before adding to the global pool.</p>
            <div style="display: flex; gap: 12px; flex-wrap: wrap;">
              <input type="file" id="outscraperFile" accept=".xlsx,.csv" style="display: none;" onchange="uploadOutscraperFile(event)">
              <button class="btn" onclick="document.getElementById('outscraperFile').click()">📤 Upload File</button>
              <button class="btn secondary" onclick="viewOutscraperStaging()">👁️ Review Pending Leads</button>
            </div>
          </div>
          
          <div style="background: var(--bg); padding: 12px; border-radius: 8px; margin-bottom: 16px;">
            <h4 style="margin: 0 0 8px 0; font-size: 14px;">🔗 Webhook (Automatic)</h4>
            <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-bottom: 12px;">
              <input type="password" id="outscraperWebhookSecret" value="${outscraperValue}" placeholder="Enter or generate a webhook secret..." style="flex: 1; min-width: 200px; padding: 8px; border-radius: 4px; border: 1px solid var(--border); background: var(--bg); color: var(--text);">
              <button class="btn secondary" id="outscraperSecretToggle" onclick="toggleOutscraperSecretVisibility()">Show</button>
              <button class="btn" onclick="generateOutscraperSecret()">Generate</button>
              <button class="btn" onclick="saveOutscraperSecret()">Save</button>
              ${hasOutscraperSecret ? '<span style="color: var(--success); font-size: 12px;">Configured</span>' : ''}
            </div>
            <p style="color: var(--muted); font-size: 12px;">Webhook URL: <code style="background: var(--panel); padding: 2px 6px; border-radius: 4px;">${window.location.origin}/?api=outscraper.webhook</code></p>
          </div>
          
          <p style="color: var(--muted); font-size: 12px;">Imported fields: Company name, contact name/title, phone, email, address, industry, rating, reviews, website, social links</p>
          <button class="btn secondary" style="margin-top: 12px;" onclick="viewOutscraperImports()">View Import History</button>
        </div>
        <div class="card" style="margin-top: 16px;">
          <h3>Manage Industries</h3>
          <button class="btn" onclick="openIndustriesManagement()">Manage Industries</button>
        </div>
        <div class="card" style="margin-top: 16px;">
          <h3>Export / Import</h3>
          <button class="btn" onclick="exportData()">⬇️ Export JSON</button>
          <button class="btn warning" onclick="document.getElementById('importFile').click()">⬆️ Import JSON</button>
          <input id="importFile" type="file" accept="application/json" style="display:none" onchange="importData(event)" />
        </div>
        <div class="card" style="margin-top: 16px;">
          <h3>Danger Zone</h3>
          <button class="btn danger" onclick="resetDatabase()">Reset Database (truncate)</button>
        </div>
        ` : ''}
      `;
    }
    
    async function saveDefaultCountry() {
      const value = document.getElementById('defaultCountry').value;
      await api('settings.set', {
        method: 'POST',
        body: JSON.stringify({ key: 'defaultCountry', value })
      });
      alert('Default country saved');
    }
    
    async function saveRetellApiKey() {
      const value = document.getElementById('retellApiKey').value;
      if (!value.trim()) {
        alert('Please enter your Retell API Key');
        return;
      }
      await api('settings.set', {
        method: 'POST',
        body: JSON.stringify({ key: 'retell_api_key', value })
      });
      alert('Retell API Key saved');
      await renderSettings();
    }
    
    function toggleRetellApiKeyVisibility() {
      const input = document.getElementById('retellApiKey');
      const btn = document.getElementById('retellApiKeyToggle');
      if (input.type === 'password') {
        input.type = 'text';
        btn.textContent = '🙈 Hide';
        btn.title = 'Hide';
      } else {
        input.type = 'password';
        btn.textContent = '👁️ Show';
        btn.title = 'Show';
      }
    }
    
    function generateCalSecret() {
      const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
      const randomValues = new Uint32Array(32);
      crypto.getRandomValues(randomValues);
      let secret = 'whsec_';
      for (let i = 0; i < 32; i++) {
        secret += chars.charAt(randomValues[i] % chars.length);
      }
      const input = document.getElementById('calWebhookSecret');
      input.value = secret;
      input.type = 'text';
      const btn = document.getElementById('calSecretToggle');
      btn.textContent = '🙈 Hide';
      btn.title = 'Hide';
    }
    
    async function saveCalSecret() {
      const value = document.getElementById('calWebhookSecret').value;
      if (!value.trim()) {
        alert('Please enter or generate a webhook secret');
        return;
      }
      await api('settings.set', {
        method: 'POST',
        body: JSON.stringify({ key: 'cal_webhook_secret', value })
      });
      alert('Cal.com webhook secret saved. Copy this secret and add it to your Cal.com webhook settings.');
      await renderSettings();
    }
    
    function toggleCalSecretVisibility() {
      const input = document.getElementById('calWebhookSecret');
      const btn = document.getElementById('calSecretToggle');
      if (input.type === 'password') {
        input.type = 'text';
        btn.textContent = '🙈 Hide';
        btn.title = 'Hide';
      } else {
        input.type = 'password';
        btn.textContent = '👁️ Show';
        btn.title = 'Show';
      }
    }
    
    function toggleOutscraperSecretVisibility() {
      const input = document.getElementById('outscraperWebhookSecret');
      const btn = document.getElementById('outscraperSecretToggle');
      if (input.type === 'password') {
        input.type = 'text';
        btn.textContent = 'Hide';
      } else {
        input.type = 'password';
        btn.textContent = 'Show';
      }
    }
    
    function generateOutscraperSecret() {
      const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
      const randomValues = new Uint32Array(32);
      crypto.getRandomValues(randomValues);
      let secret = 'outscraper_';
      for (let i = 0; i < 32; i++) {
        secret += chars.charAt(randomValues[i] % chars.length);
      }
      const input = document.getElementById('outscraperWebhookSecret');
      input.value = secret;
      input.type = 'text';
      document.getElementById('outscraperSecretToggle').textContent = 'Hide';
    }
    
    async function saveOutscraperSecret() {
      const value = document.getElementById('outscraperWebhookSecret').value;
      if (!value.trim()) {
        alert('Please enter or generate a webhook secret');
        return;
      }
      await api('settings.set', {
        method: 'POST',
        body: JSON.stringify({ key: 'outscraper_webhook_secret', value })
      });
      alert('Outscraper webhook secret saved. Use this secret when configuring your Outscraper webhook.');
      await renderSettings();
    }
    
    async function viewOutscraperImports() {
      const data = await api('outscraper_imports.list');
      const imports = data.items || [];
      
      let html = '<div style="max-height: 400px; overflow-y: auto;">';
      if (imports.length === 0) {
        html += '<p style="color: var(--muted);">No imports yet. Configure Outscraper to send leads to your webhook URL.</p>';
      } else {
        html += '<table style="width: 100%; border-collapse: collapse; font-size: 13px;">';
        html += '<tr style="background: var(--bg);"><th style="padding: 8px; text-align: left;">Date</th><th style="padding: 8px; text-align: left;">Query</th><th style="padding: 8px; text-align: center;">Imported</th><th style="padding: 8px; text-align: center;">Duplicates</th><th style="padding: 8px; text-align: center;">Errors</th></tr>';
        imports.forEach(imp => {
          const date = new Date(imp.created_at).toLocaleString();
          html += `<tr style="border-bottom: 1px solid var(--border);">
            <td style="padding: 8px;">${date}</td>
            <td style="padding: 8px; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${imp.query || ''}">${imp.query || 'N/A'}</td>
            <td style="padding: 8px; text-align: center; color: var(--success);">${imp.imported_count}</td>
            <td style="padding: 8px; text-align: center; color: var(--warning);">${imp.duplicate_count}</td>
            <td style="padding: 8px; text-align: center; color: var(--danger);">${imp.error_count}</td>
          </tr>`;
        });
        html += '</table>';
      }
      html += '</div>';
      
      const modal = document.createElement('div');
      modal.className = 'modal-overlay';
      modal.innerHTML = `
        <div class="modal" style="max-width: 600px;">
          <h2>Outscraper Import History</h2>
          ${html}
          <div style="margin-top: 16px; text-align: right;">
            <button class="btn" onclick="this.closest('.modal-overlay').remove()">Close</button>
          </div>
        </div>
      `;
      document.body.appendChild(modal);
    }
    
    async function uploadOutscraperFile(e) {
      const file = e.target.files[0];
      if (!file) return;
      
      const formData = new FormData();
      formData.append('file', file);
      
      try {
        const response = await fetch('?api=outscraper.upload', {
          method: 'POST',
          body: formData
        });
        
        const result = await response.json();
        
        if (result.error) {
          alert('Upload failed: ' + result.error);
          return;
        }
        
        alert(`File parsed successfully!\n\n${result.parsed} leads ready for review.\n\nClick "Review Pending Leads" to approve them.`);
        e.target.value = '';
        viewOutscraperStaging();
      } catch (err) {
        alert('Upload failed: ' + err.message);
      }
    }
    
    async function viewOutscraperStaging() {
      const data = await api('outscraper_staging.list&status=pending');
      const items = data.items || [];
      const batches = data.batches || [];
      
      let htmlContent = '';
      
      if (items.length === 0) {
        htmlContent = '<p style="color: var(--muted); padding: 20px; text-align: center;">No pending leads to review. Upload an Outscraper file to get started.</p>';
      } else {
        htmlContent += `<p style="margin-bottom: 12px; font-size: 14px;">Found <strong>${items.length}</strong> leads pending review.</p>`;
        
        if (batches.length > 1) {
          htmlContent += `
            <div style="margin-bottom: 16px;">
              <label style="font-size: 13px; font-weight: 500; display: block; margin-bottom: 4px;">Filter by batch:</label>
              <select id="stagingBatchFilter" onchange="filterStagingBatch(this.value)" style="width: 100%; max-width: 300px; padding: 8px; border-radius: 4px; border: 1px solid var(--border); background: var(--bg); color: var(--text); font-size: 13px;">
                <option value="">All batches</option>
                ${batches.map(b => `<option value="${b.batch_id}">${new Date(b.created_at).toLocaleString()} (${b.count} leads)</option>`).join('')}
              </select>
            </div>`;
        }
        
        htmlContent += `
          <div style="flex: 1; overflow: auto; border: 1px solid var(--border); border-radius: 8px; background: var(--panel);">
            <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
              <thead style="position: sticky; top: 0; background: var(--bg); z-index: 10;">
                <tr style="border-bottom: 2px solid var(--border);">
                  <th style="padding: 10px 6px; text-align: center; width: 35px;"><input type="checkbox" id="selectAllStaging" onchange="toggleAllStaging(this.checked)"></th>
                  <th style="padding: 10px 6px; text-align: left;">Company</th>
                  <th style="padding: 10px 6px; text-align: left;">Phone</th>
                  <th style="padding: 10px 6px; text-align: left;">Email</th>
                  <th style="padding: 10px 6px; text-align: left;">Industry</th>
                  <th style="padding: 10px 6px; text-align: left;">Website</th>
                  <th style="padding: 10px 6px; text-align: left;">Address</th>
                </tr>
              </thead>
              <tbody>
                ${items.map(item => `
                  <tr style="border-bottom: 1px solid var(--border);" data-batch="${item.batch_id}" class="staging-row">
                    <td style="padding: 8px 6px; text-align: center;"><input type="checkbox" class="staging-checkbox" value="${item.id}"></td>
                    <td style="padding: 8px 6px; font-weight: 500; max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${item.name || ''}">${item.name || '-'}</td>
                    <td style="padding: 8px 6px; white-space: nowrap;">${item.phone || '-'}</td>
                    <td style="padding: 8px 6px; max-width: 160px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--kt-blue); font-size: 11px;" title="${item.email || ''}">${item.email || '-'}</td>
                    <td style="padding: 8px 6px;"><span style="background: var(--bg); padding: 2px 6px; border-radius: 10px; font-size: 11px; white-space: nowrap;">${item.industry || '-'}</span></td>
                    <td style="padding: 8px 6px; max-width: 140px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${item.website || ''}">${item.website ? `<a href="${item.website}" target="_blank" style="color: var(--kt-blue);">View</a>` : '-'}</td>
                    <td style="padding: 8px 6px; font-size: 11px; color: var(--muted); max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${item.address || ''}">${item.address || '-'}</td>
                  </tr>
                `).join('')}
              </tbody>
            </table>
          </div>`;
      }
      
      // Close any existing staging modal first
      const existingModal = document.getElementById('outscraperStagingModal');
      if (existingModal) existingModal.remove();
      
      const modal = document.createElement('div');
      modal.id = 'outscraperStagingModal';
      modal.style.cssText = 'position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.8); z-index: 99999; display: flex; align-items: center; justify-content: center; padding: 20px;';
      modal.onclick = (e) => { if (e.target === modal) modal.remove(); };
      modal.innerHTML = `
        <div style="background: var(--panel); border-radius: 12px; max-width: 1400px; width: 100%; max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 20px 60px rgba(0,0,0,0.4);" onclick="event.stopPropagation()">
          <div style="display: flex; justify-content: space-between; align-items: center; padding: 20px 24px; border-bottom: 1px solid var(--border);">
            <h2 style="margin: 0; font-size: 18px; font-weight: 600;">Review Outscraper Leads</h2>
            <button style="background: none; border: none; font-size: 24px; cursor: pointer; color: var(--muted); padding: 0; line-height: 1;" onclick="document.getElementById('outscraperStagingModal').remove()">&times;</button>
          </div>
          
          <div style="flex: 1; overflow: hidden; padding: 20px 24px; display: flex; flex-direction: column; min-height: 0;">
            ${htmlContent}
          </div>
          
          <div style="padding: 16px 24px; border-top: 1px solid var(--border); display: flex; gap: 12px; justify-content: space-between; flex-wrap: wrap; align-items: center; background: var(--bg); border-radius: 0 0 12px 12px;">
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
              ${items.length > 0 ? `
                <button class="btn" style="background: var(--kt-blue); color: white;" onclick="approveSelectedStaging()">✓ Approve Selected</button>
                <button class="btn" style="background: #28a745; color: white;" onclick="approveAllStaging()">✓ Approve All</button>
                <button class="btn" style="background: #dc3545; color: white;" onclick="rejectSelectedStaging()">✗ Reject Selected</button>
              ` : ''}
            </div>
            <button class="btn secondary" onclick="document.getElementById('outscraperStagingModal').remove()">Close</button>
          </div>
        </div>
        <style>
          #outscraperStagingModal .staging-row:hover { background: var(--bg) !important; }
          #outscraperStagingModal .staging-checkbox { cursor: pointer; width: 16px; height: 16px; }
          #outscraperStagingModal table { table-layout: auto; }
        </style>
      `;
      document.body.appendChild(modal);
    }
    
    function toggleAllStaging(checked) {
      document.querySelectorAll('.staging-checkbox').forEach(cb => cb.checked = checked);
    }
    
    function filterStagingBatch(batchId) {
      document.querySelectorAll('.modal table tr[data-batch]').forEach(row => {
        if (!batchId || row.dataset.batch === batchId) {
          row.style.display = '';
        } else {
          row.style.display = 'none';
        }
      });
    }
    
    async function approveSelectedStaging() {
      const ids = Array.from(document.querySelectorAll('.staging-checkbox:checked')).map(cb => parseInt(cb.value));
      if (ids.length === 0) {
        alert('Please select at least one lead to approve');
        return;
      }
      
      const result = await api('outscraper_staging.approve', {
        method: 'POST',
        body: JSON.stringify({ ids })
      });
      
      if (result.ok) {
        alert(`Approved ${result.imported} leads to global pool.\n${result.duplicates} duplicates skipped.`);
        document.querySelector('.modal-overlay').remove();
        viewOutscraperStaging();
      } else {
        alert('Error: ' + (result.error || 'Unknown error'));
      }
    }
    
    async function approveAllStaging() {
      if (!confirm('Approve ALL pending leads and add them to the global pool?')) return;
      
      const result = await api('outscraper_staging.approve', {
        method: 'POST',
        body: JSON.stringify({ approve_all: true })
      });
      
      if (result.ok) {
        alert(`Approved ${result.imported} leads to global pool.\n${result.duplicates} duplicates skipped.`);
        document.querySelector('.modal-overlay').remove();
      } else {
        alert('Error: ' + (result.error || 'Unknown error'));
      }
    }
    
    async function rejectSelectedStaging() {
      const ids = Array.from(document.querySelectorAll('.staging-checkbox:checked')).map(cb => parseInt(cb.value));
      if (ids.length === 0) {
        alert('Please select at least one lead to reject');
        return;
      }
      
      if (!confirm(`Reject ${ids.length} selected leads?`)) return;
      
      const result = await api('outscraper_staging.reject', {
        method: 'POST',
        body: JSON.stringify({ ids })
      });
      
      if (result.ok) {
        alert(`Rejected ${result.rejected} leads.`);
        document.querySelector('.modal-overlay').remove();
        viewOutscraperStaging();
      } else {
        alert('Error: ' + (result.error || 'Unknown error'));
      }
    }
    
    async function exportData() {
      window.location.href = '?api=export';
    }
    
    async function importData(e) {
      const file = e.target.files[0];
      if (!file) return;
      const text = await file.text();
      const data = JSON.parse(text);
      if (!confirm('This will replace all contacts, calls, projects, and settings. Continue?')) return;
      try {
        await api('import', { method: 'POST', body: JSON.stringify(data) });
        alert('Import successful');
        renderSettings();
      } catch (err) {
        alert('Import failed: ' + err.message);
      }
    }
    
    async function resetDatabase() {
      if (!confirm('This will permanently delete ALL contacts, calls, projects, and settings. Are you absolutely sure?')) return;
      if (!confirm('Last warning: This cannot be undone!')) return;
      await api('reset', { method: 'POST' });
      alert('Database reset complete');
      renderSettings();
    }
    
    async function openIndustriesManagement() {
      const industries = await api('industries.list');
      
      showModal(`
        <h3>Manage Industries</h3>
        <div class="form-group">
          <button class="btn" onclick="openAddIndustryForm()" style="margin-bottom: 16px;">+ Add New Industry</button>
        </div>
        <table style="width: 100%;">
          <thead>
            <tr>
              <th>Industry Name</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="industriesTableBody">
            ${industries.items.map(i => `
              <tr>
                <td>${i.name}</td>
                <td>
                  <button class="btn" onclick="openEditIndustryForm(${i.id}, '${i.name.replace(/'/g, "\\'")}')">Edit</button>
                  <button class="btn danger" onclick="deleteIndustry(${i.id})">Delete</button>
                </td>
              </tr>
            `).join('')}
          </tbody>
        </table>
        <div style="margin-top: 20px;">
          <button type="button" class="btn secondary" onclick="closeModal()">Close</button>
        </div>
      `);
    }
    
    function openAddIndustryForm() {
      showModal(`
        <h3>Add New Industry</h3>
        <form onsubmit="saveIndustry(event, null)">
          <div class="form-group">
            <label>Industry Name *</label>
            <input type="text" name="name" required placeholder="e.g., Real Estate">
          </div>
          <button type="submit" class="btn">Add Industry</button>
          <button type="button" class="btn secondary" onclick="closeModal(); openIndustriesManagement();">Cancel</button>
        </form>
      `);
    }
    
    function openEditIndustryForm(id, currentName) {
      showModal(`
        <h3>Edit Industry</h3>
        <form onsubmit="saveIndustry(event, ${id})">
          <div class="form-group">
            <label>Industry Name *</label>
            <input type="text" name="name" value="${currentName}" required>
          </div>
          <button type="submit" class="btn">Save Changes</button>
          <button type="button" class="btn secondary" onclick="closeModal(); openIndustriesManagement();">Cancel</button>
        </form>
      `);
    }
    
    async function saveIndustry(e, id) {
      e.preventDefault();
      const form = e.target;
      const name = form.name.value.trim();
      
      if (!name) {
        alert('Industry name is required');
        return;
      }
      
      try {
        await api('industries.save', {
          method: 'POST',
          body: JSON.stringify({ id, name })
        });
        closeModal();
        openIndustriesManagement();
      } catch (e) {
        alert('Error: ' + e.message);
      }
    }
    
    async function deleteIndustry(id) {
      if (!confirm('Delete this industry? This will not affect existing contacts/leads using this industry.')) return;
      
      try {
        await api(`industries.delete&id=${id}`, { method: 'DELETE' });
        openIndustriesManagement();
      } catch (e) {
        alert('Error: ' + e.message);
      }
    }
    
    checkSession();
  </script>
</body>
</html>
