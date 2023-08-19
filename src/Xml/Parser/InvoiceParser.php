<?php
/**
 * Created by PhpStorm.
 * User: Giansalex
 * Date: 05/10/2017
 * Time: 08:14
 */

declare(strict_types=1);

namespace Greenter\Xml\Parser;

use DateTime;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMNodeList;
use DOMXPath;
use Greenter\Model\Client\Client;
use Greenter\Model\Company\Address;
use Greenter\Model\Company\Company;
use Greenter\Model\DocumentInterface;
use Greenter\Model\Sale\Detraction;
use Greenter\Model\Sale\Document;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\Legend;
use Greenter\Model\Sale\Prepayment;
use Greenter\Model\Sale\SaleDetail;
use Greenter\Model\Sale\SalePerception;
use Greenter\Parser\DocumentParserInterface;

/**
 * Class InvoiceParser.
 * @package Greenter\Xml\Parser
 */
class InvoiceParser implements DocumentParserInterface
{
    /**
     * @param mixed $value
     * @return DocumentInterface
     */
    public function parse($value): ?DocumentInterface
    {
        $xpt = $this->getXpath($value);
        $inv = new Invoice();
        $docFac = explode('-', $this->defValue($xpt->query('/xt:Invoice/cbc:ID')));
        $issueDate = $this->defValue($xpt->query('/xt:Invoice/cbc:IssueDate'));
        $issueTime = $this->defValue($xpt->query('/xt:Invoice/cbc:IssueTime'));
        $fechaEmision = new \DateTime($issueDate . ' ' . $issueTime);
        $inv->setSerie($docFac[0])
            ->setCorrelativo($docFac[1])
            ->setFechaEmision($fechaEmision)
            ->setTipoOperacion($this->defNodeAttribute($xpt->query('/xt:Invoice/cbc:InvoiceTypeCode'), 'listID'))
            ->setTipoDoc($this->defValue($xpt->query('/xt:Invoice/cbc:InvoiceTypeCode')))
            ->setTipoMoneda($this->defValue($xpt->query('/xt:Invoice/cbc:DocumentCurrencyCode')))
            ->setCompany($this->getCompany($xpt))
            ->setClient($this->getClient($xpt, $xpt->query('/xt:Invoice/cac:AccountingCustomerParty/cac:Party')->item(0)))
            ->setSeller($this->getClient($xpt, $xpt->query('/xt:Invoice/cac:SellerSupplierParty/cac:Party')->item(0)))
            ->setDireccionEntrega($this->getAddress($xpt, $xpt->query('/xt:Invoice/cac:Delivery/cac:DeliveryLocation/cac:Address')->item(0)));


        $fecVen = $this->defValue($xpt->query('/xt:Invoice/cac:DueDate'));
        if (!empty($fecVen)) {
            $inv->setFecVencimiento(new DateTime($fecVen));
        }

        $inv->setLegends(iterator_to_array($this->getLegends($xpt, $inv)));

        $taxTotal = $xpt->query('/xt:Invoice/cac:TaxTotal')->item(0);
        $this->loadTotals($inv, $xpt, $taxTotal);

        //va  primera por que obtiene array de anticipos
        $this->loadExtras($xpt, $inv);

//        $this->loadTributos($inv, $xpt);

        $monetaryTotal = $xpt->query('/xt:Invoice/cac:LegalMonetaryTotal')->item(0);

        $inv
            ->setAnticipos(iterator_to_array($this->getPrepayments($xpt)))
            ->setTotalAnticipos((float)$this->defValue($xpt->query('cbc:PrepaidAmount', $monetaryTotal), '0'))
            ->setSumDsctoGlobal((float)$this->defValue($xpt->query('cbc:AllowanceTotalAmount', $monetaryTotal), '0'))
            ->setMtoOtrosTributos((float)$this->defValue($xpt->query('cbc:ChargeTotalAmount', $monetaryTotal), '0'))
            ->setMtoImpVenta((float)$this->defValue($xpt->query('cbc:PayableAmount', $monetaryTotal), '0'))
            ->setDetails(iterator_to_array($this->getDetails($xpt)));
//            ->setLegends(iterator_to_array($this->getLegends($xpt, $additional)));

        return $inv;
    }

    private function getXpath($value)
    {
        $doc = $value;
        if (!($value instanceof DOMDocument)) {
            $doc = new DOMDocument();
            @$doc->loadXML($value);
        }

        $rootNamespace = $doc->documentElement->namespaceURI;
        $xpt = new DOMXPath($doc);
        $xpt->registerNamespace('xt', $rootNamespace);

        return $xpt;
    }

