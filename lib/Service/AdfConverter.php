<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\Service;

/**
 * Pragmatic, dependency-free converter between Atlassian Document Format (ADF)
 * and Markdown. Jira Cloud returns issue descriptions/comments as ADF JSON and
 * expects ADF for writes; this isolates that complexity so Jira can share the
 * app's Markdown editor and renderer.
 *
 * toText() renders an ADF tree to Markdown (headings, bold/italic/code/strike,
 * links, lists, code blocks, quotes). fromMarkdown() parses a Markdown subset
 * back into a valid ADF document. The mapping is intentionally lossy (no
 * panels/media/mentions) — enough for issue text.
 */
class AdfConverter {

	// --- ADF -> Markdown -----------------------------------------------------

	/** When true, walk() renders mentions as the editor token @"user/<id>", not @Name. */
	private bool $mentionAsToken = false;

	/**
	 * @param mixed $node an ADF document (array) — anything else yields ''
	 * @param bool $mentionAsToken emit mentions as the `@"user/<accountId>"` editor
	 *                             token (for loading into the mention editor) instead of the display `@Name`
	 */
	public function toText(mixed $node, bool $mentionAsToken = false): string {
		if (!is_array($node)) {
			return '';
		}
		$this->mentionAsToken = $mentionAsToken;
		return trim($this->walk($node));
	}

	/**
	 * Collect the document's mentions as `{id, label}` for the editor's userData —
	 * `id` is the canonical `user/<accountId>` token id, `label` the display name.
	 *
	 * @param mixed $node an ADF document (array) — anything else yields []
	 * @return list<array{id: string, label: string}>
	 */
	public function extractMentions(mixed $node): array {
		$out = [];
		if (is_array($node)) {
			$this->collectMentions($node, $out);
		}
		return $out;
	}

	/**
	 * @param array<mixed> $node
	 * @param list<array{id: string, label: string}> $out
	 */
	private function collectMentions(array $node, array &$out): void {
		if (($node['type'] ?? '') === 'mention') {
			$id = (string)($node['attrs']['id'] ?? '');
			if ($id !== '') {
				$label = ltrim((string)($node['attrs']['text'] ?? ''), '@');
				$out[] = ['id' => 'user/' . $id, 'label' => $label !== '' ? $label : $id];
			}
			return;
		}
		foreach ((is_array($node['content'] ?? null) ? $node['content'] : []) as $child) {
			if (is_array($child)) {
				$this->collectMentions($child, $out);
			}
		}
	}

	private function walk(array $node): string {
		$type = (string)($node['type'] ?? '');
		$content = is_array($node['content'] ?? null) ? $node['content'] : [];

		switch ($type) {
			case 'doc':
				return $this->children($content, "\n\n");
			case 'paragraph':
				return $this->children($content, '');
			case 'heading':
				$level = max(1, min((int)($node['attrs']['level'] ?? 1), 6));
				return str_repeat('#', $level) . ' ' . $this->children($content, '');
			case 'text':
				return $this->renderText($node);
			case 'hardBreak':
				return "\n";
			case 'bulletList':
			case 'orderedList':
				$items = [];
				foreach ($content as $i => $item) {
					if (!is_array($item)) {
						continue;
					}
					$prefix = $type === 'orderedList' ? (($i + 1) . '. ') : '- ';
					$items[] = $prefix . trim($this->walk($item));
				}
				return implode("\n", $items);
			case 'listItem':
				return $this->children($content, "\n");
			case 'taskList':
				$items = [];
				foreach ($content as $item) {
					if (is_array($item)) {
						$items[] = trim($this->walk($item));
					}
				}
				return implode("\n", $items);
			case 'taskItem':
				$mark = ($node['attrs']['state'] ?? '') === 'DONE' ? '[x]' : '[ ]';
				return '- ' . $mark . ' ' . $this->children($content, '');
			case 'codeBlock':
				$lang = (string)($node['attrs']['language'] ?? '');
				return '```' . $lang . "\n" . $this->children($content, '') . "\n```";
			case 'blockquote':
				return '> ' . str_replace("\n", "\n> ", trim($this->children($content, "\n")));
			case 'mention':
				$id = (string)($node['attrs']['id'] ?? '');
				// For the editor, emit the canonical token so it renders as a pill.
				if ($this->mentionAsToken && $id !== '') {
					return '@"user/' . $id . '"';
				}
				// Jira hydrates a mention's `text` to the display name *including* the
				// leading '@' (e.g. "@Jochen Roth"); only add one when it's missing so
				// we don't render "@@Jochen Roth".
				$label = (string)($node['attrs']['text'] ?? '');
				if ($label === '') {
					$label = $id;
				}
				return str_starts_with($label, '@') ? $label : '@' . $label;
			case 'emoji':
				return (string)($node['attrs']['shortName'] ?? $node['attrs']['text'] ?? '');
			case 'inlineCard':
				return (string)($node['attrs']['url'] ?? '');
			case 'rule':
				return '---';
			case 'table':
				return $this->tableToMarkdown($content);
			default:
				return $this->children($content, '');
		}
	}

