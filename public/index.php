<?php
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');

session_start();

$DB_HOST = getenv('PGHOST') ?: '127.0.0.1';
$DB_PORT = getenv('PGPORT') ?: '5432';
$DB_NAME = getenv('PGDATABASE') ?: 'crmdb';
$DB_USER = getenv('PGUSER') ?: 'crmuser';
$DB_PASS = getenv('PGPASSWORD') ?: 'crmStongpassword';

$SMTP_HOST = getenv('SMTP_HOST') ?: 'mail.koaditech.com';
$SMTP_PORT = getenv('SMTP_PORT') ?: '465';
$SMTP_USER = getenv('SMTP_USER') ?: 'help@koaditech.com';
$SMTP_PASS = getenv('SMTP_PASS') ?: 'Men4patj@3112';

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

function ensure_schema() {
  $pdo = db();
  $pdo->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS users (
      id SERIAL PRIMARY KEY,
      username TEXT UNIQUE NOT NULL,
      email TEXT,
      password TEXT NOT NULL,
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
      IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='users' AND column_name='caller_id') THEN
        ALTER TABLE users ADD COLUMN caller_id TEXT;
      END IF;
      IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='calls' AND column_name='recording_url') THEN
        ALTER TABLE calls ADD COLUMN recording_url TEXT;
      END IF;
      IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='calls' AND column_name='twilio_call_sid') THEN
        ALTER TABLE calls ADD COLUMN twilio_call_sid TEXT;
      END IF;
    END $$;
    
    CREATE INDEX IF NOT EXISTS idx_contacts_assigned ON contacts(assigned_to);
    CREATE INDEX IF NOT EXISTS idx_calls_assigned ON calls(assigned_to);
    CREATE INDEX IF NOT EXISTS idx_projects_assigned ON projects(assigned_to);
