<?php

namespace App\Services\Ai;

use App\Enums\AgentRunChangeStatus;
use App\Enums\IssuePriority;
use App\Enums\IssueType;
use App\Enums\LinkType;
use App\Http\Requests\Issues\StoreIssueRequest;
use App\Http\Requests\Issues\UpdateIssueRequest;
use App\Models\Agent;
use App\Models\AgentRole;
use App\Models\AgentRun;
use App\Models\AgentRunChange;
use App\Models\Issue;
use App\Services\ActivityLogger;
use App\Services\AgentService;
use App\Services\IssueService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Step B (B-R4, B-R5, B-R17, PR 51 owner review): the five tools of an in-app run. They read and propose only; no
 * status, delete or settings tool exists. call() is the gate: a fixed name list (never a method or class named by the
 * model), the run's role allowlist, each tool's validation rules, the agent re-checked, and every issue looked up
 * again as the agent (ActivityLogger::forRun), so a result never holds what the agent can't see.
 */
class RunTools
{
    public const ALL = ['read_issue', 'search_issues', 'propose_fields', 'propose_child_issue', 'propose_link'];

    /** Every field propose_fields knows ("summary" in the role table is the issue's title). */
    private const FIELDS = ['title', 'description', 'type', 'priority', 'labels'];

    /** B-R5: the built-in roles' narrower field and link sets; other roles and typed instructions get them all. */
    private const ROLE_FIELDS = ['Triage' => ['type', 'priority', 'labels'], 'Writer' => ['title', 'description']];

    private const ROLE_LINKS = ['Triage' => ['duplicates', 'relates to']];

    /** Most changes one run may propose, so a model can't fill the table. */
    public const MAX_CHANGES = 50;

    private const SEARCH_LIMIT = 10;

    public function __construct(private IssueService $issues, private AgentService $agents) {}

    /** The tools $role may call (null: a typed instruction, B-R17), as provider-neutral {name, description, parameters}. */
    public static function for(?AgentRole $role): array
    {
        $names = $role ? array_values(array_intersect(self::ALL, $role->allowed_tools)) : self::ALL;
        $string = fn (string $description, ?array $enum = null) => ['type' => 'string', 'description' => $description, ...($enum ? ['enum' => $enum] : [])];
        $key = ['type' => 'object', 'properties' => ['key' => $string('An issue key like WEB-12.')], 'required' => ['key']];
        $fields = [
            'title' => $string('A new title (summary).'),
            'description' => $string('A new description (Markdown).'),
            'type' => $string('Issue type.', IssueType::values()),
            'priority' => $string('Priority.', IssuePriority::values()),
            'labels' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Label names; the full new set.'],
        ];
        $tools = [
            'read_issue' => ['Read one issue: fields, parent, links and recent comments.', $key],
            'search_issues' => ['Find issues by text in the title, or by key.', ['type' => 'object', 'properties' => ['q' => $string('Words from the title, or a key.')], 'required' => ['q']]],
            'propose_fields' => ['Propose new values for fields of the issue you work on. A person reviews them.',
                ['type' => 'object', 'properties' => Arr::only($fields, self::fieldsFor($role))]],
            'propose_child_issue' => ['Propose a new child issue under the issue you work on. A person reviews it.', ['type' => 'object', 'properties' => [
                ...Arr::only($fields, ['title', 'description', 'type', 'priority']),
                'story_points' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Estimate in story points.'],
            ], 'required' => ['title']]],
            'propose_link' => ['Propose a link from the issue you work on to another issue. A person reviews it.', ['type' => 'object', 'properties' => [
                'type' => $string('Link type.', self::linksFor($role)),
                'issue' => $string('The other issue\'s key.'),
            ], 'required' => ['type', 'issue']]],
        ];

        return array_map(fn ($name) => ['name' => $name, 'description' => $tools[$name][0], 'parameters' => $tools[$name][1]], $names);
    }

    /** Runs one tool call the model asked for; any refusal comes back as ['error' => plain text] and changes nothing. */
    public function call(AgentRun $run, string $name, array $args): array
    {
        $allowed = self::allowed($run);
        if (! in_array($name, $allowed, true)) {
            return ['error' => 'Unknown tool "'.Str::limit($name, 60).'". Tools you can use: '.implode(', ', $allowed).'.'];
        }
        if ($stop = $this->unusable($run->agent)) {
            return ['error' => $stop];
        }

        try {
            return ActivityLogger::forRun($run, fn () => match ($name) {
                'read_issue' => $this->readIssue($run, $args),
                'search_issues' => $this->searchIssues($run, $args),
                'propose_fields' => $this->proposeFields($run, $args),
                'propose_child_issue' => $this->proposeChildIssue($run, $args),
                'propose_link' => $this->proposeLink($run, $args),
            });
        } catch (ValidationException $e) {
            return ['error' => collect($e->errors())->flatten()->implode(' ')];
        }
    }

    /** Why $agent can't act now (AgentService::ensureUsable's message), or null when it can. A deleted agent can't. */
    public function unusable(?Agent $agent): ?string
    {
        if (! $agent) {
            return 'The agent was removed.';
        }
        try {
            $this->agents->ensureUsable($agent);

            return null;
        } catch (HttpResponseException $e) {
            return $e->getResponse()->getData(true)['message'] ?? 'The agent can\'t act now.';
        }
    }

