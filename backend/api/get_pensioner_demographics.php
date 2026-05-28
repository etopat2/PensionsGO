<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'], $_SESSION['userRole'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

ensureFileMovementTables($conn);
ensurePensionerDeathReportingTables($conn);

function demographicsNormalizeLabel(string $value): string {
    return trim(preg_replace('/\s+/', ' ', $value));
}

function demographicsDistrictRegionLookup(mysqli $conn): array {
    $lookup = [];
    foreach (getPoliticalDistricts($conn) as $row) {
        $district = demographicsNormalizeLabel((string)($row['district'] ?? ''));
        if ($district === '') {
            continue;
        }
        $lookup[strtolower($district)] = [
            'district' => $district,
            'region' => demographicsNormalizeLabel((string)($row['region'] ?? '')) ?: 'Unmapped'
        ];
    }
    return $lookup;
}

function demographicsResolveLocation(string $address, array $lookup): array {
    $district = demographicsNormalizeLabel($address);
    if ($district === '') {
        return ['district' => 'Unspecified', 'region' => 'Unspecified'];
    }

    $key = strtolower($district);
    if (isset($lookup[$key])) {
        return [
            'district' => (string)$lookup[$key]['district'],
            'region' => (string)$lookup[$key]['region']
        ];
    }

    return ['district' => $district, 'region' => 'Unmapped'];
}

function demographicsAgeAt(?string $birthDate, ?string $asOfDate): ?float {
    return calculateYearsBetweenDates($birthDate, $asOfDate, 1);
}

function demographicsSnapshotFromRow(array $row): array {
    return [
        'registryId' => (int)($row['registryId'] ?? 0),
        'regNo' => (string)($row['regNo'] ?? ''),
        'name' => (string)($row['name'] ?? ''),
        'displayName' => (string)($row['displayName'] ?? ''),
        'gender' => (string)($row['gender'] ?? ''),
        'ageYears' => $row['ageYears'] !== null ? (float)$row['ageYears'] : null,
        'district' => (string)($row['district'] ?? ''),
        'region' => (string)($row['region'] ?? ''),
        'retirementType' => (string)($row['retirementType'] ?? ''),
        'retirementTypeLabel' => (string)($row['retirementTypeLabel'] ?? ''),
        'retirementDate' => (string)($row['retirementDate'] ?? ''),
        'dateOfDeath' => (string)($row['dateOfDeath'] ?? ''),
        'estateExpiryDate' => (string)($row['estateExpiryDate'] ?? ''),
        'estateStatus' => (string)($row['estateStatus'] ?? ''),
        'yearsAfterRetirement' => $row['yearsAfterRetirement'] !== null ? (float)$row['yearsAfterRetirement'] : null,
        'yearsAfterRetirementLabel' => (string)($row['yearsAfterRetirementLabel'] ?? '')
    ];
}

function demographicsExtreme(array $rows, string $direction = 'oldest'): ?array {
    $rows = array_values(array_filter($rows, static function (array $row): bool {
        return isset($row['ageYears']) && $row['ageYears'] !== null;
    }));
    if (empty($rows)) {
        return null;
    }

    usort($rows, static function (array $left, array $right) use ($direction): int {
        $leftAge = (float)($left['ageYears'] ?? -1);
        $rightAge = (float)($right['ageYears'] ?? -1);
        $ageComparison = ($direction === 'youngest')
            ? ($leftAge <=> $rightAge)
            : ($rightAge <=> $leftAge);
        if ($ageComparison !== 0) {
            return $ageComparison;
        }
        return strcasecmp((string)($left['displayName'] ?? ''), (string)($right['displayName'] ?? ''));
    });

    return demographicsSnapshotFromRow($rows[0]);
}

function demographicsBuildGroupExtremes(array $rows, callable $keyResolver): array {
    $groups = [];
    foreach ($rows as $row) {
        $key = (string)$keyResolver($row);
        if ($key === '') {
            $key = 'Unspecified';
        }
        if (!isset($groups[$key])) {
            $groups[$key] = [];
        }
        $groups[$key][] = $row;
    }

    $items = [];
    foreach ($groups as $key => $groupRows) {
        $items[] = [
            'group' => $key,
            'aliveCount' => count($groupRows),
            'oldest' => demographicsExtreme($groupRows, 'oldest'),
            'youngest' => demographicsExtreme($groupRows, 'youngest')
        ];
    }

    usort($items, static function (array $left, array $right): int {
        $countComparison = ((int)($right['aliveCount'] ?? 0)) <=> ((int)($left['aliveCount'] ?? 0));
        if ($countComparison !== 0) {
            return $countComparison;
        }
        return strcasecmp((string)($left['group'] ?? ''), (string)($right['group'] ?? ''));
    });

    return $items;
}

