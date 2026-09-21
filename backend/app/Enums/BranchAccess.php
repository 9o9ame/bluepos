<?php

namespace App\Enums;

enum BranchAccess: string
{
    case AllBranches = 'all_branches';
    case SelectedBranches = 'selected_branches';
}
