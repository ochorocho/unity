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

	/**
	 * @param mixed $node an ADF document (array) — anything else yields ''
	 */
	public function toText(mixed $node): string {
		if (!is_array($node)) {
			return '';
		}
		return trim($this->walk($node));
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
			case 'codeBlock':
				$lang = (string)($node['attrs']['language'] ?? '');
				return '```' . $lang . "\n" . $this->children($content, '') . "\n```";
			case 'blockquote':
				return '> ' . str_replace("\n", "\n> ", trim($this->children($content, "\n")));
			case 'mention':
				return '@' . (string)($node['attrs']['text'] ?? $node['attrs']['id'] ?? '');
			case 'emoji':
				return (string)($node['attrs']['shortName'] ?? $node['attrs']['text'] ?? '');
			case 'inlineCard':
				return (string)($node['attrs']['url'] ?? '');
			case 'rule':
				return '---';
			default:
				return $this->children($content, '');
		}
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
	 * blocks, bullet/ordered lists, blockquotes) into an ADF document.
	 *
	 * @return array<string, mixed>
	 */
	public function fromMarkdown(string $text): array {
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
				&& preg_match('/^(#{1,6}\s|>|```|\s*([-*+]|\d+\.)\s)/', $lines[$i]) !== 1) {
				$para[] = $lines[$i];
				$i++;
			}
			$content[] = ['type' => 'paragraph', 'content' => $this->inline(implode("\n", $para))];
		}

		return ['type' => 'doc', 'version' => 1, 'content' => $content !== [] ? $content : [$this->emptyParagraph()]];
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
		$pattern = '/(`[^`]+`)|(\[[^\]]+\]\([^)]+\))|(\*\*[^*]+\*\*)|(~~[^~]+~~)|(\*[^*]+\*)|(_[^_]+_)/';
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
}
