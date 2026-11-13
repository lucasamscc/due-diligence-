<?php

namespace App\Sources\Data;

enum FindingType: string
{
    case Cadastral = 'cadastral';
    case Sanction = 'sanction';
    case Environmental = 'environmental';
}
