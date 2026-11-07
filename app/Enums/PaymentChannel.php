<?php

namespace App\Enums;

enum PaymentChannel: string
{
    case POS = 'POS';
    case NipTransfer = 'NIP_TRANSFER';
    case Qr = 'QR';
}
