<?php
/**
 * LexiVault - Professional Vocabulary Management System
 * Single-file PHP Application
 * Storage: MySQL Database only
 * Developer: Vineet Pratap Singh (psvineet@zohomail.in | https://admin.xo.je/)
 */

// ============================================================
// CONFIGURATION
// ============================================================
define('APP_VERSION', '1.1.0');
define('DATA_DIR', __DIR__ . '/lexivault_data');
define('SESSION_NAME', 'lexivault_session');

session_name(SESSION_NAME);
session_start();

// ============================================================
// INITIALIZATION
// ============================================================
if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0755, true);
    // Create .htaccess to protect data dir
    file_put_contents(DATA_DIR . '/.htaccess', "Deny from all\n");
}

define('SYS_FILE', DATA_DIR . '/system.json');
$isSetup = file_exists(SYS_FILE);

function jsonResponse($data) {
    if (ob_get_length()) ob_clean(); // FIX: Safely clean buffer to prevent notices from breaking JSON
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo json_encode($data);
    exit;
}

// ============================================================
// SETUP WIZARD HANDLER
// ============================================================
if (isset($_GET['api']) && $_GET['api'] === 'setup_test_db') {
    try {
        $m = [
            'host' => $_POST['db_host'] ?? 'localhost',
            'port' => $_POST['db_port'] ?? '3306',
            'user' => $_POST['db_user'] ?? 'root',
            'pass' => $_POST['db_pass'] ?? ''
        ];
        $pdo = new PDO("mysql:host={$m['host']};port={$m['port']}", $m['user'], $m['pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        jsonResponse(['success'=>true, 'message'=>'Connection successful!']);
    } catch (Exception $e) {
        jsonResponse(['success'=>false, 'message'=>'Database connection failed: '.$e->getMessage()]);
    }
    exit;
}
if (isset($_GET['api']) && $_GET['api'] === 'setup') {
    $adminUser = $_POST['admin_user'] ?? 'admin';
    $adminPass = $_POST['admin_pass'] ?? 'admin123';
    $adminName = $_POST['admin_name'] ?? 'Administrator';

    $config = [
        'storage' => 'mysql', 
        'setup_complete' => true,
        'mysql' => [
            'host' => $_POST['db_host'] ?? 'localhost',
            'port' => $_POST['db_port'] ?? '3306',
            'db'   => $_POST['db_name'] ?? 'lexivault',
            'user' => $_POST['db_user'] ?? 'root',
            'pass' => $_POST['db_pass'] ?? ''
        ]
    ];

    try {
        $m = $config['mysql'];
        $pdo = new PDO("mysql:host={$m['host']};port={$m['port']}", $m['user'], $m['pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$m['db']}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$m['db']}`");

        $pdo->exec("CREATE TABLE IF NOT EXISTS users (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50), password VARCHAR(255), name VARCHAR(100), email VARCHAR(100), created_at DATETIME, last_login DATETIME)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS categories (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100), description TEXT, color VARCHAR(20))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS words (id INT AUTO_INCREMENT PRIMARY KEY, term VARCHAR(255), definition TEXT, pronunciation VARCHAR(100), word_class VARCHAR(100), notes TEXT, category_id INT, category_name VARCHAR(100), tags TEXT, difficulty VARCHAR(20), source VARCHAR(100), date_tag DATE, created_at DATETIME, updated_at DATETIME, last_reviewed DATETIME, review_count INT DEFAULT 0, mastered TINYINT(1) DEFAULT 0, starred TINYINT(1) DEFAULT 0)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (setting_key VARCHAR(50) PRIMARY KEY, setting_value TEXT)");

        $hash = password_hash($adminPass, PASSWORD_BCRYPT);
        $pdo->prepare("INSERT INTO users (username, password, name, created_at) VALUES (?, ?, ?, NOW())")->execute([$adminUser, $hash, $adminName]);
        
        $pdo->exec("INSERT INTO categories (name, description, color) VALUES ('General', 'General vocabulary', '#1e3a5f')");
        $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('words_per_page', '20'), ('timezone', 'UTC')");
        
    } catch (Exception $e) {
        jsonResponse(['success'=>false, 'message'=>'Database error: '.$e->getMessage()]);
    }

    file_put_contents(SYS_FILE, json_encode($config, JSON_PRETTY_PRINT));

    jsonResponse(['success'=>true]);
}

// ============================================================
// DB / STORAGE HELPERS
// ============================================================
function getSysConfig() {
    global $isSetup;
    if (!$isSetup) return ['storage'=>'mysql'];
    return json_decode(file_get_contents(SYS_FILE), true) ?? ['storage'=>'mysql'];
}

function db() {
    static $pdo;
    if ($pdo) return $pdo;
    $c = getSysConfig()['mysql'];
    if (empty($c['host'])) throw new Exception("Database not configured");
    $pdo = new PDO("mysql:host={$c['host']};port={$c['port']};dbname={$c['db']};charset=utf8mb4", $c['user'], $c['pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    return $pdo;
}

// ============================================================
// HELPER FUNCTIONS
// ============================================================
function getSettings() {
    $s = []; 
    try {
        foreach(db()->query("SELECT * FROM settings")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $v = $r['setting_value'];
            if ($v === 'true') $v = true; if ($v === 'false') $v = false;
            $s[$r['setting_key']] = $v;
        }
    } catch(Exception $e) {}
    return $s;
}
function saveSettings($s) {
    $db = db(); $db->exec("DELETE FROM settings");
    $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");
    foreach($s as $k=>$v) { if (is_bool($v)) $v = $v ? 'true' : 'false'; $stmt->execute([$k, (string)$v]); }
}
// Apply User Timezone globally right away to fix all date/time and chart bugs
if ($isSetup) {
    try {
        $appSettings = getSettings();
        if (!empty($appSettings['timezone'])) date_default_timezone_set($appSettings['timezone']);
        else date_default_timezone_set('UTC');

        // Seamlessly migrate existing database columns
        try {
            $colCheck = db()->query("SHOW COLUMNS FROM words LIKE 'part_of_speech'")->fetch();
            if ($colCheck) {
                db()->exec("ALTER TABLE words CHANGE part_of_speech word_class VARCHAR(100)");
            }
        } catch (Exception $e) {}
    } catch (Exception $e) {}
}
function getUsers() {
    return db()->query("SELECT * FROM users")->fetchAll(PDO::FETCH_ASSOC);
}
function saveUsersList($users) {
    $db = db();
    foreach($users as $u) {
        $stmt = $db->prepare("SELECT id FROM users WHERE id=?"); $stmt->execute([$u['id']]);
        if ($stmt->fetch()) {
            $db->prepare("UPDATE users SET username=?, password=?, name=?, email=?, last_login=? WHERE id=?")->execute([$u['username'], $u['password'], $u['name'], $u['email']??'', $u['last_login']??null, $u['id']]);
        } else {
            $db->prepare("INSERT INTO users (id, username, password, name, email, created_at, last_login) VALUES (?,?,?,?,?,?,?)")->execute([$u['id'], $u['username'], $u['password'], $u['name'], $u['email']??'', $u['created_at']??date('Y-m-d H:i:s'), $u['last_login']??null]);
        }
    }
}
function getCategories() {
    return db()->query("SELECT * FROM categories")->fetchAll(PDO::FETCH_ASSOC);
}
function saveCategory($c) {
    $db = db();
    if (!empty($c['id'])) {
        $db->prepare("UPDATE categories SET name=?, description=?, color=? WHERE id=?")->execute([$c['name'], $c['description'], $c['color'], $c['id']]);
        $db->prepare("UPDATE words SET category_name=? WHERE category_id=?")->execute([$c['name'], $c['id']]);
        return $c;
    } else {
        $db->prepare("INSERT INTO categories (name, description, color) VALUES (?,?,?)")->execute([$c['name'], $c['description'], $c['color']]);
        $c['id'] = $db->lastInsertId(); return $c;
    }
}
function deleteCategory($id) {
    db()->prepare("DELETE FROM categories WHERE id=?")->execute([$id]);
    db()->prepare("UPDATE words SET category_id=0, category_name='Uncategorized' WHERE category_id=?")->execute([$id]);
}
function getWordsList() {
    $words = db()->query("SELECT * FROM words")->fetchAll(PDO::FETCH_ASSOC);
    foreach($words as &$w) {
        $w['tags'] = $w['tags'] ? json_decode($w['tags'], true) : [];
        $w['mastered'] = (bool)$w['mastered']; $w['starred'] = (bool)$w['starred'];
        $w['review_count'] = (int)$w['review_count']; $w['category_id'] = (int)$w['category_id'];
    }
    return $words;
}
function saveWordObj($w) {
    $db = db(); $tags = json_encode($w['tags'] ?? []);
    $m = empty($w['mastered']) ? 0 : 1; $s = empty($w['starred']) ? 0 : 1; $rc = (int)($w['review_count'] ?? 0);
    if (!empty($w['id'])) {
        $db->prepare("UPDATE words SET term=?, definition=?, pronunciation=?, word_class=?, notes=?, category_id=?, category_name=?, tags=?, difficulty=?, source=?, updated_at=?, review_count=?, mastered=?, starred=?, last_reviewed=? WHERE id=?")->execute([
            $w['term'], $w['definition']??'', $w['pronunciation']??'', $w['word_class']??'', $w['notes']??'', $w['category_id']??0, $w['category_name']??'', $tags, $w['difficulty']??'medium', $w['source']??'', date('Y-m-d H:i:s'), $rc, $m, $s, $w['last_reviewed']??null, $w['id']
        ]);
        return $w;
    } else {
        // ID Recycling: Find the first available ID to prevent gaps.
        $recycled_id = null;
        $stmt = $db->prepare("SELECT 1 FROM words WHERE id = 1");
        $stmt->execute();
        if (!$stmt->fetch()) {
            $recycled_id = 1;
        } else {
            $stmt = $db->prepare("SELECT t1.id + 1 FROM words AS t1 LEFT JOIN words AS t2 ON t1.id + 1 = t2.id WHERE t2.id IS NULL ORDER BY t1.id LIMIT 1");
            $stmt->execute();
            $recycled_id = $stmt->fetchColumn();
        }

        $cols = ['term', 'definition', 'pronunciation', 'word_class', 'notes', 'category_id', 'category_name', 'tags', 'difficulty', 'source', 'date_tag', 'created_at', 'updated_at', 'review_count', 'mastered', 'starred'];
        $params = [
            $w['term'], $w['definition']??'', $w['pronunciation']??'', $w['word_class']??'', $w['notes']??'', $w['category_id']??0, $w['category_name']??'', $tags, $w['difficulty']??'medium', $w['source']??'', $w['date_tag']??date('Y-m-d'), $w['created_at']??date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $rc, $m, $s
        ];

        if ($recycled_id) {
            array_unshift($cols, 'id');
            array_unshift($params, $recycled_id);
            $w['id'] = $recycled_id;
        }

        $sql = "INSERT INTO words (" . implode(', ', $cols) . ") VALUES (" . rtrim(str_repeat('?,', count($cols)), ',') . ")";
        $db->prepare($sql)->execute($params);

        if (!$recycled_id) {
            $w['id'] = $db->lastInsertId();
        }
        return $w;
    }
}
function deleteWordObj($id) {
    db()->prepare("DELETE FROM words WHERE id=?")->execute([$id]);
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ?page=login');
        exit;
    }
}

function currentUser() {
    if (!isLoggedIn()) return null;
    $users = getUsers();
    foreach ($users as $u) {
        if ($u['id'] == $_SESSION['user_id']) return $u;
    }
    return null;
}

function sanitize($str) {
    return strip_tags(trim($str));
}

function sendDigestEmail($settings, $words) {
    if (empty($settings['smtp_host']) || empty($settings['digest_email'])) return false;

    // Determine base URL for links
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? 80) == 443) ? "https://" : "http://";
    $baseUrl = $protocol . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(str_replace('index.php', '', $_SERVER['SCRIPT_NAME'] ?? '/'), '/');

    $todayWords = array_filter($words, function($w) {
        return isset($w['date_tag']) && $w['date_tag'] === date('Y-m-d');
    });
    if (empty($todayWords)) {
        $todayWords = array_slice(array_reverse($words), 0, 10);
    }
    $subject = "[LexiVault] Daily Word Digest - " . date('F j, Y');
    // ---- Themed email template (creamy white + navy + gold) ----
    $body = "<!DOCTYPE html><html lang='en'><head>";
    $body .= "<meta charset='UTF-8'><meta name='viewport' content='width=device-width,initial-scale=1'>";
    $body .= "<link href='https://fonts.googleapis.com/css2?family=Noto+Sans:ital,wght@0,400;0,600;0,700;1,400&display=swap' rel='stylesheet'>";
    $body .= "<style>";
    $body .= "body{margin:0;padding:0;background:#f2ede0;font-family:'Noto Sans',Arial,sans-serif}";
    $body .= ".wrapper{max-width:600px;margin:0 auto;padding:20px 12px}";
    $body .= ".header{background:linear-gradient(135deg,#163459 0%,#1e4d87 100%);border-radius:12px 12px 0 0;padding:28px 32px;text-align:center}";
    $body .= ".header h1{color:#fff;margin:0;font-size:26px;font-weight:700;letter-spacing:-0.5px}";
    $body .= ".header .date{color:#b8d6f5;margin:6px 0 0;font-size:14px}";
    $body .= ".header .gold-bar{width:48px;height:3px;background:linear-gradient(90deg,#b8860b,#d4a017);border-radius:2px;margin:12px auto 0}";
    $body .= ".body-wrap{background:#fffef8;padding:28px 32px;border-radius:0 0 12px 12px;border:1px solid #e8e0cd;border-top:none}";
    $body .= ".intro{color:#3d3628;font-size:15px;margin:0 0 20px;line-height:1.5}";
    $body .= ".word-card{border-left:4px solid #1e4d87;padding:16px 20px;margin:16px 0;background:#f9f6ee;border-radius:0 8px 8px 0;border:1px solid #e5dece;border-left:4px solid #1e4d87}";
    $body .= ".word-term{color:#0d1f35;margin:0 0 4px;font-size:19px;font-weight:700;line-height:1.3}";
    $body .= ".word-term a{color:#0d1f35;text-decoration:none}";
    $body .= ".word-pron{color:#7d7260;font-size:13px;font-style:italic;font-weight:400}";
    $body .= ".word-def{color:#5a5144;margin:6px 0 0;font-size:14px;line-height:1.65}";
    $body .= ".word-notes{color:#a89e88;margin:8px 0 0;font-size:12px}";
    $body .= ".cat-badge{display:inline-block;background:#163459;color:#fff;padding:3px 12px;border-radius:12px;font-size:12px;font-weight:600;margin-top:10px;letter-spacing:0.3px}";
    $body .= ".divider{border:none;border-top:1px solid #e8e0cd;margin:24px 0}";
    $body .= ".footer{text-align:center;color:#a89e88;font-size:12px;margin-top:20px;padding-top:16px}";
    $body .= ".footer strong{color:#b8860b}";
    $body .= "@media only screen and (max-width:480px){";
    $body .= ".wrapper{padding:10px 4px}.header{padding:20px 18px;border-radius:8px 8px 0 0}.header h1{font-size:20px}";
    $body .= ".body-wrap{padding:18px 14px}.word-card{padding:12px 14px}";
    $body .= "}";
    $body .= "</style></head>";
    $body .= "<body><div class='wrapper'>";
    $body .= "<div class='header'>";
    $body .= "<h1>&#128218; LexiVault Daily Digest</h1>";
    $body .= "<p class='date'>" . date('l, F j, Y') . "</p>";
    $body .= "<div class='gold-bar'></div>";
    $body .= "</div>";
    $body .= "<div class='body-wrap'>";
    $body .= "<p class='intro'>Here are your words for today. Keep growing your vocabulary!</p>";
    $count = 0;
    foreach ($todayWords as $w) {
        $count++;
        $wordUrl = $baseUrl . '/?page=words&view_id=' . $w['id'];
        $body .= "<div class='word-card'>";
        $termHtml = "<a href='{$wordUrl}'>" . htmlspecialchars($w['term']) . "</a>";
        if (!empty($w['pronunciation'])) $termHtml .= " <span class='word-pron'>" . htmlspecialchars($w['pronunciation']) . "</span>";
        $body .= "<h3 class='word-term'>{$termHtml}</h3>";
        $body .= "<p class='word-def'>" . strip_tags($w['definition'] ?? '') . "</p>";
        if (!empty($w['notes'])) {
            $body .= "<p class='word-notes'><strong>Notes:</strong> " . htmlspecialchars($w['notes']) . "</p>";
        }
        if (!empty($w['category_name'])) {
            $body .= "<span class='cat-badge'>" . htmlspecialchars($w['category_name']) . "</span>";
        }
        $body .= "</div>";
    }
    $body .= "<hr class='divider'>";
    $body .= "<div class='footer'>Sent by <strong>LexiVault</strong> &bull; Your Personal Vocabulary Manager<br>Total words today: {$count}</div>";
    $body .= "</div></div></body></html>";

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: LexiVault <' . $settings['smtp_from'] . '>',
        'X-Mailer: PHP/' . phpversion()
    ];

    // Native SMTP Implementation
    if (!empty($settings['smtp_user']) && !empty($settings['smtp_pass'])) {
        try {
            $host = $settings['smtp_host'];
            $port = $settings['smtp_port'] ?? 587;
            
            // Handle implicit SSL (Port 465)
            $connHost = ($port == 465 && strpos($host, 'ssl://') === false) ? 'ssl://' . $host : $host;
            
            $socket = @fsockopen($connHost, $port, $errno, $errstr, 15);
            if ($socket) {
                stream_set_timeout($socket, 15);
                fread($socket, 256);
                
                $heloHost = preg_replace('/^ssl:\/\//', '', $host);
                fwrite($socket, "EHLO {$heloHost}\r\n");
                $res = fread($socket, 1024);

                // Handle STARTTLS for port 587
                if ($port == 587 && strpos($res, 'STARTTLS') !== false) {
                    fwrite($socket, "STARTTLS\r\n");
                    fread($socket, 256);
                    stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                    fwrite($socket, "EHLO {$heloHost}\r\n");
                    fread($socket, 1024);
                }

                fwrite($socket, "AUTH LOGIN\r\n");
                fread($socket, 256);
                fwrite($socket, base64_encode($settings['smtp_user']) . "\r\n");
                fread($socket, 256);
                fwrite($socket, base64_encode($settings['smtp_pass']) . "\r\n");
                $res = fread($socket, 256);

                if (substr($res, 0, 3) === '235') {
                    fwrite($socket, "MAIL FROM:<{$settings['smtp_from']}>\r\n");
                    fread($socket, 256);
                    fwrite($socket, "RCPT TO:<{$settings['digest_email']}>\r\n");
                    fread($socket, 256);
                    fwrite($socket, "DATA\r\n");
                    fread($socket, 256);

                    $message = "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
                    $message .= "To: {$settings['digest_email']}\r\n";
                    $message .= implode("\r\n", $headers) . "\r\n\r\n";
                    $message .= $body . "\r\n.\r\n";

                    fwrite($socket, $message);
                    $res = fread($socket, 256);

                    fwrite($socket, "QUIT\r\n");
                    fclose($socket);

                    if (substr($res, 0, 3) === '250') {
                        return true;
                    }
                } else {
                    fwrite($socket, "QUIT\r\n");
                    fclose($socket);
                }
            }
        } catch (Exception $e) {
            // Fallback to mail()
        }
    }

    // Fallback to mail()
    if (function_exists('mail')) {
        return mail($settings['digest_email'], $subject, $body, implode("\r\n", $headers));
    }
    return false;
}

// ============================================================
// API HANDLER
// ============================================================
if (isset($_GET['pbw'])) {
    if (ob_get_length()) ob_clean();
    header('Access-Control-Allow-Origin: *');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    
    $term = trim($_GET['pbw'] ?? '');
    $format = $_GET['format'] ?? 'text';
    $category = $_GET['category'] ?? '';
    $word_type = $_GET['word_type'] ?? '';

    if (empty($term) && empty($category) && empty($word_type)) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'message' => 'A search term, category, or word type is required.']);
        exit;
    }

    try {
        $sql = "SELECT * FROM words";
        $params = [];
        $where_clauses = [];
        $query_title_parts = [];

        if (!empty($term)) {
            $where_clauses[] = "term LIKE :like_term";
            $params[':like_term'] = '%' . $term . '%';
            $query_title_parts[] = 'term "' . htmlspecialchars($term) . '"';
        }

        if (!empty($category)) {
            if (is_numeric($category)) {
                $where_clauses[] = "category_id = :category";
                $params[':category'] = (int)$category;
            } else {
                $where_clauses[] = "category_name LIKE :category";
                $params[':category'] = $category;
            }
            $query_title_parts[] = 'category "' . htmlspecialchars($category) . '"';
        }

        if (!empty($word_type)) {
            $where_clauses[] = "word_class LIKE :word_type";
            $params[':word_type'] = $word_type;
            $query_title_parts[] = 'type "' . htmlspecialchars($word_type) . '"';
        }

        if (!empty($where_clauses)) {
            $sql .= " WHERE " . implode(' AND ', $where_clauses);
        }

        if (!empty($term)) {
            $sql .= " ORDER BY CASE WHEN term = :exact_term THEN 1 WHEN term LIKE :starts_with_term THEN 2 ELSE 3 END, LENGTH(term) ASC, term ASC";
            $params[':exact_term'] = $term;
            $params[':starts_with_term'] = $term . '%';
        } else {
            $sql .= " ORDER BY term ASC";
        }

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $query_title = implode(' and ', $query_title_parts);

        if ($results) {
            if ($format === 'json') {
                header('Content-Type: application/json; charset=UTF-8');
                $output_words = [];
                foreach ($results as $w) {
                    $output_words[] = ['term' => $w['term'], 'pronunciation' => $w['pronunciation'] ?? '', 'word_class' => $w['word_class'] ?? '', 'definition' => strip_tags($w['definition'] ?? '')];
                }
                echo json_encode(['success' => true, 'words' => $output_words]);
            } else {
                header('Content-Type: text/html; charset=UTF-8');
                echo '<!DOCTYPE html><html lang="en"><head><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Search Results</title><style>body{font-family: sans-serif; padding: 1em; background: #f9f9f9;} a{color: #1e4d87; text-decoration: none; font-weight: bold;} a:hover{text-decoration: underline;} p{margin: 0 0 1em 0;}</style></head><body>';
                echo '<h3>Found ' . count($results) . ' match(es) for ' . $query_title . ':</h3>';
                foreach ($results as $w) {
                    $link = isLoggedIn() ? '?page=words&view_id=' . $w['id'] : '?page=public_search&view_term=' . urlencode($w['term']);
                    echo '<p><a href="' . $link . '">' . htmlspecialchars($w['term']) . '</a>';
                    if (!empty($w['pronunciation'])) echo ' <i style="color:#555">(' . htmlspecialchars($w['pronunciation']) . ')</i>';
                    if (!empty($w['word_class'])) echo ' - <small style="color:#777">' . htmlspecialchars($w['word_class']) . '</small>';
                    echo '<br><span style="color:#333;font-size:0.9em">' . htmlspecialchars(strip_tags($w['definition'] ?? '')) . '</span>';
                    echo '</p>';
                }
                echo '</body></html>';
            }
        } else {
            if ($format === 'json') {
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode(['success' => false, 'message' => 'No words found.']);
            } else {
                header('Content-Type: text/html; charset=UTF-8');
                echo '<!DOCTYPE html><html lang="en"><head><title>Search Results</title><style>body{font-family: sans-serif; padding: 1em; background: #f9f9f9;}</style></head><body>';
                echo '<p>No words found matching ' . $query_title . '.</p>';
                echo '</body></html>';
            }
        }
    } catch (Exception $e) {
        header('Content-Type: text/html; charset=UTF-8');
        echo "An error occurred during lookup.";
    }
    exit;
}

if (isset($_GET['api']) && $_GET['api'] === 'public_autocomplete') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=UTF-8');
    header('Access-Control-Allow-Origin: *');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    $term = trim($_GET['term'] ?? '');

    if (strlen($term) < 2) {
        echo json_encode([]);
        exit;
    }

    try {
        $stmt = db()->prepare("
            SELECT term FROM words
            WHERE term LIKE :starts_with_term
            ORDER BY LENGTH(term) ASC, term ASC
            LIMIT 5
        ");
        // Using starts_with for better autocomplete suggestions
        $stmt->execute([':starts_with_term' => $term . '%']);
        $results = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode($results);
    } catch (Exception $e) {
        // On error, return an empty array to prevent breaking the frontend
        echo json_encode([]);
    }
    exit;
}

