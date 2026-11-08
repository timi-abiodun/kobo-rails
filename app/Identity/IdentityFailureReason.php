<?php

namespace App\Identity;

enum IdentityFailureReason: string
{
    case BvnNotFound     = 'bvn_not_found';
    case NinNotFound     = 'nin_not_found';
    case NinBvnMismatch  = 'nin_bvn_mismatch';
}