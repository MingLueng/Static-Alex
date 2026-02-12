<?php
declare(strict_types=1);

/**
 * contact_form.php（オールインワン）
 * ------------------------------------------------------------
 * 3ステップフォーム
 *  - STEP1：入力
 *  - STEP2：確認
 *  - STEP3：完了（PRG: Post/Redirect/Get で二重送信防止）
 *
 * 言語切替：
 *  - ?lang=ja|vi|en
 *
 * 二重送信対策：
 *  - セッショントークン + PRG（POST後にリダイレクトして再POSTを防ぐ）
 *
 * 連続送信制限（レートリミット）：
 *  - IP単位で一定秒数以内の連続送信をブロック
 *
 * 送信方式の自動選択：
 *  - sendMailAuto() が自動で判定
 *    1) mb_send_mail が使えるなら先に試す
 *    2) 失敗したら Gmail SMTP にフォールバック
 */

mb_internal_encoding('UTF-8');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/* =========================================================
   CONFIG（ここだけ編集すればOK）
   ※ App Password は絶対に公開しないこと（Gitにも載せない）
   ========================================================= */
const GMAIL_HOST     = 'smtp.gmail.com';           // Gmail SMTPホスト
const GMAIL_PORT     = 587;                        // SMTPポート（STARTTLS）
const GMAIL_USER     = 'hinodesenpaijpt@gmail.com';// SMTPログインID（Gmail）
const GMAIL_APP_PASS = 'wvnx sltl rnlx bibj';      // ✅ 本物のApp Passwordは書かない（環境変数推奨）
const ADMIN_TO       = 'hapx@dragoon.vn';  // 管理者の受信先（問い合わせが届く先）

// mb_send_mail を使う場合の From（固定推奨：自ドメイン or 管理者メール）
const FROM_ADDR = 'hapx@dragoon.vn';

// 連続送信制限（IP単位・秒）
const RATE_LIMIT_SECONDS = 10;

/**
 * 旧システム互換のフラグ（お客様側の仕様に合わせる）
 * ------------------------------------------------------------
 * $chmail   確認画面の有無（1=あり / 0=なし）
 * $from_add 返信先/差出人の扱い（1=ユーザーのメールをReply-Toにする）
 * $remail   自動返信（1=送る）
 * $esse     必須チェック（1=する）
 */
$chmail   = 1;
$from_add = 1;
$remail   = 1;
$esse     = 1;

// 件名（後で言語パック $L['subject'] で上書きされる）
$sbj   = "お問い合わせ【ALEX】";
$resbj = "お問い合わせ【ALEX】";

/* =========================================================
   HELPERS（共通関数）
   ========================================================= */

/** XSS対策：HTMLに出す前に必ずエスケープする */
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/**
 * 言語判定
 * 優先順位：
 *  1) GET lang
 *  2) POST lang
 *  3) URL/Referer に /vi/ /en/ が含まれるか
 *  4) それ以外は ja
 */
function detectLang(): string {
  $lang = trim((string)($_GET['lang'] ?? ''));
  if (in_array($lang, ['ja','vi','en'], true)) return $lang;

  $lang = trim((string)($_POST['lang'] ?? ''));
  if (in_array($lang, ['ja','vi','en'], true)) return $lang;

  $u   = (string)($_SERVER['REQUEST_URI'] ?? '');
  $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
  $hay = $u.' '.$ref;

  if (strpos($hay, '/vi/') !== false) return 'vi';
  if (strpos($hay, '/en/') !== false) return 'en';
  return 'ja';
}

/** 言語ごとのURLプレフィックス */
function langPrefix(string $lang): string {
  return $lang === 'vi' ? '/vi' : ($lang === 'en' ? '/en' : '');
}

/** /path を言語付きURLへ変換 */
function urlLang(string $lang, string $path): string {
  $path = '/' . ltrim($path, '/');
  return langPrefix($lang) . $path;
}

/** 言語フラグ（国旗）リンク：常に contact_form.php?lang=xx へ */
function flagLink(string $lang): string {
  return '/contact_form.php?lang=' . rawurlencode($lang);
}

/* =========================================================
   LANGUAGE PACK（文言セット）
   ========================================================= */
