<?php
/**
 * ImportTrait — Handles CSV-based entity imports (vendor, recipient, cost center).
 *
 * Provides a shared importEntity() method that extracts the common flow:
 * resolve table → query DB → build CSV → upload multipart → cleanup.
 * Entity-specific logic (e.g. vendor override fields) is handled via config params.
 */
trait ImportTrait
  {
  private string $vendorOutputFileName = "pedantVendorOutput.csv";
  private string $recipientOutputFileName = "pedantRecipientOutput.csv";
  private string $costCenterOutputFileName = "pedantCostCenterOutput.csv";

  // ─── Entry Points (called by JobRouter framework) ───────────────────────

  protected function importVendorCSV(): void
    {
    $this->cleanOldLogs();
    $this->logInfo('Starting importVendorCSV');
    try {
      $this->importVendor();
      $this->markActivityAsCompleted();
      $this->logInfo('importVendorCSV completed successfully');
      } catch (JobRouterException $e) {
      $this->logError('Import vendor CSV failed', $e);
      throw $e;
      } catch (Exception $e) {
      $this->logError('Unexpected error in importVendorCSV', $e);
      throw new JobRouterException('Vendor CSV import error: ' . $e->getMessage());
      }
    }

  protected function importRecipientCSV(): void
    {
    $this->cleanOldLogs();
    $this->logInfo('Starting importRecipientCSV');
    try {
      $this->importRecipient();
      $this->markActivityAsCompleted();
      $this->logInfo('importRecipientCSV completed successfully');
      } catch (JobRouterException $e) {
      $this->logError('Import recipient CSV failed', $e);
      throw $e;
      } catch (Exception $e) {
      $this->logError('Unexpected error in importRecipientCSV', $e);
      throw new JobRouterException('Recipient CSV import error: ' . $e->getMessage());
      }
    }

  protected function importCostCenterCSV(): void
    {
    $this->cleanOldLogs();
    $this->logInfo('Starting importCostCenterCSV');
    try {
      $this->importCostCenter();
      $this->markActivityAsCompleted();
      $this->logInfo('importCostCenterCSV completed successfully');
      } catch (JobRouterException $e) {
      $this->logError('Import cost center CSV failed', $e);
      throw $e;
      } catch (Exception $e) {
      $this->logError('Unexpected error in importCostCenterCSV', $e);
      throw new JobRouterException('Cost center CSV import error: ' . $e->getMessage());
      }
    }

  // ─── Individual Import Methods ──────────────────────────────────────────

  protected function importVendor(): void
    {
    $fields = [
      'internalVendorNumber',
      'vendorProfileName',
      'company',
      'street',
      'zipCode',
      'city',
      'country',
      'iban',
      'taxNumber',
      'vatNumber',
      'recipientNumber',
      'kvk',
      'currency',
      'blocked',
      'sortCode',
      'accountNumber',
    ];

    $fieldMapping = [
      'internalNumber' => 'internalVendorNumber',
      'profileName' => 'vendorProfileName',
      'name' => 'company',
      'street' => 'street',
      'zipCode' => 'zipCode',
      'city' => 'city',
      'country' => 'country',
      'iban' => 'iban',
      'taxNumber' => 'taxNumber',
      'vatNumber' => 'vatNumber',
      'recipientNumber' => 'recipientNumber',
      'kvk' => 'kvk',
      'currency' => 'currency',
      'blocked' => 'blocked',
      'sortCode' => 'sortCode',
      'accountNumber' => 'accountNumber',
    ];

    $this->importEntity(
      entityType: 'vendor',
      tableParam: 'vendorTable',
      listParam: 'importVendor',
      fields: $fields,
      csvFileName: $this->vendorOutputFileName,
      apiEndpoint: '/v2/external/entities/vendors/import',
      fieldMapping: $fieldMapping,
      rowTransformers: [
        'blocked' => function ($value) {
          $truthy = ['yes', 'true', 'ja', '1'];
          $normalized = is_string($value) ? strtolower(trim($value)) : strval($value);
          return in_array($normalized, $truthy) ? 'TRUE' : 'FALSE';
          },
      ],
      useOverrides: true
    );
    }

  protected function importRecipient(): void
    {
    $fields = [
      'internalRecipientNumber',
      'recipientProfileName',
      'country',
      'city',
      'zipCode',
      'street',
      'company',
      'vatNumber',
      'synonyms',
    ];

    $fieldMapping = [
      'internalNumber' => 'internalRecipientNumber',
      'profileName' => 'recipientProfileName',
      'country' => 'country',
      'city' => 'city',
      'zipCode' => 'zipCode',
      'street' => 'street',
      'name' => 'company',
      'vatNumber' => 'vatNumber',
      'synonyms' => 'synonyms',
    ];

    $this->importEntity(
      entityType: 'recipient',
      tableParam: 'recipientTable',
      listParam: 'importRecipient',
      fields: $fields,
      csvFileName: $this->recipientOutputFileName,
      apiEndpoint: '/v2/external/entities/recipient-groups/import',
      fieldMapping: $fieldMapping
    );
    }

  protected function importCostCenter(): void
    {
    $fields = ['internalCostCenterNumber', 'costCenterProfileName', 'recipientNumber'];

    $fieldMapping = [
      'internalNumber' => 'internalCostCenterNumber',
      'name' => 'costCenterProfileName',
      'recipientNumber' => 'recipientNumber',
    ];

    $this->importEntity(
      entityType: 'costCenter',
      tableParam: 'costCenterTable',
      listParam: 'importCostCenter',
      fields: $fields,
      csvFileName: $this->costCenterOutputFileName,
      apiEndpoint: '/v1/external/entities/cost-centers/import',
      fieldMapping: $fieldMapping
    );
    }

  // ─── Shared Import Engine ───────────────────────────────────────────────

  /**
   * Generic entity import: query DB → build CSV → upload → cleanup.
   *
   * @param string $entityType      Label for logging (e.g. 'vendor', 'recipient').
   * @param string $tableParam      Input parameter name for the DB table.
   * @param string $listParam       Input parameter name for the field list.
   * @param array  $fields          CSV column names (in order).
   * @param string $csvFileName     Output CSV file name.
   * @param string $apiEndpoint     API path (appended to entity URL).
   * @param array  $fieldMapping    API field name => CSV field name.
   * @param array|null $rowTransformers  Optional: field => callable for special value processing.
   * @param bool   $useOverrides    If true, add overrideXxx fields based on first record values (vendor-specific).
   */
  private function importEntity(
    string $entityType,
    string $tableParam,
    string $listParam,
    array $fields,
    string $csvFileName,
    string $apiEndpoint,
    array $fieldMapping,
    ?array $rowTransformers = null,
    bool $useOverrides = false
  ): void {
    $csvFilePath = null;
    try {
      $this->logInfo("Starting $entityType import");

      $table = $this->resolveInputParameter($tableParam) ?? [];
      $listfields = $this->resolveInputParameterListValues($listParam) ?? [];

      $this->logDebug("Resolving input Parameter", [
        'table' => $table,
        'listfields' => $listfields,
      ]);
      

      $list = array();
      foreach ($listfields as $listindex => $listvalue) {
        $list[$listindex] = $listvalue;
        }
      ksort($list);

      if (empty($table)) {
        $this->logInfo("$entityType import skipped: table parameter is empty");
        return;
        }

      // Build SELECT query from field mapping
      $this->logInfo("Connectiong to internal Database");
      $DB = $this->getJobDB();
  
      $lastKey = null;
      foreach ($list as $listindex => $listvalue) {
        if (!empty($listvalue)) {
          $lastKey = $listindex;
          }
        }

      $temp = "SELECT ";
      foreach ($list as $listindex => $listvalue) {
        if (!empty($listvalue)) {
          $temp .= $listvalue . " AS " . $fields[$listindex - 1];
          if ($listindex !== $lastKey) {
            $temp .= ", ";
            }
          }
        }
      $temp .= " FROM " . $table;

      $this->logDebug("$entityType import query", ['query' => $temp]);

      $result = $DB->query($temp);
      $payloads = [];
      while ($row = $DB->fetchRow($result)) {
        $data = [];
        foreach ($fields as $index => $field) {
          $value = isset($row[$fields[$index]]) ? $row[$fields[$index]] : '';
          if ($rowTransformers && isset($rowTransformers[$field])) {
            $data[$field] = $rowTransformers[$field]($value);
          } else {
            $data[$field] = !empty($value) ? $value : '';
          }
        }
        $payloads[] = $data;
      }

      $this->logInfo("$entityType CSV generated", ['rowCount' => count($payloads)]);
      $this->logDebug("$entityType payloads", ['payloads' => $payloads]);

      // Build CSV
      $csvData = [$fields];
      foreach ($payloads as $payload) {
        $rowData = [];
        foreach ($fields as $field) {
          $rowData[] = isset($payload[$field]) ? $payload[$field] : '';
          }
        $csvData[] = $rowData;
        }

      $csvFilePath = dirname(__DIR__) . '/' . $csvFileName;
      $csvFile = fopen($csvFilePath, 'w');

      if ($csvFile === false) {
        throw new JobRouterException("Failed to create $entityType CSV file: " . $csvFilePath);
        }

      foreach ($csvData as $row) {
        fputcsv($csvFile, $row);
        }
      fclose($csvFile);

      // Build upload payload
      $url = $this->getEntityUrl() . $apiEndpoint;
      $uploadPayload = $fieldMapping;
      $uploadPayload['file'] = new CURLFILE($csvFilePath);

      // Vendor-specific: add override flags based on first record values
      if ($useOverrides && !empty($payloads)) {
        $firstRecord = $payloads[0];
        $overrideFields = [];
        foreach ($fieldMapping as $apiField => $csvField) {
          if ($apiField === 'blocked') {
            continue;
            }
          $fieldValue = isset($firstRecord[$csvField]) ? $firstRecord[$csvField] : '';
          if (!empty($fieldValue)) {
            $uploadPayload['override' . ucfirst($apiField)] = 'true';
            $overrideFields[] = $apiField;
            }
          }
        $this->logDebug("$entityType override fields set", ['overrideFields' => $overrideFields]);
        }

      $this->logDebug("$entityType upload payload keys", ['keys' => array_keys($uploadPayload)]);

      // Upload
      $responseData = $this->makeApiRequest($url, 'POST', $uploadPayload);
      $response = $responseData['response'];
      $httpcode = $responseData['httpCode'];

      if (!in_array($httpcode, self::SUCCESS_HTTP_CODES)) {
        $this->logError("$entityType import failed", null, ['httpcode' => $httpcode, 'response' => substr($response, 0, 500)]);
        throw new JobRouterException("Error occurred during $entityType update. HTTP Error Code: " . $httpcode);
        }

      $this->logInfo("$entityType import successful", ['httpCode' => $httpcode]);

      // Cleanup
      if (file_exists($csvFilePath)) {
        unlink($csvFilePath);
        }
      } catch (JobRouterException $e) {
      if ($csvFilePath && file_exists($csvFilePath)) {
        unlink($csvFilePath);
        }
      throw $e;
      } catch (Exception $e) {
      if ($csvFilePath && file_exists($csvFilePath)) {
        unlink($csvFilePath);
        }
      $this->logError("Unexpected error in $entityType import", $e);
      throw new JobRouterException("$entityType import error: " . $e->getMessage());
      }
    }
  }
