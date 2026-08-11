<?php

namespace App\Enums;

enum ApprovalDecision: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
    case ChangesRequested = 'changes_requested'; // gap_analysis gate only — pre_push has no equivalent
}
