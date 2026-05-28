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

$role = normalizeRoleKey((string)($_SESSION['userRole'] ?? ''));
$isPensioner = $role === 'pensioner';
$canUseLifeCertTools = currentUserHasPermission($conn, 'registry.life_certificate.submit');
$canEditRegistry = currentUserHasPermission($conn, 'registry.edit');
if (!$isPensioner && !$canUseLifeCertTools && !$canEditRegistry) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

if (function_exists('ensureFileMovementTables')) {
    ensureFileMovementTables($conn);
}
if (function_exists('ensureStaffDueExtendedColumns')) {
    ensureStaffDueExtendedColumns($conn);
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request payload']);
    exit;
}

function normalizeRestrictedContactValue(string $value): string
{
    return strtolower(preg_replace('/\s+/', ' ', trim($value)));
}

function normalizeRestrictedContactPhone(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $normalized = normalizePhoneNumber($value);
    return $normalized !== null ? $normalized : preg_replace('/\s+/', '', $value);
}

try {
    $transactionStarted = false;
    $resolvedUser = null;
    if ($isPensioner) {
        $resolved = resolvePensionerOwnedRegistry($conn, (string)$_SESSION['userId']);
        if (!$resolved) {
            throw new RuntimeException('Your registry record could not be linked to this account.');
        }
        $regNo = (string)($resolved['regNo'] ?? '');
        $resolvedUser = is_array($resolved['user'] ?? null) ? $resolved['user'] : null;
    } else {
        $regNo = trim((string)($payload['regNo'] ?? ''));
    }

    if ($regNo === '') {
        throw new RuntimeException('File number is required.');
    }

    $checkStmt = $conn->prepare("
        SELECT
            fr.id,
            fr.telNo,
            fr.applicant_email,
            fr.address,
            fr.next_of_kin,
            fr.next_of_kin_contact,
            fr.bank_name,
            fr.bank_account,
            fr.bank_branch,
            fr.retirementType AS registry_retirement_type,
            fr.livingStatus AS registry_living_status,
            sd.id AS staff_id,
            sd.telNo AS staff_telNo,
            sd.applicant_email AS staff_email,
            sd.address AS staff_address,
            sd.next_of_kin AS staff_next_of_kin,
            sd.next_of_kin_contact AS staff_next_of_kin_contact,
            sd.prisonUnit AS staff_station,
            sd.retirementType AS staff_retirement_type,
            sd.livingStatus AS staff_living_status
        FROM tb_fileregistry fr
        LEFT JOIN tb_staffdue sd ON sd.regNo = fr.regNo
        WHERE fr.regNo = ?
        LIMIT 1
    ");
    if (!$checkStmt) {
        throw new RuntimeException('Unable to validate registry record.');
    }
    $checkStmt->bind_param('s', $regNo);
    $checkStmt->execute();
    $existing = $checkStmt->get_result()->fetch_assoc() ?: null;
    $checkStmt->close();

    if (!$existing) {
        throw new RuntimeException('Registry record not found.');
    }

    $existingAddress = trim((string)($existing['address'] ?? $existing['staff_address'] ?? ''));
    $auditChanges = [];
    $formatAuditValue = function(?string $value, string $field = ''): string {
        $value = trim((string)($value ?? ''));
        if ($value === '') {
            return '(empty)';
        }
        if ($field === 'bank_account') {
            $digits = preg_replace('/\s+/', '', $value);
            $tail = strlen($digits) > 4 ? substr($digits, -4) : $digits;
            return '****' . $tail;
        }
        return $value;
    };
    $addAuditChange = function(string $label, ?string $oldValue, ?string $newValue, string $field = '') use (&$auditChanges, $formatAuditValue): void {
        $oldValue = trim((string)($oldValue ?? ''));
        $newValue = trim((string)($newValue ?? ''));
        if ($oldValue === $newValue) {
            return;
        }
        $auditChanges[] = "Edited {$label} from '{$formatAuditValue($oldValue, $field)}' to '{$formatAuditValue($newValue, $field)}'";
    };
    $emailKeyPresent = array_key_exists('applicant_email', $payload) || array_key_exists('email', $payload);
    $addressKeyPresent = array_key_exists('address', $payload);
    $phoneKeyPresent = array_key_exists('telNo', $payload) || array_key_exists('phone', $payload);
    $nextOfKinKeyPresent = array_key_exists('next_of_kin', $payload);
    $nextOfKinContactKeyPresent = array_key_exists('next_of_kin_contact', $payload);
    $stationKeyPresent = array_key_exists('station', $payload) || array_key_exists('prisonUnit', $payload);
    $bankNameKeyPresent = array_key_exists('bank_name', $payload);
    $bankAccountKeyPresent = array_key_exists('bank_account', $payload);
    $bankBranchKeyPresent = array_key_exists('bank_branch', $payload);

    $telNo = $phoneKeyPresent
        ? trim((string)($payload['telNo'] ?? $payload['phone'] ?? ''))
        : trim((string)($existing['telNo'] ?? $existing['staff_telNo'] ?? ''));
    $email = $emailKeyPresent
        ? trim((string)($payload['applicant_email'] ?? $payload['email'] ?? ''))
        : trim((string)($existing['applicant_email'] ?? $existing['staff_email'] ?? ''));
    $address = $addressKeyPresent
        ? trim((string)($payload['address'] ?? ''))
        : $existingAddress;
    $nextOfKin = $nextOfKinKeyPresent
        ? trim((string)($payload['next_of_kin'] ?? ''))
        : trim((string)($existing['next_of_kin'] ?? $existing['staff_next_of_kin'] ?? ''));
    $nextOfKinContact = $nextOfKinContactKeyPresent
        ? trim((string)($payload['next_of_kin_contact'] ?? ''))
        : trim((string)($existing['next_of_kin_contact'] ?? $existing['staff_next_of_kin_contact'] ?? ''));
    $station = $stationKeyPresent
        ? trim((string)($payload['station'] ?? $payload['prisonUnit'] ?? ''))
        : trim((string)($existing['staff_station'] ?? ''));
    $bankName = $bankNameKeyPresent
        ? trim((string)($payload['bank_name'] ?? ''))
        : trim((string)($existing['bank_name'] ?? ''));
    $bankAccount = $bankAccountKeyPresent
        ? trim((string)($payload['bank_account'] ?? ''))
        : trim((string)($existing['bank_account'] ?? ''));
    $bankBranch = $bankBranchKeyPresent
        ? trim((string)($payload['bank_branch'] ?? ''))
        : trim((string)($existing['bank_branch'] ?? ''));

    $currentStation = trim((string)($existing['staff_station'] ?? ''));
    $currentNextOfKin = trim((string)($existing['next_of_kin'] ?? $existing['staff_next_of_kin'] ?? ''));
    $currentNextOfKinContact = trim((string)($existing['next_of_kin_contact'] ?? $existing['staff_next_of_kin_contact'] ?? ''));
    $currentRetirementType = trim((string)($existing['registry_retirement_type'] ?? ''));
    if ($currentRetirementType === '') {
        $currentRetirementType = trim((string)($existing['staff_retirement_type'] ?? ''));
    }
    $currentLivingStatus = trim((string)($existing['registry_living_status'] ?? ''));
    if ($currentLivingStatus === '') {
        $currentLivingStatus = trim((string)($existing['staff_living_status'] ?? ''));
    }
    if ($currentLivingStatus === '' && normalizeRestrictedContactValue($currentRetirementType) === 'death') {
        $currentLivingStatus = 'Deceased';
    }
    $pensionerRestrictedContactFields = $isPensioner
        && (
            normalizeRestrictedContactValue($currentRetirementType) === 'death'
            || normalizeRestrictedContactValue($currentLivingStatus) === 'deceased'
        );

    if ($pensionerRestrictedContactFields) {
        $attemptedRestrictedFieldChange = false;
        if ($stationKeyPresent) {
            $attemptedRestrictedFieldChange = normalizeRestrictedContactValue($station) !== normalizeRestrictedContactValue($currentStation);
        }
        if (!$attemptedRestrictedFieldChange && $nextOfKinKeyPresent) {
            $attemptedRestrictedFieldChange = normalizeRestrictedContactValue($nextOfKin) !== normalizeRestrictedContactValue($currentNextOfKin);
        }
        if (!$attemptedRestrictedFieldChange && $nextOfKinContactKeyPresent) {
            $attemptedRestrictedFieldChange = normalizeRestrictedContactPhone($nextOfKinContact) !== normalizeRestrictedContactPhone($currentNextOfKinContact);
        }
        if ($attemptedRestrictedFieldChange) {
            throw new RuntimeException('Station and next of kin details cannot be edited when the retirement type is death or the living status is deceased.');
        }

        $station = $currentStation;
        $nextOfKin = $currentNextOfKin;
        $nextOfKinContact = $currentNextOfKinContact;
        $stationKeyPresent = false;
        $nextOfKinKeyPresent = false;
        $nextOfKinContactKeyPresent = false;
    }

    if ($telNo !== '') {
        $normalizedPhone = normalizePhoneNumber($telNo);
        if ($normalizedPhone === null) {
            throw new RuntimeException('Invalid phone number format.');
        }
        $telNo = $normalizedPhone;
    }

    if ($nextOfKinContact !== '') {
        $normalizedNextOfKinPhone = normalizePhoneNumber($nextOfKinContact);
        if ($normalizedNextOfKinPhone === null) {
            throw new RuntimeException('Invalid next of kin contact format.');
        }
        $nextOfKinContact = $normalizedNextOfKinPhone;
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Enter a valid email address.');
    }

    if ($address !== '') {
        $resolvedDistrict = resolvePoliticalDistrictName($conn, $address);
        if ($resolvedDistrict !== null) {
            $address = $resolvedDistrict;
        } elseif (normalizePoliticalDistrictName($address) !== normalizePoliticalDistrictName($existingAddress)) {
            throw new RuntimeException('Select a valid district of residence.');
        }
    }

    if ($station !== '') {
        $resolvedStation = resolvePrisonUnitName($conn, $station);
        if ($resolvedStation !== null) {
            $station = $resolvedStation;
        } elseif (strcasecmp($station, trim((string)($existing['staff_station'] ?? ''))) !== 0) {
            throw new RuntimeException('Select a valid station or retirement location.');
        }
    }

    $addAuditChange('Phone number', (string)($existing['telNo'] ?? ''), $telNo, 'phone');
    $addAuditChange('Email address', (string)($existing['applicant_email'] ?? ''), $email);
    $addAuditChange('District of residence', $existingAddress, $address);
    $addAuditChange('Next of kin', (string)($existing['next_of_kin'] ?? ''), $nextOfKin);
    $addAuditChange('Next of kin contact', (string)($existing['next_of_kin_contact'] ?? ''), $nextOfKinContact, 'phone');
    $addAuditChange('Bank name', (string)($existing['bank_name'] ?? ''), $bankName);
    $addAuditChange('Bank account', (string)($existing['bank_account'] ?? ''), $bankAccount, 'bank_account');
    $addAuditChange('Bank branch', (string)($existing['bank_branch'] ?? ''), $bankBranch);
    $addAuditChange('Station / retirement location', (string)($existing['staff_station'] ?? ''), $station);

    $conn->begin_transaction();
    $transactionStarted = true;

    $registryStmt = $conn->prepare("
        UPDATE tb_fileregistry
        SET telNo = ?,
            applicant_email = ?,
            address = ?,
            next_of_kin = ?,
            next_of_kin_contact = ?,
            bank_name = ?,
            bank_account = ?,
            bank_branch = ?
        WHERE regNo = ?
    ");
    if (!$registryStmt) {
        throw new RuntimeException('Unable to prepare registry update.');
    }
    $registryStmt->bind_param(
        'sssssssss',
        $telNo,
        $email,
        $address,
        $nextOfKin,
        $nextOfKinContact,
        $bankName,
        $bankAccount,
        $bankBranch,
        $regNo
    );
    if (!$registryStmt->execute()) {
        $error = $registryStmt->error;
        $registryStmt->close();
        throw new RuntimeException($error ?: 'Unable to update beneficiary details.');
    }
    $registryStmt->close();

    $staffId = (int)($existing['staff_id'] ?? 0);
    if ($staffId > 0) {
        $staffStmt = $conn->prepare("
            UPDATE tb_staffdue
            SET telNo = ?,
                applicant_email = ?,
                address = ?,
                next_of_kin = ?,
                next_of_kin_contact = ?,
                prisonUnit = ?
            WHERE id = ?
            LIMIT 1
        ");
        if (!$staffStmt) {
            throw new RuntimeException('Unable to prepare staff profile update.');
        }
        $staffStmt->bind_param(
            'ssssssi',
            $telNo,
            $email,
            $address,
            $nextOfKin,
            $nextOfKinContact,
            $station,
            $staffId
        );
        if (!$staffStmt->execute()) {
            $error = $staffStmt->error;
            $staffStmt->close();
            throw new RuntimeException($error ?: 'Unable to update station details.');
        }
        $staffStmt->close();
    }

    if ($isPensioner && $resolvedUser) {
        $userEmail = $email !== '' ? $email : trim((string)($resolvedUser['userEmail'] ?? ''));
        $userPhone = $telNo !== '' ? $telNo : trim((string)($resolvedUser['phoneNo'] ?? ''));
        $userStmt = $conn->prepare("
            UPDATE tb_users
            SET userEmail = ?, phoneNo = ?
            WHERE userId = ?
            LIMIT 1
        ");
        if (!$userStmt) {
            throw new RuntimeException('Unable to refresh user account contact details.');
        }
        $sessionUserId = (string)($_SESSION['userId'] ?? '');
        $userStmt->bind_param('sss', $userEmail, $userPhone, $sessionUserId);
        if (!$userStmt->execute()) {
            $error = $userStmt->error;
            $userStmt->close();
            throw new RuntimeException($error ?: 'Unable to update the linked user account.');
        }
        $userStmt->close();
    }

    $conn->commit();

    if (function_exists('logAuditEvent')) {
        $actorId = (string)($_SESSION['userId'] ?? 'system');
        $actorName = (string)($_SESSION['userName'] ?? 'System');
        $actorRole = (string)($_SESSION['userRole'] ?? 'system');
        $details = !empty($auditChanges)
            ? implode('; ', $auditChanges)
            : 'Contact record saved with no field changes.';
        $action = $isPensioner ? 'pensioner_contact_updated' : 'registry_contact_updated';

        logAuditEvent($conn, [
            'actor_id' => $actorId,
            'actor_name' => $actorName,
            'actor_role' => $actorRole,
            'action' => $action,
            'entity_type' => 'registry_contact',
            'entity_id' => $regNo,
            'details' => $details
        ]);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Beneficiary details updated successfully.'
    ]);
} catch (Throwable $error) {
    if (!empty($transactionStarted)) {
        $conn->rollback();
    }
    echo json_encode([
        'success' => false,
        'message' => $error->getMessage() ?: 'Unable to update beneficiary details.'
    ]);
}

$conn->close();
?>
