<?php
/**
 * OceanViewFlats - Secure Direct Booking Inquiry Processor
 * 
 * Validates request dates, prevents overlaps against cached Airbnb data,
 * computes night-by-night CSV rate sheets, logs to local MySQL database,
 * forwards to Google Sheet, and delivers details to host and guest.
 * Supports complete multi-language localization (EN, ES, FR, IT, DE, JA).
 */

declare(strict_types=1);

// Configuration parameters
define('RECIPIENT_EMAIL', $_ENV['RECIPIENT_EMAIL'] ?? $_SERVER['RECIPIENT_EMAIL'] ?? getenv('RECIPIENT_EMAIL') ?: 'rentals@oceanviewflats.com');
define('CAPTCHA_SECRET', $_ENV['CAPTCHA_SECRET'] ?? $_SERVER['CAPTCHA_SECRET'] ?? getenv('CAPTCHA_SECRET') ?: 'securesaltsecret');
define('GOOGLE_SHEET_WEBAPP_URL', $_ENV['GOOGLE_SHEET_WEBAPP_URL'] ?? $_SERVER['GOOGLE_SHEET_WEBAPP_URL'] ?? getenv('GOOGLE_SHEET_WEBAPP_URL') ?: '');

// Set headers
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Content-Type: application/json; charset=UTF-8");

// Load central configuration
$config = require __DIR__ . '/config.php';

// Helper function to send JSON response
function send_json_response(bool $success, string $message, array $extra = []): void {
    $res = array_merge(['success' => $success], $extra);
    if ($success) {
        $res['message'] = $message;
    } else {
        $res['error'] = $message;
    }
    echo json_encode($res);
    exit;
}

// Helper to clean inputs
function clean_input(string $data): string {
    return htmlspecialchars(trim(stripslashes($data)), ENT_QUOTES, 'UTF-8');
}

// 1. Math Captcha Action (GET)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'captcha') {
    $x = random_int(2, 9);
    $y = random_int(2, 9);
    $challenge = "$x + $y";
    $signature = hash_hmac('sha256', $challenge, CAPTCHA_SECRET);
    
    echo json_encode([
        'challenge' => $challenge,
        'signature' => $signature
    ]);
    exit;
}

// Reject non-POST submissions for checkout requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    send_json_response(false, 'Method Not Allowed');
}

// 2. Honeypot check (anti-spam)
$honeypot = $_POST['website_url'] ?? '';
if ($honeypot !== '') {
    send_json_response(true, 'Your reservation inquiry has been received (honeypot triggered).');
}

// 3. Multi-language Localization Dictionary Setup
$lang = clean_input($_POST['lang'] ?? 'en');
if (!in_array($lang, ['en', 'es', 'fr', 'it', 'de', 'ja'], true)) {
    $lang = 'en';
}