    /**
     * The run's role allowlist; a typed instruction (no role, B-R17) gets every tool.
     * shortcut: a custom role named "Instruction" that is deleted before its run starts gets every tool (propose-only), check role_id at start if custom roles make that matter.
     */
    private static function allowed(AgentRun $run): array
    {
        return $run->role ? array_values(array_intersect(self::ALL, $run->role->allowed_tools)) : ($run->role_name === 'Instruction' ? self::ALL : []);
    }

    private static function fieldsFor(?AgentRole $role): array
    {
        return ($role && $role->workspace_id === null ? self::ROLE_FIELDS[$role->name] ?? null : null) ?? self::FIELDS;
    }

    private static function linksFor(?AgentRole $role): array
    {
        return ($role && $role->workspace_id === null ? self::ROLE_LINKS[$role->name] ?? null : null) ?? array_column(LinkType::cases(), 'value');
    }

    private function readIssue(AgentRun $run, array $args): array
    {
        $data = Validator::make($args, ['key' => ['required', 'string', 'max:30']])->validate();

        return RunPrompt::issue($this->issues->resolve($run->user, $data['key']), $run->user);
    }

    private function searchIssues(AgentRun $run, array $args): array
    {
        $data = Validator::make($args, ['q' => ['required', 'string', 'max:200']])->validate();

        return ['issues' => $this->issues->list($run->user, null, ['q' => $data['q'], 'per_page' => self::SEARCH_LIMIT])->getCollection()
            ->map(fn (Issue $i) => ['key' => $i->key, 'title' => $i->title, 'type' => $i->type->value, 'status' => $i->status->name, 'priority' => $i->priority->value])->all()];
    }

    private function proposeFields(AgentRun $run, array $args): array
    {
        $fields = self::fieldsFor($run->role);
        if (array_diff(array_keys($args), $fields)) {
            throw ValidationException::withMessages(['fields' => "{$run->role_name} can propose: ".implode(', ', $fields).'.']);
        }
        if (! $args) {
            throw ValidationException::withMessages(['fields' => 'Propose at least one of: '.implode(', ', $fields).'.']);
        }
        $data = $this->validated($args, [...$fields, 'labels.*'], new UpdateIssueRequest);
        $issue = $this->issue($run);
        $this->ensureRoom($run, count($data));
        $now = ['title' => $issue->title, 'description' => $issue->description, 'type' => $issue->type->value, 'priority' => $issue->priority->value,
            'labels' => $issue->labels()->pluck('name')->all()];
        foreach ($data as $field => $value) {
            // A newer proposal for the same field replaces the one not yet applied.
            AgentRunChange::updateOrCreate(['run_id' => $run->id, 'kind' => 'field', 'target' => $field, 'status' => AgentRunChangeStatus::Proposed],
                ['before' => ['value' => $now[$field]], 'after' => ['value' => $value], 'issue_version' => $issue->version]);
        }

        return ['proposed' => array_keys($data)];
    }

    private function proposeChildIssue(AgentRun $run, array $args): array
    {
        $data = $this->validated($args, ['title', 'description', 'type', 'priority', 'story_points'], new StoreIssueRequest);
        $issue = $this->issue($run);
        $this->ensureRoom($run, 1);
        AgentRunChange::create(['run_id' => $run->id, 'kind' => 'child_issue', 'after' => $data, 'issue_version' => $issue->version, 'status' => AgentRunChangeStatus::Proposed]);

        return ['proposed' => 'child_issue'];
    }

    private function proposeLink(AgentRun $run, array $args): array
    {
        $data = Validator::make($args, ['type' => ['required', 'string', 'max:30'], 'issue' => ['required', 'string', 'max:30']])->validate();
        [$type, $reverse] = LinkType::parse($data['type']);
        $links = self::linksFor($run->role);
        if (! in_array($type->value, $links, true)) {
            throw ValidationException::withMessages(['type' => "{$run->role_name} can propose these links: ".implode(', ', $links).'.']);
        }
        $issue = $this->issue($run);
        $other = $this->issues->resolve($run->user, $data['issue']);
        if ($other->id === $issue->id) {
            throw ValidationException::withMessages(['issue' => 'An issue can\'t link to itself.']);
        }
        $this->ensureRoom($run, 1);
        AgentRunChange::create(['run_id' => $run->id, 'kind' => 'link', 'target' => $other->key, 'issue_version' => $issue->version, 'status' => AgentRunChangeStatus::Proposed,
            'after' => ['type' => $reverse ? $type->reverse() : $type->value, 'issue' => $other->key]]);

        return ['proposed' => 'link'];
    }

    /** $args checked against the issue form's own rules and messages (which list the valid options). */
    private function validated(array $args, array $keys, StoreIssueRequest $form): array
    {
        return Validator::make($args, Arr::only($form->rules(), $keys), $form->messages())->validate();
    }

    /** The run's issue, looked up again as the agent on every call: deleted or out of sight, nothing is proposed. */
    private function issue(AgentRun $run): Issue
    {
        return Issue::visibleTo($run->user)->with('project')->find($run->issue_id)
            ?? throw ValidationException::withMessages(['issue' => "{$run->issue_key} was deleted, or the agent can no longer see it."])->status(404);
    }

    private function ensureRoom(AgentRun $run, int $adding): void
    {
        if ($run->changes()->count() + $adding > self::MAX_CHANGES) {
            throw ValidationException::withMessages(['run' => 'This run already proposed '.self::MAX_CHANGES.' changes, the most one run can.']);
        }
    }
}