if (isset($_GET['api'])) {
    requireLogin();
    $action = $_GET['api'];
    $is_json_request = (strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false);
    $data = $is_json_request ? (json_decode(file_get_contents('php://input'), true) ?? []) : $_POST;

    try {
      switch ($action) {
        case 'words_list':
            $words = getWordsList();
            $cats = getCategories();
            $catMap = array_column($cats, 'name', 'id');
            foreach ($words as &$w) {
                $w['category_name'] = $catMap[$w['category_id'] ?? 0] ?? 'Uncategorized';
            }
            $search = $_GET['search'] ?? '';
            $category = $_GET['category'] ?? '';
            $date = $_GET['date'] ?? '';
            $tag = $_GET['tag'] ?? '';
            if ($search) {
                $words = array_filter($words, fn($w) =>
                    stripos($w['term'], $search) !== false ||
                    stripos(strip_tags($w['definition'] ?? ''), $search) !== false ||
                    stripos($w['notes'] ?? '', $search) !== false
                );
            }
            if ($category) {
                $words = array_filter($words, fn($w) => ($w['category_id'] ?? 0) == $category);
            }
            if ($date) {
                $words = array_filter($words, fn($w) => ($w['date_tag'] ?? '') === $date);
            }
            if ($tag) {
                $words = array_filter($words, fn($w) => in_array($tag, $w['tags'] ?? []));
            }
            $words = array_values($words);
            usort($words, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
            jsonResponse(['success' => true, 'words' => $words, 'total' => count($words)]);
            break;

        case 'word_get':
            $id = intval($_GET['id'] ?? 0);
            foreach (getWordsList() as $w) {
                if ($w['id'] == $id) { jsonResponse(['success' => true, 'word' => $w]); }
            }
            jsonResponse(['success' => false, 'message' => 'Word not found']);
            break;

        case 'word_save':
            $cats = getCategories();
            $catMap = array_column($cats, 'name', 'id');
            $term = trim($data['term'] ?? '');
            if (empty($term)) jsonResponse(['success' => false, 'message' => 'Term is required']);

            // Prevent duplicates on new word creation
            if (empty($data['id'])) {
                $stmt = db()->prepare("SELECT id FROM words WHERE LOWER(term) = LOWER(?)");
                $stmt->execute([$term]);
                if ($stmt->fetch()) {
                    jsonResponse(['success' => false, 'message' => 'This word already exists in your vault.']);
                }
            }

            $w = [];
            $existing_word = null;
            if (!empty($data['id'])) {
                $stmt = db()->prepare("SELECT * FROM words WHERE id=?");
                $stmt->execute([$data['id']]);
                $existing_word = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            $tags = array_filter(array_map('trim', explode(',', $data['tags'] ?? '')));
            $w = [
                'id' => $data['id'] ?? null,
                'term' => $term,
                'definition' => $data['definition'] ?? '',
                'pronunciation' => sanitize($data['pronunciation'] ?? ''),
                'word_class' => sanitize($data['word_class'] ?? ''),
                'notes' => $data['notes'] ?? '',
                'category_id' => (int)($data['category_id'] ?? 0),
                'category_name' => $catMap[intval($data['category_id'] ?? 0)] ?? 'Uncategorized',
                'tags' => array_values($tags),
                'difficulty' => sanitize($data['difficulty'] ?? 'medium'),
                'source' => sanitize($data['source'] ?? '')
            ];

            if ($existing_word) {
                $w['review_count'] = $existing_word['review_count'] ?? 0;
                $w['mastered'] = (bool)($existing_word['mastered'] ?? false);
                $w['starred'] = (bool)($existing_word['starred'] ?? false);
                $w['last_reviewed'] = $existing_word['last_reviewed'] ?? null;
                $w['date_tag'] = $existing_word['date_tag'] ?? date('Y-m-d');
                $w['created_at'] = $existing_word['created_at'] ?? date('Y-m-d H:i:s');
            } else {
                $w['date_tag'] = date('Y-m-d');
                $w['created_at'] = date('Y-m-d H:i:s');
            }

            try {
                $saved = saveWordObj($w);
                jsonResponse(['success' => true, 'word' => $saved]);
            } catch (Exception $e) {
                jsonResponse(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
            }
            break;

        case 'word_delete':
            $id = intval($data['id'] ?? 0);
            deleteWordObj($id);
            jsonResponse(['success' => true]);
            break;

        case 'word_toggle_star':
            $id = intval($data['id'] ?? 0);
            foreach (getWordsList() as $w) {
                if ($w['id'] == $id) {
                    $w['starred'] = !($w['starred'] ?? false);
                    saveWordObj($w);
                    jsonResponse(['success' => true, 'starred' => $w['starred']]);
                }
            }
            jsonResponse(['success' => false]);
            break;

        case 'word_toggle_mastered':
            $id = intval($data['id'] ?? 0);
            foreach (getWordsList() as $w) {
                if ($w['id'] == $id) {
                    $w['mastered'] = !($w['mastered'] ?? false);
                    saveWordObj($w);
                    jsonResponse(['success' => true, 'mastered' => $w['mastered']]);
                }
            }
            jsonResponse(['success' => false]);
            break;

        case 'word_increment_review':
            $id = intval($data['id'] ?? 0);
            foreach (getWordsList() as $w) {
                if ($w['id'] == $id) {
                    $w['review_count'] = ($w['review_count'] ?? 0) + 1;
                    $w['last_reviewed'] = date('Y-m-d H:i:s');
                    saveWordObj($w);
                    jsonResponse(['success' => true]);
                }
            }
            jsonResponse(['success' => false]);
            break;
            
        case 'words_bulk_action':
            $ids = $data['ids'] ?? [];
            $bulkAction = $data['bulk_action'] ?? '';
            if (empty($ids) || !is_array($ids)) jsonResponse(['success'=>false, 'message'=>'No words selected']);
            $count = 0;
            if ($bulkAction === 'delete') {
                foreach($ids as $id) { deleteWordObj($id); $count++; }
            } else {
                $words = getWordsList();
                $catMap = [];
                if ($bulkAction === 'categorize') {
                    $cats = getCategories();
                    $catMap = array_column($cats, 'name', 'id');
                }
                foreach ($words as $w) {
                    if (in_array($w['id'], $ids)) {
                        if ($bulkAction === 'master') $w['mastered'] = true;
                        if ($bulkAction === 'unmaster') $w['mastered'] = false;
                        if ($bulkAction === 'star') $w['starred'] = true;
                        if ($bulkAction === 'unstar') $w['starred'] = false;
                        if ($bulkAction === 'categorize') {
                            $cid = intval($data['category_id'] ?? 0);
                            $w['category_id'] = $cid;
                            $w['category_name'] = $catMap[$cid] ?? 'Uncategorized';
                        }
                        saveWordObj($w);
                        $count++;
                    }
                }
            }
            jsonResponse(['success'=>true, 'count'=>$count]);
            break;

        case 'categories_list':
            $cats = getCategories();
            $words = getWordsList();
            $counts = array_count_values(array_column($words, 'category_id'));
            foreach ($cats as &$c) {
                $c['word_count'] = $counts[$c['id']] ?? 0;
            }
            jsonResponse(['success' => true, 'categories' => $cats]);
            break;

        case 'category_save':
            $name = sanitize($data['name'] ?? '');
            if (empty($name)) jsonResponse(['success' => false, 'message' => 'Name required']);
            $c = [
                'id' => $data['id'] ?? null,
                'name' => $name,
                'color' => sanitize($data['color'] ?? '#1e3a5f'),
                'description' => sanitize($data['description'] ?? '')
            ];
            $saved = saveCategory($c);
            jsonResponse(['success' => true, 'category' => $saved]);
            break;

        case 'category_delete':
            $id = intval($data['id'] ?? 0);
            deleteCategory($id);
            jsonResponse(['success' => true]);
            break;

        case 'stats':
            $words = getWordsList();
            $cats = getCategories();
            $total = count($words);
            $mastered = count(array_filter($words, fn($w) => $w['mastered'] ?? false));
            $starred = count(array_filter($words, fn($w) => $w['starred'] ?? false));
            $today = count(array_filter($words, fn($w) => ($w['date_tag'] ?? '') === date('Y-m-d')));
            $week = count(array_filter($words, fn($w) => ($w['date_tag'] ?? '') >= date('Y-m-d', strtotime('-7 days'))));
            // Per category
            $catCounts = [];
            $catMap = array_column($cats, 'name', 'id');
            foreach ($words as $w) {
                $cid = $w['category_id'] ?? 0;
                $cname = $catMap[$cid] ?? 'Uncategorized';
                $catCounts[$cname] = ($catCounts[$cname] ?? 0) + 1;
            }
    
    // Strict Timezone Enforcement for Chart Accuracy
    $tz = getSettings()['timezone'] ?? 'UTC';
    date_default_timezone_set($tz);
    
    // Per day (Last 7 days only, today inclusive)
    $daily = [];
    $todayDate = date('Y-m-d');
    
    $dt = new DateTime($todayDate);
    $dt->modify('-6 days'); // Exactly 7 days inclusive
    
    for ($i = 0; $i < 7; $i++) {
        $daily[$dt->format('Y-m-d')] = 0;
        $dt->modify('+1 day');
    }
            foreach ($words as $w) {
                $d = $w['date_tag'] ?? '';
                if (isset($daily[$d])) $daily[$d]++;
            }
            // Dashboard features
            $most_opened = $words;
            usort($most_opened, fn($a,$b) => ($b['review_count'] ?? 0) <=> ($a['review_count'] ?? 0));
                        $most_opened = array_slice($most_opened, 0, 5);

            $today_learned = array_filter($words, fn($w) => ($w['mastered'] ?? false) && ($w['last_reviewed'] ?? $w['updated_at'] ?? '') >= date('Y-m-d', strtotime('-7 days')));
            usort($today_learned, fn($a,$b) => strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? ''));

            $wotd = null;
            if ($total > 0) {
                srand((int)date('Ymd'));
                $wotd = $words[rand(0, $total - 1)];
                srand(); // Reset
            }

            jsonResponse(['success' => true, 'stats' => [
                'total' => $total, 'mastered' => $mastered, 'starred' => $starred,
                'today' => $today, 'week' => $week, 'categories' => $catCounts, 'daily' => $daily,
                'most_opened' => $most_opened, 'today_learned' => array_values($today_learned),
                'wotd' => $wotd,
                'server_today' => $todayDate
            ]]);
            break;

        case 'export':
            $words = getWordsList();
            $format = $_GET['format'] ?? 'json';
            if ($format === 'csv') {
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="lexivault_export_' . date('Ymd') . '.csv"');
                $out = fopen('php://output', 'w');
                fputcsv($out, ['ID','Term','Definition','Category','Tags','Difficulty','Date','Source','Mastered','Starred','Review Count']);
                foreach ($words as $w) {
                    fputcsv($out, [
                        $w['id'], $w['term'], strip_tags($w['definition'] ?? ''),
                        $w['category_name'] ?? '', implode(';', $w['tags'] ?? []),
                        $w['difficulty'] ?? '', $w['date_tag'] ?? '',
                        $w['source'] ?? '', $w['mastered'] ? 'Yes' : 'No',
                        $w['starred'] ? 'Yes' : 'No', $w['review_count'] ?? 0
                    ]);
                }
                fclose($out);
                exit;
            } elseif ($format === 'sql') {
                header('Content-Type: application/sql');
                header('Content-Disposition: attachment; filename="lexivault_backup_' . date('Ymd') . '.sql"');
                $db = db();
                
                echo "-- LexiVault SQL Backup\n";
                echo "-- Generated on: " . date('Y-m-d H:i:s') . "\n\n";

                $tables = ['categories', 'words', 'settings'];
                foreach ($tables as $table) {
                    echo "--\n-- Table structure for table `$table`\n--\n";
                    echo "DROP TABLE IF EXISTS `$table`;\n";
                    $create_table_stmt = $db->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_ASSOC);
                    echo $create_table_stmt['Create Table'] . ";\n\n";

                    $data = $db->query("SELECT * FROM $table")->fetchAll(PDO::FETCH_ASSOC);
                    if (!empty($data)) {
                        echo "--\n-- Dumping data for table `$table`\n--\n";
                        foreach ($data as $row) {
                            // Don't export sensitive SMTP pass
                            if ($table === 'settings' && $row['setting_key'] === 'smtp_pass') {
                                continue;
                            }
                            $keys = array_keys($row);
                            $values = array_map([$db, 'quote'], array_values($row));
                            echo "INSERT INTO `$table` (`" . implode('`, `', $keys) . "`) VALUES (" . implode(', ', $values) . ");\n";
                        }
                        echo "\n";
                    }
                }
                exit;
            } else {
                header('Content-Type: application/json');
                header('Content-Disposition: attachment; filename="lexivault_export_' . date('Ymd') . '.json"');
                echo json_encode($words, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                exit;
            }
            break;

        case 'import':
            $raw = $data['data'] ?? '';
            if (empty($raw)) jsonResponse(['success' => false, 'message' => 'No data']);
            $imported = json_decode($raw, true);
            if (!is_array($imported)) jsonResponse(['success' => false, 'message' => 'Invalid JSON']);
            $count = 0;
            foreach ($imported as $w) {
                if (empty($w['term'])) continue;
                unset($w['id']); // Let saveWordObj handle ID
                $w['date_tag'] = $w['date_tag'] ?? date('Y-m-d');
                $w['created_at'] = $w['created_at'] ?? date('Y-m-d H:i:s');
                $w['updated_at'] = date('Y-m-d H:i:s');
                saveWordObj($w);
                $count++;
            }
            jsonResponse(['success' => true, 'imported' => $count]);
            break;

        case 'import_sql':
            $sql = $data['data'] ?? '';
            if (empty($sql)) jsonResponse(['success' => false, 'message' => 'No SQL data provided']);
            
            try {
                $db = db();
                $db->exec($sql);
                jsonResponse(['success' => true, 'message' => 'SQL data imported successfully.']);
            } catch (Exception $e) {
                jsonResponse(['success' => false, 'message' => 'SQL Import Error: ' . $e->getMessage()]);
            }
            break;

        case 'settings_get':
            $settings = getSettings();
            $settings['smtp_pass'] = !empty($settings['smtp_pass']) ? '••••••••' : '';
            jsonResponse(['success' => true, 'settings' => $settings]);
            break;

        case 'settings_save':
            $settings = getSettings();
            foreach (['smtp_host','smtp_port','smtp_user','smtp_from','digest_email','digest_time','timezone'] as $k) {
                if (isset($data[$k])) $settings[$k] = sanitize($data[$k]);
            }
            if (!empty($data['smtp_pass']) && $data['smtp_pass'] !== '••••••••') {
                $settings['smtp_pass'] = $data['smtp_pass'];
            }
            $settings['digest_enabled'] = !empty($data['digest_enabled']);
            $settings['words_per_page'] = intval($data['words_per_page'] ?? 20);
            saveSettings($settings);
            jsonResponse(['success' => true]);
            break;

        case 'send_test_digest':
            $settings = getSettings();
            $words = getWordsList();
            $result = sendDigestEmail($settings, $words);
            jsonResponse(['success' => $result, 'message' => $result ? 'Email sent' : 'Failed to send email']);
            break;

        case 'profile_update':
            $users = getUsers();
            foreach ($users as &$u) {
                if ($u['id'] == $_SESSION['user_id']) {
                    if (!empty($data['name'])) $u['name'] = sanitize($data['name']);
                    if (!empty($data['email'])) $u['email'] = sanitize($data['email']);
                    if (!empty($data['new_password'])) {
                        if (!password_verify($data['current_password'] ?? '', $u['password'])) {
                            jsonResponse(['success' => false, 'message' => 'Current password incorrect']);
                        }
                        $u['password'] = password_hash($data['new_password'], PASSWORD_BCRYPT);
                    }
                    saveUsersList($users);
                    $_SESSION['user_name'] = $u['name'];
                    jsonResponse(['success' => true]);
                }
            }
            jsonResponse(['success' => false]);
            break;

        case 'random_word':
            $words = getWordsList();
            if (empty($words)) jsonResponse(['success' => false, 'message' => 'No words found']);
            $excludeId = (int)($_GET['exclude_id'] ?? 0);
            if ($excludeId && count($words) > 1) {
                $words = array_values(array_filter($words, fn($w) => (int)$w['id'] !== $excludeId));
            }
            $w = $words[array_rand($words)];
            jsonResponse(['success' => true, 'word' => $w]);
            break;

        case 'all_tags':
            $words = getWordsList();
            $tags = [];
            foreach ($words as $w) {
                foreach ($w['tags'] ?? [] as $t) {
                    if (!in_array($t, $tags)) $tags[] = $t;
                }
            }
            sort($tags);
            jsonResponse(['success' => true, 'tags' => $tags]);
            break;

        default:
            jsonResponse(['success' => false, 'message' => 'Unknown action']);
      }
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => 'API Error: ' . $e->getMessage()]);
    }
    
    exit;
}

// ============================================================
// AUTH HANDLER
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'login') {
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            if (!empty($username) && !empty($password)) {
                $users = getUsers();
                foreach ($users as $u) {
                    if ($u['username'] === $username && password_verify($password, $u['password'])) {
                        $_SESSION['user_id'] = $u['id'];
                        $_SESSION['user_name'] = $u['name'];
                        $_SESSION['username'] = $u['username'];
                        // Record last login
                        $users_copy = $users;
                        foreach ($users_copy as &$uu) {
                            if ($uu['id'] == $u['id']) $uu['last_login'] = date('Y-m-d H:i:s');
                        }
                        saveUsersList($users_copy);
                        header('Location: ?page=dashboard');
                        exit;
                    }
                }
            }
            $loginError = 'Invalid username or password.';
        }
        if ($_POST['action'] === 'logout') {
            session_destroy();
            header('Location: ?page=login');
            exit;
        }
    }
}

$page = $_GET['page'] ?? (isLoggedIn() ? 'dashboard' : 'login');
if (isLoggedIn() && $page === 'public_search' && isset($_GET['view_term'])) {
    try {
        $term = $_GET['view_term'];
        $stmt = db()->prepare("SELECT id FROM words WHERE term = ? LIMIT 1");
        $stmt->execute([$term]);
        $word = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($word) {
            header('Location: ?page=words&view_id=' . $word['id']);
            exit;
        } else {
            header('Location: ?page=words&q=' . urlencode($term) . '&focus_search=1');
            exit;
        }
    } catch (Exception $e) {
        // DB not ready or other error, just go to dashboard
        header('Location: ?page=dashboard');
        exit;
    }
}

if (!isLoggedIn() && !in_array($page, ['login', 'public_search'])) {
    header('Location: ?page=login');
    exit;
}