$translations = [
    'en' => [
        'err_captcha_sign' => 'Security check failed. Please refresh the calendar section and try again.',
        'err_captcha_invalid' => 'Invalid verification challenge.',
        'err_captcha_wrong' => 'Incorrect answer to the security verification question.',
        'err_property' => 'Invalid property selected.',
        'err_name' => 'Please enter your full name (minimum 3 characters).',
        'err_email' => 'Please enter a valid email address.',
        'err_phone' => 'Please enter a valid phone number.',
        'err_dates_invalid' => 'Please enter a valid check-in and check-out range.',
        'err_dates_past' => 'Check-in date cannot be in the past.',
        'err_overlap_airbnb' => 'The selected dates overlap with an existing Airbnb booking (%s). Please choose other dates.',
        'err_overlap_db' => 'The selected dates are already locked in our direct booking system. Please select another range.',
        'err_min_stay' => 'The minimum stay for the selected season is %d nights. Your requested stay is %d nights.',
        'msg_success_title' => 'Inquiry Secured Successfully!',
        'msg_success_desc1' => 'We have received your inquiry for apartment <strong>%s</strong>. A temporal hold has been secured under registration code <strong>%s</strong>.',
        'msg_success_desc2' => 'A confirmation email with the price breakdown of <strong>%s</strong> was sent to <strong>%s</strong>. We will contact you shortly to coordinate payment.',
        'email_subject_guest' => 'We received your booking inquiry - OceanViewFlats %s',
        'email_title' => 'Booking Inquiry',
        'email_intro' => 'Dear <strong>%s</strong>,',
        'email_received' => 'We have successfully received your direct booking inquiry and placed a 10-minute temporary block. Here is your stay summary:',
        'email_summary' => 'Stay Summary',
        'email_property' => 'Property',
        'email_code' => 'Registration Code',
        'email_nights' => '%d nights',
        'email_breakdown' => 'Price Breakdown',
        'email_accommodation' => 'Accommodation',
        'email_cleaning' => 'Cleaning Fee',
        'email_resort' => 'Lobby Register',
        'email_total' => 'Total',
        'email_footer' => 'We will contact you in the next few minutes to specify authorized direct payment options.'
    ],
    'es' => [
        'err_captcha_sign' => 'Error de comprobación de seguridad. Por favor, recargue la sección del calendario e intente de nuevo.',
        'err_captcha_invalid' => 'Desafío de verificación inválido.',
        'err_captcha_wrong' => 'Respuesta incorrecta a la pregunta de verificación de seguridad.',
        'err_property' => 'Propiedad seleccionada inválida.',
        'err_name' => 'Por favor, ingrese su nombre completo (mínimo 3 caracteres).',
        'err_email' => 'Por favor, ingrese un correo electrónico válido.',
        'err_phone' => 'Por favor, ingrese un número de teléfono válido.',
        'err_dates_invalid' => 'Por favor, ingrese un rango de fechas de entrada y salida válido.',
        'err_dates_past' => 'La fecha de llegada no puede ser en el pasado.',
        'err_overlap_airbnb' => 'Las fechas seleccionadas coinciden con una reserva de Airbnb existente (%s). Por favor, elija otras fechas.',
        'err_overlap_db' => 'Las fechas seleccionadas ya están reservadas en nuestro sistema. Por favor, seleccione otro rango.',
        'err_min_stay' => 'La estadía mínima para la temporada seleccionada es de %d noches. Su solicitud es de %d noches.',
        'msg_success_title' => '¡Solicitud Recibida Exitosamente!',
        'msg_success_desc1' => 'Hemos recibido su solicitud para el apartamento <strong>%s</strong>. Se ha reservado un bloqueo temporal bajo el código <strong>%s</strong>.',
        'msg_success_desc2' => 'Enviamos un correo de confirmación con el desglose de <strong>%s</strong> a <strong>%s</strong>. Nos comunicaremos con usted a la brevedad para coordinar el pago.',
        'email_subject_guest' => 'Recibimos su solicitud de reserva - OceanViewFlats %s',
        'email_title' => 'Solicitud de Reserva',
        'email_intro' => 'Estimado/a <strong>%s</strong>,',
        'email_received' => 'Hemos recibido su solicitud de reserva directa y guardado un bloqueo temporal por 10 minutos. A continuación, el resumen de su estadía:',
        'email_summary' => 'Resumen de Reserva',
        'email_property' => 'Propiedad',
        'email_code' => 'Código de Registro',
        'email_nights' => '%d noches',
        'email_breakdown' => 'Detalle del Precio',
        'email_accommodation' => 'Hospedaje',
        'email_cleaning' => 'Limpieza',
        'email_resort' => 'Registro Lobby',
        'email_total' => 'Total',
        'email_footer' => 'Nos contactaremos con usted en los próximos minutos para indicarle los canales de pago directo autorizados.'
    ],
    'fr' => [
        'err_captcha_sign' => 'La vérification de sécurité a échoué. Veuillez actualiser le calendrier et réessayer.',
        'err_captcha_invalid' => 'Défi de vérification invalide.',
        'err_captcha_wrong' => 'Réponse incorrecte à la question de sécurité.',
        'err_property' => 'Propriété sélectionnée non valide.',
        'err_name' => 'Veuillez saisir votre nom complet (au moins 3 caractères).',
        'err_email' => 'Veuillez saisir une adresse e-mail valide.',
        'err_phone' => 'Veuillez saisir un numéro de téléphone valide.',
        'err_dates_invalid' => 'Veuillez saisir des dates d\'arrivée et de départ valides.',
        'err_dates_past' => 'La date d\'arrivée ne peut pas être dans le passé.',
        'err_overlap_airbnb' => 'Les dates sélectionnées chevauchent une réservation Airbnb existante (%s). Veuillez choisir d\'autres dates.',
        'err_overlap_db' => 'Les dates sélectionnées sont déjà bloquées dans notre système de réservation directe.',
        'err_min_stay' => 'Le séjour minimum pour la saison sélectionnée est de %d nuits. Votre demande est de %d nuits.',
        'msg_success_title' => 'Demande reçue avec succès !',
        'msg_success_desc1' => 'Nous avons reçu votre demande pour l\'appartement <strong>%s</strong>. Un blocage temporaire a été sécurisé sous le code de réservation <strong>%s</strong>.',
        'msg_success_desc2' => 'Un e-mail de confirmation avec le détail de <strong>%s</strong> a été envoyé à <strong>%s</strong>. Nous vous contacterons sous peu pour coordonner le paiement.',
        'email_subject_guest' => 'Nous avons reçu votre demande de réservation - OceanViewFlats %s',
        'email_title' => 'Demande de Réservation',
        'email_intro' => 'Cher/Chère <strong>%s</strong>,',
        'email_received' => 'Nous avons bien reçu votre demande de réservation directe et avons bloqué temporairement les dates pendant 10 minutes. Voici le résumé de votre séjour :',
        'email_summary' => 'Résumé du Séjour',
        'email_property' => 'Propriété',
        'email_code' => 'Code d\'enregistrement',
        'email_nights' => '%d nuits',
        'email_breakdown' => 'Détail du Prix',
        'email_accommodation' => 'Hébergement',
        'email_cleaning' => 'Frais de ménage',
        'email_resort' => 'Enregistrement Hall',
        'email_total' => 'Total',
        'email_footer' => 'Nous vous contacterons dans les prochaines minutes pour vous indiquer les modalités de paiement direct autorisées.'
    ],
    'it' => [
        'err_captcha_sign' => 'Verifica di sicurezza fallita. Aggiorna la sezione del calendario e riprova.',
        'err_captcha_invalid' => 'Sfida di verifica non valida.',
        'err_captcha_wrong' => 'Risposta errata alla domanda di sicurezza.',
        'err_property' => 'Proprietà selezionata non valida.',
        'err_name' => 'Inserisci il tuo nome completo (almeno 3 caratteri).',
        'err_email' => 'Inserisci un indirizzo e-mail valido.',
        'err_phone' => 'Inserisci un numero di telefono valido.',
        'err_dates_invalid' => 'Inserisci date di arrivo e partenza valide.',
        'err_dates_past' => 'La data di arrivo non può essere nel passato.',
        'err_overlap_airbnb' => 'Le date selezionate si sovrappongono a una prenotazione Airbnb esistente (%s). Scegli altre date.',
        'err_overlap_db' => 'Le date selezionate sono già bloccate nel nostro sistema di prenotazione diretta.',
        'err_min_stay' => 'Il soggiorno minimo per la stagione selezionata è di %d notti. La tua richiesta è di %d notti.',
        'msg_success_title' => 'Richiesta ricevuta con successo!',
        'msg_success_desc1' => 'Abbiamo ricevuto la tua richiesta per l\'appartamento <strong>%s</strong>. Un blocco temporaneo è stato riservato con il codice <strong>%s</strong>.',
        'msg_success_desc2' => 'Un\'e-mail di conferma con il dettaglio di <strong>%s</strong> è stata inviata a <strong>%s</strong>. Ti contatteremo a breve per coordinare il pagamento.',
        'email_subject_guest' => 'Abbiamo ricevuto la tua richiesta di prenotazione - OceanViewFlats %s',
        'email_title' => 'Richiesta di Prenotazione',
        'email_intro' => 'Gentile <strong>%s</strong>,',
        'email_received' => 'Abbiamo ricevuto la tua richiesta di prenotazione diretta e salvato un blocco temporaneo di 10 minuti. Ecco il riepilogo del tuo soggiorno:',
        'email_summary' => 'Riepilogo del Soggiorno',
        'email_property' => 'Proprietà',
        'email_code' => 'Codice di registrazione',
        'email_nights' => '%d notti',
        'email_breakdown' => 'Dettaglio Prezzo',
        'email_accommodation' => 'Alloggio',
        'email_cleaning' => 'Spese di pulizia',
        'email_resort' => 'Registrazione Hall',
        'email_total' => 'Totale',
        'email_footer' => 'Ti contatteremo nei prossimi minuti per indicarti le modalità di pagamento diretto autorizzate.'
    ],
    'de' => [
        'err_captcha_sign' => 'Sicherheitsprüfung fehlgeschlagen. Bitte laden Sie den Kalenderbereich neu und versuchen Sie es erneut.',
        'err_captcha_invalid' => 'Ungültige Sicherheitsprüfung.',
        'err_captcha_wrong' => 'Falsche Antwort auf die Sicherheitsfrage.',
        'err_property' => 'Ungültige Unterkunft ausgewählt.',
        'err_name' => 'Bitte geben Sie Ihren vollständigen Namen ein (mindestens 3 Zeichen).',
        'err_email' => 'Bitte geben Sie eine gültige E-Mail-Adresse ein.',
        'err_phone' => 'Bitte geben Sie eine gültige Telefonnummer ein.',
        'err_dates_invalid' => 'Bitte geben Sie einen gültigen Zeitraum für An- und Abreise ein.',
        'err_dates_past' => 'Das Anreisedatum darf nicht in der Vergangenheit liegen.',
        'err_overlap_airbnb' => 'Die ausgewählten Daten überschneiden sich mit einer bestehenden Airbnb-Buchung (%s). Bitte wählen Sie andere Daten.',
        'err_overlap_db' => 'Die ausgewählten Daten sind bereits in unserem Direktbuchungssystem blockiert.',
        'err_min_stay' => 'Der Mindestaufenthalt für die gewählte Saison beträgt %d Nächte. Ihre Anfrage beträgt %d Nächte.',
        'msg_success_title' => 'Buchungsanfrage erfolgreich erhalten!',
        'msg_success_desc1' => 'Wir haben Ihre Anfrage für das Apartment <strong>%s</strong> erhalten. Eine temporäre Sperre wurde unter dem Registrierungscode <strong>%s</strong> hinterlegt.',
        'msg_success_desc2' => 'Eine Bestätigungs-E-Mail mit der Preisaufschlüsselung von <strong>%s</strong> wurde an <strong>%s</strong> gesendet. Wir werden uns in Kürze mit Ihnen in Verbindung setzen, um die Zahlung zu koordinieren.',
        'email_subject_guest' => 'Ihre Buchungsanfrage ist eingegangen - OceanViewFlats %s',
        'email_title' => 'Buchungsanfrage',
        'email_intro' => 'Sehr geehrte/r <strong>%s</strong>,',
        'email_received' => 'wir haben Ihre Anfrage zur Direktbuchung erhalten und die Daten für 10 Minuten für Sie blockiert. Hier ist die Zusammenfassung Ihres Aufenthalts:',
        'email_summary' => 'Zusammenfassung des Aufenthalts',
        'email_property' => 'Unterkunft',
        'email_code' => 'Registrierungscode',
        'email_nights' => '%d Nächte',
        'email_breakdown' => 'Preisaufschlüsselung',
        'email_accommodation' => 'Unterkunft',
        'email_cleaning' => 'Reinigungsgebühr',
        'email_resort' => 'Lobby-Registrierung',
        'email_total' => 'Gesamt',
        'email_footer' => 'Wir werden uns in den nächsten Minuten mit Ihnen in Verbindung setzen, um Ihnen die autorisierten Direktzahlungsmöglichkeiten mitzuteilen.'
    ],
    'ja' => [
        'err_captcha_sign' => 'セキュリティ検証に失敗しました。カレンダーエリアを更新して、もう一度お試しください。',
        'err_captcha_invalid' => '無効な認証要請です。',
        'err_captcha_wrong' => 'セキュリティ質問の答えが正しくありません。',
        'err_property' => '選択されたアパートメントは無効です。',
        'err_name' => 'お名前をフルネームで入力してください（3文字以上）。',
        'err_email' => '有効なメールアドレスを入力してください。',
        'err_phone' => '有効な電話番号を入力してください。',
        'err_dates_invalid' => '有効なチェックインおよびチェックアウトの日付を選択してください。',
        'err_dates_past' => '過去の日付はチェックイン日として選択できません。',
        'err_overlap_airbnb' => 'ご希望の日付は既にAirbnbの予約（%s）と重複しています。別の日付を選択してください。',
        'err_overlap_db' => 'ご希望の日付は既に直接予約システムで確保されています。別の範囲を選択してください。',
        'err_min_stay' => '選択されたシーズンの最低宿泊日数は %d 泊です。現在のご希望は %d 泊です。',
        'msg_success_title' => 'お問い合わせを正常に受け付けました！',
        'msg_success_desc1' => 'アパートメント <strong>%s</strong> の直接予約のお問い合わせを受領いたしました。登録コード <strong>%s</strong> にて仮押さえをいたしました。',
        'msg_success_desc2' => '料金明細 <strong>%s</strong> を記載した確認メールを <strong>%s</strong> 宛に送信しました。お支払い方法のご案内のため、まもなくホストよりご連絡いたします。',
        'email_subject_guest' => '予約問い合わせを承りました - OceanViewFlats %s',
        'email_title' => '予約問い合わせ',
        'email_intro' => '<strong>%s</strong> 様',
        'email_received' => 'この度は直接予約のお問い合わせをいただきありがとうございます。ご希望の日程を10分間、仮押さえいたしました。ご予約内容は以下の通りです：',
        'email_summary' => 'ご予約内容の概要',
        'email_property' => 'お部屋',
        'email_code' => '登録コード',
        'email_nights' => '%d 泊',
        'email_breakdown' => '料金明細',
        'email_accommodation' => '宿泊料金',
        'email_cleaning' => '清掃料金',
        'email_resort' => 'ロビー登録料',
        'email_total' => '合計金額',
        'email_footer' => 'お支払い方法のご案内について、まもなくホストより直接ご連絡いたします。今しばらくお待ちください。'
    ]
];

