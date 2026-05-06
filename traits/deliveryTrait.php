<?php
/**
 * Delivery Note Trait — Delivery Note workflow: upload and check.
 *
 * Contains the readDeliveryNote() entry point, uploadDeliveryNote()
 * and checkDeliveryNote() methods for the Pedant Delivery Note API.
 */
trait DeliveryTrait
  {
  /**
   * Main entry point for delivery note analysis (upload → check cycle).
   * Called by the JobRouter framework.
   */
  protected function readDeliveryNote(): void
    {
    $this->cleanOldLogs();
    $this->logInfo('Starting DeliveryNote workflow');
    try {
      $this->maxFileSizeMB = $this->resolveInputParameter('maxFileSize') ?: self::DEFAULT_MAX_FILE_SIZE_MB;
      $interval = $this->resolveInputParameter('interval');
      $this->setResubmission($interval, 'm');
      $this->logDebug('DeliveryNote params', ['maxFileSizeMB' => $this->maxFileSizeMB, 'interval' => $interval]);

      $dnUploadCounter = $this->getSystemActivityVar('DN_UPLOADCOUNTER');
      if (!$dcUploadCounter) {
        $this->setSystemActivityVar('DN_UPLOADCOUNTER', 0);
        $this->logDebug('DN_UPLOADCOUNTER initialized to 0');
        }

      $dnDocumentId = $this->getSystemActivityVar('DN_DOCUMENTID');
      $this->logDebug('DeliveryNote state check', ['dnDocumentId' => $dnDocumentId, 'dnUploadCounter' => $dnUploadCounter]);

      if ($dnDocumentId) {
        $this->logInfo('Document already uploaded, checking file status', ['documentId' => $dnDocumentId]);
        $this->checkDeliveryNote();
        }

      if (!$this->getSystemActivityVar('DN_DOCUMENTID')) {
        $this->logInfo('No delivery note uploaded yet, starting upload');
        $this->uploadDeliveryNote();
        }
      } catch (JobRouterException $e) {
      $this->logError('Delivery Note processing failed', $e);
      throw $e;
      } catch (Exception $e) {
      $this->logError('Unexpected error in readDeliveryNote', $e);
      throw new JobRouterException('Delivery Note error: ' . $e->getMessage());
      }
    }

  /**
   * Uploads a document to the Pedant Delivery Note API.
   */
  protected function uploadDeliveryNote(): void
    {
    try {
      $this->logInfo('Starting delivery note upload');
      $file = $this->getUploadPath() . $this->resolveInputParameter('inputFile');

      if (!file_exists($file)) {
        $this->logError('Upload file does not exist', null, ['path' => $file]);
        throw new JobRouterException('Upload file does not exist: ' . $file);
        }

      $fileSizeB = filesize($file);
      if ($fileSizeB === false) {
        throw new JobRouterException('Failed to get file size for: ' . $file);
        }

      $fileSizeMB = $fileSizeB / (1024 * 1024);
      $this->logInfo('Uploading document for analysis', [
        'file' => basename($file),
        'sizeMB' => round($fileSizeMB, 2),
      ]);

      if ($fileSizeMB > $this->maxFileSizeMB) {
        $this->logError('File size exceeds maximum', null, ['sizeMB' => $fileSizeMB, 'maxMB' => $this->maxFileSizeMB]);
        throw new JobRouterException("File size exceeds the maximum limit of $this->maxFileSizeMB MB. Actual size: $fileSizeMB MB.");
        }

      $baseUrl = $this->getBaseUrl();
      $url = $baseUrl . '/v1/external/documents/delivery-notes/upload';

      $action = $this->resolveInputParameter('flag') ?: 'normal';
      if (!in_array($action, self::VALID_FLAGS)) {
        throw new JobRouterException('Invalid input parameter value for flag: ' . $action);
        }

      $this->logDebug('Delivery Note upload parameters', ['url' => $url, 'action' => $action]);

      $responseData = $this->makeApiRequest(
        $url,
        'POST',
        [
          'file' => new CURLFILE($file),
          'action' => $action,
        ]
      );

      $response = $responseData['response'];
      $httpCode = $responseData['httpCode'];

      $maxCounter = $this->resolveInputParameter('maxCounter');
      $counter = $this->getSystemActivityVar('DN_UPLOADCOUNTER');

      if ($counter >= $maxCounter && !in_array($httpCode, array_merge(self::SUCCESS_HTTP_CODES, self::RETRY_HTTP_CODES))) {
        $this->setSystemActivityVar('DN_UPLOADCOUNTER', 0);
        $this->logError('Delivery Note upload failed after max retries', null, ['counter' => $counter, 'httpCode' => $httpCode]);
        throw new JobRouterException('Error occurred during delivery Note upload after maximum retries (' . $counter . '). HTTP Code: ' . $httpCode);
        } else {
        $this->setSystemActivityVar('DN_UPLOADCOUNTER', ++$counter);
        $this->logDebug('DN upload counter incremented', ['counter' => $counter]);
        }

      if (!in_array($httpCode, self::SUCCESS_HTTP_CODES)) {
        $this->logWarning('Delivery Note upload returned non-success', ['httpCode' => $httpCode, 'response' => substr($response, 0, 500)]);
        return;
        }

      $data = json_decode($response, true);

      if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        $this->logError('Failed to parse document classifier upload response JSON', null, ['json_error' => json_last_error_msg()]);
        throw new JobRouterException('Failed to parse API response: ' . json_last_error_msg());
        }

      $documentId = $data['documents'][0]['documentId'] ?? '';

      if (empty($documentId)) {
        throw new JobRouterException('Delivery note upload response missing documentId');
        }

      $this->storeOutputParameter('fileID', $documentId);
      $this->setSystemActivityVar('DN_DOCUMENTID', $documentId);
      $this->setSystemActivityVar('DN_FETCHCOUNTER', 0);

      $this->logInfo('Delivery Note upload successful', ['documentId' => $documentId]);
      } catch (JobRouterException $e) {
      throw $e;
      } catch (Exception $e) {
      $this->logError('Unexpected error in readDeliveryNote', $e);
      throw new JobRouterException('Delivery Note upload error: ' . $e->getMessage());
      }
    }

  /**
   * Checks the status of a previously uploaded document.
   * If analysis is complete, stores extracted data and marks activity as completed.
   */
  protected function checkDeliveryNote(): void
    {
    try {
      $this->logInfo('Checking Delivery Note status');
      $baseUrl = $this->getBaseUrl();
      $documentId = $this->getSystemActivityVar('DN_DOCUMENTID');
      $url = $baseUrl . '/v1/external/documents/delivery-notes?documentId=' . urlencode($documentId);
      $maxCounter = $this->resolveInputParameter('maxCounter');

      $this->logDebug('Delivery Note check parameters', ['url' => $url, 'documentId' => $documentId]);

      $responseData = $this->makeApiRequest($url, 'GET');
      $response = $responseData['response'];
      $httpCode = $responseData['httpCode'];

      $counter = $this->getSystemActivityVar('DN_FETCHCOUNTER');

      $this->logDebug('Delivery Note check counters', [
        'fetchCounter' => $counter,
        'maxCounter' => $maxCounter,
        'httpCode' => $httpCode,
      ]);

      if ($counter >= $maxCounter && !in_array($httpCode, array_merge(self::SUCCESS_HTTP_CODES, self::RETRY_HTTP_CODES))) {
        $this->setSystemActivityVar('DN_FETCHCOUNTER', 0);
        $this->logError('Delivery Note check failed after max retries', null, ['counter' => $counter, 'httpCode' => $httpCode]);
        throw new JobRouterException('Error occurred during delivery note check after maximum retries (' . $counter . '). HTTP Code: ' . $httpCode);
        } else {
        if (!in_array($httpCode, self::SUCCESS_HTTP_CODES)) {
          $this->setSystemActivityVar('DN_FETCHCOUNTER', ++$counter);
          $this->logWarning('Delivery Note non-success HTTP code, will retry', ['httpCode' => $httpCode, 'fetchCounter' => $counter]);
          return;
          }
        }

      $data = json_decode($response, true);

      if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        $this->logError('Failed to parse delivery note check response JSON', null, ['json_error' => json_last_error_msg()]);
        throw new JobRouterException('Failed to parse API response: ' . json_last_error_msg());
        }

      if (!isset($data['data'][0])) {
        $this->logError('Invalid delivery note check response structure', null, ['response' => substr($response, 0, 500)]);
        throw new JobRouterException('Invalid API response: missing data');
        }

      $dataItem = $data['data'][0];
      $status = $dataItem['status'] ?? '';
      $this->logInfo('Delivery Note status received', ['status' => $status]);

      if (in_array($status, self::FALSE_STATES)) {
        $this->logDebug('Document still processing', ['status' => $status]);
        return;
        }

      $this->storeOutputParameter('invoiceID', $dataItem['documentId'] ?? '');
      $this->storeOutputParameter('tempJSON', json_encode($data, JSON_PRETTY_PRINT));

      $attributes = $this->resolveOutputParameterListAttributes('deliveryNoteDetails');
      $values = [
        // Values
        'orderDate' => $dataItem['orderDate'] ?? '',
        'orderNumber' => $dataItem['orderNumber'] ?? '',
        'recipientCompanyName' => $dataItem['recipientCompanyName'] ?? '',
        'vendorCompanyName' => $dataItem['vendorCompanyName'] ?? '',
        'vendorInfo' => $dataItem['vendorInfo'] ?? '',
      ];

      $this->logDebug('Delivery Note values', $values);

      foreach ($attributes as $attribute) {
        try {
          $this->setTableValue($attribute['value'], $values[$attribute['id']] ?? '');
          } catch (Exception $e) {
          $this->logWarning('Failed to set delivery note detail table value', ['attribute' => $attribute['id'], 'error' => $e->getMessage()]);
          }
        }

        if( $this->isCompleted() === false ){
          $this->markActivityAsCompleted();
          $this->logInfo('Delivery Note completed, activity marked as completed');
        } else {
          $this->logInfo('Delivery Note still running');
        }
      
      } catch (JobRouterException $e) {
      throw $e;
      } catch (Exception $e) {
      $this->logError('Unexpected error in Delivery Note', $e);
      throw new JobRouterException('Delivery Note check error: ' . $e->getMessage());
      }
    }
}


