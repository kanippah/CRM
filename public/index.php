<?php
session_start();

$DB_HOST = getenv('PGHOST') ?: '127.0.0.1';
$DB_PORT = getenv('PGPORT') ?: '5432';
$DB_NAME = getenv('PGDATABASE') ?: 'postgres';
$DB_USER = getenv('PGUSER') ?: 'postgres';
$DB_PASS = getenv('PGPASSWORD') ?: '';

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

function ensure_schema() {
  $pdo = db();
  $pdo->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS users (
      id SERIAL PRIMARY KEY,
      username TEXT UNIQUE NOT NULL,
      password TEXT NOT NULL,
      full_name TEXT NOT NULL,
      role TEXT NOT NULL DEFAULT 'sales',
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
      created_at TIMESTAMPTZ DEFAULT now(),
      updated_at TIMESTAMPTZ DEFAULT now()
    );
    
    CREATE TABLE IF NOT EXISTS settings (
      key TEXT PRIMARY KEY,
      value TEXT
    );
    
    CREATE INDEX IF NOT EXISTS idx_leads_status ON leads(status);
    CREATE INDEX IF NOT EXISTS idx_leads_assigned ON leads(assigned_to);
    CREATE INDEX IF NOT EXISTS idx_contacts_company ON contacts (LOWER(BTRIM(COALESCE(company,''))));
    CREATE UNIQUE INDEX IF NOT EXISTS idx_contacts_phone_unique ON contacts ((regexp_replace(COALESCE(phone_country,'')||COALESCE(phone_number,'') ,'\\D','','g'))) WHERE COALESCE(phone_country,'')<>'' AND COALESCE(phone_number,'')<>'';
SQL);

  $admin_exists = $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
  if (!$admin_exists) {
    $pdo->prepare("INSERT INTO users (username, password, full_name, role) VALUES ('admin', :pwd, 'Administrator', 'admin')")
      ->execute([':pwd' => password_hash('admin123', PASSWORD_DEFAULT)]);
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
      
      case 'users.list': api_users_list(); break;
      case 'users.save': api_users_save(); break;
      case 'users.delete': api_users_delete(); break;
      
      case 'leads.list': api_leads_list(); break;
      case 'leads.save': api_leads_save(); break;
      case 'leads.delete': api_leads_delete(); break;
      case 'leads.grab': api_leads_grab(); break;
      case 'leads.import': api_leads_import(); break;
      
      case 'interactions.list': api_interactions_list(); break;
      case 'interactions.save': api_interactions_save(); break;
      
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
  $username = $b['username'] ?? '';
  $password = $b['password'] ?? '';
  
  $pdo = db();
  $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :u");
  $stmt->execute([':u' => $username]);
  $user = $stmt->fetch();
  
  if ($user && password_verify($password, $user['password'])) {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['role'] = $user['role'];
    respond(['ok' => true, 'user' => [
      'id' => $user['id'],
      'username' => $user['username'],
      'full_name' => $user['full_name'],
      'role' => $user['role']
    ]]);
  } else {
    respond(['error' => 'Invalid credentials'], 401);
  }
}

function api_logout() {
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
  } else {
    respond(['user' => null]);
  }
}

function api_users_list() {
  require_admin();
  $pdo = db();
  $users = $pdo->query("SELECT id, username, full_name, role, created_at FROM users ORDER BY id DESC")->fetchAll();
  respond(['items' => $users]);
}