// Digest trigger via secret endpoint
if (isset($_GET['digest_trigger']) && $_GET['digest_trigger'] === 'p3uiCUO4eF8wICzuQ84WPGNP37q_I6FADptXUYyL1pk') {
    try {
        $settings = getSettings();
        $words = getWordsList();
        $result = sendDigestEmail($settings, $words);
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => $result, 'message' => $result ? 'Digest sent successfully' : 'Failed - check SMTP settings']);
        exit;
    } catch (Exception $e) {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

$appName = 'LexiVault';
$wordsPerPage = 20;
if ($isSetup) {
    try {
        $set = getSettings();
        $wordsPerPage = intval($set['words_per_page'] ?? 20);
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title><?= htmlspecialchars($appName) ?></title>
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A//www.w3.org/2000/svg%22%20viewBox%3D%220%200%2032%2032%22%3E%3Crect%20width%3D%2232%22%20height%3D%2232%22%20rx%3D%228%22%20fill%3D%22%23faf3dc%22/%3E%3Crect%20x%3D%229%22%20y%3D%226%22%20width%3D%2214%22%20height%3D%2220%22%20rx%3D%223%22%20fill%3D%22none%22%20stroke%3D%22%230A1628%22%20stroke-width%3D%222%22/%3E%3Ccircle%20cx%3D%2216%22%20cy%3D%2214%22%20r%3D%222.4%22%20fill%3D%22none%22%20stroke%3D%22%230A1628%22%20stroke-width%3D%222%22/%3E%3Cline%20x1%3D%2216%22%20y1%3D%2216.8%22%20x2%3D%2216%22%20y2%3D%2219.5%22%20stroke%3D%22%230A1628%22%20stroke-width%3D%222%22%20stroke-linecap%3D%22round%22/%3E%3C/svg%3E">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">
<link href="https://cdn.quilljs.com/1.3.7/quill.snow.css" rel="stylesheet">
<script src="https://cdn.quilljs.com/1.3.7/quill.min.js"></script>
<style>
:root {
  /* Navy palette (accent color) */
  --navy-950: #040e1c;
  --navy-900: #0d1f35;
  --navy-800: #0f2847;
  --navy-700: #163459;
  --navy-600: #1a4070;
  --navy-500: #1e4d87;
  --navy-400: #2a6baf;
  --navy-300: #4a8fd4;
  --navy-200: #7ab3e8;
  --navy-100: #d6e6f7;
  --navy-50: #edf4fb;
  /* Creamy white palette */
  --white: #fffef8;
  --cream: #fdfcf9;
  --cream-dark: #f7f5ef;
  --cream-border: #ece6d8;
  /* Gold accent */
  --gold: #b8860b;
  --gold-dark: #8a6a14;
  --gold-light: #d4a017;
  --gold-pale: #faf3dc;
  --gold-border: #e8c96a;
  /* Grays (warm-tinted) */
  --gray-50: #fbfaf6;
  --gray-100: #f2ede0;
  --gray-200: #e5dece;
  --gray-300: #ccc4b0;
  --gray-400: #a89e88;
  --gray-500: #7d7260;
  --gray-600: #5a5144;
  --gray-700: #3d3628;
  --gray-800: #1e1a10;
  /* Primary accent: navy blue */
  --accent: #1e4d87;
  --accent-light: #2a6baf;
  /* States */
  --success: #1a7c5c;
  --success-light: #e8f7f2;
  --warning: #c47c0a;
  --warning-light: #fef9ec;
  --danger: #c0392b;
  --danger-light: #fef2f0;
  /* Layout */
  --sidebar-w: 250px;
  --header-h: 52px;
  --radius: 10px;
  --radius-sm: 6px;
  /* Shadows (warm-tinted) */
  --shadow-sm: 0 2px 8px rgba(20,24,32,0.05);
  --shadow: 0 6px 18px rgba(20,24,32,0.08);
  --shadow-lg: 0 12px 32px rgba(20,24,32,0.12);
  /* Transitions: slightly faster = lighter feel */
  --transition: all 0.12s ease;
  /* Glass: lighter blur, warm tint */
  --glass-bg: rgba(255,254,248,0.82);
  --glass-border: 1px solid rgba(232,224,205,0.75);
  --glass-shadow: 0 4px 16px rgba(30,18,5,0.06);
}

.autocomplete-items {
  position: absolute;
  border: 1px solid rgba(255,255,255,0.2);
  border-top: none;
  z-index: 99;
  top: 100%;
  left: 0;
  right: 0;
  background: var(--white);
  border: 1px solid var(--gray-200);
  border-radius: 0 0 var(--radius) var(--radius);
  box-shadow: var(--shadow-lg);
  overflow: hidden;
}
.autocomplete-items div {
  padding: 10px 16px;
  cursor: pointer;
  color: var(--gray-800);
  font-size: 14px;
  border-bottom: 1px solid var(--cream-border);
}
.autocomplete-items div:last-child { border-bottom: none; }
.autocomplete-items div:hover {
  background-color: var(--gray-50);
  color: var(--accent);
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

html { font-size: 15px; scroll-behavior: smooth; }

body {
  font-family: 'Noto Sans', sans-serif;
  background: var(--cream);
  background-attachment: fixed;
  color: var(--gray-800);
  min-height: 100vh;
  line-height: 1.6;
  -webkit-font-smoothing: antialiased;
}

/* ---- SCROLLBAR ---- */
::-webkit-scrollbar { width: 5px; height: 0 !important; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--navy-200); border-radius: 3px; }
* { scrollbar-width: none; -ms-overflow-style: none; }
*::-webkit-scrollbar:horizontal { display: none !important; height: 0 !important; }

/* ---- LOGIN PAGE ---- */
.login-page {
  min-height: 100vh;
  display: flex;
  background: var(--cream);
  position: relative;
  overflow: hidden;
  padding: 20px;
}
.login-page::before {
  content: '';
  position: absolute;
  inset: 0;
  background: radial-gradient(ellipse at 20% 50%, rgba(46,125,209,0.05) 0%, transparent 60%),
              radial-gradient(ellipse at 80% 20%, rgba(26,64,112,0.05) 0%, transparent 50%);
}
.login-grid-bg {
  position: absolute;
  inset: 0;
  background-image: linear-gradient(rgba(0,0,0,0.03) 1px, transparent 1px),
                    linear-gradient(90deg, rgba(0,0,0,0.03) 1px, transparent 1px);
  background-size: 40px 40px;
}
.login-panel {
  position: relative;
  z-index: 10;
  margin: auto;
  background: var(--white);
  border: 1px solid var(--cream-border);
  border-radius: 20px;
  padding: 48px 40px;
  width: 100%;
  max-width: 440px;
  box-shadow: 0 16px 48px rgba(13,31,53,0.08);
}
.login-logo {
  text-align: center;
  margin-bottom: 36px;
}
.login-logo .logo-mark {
  width: 60px;
  height: 60px;
  background: var(--gold-pale, #f7ecd2);
  border: 1px solid var(--cream-border);
  border-radius: 16px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 16px;
  box-shadow: 0 3px 10px rgba(201,168,76,0.25);
}
.login-logo h1 {
  font-size: 28px;
  font-weight: 700;
  color: var(--navy-900);
  letter-spacing: -0.5px;
}
.login-logo p {
  color: var(--gray-500);
  font-size: 14px;
  margin-top: 4px;
}
.login-error {
  background: var(--danger-light);
  border: 1px solid rgba(192,57,43,0.3);
  color: var(--danger);
  padding: 12px 16px;
  border-radius: var(--radius-sm);
  font-size: 13px;
  margin-bottom: 20px;
  display: flex;
  align-items: center;
  gap: 8px;
}
.form-group { margin-bottom: 20px; }
.form-group label {
  display: block;
  color: var(--gray-600);
  font-size: 12px;
  font-weight: 500;
  letter-spacing: 0.8px;
  text-transform: uppercase;
  margin-bottom: 8px;
}
.form-group input {
  width: 100%;
  background: var(--white);
  border: 1px solid var(--gray-200);
  border-radius: var(--radius-sm);
  padding: 12px 16px;
  color: var(--gray-800);
  font-size: 14px;
  font-family: inherit;
  transition: var(--transition);
  outline: none;
}
.form-group input:focus {
  border-color: var(--accent);
  background: var(--white);
  box-shadow: 0 0 0 3px rgba(46,125,209,0.1);
}
.form-group input::placeholder { color: var(--gray-400); }
.btn-login {
  width: 100%;
  padding: 13px;
  background: var(--navy-700);
  color: var(--white);
  border: none;
  border-radius: var(--radius-sm);
  font-size: 14px;
  font-weight: 600;
  letter-spacing: 0.3px;
  cursor: pointer;
  transition: var(--transition);
  font-family: inherit;
  margin-top: 4px;
}
.btn-login:hover {
  transform: translateY(-1px);
  background: var(--navy-800);
  box-shadow: 0 6px 16px rgba(22,52,89,0.35);
}
.login-hint {
  text-align: center;
  margin-top: 24px;
  color: var(--gray-500);
  font-size: 12px;
}

/* ---- APP LAYOUT ---- */
.app-layout {
  display: flex;
  min-height: 100vh;
}

/* ---- SIDEBAR ---- */
.sidebar {
  width: var(--sidebar-w);
  background: var(--cream);
  position: fixed;
  top: 0; left: 0; bottom: 0;
  z-index: 100;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  transition: transform 0.2s ease;
  border-right: 1px solid var(--cream-border);
}
.sidebar-logo {
  height: var(--header-h);
  padding: 0 24px;
  display: flex;
  align-items: center;
  gap: 12px;
  box-sizing: border-box;
  border-bottom: 1px solid var(--cream-border);
}
.sidebar-logo .logo-mark {
  width: 32px; height: 32px;
  background: var(--gold-pale, #f7ecd2);
  border: 1px solid var(--cream-border);
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
  box-shadow: 0 2px 6px rgba(201,168,76,0.2);
}
.sidebar-logo h2 {
  font-size: 19px;
  font-weight: 700;
  color: var(--navy-900);
  letter-spacing: -0.3px;
}
.sidebar-logo span {
  font-size: 11px;
  color: var(--gray-500);
  display: block;
  margin-top: -2px;
  font-weight: 500;
}
.sidebar-nav {
  flex: 1;
  overflow-y: auto;
  padding: 24px 16px;
}
.sidebar-section {
  margin-bottom: 28px;
}
.sidebar-section-label {
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 1px;
  text-transform: uppercase;
  color: var(--gray-400);
  padding: 0 10px;
  margin-bottom: 8px;
}
.nav-item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  margin-bottom: 4px;
  border-radius: var(--radius-sm);
  color: var(--gray-600);
  text-decoration: none;
  font-size: 14px;
  font-weight: 500;
  cursor: pointer;
  transition: var(--transition);
  border: none;
  background: none;
  width: 100%;
  text-align: left;
}
.nav-item:hover {
  background: var(--gray-50);
  color: var(--navy-900);
}
.nav-item.active {
  background: var(--gold-pale);
  color: var(--gold);
  font-weight: 600;
  border-left: 3px solid var(--gold);
  padding-left: 9px;
}
.nav-item.active svg { color: var(--gold); }
.nav-item svg { width: 18px; height: 18px; flex-shrink: 0; color: var(--gray-400); transition: var(--transition); }
.nav-item:hover svg { color: var(--gray-600); }
.nav-item.active:hover svg { color: var(--gold); }
.nav-badge {
  margin-left: auto;
  background: var(--navy-600);
  color: white;
  font-size: 10px;
  font-weight: 600;
  padding: 2px 7px;
  border-radius: 20px;
  min-width: 20px;
  text-align: center;
}
.sidebar-user {
  padding: 16px 20px;
  border-top: 1px solid var(--cream-border);
  display: flex;
  align-items: center;
  gap: 10px;
  background: var(--cream-dark);
}
.user-avatar {
  width: 36px; height: 36px;
  background: linear-gradient(160deg, var(--gold-pale, #faf3dc), var(--cream-border, #ece6d8));
  border: 1px solid var(--gold, #c9a84c);
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  color: var(--navy-900);
  font-weight: 700;
  font-size: 13px;
  flex-shrink: 0;
  box-shadow: 0 2px 6px rgba(201,168,76,0.25);
}
.user-info { flex: 1; min-width: 0; }
.user-info .uname {
  color: var(--navy-900);
  font-size: 13.5px;
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.user-info .urole {
  color: var(--gray-500);
  font-size: 11px;
}
.logout-btn {
  background: var(--white);
  border: 1px solid var(--gray-200);
  color: var(--gray-500);
  cursor: pointer;
  padding: 6px;
  transition: var(--transition);
  display: flex;
  border-radius: var(--radius-sm);
}
.logout-btn:hover { color: var(--danger); border-color: var(--danger-light); background: var(--danger-light); }

/* ---- MAIN CONTENT ---- */
.main-content {
  margin-left: var(--sidebar-w);
  flex: 1;
  display: flex;
  flex-direction: column;
  min-height: 100vh;
  min-width: 0;
  overflow-x: hidden;
}

/* ---- HEADER ---- */
.app-header {
  transform: translateZ(0);
  will-change: transform;
  height: var(--header-h);
  background: var(--cream);
  border-bottom: 1px solid var(--cream-border);
  display: flex;
  align-items: center;
  padding: 0 20px;
  gap: 12px;
  position: sticky;
  top: 0;
  z-index: 50;
  box-shadow: var(--shadow-sm);
}
.header-title {
  font-size: 16px;
  font-weight: 700;
  color: var(--navy-900);
  flex: 1;
  letter-spacing: -0.2px;
}
.header-actions { display: flex; align-items: center; gap: 10px; }
.hamburger {
  display: none;
  position: relative;
  width: 34px; height: 34px;
  background: var(--cream-dark);
  border: 1px solid var(--cream-border);
  cursor: pointer;
  border-radius: 8px;
  margin-right: 4px;
  flex-shrink: 0;
  transition: background 0.15s ease, border-color 0.15s ease;
}
.hamburger:hover { background: var(--gold-pale, #f7ecd2); border-color: var(--gold); }
.hamburger-bar {
  position: absolute;
  left: 8px; right: 8px;
  height: 2px;
  border-radius: 2px;
  background: var(--navy-900);
  transition: transform 0.25s cubic-bezier(0.4,0,0.2,1), opacity 0.2s ease, top 0.25s ease;
}
.hamburger .bar1 { top: 11px; }
.hamburger .bar2 { top: 16px; }
.hamburger .bar3 { top: 21px; }
.hamburger.is-open .bar1 { top: 16px; transform: rotate(45deg); }
.hamburger.is-open .bar2 { opacity: 0; }
.hamburger.is-open .bar3 { top: 16px; transform: rotate(-45deg); }

/* ---- PAGE CONTENT ---- */
.page-content {
  flex: 1;
  padding: 28px;
  width: 100%;
  max-width: none;
  box-sizing: border-box;
  overflow-x: hidden;
}

/* ---- STATS CARDS ---- */
.stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 16px;
  margin-bottom: 24px;
}
.stat-card {
  background: var(--glass-bg);
  backdrop-filter: blur(6px);
  -webkit-backdrop-filter: blur(6px);
  border-radius: var(--radius);
  padding: 20px;
  box-shadow: var(--shadow-sm);
  border: var(--glass-border);
  display: flex;
  align-items: center;
  gap: 16px;
  transition: var(--transition);
}
.stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow); border-color: var(--cream-border); }
.stat-icon {
  width: 48px; height: 48px;
  border-radius: 14px;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
  border: 1px solid transparent;
  transition: transform 0.15s ease;
}
.stat-card:hover .stat-icon { transform: scale(1.05); }
.stat-icon.blue { background: rgba(30,77,135,0.08); color: var(--accent); border-color: rgba(30,77,135,0.16); }
.stat-icon.green { background: rgba(34,139,87,0.1); color: var(--success); border-color: rgba(34,139,87,0.18); }
.stat-icon.gold { background: rgba(184,134,11,0.1); color: var(--gold); border-color: rgba(184,134,11,0.2); }
.stat-icon.navy { background: rgba(10,22,40,0.06); color: var(--navy-600); border-color: rgba(10,22,40,0.12); }
.stat-icon svg { width: 22px; height: 22px; }
.stat-info .stat-value {
  font-size: 28px;
  font-weight: 700;
  color: var(--navy-900);
  line-height: 1;
}
.stat-info .stat-label {
  font-size: 13px;
  color: var(--gray-500);
  margin-top: 5px;
  font-weight: 500;
}

/* ---- BUTTONS ---- */
.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 10px 20px;
  border-radius: var(--radius-sm);
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  border: 1px solid transparent;
  transition: var(--transition);
  font-family: inherit;
  text-decoration: none;
  white-space: nowrap;
}
.btn svg { width: 16px; height: 16px; }
.btn-primary {
  background: var(--navy-700);
  color: var(--white);
  border-color: var(--navy-700);
}
.btn-primary:hover { background: var(--accent-light); border-color: var(--accent-light); box-shadow: 0 3px 10px rgba(30,77,135,0.25); transform: translateY(-1px); }
.btn-secondary {
  background: var(--white);
  color: var(--gray-700);
  border: 1px solid var(--gray-200);
}
.btn-secondary:hover { background: var(--gray-50); border-color: var(--gray-300); }
.btn-danger { background: var(--danger); color: white; border-color: var(--danger); }
.btn-danger:hover { opacity: 0.9; box-shadow: 0 4px 12px rgba(192,57,43,0.25); }
.btn-success { background: var(--success); color: white; border-color: var(--success); }
.btn-success:hover { opacity: 0.9; box-shadow: 0 4px 12px rgba(26,124,92,0.2); }
.btn-ghost { background: transparent; color: var(--gray-600); border-color: transparent; }
.btn-ghost:hover { background: var(--gray-100); color: var(--gray-800); }
.btn-sm { padding: 6px 14px; font-size: 13px; }
.btn-icon {
  width: 38px; height: 38px;
  padding: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: var(--radius-sm);
}
.btn-icon svg { width: 17px; height: 17px; }

/* ---- SEARCH & FILTER BAR ---- */
.filter-bar {
  background: rgba(249,246,238,0.9);
  backdrop-filter: blur(6px);
  -webkit-backdrop-filter: blur(6px);
  border: var(--glass-border);
  border-radius: var(--radius);
  padding: 14px 18px;
  margin-bottom: 24px;
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  align-items: center;
  box-shadow: var(--glass-shadow);
}
.search-input-wrap {
  flex: 1;
  min-width: 220px;
  position: relative;
}
.search-input-wrap svg {
  position: absolute;
  left: 14px;
  top: 50%;
  transform: translateY(-50%);
  width: 16px;
  height: 16px;
  color: var(--gray-400);
  transition: var(--transition);
}
.search-input-wrap:focus-within svg { color: var(--accent); }
.search-input {
  width: 100%;
  padding: 10px 14px 10px 42px;
  border: 1px solid transparent;
  border-radius: var(--radius-sm);
  font-size: 14px;
  font-family: inherit;
  outline: none;
  transition: var(--transition);
  color: var(--gray-800);
  background: var(--gray-50);
  text-overflow: ellipsis;
  white-space: nowrap;
  overflow: hidden;
}
.search-input:hover { background: var(--gray-100); }
.search-input:focus { border-color: var(--accent); background: white; box-shadow: 0 0 0 3px rgba(46,125,209,0.1); }
.filter-select {
  padding: 10px 14px;
  border: 1px solid transparent;
  border-radius: var(--radius-sm);
  font-size: 14px;
  font-family: inherit;
  outline: none;
  color: var(--gray-700);
  background: var(--gray-50);
  cursor: pointer;
  transition: var(--transition);
}
.filter-select:hover { background: var(--gray-100); }
.filter-select:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(46,125,209,0.1); }

/* ---- WORD CARDS GRID ---- */
.words-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
  gap: 20px;
}
.word-card {
  background: var(--glass-bg);
  backdrop-filter: blur(6px);
  -webkit-backdrop-filter: blur(6px);
  border: var(--glass-border);
  border-radius: var(--radius);
  padding: 24px;
  box-shadow: var(--shadow-sm);
  transition: var(--transition);
  position: relative;
  display: flex;
  flex-direction: column;
}
.word-card:hover { transform: translateY(-2px); box-shadow: var(--shadow); border-color: var(--cream-border); }
.word-card-header {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  margin-bottom: 12px;
}
.word-term {
  font-size: 24px;
  font-weight: 700;
  color: var(--navy-900);
  flex: 1;
  line-height: 1.3;
  word-break: break-word;
}
.word-pos {
  font-style: italic;
  color: var(--gray-500);
  font-size: 13px;
  margin-top: 3px;
}
.word-card-actions {
  display: flex;
  gap: 4px;
  flex-shrink: 0;
  flex-wrap: wrap;
  justify-content: flex-end;
}
.word-definition {
  color: var(--gray-700);
  font-size: 14px;
  line-height: 1.7;
  flex: 1;
  display: -webkit-box;
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.word-definition p { margin: 0; }
.word-card-footer {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 16px;
  padding-top: 16px;
  border-top: 1px solid var(--gray-100);
  flex-wrap: wrap;
}
.word-cat-badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 4px 12px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 500;
  background: var(--gold-pale);
  color: var(--navy-700);
  border: 1px solid var(--gold-border);
}
.word-date-badge {
  font-size: 12px;
  color: var(--gray-400);
  margin-left: auto;
}
.word-difficulty {
  font-size: 12px;
  padding: 3px 10px;
  border-radius: 20px;
  font-weight: 600;
}
.diff-easy { background: var(--success-light); color: var(--success); }
.diff-medium { background: var(--warning-light); color: var(--warning); }
.diff-hard { background: var(--danger-light); color: var(--danger); }
.word-star-btn {
  background: none;
  border: none;
  cursor: pointer;
  color: var(--gray-300);
  transition: var(--transition);
  padding: 6px;
  display: flex;
}
.word-star-btn.starred { color: var(--gold); }
.word-star-btn:hover { transform: scale(1.2); color: var(--gold); }
.mastered-badge {
  background: var(--success-light);
  color: var(--success);
  font-size: 11px;
  font-weight: 600;
  padding: 4px 10px;
  border-radius: 20px;
  letter-spacing: 0.5px;
}
.tags-row {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
  margin-top: 12px;
}
.tag-chip {
  display: inline-block;
  background: var(--gray-100);
  color: var(--gray-700);
  font-size: 12px;
  padding: 3px 10px;
  border-radius: 6px;
  font-weight: 500;
}

/* ---- MODAL ---- */
.modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(4, 14, 28, 0.7);
  z-index: 1000;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.15s ease;
  backdrop-filter: blur(8px);
}
.modal-overlay.active { opacity: 1; pointer-events: all; }
.modal {
  background: rgba(255, 255, 255, 0.98);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
  border: 1px solid rgba(0,0,0,0.05);
  border-radius: 20px;
  box-shadow: 0 24px 48px rgba(0,0,0,0.15);
  width: 100%;
  max-width: 760px;
  max-height: 90vh;
  display: flex;
  flex-direction: column;
  transform: translateY(16px);
  transition: transform 0.15s ease;
}
.modal-overlay.active .modal { transform: translateY(0); }
.modal-sm { max-width: 440px; }
.modal-header {
  padding: 24px 32px 20px;
  border-bottom: 1px solid rgba(0,0,0,0.05);
  display: flex;
  align-items: center;
  gap: 12px;
}
.modal-header h3 {
  font-size: 20px;
  font-weight: 600;
  color: var(--navy-900);
  flex: 1;
}
.modal-close {
  background: none;
  border: none;
  cursor: pointer;
  color: var(--gray-400);
  padding: 4px;
  border-radius: 4px;
  transition: var(--transition);
}
.modal-close:hover { color: var(--gray-700); background: var(--gray-100); }
.modal-body {
  padding: 24px 32px;
  overflow-y: auto;
  flex: 1;
}
.modal-footer {
  padding: 20px 32px;
  border-top: 1px solid rgba(0,0,0,0.05);
  background: rgba(247,249,252,0.5);
  border-radius: 0 0 20px 20px;
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  flex-wrap: wrap;
}

/* ---- FORM ---- */
.form-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
}
.form-field { margin-bottom: 18px; }
.form-field label {
  display: block;
  font-size: 12px;
  font-weight: 600;
  color: var(--gray-600);
  letter-spacing: 0.5px;
  text-transform: uppercase;
  margin-bottom: 7px;
}
.form-field input,
.form-field select,
.form-field textarea {
  width: 100%;
  border: 1px solid var(--gray-200);
  border-radius: var(--radius-sm);
  padding: 9px 13px;
  font-size: 13.5px;
  font-family: inherit;
  color: var(--gray-800);
  background: var(--white);
  outline: none;
  transition: var(--transition);
}
.form-field input:focus,
.form-field select:focus,
.form-field textarea:focus {
  border-color: var(--accent);
  box-shadow: 0 0 0 3px rgba(46,125,209,0.1);
}
.form-field textarea { resize: vertical; min-height: 80px; }
.quill-wrapper .ql-container { border-radius: 0 0 var(--radius-sm) var(--radius-sm); min-height: 140px; font-family: inherit; }
.quill-wrapper .ql-toolbar { border-radius: var(--radius-sm) var(--radius-sm) 0 0; border-color: var(--gray-200); }
.quill-wrapper .ql-container { border-color: var(--gray-200); }
.quill-wrapper.focused .ql-container,
.quill-wrapper.focused .ql-toolbar { border-color: var(--accent); }

/* ---- TABLE ---- */
.data-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13.5px;
  table-layout: fixed;
}
.data-table th {
  text-align: left;
  padding: 12px 16px;
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.8px;
  color: var(--gray-500);
  border-bottom: 2px solid var(--gray-100);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.data-table td {
  padding: 13px 16px;
  border-bottom: 1px solid var(--gray-100);
  vertical-align: middle;
  color: var(--gray-700);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.data-table tr:hover td { background: var(--gray-50); }
.data-table tr:last-child td { border-bottom: none; }

/* ---- CARD CONTAINER ---- */
.card {
  transform: translateZ(0);
  background: var(--glass-bg);
  backdrop-filter: blur(6px);
  -webkit-backdrop-filter: blur(6px);
  border: var(--glass-border);
  border-radius: var(--radius);
  box-shadow: var(--shadow-sm);
  overflow: hidden;
}
.card-header {
  padding: 14px 18px;
  border-bottom: 1px solid var(--gray-100);
  display: flex;
  align-items: center;
  gap: 10px;
}
.card-header h3 {
  font-size: 16px;
  font-weight: 600;
  color: var(--navy-900);
  flex: 1;
}
.card-body { padding: 16px 18px; }
#daily-chart.card-body { padding: 12px 14px 8px; min-height: 220px; height: 220px; }

/* ---- WORD DETAIL VIEW ---- */
.word-detail {
  background: var(--white);
  border-radius: var(--radius);
  box-shadow: var(--shadow);
  overflow: hidden;
  border: 1px solid var(--gray-100);
}
.word-detail-header {
  background: linear-gradient(135deg, var(--navy-900), var(--navy-700));
  padding: 30px 32px;
  color: white;
}
.word-detail-header .term {
  font-size: 38px;
  font-weight: 600;
  letter-spacing: -0.5px;
}
.word-detail-header .pronunciation {
  color: var(--navy-200);
  font-style: italic;
  font-size: 16px;
  margin-top: 4px;
}
.word-detail-body { padding: 28px 32px; }
.detail-section { margin-bottom: 24px; }
.detail-section-title {
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 1px;
  text-transform: uppercase;
  color: var(--gray-400);
  margin-bottom: 8px;
}
.detail-definition {
  font-size: 15px;
  color: var(--gray-700);
  line-height: 1.8;
}

/* ---- PAGINATION ---- */
.pagination {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  margin-top: 28px;
  flex-wrap: wrap;
}
.page-btn {
  min-width: 36px;
  height: 36px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: var(--radius-sm);
  border: 1px solid var(--gray-200);
  background: var(--white);
  color: var(--gray-600);
  font-size: 13px;
  cursor: pointer;
  transition: var(--transition);
  font-family: inherit;
  padding: 0 10px;
}
.page-btn:hover { border-color: var(--accent); color: var(--accent); }
.page-btn.active { background: var(--accent); border-color: var(--accent); color: white; font-weight: 600; }
.page-btn:disabled { opacity: 0.4; cursor: not-allowed; }

/* ---- EMPTY STATE ---- */
.empty-state {
  text-align: center;
  padding: 60px 20px;
  color: var(--gray-400);
}
.empty-state svg { width: 56px; height: 56px; margin: 0 auto 16px; opacity: 0.4; display: block; }
.empty-state h4 { font-size: 17px; color: var(--gray-600); margin-bottom: 6px; font-weight: 600; }
.empty-state p { font-size: 13.5px; }


/* ---- SETTINGS ---- */
.settings-section {
  background: var(--glass-bg);
  backdrop-filter: blur(6px);
  -webkit-backdrop-filter: blur(6px);
  border: var(--glass-border);
  border-radius: var(--radius);
  margin-bottom: 20px;
  overflow: hidden;
}
.settings-section-header {
  padding: 18px 24px;
  border-bottom: 1px solid var(--gray-100);
  display: flex;
  align-items: center;
  gap: 12px;
  background: var(--gray-50);
}
.settings-section-header h3 {
  font-size: 15px;
  font-weight: 600;
  color: var(--navy-800);
}
.settings-section-header svg { width: 18px; height: 18px; color: var(--accent); }
.settings-body { padding: 24px; }
.toggle-wrap {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 0;
  border-bottom: 1px solid var(--gray-100);
}
.toggle-wrap:last-child { border-bottom: none; }
.toggle-info h4 { font-size: 14px; font-weight: 500; color: var(--gray-800); }
.toggle-info p { font-size: 12px; color: var(--gray-500); margin-top: 2px; }
.toggle {
  position: relative;
  width: 44px;
  height: 24px;
  flex-shrink: 0;
}
.toggle input { opacity: 0; width: 0; height: 0; }
.toggle-slider {
  position: absolute;
  inset: 0;
  background: var(--gray-300);
  border-radius: 12px;
  cursor: pointer;
  transition: var(--transition);
}
.toggle-slider::before {
  content: '';
  position: absolute;
  width: 18px; height: 18px;
  left: 3px; top: 3px;
  background: white;
  border-radius: 50%;
  transition: var(--transition);
  box-shadow: 0 1px 3px rgba(0,0,0,0.2);
}
.toggle input:checked + .toggle-slider { background: var(--accent); }
.toggle input:checked + .toggle-slider::before { transform: translateX(20px); }

/* ---- CHART ---- */
.chart-bar-wrap {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.chart-bar-item {
  display: flex;
  align-items: center;
  gap: 12px;
  font-size: 13px;
}
.chart-bar-label { width: 130px; flex-shrink: 0; color: var(--gray-600); font-weight: 500; text-align: left; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.chart-bar-track {
  flex: 1;
  height: 10px;
  background: var(--gray-100);
  border-radius: 5px;
  overflow: hidden;
}
.chart-bar-fill {
  height: 100%;
  border-radius: 5px;
  background: linear-gradient(90deg, var(--navy-500), var(--accent));
  transition: width 0.3s ease;
}
.chart-bar-count { color: var(--gray-500); width: 35px; flex-shrink: 0; text-align: right; font-size: 12px; font-weight: 600; }

/* ---- DAILY CHART ---- */
#daily-chart {
  overflow-x: auto;
  overflow-y: hidden;
  -webkit-overflow-scrolling: touch;
  padding: 16px 14px 12px;
  min-height: 200px;
  display: flex;
  align-items: flex-end;
  scrollbar-width: none;
  background: transparent;
  border-radius: 0 0 10px 10px;
}
#daily-chart::-webkit-scrollbar { display: none; }

.daily-chart-svg {
  display: block;
  overflow: visible;
  flex-shrink: 0;
}

/* Default state */
.daily-bar-group {
  transition: opacity 0.2s ease;
  cursor: pointer;
}

/* Hover */
.daily-bar-group:hover .bar-hover-bg { opacity: 1 !important; }
.daily-bar-group:hover .bar-rect { filter: brightness(1.07); }

/* Focus: dim all, highlight clicked */
.daily-chart-svg.has-focus .daily-bar-group { opacity: 0.3; }
.daily-chart-svg.has-focus .daily-bar-group.focused { opacity: 1; }
.daily-chart-svg.has-focus .daily-bar-group.focused .bar-rect {
  filter: brightness(1.06);
}
.daily-chart-svg.has-focus .daily-bar-group.bar-today:not(.focused) { opacity: 0.4; }

@media (max-width: 768px) {
  #daily-chart { padding: 12px 8px 10px; min-height: 185px; }
}

/* ---- CATEGORIES ---- */
.cats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 16px;
}
.cat-card {
  background: var(--glass-bg);
  backdrop-filter: blur(6px);
  -webkit-backdrop-filter: blur(6px);
  border: var(--glass-border);
  border-radius: var(--radius);
  padding: 16px 18px;
  box-shadow: var(--shadow-sm);
  display: flex;
  align-items: center;
  gap: 16px;
  transition: var(--transition);
  cursor: pointer;
  min-width: 0;
}
.cat-card:hover { transform: translateY(-1px); box-shadow: var(--shadow); }
.cat-color-dot {
  width: 44px; height: 44px;
  border-radius: 12px;
  flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  color: white;
  font-weight: 700;
  font-size: 17px;
}
.cat-info { flex: 1; min-width: 0; overflow: hidden; }
.cat-name { font-weight: 600; color: var(--navy-900); font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cat-desc { font-size: 12px; color: var(--gray-500); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cat-count { font-size: 11px; color: var(--gray-400); margin-top: 4px; }
.cat-actions { display: flex; gap: 4px; flex-shrink: 0; }

/* ---- VIEW TOGGLE ---- */
.view-toggle {
  display: flex;
  background: var(--gray-100);
  border-radius: var(--radius-sm);
  padding: 3px;
  gap: 2px;
}
.view-btn {
  padding: 5px 10px;
  border-radius: 4px;
  border: none;
  background: transparent;
  cursor: pointer;
  color: var(--gray-500);
  transition: var(--transition);
  display: flex; align-items: center;
}
.view-btn svg { width: 15px; height: 15px; }
.view-btn.active { background: var(--white); color: var(--accent); box-shadow: var(--shadow-sm); }

/* ---- LIST VIEW ---- */
.words-list-view .card { margin-bottom: 0; }

/* ---- RESPONSIVE UTILS ---- */
.table-responsive {
  width: 100%;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
  display: block;
  scrollbar-width: none;
}
.table-responsive::-webkit-scrollbar { display: none; }
#words-table-view .card {
  overflow: hidden;
  min-width: 0;
}
.wotd-body {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 16px;
  position: relative;
  z-index: 10;
}
.wotd-actions {
  display: flex;
  gap: 10px;
  align-items: center;
  flex-shrink: 0;
}
.dash-list-item-text {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  flex: 1;
  min-width: 0;
  font-size: 13.5px;
  font-weight: 600;
  color: var(--navy-900);
}
.dash-tag {
  font-size: 11px;
  color: var(--gray-600);
  background: var(--gray-100);
  padding: 4px 10px;
  border-radius: 12px;
  font-weight: 600;
  white-space: nowrap;
  flex-shrink: 0;
  display: inline-block;
}

/* ---- RESPONSIVE ---- */

@media (max-width: 900px) {
  .app-header { height: 46px; padding: 0 10px; gap: 8px; }
  .hamburger { margin-right: 0; width: 30px; height: 30px; }
  .hamburger .bar1 { top: 9px; } .hamburger .bar2 { top: 14px; } .hamburger .bar3 { top: 19px; }
  .hamburger.is-open .bar1, .hamburger.is-open .bar3 { top: 14px; }
  .header-title { font-size: 15px; }
  .sidebar-logo { padding: 14px 16px; }
}
@media (max-width: 900px) {
  .sidebar { transform: translateX(-100%); }
  .sidebar.open { transform: translateX(0); }
  .main-content { margin-left: 0; }
  .hamburger { display: flex; }
  .page-content { padding: 20px 16px; }
  .form-row { grid-template-columns: 1fr; }
  
  .login-panel { padding: 40px 24px; }
  #dash-charts, #dash-lists { grid-template-columns: 1fr; }}
@media (max-width: 600px) {
  .stats-grid { grid-template-columns: 1fr 1fr; }
  .stat-card { padding: 12px; gap: 10px; }
  .stat-icon { width: 36px; height: 36px; }
  .stat-info .stat-value { font-size: 20px; }
  .stat-info .stat-label { font-size: 11px; }
  .filter-bar { flex-direction: column; gap: 10px; padding: 12px; }
  .search-input-wrap { min-width: unset; width: 100%; }
  .filter-select { width: 100%; }
  .header-title { font-size: 18px; }
  .wotd-body { flex-direction: column; align-items: flex-start; }
  .wotd-actions { width: 100%; justify-content: flex-start; }
  .chart-bar-item { gap: 8px; font-size: 12px; }
  .chart-bar-label { width: 85px; font-size: 11px; }
  .chart-bar-count { width: 25px; font-size: 11px; }
  .dash-list-item { padding: 12px; }
  .cats-grid { grid-template-columns: 1fr; }
  .cat-card { padding: 14px 14px; gap: 12px; }
  .cat-color-dot { width: 38px; height: 38px; font-size: 15px; border-radius: 10px; }
  .cat-actions .btn { padding: 5px 8px; font-size: 12px; }
  .hide-mobile { display: none !important; }
  .mobile-icon-btn { width: 38px !important; height: 38px !important; padding: 0 !important; min-width: unset !important; }
  .modal-header, .modal-body { padding: 16px 20px; }
  .modal-footer { padding: 16px 20px; justify-content: center; gap: 8px; }
  .word-detail-header { padding: 20px 24px; }
  .word-detail-body { padding: 20px 24px; }
  .word-detail-header .term { font-size: 28px; }
  .page-content { padding: 16px 12px; }
  
  #bulk-action-bar {
    width: calc(100vw - 32px);
    padding: 10px 14px;
    border-radius: 30px;
    flex-wrap: nowrap;
    gap: 8px;
    bottom: 16px;
    overflow-x: auto;
    justify-content: flex-start;
  }
  #bulk-count {
    width: auto;
    text-align: left;
    margin-bottom: 0;
    font-size: 15px !important;
  }
  .bulk-divider { display: block !important; }
  #bulk-action-bar .btn-sm { flex: none; min-width: auto; margin: 0; padding: 6px 10px; }
  #bulk-action-bar .btn-icon { position: static; margin-left: auto !important; }
}
@media (max-width: 400px) {
    .words-grid { grid-template-columns: 1fr; }
}

/* ---- OVERLAY for mobile sidebar ---- */
.sidebar-overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.5);
  z-index: 99;
}
.sidebar-overlay.active { display: block; }

/* ---- REVIEW CARDS ---- */
.review-card-wrap { perspective: 1000px; height: 280px; }
.review-card {
  width: 100%;
  height: 100%;
  position: relative;
  transform-style: preserve-3d;
  transition: transform 0.5s ease;
  cursor: pointer;
}
.review-card.flipped { transform: rotateY(180deg); }
.review-front, .review-back {
  position: absolute;
  inset: 0;
  backface-visibility: hidden;
  border-radius: var(--radius);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-direction: column;
  padding: 30px;
  box-shadow: var(--shadow);
  text-align: center;
}
.review-front {
  background: linear-gradient(135deg, var(--navy-900), var(--navy-700));
  color: white;
}
.review-back {
  background: var(--white);
  border: 1px solid var(--gray-100);
  transform: rotateY(180deg);
}
.review-front .term { font-size: 32px; font-weight: 700; word-break: break-word; padding: 0 10px; line-height: 1.2; }
.review-front .hint { color: var(--navy-300); font-size: 13px; margin-top: 12px; }
.review-back .definition { font-size: 14px; color: var(--gray-700); line-height: 1.8; }

/* ---- LOADING ---- */
.loading {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 60px;
  color: var(--gray-400);
  gap: 12px;
  font-size: 14px;
}
.spinner {
  width: 20px; height: 20px;
  border: 2px solid var(--gray-200);
  border-top-color: var(--accent);
  border-radius: 50%;
  animation: spin 0.55s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* Word view toggle between grid and table */
#words-table-view { display: none; }

/* Word Detail Text Wrap */
.word-detail-header .term { font-size: 32px; font-weight: 600; letter-spacing: -0.5px; word-break: break-word; line-height: 1.2; }

/* ---- BULK ACTION BAR ---- */
#bulk-action-bar {
  position: fixed;
  bottom: 24px;
  left: 50%;
  transform: translateX(-50%) translateY(100px);
  background: var(--navy-900);
  color: white;
  padding: 12px 24px;
  border-radius: 30px;
  box-shadow: var(--shadow-lg);
  z-index: 999;
  display: flex;
  align-items: center;
  gap: 16px;
  opacity: 0;
  transition: all 0.18s cubic-bezier(0.4, 0, 0.2, 1);
  pointer-events: none;
}
#bulk-action-bar.active {
  transform: translateX(-50%) translateY(0);
  opacity: 1;
  pointer-events: all;
}