    private function defValue(DOMNodeList $nodeList, string $default = '')
    {
        if ($nodeList->length == 0) {
            return $default;
        }

        return trim($nodeList->item(0)->nodeValue);
    }

    private function defNodeAttribute(DOMNodeList $nodeList, string $attribute, string $default = '')
    {
        if ($nodeList->length == 0) {
            return $default;
        }

        $node = $nodeList->item(0);
        if (!$node instanceof DOMElement) {
            return $default;
        }

        return $node->getAttribute($attribute);
    }

    private function loadTotals(Invoice $inv, DOMXPath $xpt, DOMNode $node = null)
    {
        if (empty($node)) {
            return;
        }

        $inv->setTotalImpuestos((float)$this->defValue($xpt->query('cbc:TaxAmount')));

        $totals = $xpt->query('cac:TaxSubtotal', $node);
        foreach ($totals as $total) {
            /**@var $total DOMElement */
            $id = trim($this->defValue($xpt->query('cac:TaxCategory/cac:TaxScheme/cbc:ID', $total)));
            $val = (float)$this->defValue($xpt->query('cbc:TaxableAmount', $total), '0');
            switch ($id) {
                case '2000':
                    $inv->setMtoBaseIsc($val)
                        ->setMtoISC((float)$this->defValue($xpt->query('cbc:TaxAmount', $total), '0'));
                    break;
                case '1000':
                    $inv->setMtoOperGravadas($val)
                        ->setMtoIGV((float)$this->defValue($xpt->query('cbc:TaxAmount', $total), '0'));
                    break;
                case '9998':
                    $inv->setMtoOperInafectas($val);
                    break;
                case '9997':
                    $inv->setMtoOperExoneradas($val);
                    break;
                case '9996':
                    $inv->setMtoOperGratuitas($val)
                        ->setMtoIGVGratuitas((float)$this->defValue($xpt->query('cbc:TaxAmount', $total), '0'));
                    break;
                case '9995':
                    $inv->setMtoOperExportacion($val);
                    break;
                case '1016':
                    $inv->setMtoBaseIvap($val)
                        ->setMtoIvap((float)$this->defValue($xpt->query('cbc:TaxAmount', $total), '0'));
                    break;
                case '9999':
                    $inv->setMtoBaseOth($val)
                        ->setMtoOtrosTributos((float)$this->defValue($xpt->query('cbc:TaxAmount', $total), '0'));
                    break;
                case '7152':
                    $inv->setIcbper($val);
                    break;
//
//                case '2001':
//                    $inv->setPerception((new SalePerception())
//                        ->setCodReg($this->defNodeAttribute($xpt->query('cbc:ID', $total), 'schemeID'))
//                        ->setMto($val)
//                        ->setMtoBase((float)$this->defValue($xpt->query('sac:ReferenceAmount', $total), '0'))
//                        ->setMtoTotal((float)$this->defValue($xpt->query('sac:TotalAmount', $total), '0')));
//                    break;
//                case '2003':
//                    $inv->setDetraccion((new Detraction())
//                        ->setMount($val)
//                        ->setPercent((float)$this->defValue($xpt->query('cbc:Percent', $total), '0'))
//                        ->setValueRef((float)$this->defValue($xpt->query('sac:ReferenceAmount', $total), '0')));
//                    break;
//                case '2005':
//                    $inv->setMtoDescuentos($val);
                    break;
            }
        }
    }

    private function loadTributos(Invoice $inv, DOMXPath $xpt)
    {
        $taxs = $xpt->query('/xt:Invoice/cac:TaxTotal');
        foreach ($taxs as $tax) {
            $name = $this->defValue($xpt->query('cac:TaxSubtotal/cac:TaxCategory/cac:TaxScheme/cbc:Name', $tax));
            $val = (float)$this->defValue($xpt->query('cbc:TaxAmount', $tax), '0');
            switch ($name) {
                case 'IGV':
                    $inv->setMtoIGV($val);
                    break;
                case 'ISC':
                    $inv->setMtoISC($val);
                    break;
                case 'OTROS':
                    $inv->setSumOtrosCargos($val);
                    break;
            }
        }
    }

