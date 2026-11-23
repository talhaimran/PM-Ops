<?php

namespace App\Mcp\Tools;

use App\Enums\StatusCategory;
use App\Http\Requests\Board\ColumnRequest;
use App\Http\Resources\ColumnResource;
use App\Mcp\Tools\Concerns\RespondsWithJson;
use App\Services\ColumnService;
use App\Services\ProjectService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('create_column')]
#[Description('Add a board column to a project (admins only); appended unless position is given. It shows the library status given in status, else a status named after the column in category (reused or created).')]
class CreateColumn extends Tool
{
    use RespondsWithJson;

    public function handle(Request $request, ProjectService $projects, ColumnService $columns): Response
    {
        $request->validate(['project' => 'required|string']);
        $project = $projects->resolve($request->user(), $request->get('project'));
        Gate::authorize('manage', $project);
        $column = $columns->create($project, $request->user(), $request->validate(ColumnRequest::rulesFor(creating: true), (new ColumnRequest)->messages()));

        return $this->result("Added column \"{$column->name}\" to {$project->key}.", new ColumnResource($column));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Project key, e.g. WEB.')->required(),
            'name' => $schema->string()->max(50)->required(),
            'position' => $schema->integer()->min(0)->description('0-based position; default last.'),
            'status' => $schema->string()->max(100)->description('An existing workspace status to show in this column, e.g. "Done".'),
            'category' => $schema->string()->enum(StatusCategory::class)->description('Category of the status named after the column, when status is not given; default in_progress.'),
        ];
    }
}
