<?php
// mini_crm_postgres.php — Single-file Mini CRM (PHP + HTML + JS) using PostgreSQL
// Author: ChatGPT
// Notes: Drop this single file into your web root. Configure DB via env vars (see README in response).

// ==========================
// 0) CONFIG & BOOTSTRAP
// ==========================
$DB_HOST = getenv('CRM_DB_HOST') ?: '127.0.0.1';
$DB_PORT = getenv('CRM_DB_PORT') ?: '5432';
$DB_NAME = getenv('CRM_DB_NAME') ?: 'mini_crm';
$DB_USER = getenv('CRM_DB_USER') ?: 'mini_crm';
$DB_PASS = getenv('CRM_DB_PASS') ?: 'ChangeMe!';
$HTTPS_ONLY = false; // set true in production

if ($HTTPS_ONLY && (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on')) {
  // header('Location: https://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI']); exit;
}
header_remove('X-Powered-By');

function db() {
  static $pdo = null; global $DB_HOST,$DB_PORT,$DB_NAME,$DB_USER,$DB_PASS;
  if ($pdo) return $pdo;
  $dsn = "pgsql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};";
  $opt = [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC ];
  $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $opt);
  return $pdo;
}
function respond($data, $code=200, $ctype='application/json'){
  http_response_code($code); header('Content-Type: '.$ctype);
  if ($ctype==='application/json') { echo json_encode($data); } else { echo $data; }
  exit;
}
function body_json(){ return json_decode(file_get_contents('php://input'), true) ?: []; }
function normalize_phone($country,$number){ return preg_replace('/\D+/', '', ($country??'').($number??'')); }

function ensure_schema(){
  $pdo=db();
  $pdo->exec(<<<SQL
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
SQL);
  // Helpful indexes
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_contacts_company ON contacts (LOWER(BTRIM(COALESCE(company,''))))");
  $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_contacts_phone_unique ON contacts ((regexp_replace(COALESCE(phone_country,'')||COALESCE(phone_number,'') ,'\\D','','g'))) WHERE COALESCE(phone_country,'')<>'' AND COALESCE(phone_number,'')<>''");
}
ensure_schema();

// ==========================
// 1) API ROUTER
// ==========================
if (isset($_GET['api'])) {
  $a = $_GET['api'];
  try {
    switch ($a) {
      case 'stats': api_stats(); break;
      case 'countries': api_countries(); break;

      case 'contacts.list': api_contacts_list(); break;
      case 'contacts.save': api_contacts_save(); break;
      case 'contacts.delete': api_contacts_delete(); break;

      case 'calls.list': api_calls_list(); break;
      case 'calls.save': api_calls_save(); break;
      case 'calls.delete': api_calls_delete(); break;

      case 'projects.list': api_projects_list(); break;
      case 'projects.save': api_projects_save(); break;
      case 'projects.delete': api_projects_delete(); break;
      case 'projects.stage': api_projects_stage(); break; // update stage only

      case 'settings.get': api_settings_get(); break;
      case 'settings.set': api_settings_set(); break;

      case 'export': api_export(); break;
      case 'import': api_import(); break;
      case 'reset': api_reset(); break;

      default: respond(['error'=>'Unknown action'],404);
    }
  } catch (Throwable $e) { respond(['error'=>'Server error','detail'=>$e->getMessage()],500); }
}

// ======= API handlers =======
function COUNTRIES_DATA(){
  return [
    ['code'=>'+1','name'=>'United States'], ['code'=>'+1','name'=>'Canada'], ['code'=>'+44','name'=>'United Kingdom'],
    ['code'=>'+61','name'=>'Australia'], ['code'=>'+234','name'=>'Nigeria'], ['code'=>'+233','name'=>'Ghana'],
    ['code'=>'+27','name'=>'South Africa'], ['code'=>'+91','name'=>'India'], ['code'=>'+49','name'=>'Germany'],
    ['code'=>'+33','name'=>'France'], ['code'=>'+34','name'=>'Spain'], ['code'=>'+39','name'=>'Italy'],
    ['code'=>'+81','name'=>'Japan'], ['code'=>'+86','name'=>'China'], ['code'=>'+971','name'=>'UAE'],
    ['code'=>'+973','name'=>'Bahrain'], ['code'=>'+974','name'=>'Qatar'], ['code'=>'+966','name'=>'Saudi Arabia'],
    ['code'=>'+55','name'=>'Brazil'], ['code'=>'+52','name'=>'Mexico']
  ];
}
function api_countries(){ respond(['items'=>COUNTRIES_DATA()]); }

function api_stats(){ $p=db();
  $contacts=(int)$p->query("SELECT COUNT(*) FROM contacts")->fetchColumn();
  $calls7=(int)$p->query("SELECT COUNT(*) FROM calls WHERE when_at >= now()-interval '7 days'")->fetchColumn();
  $openProjects=(int)$p->query("SELECT COUNT(*) FROM projects WHERE COALESCE(stage,'') <> 'Won'")->fetchColumn();
  $recentContacts=$p->query("SELECT id,name,company,phone_country,phone_number FROM contacts ORDER BY id DESC LIMIT 5")->fetchAll();
  $recentCalls=$p->query("SELECT c.when_at,c.outcome,c.notes,co.name,co.company FROM calls c LEFT JOIN contacts co ON co.id=c.contact_id ORDER BY c.id DESC LIMIT 5")->fetchAll();
  respond(compact('contacts','calls7','openProjects','recentContacts','recentCalls'));
}