SQL);

  $admin_exists = $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
  if (!$admin_exists) {
    $pdo->prepare("INSERT INTO users (username, email, password, full_name, role) VALUES ('admin', 'admin@koadi.tech', :pwd, 'Administrator', 'admin')")
      ->execute([':pwd' => password_hash('admin123', PASSWORD_DEFAULT)]);
  }
  
  // Update existing admin user to have email if missing
  $pdo->exec("UPDATE users SET email = 'admin@koadi.tech' WHERE username = 'admin' AND (email IS NULL OR email = '')");
  
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
      case 'forgot_password': api_forgot_password(); break;
      case 'reset_password': api_reset_password(); break;
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
      
      case 'export': api_export(); break;
      case 'import': api_import(); break;
      case 'reset': api_reset(); break;
      
      case 'twilio.token': api_twilio_token(); break;
      case 'twilio.webhook': api_twilio_webhook(); break;
      case 'twilio.settings.get': api_twilio_settings_get(); break;
      case 'twilio.settings.set': api_twilio_settings_set(); break;
      
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
  $password = $b['password'] ?? '';
  $remember_me = $b['remember_me'] ?? false;
  
  // Validate email format
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(['error' => 'Invalid email format'], 400);
  }
  
  $pdo = db();
  $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = LOWER(:e)");
  $stmt->execute([':e' => $email]);
  $user = $stmt->fetch();
  
  if ($user && password_verify($password, $user['password'])) {
    // Check if user is active
    if (isset($user['status']) && $user['status'] === 'inactive') {
      respond(['error' => 'Account is deactivated. Please contact administrator.'], 403);
    }
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['role'] = $user['role'];
    
    // Handle remember me
    if ($remember_me) {
      $token = bin2hex(random_bytes(32));
      $pdo->prepare("UPDATE users SET remember_token = :token WHERE id = :id")
        ->execute([':token' => password_hash($token, PASSWORD_DEFAULT), ':id' => $user['id']]);
      setcookie('remember_token', $token, time() + (30 * 24 * 60 * 60), '/', '', true, true); // 30 days
    }
    
    respond(['ok' => true, 'user' => [
      'id' => $user['id'],
      'username' => $user['username'],
      'email' => $user['email'],
      'full_name' => $user['full_name'],
      'role' => $user['role']
    ]]);
  } else {
    respond(['error' => 'Invalid email or password'], 401);
  }
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
      'role' => $_SESSION['role']
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
        
        respond(['user' => [
          'id' => $user['id'],
          'username' => $user['username'],
          'full_name' => $user['full_name'],
          'role' => $user['role']
        ]]);
      }
    }
  }
  respond(['user' => null]);
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
  $stmt = $pdo->query("SELECT * FROM password_resets WHERE expires_at > NOW()");
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
  
  // Generate invitation token
  $token = bin2hex(random_bytes(32));
  $expires_at = date('Y-m-d H:i:s', strtotime('+48 hours'));
  
  // Store invitation
  $pdo->prepare("INSERT INTO invitations (email, role, token, expires_at) VALUES (:e, :r, :t, :exp)")
    ->execute([':e' => $email, ':r' => $role, ':t' => password_hash($token, PASSWORD_DEFAULT), ':exp' => $expires_at]);
  
  // Send invite email
  $setup_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/?invite_token=' . urlencode($token);
  
  $email_body = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
      <div style='background: linear-gradient(135deg, #FF8C42, #0066CC); padding: 30px; text-align: center;'>
        <h1 style='color: white; margin: 0;'>You're Invited to Koadi Tech CRM</h1>
      </div>
      <div style='padding: 30px; background: #f9f9f9;'>
        <p>Hello,</p>
        <p>You've been invited to join Koadi Technology CRM platform as a <strong>{$role}</strong> user.</p>
        <p>Click the button below to set up your account with your name and password:</p>
        <div style='text-align: center; margin: 30px 0;'>
          <a href='{$setup_url}' style='background: #0066CC; color: white; padding: 15px 40px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;'>Set Up My Account</a>
        </div>
        <p style='color: #666; font-size: 14px;'>This invitation link will expire in 48 hours.</p>
        <p style='color: #999; font-size: 12px; margin-top: 20px;'>If you didn't expect this invitation, you can safely ignore this email.</p>
        <p style='margin-top: 30px; color: #999; font-size: 12px;'>This is an automated message from Koadi Technology CRM.</p>
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
  $password = $b['password'] ?? '';
  
  if (!$full_name || !$password) {
    respond(['error' => 'Full name and password are required'], 400);
  }
  
  if (strlen($password) < 8) {
    respond(['error' => 'Password must be at least 8 characters'], 400);
  }
  
  $pdo = db();
  $stmt = $pdo->query("SELECT * FROM invitations WHERE expires_at > NOW()");
  $invites = $stmt->fetchAll();
  
  foreach ($invites as $invite) {
    if (password_verify($token, $invite['token'])) {
      // Create user account
      $username = explode('@', $invite['email'])[0];
      $stmt = $pdo->prepare("INSERT INTO users (email, username, password, full_name, role) VALUES (:e, :u, :p, :n, :r) RETURNING id");
      $stmt->execute([
        ':e' => $invite['email'],
        ':u' => $username,
        ':p' => password_hash($password, PASSWORD_DEFAULT),
        ':n' => $full_name,
        ':r' => $invite['role']
      ]);
      
      // Delete the invitation
      $pdo->prepare("DELETE FROM invitations WHERE email = :email")->execute([':email' => $invite['email']]);
      
      respond(['ok' => true, 'message' => 'Account created successfully! Please login.']);
    }
  }
  
  respond(['error' => 'Invalid or expired invitation'], 400);
}

