<?php
declare(strict_types=1);

/**
 * TOTP (RFC 6238) แบบ dependency-free สำหรับใช้กับแอปยืนยันตัวตน 2 ขั้นตอน
 * (Google Authenticator, Microsoft Authenticator, Authy ฯลฯ) — ไม่ต้องติดตั้งไลบรารีเพิ่ม
 */

function totp_generate_secret(int $bytes = 20): string
{
    return totp_base32_encode(random_bytes($bytes));
}

function totp_base32_encode(string $data): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($data) as $byte) {
        $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
    }
    $output = '';
    foreach (str_split($bits, 5) as $chunk) {
        if (strlen($chunk) < 5) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        }
        $output .= $alphabet[bindec($chunk)];
    }
    return $output;
}

function totp_base32_decode(string $secret): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret));
    $bits = '';
    foreach (str_split($secret) as $char) {
        $pos = strpos($alphabet, $char);
        if ($pos === false) {
            continue;
        }
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $bytes = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) < 8) {
            continue;
        }
        $bytes .= chr((int)bindec($byte));
    }
    return $bytes;
}

function totp_code_at(string $secret, int $timeStep): string
{
    $key = totp_base32_decode($secret);
    $time = pack('N*', 0) . pack('N*', $timeStep);
    $hash = hash_hmac('sha1', $time, $key, true);
    $offset = ord($hash[19]) & 0x0F;
    $part = ((ord($hash[$offset]) & 0x7F) << 24)
        | ((ord($hash[$offset + 1]) & 0xFF) << 16)
        | ((ord($hash[$offset + 2]) & 0xFF) << 8)
        | (ord($hash[$offset + 3]) & 0xFF);
    return str_pad((string)($part % 1000000), 6, '0', STR_PAD_LEFT);
}

/** ตรวจรหัส 6 หลักจากแอป โดยยอมรับช่วงเวลาก่อน/หลังปัจจุบัน ±1 ช่วง (30 วินาที) เผื่อนาฬิกาเหลื่อมกันเล็กน้อย */
function totp_verify_code(string $secret, string $code, int $window = 1): bool
{
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) {
        return false;
    }
    $currentStep = (int)floor(time() / 30);
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totp_code_at($secret, $currentStep + $i), $code)) {
            return true;
        }
    }
    return false;
}

function totp_provisioning_uri(string $secret, string $accountName, string $issuer = 'VSJS'): string
{
    $label = rawurlencode($issuer . ':' . $accountName);
    $params = http_build_query([
        'secret' => $secret,
        'issuer' => $issuer,
        'algorithm' => 'SHA1',
        'digits' => 6,
        'period' => 30,
    ]);
    return "otpauth://totp/{$label}?{$params}";
}