// Contacts
function api_contacts_list(){ $p=db(); $q=$_GET['q']??'';
  if($q!==''){
    $s=$p->prepare("SELECT * FROM contacts WHERE (name ILIKE :q OR company ILIKE :q OR email ILIKE :q OR (COALESCE(phone_country,'')||COALESCE(phone_number,'')) ILIKE :q) ORDER BY id DESC");
    $s->execute([':q'=>'%'.$q.'%']);
  } else { $s=$p->query("SELECT * FROM contacts ORDER BY id DESC"); }
  respond(['items'=>$s->fetchAll()]);
}
function api_contacts_save(){ $p=db(); $b=body_json();
  $id=$b['id']??null; $type=$b['type']??'Individual'; $company=trim($b['company']??'');
  $name=trim($b['name']??''); $email=trim($b['email']??''); $pc=$b['phoneCountry']??'';
  $pn=preg_replace('/\s+/','',$b['phoneNumber']??''); $source=trim($b['source']??'');
  $notes=trim($b['notes']??''); $now=date('c');

  // Duplicate check: by normalized phone and company (case-insensitive)
  $dup=null; $norm=normalize_phone($pc,$pn);
  if($norm){
    $sql="SELECT * FROM contacts WHERE (regexp_replace(COALESCE(phone_country,'')||COALESCE(phone_number,'') ,'\\D','','g') = :np)".($id?" AND id<>:id":"")." LIMIT 1";
    $s=$p->prepare($sql); $pr=[':np'=>$norm]; if($id) $pr[':id']=$id; $s->execute($pr); $dup=$s->fetch();
  }
  if(!$dup && $company!==''){
    $sql="SELECT * FROM contacts WHERE LOWER(BTRIM(company)) = LOWER(BTRIM(:c))".($id?" AND id<>:id":"")." LIMIT 1";
    $s=$p->prepare($sql); $pr=[':c'=>$company]; if($id) $pr[':id']=$id; $s->execute($pr); $dup=$s->fetch();
  }

  if($id){
    $s=$p->prepare("UPDATE contacts SET type=:t,company=:co,name=:n,email=:e,phone_country=:pc,phone_number=:pn,source=:s,notes=:no,updated_at=:u WHERE id=:id RETURNING *");
    $s->execute([':t'=>$type,':co'=>$company,':n'=>$name,':e'=>$email,':pc'=>$pc,':pn'=>$pn,':s'=>$source,':no'=>$notes,':u'=>$now,':id'=>$id]);
    $row=$s->fetch();
  } else {
    $s=$p->prepare("INSERT INTO contacts (type,company,name,email,phone_country,phone_number,source,notes,created_at,updated_at) VALUES (:t,:co,:n,:e,:pc,:pn,:s,:no,:c,:u) RETURNING *");
    $s->execute([':t'=>$type,':co'=>$company,':n'=>$name,':e'=>$email,':pc'=>$pc,':pn'=>$pn,':s'=>$source,':no'=>$notes,':c'=>$now,':u'=>$now]);
    $row=$s->fetch();
  }
  respond(['item'=>$row,'duplicate_of'=>$dup?($dup['company']?:$dup['name']):null]);
}
function api_contacts_delete(){ $p=db(); $id=(int)($_GET['id']??0);
  $p->prepare("DELETE FROM contacts WHERE id=:id")->execute([':id'=>$id]); respond(['ok'=>true]);
}

// Calls
function api_calls_list(){ $p=db(); $q=$_GET['q']??'';
  if($q!==''){
    $s=$p->prepare("SELECT c.*, co.name AS contact_name, co.company AS contact_company FROM calls c LEFT JOIN contacts co ON co.id=c.contact_id WHERE (co.name ILIKE :q OR co.company ILIKE :q OR c.notes ILIKE :q OR c.outcome ILIKE :q) ORDER BY c.id DESC");
    $s->execute([':q'=>'%'.$q.'%']);
  } else {
    $s=$p->query("SELECT c.*, co.name AS contact_name, co.company AS contact_company FROM calls c LEFT JOIN contacts co ON co.id=c.contact_id ORDER BY c.id DESC");
  }
  respond(['items'=>$s->fetchAll()]);
}
function api_calls_save(){ $p=db(); $b=body_json();
  $id=$b['id']??null; $cid=(int)($b['contactId']??0); $when=$b['when']??date('c');
  $outc=$b['outcome']??'Attempted'; $dur=(int)($b['durationMin']??0); $notes=trim($b['notes']??'');
  $now=date('c');

  if($id){
    $s=$p->prepare("UPDATE calls SET contact_id=:cid,when_at=:w,outcome=:o,duration_min=:d,notes=:n,updated_at=:u WHERE id=:id RETURNING *");
    $s->execute([':cid'=>$cid,':w'=>$when,':o'=>$outc,':d'=>$dur,':n'=>$notes,':u'=>$now,':id'=>$id]); $row=$s->fetch();
  } else {
    $s=$p->prepare("INSERT INTO calls (contact_id,when_at,outcome,duration_min,notes,created_at,updated_at) VALUES (:cid,:w,:o,:d,:n,:c,:u) RETURNING *");
    $s->execute([':cid'=>$cid,':w'=>$when,':o'=>$outc,':d'=>$dur,':n'=>$notes,':c'=>$now,':u'=>$now]); $row=$s->fetch();
  }
  respond(['item'=>$row]);
}
function api_calls_delete(){ $p=db(); $id=(int)($_GET['id']??0);
  $p->prepare("DELETE FROM calls WHERE id=:id")->execute([':id'=>$id]); respond(['ok'=>true]);
}

