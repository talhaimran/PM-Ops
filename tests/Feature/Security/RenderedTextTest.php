<?php

use App\Enums\ProjectRole;
use App\Models\User;
use App\Services\CommentService;
use App\Services\IssueService;

// Review Focus 3: mentions and issue keys in rendered text. No mention from code, no nested links, no injected HTML.

beforeEach(function () {
    $this->ann = User::factory()->create(['name' => 'Ann Admin', 'email' => 'ann@example.com']);
    $this->project = makeProject($this->ann, 'WEB');
    $this->sam = addMember($this->project, User::factory()->create(['name' => 'Sam Smith', 'email' => 'sam@example.com']), ProjectRole::Member);
    $this->first = makeIssue($this->project, $this->ann, ['title' => 'First "quoted" <b>issue</b>']);
    $this->html = function (string $body, ?User $viewer = null) {
        app(CommentService::class)->add($this->first, $this->ann, $body);
        $page = $this->actingAs($viewer ?? $this->ann)->get(w('/issues/WEB-1'))->assertOk()->viewData('page')['props'];

        return collect($page['issue']['comments'])->last()['body_html'];
    };
});

test('a mention shows as a highlighted name and a key links with hover data', function () {
    $html = ($this->html)('Hi @sam@example.com see WEB-1');

    expect($html)->toContain('<span class="mention">@Sam Smith</span>')
        ->and($html)->toContain('href="'.url(w('/issues/WEB-1')).'"')->and($html)->toContain('data-key="WEB-1"')
        ->and($html)->toContain('data-status="To Do"')->and($html)->not->toContain('<b>issue</b>');
    // The title is data in the attribute, not markup: read back it is the text as typed.
    $doc = new DOMDocument;
    @$doc->loadHTML('<?xml encoding="UTF-8">'.$html);
    expect($doc->getElementsByTagName('a')->item(0)->getAttribute('data-title'))->toBe('First "quoted" <b>issue</b>')
        ->and($doc->getElementsByTagName('b')->length)->toBe(0);
});

test('inline code, fenced code and existing links are left alone', function () {
    $html = ($this->html)("`@sam@example.com WEB-1`\n\n```\n@sam@example.com WEB-1\n```\n\n[WEB-1](https://x.test) and <a href=\"https://y.test\">WEB-1</a>");

    expect($html)->not->toContain('class="mention"')->and(substr_count($html, '<a '))->toBe(2) // the markdown link, and the key from the stripped tag
        ->and($html)->not->toMatch('/<a[^>]*><a /')->and($html)->toContain('<code>@sam@example.com WEB-1</code>');
});

test('script stays stripped', function () {
    $html = ($this->html)('<script>alert(1)</script> WEB-1 <img src=x onerror=alert(1)>');

    expect($html)->not->toContain('<script')->and($html)->not->toContain('<img')->and($html)->not->toContain('onerror');
});

test('a non-member email and an unseen or other-workspace key stay plain text and leak nothing', function () {
    // SEC is in this workspace but sam isn't in it; OTH-1 is in another workspace.
    $other = User::factory()->create();
    makeIssue(makeProject($other, 'SEC'), $other, ['title' => 'Top secret plan']);
    $elsewhere = makeWorkspace($other, 'elsewhere');
    makeIssue(makeProject($other, 'OTH'), $other, ['title' => 'Top secret other']);
    useWorkspace($this->project->workspace, $this->ann);
    $html = ($this->html)('cc @stranger@example.com SEC-1 OTH-1 NOPE-9', $this->sam);

    expect($html)->not->toContain('Top secret')->and($html)->not->toContain('data-key')->and($html)->toContain('SEC-1 OTH-1 NOPE-9')
        ->and($html)->not->toContain('class="mention"');
});

test('a key to a deleted issue stays plain text', function () {
    $gone = makeIssue($this->project, $this->ann, ['title' => 'Gone']);
    app(IssueService::class)->delete($gone, $this->ann);

    expect(($this->html)("see {$gone->key}"))->not->toContain('data-key');
});
