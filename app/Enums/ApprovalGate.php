<?php

namespace App\Enums;

enum ApprovalGate: string
{
    case GapAnalysis = 'gap_analysis';
    case PrePush = 'pre_push';
}
