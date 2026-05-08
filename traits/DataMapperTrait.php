<?php
/**
 * DataMapperTrait — Maps and stores extracted invoice data into JobRouter tables.
 *
 * Contains storeList() which maps API response data to output parameters
 * (recipient, vendor, invoice, positions, audit trail, rejections, attachments, workflow)
 * and convertAuditTrailToCSV() for audit trail export.
 */
trait DataMapperTrait
  {
  /**
   * Stores the list of invoice details in the system activity.
   *
   * @param array $data The data containing invoice details.
   */
  public function storeList($data): void
    {
    try {
      $type = $this->getSystemActivityVar('TYPE');
      $this->logInfo('Storing invoice data', ['type' => $type]);

      if (!isset($data['data'][0])) {
        $this->logError('Invalid data structure in storeList', null, ['data_keys' => array_keys($data)]);
        throw new JobRouterException('Invalid invoice data structure');
        }

      $dataItem = $data['data'][0];
      $eInvoiceFields = $dataItem['eInvoiceFields'] ?? [];
      $paymentInstructionsCreditTransfer = $eInvoiceFields['paymentInstructionsCreditTransfer'][0] ?? [];
      $document = $dataItem['document'] ?? [];
      $auditTrailItem = $dataItem['auditTrail'][0] ?? [];
      $taxRates = $dataItem['taxRates'] ?? [];
      $vatBreakdown = $eInvoiceFields['vatBreakdown'] ?? [];
      $recipientEntity = $dataItem['recipientEntity'] ?? [];
      $vendorEntity = $dataItem['vendorEntity'] ?? [];

      $this->logDebug('Data structure extracted', [
        'hasEInvoiceFields' => !empty($eInvoiceFields),
        'taxRatesCount' => count($taxRates),
        'vatBreakdownCount' => count($vatBreakdown),
        'status' => $dataItem['status'] ?? 'unknown',
      ]);

      // ── Recipient Details ────────────────────────────────────────
      $attributes1 = $this->resolveOutputParameterListAttributes('recipientDetails');
      $values1 = ($type == "e_invoice") ? [
        'recipientCompanyName' => $eInvoiceFields['recipientName'],
        'recipientName' => $eInvoiceFields['recipientContactPersonName'],
        'recipientStreet' => $eInvoiceFields['recipientPostalAddressAddressLines'][0],
        'recipientZipCode' => $eInvoiceFields['recipientPostalAddressPostCode'],
        'recipientCity' => $eInvoiceFields['recipientPostalAddressCity'],
        'recipientCountry' => $eInvoiceFields['recipientPostalAddressCountryCode'],
        'recipientVatNumber' => $eInvoiceFields['recipientVatIdentifier'] ?? '',
        'recipientInternalNumber' => $recipientEntity['internalNumber'] ?? ''
      ] : [
        'recipientCompanyName' => $dataItem["recipientCompanyName"] ?? '',
        'recipientName' => $dataItem["recipientName"] ?? '',
        'recipientStreet' => $dataItem["recipientStreet"] ?? '',
        'recipientZipCode' => $dataItem["recipientZipCode"] ?? '',
        'recipientCity' => $dataItem["recipientCity"] ?? '',
        'recipientCountry' => $dataItem["recipientCountry"] ?? '',
        'recipientVatNumber' => $dataItem["recipientVatNumber"] ?? '',
        'recipientInternalNumber' => $dataItem["recipientEntity"]["internalNumber"] ?? ''
      ];

      $this->logDebug('Recipient values mapped', ['values' => $values1]);

      foreach ($attributes1 as $attribute) {
        try {
          $this->setTableValue($attribute['value'], $values1[$attribute['id']] ?? '');
          } catch (Exception $e) {
          $this->logWarning('Failed to set recipient table value', ['attribute' => $attribute['id'], 'error' => $e->getMessage()]);
          }
        }

      // ── Vendor Details ───────────────────────────────────────────
      $attributes2 = $this->resolveOutputParameterListAttributes('vendorDetails');
      $values2 = ($type == "e_invoice") ? [
        'vendorBankNumber' => $paymentInstructionsCreditTransfer['paymentAccountIdentifierId'][0],
        'vendorVatNumber' => $eInvoiceFields['vendorVatIdentifier'],
        'vendorTaxNumber' => $eInvoiceFields['vendorTaxRegistrationIdentifier'],
        'vendorCompanyName' => $eInvoiceFields['vendorName'],
        'vendorStreet' => $eInvoiceFields['vendorPostalAddressAddressLines'][0],
        'vendorZipCode' => $eInvoiceFields['vendorPostalAddressPostCode'],
        'vendorCity' => $eInvoiceFields['vendorPostalAddressCity'],
        'vendorCountry' => $eInvoiceFields['vendorPostalAddressCountryCode'],
        'vendorEmail' => $eInvoiceFields['vendorContactEmailAddress'] ?? '',
        'vendorDeliveryPeriod' => '',
        'vendorAccountNumber' => '',
        'vendorInternalNumber' => $vendorEntity['internalNumber'] ?? ''
      ] : [
        'vendorBankNumber' => $dataItem["bankNumber"] ?? '',
        'vendorVatNumber' => $dataItem["vat"] ?? '',
        'vendorTaxNumber' => $dataItem["taxNumber"] ?? '',
        'vendorCompanyName' => $dataItem["vendorCompanyName"] ?? '',
        'vendorStreet' => $dataItem["vendorStreet"] ?? '',
        'vendorZipCode' => $dataItem["vendorZipCode"] ?? '',
        'vendorCity' => $dataItem["vendorCity"] ?? '',
        'vendorCountry' => $dataItem["vendorCountry"] ?? '',
        'vendorDeliveryPeriod' => $dataItem["deliveryPeriod"] ?? '',
        'vendorAccountNumber' => $dataItem["accountNumber"] ?? '',
        'vendorEmail' => $dataItem["vendorEmail"] ?? '',
        'vendorInternalNumber' => $dataItem["vendorEntity"]["internalNumber"] ?? '',
        'vendorCompanyRegistrationNumber' => $dataItem['KVKNumber'] ?? ''
      ];

      $this->logDebug('Vendor values mapped', ['values' => $values2]);

      foreach ($attributes2 as $attribute) {
        try {
          $this->setTableValue($attribute['value'], $values2[$attribute['id']] ?? '');
          } catch (Exception $e) {
          $this->logWarning('Failed to set vendor table value', ['attribute' => $attribute['id'], 'error' => $e->getMessage()]);
          }
        }

      // ── Invoice Details ──────────────────────────────────────────
      $attributes3 = $this->resolveOutputParameterListAttributes('invoiceDetails');

      $values3 = ($type == "e_invoice") ? [
        'taxRate1' => count($vatBreakdown) > 0 ? $vatBreakdown[0]["vatCategoryTaxableAmount"] . ";" . $vatBreakdown[0]["vatCategoryTaxAmount"] . ";" . $vatBreakdown[0]["vatCategoryRate"] : '',
        'taxRate2' => count($vatBreakdown) > 1 ? $vatBreakdown[1]["vatCategoryTaxableAmount"] . ";" . $vatBreakdown[1]["vatCategoryTaxAmount"] . ";" . $vatBreakdown[1]["vatCategoryRate"] : '',
        'taxRate3' => count($vatBreakdown) > 2 ? $vatBreakdown[2]["vatCategoryTaxableAmount"] . ";" . $vatBreakdown[2]["vatCategoryTaxAmount"] . ";" . $vatBreakdown[2]["vatCategoryRate"] : '',
        'taxRate4' => count($vatBreakdown) > 3 ? $vatBreakdown[3]["vatCategoryTaxableAmount"] . ";" . $vatBreakdown[3]["vatCategoryTaxAmount"] . ";" . $vatBreakdown[3]["vatCategoryRate"] : '',
        'taxRate5' => count($vatBreakdown) > 4 ? $vatBreakdown[4]["vatCategoryTaxableAmount"] . ";" . $vatBreakdown[4]["vatCategoryTaxAmount"] . ";" . $vatBreakdown[4]["vatCategoryRate"] : '',
        'invoiceNumber' => $eInvoiceFields['invoiceNumber'],
        'date' => date("Y-m-d", strtotime(str_replace(".", "-", $eInvoiceFields['invoiceIssueDate']))) . ' 00:00:00.000',
        'netAmount' => $eInvoiceFields['sumOfInvoiceLineNetAmount'],
        'taxAmount' => $eInvoiceFields['invoiceTotalVatAmount'],
        'grossAmount' => $eInvoiceFields['invoiceTotalAmountWithVat'],
        'taxRate' => count($vatBreakdown) == 1 ? $vatBreakdown[0]['vatCategoryRate'] : '',
        'projectNumber' => $eInvoiceFields['projectReferenceId'],
        'purchaseOrder' => '',
        'purchaseDate' => '',
        'hasDiscount' => '',
        'refund' => '',
        'discountPercentage' => '',
        'discountAmount' => '',
        'discountDate' => '',
        'invoiceType' => $eInvoiceFields['invoiceTypeCode'],
        'type' => $type,
        'note' => $document['note'],
        'status' => $dataItem['status'],
        'currency' => $eInvoiceFields['invoiceCurrencyCode'],
        'resolvedIssuesCount' => $dataItem['resolvedIssuesCount'],
        'hasProcessingIssues' => $dataItem['hasProcessingIssues'],
        'deliveryDate' => date("Y-m-d", strtotime(str_replace(".", "-", $eInvoiceFields['deliveryInformationActualDeliveryDate']))) . ' 00:00:00.000',
        'esrReferenceNumber' => ''
      ] : [
        'taxRate1' => count($taxRates) > 0 ? ($taxRates[0]["subNetAmount"] ?? '') . ";" . ($taxRates[0]["subTaxAmount"] ?? '') . ";" . ($taxRates[0]["subTaxRate"] ?? '') : '',
        'taxRate2' => count($taxRates) > 1 ? ($taxRates[1]["subNetAmount"] ?? '') . ";" . ($taxRates[1]["subTaxAmount"] ?? '') . ";" . ($taxRates[1]["subTaxRate"] ?? '') : '',
        'taxRate3' => count($taxRates) > 2 ? ($taxRates[2]["subNetAmount"] ?? '') . ";" . ($taxRates[2]["subTaxAmount"] ?? '') . ";" . ($taxRates[2]["subTaxRate"] ?? '') : '',
        'taxRate4' => count($taxRates) > 3 ? ($taxRates[3]["subNetAmount"] ?? '') . ";" . ($taxRates[3]["subTaxAmount"] ?? '') . ";" . ($taxRates[3]["subTaxRate"] ?? '') : '',
        'taxRate5' => count($taxRates) > 4 ? ($taxRates[4]["subNetAmount"] ?? '') . ";" . ($taxRates[4]["subTaxAmount"] ?? '') . ";" . ($taxRates[4]["subTaxRate"] ?? '') : '',
        'invoiceNumber' => $dataItem["invoiceNumber"] ?? '',
        'date' => date("Y-m-d", strtotime(str_replace(".", "-", $dataItem["issueDate"] ?? ''))) . ' 00:00:00.000',
        'netAmount' => $dataItem["netAmount"] ?? '',
        'taxAmount' => $dataItem["taxAmount"] ?? '',
        'grossAmount' => $dataItem["amount"] ?? '',
        'taxRate' => $dataItem["taxRate"] ?? '',
        'projectNumber' => $dataItem["projectNumber"] ?? '',
        'purchaseOrder' => $dataItem["purchaseOrder"] ?? '',
        'purchaseDate' => $dataItem["purchaseDate"] ?? '',
        'hasDiscount' => $dataItem["hasDiscount"] ?? '',
        'refund' => $dataItem["refund"] ?? '',
        'discountPercentage' => $dataItem["discountPercentage"] ?? '',
        'discountAmount' => $dataItem["discountAmount"] ?? '',
        'discountDate' => $dataItem["discountDate"] ?? '',
        'invoiceType' => $dataItem["invoiceType"] ?? '',
        'type' => $type,
        'note' => $dataItem["file"]["note"] ?? '',
        'status' => $dataItem["status"] ?? '',
        'currency' => $dataItem["currency"] ?? '',
        'resolvedIssuesCount' => $dataItem["resolvedIssuesCount"] ?? '',
        'hasProcessingIssues' => $dataItem["hasProcessingIssues"] ?? '',
        'deliveryDate' => date("Y-m-d", strtotime(str_replace(".", "-", $dataItem["deliveryDate"] ?? ''))) . ' 00:00:00.000',
        'esrReferenceNumber' => $dataItem["esrReferenceNumber"] ?? ''
      ];

      $this->logDebug('Invoice values mapped', ['invoiceNumber' => $values3['invoiceNumber'] ?? '', 'status' => $values3['status'] ?? '']);

      foreach ($attributes3 as $attribute) {
        try {
          $this->setTableValue($attribute['value'], $values3[$attribute['id']] ?? '');
          } catch (Exception $e) {
          $this->logWarning('Failed to set invoice table value', ['attribute' => $attribute['id'], 'error' => $e->getMessage()]);
          }
        }

      // ── Audit Trail Details ──────────────────────────────────────
      $attributes4 = $this->resolveOutputParameterListAttributes('auditTrailDetails');
      $auditTrail = $type == "e_invoice" ? $auditTrailItem : $dataItem["auditTrail"];

      $isAutomatic = count($auditTrail) <= 2;
      $userInformationIndex = $isAutomatic ? 0 : 1;

      $values4 = [
        'auditTrailUserName' => $auditTrail[$userInformationIndex]['userName'],
        'auditTrailType' => $auditTrail[$userInformationIndex]['type'],
        'auditTrailSubType' => $auditTrail[$userInformationIndex]['subType'],
        'auditTrailComment' => $auditTrail[$userInformationIndex]['comment']
      ];

      $this->logDebug('Audit trail values mapped', ['values' => $values4]);

      foreach ($attributes4 as $attribute) {
        try {
          $this->setTableValue($attribute['value'], $values4[$attribute['id']] ?? '');
          } catch (Exception $e) {
          $this->logWarning('Failed to set audit trail table value', ['attribute' => $attribute['id'], 'error' => $e->getMessage()]);
          }
        }

      // ── Rejection Details ────────────────────────────────────────
      $attributes5 = $this->resolveOutputParameterListAttributes('rejectionDetails');

      $values5 = [
        'rejectReason' => $type == "e_invoice" ? $dataItem['rejectReason'] : $dataItem['rejectReason'],
        'rejectionCode' => $type == "e_invoice" ? '' : ($dataItem['rejectionType']['code'] ?? null),
        'rejectionType' => $type == "e_invoice" ? '' : ($dataItem['rejectionType']['type'] ?? null)
      ];
      if ($type == "e_invoice") {
        $violations = [];
        foreach ($dataItem['violations'] as $violation) {
          $messages = implode(', ', $violation['messages']);
          $violations[] = $violation['level'] . ': ' . $messages;
          }
        $values5['violations'] = implode(" --- ", $violations);
        } else {
        $values5['violations'] = '';
        }

      $this->logDebug('Rejection values mapped', ['values' => $values5]);

      foreach ($attributes5 as $attribute) {
        try {
          $this->setTableValue($attribute['value'], $values5[$attribute['id']] ?? '');
          } catch (Exception $e) {
          $this->logWarning('Failed to set rejection table value', ['attribute' => $attribute['id'], 'error' => $e->getMessage()]);
          }
        }

      // ── Attachments ──────────────────────────────────────────────
      $attributes6 = $this->resolveOutputParameterListAttributes('attachments');

      if ($type == "e_invoice") {
        // Download PDF
        $urlPDF = $dataItem['eInvoicePdfPath'];
        $tempPath = $this->getTempPath();
        $tempFileName = pathinfo($dataItem['fileName'], PATHINFO_FILENAME);
        $savePath = $tempPath . "/pedant";

        if (!is_dir($savePath)) {
          mkdir($savePath, 0777, true);
          }

        $dataPDF = false;
        try {
          $responseData = $this->makeApiRequest($urlPDF, 'GET');
          if (in_array($responseData['httpCode'], self::SUCCESS_HTTP_CODES)) {
            $dataPDF = $responseData['response'];
            } else {
            $this->logWarning('Failed to download e-invoice PDF', ['url' => $urlPDF, 'httpcode' => $responseData['httpCode']]);
            }
          } catch (JobRouterException $e) {
          $this->logWarning('Failed to download e-invoice PDF', ['url' => $urlPDF, 'error' => $e->getMessage()]);
          }

        $eInvoicePDF = $savePath . "/" . $tempFileName . "_PDF_.pdf";

        if ($dataPDF !== false) {
          $writeResult = file_put_contents($eInvoicePDF, $dataPDF);
          if ($writeResult === false) {
            $this->logWarning('Failed to save e-invoice PDF', ['path' => $eInvoicePDF]);
            }
          }

        $this->setSystemActivityVar('PDFPATH', $eInvoicePDF);

        // Download Report
        $urlReport = $dataItem['reportFilePath'];

        if (!is_dir($savePath)) {
          mkdir($savePath, 0777, true);
          }

        $dataReport = false;
        try {
          $responseData = $this->makeApiRequest($urlReport, 'GET');
          if (in_array($responseData['httpCode'], self::SUCCESS_HTTP_CODES)) {
            $dataReport = $responseData['response'];
            } else {
            $this->logWarning('Failed to download e-invoice report', ['url' => $urlReport, 'httpcode' => $responseData['httpCode']]);
            }
          } catch (JobRouterException $e) {
          $this->logWarning('Failed to download e-invoice report', ['url' => $urlReport, 'error' => $e->getMessage()]);
          }

        $eInvoiceReport = $savePath . "/" . $tempFileName . "_REPORT_.xml";

        if ($dataReport !== false) {
          $writeResult = file_put_contents($eInvoiceReport, $dataReport);
          if ($writeResult === false) {
            $this->logWarning('Failed to save e-invoice report', ['path' => $eInvoiceReport]);
            }
          }

        $this->setSystemActivityVar('REPORTPATH', $eInvoiceReport);

        // Download Attachments
        $attachments = $dataItem['attachments'] ?? [];
        $attachmentFiles = [];
        foreach ($attachments as $index => $url) {
          if ($index >= self::MAX_ATTACHMENTS) {
            break;
            }

          try {
            $responseData = $this->makeApiRequest($url, 'GET');
            if (!in_array($responseData['httpCode'], self::SUCCESS_HTTP_CODES)) {
              $this->logWarning('Failed to download attachment', ['url' => $url, 'index' => $index, 'httpcode' => $responseData['httpCode']]);
              continue;
              }

            $dataAttachment = $responseData['response'];

            $attachmentPath = $savePath . "/" . $tempFileName . "_ATTACHMENT_" . ($index + 1) . ".pdf";
            $writeResult = file_put_contents($attachmentPath, $dataAttachment);

            if ($writeResult === false) {
              $this->logWarning('Failed to save attachment', ['path' => $attachmentPath, 'index' => $index]);
              continue;
              }

            $this->setSystemActivityVar('ATTACHMENTPATH' . $index, $attachmentPath);
            $attachmentFiles[] = $attachmentPath;
            } catch (Exception $e) {
            $this->logWarning('Error processing attachment', ['url' => $url, 'index' => $index, 'error' => $e->getMessage()]);
            }
          }

        $values6 = [
          'e_invoicePDF' => $eInvoicePDF,
          'e_invoiceReport' => $eInvoiceReport
        ];

        foreach ($attachmentFiles as $i => $attachmentPath) {
          $values6['e_invoiceAttachments'][] = $attachmentPath;
          }

        // Generate audit trail CSV
        $auditTrailData = $dataItem['auditTrail'] ?? [];
        if (!empty($auditTrailData)) {
          $auditTrailCSVPath = $this->convertAuditTrailToCSV($auditTrailData, $savePath, $tempFileName);
          $values6['auditTrailCSV'] = $auditTrailCSVPath;
          $this->setSystemActivityVar('AUDITTRAILPATH', $auditTrailCSVPath);
          } else {
          $values6['auditTrailCSV'] = '';
          }
        } else {
        $tempPath = $this->getTempPath();
        $tempFileName = $dataItem['file']['name'] ?? 'invoice';
        $savePath = $tempPath . "/pedant";

        if (!is_dir($savePath)) {
          mkdir($savePath, 0755, true);
          }

        // Generate audit trail CSV for regular invoices
        $auditTrailData = $dataItem['auditTrail'] ?? [];
        $auditTrailCSVPath = '';
        if (!empty($auditTrailData)) {
          $auditTrailCSVPath = $this->convertAuditTrailToCSV($auditTrailData, $savePath, $tempFileName);
          $this->setSystemActivityVar('AUDITTRAILPATH', $auditTrailCSVPath);
          }

        $values6 = [
          'e_invoicePDF' => '',
          'e_invoiceReport' => '',
          'e_invoiceAttachments' => [],
          'auditTrailCSV' => $auditTrailCSVPath
        ];
        }

      $this->logDebug('Attachment values prepared', ['keys' => array_keys($values6)]);

      foreach ($attributes6 as $attribute) {
        try {
          $value = $values6[$attribute['id']] ?? '';
          if ($attribute['subtable'] == "") {
            $this->attachFile($attribute['value'], $value);
            } else {
            $attachments = is_array($value) ? $value : [$value];
            foreach ($attachments as $attachment) {
              $this->attachSubtableFile($attribute['subtable'], $this->getSubtableCount($attribute['subtable']) + 1, $attribute['value'], $attachment);
              }
            }
          } catch (Exception $e) {
          $this->logWarning('Failed to attach file', ['attribute' => $attribute['id'], 'error' => $e->getMessage()]);
          }
        }

      // ── Position Details (Subtable) ──────────────────────────────
      $attributes7 = $this->resolveOutputParameterListAttributes('positionDetails');

      if ($type == "e_invoice") {
        $invoiceLine = $eInvoiceFields['invoiceLine'] ?? [];

        foreach ($invoiceLine as $line) {
          $values7['positionNumber'][] = $line['invoiceLineIdentifierId'];
          $values7['singleNetPrice'][] = $line['priceDetailsItemNetPrice'];
          $values7['singleNetAmount'][] = $line['invoiceTotalAmountWithoutVat'];
          $values7['quantity'][] = $line['invoicedQuantity'];
          $values7['unitOfMeasureCode'][] = $line['invoicedQuantityUnitOfMeasureCode'];
          $values7['articleNumber'][] = $line['Artikelnummer'];
          $values7['articleName'][] = $line['itemInformationItemName'];
          $values7['itemDescription'][] = $line['itemInformationItemDescription'];
          $values7['vatRatePerLine'][] = $line['lineVatInformationInvoicedItemVatRate'];
          }
        } else {
        $invoiceLine = $dataItem['lineItems'][0] ?? [];

        foreach ($invoiceLine as $line) {
          $values7['positionNumber'][] = $line['lineSubPositionNumber'];
          $values7['singleNetPrice'][] = $line['lineSubUnitPrice'];
          $values7['singleNetAmount'][] = $line['lineSubNetAmount'];
          $values7['quantity'][] = $line['lineSubQuantity'];
          $values7['unitOfMeasureCode'][] = $line['lineSubUnit'];
          $values7['articleNumber'][] = '';
          $values7['articleName'][] = '';
          $values7['itemDescription'][] = $line['lineSubDescription'];
          $values7['vatRatePerLine'][] = $line['lineSubVatPercent'];
          }
        }

      $this->logDebug('Position details', ['lineItemCount' => count($invoiceLine)]);

      foreach ($invoiceLine as $index => $line) {
        try {
          $rowID = $this->getSubtableCount($attributes7[0]['subtable']) + 1;
          foreach ($attributes7 as $attribute) {
            $value = isset($values7[$attribute['id']][$index]) ? $values7[$attribute['id']][$index] : '';
            $this->setSubtableValue($attribute['subtable'], $rowID + $index, $attribute['value'], $value);
            }
          } catch (Exception $e) {
          $this->logWarning('Failed to set position subtable value', ['index' => $index, 'error' => $e->getMessage()]);
          }
        }

      // ── Workflow Details ──────────────────────────────────────────
      $attributes8 = $this->resolveOutputParameterListAttributes('workflowDetails');

      $workflows = $dataItem['workflows'];

      $values8 = [
        'direkt' => !empty($workflows) && ($workflows[0]['name'] ?? '') === 'Direkt' ? 1 : 0
      ];

      $this->logDebug('Workflow values mapped', ['values' => $values8]);

      foreach ($attributes8 as $attribute) {
        try {
          $this->setTableValue($attribute['value'], $values8[$attribute['id']]);
          } catch (Exception $e) {
          $this->logWarning('Failed to set workflow table value', ['attribute' => $attribute['id'], 'error' => $e->getMessage()]);
          }
        }

      $this->logInfo('Invoice data stored successfully');
      } catch (JobRouterException $e) {
      $this->logError('JobRouter error in storeList', $e);
      throw $e;
      } catch (Exception $e) {
      $this->logError('Unexpected error in storeList', $e);
      throw new JobRouterException('Store list error: ' . $e->getMessage());
      }
      // ── DeliveryNote Details ──────────────────────────────────────────
      $attributes9 = $this->resolveOutputParameterListAttributes('deliveryNoteDetails');
      $values9 = [
        'recipientCity' => $dataItem['recipientCity'],
        'recipientCompanyName' => $dataItem['recipientCompanyName'],
        'recipientInfo' => $dataItem['recipientInfo'],
        'recipientStreet' => $dataItem['recipientStreet'],
        'recipientVatNumber' => $dataItem['recipientVatNumber'],
        'recipientZipCode' => $dataItem['recipientZipCode'],
        'vendorCompanyName' => $dataItem['vendorCompanyName'],
        'vendorInfo' => $dataItem['vendorInfo'],
        'vendorName' => $dataItem['vendorName'],
        'vendorStreet' => $dataItem['vendorStreet'],
        'vendorZipCode' => $dataItem['vendorZipCode'],
        'vendorEmail' => $dataItem['vendorEmail'],
        'vendorCity' => $dataItem['vendorCity'],
        'vendorVatNumber' => $dataItem['vendorVatNumber'],
      ];

      foreach ($attributes9 as $attribute) {
          try {
            $this->setTableValue($attribute['value'], $values9[$attribute['id']] ?? '');
            } catch (Exception $e) {
            $this->logWarning('Failed to set Delivery-Note value', ['attribute' => $attribute['id'], 'error' => $e->getMessage()]);
            }
          };
    }

    

  /**
   * Converts an audit trail array to CSV format and saves it to a file.
   *
   * @param array $auditTrail The audit trail data array.
   * @param string $savePath The path to save the CSV file.
   * @param string $fileName The base name for the CSV file.
   * @return string The full path to the saved CSV file.
   */
  protected function convertAuditTrailToCSV(array $auditTrail, string $savePath, string $fileName): string
    {
    try {
      $fileNameWithoutExtension = pathinfo($fileName, PATHINFO_FILENAME);

      $csvFilePath = $savePath . "/" . $fileNameWithoutExtension . "_AUDITTRAIL.csv";
      $csvFile = fopen($csvFilePath, 'w');

      if ($csvFile === false) {
        $this->logWarning('Failed to create audit trail CSV file', ['path' => $csvFilePath]);
        return '';
        }

      $headers = [
        'actionBy',
        'userName',
        'userId',
        'type',
        'subType',
        'field',
        'comment',
        'oldValue',
        'newValue',
        'createdAt',
        'updatedAt'
      ];
      fputcsv($csvFile, $headers);

      foreach ($auditTrail as $entry) {
        $oldValue = isset($entry['updates']['oldValue']) ? $entry['updates']['oldValue'] : '';
        $newValue = isset($entry['updates']['newValue']) ? $entry['updates']['newValue'] : '';

        if (is_array($oldValue) || is_object($oldValue)) {
          $oldValue = json_encode($oldValue);
          }
        if (is_array($newValue) || is_object($newValue)) {
          $newValue = json_encode($newValue);
          }

        $row = [
          $entry['actionBy'] ?? '',
          $entry['userName'] ?? '',
          $entry['userId'] ?? '',
          $entry['type'] ?? '',
          $entry['subType'] ?? '',
          $entry['field'] ?? '',
          $entry['comment'] ?? '',
          $oldValue,
          $newValue,
          $entry['createdAt'] ?? '',
          $entry['updatedAt'] ?? ''
        ];
        fputcsv($csvFile, $row);
        }

      fclose($csvFile);

      $this->logInfo('Audit trail CSV generated', ['path' => $csvFilePath, 'entries' => count($auditTrail)]);
      return $csvFilePath;
      } catch (Exception $e) {
      $this->logError('Error converting audit trail to CSV', $e, ['savePath' => $savePath, 'fileName' => $fileName]);
      return '';
      }
    }
  }