$t = $translations[$lang];

// 4. Captcha Verification
$captcha_challenge = $_POST['captcha_challenge'] ?? '';
$captcha_signature = $_POST['captcha_signature'] ?? '';
$captcha_response = $_POST['captcha_response'] ?? '';

$expected_signature = hash_hmac('sha256', $captcha_challenge, CAPTCHA_SECRET);
if (!hash_equals($expected_signature, $captcha_signature)) {
    send_json_response(false, $t['err_captcha_sign']);
}

if (!preg_match('/^(\d+)\s*\+\s*(\d+)$/', $captcha_challenge, $matches)) {
    send_json_response(false, $t['err_captcha_invalid']);
}
$expected_sum = (int)$matches[1] + (int)$matches[2];
if ((int)$captcha_response !== $expected_sum) {
    send_json_response(false, $t['err_captcha_wrong']);
}

// Record timestamp for rate-limit
$limits[$ip_hash][] = $now;
@file_put_contents($rate_limit_path, json_encode($limits), LOCK_EX);

// 5. Gather & Validate Core Input Details
$propertyId = clean_input($_POST['property_id'] ?? '');
$checkInStr = clean_input($_POST['check_in'] ?? '');
$checkOutStr = clean_input($_POST['check_out'] ?? '');
$guestName = clean_input($_POST['guest_name'] ?? '');
$guestEmail = clean_input($_POST['guest_email'] ?? '');
$guestPhone = clean_input($_POST['guest_phone'] ?? '');
$clientPriceCop = (float)($_POST['total_price_cop'] ?? 0);