function langPack(string $lang): array {
  $m = [
    'ja' => [
      'page_title' => 'お問い合わせ',
      'subject' => '【Website】お問い合わせ',
      'missing' => '未入力項目があります',
      'invalid_email' => 'メールアドレスの形式が正しくありません。',
      'email_required' => '「メールアドレス」は必須入力項目です。',
      'email2_required' => '「メールアドレス：確認用」は必須入力項目です。',
      'email_not_match' => 'メールアドレス確認用が一致しません。',
      'required' => 'は必須入力項目です。',
      'req_badge' => '※必須',
      'too_fast' => '送信間隔が短すぎます。少し待ってから再送信してください。',
      'send_fail' => '送信に失敗しました。時間をおいて再度お試しください。',
      'intro' => '以下のフォームにお問い合わせ内容をご記入いただき、送信してください',
      'confirm_intro' => '以下の内容で間違いがなければ、「送信」ボタンを押してください。',
      'btn_confirm' => '入力内容を確認する',
      'btn_back' => '入力画面に戻る',
      'btn_send' => '送信する',
      'thanks_html' => "お問い合わせありがとうございました。<br>送信は無事に完了しました。",
      'steps' => [
        's1' => 'STEP1　内容入力',
        's2' => 'STEP2　内容確認',
        's3' => 'STEP3　受付完了',
      ],
      'nav' => [
        'top' => 'TOP',
        'about' => 'ABOUT',
        'service' => 'SERVICE',
        'works' => 'WORKS',
        'recruit' => 'RECRUIT',
        'access' => 'ACCESS',
        'contact' => 'CONTACT',
        'about_message' => '代表メッセージ',
        'about_company' => '会社概要',
        'about_history' => '沿革',
        'about_org' => '組織図',
        'about_clients' => '主要取引先',
        'service_movie' => '映像制作',
        'service_dev' => '実装・プログラミング',
        'service_system' => 'システム・ツール開発',
        'service_plan' => '企画',
        'recruit_jobs' => '募集職種',
        'recruit_voices' => 'スタッフの声',
      ],
      'labels' => [
        'name' => 'お名前',
        'kana' => 'フリガナ',
        'phone'=> '電話番号',
        'email'=> 'メールアドレス',
        'email2'=> 'メールアドレス確認用',
        'msg'  => 'お問い合わせ内容',
      ],
      'placeholders' => [
        'name' => '例：山田 太郎',
        'kana' => '例：ヤマダ タロウ',
        'phone'=> '数字のみ（9〜11桁）',
        'email'=> '例：info@example.com',
        'email2'=> '確認のため再入力',
        'msg'  => 'お問い合わせ内容をご記入ください…',
      ],
      'reply_intro' => "以下の内容でお問合せを受け付けました。\n\n",
      'company_sig' => "株式会社アレックス(ALEX)\n※このメールは自動配信です。\n",
      'empty_text' => '（未入力）',
      'mail_domain_note' => 'ドメイン指定受信を設定している方は「alex7.co.jp」を受信許可リストに追加してください。',
    ],

    'vi' => [
      'page_title' => 'Liên hệ',
      'subject' => '[Website] Liên hệ mới',
      'missing' => 'Có mục chưa nhập',
      'invalid_email' => 'Định dạng email không hợp lệ.',
      'email_required' => '“Email” là bắt buộc.',
      'email2_required' => '“Xác nhận email” là bắt buộc.',
      'email_not_match' => 'Email xác nhận không khớp.',
      'required' => 'là bắt buộc.',
      'req_badge' => '※Bắt buộc',
      'too_fast' => 'Bạn gửi quá nhanh. Vui lòng chờ một chút rồi thử lại.',
      'send_fail' => 'Gửi thất bại. Vui lòng thử lại sau.',
      'intro' => 'Vui lòng điền nội dung liên hệ vào biểu mẫu bên dưới và gửi cho chúng tôi.',
      'confirm_intro' => 'Vui lòng kiểm tra nội dung. Nếu đúng, bấm “Gửi”.',
      'btn_confirm' => 'Xác nhận nội dung',
      'btn_back' => 'Quay lại',
      'btn_send' => 'Gửi',
      'thanks_html' => "Cảm ơn bạn đã liên hệ.<br>Gửi thành công.",
      'steps' => [
        's1' => 'BƯỚC 1 Nhập nội dung',
        's2' => 'BƯỚC 2 Xác nhận',
        's3' => 'BƯỚC 3 Hoàn tất',
      ],
      'nav' => [
        'top' => 'TRANG CHỦ',
        'about' => 'GIỚI THIỆU',
        'service' => 'DỊCH VỤ',
        'works' => 'SẢN PHẨM',
        'recruit' => 'TUYỂN DỤNG',
        'access' => 'ĐỊA CHỈ',
        'contact' => 'LIÊN HỆ',
        'about_message' => 'Thông điệp CEO',
        'about_company' => 'Giới thiệu công ty',
        'about_history' => 'Lịch sử',
        'about_org' => 'Sơ đồ tổ chức',
        'about_clients' => 'Khách hàng tiêu biểu',
        'service_movie' => 'Sản xuất video',
        'service_dev' => 'Lập trình & triển khai',
        'service_system' => 'Phát triển hệ thống & công cụ',
        'service_plan' => 'Lên ý tưởng',
        'recruit_jobs' => 'Cơ hội việc làm',
        'recruit_voices' => 'Ý kiến đánh giá của nhân viên',
      ],
      'labels' => [
        'name' => 'Họ và tên',
        'kana' => 'Furigana (nếu có)',
        'phone'=> 'Số điện thoại',
        'email'=> 'Email',
        'email2'=> 'Xác nhận email',
        'msg'  => 'Nội dung liên hệ',
      ],
      'placeholders' => [
        'name' => 'Ví dụ: Nguyễn Văn A',
        'kana' => 'Ví dụ: ヤマダ タロウ',
        'phone'=> 'Chỉ nhập số (9–11 chữ số)',
        'email'=> 'Ví dụ: info@example.com',
        'email2'=> 'Nhập lại email',
        'msg'  => 'Vui lòng nhập nội dung liên hệ của bạn...',
      ],
      'reply_intro' => "Chúng tôi đã nhận được nội dung liên hệ của bạn.\n\n",
      'company_sig' => "ALEX CO., LTD.\n",
      'empty_text' => '(Chưa nhập)',
      'mail_domain_note' => 'Nếu bạn dùng chặn thư theo tên miền, hãy thêm “alex7.co.jp” vào danh sách cho phép.',
    ],

    'en' => [
      'page_title' => 'Contact',
      'subject' => '[Website] New inquiry',
      'missing' => 'There are missing fields',
      'invalid_email' => 'Invalid email format.',
      'email_required' => '“Email address” is required.',
      'email2_required' => '“Confirm email” is required.',
      'email_not_match' => 'Emails do not match.',
      'required' => 'is required.',
      'req_badge' => 'Required',
      'too_fast' => 'You are sending too fast. Please wait and try again.',
      'send_fail' => 'Send failed. Please try again later.',
      'intro' => 'Please fill in the form below and submit your inquiry.',
      'confirm_intro' => 'Please confirm the details. If correct, press “Send”.',
      'btn_confirm' => 'Confirm details',
      'btn_back' => 'Back',
      'btn_send' => 'Send',
      'thanks_html' => "Thank you for contacting us.<br>Your message has been sent successfully.",
      'steps' => [
        's1' => 'STEP 1 Enter details',
        's2' => 'STEP 2 Confirm',
        's3' => 'STEP 3 Completed',
      ],
      'nav' => [
        'top' => 'TOP',
        'about' => 'ABOUT',
        'service' => 'SERVICE',
        'works' => 'WORKS',
        'recruit' => 'RECRUIT',
        'access' => 'ACCESS',
        'contact' => 'CONTACT',
        'about_message' => 'CEO Message',
        'about_company' => 'Company Profile',
        'about_history' => 'History',
        'about_org' => 'Organization',
        'about_clients' => 'Key Clients',
        'service_movie' => 'Video Production',
        'service_dev' => 'Development & Implementation',
        'service_system' => 'System & Tools',
        'service_plan' => 'Planning',
        'recruit_jobs' => 'Job Openings',
        'recruit_voices' => 'Staff Voices',
      ],
      'labels' => [
        'name' => 'Name',
        'kana' => 'Kana',
        'phone'=> 'Phone',
        'email'=> 'Email',
        'email2'=> 'Confirm email',
        'msg'  => 'Message',
      ],
      'placeholders' => [
        'name' => 'e.g. John Doe',
        'kana' => 'e.g. JOHN DOE',
        'phone'=> 'Digits only (9–11)',
        'email'=> 'e.g. info@example.com',
        'email2'=> 'Re-enter email',
        'msg'  => 'Please write your message...',
      ],
      'reply_intro' => "We received your inquiry.\n\n",
      'company_sig' => "ALEX CO., LTD.\n",
      'empty_text' => '(Empty)',
      'mail_domain_note' => 'If you use domain filtering, please allow “alex7.co.jp”.',
    ],
  ];

  return $m[$lang] ?? $m['ja'];
}

