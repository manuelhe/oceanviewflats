<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Fulfillment;

use PDO;
use Throwable;

/**
 * Outbound synchronization adapter for Condominium Administration Portal (Huésped Manager).
 *
 * Implements the 2-step synchronization protocol documented in ADR 0008:
 * Step 1: POST to reg_guest_owner_pre.php?unit={propertyId}&check={checkToken} to register stay & obtain last_id.
 * Step 2: POST to reg_hpds_pre.php with occupant details, primary guest phone fallback, and session cookies.
 */
final class HuespedManagerClearanceSync implements CondominiumClearanceSyncInterface
{
    public const DEFAULT_BASE_URL = 'https://salguerosunset.huespedmanager.com.co';
    public const DEFAULT_1707_CHECK = 'ep92449222';
    public const DEFAULT_1606_CHECK = 'ep24281580';

    private readonly string $baseUrl;
    private readonly string $token1707;
    private readonly string $token1606;

    public function __construct(
        private readonly CondominiumClearanceRepositoryInterface $repository,
        private readonly HttpTransportInterface $transport = new CurlHttpTransport(),
        ?string $baseUrl = null,
        ?string $token1707 = null,
        ?string $token1606 = null,
    ) {
        $this->baseUrl = $baseUrl
            ?? $_ENV['HUESPED_MANAGER_BASE_URL']
            ?? $_SERVER['HUESPED_MANAGER_BASE_URL']
            ?? (getenv('HUESPED_MANAGER_BASE_URL') ?: null)
            ?: self::DEFAULT_BASE_URL;

        $this->token1707 = $token1707
            ?? $_ENV['HUESPED_MANAGER_1707_CHECK']
            ?? $_SERVER['HUESPED_MANAGER_1707_CHECK']
            ?? (getenv('HUESPED_MANAGER_1707_CHECK') ?: null)
            ?: self::DEFAULT_1707_CHECK;

        $this->token1606 = $token1606
            ?? $_ENV['HUESPED_MANAGER_1606_CHECK']
            ?? $_SERVER['HUESPED_MANAGER_1606_CHECK']
            ?? (getenv('HUESPED_MANAGER_1606_CHECK') ?: null)
            ?: self::DEFAULT_1606_CHECK;
    }

    public static function createDefault(PDO $pdo, ?HttpTransportInterface $transport = null): self
    {
        return new self(
            repository: new PdoCondominiumClearanceRepository($pdo),
            transport: $transport ?? new CurlHttpTransport()
        );
    }