/* ---- TOAST ---- */
#toast-container {
  position: fixed;
  top: 20px;
  right: 20px;
  z-index: 2000;
  display: flex;
  flex-direction: column;
  gap: 10px;
  align-items: flex-end;
}
.toast {
  display: flex;
  align-items: center;
  gap: 10px;
  background: var(--navy-800);
  color: white;
  padding: 12px 18px;
  border-radius: var(--radius-sm);
  box-shadow: var(--shadow-lg);
  transform: translateX(120%);
  transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
  opacity: 0;
  pointer-events: none;
}
.toast.show {
  transform: translateX(0);
  opacity: 1;
  pointer-events: all;
}
.toast svg {
  width: 20px;
  height: 20px;
  flex-shrink: 0;
}
.toast.success { background: var(--success); }
.toast.error { background: var(--danger); }
.toast.info { background: var(--navy-700); }

/* ---- DASH LIST ITEM ---- */
.dash-list-item {
  padding: 12px 16px;
  border-bottom: 1px solid var(--gray-100);
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  transition: var(--transition);
}
.dash-list-item:hover { background: rgba(0,0,0,0.02); }
.dash-list-item:last-child { border-bottom: none; }
#dash-charts, #dash-lists { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }

</style>
<style>
/* Additional styles for new features */
.recent-search-tag {
    display: inline-block;
    background: var(--gray-100);
    color: var(--gray-700);
    padding: 5px 12px;
    border-radius: 15px;
    font-size: 12px;
    margin: 4px;
    cursor: pointer;
    transition: var(--transition);
    border: 1px solid var(--gray-200);
}
.recent-search-tag:hover {
    background: var(--navy-50);
    color: var(--navy-700);
    border-color: var(--accent);
}

