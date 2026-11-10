<?php

declare(strict_types=1);

namespace Agent001\Assistant\Test\Unit\Model\Catalog;

use Agent001\Assistant\Model\Catalog\Text;
use Magento\Catalog\Helper\Output;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class TextTest extends TestCase
{
    /**
     * Check that plain text drops styles, scripts, directives and entities.
     */
    public function testPlainRemovesMarkup(): void
    {
        $text = new Text(output: $this->createStub(Output::class), logger: new NullLogger());

        $html = '<style>[data-pb-style=A]{color:red}</style><p>Bawełna&nbsp;100%&nbsp;&amp; len</p>'
            . '<script>alert(1)</script>{{widget type="x"}}<b>koniec</b>';

        $this->assertSame('Bawełna 100% & len koniec', $text->plain(html: $html));
    }

    /**
     * Check that invalid UTF-8 keeps the readable part of the value.
     */
    public function testPlainKeepsTextWithInvalidUtf8(): void
    {
        $text = new Text(output: $this->createStub(Output::class), logger: new NullLogger());

        $this->assertSame('Len ?( x', $text->plain(html: "Len \xC3\x28  x"));
    }
}