function api_users_save() {
  require_admin();
  $b = body_json();
  $pdo = db();
  
  $id = $b['id'] ?? null;
  $username = trim($b['username'] ?? '');
  $full_name = trim($b['full_name'] ?? '');
  $role = $b['role'] ?? 'sales';
  $password = $b['password'] ?? '';
  
  if ($id) {
    if ($password) {
      $stmt = $pdo->prepare("UPDATE users SET username=:u, full_name=:n, role=:r, password=:p WHERE id=:id RETURNING id, username, full_name, role");
      $stmt->execute([':u' => $username, ':n' => $full_name, ':r' => $role, ':p' => password_hash($password, PASSWORD_DEFAULT), ':id' => $id]);
    } else {
      $stmt = $pdo->prepare("UPDATE users SET username=:u, full_name=:n, role=:r WHERE id=:id RETURNING id, username, full_name, role");
      $stmt->execute([':u' => $username, ':n' => $full_name, ':r' => $role, ':id' => $id]);
    }
    $user = $stmt->fetch();
  } else {
    $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, role) VALUES (:u, :p, :n, :r) RETURNING id, username, full_name, role");
    $stmt->execute([':u' => $username, ':p' => password_hash($password, PASSWORD_DEFAULT), ':n' => $full_name, ':r' => $role]);
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

function api_leads_list() {
  require_auth();
  $pdo = db();
  $q = $_GET['q'] ?? '';
  $type = $_GET['type'] ?? 'all';
  
  $user_id = $_SESSION['user_id'];
  $role = $_SESSION['role'];
  
  if ($role === 'admin') {
    if ($q) {
      $stmt = $pdo->prepare("SELECT l.*, u.full_name as assigned_name FROM leads l LEFT JOIN users u ON l.assigned_to = u.id WHERE (l.name ILIKE :q OR l.phone ILIKE :q OR l.address ILIKE :q OR l.company ILIKE :q) ORDER BY l.id DESC");
      $stmt->execute([':q' => "%$q%"]);
    } else {
      if ($type === 'global') {
        $stmt = $pdo->query("SELECT l.*, u.full_name as assigned_name FROM leads l LEFT JOIN users u ON l.assigned_to = u.id WHERE l.status='global' ORDER BY l.id DESC");
      } elseif ($type === 'assigned') {
        $stmt = $pdo->query("SELECT l.*, u.full_name as assigned_name FROM leads l LEFT JOIN users u ON l.assigned_to = u.id WHERE l.status='assigned' ORDER BY l.id DESC");
      } else {
        $stmt = $pdo->query("SELECT l.*, u.full_name as assigned_name FROM leads l LEFT JOIN users u ON l.assigned_to = u.id ORDER BY l.id DESC");
      }
    }
  } else {
    if ($q) {
      $stmt = $pdo->prepare("SELECT l.*, u.full_name as assigned_name FROM leads l LEFT JOIN users u ON l.assigned_to = u.id WHERE (l.status='global' OR l.assigned_to=:uid) AND (l.name ILIKE :q OR l.phone ILIKE :q OR l.address ILIKE :q OR l.company ILIKE :q) ORDER BY l.id DESC");
      $stmt->execute([':uid' => $user_id, ':q' => "%$q%"]);
    } else {
      if ($type === 'global') {
        $stmt = $pdo->query("SELECT l.*, u.full_name as assigned_name FROM leads l LEFT JOIN users u ON l.assigned_to = u.id WHERE l.status='global' ORDER BY l.id DESC");
      } elseif ($type === 'personal') {
        $stmt = $pdo->prepare("SELECT l.*, u.full_name as assigned_name FROM leads l LEFT JOIN users u ON l.assigned_to = u.id WHERE l.assigned_to=:uid ORDER BY l.id DESC");
        $stmt->execute([':uid' => $user_id]);
      } else {
        $stmt = $pdo->prepare("SELECT l.*, u.full_name as assigned_name FROM leads l LEFT JOIN users u ON l.assigned_to = u.id WHERE (l.status='global' OR l.assigned_to=:uid) ORDER BY l.id DESC");
        $stmt->execute([':uid' => $user_id]);
      }
    }
  }
  
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
  
  respond(['items' => $leads]);
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
  
  if ($_SESSION['role'] !== 'admin' && $id) {
    $check = $pdo->prepare("SELECT assigned_to FROM leads WHERE id=:id");
    $check->execute([':id' => $id]);
    $lead = $check->fetch();
    if ($lead && $lead['assigned_to'] != $_SESSION['user_id']) {
      respond(['error' => 'Forbidden'], 403);
    }
  }
  
  if ($id) {
    $stmt = $pdo->prepare("UPDATE leads SET name=:n, phone=:p, email=:e, company=:c, address=:a, updated_at=now() WHERE id=:id RETURNING *");
    $stmt->execute([':n' => $name, ':p' => $phone, ':e' => $email, ':c' => $company, ':a' => $address, ':id' => $id]);
  } else {
    if ($_SESSION['role'] !== 'admin') {
      respond(['error' => 'Only admin can create leads'], 403);
    }
    $stmt = $pdo->prepare("INSERT INTO leads (name, phone, email, company, address, status) VALUES (:n, :p, :e, :c, :a, 'global') RETURNING *");
    $stmt->execute([':n' => $name, ':p' => $phone, ':e' => $email, ':c' => $company, ':a' => $address]);
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
    
    if ($name) {
      $pdo->prepare("INSERT INTO leads (name, phone, email, company, address, status) VALUES (:n, :p, :e, :c, :a, 'global')")
        ->execute([':n' => $name, ':p' => $phone, ':e' => $email, ':c' => $company, ':a' => $address]);
      $count++;
    }
  }
  
  respond(['imported' => $count]);
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
    }
    
    .app { display: flex; height: 100vh; }
    
    .sidebar {
      width: 280px;
      background: var(--panel);
      border-right: 1px solid var(--border);
      padding: 20px;
      overflow-y: auto;
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
    
    .content { flex: 1; padding: 24px; overflow-y: auto; }
    
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
      background: linear-gradient(135deg, var(--kt-blue), var(--kt-dark-blue));
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
    let currentLeadTab = 'global';
    
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
      document.getElementById('app').innerHTML = `
        <div class="login-container">
          <div class="login-box">
            <img src="?logo" alt="Koadi Technology">
            <h2 style="margin-bottom: 24px;">CRM Login</h2>
            <form onsubmit="handleLogin(event)">
              <div class="form-group">
                <input type="text" name="username" placeholder="Username" autocomplete="username" required>
              </div>
              <div class="form-group">
                <input type="password" name="password" placeholder="Password" autocomplete="current-password" required>
              </div>
              <button type="submit" class="btn" style="width: 100%;">Login</button>
            </form>
            <p style="margin-top: 20px; color: var(--muted); font-size: 12px;">
              Default: admin / admin123
            </p>
          </div>
        </div>
      `;
    }
    
    async function handleLogin(e) {
      e.preventDefault();
      const form = e.target;
      const username = form.username.value;
      const password = form.password.value;
      
      try {
        await api('login', {
          method: 'POST',
          body: JSON.stringify({ username, password })
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
    
    function toggleTheme() {
      const current = document.body.getAttribute('data-theme');
      document.body.setAttribute('data-theme', current === 'dark' ? 'light' : 'dark');
    }
    
    function renderApp() {
      const isAdmin = currentUser.role === 'admin';
      
      document.getElementById('app').innerHTML = `
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
              <button onclick="switchView('leads')" class="active">📊 Leads</button>
              ${isAdmin ? '<button onclick="switchView(\'users\')">👥 Users</button>' : ''}
              <button onclick="handleLogout()" class="secondary">🚪 Logout</button>
              <button onclick="toggleTheme()" class="theme-toggle">🌓 Theme</button>
            </nav>
          </aside>
          <main class="content">
            <div id="view-leads" class="view active"></div>
            ${isAdmin ? '<div id="view-users" class="view"></div>' : ''}
          </main>
        </div>
      `;
      
      switchView('leads');
    }
    
    function switchView(view) {
      currentView = view;
      document.querySelectorAll('.nav button').forEach(b => {
        b.classList.toggle('active', b.textContent.includes(view === 'leads' ? 'Leads' : 'Users'));
      });
      document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));
      document.getElementById('view-' + view).classList.add('active');
      
      if (view === 'leads') renderLeads();
      if (view === 'users') renderUsers();
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
      
      document.getElementById('view-leads').innerHTML = `
        <div class="toolbar">
          ${isAdmin ? '<button class="btn" onclick="openLeadForm()">+ Add Lead</button>' : ''}
          ${isAdmin ? '<button class="btn warning" onclick="openImportModal()">📥 Import Leads</button>' : ''}
          <input type="text" class="search" id="leadSearch" placeholder="Search by name, phone, address, company..." oninput="loadLeads()">
        </div>
        ${tabs}
        <div class="card">
          <table id="leadsTable">
            <thead>
              <tr>
                <th>Name</th>
                <th>Phone</th>
                <th>Email</th>
                <th>Company</th>
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
      renderLeads();
    }
    
    async function loadLeads() {
      const search = document.getElementById('leadSearch')?.value || '';
      const data = await api(`leads.list&q=${encodeURIComponent(search)}&type=${currentLeadTab}`);
      const tbody = document.querySelector('#leadsTable tbody');
      
      if (data.items.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" style="text-align: center; color: var(--muted);">No leads found</td></tr>';
        return;
      }
      
      tbody.innerHTML = data.items.map(lead => {
        const isGlobal = lead.status === 'global' && !lead.assigned_to;
        const canGrab = currentUser.role === 'sales' && isGlobal;
        const canView = currentUser.role === 'admin' || lead.assigned_to == currentUser.id;
        const isHidden = lead.email === '***';
        
        return `
          <tr>
            <td><strong>${lead.name}</strong></td>
            <td>${lead.phone}</td>
            <td>${lead.email}</td>
            <td>${lead.company || '-'}</td>
            <td>${lead.address}</td>
            <td><span class="badge ${lead.status}">${lead.status}</span></td>
            <td>${lead.assigned_name || '-'}</td>
            <td>
              ${canGrab ? `<button class="btn success" onclick="grabLead(${lead.id})">Grab</button>` : ''}
              ${canView && !isHidden ? `<button class="btn secondary" onclick="viewLead(${lead.id})">View</button>` : ''}
              ${currentUser.role === 'admin' ? `<button class="btn" onclick="openLeadForm(${lead.id})">Edit</button>` : ''}
              ${currentUser.role === 'admin' ? `<button class="btn danger" onclick="deleteLead(${lead.id})">Delete</button>` : ''}
            </td>
          </tr>
        `;
      }).join('');
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
    
    async function viewLead(id) {
      const data = await api(`leads.list&q=`);
      const lead = data.items.find(l => l.id === id);
      if (!lead) return;
      
      const interactions = await api(`interactions.list&lead_id=${id}`);
      
      showModal(`
        <h3>${lead.name}</h3>
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
    
    function openLeadForm(id = null) {
      if (id) {
        api(`leads.list&q=`).then(data => {
          const lead = data.items.find(l => l.id === id);
          showLeadForm(lead);
        });
      } else {
        showLeadForm(null);
      }
    }
    
    function showLeadForm(lead) {
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
          Paste CSV data (Name, Phone, Email, Company, Address)
        </p>
        <form onsubmit="importLeads(event)">
          <div class="form-group">
            <textarea name="csv" placeholder="John Doe,+1234567890,john@example.com,Acme Corp,123 Main St&#10;Jane Smith,+0987654321,jane@example.com,Tech Inc,456 Oak Ave" rows="10" required></textarea>
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
          address: parts[4] || ''
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
        </div>
        <div class="card">
          <h2>Users</h2>
          <table id="usersTable">
            <thead>
              <tr>
                <th>Username</th>
                <th>Full Name</th>
                <th>Role</th>
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
      
      tbody.innerHTML = data.items.map(user => `
        <tr>
          <td><strong>${user.username}</strong></td>
          <td>${user.full_name}</td>
          <td><span class="badge ${user.role}">${user.role}</span></td>
          <td>${new Date(user.created_at).toLocaleDateString()}</td>
          <td>
            <button class="btn" onclick="openUserForm(${user.id})">Edit</button>
            ${user.id !== currentUser.id ? `<button class="btn danger" onclick="deleteUser(${user.id})">Delete</button>` : ''}
          </td>
        </tr>
      `).join('');
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
            <label>Username *</label>
            <input type="text" name="username" value="${user?.username || ''}" required>
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
      const data = {
        id,
        username: form.username.value,
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
    
    checkSession();
  </script>
</body>
</html>