function api_users_list() {
  require_admin();
  $pdo = db();
  $users = $pdo->query("SELECT id, username, email, full_name, role, status, created_at, 'user' as type FROM users ORDER BY id DESC")->fetchAll();
  $invites = $pdo->query("SELECT id, email, role, created_at, 'invite' as type, expires_at FROM invitations WHERE expires_at > NOW() ORDER BY id DESC")->fetchAll();
  
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
  $password = $b['password'] ?? '';
  
  // Auto-generate username from email (keep for backward compatibility)
  $username = explode('@', $email)[0];
  
  // Validate email format
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(['error' => 'Invalid email format'], 400);
  }
  
  // Check if email already exists (excluding current user if editing)
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
  
  if ($id) {
    if ($password) {
      $stmt = $pdo->prepare("UPDATE users SET email=:e, username=:u, full_name=:n, role=:r, password=:p WHERE id=:id RETURNING id, username, email, full_name, role");
      $stmt->execute([':e' => $email, ':u' => $username, ':n' => $full_name, ':r' => $role, ':p' => password_hash($password, PASSWORD_DEFAULT), ':id' => $id]);
    } else {
      $stmt = $pdo->prepare("UPDATE users SET email=:e, username=:u, full_name=:n, role=:r WHERE id=:id RETURNING id, username, email, full_name, role");
      $stmt->execute([':e' => $email, ':u' => $username, ':n' => $full_name, ':r' => $role, ':id' => $id]);
    }
    $user = $stmt->fetch();
  } else {
    if (!$password) {
      respond(['error' => 'Password is required for new users'], 400);
    }
    $stmt = $pdo->prepare("INSERT INTO users (email, username, password, full_name, role) VALUES (:e, :u, :p, :n, :r) RETURNING id, username, email, full_name, role");
    $stmt->execute([':e' => $email, ':u' => $username, ':p' => password_hash($password, PASSWORD_DEFAULT), ':n' => $full_name, ':r' => $role]);
    $user = $stmt->fetch();
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
  $pdo->prepare("DELETE FROM invitations WHERE id=:id")->execute([':id' => $id]);
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
  
  if ($id) {
    $stmt = $pdo->prepare("UPDATE leads SET name=:n, phone=:p, email=:e, company=:c, address=:a, industry=:i, updated_at=now() WHERE id=:id RETURNING *");
    $stmt->execute([':n' => $name, ':p' => $phone, ':e' => $email, ':c' => $company, ':a' => $address, ':i' => $industry, ':id' => $id]);
  } else {
    if ($_SESSION['role'] === 'admin') {
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

function api_export() {
  require_admin();
  $p = db();
  $data = [
    'leads' => $p->query("SELECT * FROM leads ORDER BY id")->fetchAll(),
    'contacts' => $p->query("SELECT * FROM contacts ORDER BY id")->fetchAll(),
    'calls' => $p->query("SELECT * FROM calls ORDER BY id")->fetchAll(),
    'projects' => $p->query("SELECT * FROM projects ORDER BY id")->fetchAll(),
    'settings' => $p->query("SELECT * FROM settings ORDER BY key")->fetchAll(),
  ];
  header('Content-Disposition: attachment; filename="koadi_crm_export.json"');
  respond($data);
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

function api_twilio_token() {
  require_auth();
  $pdo = db();
  
  // Get Twilio credentials from settings
  $account_sid = $pdo->query("SELECT value FROM settings WHERE key='twilio_account_sid'")->fetchColumn();
  $auth_token = $pdo->query("SELECT value FROM settings WHERE key='twilio_auth_token'")->fetchColumn();
  $twiml_app_sid = $pdo->query("SELECT value FROM settings WHERE key='twilio_twiml_app_sid'")->fetchColumn();
  
  if (!$account_sid || !$auth_token || !$twiml_app_sid) {
    respond(['error' => 'Twilio not configured. Please contact administrator.'], 400);
  }
  
  // Get user's caller ID
  $user_id = $_SESSION['user_id'];
  $user = $pdo->query("SELECT caller_id FROM users WHERE id={$user_id}")->fetch();
  $caller_id = $user['caller_id'] ?? '';
  
  if (!$caller_id) {
    respond(['error' => 'No caller ID assigned. Please contact administrator.'], 400);
  }
  
  // Generate Twilio access token
  $identity = $_SESSION['username'];
  $token_data = [
    'jti' => $account_sid . '-' . time(),
    'iss' => $account_sid,
    'sub' => $account_sid,
    'nbf' => time(),
    'exp' => time() + 3600, // 1 hour expiry
    'grants' => [
      'identity' => $identity,
      'voice' => [
        'incoming' => ['allow' => true],
        'outgoing' => [
          'application_sid' => $twiml_app_sid,
          'params' => [
            'From' => $caller_id,
            'userId' => $user_id
          ]
        ]
      ]
    ]
  ];
  
  // Create JWT token (simple base64 encoding for now - in production use proper JWT library)
  $header = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
  $payload = base64_encode(json_encode($token_data));
  $signature = base64_encode(hash_hmac('sha256', "$header.$payload", $auth_token, true));
  $jwt = "$header.$payload.$signature";
  
  respond([
    'token' => $jwt,
    'identity' => $identity,
    'caller_id' => $caller_id
  ]);
}

function api_twilio_webhook() {
  // Twilio webhook to receive call status updates
  $call_sid = $_POST['CallSid'] ?? '';
  $call_status = $_POST['CallStatus'] ?? '';
  $call_duration = (int)($_POST['CallDuration'] ?? 0);
  $recording_url = $_POST['RecordingUrl'] ?? '';
  $to_number = $_POST['To'] ?? '';
  $user_id = (int)($_POST['userId'] ?? 0);
  
  if (!$call_sid) {
    respond(['error' => 'Missing CallSid'], 400);
  }
  
  $pdo = db();
  
  // Find contact by phone number
  $contact = null;
  if ($to_number) {
    $normalized = preg_replace('/\D+/', '', $to_number);
    $stmt = $pdo->prepare("SELECT id FROM contacts WHERE regexp_replace(COALESCE(phone_country,'')||COALESCE(phone_number,''), '\\D', '', 'g') = :phone LIMIT 1");
    $stmt->execute([':phone' => $normalized]);
    $contact = $stmt->fetch();
  }
  
  // Update or create call log
  if ($call_status === 'completed') {
    $contact_id = $contact ? $contact['id'] : null;
    $duration_min = ceil($call_duration / 60);
    $when_at = date('c');
    
    // Check if call already exists
    $existing = $pdo->prepare("SELECT id FROM calls WHERE twilio_call_sid = :sid");
    $existing->execute([':sid' => $call_sid]);
    $call = $existing->fetch();
    
    if ($call) {
      // Update existing call
      $pdo->prepare("UPDATE calls SET duration_min = :dur, recording_url = :rec, outcome = 'Completed' WHERE id = :id")
        ->execute([':dur' => $duration_min, ':rec' => $recording_url, ':id' => $call['id']]);
    } else {
      // Create new call log
      $pdo->prepare("INSERT INTO calls (contact_id, when_at, outcome, duration_min, recording_url, twilio_call_sid, assigned_to, notes) VALUES (:cid, :when, 'Completed', :dur, :rec, :sid, :uid, :notes)")
        ->execute([
          ':cid' => $contact_id,
          ':when' => $when_at,
          ':dur' => $duration_min,
          ':rec' => $recording_url,
          ':sid' => $call_sid,
          ':uid' => $user_id,
          ':notes' => 'Called from CRM to ' . $to_number
        ]);
    }
  }
  
  // Return TwiML response
  header('Content-Type: text/xml');
  echo '<?xml version="1.0" encoding="UTF-8"?><Response></Response>';
  exit;
}

function api_twilio_settings_get() {
  require_admin();
  $pdo = db();
  
  $account_sid = $pdo->query("SELECT value FROM settings WHERE key='twilio_account_sid'")->fetchColumn();
  $auth_token = $pdo->query("SELECT value FROM settings WHERE key='twilio_auth_token'")->fetchColumn();
  $twiml_app_sid = $pdo->query("SELECT value FROM settings WHERE key='twilio_twiml_app_sid'")->fetchColumn();
  
  respond([
    'account_sid' => $account_sid ?: '',
    'auth_token' => $auth_token ? '••••••••' : '', // Mask the token
    'twiml_app_sid' => $twiml_app_sid ?: ''
  ]);
}

function api_twilio_settings_set() {
  require_admin();
  $pdo = db();
  $b = body_json();
  
  if (isset($b['account_sid'])) {
    $pdo->prepare("INSERT INTO settings(key,value) VALUES ('twilio_account_sid', :v) ON CONFLICT(key) DO UPDATE SET value=EXCLUDED.value")
      ->execute([':v' => $b['account_sid']]);
  }
  
  if (isset($b['auth_token']) && $b['auth_token'] !== '••••••••') {
    $pdo->prepare("INSERT INTO settings(key,value) VALUES ('twilio_auth_token', :v) ON CONFLICT(key) DO UPDATE SET value=EXCLUDED.value")
      ->execute([':v' => $b['auth_token']]);
  }
  
  if (isset($b['twiml_app_sid'])) {
    $pdo->prepare("INSERT INTO settings(key,value) VALUES ('twilio_twiml_app_sid', :v) ON CONFLICT(key) DO UPDATE SET value=EXCLUDED.value")
      ->execute([':v' => $b['twiml_app_sid']]);
  }
  
  respond(['ok' => true]);
}

if (isset($_GET['logo'])) {
  header('Content-Type: image/png');
  readfile('logo.png');
  exit;
}

if (isset($_GET['favicon'])) {
  header('Content-Type: image/png');
  readfile('favicon.png');
  exit;
}

if (isset($_GET['background'])) {
  header('Content-Type: image/jpeg');
  readfile('background.jpg');
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
      background: linear-gradient(135deg, rgba(0, 102, 204, 0.15), rgba(0, 51, 102, 0.15)), url('?background') center/cover no-repeat;
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
      const resetToken = new URLSearchParams(window.location.search).get('reset_token');
      if (resetToken) {
        renderResetPassword(resetToken);
        return;
      }
      
      const inviteToken = new URLSearchParams(window.location.search).get('invite_token');
      if (inviteToken) {
        renderAcceptInvite(inviteToken);
        return;
      }
      
      document.getElementById('app').innerHTML = `
        <div class="login-container">
          <div class="login-box">
            <img src="?logo" alt="Koadi Technology">
            <h2 style="margin-bottom: 24px;">Koadi Tech CRM</h2>
            <form onsubmit="handleLogin(event)">
              <div class="form-group">
                <input type="email" name="email" placeholder="Email" autocomplete="email" required>
              </div>
              <div class="form-group">
                <input type="password" name="password" placeholder="Password" autocomplete="current-password" required>
              </div>
              <div class="form-group" style="display: flex; align-items: center; gap: 8px; justify-content: space-between;">
                <label style="display: flex; align-items: center; gap: 8px; margin: 0;">
                  <input type="checkbox" name="remember_me" style="width: auto;">
                  <span>Remember Me</span>
                </label>
                <a href="#" onclick="event.preventDefault(); showForgotPassword();" style="color: var(--brand); text-decoration: none; font-size: 14px;">Forgot Password?</a>
              </div>
              <button type="submit" class="btn" style="width: 100%;">Login</button>
            </form>
          </div>
        </div>
      `;
    }
    
    async function handleLogin(e) {
      e.preventDefault();
      const form = e.target;
      const email = form.email.value.trim();
      const password = form.password.value;
      const remember_me = form.remember_me.checked;
      
      // Validate email format
      const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      if (!emailRegex.test(email)) {
        alert('Please enter a valid email address');
        return;
      }
      
      try {
        await api('login', {
          method: 'POST',
          body: JSON.stringify({ email, password, remember_me })
        });
        await checkSession();
      } catch (e) {
        alert('Login failed: ' + e.message);
      }
    }
    
    async function handleLogout() {
      await api('logout', { method: 'POST' });
      currentUser = null;
      renderLogin();
    }
    
    function showForgotPassword() {
      document.getElementById('app').innerHTML = `
        <div class="login-container">
          <div class="login-box">
            <img src="?logo" alt="Koadi Technology">
            <h2 style="margin-bottom: 24px;">Forgot Password</h2>
            <p style="color: var(--muted); margin-bottom: 20px;">Enter your email address and we'll send you a password reset link.</p>
            <form onsubmit="handleForgotPassword(event)">
              <div class="form-group">
                <input type="email" name="email" placeholder="Email" autocomplete="email" required>
              </div>
              <button type="submit" class="btn" style="width: 100%;">Send Reset Link</button>
              <button type="button" class="btn secondary" onclick="renderLogin()" style="width: 100%; margin-top: 12px;">Back to Login</button>
            </form>
          </div>
        </div>
      `;
    }
    
    async function handleForgotPassword(e) {
      e.preventDefault();
      const form = e.target;
      const email = form.email.value.trim();
      
      try {
        const result = await api('forgot_password', {
          method: 'POST',
          body: JSON.stringify({ email })
        });
        alert(result.message || 'Reset link sent! Please check your email.');
        renderLogin();
      } catch (e) {
        alert('Error: ' + e.message);
      }
    }
    
    function renderResetPassword(token) {
      document.getElementById('app').innerHTML = `
        <div class="login-container">
          <div class="login-box">
            <img src="?logo" alt="Koadi Technology">
            <h2 style="margin-bottom: 24px;">Reset Password</h2>
            <p style="color: var(--muted); margin-bottom: 20px;">Enter your new password below.</p>
            <form onsubmit="handleResetPassword(event, '${token}')">
              <div class="form-group">
                <input type="password" name="password" placeholder="New Password (min 8 characters)" required minlength="8">
              </div>
              <div class="form-group">
                <input type="password" name="confirm_password" placeholder="Confirm New Password" required minlength="8">
              </div>
              <button type="submit" class="btn" style="width: 100%;">Reset Password</button>
              <button type="button" class="btn secondary" onclick="window.location.href = window.location.origin;" style="width: 100%; margin-top: 12px;">Back to Login</button>
            </form>
          </div>
        </div>
      `;
    }
    
    async function handleResetPassword(e, token) {
      e.preventDefault();
      const form = e.target;
      const password = form.password.value;
      const confirmPassword = form.confirm_password.value;
      
      if (password !== confirmPassword) {
        alert('Passwords do not match!');
        return;
      }
      
      try {
        const result = await api('reset_password', {
          method: 'POST',
          body: JSON.stringify({ token, password })
        });
        alert(result.message || 'Password reset successful! Please login with your new password.');
        window.location.href = window.location.origin;
      } catch (e) {
        alert('Error: ' + e.message);
      }
    }
    
    function renderAcceptInvite(token) {
      document.getElementById('app').innerHTML = `
        <div class="login-container">
          <div class="login-box">
            <img src="?logo" alt="Koadi Technology">
            <h2 style="margin-bottom: 24px;">Set Up Your Account</h2>
            <p style="color: var(--muted); margin-bottom: 20px;">Complete your account setup by entering your details below.</p>
            <form onsubmit="handleAcceptInvite(event, '${token}')">
              <div class="form-group">
                <input type="text" name="full_name" placeholder="Full Name" required>
              </div>
              <div class="form-group">
                <input type="password" name="password" placeholder="Password (min 8 characters)" required minlength="8">
              </div>
              <div class="form-group">
                <input type="password" name="confirm_password" placeholder="Confirm Password" required minlength="8">
              </div>
              <button type="submit" class="btn" style="width: 100%;">Create Account</button>
              <button type="button" class="btn secondary" onclick="window.location.href = window.location.origin;" style="width: 100%; margin-top: 12px;">Back to Login</button>
            </form>
          </div>
        </div>
      `;
    }
    
    async function handleAcceptInvite(e, token) {
      e.preventDefault();
      const form = e.target;
      const password = form.password.value;
      const confirmPassword = form.confirm_password.value;
      
      if (password !== confirmPassword) {
        alert('Passwords do not match!');
        return;
      }
      
      try {
        const result = await api('accept_invite', {
          method: 'POST',
          body: JSON.stringify({
            token: token,
            full_name: form.full_name.value,
            password: password
          })
        });
        alert(result.message || 'Account created successfully! Please login.');
        window.location.href = window.location.origin;
      } catch (e) {
        alert('Error: ' + e.message);
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
            <div id="view-projects" class="view"></div>
            <div id="view-leads" class="view active"></div>
            ${isAdmin ? '<div id="view-users" class="view"></div>' : ''}
            <div id="view-settings" class="view"></div>
            <footer style="text-align: center; padding: 20px; margin-top: 40px; color: var(--muted); font-size: 12px; border-top: 1px solid var(--border);">
              @2025 Koadi Technology LLC
            </footer>
          </main>
        </div>
      `;
      
      const savedView = localStorage.getItem('crm_current_view') || 'dashboard';
      switchView(savedView);
    }
    
    function switchView(view) {
      currentView = view;
      localStorage.setItem('crm_current_view', view);
      document.querySelectorAll('.nav button').forEach(b => b.classList.remove('active'));
      document.querySelectorAll('.nav button').forEach(b => {
        const text = b.textContent.toLowerCase();
        if (text.includes(view)) b.classList.add('active');
      });
      document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));
      document.getElementById('view-' + view).classList.add('active');
      
      if (view === 'dashboard') renderDashboard();
      if (view === 'contacts') renderContacts();
      if (view === 'calls') renderCalls();
      if (view === 'projects') renderProjects();
      if (view === 'leads') renderLeads();
      if (view === 'users') renderUsers();
      if (view === 'settings') renderSettings();
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
                <th>Email</th>
                <th>Company</th>
                <th>Industry</th>
                <th>Address</th>
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
        
        return `
          <tr>
            <td>${nameDisplay}</td>
            <td>${isHidden ? '***' : lead.phone}</td>
            <td>${lead.email}</td>
            <td>${lead.company || '-'}</td>
            <td>${lead.industry || '-'}</td>
            <td>${isHidden ? '***' : lead.address}</td>
            <td><span class="badge ${lead.status}">${lead.status}</span></td>
            <td>${lead.assigned_name || '-'}</td>
            <td>
              ${canGrab ? `<button class="btn success" onclick="grabLead(${lead.id})">Grab</button>` : ''}
              ${canView && !isHidden ? `<button class="btn secondary" onclick="viewLead(${lead.id})">View</button>` : ''}
              ${canView && !isHidden && currentUser.role === 'sales' ? `<button class="btn" style="background: #FF8C42;" onclick="convertLeadToContact(${lead.id})">Convert to Contact</button>` : ''}
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
      
      const adminActions = isAdmin ? `
        <div style="display: flex; gap: 8px; margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid var(--border);">
          <button class="btn" onclick="closeModal(); openLeadForm(${id});">Edit</button>
          <button class="btn" onclick="closeModal(); openAssignModal(${id});">Assign</button>
          <button class="btn danger" onclick="deleteLead(${id}); closeModal();">Delete</button>
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
          <button type="submit" class="btn">Add Interaction</button>
          <button type="button" class="btn secondary" onclick="closeModal()">Close</button>
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
            <label>Address</label>
            <textarea name="address">${lead?.address || ''}</textarea>
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
        id,
        name: form.name.value,
        phone: form.phone.value,
        email: form.email.value,
        company: form.company.value,
        industry: form.industry.value,
        address: form.address.value
      };
      
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
            <label>Password ${user ? '(leave blank to keep current)' : '*'}</label>
            <input type="password" name="password" ${user ? '' : 'required'}>
          </div>
          <div class="form-group">
            <label>Role *</label>
            <select name="role" required>
              <option value="sales" ${user?.role === 'sales' ? 'selected' : ''}>Sales</option>
              <option value="admin" ${user?.role === 'admin' ? 'selected' : ''}>Admin</option>
            </select>
          </div>
          <button type="submit" class="btn">Save</button>
          <button type="button" class="btn secondary" onclick="closeModal()">Cancel</button>
        </form>
      `);
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
        password: form.password.value,
        role: form.role.value
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
        <p style="color: var(--muted); margin-bottom: 20px;">Send an invitation link to a user's email. They will set up their own account with name and password.</p>
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
            <td>${(c.phone_country||'') + ' ' + (c.phone_number||'')}</td>
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
      const isAdmin = currentUser.role === 'admin';
      
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
