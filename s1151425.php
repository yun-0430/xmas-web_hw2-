<?php
/* 除錯用：想看表單到底送了什麼，把下面兩行的註解拿掉就好
echo '<pre>'; print_r($_POST); echo '</pre>'; exit;
*/

require_once('../TCPDF/tcpdf_import.php');

/*---------------- 1. 讀取表單資料 -----------------*/
// 直接寫 $_POST['xxx']，欄位沒送來時會出現 Warning，而且會讓 PDF 壞掉
// ?? '' 的意思是：有值就用，沒有就當成空字串；trim() 去掉前後空白
function post($key) {
    return trim($_POST[$key] ?? '');
}

// 把使用者輸入的文字變成「純文字」，避免有人輸入 <b>、<script> 之類的 HTML 搞亂版面
function h($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

$sender_email   = post('sender_email');
$mode           = post('mode');            // named 或 anonymous
$sender_name    = post('sender_name');
$sender_phone   = post('sender_phone');
$receiver_email = post('receiver_email');
$receiver_name  = post('receiver_name');
$card           = post('card');            // red / blue / green / yellow
$message        = post('message');
$agree          = post('agree');           // 有勾是 "on"，沒勾就是空字串

// 卡片款式 → 中文名稱和顏色（跟 xmas.css 裡的 .card.red 等顏色一樣）
$cards = array(
    'red'    => array('紅色款', '#c62828'),
    'blue'   => array('藍色款', '#1565c0'),
    'green'  => array('綠色款', '#388e3c'),
    'yellow' => array('黃色款', '#ffc107'),
);
if (!isset($cards[$card])) {
    $card = 'red';                         // 沒選或亂填 → 預設紅色
}


/*---------------- 2. 後端檢查 -----------------*/
// HTML 的 required、pattern 和 JS 都可以被繞過，伺服器這邊一定要再檢查一次
$errors = array();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $errors[] = '請從報名表單送出。';
}
if (!preg_match('/^[^@\s]+@mail\.yzu\.edu\.tw$/', $sender_email)) {
    $errors[] = '你的校園信箱格式不正確。';
}
if (!preg_match('/^[^@\s]+@mail\.yzu\.edu\.tw$/', $receiver_email)) {
    $errors[] = '收件人校園信箱格式不正確。';
}
if ($mode !== 'named' && $mode !== 'anonymous') {
    $errors[] = '請選擇寄送方式。';
}
if ($mode === 'named' && $sender_name === '') {
    $errors[] = '具名寄送時，姓名或暱稱必填。';
}
if ($mode === 'named' && $sender_phone === '') {
    $errors[] = '具名寄送時，聯絡電話必填。';
}
if ($sender_phone !== '' && !preg_match('/^09\d{8}$/', $sender_phone)) {
    $errors[] = '聯絡電話請輸入 09 開頭的 10 碼手機號碼。';
}
if ($message === '') {
    $errors[] = '請填寫祝福內容。';
} elseif (mb_strlen($message, 'UTF-8') > 300) {   // 中文要用 mb_strlen，strlen 會把一個中文字算成 3
    $errors[] = '祝福內容不能超過 300 字。';
}
if ($agree === '') {
    $errors[] = '請勾選同意活動規範與個資聲明。';
}

// 有錯誤就顯示錯誤頁，不要往下產生 PDF
if (count($errors) > 0) {
    echo '<!DOCTYPE html><html lang="zh-Hant-TW"><head><meta charset="UTF-8"><title>填寫有誤</title></head><body>';
    echo '<h2>表單有以下問題：</h2><ul>';
    foreach ($errors as $e) {
        echo '<li>' . h($e) . '</li>';
    }
    echo '</ul><p><a href="javascript:history.back()">回上一頁修改</a></p></body></html>';
    exit;
}


/*---------------- Print PDF Start -----------------*/
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetFont('cid0jp', '', 12);
$pdf->AddPage();