    /**
     * @param array<int, OccupantDetails|array<string, mixed>> $guests
     */
    public function sync(
        string $reservationUid,
        string $propertyId,
        string $checkIn,
        string $checkOut,
        array $guests,
        ?string $carPlates = null,
        ?string $notes = null
    ): CondominiumClearance {
        $existing = $this->repository->findByReservationUid($reservationUid);
        $clearance = $existing !== null
            ? $existing->recordAttempt()
            : CondominiumClearance::createPending($reservationUid, $propertyId);

        // 1. Validate property check token
        $checkToken = $this->getCheckTokenForProperty($propertyId);
        if ($checkToken === null || trim($checkToken) === '') {
            $clearance = $clearance->markFailed(sprintf('No check token configured for property ID: %s', $propertyId));
            $this->repository->save($clearance);
            return $clearance;
        }

        // 2. Parse and normalize occupants
        $occupants = $this->normalizeOccupants($guests);
        if ($occupants === []) {
            $clearance = $clearance->markFailed('Occupant list is empty; at least 1 guest required for condominium clearance.');
            $this->repository->save($clearance);
            return $clearance;
        }

        $primaryPhone = trim((string) ($occupants[0]->phone ?? ''));

        $productionBase = $this->getProductionBaseUrl();
        $step1Url = sprintf('%s/reg_guest_owner_pre.php?unit=%s&check=%s', $productionBase, urlencode($propertyId), urlencode($checkToken));

        $step1Payload = [
            'send_apt' => $propertyId,
            'send_indate' => $checkIn,
            'send_horain' => '15:00',
            'send_outdate' => $checkOut,
            'send_horaout' => '11:00',
            'send_fpay' => 'TRANSFERENCIA',
            'send_cant_hp' => (string) count($occupants),
            'send_price' => '0',
            'send_car' => $carPlates ?? '',
            'send_observacion' => $notes ?? '',
            'send_btn' => '1',
        ];

        // 3. Step 1: Register reservation stay and acquire consecutivo (last_id)
        try {
            $response1 = $this->transport->post($step1Url, $step1Payload);
        } catch (Throwable $e) {
            $clearance = $clearance->withPayload(['step1' => $step1Payload])
                ->markFailed('Step 1 transport exception: ' . $e->getMessage());
            $this->repository->save($clearance);
            return $clearance;
        }

        if ($response1['error'] !== null) {
            $clearance = $clearance->withPayload(['step1' => $step1Payload])
                ->markFailed('Step 1 HTTP transport error: ' . $response1['error']);
            $this->repository->save($clearance);
            return $clearance;
        }

        if ($response1['statusCode'] < 200 || $response1['statusCode'] >= 300) {
            $clearance = $clearance->withPayload(['step1' => $step1Payload])
                ->markFailed(sprintf(
                    'Step 1 returned unexpected HTTP status %d: %s',
                    $response1['statusCode'],
                    substr($response1['body'], 0, 250)
                ));
            $this->repository->save($clearance);
            return $clearance;
        }

        $json1 = json_decode($response1['body'], true);
        if (!is_array($json1) || empty($json1['last_id']) || !is_numeric($json1['last_id']) || (int) $json1['last_id'] <= 0) {
            $clearance = $clearance->withPayload(['step1' => $step1Payload])
                ->markFailed("Step 1 response missing valid 'last_id': " . substr($response1['body'], 0, 250));
            $this->repository->save($clearance);
            return $clearance;
        }

        $lastId = (int) $json1['last_id'];
        $cookies = $response1['cookies'];

        // 4. Step 2: Prepare occupant form arrays and submit with session cookies
        $typeid = [];
        $ide = [];
        $name = [];
        $sname = [];
        $lname = [];
        $mname = [];
        $country = [];
        $phone = [];
        $nexo = [];
        $covid = [];

        foreach ($occupants as $i => $occ) {
            $typeid[] = $occ->getPortalDocType();
            $ide[] = $occ->docNum;
            $name[] = $occ->firstName ?? $occ->name;
            $sname[] = $occ->middleName ?? '';
            $lname[] = $occ->lastName ?? '';
            $mname[] = $occ->secondLastName ?? '';
            $country[] = $occ->normalizeCountry($occ->country);

            // Companion inherits primary guest phone if empty
            $occPhone = trim((string) ($occ->phone ?? ''));
            $phone[] = $occPhone !== '' ? $occPhone : $primaryPhone;

            // Primary occupant is TITULAR, companions are ACOMPAÑANTE
            $nexo[] = $i === 0 ? 'TITULAR' : 'ACOMPAÑANTE';
            $covid[] = '0';
        }

        $step2Url = sprintf('%s/reg_hpds_pre.php', $productionBase);
        $step2Payload = [
            'consecutivo' => (string) $lastId,
            'typeid' => $typeid,
            'ide' => $ide,
            'name' => $name,
            'sname' => $sname,
            'lname' => $lname,
            'mname' => $mname,
            'country' => $country,
            'phone' => $phone,
            'nexo' => $nexo,
            'covid3' => $covid,
            'covid4' => $covid,
            'covid5' => $covid,
            'covid6' => $covid,
            'covid7' => $covid,
            'covid8' => $covid,
            'covid9' => $covid,
            'covid10' => $covid,
        ];

        $fullPayload = [
            'step1' => $step1Payload,
            'step2' => $step2Payload,
        ];

        try {
            $response2 = $this->transport->post(
                $step2Url,
                $step2Payload,
                [],
                [
                    'cookies' => $cookies,
                    'followRedirects' => false,
                ]
            );
        } catch (Throwable $e) {
            $clearance = $clearance->withPayload($fullPayload)
                ->markFailed('Step 2 transport exception: ' . $e->getMessage());
            $this->repository->save($clearance);
            return $clearance;
        }

        // 5. Verify Step 2 outcome
        $isSuccess = false;
        $failureReason = null;

        if ($response2['error'] !== null) {
            $failureReason = 'Step 2 HTTP transport error: ' . $response2['error'];
        } else {
            $status2 = $response2['statusCode'];
            $location = $response2['headers']['location'] ?? '';

            if ($status2 === 302 || $status2 === 303 || $status2 === 301) {
                $locationLower = strtolower($location);
                if (str_contains($locationLower, 'err') || str_contains($locationLower, 'fallo')) {
                    $failureReason = sprintf('Step 2 redirected to error page: %s', $location);
                } else {
                    $isSuccess = true;
                }
            } elseif ($status2 === 200) {
                $bodyLower = strtolower($response2['body']);
                $effLower = strtolower($response2['effectiveUrl'] ?? '');
                if (
                    str_contains($effLower, 'succ')
                    || str_contains($bodyLower, 'succ')
                    || str_contains($bodyLower, 'registrad')
                    || str_contains($bodyLower, 'exitos')
                    || str_contains($bodyLower, 'consecutivo')
                ) {
                    $isSuccess = true;
                } else {
                    $failureReason = sprintf('Step 2 returned HTTP 200 without recognized success indicator: %s', substr($response2['body'], 0, 250));
                }
            } else {
                $failureReason = sprintf('Step 2 returned unexpected HTTP status %d: %s', $status2, substr($response2['body'], 0, 250));
            }
        }

        if ($isSuccess) {
            $clearance = $clearance->withPayload($fullPayload)
                ->markSynced((string) $lastId);
        } else {
            $clearance = $clearance->withPayload($fullPayload)
                ->markFailed($failureReason ?? 'Step 2 failed during occupant submission.');
        }

        $this->repository->save($clearance);
        return $clearance;
    }