/* ---- FULL PAGE PRELOADER ---- */
#page-preloader {
  position: fixed; inset: 0; z-index: 9999;
  background: var(--cream);
  display: flex; align-items: center; justify-content: center;
  transition: opacity 0.12s ease, visibility 0.12s ease;
  opacity: 1; visibility: visible;
}
#page-preloader.hidden { opacity: 0; visibility: hidden; pointer-events: none; }
.preloader-mark {
  width: 56px; height: 56px;
  border-radius: 50%;
  background: var(--gold-pale, #f7ecd2);
  border: 1px solid var(--cream-border);
  display: flex; align-items: center; justify-content: center;
  position: relative;
}
.preloader-mark svg { width: 26px; height: 26px; }
.preloader-ring {
  position: absolute; inset: -6px;
  border-radius: 50%;
  border: 2px solid transparent;
  border-top-color: var(--gold, #c9a84c);
  animation: preloaderSpin 0.55s linear infinite;
}
@keyframes preloaderSpin { to { transform: rotate(360deg); } }
.pulse-heart-wrap {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 20px; height: 16px;
  margin: 0 2px;
  vertical-align: middle;
  position: relative;
  top: -1px;
  overflow: visible;
}
.pulse-heart {
  display: block;
  transform-origin: center;
  animation: heartBeat 1.1s ease-in-out infinite;
}
@keyframes heartBeat {
  0%, 100% { transform: scale(1); }
  25% { transform: scale(1.18); }
  40% { transform: scale(0.95); }
  60% { transform: scale(1.1); }
}
.page-content { animation: pageFadeIn 0.25s ease; }
@keyframes pageFadeIn {
  from { opacity: 0; transform: translateY(4px); }
  to { opacity: 1; transform: translateY(0); }
}
</style>
</head>
<body>
<div id="page-preloader">
  <div class="preloader-mark">
    <div class="preloader-ring"></div>
    <svg viewBox="0 0 24 24" fill="none" stroke="var(--navy-900)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
      <rect x="4.5" y="3" width="15" height="18" rx="2.5"/>
      <circle cx="12" cy="10.5" r="2.1"/>
      <line x1="12" y1="12.6" x2="12" y2="15.2"/>
    </svg>
  </div>
</div>

<?php if (!$isSetup): ?>
<div class="login-page">
  <div class="login-grid-bg"></div>
  <div class="login-panel" style="max-width:540px">
    <div class="login-logo">
      <div class="logo-mark"><svg viewBox="0 0 24 24" fill="none" stroke="var(--navy-900)" stroke-width="1.8" width="26" height="26"><rect x="4.5" y="3" width="15" height="18" rx="2.5"/><circle cx="12" cy="10.5" r="2.1"/><line x1="12" y1="12.6" x2="12" y2="15.2"/></svg></div>
      <h1>LexiVault Setup</h1>
      <p id="setup-subtitle">Step 1: Configure your database</p>
    </div>
    <div id="setup-error" class="login-error" style="display:none; margin-bottom: 20px;"></div>
    <form id="setup-form" onsubmit="event.preventDefault(); doSetup()">
      <div id="setup-step-1">
        <div style="background:var(--gray-50);padding:16px;border-radius:8px;margin-bottom:20px;border:1px solid var(--gray-200)">
          <div class="form-group"><label>DB Host & Port</label><div style="display:flex;gap:10px"><input type="text" id="setup-db-host" value="localhost" style="flex:2"><input type="text" id="setup-db-port" value="3306" style="flex:1"></div></div>
          <div class="form-group"><label>Database Name</label><input type="text" id="setup-db-name" value="lexivault"></div>
          <div class="form-group"><label>DB User & Pass</label><div style="display:flex;gap:10px"><input type="text" id="setup-db-user" value="root" style="flex:1"><input type="password" id="setup-db-pass" placeholder="DB Password" style="flex:1"></div></div>
        </div>
        <button type="button" class="btn-login" id="setup-next-btn" onclick="goToSetupStep2()">Test Connection & Next</button>
      </div>
      <div id="setup-step-2" style="display:none">
        <h4 style="color:var(--navy-900);font-size:14px;margin-bottom:12px;border-bottom:1px solid var(--gray-200);padding-bottom:6px">Admin Account</h4>
        <div class="form-group"><label>Admin Name</label><input type="text" id="setup-admin-name" value="Administrator" required></div>
        <div class="form-group"><label>Admin Username</label><input type="text" id="setup-admin-user" value="admin" required></div>
        <div class="form-group"><label>Admin Password</label><input type="password" id="setup-admin-pass" value="admin123" required></div>
        <div style="display:flex; gap:10px; margin-top:10px;">
          <button type="button" class="btn-secondary" onclick="goToSetupStep1()" style="width:120px">Back</button>
          <button type="submit" class="btn-login" id="setup-btn" style="flex:1">Complete Setup</button>
        </div>
      </div>
    </form>
  </div>
</div>
<script>
function showSetupError(message) {
    const errDiv = document.getElementById('setup-error');
    errDiv.textContent = message;
    errDiv.style.display = 'block';
}

function hideSetupError() {
    document.getElementById('setup-error').style.display = 'none';
}

function goToSetupStep1() {
    document.getElementById('setup-step-1').style.display = 'block';
    document.getElementById('setup-step-2').style.display = 'none';
    document.getElementById('setup-subtitle').textContent = 'Step 1: Configure your database';
    hideSetupError();
}

async function goToSetupStep2() {
    hideSetupError();
    const btn = document.getElementById('setup-next-btn');
    btn.disabled = true;
    btn.textContent = 'Testing...';

    const fd = new FormData();
    fd.append('db_host', document.getElementById('setup-db-host').value);
    fd.append('db_port', document.getElementById('setup-db-port').value);
    fd.append('db_user', document.getElementById('setup-db-user').value);
    fd.append('db_pass', document.getElementById('setup-db-pass').value);

    const r = await fetch('?api=setup_test_db', {method:'POST', body:fd}).then(res=>res.json()).catch(()=>({success:false, message:'Network error'}));
    
    btn.disabled = false;
    btn.textContent = 'Test Connection & Next';

    if(r.success) {
        document.getElementById('setup-step-1').style.display = 'none';
        document.getElementById('setup-step-2').style.display = 'block';
        document.getElementById('setup-subtitle').textContent = 'Step 2: Create admin account';
    } else {
        showSetupError(r.message || 'Connection failed. Please check your credentials.');
    }
}

async function doSetup() {
    const btn = document.getElementById('setup-btn'); btn.disabled = true; btn.textContent = 'Setting up...';
    hideSetupError();

    const fd = new FormData();
    fd.append('db_host', document.getElementById('setup-db-host').value); fd.append('db_port', document.getElementById('setup-db-port').value);
    fd.append('db_name', document.getElementById('setup-db-name').value); fd.append('db_user', document.getElementById('setup-db-user').value); fd.append('db_pass', document.getElementById('setup-db-pass').value);
    fd.append('admin_name', document.getElementById('setup-admin-name').value); fd.append('admin_user', document.getElementById('setup-admin-user').value); fd.append('admin_pass', document.getElementById('setup-admin-pass').value);
    const r = await fetch('?api=setup', {method:'POST', body:fd}).then(res=>res.json()).catch(()=>({success:false, message:'Network error'}));
    if(r.success) {
        location.reload();
    } else {
        showSetupError(r.message || 'Setup failed');
        btn.disabled = false;
        btn.textContent = 'Complete Setup';
    }
}
</script>

<?php elseif (!isLoggedIn() && $page === 'login'): ?>
<!-- ============================================================ LOGIN PAGE ============================================================ -->
<div class="login-page">
  <div class="login-grid-bg"></div>
  <div class="login-panel">
    <div class="login-logo">
      <div class="logo-mark">
        <svg viewBox="0 0 24 24" fill="none" stroke="var(--navy-900)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="26" height="26">
          <rect x="4.5" y="3" width="15" height="18" rx="2.5"/>
          <circle cx="12" cy="10.5" r="2.1"/>
          <line x1="12" y1="12.6" x2="12" y2="15.2"/>
        </svg>
      </div>
      <h1>LexiVault</h1>
      <p>Your Personal Vocabulary Repository</p>
    </div>
    <?php if (!empty($loginError)): ?>
    <div class="login-error">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
      <?= htmlspecialchars($loginError) ?>
    </div>
    <?php endif; ?>
    <form method="POST">
      <input type="hidden" name="action" value="login">
      <div class="form-group">
        <label>Username</label>
        <input type="text" name="username" placeholder="Enter your username" autocomplete="username" required>
      </div>
      <div class="form-group">
        <label>Password</label>
        <input type="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
      </div>
      <button type="submit" class="btn-login">Sign In to LexiVault</button>
      <div style="text-align: center; margin-top: 24px;">
        <a href="?page=public_search" style="color: var(--accent); font-size: 13px; text-decoration: none; font-weight: 500; transition: var(--transition);">Or search the public dictionary &rarr;</a>
      </div>
    </form>
  </div>
</div>

<?php elseif (!isLoggedIn() && $page === 'public_search'): ?>
<!-- ============================================================ PUBLIC SEARCH PAGE ============================================================ -->
<div class="login-page">
  <div class="login-grid-bg"></div>
  <div class="login-panel" style="max-width: 600px;">
    <div class="login-logo" style="margin-bottom: 30px;">
      <div class="logo-mark">
        <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="28" height="28">
          <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
        </svg>
      </div>
      <h1>Public Dictionary</h1>
      <p>Search our public vocabulary repository</p>
    </div>
    
    <div style="display: flex; gap: 12px; margin-bottom: 16px; align-items: stretch; flex-wrap: wrap;">
       <div class="form-group" style="flex: 1; min-width: 200px; margin-bottom: 0; position: relative;">
         <svg viewBox="0 0 24 24" fill="none" stroke="var(--gray-400)" stroke-width="2" style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); width: 18px; height: 18px;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
         <input type="text" id="public-search-input" placeholder="Enter a word to search..." oninput="handleAutocomplete()" autocomplete="off" style="padding-left: 40px; height: 46px; margin: 0; background: var(--white); border: 1px solid var(--gray-200); color: var(--gray-800);">
         <div id="autocomplete-list" class="autocomplete-items"></div>
       </div>
       <button class="btn-login" onclick="doPublicSearch()" id="public-search-btn" style="width: auto; padding: 0 28px; margin-top: 0; height: 46px;">Search</button>
    </div>

    <div id="recent-searches-container" style="margin-bottom: 24px; text-align: center; min-height: 28px;">
        <!-- Recent searches will be injected here -->
    </div>

    <div id="public-search-results" style="display: none; background: var(--white); border-radius: var(--radius); padding: 28px; text-align: left; box-shadow: var(--shadow-lg); border: 1px solid var(--gray-200);">
       <!-- Results injected via JS -->
    </div>
    
    <div style="text-align: center; margin-top: 24px;">
        <a href="?page=login" style="color: var(--accent); font-size: 13px; text-decoration: none; font-weight: 500;">&larr; Back to Login</a>
    </div>
  </div>
</div>

<script>
// --- State ---
let autocompleteDebounce;
let currentFocus = -1;

// --- Recent Searches ---
const RECENT_SEARCH_KEY = 'lexivault_public_recent';
const MAX_RECENT = 5;

function getRecentSearches() {
    try {
        const searches = localStorage.getItem(RECENT_SEARCH_KEY);
        return searches ? JSON.parse(searches) : [];
    } catch (e) { return []; }
}

function addRecentSearch(term) {
    if (!term) return;
    let searches = getRecentSearches();
    searches = searches.filter(s => s.toLowerCase() !== term.toLowerCase());
    searches.unshift(term);
    if (searches.length > MAX_RECENT) {
        searches = searches.slice(0, MAX_RECENT);
    }
    try {
        localStorage.setItem(RECENT_SEARCH_KEY, JSON.stringify(searches));
        renderRecentSearches();
    } catch (e) { console.error("Could not save recent searches", e); }
}

function renderRecentSearches() {
    const container = document.getElementById('recent-searches-container');
    const searches = getRecentSearches();
    if (searches.length > 0) {
        container.innerHTML = `
            <span style="font-size: 11px; color: var(--gray-400); text-transform: uppercase; font-weight: 500; letter-spacing: 0.5px; margin-right: 8px;">Recent:</span>
            ${searches.map(s => `<span class="recent-search-tag" onclick="clickRecentSearch('${escapeHtml(s)}')">${escapeHtml(s)}</span>`).join('')}
        `;
    } else {
        container.innerHTML = '';
    }
}

function clickRecentSearch(term) {
    document.getElementById('public-search-input').value = term;
    doPublicSearch();
}

// --- Autocomplete ---
function handleAutocomplete() {
    clearTimeout(autocompleteDebounce);
    autocompleteDebounce = setTimeout(() => {
        const term = document.getElementById('public-search-input').value;
        if (term.length < 2) {
            closeAllLists();
            return;
        }
        fetchAutocomplete(term);
    }, 250);
}

async function fetchAutocomplete(term) {
    try {
        const res = await fetch(`?api=public_autocomplete&term=${encodeURIComponent(term)}&_t=${Date.now()}`, { cache: 'no-store' });
        const suggestions = await res.json();
        renderAutocomplete(suggestions, term);
    } catch (e) { console.error("Autocomplete fetch failed", e); }
}

function renderAutocomplete(suggestions, term) {
    const list = document.getElementById('autocomplete-list');
    closeAllLists();
    if (!suggestions || suggestions.length === 0) return;

    currentFocus = -1;
    list.innerHTML = '';
    suggestions.forEach(suggestion => {
        const item = document.createElement('DIV');
        const regex = new RegExp(`(${escapeRegExp(term)})`, 'gi');
        item.innerHTML = escapeHtml(suggestion).replace(regex, '<strong>$1</strong>');
        item.addEventListener('click', function() {
            document.getElementById('public-search-input').value = suggestion;
            closeAllLists();
            doPublicSearch();
        });
        list.appendChild(item);
    });
}

function closeAllLists(elmnt) {
    const items = document.getElementsByClassName("autocomplete-items");
    for (let i = 0; i < items.length; i++) {
        if (elmnt != items[i] && elmnt != document.getElementById('public-search-input')) {
            items[i].innerHTML = '';
        }
    }
}

function addActive(x) {
    if (!x) return false;
    removeActive(x);
    if (currentFocus >= x.length) currentFocus = 0;
    if (currentFocus < 0) currentFocus = (x.length - 1);
    x[currentFocus].classList.add("autocomplete-active");
}

function removeActive(x) {
    for (var i = 0; i < x.length; i++) {
        x[i].classList.remove("autocomplete-active");
    }
}

function escapeRegExp(string) {
  return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

// --- Event Listeners ---
document.addEventListener('DOMContentLoaded', () => {
    renderRecentSearches();
    const urlParams = new URLSearchParams(window.location.search);
    const viewTerm = urlParams.get('view_term');
    if (viewTerm) {
        document.getElementById('public-search-input').value = viewTerm;
        doPublicSearch();
        // Clean URL to avoid confusion on subsequent searches
        const cleanUrl = window.location.protocol + '//' + window.location.host + window.location.pathname + '?page=public_search';
        window.history.replaceState({}, '', cleanUrl);
    }
});

document.getElementById('public-search-input').addEventListener('keydown', function(e) {
    let x = document.getElementById("autocomplete-list");
    if (x) x = x.getElementsByTagName("div");
    if (e.key === 'ArrowDown') {
        currentFocus++;
        addActive(x);
    } else if (e.key === 'ArrowUp') {
        currentFocus--;
        addActive(x);
    } else if (e.key === 'Enter') {
        e.preventDefault();
        if (currentFocus > -1) {
            if (x) x[currentFocus].click();
        } else {
            doPublicSearch();
        }
        closeAllLists();
    }
});

document.addEventListener("click", function (e) {
    closeAllLists(e.target);
});

// --- Main Search Function ---
async function doPublicSearch() {
    const term = document.getElementById('public-search-input').value.trim();
    if (!term) return;
    
    const btn = document.getElementById('public-search-btn');
    const resDiv = document.getElementById('public-search-results');
    
    btn.disabled = true;
    btn.innerHTML = '<div class="spinner" style="width:16px;height:16px;border-width:2px;display:inline-block;vertical-align:middle;border-color:rgba(255,255,255,0.3);border-top-color:white;"></div>';
    
    try {
        const res = await fetch('?pbw=' + encodeURIComponent(term) + '&format=json&_t=' + Date.now(), { cache: 'no-store' });
        if (!res.ok) throw new Error('Network error');
        const data = await res.json();
        
        resDiv.style.display = 'block';
        if (data.success && data.words && data.words.length > 0) {
            addRecentSearch(term); // Add to recent searches on success
            const w = data.words[0]; // Show the first, best match
            resDiv.innerHTML = `
                <div style="margin-bottom: 16px; border-bottom: 1px solid var(--gray-200); padding-bottom: 16px;">
                    <h2 style="color: var(--navy-900); font-size: 28px; font-weight: 700; margin: 0; letter-spacing: -0.5px;">${escapeHtml(w.term)}</h2>
                    <div style="margin-top: 6px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                        ${w.pronunciation ? `<span style="color: var(--gray-500); font-style: italic; font-size: 15px;">${escapeHtml(w.pronunciation)}</span>` : ''}
                        ${w.word_class ? `<span style="background: var(--navy-50); color: var(--navy-700); padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">${escapeHtml(w.word_class)}</span>` : ''}
                    </div>
                </div>
                <div style="font-size: 15px; color: var(--gray-700); line-height: 1.6;">
                    <strong style="color: var(--navy-800);">Definition:</strong> ${w.definition || '<em style="color: var(--gray-400);">No definition available.</em>'}
                </div>
            `;
        } else {
            resDiv.innerHTML = '<div style="text-align: center; color: var(--gray-500); padding: 30px 20px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="48" height="48" style="opacity: 0.3; margin-bottom: 10px;"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg><br>Word not found.</div>';
        }
    } catch (e) {
        resDiv.style.display = 'block';
        resDiv.innerHTML = '<div style="text-align: center; color: var(--danger); padding: 20px;">An error occurred during search. Please try again.</div>';
    }
    
    btn.disabled = false;
    btn.innerHTML = 'Search';
}
function escapeHtml(unsafe) {
    return (unsafe||'').toString()
         .replace(/&/g, "&amp;")
         .replace(/</g, "&lt;")
         .replace(/>/g, "&gt;")
         .replace(/"/g, "&quot;")
         .replace(/'/g, "&#039;");
}
</script>

<?php else: ?>
<!-- ============================================================ APP ============================================================ -->
<?php $user = currentUser(); ?>
<div class="app-layout">

  <!-- Sidebar overlay (mobile) -->
  <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

  <!-- SIDEBAR -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-logo">
      <div class="logo-mark">
        <svg viewBox="0 0 24 24" fill="none" stroke="var(--navy-900)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18">
          <rect x="4.5" y="3" width="15" height="18" rx="2.5"/>
          <circle cx="12" cy="10.5" r="2"/>
          <line x1="12" y1="12.5" x2="12" y2="15"/>
        </svg>
      </div>
      <div>
        <h2>LexiVault</h2>
        <span>Vocabulary Manager</span>
      </div>
    </div>

    <nav class="sidebar-nav">
      <div class="sidebar-section">
        <div class="sidebar-section-label">Main</div>
        <a class="nav-item <?= $page==='dashboard'?'active':'' ?>" href="?page=dashboard" onclick="closeSidebar()">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
          Dashboard
        </a>
        <a class="nav-item <?= $page==='words'?'active':'' ?>" href="?page=words" onclick="closeSidebar()">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
          Words
        </a>
        <a class="nav-item <?= $page==='categories'?'active':'' ?>" href="?page=categories" onclick="closeSidebar()">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
          Categories
        </a>
        <a class="nav-item <?= $page==='review'?'active':'' ?>" href="?page=review" onclick="closeSidebar()">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
          Review Cards
        </a>
        <a class="nav-item <?= $page==='import_export'?'active':'' ?>" href="?page=import_export" onclick="closeSidebar()">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="8 17 12 21 16 17"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.88 18.09A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.29"/></svg>
          Import / Export
        </a>
        <a class="nav-item <?= $page==='settings'?'active':'' ?>" href="?page=settings" onclick="closeSidebar()">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
          Settings
        </a>
      </div>
    </nav>

    <div class="sidebar-user">
      <div class="user-avatar"><?= strtoupper(substr($user['name'] ?? 'A', 0, 1)) ?></div>
      <div class="user-info">
        <div class="uname"><?= sanitize($user['name'] ?? 'Admin') ?></div>
        <div class="urole"><?= sanitize($user['username'] ?? '') ?></div>
      </div>
      <form method="POST" style="display:inline">
        <input type="hidden" name="action" value="logout">
        <button type="submit" class="logout-btn" title="Sign Out">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        </button>
      </form>
    </div>
  </aside>

  <!-- MAIN -->
  <div class="main-content">
    <header class="app-header">
      <button class="hamburger" id="hamburgerBtn" onclick="toggleSidebar()" aria-label="Toggle menu">
        <span class="hamburger-bar bar1"></span>
        <span class="hamburger-bar bar2"></span>
        <span class="hamburger-bar bar3"></span>
      </button>
      <div class="header-title" id="page-title">
        <?php
          $titles = ['dashboard'=>'Dashboard','words'=>'Word Library','categories'=>'Categories','review'=>'Review Cards','import_export'=>'Import / Export','settings'=>'Settings'];
          echo $titles[$page] ?? 'LexiVault';
        ?>
      </div>
      <div class="header-actions">
        <?php if ($page === 'words'): ?>
        <button class="btn btn-primary" onclick="openAddWord()">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          <span class="hide-mobile">Add Word</span>
        </button>
        <?php endif; ?>
        <?php if ($page === 'words'): ?>
        <button class="btn btn-secondary" onclick="printVocabulary()" title="Print List">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        </button>
        <?php endif; ?>
      </div>
    </header>

    <main class="page-content">

    <?php if ($page === 'dashboard'): ?>
    <!-- ======= DASHBOARD ======= -->
    <div id="dashboard-content">
      <div class="stats-grid" id="stats-grid">
        <div class="stat-card">
          <div class="stat-icon blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg></div>
          <div class="stat-info"><div class="stat-value" id="stat-total">–</div><div class="stat-label">Total Words</div></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg></div>
          <div class="stat-info"><div class="stat-value" id="stat-mastered">–</div><div class="stat-label">Mastered</div></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon gold"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg></div>
          <div class="stat-info"><div class="stat-value" id="stat-starred">–</div><div class="stat-label">Starred</div></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon navy"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
          <div class="stat-info"><div class="stat-value" id="stat-today">–</div><div class="stat-label">Added Today</div></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div>
          <div class="stat-info"><div class="stat-value" id="stat-week">–</div><div class="stat-label">This Week</div></div>
        </div>
      </div>

      <div id="wotd-container" style="display:none; margin-bottom:16px;"></div>

      <div id="dash-charts">
        <div class="card">
          <div class="card-header">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18" style="color:var(--accent)"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
            <h3>By Category</h3>
          </div>
          <div class="card-body" id="cat-chart">
            <div class="loading"><div class="spinner"></div> Loading...</div>
          </div>
        </div>
        <div class="card">
          <div class="card-header">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18" style="color:var(--accent)"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            <h3>Words per Day</h3>
          </div>
          <div class="card-body" id="daily-chart">
            <div class="loading"><div class="spinner"></div> Loading...</div>
          </div>
        </div>
      </div>

      <div id="dash-lists">
        <div class="card">
          <div class="card-header"><h3 style="display:flex;align-items:center;gap:6px"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18" style="color:var(--accent)"><path d="M2 12h4l3-9 5 18 3-9h5"/></svg> Most Opened Words</h3></div>
          <div class="card-body" id="most-opened-list" style="padding:0"></div>
        </div>
        <div class="card">
          <div class="card-header"><h3 style="display:flex;align-items:center;gap:6px"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18" style="color:var(--accent)"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg> Recently Learned</h3></div>
          <div class="card-body" id="today-learned-list" style="padding:0"></div>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18" style="color:var(--accent)"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
          <h3>Recently Added</h3>
          <a href="?page=words" class="btn btn-sm btn-ghost" style="margin-left:auto">View All</a>
        </div>
        <div class="card-body" id="recent-words" style="padding:0">
          <div class="loading"><div class="spinner"></div> Loading...</div>
        </div>
      </div>
    </div>

    <?php elseif ($page === 'words'): ?>
    <!-- ======= WORDS ======= -->
    <div class="filter-bar">
      <div class="search-input-wrap">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" class="search-input" id="search-input" placeholder="Search terms, definitions, notes..." oninput="debounceSearch()">
      </div>
      <select class="filter-select" id="cat-filter" onchange="loadWords()">
        <option value="">All Categories</option>
      </select>
      <select class="filter-select" id="word-type-filter" onchange="loadWords()">
        <option value="">All Word Types</option>
      </select>
      <select class="filter-select" id="tag-filter" onchange="loadWords()">
        <option value="">All Tags</option>
      </select>
      <input type="text" class="filter-select" id="date-filter" onchange="loadWords()" onfocus="this.type='date'; setTimeout(() => { if(this.showPicker) this.showPicker(); }, 50);" onblur="(this.type=this.value?'date':'text')" placeholder="Select date" title="Filter by date">
      <select class="filter-select" id="diff-filter" onchange="loadWords()">
        <option value="">All Difficulty</option>
        <option value="easy">Easy</option>
        <option value="medium">Medium</option>
        <option value="hard">Hard</option>
      </select>
      <select class="filter-select" id="status-filter" onchange="loadWords()">
        <option value="">All Statuses</option>
        <option value="starred">Favorites (Starred)</option>
        <option value="mastered">Mastered</option>
        <option value="learning">Learning</option>
      </select>
      <select class="filter-select" id="sort-filter" onchange="loadWords()">
        <option value="newest">Newest First</option>
        <option value="oldest">Oldest First</option>
        <option value="az">A - Z</option>
        <option value="za">Z - A</option>
        <option value="mastered">Mastered</option>
        <option value="starred">Starred</option>
      </select>
      <select class="filter-select" id="per-page-filter" onchange="changePerPage()">
        <?php if (!in_array($wordsPerPage, [5, 10, 20, 50, 100])): ?>
        <option value="<?= $wordsPerPage ?>" selected><?= $wordsPerPage ?> per page</option>
        <?php endif; ?>
        <option value="5" <?= $wordsPerPage==5?'selected':'' ?>>5 per page</option>
        <option value="10" <?= $wordsPerPage==10?'selected':'' ?>>10 per page</option>
        <option value="20" <?= $wordsPerPage==20?'selected':'' ?>>20 per page</option>
        <option value="50" <?= $wordsPerPage==50?'selected':'' ?>>50 per page</option>
        <option value="100" <?= $wordsPerPage==100?'selected':'' ?>>100 per page</option>
      </select>
      <div class="view-toggle">
        <button class="view-btn active" id="grid-view-btn" onclick="setView('grid')" title="Grid View">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
        </button>
        <button class="view-btn" id="list-view-btn" onclick="setView('list')" title="List View">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
        </button>
      </div>
    </div>
    <div id="words-result-info" style="font-size:13px;color:var(--gray-500);margin-bottom:14px"></div>
    <div id="words-grid-view">
      <div class="words-grid" id="words-grid">
        <div class="loading" style="grid-column:1/-1"><div class="spinner"></div> Loading...</div>
      </div>
    </div>
    <div id="words-table-view">
      <div class="card">
        <div class="table-responsive">
          <table class="data-table" id="words-table">
            <thead>
              <tr>
                <th style="width:30px"><input type="checkbox" id="select-all-cb" onchange="toggleSelectAll(this)" title="Select All on Page"></th>
                <th>Term</th><th>Category</th><th>Difficulty</th><th>Date</th><th>Mastered</th><th style="text-align:right">Actions</th>
              </tr>
            </thead>
            <tbody id="words-table-body"></tbody>
          </table>
        </div>
      </div>
    </div>
    <div id="words-pagination" class="pagination"></div>

    <?php elseif ($page === 'categories'): ?>
    <!-- ======= CATEGORIES ======= -->
    <div class="filter-bar" style="display:flex;justify-content:space-between;align-items:center;flex-direction:row !important;flex-wrap:nowrap !important;">
      <div class="search-input-wrap" style="flex:1;max-width:400px;min-width:0;margin-right:10px;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" class="search-input" id="cat-search" placeholder="Search categories..." oninput="filterCats()">
      </div>
      <button class="btn btn-primary" onclick="openCatModal()" style="white-space:nowrap;flex-shrink:0;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        New <span class="hide-mobile">Category</span>
      </button>
    </div>
    <div class="cats-grid" id="cats-grid">
      <div class="loading"><div class="spinner"></div> Loading...</div>
    </div>

    <?php elseif ($page === 'review'): ?>
    <!-- ======= REVIEW CARDS ======= -->
    <div style="max-width:600px;margin:0 auto">
      <div class="filter-bar" style="padding:14px;margin-bottom:20px;justify-content:center;flex-direction:row !important;flex-wrap:nowrap !important;">
        <select class="filter-select" id="review-cat" onchange="loadReviewCard()" style="flex:1;min-width:0;">
          <option value="">All Categories</option>
        </select>
        <select class="filter-select" id="review-filter" onchange="loadReviewCard()" style="flex:1;min-width:0;">
          <option value="">All Words</option>
          <option value="notmastered">Not Mastered</option>
          <option value="mastered">Mastered</option>
          <option value="starred">Starred</option>
        </select>
      </div>
      <div style="background:var(--gray-200);height:4px;border-radius:2px;margin-bottom:12px;overflow:hidden"><div id="review-progress-bar" style="background:var(--gold);height:100%;width:0%;transition:width 0.3s"></div></div>
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;font-size:13px;color:var(--gray-500)">
        <div id="review-progress">Loading...</div>
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer"><input type="checkbox" id="review-autoplay"> Auto-play Audio</label>
      </div>
      <div class="review-card-wrap" id="review-card-wrap" onclick="flipReviewCard()">
        <div class="review-card" id="review-card">
          <div class="review-front">
            <div class="term" id="review-term">Loading...</div>
            <div class="hint">Click to reveal definition</div>
          </div>
          <div class="review-back">
            <div class="definition" id="review-def"></div>
          </div>
        </div>
      </div>
      <div style="display:flex;justify-content:center;align-items:center;gap:12px;margin-top:20px;flex-wrap:wrap">
        <button class="btn btn-secondary" onclick="prevReviewCard()">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
          Previous
        </button>
        <button id="review-mastered-btn" class="btn btn-success" onclick="markReviewMastered(event)">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Mark as Mastered
        </button>
        <button class="btn btn-secondary" onclick="nextReviewCard()">
          Next
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
        </button>
      </div>
      <div style="text-align:center;margin-top:24px">
        <button class="btn btn-ghost" onclick="shuffleReview()">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 3 21 3 21 8"/><line x1="4" y1="20" x2="21" y2="3"/><polyline points="21 16 21 21 16 21"/><line x1="15" y1="15" x2="21" y2="21"/></svg>
          Shuffle
        </button>
      </div>
    </div>

    <?php elseif ($page === 'import_export'): ?>
    <!-- ======= IMPORT / EXPORT ======= -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
      <div class="card">
        <div class="card-header">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18" style="color:var(--accent)"><polyline points="8 17 12 21 16 17"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.88 18.09A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.29"/></svg>
          <h3>Export Words</h3>
        </div>
        <div class="card-body">
          <p style="font-size:13.5px;color:var(--gray-600);margin-bottom:20px">Export all your vocabulary words for backup or sharing.</p>
          <div style="display:flex;flex-direction:column;gap:12px">
            <a href="?api=export&format=json" class="btn btn-primary" target="_blank">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
              Export as JSON
            </a>
            <a href="?api=export&format=csv" class="btn btn-secondary" target="_blank">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
              Export as CSV
            </a>
            <a href="?api=export&format=sql" class="btn btn-secondary" target="_blank">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
              Export as SQL
            </a>
          </div>
        </div>
      </div>
      <div class="card">
        <div class="card-header">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18" style="color:var(--accent)"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
          <h3>Import Words</h3>
        </div>
        <div class="card-body">
          <p style="font-size:13.5px;color:var(--gray-600);margin-bottom:16px">Import words from a previously exported JSON file.</p>
          <div class="form-field">
            <label>Select JSON File</label>
            <input type="file" id="import-file" accept=".json" onchange="previewImport()" style="padding:8px">
          </div>
          <div id="import-preview" style="display:none;margin-bottom:14px">
            <div style="background:var(--navy-50);border:1px solid var(--gray-200);border-radius:var(--radius-sm);padding:12px;font-size:13px;color:var(--gray-600)">
              Found <strong id="import-count">0</strong> words in file.
            </div>
          </div>
          <button class="btn btn-primary" onclick="doImport()" id="import-btn" disabled>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/></svg>
            Import Words
          </button>
        </div>
      </div>
    </div>
    <div class="card" style="margin-top:20px">
      <div class="card-header">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18" style="color:var(--accent)"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <h3>Import Format</h3>
      </div>
      <div class="card-body">
        <p style="font-size:13.5px;color:var(--gray-600);margin-bottom:12px">JSON should be an array of word objects. Supported fields:</p>
        <pre style="background:var(--gray-50);border:1px solid var(--gray-200);border-radius:var(--radius-sm);padding:14px;font-size:12px;overflow-x:auto;color:var(--gray-700)">[
  {
    "term": "Ephemeral",
    "definition": "&lt;p&gt;Lasting for a very short time&lt;/p&gt;",
    "pronunciation": "/ɪˈfem.ər.əl/",
    "part_of_speech": "adjective",
    "category_id": 1,
    "tags": ["literary", "time"],
    "difficulty": "medium",
    "source": "Oxford Dictionary"
  }
]</pre>
      </div>
    </div>

    <?php elseif ($page === 'settings'): ?>
    <!-- ======= SETTINGS ======= -->
    <form id="settings-form">
    <div class="settings-section">
      <div class="settings-section-header">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
        <h3>Application Settings</h3>
      </div>
      <div class="settings-body">
        <div class="form-row">
          <div class="form-field">
            <label>Words Per Page</label>
            <input type="number" name="words_per_page" id="set-per-page" min="5" max="100" value="20">
          </div>
          <div class="form-field">
            <label>Timezone</label>
            <select name="timezone" id="set-timezone">
              <option value="Asia/Kolkata">Asia/Kolkata (IST)</option>
              <option value="UTC">UTC</option>
              <option value="America/New_York">America/New_York (EST)</option>
              <option value="America/Los_Angeles">America/Los_Angeles (PST)</option>
              <option value="Europe/London">Europe/London (GMT)</option>
              <option value="Asia/Tokyo">Asia/Tokyo (JST)</option>
              <option value="Asia/Dubai">Asia/Dubai (GST)</option>
            </select>
          </div>
        </div>
      </div>
    </div>

    <div class="settings-section">
      <div class="settings-section-header">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
        <h3>Email / SMTP Configuration</h3>
      </div>
      <div class="settings-body">
        <p style="font-size:13px;color:var(--gray-500);margin-bottom:18px">Configure SMTP to receive daily word digest emails. For Gmail, use App Password with 2FA enabled.</p>
        <div class="form-row">
          <div class="form-field">
            <label>SMTP Host</label>
            <input type="text" name="smtp_host" id="set-smtp-host" placeholder="smtp.gmail.com">
          </div>
          <div class="form-field">
            <label>SMTP Port</label>
            <input type="number" name="smtp_port" id="set-smtp-port" placeholder="587" value="587">
          </div>
        </div>
        <div class="form-row">
          <div class="form-field">
            <label>SMTP Username</label>
            <input type="email" name="smtp_user" id="set-smtp-user" placeholder="your@gmail.com">
          </div>
          <div class="form-field">
            <label>SMTP Password / App Password</label>
            <input type="password" name="smtp_pass" id="set-smtp-pass" placeholder="App password">
          </div>
        </div>
        <div class="form-row">
          <div class="form-field">
            <label>From Email</label>
            <input type="email" name="smtp_from" id="set-smtp-from" placeholder="your@gmail.com">
          </div>
          <div class="form-field">
            <label>Digest Recipient Email</label>
            <input type="email" name="digest_email" id="set-digest-email" placeholder="you@example.com">
          </div>
        </div>
        <div style="margin-top:16px;background:var(--gold-pale);border:1px solid var(--gold-border);border-radius:var(--radius-sm);padding:16px">
          <h4 style="color:var(--navy-800);font-size:13px;font-weight:700;margin-bottom:6px;display:flex;align-items:center;gap:6px">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
            Digest API Endpoint
          </h4>
          <p style="font-size:12px;color:var(--gray-600);margin-bottom:10px">Call this URL to trigger digest (via cron, webhook, or manually). Keep the key secret.</p>
          <code style="display:block;background:var(--navy-900);color:#b8d6f5;padding:10px 14px;border-radius:6px;font-size:12px;word-break:break-all;user-select:all">?digest_trigger=p3uiCUO4eF8wICzuQ84WPGNP37q_I6FADptXUYyL1pk</code>
          <button type="button" class="btn btn-secondary" style="margin-top:12px;font-size:12px;padding:7px 14px" onclick="(()=>{const url=window.location.origin+window.location.pathname+'?digest_trigger=p3uiCUO4eF8wICzuQ84WPGNP37q_I6FADptXUYyL1pk';navigator.clipboard.writeText(url).then(()=>toast('Endpoint URL copied!','success'));})()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
            Copy Endpoint URL
          </button>
          <button type="button" class="btn btn-primary" style="margin-top:12px;margin-left:8px;font-size:12px;padding:7px 14px" onclick="sendTestDigest()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
            Send Test Now
          </button>
        </div>
      </div>
    </div>

    <div class="settings-section">
      <div class="settings-section-header">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        <h3>Profile &amp; Security</h3>
      </div>
      <div class="settings-body">
        <div class="form-row">
          <div class="form-field">
            <label>Display Name</label>
            <input type="text" id="prof-name" placeholder="Your name" value="<?= sanitize($user['name'] ?? '') ?>">
          </div>
          <div class="form-field">
            <label>Email Address</label>
            <input type="email" id="prof-email" placeholder="your@email.com" value="<?= sanitize($user['email'] ?? '') ?>">
          </div>
        </div>
        <div class="form-row">
          <div class="form-field">
            <label>Current Password</label>
            <input type="password" id="prof-curpass" placeholder="Current password">
          </div>
          <div class="form-field">
            <label>New Password</label>
            <input type="password" id="prof-newpass" placeholder="Leave blank to keep current">
          </div>
        </div>
        <button type="button" class="btn btn-primary" onclick="saveProfile()">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
          Update Profile
        </button>
      </div>
    </div>

    <div style="text-align:right;margin-top:20px">
      <button type="button" class="btn btn-primary" onclick="saveSettings()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
        Save Settings
      </button>
    </div>
    </form>
    <?php endif; ?>

    </main>
  </div>
</div>

<!-- ======= WORD MODAL ======= -->
<div class="modal-overlay" id="word-modal-overlay">
  <div class="modal">
    <div class="modal-header">
      <h3 id="word-modal-title">Add New Word</h3>
      <button class="modal-close" onclick="closeWordModal()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="word-id">
      <div class="form-row">
        <div class="form-field">
          <label>Term / Word *</label> 
          <div style="position:relative">
            <input type="text" id="word-term" placeholder="e.g. Ephemeral" style="padding-right:85px">
            <div style="position:absolute; right:8px; top:50%; transform: translateY(-50%);">
              <button class="btn btn-secondary btn-sm" onclick="autoFetchDefinition()" type="button" title="Auto-fetch from Dictionary">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15">
                  <polyline points="8 17 12 21 16 17"></polyline>
                  <line x1="12" y1="12" x2="12" y2="21"></line>
                  <path d="M20.88 18.09A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.29"></path>
                </svg>
                Fetch
              </button>
            </div>
          </div>
        </div>
        <div class="form-field">
          <label>Pronunciation</label>
          <input type="text" id="word-pronunciation" placeholder="/ɪˈfem.ər.əl/">
        </div>
      </div>
      <div class="form-row">
        <div class="form-field">
          <label>Word Class / Type</label>
          <select id="word-class" onchange="toggleManualClass()">
            <option value="">Select...</option>
          </select>
          <input type="text" id="word-class-manual" placeholder="Enter custom word class..." style="display:none; margin-top:8px;">
        </div>
        <div class="form-field">
          <label>Category</label>
          <select id="word-category">
            <option value="0">Uncategorized</option>
          </select>
        </div>
      </div>
      <div class="form-field">
        <label>Definition *</label>
        <div class="quill-wrapper" id="def-quill-wrap">
          <div id="def-editor"></div>
        </div>
      </div>
      <div class="form-row">
        <div class="form-field">
          <label>Difficulty</label>
          <select id="word-difficulty">
            <option value="easy">Easy</option>
            <option value="medium" selected>Medium</option>
            <option value="hard">Hard</option>
          </select>
        </div>
        <div class="form-field">
          <label>Source / Reference</label>
          <input type="text" id="word-source" placeholder="Oxford, Merriam-Webster...">
        </div>
      </div>
      <div class="form-field" style="position:relative;">
        <label>Tags (comma separated)</label>
        <input type="text" id="word-tags" placeholder="science, important, exam..." oninput="suggestTags(this)" autocomplete="off">
        <div id="tag-suggestions" class="autocomplete-items" style="display:none; bottom:100%; top:auto; border-radius:var(--radius) var(--radius) 0 0; border-top:1px solid var(--gray-200); border-bottom:none; z-index:1000; max-height:200px; overflow-y:auto;"></div>
      </div>
      <div class="form-field">
        <label>Personal Notes</label>
        <textarea id="word-notes" rows="3" placeholder="Mnemonic, personal context, related words..."></textarea>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeWordModal()">Cancel</button>
      <button class="btn btn-primary" onclick="saveWord()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/></svg>
        Save Word
      </button>
    </div>
  </div>
</div>

<!-- ======= WORD DETAIL MODAL ======= -->
<div class="modal-overlay" id="word-detail-overlay">
  <div class="modal">
    <div class="modal-header">
      <h3>Word Details</h3>
      <button class="modal-close" onclick="closeDetailModal()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div class="modal-body" id="word-detail-content" style="padding:0">
    </div>
    <div class="modal-footer" id="word-detail-footer">
    </div>
  </div>
</div>

<!-- ======= CATEGORY MODAL ======= -->
<div class="modal-overlay" id="cat-modal-overlay">
  <div class="modal modal-sm">
    <div class="modal-header">
      <h3 id="cat-modal-title">New Category</h3>
      <button class="modal-close" onclick="closeCatModal()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="cat-id">
      <div class="form-field">
        <label>Category Name *</label>
        <input type="text" id="cat-name" placeholder="e.g. Biology, Technology...">
      </div>
      <div class="form-field">
        <label>Description</label>
        <input type="text" id="cat-desc" placeholder="Brief description">
      </div>
      <div class="form-field">
        <label>Color</label>
        <input type="color" id="cat-color" value="#1e3a5f" style="height:42px;padding:4px 8px;cursor:pointer">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeCatModal()">Cancel</button>
      <button class="btn btn-primary" onclick="saveCat()">Save Category</button>
    </div>
  </div>
</div>

<!-- ======= BULK ACTIONS UI ======= -->
<div id="bulk-action-bar">
  <span id="bulk-count" style="font-weight:600;font-size:14px;white-space:nowrap">0 selected</span>
  <div class="bulk-divider" style="width:1px;height:20px;background:rgba(255,255,255,0.2);margin:0 4px"></div>
  <button class="btn btn-sm" style="background:rgba(255,255,255,0.1);color:white;border:none" onclick="bulkAction('categorize')" title="Categorize">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
    <span class="hide-mobile">Categorize</span>
  </button>
  <button class="btn btn-sm" style="background:rgba(255,255,255,0.1);color:white;border:none" onclick="bulkAction('master')" title="Master">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><polyline points="20 6 9 17 4 12"/></svg>
    <span class="hide-mobile">Master</span>
  </button>
  <button class="btn btn-sm" style="background:rgba(255,255,255,0.1);color:white;border:none" onclick="bulkAction('star')" title="Star">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
    <span class="hide-mobile">Star</span>
  </button>
  <button class="btn btn-sm btn-danger" onclick="bulkAction('delete')" title="Delete">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
    <span class="hide-mobile">Delete</span>
  </button>
  <button class="btn btn-icon" style="background:none;color:var(--gray-400);border:none;margin-left:auto" onclick="clearSelection()" title="Clear Selection">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
  </button>
</div>

<!-- BULK CATEGORIZE MODAL -->
<div class="modal-overlay" id="bulk-cat-overlay">
  <div class="modal modal-sm">
    <div class="modal-header"><h3>Bulk Categorize</h3><button class="modal-close" onclick="document.getElementById('bulk-cat-overlay').classList.remove('active')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <div class="modal-body">
      <div class="form-field">
        <label>Select Category</label>
        <select id="bulk-category-select"><option value="0">Uncategorized</option></select>
      </div>
    </div>
    <div class="modal-footer"><button class="btn btn-secondary" onclick="document.getElementById('bulk-cat-overlay').classList.remove('active')">Cancel</button><button class="btn btn-primary" onclick="confirmBulkCategorize()">Apply</button></div>
  </div>
</div>

<!-- TOAST CONTAINER -->
<div id="toast-container"></div>

<script>
// ============================================================
// GLOBAL STATE
// ============================================================
let currentPage = 1;
let perPage = <?= $wordsPerPage ?? 20 ?>;
let currentView = 'grid';
let selectedWords = new Set();
let allWordsCache = [];
let isDataLoaded = false;
let filteredWords = [];
let reviewWords = [];
let reviewIndex = 0;
let deleteWordId = null;
let quillDef = null;
let importData = null;
let allAvailableTags = [];

// ============================================================
// WORD CLASSES & DROPDOWN RENDER
// ============================================================
const BASE_WORD_CLASSES = [ // Standardized, advanced-level word types
  'Abbreviation / Acronym', 'Concept / Theory', 'Framework / Model', 'Algorithm / Method',
  'Protocol / Procedure', 'Mechanism of Action', 'Active Ingredient',
  'Excipient / Agent', 'Biomarker / Indicator', 'Diagnostic / Test',
  'Reagent / Compound'
];

function populateWordClassDropdown(selectedValue = '') {
  const sel = document.getElementById('word-class');
  const manualInput = document.getElementById('word-class-manual');
  if(!sel) return;

  const usedClasses = allWordsCache ? [...new Set(allWordsCache.map(w => w.word_class).filter(Boolean))] : [];
  const customClasses = usedClasses.filter(c => !BASE_WORD_CLASSES.includes(c));

  let html = '<option value="">Select...</option>';
  BASE_WORD_CLASSES.forEach(c => { html += `<option value="${esc(c)}">${esc(c)}</option>`; });

  if (customClasses.length > 0) {
    html += '<optgroup label="Custom / Saved">';
    customClasses.sort().forEach(c => { html += `<option value="${esc(c)}">${esc(c)}</option>`; });
    html += '</optgroup>';
  }

  html += '<option value="__OTHER__">Other (Manual Input)...</option>';
  sel.innerHTML = html;

  if (selectedValue) {
    if (BASE_WORD_CLASSES.includes(selectedValue) || customClasses.includes(selectedValue)) {
      sel.value = selectedValue;
      manualInput.style.display = 'none';
      manualInput.value = '';
    } else {
      sel.value = '__OTHER__';
      manualInput.style.display = 'block';
      manualInput.value = selectedValue;
    }
  } else {
    sel.value = '';
    manualInput.style.display = 'none';
    manualInput.value = '';
  }
}

function toggleManualClass() {
  const sel = document.getElementById('word-class');
  const manualInput = document.getElementById('word-class-manual');
  if (sel.value === '__OTHER__') {
    manualInput.style.display = 'block';
    manualInput.focus();
  } else {
    manualInput.style.display = 'none';
  }
}

// ============================================================
// QUILL INIT
// ============================================================
// ============================================================
// QUILL + WORD MODAL INIT (all pages)
// ============================================================
document.addEventListener('DOMContentLoaded', () => {
  try {
    const toolbarOptions = [
      ['bold', 'italic', 'underline', 'strike'],
      [{ 'list': 'ordered'}, { 'list': 'bullet' }],
      ['link'],
      [{ 'size': ['small', false, 'large'] }],
      ['clean']
    ];
    if (typeof Quill !== 'undefined') {
      quillDef = new Quill('#def-editor', { theme: 'snow', modules: { toolbar: toolbarOptions }, placeholder: 'Enter a clear definition...' });
      quillDef.on('focus', () => document.getElementById('def-quill-wrap').classList.add('focused'));
      quillDef.on('blur', () => document.getElementById('def-quill-wrap').classList.remove('focused'));
    }
  } catch (e) { console.error('Quill init failed', e); }

<?php if ($page === 'words'): ?>
  loadWords();
  loadCatsForFilter();
  loadWordCount();
  
  loadWords().then(() => {
    const params = new URLSearchParams(window.location.search);
    if (params.has('q')) {
      const searchEl = document.getElementById('search-input');
      if (searchEl) { searchEl.value = decodeURIComponent(params.get('q')); loadWords(); }
      window.history.replaceState({}, '', window.location.pathname + '?page=words');
    }
    if (params.has('focus_search')) {
      const searchEl = document.getElementById('search-input');
      if (searchEl) setTimeout(() => searchEl.focus(), 200);
      window.history.replaceState({}, '', window.location.pathname + '?page=words');
    }
    if (params.has('view_id')) {
      viewWord(parseInt(params.get('view_id')));
      window.history.replaceState({}, '', window.location.pathname + '?page=words');
    }
  });
<?php endif; ?>
});


// ============================================================
// SIDEBAR
// ============================================================
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('sidebarOverlay').classList.toggle('active');
  var hb = document.getElementById('hamburgerBtn');
  if (hb) hb.classList.toggle('is-open');
}
function closeSidebar() {
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('sidebarOverlay').classList.remove('active');
  var hb = document.getElementById('hamburgerBtn');
  if (hb) hb.classList.remove('is-open');
}

// ============================================================
// TOAST
// ============================================================
function toast(msg, type = 'info') {
  const icons = {
    success: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>',
    error: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
    info: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>'
  };
  const t = document.createElement('div');
  t.className = `toast ${type}`;
  t.innerHTML = (icons[type] || icons.info) + `<span>${msg}</span>`;
  document.getElementById('toast-container').appendChild(t);
  requestAnimationFrame(() => { requestAnimationFrame(() => t.classList.add('show')); });
  setTimeout(() => {
    t.classList.remove('show');
    setTimeout(() => t.remove(), 350);
  }, 3500);
}

function toastUndo(msg, undoCallback) {
  const t = document.createElement('div');
  t.className = `toast info`;
  t.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
  <span style="flex:1">${msg}</span>
  <button class="btn btn-sm" style="background:rgba(255,255,255,0.2);color:white;border:none;margin-left:10px" onclick="this.parentElement.undoFn()">Undo</button>`;
  t.undoFn = () => {
    undoCallback();
    t.classList.remove('show');
    setTimeout(() => t.remove(), 350);
  };
  document.getElementById('toast-container').appendChild(t);
  requestAnimationFrame(() => { requestAnimationFrame(() => t.classList.add('show')); });
  setTimeout(() => {
    if (t.parentElement) {
      t.classList.remove('show');
      setTimeout(() => { if (t.parentElement) t.remove(); }, 350);
    }
  }, 5000); // Wait 5 seconds for undo
}

// ============================================================
// API CALL
// ============================================================
async function api(action, data = {}, method = 'GET') {
  try {
    const opts = { 
      method, 
      headers: { 
        'Content-Type': 'application/json',
        'Cache-Control': 'no-cache, no-store, must-revalidate',
        'Pragma': 'no-cache',
        'Expires': '0'
      } 
    };
    if (method === 'POST') opts.body = JSON.stringify(data);
    const url = `?api=${action}&_t=${Date.now()}`;
    const res = await fetch(url, opts);
    if (!res.ok) return { success: false, message: 'HTTP Error ' + res.status };
    return await res.json();
  } catch (e) {
    console.error(e);
    return { success: false, message: 'Request failed' };
  }
}
async function apiGet(action, params = {}) {
  try {
    params._t = Date.now();
    const q = new URLSearchParams({ api: action, ...params });
    const res = await fetch('?' + q, { 
      cache: 'no-store',
      headers: {
        'Cache-Control': 'no-cache, no-store, must-revalidate',
        'Pragma': 'no-cache',
        'Expires': '0'
      }
    });
    if (!res.ok) return { success: false, message: 'HTTP Error ' + res.status };
    return await res.json();
  } catch (e) {
    return { success: false, message: 'Request failed' };
  }
}
async function apiPost(action, data = {}) {
  return api(action, data, 'POST');
}

// ============================================================
// WORD COUNT BADGE
// ============================================================
async function loadWordCount() {
  const r = await apiGet('stats');
  if (r.success) {
    const el = document.getElementById('sb-word-count');
    if (el) el.textContent = r.stats.total;
  }
}

// ============================================================
// DASHBOARD
// ============================================================
<?php if ($page === 'dashboard'): ?>
document.addEventListener('DOMContentLoaded', loadDashboard);
async function loadDashboard() {
  const r = await apiGet('stats');
  if (!r.success) return;
  const s = r.stats;
  document.getElementById('stat-total').textContent = s.total;
  document.getElementById('stat-mastered').textContent = s.mastered;
  document.getElementById('stat-starred').textContent = s.starred;
  document.getElementById('stat-today').textContent = s.today;
  document.getElementById('stat-week').textContent = s.week;
  
  // Word of the day — use today's stored word if available, else server's seeded pick
  renderWOTD(getStoredWOTD() || s.wotd);

  // Cat chart
  const catEl = document.getElementById('cat-chart');
  if (Object.keys(s.categories).length === 0) {
    catEl.innerHTML = '<div class="empty-state" style="padding:30px"><p>No data yet</p></div>';
  } else {
    const sorted = Object.entries(s.categories).sort((a,b)=>b[1]-a[1]);
    const max = Math.max(...sorted.map(e=>e[1]));
    const top5 = sorted.slice(0,6);
    const rest = sorted.slice(6);
    let html = '<div class="chart-bar-wrap" id="cat-bar-main">';
    top5.forEach(([name,count]) => {
      const pct = max > 0 ? (count/max*100) : 0;
      html += `<div class="chart-bar-item"><div class="chart-bar-label">${esc(name)}</div><div class="chart-bar-track"><div class="chart-bar-fill" style="width:${pct}%"></div></div><div class="chart-bar-count">${count}</div></div>`;
    });
    html += '</div>';
    if (rest.length > 0) {
      html += '<div class="chart-bar-wrap" id="cat-bar-extra" style="display:none;">';
      rest.forEach(([name,count]) => {
        const pct = max > 0 ? (count/max*100) : 0;
        html += `<div class="chart-bar-item"><div class="chart-bar-label">${esc(name)}</div><div class="chart-bar-track"><div class="chart-bar-fill" style="width:${pct}%"></div></div><div class="chart-bar-count">${count}</div></div>`;
      });
      html += '</div>';
    }
    catEl.innerHTML = html;
    // Inject expand button into card-header
    const catCardHeader = catEl.closest('.card').querySelector('.card-header');
    if (rest.length > 0 && catCardHeader && !catCardHeader.querySelector('#cat-expand-btn')) {
      const btn = document.createElement('button');
      btn.id = 'cat-expand-btn';
      btn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="16" height="16"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>`;
      btn.style.cssText = 'margin-left:auto;background:var(--gray-100);border:none;border-radius:6px;width:28px;height:28px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--gray-600);transition:all 0.2s;flex-shrink:0;';
      btn.title = 'Show all categories';
      let expanded = false;
      btn.onclick = () => {
        expanded = !expanded;
        document.getElementById('cat-bar-extra').style.display = expanded ? 'flex' : 'none';
        document.getElementById('cat-bar-extra').style.marginTop = expanded ? '10px' : '0';
        btn.innerHTML = expanded
          ? `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="16" height="16"><line x1="5" y1="12" x2="19" y2="12"/></svg>`
          : `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="16" height="16"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>`;
        btn.style.background = expanded ? 'var(--navy-50)' : 'var(--gray-100)';
        btn.style.color = expanded ? 'var(--accent)' : 'var(--gray-600)';
        btn.title = expanded ? 'Show less' : 'Show all categories';
      };
      catCardHeader.appendChild(btn);
    }
  }
  // Daily chart — last 7 days (enforced on client side too)
  const dailyEl = document.getElementById('daily-chart');
  // Sort by date, take last 7 only — defensive against stale/cached data
  const allEntries = Object.entries(s.daily).sort((a, b) => a[0].localeCompare(b[0]));
  const entries = allEntries.slice(-7);
  const serverToday = s.server_today;

  if (entries.length === 0) {
    dailyEl.innerHTML = '<div class="empty-state" style="padding:30px"><p>No data yet</p></div>';
  } else {
    const dmax = Math.max(...entries.map(e => e[1]), 1);
    const dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

    const paddingTop    = 34;
    const paddingBottom = 44;
    const paddingLeft   = 10;
    const paddingRight  = 10;
    // Use actual container height so bars fill the full card — min 100 as fallback
    const containerH    = dailyEl.clientHeight || 0;
    const chartH        = Math.max(100, containerH - paddingTop - paddingBottom - 4);
    const nBars         = entries.length;
    const gridCount     = 4;

    const containerW  = dailyEl.clientWidth || 320;
    const colW        = Math.max(40, Math.floor((containerW - paddingLeft - paddingRight) / nBars));
    const svgW        = paddingLeft + paddingRight + nBars * colW;
    const barW        = 18; // Slimmer bars
    const svgH        = paddingTop + chartH + paddingBottom;
    const uid         = 'dc' + Date.now();

    let defs = `<defs>`;
    entries.forEach(([date, count], i) => {
      const isToday = date === serverToday;
      const isEmpty = count === 0;
      // App palette matching stats cards
      const top = isEmpty ? '#f9f6ee' : (isToday ? '#e0bb52' : '#d9c787');
      const bot = isEmpty ? '#f0ead9' : (isToday ? '#b8860b' : '#a78a3f');
      defs += `<linearGradient id="${uid}_g${i}" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0%" stop-color="${top}"/>
        <stop offset="100%" stop-color="${bot}"/>
      </linearGradient>`;
      if (isToday) {
        defs += `<filter id="${uid}_glow" x="-20%" y="-20%" width="140%" height="140%">
          <feDropShadow dx="0" dy="4" stdDeviation="6" flood-color="#b8860b" flood-opacity="0.3"/>
        </filter>`;
      }
    });
    defs += `</defs>`;

    let svg = defs;

    // Grid lines — clean light gray, dashed for inner lines
    for (let g = 0; g <= gridCount; g++) {
      const lineY   = paddingTop + (chartH / gridCount) * g;
      const isBase  = g === gridCount;
      svg += `<line
        x1="${paddingLeft}" y1="${lineY}"
        x2="${svgW - paddingRight}" y2="${lineY}"
        stroke="${isBase ? '#c5d3e8' : '#eef2f8'}"
        stroke-width="${isBase ? 2 : 1}"
        stroke-dasharray="${isBase ? 'none' : '4,4'}"
        style="pointer-events:none;"/>`;
    }

    entries.forEach(([date, count], i) => {
      const isToday = date === serverToday;
      const dayObj   = new Date(date + 'T00:00:00');
      const dayLabel = isToday ? 'Today' : dayNames[dayObj.getDay()];
      const dateLabel = date.slice(5);

      const barH  = count > 0 ? Math.max(8, (count / dmax) * chartH) : 0;
      const centerX = paddingLeft + i * colW + colW / 2;
      const x       = centerX - barW / 2;
      const barY  = paddingTop + chartH - barH;
      const barRx = 6;

      // Text colors from app palette
      const dayColor  = isToday ? '#8a6a14' : '#9a8f6e';   // gold-dark vs warm gray
      const dateColor = isToday ? '#b8860b' : '#b3a780';   // gold vs muted gold-gray

      svg += `<g class="daily-bar-group${isToday ? ' bar-today' : ''}" onclick="focusDailyBar(this,event)">`;

      // Full-column hover hit area (invisible)
      svg += `<rect x="${centerX - colW/2}" y="${paddingTop - 4}" width="${colW}" height="${chartH + 8}" fill="transparent"/>`;

      // Hover background
      svg += `<rect class="bar-hover-bg" x="${centerX - colW/2 + 2}" y="${paddingTop}" width="${colW - 4}" height="${chartH}" rx="8"
        fill="${isToday ? 'rgba(184,134,11,0.1)' : 'rgba(154,143,110,0.07)'}"
        style="pointer-events:none;opacity:0;transition:opacity 0.2s;"/>`;

      // Bar
      if (count > 0) {
        const pathD = `M ${x},${barY + barRx} A ${barRx},${barRx} 0 0 1 ${x + barRx},${barY} L ${x + barW - barRx},${barY} A ${barRx},${barRx} 0 0 1 ${x + barW},${barY + barRx} L ${x + barW},${barY + barH} L ${x},${barY + barH} Z`;
        svg += `<path class="bar-rect"
          d="${pathD}"
          fill="url(#${uid}_g${i})"
          ${isToday ? `filter="url(#${uid}_glow)"` : ''}
          style="pointer-events:none;transition:filter 0.2s ease, transform 0.2s ease;">
          <title>${date}: ${count} word${count !== 1 ? 's' : ''}</title>
        </path>`;
      }

      // Count label above bar inside a badge
      if (count > 0) {
        svg += `<rect x="${centerX - 14}" y="${barY - 26}" width="28" height="18" rx="5"
          fill="#8a6a14"
          stroke="none"
          style="pointer-events:none;box-shadow:0 2px 4px rgba(0,0,0,0.05);"></rect>`;
        svg += `<text class="bar-count-label"
          x="${centerX}" y="${barY - 13}"
          text-anchor="middle" font-size="11" font-weight="700"
          fill="#ffffff" font-family="inherit"
          style="pointer-events:none;">${count}</text>`;
      }

      // Day name
      svg += `<text
        x="${centerX}" y="${paddingTop + chartH + 18}"
        text-anchor="middle" font-size="11.5"
        font-weight="${isToday ? '700' : '600'}"
        fill="${dayColor}" font-family="inherit"
        style="pointer-events:none;">${dayLabel}</text>`;

      // Date sub-label
      svg += `<text
        x="${centerX}" y="${paddingTop + chartH + 32}"
        text-anchor="middle" font-size="10" font-weight="500"
        fill="${dateColor}" font-family="inherit"
        style="pointer-events:none;">${dateLabel}</text>`;

      // Today dot indicator
      if (isToday) {
        svg += `<circle cx="${centerX}" cy="${paddingTop + chartH + 42}" r="3" fill="#2a6baf" style="pointer-events:none;"/>`;
      }

      svg += `</g>`;
    });

    dailyEl.innerHTML = `<svg viewBox="0 0 ${svgW} ${svgH}" width="${svgW}" height="${svgH}"
      class="daily-chart-svg" style="display:block;min-width:${svgW}px;overflow:visible;"
    >${svg}</svg>`;

    requestAnimationFrame(() => { dailyEl.scrollLeft = dailyEl.scrollWidth; });
  }

  // Most opened list
  const moEl = document.getElementById('most-opened-list');
  if (s.most_opened && s.most_opened.length > 0) {
    moEl.innerHTML = s.most_opened.map(w => `<div class="dash-list-item" style="cursor:pointer" onclick="location.href='?page=words&view_id=${w.id}'">
      <div class="dash-list-item-text">${esc(w.term)}</div>
      <span class="dash-tag">${w.review_count||0} views</span>
    </div>`).join('');
  } else { moEl.innerHTML = '<div style="padding:16px;text-align:center;color:var(--gray-400);font-size:13px">No data yet</div>'; }

  // Today learned list
  const tlEl = document.getElementById('today-learned-list');
  if (s.today_learned && s.today_learned.length > 0) {
    tlEl.innerHTML = s.today_learned.map(w => `<div class="dash-list-item" style="cursor:pointer" onclick="location.href='?page=words&view_id=${w.id}'">
      <div class="dash-list-item-text">${esc(w.term)}</div>
      <span class="dash-tag">${(w.last_reviewed || w.updated_at || '').slice(0,10)}</span>
    </div>`).join('');
  } else { tlEl.innerHTML = '<div style="padding:16px;text-align:center;color:var(--gray-400);font-size:13px">No words mastered recently</div>'; }

  // Recent words
  const rw = await apiGet('words_list');
  const recentEl = document.getElementById('recent-words');
  if (!rw.success || rw.words.length === 0) {
    recentEl.innerHTML = '<div class="empty-state"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg><h4>No words yet</h4><p>Start by adding your first word.</p></div>';
    return;
  }
  const recent = rw.words.slice(0, 5);
  let tableHtml = '<div class="table-responsive"><table class="data-table"><thead><tr><th style="width:35%">Term</th><th style="width:20%">Date Added</th><th style="width:25%">Category</th><th style="width:20%">Status</th></tr></thead><tbody>';
  recent.forEach(w => {
    const statusHtml = w.mastered
      ? '<span style="font-size:13px;font-weight:600;color:var(--success)">Mastered</span>'
      : '<span style="font-size:13px;font-weight:500;color:var(--gray-400)">Learning</span>';
    tableHtml += `<tr>
      <td><strong style="font-size:13px;font-weight:600;color:var(--navy-900)">${esc(w.term)}</strong></td>
      <td style="color:var(--gray-500);font-size:13px">${esc(w.date_tag)}</td>
      <td><span class="dash-tag">${esc(w.category_name)}</span></td>
      <td>${statusHtml}</td>
    </tr>`;
  });
  tableHtml += '</tbody></table></div>';
  recentEl.innerHTML = tableHtml;
  // Update sidebar badge
  const sb = document.getElementById('sb-word-count');
  if (sb) sb.textContent = s.total;
}

function renderWOTD(w) {
  const wc = document.getElementById('wotd-container');
  if (!w) { wc.style.display = 'none'; return; }
  storeWOTD(w);
  wc.style.display = 'block';
  wc.innerHTML = `
    <div class="card" style="background: linear-gradient(135deg, var(--navy-900) 0%, #2d2410 55%, var(--gold-dark, #8a6a14) 100%); color: white; border: none; overflow:hidden; position:relative;">
      <svg width="100%" height="100%" style="position:absolute;inset:0;opacity:0.12;pointer-events:none;">
        <defs><pattern id="wotdDots" width="22" height="22" patternUnits="userSpaceOnUse">
          <circle cx="3" cy="3" r="1.6" fill="var(--gold)"/>
        </pattern></defs>
        <rect width="100%" height="100%" fill="url(#wotdDots)"/>
      </svg>
      <div style="position:absolute; right:-20px; top:-20px; opacity:0.12;"><svg viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2" width="160" height="160"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg></div>
      <div class="card-body wotd-body">
        <div style="flex: 1; min-width: 0;">
          <div style="font-size:11px; color:var(--navy-200); text-transform:uppercase; font-weight:600; letter-spacing:1px; margin-bottom:4px; display:flex; align-items:center; gap:6px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg> Word of the Day</div>
          <div style="font-size:28px; font-weight:700; margin-bottom:4px; font-family:'Noto Sans', sans-serif; word-break: break-word; overflow-wrap: break-word; line-height:1.2;">${esc(w.term)}</div>
          <div style="font-size:14px; color:var(--navy-100); max-width:600px; line-height:1.5; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden;">${(w.definition || '').replace(/<[^>]+>/g, '') || '<em>No definition</em>'}</div>
        </div>
        <div class="wotd-actions">
          <button class="btn btn-icon" style="background:rgba(255,255,255,0.1); color:white; border:none;" onclick="refreshWOTD()" title="Get another word">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.92-10.44l5.67-5.67"/></svg>
          </button>
          <button class="btn" style="background:rgba(255,255,255,0.15); color:white; border:1px solid rgba(255,255,255,0.2);" onclick="location.href='?page=words&view_id=${w.id}'">
            View Details
          </button>
        </div>
      </div>
    </div>
  `;
}

// ---- WOTD localStorage daily persistence ----
const WOTD_STORE_KEY = 'lexivault_wotd';

function getTodayDateStr() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
}