// heredoc（<<<EOF）裡面只能放變數、不能呼叫函式，所以先把要印的內容準備好
// 沒填的欄位顯示「（未填寫）」，表格才不會空一格看起來像漏印
function show($text) {
    return ($text === '') ? '<span color="#999999">（未填寫）</span>' : h($text);
}

date_default_timezone_set('Asia/Taipei');      // 不設的話，時間可能會差 8 小時
$time      = date('Y/m/d H:i');
$number    = 'XMAS' . date('YmdHis');          // 簡單的確認單編號：用送出時間組成

$s_name    = show($sender_name);
$s_email   = h($sender_email);
$s_phone   = show($sender_phone);
$mode_text = ($mode === 'named') ? '具名寄送（收件人會看到你的姓名）' : '匿名寄送（收件人看不到你的姓名）';
$r_email   = h($receiver_email);
$r_name    = show($receiver_name);
$card_name = $cards[$card][0];
$color     = $cards[$card][1];
$text      = nl2br(h($message));               // 保留使用者打的換行
$length    = mb_strlen($message, 'UTF-8');

// 表格樣式：th 是左邊的欄位名稱，.section 是每一區的標題列
$html = <<<EOF
<style>
    h1 { color: #5e5e5e; text-align: center; font-size: 20pt; }
    .info { text-align: center; color: #666666; font-size: 10pt; }
    table { border-collapse: collapse; }
    th { background-color: #f3f3f3; font-weight: bold; width: 28%; }
    td { width: 72%; }
    .section { background-color: #5e5e5e; color: #ffffff; font-weight: bold; width: 100%; }
</style>

<h1>聖誕傳情活動資料確認單</h1>
<p class="info">確認單編號：{$number}　　填寫時間：{$time}</p>

<table border="1" cellpadding="6">
    <tr><td class="section" colspan="2">一、參與人資料</td></tr>
    <tr><th>姓名／暱稱</th><td>{$s_name}</td></tr>
    <tr><th>校園信箱</th><td>{$s_email}</td></tr>
    <tr><th>聯絡電話</th><td>{$s_phone}</td></tr>
    <tr><th>寄送方式</th><td>{$mode_text}</td></tr>

    <tr><td class="section" colspan="2">二、寄送對象資料</td></tr>
    <tr><th>收件人稱呼</th><td>{$r_name}</td></tr>
    <tr><th>收件人信箱</th><td>{$r_email}</td></tr>

    <tr><td class="section" colspan="2">三、卡片內容</td></tr>
    <tr><th>卡片款式</th><td><span color="{$color}">■</span> {$card_name}</td></tr>
    <tr><th>祝福內容<br><span color="#999999">（共 {$length} 字）</span></th><td>{$text}</td></tr>
</table>

<p></p>
<table cellpadding="4">
    <tr><td style="width:100%; color:#666666; font-size:10pt;">
        ※ 請確認以上資料是否正確。卡片已於送出時同步寄到收件人信箱。<br>
        ※ 匿名寄送時，收件人不會看到你的姓名，但主辦單位仍會保留你的校園信箱，以便處理檢舉。<br>
        ※ 資料有誤請聯繫活動主辦單位修改。
    </td></tr>
</table>
EOF;
/*---------------- Print PDF End -------------------*/

$pdf->writeHTML($html); // 把剛剛那段 HTML 畫進 PDF
$pdf->lastPage();// 設定 PDF 的最後一頁，避免頁碼亂掉


/*---------------- Sent Mail Start -----------------*/
// 寄件人的確認信要附上 PDF，所以寄信放在 PDF 做好之後
$pdf_data = $pdf->Output('', 'S');   // 'S' = 不輸出到畫面，把 PDF 內容當成字串拿回來

// 寄件地址：學校伺服器若要求指定寄件人，填在這裡（例如老師提供的信箱）；空字串 = 用伺服器預設
$mail_from = '';

// 中文的信件標題、寄件人名稱要編碼，不然收件匣會看到亂碼
function mime_text($text) {
    return '=?UTF-8?B?' . base64_encode($text) . '?=';
}

// 寄一封 HTML 信，可以選擇附一個 PDF 檔
// 信件格式（MIME）：用一條「分隔線 boundary」把內文和附件隔開
function send_mail($to, $subject, $body_html, $mail_from, $pdf_name = '', $pdf_data = '') {
    $boundary = 'xmas_' . md5(uniqid());

    $headers  = "MIME-Version: 1.0\r\n";
    if ($mail_from !== '') {
        $headers .= 'From: ' . mime_text('聖誕傳情活動') . " <{$mail_from}>\r\n";
    }
    $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"";

    // 第 1 段：信件內文（HTML）
    $message  = "--{$boundary}\r\n"
              . "Content-Type: text/html; charset=UTF-8\r\n"
              . "Content-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($body_html)) . "\r\n";

    // 第 2 段：PDF 附件（有給才加）
    if ($pdf_data !== '') {
        $message .= "--{$boundary}\r\n"
                  . "Content-Type: application/pdf; name=\"{$pdf_name}\"\r\n"
                  . "Content-Transfer-Encoding: base64\r\n"
                  . "Content-Disposition: attachment; filename=\"{$pdf_name}\"\r\n\r\n"
                  . chunk_split(base64_encode($pdf_data)) . "\r\n";
    }
    $message .= "--{$boundary}--";

    // 前面加 @：寄信失敗時不要印出 Warning
    // （PDF 之前只要印出任何一個字，TCPDF 就會報錯「Some data has already been output」）
    return @mail($to, mime_text($subject), $message, $headers);
}

// ---- 信 1：寄給寄件人的確認信（附 PDF 確認單）----
$sender_body = <<<EOF
<p>你好：</p>
<p>我們已收到你寄給 <b>{$r_email}</b> 的聖誕卡片（{$card_name}），卡片已同步寄出。</p>
<p>附件是本次的活動資料確認單（編號 {$number}），請確認資料是否正確。</p>
<p>聖誕傳情活動 敬上</p>
EOF;
$sender_ok = send_mail($sender_email, '聖誕傳情：你的卡片已送出', $sender_body, $mail_from,
                       'xmas_confirm.pdf', $pdf_data);

// ---- 信 2：寄給收件人的聖誕卡片 ----
// 匿名寄送時，信裡完全不出現寄件人的姓名和信箱
$to_text   = ($receiver_name !== '') ? h($receiver_name) : '你';
$from_text = ($mode === 'named') ? h($sender_name) : '一位匿名的朋友';
$font      = ($card === 'yellow') ? '#333333' : '#ffffff';   // 黃色底配白字看不清楚，改深色字

$receiver_body = <<<EOF
<div style="max-width:480px; margin:0 auto; border:3px solid {$color}; border-radius:10px; overflow:hidden; font-family:sans-serif;">
    <div style="background-color:{$color}; color:{$font}; text-align:center; padding:20px; font-size:24px; font-weight:bold;">
        Merry Christmas
    </div>
    <div style="padding:20px; line-height:1.8; color:#333333;">
        <p>給 {$to_text}：</p>
        <p>{$text}</p>
        <p style="text-align:right;">—— {$from_text}</p>
    </div>
</div>
<p style="text-align:center; color:#999999; font-size:12px;">這封信來自聖誕傳情活動。收到讓你不舒服的卡片，請寄信至活動主辦方檢舉。</p>
EOF;
// 標題用 $sender_name 原文（不是 h() 過的），標題是純文字，不需要也不能放 HTML 跳脫碼
$subject_from = ($mode === 'named') ? $sender_name : '一位匿名的朋友';
$receiver_ok  = send_mail($receiver_email, "聖誕傳情：{$subject_from} 寄給你一張聖誕卡片", $receiver_body, $mail_from);
/*---------------- Sent Mail End -------------------*/

$pdf->Output('xmas_confirm.pdf', 'I');   // 'I' = 直接在瀏覽器裡顯示