function demographicsBuildFilterOptions(array $rows): array {
    $genders = [];
    $regions = [];
    $districts = [];
    $locations = [];
    $retirementTypes = [];

    foreach ($rows as $row) {
        $gender = demographicsNormalizeLabel((string)($row['gender'] ?? ''));
        if ($gender !== '') {
            $genders[strtolower($gender)] = $gender;
        }

        $region = demographicsNormalizeLabel((string)($row['region'] ?? ''));
        if ($region !== '') {
            $regions[strtolower($region)] = $region;
        }

        $district = demographicsNormalizeLabel((string)($row['district'] ?? ''));
        if ($district !== '') {
            $districts[strtolower($district)] = $district;
            $locationKey = strtolower($district . '|' . $region);
            $locations[$locationKey] = [
                'district' => $district,
                'region' => $region !== '' ? $region : 'Unspecified'
            ];
        }

        $retirementType = (string)($row['retirementType'] ?? '');
        if ($retirementType !== '' && !isset($retirementTypes[$retirementType])) {
            $retirementTypes[$retirementType] = [
                'value' => $retirementType,
                'label' => (string)($row['retirementTypeLabel'] ?? getBenefitsRetirementTypeLabel($retirementType))
            ];
        }
    }

    natcasesort($genders);
    natcasesort($regions);
    natcasesort($districts);
    uasort($locations, static function (array $left, array $right): int {
        $regionComparison = strcasecmp((string)($left['region'] ?? ''), (string)($right['region'] ?? ''));
        if ($regionComparison !== 0) {
            return $regionComparison;
        }
        return strcasecmp((string)($left['district'] ?? ''), (string)($right['district'] ?? ''));
    });
    uasort($retirementTypes, static fn(array $left, array $right): int => strcasecmp((string)($left['label'] ?? ''), (string)($right['label'] ?? '')));

    return [
        'genders' => array_values($genders),
        'regions' => array_values($regions),
        'districts' => array_values($districts),
        'locations' => array_values($locations),
        'livingStatuses' => ['Alive', 'Deceased'],
        'retirementTypes' => array_values($retirementTypes)
    ];
}

function demographicsMatchesFilters(array $row, array $filters): bool {
    $livingStatusFilter = trim((string)($filters['livingStatus'] ?? ''));
    $genderFilter = trim((string)($filters['gender'] ?? ''));
    $regionFilter = trim((string)($filters['region'] ?? ''));
    $districtFilter = trim((string)($filters['district'] ?? ''));
    $retirementTypeFilter = trim((string)($filters['retirementType'] ?? ''));
    $search = strtolower(trim((string)($filters['search'] ?? '')));

    if ($livingStatusFilter !== '' && strcasecmp((string)($row['livingStatus'] ?? ''), $livingStatusFilter) !== 0) {
        return false;
    }
    if ($genderFilter !== '' && strcasecmp((string)($row['gender'] ?? ''), $genderFilter) !== 0) {
        return false;
    }
    if ($regionFilter !== '' && strcasecmp((string)($row['region'] ?? ''), $regionFilter) !== 0) {
        return false;
    }
    if ($districtFilter !== '' && strcasecmp((string)($row['district'] ?? ''), $districtFilter) !== 0) {
        return false;
    }
    if ($retirementTypeFilter !== '' && strcasecmp((string)($row['retirementType'] ?? ''), $retirementTypeFilter) !== 0) {
        return false;
    }
    if ($search !== '') {
        $haystack = strtolower(implode(' ', [
            (string)($row['regNo'] ?? ''),
            (string)($row['displayName'] ?? ''),
            (string)($row['name'] ?? ''),
            (string)($row['gender'] ?? ''),
            (string)($row['district'] ?? ''),
            (string)($row['region'] ?? ''),
            (string)($row['retirementTypeLabel'] ?? ''),
            (string)($row['livingStatus'] ?? ''),
            (string)($row['estateStatus'] ?? ''),
            (string)($row['yearsAfterRetirementLabel'] ?? ''),
            (string)($row['payType'] ?? '')
        ]));
        if (strpos($haystack, $search) === false) {
            return false;
        }
    }

    return true;
}