/**
 * 入力チェック（必須・メール形式・メール一致）
 * 返り値：エラーメッセージ配列（空ならOK）
 */
function validateInput(array $L, string $name, string $email, string $email2, string $msg): array {
  $errors = [];
  if ($name === '')   $errors[] = '「'.$L['labels']['name'].'」'.$L['required'];
  if ($email === '')  $errors[] = $L['email_required'];
  if ($email2 === '') $errors[] = $L['email2_required'];
  if ($msg === '')    $errors[] = '「'.$L['labels']['msg'].'」'.$L['required'];

  if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = $L['invalid_email'];
  if ($email !== '' && $email2 !== '' && $email !== $email2) $errors[] = $L['email_not_match'];

  return $errors;
}

/* =========================================================
   SMTP（最小実装）Gmail送信用
   ========================================================= */
final class SimpleSMTP {
  private $fp;
  private string $host;
  private int $port;
  private int $timeout;

  public function __construct(string $host, int $port, int $timeout = 20) {
    $this->host = $host;
    $this->port = $port;
    $this->timeout = $timeout;
  }

  /** SMTPへ接続 */
  public function connect(): void {
    $errno = 0; $errstr = '';
    $this->fp = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
    if (!$this->fp) throw new Exception("SMTP connect failed: {$errstr} ({$errno})");
    stream_set_timeout($this->fp, $this->timeout);
    $this->expect([220]);
  }

  /** 1行送信 */
  private function write(string $line): void { fwrite($this->fp, $line . "\r\n"); }

  /** 1行受信 */
  private function readLine(): string {
    $line = fgets($this->fp, 515);
    return $line === false ? '' : $line;
  }

  /**
   * 期待するステータスコードを待つ
   * - 例：220/250/334/235/354 など
   */
  private function expect(array $codes): string {
    $data = '';
    while (true) {
      $line = $this->readLine();
      if ($line === '') break;
      $data .= $line;
      if (preg_match('/^\d{3}\s/', $line)) break; // 3桁 + 半角スペースで終了行
    }
    $code = (int)substr(trim($data), 0, 3);
    if (!in_array($code, $codes, true)) throw new Exception("SMTP unexpected response: {$data}");
    return $data;
  }

  /** コマンド送信 + OKコード確認 */
  public function cmd(string $command, array $okCodes): string {
    $this->write($command);
    return $this->expect($okCodes);
  }

