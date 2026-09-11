<?php
declare(strict_types=1);
require_once __DIR__ . '/blog-storage.php';
require_once __DIR__ . '/smtp-mailer.php';

function support_pdo(): PDO
{
    $pdo = blogs_pdo();
    static $ready = false;
    if (!$ready) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS contact_submissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL DEFAULT '', email TEXT NOT NULL,
            subject TEXT NOT NULL, message TEXT NOT NULL, source_page TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'new' CHECK(status IN ('new','read','resolved')),
            created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS contacts_created ON contact_submissions(created_at DESC, id DESC);
        CREATE TABLE IF NOT EXISTS live_chat_sessions (
            id TEXT PRIMARY KEY, token_hash TEXT NOT NULL, customer_name TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open','closed')),
            created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS chats_activity ON live_chat_sessions(status, updated_at DESC);
        CREATE TABLE IF NOT EXISTS live_chat_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT, chat_id TEXT NOT NULL REFERENCES live_chat_sessions(id) ON DELETE CASCADE,
            sender TEXT NOT NULL CHECK(sender IN ('customer','admin')), message TEXT NOT NULL,
            created_at INTEGER NOT NULL, read_at INTEGER
        );
        CREATE INDEX IF NOT EXISTS chat_messages_cursor ON live_chat_messages(chat_id, id);
        CREATE INDEX IF NOT EXISTS chat_messages_unread ON live_chat_messages(chat_id, sender, read_at);");
        $ready = true;
    }
    return $pdo;
}

function support_text(array $input, string $key, int $max, bool $required = true): string
{
    $value = $input[$key] ?? '';
    if (!is_string($value) || !preg_match('//u', $value) || strlen($value) > $max) {
        throw new InvalidArgumentException('Invalid ' . $key . '.');
    }
    $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '');
    if ($required && $value === '') throw new InvalidArgumentException('Please complete ' . $key . '.');
    return $value;
}

function support_contact_save(array $input): int
{
    $name = support_text($input, 'name', 120, false);
    $email = support_text($input, 'email', 254);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Please enter a valid email.');
    $subject = support_text($input, 'subject', 200);
    $message = support_text($input, 'message', 10000);
    $source = support_text($input, 'source_page', 1000, false);
    $pdo = support_pdo();
    $stmt = $pdo->prepare('INSERT INTO contact_submissions (name,email,subject,message,source_page,created_at,updated_at) VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([$name,$email,$subject,$message,$source,time(),time()]);
    return (int) $pdo->lastInsertId();
}

/** Notifications are optional and always run after the submission is committed. */
function support_contact_notify(int $id): bool
{
    $recipient = env_value('CONTACT_RECIPIENT_EMAIL') ?? env_value('TICKET_RECIPIENT_EMAIL');
    if (!smtp_is_configured() || !$recipient) return false;
    try {
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Invalid recipient configuration');
        $stmt = support_pdo()->prepare('SELECT name,email,subject,message FROM contact_submissions WHERE id=?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) return false;
        $from = env_value('SMTP_FROM_EMAIL') ?? env_value('TICKET_FROM_EMAIL') ?? '';
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Invalid sender configuration');
        $body = "Name: {$row['name']}\nEmail: {$row['email']}\nSubject: {$row['subject']}\n\n{$row['message']}";
        $result = smtp_send_mail($recipient, 'New contact submission #' . $id, $body, $from, 'Website Contact');
        if (!($result['ok'] ?? false)) throw new RuntimeException('Delivery failed');
        return true;
    } catch (Throwable $error) {
        // Do not log SMTP responses, credentials, or message contents.
        error_log('Contact notification failed for submission ' . $id . '.');
        return false;
    }
}

function support_contact_status(int $id, string $status): void
{
    if (!in_array($status, ['new','read','resolved'], true)) throw new InvalidArgumentException('Invalid status.');
    $stmt = support_pdo()->prepare('UPDATE contact_submissions SET status=?,updated_at=? WHERE id=?');
    $stmt->execute([$status,time(),$id]);
}

function support_chat_start(string $name): array
{
    $id = bin2hex(random_bytes(16));
    $token = bin2hex(random_bytes(32));
    $stmt = support_pdo()->prepare('INSERT INTO live_chat_sessions (id,token_hash,customer_name,created_at,updated_at) VALUES (?,?,?,?,?)');
    $stmt->execute([$id,hash('sha256',$token),$name,time(),time()]);
    return ['chat_id'=>$id,'token'=>$token,'status'=>'open'];
}

function support_chat_id(mixed $id): string
{
    if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) throw new InvalidArgumentException('Invalid chat ID.');
    return $id;
}

function support_chat(string $id): ?array
{
    $stmt = support_pdo()->prepare('SELECT * FROM live_chat_sessions WHERE id=?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function support_chat_authorized(string $id, string $token): bool
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) return false;
    $chat = support_chat($id);
    return $chat !== null && hash_equals($chat['token_hash'],hash('sha256',$token));
}

function support_chat_send(string $id, string $sender, string $message): int
{
    if (!in_array($sender, ['customer','admin'], true)) throw new InvalidArgumentException('Invalid sender.');
    $message = support_text(['message'=>$message], 'message', 4000);
    $pdo = support_pdo();
    // Serialize status checks with close/reopen and concurrent messages.
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $chat = support_chat($id);
        if (!$chat || $chat['status'] !== 'open') throw new DomainException('Chat is closed or unavailable.');
        $stmt = $pdo->prepare('INSERT INTO live_chat_messages (chat_id,sender,message,created_at) VALUES (?,?,?,?)');
        $stmt->execute([$id,$sender,$message,time()]);
        $messageId = (int) $pdo->lastInsertId();
        $stmt = $pdo->prepare('UPDATE live_chat_sessions SET updated_at=? WHERE id=?');
        $stmt->execute([time(),$id]);
        $pdo->exec('COMMIT');
        return $messageId;
    } catch (Throwable $error) {
        $pdo->exec('ROLLBACK');
        throw $error;
    }
}

function support_chat_messages(string $id, int $after): array
{
    $stmt = support_pdo()->prepare('SELECT id,sender,message,created_at,read_at FROM live_chat_messages WHERE chat_id=? AND id>? ORDER BY id ASC LIMIT 100');
    $stmt->execute([$id,$after]);
    return $stmt->fetchAll();
}

function support_chat_read(string $id, string $sender, int $through): void
{
    $stmt = support_pdo()->prepare('UPDATE live_chat_messages SET read_at=? WHERE chat_id=? AND sender=? AND read_at IS NULL AND id<=?');
    $stmt->execute([time(),$id,$sender,$through]);
}

function support_chat_status(string $id, string $status): void
{
    if (!in_array($status,['open','closed'],true)) throw new InvalidArgumentException('Invalid status.');
    $stmt = support_pdo()->prepare('UPDATE live_chat_sessions SET status=?,updated_at=? WHERE id=?');
    $stmt->execute([$status,time(),$id]);
}