// Projects
function api_projects_list(){ $p=db();
  $s=$p->query("SELECT p.*, co.name AS contact_name, co.company AS contact_company FROM projects p LEFT JOIN contacts co ON co.id=p.contact_id ORDER BY p.id DESC");
  respond(['items'=>$s->fetchAll()]);
}
function api_projects_save(){ $p=db(); $b=body_json();
  $id=$b['id']??null; $cid=(int)($b['contactId']??0); $name=trim($b['name']??'');
  $value=(float)($b['value']??0); $stage=$b['stage']??'Lead'; $next=$b['next']??null; $notes=trim($b['notes']??'');
  $now=date('c');

  if($id){
    $s=$p->prepare("UPDATE projects SET contact_id=:cid,name=:n,value=:v,stage=:s,next_date=:nx,notes=:no,updated_at=:u WHERE id=:id RETURNING *");
    $s->execute([':cid'=>$cid,':n'=>$name,':v'=>$value,':s'=>$stage,':nx'=>$next,':no'=>$notes,':u'=>$now,':id'=>$id]); $row=$s->fetch();
  } else {
    $s=$p->prepare("INSERT INTO projects (contact_id,name,value,stage,next_date,notes,created_at,updated_at) VALUES (:cid,:n,:v,:s,:nx,:no,:c,:u) RETURNING *");
    $s->execute([':cid'=>$cid,':n'=>$name,':v'=>$value,':s'=>$stage,':nx'=>$next,':no'=>$notes,':c'=>$now,':u'=>$now]); $row=$s->fetch();
  }
  respond(['item'=>$row]);
}
function api_projects_delete(){ $p=db(); $id=(int)($_GET['id']??0);
  $p->prepare("DELETE FROM projects WHERE id=:id")->execute([':id'=>$id]); respond(['ok'=>true]);
}
function api_projects_stage(){ $p=db(); $b=body_json();
  $id=(int)($b['id']??0); $stage=$b['stage']??'Lead'; $now=date('c');
  $s=$p->prepare("UPDATE projects SET stage=:s, updated_at=:u WHERE id=:id RETURNING *");
  $s->execute([':s'=>$stage,':u'=>$now,':id'=>$id]); $row=$s->fetch(); respond(['item'=>$row]);
}