  /** STARTTLS開始（暗号化） */
  public function startTLS(): void {
    $this->cmd('STARTTLS', [220]);
    $cryptoOk = stream_socket_enable_crypto($this->fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
    if ($cryptoOk !== true) throw new Exception("STARTTLS failed");
  }

  /** AUTH LOGIN 認証 */
  public function authLogin(string $user, string $pass): void {
    $this->cmd('AUTH LOGIN', [334]);
    $this->cmd(base64_encode($user), [334]);
    $this->cmd(base64_encode($pass), [235]);
  }

  public function mailFrom(string $from): void { $this->cmd("MAIL FROM:<{$from}>", [250]); }
  public function rcptTo(string $to): void { $this->cmd("RCPT TO:<{$to}>", [250, 251]); }

  /**
   * DATA本文送信
   * - 先頭が . の行は SMTP仕様により .. にエスケープする
   */
  public function data(string $raw): void {
    $this->cmd('DATA', [354]);
    $lines = preg_split("/\r\n|\n|\r/", $raw);
    foreach ($lines as $ln) {
      if (isset($ln[0]) && $ln[0] === '.') $ln = '.' . $ln;
      fwrite($this->fp, $ln . "\r\n");
    }
    fwrite($this->fp, ".\r\n");
    $this->expect([250]);
  }

  /** 終了 */
  public function quit(): void {
    if (is_resource($this->fp)) {
      $this->cmd('QUIT', [221]);
      fclose($this->fp);
    }
  }
}

/** 件名のRFC2047エンコード（UTF-8対応） */
function rfc2047(string $text): string {
  return '=?UTF-8?B?' . base64_encode($text) . '?=';
}

/**
 * Gmail SMTPで送信
 * - Reply-To を必要に応じて付与
 * - 戻り値：成功/失敗（bool）
 */
function sendViaGmailSMTP(string $to, string $subject, string $body, string $replyTo = ''): bool {
  $from = GMAIL_USER;

  $headers = [];
  $headers[] = 'From: <' . $from . '>';
  if ($replyTo !== '') $headers[] = 'Reply-To: ' . $replyTo;
  $headers[] = 'MIME-Version: 1.0';
  $headers[] = 'Content-Type: text/plain; charset=UTF-8';
  $headers[] = 'Content-Transfer-Encoding: 8bit';

  $raw  = 'To: <' . $to . ">\r\n";
  $raw .= 'From: <' . $from . ">\r\n";
  $raw .= 'Subject: ' . rfc2047($subject) . "\r\n";
  $raw .= implode("\r\n", $headers) . "\r\n\r\n";
  $raw .= $body;

  $smtp = new SimpleSMTP(GMAIL_HOST, GMAIL_PORT);
  try {
    $smtp->connect();
    $smtp->cmd('EHLO localhost', [250]);
    $smtp->startTLS();
    $smtp->cmd('EHLO localhost', [250]);
    $smtp->authLogin(GMAIL_USER, GMAIL_APP_PASS);

    $smtp->mailFrom(GMAIL_USER);
    $smtp->rcptTo($to);
    $smtp->data($raw);

    $smtp->quit();
    return true;
  } catch (Throwable $e) {
    try { $smtp->quit(); } catch (Throwable $ignore) {}
    return false;
  }
}

/* =========================================================
   mb_send_mail（サーバーが対応している場合の送信）
   ========================================================= */
/**
 * mb_send_mailで送信
 * - Fromは固定（FROM_ADDR）
 * - Reply-To は指定があれば優先
 */
function sendViaMbSendMail(string $to, string $subject, string $body, string $replyTo = ''): bool {
  mb_language('uni');
  mb_internal_encoding('UTF-8');

  $encodedSubject = mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");

  $from = FROM_ADDR;
  $rt   = $replyTo !== '' ? $replyTo : $from;

  $headers = [];
  $headers[] = "From: {$from}";
  $headers[] = "Reply-To: {$rt}";
  $headers[] = "MIME-Version: 1.0";
  $headers[] = "Content-Type: text/plain; charset=UTF-8";
  $headers[] = "Content-Transfer-Encoding: 8bit";

  $ok = mb_send_mail($to, $encodedSubject, $body, implode("\r\n", $headers));
  error_log("mb_send_mail result=" . ($ok ? "true" : "false") . " to={$to} from={$from} replyTo={$rt}");
  return $ok;
}

/**
 * 送信方式を自動選択
 *  - mb_send_mail が使えるなら先に試す
 *  - 失敗したら Gmail SMTP にフォールバック
 *
 * 戻り値： [成功(bool), 使用方式(string)]
 */
function sendMailAuto(string $to, string $subject, string $body, string $replyTo = ''): array {
  if (function_exists('mb_send_mail')) {
    $ok = sendViaMbSendMail($to, $subject, $body, $replyTo);
    if ($ok) return [true, 'mb_send_mail'];
  }
  $ok = sendViaGmailSMTP($to, $subject, $body, $replyTo);
  return [$ok, 'gmail_smtp'];
}

/**
 * 画面表示用：空なら（未入力）を出す
 * - それ以外はエスケープして返す
 */
function showOrEmpty(string $v, string $emptyText): string {
  $v = trim($v);
  return $v === '' ? '<span class="is-empty">'.h($emptyText).'</span>' : h($v);
}

/* =========================================================
   STATE / INPUTS（状態と入力値）
   ========================================================= */
$lang = detectLang();
$L    = langPack($lang);

// 二重送信防止トークン（初回だけ生成）
if (empty($_SESSION['contact_token'])) {
  $_SESSION['contact_token'] = bin2hex(random_bytes(16));
}
$sessionToken = (string)$_SESSION['contact_token'];

$action    = (string)($_POST['action'] ?? '');
$finalSend = (($_POST['eweb_set'] ?? '') === 'eweb_submit');

// 入力値（POST）
$name   = trim((string)($_POST['お名前'] ?? ''));
$kana   = trim((string)($_POST['フリガナ'] ?? ''));
$phone  = trim((string)($_POST['電話番号'] ?? ''));
$email  = trim((string)($_POST['email'] ?? ''));
$email2 = trim((string)($_POST['email2'] ?? ''));
$msg    = trim((string)($_POST['お問い合わせ内容'] ?? ''));

$step   = 'step1';
$errors = [];

/**
 * ステップ判定
 * - GET done=1 のときは完了画面（STEP3）
 * - GETのみは入力画面（STEP1）
 * - POSTは back / confirm / send を判定
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (string)($_GET['done'] ?? '') === '1') {
  $step = 'step3';
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $step = 'step1';
} else {
  // POST
  if ($action === 'back') {
    $step = 'step1';
  } else {
    // 必須チェック（$esseが1なら実施）
    if (!empty($esse)) {
      $errors = validateInput($L, $name, $email, $email2, $msg);
    } else {
      $errors = [];
    }

    // 確認画面を無効にする場合（$chmail=0）は、確認を飛ばして送信扱いにする
    if (empty($chmail)) {
      $finalSend = true;
    }

    $step = ($finalSend && empty($errors)) ? 'step3' : 'step2';
  }
}

/* =========================================================
   STEP3: SEND（POST時のみ / 1回だけ送る）
   ========================================================= */
if ($step === 'step3' && $_SERVER['REQUEST_METHOD'] === 'POST') {

  // 1) トークン検証（二重送信防止）
  $postedToken = (string)($_POST['token'] ?? '');
  if (!hash_equals($sessionToken, $postedToken)) {
    $errors = [$L['send_fail']];
    $step   = 'step2';
  } else {

    // 2) トークン無効化（次回は新トークンを使う）
    $_SESSION['contact_token'] = bin2hex(random_bytes(16));

    // 3) レートリミット（IP単位）
    $ip = getenv('REMOTE_ADDR') ?: ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $rateKey = sys_get_temp_dir() . '/contact_rate_' . preg_replace('/[^a-z0-9\._-]/i', '_', $ip) . '.txt';
    $now  = time();
    $last = (int)@file_get_contents($rateKey);

    if ($last > 0 && ($now - $last) < RATE_LIMIT_SECONDS) {
      $errors = [$L['too_fast']];
      $step   = 'step2';
    } else {

      @file_put_contents($rateKey, (string)$now);

      // 宛先/From（旧仕様に合わせる）
      $to       = ADMIN_TO;
      $fromAddr = FROM_ADDR;

      // 件名（言語パック）
      $sbj   = $L['subject'];
      $resbj = $L['subject'];

      // 送信フラグ（旧ファイル互換）
      $sendm = 1;

      // 逆引きホスト名（ログ用）
      $host = getHostByAddr($ip);

      // 管理者向け本文
      
      $body  = $sbj . "\n\n";
      $body .= ($L['reply_intro'] ?? '') . "\n";
      $body="お問い合わせ【ALEX】\n\n";
      $body.="以下の内容でお問合せを受け付けました。\n\n";
      $body .= "-------------------------------------------------\n\n";
      $body .= "【{$L['labels']['name']}】\n" . $name  . "\n\n";
      $body .= "【{$L['labels']['kana']}】\n" . $kana  . "\n\n";
      $body .= "【{$L['labels']['phone']}】\n" . $phone . "\n\n";
      $body .= "【{$L['labels']['email']}】\n" . $email . "\n\n";
      $body .= "【{$L['labels']['msg']}】\n"  . $msg   . "\n";
      $body .= "\n-------------------------------------------------\n\n";
      $body .= "送信日時：" . date("Y/m/d (D) H:i:s", time()) . "\n";
      $body .= "ホスト名：" . $host . "\n\n";

      // 自動返信（ユーザー向け）
      if ($remail == 1) {

        $rebody  = $resbj . "\n\n";
        $rebody .= ($L['reply_intro'] ?? '') . "\n";
        $rebody="お問い合わせ【ALEX】\n\n";
        $rebody.="以下の内容でお問合せを受け付けました。\n\n";
        $rebody .= "-------------------------------------------------\n\n";
        $rebody .= "【{$L['labels']['name']}】\n" . $name  . "\n\n";
        $rebody .= "【{$L['labels']['kana']}】\n" . $kana  . "\n\n";
        $rebody .= "【{$L['labels']['phone']}】\n" . $phone . "\n\n";
        $rebody .= "【{$L['labels']['email']}】\n" . $email . "\n\n";
        $rebody .= "【{$L['labels']['msg']}】\n"  . $msg   . "\n";
        $rebody .= "\n-------------------------------------------------\n\n";
        $rebody .= $L['company_sig'] . "\n";
        $rebody.="株式会社アレックス(ALEX)\n";
        $rebody.="〒114-0024　東京都北区西ケ原1-46-13　横河駒込ビル1F\n";
        $rebody.="TEL 03-5972-1888\n";
        $rebody.="FAX 03-5972-1890\n";
        $rebody.="\n-------------------------------------------------\n\n";
        $rebody.="※なお、このメールはシステムにより自動配信されています。\n";
        $rebody.="本メールに見覚えのない方は、下記アドレスまでご返信ください。\n";
        $rebody.="alex@alex7.co.jp\n";

        $reto = $email;
        $reheader = "From: {$to}\nReply-To: {$to}\n"; // 互換用（実際は sendMailAuto 側でヘッダ生成）
      }

      // Reply-To 制御（旧仕様互換）
      if ($from_add == 1) {
        $from   = $email;
        $header = "From: {$from}\nReply-To: {$email}\n";
      } else {
        $header = "Reply-To: {$email}\n";
      }

      // 旧仕様互換（未使用）
      $headers = "From:{$fromAddr}";

      // 送信（この3ステップフローでは基本ここに入る）
      if ($chmail == 0 || $sendm == 1) {

        // 管理者メール：ユーザーのアドレスを Reply-To にしたい場合のみ設定
        $adminReplyTo = ($from_add == 1) ? $email : '';

        [$okAdmin, $methodAdmin] = sendMailAuto($to, $sbj, $body, $adminReplyTo);
        error_log("Contact send admin method={$methodAdmin} ok=" . ($okAdmin ? "true" : "false"));

        if (!$okAdmin) {
          $errors = [$L['send_fail']];
          $step   = 'step2';
        } else {

          // 自動返信（失敗しても admin が受け取れていればOK）
          if ($remail == 1 && !empty($reto)) {
            [$okUser, $methodUser] = sendMailAuto($reto, $resbj, $rebody, $to);
            error_log("Contact send user method={$methodUser} ok=" . ($okUser ? "true" : "false"));
          }

          // PRG：POSTの後にGETへリダイレクトして二重送信を防止
          header('Location: /contact_form.php?lang=' . rawurlencode($lang) . '&done=1');
          exit;
        }

      } else {
        // 3ステップ設計上、基本ここには入らない
      }
    }
  }
}

?>
<!doctype html>
<html lang="<?php echo h($lang); ?>">
<head>
  <meta charset="utf-8">