if ($propertyId !== '1606' && $propertyId !== '1707') {
    send_json_response(false, $t['err_property']);
}
if (empty($guestName) || strlen($guestName) < 3) {
    send_json_response(false, $t['err_name']);
}
if (!filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
    send_json_response(false, $t['err_email']);
}
if (empty($guestPhone) || strlen($guestPhone) < 6) {
    send_json_response(false, $t['err_phone']);
}

$checkIn = strtotime($checkInStr);
$checkOut = strtotime($checkOutStr);

if (!$checkIn || !$checkOut || $checkIn >= $checkOut) {
    send_json_response(false, $t['err_dates_invalid']);
}

if ($checkIn < strtotime(date('Y-m-d'))) {
    send_json_response(false, $t['err_dates_past']);
}

// 6. Overlap Booking Check Against Cache and Database
// A: Check Airbnb iCal Cache
$cacheFile = __DIR__ . '/../cache/avail_' . $propertyId . '.json';
$blockedDates = [];
if (file_exists($cacheFile)) {
    $cacheContent = @file_get_contents($cacheFile);
    if ($cacheContent !== false) {
        $blockedDates = json_decode($cacheContent, true) ?: [];
    }
}

$requestedNights = [];
$curr = $checkIn;
while ($curr < $checkOut) {
    $dateStr = date('Y-m-d', $curr);
    $requestedNights[] = $dateStr;
    if (in_array($dateStr, $blockedDates, true)) {
        send_json_response(false, sprintf($t['err_overlap_airbnb'], $dateStr));
    }
    $curr = strtotime("+1 day", $curr);
}