    private function getPrepayments(DOMXPath $xpt)
    {
        $nodes = $xpt->query('/xt:Invoice/cac:PrepaidPayment');
        if ($nodes->length == 0) {
            return;
        }
        foreach ($nodes as $node) {
            yield (new Prepayment())
                ->setTotal((float)$this->defValue($xpt->query('cbc:PaidAmount', $node), '0'))
                ->setTipoDocRel($this->defNodeAttribute($xpt->query('cbc:ID', $node), 'schemeID'))
                ->setNroDocRel($this->defValue($xpt->query('cbc:ID', $node)));
        }
    }

    private function getLegends(DOMXPath $xpt, Invoice $inv)
    {
        $legends = $xpt->query('/xt:Invoice/cbc:Note');

        if (!$legends)
            return;

        foreach ($legends as $legend) {
            /**@var $legend DOMElement */
            $code = $legend->getAttribute('languageLocaleID');
            $value = trim($legend->nodeValue);

            if ($code) {
                yield (new Legend())
                    ->setCode($code)
                    ->setValue($value);
            } else {
                $inv->setObservacion($value);
            }
        }

    }

    private function getClient(DOMXPath $xp, DOMNode $node = null)
    {
        if (!$node)
            return null;

        $nodeAddr = $xp->query('cac:PartyLegalEntity/cac:RegistrationAddress', $node)->item(0);

        $cl = new Client();
        $cl->setNumDoc($this->defValue($xp->query('cac:PartyIdentification/cbc:ID', $node)))
            ->setTipoDoc($this->defNodeAttribute($xp->query('cac:PartyIdentification/cbc:ID', $node), 'schemeID'))
            ->setRznSocial($this->defValue($xp->query('cac:PartyLegalEntity/cbc:RegistrationName', $node)))
            ->setAddress($this->getAddress($xp, $nodeAddr))
            ->setEmail($this->defValue($xp->query('cac:Contact/cbc:ElectronicMail', $node)))
            ->setTelephone($this->defValue($xp->query('cac:Contact/cbc:Telephone', $node)));

        return $cl;
    }

    private function getCompany(DOMXPath $xp)
    {
        $node = $xp->query('/xt:Invoice/cac:AccountingSupplierParty/cac:Party')->item(0);
        $nodeAddr = $xp->query('cac:PartyLegalEntity/cac:RegistrationAddress', $node)->item(0);

        $cl = new Company();
        $cl->setRuc($this->defValue($xp->query('cac:PartyIdentification/cbc:ID', $node)))
            ->setNombreComercial($this->defValue($xp->query('cac:PartyName/cbc:Name', $node)))
            ->setRazonSocial($this->defValue($xp->query('cac:PartyLegalEntity/cbc:RegistrationName', $node)))
            ->setAddress($this->getAddress($xp, $nodeAddr))
            ->setEmail($this->defValue($xp->query('cac:Contact/cbc:ElectronicMail', $node)))
            ->setTelephone($this->defValue($xp->query('cac:Contact/cbc:Telephone', $node)));

        return $cl;
    }

    private function loadExtras(DOMXPath $xpt, Invoice $inv)
    {
        $inv->setCompra($this->defValue($xpt->query('/xt:Invoice/cac:OrderReference/cbc:ID')));
        $inv->setGuias(iterator_to_array($this->getGuias($xpt)));

        $additionals = $xpt->query('/xt:Invoice/cac:AdditionalDocumentReference');

        if ($additionals->length == 0)
            return;

        $anticipos = [];
        $relDocs = [];
        foreach ($additionals as $additional) {
            $statusCode = $this->defValue($xpt->query('cbc:DocumentStatusCode', $additional));

            if ($statusCode) {
                $anticipos[] = (new Prepayment())
                    ->setNroDocRel($this->defValue($xpt->query('cbc:ID', $additional)))
                    ->setTipoDocRel($this->defValue($xpt->query('cbc:DocumentTypeCode', $additional)));
            } else {
                $relDocs[] = (new Document())
                    ->setTipoDoc($this->defValue($xpt->query('cbc:DocumentTypeCode', $additional)))
                    ->setNroDoc($this->defValue($xpt->query('cbc:ID', $additional)));
            }
        }

        if ($anticipos)
            $inv->setAnticipos($anticipos);
        if ($relDocs)
            $inv->setRelDocs($relDocs);
    }

    private function getGuias(DOMXPath $xpt)
    {
        $guias = $xpt->query('/xt:Invoice/cac:DespatchDocumentReference');
        if ($guias->length == 0) {
            return;
        }

        foreach ($guias as $guia) {
            $item = new Document();
            $item->setTipoDoc($this->defValue($xpt->query('cbc:DocumentTypeCode', $guia)));
            $item->setNroDoc($this->defValue($xpt->query('cbc:ID', $guia)));

            yield $item;
        }
    }

