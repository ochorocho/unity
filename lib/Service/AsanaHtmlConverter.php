<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\Service;

/**
 * Pragmatic, dependency-free converter between Asana's restricted rich-text HTML
 * and Markdown. Asana returns task descriptions as `html_notes` and comment
 * bodies as `html_text` — a small HTML subset wrapped in a single <body> element
 * — and expects the same on write. This isolates that complexity so Asana can
 * share the app's Markdown editor and renderer.
 *
 * toText() renders Asana HTML to Markdown (headings, bold/italic/strike/code,
 * links, lists, code blocks, quotes, rules). fromMarkdown() parses a Markdown
 * subset back into Asana-safe HTML wrapped in <body>. The mapping is
 * intentionally lossy (no mentions, tables, media, nested lists) — enough for
 * issue text — mirroring AdfConverter.
 */
class AsanaHtmlConverter {

	// --- Markdown -> Asana HTML ----------------------------------------------

	/**
	 * Parse a Markdown subset into Asana restricted HTML wrapped in <body>.
	 *
	 * The output must be valid XML using only Asana-supported tags. Headings
	 * (<h1>/<h2>) and horizontal rules (<hr/>) are only valid in task html_notes,
	 * not in comment html_text, so $allowNotesBlocks gates them: pass false for
	 * comments, where headings degrade to <strong> and rules to literal "---".
	 */
	public function fromMarkdown(string $text, bool $allowNotesBlocks = true): string {
		$lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
		$blocks = [];
		$i = 0;
		$n = count($lines);

		while ($i < $n) {
			$line = $lines[$i];

			if (preg_match('/^```/', $line) === 1) {
				$code = [];
				$i++;
				while ($i < $n && preg_match('/^```/', $lines[$i]) !== 1) {
					$code[] = $lines[$i];
					$i++;
				}
				$i++;
				$blocks[] = '<pre>' . $this->escape(implode("\n", $code)) . '</pre>';
				continue;
			}
			if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m) === 1) {
				if ($allowNotesBlocks) {
					$level = strlen($m[1]) === 1 ? '1' : '2';
					$blocks[] = '<h' . $level . '>' . $this->inline($m[2]) . '</h' . $level . '>';
				} else {
					$blocks[] = '<strong>' . $this->inline($m[2]) . '</strong>';
				}
				$i++;
				continue;
			}
			if (preg_match('/^\s*(---+|\*\*\*+|___+)\s*$/', $line) === 1) {
				$blocks[] = $allowNotesBlocks ? '<hr/>' : '---';
				$i++;
				continue;
			}
			if ($this->isTableAt($lines, $i)) {
				$rows = [$this->tableCells($lines[$i])];
				$i += 2; // skip the header and the separator row
				while ($i < $n && trim($lines[$i]) !== '' && str_contains($lines[$i], '|')) {
					$rows[] = $this->tableCells($lines[$i]);
					$i++;
				}
				$blocks[] = $this->renderTable($rows);
				continue;
			}
			if (preg_match('/^>\s?(.*)$/', $line, $m) === 1) {
				$quote = [];
				while ($i < $n && preg_match('/^>\s?(.*)$/', $lines[$i], $mm) === 1) {
					$quote[] = $mm[1];
					$i++;
				}
				$blocks[] = '<blockquote>' . $this->inline(implode("\n", $quote)) . '</blockquote>';
				continue;
			}
			if (preg_match('/^\s*([-*+]|\d+\.)\s+(.*)$/', $line) === 1) {
				$ordered = null;
				$items = [];
				while ($i < $n && preg_match('/^\s*([-*+]|\d+\.)\s+(.*)$/', $lines[$i], $mm) === 1) {
					if ($ordered === null) {
						$ordered = preg_match('/^\d+\./', $mm[1]) === 1;
					}
					$items[] = '<li>' . $this->inline($mm[2]) . '</li>';
					$i++;
				}
				$tag = $ordered ? 'ol' : 'ul';
				$blocks[] = '<' . $tag . '>' . implode('', $items) . '</' . $tag . '>';
				continue;
			}
			if (trim($line) === '') {
				$i++;
				continue;
			}
			$para = [];
			while ($i < $n && trim($lines[$i]) !== ''
				&& preg_match('/^(#{1,6}\s|>|```|\s*([-*+]|\d+\.)\s|(---+|\*\*\*+|___+)\s*$)/', $lines[$i]) !== 1
				&& !$this->isTableAt($lines, $i)) {
				$para[] = $lines[$i];
				$i++;
			}
			$blocks[] = $this->inline(implode("\n", $para));
		}

		return '<body>' . implode("\n", $blocks) . '</body>';
	}

	/**
	 * A GFM pipe table starts here if this line has a pipe and the next line is a
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
	 * Render table rows as Asana table markup. Asana supports <table>/<tr>/<td>
	 * in task html_notes and comment html_text (read-write since 2024-03-11) but
	 * rejects <th> and other table tags, so every cell — header included — is a
	 * <td>. The delimiter row was already dropped during parsing.
	 *
	 * @param list<list<string>> $rows
	 */
	private function renderTable(array $rows): string {
		$html = '<table>';
		foreach ($rows as $cells) {
			$html .= '<tr>';
			foreach ($cells as $cell) {
				$html .= '<td>' . $this->inline($cell) . '</td>';
			}
			$html .= '</tr>';
		}
		return $html . '</table>';
	}

	/**
	 * Convert one block of inline Markdown to Asana HTML. Asana has no supported
	 * line-break tag (<br> is rejected), so soft breaks become literal newlines,
	 * which Asana renders as line breaks inside <body>.
	 */
	private function inline(string $text): string {
		$parts = [];
		foreach (explode("\n", $text) as $si => $seg) {
			if ($si > 0) {
				$parts[] = "\n";
			}
			$parts[] = $this->inlineSegment($seg);
		}
		return implode('', $parts);
	}

	private function inlineSegment(string $text): string {
		$pattern = '/(@"user\/[^"]+")|(`[^`]+`)|(\[[^\]]+\]\([^)]+\))|(\*\*[^*]+\*\*)|(~~[^~]+~~)|(\*[^*]+\*)|(_[^_]+_)/';
		$out = '';
		$offset = 0;
		while (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
			$matchStr = $m[0][0];
			$pos = $m[0][1];
			if ($pos > $offset) {
				$out .= $this->escape(substr($text, $offset, $pos - $offset));
			}
			$out .= $this->inlineToken($matchStr);
			$offset = $pos + strlen($matchStr);
		}
		if ($offset < strlen($text)) {
			$out .= $this->escape(substr($text, $offset));
		}
		return $out;
	}

	private function inlineToken(string $tok): string {
		if (preg_match('/^@"user\/([^"]+)"$/', $tok, $m) === 1) {
			// Asana resolves the user from the gid; a self-closing anchor is the
			// documented html_text mention form and is valid XML.
			return '<a data-asana-gid="' . $this->escape($m[1]) . '"/>';
		}
		if (preg_match('/^`(.+)`$/s', $tok, $m) === 1) {
			return '<code>' . $this->escape($m[1]) . '</code>';
		}
		if (preg_match('/^\[([^\]]+)\]\(([^)]+)\)$/', $tok, $m) === 1) {
			return '<a href="' . $this->escape($m[2]) . '">' . $this->escape($m[1]) . '</a>';
		}
		if (preg_match('/^\*\*(.+)\*\*$/s', $tok, $m) === 1) {
			return '<strong>' . $this->escape($m[1]) . '</strong>';
		}
		if (preg_match('/^~~(.+)~~$/s', $tok, $m) === 1) {
			return '<s>' . $this->escape($m[1]) . '</s>';
		}
		if (preg_match('/^[*_](.+)[*_]$/s', $tok, $m) === 1) {
			return '<em>' . $this->escape($m[1]) . '</em>';
		}
		return $this->escape($tok);
	}

	private function escape(string $text): string {
		return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	// --- Asana HTML -> Markdown ----------------------------------------------

	/**
	 * Render Asana restricted HTML (`html_notes` / `html_text`) to Markdown.
	 */
	public function toText(string $html): string {
		$html = trim($html);
		if ($html === '') {
			return '';
		}
		$doc = new \DOMDocument();
		$prev = libxml_use_internal_errors(true);
		// Force UTF-8 and avoid a wrapping <html>/<body> being auto-added twice.
		$doc->loadHTML(
			'<?xml encoding="UTF-8"?><div>' . $html . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING,
		);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);

		$root = $doc->getElementsByTagName('body')->item(0)
			?? $doc->getElementsByTagName('div')->item(0);
		if ($root === null) {
			return '';
		}
		return trim($this->renderBlocks($root));
	}

	/**
	 * Transform stored Asana rich HTML into display HTML for the unity renderer:
	 * literal task-list markers ("[ ]" / "[x]" at the start of a <li>) become the
	 * GitLab-style disabled-checkbox markup the frontend already renders and
	 * toggles (.task-list-item + <input type="checkbox">), and user mentions
	 * (`<a data-asana-gid="gid">`) get a profile href so the rendered pill links to
	 * the user. Everything else passes through unchanged. Asana has no rich-text
	 * checkbox, so the stored form stays "[ ] text"; this only affects display.
	 */
	public function toRenderedHtml(string $html): string {
		$html = preg_replace_callback(
			'/<li>\[([ xX])\]\s+/',
			static function (array $m): string {
				$checked = $m[1] === ' ' ? '' : ' checked';
				return '<li class="task-list-item"><input type="checkbox" disabled' . $checked . '> ';
			},
			$html,
		) ?? $html;
		// Give mention anchors that lack an href a link to the user's Asana profile.
		return preg_replace_callback(
			'/<a\b(?![^>]*\shref=)([^>]*\bdata-asana-gid="([^"]+)"[^>]*)>/i',
			static fn (array $m): string => '<a href="https://app.asana.com/0/profile/' . $m[2] . '"' . $m[1] . '>',
			$html,
		) ?? $html;
	}

	/**
	 * Render a block container's children: block elements are separated by blank
	 * lines; runs of consecutive inline nodes collapse into a single paragraph.
	 */
	private function renderBlocks(\DOMNode $node): string {
		$blocks = [];
		$inline = '';
		$flush = static function () use (&$blocks, &$inline): void {
			$trimmed = trim($inline);
			if ($trimmed !== '') {
				$blocks[] = $trimmed;
			}
			$inline = '';
		};
		foreach ($node->childNodes as $child) {
			if ($this->isBlock($child)) {
				$flush();
				$rendered = $this->walk($child);
				if ($rendered !== '') {
					$blocks[] = $rendered;
				}
			} else {
				$inline .= $this->walk($child);
			}
		}
		$flush();
		return implode("\n\n", $blocks);
	}

	private function isBlock(\DOMNode $node): bool {
		return $node instanceof \DOMElement && in_array(
			strtolower($node->nodeName),
			['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'blockquote', 'pre', 'hr', 'table'],
			true,
		);
	}

	private function walk(\DOMNode $node): string {
		if ($node instanceof \DOMText) {
			return $node->nodeValue ?? '';
		}
		if (!$node instanceof \DOMElement) {
			return '';
		}

		switch (strtolower($node->nodeName)) {
			case 'h1':
				return '# ' . $this->inlineChildren($node);
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				return '## ' . $this->inlineChildren($node);
			case 'strong':
			case 'b':
				return '**' . $this->inlineChildren($node) . '**';
			case 'em':
			case 'i':
				return '*' . $this->inlineChildren($node) . '*';
			case 's':
			case 'del':
			case 'strike':
				return '~~' . $this->inlineChildren($node) . '~~';
			case 'code':
				return '`' . $this->inlineChildren($node) . '`';
			case 'pre':
				return "```\n" . rtrim($this->textContent($node), "\n") . "\n```";
			case 'a':
				$href = $node->getAttribute('href');
				$label = $this->inlineChildren($node);
				return $href !== '' ? '[' . $label . '](' . $href . ')' : $label;
			case 'ul':
			case 'ol':
				$ordered = strtolower($node->nodeName) === 'ol';
				$items = [];
				$idx = 1;
				foreach ($node->childNodes as $li) {
					if ($li instanceof \DOMElement && strtolower($li->nodeName) === 'li') {
						$prefix = $ordered ? ($idx . '. ') : '- ';
						$items[] = $prefix . $this->inlineChildren($li);
						$idx++;
					}
				}
				return implode("\n", $items);
			case 'blockquote':
				$inner = trim($this->inlineChildren($node));
				return '> ' . str_replace("\n", "\n> ", $inner);
			case 'hr':
				return '---';
			case 'br':
				return "\n";
			case 'table':
				return $this->renderTableToMarkdown($node);
			case 'body':
				return $this->renderBlocks($node);
			default:
				return $this->inlineChildren($node);
		}
	}

	/**
	 * Render an Asana <table> back to a GFM markdown table. Cells are all <td>
	 * (Asana emits no <th>); the first row is treated as the header and a delimiter
	 * row is synthesised beneath it.
	 */
	private function renderTableToMarkdown(\DOMElement $table): string {
		$rows = [];
		foreach ($table->getElementsByTagName('tr') as $tr) {
			$cells = [];
			foreach ($tr->childNodes as $cell) {
				if ($cell instanceof \DOMElement && in_array(strtolower($cell->nodeName), ['td', 'th'], true)) {
					$cells[] = trim($this->inlineChildren($cell));
				}
			}
			if ($cells !== []) {
				$rows[] = $cells;
			}
		}
		if ($rows === []) {
			return '';
		}
		$cols = count($rows[0]);
		$lines = ['| ' . implode(' | ', $rows[0]) . ' |'];
		$lines[] = '| ' . implode(' | ', array_fill(0, $cols, '---')) . ' |';
		foreach (array_slice($rows, 1) as $row) {
			$lines[] = '| ' . implode(' | ', $row) . ' |';
		}
		return implode("\n", $lines);
	}

	/** Render a node's children as inline markdown (block separators collapse to ''). */
	private function inlineChildren(\DOMNode $node): string {
		$out = '';
		foreach ($node->childNodes as $child) {
			$out .= $this->walk($child);
		}
		return $out;
	}

	/** Raw text of a node (for <pre> content), preserving newlines. */
	private function textContent(\DOMNode $node): string {
		return $node->textContent;
	}
}
