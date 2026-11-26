<?php

namespace App\Services;

use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;
use Illuminate\Support\Str;

/**
 * Markdown to HTML for comments and descriptions (raw HTML stripped, no unsafe links), then 3-D3 mentions and 3-R12 issue keys
 * are found in the text nodes only (DOMDocument, never a regex over HTML): never inside code or an existing link.
 * mentions() is what MentionService stores; html() is what the page shows.
 */
class RenderedText
{
    /** Most distinct keys looked up per render. shortcut: later keys stay plain text, raise it if a text really names more. */
    private const MAX_KEYS = 50;

    /** `@email` (not glued to a word or another email) or a key like WEB-12. */
    private const TOKENS = '/(?<![\w@.+-])@(?<email>[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+)|(?<![\w-])(?<key>[A-Z][A-Z0-9]{1,9}-\d+)(?![\w-])/';

    /** @return list<string> the lowercase emails @mentioned in $text, outside code and links, in order, once each */
    public function mentions(string $text): array
    {
        [, $sites] = $this->sites($this->load($this->markdown($text)));

        return collect($sites)->where('type', 'email')->pluck('value')->unique()->values()->all();
    }

    /** The page's HTML: mentions of $project's members as "@Name", keys of issues $viewer can see as links with hover data. */
    public function html(?string $text, User $viewer, Project $project): string
    {
        return $this->htmlMany([$text], $viewer, $project)[0];
    }

    /**
     * html() for several texts (an issue's description and comments) with one members lookup and one issues lookup between them (3-R12).
     *
     * @param  list<?string>  $texts
     * @return list<string> in the same order
     */
    public function htmlMany(array $texts, User $viewer, Project $project): array
    {
        $parsed = array_map(function (?string $text) {
            if ($text === null || $text === '') {
                return null;
            }
            $doc = $this->load($this->markdown($text));

            return [$doc, ...$this->sites($doc)];
        }, $texts);

        $sites = collect($parsed)->filter()->flatMap(fn ($p) => $p[2]);
        $emails = $sites->where('type', 'email')->pluck('value')->unique()->all();
        $names = $emails ? $project->members()->whereIn('email', $emails)->pluck('users.name', 'users.email')->mapWithKeys(fn ($n, $e) => [strtolower($e) => $n])->all() : [];
        $issues = $this->visibleIssues($viewer, $sites->where('type', 'key')->pluck('value')->unique()->take(self::MAX_KEYS)->all());

        return array_map(function (?array $p) use ($names, $issues) {
            if ($p === null) {
                return '';
            }
            [$doc, $body, $sites] = $p;
            foreach (collect($sites)->groupBy(fn ($s) => spl_object_id($s['node'])) as $group) {
                $node = $group[0]['node'];
                if ($node instanceof DOMText) {
                    $this->rewrite($doc, $node, $names, $issues);
                } elseif (isset($names[$group[0]['value']])) {
                    // An autolinked "@" + email: the "@" is the previous text's last character.
                    $previous = $node->previousSibling;
                    $previous->nodeValue = substr($previous->nodeValue, 0, -1);
                    $node->parentNode->replaceChild($this->mention($doc, $names[$group[0]['value']]), $node);
                }
            }

            return collect($body->childNodes)->map(fn (DOMNode $n) => $doc->saveHTML($n))->implode('');
        }, $parsed);
    }