  <meta http-equiv="Content-Security-Policy" content="
    default-src 'self';
    base-uri 'self';
    object-src 'none';
    form-action 'self';

    script-src 'self' https://code.jquery.com https://www.googletagmanager.com;
    style-src  'self' https://fonts.googleapis.com;
    font-src   'self' https://fonts.gstatic.com data:;
    img-src    'self' data: blob:;
    connect-src 'self' https://www.google-analytics.com https://www.googletagmanager.com;
    frame-src https://www.googletagmanager.com;
  ">

  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <title><?php echo h($L['page_title']); ?></title>

  <!-- GTM（ローカル読み込み：CSPでinlineを避ける） -->
  <script src="/js/gtm.js"></script>

  <!-- CSS -->
  <link rel="stylesheet" href="/css/site-CmZlzcU5.css">
  <link rel="stylesheet" href="/css/fonts.css">
  <link rel="stylesheet" href="/assets/fontawesome/css/all.min.css">
  <link rel="stylesheet" href="/css/bootstrap.css">
  <link rel="stylesheet" href="/css/site.css">
  <link rel="stylesheet" href="/css/contact-page.css">
</head>

<body class="bg-slate-100 dark:bg-gray-800 font-sans leading-normal text-slate-800 dark:text-gray-400">
<div id="page" class="site">

  <!-- モバイルナビ -->
  <nav id="mobilenav">
    <div class="mobilenav__inner">
      <div class="toplg">
        <div class="logo">
          <a class="logo-link" href="<?php echo h(urlLang($lang, '/index.html')); ?>">
            <img src="/image/_01__01_alex.webp" width="194" height="68" alt="Logo">
          </a>
        </div>
      </div>

