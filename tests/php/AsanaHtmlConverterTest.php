<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\Tests;

use OCA\Unity\Service\AsanaHtmlConverter;
use PHPUnit\Framework\TestCase;

class AsanaHtmlConverterTest extends TestCase {

	private AsanaHtmlConverter $conv;

	protected function setUp(): void {
		parent::setUp();
		$this->conv = new AsanaHtmlConverter();
	}

	// --- fromMarkdown --------------------------------------------------------

	public function testFromMarkdownWrapsInBodyAndEscapes(): void {
		$this->assertSame('<body>a &amp; b &lt;x&gt;</body>', $this->conv->fromMarkdown('a & b <x>'));
	}

	public function testFromMarkdownEmptyIsEmptyBody(): void {
		$this->assertSame('<body></body>', $this->conv->fromMarkdown(''));
	}

	public function testFromMarkdownHeadingsClampToH1AndH2(): void {
		$this->assertSame('<body><h1>Title</h1></body>', $this->conv->fromMarkdown('# Title'));
		$this->assertSame('<body><h2>Sub</h2></body>', $this->conv->fromMarkdown('## Sub'));
		$this->assertSame('<body><h2>Deep</h2></body>', $this->conv->fromMarkdown('#### Deep'));
	}

	public function testFromMarkdownInlineMarks(): void {
		$this->assertSame(
			'<body><strong>b</strong> <em>i</em> <s>s</s> <code>c</code></body>',
			$this->conv->fromMarkdown('**b** *i* ~~s~~ `c`'),
		);
	}

	public function testFromMarkdownLink(): void {
		$this->assertSame(
			'<body>see <a href="https://example.com">site</a></body>',
			$this->conv->fromMarkdown('see [site](https://example.com)'),
		);
	}

	public function testFromMarkdownLists(): void {
		$this->assertSame('<body><ul><li>a</li><li>b</li></ul></body>', $this->conv->fromMarkdown("- a\n- b"));
		$this->assertSame('<body><ol><li>a</li><li>b</li></ol></body>', $this->conv->fromMarkdown("1. a\n2. b"));
	}

	public function testFromMarkdownBlockquoteAndRuleAndCode(): void {
		$this->assertSame('<body><blockquote>quoted</blockquote></body>', $this->conv->fromMarkdown('> quoted'));
		$this->assertSame('<body><hr/></body>', $this->conv->fromMarkdown('---'));
		$this->assertSame('<body><pre>x = 1</pre></body>', $this->conv->fromMarkdown("```\nx = 1\n```"));
	}

	public function testFromMarkdownMultilineUsesNewlineNotBr(): void {
		// Asana rejects <br>; soft breaks must be literal newlines inside <body>.
		$html = $this->conv->fromMarkdown("line1\nline2");
		$this->assertSame("<body>line1\nline2</body>", $html);
		$this->assertStringNotContainsString('<br', $html);
	}

	public function testFromMarkdownCommentContextDropsTasksOnlyTags(): void {
		// html_text (comments) does not support <h1>/<h2>/<hr>; degrade gracefully.
		$this->assertSame('<body><strong>Title</strong></body>', $this->conv->fromMarkdown('# Title', false));
		$this->assertSame('<body>---</body>', $this->conv->fromMarkdown('---', false));
		$heading = $this->conv->fromMarkdown('## Sub', false);
		$this->assertStringNotContainsString('<h1', $heading);
		$this->assertStringNotContainsString('<h2', $heading);
		$this->assertStringNotContainsString('<hr', $this->conv->fromMarkdown('---', false));
	}

	public function testFromMarkdownTableRendersHtmlTable(): void {
		$html = $this->conv->fromMarkdown("| Column | Column |\n| --- | --- |\n| a | b |");
		$this->assertSame(
			'<body><table><tr><td>Column</td><td>Column</td></tr><tr><td>a</td><td>b</td></tr></table></body>',
			$html,
		);
		// Asana rejects <th>; no raw markdown table syntax may leak.
		$this->assertStringNotContainsString('<th', $html);
		$this->assertStringNotContainsString('|', $html);
		$this->assertStringNotContainsString('---', $html);
	}

	public function testFromMarkdownTableConvertsInlineCellMarkup(): void {
		$html = $this->conv->fromMarkdown("| **b** | [x](https://e.com) |\n| --- | --- |\n| a | b |");
		$this->assertStringContainsString('<td><strong>b</strong></td>', $html);
		$this->assertStringContainsString('<td><a href="https://e.com">x</a></td>', $html);
	}

	public function testFromMarkdownTableAfterParagraphWithoutBlankLine(): void {
		// A table immediately following text must not be swallowed into the paragraph.
		$html = $this->conv->fromMarkdown("intro\n| a | b |\n| --- | --- |\n| c | d |");
		$this->assertSame(
			"<body>intro\n<table><tr><td>a</td><td>b</td></tr><tr><td>c</td><td>d</td></tr></table></body>",
			$html,
		);
		$this->assertStringNotContainsString('|', $html);
	}

	public function testToTextTableBecomesMarkdownTable(): void {
		$md = $this->conv->toText('<body><table><tr><td>a</td><td>b</td></tr><tr><td>c</td><td>d</td></tr></table></body>');
		$this->assertSame("| a | b |\n| --- | --- |\n| c | d |", $md);
	}

	public function testTableRoundTrip(): void {
		$table = "| Column | Column |\n| --- | --- |\n| a | b |";
		$this->assertSame($table, $this->conv->toText($this->conv->fromMarkdown($table)));
	}