function getStoredWOTD() {
  try {
    const stored = JSON.parse(localStorage.getItem(WOTD_STORE_KEY));
    if (stored && stored.date === getTodayDateStr() && stored.word) return stored.word;
  } catch(e) {}
  return null;
}

function storeWOTD(word) {
  try {
    localStorage.setItem(WOTD_STORE_KEY, JSON.stringify({ date: getTodayDateStr(), word }));
  } catch(e) {}
}

async function refreshWOTD() {
  // Only fetches a different random word (within the same day), does NOT persist as the daily word
  const current = getStoredWOTD();
  const currentId = current ? current.id : null;
  const r = await apiGet('random_word', currentId ? { exclude_id: currentId } : {});
  if (r.success && r.word) {
    // Store the new word so same-session refreshes stay consistent, but still tied to today
    storeWOTD(r.word);
    renderWOTD(r.word);
    toast('New word loaded', 'success');
  }
}

function focusDailyBar(el, event) {
  event.stopPropagation();
  const svg = el.closest('svg');
  const allGroups = svg.querySelectorAll('.daily-bar-group');
  const isAlreadyFocused = el.classList.contains('focused');

  // Clear all focused states first
  allGroups.forEach(g => g.classList.remove('focused'));
  svg.classList.remove('has-focus');

  if (!isAlreadyFocused) {
    // Focus the clicked bar — all others become passive via CSS
    el.classList.add('focused');
    svg.classList.add('has-focus');
  }
}