      <div class="menu-primary-menu-ja-container">
        <ul id="menu-main" class="mobile-menu">
          <li class="menu-item"><a class="rd-nav-link" href="<?php echo h(urlLang($lang, '/index.html')); ?>"><?php echo h($L['nav']['top']); ?></a></li>

          <li class="menu-item menu-item-has-children">
            <a class="rd-nav-link" href="<?php echo h(urlLang($lang, '/about/index.html')); ?>"><?php echo h($L['nav']['about']); ?></a>
            <ul class="sub-menu">
              <li><a href="<?php echo h(urlLang($lang, '/about/index.html#message')); ?>"><?php echo h($L['nav']['about_message']); ?></a></li>
              <li><a href="<?php echo h(urlLang($lang, '/about/index.html#company')); ?>"><?php echo h($L['nav']['about_company']); ?></a></li>
              <li><a href="<?php echo h(urlLang($lang, '/about/index.html#history')); ?>"><?php echo h($L['nav']['about_history']); ?></a></li>
              <li><a href="<?php echo h(urlLang($lang, '/about/index.html#org')); ?>"><?php echo h($L['nav']['about_org']); ?></a></li>
              <li><a href="<?php echo h(urlLang($lang, '/about/index.html#clients')); ?>"><?php echo h($L['nav']['about_clients']); ?></a></li>
            </ul>
          </li>

          <li class="menu-item menu-item-has-children">
            <a class="rd-nav-link" href="<?php echo h(urlLang($lang, '/service/index.html')); ?>"><?php echo h($L['nav']['service']); ?></a>
            <ul class="sub-menu">
              <li><a href="<?php echo h(urlLang($lang, '/service/index.html#movie')); ?>"><?php echo h($L['nav']['service_movie']); ?></a></li>
              <li><a href="<?php echo h(urlLang($lang, '/service/index.html#dev')); ?>"><?php echo h($L['nav']['service_dev']); ?></a></li>
              <li><a href="<?php echo h(urlLang($lang, '/service/index.html#system')); ?>"><?php echo h($L['nav']['service_system']); ?></a></li>
              <li><a href="<?php echo h(urlLang($lang, '/service/index.html#plan')); ?>"><?php echo h($L['nav']['service_plan']); ?></a></li>
            </ul>
          </li>

          <li class="menu-item"><a class="rd-nav-link" href="<?php echo h(urlLang($lang, '/works/index.html')); ?>"><?php echo h($L['nav']['works']); ?></a></li>

          <li class="menu-item menu-item-has-children">
            <a class="rd-nav-link" href="<?php echo h(urlLang($lang, '/recruit/index.html')); ?>"><?php echo h($L['nav']['recruit']); ?></a>
            <ul class="sub-menu">
              <li><a href="<?php echo h(urlLang($lang, '/recruit/index.html')); ?>"><?php echo h($L['nav']['recruit_jobs']); ?></a></li>
              <li><a href="<?php echo h(urlLang($lang, '/comments-top/index.html')); ?>"><?php echo h($L['nav']['recruit_voices']); ?></a></li>
            </ul>
          </li>

          <li class="menu-item"><a class="rd-nav-link" href="<?php echo h(urlLang($lang, '/access/index.html')); ?>"><?php echo h($L['nav']['access']); ?></a></li>

          <!-- CONTACT：常に contact_form.php（言語は?langで保持） -->
          <li class="menu-item"><a class="rd-nav-link" href="<?php echo h(flagLink($lang)); ?>"><?php echo h($L['nav']['contact']); ?></a></li>
        </ul>
      </div>

      <a class="menu_close"><i class="fas fa-angle-left"></i></a>
    </div>
  </nav>

  <!-- HEADER -->
  <header id="masthead" role="banner" itemscope itemtype="http://schema.org/WPHeader">
    <div class="header-main">
      <div class="container">
        <div class="header-content">
          <a id="showmenu" class="d-dropdown">
            <span class="hamburger hamburger--collapse">
              <span class="hamburger-box"><span class="hamburger-inner"></span></span>
            </span>
          </a>

          <div class="row align-items-center">
            <div class="col-xl-2 col-lg-3 col-4">
              <div class="logo">
                <a class="logo-link" href="<?php echo h(urlLang($lang, '/index.html')); ?>">
                  <img src="/image/_01__01_alex.webp" width="194" height="68" alt="Logo">
                </a>
              </div>
            </div>

            <div class="col-xl-10 col-lg-9 col-8">
              <div class="top-menu">

                <!-- 言語フラグ（JSなし） -->
                <div class="top-language">
                  <section id="polylang-2" class="widget widget_polylang">
                    <ul>
                      <li class="lang-item lang-item-ja <?php echo $lang==='ja'?'is-active':''; ?>">
                        <a class="lang-btn" href="<?php echo h(flagLink('ja')); ?>" aria-current="<?php echo $lang==='ja'?'true':'false'; ?>">
                          <img src="/image/jp-image.webp" alt="日本語" width="16" height="11">
                        </a>
                      </li>
                      <li class="lang-item lang-item-vi <?php echo $lang==='vi'?'is-active':''; ?>">
                        <a class="lang-btn" href="<?php echo h(flagLink('vi')); ?>" aria-current="<?php echo $lang==='vi'?'true':'false'; ?>">
                          <img src="/image/vn-image.webp" alt="Tiếng Việt" width="16" height="11">
                        </a>
                      </li>
                      <li class="lang-item lang-item-en <?php echo $lang==='en'?'is-active':''; ?>">
                        <a class="lang-btn" href="<?php echo h(flagLink('en')); ?>" aria-current="<?php echo $lang==='en'?'true':'false'; ?>">
                          <img src="/image/en-image.webp" alt="English" width="16" height="11">
                        </a>
                      </li>
                    </ul>
                  </section>
                </div>

                <!-- メインナビ -->
                <nav id="site-navigation" class="main-navigation" itemscope itemtype="https://schema.org/SiteNavigationElement">
                  <div class="menu-primary-menu-ja-container">
                    <ul id="primary-menu" class="menu clearfix">

                      <li class="menu-item"><a class="rd-nav-link" href="<?php echo h(urlLang($lang, '/index.html')); ?>"><?php echo h($L['nav']['top']); ?></a></li>

