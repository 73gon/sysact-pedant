<?php
/**
 * DocumentClassifierTrait — Document classification workflow: upload and check.
 *
 * Contains the documentClassifier() entry point, uploadDocumentClassifier()
 * and checkDocumentClassifier() methods for the Pedant Document Classifier API.
 */
trait DocumentClassifierTrait
  {
  /**
   * Main entry point for document classification (upload → check cycle).
   * Called by the JobRouter framework.
   */
  protected function documentClassifier(): void
    {
    $this->cleanOldLogs();
    $this->logInfo('Starting documentClassifier workflow');
    try {
      $this->maxFileSizeMB = $this->resolveInputParameter('maxFileSize') ?: self::DEFAULT_MAX_FILE_SIZE_MB;
      $dcInterval = $this->resolveInputParameter('dc_interval');
      $this->setResubmission($dcInterval, 'm');
      $this->logDebug('documentClassifier params', ['maxFileSizeMB' => $this->maxFileSizeMB, 'dc_interval' => $dcInterval]);

      $dcUploadCounter = $this->getSystemActivityVar('DC_UPLOADCOUNTER');
      if (!$dcUploadCounter) {
        $this->setSystemActivityVar('DC_UPLOADCOUNTER', 0);
        $this->logDebug('DC_UPLOADCOUNTER initialized to 0');
        }

      $dcDocumentId = $this->getSystemActivityVar('DC_DOCUMENTID');
      $this->logDebug('documentClassifier state check', ['dcDocumentId' => $dcDocumentId, 'dcUploadCounter' => $dcUploadCounter]);

      if ($dcDocumentId) {
        $this->logInfo('Document already uploaded, checking classification status', ['documentId' => $dcDocumentId]);
        $this->checkDocumentClassifier();
        }

      if (!$this->getSystemActivityVar('DC_DOCUMENTID')) {
        $this->logInfo('No document uploaded yet, starting upload');
        $this->uploadDocumentClassifier();
        }
      } catch (JobRouterException $e) {
      $this->logError('Document classifier processing failed', $e);
      throw $e;
      } catch (Exception $e) {
      $this->logError('Unexpected error in documentClassifier', $e);
      throw new JobRouterException('Document classifier error: ' . $e->getMessage());
      }
    }

  /**
   * Uploads a document to the Pedant Document Classifier API.
   */
  protected function uploadDocumentClassifier(): void
    {
    try {
      $this->logInfo('Starting document classifier upload');
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
      $this->logInfo('Uploading document for classification', [
        'file' => basename($file),
        'sizeMB' => round($fileSizeMB, 2),
      ]);

      if ($fileSizeMB > $this->maxFileSizeMB) {
        $this->logError('File size exceeds maximum', null, ['sizeMB' => $fileSizeMB, 'maxMB' => $this->maxFileSizeMB]);
        throw new JobRouterException("File size exceeds the maximum limit of $this->maxFileSizeMB MB. Actual size: $fileSizeMB MB.");
        }

      $baseUrl = $this->getBaseUrl();
      $url = $baseUrl . '/v1/external/documents/document-classifiers/upload';

      $action = $this->resolveInputParameter('dc_action') ?: 'normal';
      if (!in_array($action, self::VALID_FLAGS)) {
        throw new JobRouterException('Invalid input parameter value for DC_ACTION: ' . $action);
        }

      $this->logDebug('Document classifier upload parameters', ['url' => $url, 'action' => $action]);

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

      $maxCounter = $this->resolveInputParameter('dc_maxCounter');
      $counter = $this->getSystemActivityVar('DC_UPLOADCOUNTER');

      if ($counter >= $maxCounter && !in_array($httpCode, array_merge(self::SUCCESS_HTTP_CODES, self::RETRY_HTTP_CODES))) {
        $this->setSystemActivityVar('DC_UPLOADCOUNTER', 0);
        $this->logError('Document classifier upload failed after max retries', null, ['counter' => $counter, 'httpCode' => $httpCode]);
        throw new JobRouterException('Error occurred during document classifier upload after maximum retries (' . $counter . '). HTTP Code: ' . $httpCode);
        } else {
        $this->setSystemActivityVar('DC_UPLOADCOUNTER', ++$counter);
        $this->logDebug('DC upload counter incremented', ['counter' => $counter]);
        }

      if (!in_array($httpCode, self::SUCCESS_HTTP_CODES)) {
        $this->logWarning('Document classifier upload returned non-success', ['httpCode' => $httpCode, 'response' => substr($response, 0, 500)]);
        return;
        }

      $data = json_decode($response, true);

      if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        $this->logError('Failed to parse document classifier upload response JSON', null, ['json_error' => json_last_error_msg()]);
        throw new JobRouterException('Failed to parse API response: ' . json_last_error_msg());
        }

      $documentId = $data['documents'][0]['documentId'] ?? '';

      if (empty($documentId)) {
        throw new JobRouterException('Document classifier upload response missing documentId');
        }

      $this->storeOutputParameter('dc_documentId', $documentId);
      $this->setSystemActivityVar('DC_DOCUMENTID', $documentId);
      $this->setSystemActivityVar('DC_FETCHCOUNTER', 0);

      $this->logInfo('Document classifier upload successful', ['documentId' => $documentId]);
      } catch (JobRouterException $e) {
      throw $e;
      } catch (Exception $e) {
      $this->logError('Unexpected error in uploadDocumentClassifier', $e);
      throw new JobRouterException('Document classifier upload error: ' . $e->getMessage());
      }
    }

  /**
   * Checks the classification status of a previously uploaded document.
   * If classification is complete, stores extracted data and marks activity as completed.
   */
  protected function checkDocumentClassifier(): void
    {
    try {
      $this->logInfo('Checking document classifier status');
      $baseUrl = $this->getBaseUrl();
      $documentId = $this->getSystemActivityVar('DC_DOCUMENTID');
      $url = $baseUrl . '/v1/external/documents/document-classifiers?documentId=' . urlencode($documentId);
      $maxCounter = $this->resolveInputParameter('dc_maxCounter');

      $this->logDebug('Document classifier check parameters', ['url' => $url, 'documentId' => $documentId]);

      $responseData = $this->makeApiRequest($url, 'GET');
      $response = $responseData['response'];
      $httpCode = $responseData['httpCode'];

      $counter = $this->getSystemActivityVar('DC_FETCHCOUNTER');

      $this->logDebug('Document classifier check counters', [
        'fetchCounter' => $counter,
        'maxCounter' => $maxCounter,
        'httpCode' => $httpCode,
      ]);

      if ($counter >= $maxCounter && !in_array($httpCode, array_merge(self::SUCCESS_HTTP_CODES, self::RETRY_HTTP_CODES))) {
        $this->setSystemActivityVar('DC_FETCHCOUNTER', 0);
        $this->logError('Document classifier check failed after max retries', null, ['counter' => $counter, 'httpCode' => $httpCode]);
        throw new JobRouterException('Error occurred during document classifier check after maximum retries (' . $counter . '). HTTP Code: ' . $httpCode);
        } else {
        if (!in_array($httpCode, self::SUCCESS_HTTP_CODES)) {
          $this->setSystemActivityVar('DC_FETCHCOUNTER', ++$counter);
          $this->logWarning('Document classifier non-success HTTP code, will retry', ['httpCode' => $httpCode, 'fetchCounter' => $counter]);
          return;
          }
        }

      $data = json_decode($response, true);

      if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        $this->logError('Failed to parse document classifier check response JSON', null, ['json_error' => json_last_error_msg()]);
        throw new JobRouterException('Failed to parse API response: ' . json_last_error_msg());
        }

      if (!isset($data['data'][0])) {
        $this->logError('Invalid document classifier check response structure', null, ['response' => substr($response, 0, 500)]);
        throw new JobRouterException('Invalid API response: missing data');
        }

      $dataItem = $data['data'][0];
      $status = $dataItem['status'] ?? '';
      $confidence = $this->buildConfidenceField($dataItem);
      $this->logInfo('Document classifier status received', ['status' => $status]);

      if (in_array($status, self::FALSE_STATES)) {
        $this->logDebug('Document still processing', ['status' => $status]);
        return;
        }

      $this->storeOutputParameter('dc_documentId', $dataItem['documentId'] ?? '');
      $this->storeOutputParameter('dc_tempJSON', json_encode($data));

      $attributes = $this->resolveOutputParameterListAttributes('classificationDetails');
      $values = [
        // Values
        'documentClassifierNumber' => $dataItem['documentClassifierNumber'] ?? '',
        'documentType' => $dataItem['documentType'] ?? '',
        'vendorCompanyName' => $dataItem['vendorCompanyName'] ?? '',
        'recipientCompanyName' => $dataItem['recipientCompanyName'] ?? '',
        'issueDate' => !empty($dataItem['issueDate']) ? date('d.m.Y', strtotime($dataItem['issueDate'])) : '',
        'documentClassifierConfidence' => $confidence ?? '',
      ];

      $this->logDebug('Classification values', $values);

      foreach ($attributes as $attribute) {
        try {
          $this->setTableValue($attribute['value'], $values[$attribute['id']] ?? '');
          } catch (Exception $e) {
          $this->logWarning('Failed to set classification detail table value', ['attribute' => $attribute['id'], 'error' => $e->getMessage()]);
          }
        }

        if( $this->isCompleted() === false ){
          $this->markActivityAsCompleted();
          $this->logInfo('Document classifier completed, activity marked as completed');
        } else {
          $this->logInfo('Document classifier still running');
        }
      
      } catch (JobRouterException $e) {
      throw $e;
      } catch (Exception $e) {
      $this->logError('Unexpected error in checkDocumentClassifier', $e);
      throw new JobRouterException('Document classifier check error: ' . $e->getMessage());
      }
    }

  protected function buildConfidenceField(array $dataValues): string{

      $dataConfidence = $dataValues['documentClassifierDataBoxes'];
      $values = [
        'dateConfidence' => [
          'confidence' => $dataConfidence['documentClassifierDate']['confidence'] ?? '',
          'reasoning' => $dataConfidence['documentClassifierDate']['reasoning'] ?? '',
        ],
        'numberConfidence' => [
          'confidence' => $dataConfidence['documentClassifierNumber']['confidence'] ?? '',
          'reasoning' => $dataConfidence['documentClassifierNumber']['reasoning'] ?? '',
        ],
        'typeConfidence' => [
          'confidence' => $dataConfidence['documentClassifierType']['confidence'] ?? '',
          'reasoning' => $dataConfidence['documentClassifierType']['reasoning'] ?? '',
        ],
        'recipientCompanyConfidence' => [
          'confidence' => $dataConfidence['recipientCompanyName']['confidence'] ?? '',
          'reasoning' => $dataConfidence['recipientCompanyName']['reasoning'] ?? '',
        ],
        'recipientInfoConfidence' => [
          'confidence' => $dataConfidence['recipientInfo']['confidence'] ?? '',
          'reasoning' => $dataConfidence['recipientInfo']['reasoning'] ?? '',
        ],
        'recipientVatNumberConfidence' => [
          'confidence' => $dataConfidence['recipientVatNumber']['confidence'] ?? '',
          'reasoning' => $dataConfidence['recipientVatNumber']['reasoning'] ?? '',
        ],
        'vatNumberConfidence' => [
          'confidence' => $dataConfidence['vatNumber']['confidence'] ?? '',
          'reasoning' => $dataConfidence['vatNumber']['reasoning'] ?? '',
        ],
        'vendorCompanyNameConfidence' => [
          'confidence' => $dataConfidence['vendorCompanyName']['confidence'] ?? '',
          'reasoning' => $dataConfidence['vendorCompanyName']['reasoning'] ?? '',
        ],
        'vendorInfoConfidence' => [
          'confidence' => $dataConfidence['vendorInfo']['confidence'] ?? '',
          'reasoning' => $dataConfidence['vendorInfo']['reasoning'] ?? '',
        ],
      ];
      $string = [];
      foreach($values as $name => $value ){
        $string[] = $name . ': [reason: ' . $value['reasoning'] . ', confidence: ' . $value['confidence'] . ']';
      }

      $this->logDebug('Confidence values fetched', [
        'function' => 'buildConfidenceField',
        'values' => $string,
        ]
      );
      return implode( ', ', $string);
  }  
  
}


