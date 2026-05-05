<?php
/**
 * FetchTrait — Fetches processed invoices from the Pedant API and triggers resubmission.
 *
 * Contains the fetchData() entry point (with worktime/weekend scheduling)
 * and fetchInvoices() which pages through the API and updates the DB.
 */
trait FetchTrait
  {
  private static array $VALID_INVOICE_STATUSES = ['reviewed', 'exported', 'rejected', 'archived'];

  /**
   * Entry point for batch invoice fetching.
   * Calculates resubmission schedule based on worktime/weekend settings,
   * then fetches invoices from the API.
   */
  protected function fetchData(): void
    {
    $this->cleanOldLogs();
    $this->logInfo('Starting fetchData');
    try {
      $this->markActivityAsPending();

      $interval = $this->resolveInputParameter('interval');
      $worktime = $this->resolveInputParameter('worktime');
      $weekend = $this->resolveInputParameter('weekend');

      list($startTime, $endTime) = array_map('intval', explode(',', $worktime));
      list($currentHour, $currentDayOfWeek) = [(int) (new DateTime())->format('G'), (int) (new DateTime())->format('w')];

      $this->logDebug('FetchData schedule parameters', [
        'interval' => $interval,
        'worktime' => $worktime,
        'weekend' => $weekend,
        'startTime' => $startTime,
        'endTime' => $endTime,
        'currentHour' => $currentHour,
        'currentDayOfWeek' => $currentDayOfWeek,
      ]);

      if ($weekend) {
        if ($currentHour >= $startTime && $currentHour < $endTime) {
          $this->setResubmission($interval, 'm');
          } else {
          $hoursToStart = ($currentHour < $startTime) ? $startTime - $currentHour : 24 - $currentHour + $startTime;
          $this->setResubmission($hoursToStart, 'h');
          }
        } else {
        if ($currentDayOfWeek >= 1 && $currentDayOfWeek <= 5) {
          if ($currentHour >= $startTime && $currentHour < $endTime) {
            $this->setResubmission($interval, 'm');
            } else {
            $hoursToStart = ($currentHour < $startTime) ? $startTime - $currentHour : 24 - $currentHour + $startTime;
            $this->setResubmission($hoursToStart, 'h');
            }
          } else {
          $hoursToStart = ($currentHour < $startTime) ? $startTime - $currentHour : 24 - $currentHour + $startTime;
          if ($currentDayOfWeek == 6) {
            $hoursToStart += 24;
            }
          $this->setResubmission($hoursToStart, 'h');
          }
        }

      $this->fetchInvoices();
      } catch (JobRouterException $e) {
      $this->logError('Fetch data failed', $e);
      throw $e;
      } catch (Exception $e) {
      $this->logError('Unexpected error in fetchData', $e);
      throw new JobRouterException('Fetch data error: ' . $e->getMessage());
      }
    }

  /**
   * Fetches invoices from the Pedant API and updates the resubmission date in the database.
   *
   * @throws Exception If the database type is unsupported or if the query fails.
   */
  protected function fetchInvoices(): void
    {
    try {
      $invoice_status = $this->resolveInputParameter('invoice_status');
      if (empty($invoice_status)) {
        $invoice_status = 'reviewed';
        }

      $statusValues = [];
      if (!empty($invoice_status)) {
        $parts = array_map('trim', explode(',', $invoice_status));
        $parts = array_values(array_filter($parts, static fn($status) => $status !== ''));

        foreach ($parts as $status) {
          if (!in_array($status, self::$VALID_INVOICE_STATUSES, true)) {
            $this->logError('Invalid invoice status', null, ['status' => $status]);
            throw new JobRouterException('Invalid invoice status: ' . $status);
            }
          $statusValues[] = $status;
          }
        }

      $this->logInfo('Fetching invoices', ['statuses' => $statusValues]);

      $statusQuery = '';
      if (!empty($statusValues)) {
        $queryParts = [];
        foreach ($statusValues as $status) {
          $queryParts[] = 'status=' . rawurlencode($status);
          }
        $statusQuery = '?' . implode('&', $queryParts);
        }

      $baseURL = $this->getBaseUrl();
      $url_invoice = "$baseURL/v1/external/documents/invoices/to-export" . $statusQuery;
      $url_einvoice = "$baseURL/v1/external/documents/e-invoices/to-export" . $statusQuery;

      foreach ([$url_invoice, $url_einvoice] as $baseUrl) {
        $allIds = [];
        $pageCount = 1;
        $currentPage = 1;

        // Fetch first page to get pageCount
        $pageParam = (strpos($baseUrl, '?') !== false) ? '&page=1' : '?page=1';
        $url = $baseUrl . $pageParam;

        try {
          $responseData = $this->makeApiRequest($url, 'GET');
          $response = $responseData['response'];
          } catch (JobRouterException $e) {
          $this->logWarning('Fetch invoices request failed, skipping URL', ['url' => $url, 'error' => $e->getMessage()]);
          continue;
          }

        $data = json_decode($response, TRUE);

        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
          $this->logWarning('Failed to parse fetch invoices response', ['json_error' => json_last_error_msg(), 'url' => $url]);
          continue;
          }

        if (!isset($data['data']) || !is_array($data['data'])) {
          $this->logWarning('Invalid fetch invoices response structure', ['url' => $url]);
          continue;
          }

        $pageCount = isset($data['pageCount']) ? (int) $data['pageCount'] : 1;
        $allIds = array_merge($allIds, $data['data']);

        $this->logDebug('First page fetched', ['url' => $url, 'pageCount' => $pageCount, 'idsOnPage' => count($data['data'])]);

        // Fetch remaining pages
        for ($currentPage = 2; $currentPage <= $pageCount; $currentPage++) {
          $pageParam = (strpos($baseUrl, '?') !== false) ? "&page=$currentPage" : "?page=$currentPage";
          $url = $baseUrl . $pageParam;

          try {
            $responseData = $this->makeApiRequest($url, 'GET');
            $response = $responseData['response'];
            } catch (JobRouterException $e) {
            $this->logWarning('Fetch invoices failed on page', ['url' => $url, 'page' => $currentPage, 'error' => $e->getMessage()]);
            continue;
            }

          $data = json_decode($response, TRUE);

          if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            $this->logWarning('Failed to parse fetch invoices response on page', ['json_error' => json_last_error_msg(), 'url' => $url, 'page' => $currentPage]);
            continue;
            }

          if (!isset($data['data']) || !is_array($data['data'])) {
            $this->logWarning('Invalid fetch invoices response structure on page', ['url' => $url, 'page' => $currentPage]);
            continue;
            }

          $allIds = array_merge($allIds, $data['data']);
          }

        $this->logInfo('Invoices collected', ['totalIds' => count($allIds), 'pages' => $pageCount, 'url' => $baseUrl]);

        // Process all collected IDs
        $table_head = $this->resolveInputParameter('table_head');
        $stepID = $this->resolveInputParameter('stepID');
        $fileid = $this->resolveInputParameter('fileid'); //We need the name of the Tablefield, not the value of that tablefield

        $currentTime = new DateTime();
        $currentTime->modify('+10 seconds');
        $formattedTime = $currentTime->format('Y-m-d H:i:s');

        if (!empty($allIds)) {
          $this->logInfo('Triggering resubmission for invoices', ['count' => count($allIds), 'ids' => $allIds]);
          }

        foreach ($allIds as $id) {
          try {
            $dbType = $this->getDatabaseType();
            if ($dbType === "MySQL") {
              $query = "
                        UPDATE JRINCIDENTS j
                        JOIN $table_head t ON t.step_id = j.process_step_id
                        SET j.resubmission_date = '$formattedTime'
                        WHERE t.step = $stepID AND t.$fileid = '$id';
                        ";
              } elseif ($dbType === "MSSQL") {
              $query = "
                        UPDATE j
                        SET j.resubmission_date = '$formattedTime'
                        FROM JRINCIDENTS AS j
                        JOIN $table_head AS t ON t.step_id = j.process_step_id
                        WHERE t.step = $stepID AND t.$fileid = '$id';
                        ";
              } else {
              $this->logError('Unsupported database type', null, ['dbType' => $dbType]);
              throw new JobRouterException("Unsupported database type: " . $dbType);
              }

            $this->logDebug('Executing resubmission update query', ['id' => $id, 'query' => $query]);

            $jobDB = $this->getJobDB();
            $jobDB->exec($query);

            $this->logDebug('Resubmission updated for invoice', ['id' => $id]);
            } catch (Exception $e) {
            $this->logWarning('Failed to update resubmission date for invoice', ['id' => $id, 'error' => $e->getMessage()]);
            }
          }
        }

      $this->logInfo('fetchInvoices completed');
      } catch (JobRouterException $e) {
      throw $e;
      } catch (Exception $e) {
      $this->logError('Unexpected error in fetchInvoices', $e);
      throw new JobRouterException('Fetch invoices error: ' . $e->getMessage());
      }
    }
  }
