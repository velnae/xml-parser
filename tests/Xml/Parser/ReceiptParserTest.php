<?php
/**
 * Created by PhpStorm.
 * User: Administrador
 * Date: 18/10/2017
 * Time: 07:10 PM
 */

namespace Tests\Greenter\Xml\Parser;

use Greenter\Model\Sale\Receipt;
use Greenter\Xml\Parser\ReceiptParser;

class ReceiptParserTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @dataProvider providerDocs
     * @param string $filename
     */
    public function testParseRrhh($filename)
    {
        $xml = file_get_contents($filename);
        /**@var $doc Receipt */
        $doc = $this->getParser()->parse($xml);

        $this->assertStringStartsWith('E', $doc->getSerie());
        $this->assertLessThanOrEqual(8, strlen($doc->getCorrelativo()));
        $this->assertEquals(11, strlen($doc->getReceptor()->getNumDoc()));
        $this->assertNotEmpty($doc->getMontoLetras());
        $this->assertNotEmpty($doc->getConcepto());
        $this->assertNotEmpty($doc->getSubTotal());
        $this->assertNotEmpty($doc->getTotal());
        $this->assertNotEmpty($doc->getPorcentaje());
    }

    public function providerDocs()
    {
        return [
          [__DIR__.'/../../Resources/RHE1048344835617.xml'],
          [__DIR__.'/../../Resources/RHE1048344835618.xml']
        ];
    }

    private function getParser()
    {
        return new ReceiptParser();
    }
}