    private function getProductionBaseUrl(): string
    {
        $clean = rtrim($this->baseUrl, '/');
        if (str_ends_with($clean, '/propietarios/production')) {
            return $clean;
        }
        return $clean . '/propietarios/production';
    }

    private function getCheckTokenForProperty(string $propertyId): ?string
    {
        $clean = trim($propertyId);
        if ($clean === '1707') {
            return $this->token1707;
        }
        if ($clean === '1606') {
            return $this->token1606;
        }
        return null;
    }

    /**
     * @param array<int, OccupantDetails|array<string, mixed>> $guests
     * @return list<OccupantDetails>
     */
    private function normalizeOccupants(array $guests): array
    {
        $codeMap = [
            'CC' => 'Cédula de Ciudadanía',
            'PA' => 'Passport',
            'TI' => 'Tarjeta de Identidad',
            'RC' => 'Registro Civil',
            'CE' => 'Cédula de Extranjería',
            'DE' => 'National ID',
            'DL' => 'Driver License',
        ];

        $normalized = [];
        $index = 1;
        foreach ($guests as $guest) {
            if ($guest instanceof OccupantDetails) {
                $normalized[] = $guest;
                $index++;
            } elseif (is_array($guest)) {
                $guestData = $guest;
                if (isset($guestData['doc_number']) && !isset($guestData['doc_num'])) {
                    $guestData['doc_num'] = (string) $guestData['doc_number'];
                }
                $rawDocType = (string) ($guestData['doc_type'] ?? $guestData['docType'] ?? '');
                $upperDoc = strtoupper(trim($rawDocType));
                if (isset($codeMap[$upperDoc])) {
                    $guestData['doc_type'] = $codeMap[$upperDoc];
                }
                $normalized[] = OccupantDetails::fromArray($guestData, $index);
                $index++;
            }
        }
        return $normalized;
    }
}