	public function testFromMarkdownTaskListKeepsMarkerText(): void {
		// Asana has no rich-text checkbox; keep the literal [ ] / [x] marker as text.
		$html = $this->conv->fromMarkdown("- [ ] todo\n- [x] done");
		$this->assertSame('<body><ul><li>[ ] todo</li><li>[x] done</li></ul></body>', $html);
	}

	public function testTaskListRoundTrip(): void {
		$md = "- [ ] a\n- [x] b";
		$this->assertSame($md, $this->conv->toText($this->conv->fromMarkdown($md)));
	}

	public function testToRenderedHtmlUpgradesTaskMarkersToCheckboxes(): void {
		$rendered = $this->conv->toRenderedHtml('<body><ul><li>[ ] todo</li><li>[x] done</li></ul></body>');
		$this->assertSame(
			'<body><ul><li class="task-list-item"><input type="checkbox" disabled> todo</li>'
			. '<li class="task-list-item"><input type="checkbox" disabled checked> done</li></ul></body>',
			$rendered,
		);
	}

	public function testToRenderedHtmlLeavesNonTaskContentUntouched(): void {
		$html = '<body><blockquote>quoted</blockquote><ul><li>plain</li></ul>'
			. '<table><tr><td>a</td></tr></table></body>';
		$this->assertSame($html, $this->conv->toRenderedHtml($html));
	}

	public function testToRenderedHtmlDoesNotDoubleWrapExistingCheckboxes(): void {
		$html = '<ul><li class="task-list-item"><input type="checkbox" disabled> already</li></ul>';
		$this->assertSame($html, $this->conv->toRenderedHtml($html));
	}

	public function testFromMarkdownNoToolbarConstructLeaksRawMarkdown(): void {
		// Everything the editor toolbar can insert must be consumed, not passed through.
		$cases = [
			'**bold**' => '**',
			'*italic*' => '*',
			'~~strike~~' => '~~',
			'`code`' => '`',
			"```\ncode\n```" => '```',
			'## Heading' => '## ',
			'> quote' => '> ',
			'- bullet' => '- ',
			'1. numbered' => '1. ',
			'[link](https://example.com)' => '](',
			"| a | b |\n| --- | --- |\n| c | d |" => '|',
		];
		foreach ($cases as $markdown => $marker) {
			$this->assertStringNotContainsString($marker, $this->conv->fromMarkdown($markdown), 'leaked marker for: ' . $markdown);
		}
	}

	// --- toText --------------------------------------------------------------

	public function testToTextEmptyIsEmpty(): void {
		$this->assertSame('', $this->conv->toText(''));
		$this->assertSame('', $this->conv->toText('<body></body>'));
	}

	public function testToTextHeadingsAndMarks(): void {
		$this->assertSame('# Title', $this->conv->toText('<body><h1>Title</h1></body>'));
		$this->assertSame('## Sub', $this->conv->toText('<body><h2>Sub</h2></body>'));
		$this->assertSame('**b** *i* ~~s~~ `c`', $this->conv->toText('<body><strong>b</strong> <em>i</em> <s>s</s> <code>c</code></body>'));
	}

	public function testToTextLinkAndList(): void {
		$this->assertSame('[site](https://example.com)', $this->conv->toText('<body><a href="https://example.com">site</a></body>'));
		$this->assertSame("- a\n- b", $this->conv->toText('<body><ul><li>a</li><li>b</li></ul></body>'));
		$this->assertSame("1. a\n2. b", $this->conv->toText('<body><ol><li>a</li><li>b</li></ol></body>'));
	}

	public function testToTextDecodesEntities(): void {
		$this->assertSame('a & b <x>', $this->conv->toText('<body>a &amp; b &lt;x&gt;</body>'));
	}

	public function testToTextHandlesMissingBodyWrapper(): void {
		// Asana always wraps in <body>, but be defensive about bare fragments.
		$this->assertSame('**bold**', $this->conv->toText('<strong>bold</strong>'));
	}

	// --- round trip ----------------------------------------------------------

	public function testRoundTrip(): void {
		$cases = [
			'hello world',
			'**bold**',
			'*italic*',
			'a **b** and `c` here',
			"a\nb",
			'[site](https://example.com)',
			'# Title',
			'## Sub',
			"- a\n- b\n- c",
			"1. a\n2. b",
			'> quoted',
			'---',
			'a & b < c > d',
		];
		foreach ($cases as $markdown) {
			$this->assertSame($markdown, $this->conv->toText($this->conv->fromMarkdown($markdown)), 'round trip: ' . $markdown);
		}
	}

	public function testFromMarkdownEmitsMentionAnchor(): void {
		$html = $this->conv->fromMarkdown('ping @"user/1203456789" please', false);
		$this->assertStringContainsString('<a data-asana-gid="1203456789"/>', $html);
		$this->assertStringNotContainsString('user/', $html);
	}

	public function testFromMarkdownIgnoresPlainAtWord(): void {
		$html = $this->conv->fromMarkdown('ping @someone please', false);
		$this->assertStringNotContainsString('data-asana-gid', $html);
	}

	public function testToRenderedHtmlAddsProfileHrefToMention(): void {
		$out = $this->conv->toRenderedHtml('<body>hi <a data-asana-gid="12345">Jane</a></body>');
		$this->assertStringContainsString('<a href="https://app.asana.com/0/profile/12345" data-asana-gid="12345">Jane</a>', $out);
	}

	public function testToRenderedHtmlKeepsExistingHref(): void {
		$in = '<a href="https://example.test/x" data-asana-gid="12345">Jane</a>';
		$this->assertStringContainsString($in, $this->conv->toRenderedHtml($in));
	}
}
