<?php
declare(strict_types=1);

/**
 * Sinh chuỗi VietQR theo chuẩn EMVCo / NAPAS 247 (chuyển nhanh đến tài khoản).
 * Chuỗi này được vẽ thành mã QR ở trình duyệt; mọi app ngân hàng tại VN đều quét được.
 */
final class VietQR
{
    public static function payload(string $bankBin, string $accountNo, ?int $amount, string $content): string
    {
        $merchant = self::tlv('00', 'A000000727')
            . self::tlv('01', self::tlv('00', $bankBin) . self::tlv('01', $accountNo))
            . self::tlv('02', 'QRIBFTTA');

        $data = self::tlv('00', '01')
            . self::tlv('01', $amount ? '12' : '11')
            . self::tlv('38', $merchant)
            . self::tlv('53', '704')
            . ($amount ? self::tlv('54', (string)$amount) : '')
            . self::tlv('58', 'VN')
            . ($content !== '' ? self::tlv('62', self::tlv('08', $content)) : '')
            . '6304';
        return $data . self::crc16($data);
    }

    private static function tlv(string $id, string $value): string
    {
        $len = strlen($value);
        if ($len > 99) {
            throw new InvalidArgumentException("Trường $id quá dài");
        }
        return $id . str_pad((string)$len, 2, '0', STR_PAD_LEFT) . $value;
    }

    /** CRC-16/CCITT-FALSE (poly 0x1021, init 0xFFFF) */
    public static function crc16(string $data): string
    {
        $crc = 0xFFFF;
        for ($i = 0, $n = strlen($data); $i < $n; $i++) {
            $crc ^= ord($data[$i]) << 8;
            for ($b = 0; $b < 8; $b++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }
        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }

    /** Danh sách mã BIN ngân hàng thường dùng (theo NAPAS) */
    public static function banks(): array
    {
        return [
            '970418' => 'BIDV',
            '970415' => 'VietinBank',
            '970436' => 'Vietcombank',
            '970405' => 'Agribank',
            '970422' => 'MB Bank',
            '970407' => 'Techcombank',
            '970416' => 'ACB',
            '970432' => 'VPBank',
            '970423' => 'TPBank',
            '970403' => 'Sacombank',
        ];
    }
}