    private function markdown(string $text): string
    {
        return Str::markdown($text, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }

    private function load(string $html): DOMDocument
    {
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><body>'.$html.'</body>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $doc;
    }

    /**
     * Where mentions and keys sit: text nodes outside code and links, and an email the Markdown autolinked right after an "@".
     *
     * @return array{0: DOMElement, 1: list<array{type: string, value: string, node: DOMNode}>}
     */
    private function sites(DOMDocument $doc): array
    {
        $xpath = new DOMXPath($doc);
        $sites = [];
        foreach ($xpath->query('//body//text()[not(ancestor::code or ancestor::pre or ancestor::a)]') as $node) {
            if (preg_match_all(self::TOKENS, $node->nodeValue, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $sites[] = isset($m['key']) && $m['key'] !== '' ? ['type' => 'key', 'value' => $m['key'], 'node' => $node] : ['type' => 'email', 'value' => strtolower($m['email']), 'node' => $node];
                }
            }
        }
        foreach ($xpath->query('//body//a[starts-with(@href, "mailto:")]') as $a) {
            $previous = $a->previousSibling;
            $email = strtolower($a->textContent);
            if ($previous instanceof DOMText && preg_match('/(?<![\w@.+-])@$/', $previous->nodeValue) && strtolower($a->getAttribute('href')) === 'mailto:'.$email) {
                $sites[] = ['type' => 'email', 'value' => $email, 'node' => $a];
            }
        }

        return [$xpath->query('//body')->item(0), $sites];
    }

    /** Swap the matches in one text node for spans and links; what isn't a member or a visible issue stays as text. */
    private function rewrite(DOMDocument $doc, DOMText $node, array $names, array $issues): void
    {
        $text = $node->nodeValue;
        preg_match_all(self::TOKENS, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $out = $doc->createDocumentFragment();
        $at = 0;
        foreach ($matches as $m) {
            [$whole, $offset] = $m[0];
            $replacement = null;
            if (($m['key'][0] ?? '') !== '') {
                $replacement = isset($issues[$m['key'][0]]) ? $this->link($doc, $m['key'][0], $issues[$m['key'][0]]) : null;
            } else {
                $name = $names[strtolower($m['email'][0])] ?? null;
                $replacement = $name !== null ? $this->mention($doc, $name) : null;
            }
            if ($replacement) {
                $out->appendChild($doc->createTextNode(substr($text, $at, $offset - $at)));
                $out->appendChild($replacement);
                $at = $offset + strlen($whole);
            }
        }
        $out->appendChild($doc->createTextNode(substr($text, $at)));
        $node->parentNode->replaceChild($out, $node);
    }

    private function mention(DOMDocument $doc, string $name): DOMElement
    {
        $span = $doc->createElement('span');
        $span->setAttribute('class', 'mention');
        $span->appendChild($doc->createTextNode('@'.$name));

        return $span;
    }

    /** @param array{title: string, status: string} $issue */
    private function link(DOMDocument $doc, string $key, array $issue): DOMElement
    {
        $a = $doc->createElement('a');
        $a->setAttribute('href', route('issues.show', $key));
        $a->setAttribute('class', 'issue-key');
        $a->setAttribute('data-key', $key);
        $a->setAttribute('data-title', $issue['title']);
        $a->setAttribute('data-status', $issue['status']);
        $a->appendChild($doc->createTextNode($key));

        return $a;
    }

    /**
     * Title and status of the issues among $keys that $viewer can see (not deleted), by key: one query. Others are absent,
     * so an unseen or unknown key stays plain text and nothing about it is hinted.
     *
     * @param  list<string>  $keys
     * @return array<string, array{title: string, status: string}>
     */
    private function visibleIssues(User $viewer, array $keys): array
    {
        $parsed = collect($keys)->map(fn ($k) => explode('-', $k));
        if ($parsed->isEmpty()) {
            return [];
        }

        return Issue::visibleTo($viewer)->join('projects', 'projects.id', '=', 'issues.project_id')->join('statuses', 'statuses.id', '=', 'issues.status_id')
            ->whereNull('issues.deleted_at')
            ->where(fn ($q) => $parsed->each(fn ($k) => $q->orWhere(fn ($q) => $q->where('projects.key', $k[0])->where('issues.number', $k[1]))))
            ->toBase()->get(['projects.key', 'issues.number', 'issues.title', 'statuses.name as status'])
            ->mapWithKeys(fn ($r) => ["{$r->key}-{$r->number}" => ['title' => $r->title, 'status' => $r->status]])->all();
    }
}
