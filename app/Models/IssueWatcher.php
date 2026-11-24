<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** One person watching one issue; `source` is auto (reporter, assignee, commenter) or manual. */
#[Fillable(['issue_id', 'user_id', 'source'])]
class IssueWatcher extends Model {}