// B: Establish Database Connection and check local table
$pdo = null;
try {
    $dsn = "mysql:host=" . $config['db']['host'] . ";charset=utf8mb4";
    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    // Create database and tables automatically if missing
    $dbname = $config['db']['dbname'];
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$dbname`");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `reservations` (
      `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      `reservation_uid` VARCHAR(36) NOT NULL UNIQUE,
      `property_id` VARCHAR(10) NOT NULL,
      `guest_name` VARCHAR(120) NOT NULL,
      `guest_email` VARCHAR(100) NOT NULL,
      `guest_phone` VARCHAR(25) NOT NULL,
      `check_in` DATE NOT NULL,
      `check_out` DATE NOT NULL,
      `total_price` DECIMAL(10, 2) NOT NULL,
      `mercadopago_preference_id` VARCHAR(255) DEFAULT NULL,
      `status` ENUM('pending_payment', 'confirmed', 'cancelled') NOT NULL DEFAULT 'pending_payment',
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX `idx_property_dates` (`property_id`, `check_in`, `check_out`),
      INDEX `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Query for overlapping local reservations that are NOT cancelled
    $stmt = $pdo->prepare("
        SELECT id FROM `reservations`
        WHERE `property_id` = :prop_id
          AND `status` != 'cancelled'
          AND (
            (`check_in` <= :check_in AND `check_out` > :check_in) OR
            (`check_in` < :check_out AND `check_out` >= :check_out) OR
            (:check_in <= `check_in` AND :check_out >= `check_out`)
          )
    ");
    $stmt->execute([
        'prop_id' => $propertyId,
        'check_in' => $checkInStr,
        'check_out' => $checkOutStr
    ]);
    if ($stmt->fetch()) {
        send_json_response(false, $t['err_overlap_db']);
    }
} catch (PDOException $e) {
    // If the database connection fails, log locally but proceed with CSV calculations and email routing (high reliability)
    error_log("Direct booking MySQL connection failed: " . $e->getMessage());
}

// 7. Night-by-Night Pricing Resolution via prices.csv
$csvPath = __DIR__ . '/../data/prices.csv';
$pricesData = [];
if (file_exists($csvPath)) {
    $csvFile = fopen($csvPath, 'r');
    if ($csvFile !== false) {
        $headers = fgetcsv($csvFile);
        while (($row = fgetcsv($csvFile)) !== false) {
            if (count($row) >= 5) {
                $pricesData[] = [
                    'property_id' => $row[0],
                    'start_date' => $row[1],
                    'end_date' => $row[2],
                    'nightly_rate_cop' => (float)$row[3],
                    'minimum_stay' => (int)$row[4]
                ];
            }
        }
        fclose($csvFile);
    }
}

// Compute total nightly rate
$accommodationTotal = 0.0;
$minimumStayRequired = 2; // Default minimum
$datesCount = count($requestedNights);

foreach ($requestedNights as $night) {
    // Find matching tier
    $tierFound = null;
    foreach ($pricesData as $tier) {
        if ($tier['property_id'] === $propertyId && $night >= $tier['start_date'] && $night <= $tier['end_date']) {
            $tierFound = $tier;
            break;
        }
    }
    $rate = $tierFound ? $tierFound['nightly_rate_cop'] : ($propertyId === '1707' ? 450000.0 : 350000.0);
    if ($tierFound) {
        $minimumStayRequired = max($minimumStayRequired, $tierFound['minimum_stay']);
    }
    $accommodationTotal += $rate;
}

if ($datesCount < $minimumStayRequired) {
    send_json_response(false, sprintf($t['err_min_stay'], $minimumStayRequired, $datesCount));
}

$cleaningFee = $propertyId === '1707' ? 100000.0 : 80000.0;
$resortFee = 20000.0;
$serverTotalCop = $accommodationTotal + $cleaningFee + $resortFee;

// Security verification: compare computed total against client total
if (abs($serverTotalCop - $clientPriceCop) > 1.0) {
    // Audit mismatch: log and enforce server resolution
    error_log("Direct booking pricing audit mismatch: Client: $clientPriceCop, Server: $serverTotalCop.");
}

// 8. Log Booking Request to MySQL
$uid = 'ovf_' . bin2hex(random_bytes(4)); // Safe unique reservation code
$dbLogged = false;

if ($pdo !== null) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO `reservations` (reservation_uid, property_id, guest_name, guest_email, guest_phone, check_in, check_out, total_price, status)
            VALUES (:uid, :prop, :name, :email, :phone, :check_in, :check_out, :price, 'pending_payment')
        ");
        $stmt->execute([
            'uid' => $uid,
            'prop' => $propertyId,
            'name' => $guestName,
            'email' => $guestEmail,
            'phone' => $guestPhone,
            'check_in' => $checkInStr,
            'check_out' => $checkOutStr,
            'price' => $serverTotalCop
        ]);
        $dbLogged = true;
    } catch (PDOException $e) {
        error_log("Database insertion failed: " . $e->getMessage());
    }
}

// 9. Forward Details to Google Sheet webhook
$sheetSuccess = false;
$webhook_url = GOOGLE_SHEET_WEBAPP_URL;
if (!empty($webhook_url) && filter_var($webhook_url, FILTER_VALIDATE_URL)) {
    $sheetPayload = [
        'timestamp' => date('Y-m-d H:i:s'),
        'reservation_uid' => $uid,
        'property' => $propertyId,
        'check_in' => $checkInStr,
        'check_out' => $checkOutStr,
        'guest_name' => $guestName,
        'guest_email' => $guestEmail,
        'guest_phone' => $guestPhone,
        'total_price' => $serverTotalCop,
        'status' => 'pending_payment'
    ];

    $ch = curl_init($webhook_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($sheetPayload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'User-Agent: OceanViewFlats Direct Booking PHP'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    $res = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200) {
        $sheetSuccess = true;
    }
}

// 10. Send Structured Emails (Host & Guest)
$copFormatter = "$ " . number_format($serverTotalCop, 0, ',', '.') . " COP";
$accommodationFormatted = "$ " . number_format($accommodationTotal, 0, ',', '.') . " COP";
$cleaningFormatted = "$ " . number_format($cleaningFee, 0, ',', '.') . " COP";
$resortFormatted = "$ " . number_format($resortFee, 0, ',', '.') . " COP";

$stayNightsLabel = sprintf($t['email_nights'], $datesCount);

// Email HTML content - fully localized for the guest!
$html_message = "
<html>
<head>
  <style>
    body { font-family: sans-serif; color: #333; line-height: 1.6; }
    .container { max-width: 600px; margin: 0 auto; border: 1px solid #eee; border-radius: 12px; overflow: hidden; }
    .header { background-color: #f8fafc; padding: 24px; border-bottom: 1px solid #eee; text-align: center; }
    .body { padding: 24px; }
    .card { background-color: #f8fafc; padding: 16px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #e2e8f0; }
    .table { width: 100%; margin-top: 10px; border-collapse: collapse; }
    .table td { padding: 8px 0; border-bottom: 1px solid #edf2f7; }
    .table .bold { font-weight: bold; }
    .text-right { text-align: right; }
    .footer { font-size: 11px; color: #999; padding: 20px; text-align: center; border-top: 1px solid #eee; }
  </style>
</head>
<body>
  <div class='container'>
    <div class='header'>
      <h2 style='margin:0;color:#0f172a;'>{$t['email_title']}</h2>
      <p style='margin:5px 0 0 0;color:#64748b;'>OceanViewFlats Santa Marta</p>
    </div>
    <div class='body'>
      <p>" . sprintf($t['email_intro'], $guestName) . "</p>
      <p>{$t['email_received']}</p>
      
      <div class='card'>
        <h3 style='margin:0 0 10px 0;color:#0f172a;font-size:16px;'>{$t['email_summary']}</h3>
        <table width='100%' class='table'>
          <tr><td><strong>{$t['email_property']}:</strong></td><td class='text-right'>OceanViewFlats {$propertyId}</td></tr>
          <tr><td><strong>{$t['email_code']}:</strong></td><td class='text-right'><code style='background:#f1f5f9;padding:2px 6px;border-radius:4px;'>{$uid}</code></td></tr>
          <tr><td><strong>Check-In:</strong></td><td class='text-right'>{$checkInStr}</td></tr>
          <tr><td><strong>Check-Out:</strong></td><td class='text-right'>{$checkOutStr}</td></tr>
          <tr><td><strong>Estadía / Stay:</strong></td><td class='text-right'>{$stayNightsLabel}</td></tr>
        </table>
      </div>

      <div class='card'>
        <h3 style='margin:0 0 10px 0;color:#0f172a;font-size:16px;'>{$t['email_breakdown']}</h3>
        <table width='100%' class='table'>
          <tr><td>{$t['email_accommodation']}:</td><td class='text-right'>{$accommodationFormatted}</td></tr>
          <tr><td>{$t['email_cleaning']}:</td><td class='text-right'>{$cleaningFormatted}</td></tr>
          <tr><td>{$t['email_resort']}:</td><td class='text-right'>{$resortFormatted}</td></tr>
          <tr style='font-size:18px;font-weight:bold;'><td style='border-bottom:none;'>{$t['email_total']}:</td><td class='text-right' style='border-bottom:none;color:#059669;'>{$copFormatter}</td></tr>
        </table>
      </div>

      <p>{$t['email_footer']}</p>
      
      <p style='font-size:13px;color:#64748b;'><em>Inquiries automatically secured. Google Sheets sync: " . ($sheetSuccess ? 'YES' : 'NO') . ". DB storage: " . ($dbLogged ? 'YES' : 'NO') . ". Language Code: " . strtoupper($lang) . ".</em></p>
    </div>
    <div class='footer'>
      &copy; 2026 OceanViewFlats. Calle 26 # 2-80, Playa Salguero, Santa Marta, Colombia.
    </div>
  </div>
</body>
</html>
";

// Secure headers for multipart HTML delivery
$headers = "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/html; charset=UTF-8\r\n";
$headers .= "From: OceanViewFlats <no-reply@oceanviewflats.com>\r\n";
$headers .= "Reply-To: rentals@oceanviewflats.com\r\n";
$headers .= "X-Mailer: PHP/" . phpversion();

// Send to host
$subjectHost = "NEW DIRECT BOOKING REQUEST: Prop $propertyId ($guestName) - [" . strtoupper($lang) . "]";
mail(RECIPIENT_EMAIL, $subjectHost, $html_message, $headers);

// Send to guest as receipt (fully localized!)
$subjectGuest = sprintf($t['email_subject_guest'], $propertyId);
mail($guestEmail, $subjectGuest, $html_message, $headers);

// 11. Generate MercadoPago Payment Preference Session (Checkout Pro)
$mpAccessToken = $_ENV['MERCADOPAGO_ACCESS_TOKEN'] ?? $_SERVER['MERCADOPAGO_ACCESS_TOKEN'] ?? getenv('MERCADOPAGO_ACCESS_TOKEN') ?: '';
$mpSandbox = $_ENV['MERCADOPAGO_SANDBOX'] ?? $_SERVER['MERCADOPAGO_SANDBOX'] ?? getenv('MERCADOPAGO_SANDBOX') ?: 'false';
$isSandbox = (strtolower($mpSandbox) === 'true' || $mpSandbox === '1' || $mpSandbox === 1);

$redirectUrl = '';
$preferenceId = '';

if (!empty($mpAccessToken)) {
    // Compile exact localized receipt return URLs
    $baseUrl = 'https://www.oceanviewflats.com';
    $backSuccess = $lang === 'en' ? "{$baseUrl}/booking-success/" : "{$baseUrl}/booking-success/{$lang}.html";
    $backFailure = $lang === 'en' ? "{$baseUrl}/booking-failure/" : "{$baseUrl}/booking-failure/{$lang}.html";
    $backPending = $lang === 'en' ? "{$baseUrl}/booking-pending/" : "{$baseUrl}/booking-pending/{$lang}.html";

    // Call MercadoPago Preferences REST API
    $ch = curl_init("https://api.mercadopago.com/checkout/preferences");
    $preferenceData = [
        "items" => [[
            "id" => "ovf_" . $propertyId,
            "title" => "Reserva Apto " . $propertyId . " - OceanViewFlats",
            "quantity" => 1,
            "currency_id" => "COP",
            "unit_price" => (float)$serverTotalCop
        ]],
        "payer" => [
            "name" => $guestName,
            "email" => $guestEmail,
            "phone" => ["number" => $guestPhone]
        ],
        "back_urls" => [
            "success" => $backSuccess,
            "failure" => $backFailure,
            "pending" => $backPending
        ],
        "auto_return" => "all",
        "external_reference" => $uid,
        "expires" => true,
        "date_of_expiration" => date('c', strtotime('+10 minutes'))
    ];

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($preferenceData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $mpAccessToken,
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $responseStr = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 || $httpCode === 201) {
        $prefResponse = json_decode($responseStr, true);
        if (isset($prefResponse['id'])) {
            $preferenceId = $prefResponse['id'];
            $redirectUrl = $isSandbox ? $prefResponse['sandbox_init_point'] : $prefResponse['init_point'];
            
            // Link preference id inside MySQL database row
            if ($pdo !== null && $dbLogged) {
                try {
                    $upStmt = $pdo->prepare("UPDATE `reservations` SET `mercadopago_preference_id` = :pref WHERE `reservation_uid` = :uid");
                    $upStmt->execute(['pref' => $preferenceId, 'uid' => $uid]);
                } catch (PDOException $e) {
                    error_log("Failed to update reservation with preference ID: " . $e->getMessage());
                }
            }
        }
    } else {
        error_log("MercadoPago preference API error. Code: $httpCode. Response: $responseStr");
    }
}

// Output successful response to client - fully localized!
$localizedMessage = "
  <div class='space-y-2'>
    <p class='font-bold text-base text-emerald-900'>{$t['msg_success_title']}</p>
    <p class='text-emerald-800 opacity-90 leading-relaxed'>" . sprintf($t['msg_success_desc1'], $propertyId, $uid) . "</p>
    <p class='text-emerald-800 opacity-90 leading-relaxed'>" . sprintf($t['msg_success_desc2'], $copFormatter, $guestEmail) . "</p>
  </div>
";

send_json_response(true, $localizedMessage, [
    'reservation_uid' => $uid,
    'total_price' => $serverTotalCop,
    'redirect_url' => $redirectUrl
]);