                      <li class="menu-item menu-item-has-children">
                        <a class="rd-nav-link" href="<?php echo h(urlLang($lang, '/about/index.html')); ?>"><?php echo h($L['nav']['about']); ?></a>
                        <ul class="sub-menu">
                          <li><a href="<?php echo h(urlLang($lang, '/about/index.html#message')); ?>"><?php echo h($L['nav']['about_message']); ?></a></li>
                          <li><a href="<?php echo h(urlLang($lang, '/about/index.html#company')); ?>"><?php echo h($L['nav']['about_company']); ?></a></li>
                          <li><a href="<?php echo h(urlLang($lang, '/about/index.html#history')); ?>"><?php echo h($L['nav']['about_history']); ?></a></li>
                          <li><a href="<?php echo h(urlLang($lang, '/about/index.html#org')); ?>"><?php echo h($L['nav']['about_org']); ?></a></li>
                          <li><a href="<?php echo h(urlLang($lang, '/about/index.html#clients')); ?>"><?php echo h($L['nav']['about_clients']); ?></a></li>
                        </ul>
                      </li>

                      <li class="menu-item menu-item-has-children">
                        <a class="rd-nav-link" href="<?php echo h(urlLang($lang, '/service/index.html')); ?>"><?php echo h($L['nav']['service']); ?></a>
                        <ul class="sub-menu">
                          <li><a href="<?php echo h(urlLang($lang, '/service/index.html#movie')); ?>"><?php echo h($L['nav']['service_movie']); ?></a></li>
                          <li><a href="<?php echo h(urlLang($lang, '/service/index.html#dev')); ?>"><?php echo h($L['nav']['service_dev']); ?></a></li>
                          <li><a href="<?php echo h(urlLang($lang, '/service/index.html#system')); ?>"><?php echo h($L['nav']['service_system']); ?></a></li>
                          <li><a href="<?php echo h(urlLang($lang, '/service/index.html#plan')); ?>"><?php echo h($L['nav']['service_plan']); ?></a></li>
                        </ul>
                      </li>

                      <li class="menu-item"><a class="rd-nav-link" href="<?php echo h(urlLang($lang, '/works/index.html')); ?>"><?php echo h($L['nav']['works']); ?></a></li>

                      <li class="menu-item menu-item-has-children">
                        <a class="rd-nav-link" href="<?php echo h(urlLang($lang, '/recruit/index.html')); ?>"><?php echo h($L['nav']['recruit']); ?></a>
                        <ul class="sub-menu">
                          <li><a href="<?php echo h(urlLang($lang, '/recruit/index.html')); ?>"><?php echo h($L['nav']['recruit_jobs']); ?></a></li>
                          <li><a href="<?php echo h(urlLang($lang, '/comments-top/index.html')); ?>"><?php echo h($L['nav']['recruit_voices']); ?></a></li>
                        </ul>
                      </li>

                      <li class="menu-item"><a class="rd-nav-link" href="<?php echo h(urlLang($lang, '/access/index.html')); ?>"><?php echo h($L['nav']['access']); ?></a></li>

                      <!-- CONTACT：常に contact_form.php -->
                      <li class="menu-item"><a class="rd-nav-link" href="<?php echo h(flagLink($lang)); ?>"><?php echo h($L['nav']['contact']); ?></a></li>

                    </ul>
                  </div>
                </nav>

              </div>
            </div>

          </div>
        </div>
      </div>
    </div>
  </header>

  <!-- HERO -->
  <section class="about">
    <div class="about-avatar">
      <div class="about-bg bg1"></div>
      <div class="about-bg bg2"></div>

      <div class="overlay-contact">
        <div class="container-contact-text">
          <div class="contact-left-text"><h2></h2></div>
          <div class="contact-right-text">
            <span class="contact-company-info"><?php echo h($L['page_title']); ?></span>
            <h1 class="contact-heading-info"><?php echo h($L['nav']['contact']); ?></h1>
          </div>
        </div>
      </div>
    </div>
  </section>

  <nav class="submenu-tabs-4"></nav>

  <section class="recruit-heading">
    <video class="recruit-bg-video" autoplay muted loop playsinline preload="auto">
      <source src="/assets/共通_00_最下層レイヤー_背景動画.mp4" type="video/mp4">
    </video>

    <div class="container video-inner">
      <div class="contact-block-wrapper" id="main_contents">

        <p class="contact-intro"><?php echo h($L['intro']); ?></p>

        <!-- ステップ表示（言語対応） -->
        <div id="position_box">
          <ul>
            <li class="<?php echo ($step === 'step1') ? 'active' : ''; ?>">
              <span class="step-label-1"><?php echo h($L['steps']['s1']); ?></span> →
            </li>
            <li class="<?php echo ($step === 'step2') ? 'active' : ''; ?>">
              <span class="step-label-2"><?php echo h($L['steps']['s2']); ?></span> →
            </li>
            <li class="<?php echo ($step === 'step3') ? 'active' : ''; ?>">
              <span class="step-label-3"><?php echo h($L['steps']['s3']); ?></span>
            </li>
          </ul>
        </div>

        <div id="form_box" class="line-contact">

          <?php if ($step === 'step1'): ?>
            <form action="/contact_form.php?lang=<?php echo h($lang); ?>" method="post" name="applyform" id="applyform">
              <input type="hidden" name="lang" value="<?php echo h($lang); ?>">
              <input type="hidden" name="token" value="<?php echo h((string)($_SESSION['contact_token'] ?? '')); ?>">

              <table>
                <tbody>

                <tr>
                  <th class="th-name">
                    <span class="label-name"><?php echo h($L['labels']['name']); ?></span>
                    <span class="required req-name"><?php echo h($L['req_badge']); ?></span>
                  </th>
                  <td>
                    <input name="お名前" type="text" size="30"
                           placeholder="<?php echo h($L['placeholders']['name']); ?>"
                           value="<?php echo h($name); ?>">
                  </td>
                </tr>

