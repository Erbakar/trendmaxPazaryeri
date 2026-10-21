<?php
/**
 * OzyConnect Panel — UBL-TR XML Builder
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2025 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 * @version    1.0.0
 * @since      2025-01-01
 * @link       https://www.oziyweb.com.tr
 */
declare(strict_types=1);

namespace App\Services\Invoice;

final class UblTrBuilder
{
    /**
     * UBL-TR 1.2 formatında fatura XML'i oluştur
     *
     * @param array<string, mixed> $invoice Invoice tablosundan gelen veri
     * @param array<string, mixed> $order   Order tablosundan gelen veri
     * @param array<int, array<string, mixed>> $items OrderItem'lar
     * @param array<string, mixed> $seller  Satıcı bilgileri (settings)
     */
    public function build(array $invoice, array $order, array $items, array $seller): string
    {
        $uuid = $invoice['uuid'] ?? $this->generateUuid();
        $invoiceNo = ($invoice['series'] ?? 'OMR') . str_pad((string) ($invoice['sequence_no'] ?? 1), 9, '0', STR_PAD_LEFT);
        $issueDate = date('Y-m-d', strtotime($invoice['issued_at'] ?? 'now'));
        $issueTime = date('H:i:s', strtotime($invoice['issued_at'] ?? 'now'));
        $profileId = $invoice['type'] === 'e-fatura' ? 'TICARIFATURA' : 'EARSIVFATURA';

        $billingAddress = json_decode($order['billing_address_json'] ?? '{}', true) ?: [];

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"' . "\n";
        $xml .= '         xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"' . "\n";
        $xml .= '         xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">' . "\n";

        // ─── Fatura Başlık ─────────────────────────────
        $xml .= "  <cbc:UBLVersionID>2.1</cbc:UBLVersionID>\n";
        $xml .= "  <cbc:CustomizationID>TR1.2</cbc:CustomizationID>\n";
        $xml .= "  <cbc:ProfileID>{$profileId}</cbc:ProfileID>\n";
        $xml .= "  <cbc:ID>{$invoiceNo}</cbc:ID>\n";
        $xml .= "  <cbc:UUID>{$uuid}</cbc:UUID>\n";
        $xml .= "  <cbc:IssueDate>{$issueDate}</cbc:IssueDate>\n";
        $xml .= "  <cbc:IssueTime>{$issueTime}</cbc:IssueTime>\n";
        $xml .= "  <cbc:InvoiceTypeCode>SATIS</cbc:InvoiceTypeCode>\n";
        $xml .= "  <cbc:DocumentCurrencyCode>TRY</cbc:DocumentCurrencyCode>\n";

        // ─── Satıcı Bilgileri ──────────────────────────
        $xml .= "  <cac:AccountingSupplierParty>\n";
        $xml .= "    <cac:Party>\n";
        $xml .= "      <cac:PartyIdentification>\n";
        $xml .= "        <cbc:ID schemeID=\"VKN\">" . $this->escape($seller['seller_tax_no'] ?? '') . "</cbc:ID>\n";
        $xml .= "      </cac:PartyIdentification>\n";
        $xml .= "      <cac:PartyName>\n";
        $xml .= "        <cbc:Name>" . $this->escape($seller['seller_company_name'] ?? '') . "</cbc:Name>\n";
        $xml .= "      </cac:PartyName>\n";
        $xml .= "      <cac:PostalAddress>\n";
        $xml .= "        <cbc:StreetName>" . $this->escape($seller['seller_address'] ?? '') . "</cbc:StreetName>\n";
        $xml .= "        <cbc:CitySubdivisionName>" . $this->escape($seller['seller_district'] ?? '') . "</cbc:CitySubdivisionName>\n";
        $xml .= "        <cbc:CityName>" . $this->escape($seller['seller_city'] ?? '') . "</cbc:CityName>\n";
        $xml .= "        <cac:Country><cbc:Name>Türkiye</cbc:Name></cac:Country>\n";
        $xml .= "      </cac:PostalAddress>\n";
        $xml .= "      <cac:PartyTaxScheme>\n";
        $xml .= "        <cac:TaxScheme><cbc:Name>" . $this->escape($seller['seller_tax_office'] ?? '') . "</cbc:Name></cac:TaxScheme>\n";
        $xml .= "      </cac:PartyTaxScheme>\n";
        $xml .= "    </cac:Party>\n";
        $xml .= "  </cac:AccountingSupplierParty>\n";

        // ─── Alıcı Bilgileri ───────────────────────────
        $xml .= "  <cac:AccountingCustomerParty>\n";
        $xml .= "    <cac:Party>\n";
        $xml .= "      <cac:PartyIdentification>\n";
        $xml .= "        <cbc:ID schemeID=\"TCKN\">" . $this->escape($billingAddress['tckn'] ?? '11111111111') . "</cbc:ID>\n";
        $xml .= "      </cac:PartyIdentification>\n";
        $xml .= "      <cac:PartyName>\n";
        $xml .= "        <cbc:Name>" . $this->escape($order['customer_name'] ?? '') . "</cbc:Name>\n";
        $xml .= "      </cac:PartyName>\n";
        $xml .= "      <cac:PostalAddress>\n";
        $xml .= "        <cbc:StreetName>" . $this->escape($billingAddress['fullAddress'] ?? $billingAddress['address'] ?? '') . "</cbc:StreetName>\n";
        $xml .= "        <cbc:CitySubdivisionName>" . $this->escape($billingAddress['district'] ?? '') . "</cbc:CitySubdivisionName>\n";
        $xml .= "        <cbc:CityName>" . $this->escape($billingAddress['city'] ?? '') . "</cbc:CityName>\n";
        $xml .= "        <cac:Country><cbc:Name>Türkiye</cbc:Name></cac:Country>\n";
        $xml .= "      </cac:PostalAddress>\n";
        $xml .= "    </cac:Party>\n";
        $xml .= "  </cac:AccountingCustomerParty>\n";

        // ─── Fatura Kalemleri ──────────────────────────
        $lineNo = 0;
        foreach ($items as $item) {
            $lineNo++;
            $qty       = (int) ($item['qty'] ?? 1);
            $unitPrice = (float) ($item['unit_price'] ?? 0);
            $taxRate   = (float) ($item['tax_rate'] ?? 20);
            $lineTotal = (float) ($item['line_total'] ?? ($qty * $unitPrice));
            $netPrice  = $lineTotal / (1 + $taxRate / 100);
            $taxAmount = $lineTotal - $netPrice;

            $xml .= "  <cac:InvoiceLine>\n";
            $xml .= "    <cbc:ID>{$lineNo}</cbc:ID>\n";
            $xml .= "    <cbc:InvoicedQuantity unitCode=\"C62\">{$qty}</cbc:InvoicedQuantity>\n";
            $xml .= "    <cbc:LineExtensionAmount currencyID=\"TRY\">" . number_format($netPrice, 2, '.', '') . "</cbc:LineExtensionAmount>\n";
            $xml .= "    <cac:TaxTotal>\n";
            $xml .= "      <cbc:TaxAmount currencyID=\"TRY\">" . number_format($taxAmount, 2, '.', '') . "</cbc:TaxAmount>\n";
            $xml .= "      <cac:TaxSubtotal>\n";
            $xml .= "        <cbc:TaxableAmount currencyID=\"TRY\">" . number_format($netPrice, 2, '.', '') . "</cbc:TaxableAmount>\n";
            $xml .= "        <cbc:TaxAmount currencyID=\"TRY\">" . number_format($taxAmount, 2, '.', '') . "</cbc:TaxAmount>\n";
            $xml .= "        <cbc:Percent>" . number_format($taxRate, 0) . "</cbc:Percent>\n";
            $xml .= "        <cac:TaxCategory><cac:TaxScheme><cbc:Name>KDV</cbc:Name><cbc:TaxTypeCode>0015</cbc:TaxTypeCode></cac:TaxScheme></cac:TaxCategory>\n";
            $xml .= "      </cac:TaxSubtotal>\n";
            $xml .= "    </cac:TaxTotal>\n";
            $xml .= "    <cac:Item>\n";
            $xml .= "      <cbc:Name>" . $this->escape($item['name'] ?? '') . "</cbc:Name>\n";
            $xml .= "    </cac:Item>\n";
            $xml .= "    <cac:Price>\n";
            $xml .= "      <cbc:PriceAmount currencyID=\"TRY\">" . number_format($unitPrice / (1 + $taxRate / 100), 2, '.', '') . "</cbc:PriceAmount>\n";
            $xml .= "    </cac:Price>\n";
            $xml .= "  </cac:InvoiceLine>\n";
        }

        // ─── Toplam KDV ───────────────────────────────
        $totalNet = (float) ($invoice['net_total'] ?? 0);
        $totalTax = (float) ($invoice['tax_total'] ?? 0);
        $totalGross = (float) ($invoice['total'] ?? 0);

        $xml .= "  <cac:TaxTotal>\n";
        $xml .= "    <cbc:TaxAmount currencyID=\"TRY\">" . number_format($totalTax, 2, '.', '') . "</cbc:TaxAmount>\n";
        $xml .= "  </cac:TaxTotal>\n";

        $xml .= "  <cac:LegalMonetaryTotal>\n";
        $xml .= "    <cbc:LineExtensionAmount currencyID=\"TRY\">" . number_format($totalNet, 2, '.', '') . "</cbc:LineExtensionAmount>\n";
        $xml .= "    <cbc:TaxExclusiveAmount currencyID=\"TRY\">" . number_format($totalNet, 2, '.', '') . "</cbc:TaxExclusiveAmount>\n";
        $xml .= "    <cbc:TaxInclusiveAmount currencyID=\"TRY\">" . number_format($totalGross, 2, '.', '') . "</cbc:TaxInclusiveAmount>\n";
        $xml .= "    <cbc:PayableAmount currencyID=\"TRY\">" . number_format($totalGross, 2, '.', '') . "</cbc:PayableAmount>\n";
        $xml .= "  </cac:LegalMonetaryTotal>\n";

        $xml .= "</Invoice>";

        return $xml;
    }

    /**
     * UUID v4 oluştur
     */
    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