// Settings / Export / Import / Reset
function api_settings_get(){ $p=db(); $k=$_GET['key']??'';
  $s=$p->prepare("SELECT value FROM settings WHERE key=:k"); $s->execute([':k'=>$k]); $v=$s->fetchColumn();
  respond(['key'=>$k,'value'=>$v]);
}
function api_settings_set(){ $p=db(); $b=body_json(); $k=$b['key']??''; $v=$b['value']??'';
  $p->prepare("INSERT INTO settings(key,value) VALUES (:k,:v) ON CONFLICT(key) DO UPDATE SET value=EXCLUDED.value")->execute([':k'=>$k,':v'=>$v]);
  respond(['ok'=>true]);
}
function api_export(){ $p=db();
  $data=[
    'contacts'=>$p->query("SELECT * FROM contacts ORDER BY id")->fetchAll(),
    'calls'=>$p->query("SELECT * FROM calls ORDER BY id")->fetchAll(),
    'projects'=>$p->query("SELECT * FROM projects ORDER BY id")->fetchAll(),
    'settings'=>$p->query("SELECT * FROM settings ORDER BY key")->fetchAll(),
  ];
  header('Content-Disposition: attachment; filename=\"mini_crm_export.json\"');
  respond($data,200,'application/json');
}
function api_import(){ $p=db(); $b=body_json(); $p->beginTransaction();
  try {
    $p->exec("TRUNCATE calls, projects, contacts RESTART IDENTITY CASCADE");
    foreach(($b['contacts']??[]) as $r){
      $s=$p->prepare("INSERT INTO contacts (id,type,company,name,email,phone_country,phone_number,source,notes,created_at,updated_at) VALUES (:id,:t,:co,:n,:e,:pc,:pn,:s,:no,:c,:u)");
      $s->execute([':id'=>$r['id'],':t'=>$r['type'],':co'=>$r['company'],':n'=>$r['name'],':e'=>$r['email'],':pc'=>$r['phone_country'],':pn'=>$r['phone_number'],':s'=>$r['source'],':no'=>$r['notes'],':c'=>$r['created_at']??date('c'),':u'=>$r['updated_at']??date('c')]);
    }
    foreach(($b['projects']??[]) as $r){
      $s=$p->prepare("INSERT INTO projects (id,contact_id,name,value,stage,next_date,notes,created_at,updated_at) VALUES (:id,:cid,:n,:v,:s,:nx,:no,:c,:u)");
      $s->execute([':id'=>$r['id'],':cid'=>$r['contact_id'],':n'=>$r['name'],':v'=>$r['value'],':s'=>$r['stage'],':nx'=>$r['next_date'],':no'=>$r['notes'],':c'=>$r['created_at']??date('c'),':u'=>$r['updated_at']??date('c')]);
    }
    foreach(($b['calls']??[]) as $r){
      $s=$p->prepare("INSERT INTO calls (id,contact_id,when_at,outcome,duration_min,notes,created_at,updated_at) VALUES (:id,:cid,:w,:o,:d,:n,:c,:u)");
      $s->execute([':id'=>$r['id'],':cid'=>$r['contact_id'],':w'=>$r['when_at'],':o'=>$r['outcome'],':d'=>$r['duration_min'],':n'=>$r['notes'],':c'=>$r['created_at']??date('c'),':u'=>$r['updated_at']??date('c')]);
    }
    $p->exec("TRUNCATE settings");
    foreach(($b['settings']??[]) as $r){
      $p->prepare("INSERT INTO settings(key,value) VALUES (:k,:v)")->execute([':k'=>$r['key'],':v'=>$r['value']]);
    }
    $p->commit();
  } catch(Throwable $e){
    $p->rollBack(); respond(['error'=>'Import failed','detail'=>$e->getMessage()],400);
  }
  respond(['ok'=>true]);
}
function api_reset(){ $p=db(); $p->exec("TRUNCATE calls, projects, contacts, settings RESTART IDENTITY CASCADE"); respond(['ok'=>true]); }

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Mini CRM (PostgreSQL)</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root{--bg:#0f172a;--panel:#111827;--muted:#94a3b8;--text:#e5e7eb;--brand:#6d28d9;--brand-2:#8b5cf6;--ok:#10b981;--warn:#f59e0b;--bad:#ef4444;--border:#1f2937}
    *{box-sizing:border-box} body{margin:0;background:linear-gradient(180deg,var(--bg),#030712 60%);color:var(--text);font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial}
    .app{display:grid;grid-template-columns:280px 1fr;height:100vh}
    .sidebar{background:linear-gradient(180deg,#0b1220,#0a0f1a);border-right:1px solid var(--border);padding:20px;overflow:auto}
    .brand{display:flex;align-items:center;gap:12px;margin-bottom:20px}.logo{width:36px;height:36px;background:radial-gradient(circle at 30% 30%,var(--brand-2),var(--brand));border-radius:10px;box-shadow:0 4px 16px rgba(139,92,246,.3) inset,0 0 0 1px #2e1065}
    .nav{display:flex;flex-direction:column;gap:8px}
    .nav button{padding:10px 12px;border-radius:10px;border:1px solid var(--border);background:#0f172a;color:var(--text);text-align:left;cursor:pointer;font-weight:500}
    .nav button.active{background:radial-gradient(120% 140% at 0 0,rgba(139,92,246,.18),rgba(0,0,0,0) 60%),#0f172a;border-color:#3b0764}
    .content{padding:24px;overflow:auto}
    .toolbar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:16px}
    .btn{padding:10px 12px;border-radius:10px;background:var(--brand);color:#fff;border:1px solid #4c1d95;cursor:pointer;font-weight:600}
    .btn.secondary{background:#0f172a;color:var(--text);border:1px solid var(--border);font-weight:500}
    .btn.warn{background:var(--warn);border-color:#b45309}.btn.bad{background:var(--bad);border-color:#991b1b}.btn.ok{background:var(--ok);border-color:#065f46}
    .grid{display:grid;gap:16px}.grid.cols-3{grid-template-columns:repeat(3,1fr)}.grid.cols-2{grid-template-columns:repeat(2,1fr)}
    @media(max-width:900px){.app{grid-template-columns:1fr}}
    .card{background:linear-gradient(180deg,#0b1220,#0a0f1a);border:1px solid var(--border);border-radius:16px;padding:16px}
    .table{width:100%;border-collapse:collapse}.table th,.table td{border-bottom:1px solid var(--border);padding:10px 8px;text-align:left;font-size:14px;vertical-align:top}
    .search{flex:1;display:flex;align-items:center;gap:8px;padding:10px 12px;border-radius:10px;background:#0f172a;border:1px solid var(--border)}
    .search input{border:none;outline:none;background:transparent;padding:0;color:var(--text);width:100%}
    .kanban{display:grid;grid-template-columns:repeat(5,1fr);gap:12px}
    .kanban .col{background:#0b1220;border:1px solid var(--border);border-radius:14px;padding:10px;min-height:120px}
    .ticket{background:#0f172a;border:1px solid var(--border);border-radius:12px;padding:10px;margin:8px;cursor:grab}
    .badge{border:1px dashed #334155;color:#cbd5e1;background:#0b1220;border-radius:8px;padding:4px 8px;font-size:12px}
    .tiny{font-size:12px;color:#94a3b8}
    .toast{position:fixed;right:20px;bottom:20px;background:#1e293b;color:#e2e8f0;border:1px solid #334155;border-radius:12px;padding:12px 14px;box-shadow:0 10px 30px rgba(0,0,0,.4);opacity:0;transform:translateY(10px);transition:all .25s ease;z-index:100}
    .toast.show{opacity:1;transform:translateY(0)}
  </style>
</head>
<body>
<div class=\"app\">
  <aside class=\"sidebar\">
    <div class=\"brand\"><div class=\"logo\"></div><h1>Mini CRM</h1></div>
    <div class=\"nav\">
      <button data-view=\"dashboard\" class=\"active\">📊 Dashboard</button>
      <button data-view=\"contacts\">👥 Contacts</button>
      <button data-view=\"calls\">📞 Calls</button>
      <button data-view=\"projects\">📁 Projects</button>
      <button data-view=\"settings\">⚙️ Settings</button>
      <button id=\"exportBtn\" class=\"secondary\">⬇️ Export JSON</button>
      <button id=\"importBtn\" class=\"secondary\">⬆️ Import JSON</button>
      <input id=\"importFile\" type=\"file\" accept=\"application/json\" style=\"display:none\" />
    </div>
  </aside>
  <main class=\"content\">
    <div id=\"view-dashboard\" class=\"view\">
      <div class=\"toolbar\">
        <button class=\"btn\" onclick=\"openContactForm()\">+ Contact</button>
        <button class=\"btn\" onclick=\"openCallForm()\">+ Call</button>
        <button class=\"btn\" onclick=\"openProjectForm()\">+ Project</button>
        <div class=\"search\" style=\"margin-left:auto;max-width:420px\"><input id=\"globalSearch\" placeholder=\"Search everywhere\"/></div>
      </div>
      <div class=\"grid cols-3\">
        <div class=\"card\"><h3>Contacts</h3><div id=\"stat-contacts\" class=\"tiny\">0</div></div>
        <div class=\"card\"><h3>Calls (7d)</h3><div id=\"stat-calls7\" class=\"tiny\">0</div></div>
        <div class=\"card\"><h3>Open Projects</h3><div id=\"stat-projects\" class=\"tiny\">0</div></div>
      </div>
      <div class=\"grid cols-2\" style=\"margin-top:16px\">
        <div class=\"card\"><h3>Recent Contacts</h3><table class=\"table\" id=\"recentContacts\"></table></div>
        <div class=\"card\"><h3>Recent Calls</h3><table class=\"table\" id=\"recentCalls\"></table></div>
      </div>
    </div>

    <div id=\"view-contacts\" class=\"view\" style=\"display:none\">
      <div class=\"toolbar\">
        <button class=\"btn\" onclick=\"openContactForm()\">+ New Contact</button>
        <div class=\"search\"><input id=\"contactSearch\" placeholder=\"Search contacts\" oninput=\"renderContacts()\"></div>
      </div>
      <div class=\"card\"><table class=\"table\" id=\"contactsTable\"></table></div>
    </div>

    <div id=\"view-calls\" class=\"view\" style=\"display:none\">
      <div class=\"toolbar\">
        <button class=\"btn\" onclick=\"openCallForm()\">+ Log Call</button>
        <div class=\"search\"><input id=\"callSearch\" placeholder=\"Search calls\" oninput=\"renderCalls()\"></div>
      </div>
      <div class=\"card\"><table class=\"table\" id=\"callsTable\"></table></div>
    </div>

    <div id=\"view-projects\" class=\"view\" style=\"display:none\">
      <div class=\"toolbar\">
        <button class=\"btn\" onclick=\"openProjectForm()\">+ New Project</button>
        <div class=\"tiny\">Drag cards between stages</div>
      </div>
      <div class=\"kanban\" id=\"kanban\"></div>
      <div class=\"card\" style=\"margin-top:16px\"><h3>All Projects</h3><table class=\"table\" id=\"projectsTable\"></table></div>
    </div>

    <div id=\"view-settings\" class=\"view\" style=\"display:none\">
      <div class=\"card\">
        <h3>Default Country Code</h3>
        <div style=\"display:flex;gap:12px;align-items:center\">
          <select id=\"defaultCountry\"></select>
          <button class=\"btn ok\" onclick=\"saveDefaultCountry()\">Save</button>
        </div>
      </div>
      <div class=\"card\" style=\"margin-top:16px\">
        <h3>Danger Zone</h3>
        <button class=\"btn bad\" onclick=\"resetAll()\">Reset Database (truncate)</button>
      </div>
    </div>

    <div id=\"modal-root\"></div>
  </main>
</div>
<div class=\"toast\" id=\"toast\"></div>
<script>
const STAGES=['Lead','Qualified','Proposal','Negotiation','Won'];
let COUNTRIES=[];
function showToast(msg){const t=document.getElementById('toast');t.textContent=msg;t.classList.add('show');setTimeout(()=>t.classList.remove('show'),2200)}
function setActive(view){document.querySelectorAll('.nav button[data-view]').forEach(b=>b.classList.toggle('active',b.dataset.view===view));document.querySelectorAll('.view').forEach(v=>v.style.display='none');document.getElementById('view-'+view).style.display='block';if(view==='dashboard')refreshDashboard();if(view==='contacts')renderContacts();if(view==='calls')renderCalls();if(view==='projects'){renderProjects();renderKanban();}if(view==='settings')renderSettings();}
async function api(path,opts={}){const r=await fetch(`?api=${path}`,{headers:{'Content-Type':'application/json'},...opts});if(!r.ok){throw new Error(await r.text()||('HTTP '+r.status));}return r.json();}

// Dashboard
async function refreshDashboard(){const s=await api('stats');document.getElementById('stat-contacts').textContent=s.contacts+' total';document.getElementById('stat-calls7').textContent=s.calls7+' calls';document.getElementById('stat-projects').textContent=s.openProjects+' active';const rc=document.getElementById('recentContacts');rc.innerHTML=`<tr><th>Name</th><th>Company</th><th>Phone</th><th></th></tr>`+(s.recentContacts||[]).map(c=>`<tr><td><b>${c.name||'(no name)'}</b></td><td>${c.company||'-'}</td><td>${(c.phone_country||'')+' '+(c.phone_number||'')}</td><td><button class='btn secondary' onclick='openContactForm(${c.id})'>Edit</button></td></tr>`).join('');const rcl=document.getElementById('recentCalls');rcl.innerHTML=`<tr><th>When</th><th>Contact</th><th>Outcome</th><th>Notes</th></tr>`+(s.recentCalls||[]).map(k=>`<tr><td>${new Date(k.when_at).toLocaleString()}</td><td>${k.name||k.company||'Contact'}</td><td><span class='badge'>${k.outcome}</span></td><td>${k.notes||''}</td></tr>`).join('');}

// Contacts
async function renderContacts(){const q=(document.getElementById('contactSearch').value||'').trim();const res=await api('contacts.list'+(q?`&q=${encodeURIComponent(q)}`:''));const rows=res.items.map(c=>`<tr><td><b>${c.name||'(no name)'}</b><div class='tiny'>${c.email||''}</div></td><td>${c.company||'-'}<div class='tiny'>${c.type||''}</div></td><td>${(c.phone_country||'')+' '+(c.phone_number||'')}</td><td>${c.source||''}</td><td><button class='btn secondary' onclick='openCallForm(null,${c.id})'>Log Call</button> <button class='btn secondary' onclick='openProjectForm(null,${c.id})'>New Project</button> <button class='btn secondary' onclick='openContactForm(${c.id})'>Edit</button></td></tr>`).join('');document.getElementById('contactsTable').innerHTML=`<tr><th>Contact</th><th>Company</th><th>Phone</th><th>Source</th><th>Actions</th></tr>`+(rows||`<tr><td colspan=5 class='tiny'>No contacts yet</td></tr>`);}

// Calls
async function renderCalls(){const q=(document.getElementById('callSearch').value||'').trim();const res=await api('calls.list'+(q?`&q=${encodeURIComponent(q)}`:''));const rows=res.items.map(k=>`<tr><td>${new Date(k.when_at).toLocaleString()}</td><td>${k.contact_name||k.contact_company||'Contact'}</td><td>${k.outcome} ${k.duration_min?`(${k.duration_min}m)`:''}</td><td>${k.notes||''}</td><td><button class='btn secondary' onclick='openCallForm(${k.id})'>Edit</button></td></tr>`).join('');document.getElementById('callsTable').innerHTML=`<tr><th>When</th><th>Contact</th><th>Result</th><th>Notes</th><th>Actions</th></tr>`+(rows||`<tr><td colspan=5 class='tiny'>No calls</td></tr>`);}

// Projects (table)
async function renderProjects(){const res=await api('projects.list');const rows=res.items.map(p=>`<tr><td><b>${p.name||'(unnamed)'}</b><div class='tiny'>${p.contact_company||p.contact_name||''}</div></td><td>$${p.value||0}</td><td>${p.stage||''}</td><td>${p.next_date||'—'}</td><td>${p.notes||''}</td><td><button class='btn secondary' onclick='openProjectForm(${p.id})'>Edit</button></td></tr>`).join('');document.getElementById('projectsTable').innerHTML=`<tr><th>Project</th><th>Value</th><th>Stage</th><th>Next</th><th>Notes</th><th>Actions</th></tr>`+(rows||`<tr><td colspan=6 class='tiny'>No projects</td></tr>`);}

// Kanban (drag→drop)
async function renderKanban(){const data=(await api('projects.list')).items;const container=document.getElementById('kanban');container.innerHTML=STAGES.map(stage=>{const items=data.filter(p=>p.stage===stage).map(p=>`<div class='ticket' draggable='true' data-id='${p.id}' ondragstart='drag(event)'><div style=\"display:flex;justify-content:space-between\"><div><b>${p.name||'(unnamed)'}</b><div class='tiny'>${p.contact_company||p.contact_name||''}</div></div><div class='badge'>$${p.value||0}</div></div><div class='tiny'>Next: ${p.next_date||'—'}</div></div>`).join('');return `<div class='col' ondragover='allowDrop(event)' ondrop='dropStage(event,\"${stage}\")'><h4 class='tiny' style='text-transform:uppercase;letter-spacing:.08em'>${stage}</h4>${items||`<div class='tiny'>No items</div>`}</div>`;}).join('');}
function drag(ev){ev.dataTransfer.setData('text',ev.target.dataset.id);} function allowDrop(ev){ev.preventDefault();}
async function dropStage(ev,stage){ev.preventDefault();const id=Number(ev.dataTransfer.getData('text'));await api('projects.stage',{method:'POST',body:JSON.stringify({id,stage})});showToast('Moved to '+stage);renderKanban();renderProjects();}

// Settings
async function renderSettings(){ if(!COUNTRIES.length){ COUNTRIES=(await api('countries')).items; } const sel=document.getElementById('defaultCountry'); sel.innerHTML=COUNTRIES.map(c=>`<option value='${c.code}'>${c.name} (${c.code})</option>`).join(''); const cur=await api('settings.get&key=default_country'); sel.value=(cur.value||'+1'); }
async function saveDefaultCountry(){ const code=document.getElementById('defaultCountry').value; await api('settings.set',{method:'POST',body:JSON.stringify({key:'default_country',value:code})}); showToast('Default saved'); }
async function resetAll(){ if(!confirm('This will TRUNCATE all tables. Continue?')) return; await api('reset'); showToast('Database truncated'); refreshDashboard(); renderContacts(); renderCalls(); renderProjects(); renderKanban(); }

// Modal helpers
function modal(inner){ document.getElementById('modal-root').innerHTML=`<div style=\"position:fixed;inset:0;background:rgba(2,6,23,.6);display:flex;align-items:center;justify-content:center;z-index:50\"><div class='card' style='width:min(860px,96vw);max-height:90vh;overflow:auto'><div style='display:flex;justify-content:space-between;align-items:center;margin-bottom:8px'><div style='font-weight:700'>Modal</div><button class='btn secondary' onclick='closeModal()'>Close ✕</button></div>${inner}</div></div>`; }
function closeModal(){ document.getElementById('modal-root').innerHTML=''; }
function countrySelectHTML(id,value){ const opts=(COUNTRIES||[]).map(c=>`<option value='${c.code}' ${value===c.code?'selected':''}>${c.name} (${c.code})</option>`).join(''); return `<select id='${id}'>${opts}</select>`; }

// Contact Form
async function openContactForm(existingId=null){ if(!COUNTRIES.length){ COUNTRIES=(await api('countries')).items; } let c={ id:null,type:'Individual',company:'',name:'',email:'',phoneCountry:(await api('settings.get&key=default_country')).value||'+1',phoneNumber:'',source:'Cold Call',notes:'' }; if(existingId){ const res=await api('contacts.list'); c=res.items.find(x=>x.id==existingId)||c; c.phoneCountry=c.phone_country; c.phoneNumber=c.phone_number; }
  modal(`
    <h3>${existingId?'Edit Contact':'New Contact'}</h3>
    <div class='grid cols-2'>
      <div><label>Type</label><select id='c_type'><option ${c.type==='Individual'?'selected':''}>Individual</option><option ${c.type==='Company'?'selected':''}>Company</option></select></div>
      <div><label>Company</label><input id='c_company' value='${(c.company||'').replace(/\"/g,'&quot;')}' placeholder='Company name'></div>
      <div><label>Contact Name</label><input id='c_name' value='${(c.name||'').replace(/\"/g,'&quot;')}' placeholder='Full name'></div>
      <div><label>Email</label><input id='c_email' value='${(c.email||'').replace(/\"/g,'&quot;')}' type='email' placeholder='name@company.com'></div>
      <div><label>Country Code</label>${countrySelectHTML('c_phoneCountry',c.phoneCountry)}</div>
      <div><label>Phone Number</label><input id='c_phoneNumber' value='${c.phoneNumber||''}' placeholder='5551234567'></div>
      <div><label>Source</label><input id='c_source' value='${(c.source||'').replace(/\"/g,'&quot;')}'></div>
      <div><label>Notes</label><input id='c_notes' value='${(c.notes||'').replace(/\"/g,'&quot;')}'></div>
    </div>
    <div style='display:flex;gap:10px;justify-content:flex-end;margin-top:12px'>
      ${existingId?`<button class='btn bad' onclick='deleteContact(${existingId})'>Delete</button>`:''}
      <button class='btn secondary' onclick='closeModal()'>Cancel</button>
      <button class='btn ok' onclick='saveContact(${existingId??'null'})'>Save</button>
    </div>
  `);
}
async function saveContact(existingId){ const payload={ id:existingId, type:document.getElementById('c_type').value, company:document.getElementById('c_company').value.trim(), name:document.getElementById('c_name').value.trim(), email:document.getElementById('c_email').value.trim(), phoneCountry:document.getElementById('c_phoneCountry').value, phoneNumber:document.getElementById('c_phoneNumber').value.replace(/\\s+/g,''), source:document.getElementById('c_source').value.trim(), notes:document.getElementById('c_notes').value.trim() }; const res=await api('contacts.save',{method:'POST',body:JSON.stringify(payload)}); if(res.duplicate_of){ showToast('Duplicate found: '+res.duplicate_of); } closeModal(); showToast('Contact saved'); renderContacts(); refreshDashboard(); }
async function deleteContact(id){ await api('contacts.delete&id='+id); closeModal(); renderContacts(); refreshDashboard(); showToast('Contact deleted'); }

// Call Form
async function openCallForm(existingId=null,contactId=null){ const contacts=(await api('contacts.list')).items; let call={ id:null, contactId:contactId||contacts[0]?.id, when:new Date().toISOString().slice(0,16), outcome:'Attempted', durationMin:'', notes:'' }; if(existingId){ const all=(await api('calls.list')).items; const k=all.find(x=>x.id==existingId); if(k){ call={ id:k.id, contactId:k.contact_id, when:new Date(k.when_at).toISOString().slice(0,16), outcome:k.outcome, durationMin:k.duration_min, notes:k.notes }; } } const options=contacts.map(c=>`<option value='${c.id}'>${c.name||'(no name)'} — ${c.company||'Individual'}</option>`).join('');
  modal(`
    <h3>${existingId?'Edit Call':'Log Call'}</h3>
    <div class='grid cols-2'>
      <div><label>Contact</label><select id='k_contactId'>${options}</select></div>
      <div><label>Date/Time</label><input type='datetime-local' id='k_when' value='${call.when}'></div>
      <div><label>Outcome</label><select id='k_outcome'><option>Attempted</option><option>Connected</option><option>Voicemail</option><option>Bad Number</option></select></div>
      <div><label>Duration (min)</label><input id='k_duration' type='number' min='0' value='${call.durationMin||''}'></div>
    </div>
    <div><label>Notes</label><textarea id='k_notes' style='width:100%;min-height:90px'>${call.notes||''}</textarea></div>
    <div style='display:flex;gap:10px;justify-content:flex-end;margin-top:12px'>
      ${existingId?`<button class='btn bad' onclick='deleteCall(${existingId})'>Delete</button>`:''}
      <button class='btn secondary' onclick='closeModal()'>Cancel</button>
      <button class='btn ok' onclick='saveCall(${existingId??'null'})'>Save</button>
    </div>
  `);
  document.getElementById('k_contactId').value=call.contactId||''; document.getElementById('k_outcome').value=call.outcome;
}
async function saveCall(existingId){ const payload={ id:existingId, contactId:Number(document.getElementById('k_contactId').value), when:new Date(document.getElementById('k_when').value).toISOString(), outcome:document.getElementById('k_outcome').value, durationMin:Number(document.getElementById('k_duration').value||0), notes:document.getElementById('k_notes').value.trim() }; await api('calls.save',{method:'POST',body:JSON.stringify(payload)}); closeModal(); renderCalls(); refreshDashboard(); showToast('Call saved'); }
async function deleteCall(id){ await api('calls.delete&id='+id); closeModal(); renderCalls(); refreshDashboard(); showToast('Call deleted'); }

// Project Form
async function openProjectForm(existingId=null,contactId=null){ const contacts=(await api('contacts.list')).items; let p={ id:null, contactId:contactId||contacts[0]?.id, name:'', value:'', stage:'Lead', next:'', notes:'' }; if(existingId){ const all=(await api('projects.list')).items; const f=all.find(x=>x.id==existingId); if(f){ p={ id:f.id, contactId:f.contact_id, name:f.name, value:f.value, stage:f.stage||'Lead', next:f.next_date||'', notes:f.notes||'' }; } } const options=contacts.map(c=>`<option value='${c.id}'>${c.name||'(no name)'} — ${c.company||'Individual'}</option>`).join('');
  modal(`
    <h3>${existingId?'Edit Project':'New Project'}</h3>
    <div class='grid cols-2'>
      <div><label>Contact</label><select id='p_contactId'>${options}</select></div>
      <div><label>Project Name</label><input id='p_name' value='${(p.name||'').replace(/\"/g,'&quot;')}' placeholder='e.g., IT Support Contract'></div>
      <div><label>Value ($)</label><input id='p_value' type='number' min='0' value='${p.value||''}'></div>
      <div><label>Stage</label><select id='p_stage'>${STAGES.map(s=>`<option ${p.stage===s?'selected':''}>${s}</option>`).join('')}</select></div>
      <div><label>Next Follow-up</label><input id='p_next' type='date' value='${p.next||''}'></div>
      <div><label>Notes</label><input id='p_notes' value='${(p.notes||'').replace(/\"/g,'&quot;')}'></div>
    </div>
    <div style='display:flex;gap:10px;justify-content:flex-end;margin-top:12px'>
      ${existingId?`<button class='btn bad' onclick='deleteProject(${existingId})'>Delete</button>`:''}
      <button class='btn secondary' onclick='closeModal()'>Cancel</button>
      <button class='btn ok' onclick='saveProject(${existingId??'null'})'>Save</button>
    </div>
  `);
  document.getElementById('p_contactId').value=p.contactId||'';
}
async function saveProject(existingId){ const payload={ id:existingId, contactId:Number(document.getElementById('p_contactId').value), name:document.getElementById('p_name').value.trim(), value:Number(document.getElementById('p_value').value||0), stage:document.getElementById('p_stage').value, next:document.getElementById('p_next').value, notes:document.getElementById('p_notes').value.trim() }; await api('projects.save',{method:'POST',body:JSON.stringify(payload)}); closeModal(); renderProjects(); renderKanban(); refreshDashboard(); showToast('Project saved'); }
async function deleteProject(id){ await api('projects.delete&id='+id); closeModal(); renderProjects(); renderKanban(); refreshDashboard(); showToast('Project deleted'); }

// Import/Export/Reset JSON
document.getElementById('exportBtn').addEventListener('click',()=>{ window.location='?api=export'; });
document.getElementById('importBtn').addEventListener('click',()=>document.getElementById('importFile').click());
document.getElementById('importFile').addEventListener('change',async (e)=>{ const f=e.target.files[0]; if(!f) return; const txt=await f.text(); try{ const json=JSON.parse(txt); await api('import',{method:'POST',body:JSON.stringify(json)}); showToast('Import successful'); setActive('dashboard'); }catch(err){ showToast('Invalid JSON'); } });

// Nav
Array.from(document.querySelectorAll('.nav button[data-view]')).forEach(btn=>btn.addEventListener('click',()=>setActive(btn.dataset.view)));

// Init
(async function init(){ try{ COUNTRIES=(await api('countries')).items; await refreshDashboard(); }catch(e){ console.error(e); showToast('Init error'); } })();
</script>
</body>
</html>
