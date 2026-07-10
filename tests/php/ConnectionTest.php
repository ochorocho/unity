<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\Tests;

use OCA\Unity\Model\Connection;
use PHPUnit\Framework\TestCase;

class ConnectionTest extends TestCase {

	public function testNormalizeBaseUrlAddsHttpsWhenMissing(): void {
		$this->assertSame('https://gitlab.com', Connection::normalizeBaseUrl('gitlab.com'));
		$this->assertSame('https://your-site.atlassian.net', Connection::normalizeBaseUrl('your-site.atlassian.net/'));
	}

	public function testNormalizeBaseUrlKeepsExistingScheme(): void {
		$this->assertSame('https://gitlab.com', Connection::normalizeBaseUrl('https://gitlab.com/'));
		$this->assertSame('http://localhost:8080', Connection::normalizeBaseUrl('http://localhost:8080'));
	}

	public function testNormalizeBaseUrlEmptyStaysEmpty(): void {
		$this->assertSame('', Connection::normalizeBaseUrl('  '));
	}

	public function testSecretsNotExposedInJson(): void {
		$c = new Connection('id', 'jira', 'L', 'https://x', 'u', 'secret-token', 'tempo-secret');
		$json = $c->jsonSerialize();
		$this->assertArrayNotHasKey('token', $json);
		$this->assertTrue($json['hasToken']);
		$this->assertTrue($json['hasTempoToken']);
	}
}
