<?php

namespace App\Policies;

use App\Models\Issue;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class IssuePolicy
{
    public function __construct(private ProjectPolicy $projects) {}

    public function update(User $user, Issue $issue): Response
    {
        return $this->projects->contribute($user, $issue->project);
    }

    /** 2-R3: removing a link needs contribute in either issue's project (adding one is `update` on the source issue). */
    public function unlink(User $user, Issue $issue, Issue $other): Response
    {
        $own = $this->projects->contribute($user, $issue->project);

        return $own->denied() && $this->projects->contribute($user, $other->project)->allowed() ? Response::allow() : $own;
    }

    public function delete(User $user, Issue $issue): Response
    {
        return $this->projects->remove($user, $issue->project);
    }

    public function comment(User $user, Issue $issue): Response
    {
        return $this->projects->contribute($user, $issue->project);
    }
}