                <tr>
                  <th class="th-kana"><span class="label-kana"><?php echo h($L['labels']['kana']); ?></span></th>
                  <td>
                    <input name="フリガナ" type="text" size="30"
                           placeholder="<?php echo h($L['placeholders']['kana']); ?>"
                           value="<?php echo h($kana); ?>">
                  </td>
                </tr>

                <tr>
                  <th class="th-phone"><span class="label-phone"><?php echo h($L['labels']['phone']); ?></span></th>
                  <td>
                    <input name="電話番号" type="text"
                           placeholder="<?php echo h($L['placeholders']['phone']); ?>"
                           value="<?php echo h($phone); ?>">
                  </td>
                </tr>

                <tr>
                  <th class="th-email">
                    <span class="label-email"><?php echo h($L['labels']['email']); ?></span>
                    <span class="required req-email"><?php echo h($L['req_badge']); ?></span>
                  </th>
                  <td>
                    <input name="email" type="text" autocomplete="email"
                           placeholder="<?php echo h($L['placeholders']['email']); ?>"
                           value="<?php echo h($email); ?>">

                    <div class="plus_txt email-note">
                      <?php echo h($L['mail_domain_note']); ?>
                    </div>
                  </td>
                </tr>

                <tr>
                  <th class="th-email2">
                    <span class="label-email2"><?php echo h($L['labels']['email2']); ?></span>
                    <span class="required req-email2"><?php echo h($L['req_badge']); ?></span>
                  </th>
                  <td>
                    <input name="email2" type="text" autocomplete="email"
                           placeholder="<?php echo h($L['placeholders']['email2']); ?>"
                           value="<?php echo h($email2); ?>">
                  </td>
                </tr>

                <tr>
                  <th class="th-body">
                    <span class="label-message"><?php echo h($L['labels']['msg']); ?></span>
                    <span class="required req-body"><?php echo h($L['req_badge']); ?></span>
                  </th>
                  <td>
                    <textarea name="お問い合わせ内容" id="comment"
                              placeholder="<?php echo h($L['placeholders']['msg']); ?>"><?php echo h($msg); ?></textarea>
                  </td>
                </tr>

                </tbody>
              </table>

              <p id="sub_btn">
                <button type="submit" class="contact-detail-btn submit">
                  <?php echo h($L['btn_confirm']); ?>
                </button>
              </p>
            </form>

          <?php elseif ($step === 'step2'): ?>
            <div class="submittable">
              <p class="confirm-intro"><?php echo h($L['confirm_intro']); ?></p>

              <?php if (!empty($errors)): ?>
                <div class="errm">
                  <h4 class="err-title"><?php echo h($L['missing']); ?></h4>
                  <div class="err-lines">
                    <?php foreach ($errors as $e): ?>
                      <div class="err-line-text"><?php echo h($e); ?></div>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endif; ?>

              <form action="/contact_form.php?lang=<?php echo h($lang); ?>" method="POST">
                <input type="hidden" name="lang" value="<?php echo h($lang); ?>">
                <input type="hidden" name="token" value="<?php echo h((string)($_SESSION['contact_token'] ?? '')); ?>">

                <table cellpadding="0" cellspacing="0" class="confirm_table">
                  <tbody>
                  <tr>
                    <th class="label-name"><?php echo h($L['labels']['name']); ?></th>
                    <td>
                      <?php echo showOrEmpty($name, $L['empty_text']); ?>
                      <input type="hidden" name="お名前" value="<?php echo h($name); ?>">
                    </td>
                  </tr>
                  <tr>
                    <th class="label-kana"><?php echo h($L['labels']['kana']); ?></th>
                    <td>
                      <?php echo showOrEmpty($kana, $L['empty_text']); ?>
                      <input type="hidden" name="フリガナ" value="<?php echo h($kana); ?>">
                    </td>
                  </tr>
                  <tr>
                    <th class="label-phone"><?php echo h($L['labels']['phone']); ?></th>
                    <td>
                      <?php echo showOrEmpty($phone, $L['empty_text']); ?>
                      <input type="hidden" name="電話番号" value="<?php echo h($phone); ?>">
                    </td>
                  </tr>
                  <tr>
                    <th class="label-email"><?php echo h($L['labels']['email']); ?></th>
                    <td>
                      <?php echo showOrEmpty($email, $L['empty_text']); ?>
                      <input type="hidden" name="email" value="<?php echo h($email); ?>">
                    </td>
                  </tr>
                  <tr>
                    <th class="label-message"><?php echo h($L['labels']['msg']); ?></th>
                    <td>
                      <?php
                        $msgShow = trim($msg) === '' ? '<span class="is-empty">'.h($L['empty_text']).'</span>' : nl2br(h($msg));
                        echo $msgShow;
                      ?>
                      <input type="hidden" name="お問い合わせ内容" value="<?php echo h($msg); ?>">
                    </td>
                  </tr>
                  </tbody>
                </table>

                <div class="hide">
                  <input type="hidden" name="email2" value="<?php echo h($email2); ?>">
                </div>

                <div id="confirm_btn">
                  <button type="submit" name="action" value="back" class="h_back contact-detail-btn">
                    <?php echo h($L['btn_back']); ?>
                  </button>

                  <?php if (empty($errors)): ?>
                    <input type="hidden" name="eweb_set" value="eweb_submit">
                    <button type="submit" name="submit-a" class="submit-a confirm-detail-btn">
                      <?php echo h($L['btn_send']); ?>
                    </button>
                  <?php endif; ?>
                </div>
              </form>
            </div>

          <?php else: ?>
            <p class="thanks-text"><?php echo $L['thanks_html']; ?></p>
          <?php endif; ?>

        </div>
      </div>
    </div>
  </section>

  <div class="footer-main">
    <footer class="site-footer" role="contentinfo">
      <div class="site-footer__inner">
        <small class="site-footer__copy">
          Copyright © <span id="footerYear"></span> ALEX CO., LTD. All Rights Reserved
        </small>
      </div>
    </footer>
  </div>

</div>

<!-- JS：このページでは lang.js を読み込まない（?lang= を上書きしないため） -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="/js/script.js"></script>
<script src="/js/background-intro.js"></script>
<script src="/js/footer-year.js" defer></script>
</body>
</html>
