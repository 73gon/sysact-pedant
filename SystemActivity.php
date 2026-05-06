<?php
//v2.5.0

require_once __DIR__ . '/traits/LoggerTrait.php';
require_once __DIR__ . '/traits/HelperTrait.php';
require_once __DIR__ . '/traits/ApiTrait.php';
require_once __DIR__ . '/traits/ImportTrait.php';
require_once __DIR__ . '/traits/InvoiceTrait.php';
require_once __DIR__ . '/traits/FetchTrait.php';
require_once __DIR__ . '/traits/DataMapperTrait.php';
require_once __DIR__ . '/traits/DocumentClassifierTrait.php';

class pedantSystemActivity extends AbstractSystemActivityAPI
    {
    // Shared constants (used across multiple traits)
    private const SUCCESS_HTTP_CODES = [200, 201];
    private const MAX_ATTACHMENTS = 3;
    private const VALID_FLAGS = ['normal', 'check_extraction', 'skip_review', 'force_skip'];
    private const DEFAULT_MAX_FILE_SIZE_MB = 20;
    private const RETRY_HTTP_CODES = [404, 500, 502, 503, 0];
    private const FALSE_STATES = ['processing', 'failed', 'uploaded', ''];

    // Shared property (used by InvoiceTrait and DocumentClassifierTrait)
    private int $maxFileSizeMB = 20;

    use LoggerTrait;
    use HelperTrait;
    use ApiTrait;
    use ImportTrait;
    use InvoiceTrait;
    use FetchTrait;
    use DataMapperTrait;
    use DocumentClassifierTrait;

    public function getActivityName()
        {
        return 'Pedant';
        }

    public function getActivityDescription()
        {
        return READ_DESC;
        }

    public function getDialogXml()
        {
        return file_get_contents(__DIR__ . '/dialog.xml');
        }

    /**
     * Returns the UDL (User Defined List) for the given element ID.
     *
     * @param string $udl The UDL identifier.
     * @param string $elementID The element ID for which to get the UDL.
     * @return array|null The UDL as an array of name-value pairs.
     */
    public function getUDL($udl, $elementID)
        {

        if ($elementID == 'importVendor') {
            return [
                ['name' => '-', 'value' => ''],
                ['name' => INTERNALNUMBER, 'value' => '1'],
                ['name' => PROFILNAME, 'value' => '2'],
                ['name' => VENDORNAME, 'value' => '3'],
                ['name' => STREET, 'value' => '4'],
                ['name' => ZIPCODE, 'value' => '5'],
                ['name' => CITY, 'value' => '6'],
                ['name' => COUNTRY, 'value' => '7'],
                ['name' => BANKNUMBER, 'value' => '8'],
                ['name' => TAXNUMBER, 'value' => '9'],
                ['name' => VAT, 'value' => '10'],
                ['name' => RECIPIENTNUMBER, 'value' => '11'],
                ['name' => KVK, 'value' => '12'],
                ['name' => CURRENCY, 'value' => '13'],
                ['name' => BLOCKED, 'value' => '14'],
                ['name' => SORTCODE, 'value' => '15'],
                ['name' => ACCOUNTNUMBER, 'value' => '16'],
            ];
            }

        if ($elementID == 'importRecipient') {
            return [
                ['name' => '-', 'value' => ''],
                ['name' => INTERNALNUMBER, 'value' => '1'],
                ['name' => PROFILNAME, 'value' => '2'],
                ['name' => COUNTRY, 'value' => '3'],
                ['name' => CITY, 'value' => '4'],
                ['name' => ZIPCODE, 'value' => '5'],
                ['name' => STREET, 'value' => '6'],
                ['name' => RECIPIENTNAME, 'value' => '7'],
                ['name' => VAT, 'value' => '8'],
                ['name' => SYNONYMS, 'value' => '9']
            ];
            }

        if ($elementID == 'importCostCenter') {
            return [
                ['name' => '-', 'value' => ''],
                ['name' => INTERNALNUMBER, 'value' => '1'],
                ['name' => PROFILNAME, 'value' => '2'],
                ['name' => RECIPIENTNAME, 'value' => '3'],
            ];
            }

        if ($elementID == 'recipientDetails') {
            return [
                ['name' => '-', 'value' => ''],
                ['name' => RECIPIENTCOMPANYNAME, 'value' => 'recipientCompanyName'],
                ['name' => RECIPIENTNAME, 'value' => 'recipientName'],
                ['name' => STREET, 'value' => 'recipientStreet'],
                ['name' => ZIPCODE, 'value' => 'recipientZipCode'],
                ['name' => CITY, 'value' => 'recipientCity'],
                ['name' => COUNTRY, 'value' => 'recipientCountry'],
                ['name' => RECIPIENTVATNUMBER, 'value' => 'recipientVatNumber'],
                ['name' => INTERNALNUMBER, 'value' => 'recipientInternalNumber']
            ];
            }

        if ($elementID == 'vendorDetails') {
            return [
                ['name' => '-', 'value' => ''],
                ['name' => BANKNUMBER, 'value' => 'vendorBankNumber'],
                ['name' => VAT, 'value' => 'vendorVatNumber'],
                ['name' => TAXNUMBER, 'value' => 'vendorTaxNumber'],
                ['name' => VENDORCOMPANYNAME, 'value' => 'vendorCompanyName'],
                ['name' => STREET, 'value' => 'vendorStreet'],
                ['name' => ZIPCODE, 'value' => 'vendorZipCode'],
                ['name' => CITY, 'value' => 'vendorCity'],
                ['name' => COUNTRY, 'value' => 'vendorCountry'],
                ['name' => DELIVERYPERIOD, 'value' => 'vendorDeliveryPeriod'],
                ['name' => ACCOUNTNUMBER, 'value' => 'vendorAccountNumber'],
                ['name' => INTERNALNUMBER, 'value' => 'vendorInternalNumber'],
                ['name' => COMPANYREGISTRATIONNUMBER, 'value' => 'vendorCompanyRegistrationNumber'],
                ['name' => EMAIL, 'value' => 'vendorEmail']
            ];
            }

        if ($elementID == 'invoiceDetails') {
            return [
                ['name' => '-', 'value' => ''],
                ['name' => TAXRATE1, 'value' => 'taxRate1'],
                ['name' => TAXRATE2, 'value' => 'taxRate2'],
                ['name' => TAXRATE3, 'value' => 'taxRate3'],
                ['name' => TAXRATE4, 'value' => 'taxRate4'],
                ['name' => TAXRATE5, 'value' => 'taxRate5'],
                ['name' => INVOICENUMBER, 'value' => 'invoiceNumber'],
                ['name' => DATE, 'value' => 'date'],
                ['name' => NETAMOUNT, 'value' => 'netAmount'],
                ['name' => TAXAMOUNT, 'value' => 'taxAmount'],
                ['name' => GROSSAMOUNT, 'value' => 'grossAmount'],
                ['name' => TAXRATE, 'value' => 'taxRate'],
                ['name' => PROJECTNUMBER, 'value' => 'projectNumber'],
                ['name' => PURCHASEORDER, 'value' => 'purchaseOrder'],
                ['name' => PURCHASEDATE, 'value' => 'purchaseDate'],
                ['name' => HASDISCOUNT, 'value' => 'hasDiscount'],
                ['name' => REFUND, 'value' => 'refund'],
                ['name' => DISCOUNTPERCENTAGE, 'value' => 'discountPercentage'],
                ['name' => DISCOUNTAMOUNT, 'value' => 'discountAmount'],
                ['name' => DISCOUNTDATE, 'value' => 'discountDate'],
                ['name' => INVOICETYPE, 'value' => 'invoiceType'],
                ['name' => TYPEINV, 'value' => 'type'],
                ['name' => NOTE, 'value' => 'note'],
                ['name' => STATUS, 'value' => 'status'],
                ['name' => CURRENCY, 'value' => 'currency'],
                ['name' => RESOLVEDISSUES, 'value' => 'resolvedIssuesCount'],
                ['name' => HASPROCESSINGISSUES, 'value' => 'hasProcessingIssues'],
                ['name' => DELIVERYDATE, 'value' => 'deliveryDate'],
                ['name' => ESRREFERENCENUMBER, 'value' => 'esrReferenceNumber']
            ];
            }

        if ($elementID == 'positionDetails') {
            return [
                ['name' => '-', 'value' => ''],
                ['name' => POSITIONNUMBER, 'value' => 'positionNumber'],
                ['name' => SINGLENETPRICE, 'value' => 'singleNetPrice'],
                ['name' => NETAMOUNT, 'value' => 'singleNetAmount'],
                ['name' => QUANTITY, 'value' => 'quantity'],
                ['name' => UNITOFMEASURECODE, 'value' => 'unitOfMeasureCode'],
                ['name' => ARTICLENUMBER, 'value' => 'articleNumber'],
                ['name' => ARTICLENAME, 'value' => 'articleName'],
                ['name' => ITEMDESCRIPTION, 'value' => 'itemDescription'],
                ['name' => VATRATEPERINVOICELINE, 'value' => 'vatRatePerLine']
            ];
            }

        if ($elementID == 'auditTrailDetails') {
            return [
                ['name' => '-', 'value' => ''],
                ['name' => USERNAME, 'value' => 'auditTrailUserName'],
                ['name' => TYPE, 'value' => 'auditTrailType'],
                ['name' => SUBTYPE, 'value' => 'auditTrailSubType'],
                ['name' => COMMENT, 'value' => 'auditTrailComment']
            ];
            }

        if ($elementID == 'rejectionDetails') {
            return [
                ['name' => '-', 'value' => ''],
                ['name' => REJECTREASON, 'value' => 'rejectReason'],
                ['name' => CODE, 'value' => 'rejectionCode'],
                ['name' => TYPE, 'value' => 'rejectionType'],
                ['name' => VIOLATIONS, 'value' => 'violations']
            ];
            }

        if ($elementID == 'attachments') {
            return [
                ['name' => '-', 'value' => ''],
                ['name' => E_INVOICEPDF, 'value' => 'e_invoicePDF'],
                ['name' => E_INVOICEREPORT, 'value' => 'e_invoiceReport'],
                ['name' => E_INVOICEATTACHMENT1, 'value' => 'e_invoiceAttachments'],
                ['name' => AUDITTRAILCSV, 'value' => 'auditTrailCSV']
            ];
            }

        if ($elementID == 'workflowDetails') {
            return [
                ['name' => '-', 'value' => ''],
                ['name' => DIREKT, 'value' => 'direkt']
            ];
            }

        if ($elementID == 'classificationDetails') {
            return [
                ['name' => '-', 'value' => ''],
                ['name' => DC_CLASSIFIERNUMBER, 'value' => 'documentClassifierNumber'],
                ['name' => DC_DOCUMENTTYPE, 'value' => 'documentType'],
                ['name' => DC_VENDORCOMPANYNAME, 'value' => 'vendorCompanyName'],
                ['name' => DC_RECIPIENTCOMPANYNAME, 'value' => 'recipientCompanyName'],
                ['name' => DC_ISSUEDATE, 'value' => 'issueDate'],
                ['name' => DC_CONFIDENCES, 'value' => 'documentClassifierConfidence'],
            ];
            }
        $this->logDebug('getUDL() called', ['udl' => $udl, 'elementID' => $elementID]);
        return null;
        }
    }

