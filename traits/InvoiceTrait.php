<?php
/**
 * InvoiceTrait — Main invoice processing workflow: upload and check file.
 *
 * Contains the pedant() entry point, uploadFile() and checkFile() methods.
 */
trait InvoiceTrait
  {
  /**
   * Main entry point for invoice processing (upload → check cycle).
   * Called by the JobRouter framework.
   */
  protected function pedant(): void
    {
    $this->cleanOldLogs();
    $this->logInfo('Starting pedant workflow');
    try {
      $this->maxFileSizeMB = $this->resolveInputParameter('maxFileSize') ?: self::DEFAULT_MAX_FILE_SIZE_MB;
      $isNew = $this->resolveInputParameter('new');
      $intervalOld = $this->resolveInputParameter('intervalOld');
      $this->setResubmission($isNew ? 17520 : $intervalOld, $isNew ? 'h' : 'm');
      $this->logDebug('Pedant resubmission set', ['isNew' => $isNew, 'intervalOld' => $intervalOld]);

      if (!date_default_timezone_get()) {
        date_default_timezone_set('Europe/Berlin');
        }

      $uploadCounter = $this->getSystemActivityVar('UPLOADCOUNTER');
      if (!$uploadCounter) {
        $this->setSystemActivityVar('UPLOADCOUNTER', 0);
        }

      $fileId = $this->getSystemActivityVar('FILEID');
      $this->logDebug('Pedant state check', [
        'hasFileId' => !empty($fileId),
        'uploadCounter' => $uploadCounter,
        'maxFileSizeMB' => $this->maxFileSizeMB,
      ]);

      if ($fileId) {
        $this->logInfo('File already uploaded, checking status', ['fileId' => $fileId]);
        $this->checkFile();
        }

      if (!$this->getSystemActivityVar('FILEID')) {
        $this->logInfo('No file uploaded yet, starting upload');
        $this->uploadFile();
        }
      } catch (JobRouterException $e) {
      $this->logError('Pedant processing failed', $e);
      throw $e;
      } catch (Exception $e) {
      $this->logError('Unexpected error in pedant method', $e);
      throw new JobRouterException('Pedant processing error: ' . $e->getMessage());
      }
    }

  /**
   * Uploads a file to the Pedant API.
   */
  protected function uploadFile(): void
    {
    try {
      $file = $this->getUploadPath() . $this->resolveInputParameter('inputFile');

      if (!file_exists($file)) {
        $this->logError('Upload file does not exist', null, ['path' => $file]);
        throw new JobRouterException('Upload file does not exist: ' . $file);
        }

      $fileExtension = pathinfo($file, PATHINFO_EXTENSION);
      $fileSizeB = filesize($file);

      if ($fileSizeB === false) {
        throw new JobRouterException('Failed to get file size for: ' . $file);
        }

      $fileSizeMB = $fileSizeB / (1024 * 1024);
      $this->logInfo('Uploading file', [
        'file' => basename($file),
        'extension' => $fileExtension,
        'sizeMB' => round($fileSizeMB, 2),
      ]);

      if ($fileSizeMB > $this->maxFileSizeMB) {
        $this->logError('File size exceeds maximum', null, ['sizeMB' => $fileSizeMB, 'maxMB' => $this->maxFileSizeMB]);
        throw new JobRouterException("File size exceeds the maximum limit of $this->maxFileSizeMB MB. Actual size: $fileSizeMB MB.");
        }

      $baseUrl = $this->getBaseUrl();
      $zugferd = $this->resolveInputParameter('zugferd');
      $url = $baseUrl . (strtolower($fileExtension) == 'xml'
        ? "/v2/external/documents/invoices/upload"
        : ($zugferd == '1'
          ? "/v1/external/documents/invoices/upload"
          : "/v2/external/documents/invoices/upload"));

      $flag = $this->resolveInputParameter('flag');
      if (strtolower($fileExtension) == 'xml') {
        $flagXML = $this->resolveInputParameter('flagXML');
        if (!empty($flagXML)) {
          $flag = $flagXML;
          if (!in_array($flag, self::VALID_FLAGS)) {
            throw new JobRouterException('Invalid input parameter value for FLAGXML: ' . $flag);
            }
          }
        }

      if (!in_array($flag, self::VALID_FLAGS)) {
        throw new JobRouterException('Invalid input parameter value for FLAG: ' . $flag);
        }

      $action = $flag;
      $internalNumber = $this->resolveInputParameter('internalNumber');
      $note = $this->resolveInputParameter('note');
      $this->logDebug('Upload parameters', ['url' => $url, 'action' => $action, 'internalNumber' => $internalNumber, 'note' => $note]);

      $responseData = $this->makeApiRequest(
        $url,
        'POST',
        [
          'file' => new CURLFILE($file),
          'recipientInternalNumber' => $internalNumber,
          'action' => $action,
          'note' => $note,
        ]
      );

      $response = $responseData['response'];
      $httpcode = $responseData['httpCode'];

      $data = json_decode($response, TRUE);

      if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        $this->logError('Failed to parse upload response JSON', null, ['json_error' => json_last_error_msg(), 'response' => substr($response, 0, 500)]);
        throw new JobRouterException('Failed to parse API response: ' . json_last_error_msg());
        }

      $maxCounter = $this->resolveInputParameter('maxCounter');
      $counter = $this->getSystemActivityVar('UPLOADCOUNTER');

      if ($counter >= $maxCounter && !in_array($httpcode, array_merge(self::SUCCESS_HTTP_CODES, self::RETRY_HTTP_CODES))) {
        $this->setSystemActivityVar('UPLOADCOUNTER', 0);
        $this->logError('Upload failed after max retries', null, ['counter' => $counter, 'httpcode' => $httpcode]);
        throw new JobRouterException('Error occurred during upload after maximum retries (' . $counter . '). HTTP Error Code: ' . $httpcode);
        } else {
        $this->setSystemActivityVar('UPLOADCOUNTER', ++$counter);
        $this->logDebug('Upload counter incremented', ['counter' => $counter]);
        }

      try {
        $this->storeOutputParameter('counterSummary', "Upload attempts: {$counter}, HTTP Code: {$httpcode}");
        } catch (Exception $e) {
        $this->logWarning('Failed to store counterSummary output parameter', ['error' => $e->getMessage()]);
        }

      if (!isset($data['files'][0])) {
        $this->logError('Invalid upload response structure', null, ['response' => $response]);
        throw new JobRouterException('Invalid API response: missing files data');
        }

      $fileId = $data['files'][0]['fileId'] ?? null;
      $invoiceId = $data['files'][0]['invoiceId'] ?? null;
      $type = $data['files'][0]['type'] ?? null;

      if (empty($fileId)) {
        throw new JobRouterException('Upload response missing fileId');
        }

      $this->storeOutputParameter('fileID', $fileId);
      $this->storeOutputParameter('invoiceID', $invoiceId);
      $this->setSystemActivityVar('FILEID', $fileId);
      $this->setSystemActivityVar('FETCHCOUNTER', 0);
      $this->setSystemActivityVar('TYPE', $type);
      $this->setSystemActivityVar('COUNTER404', 0);

      $this->logInfo('File uploaded successfully', ['fileId' => $fileId, 'invoiceId' => $invoiceId, 'type' => $type, 'httpCode' => $httpcode]);
      $this->logDebug('System activity vars after upload', [
        'FILEID' => $fileId,
        'FETCHCOUNTER' => 0,
        'TYPE' => $type,
        'COUNTER404' => 0,
      ]);
      } catch (JobRouterException $e) {
      throw $e;
      } catch (Exception $e) {
      $this->logError('Unexpected error in uploadFile', $e);
      throw new JobRouterException('Upload error: ' . $e->getMessage());
      }
    }

  /**
   * Checks the processing status of a previously uploaded file.
   * If processing is complete, stores extracted data and marks activity as completed.
   */
  protected function checkFile(): void
    {
    try {
      $vendorTable = $this->resolveInputParameter('vendorTable');
      if (!empty($vendorTable)) {
        $this->logInfo('Vendor table configured, running vendor import during checkFile', ['vendorTable' => $vendorTable]);
        $this->importVendor();
        }

      $baseURL = $this->getBaseUrl();
      $fileId = $this->getSystemActivityVar('FILEID');
      $type = $this->getSystemActivityVar('TYPE');
      $urlType = $type == 'e_invoice' ? 'e-invoices' : 'invoices';
      $url = "$baseURL/v1/external/documents/$urlType?" . ($urlType == 'e-invoices' ? "documentId=$fileId" : "fileId=$fileId") . "&auditTrail=true";
      $maxCounter = $this->resolveInputParameter('maxCounter');

      $this->logInfo('Checking file status', ['fileId' => $fileId, 'type' => $type]);

      $responseData = $this->makeApiRequest($url, 'GET');
      $response = $responseData['response'];
      $httpcode = $responseData['httpCode'];

      $counter = $this->getSystemActivityVar('FETCHCOUNTER');
      $counter404 = $this->getSystemActivityVar('COUNTER404');
      $resubTime = $this->resolveInputParameter('intervalOld');

      $this->logDebug('CheckFile counters', [
        'fetchCounter' => $counter,
        'counter404' => $counter404,
        'maxCounter' => $maxCounter,
        'httpCode' => $httpcode,
        'resubTime' => $resubTime,
      ]);

      if ($counter >= $maxCounter && !in_array($httpcode, array_merge(self::SUCCESS_HTTP_CODES, self::RETRY_HTTP_CODES))) {
        $this->setSystemActivityVar('FETCHCOUNTER', 0);
        $this->logError('File extraction failed after max retries', null, ['counter' => $counter, 'httpcode' => $httpcode]);
        throw new JobRouterException('Error occurred during file extraction after maximum retries (' . $counter . '). HTTP Error Code: ' . $httpcode);
        } else {
        if ($httpcode == 404 && $resubTime > 0 && (300 / $resubTime) > $counter404) {
          $this->setSystemActivityVar('COUNTER404', ++$counter404);
          $this->logWarning('File not found (404), will retry', ['counter404' => $counter404]);
          return;
          } elseif (!in_array($httpcode, self::SUCCESS_HTTP_CODES)) {
          $this->setSystemActivityVar('FETCHCOUNTER', ++$counter);
          $this->logWarning('Non-success HTTP code, will retry', ['httpCode' => $httpcode, 'fetchCounter' => $counter]);
          return;
          }
        }

      try {
        $this->storeOutputParameter('counterSummary', "Fetch attempts: {$counter}, HTTP Code: {$httpcode}");
        } catch (Exception $e) {
        $this->logWarning('Failed to store counterSummary output parameter', ['error' => $e->getMessage()]);
        }

      $data = json_decode($response, TRUE);

      if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        $this->logError('Failed to parse checkFile response JSON', null, ['json_error' => json_last_error_msg()]);
        throw new JobRouterException('Failed to parse API response: ' . json_last_error_msg());
        }

      if (!isset($data['data'][0])) {
        $this->logError('Invalid checkFile response structure', null, ['response' => substr($response, 0, 500)]);
        throw new JobRouterException('Invalid API response: missing data');
        }

      $dataItem = $data["data"][0];
      $check = false;
      $status = $dataItem["status"] ?? '';

      try {
        $this->storeOutputParameter('tempJSON', json_encode($data, JSON_PRETTY_PRINT));
        } catch (Exception $e) {
        $this->logWarning('Failed to store tempJSON output parameter', ['error' => $e->getMessage()]);
        }

      $this->logInfo('File status received', ['status' => $dataItem['status'], 'httpCode' => $httpcode]);

      if (in_array($dataItem["status"], self::FALSE_STATES) === false) {
        $check = true;
        $this->logInfo('File processing complete, storing extracted data');
        $this->storeList($data);
        } else {
        $this->logDebug('File still processing', ['status' => $dataItem['status']]);
        }

      if ($check === true) {
        // Clean up temporary files for e-invoices
        if ($type == "e_invoice") {
          $pdfPath = $this->getSystemActivityVar('PDFPATH');
          if ($pdfPath && file_exists($pdfPath)) {
            try {
              unlink($pdfPath);
              } catch (Exception $e) {
              $this->logWarning('Failed to delete PDF file', ['path' => $pdfPath, 'error' => $e->getMessage()]);
              }
            }

          $reportPath = $this->getSystemActivityVar('REPORTPATH');
          if ($reportPath && file_exists($reportPath)) {
            try {
              unlink($reportPath);
              } catch (Exception $e) {
              $this->logWarning('Failed to delete report file', ['path' => $reportPath, 'error' => $e->getMessage()]);
              }
            }

          $index = 0;
          while (true) {
            $attachmentPath = $this->getSystemActivityVar('ATTACHMENTPATH' . $index);
            if (!$attachmentPath) {
              break;
              }
            if (file_exists($attachmentPath)) {
              try {
                unlink($attachmentPath);
                } catch (Exception $e) {
                $this->logWarning('Failed to delete attachment file', ['path' => $attachmentPath, 'error' => $e->getMessage()]);
                }
              }
            $index++;
            }
          }

        // Clean up audit trail CSV
        $auditTrailPath = $this->getSystemActivityVar('AUDITTRAILPATH');
        if ($auditTrailPath && file_exists($auditTrailPath)) {
          try {
            unlink($auditTrailPath);
            } catch (Exception $e) {
            $this->logWarning('Failed to delete audit trail CSV', ['path' => $auditTrailPath, 'error' => $e->getMessage()]);
            }
          }
        $this->markActivityAsCompleted();
        $this->logInfo('Activity completed successfully', ['fileId' => $fileId]);
        }
      } catch (JobRouterException $e) {
      throw $e;
      } catch (Exception $e) {
      $this->logError('Unexpected error in checkFile', $e);
      throw new JobRouterException('Check file error: ' . $e->getMessage());
      }
    }
  }