<?php

function pixSubscriptionDurationDays(): int
{
    return 365;
}

function nextPixSubscriptionExpiration(?string $currentExpiration, ?int $now = null): string
{
    $now ??= time();
    $expiration = trim((string) $currentExpiration);
    $expiresAt = $expiration === '' ? false : strtotime($expiration);
    if ($expiresAt !== false && preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiration) === 1) {
        $expiresAt = strtotime($expiration . ' 23:59:59');
    }

    $baseTime = $expiresAt === false ? $now : max($now, $expiresAt);
    $baseDate = (new DateTimeImmutable('@' . $baseTime))
        ->setTimezone(new DateTimeZone(date_default_timezone_get()));

    return $baseDate->modify('+12 months')->format('Y-m-d H:i:s');
}

function pixTlv(string $id, string $value): string
{
    return $id . str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT) . $value;
}

function pixCrc16(string $value): string
{
    $crc = 0xFFFF;
    for ($index = 0, $length = strlen($value); $index < $length; $index++) {
        $crc ^= ord($value[$index]) << 8;
        for ($bit = 0; $bit < 8; $bit++) {
            $crc = ($crc & 0x8000) !== 0 ? (($crc << 1) ^ 0x1021) : ($crc << 1);
            $crc &= 0xFFFF;
        }
    }

    return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
}

function pixAsciiField(string $value, int $limit): string
{
    if (function_exists('iconv')) {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($ascii !== false) {
            $value = $ascii;
        }
    }

    $value = strtoupper($value);
    $value = (string) preg_replace('/[^A-Z0-9 ]/', '', $value);
    $value = trim((string) preg_replace('/\s+/', ' ', $value));
    return substr($value, 0, $limit);
}

function generatePixPayload(
    string $pixKey,
    string $merchantName,
    string $merchantCity,
    int $amountCents,
    string $txid
): string {
    if ($pixKey === '' || $amountCents <= 0 || strlen($pixKey) > 77) {
        throw new InvalidArgumentException('Configuração Pix inválida.');
    }

    $merchantName = pixAsciiField($merchantName, 25);
    $merchantCity = pixAsciiField($merchantCity, 15);
    if ($merchantName === '' || $merchantCity === '') {
        throw new InvalidArgumentException('Informe o nome e a cidade do recebedor Pix.');
    }

    $account = pixTlv('00', 'br.gov.bcb.pix') . pixTlv('01', $pixKey);
    $payload = pixTlv('00', '01')
        . pixTlv('01', '12')
        . pixTlv('26', $account)
        . pixTlv('52', '0000')
        . pixTlv('53', '986')
        . pixTlv('54', number_format($amountCents / 100, 2, '.', ''))
        . pixTlv('58', 'BR')
        . pixTlv('59', $merchantName)
        . pixTlv('60', $merchantCity)
        . pixTlv('62', pixTlv('05', substr($txid, 0, 25)))
        . '6304';

    return $payload . pixCrc16($payload);
}