    /**
     * @param DOMXPath $xp
     * @param DOMNode $node
     * @return Address|null
     */
    private function getAddress(DOMXPath $xp, $node)
    {
        if ($node) {
            return (new Address())
                ->setDireccion($this->defValue($xp->query('cac:AddressLine/cbc:Line', $node)))
                ->setUrbanizacion($this->defValue($xp->query('cbc:CitySubdivisionName', $node)))
                ->setDepartamento($this->defValue($xp->query('cbc:CountrySubentity', $node)))
                ->setProvincia($this->defValue($xp->query('cbc:CityName', $node)))
                ->setDistrito($this->defValue($xp->query('cbc:District', $node)))
                ->setUbigueo($this->defValue($xp->query('cbc:ID', $node)))
                ->setCodigoPais($this->defValue($xp->query('cac:Country/cbc:IdentificationCode', $node)));
        }

        return null;
    }

    private function getDetails(DOMXPath $xpt)
    {
        $nodes = $xpt->query('/xt:Invoice/cac:InvoiceLine');

        foreach ($nodes as $node) {
            $det = new SaleDetail();
            $det->setCantidad((float)$this->defValue($xpt->query('cbc:InvoicedQuantity', $node), '0'))
                ->setUnidad($this->defNodeAttribute($xpt->query('cbc:InvoicedQuantity', $node), 'unitCode'))
                ->setMtoValorVenta((float)$this->defValue($xpt->query('cbc:LineExtensionAmount', $node)))
                ->setMtoValorUnitario((float)$this->defValue($xpt->query('cac:Price/cbc:PriceAmount', $node)))
                ->setDescripcion($this->defValue($xpt->query('cac:Item/cbc:Description', $node)))
                ->setCodProducto($this->defValue($xpt->query('cac:Item/cac:SellersItemIdentification/cbc:ID', $node)))
                ->setCodProdSunat($this->defValue($xpt->query('cac:Item/cac:CommodityClassification/cbc:ItemClassificationCode', $node)));

            $this->loadTaxDetail($det, $xpt, $node);
            $this->loadDescuentosDetail($det, $xpt, $node);
            $this->loadPricesDetail($det, $xpt, $node);

            yield $det;
        }
    }

    private function loadTaxDetail(SaleDetail $detail, DOMXPath $xpt, DOMNode $detailNode)
    {
        $taxs = $xpt->query('cac:TaxTotal', $detailNode);
        foreach ($taxs as $tax) {
            $name = $this->defValue($xpt->query('cac:TaxSubtotal/cac:TaxCategory/cac:TaxScheme/cbc:Name', $tax));
            $val = (float)$this->defValue($xpt->query('cbc:TaxAmount', $tax), '0');
            switch ($name) {
                case 'IGV':
                    $detail->setIgv($val)
                        ->setTipAfeIgv($this->defValue($xpt->query('cac:TaxSubtotal/cac:TaxCategory/cbc:TaxExemptionReasonCode', $tax)));
                    break;
                case 'ISC':
                    $detail->setIsc($val)
                        ->setTipSisIsc($this->defValue($xpt->query('cac:TaxSubtotal/cac:TaxCategory/cbc:TierRange', $tax)));
                    break;
            }
        }
    }

    private function loadDescuentosDetail(SaleDetail $detail, DOMXPath $xpt, DOMNode $detailNode)
    {
        $descs = $xpt->query('cac:AllowanceCharge', $detailNode);
        foreach ($descs as $desc) {
            $charge = $this->defValue($xpt->query('cbc:ChargeIndicator', $desc));
            $charge = trim($charge);
            if ($charge == 'false') {
                $val = (float)$this->defValue($xpt->query('cbc:Amount', $desc), '0');
                $detail->setDescuento($val);
            }
        }
    }

    private function loadPricesDetail(SaleDetail $detail, DOMXPath $xpt, DOMNode $detailNode)
    {
        $prices = $xpt->query('cac:PricingReference', $detailNode);
        foreach ($prices as $price) {
            $code = $this->defValue($xpt->query('cac:AlternativeConditionPrice/cbc:PriceTypeCode', $price));
            $value = (float)$this->defValue($xpt->query('cac:AlternativeConditionPrice/cbc:PriceAmount', $price), '0');
            $code = trim($code);

            switch ($code) {
                case '01':
                    $detail->setMtoPrecioUnitario($value);
                    break;
                case '02':
                    $detail->setMtoValorGratuito($value);
                    break;
            }
        }
    }
}