try {
    $canReportDeath = currentUserHasPermission($conn, 'registry.edit');
    $districtLookup = demographicsDistrictRegionLookup($conn);
    $today = date('Y-m-d');

    $query = $conn->query("
        SELECT
            id,
            regNo,
            title,
            sName,
            fName,
            gender,
            birthDate,
            enlistmentDate,
            retirementDate,
            retirementType,
            livingStatus,
            payType,
            address,
            telNo,
            dateOfDeath,
            deathNotificationDate,
            deathNotifierName,
            deathNotifierContact,
            estateExpiryDate,
            estateStatus,
            COALESCE(is_deleted, 0) AS is_deleted
        FROM tb_fileregistry
        WHERE COALESCE(is_deleted, 0) = 0
    ");
    if (!$query) {
        throw new RuntimeException('Unable to load pensioner demographics.');
    }

    $rows = [];
    while ($raw = $query->fetch_assoc()) {
        $retirementType = normalizeBenefitsRetirementTypeKey((string)($raw['retirementType'] ?? ''));
        $payType = deriveRegistryPayTypeFromProfile(
            $retirementType,
            (string)($raw['enlistmentDate'] ?? ''),
            (string)($raw['retirementDate'] ?? ''),
            (string)($raw['payType'] ?? '')
        );
        if (normalizeRegistryPayType($payType) !== 'Pensioner') {
            continue;
        }

        $livingStatus = normalizeRegistryLivingStatus((string)($raw['livingStatus'] ?? ''));
        $location = demographicsResolveLocation((string)($raw['address'] ?? ''), $districtLookup);
        $dateOfDeath = trim((string)($raw['dateOfDeath'] ?? ''));
        $ageReferenceDate = $livingStatus === 'Deceased' && $dateOfDeath !== '' ? $dateOfDeath : $today;
        $ageYears = demographicsAgeAt((string)($raw['birthDate'] ?? ''), $ageReferenceDate);
        $retirementReferenceDate = $dateOfDeath !== '' ? $dateOfDeath : $today;
        $yearsAfterRetirement = calculateYearsBetweenDates((string)($raw['retirementDate'] ?? ''), $retirementReferenceDate, 1);
        $yearsAfterRetirementLabel = formatYearsBetweenDatesLabel((string)($raw['retirementDate'] ?? ''), $retirementReferenceDate);
        $estate = evaluatePensionEstateLifecycle(
            (string)($raw['retirementDate'] ?? ''),
            $payType,
            $livingStatus,
            $dateOfDeath
        );

        $rows[] = [
            'registryId' => (int)($raw['id'] ?? 0),
            'regNo' => (string)($raw['regNo'] ?? ''),
            'title' => (string)($raw['title'] ?? ''),
            'displayName' => trim((string)($raw['sName'] ?? '') . ' ' . (string)($raw['fName'] ?? '')),
            'name' => formatTitleName((string)($raw['title'] ?? ''), (string)($raw['sName'] ?? ''), (string)($raw['fName'] ?? '')),
            'gender' => (string)($raw['gender'] ?? ''),
            'birthDate' => (string)($raw['birthDate'] ?? ''),
            'enlistmentDate' => (string)($raw['enlistmentDate'] ?? ''),
            'retirementDate' => (string)($raw['retirementDate'] ?? ''),
            'retirementType' => $retirementType,
            'retirementTypeLabel' => $retirementType !== '' ? getBenefitsRetirementTypeLabel($retirementType) : 'Unspecified',
            'livingStatus' => $livingStatus,
            'payType' => $payType,
            'district' => $location['district'],
            'region' => $location['region'],
            'contact' => (string)($raw['telNo'] ?? ''),
            'dateOfDeath' => $dateOfDeath,
            'notificationDate' => (string)($raw['deathNotificationDate'] ?? ''),
            'notifierName' => (string)($raw['deathNotifierName'] ?? ''),
            'notifierContact' => (string)($raw['deathNotifierContact'] ?? ''),
            'estateExpiryDate' => (string)($estate['estateExpiryDate'] ?? $raw['estateExpiryDate'] ?? ''),
            'estateStatus' => (string)($estate['label'] ?? $raw['estateStatus'] ?? ''),
            'ageYears' => $ageYears,
            'yearsAfterRetirement' => $yearsAfterRetirement,
            'yearsAfterRetirementLabel' => $yearsAfterRetirementLabel,
            'canReportDeath' => $canReportDeath && $retirementType !== 'death' && $livingStatus !== 'Deceased'
        ];
    }
    $query->free();

    $aliveRows = array_values(array_filter($rows, static function (array $row): bool {
        return (string)($row['livingStatus'] ?? '') === 'Alive';
    }));
    $deceasedRows = array_values(array_filter($rows, static function (array $row): bool {
        return (string)($row['livingStatus'] ?? '') === 'Deceased';
    }));

    $observedDeathRows = array_values(array_filter($deceasedRows, static function (array $row): bool {
        return (string)($row['retirementType'] ?? '') !== 'death'
            && trim((string)($row['dateOfDeath'] ?? '')) !== ''
            && $row['yearsAfterRetirement'] !== null;
    }));
    $observedYears = array_values(array_map(static function (array $row): float {
        return (float)($row['yearsAfterRetirement'] ?? 0);
    }, $observedDeathRows));
    sort($observedYears);
    $observedCount = count($observedYears);
    $medianYears = null;
    if ($observedCount > 0) {
        $mid = (int)floor($observedCount / 2);
        $medianYears = ($observedCount % 2 === 0)
            ? round(($observedYears[$mid - 1] + $observedYears[$mid]) / 2, 1)
            : round($observedYears[$mid], 1);
    }

    $withinCapCount = 0;
    $beyondCapCount = 0;
    foreach ($observedDeathRows as $row) {
        $expiry = trim((string)($row['estateExpiryDate'] ?? ''));
        $deathDate = trim((string)($row['dateOfDeath'] ?? ''));
        if ($expiry !== '' && $deathDate !== '' && $deathDate >= $expiry) {
            $beyondCapCount++;
        } else {
            $withinCapCount++;
        }
    }

    $mode = strtolower(trim((string)($_GET['mode'] ?? 'summary')));
    if ($mode === 'list') {
        $focus = strtolower(trim((string)($_GET['focus'] ?? 'oldest')));
        if (!in_array($focus, ['oldest', 'youngest', 'all'], true)) {
            $focus = 'oldest';
        }
        $genderFilter = trim((string)($_GET['gender'] ?? ''));
        $regionFilter = trim((string)($_GET['region'] ?? ''));
        $districtFilter = trim((string)($_GET['district'] ?? ''));
        $retirementTypeFilter = normalizeBenefitsRetirementTypeKey((string)($_GET['retirement_type'] ?? ''));
        $rawLivingStatusFilter = trim((string)($_GET['living_status'] ?? 'Alive'));
        $livingStatusFilter = $rawLivingStatusFilter === '' ? '' : normalizeRegistryLivingStatus($rawLivingStatusFilter);
        $search = strtolower(trim((string)($_GET['search'] ?? '')));
        $limit = max(10, min(200, (int)($_GET['limit'] ?? 60)));

        $activeFilters = [
            'livingStatus' => $livingStatusFilter,
            'gender' => $genderFilter,
            'region' => $regionFilter,
            'district' => $districtFilter,
            'retirementType' => $retirementTypeFilter,
            'search' => $search
        ];

        $filtered = array_values(array_filter($rows, static function (array $row) use ($activeFilters): bool {
            return demographicsMatchesFilters($row, $activeFilters);
        }));

        usort($filtered, static function (array $left, array $right) use ($focus): int {
            if ($focus === 'all') {
                return strcasecmp((string)($left['displayName'] ?? ''), (string)($right['displayName'] ?? ''));
            }
            $leftAge = $left['ageYears'] !== null ? (float)$left['ageYears'] : ($focus === 'youngest' ? INF : -INF);
            $rightAge = $right['ageYears'] !== null ? (float)$right['ageYears'] : ($focus === 'youngest' ? INF : -INF);
            $comparison = $focus === 'youngest'
                ? ($leftAge <=> $rightAge)
                : ($rightAge <=> $leftAge);
            if ($comparison !== 0) {
                return $comparison;
            }
            return strcasecmp((string)($left['displayName'] ?? ''), (string)($right['displayName'] ?? ''));
        });

        $matchedCount = count($filtered);
        $summaryOldest = demographicsExtreme($filtered, 'oldest');
        $summaryYoungest = demographicsExtreme($filtered, 'youngest');
        $filtered = array_slice($filtered, 0, $limit);
        $returnedCount = count($filtered);

        echo json_encode([
            'success' => true,
            'mode' => 'list',
            'rows' => array_values(array_map(static fn(array $row): array => demographicsSnapshotFromRow($row) + [
                'livingStatus' => (string)($row['livingStatus'] ?? ''),
                'payType' => (string)($row['payType'] ?? ''),
                'birthDate' => (string)($row['birthDate'] ?? ''),
                'contact' => (string)($row['contact'] ?? ''),
                'notificationDate' => (string)($row['notificationDate'] ?? ''),
                'notifierName' => (string)($row['notifierName'] ?? ''),
                'notifierContact' => (string)($row['notifierContact'] ?? ''),
                'canReportDeath' => (bool)($row['canReportDeath'] ?? false)
            ], $filtered)),
            'summary' => [
                'focus' => $focus,
                'matchedCount' => $matchedCount,
                'returnedCount' => $returnedCount,
                'limit' => $limit,
                'truncated' => $matchedCount > $returnedCount,
                'oldest' => $summaryOldest,
                'youngest' => $summaryYoungest
            ],
            'permissions' => [
                'canReportDeath' => $canReportDeath
            ]
        ]);
        exit;
    }

    $averageYears = $observedCount > 0 ? round(array_sum($observedYears) / $observedCount, 1) : null;
    $averageAgeAtDeath = null;
    $ageAtDeathValues = array_values(array_filter(array_map(static function (array $row) {
        return $row['ageYears'];
    }, $observedDeathRows), static fn($value): bool => $value !== null));
    if (!empty($ageAtDeathValues)) {
        $averageAgeAtDeath = round(array_sum($ageAtDeathValues) / count($ageAtDeathValues), 1);
    }

    $forecastPopulation = array_values(array_filter($aliveRows, static function (array $row): bool {
        return (string)($row['retirementType'] ?? '') !== 'death'
            && $row['yearsAfterRetirement'] !== null;
    }));
    $averageRemainingYears = null;
    if ($averageYears !== null && !empty($forecastPopulation)) {
        $remainingYears = array_map(static function (array $row) use ($averageYears): float {
            return round(max(0, $averageYears - (float)($row['yearsAfterRetirement'] ?? 0)), 1);
        }, $forecastPopulation);
        $averageRemainingYears = round(array_sum($remainingYears) / count($remainingYears), 1);
    }

    $filters = demographicsBuildFilterOptions($rows);

    echo json_encode([
        'success' => true,
        'mode' => 'summary',
        'summary' => [
            'totals' => [
                'pensionerPopulation' => count($rows),
                'alivePensioners' => count($aliveRows),
                'deceasedPensioners' => count($deceasedRows),
                'activeEstates' => count(array_filter($deceasedRows, static fn(array $row): bool => (string)($row['estateStatus'] ?? '') === 'Estate Active')),
                'expiredEstates' => count(array_filter($deceasedRows, static fn(array $row): bool => (string)($row['estateStatus'] ?? '') === '15 Years Elapsed'))
            ],
            'overall' => [
                'oldestAlive' => demographicsExtreme($aliveRows, 'oldest'),
                'youngestAlive' => demographicsExtreme($aliveRows, 'youngest')
            ],
            'byGender' => demographicsBuildGroupExtremes($aliveRows, static fn(array $row): string => (string)($row['gender'] ?? 'Unspecified')),
            'byRegion' => demographicsBuildGroupExtremes($aliveRows, static fn(array $row): string => (string)($row['region'] ?? 'Unspecified')),
            'byRetirementType' => demographicsBuildGroupExtremes($aliveRows, static fn(array $row): string => (string)($row['retirementTypeLabel'] ?? 'Unspecified')),
            'lifespan' => [
                'observedDeaths' => $observedCount,
                'averageYearsAfterRetirement' => $averageYears,
                'medianYearsAfterRetirement' => $medianYears,
                'longestYearsAfterRetirement' => $observedCount > 0 ? round(max($observedYears), 1) : null,
                'averageAgeAtDeath' => $averageAgeAtDeath,
                'projectedAverageRemainingYears' => $averageRemainingYears,
                'projectedAgeAtDeath' => $averageAgeAtDeath,
                'forecastPopulation' => count($forecastPopulation),
                'within15YearsCount' => $withinCapCount,
                'beyond15YearsCount' => $beyondCapCount
            ]
        ],
        'filters' => $filters,
        'permissions' => [
            'canReportDeath' => $canReportDeath
        ]
    ]);
} catch (Throwable $e) {
    error_log('get_pensioner_demographics.php error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Unable to load demographics insights.'
    ]);
}

$conn->close();
