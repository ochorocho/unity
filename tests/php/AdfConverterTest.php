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
}