// Click outside chart to clear all focus
document.addEventListener('click', (e) => {
  if (!e.target.closest('.daily-chart-svg')) {
    document.querySelectorAll('.daily-chart-svg.has-focus').forEach(svg => {
      svg.classList.remove('has-focus');
      svg.querySelectorAll('.daily-bar-group.focused').forEach(g => g.classList.remove('focused'));
    });
  }
});<?php endif; ?>

// ============================================================
// WORDS PAGE
// ============================================================
<?php if ($page === 'words'): ?>
let searchTimeout = null;
function debounceSearch() {
  clearTimeout(searchTimeout);
  searchTimeout = setTimeout(() => { currentPage = 1; loadWords(); }, 300);
}

function setView(v) {
  currentView = v;
  document.getElementById('grid-view-btn').classList.toggle('active', v==='grid');
  document.getElementById('list-view-btn').classList.toggle('active', v==='list');
  document.getElementById('words-grid-view').style.display = v==='grid'?'block':'none';
  document.getElementById('words-table-view').style.display = v==='list'?'block':'none';
  renderWords(filteredWords);
}

function changePerPage() {
  perPage = parseInt(document.getElementById('per-page-filter').value);
  currentPage = 1;
  renderWords(filteredWords);
  renderPagination(filteredWords.length);
}

async function loadCatsForFilter() {
  const r = await apiGet('categories_list');
  if (!r.success) return;
  const sel = document.getElementById('cat-filter');
  const wordCatSel = document.getElementById('word-category');
  const bulkCatSel = document.getElementById('bulk-category-select');
  
  // Clear existing options except defaults to prevent dupes
  if(sel) sel.innerHTML = '<option value="">All Categories</option>';
  if(wordCatSel) wordCatSel.innerHTML = '<option value="0">Uncategorized</option>';
  if(bulkCatSel) bulkCatSel.innerHTML = '<option value="0">Uncategorized</option>';

  r.categories.forEach(c => {
    if(sel) sel.innerHTML += `<option value="${c.id}">${esc(c.name)}</option>`;
    if(wordCatSel) wordCatSel.innerHTML += `<option value="${c.id}">${esc(c.name)}</option>`;
    if(bulkCatSel) bulkCatSel.innerHTML += `<option value="${c.id}">${esc(c.name)}</option>`;
  });

  // Auto-apply ?cat= filter from URL (set by category page click)
  const urlParams = new URLSearchParams(window.location.search);
  const catParam = urlParams.get('cat');
  if (catParam && sel) {
    sel.value = catParam;
    // Clean URL without reloading
    const cleanUrl = window.location.protocol + '//' + window.location.host + window.location.pathname + '?page=words';
    window.history.replaceState({}, '', cleanUrl);
    loadWords();
  }
}

async function loadTagsForFilter() {
  const r = await apiGet('all_tags');
  if (!r.success) return;
  allAvailableTags = r.tags;
  const sel = document.getElementById('tag-filter');
  if (sel) {
    sel.innerHTML = '<option value="">All Tags</option>';
    r.tags.forEach(t => {
      sel.innerHTML += `<option value="${esc(t)}">${esc(t)}</option>`;
    });
  }
}

function suggestTags(input) {
  const list = document.getElementById('tag-suggestions');
  if (!list) return;
  const val = input.value;
  const parts = val.split(',').map(s => s.trim());
  const currentPart = parts[parts.length - 1].toLowerCase();
  
  if (!currentPart) {
    list.style.display = 'none';
    return;
  }
  
  const matches = allAvailableTags.filter(t => 
    t.toLowerCase().includes(currentPart) && 
    !parts.slice(0, -1).map(p=>p.toLowerCase()).includes(t.toLowerCase())
  );
  
  if (matches.length === 0) {
    list.style.display = 'none';
    return;
  }
  
  list.innerHTML = matches.map(m => `<div onclick="selectTag('${esc(m).replace(/'/g, "\\'")}')">${esc(m)}</div>`).join('');
  list.style.display = 'block';
}

function selectTag(tag) {
  const input = document.getElementById('word-tags');
  const parts = input.value.split(',').map(s => s.trim());
  parts.pop();
  parts.push(tag);
  input.value = parts.join(', ') + ', ';
  document.getElementById('tag-suggestions').style.display = 'none';
  input.focus();
}

async function fetchAllWords() {
  const r = await apiGet('words_list');
  if (r && r.success) {
    allWordsCache = r.words;
    isDataLoaded = true;
    const sb = document.getElementById('sb-word-count');
    if (sb) sb.textContent = r.total;
    // Populate dynamic filters now that we have the data
    populateWordTypeFilter();
    loadTagsForFilter();
  }
}

function populateWordTypeFilter() {
  const sel = document.getElementById('word-type-filter');
  if (!sel) return;
  const currentVal = sel.value; // Preserve selection
  const usedTypes = [...new Set(allWordsCache.map(w => w.word_class).filter(Boolean))].sort();
  sel.innerHTML = '<option value="">All Word Types</option>';
  usedTypes.forEach(t => {
    sel.innerHTML += `<option value="${esc(t)}">${esc(t)}</option>`;
  });
  sel.value = currentVal;
}

async function loadWords() {
  if (!isDataLoaded) {
    const grid = document.getElementById('words-grid');
    if (grid) grid.innerHTML = '<div class="loading" style="grid-column:1/-1"><div class="spinner"></div> Loading...</div>';
    await fetchAllWords();
    if (!isDataLoaded) {
        if(grid) grid.innerHTML = '<div class="empty-state">Failed to load words</div>';
        return;
    }
  }

  const search = document.getElementById('search-input').value.toLowerCase();
  const cat = document.getElementById('cat-filter').value;
  const wordType = document.getElementById('word-type-filter').value;
  const tag = document.getElementById('tag-filter') ? document.getElementById('tag-filter').value : '';
  const date = document.getElementById('date-filter').value;
  const diff = document.getElementById('diff-filter').value;
  const sort = document.getElementById('sort-filter').value;
  const status = document.getElementById('status-filter') ? document.getElementById('status-filter').value : '';
  
  let words = [...allWordsCache];

  if (search) {
      words = words.filter(w => 
          w.term.toLowerCase().includes(search) || 
          (w.definition || '').replace(/<[^>]+>/g, '').toLowerCase().includes(search) ||
          (w.notes || '').toLowerCase().includes(search) ||
          (w.tags || []).some(t => t.toLowerCase().includes(search))
      );
  }
  if (cat) words = words.filter(w => w.category_id == cat);
  if (wordType) words = words.filter(w => w.word_class === wordType);
  if (tag) words = words.filter(w => w.tags && w.tags.includes(tag));
  if (date) words = words.filter(w => w.date_tag === date);
  if (diff) words = words.filter(w => w.difficulty === diff);
  if (status === 'starred') words = words.filter(w => w.starred);
  else if (status === 'mastered') words = words.filter(w => w.mastered);
  else if (status === 'learning') words = words.filter(w => !w.mastered);

  if (sort === 'oldest') words.reverse();
  else if (sort === 'az') words.sort((a,b) => a.term.localeCompare(b.term));
  else if (sort === 'za') words.sort((a,b) => b.term.localeCompare(a.term));
  else if (sort === 'mastered') words = [...words.filter(w=>w.mastered), ...words.filter(w=>!w.mastered)];
  else if (sort === 'starred') words = [...words.filter(w=>w.starred), ...words.filter(w=>!w.starred)];
  filteredWords = words;
  renderWords(words);
  renderPagination(words.length);
}

function getPageWords(words) {
  const start = (currentPage - 1) * perPage;
  return words.slice(start, start + perPage);
}

function renderWords(words) {
  const pageWords = getPageWords(words);
  const info = document.getElementById('words-result-info');
  if (info) {
      if (words.length === 0) {
          info.textContent = 'Showing 0 words';
      } else {
          const start = (currentPage - 1) * perPage + 1;
          const end = Math.min(currentPage * perPage, words.length);
          info.textContent = `Showing ${start}-${end} of ${words.length} word${words.length !== 1 ? 's' : ''}`;
      }
  }
  if (currentView === 'grid') renderGrid(pageWords, words.length);
  else renderTable(pageWords, words.length);
}

function renderGrid(words, total) {
  const grid = document.getElementById('words-grid');
  if (words.length === 0) {
    grid.innerHTML = `<div class="empty-state" style="grid-column:1/-1">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
      <h4>No words found</h4><p>Try adjusting your search or add a new word.</p>
    </div>`;
    return;
  }
  grid.innerHTML = words.map(w => wordCard(w)).join('');
}

function renderTable(words, total) {
  const tbody = document.getElementById('words-table-body');
  const sa = document.getElementById('select-all-cb');
  if (sa) sa.checked = false;

  if (words.length === 0) {
    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--gray-400);padding:40px">No words found</td></tr>';
    return;
  }
  tbody.innerHTML = words.map(w => `
    <tr>
      <td><input type="checkbox" class="word-checkbox" value="${w.id}" ${selectedWords.has(w.id)?'checked':''} onchange="toggleSelectWord(${w.id}, this)"></td>
      <td>
        <strong style="font-size:15px;font-weight:600;cursor:pointer;color:var(--navy-900)" onclick="viewWord(${w.id})">${esc(w.term)}</strong>
        ${w.pronunciation ? `<span style="color:var(--gray-400);font-size:12px;font-style:italic;margin-left:8px">${esc(w.pronunciation)}</span>` : ''}
      </td>
      <td><span class="word-cat-badge">${esc(w.category_name)}</span></td>
      <td><span class="word-difficulty diff-${w.difficulty||'medium'}">${cap(w.difficulty||'medium')}</span></td>
      <td style="color:var(--gray-400);font-size:12px">${esc(w.date_tag)}</td>
      <td>${w.mastered ? '<span class="mastered-badge">Yes</span>' : '<span style="color:var(--gray-400);font-size:12px">No</span>'}</td>
      <td>
        <div style="display:flex;gap:4px;justify-content:flex-end">
          <button class="btn btn-icon btn-ghost" onclick="speakWord('${esc(w.term)}')" title="Listen"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"/></svg></button>
          <button class="btn btn-icon btn-ghost" onclick="viewWord(${w.id})" title="View"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
          <button class="btn btn-icon btn-ghost" onclick="editWord(${w.id})" title="Edit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></button>
          <button class="btn btn-icon btn-danger" onclick="deleteWordWithUndo(${w.id})" title="Delete"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg></button>
        </div>
      </td>
    </tr>
  `).join('');
}

function wordCard(w) {
  const starClass = w.starred ? 'starred' : '';
  return `<div class="word-card" id="wc-${w.id}">
    <div class="word-card-header">
      <div style="flex:1; min-width:0;">
        <div class="word-term" onclick="viewWord(${w.id})" style="cursor:pointer;font-weight:700">${esc(w.term)}</div>
        ${w.pronunciation ? `<div class="word-pos">${esc(w.pronunciation)}</div>` : ''}
        ${w.word_class ? `<div class="word-pos" style="margin-top:2px">${esc(w.word_class)}</div>` : ''}
      </div>
      <div class="word-card-actions">
        <button class="word-star-btn ${starClass}" onclick="toggleStar(${w.id})" id="star-${w.id}" title="${w.starred?'Unstar':'Star'}">
          <svg viewBox="0 0 24 24" fill="${w.starred?'currentColor':'none'}" stroke="currentColor" stroke-width="2" width="17" height="17"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
        </button>
        <button class="btn btn-icon btn-ghost" onclick="speakWord('${esc(w.term)}')" title="Listen">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>
        </button>
        <button class="btn btn-icon btn-ghost" onclick="editWord(${w.id})" title="Edit">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        </button>
        <button class="btn btn-icon btn-danger" onclick="deleteWordWithUndo(${w.id})" title="Delete">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
        </button>
      </div>
    </div>
    <div class="word-definition">${w.definition || '<em style="color:var(--gray-300)">No definition yet</em>'}</div>
    ${(w.tags && w.tags.length) ? `<div class="tags-row">${w.tags.map(t=>`<span class="tag-chip">${esc(t)}</span>`).join('')}</div>` : ''}
    <div class="word-card-footer">
      <span class="word-cat-badge">${esc(w.category_name)}</span>
      <span class="word-difficulty diff-${w.difficulty||'medium'}">${cap(w.difficulty||'medium')}</span>
      ${w.mastered ? '<span class="mastered-badge">Mastered</span>' : ''}
      <span class="word-date-badge">${esc(w.date_tag)}</span>
    </div>
  </div>`;
}

function renderPagination(total) {
  const pages = Math.ceil(total / perPage);
  const el = document.getElementById('words-pagination');
  if (pages <= 1) { el.innerHTML = ''; return; }
  let html = `<button class="page-btn" onclick="goPage(${currentPage-1})" ${currentPage<=1?'disabled':''}>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polyline points="15 18 9 12 15 6"/></svg>
  </button>`;
  const start = Math.max(1, currentPage - 2);
  const end = Math.min(pages, currentPage + 2);
  if (start > 1) html += `<button class="page-btn" onclick="goPage(1)">1</button>${start>2?'<span style="padding:0 4px;color:var(--gray-400)">...</span>':''}`;
  for (let i = start; i <= end; i++) {
    html += `<button class="page-btn ${i===currentPage?'active':''}" onclick="goPage(${i})">${i}</button>`;
  }
  if (end < pages) html += `${end<pages-1?'<span style="padding:0 4px;color:var(--gray-400)">...</span>':''}<button class="page-btn" onclick="goPage(${pages})">${pages}</button>`;
  html += `<button class="page-btn" onclick="goPage(${currentPage+1})" ${currentPage>=pages?'disabled':''}>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polyline points="9 18 15 12 9 6"/></svg>
  </button>`;
  el.innerHTML = html;
}

