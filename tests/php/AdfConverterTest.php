<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\Tests;

use OCA\Unity\Service\AdfConverter;
use PHPUnit\Framework\TestCase;

class AdfConverterTest extends TestCase {

	private AdfConverter $adf;

	protected function setUp(): void {
		parent::setUp();
		$this->adf = new AdfConverter();
	}

	public function testToTextRendersParagraphsAndLinks(): void {
		$doc = [
			'type' => 'doc',
			'version' => 1,
			'content' => [
				['type' => 'paragraph', 'content' => [
					['type' => 'text', 'text' => 'Hello '],
					['type' => 'text', 'text' => 'world', 'marks' => [
						['type' => 'link', 'attrs' => ['href' => 'https://example.com']],
					]],
				]],
				['type' => 'bulletList', 'content' => [
					['type' => 'listItem', 'content' => [
						['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'first']]],
					]],
					['type' => 'listItem', 'content' => [
						['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'second']]],
					]],
				]],
			],
		];
		$text = $this->adf->toText($doc);
		$this->assertStringContainsString('Hello [world](https://example.com)', $text);
		$this->assertStringContainsString('- first', $text);
		$this->assertStringContainsString('- second', $text);
	}

	public function testFromMarkdownParsesBlocksAndMarks(): void {
		$adf = $this->adf->fromMarkdown("# Title\n\n**bold** and *italic* and `code`\n\n- a\n- b");
		$this->assertSame('doc', $adf['type']);
		$this->assertSame('heading', $adf['content'][0]['type']);
		$this->assertSame(1, $adf['content'][0]['attrs']['level']);

		$para = $adf['content'][1];
		$this->assertSame('paragraph', $para['type']);
		$marks = [];
		foreach ($para['content'] as $node) {
			foreach ($node['marks'] ?? [] as $mark) {
				$marks[$mark['type']] = true;
			}
		}
		$this->assertArrayHasKey('strong', $marks);
		$this->assertArrayHasKey('em', $marks);
		$this->assertArrayHasKey('code', $marks);

		$list = $adf['content'][2];
		$this->assertSame('bulletList', $list['type']);
		$this->assertCount(2, $list['content']);
	}

	public function testFromMarkdownLink(): void {
		$adf = $this->adf->fromMarkdown('see [here](https://x.io)');
		$link = null;
		foreach ($adf['content'][0]['content'] as $node) {
			foreach ($node['marks'] ?? [] as $mark) {
				if ($mark['type'] === 'link') {
					$link = $mark;
				}
			}
		}
		$this->assertNotNull($link);
		$this->assertSame('https://x.io', $link['attrs']['href']);
	}

	public function testMarkdownRoundTripKeepsBold(): void {
		$this->assertStringContainsString('**bold**', $this->adf->toText($this->adf->fromMarkdown('**bold**')));
	}

	public function testToTextOnNonArrayIsEmpty(): void {
		$this->assertSame('', $this->adf->toText(null));
		$this->assertSame('', $this->adf->toText('plain string'));
	}

	public function testFromTextProducesValidAdf(): void {
		$adf = $this->adf->fromText("line one\nline two");
		$this->assertSame('doc', $adf['type']);
		$this->assertSame(1, $adf['version']);
		$this->assertCount(2, $adf['content']);
		$this->assertSame('line one', $adf['content'][0]['content'][0]['text']);
	}

	public function testSingleLineRoundTrip(): void {
		$this->assertSame('Hello world', $this->adf->toText($this->adf->fromText('Hello world')));
	}

	public function testFromEmptyTextStillValid(): void {
		$adf = $this->adf->fromText('');
		$this->assertSame('doc', $adf['type']);
		$this->assertNotEmpty($adf['content']);
	}

	public function testFromMarkdownEmitsMentionNode(): void {
		$adf = $this->adf->fromMarkdown('hi @"user/557058:abc-123" there');
		$nodes = $adf['content'][0]['content'];
		$mention = null;
		foreach ($nodes as $node) {
			if (($node['type'] ?? '') === 'mention') {
				$mention = $node;
			}
		}
		$this->assertNotNull($mention, 'a mention node should be emitted');
		$this->assertSame('557058:abc-123', $mention['attrs']['id']);
		// surrounding text is preserved
		$this->assertSame('text', $nodes[0]['type']);
		$this->assertSame('hi ', $nodes[0]['text']);
	}

	public function testFromMarkdownIgnoresPlainAtWord(): void {
		$adf = $this->adf->fromMarkdown('email me @notauser please');
		foreach ($adf['content'][0]['content'] as $node) {
			$this->assertNotSame('mention', $node['type'] ?? '', 'a bare @word must not become a mention');
		}
	}

	public function testToTextRendersMentionWithSingleAt(): void {
		// Jira hydrates the mention text to include the '@'; we must not double it.
		$withAt = ['type' => 'doc', 'version' => 1, 'content' => [
			['type' => 'paragraph', 'content' => [
				['type' => 'text', 'text' => 'hi '],
				['type' => 'mention', 'attrs' => ['id' => '557058:abc', 'text' => '@Jochen Roth']],
			]],
		]];
		$this->assertSame('hi @Jochen Roth', $this->adf->toText($withAt));

		// Text without a leading '@' still gets exactly one.
		$withoutAt = ['type' => 'doc', 'version' => 1, 'content' => [
			['type' => 'paragraph', 'content' => [['type' => 'mention', 'attrs' => ['id' => 'x', 'text' => 'Jane']]]],
		]];
		$this->assertSame('@Jane', $this->adf->toText($withoutAt));

		// Falls back to the id when no text is present.
		$idOnly = ['type' => 'doc', 'version' => 1, 'content' => [
			['type' => 'paragraph', 'content' => [['type' => 'mention', 'attrs' => ['id' => 'acc-1']]]],
		]];
		$this->assertSame('@acc-1', $this->adf->toText($idOnly));
	}

	public function testToTextMentionAsTokenEmitsEditorToken(): void {
		$doc = ['type' => 'doc', 'version' => 1, 'content' => [
			['type' => 'paragraph', 'content' => [
				['type' => 'text', 'text' => 'hi '],
				['type' => 'mention', 'attrs' => ['id' => '557058:abc', 'text' => '@Jochen Roth']],
			]],
		]];
		// Default renders the display name; token mode emits the canonical editor token.
		$this->assertSame('hi @Jochen Roth', $this->adf->toText($doc));
		$this->assertSame('hi @"user/557058:abc"', $this->adf->toText($doc, true));
	}

	public function testExtractMentionsCollectsIdAndLabel(): void {
		$doc = ['type' => 'doc', 'version' => 1, 'content' => [
			['type' => 'paragraph', 'content' => [
				['type' => 'mention', 'attrs' => ['id' => '557058:abc', 'text' => '@Jochen Roth']],
				['type' => 'text', 'text' => ' and '],
				['type' => 'mention', 'attrs' => ['id' => 'acc-2', 'text' => 'Jane']],
			]],
		]];
		$this->assertSame([
			['id' => 'user/557058:abc', 'label' => 'Jochen Roth'],
			['id' => 'user/acc-2', 'label' => 'Jane'],
		], $this->adf->extractMentions($doc));
		$this->assertSame([], $this->adf->extractMentions('not an array'));
	}

	public function testToHtmlRendersMentionAsPill(): void {
		$doc = ['type' => 'doc', 'version' => 1, 'content' => [
			['type' => 'paragraph', 'content' => [
				['type' => 'text', 'text' => 'hi '],
				['type' => 'mention', 'attrs' => ['id' => '557058:abc', 'text' => '@Jochen Roth']],
				['type' => 'text', 'text' => '!'],
			]],
		]];
		// Without a URL builder the mention is a plain span (name only, no '@').
		$html = $this->adf->toHtml($doc);
		$this->assertStringContainsString('<span class="unity-mention">Jochen Roth</span>', $html);
		$this->assertStringNotContainsString('@', $html);
		$this->assertStringContainsString('<p>hi ', $html);

		// With a builder the mention links to the user's profile (accountId → URL).
		$linked = $this->adf->toHtml($doc, static fn (string $id): string => 'https://acme.atlassian.net/jira/people/' . $id);
		$this->assertStringContainsString(
			'<a class="unity-mention" href="https://acme.atlassian.net/jira/people/557058:abc">Jochen Roth</a>',
			$linked,
		);
	}

	public function testToHtmlRendersMarksListsAndCode(): void {
		$doc = ['type' => 'doc', 'version' => 1, 'content' => [
			['type' => 'paragraph', 'content' => [
				['type' => 'text', 'text' => 'bold', 'marks' => [['type' => 'strong']]],
				['type' => 'text', 'text' => ' & ', 'marks' => []],
				['type' => 'text', 'text' => 'link', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://x.test']]]],
			]],
			['type' => 'bulletList', 'content' => [
				['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'one']]]]],
			]],
			['type' => 'codeBlock', 'attrs' => ['language' => 'php'], 'content' => [['type' => 'text', 'text' => '<x>&']]],
		]];
		$html = $this->adf->toHtml($doc);
		$this->assertStringContainsString('<strong>bold</strong>', $html);
		$this->assertStringContainsString(' &amp; ', $html, 'text is HTML-escaped');
		$this->assertStringContainsString('<a href="https://x.test">link</a>', $html);
		$this->assertStringContainsString('<ul><li><p>one</p></li></ul>', $html);
		$this->assertStringContainsString('<pre><code class="language-php">&lt;x&gt;&amp;</code></pre>', $html);
	}
}