	/**
	 * Render an ADF table's rows back to a GFM markdown table (first row = header).
	 *
	 * @param list<mixed> $rows tableRow nodes
	 */
	private function tableToMarkdown(array $rows): string {
		$out = [];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$cells = [];
			foreach ((is_array($row['content'] ?? null) ? $row['content'] : []) as $cell) {
				if (is_array($cell)) {
					$cells[] = trim($this->children(is_array($cell['content'] ?? null) ? $cell['content'] : [], ''));
				}
			}
			$out[] = $cells;
		}
		if ($out === []) {
			return '';
		}
		$cols = count($out[0]);
		$lines = ['| ' . implode(' | ', $out[0]) . ' |'];
		$lines[] = '| ' . implode(' | ', array_fill(0, $cols, '---')) . ' |';
		foreach (array_slice($out, 1) as $row) {
			$lines[] = '| ' . implode(' | ', $row) . ' |';
		}
		return implode("\n", $lines);
	}

	private function renderText(array $node): string {
		$text = (string)($node['text'] ?? '');
		$types = [];
		$href = '';
		foreach (($node['marks'] ?? []) as $mark) {
			if (!is_array($mark)) {
				continue;
			}
			$t = (string)($mark['type'] ?? '');
			$types[$t] = true;
			if ($t === 'link') {
				$href = (string)($mark['attrs']['href'] ?? '');
			}
		}
		if (isset($types['code'])) {
			$text = '`' . $text . '`';
		}
		if (isset($types['strong'])) {
			$text = '**' . $text . '**';
		}
		if (isset($types['em'])) {
			$text = '*' . $text . '*';
		}
		if (isset($types['strike'])) {
			$text = '~~' . $text . '~~';
		}
		if ($href !== '') {
			$text = '[' . $text . '](' . $href . ')';
		}
		return $text;
	}

	private function children(array $content, string $separator): string {
		$parts = [];
		foreach ($content as $child) {
			if (is_array($child)) {
				$parts[] = $this->walk($child);
			}
		}
		return implode($separator, $parts);
	}

	// --- ADF -> HTML ---------------------------------------------------------

	/** Optional builder turning a mention accountId into a profile URL, set per toHtml() call. */
	private ?\Closure $mentionUrl = null;

	/**
	 * Render an ADF document to sanitizer-safe HTML for display. Mirrors toText()'s
	 * node coverage but emits HTML, and renders mentions as a styled pill so they
	 * read like the editor's mention chips instead of plain "@Name" text. When
	 * `$mentionUrl` is given and returns a URL for a mention's id, the pill becomes
	 * a link to that user's profile. The raw `body`/`description` stays Markdown
	 * (from toText) for editing; this only powers the rendered view.
	 *
	 * @param mixed $node an ADF document (array) — anything else yields ''
	 * @param (callable(string): ?string)|null $mentionUrl maps a mention id to a profile URL
	 */
	public function toHtml(mixed $node, ?callable $mentionUrl = null): string {
		if (!is_array($node)) {
			return '';
		}
		$this->mentionUrl = $mentionUrl !== null ? \Closure::fromCallable($mentionUrl) : null;
		return $this->walkHtml($node);
	}

	private function walkHtml(array $node): string {
		$type = (string)($node['type'] ?? '');
		$content = is_array($node['content'] ?? null) ? $node['content'] : [];

		switch ($type) {
			case 'doc':
				return $this->childrenHtml($content);
			case 'paragraph':
				return '<p>' . $this->childrenHtml($content) . '</p>';
			case 'heading':
				$level = max(1, min((int)($node['attrs']['level'] ?? 1), 6));
				return '<h' . $level . '>' . $this->childrenHtml($content) . '</h' . $level . '>';
			case 'text':
				return $this->renderTextHtml($node);
			case 'hardBreak':
				return '<br>';
			case 'bulletList':
				return '<ul>' . $this->childrenHtml($content) . '</ul>';
			case 'orderedList':
				return '<ol>' . $this->childrenHtml($content) . '</ol>';
			case 'listItem':
				return '<li>' . $this->childrenHtml($content) . '</li>';
			case 'taskList':
				return '<ul class="contains-task-list">' . $this->childrenHtml($content) . '</ul>';
			case 'taskItem':
				// Disabled to match GitLab/Asana; the frontend re-enables it when editable.
				$checked = ($node['attrs']['state'] ?? '') === 'DONE' ? ' checked' : '';
				return '<li class="task-list-item"><input type="checkbox" disabled' . $checked . '> '
					. $this->childrenHtml($content) . '</li>';
			case 'codeBlock':
				$lang = (string)($node['attrs']['language'] ?? '');
				$class = $lang !== '' ? ' class="language-' . $this->esc($lang) . '"' : '';
				return '<pre><code' . $class . '>' . $this->esc($this->codeText($content)) . '</code></pre>';
			case 'blockquote':
				return '<blockquote>' . $this->childrenHtml($content) . '</blockquote>';
			case 'mention':
				// The pill supplies its own '@'/icon, so emit just the display name.
				$label = (string)($node['attrs']['text'] ?? '');
				if ($label === '') {
					$label = (string)($node['attrs']['id'] ?? '');
				}
				$safe = $this->esc(ltrim($label, '@'));
				$url = $this->mentionUrl !== null ? ($this->mentionUrl)((string)($node['attrs']['id'] ?? '')) : null;
				if (is_string($url) && $url !== '') {
					return '<a class="unity-mention" href="' . $this->esc($url) . '">' . $safe . '</a>';
				}
				return '<span class="unity-mention">' . $safe . '</span>';
			case 'emoji':
				// ADF emoji nodes carry the actual character in `text`.
				return $this->esc((string)($node['attrs']['text'] ?? $node['attrs']['shortName'] ?? ''));
			case 'inlineCard':
				$url = (string)($node['attrs']['url'] ?? '');
				return $url !== '' ? '<a href="' . $this->esc($url) . '">' . $this->esc($url) . '</a>' : '';
			case 'rule':
				return '<hr>';
			case 'table':
				return $this->tableToHtml($content);
			default:
				return $this->childrenHtml($content);
		}
	}

	/**
	 * @param list<mixed> $rows tableRow nodes
	 */
	private function tableToHtml(array $rows): string {
		$html = '<table>';
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$html .= '<tr>';
			foreach ((is_array($row['content'] ?? null) ? $row['content'] : []) as $cell) {
				if (!is_array($cell)) {
					continue;
				}
				$tag = ($cell['type'] ?? '') === 'tableHeader' ? 'th' : 'td';
				$html .= '<' . $tag . '>'
					. $this->cellHtml(is_array($cell['content'] ?? null) ? $cell['content'] : [])
					. '</' . $tag . '>';
			}
			$html .= '</tr>';
		}
		return $html . '</table>';
	}

	/** Render a table cell's content inline, unwrapping the single wrapping paragraph. */
	private function cellHtml(array $content): string {
		$out = '';
		foreach ($content as $node) {
			if (!is_array($node)) {
				continue;
			}
			$out .= ($node['type'] ?? '') === 'paragraph'
				? $this->childrenHtml(is_array($node['content'] ?? null) ? $node['content'] : [])
				: $this->walkHtml($node);
		}
		return $out;
	}

	private function renderTextHtml(array $node): string {
		$text = $this->esc((string)($node['text'] ?? ''));
		$href = '';
		$marks = [];
		foreach (($node['marks'] ?? []) as $mark) {
			if (!is_array($mark)) {
				continue;
			}
			$t = (string)($mark['type'] ?? '');
			$marks[$t] = true;
			if ($t === 'link') {
				$href = (string)($mark['attrs']['href'] ?? '');
			}
		}
		if (isset($marks['code'])) {
			$text = '<code>' . $text . '</code>';
		}
		if (isset($marks['strong'])) {
			$text = '<strong>' . $text . '</strong>';
		}
		if (isset($marks['em'])) {
			$text = '<em>' . $text . '</em>';
		}
		if (isset($marks['strike'])) {
			$text = '<s>' . $text . '</s>';
		}
		if ($href !== '') {
			$text = '<a href="' . $this->esc($href) . '">' . $text . '</a>';
		}
		return $text;
	}

	private function childrenHtml(array $content): string {
		$parts = [];
		foreach ($content as $child) {
			if (is_array($child)) {
				$parts[] = $this->walkHtml($child);
			}
		}
		return implode('', $parts);
	}

	/** Concatenate the raw text of a code block's child text nodes. */
	private function codeText(array $content): string {
		$out = '';
		foreach ($content as $child) {
			if (is_array($child) && ($child['type'] ?? '') === 'text') {
				$out .= (string)($child['text'] ?? '');
			}
		}
		return $out;
	}

	private function esc(string $text): string {
		return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	// --- Markdown -> ADF -----------------------------------------------------

	/**
	 * Wrap plaintext into a minimal ADF document (one paragraph per non-empty
	 * line). Kept for callers that only have plain text.
	 *
	 * @return array<string, mixed>
	 */
	public function fromText(string $text): array {
		$content = [];
		foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
			if ($line === '') {
				continue;
			}
			$content[] = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $line]]];
		}
		return ['type' => 'doc', 'version' => 1, 'content' => $content !== [] ? $content : [$this->emptyParagraph()]];
	}

	/**
	 * Parse a Markdown subset (headings, bold/italic/code/strike, links, code
	 * blocks, bullet/ordered lists, blockquotes, GFM tables, @mentions) into an ADF
	 * document.
	 *
	 * @return array<string, mixed>
	 */
	public function fromMarkdown(string $text): array {
		$this->localIdSeq = 0;
		$lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
		$content = [];
		$i = 0;
		$n = count($lines);

		while ($i < $n) {
			$line = $lines[$i];

			if (preg_match('/^```(.*)$/', $line, $m) === 1) {
				$lang = trim($m[1]);
				$code = [];
				$i++;
				while ($i < $n && preg_match('/^```/', $lines[$i]) !== 1) {
					$code[] = $lines[$i];
					$i++;
				}
				$i++;
				$node = ['type' => 'codeBlock', 'content' => [['type' => 'text', 'text' => implode("\n", $code)]]];
				if ($lang !== '') {
					$node['attrs'] = ['language' => $lang];
				}
				if (($code === [] || implode('', $code) === '')) {
					$node['content'] = [];
				}
				$content[] = $node;
				continue;
			}
			if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m) === 1) {
				$content[] = ['type' => 'heading', 'attrs' => ['level' => strlen($m[1])], 'content' => $this->inline($m[2])];
				$i++;
				continue;
			}
			if (preg_match('/^>\s?(.*)$/', $line, $m) === 1) {
				$quote = [];
				while ($i < $n && preg_match('/^>\s?(.*)$/', $lines[$i], $mm) === 1) {
					$quote[] = $mm[1];
					$i++;
				}
				$content[] = ['type' => 'blockquote', 'content' => [['type' => 'paragraph', 'content' => $this->inline(implode("\n", $quote))]]];
				continue;
			}
			if ($this->isTableAt($lines, $i)) {
				$rows = [$this->tableCells($lines[$i])];
				$i += 2; // skip the header row and the delimiter row
				while ($i < $n && trim($lines[$i]) !== '' && str_contains($lines[$i], '|')) {
					$rows[] = $this->tableCells($lines[$i]);
					$i++;
				}
				$content[] = $this->buildTable($rows);
				continue;
			}
			// Task lists must be matched before the generic list matcher below, which
			// would otherwise swallow `- [ ] foo` as an ordinary bullet.
			if (preg_match('/^\s*[-*+]\s+\[([ xX])\]\s+(.*)$/', $line) === 1) {
				$items = [];
				while ($i < $n && preg_match('/^\s*[-*+]\s+\[([ xX])\]\s+(.*)$/', $lines[$i], $mm) === 1) {
					$items[] = [
						'type' => 'taskItem',
						'attrs' => ['localId' => $this->localId(), 'state' => strtolower($mm[1]) === 'x' ? 'DONE' : 'TODO'],
						'content' => $this->inline($mm[2]),
					];
					$i++;
				}
				$content[] = ['type' => 'taskList', 'attrs' => ['localId' => $this->localId()], 'content' => $items];
				continue;
			}
			if (preg_match('/^\s*([-*+]|\d+\.)\s+(.*)$/', $line) === 1) {
				$ordered = null;
				$items = [];
				while ($i < $n && preg_match('/^\s*([-*+]|\d+\.)\s+(.*)$/', $lines[$i], $mm) === 1) {
					if ($ordered === null) {
						$ordered = preg_match('/^\d+\./', $mm[1]) === 1;
					}
					$items[] = ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => $this->inline($mm[2])]]];
					$i++;
				}
				$content[] = ['type' => $ordered ? 'orderedList' : 'bulletList', 'content' => $items];
				continue;
			}
			if (trim($line) === '') {
				$i++;
				continue;
			}
			$para = [];
			while ($i < $n && trim($lines[$i]) !== ''
				&& preg_match('/^(#{1,6}\s|>|```|\s*([-*+]|\d+\.)\s)/', $lines[$i]) !== 1
				&& !$this->isTableAt($lines, $i)) {
				$para[] = $lines[$i];
				$i++;
			}
			$content[] = ['type' => 'paragraph', 'content' => $this->inline(implode("\n", $para))];
		}

		return ['type' => 'doc', 'version' => 1, 'content' => $content !== [] ? $content : [$this->emptyParagraph()]];
	}

	/**
	 * A GFM pipe table starts at line $i if it has a pipe and the next line is a
	 * delimiter row (dashes/colons separated by pipes).
	 *
	 * @param list<string> $lines
	 */
	private function isTableAt(array $lines, int $i): bool {
		return $i + 1 < count($lines)
			&& str_contains($lines[$i], '|')
			&& preg_match('/^\s*\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)*\|?\s*$/', $lines[$i + 1]) === 1;
	}

	/**
	 * Split a table row into trimmed cells, dropping the optional outer pipes.
	 *
	 * @return list<string>
	 */
	private function tableCells(string $line): array {
		$line = preg_replace('/^\s*\||\|\s*$/', '', trim($line)) ?? '';
		return array_map('trim', explode('|', $line));
	}

	/**
	 * Build an ADF table node from parsed rows; the first row is the header.
	 *
	 * @param list<list<string>> $rows
	 * @return array<string, mixed>
	 */
	private function buildTable(array $rows): array {
		$tableRows = [];
		foreach ($rows as $r => $cells) {
			$cellType = $r === 0 ? 'tableHeader' : 'tableCell';
			$cellNodes = [];
			foreach ($cells as $cell) {
				// No `attrs` key: cell attrs are optional in ADF, and an empty PHP
				// array would JSON-encode to `[]` (an array), which Jira rejects.
				$cellNodes[] = [
					'type' => $cellType,
					'content' => [['type' => 'paragraph', 'content' => $this->inline($cell)]],
				];
			}
			$tableRows[] = ['type' => 'tableRow', 'content' => $cellNodes];
		}
		return [
			'type' => 'table',
			'attrs' => ['isNumberColumnEnabled' => false, 'layout' => 'default'],
			'content' => $tableRows,
		];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function inline(string $text): array {
		$nodes = [];
		foreach (explode("\n", $text) as $si => $seg) {
			if ($si > 0) {
				$nodes[] = ['type' => 'hardBreak'];
			}
			foreach ($this->inlineSegment($seg) as $node) {
				$nodes[] = $node;
			}
		}
		$nodes = array_values(array_filter(
			$nodes,
			static fn (array $nd): bool => $nd['type'] !== 'text' || ($nd['text'] ?? '') !== '',
		));
		return $nodes !== [] ? $nodes : [['type' => 'text', 'text' => ' ']];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function inlineSegment(string $text): array {
		$pattern = '/(@"user\/[^"]+")|(`[^`]+`)|(\[[^\]]+\]\([^)]+\))|(\*\*[^*]+\*\*)|(~~[^~]+~~)|(\*[^*]+\*)|(_[^_]+_)/';
		$nodes = [];
		$offset = 0;
		while (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
			$matchStr = $m[0][0];
			$pos = $m[0][1];
			if ($pos > $offset) {
				$nodes[] = ['type' => 'text', 'text' => substr($text, $offset, $pos - $offset)];
			}
			$nodes[] = $this->inlineToken($matchStr);
			$offset = $pos + strlen($matchStr);
		}
		if ($offset < strlen($text)) {
			$nodes[] = ['type' => 'text', 'text' => substr($text, $offset)];
		}
		return $nodes;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function inlineToken(string $tok): array {
		if (preg_match('/^@"user\/([^"]+)"$/', $tok, $m) === 1) {
			// Jira Cloud resolves the display name from the accountId on render;
			// `text` is only the fallback label.
			return ['type' => 'mention', 'attrs' => ['id' => $m[1], 'text' => '@' . $m[1]]];
		}
		if (preg_match('/^`(.+)`$/s', $tok, $m) === 1) {
			return ['type' => 'text', 'text' => $m[1], 'marks' => [['type' => 'code']]];
		}
		if (preg_match('/^\[([^\]]+)\]\(([^)]+)\)$/', $tok, $m) === 1) {
			return ['type' => 'text', 'text' => $m[1], 'marks' => [['type' => 'link', 'attrs' => ['href' => $m[2]]]]];
		}
		if (preg_match('/^\*\*(.+)\*\*$/s', $tok, $m) === 1) {
			return ['type' => 'text', 'text' => $m[1], 'marks' => [['type' => 'strong']]];
		}
		if (preg_match('/^~~(.+)~~$/s', $tok, $m) === 1) {
			return ['type' => 'text', 'text' => $m[1], 'marks' => [['type' => 'strike']]];
		}
		if (preg_match('/^[*_](.+)[*_]$/s', $tok, $m) === 1) {
			return ['type' => 'text', 'text' => $m[1], 'marks' => [['type' => 'em']]];
		}
		return ['type' => 'text', 'text' => $tok];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function emptyParagraph(): array {
		return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => ' ']]];
	}

	/** Per-document counter feeding localId() so ids are unique within one fromMarkdown() run. */
	private int $localIdSeq = 0;

	/** ADF taskList/taskItem nodes require a localId; emit a document-unique, deterministic one. */
	private function localId(): string {
		return 'unity-' . (++$this->localIdSeq);
	}
}