function goPage(p) {
  currentPage = p;
  renderWords(filteredWords);
  renderPagination(filteredWords.length);
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

// ---- WORD DRAFT SYSTEM ----
const DRAFT_KEY = 'lexivault_word_draft';

function getDraftFields() {
  return {
    term:          document.getElementById('word-term').value,
    pronunciation: document.getElementById('word-pronunciation').value,
    word_class:    document.getElementById('word-class').value === '__OTHER__' 
                     ? document.getElementById('word-class-manual').value.trim() 
                     : document.getElementById('word-class').value,
    category:      document.getElementById('word-category').value,
    difficulty:    document.getElementById('word-difficulty').value,
    source:        document.getElementById('word-source').value,
    tags:          document.getElementById('word-tags').value,
    notes:         document.getElementById('word-notes').value,
    definition:    quillDef ? quillDef.root.innerHTML : '',
  };
}

function isDraftEmpty(d) {
  if (!d) return true;
  const defText = (d.definition || '').replace(/<[^>]+>/g, '').trim();
  return !d.term && !defText && !d.pronunciation && !d.notes && !d.tags && !d.word_class;
}

function saveDraft() {
  // Only save drafts for NEW words (no id in the hidden field)
  if (document.getElementById('word-id').value) return;
  const d = getDraftFields();
  if (isDraftEmpty(d)) {
    localStorage.removeItem(DRAFT_KEY);
  } else {
    localStorage.setItem(DRAFT_KEY, JSON.stringify({ ...d, savedAt: Date.now() }));
  }
}

function clearDraft() {
  localStorage.removeItem(DRAFT_KEY);
}

function loadDraftIntoForm(d) {
  document.getElementById('word-term').value          = d.term || '';
  document.getElementById('word-pronunciation').value = d.pronunciation || '';
  populateWordClassDropdown(d.word_class || '');
  document.getElementById('word-category').value      = d.category || '0';
  document.getElementById('word-difficulty').value    = d.difficulty || 'medium';
  document.getElementById('word-source').value        = d.source || '';
  document.getElementById('word-tags').value          = d.tags || '';
  document.getElementById('word-notes').value         = d.notes || '';
  if (quillDef) quillDef.root.innerHTML               = d.definition || '';
}

function showDraftBanner() {
  const existing = document.getElementById('draft-banner');
  if (existing) existing.remove();
  const banner = document.createElement('div');
  banner.id = 'draft-banner';
  banner.style.cssText = `
    background: linear-gradient(135deg, #fff8e1, #fff3cd);
    border: 1px solid #f0c040;
    border-radius: 8px;
    padding: 10px 14px;
    margin-bottom: 14px;
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13px;
    color: #7a5a00;
    font-weight: 500;
  `;
  banner.innerHTML = `
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" style="flex-shrink:0;color:#c08a00"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
    <span style="flex:1">Unsaved draft restored</span>
    <button onclick="discardDraft()" style="background:none;border:none;cursor:pointer;color:#c08a00;font-size:12px;padding:2px 6px;border-radius:4px;font-weight:600;white-space:nowrap;">✕ Discard</button>
  `;
  const modalBody = document.querySelector('#word-modal-overlay .modal-body');
  modalBody.insertBefore(banner, modalBody.firstChild);
}

function discardDraft() {
  clearDraft();
  // Reset form to blank new-word state
  document.getElementById('word-term').value          = '';
  document.getElementById('word-pronunciation').value = '';
  populateWordClassDropdown('');
  document.getElementById('word-category').value      = '0';
  document.getElementById('word-difficulty').value    = 'medium';
  document.getElementById('word-source').value        = '';
  document.getElementById('word-tags').value          = '';
  document.getElementById('word-notes').value         = '';
  if (quillDef) quillDef.root.innerHTML               = '';
  const banner = document.getElementById('draft-banner');
  if (banner) banner.remove();
  document.getElementById('word-term').focus();
}

// Auto-save draft every 2s while modal is open (new word only)
let draftInterval = null;
function startDraftAutosave() {
  stopDraftAutosave();
  draftInterval = setInterval(() => {
    if (document.getElementById('word-modal-overlay').classList.contains('active')) {
      saveDraft();
    }
  }, 2000);
}
function stopDraftAutosave() {
  if (draftInterval) { clearInterval(draftInterval); draftInterval = null; }
}

// ---- WORD MODAL ----
function openAddWord() {
  document.getElementById('word-modal-title').textContent = 'Add New Word';
  document.getElementById('word-id').value = '';

  // On non-words pages the category dropdown may not be populated yet — fill it now
  const catSel = document.getElementById('word-category');
  if (catSel && catSel.options.length <= 1) {
    apiGet('categories_list').then(r => {
      if (!r.success) return;
      r.categories.forEach(c => {
        catSel.innerHTML += `<option value="${c.id}">${esc(c.name)}</option>`;
      });
    });
  }

  // Check for existing draft
  let draft = null;
  try { draft = JSON.parse(localStorage.getItem(DRAFT_KEY)); } catch(e) {}

  if (!isDraftEmpty(draft)) {
    // Restore draft first, then show banner
    loadDraftIntoForm(draft);
    document.getElementById('word-modal-overlay').classList.add('active');
    setTimeout(() => {
      showDraftBanner();
      document.getElementById('word-term').focus();
    }, 50);
  } else {
    // Fresh blank form
    document.getElementById('word-term').value          = '';
    document.getElementById('word-pronunciation').value = '';
    populateWordClassDropdown('');
    document.getElementById('word-category').value      = '0';
    document.getElementById('word-difficulty').value    = 'medium';
    document.getElementById('word-source').value        = '';
    document.getElementById('word-tags').value          = '';
    document.getElementById('word-notes').value         = '';
    if (quillDef) quillDef.root.innerHTML               = '';
    document.getElementById('word-modal-overlay').classList.add('active');
    setTimeout(() => document.getElementById('word-term').focus(), 200);
  }

  startDraftAutosave();
}

function closeWordModal(skipDraftSave) {
  // Save draft before closing only if it's a new word with content AND we're not explicitly saving
  if (!skipDraftSave && !document.getElementById('word-id').value) {
    saveDraft();
  }
  stopDraftAutosave();
  const banner = document.getElementById('draft-banner');
  if (banner) banner.remove();
  document.getElementById('word-modal-overlay').classList.remove('active');
}

async function autoFetchDefinition() {
  const term = document.getElementById('word-term').value.trim();
  if (!term) { toast('Enter a term to fetch', 'error'); return; }
  const btn = document.querySelector('button[onclick="autoFetchDefinition()"]');
  btn.disabled = true; btn.innerHTML = '<div class="spinner" style="width:14px;height:14px;border-width:2px;display:inline-block"></div> Fetching...';
  try {
    const res = await fetch(`https://api.dictionaryapi.dev/api/v2/entries/en/${encodeURIComponent(term)}`);
    if (!res.ok) throw new Error('Not found');
    const data = await res.json();
    const entry = data[0];
    let phonetic = entry.phonetic || '';
    if (!phonetic && entry.phonetics) {
        const ph = entry.phonetics.find(p => p.text);
        if (ph) phonetic = ph.text;
    }
    if (phonetic) document.getElementById('word-pronunciation').value = phonetic;
    if (entry.meanings && entry.meanings.length > 0) {
      const meaning = entry.meanings[0];
      if (meaning.partOfSpeech) {
        let apiPos = meaning.partOfSpeech;
        apiPos = apiPos.charAt(0).toUpperCase() + apiPos.slice(1);
        populateWordClassDropdown(apiPos);
      }
      if (meaning.definitions && meaning.definitions.length > 0) {
        const def = meaning.definitions[0];
        if (quillDef) quillDef.root.innerHTML = `<p>${def.definition}</p>`;
      }
    }
    document.getElementById('word-source').value = 'Free Dictionary API';
    toast('Definition auto-filled', 'success');
  } catch (e) {
    toast('Word not found in dictionary', 'error');
  }
  btn.disabled = false; btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><polyline points="8 17 12 21 16 17"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.88 18.09A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.29"/></svg> Fetch';
}

async function editWord(id) {
  const r = await apiGet('word_get', { id });
  if (!r.success) { toast('Could not load word', 'error'); return; }
  const w = r.word;
  document.getElementById('word-modal-title').textContent = 'Edit Word';
  document.getElementById('word-id').value = w.id;
  document.getElementById('word-term').value = w.term;
  document.getElementById('word-pronunciation').value = w.pronunciation || '';
  populateWordClassDropdown(w.word_class || '');
  document.getElementById('word-category').value = w.category_id || '0';
  document.getElementById('word-difficulty').value = w.difficulty || 'medium';
  document.getElementById('word-source').value = w.source || '';
  document.getElementById('word-tags').value = (w.tags||[]).join(', ');
  document.getElementById('word-notes').value = w.notes || '';
  if (quillDef) quillDef.root.innerHTML = w.definition || '';
  // Editing an existing word — no draft involvement, no autosave
  stopDraftAutosave();
  const banner = document.getElementById('draft-banner');
  if (banner) banner.remove();
  document.getElementById('word-modal-overlay').classList.add('active');
}

async function saveWord() {
  const term = document.getElementById('word-term').value.trim();
  if (!term) { toast('Please enter a term', 'error'); return; }

  let wordClass = document.getElementById('word-class').value;
  if (wordClass === '__OTHER__') wordClass = document.getElementById('word-class-manual').value.trim();

  const fd = new FormData();
  fd.append('id', document.getElementById('word-id').value);
  fd.append('term', term);
  fd.append('pronunciation', document.getElementById('word-pronunciation').value);
  fd.append('word_class', wordClass);
  fd.append('category_id', document.getElementById('word-category').value);
  fd.append('definition', quillDef ? quillDef.root.innerHTML : '');
  fd.append('difficulty', document.getElementById('word-difficulty').value);
  fd.append('source', document.getElementById('word-source').value);
  fd.append('tags', document.getElementById('word-tags').value);
  fd.append('notes', document.getElementById('word-notes').value);

  const r = await fetch('?api=word_save', {
    method: 'POST',
    body: fd
  }).then(res => res.json()).catch(e => ({success: false, message: 'Network error'}));

  if (r.success) {
    clearDraft();
    stopDraftAutosave();
    toast(fd.get('id') ? 'Word updated' : 'Word added', 'success');
    closeWordModal(true); // pass true so closeWordModal does NOT re-save the draft
    isDataLoaded = false;
    if (typeof loadWords === 'function') loadWords();
    if (typeof loadWordCount === 'function') loadWordCount();
  } else {
    toast(r.message || 'Save failed', 'error');
  }
}

// ---- VIEW WORD ----
async function viewWord(id) {
  const r = await apiGet('word_get', { id });
  if (!r.success) return;
  const w = r.word;
  await apiPost('word_increment_review', { id });
  
  const rawDef = (w.definition || '').replace(/<[^>]+>/g, '');
  let copyStr = w.term + (w.pronunciation ? ` (${w.pronunciation})` : '');
  if (w.word_class) copyStr += `\nType: ${w.word_class}`;
  if (rawDef) copyStr += `\nDefinition: ${rawDef}`;
  const copyData = btoa(unescape(encodeURIComponent(copyStr)));
  
  let shareBtnHTML = '';
  if (navigator.share) {
    shareBtnHTML = `<button class="btn btn-ghost mobile-icon-btn" onclick="shareWord('${copyData}')" title="Share">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg> <span class="hide-mobile">Share</span>
    </button>`;
  }

  const content = `
    <div class="word-detail-header">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px">
        <div>
          <div class="term" style="font-weight:700">${esc(w.term)}</div>
          ${w.pronunciation ? `<div class="pronunciation">${esc(w.pronunciation)}</div>` : ''}
        </div>
        <div style="display:flex;gap:8px;flex-shrink:0">
          ${w.mastered ? '<span class="mastered-badge" style="background:rgba(26,124,92,0.3);color:#7eecc5">Mastered</span>' : ''}
          ${w.starred ? `<svg viewBox="0 0 24 24" fill="gold" stroke="gold" stroke-width="1" width="22" height="22"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>` : ''}
        </div>
      </div>
      <div style="display:flex;gap:10px;margin-top:14px;flex-wrap:wrap">
        ${w.word_class ? `<span style="background:rgba(255,255,255,0.15);color:white;padding:4px 14px;border-radius:20px;font-size:13px;font-weight:500;font-style:italic">${esc(w.word_class)}</span>` : ''}
        <span style="background:rgba(255,255,255,0.15);color:white;padding:4px 14px;border-radius:20px;font-size:13px;font-weight:500">${esc(w.category_name)}</span>
        <span style="background:rgba(255,255,255,0.15);color:white;padding:4px 14px;border-radius:20px;font-size:13px;font-weight:500">${cap(w.difficulty||'medium')}</span>
      </div>
    </div>
    <div class="word-detail-body">
      ${w.definition ? `<div class="detail-section"><div class="detail-section-title">Definition</div><div class="detail-definition">${w.definition}</div></div>` : ''}
      ${w.notes ? `<div class="detail-section"><div class="detail-section-title">Notes</div><div class="detail-definition">${esc(w.notes)}</div></div>` : ''}
      <div style="display:flex;gap:20px;flex-wrap:wrap;font-size:13px;color:var(--gray-500)">
        ${w.source ? `<span><strong>Source:</strong> ${esc(w.source)}</span>` : ''}
        <span><strong>Added:</strong> ${esc(w.date_tag)}</span>
        <span><strong>Reviews:</strong> ${w.review_count||0}</span>
        ${w.last_reviewed ? `<span><strong>Last reviewed:</strong> ${esc(w.last_reviewed.slice(0,10))}</span>` : ''}
      </div>
      ${(w.tags&&w.tags.length) ? `<div class="tags-row" style="margin-top:14px">${w.tags.map(t=>`<span class="tag-chip">${esc(t)}</span>`).join('')}</div>` : ''}
    </div>`;
  document.getElementById('word-detail-content').innerHTML = content;
  document.getElementById('word-detail-footer').innerHTML = `
    <button class="btn btn-ghost mobile-icon-btn" onclick="speakWord('${esc(w.term).replace(/'/g, "\\'")}')" title="Listen">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>
      <span class="hide-mobile">Listen</span>
    </button>
    <button class="btn btn-ghost mobile-icon-btn" onclick="copyEncodedText('${copyData}')" title="Copy">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
      <span class="hide-mobile">Copy</span>
    </button>
    ${shareBtnHTML}
    <div style="flex:1" class="hide-mobile"></div>
    <button class="btn btn-ghost mobile-icon-btn" onclick="toggleStar(${w.id});closeDetailModal()" title="${w.starred ? 'Unstar' : 'Star'}">
      <svg viewBox="0 0 24 24" fill="${w.starred?'var(--gold)':'none'}" stroke="${w.starred?'var(--gold)':'currentColor'}" stroke-width="2" width="15" height="15"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
      <span class="hide-mobile">${w.starred ? 'Unstar' : 'Star'}</span>
    </button>
    <button class="btn ${w.mastered ? 'btn-danger' : 'btn-success'} btn-sm mobile-icon-btn" onclick="toggleMastered(${w.id});closeDetailModal()" title="${w.mastered ? 'Unmark Mastered' : 'Mark Mastered'}">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15">${w.mastered ? '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>' : '<polyline points="20 6 9 17 4 12"/>'}</svg>
      <span class="hide-mobile">${w.mastered ? 'Unmark' : 'Mastered'}</span>
    </button>
    <button class="btn btn-secondary mobile-icon-btn" onclick="closeDetailModal();editWord(${w.id})" title="Edit">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
      <span class="hide-mobile">Edit</span>
    </button>
    <button class="btn btn-secondary" onclick="closeDetailModal()">Close</button>`;
  document.getElementById('word-detail-overlay').classList.add('active');
}
function closeDetailModal() {
  document.getElementById('word-detail-overlay').classList.remove('active');
}

// ---- STAR / MASTERED ----
async function toggleStar(id) {
  const r = await apiPost('word_toggle_star', { id });
  if (r.success) {
    const btn = document.getElementById(`star-${id}`);
    if (btn) {
      btn.classList.toggle('starred', r.starred);
      btn.querySelector('svg').setAttribute('fill', r.starred ? 'currentColor' : 'none');
      btn.title = r.starred ? 'Unstar' : 'Star';
    }
    toast(r.starred ? 'Starred' : 'Unstarred', 'info');
    isDataLoaded = false;
    loadWords();
  }
}
async function toggleMastered(id) {
  const r = await apiPost('word_toggle_mastered', { id });
  if (r.success) {
    toast(r.mastered ? 'Marked as mastered' : 'Marked as learning', 'success');
    isDataLoaded = false;
    loadWords();
  }
}

// ---- DELETE ----
function deleteWordWithUndo(id) {
  const wIndex = allWordsCache.findIndex(w => w.id === id);
  if (wIndex === -1) return;
  const word = allWordsCache[wIndex];
  
  allWordsCache.splice(wIndex, 1);
  loadWords();
  
  const tid = Date.now();
  let undone = false;
  toastUndo(`Word deleted`, () => {
    undone = true;
    clearTimeout(window[`delTimeout_${tid}`]);
    allWordsCache.splice(wIndex, 0, word);
    loadWords();
  });

  window[`delTimeout_${tid}`] = setTimeout(async () => {
    if (!undone) {
      const r = await apiPost('word_delete', { id });
      if (r.success) {
         loadWordCount();
      } else {
         isDataLoaded = false;
         loadWords();
      }
    }
    delete window[`delTimeout_${tid}`];
  }, 5000);
}

// ---- BULK ACTIONS ----
function toggleSelectAll(cb) {
  const checkboxes = document.querySelectorAll('.word-checkbox');
  checkboxes.forEach(c => {
    c.checked = cb.checked;
    if(cb.checked) selectedWords.add(parseInt(c.value));
    else selectedWords.delete(parseInt(c.value));
  });
  updateBulkBar();
}
function toggleSelectWord(id, cb) {
  if(cb.checked) selectedWords.add(id); else selectedWords.delete(id);
  updateBulkBar();
}
function updateBulkBar() {
  const bar = document.getElementById('bulk-action-bar');
  if(selectedWords.size > 0) {
    bar.classList.add('active');
    document.getElementById('bulk-count').textContent = selectedWords.size + ' selected';
  } else { bar.classList.remove('active'); }
}
function clearSelection() {
  selectedWords.clear();
  document.querySelectorAll('.word-checkbox').forEach(c => c.checked = false);
  if(document.getElementById('select-all-cb')) document.getElementById('select-all-cb').checked = false;
  updateBulkBar();
}
async function bulkAction(action) {
  if(selectedWords.size === 0) return;
  const ids = Array.from(selectedWords);
  if(action === 'delete') { if(!confirm(`Delete ${ids.length} words permanently?`)) return; await doBulkApi(action, ids); }
  else if (action === 'categorize') { document.getElementById('bulk-cat-overlay').classList.add('active'); }
  else { await doBulkApi(action, ids); }
}
function confirmBulkCategorize() {
  const cid = document.getElementById('bulk-category-select').value;
  document.getElementById('bulk-cat-overlay').classList.remove('active');
  doBulkApi('categorize', Array.from(selectedWords), { category_id: cid });
}
async function doBulkApi(action, ids, extra={}) {
  const r = await apiPost('words_bulk_action', { bulk_action: action, ids, ...extra });
  if (r.success) { toast(`Bulk action applied to ${r.count} words`, 'success'); clearSelection(); isDataLoaded = false; loadWords(); loadWordCount(); }
  else { toast(r.message || 'Action failed', 'error'); }
}

// Keyboard shortcuts
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    closeWordModal();
    closeDetailModal();
  }
  if ((e.ctrlKey || e.metaKey) && e.key === 'n') { e.preventDefault(); openAddWord(); }
});
<?php endif; ?>

// ============================================================
// CATEGORIES PAGE
// ============================================================
<?php if ($page === 'categories'): ?>
let allCategories = [];
document.addEventListener('DOMContentLoaded', loadCats);
async function loadCats() {
  const r = await apiGet('categories_list');
  if (!r.success) return;
  allCategories = r.categories;
  renderCats(allCategories);
}
function filterCats() {
  const q = document.getElementById('cat-search').value.toLowerCase();
  const filtered = allCategories.filter(c => c.name.toLowerCase().includes(q) || (c.description||'').toLowerCase().includes(q));
  renderCats(filtered);
}
function renderCats(cats) {
  const el = document.getElementById('cats-grid');
  if (cats.length === 0) {
    el.innerHTML = '<div class="empty-state"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg><h4>No categories</h4><p>Create categories to organize your vocabulary.</p></div>';
    return;
  }
  el.innerHTML = cats.map(c => `
    <div class="cat-card" onclick="goToCategoryWords(${c.id})" title="View words in ${esc(c.name)}" style="cursor:pointer;">
      <div class="cat-color-dot" style="background:${c.color}">${esc(c.name.slice(0,1).toUpperCase())}</div>
      <div class="cat-info">
        <div class="cat-name">${esc(c.name)}</div>
        <div class="cat-desc">${esc(c.description||'')}</div>
        <div class="cat-count">${c.word_count} word${c.word_count!==1?'s':''}</div>
      </div>
      <div class="cat-actions" onclick="event.stopPropagation()">
        <button class="btn btn-icon btn-ghost" onclick="editCat(${c.id},'${esc(c.name)}','${esc(c.description||'')}','${c.color}')" title="Edit">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        </button>
        <button class="btn btn-icon btn-danger" onclick="deleteCat(${c.id},'${esc(c.name)}')" title="Delete">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
        </button>
      </div>
    </div>
  `).join('');
}
function goToCategoryWords(catId) {
  location.href = '?page=words&cat=' + catId;
}
function openCatModal() {
  document.getElementById('cat-modal-title').textContent = 'New Category';
  document.getElementById('cat-id').value = '';
  document.getElementById('cat-name').value = '';
  document.getElementById('cat-desc').value = '';
  document.getElementById('cat-color').value = '#1e3a5f';
  document.getElementById('cat-modal-overlay').classList.add('active');
  setTimeout(() => document.getElementById('cat-name').focus(), 200);
}
function editCat(id, name, desc, color) {
  document.getElementById('cat-modal-title').textContent = 'Edit Category';
  document.getElementById('cat-id').value = id;
  document.getElementById('cat-name').value = name;
  document.getElementById('cat-desc').value = desc;
  document.getElementById('cat-color').value = color;
  document.getElementById('cat-modal-overlay').classList.add('active');
}
function closeCatModal() {
  document.getElementById('cat-modal-overlay').classList.remove('active');
}
async function saveCat() {
  const name = document.getElementById('cat-name').value.trim();
  if (!name) { toast('Category name required', 'error'); return; }
  const data = {
    id: document.getElementById('cat-id').value,
    name,
    description: document.getElementById('cat-desc').value,
    color: document.getElementById('cat-color').value,
  };
  const r = await apiPost('category_save', data);
  if (r.success) {
    toast(data.id ? 'Category updated' : 'Category created', 'success');
    closeCatModal();
    loadCats();
  } else {
    toast(r.message || 'Save failed', 'error');
  }
}
async function deleteCat(id, name) {
  if (!confirm(`Delete category "${name}"? Words in it will become uncategorized.`)) return;
  const r = await apiPost('category_delete', { id });
  if (r.success) { toast('Category deleted', 'success'); loadCats(); }
  else toast('Delete failed', 'error');
}
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') closeCatModal();
});
<?php endif; ?>

// ============================================================
// REVIEW CARDS
// ============================================================
<?php if ($page === 'review'): ?>
document.addEventListener('DOMContentLoaded', async () => {
  const r = await apiGet('categories_list');
  if (r.success) {
    const sel = document.getElementById('review-cat');
    r.categories.forEach(c => { sel.innerHTML += `<option value="${c.id}">${esc(c.name)}</option>`; });
  }
  loadReviewCard();
});

async function loadReviewCard() {
  const cat = document.getElementById('review-cat').value;
  const filt = document.getElementById('review-filter').value;
  const params = { category: cat };
  const r = await apiGet('words_list', params);
  if (!r.success) return;
  let words = r.words;
  if (filt === 'notmastered') words = words.filter(w => !w.mastered);
  else if (filt === 'starred') words = words.filter(w => w.starred);
  else if (filt === 'mastered') words = words.filter(w => w.mastered);
  reviewWords = words;
  reviewIndex = 0;
  showReviewCard();
}

function showReviewCard() {
  const card = document.getElementById('review-card');
  card.classList.remove('flipped');
  const prog = document.getElementById('review-progress');
  if (reviewWords.length === 0) {
    prog.textContent = 'No words to review';
    document.getElementById('review-term').textContent = 'No words found';
    document.getElementById('review-def').textContent = '';
    return;
  }
  document.getElementById('review-progress-bar').style.width = ((reviewIndex + 1) / reviewWords.length * 100) + '%';
  prog.textContent = `Card ${reviewIndex + 1} of ${reviewWords.length}`;
  const w = reviewWords[reviewIndex];
  document.getElementById('review-term').textContent = w.term;
  document.getElementById('review-def').innerHTML = w.definition || 'No definition';

  const masteredBtn = document.getElementById('review-mastered-btn');

  if (w.mastered) {
    masteredBtn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Unmark Mastered`;
    masteredBtn.classList.remove('btn-success');
    masteredBtn.classList.add('btn-danger');
  } else {
    masteredBtn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><polyline points="20 6 9 17 4 12"/></svg> Mark as Mastered`;
    masteredBtn.classList.remove('btn-danger');
    masteredBtn.classList.add('btn-success');
  }

  if (document.getElementById('review-autoplay').checked) speakWord(w.term);
}

function flipReviewCard() {
  document.getElementById('review-card').classList.toggle('flipped');
  if (reviewWords[reviewIndex]) apiPost('word_increment_review', { id: reviewWords[reviewIndex].id });
}
function nextReviewCard() {
  if (reviewWords.length === 0) return;
  reviewIndex = (reviewIndex + 1) % reviewWords.length;
  showReviewCard();
}
function prevReviewCard() {
  if (reviewWords.length === 0) return;
  reviewIndex = (reviewIndex - 1 + reviewWords.length) % reviewWords.length;
  showReviewCard();
}
function shuffleReview() {
  reviewWords.sort(() => Math.random() - 0.5);
  reviewIndex = 0;
  showReviewCard();
  toast('Cards shuffled', 'info');
}
async function markReviewMastered(e) {
  e.stopPropagation();
  if (!reviewWords[reviewIndex]) return;
  const r = await apiPost('word_toggle_mastered', { id: reviewWords[reviewIndex].id });
  if (r.success) {
    reviewWords[reviewIndex].mastered = r.mastered;
    toast(r.mastered ? 'Word mastered!' : 'Mastery removed.', 'success');
    showReviewCard();
  }
}
document.addEventListener('keydown', e => {
  if (e.key === 'ArrowRight') nextReviewCard();
  else if (e.key === 'ArrowLeft') prevReviewCard();
  else if (e.key === ' ') { e.preventDefault(); flipReviewCard(); }
});
<?php endif; ?>

// ============================================================
// SETTINGS
// ============================================================
<?php if ($page === 'settings'): ?>
document.addEventListener('DOMContentLoaded', loadSettings);
async function loadSettings() {
  const r = await apiGet('settings_get');
  if (!r.success) return;
  const s = r.settings;
  const set = (id, val) => { const el = document.getElementById(id); if (el) { if (el.type === 'checkbox') el.checked = !!val; else el.value = val || ''; } };
  set('set-per-page', s.words_per_page);
  set('set-timezone', s.timezone);
  set('set-smtp-host', s.smtp_host);
  set('set-smtp-port', s.smtp_port);
  set('set-smtp-user', s.smtp_user);
  set('set-smtp-pass', s.smtp_pass);
  set('set-smtp-from', s.smtp_from);
  set('set-digest-email', s.digest_email);

}
async function saveSettings() {
  const get = id => { const el = document.getElementById(id); return el ? (el.type==='checkbox' ? el.checked : el.value) : ''; };
  const data = {
    timezone: get('set-timezone'),
    smtp_host: get('set-smtp-host'),
    smtp_port: get('set-smtp-port'),
    smtp_user: get('set-smtp-user'),
    smtp_pass: get('set-smtp-pass'),
    smtp_from: get('set-smtp-from'),
    digest_email: get('set-digest-email'),
    words_per_page: get('set-per-page'),
  };
  const r = await apiPost('settings_save', data);
  if (r.success) toast('Settings saved', 'success');
  else toast('Save failed', 'error');
}
async function saveProfile() {
  const data = {
    name: document.getElementById('prof-name').value,
    email: document.getElementById('prof-email').value,
    current_password: document.getElementById('prof-curpass').value,
    new_password: document.getElementById('prof-newpass').value,
  };
  const r = await apiPost('profile_update', data);
  if (r.success) {
    toast('Profile updated', 'success');
    document.getElementById('prof-curpass').value = '';
    document.getElementById('prof-newpass').value = '';
  } else {
    toast(r.message || 'Update failed', 'error');
  }
}
async function sendTestDigest() {
  toast('Sending test digest...', 'info');
  const r = await apiPost('send_test_digest');
  if (r.success) toast('Test digest sent', 'success');
  else toast('Failed: ' + (r.message || 'Check SMTP settings'), 'error');
}
<?php endif; ?>

// ============================================================
// IMPORT / EXPORT
// ============================================================
<?php if ($page === 'import_export'): ?>
function previewImport() {
  const file = document.getElementById('import-file').files[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = e => {
    try {
      importData = JSON.parse(e.target.result);
      if (!Array.isArray(importData)) throw new Error('Not an array');
      document.getElementById('import-count').textContent = importData.length;
      document.getElementById('import-preview').style.display = 'block';
      document.getElementById('import-btn').disabled = false;
    } catch {
      toast('Invalid JSON file', 'error');
      importData = null;
      document.getElementById('import-btn').disabled = true;
      document.getElementById('import-preview').style.display = 'none';
    }
  };
  reader.readAsText(file);
}
async function doImport() {
  if (!importData) return;
  const r = await apiPost('import', { data: JSON.stringify(importData) });
  if (r.success) {
    toast(`Imported ${r.imported} words`, 'success');
    document.getElementById('import-file').value = '';
    document.getElementById('import-preview').style.display = 'none';
    document.getElementById('import-btn').disabled = true;
    importData = null;
  } else {
    toast(r.message || 'Import failed', 'error');
  }
}

function doSqlImport() {
  const file = document.getElementById('import-sql-file').files[0];
  if (!file) return;
  if (!confirm('WARNING: This will overwrite your entire current database with the contents of the SQL file. This action cannot be undone. Are you sure you want to continue?')) return;

  const reader = new FileReader();
  reader.onload = async e => {
    const sqlData = e.target.result;
    const r = await apiPost('import_sql', { data: sqlData });
    if (r.success) toast('Database imported successfully!', 'success');
    else toast(r.message || 'SQL import failed', 'error');
  };
  reader.readAsText(file);
}
<?php endif; ?>

// ============================================================
// UTILITY
// ============================================================
function esc(str) {
  if (str == null) return '';
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
function cap(str) { return str ? str.charAt(0).toUpperCase() + str.slice(1) : ''; }

function printVocabulary() {
    const wordsToPrint = filteredWords;
    if (wordsToPrint.length === 0) { toast('No words to print.', 'info'); return; }

    const printWindow = window.open('', '_blank');
    printWindow.document.write('<html><head><title>LexiVault Vocabulary List</title>');
    printWindow.document.write(`<style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; line-height: 1.5; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; vertical-align: top; }
        th { background: #f2f2f2; font-weight: 600; }
        h1 { font-size: 24px; }
        .definition p { margin: 0; }
        @media print { .no-print { display: none; } }
    </style>`);
    printWindow.document.write('</head><body>');
    printWindow.document.write(`<h1>LexiVault Vocabulary List (${wordsToPrint.length} words)</h1>`);
    printWindow.document.write('<table><thead><tr><th style="width:20%">Term</th><th>Definition</th><th style="width:20%">Category</th></tr></thead><tbody>');
    wordsToPrint.forEach(w => {
        printWindow.document.write(`<tr><td><strong>${esc(w.term)}</strong></td><td class="definition">${w.definition || ''}</td><td>${esc(w.category_name)}</td></tr>`);
    });
    printWindow.document.write('</tbody></table></body></html>');
    printWindow.document.close();
    printWindow.focus();
    setTimeout(() => printWindow.print(), 500);
}

function speakWord(text) {
  if ('speechSynthesis' in window) {
    speechSynthesis.speak(new SpeechSynthesisUtterance(text));
  } else toast("Text-to-speech not supported in your browser.", "error");
}

function copyEncodedText(b64) {
  try {
    const str = decodeURIComponent(escape(atob(b64)));
    navigator.clipboard.writeText(str).then(() => toast('Copied to clipboard', 'success'));
  } catch(e) {
    toast('Could not copy', 'error');
  }
}

function shareWord(b64) {
  try {
    const str = decodeURIComponent(escape(atob(b64)));
    navigator.share({ title: 'LexiVault Word', text: str }).catch(e => console.log(e));
  } catch(e) {
    toast('Could not share', 'error');
  }
}

// Close modals on overlay click
document.querySelectorAll('.modal-overlay').forEach(o => {
  o.addEventListener('click', e => {
    if (e.target === o) {
      if (o.id === 'word-modal-overlay') {
        closeWordModal(false); // saves draft before closing
      } else {
        o.classList.remove('active');
      }
    }
  });
});

document.addEventListener('click', e => {
  const list = document.getElementById('tag-suggestions');
  if (list && e.target.id !== 'word-tags') list.style.display = 'none';
});

(function(){
  const f = document.createElement('div');
  f.innerHTML = 'Developed with <span class="pulse-heart-wrap"><svg class="pulse-heart" viewBox="0 0 24 24" width="14" height="14" fill="#e0455f"><path d="M12 20.3 2.7 11.2C0.4 8.9 0.4 5.3 2.7 3.1c2.2-2.1 5.7-2.1 7.8 0.2L12 5l1.5-1.7c2.1-2.3 5.6-2.3 7.8-0.2 2.3 2.2 2.3 5.8 0 8.1L12 20.3z"/></svg></span> by Vineet';
  f.style.cssText = "text-align:center; padding:20px; color:var(--gray-500); font-size:14px; font-weight:500; font-family:inherit;";
  if(document.querySelector('.main-content')) document.querySelector('.main-content').appendChild(f);
})();
</script>

<?php endif; // isLoggedIn ?>

<script>
(function(){
  var pre = document.getElementById('page-preloader');
  if (!pre) return;
  function show(){ pre.classList.remove('hidden'); }
  function hide(){ pre.classList.add('hidden'); }
  // hide once the page has actually finished loading
  if (document.readyState === 'complete') {
    setTimeout(hide, 60);
  } else {
    window.addEventListener('load', function(){ setTimeout(hide, 60); });
  }
  // show again right before leaving for another page
  window.addEventListener('beforeunload', show);
  document.addEventListener('click', function(e){
    var a = e.target.closest('a[href]');
    if (a && a.target !== '_blank' && a.href && a.href.indexOf('javascript:') !== 0) show();
  });
  document.addEventListener('submit', function(e){
    if (e.target && e.target.tagName === 'FORM') show();
  });
  window.addEventListener('pageshow', function(e){
    if (e.persisted) hide();
  });
})();
</script>
</body>
</html>
